"""
Box office <-> handheld pairing ("Kassen-Scanner").

A register (TicketFlow on the PC) opens a pairing and shows its 4-digit code.
A handheld in "Kasse" mode joins with that code; every QR it scans is queued
for that register, which polls the queue and opens the ticket for payment.

  PC        POST /api/boxoffice/pair/open   {seller}          -> {code, secret}
  PC        POST /api/boxoffice/pair/poll   {code, secret}    -> {scans, handheld}
  PC        POST /api/boxoffice/pair/close  {code, secret}
  handheld  POST /api/boxoffice/pair/join   {code}            -> {seller, token}
  handheld  POST /api/boxoffice/pair/ping   {code, token}     (heartbeat)
  handheld  POST /api/boxoffice/pair/scan   {code, token, tid} -> {ticket}

Only the register that opened a pairing holds its `secret`, so the code lets
you send scans to a register, never read them. join is the only place a code
is tried; it is rate-limited in main.py and hands out the handheld `token`
that ping and scan need, so heartbeats of many phones behind one venue IP
never eat into that limit. Every route needs the backend auth key (the PHP
pages call it for logged-in staff).

State is in process memory, like the rate limiter: a pairing is a live link
between two screens, not data. A register that stops polling for
PAIR_IDLE_SECONDS (tab closed, PC asleep) loses its pairing; after a backend
restart the register opens a new one on its next poll.
"""
import asyncio
import hmac
import secrets
import time
from collections import deque
from threading import Lock
from typing import Any, Dict, Optional

import quart
import config.conf as config  # type: ignore

from assets.data import load_date, load_ticket_id

PAIR_IDLE_SECONDS = 600       # register silent this long -> pairing is dropped
HANDHELD_SEEN_SECONDS = 45    # handheld counts as connected within this window
MAX_QUEUE = 20                # scans waiting for the register, oldest dropped

_pairs: Dict[str, Dict[str, Any]] = {}
_lock = Lock()


def _authorized() -> bool:
    key = quart.request.headers.get("Authorization")
    return bool(key) and hmac.compare_digest(str(key), str(config.Auth.auth_key))


def _err(message: str, status: int = 400):
    return quart.jsonify({"status": "error", "message": message}), status


def _sweep(now: float) -> None:
    for code in [c for c, p in _pairs.items() if now - p["last_poll"] > PAIR_IDLE_SECONDS]:
        del _pairs[code]


def _new_code() -> str:
    for _ in range(200):
        code = f"{secrets.randbelow(10000):04d}"
        if code not in _pairs:
            return code
    raise RuntimeError("no free pairing code")


def _owned(code: str, secret: str) -> Optional[Dict[str, Any]]:
    p = _pairs.get(code)
    if p and hmac.compare_digest(p["secret"], secret):
        return p
    return None


def _joined(code: str, token: str) -> Optional[Dict[str, Any]]:
    p = _pairs.get(code)
    if p and token and any(hmac.compare_digest(t, token) for t in p["tokens"]):
        return p
    return None


def _ticket_summary(t: Dict[str, Any]) -> Dict[str, Any]:
    price = t.get("price")
    if price is None and t.get("valid_date"):
        price = (load_date(t["valid_date"]) or {}).get("price")
    try:
        price = round(float(price), 2) if price is not None else None
    except (TypeError, ValueError):
        price = None
    name = " ".join(
        n for n in (t.get("first_name"), t.get("last_name"))
        if n and str(n).strip().lower() not in ("unknown", "none")
    )
    return {
        "tid": t.get("tid"),
        "name": name,
        "valid_date": t.get("valid_date"),
        "seat_label": t.get("seat_label"),
        "paid": bool(t.get("paid")),
        "cancelled": (t.get("status") or "active") == "cancelled",
        "price": price,
    }


