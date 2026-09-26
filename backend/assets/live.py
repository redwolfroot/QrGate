import re
import time
import asyncio
from typing import Dict, Optional

import quart
from assets.data import checkin_stats, load_date
from assets.ticket_manager import _authorized
from assets.timeutil import today_iso
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
        return quart.jsonify({
            "status": "success",
            **counts,
            "scanners": active_devices(),
            "broadcast": None,
        })
