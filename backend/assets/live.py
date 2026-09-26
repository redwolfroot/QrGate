import re
import hmac
import time
import asyncio
import secrets
from typing import Dict, Optional

import quart
from assets.data import (
    checkin_stats,
    load_date,
    load_show,
    dashboard_overview,
    recent_checkins,
    boxoffice_sales,
    active_broadcast,
)
from assets.ticket_manager import _authorized
from assets.timeutil import today_iso
from assets.broadcast import current as current_broadcast, public_view, presets
from assets.setup import get_setting, set_setting
from reds_simple_logger import Logger

logger = Logger()
logger.success("Live.py loaded")

# --------------------------------------------------------------------------- #
# Live state for the evening: door counter for today's date, the devices that
# are currently polling (handhelds, registers) and the active announcement.
# Every screen polls GET /api/live/state every few seconds; that call is also
# the device's heartbeat. Devices live in memory only: after a restart the
# list fills again within one poll interval.
# --------------------------------------------------------------------------- #
ACTIVE_SECONDS = 15          # seen within this window = active
FORGET_SECONDS = 3600        # drop devices silent for longer than this
ROLES = ("scanner", "inspector", "kasse", "ticketflow")
_ID_RE = re.compile(r"[^A-Za-z0-9_-]")

# device id -> {"name", "role", "last_seen" (monotonic), "scans"}
_devices: Dict[str, dict] = {}


def _clean_id(device) -> str:
    return _ID_RE.sub("", str(device or ""))[:64]


def _clean_name(name) -> str:
    name = "".join(c for c in str(name or "") if c.isprintable()).strip()
    return name[:40]


def note_device(device, name=None, role=None) -> Optional[dict]:
    """Heartbeat: remember that `device` is alive (and its label)."""
    dev = _clean_id(device)
    if not dev:
        return None
    now = time.monotonic()
    entry = _devices.setdefault(dev, {"name": "", "role": "scanner", "last_seen": now, "scans": 0})
    entry["last_seen"] = now
    if name is not None and _clean_name(name):
        entry["name"] = _clean_name(name)
    if role in ROLES:
        entry["role"] = role
    return entry


def count_scan(device) -> None:
    """One admitted guest at `device` (called by the validate route)."""
    entry = note_device(device)
    if entry is not None:
        entry["scans"] += 1


def active_devices() -> list:
    now = time.monotonic()
    for dev in [d for d, e in _devices.items() if now - e["last_seen"] > FORGET_SECONDS]:
        _devices.pop(dev, None)
    out = []
    for dev, e in _devices.items():
        age = now - e["last_seen"]
        if age <= ACTIVE_SECONDS:
            out.append({
                "id": dev[:8],
                "name": e["name"] or f"Gerät {dev[:4].upper()}",
                "role": e["role"],
                "last_seen_s": int(age),
                "scans": e["scans"],
            })
    out.sort(key=lambda d: (d["role"], d["name"].lower()))
    return out


def today_counts() -> dict:
    """Door numbers for today's date, from checkin_stats() (same truth as the
    admin's check-in monitor)."""
    today = today_iso()
    c = checkin_stats().get(today) or {}
    return {
        "today": today,
        "event_today": load_date(today) is not None,
        "checked_in": int(c.get("checked_in") or 0),
        "sold": int(c.get("sold") or 0),
        "pending": int(c.get("pending") or 0),
    }


# ---- live dashboard (screens/live.php) ---------------------------------------
# A backstage monitor cannot log in, so it opens live.php?token=<display
# token>. The token only reads the dashboard; sending announcements stays with
# an admin session. Regenerating or revoking it locks old links out at once.
DISPLAY_TOKEN_KEY = "display_token"


def display_token_ok(token) -> bool:
    stored = get_setting(DISPLAY_TOKEN_KEY) or ""
    return bool(stored) and bool(token) and hmac.compare_digest(str(token), stored)


def _short_name(first, last) -> str:
    """"Anna K." on a monitor that people walk past, not the full name."""
    first = str(first or "").strip()
    last = str(last or "").strip()
    if first.lower() in ("", "unknown"):
        first = ""
    if last.lower() in ("", "unknown"):
        last = ""
    return " ".join(x for x in (first, (last[:1] + ".") if last else "") if x)


