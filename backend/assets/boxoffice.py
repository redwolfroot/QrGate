"""Box office (TicketFlow) API.

One request = one sale. A sale is a cart of price-category lines for a single
date, paid in cash or by card. Everything the cashier sells in one go is created
atomically: capacity (or the chosen seats) is claimed once, all tickets are
written in one transaction and the stats are booked with the real amounts. If
anything fails, nothing is sold.

Price categories ("Ermäßigt", "Kind", "Freikarte", ...) are configured by the
admin as `boxoffice_categories` on the show. Each derives its price from the
ticket's base price (date price, or the seat's category price on seated dates):
    fixed   -> value                     (e.g. Freikarte = fixed 0)
    minus   -> base - value, min 0       (e.g. 3 € off)
    percent -> base * (1 - value / 100)  (e.g. 50 % off)
The implicit category "normal" is always the base price. Prices are computed
here, never taken from the client.
"""
import asyncio
import hmac
import secrets
from typing import Any, Dict, List, Optional

import quart
import config.conf as config  # type: ignore
from reds_simple_logger import Logger

from assets.data import (
    load_show,
    load_date,
    load_seatmap,
    seat_index,
    bind_seats,
    release_seats_for_ticket,
    decrement_availability,
    release_availability,
    save_tickets,
    save_tickets_bulk,
    boxoffice_sales,
    search_tickets,
    load_ticket_id,
    log_sale,
)
from assets.timeutil import local_now, today_iso

logger = Logger()
logger.success("Boxoffice.py loaded")

METHODS = ("bar", "card")
MAX_TICKETS_PER_SALE = 50
NORMAL = "normal"


def _authorized() -> bool:
    key = quart.request.headers.get("Authorization")
    return bool(key) and hmac.compare_digest(str(key), str(config.Auth.auth_key))


def _err(message: str, status: int = 400, **extra):
    return quart.jsonify({"status": "error", "message": message, **extra}), status


def _money(v) -> float:
    try:
        return round(max(0.0, float(v)), 2)
    except (TypeError, ValueError):
        return 0.0


def normalize_categories(raw) -> List[Dict[str, Any]]:
    """Sanitize the admin-configured category list. Invalid rows are dropped."""
    out: List[Dict[str, Any]] = []
    seen = {NORMAL}
    for c in raw if isinstance(raw, list) else []:
        if not isinstance(c, dict):
            continue
        name = str(c.get("name") or "").strip()[:40]
        mode = c.get("mode") if c.get("mode") in ("fixed", "minus", "percent") else None
        cid = str(c.get("id") or "").strip()[:40]
        if not name or not mode or not cid or cid in seen:
            continue
        value = _money(c.get("value"))
        if mode == "percent":
            value = min(100.0, value)
        seen.add(cid)
        out.append({"id": cid, "name": name, "mode": mode, "value": value})
    return out


def show_categories() -> List[Dict[str, Any]]:
    return normalize_categories(load_show().get("boxoffice_categories"))


def category_price(base: float, cat: Optional[Dict[str, Any]]) -> float:
    if not cat:
        return round(base, 2)
    v = float(cat["value"])
    if cat["mode"] == "fixed":
        return round(v, 2)
    if cat["mode"] == "minus":
        return round(max(0.0, base - v), 2)
    return round(base * (1 - v / 100.0), 2)


def _seat_base_prices(location_id: str, base: float) -> Dict[str, float]:
    """seat_id -> seat price (seat-map category price, else the date price).
    Mirrors /api/seatmap/availability so the cashier sees what is charged."""
    sm = load_seatmap(location_id)
    cat_price: Dict[str, float] = {}
    for c in sm["categories"]:
        if isinstance(c, dict) and c.get("id") is not None:
            try:
                cat_price[str(c["id"])] = float(c.get("price"))
            except (TypeError, ValueError):
                pass
    out: Dict[str, float] = {}
    for sid, info in seat_index(location_id).items():
        cid = info.get("category_id")
        out[sid] = cat_price.get(str(cid), base) if cid is not None else base
    return out


