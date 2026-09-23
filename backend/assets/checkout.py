"""Online checkout for the public ticket shop.

The order of operations is what keeps a buyer from paying for a ticket that no
longer exists:

  1. start     capacity is claimed (GA counter or the chosen seats) and a hold
               with a short TTL is recorded. Sold out -> the buyer is told now,
               before any payment form is shown.
  2. intent    card only: a Stripe PaymentIntent with capture_method=manual is
               created for the hold's authoritative total. Confirming it in the
               browser only AUTHORISES the card.
  3. complete  the authorisation is verified with Stripe, the tickets are
               written from the hold (capacity is already ours), and only then
               is the payment captured. If anything fails before the capture,
               the tickets are removed again and the authorisation cancelled,
               so the buyer is never charged.

Holds that are abandoned expire; the sweeper gives their capacity back and
cancels their uncaptured PaymentIntent.

Every route here is internal (API key). The PHP frontend is the public entry
point and adds CSRF, honeypot and per-session checks on top.
"""
import asyncio
import hmac
import json
import re
import secrets
import time
import urllib.error
import urllib.parse
import urllib.request
from typing import Any, Dict, List, Optional, Tuple

import quart
import config.conf as config  # type: ignore
from reds_simple_logger import Logger

from assets.data import (
    load_show,
    load_date,
    seat_index,
    bind_seats,
    save_tickets_bulk,
    load_ticket_id,
    log_sale,
    mark_intent_used,
    create_checkout_hold,
    get_checkout_hold,
    set_checkout_hold_intent,
    claim_checkout_hold,
    unclaim_checkout_hold,
    finish_checkout_hold,
    release_checkout_hold,
    sweep_checkout_holds,
    delete_tickets,
    unpaid_ticket_count,
)
from assets.timeutil import local_now, today_iso

logger = Logger()
logger.success("Checkout.py loaded")

HOLD_TTL_SECONDS = 600          # time to fill in the form and pay
INTENT_GRACE_SECONDS = 600      # extra time once the payment form is open
MAX_TICKETS_PER_ORDER = 10
MAX_UNPAID_PER_EMAIL = 10       # pay-at-the-door tickets one address may hold
SWEEP_INTERVAL_SECONDS = 30

_EMAIL_RE = re.compile(r"^[^@\s]+@[^@\s]+\.[^@\s]{2,}$")
_URLISH_RE = re.compile(r"(https?://|www\.|<|>)", re.I)


def _authorized() -> bool:
    key = quart.request.headers.get("Authorization")
    return bool(key) and hmac.compare_digest(str(key), str(config.Auth.auth_key))


def _err(message: str, status: int = 400, **extra):
    return quart.jsonify({"status": "error", "message": message, **extra}), status


def _client_ip() -> str:
    xff = quart.request.headers.get("X-Forwarded-For", "")
    return (xff.split(",")[0].strip() if xff else "") or (quart.request.remote_addr or "")


def _money(v) -> float:
    try:
        return round(max(0.0, float(v)), 2)
    except (TypeError, ValueError):
        return 0.0


def _clean_name(v, limit: int = 80) -> str:
    s = re.sub(r"\s+", " ", str(v or "")).strip()[:limit]
    return "" if _URLISH_RE.search(s) else s


# --------------------------------------------------------------------------- #
# Show data safe to hand to the public shop.
# --------------------------------------------------------------------------- #
def _allowed_methods(show: dict) -> List[str]:
    pm = str(show.get("payment_methods") or "both")
    methods = {"cash": ["cash"], "online": ["card"]}.get(pm, ["card", "cash"])
    if "card" in methods and not _stripe_ready(show):
        methods = [m for m in methods if m != "card"]
    return methods


def _stripe_ready(show: dict) -> bool:
    s = show.get("stripe") or {}
    return bool(str(s.get("publishable_key") or "").strip() and str(s.get("secret_key") or "").strip())