def dashboard_data() -> dict:
    today = today_iso()
    show = load_show()
    ov = dashboard_overview()
    by_date = ov.get("by_date") or {}
    stats = checkin_stats()
    dates = []
    for d in (show.get("dates") or {}).values():
        if not isinstance(d, dict) or str(d.get("date") or "") < today:
            continue
        st = by_date.get(d["date"]) or {}
        dates.append({
            "date": d["date"],
            "time": d.get("time") or "",
            "tickets": int(d.get("tickets") or 0),
            "available": int(d.get("tickets_available") or 0),
            "sold": int(st.get("sold") or 0),
            "checked_in": int((stats.get(d["date"]) or {}).get("checked_in") or 0),
        })
    dates.sort(key=lambda x: (x["date"], x["time"]))
    register = [t for t in boxoffice_sales(today)
                if t.get("status") != "cancelled" and t.get("paid")]
    recent = []
    for r in recent_checkins(40):
        if len(recent) >= 8:
            break
        # used_at is "YYYY.MM.DD - HH:MM:SS"; only today's entries count here.
        if str(r.get("used_at") or "")[:10].replace(".", "-") != today:
            continue
        recent.append({
            "name": _short_name(*(str(r.get("name") or "").split(" ", 1) + [""])[:2]),
            "seat": r.get("seat_label") or "",
            "time": str(r.get("used_at") or "")[-8:-3],
        })
    now = time.time()
    b = active_broadcast(now)
    return {
        **today_counts(),
        "dates": dates[:6],
        "register": {
            "revenue": round(sum(float(t.get("price") or 0) for t in register), 2),
            "tickets": len(register),
        },
        "recent": recent,
        "broadcast": {**public_view(b, now), "targets": b["targets"]} if b else None,
        "presets": presets(show),
        "title": str(show.get("title") or "").strip(),
        "orga": str(show.get("orga_name") or "").strip(),
    }


def live_routes(app: quart.Quart):
    @app.route("/api/live/state", methods=["GET"])
    async def live_state():
        """?device=<id>&name=<label>&role=<scanner|inspector|kasse|ticketflow>
        (all optional; without `device` it is a read-only poll, e.g. a
        dashboard). -> {today, event_today, checked_in, sold, pending,
        scanners:[{name, role, last_seen_s, scans}], broadcast}"""
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        args = quart.request.args
        if args.get("device"):
            note_device(args.get("device"), args.get("name"), args.get("role"))
        counts = await asyncio.to_thread(today_counts)
        broadcast = await asyncio.to_thread(current_broadcast, "staff")
        return quart.jsonify({
            "status": "success",
            **counts,
            "scanners": active_devices(),
            "broadcast": broadcast,
        })

    @app.route("/api/live/dashboard", methods=["GET"])
    async def live_dashboard():
        """Everything live.php shows. Called by the PHP page with the auth key;
        for a monitor without an admin session the page forwards its
        ?display_token, which must then match."""
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        token = quart.request.args.get("display_token")
        if token is not None and not await asyncio.to_thread(display_token_ok, token):
            return quart.jsonify({"status": "error", "message": "invalid_token"}), 403
        data = await asyncio.to_thread(dashboard_data)
        return quart.jsonify({"status": "success", **data, "scanners": active_devices()})

    @app.route("/api/live/display-token", methods=["GET", "POST"])
    async def live_display_token():
        """GET: the current token (or null). POST {"action":"new"|"revoke"}."""
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        if quart.request.method == "POST":
            data = await quart.request.get_json(silent=True) or {}
            if data.get("action") == "revoke":
                await asyncio.to_thread(set_setting, DISPLAY_TOKEN_KEY, None)
            elif data.get("action") == "new":
                await asyncio.to_thread(set_setting, DISPLAY_TOKEN_KEY, secrets.token_urlsafe(24))
            else:
                return quart.jsonify({"status": "error", "message": "invalid_action"}), 400
        token = await asyncio.to_thread(get_setting, DISPLAY_TOKEN_KEY)
        return quart.jsonify({"status": "success", "token": token or None})