def _public_ticket(t: Dict[str, Any]) -> Dict[str, Any]:
    """The ticket fields the box office UI needs (no audit trail / intents)."""
    return {
        "tid": t.get("tid"),
        "first_name": t.get("first_name"),
        "last_name": t.get("last_name"),
        "email": t.get("email"),
        "valid_date": t.get("valid_date"),
        "type": t.get("type"),
        "paid": bool(t.get("paid")),
        "valid": bool(t.get("valid")),
        "used_at": t.get("used_at"),
        "status": t.get("status") or "active",
        "method": t.get("method"),
        "price": t.get("price"),
        "category": t.get("category"),
        "seller": t.get("seller"),
        "sale_id": t.get("sale_id"),
        "created_at": t.get("created_at"),
        "paid_at": t.get("paid_at"),
        "seat_label": t.get("seat_label"),
    }


async def _deliver_emails(tickets: List[Dict[str, Any]], event_time: str) -> None:
    """Email the tickets after the sale has been answered. A slow or broken
    mail server must never delay the queue at the counter."""
    from assets.ticket_manager import send_email  # local: avoids import cycle

    for t in tickets:
        try:
            await send_email(
                t["first_name"], t["last_name"], t["email"], t["tid"], True,
                date=t["valid_date"], event_time=event_time,
                seat_label=t.get("seat_label"), lang=t.get("lang"),
            )
        except Exception as e:
            logger.error(f"Box office ticket {t['tid']} created but email failed: {e}")