def public_show() -> Dict[str, Any]:
    """Everything the shop renders, and nothing else. /api/show/get returns
    the whole config including the Stripe secret key; it must never reach a
    browser."""
    from assets.manage_show import _enrich_seated_availability  # avoids a cycle

    show = load_show()
    _enrich_seated_availability(show)
    today = today_iso()
    dates = []
    for key, d in (show.get("dates") or {}).items():
        if not isinstance(d, dict) or not d.get("date"):
            continue
        dates.append({
            "id": key,
            "date": d.get("date"),
            "time": d.get("time") or "",
            "price": _money(d.get("price")),
            "tickets": int(d.get("tickets") or 0),
            "available": max(0, int(d.get("tickets_available") or 0)),
            "location": d.get("location") or "",
            "seating": bool(d.get("seating")),
            "past": str(d.get("date")) < today,
        })
    dates.sort(key=lambda x: (x["date"], x["time"]))
    locations = {}
    for lid, loc in (show.get("locations") or {}).items():
        if isinstance(loc, dict):
            locations[lid] = {"name": loc.get("name") or "", "address": loc.get("address") or ""}
    stripe_cfg = show.get("stripe") or {}
    return {
        "orga_name": show.get("orga_name") or "",
        "title": show.get("title") or "",
        "subtitle": show.get("subtitle") or "",
        "store_lock": bool(show.get("store_lock")),
        "contact_email": show.get("contact_email") or "",
        "methods": _allowed_methods(show),
        "stripe_publishable_key": str(stripe_cfg.get("publishable_key") or "") if _stripe_ready(show) else "",
        "locations": locations,
        "dates": dates,
        "hold_ttl": HOLD_TTL_SECONDS,
        "max_per_order": MAX_TICKETS_PER_ORDER,
    }


# --------------------------------------------------------------------------- #
# Stripe (form-encoded REST, no SDK). Blocking: call via asyncio.to_thread.
# --------------------------------------------------------------------------- #
def _stripe(method: str, path: str, params: Optional[dict] = None,
            idempotency_key: Optional[str] = None) -> Tuple[bool, dict]:
    secret = str((load_show().get("stripe") or {}).get("secret_key") or "").strip()
    if not secret:
        return False, {"message": "stripe_not_configured"}
    data = urllib.parse.urlencode(params or {}).encode() if params is not None else None
    headers = {"Authorization": f"Bearer {secret}"}
    if data is not None:
        headers["Content-Type"] = "application/x-www-form-urlencoded"
    if idempotency_key:
        headers["Idempotency-Key"] = idempotency_key
    req = urllib.request.Request(
        "https://api.stripe.com/v1/" + path.lstrip("/"),
        data=data, headers=headers, method=method,
    )
    try:
        with urllib.request.urlopen(req, timeout=20) as resp:
            return True, json.loads(resp.read().decode())
    except urllib.error.HTTPError as e:
        try:
            err = json.loads(e.read().decode()).get("error", {})
        except Exception:
            err = {}
        return False, {"message": err.get("message") or f"HTTP {e.code}", "code": err.get("code")}
    except Exception as e:
        return False, {"message": str(e)}


def _cancel_intent(intent_id: Optional[str]) -> None:
    """Drop an uncaptured authorisation so the buyer's bank releases it."""
    if not intent_id:
        return
    ok, res = _stripe("POST", f"payment_intents/{intent_id}/cancel", {})
    if not ok and "status of canceled" not in str(res.get("message", "")):
        logger.warn(f"Could not cancel PaymentIntent {intent_id}: {res.get('message')}")


# --------------------------------------------------------------------------- #
# Hold sweeper
# --------------------------------------------------------------------------- #
async def sweep_once() -> int:
    released = await asyncio.to_thread(sweep_checkout_holds)
    for h in released:
        if h.get("intent_id"):
            await asyncio.to_thread(_cancel_intent, h["intent_id"])
    return len(released)


async def sweeper_loop() -> None:
    while True:
        try:
            await sweep_once()
        except Exception as e:  # never let the loop die
            logger.error(f"Checkout hold sweep failed: {e}")
        await asyncio.sleep(SWEEP_INTERVAL_SECONDS)