def scanpair_routes(app: quart.Quart):
    @app.route("/api/boxoffice/pair/open", methods=["POST"])
    async def pair_open():
        if not _authorized():
            return _err("Unauthorized", 401)
        data = await quart.request.get_json(silent=True) or {}
        now = time.monotonic()
        with _lock:
            _sweep(now)
            code = _new_code()
            _pairs[code] = {
                "secret": secrets.token_hex(16),
                "seller": str(data.get("seller") or "").strip()[:80],
                "last_poll": now,
                "handheld_seen": 0.0,
                "tokens": set(),
                "queue": deque(maxlen=MAX_QUEUE),
            }
            secret = _pairs[code]["secret"]
        return quart.jsonify({"status": "success", "code": code, "secret": secret}), 200

    @app.route("/api/boxoffice/pair/poll", methods=["POST"])
    async def pair_poll():
        if not _authorized():
            return _err("Unauthorized", 401)
        data = await quart.request.get_json(silent=True) or {}
        now = time.monotonic()
        with _lock:
            _sweep(now)
            p = _owned(str(data.get("code") or ""), str(data.get("secret") or ""))
            if p is None:
                return _err("pair_gone", 410)
            p["last_poll"] = now
            scans = list(p["queue"])
            p["queue"].clear()
            handheld = now - p["handheld_seen"] < HANDHELD_SEEN_SECONDS
        return quart.jsonify({"status": "success", "scans": scans, "handheld": handheld}), 200

    @app.route("/api/boxoffice/pair/close", methods=["POST"])
    async def pair_close():
        if not _authorized():
            return _err("Unauthorized", 401)
        data = await quart.request.get_json(silent=True) or {}
        code = str(data.get("code") or "")
        with _lock:
            if _owned(code, str(data.get("secret") or "")):
                del _pairs[code]
        return quart.jsonify({"status": "success"}), 200

    @app.route("/api/boxoffice/pair/join", methods=["POST"])
    async def pair_join():
        if not _authorized():
            return _err("Unauthorized", 401)
        data = await quart.request.get_json(silent=True) or {}
        now = time.monotonic()
        with _lock:
            _sweep(now)
            p = _pairs.get(str(data.get("code") or "").strip())
            if p is None:
                return _err("pair_gone", 410)
            token = secrets.token_hex(12)
            p["tokens"].add(token)
            p["handheld_seen"] = now
            seller = p["seller"]
        return quart.jsonify({"status": "success", "seller": seller, "token": token}), 200

    @app.route("/api/boxoffice/pair/ping", methods=["POST"])
    async def pair_ping():
        if not _authorized():
            return _err("Unauthorized", 401)
        data = await quart.request.get_json(silent=True) or {}
        with _lock:
            p = _joined(str(data.get("code") or ""), str(data.get("token") or ""))
            if p is None:
                return _err("pair_gone", 410)
            p["handheld_seen"] = time.monotonic()
            seller = p["seller"]
        return quart.jsonify({"status": "success", "seller": seller}), 200

    @app.route("/api/boxoffice/pair/scan", methods=["POST"])
    async def pair_scan():
        if not _authorized():
            return _err("Unauthorized", 401)
        data = await quart.request.get_json(silent=True) or {}
        code = str(data.get("code") or "").strip()
        token = str(data.get("token") or "")
        tid = str(data.get("tid") or "").strip().upper()[:40]
        with _lock:
            if _joined(code, token) is None:
                return _err("pair_gone", 410)
        if not tid:
            return _err("missing_tid")
        ticket = await asyncio.to_thread(load_ticket_id, tid)
        if ticket is None:
            return _err("not_found", 404)
        summary = await asyncio.to_thread(_ticket_summary, ticket)
        now = time.monotonic()
        with _lock:
            p = _joined(code, token)
            if p is None:                 # closed while the ticket was loading
                return _err("pair_gone", 410)
            p["handheld_seen"] = now
            p["queue"].append({"tid": ticket.get("tid") or tid})
        return quart.jsonify({"status": "success", "ticket": summary}), 200
