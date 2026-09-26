import time
import asyncio
from typing import Optional

import quart
from assets.data import (
    create_broadcast,
    clear_broadcasts,
    active_broadcast,
    broadcast_history,
    load_show,
)
from assets.ticket_manager import _authorized
from reds_simple_logger import Logger

logger = Logger()
logger.success("Broadcast.py loaded")

# --------------------------------------------------------------------------- #
# Announcements ("Pause endet in 5 Minuten"): sent from the admin, shown on
# the foyer screens (full-screen overlay) and on staff devices (banner via
# /api/live/state). One is active at a time; a new one ends the previous.
# Text is plain text only; every client escapes it when rendering.
# --------------------------------------------------------------------------- #
CATEGORIES = ("info", "attention", "alert", "success")
TARGETS = ("screens", "staff")
MAX_TEXT = 200
MAX_DURATION_MIN = 12 * 60
MAX_PRESETS = 12

DEFAULT_PRESETS = [
    {"label": "Einlass geöffnet", "category": "success", "text": "Der Einlass ist geöffnet."},
    {"label": "Pause: noch 5 Minuten", "category": "attention", "text": "Die Pause endet in 5 Minuten."},
    {"label": "Vorstellung geht weiter", "category": "info", "text": "Die Vorstellung geht gleich weiter."},
    {"label": "Notausgänge freihalten", "category": "alert", "text": "Bitte die Notausgänge freihalten."},
]


def clean_text(value, limit: int = MAX_TEXT) -> str:
    """One line of plain text: control characters and line breaks become
    spaces, runs of whitespace collapse, cut to `limit` characters."""
    text = "".join(c if c.isprintable() else " " for c in str(value or ""))
    return " ".join(text.split())[:limit]


def clean_presets(raw) -> Optional[list]:
    """Validated preset list for the show extras, or None if `raw` is no list."""
    if not isinstance(raw, list):
        return None
    out = []
    for p in raw[:MAX_PRESETS]:
        if not isinstance(p, dict):
            continue
        label = clean_text(p.get("label"), 40)
        text = clean_text(p.get("text"))
        category = p.get("category") if p.get("category") in CATEGORIES else "info"
        if label and text:
            out.append({"label": label, "category": category, "text": text})
    return out


def presets(show: dict) -> list:
    stored = show.get("broadcast_presets")
    return stored if isinstance(stored, list) else DEFAULT_PRESETS


def public_view(b: Optional[dict], now: Optional[float] = None) -> Optional[dict]:
    """What screens and devices may see: no author, no history."""
    if not b:
        return None
    now = now if now is not None else time.time()
    return {
        "id": b["id"],
        "category": b["category"],
        "text": b["text"],
        "text_en": b.get("text_en") or "",
        "expires_in": None if b.get("expires_at") is None else max(0, int(b["expires_at"] - now)),
    }


def current(target: str) -> Optional[dict]:
    """The running announcement if it is meant for `target`."""
    now = time.time()
    b = active_broadcast(now)
    if not b or target not in (b.get("targets") or TARGETS):
        return None
    return public_view(b, now)


def broadcast_routes(app: quart.Quart):
    @app.route("/api/broadcast/send", methods=["POST"])
    async def broadcast_send():
        """Body: {category, text, text_en?, duration_min (null = until ended),
        targets: ["screens","staff"], created_by?}"""
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        data = await quart.request.get_json(silent=True) or {}
        category = data.get("category")
        if category not in CATEGORIES:
            return quart.jsonify({"status": "error", "message": "invalid_category"}), 400
        text = clean_text(data.get("text"))
        if not text:
            return quart.jsonify({"status": "error", "message": "empty_text"}), 400
        duration = data.get("duration_min")
        if duration in (None, "", 0, "0"):
            duration = None
        else:
            try:
                duration = int(duration)
            except (TypeError, ValueError):
                return quart.jsonify({"status": "error", "message": "invalid_duration"}), 400
            if not 1 <= duration <= MAX_DURATION_MIN:
                return quart.jsonify({"status": "error", "message": "invalid_duration"}), 400
        raw_targets = data.get("targets")
        targets = [t for t in TARGETS if t in (raw_targets if isinstance(raw_targets, list) else TARGETS)]
        if not targets:
            return quart.jsonify({"status": "error", "message": "no_targets"}), 400
        now = time.time()
        b = await asyncio.to_thread(
            create_broadcast, category, text, clean_text(data.get("text_en")), targets,
            now + duration * 60 if duration else None,
            clean_text(data.get("created_by"), 40), now,
        )
        logger.info(f"Broadcast #{b['id']} ({category}, {targets}): {text}")
        return quart.jsonify({"status": "success", "broadcast": {**public_view(b, now), "targets": targets}})

    @app.route("/api/broadcast/clear", methods=["POST"])
    async def broadcast_clear():
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        n = await asyncio.to_thread(clear_broadcasts, time.time())
        return quart.jsonify({"status": "success", "cleared": n})

    @app.route("/api/broadcast/active", methods=["GET"])
    async def broadcast_active():
        """Public and read-only: the foyer screens poll it without a login.
        ?target=screens (default) | staff"""
        target = quart.request.args.get("target") or "screens"
        if target not in TARGETS:
            target = "screens"
        b = await asyncio.to_thread(current, target)
        return quart.jsonify({"status": "success", "broadcast": b})

    @app.route("/api/broadcast/history", methods=["GET"])
    async def broadcast_history_route():
        """The last 20 announcements, the running one and the presets."""
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        now = time.time()
        history = await asyncio.to_thread(broadcast_history, 20)
        active = await asyncio.to_thread(active_broadcast, now)
        show = await asyncio.to_thread(load_show)
        return quart.jsonify({
            "status": "success",
            "active": {**public_view(active, now), "targets": active["targets"]} if active else None,
            "history": history,
            "presets": presets(show),
            "now": now,
        })