# --------------------------------------------------------------------------- #
# Order validation helpers
# --------------------------------------------------------------------------- #
def _sale_open(show: dict, date: dict) -> Optional[str]:
    """Reason the shop must not sell this date, or None."""
    if show.get("store_lock"):
        return "store_locked"
    if not date:
        return "invalid_date"
    if str(date.get("date")) < today_iso():
        return "date_past"
    return None


def _hold_view(hold: dict, labels: Optional[Dict[str, str]] = None) -> Dict[str, Any]:
    seats = hold.get("seats") or []
    return {
        "date": hold["date"],
        "qty": hold["qty"],
        "total": round(hold["total"], 2),
        "prices": hold.get("prices") or [],
        "seated": hold["seated"],
        "seats": seats,
        "seat_labels": [(labels or {}).get(s, s) for s in seats],
        "expires_at": hold["expires_at"],
        "expires_in": max(0, int(hold["expires_at"] - time.time())),
    }


def checkout_routes(app: quart.Quart):
    @app.before_serving
    async def _start_sweeper():
        app.add_background_task(sweeper_loop)

    @app.route("/api/show/public", methods=["GET"])
    async def show_public():
        if not _authorized():
            return _err("Unauthorized", 401)
        try:
            return quart.jsonify({"status": "success", "show": await asyncio.to_thread(public_show)}), 200
        except Exception as e:
            logger.error(f"public show failed: {e}")
            return _err("internal_error", 500)

    @app.route("/api/checkout/start", methods=["POST"])
    async def checkout_start():
        """{date, qty} for general admission, {date, seats:[...]} for seated.
        Claims the capacity now and returns the hold with its real total."""
        if not _authorized():
            return _err("Unauthorized", 401)
        data: dict = await quart.request.get_json(silent=True) or {}
        valid_date = str(data.get("date") or "").strip()

        show = await asyncio.to_thread(load_show)
        date = await asyncio.to_thread(load_date, valid_date) if valid_date else {}
        reason = _sale_open(show, date)
        if reason:
            return _err(reason, 409 if reason != "invalid_date" else 400)

        base = _money(date.get("price"))
        seated = bool(date.get("seating"))
        seats: List[str] = []
        labels: Dict[str, str] = {}
        if seated:
            seats = [str(s).strip()[:100] for s in (data.get("seats") or []) if str(s).strip()]
            if not seats or len(seats) != len(set(seats)):
                return _err("invalid_seats")
            qty = len(seats)
            if qty > MAX_TICKETS_PER_ORDER:
                return _err("too_many_tickets")
            location_id = date.get("location") or ""
            idx = await asyncio.to_thread(seat_index, location_id)
            unknown = [s for s in seats if s not in idx]
            if unknown:
                return _err("unknown_seats", seats=unknown)
            from assets.boxoffice import _seat_base_prices  # avoids a cycle
            seat_prices = await asyncio.to_thread(_seat_base_prices, location_id, base)
            prices = [round(seat_prices.get(s, base), 2) for s in seats]
            labels = {s: idx[s].get("label") or s for s in seats}
        else:
            try:
                qty = int(data.get("qty") or 0)
            except (TypeError, ValueError):
                return _err("invalid_quantity")
            if qty < 1 or qty > MAX_TICKETS_PER_ORDER:
                return _err("invalid_quantity")
            prices = [base] * qty

        total = round(sum(prices), 2)
        ok, hold, taken = await asyncio.to_thread(
            create_checkout_hold, valid_date, qty, total, prices,
            HOLD_TTL_SECONDS, seats if seated else None, _client_ip(),
        )
        if not ok:
            if seated:
                return _err("seats_taken", 409, seats=taken)
            fresh = await asyncio.to_thread(load_date, valid_date)
            return _err("not_enough_tickets", 409,
                        available=max(0, int((fresh or {}).get("tickets_available") or 0)))
        return quart.jsonify({
            "status": "success",
            "hold_token": hold["token"],
            "hold": _hold_view(hold, labels),
            "methods": _allowed_methods(show),
        }), 200

    @app.route("/api/checkout/status", methods=["POST"])
    async def checkout_status():
        if not _authorized():
            return _err("Unauthorized", 401)
        data: dict = await quart.request.get_json(silent=True) or {}
        hold = await asyncio.to_thread(get_checkout_hold, str(data.get("hold_token") or ""))
        if not hold:
            return _err("hold_not_found", 404)
        return quart.jsonify({"status": "success", "state": hold["state"],
                              "hold": _hold_view(hold), "result": hold.get("result")}), 200

    @app.route("/api/checkout/release", methods=["POST"])
    async def checkout_release():
        """The buyer went back or closed the checkout: give the capacity back
        right away instead of waiting for the TTL."""
        if not _authorized():
            return _err("Unauthorized", 401)
        data: dict = await quart.request.get_json(silent=True) or {}
        released = await asyncio.to_thread(release_checkout_hold, str(data.get("hold_token") or ""))
        if released and released.get("intent_id"):
            await asyncio.to_thread(_cancel_intent, released["intent_id"])
        return quart.jsonify({"status": "success", "released": bool(released)}), 200

    @app.route("/api/checkout/intent", methods=["POST"])
    async def checkout_intent():
        """Card payment: create (or reuse) the manual-capture PaymentIntent for
        the hold's authoritative total."""
        if not _authorized():
            return _err("Unauthorized", 401)
        data: dict = await quart.request.get_json(silent=True) or {}
        token = str(data.get("hold_token") or "")
        hold = await asyncio.to_thread(get_checkout_hold, token)
        if not hold:
            return _err("hold_not_found", 404)
        if hold["state"] != "open" or hold["expires_at"] < time.time():
            return _err("hold_expired", 410)
        show = await asyncio.to_thread(load_show)
        if "card" not in _allowed_methods(show):
            return _err("method_not_allowed")
        amount = int(round(hold["total"] * 100))
        if amount < 50:  # Stripe's minimum charge in EUR
            return _err("amount_too_small")

        if hold.get("intent_id"):
            ok, pi = await asyncio.to_thread(_stripe, "GET", f"payment_intents/{hold['intent_id']}")
            if ok and pi.get("amount") == amount and pi.get("status") in (
                "requires_payment_method", "requires_confirmation", "requires_action"
            ):
                return quart.jsonify({"status": "success", "client_secret": pi["client_secret"],
                                      "hold": _hold_view(hold)}), 200

        params = {
            "amount": amount,
            "currency": "eur",
            "capture_method": "manual",
            # Cards only (Apple Pay / Google Pay ride on them). They support a
            # separate capture and never leave the page, so the order can be
            # completed right after the buyer confirms.
            "payment_method_types[0]": "card",
            "description": f"{show.get('orga_name') or 'QrGate'} · {hold['qty']}x {hold['date']}",
            "metadata[hold_token]": token,
            "metadata[date]": hold["date"],
            "metadata[qty]": str(hold["qty"]),
        }
        # Same key for a retry of the same hold, so a double click cannot
        # create two authorisations. Params must therefore stay identical.
        ok, pi = await asyncio.to_thread(
            _stripe, "POST", "payment_intents", params, f"qrgate-hold-{token}-{amount}"
        )
        if not ok:
            logger.error(f"PaymentIntent create failed: {pi.get('message')}")
            return _err("payment_unavailable", 502)
        await asyncio.to_thread(
            set_checkout_hold_intent, token, pi["id"], time.time() + INTENT_GRACE_SECONDS
        )
        hold = await asyncio.to_thread(get_checkout_hold, token)
        return quart.jsonify({"status": "success", "client_secret": pi["client_secret"],
                              "hold": _hold_view(hold)}), 200

    @app.route("/api/checkout/complete", methods=["POST"])
    async def checkout_complete():
        """{hold_token, method: card|cash, first_name, last_name, email,
        add_people:[...], lang}. Idempotent per hold."""
        if not _authorized():
            return _err("Unauthorized", 401)
        data: dict = await quart.request.get_json(silent=True) or {}
        token = str(data.get("hold_token") or "")
        hold = await asyncio.to_thread(get_checkout_hold, token)
        if not hold:
            return _err("hold_not_found", 404)
        if hold["state"] == "done" and hold.get("result"):
            return quart.jsonify({"status": "success", **hold["result"], "repeat": True}), 200
        if hold["state"] == "processing":
            return _err("in_progress", 409)
        if hold["state"] != "open":
            return _err("hold_expired", 410)

        method = str(data.get("method") or "")
        show = await asyncio.to_thread(load_show)
        if method not in _allowed_methods(show):
            return _err("method_not_allowed")

        first_name = _clean_name(data.get("first_name"))
        last_name = _clean_name(data.get("last_name"))
        email = str(data.get("email") or "").strip()[:200]
        if not first_name or not last_name:
            return _err("invalid_name")
        if not _EMAIL_RE.match(email):
            return _err("invalid_email")
        lang = "de" if str(data.get("lang") or "").lower().startswith("de") else "en"
        extra = data.get("add_people") or []
        if not isinstance(extra, list):
            return _err("invalid_names")
        extra_names = [_clean_name(p) for p in extra[: hold["qty"] - 1]]
        names = [first_name] + [
            (extra_names[i] if i < len(extra_names) and extra_names[i] else
             (f"Gast {i + 2}" if lang == "de" else f"Guest {i + 2}"))
            for i in range(hold["qty"] - 1)
        ]

        if method == "cash":
            held = await asyncio.to_thread(unpaid_ticket_count, email)
            if held + hold["qty"] > MAX_UNPAID_PER_EMAIL:
                return _err("too_many_reservations", 429, limit=MAX_UNPAID_PER_EMAIL)

        if not await asyncio.to_thread(claim_checkout_hold, token):
            again = await asyncio.to_thread(get_checkout_hold, token)
            if again and again["state"] == "done" and again.get("result"):
                return quart.jsonify({"status": "success", **again["result"], "repeat": True}), 200
            return _err("in_progress", 409)

        amount_cents = int(round(hold["total"] * 100))
        intent_id = hold.get("intent_id") if method == "card" else None
        if method == "card":
            if not intent_id:
                await asyncio.to_thread(unclaim_checkout_hold, token)
                return _err("payment_missing")
            ok, pi = await asyncio.to_thread(_stripe, "GET", f"payment_intents/{intent_id}")
            problem = None
            if not ok:
                problem = "payment_check_failed"
            elif (pi.get("metadata") or {}).get("hold_token") != token:
                problem = "payment_mismatch"
            elif pi.get("amount") != amount_cents or pi.get("currency") != "eur":
                problem = "payment_mismatch"
            elif pi.get("status") not in ("requires_capture", "succeeded"):
                problem = "payment_not_authorized"
            if problem:
                # Nothing was charged; the buyer may try again within the hold.
                await asyncio.to_thread(unclaim_checkout_hold, token)
                return _err(problem, 402, stripe_status=(pi or {}).get("status"))
            already_captured = pi.get("status") == "succeeded"

        # ---- write the tickets (capacity is already ours) ----------------
        date = await asyncio.to_thread(load_date, hold["date"])
        from assets.ticket_manager import generate_ticket_id  # avoids a cycle
        tids: List[str] = []
        while len(tids) < hold["qty"]:
            tid = generate_ticket_id(hold["date"])
            if tid in tids or await asyncio.to_thread(load_ticket_id, tid) is not None:
                continue
            tids.append(tid)

        seats = hold.get("seats") or []
        labels: Dict[str, str] = {}
        location_id = (date or {}).get("location") or ""
        if hold["seated"]:
            idx = await asyncio.to_thread(seat_index, location_id)
            labels = {s: (idx.get(s) or {}).get("label") or s for s in seats}

        paid = method == "card"
        now_iso = local_now().isoformat(timespec="seconds")
        sale_id = "web-" + secrets.token_hex(5)
        prices = hold.get("prices") or [round(hold["total"] / hold["qty"], 2)] * hold["qty"]
        tickets: List[Dict[str, Any]] = []
        for i, tid in enumerate(tids):
            sid = seats[i] if hold["seated"] and i < len(seats) else None
            tickets.append({
                "tid": tid,
                "first_name": names[i],
                "last_name": last_name if i == 0 else "",
                "email": email,
                "paid": paid,
                "valid": paid,
                "valid_date": hold["date"],
                "type": "visitor",
                "used_at": None,
                "access_attempts": [],
                "status": "active",
                "method": "stripe" if paid else "bar",
                "payment_intent": intent_id if (paid and i == 0) else None,
                "lang": lang,
                "seat_id": sid,
                "seat_label": labels.get(sid) if sid else None,
                "location": location_id if hold["seated"] else None,
                "price": prices[i] if i < len(prices) else 0,
                "category": "Online",
                "seller": None,
                "sale_id": sale_id,
                "created_at": now_iso,
                "paid_at": now_iso if paid else None,
            })

        async def _undo(reason: str):
            await asyncio.to_thread(delete_tickets, tids)
            await asyncio.to_thread(release_checkout_hold, token, ("processing",))
            await asyncio.to_thread(finish_checkout_hold, token, "failed", None)
            if intent_id:
                await asyncio.to_thread(_cancel_intent, intent_id)
            logger.error(f"Checkout {token[:8]} rolled back: {reason}")

        try:
            if hold["seated"]:
                ok, taken = await asyncio.to_thread(
                    bind_seats, hold["date"], {s: tids[i] for i, s in enumerate(seats)},
                    hold.get("seat_token"),
                )
                if not ok:
                    # Only possible if the hold was swept and the seats resold
                    # in between; the buyer has not been charged.
                    await _undo(f"seats taken {taken}")
                    return _err("seats_taken", 409, seats=taken)
            await asyncio.to_thread(save_tickets_bulk, tickets)
        except Exception as e:
            await _undo(f"save failed: {e}")
            return _err("order_failed", 500)

        # ---- charge, now that the tickets exist -------------------------
        if method == "card" and not already_captured:
            ok, cap = await asyncio.to_thread(
                _stripe, "POST", f"payment_intents/{intent_id}/capture", {},
                f"qrgate-capture-{intent_id}",
            )
            if not ok or cap.get("status") != "succeeded":
                await _undo(f"capture failed: {(cap or {}).get('message')}")
                return _err("payment_failed", 402)
        if method == "card":
            await asyncio.to_thread(mark_intent_used, intent_id, tids[0], hold["total"])
            await asyncio.to_thread(log_sale, today_iso(), hold["qty"], hold["total"])

        result = {
            "tids": tids,
            "paid": paid,
            "method": method,
            "total": round(hold["total"], 2),
            "qty": hold["qty"],
            "date": hold["date"],
            "time": (date or {}).get("time") or "",
            "seat_labels": [labels.get(s, s) for s in seats],
            "email": email,
            "name": f"{first_name} {last_name}",
        }
        await asyncio.to_thread(finish_checkout_hold, token, "done", result)

        asyncio.get_running_loop().create_task(
            _deliver(tickets, (date or {}).get("time") or "", paid)
        )
        return quart.jsonify({"status": "success", **result}), 200


async def _deliver(tickets: List[Dict[str, Any]], event_time: str, paid: bool) -> None:
    """Email the tickets after the order has been answered; a slow mail server
    must not hold up the buyer's confirmation page."""
    from assets.ticket_manager import send_email  # avoids a cycle

    for t in tickets:
        try:
            await send_email(
                t["first_name"], t["last_name"], t["email"], t["tid"], paid,
                date=t["valid_date"], event_time=event_time,
                seat_label=t.get("seat_label"), lang=t.get("lang"),
            )
        except Exception as e:
            logger.error(f"Order ticket {t['tid']} created but email failed: {e}")