def boxoffice_routes(app: quart.Quart):
    @app.route("/api/boxoffice/sell", methods=["POST"])
    async def boxoffice_sell():
        """Body:
        {
          "valid_date": "YYYY-MM-DD",           # required for visitor sales
          "items": [{"category": "normal", "qty": 2}, ...],
          "seats": ["seat-id", ...],            # seated dates: one per ticket,
                                                # assigned to items in order
          "type": "visitor" | "vip" | "admin",  # vip/admin: free special tickets
          "method": "bar" | "card",
          "first_name", "last_name", "email", "lang", "seller"
        }
        -> {"status":"success","sale_id","tids":[...],"tickets":[...],"total"}
        """
        if not _authorized():
            return _err("Unauthorized", 401)
        data: dict = await quart.request.get_json(silent=True) or {}

        t_type = str(data.get("type") or "visitor")
        if t_type not in ("visitor", "vip", "admin"):
            return _err("invalid_type")
        method = str(data.get("method") or "bar")
        if method not in METHODS:
            return _err("invalid_method")

        first_name = str(data.get("first_name") or "").strip()[:80] or "Unknown"
        last_name = str(data.get("last_name") or "").strip()[:80] or "Unknown"
        email = str(data.get("email") or "").strip()[:200] or None
        lang = str(data.get("lang") or "de").lower()[:5]
        seller = str(data.get("seller") or "").strip()[:80] or None

        # ---- expand the cart into one line per ticket -------------------
        cats = {c["id"]: c for c in show_categories()}
        lines: List[Optional[Dict[str, Any]]] = []   # category dict or None=normal
        for it in data.get("items") or []:
            if not isinstance(it, dict):
                return _err("invalid_items")
            cid = str(it.get("category") or NORMAL)
            try:
                qty = int(it.get("qty") or 0)
            except (TypeError, ValueError):
                return _err("invalid_items")
            if qty < 0:
                return _err("invalid_items")
            if cid != NORMAL and cid not in cats:
                return _err("unknown_category", category=cid)
            lines.extend([cats.get(cid)] * qty)
        n = len(lines)
        if n < 1:
            return _err("empty_cart")
        if n > MAX_TICKETS_PER_SALE:
            return _err("too_many_tickets")

        valid_date = str(data.get("valid_date") or "").strip()
        seated = False
        location_id = None
        seat_ids: List[str] = []
        seat_labels: Dict[str, str] = {}
        prices: List[float] = []
        event_time = ""

        if t_type == "visitor":
            if not valid_date:
                return _err("no_date")
            date_info = await asyncio.to_thread(load_date, valid_date)
            if not date_info:
                return _err("Invalid date provided")
            event_time = date_info.get("time") or ""
            base = _money(date_info.get("price"))
            seated = bool(date_info.get("seating"))
            if seated:
                location_id = date_info.get("location") or ""
                seat_ids = [str(s).strip() for s in (data.get("seats") or []) if str(s).strip()]
                if len(seat_ids) != n or len(set(seat_ids)) != n:
                    return _err("seat_count_mismatch")
                idx = await asyncio.to_thread(seat_index, location_id)
                unknown = [s for s in seat_ids if s not in idx]
                if unknown:
                    return _err("Unknown seat", seats=unknown)
                seat_prices = await asyncio.to_thread(_seat_base_prices, location_id, base)
                seat_labels = {s: idx[s].get("label") for s in seat_ids}
                prices = [category_price(seat_prices[s], lines[i]) for i, s in enumerate(seat_ids)]
            else:
                prices = [category_price(base, c) for c in lines]
        else:
            # Special tickets: free, not bound to a date, consume no capacity.
            valid_date = "Unlimited"
            prices = [0.0] * n

        total = round(sum(prices), 2)
        sale_method = method if total > 0 else "free"
        sale_id = secrets.token_hex(6)
        created_at = local_now().isoformat(timespec="seconds")

        # Generate ids up front; a collision with an existing ticket is
        # astronomically unlikely but would silently overwrite it via upsert.
        from assets.ticket_manager import generate_ticket_id  # avoids import cycle

        tids: List[str] = []
        while len(tids) < n:
            tid = generate_ticket_id(valid_date)
            if tid in tids or await asyncio.to_thread(load_ticket_id, tid) is not None:
                continue
            tids.append(tid)

        # ---- claim capacity (all or nothing) ----------------------------
        reserved = False
        seats_bound = False
        if t_type == "visitor" and not seated:
            if not await asyncio.to_thread(decrement_availability, valid_date, n):
                return _err("Not enough tickets available", 409)
            reserved = True
        if seated:
            ok, taken = await asyncio.to_thread(
                bind_seats, valid_date, {s: tids[i] for i, s in enumerate(seat_ids)}, None
            )
            if not ok:
                return _err("seats_taken", 409, seats=taken)
            seats_bound = True

        tickets: List[Dict[str, Any]] = []
        for i, tid in enumerate(tids):
            cat = lines[i]
            sid = seat_ids[i] if seated else None
            tickets.append({
                "tid": tid,
                "first_name": first_name,
                "last_name": last_name,
                "email": email,
                "paid": True,
                "valid": True,
                "valid_date": valid_date,
                "type": t_type,
                "used_at": None,
                "access_attempts": [],
                "status": "active",
                "method": sale_method,
                "payment_intent": None,
                "lang": lang,
                "seat_id": sid,
                "seat_label": seat_labels.get(sid) if sid else None,
                "location": location_id if seated else None,
                "price": prices[i],
                "category": (cat["name"] if cat else ("Normal" if t_type == "visitor" else t_type.upper())),
                "seller": seller,
                "sale_id": sale_id,
                "created_at": created_at,
                "paid_at": created_at,
            })

        try:
            await asyncio.to_thread(save_tickets_bulk, tickets)
        except Exception as e:
            if reserved:
                await asyncio.to_thread(release_availability, valid_date, n)
            if seats_bound:
                for tid in tids:
                    await asyncio.to_thread(release_seats_for_ticket, valid_date, tid)
            logger.error(f"Box office sale failed, rolled back: {e}")
            return _err("save_failed", 500)

        # Visitor tickets count as sales (special tickets never did). Income is
        # the real amount charged, so cancel can reverse it per ticket.
        if t_type == "visitor":
            await asyncio.to_thread(log_sale, today_iso(), n, total)

        if email:
            asyncio.get_running_loop().create_task(_deliver_emails(tickets, event_time))

        return quart.jsonify({
            "status": "success",
            "sale_id": sale_id,
            "tids": tids,
            "tickets": [_public_ticket(t) for t in tickets],
            "total": total,
            "method": sale_method,
        }), 200

    @app.route("/api/boxoffice/sales", methods=["POST"])
    async def boxoffice_sales_route():
        """Tickets sold at the box office on `day` (default today), optionally
        only by `seller`. The UI groups them by sale_id."""
        if not _authorized():
            return _err("Unauthorized", 401)
        data: dict = await quart.request.get_json(silent=True) or {}
        day = str(data.get("day") or today_iso())[:10]
        seller = str(data.get("seller") or "").strip() or None
        rows = await asyncio.to_thread(boxoffice_sales, day, seller)
        return quart.jsonify({
            "status": "success",
            "day": day,
            "tickets": [_public_ticket(t) for t in rows],
        }), 200

    @app.route("/api/boxoffice/search", methods=["POST"])
    async def boxoffice_search():
        if not _authorized():
            return _err("Unauthorized", 401)
        data: dict = await quart.request.get_json(silent=True) or {}
        q = str(data.get("q") or "").strip()[:80]
        if len(q) < 2:
            return quart.jsonify({"status": "success", "tickets": []}), 200
        rows = await asyncio.to_thread(search_tickets, q, 25)
        return quart.jsonify({
            "status": "success",
            "tickets": [_public_ticket(t) for t in rows],
        }), 200

    @app.route("/api/boxoffice/collect", methods=["POST"])
    async def boxoffice_collect():
        """Take payment for an unpaid (reserved) ticket at the counter: marks it
        paid + valid and books it on this register, so it shows up in the
        register report. Its income was already logged when it was reserved."""
        if not _authorized():
            return _err("Unauthorized", 401)
        data: dict = await quart.request.get_json(silent=True) or {}
        tid = str(data.get("tid") or "").strip().upper()
        method = str(data.get("method") or "bar")
        if method not in METHODS:
            return _err("invalid_method")
        ticket = await asyncio.to_thread(load_ticket_id, tid)
        if ticket is None:
            return _err("Ticket not found", 404)
        if (ticket.get("status") or "active") == "cancelled":
            return _err("Ticket cancelled", 409)
        if ticket.get("paid"):
            return _err("already_paid", 409)
        if ticket.get("price") is None:
            date_info = await asyncio.to_thread(load_date, ticket.get("valid_date") or "")
            ticket["price"] = _money((date_info or {}).get("price"))
        ticket.update({
            "paid": True,
            "valid": not ticket.get("used_at"),
            "method": method,
            "seller": str(data.get("seller") or "").strip()[:80] or None,
            "sale_id": secrets.token_hex(6),
            "paid_at": local_now().isoformat(timespec="seconds"),
        })
        await asyncio.to_thread(save_tickets, tid, ticket)
        if ticket.get("email"):
            di = await asyncio.to_thread(load_date, ticket.get("valid_date") or "")
            asyncio.get_running_loop().create_task(
                _deliver_emails([ticket], (di or {}).get("time") or "")
            )
        return quart.jsonify({"status": "success", "ticket": _public_ticket(ticket)}), 200

    @app.route("/api/boxoffice/void", methods=["POST"])
    async def boxoffice_void():
        """Cancel the given tickets (e.g. a whole sale right after it was rung
        up by mistake). Reuses the regular cancellation core per ticket."""
        if not _authorized():
            return _err("Unauthorized", 401)
        from assets.ticket_manager import _do_cancel  # avoids import cycle

        data: dict = await quart.request.get_json(silent=True) or {}
        tids = [str(t).strip().upper() for t in (data.get("tids") or []) if str(t).strip()]
        if not tids or len(tids) > MAX_TICKETS_PER_SALE:
            return _err("invalid_tids")
        actor = str(data.get("actor") or "ticketflow").strip()[:80]
        reason = str(data.get("reason") or "").strip()[:200]
        results = []
        for tid in tids:
            payload, _ = await _do_cancel(tid, actor, reason)
            results.append({"tid": tid, "status": payload.get("status"), "message": payload.get("message")})
        ok = all(r["status"] == "success" for r in results)
        return quart.jsonify({
            "status": "success" if ok else "partial",
            "results": results,
        }), 200
