import os
import json
import hmac
import time
import secrets
import sqlite3
import quart
import config.conf as config
from werkzeug.security import safe_join
from typing import Dict, Optional, Any
from reds_simple_logger import Logger

logger = Logger()
logger.success("Data.py loaded")

# --------------------------------------------------------------------------- #
# SQLite-backed data layer (drop-in replacement for the old JSON flat files).
#
# The public functions below keep EXACTLY the same names, signatures and
# return/accept shapes as the original JSON implementation so that every
# caller (vote.py, manage_show.py, image_manager.py, stats.py, vaildate.py,
# ticket_manager.py) keeps working unchanged. The nested `shows.json` dict is
# decomposed into relational tables on save and reassembled on load.
#
# Storage moved to backend/data/qrgate.db. The legacy JSON files are kept as
# backups and used for the one-time auto-migration on first init.
# --------------------------------------------------------------------------- #

# cwd is `backend/` at runtime, so these relative paths resolve under backend/.
DATA_DIR = "data"
DB_PATH = os.path.join(DATA_DIR, "qrgate.db")
SHOWS_JSON = os.path.join(DATA_DIR, "shows.json")
TICKETS_JSON = os.path.join(DATA_DIR, "tickets.json")
STATS_JSON = os.path.join(DATA_DIR, "stats.json")

# The single show row always uses this id (singleton table).
SHOW_ID = 1

# Scalar top-level show keys that map to dedicated columns. Anything else
# (e.g. screens, stripe, future keys) is preserved verbatim in the `extras`
# JSON-text column so no caller-visible key is ever silently dropped.
_SHOW_SCALAR_KEYS = (
    "orga_name",
    "title",
    "subtitle",
    "banner",
    "logo",
    "wallpaper",
    "payment_methods",
)
# Keys handled out-of-band (not stored in `extras`).
_SHOW_HANDLED_KEYS = set(_SHOW_SCALAR_KEYS) | {"store_lock", "votes", "dates"}


def get_db() -> sqlite3.Connection:
    """Open a fresh connection per operation (simplest correct approach for
    sync sqlite3 under Quart). Caller is responsible for closing it."""
    os.makedirs(DATA_DIR, exist_ok=True)
    conn = sqlite3.connect(DB_PATH, timeout=5.0)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute("PRAGMA foreign_keys=ON")
    conn.execute("PRAGMA busy_timeout=5000")
    return conn


def init_db() -> None:
    """Create all tables idempotently and, on first run with empty tables,
    auto-import the legacy JSON files (one-time migration). Safe to call
    repeatedly (e.g. at import and/or startup)."""
    conn = get_db()
    try:
        conn.executescript(
            """
            CREATE TABLE IF NOT EXISTS show (
                id              INTEGER PRIMARY KEY,
                orga_name       TEXT,
                title           TEXT,
                subtitle        TEXT,
                banner          TEXT,
                logo            TEXT,
                wallpaper       TEXT,
                store_lock      INTEGER DEFAULT 0,
                payment_methods TEXT,
                votes_count     INTEGER DEFAULT 0,
                votes_value     INTEGER DEFAULT 0,
                votes_average   REAL DEFAULT 0,
                votes_comments  TEXT,   -- JSON list of comment strings (optional)
                extras          TEXT    -- JSON dict of any unmapped top-level keys
            );

            CREATE TABLE IF NOT EXISTS dates (
                date_key          TEXT PRIMARY KEY,  -- preserves "1"/"2" keys
                date              TEXT,
                time              TEXT,
                tickets           INTEGER,
                tickets_available INTEGER,
                price             TEXT,
                location          TEXT,              -- location id this day belongs to
                seating           INTEGER DEFAULT 0, -- 1 = reserved seating (seat map)
                position          INTEGER            -- preserve insertion order
            );

            CREATE TABLE IF NOT EXISTS tickets (
                tid             TEXT PRIMARY KEY,
                first_name      TEXT,
                last_name       TEXT,
                email           TEXT,
                paid            INTEGER DEFAULT 0,
                valid_date      TEXT,
                type            TEXT,
                valid           INTEGER DEFAULT 0,
                used_at         TEXT,
                access_attempts TEXT DEFAULT '[]'    -- JSON list
            );

            CREATE TABLE IF NOT EXISTS daily_stats (
                date   TEXT PRIMARY KEY,
                sales  INTEGER DEFAULT 0,
                income REAL DEFAULT 0
            );

            CREATE TABLE IF NOT EXISTS payment_intents (
                payment_intent_id TEXT PRIMARY KEY,  -- Stripe intent id (consumed once)
                tid               TEXT,              -- ticket this intent was used for
                amount            REAL,              -- amount consumed
                used_at           TEXT               -- when it was recorded
            );

            -- One saved room/hall layout per location. `layout` and
            -- `categories` are opaque JSON produced by the admin seat-map
            -- editor. Seats live inside layout.elements (type="seat") and
            -- carry a stable `id`, `row`, `number` and `category_id`.
            CREATE TABLE IF NOT EXISTS seatmaps (
                location_id TEXT PRIMARY KEY,
                layout      TEXT DEFAULT '{}',   -- JSON: {elements:[...]}
                categories  TEXT DEFAULT '[]',   -- JSON: [{id,name,color,price}]
                updated_at  TEXT
            );

            -- Per-date seat occupancy. A missing row means the seat is FREE.
            -- status: 'held' (checkout reservation with TTL) | 'sold'.
            CREATE TABLE IF NOT EXISTS seat_status (
                date_key     TEXT NOT NULL,   -- the date string (valid_date)
                seat_id      TEXT NOT NULL,
                status       TEXT NOT NULL,   -- held | sold
                tid          TEXT,            -- ticket once sold
                hold_token   TEXT,            -- checkout hold owner
                hold_expires TEXT,            -- ISO ts; held rows past this are free
                PRIMARY KEY (date_key, seat_id)
            );
            """
        )
        conn.commit()
    finally:
        conn.close()

    _migrate_ticket_columns()
    _migrate_date_columns()
    _maybe_migrate_from_json()


def _migrate_ticket_columns() -> None:
    """Idempotently add the refund/cancellation columns to an existing tickets
    table. CREATE TABLE IF NOT EXISTS never adds columns to a pre-existing table,
    so we ALTER only the ones missing (guarded by PRAGMA table_info)."""
    wanted = {
        "status": "TEXT DEFAULT 'active'",   # active | cancelled
        "payment_intent": "TEXT",            # Stripe intent backing this ticket
        "refund_id": "TEXT",                 # Stripe refund id once refunded
        "method": "TEXT",                    # stripe | bar | ... (payment method)
        "seat_id": "TEXT",                   # reserved-seating: bound seat id
        "seat_label": "TEXT",                # human seat label e.g. "Row B · 12"
        "location": "TEXT",                  # location id snapshot (seated dates)
        "lang": "TEXT",                      # buyer UI language at purchase (email/PDF)
    }
    conn = get_db()
    try:
        existing = {r["name"] for r in conn.execute("PRAGMA table_info(tickets)").fetchall()}
        for col, decl in wanted.items():
            if col not in existing:
                conn.execute(f"ALTER TABLE tickets ADD COLUMN {col} {decl}")
        conn.commit()
    finally:
        conn.close()


def _migrate_date_columns() -> None:
    """Idempotently add newer columns to an existing dates table.
    CREATE TABLE IF NOT EXISTS never adds columns to a pre-existing table, so we
    ALTER only the ones missing (guarded by PRAGMA table_info)."""
    wanted = {
        "location": "TEXT",
        "seating": "INTEGER DEFAULT 0",   # 1 = reserved seating (seat map)
    }
    conn = get_db()
    try:
        existing = {r["name"] for r in conn.execute("PRAGMA table_info(dates)").fetchall()}
        for col, decl in wanted.items():
            if col not in existing:
                conn.execute(f"ALTER TABLE dates ADD COLUMN {col} {decl}")
        conn.commit()
    finally:
        conn.close()


def _tables_empty() -> bool:
    conn = get_db()
    try:
        show_rows = conn.execute("SELECT COUNT(*) AS c FROM show").fetchone()["c"]
        ticket_rows = conn.execute("SELECT COUNT(*) AS c FROM tickets").fetchone()["c"]
        date_rows = conn.execute("SELECT COUNT(*) AS c FROM dates").fetchone()["c"]
        stat_rows = conn.execute("SELECT COUNT(*) AS c FROM daily_stats").fetchone()["c"]
    finally:
        conn.close()
    return (show_rows + ticket_rows + date_rows + stat_rows) == 0


def _maybe_migrate_from_json() -> None:
    """If the DB is empty and legacy JSON files exist, import them once."""
    try:
        if not _tables_empty():
            return
    except Exception as e:
        logger.error(f"Could not check DB emptiness: {e}")
        return

    migrated = False

    # shows.json -> show + dates
    if os.path.isfile(SHOWS_JSON):
        try:
            with open(SHOWS_JSON, "r", encoding="utf-8") as f:
                show_data = json.load(f)
            save_show(show_data)
            migrated = True
            logger.info("Migrated shows.json into SQLite.")
        except Exception as e:
            logger.error(f"Failed to migrate shows.json: {e}")

    # tickets.json -> tickets
    if os.path.isfile(TICKETS_JSON):
        try:
            with open(TICKETS_JSON, "r", encoding="utf-8") as f:
                tickets = json.load(f)
            for tid, ticket in tickets.items():
                save_tickets(tid, ticket)
            migrated = True
            logger.info(f"Migrated {len(tickets)} tickets into SQLite.")
        except Exception as e:
            logger.error(f"Failed to migrate tickets.json: {e}")

    # stats.json -> daily_stats
    if os.path.isfile(STATS_JSON):
        try:
            with open(STATS_JSON, "r", encoding="utf-8") as f:
                stats = json.load(f)
            _save_stats_dict(stats)
            migrated = True
            logger.info("Migrated stats.json into SQLite.")
        except Exception as e:
            logger.error(f"Failed to migrate stats.json: {e}")

    if migrated:
        logger.success("Legacy JSON -> SQLite migration complete (JSON kept as backup).")


def migrate_json_to_sqlite() -> None:
    """Runnable entry point to (re-)import the legacy JSON files into SQLite.
    Unlike the auto-migration, this runs unconditionally (upserts), so it can
    be used to re-sync from the JSON backups. JSON files are never deleted."""
    init_db()
    if os.path.isfile(SHOWS_JSON):
        with open(SHOWS_JSON, "r", encoding="utf-8") as f:
            save_show(json.load(f))
        logger.info("Re-imported shows.json.")
    if os.path.isfile(TICKETS_JSON):
        with open(TICKETS_JSON, "r", encoding="utf-8") as f:
            for tid, ticket in json.load(f).items():
                save_tickets(tid, ticket)
        logger.info("Re-imported tickets.json.")
    if os.path.isfile(STATS_JSON):
        with open(STATS_JSON, "r", encoding="utf-8") as f:
            _save_stats_dict(json.load(f))
        logger.info("Re-imported stats.json.")
    logger.success("Manual JSON -> SQLite migration complete.")


# --------------------------------------------------------------------------- #
# SHOW (reassemble / decompose the nested shows.json dict)
# --------------------------------------------------------------------------- #
def load_show() -> Dict[str, Any]:
    """Reassemble the full nested show dict (same shape as shows.json):
    orga_name, title, subtitle, banner, logo, wallpaper, store_lock,
    payment_methods, votes{count,value,average[,comments]},
    dates{"1":{...},...}, plus any extra top-level keys (screens, stripe, ...)."""
    conn = get_db()
    try:
        row = conn.execute(
            "SELECT * FROM show WHERE id = ?", (SHOW_ID,)
        ).fetchone()
        date_rows = conn.execute(
            "SELECT * FROM dates ORDER BY position, date_key"
        ).fetchall()
    finally:
        conn.close()

    if row is None:
        # No show stored yet: return an empty-but-shaped dict.
        return {"votes": {"count": 0, "value": 0, "average": 0}, "dates": {}}

    show: Dict[str, Any] = {}
    for key in _SHOW_SCALAR_KEYS:
        show[key] = row[key]
    show["store_lock"] = bool(row["store_lock"])

    votes: Dict[str, Any] = {
        "count": row["votes_count"] if row["votes_count"] is not None else 0,
        "value": row["votes_value"] if row["votes_value"] is not None else 0,
        "average": row["votes_average"] if row["votes_average"] is not None else 0,
    }
    if row["votes_comments"]:
        try:
            comments = json.loads(row["votes_comments"])
            if comments:
                votes["comments"] = comments
        except (ValueError, TypeError):
            pass
    show["votes"] = votes

    dates: Dict[str, Any] = {}
    for d in date_rows:
        dates[d["date_key"]] = {
            "date": d["date"],
            "time": d["time"],
            "tickets": d["tickets"],
            "tickets_available": d["tickets_available"],
            "price": d["price"],
            "location": d["location"],
            "seating": bool(d["seating"]) if d["seating"] is not None else False,
        }
    show["dates"] = dates

    # Re-merge any unmapped top-level keys (screens, stripe, future keys).
    if row["extras"]:
        try:
            extras = json.loads(row["extras"])
            if isinstance(extras, dict):
                for k, v in extras.items():
                    show[k] = v
        except (ValueError, TypeError):
            pass

    return show


def save_show(data: dict) -> None:
    """Decompose the nested show dict back INTO the relational tables (upsert).
    The "1"/"2" date keys are preserved. Any top-level keys we don't have a
    dedicated column for are stored verbatim in `extras` so nothing is lost."""
    if not isinstance(data, dict):
        raise ValueError("save_show expects a dict")

    votes = data.get("votes") or {}
    if not isinstance(votes, dict):
        votes = {}
    votes_comments = votes.get("comments")
    comments_json = (
        json.dumps(votes_comments, ensure_ascii=False)
        if isinstance(votes_comments, list) and votes_comments
        else None
    )

    extras = {k: v for k, v in data.items() if k not in _SHOW_HANDLED_KEYS}
    extras_json = json.dumps(extras, ensure_ascii=False) if extras else None

    conn = get_db()
    try:
        conn.execute(
            """
            INSERT INTO show (
                id, orga_name, title, subtitle, banner, logo, wallpaper,
                store_lock, payment_methods, votes_count, votes_value,
                votes_average, votes_comments, extras
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(id) DO UPDATE SET
                orga_name=excluded.orga_name,
                title=excluded.title,
                subtitle=excluded.subtitle,
                banner=excluded.banner,
                logo=excluded.logo,
                wallpaper=excluded.wallpaper,
                store_lock=excluded.store_lock,
                payment_methods=excluded.payment_methods,
                votes_count=excluded.votes_count,
                votes_value=excluded.votes_value,
                votes_average=excluded.votes_average,
                votes_comments=excluded.votes_comments,
                extras=excluded.extras
            """,
            (
                SHOW_ID,
                data.get("orga_name"),
                data.get("title"),
                data.get("subtitle"),
                data.get("banner"),
                data.get("logo"),
                data.get("wallpaper"),
                1 if data.get("store_lock") else 0,
                data.get("payment_methods"),
                int(votes.get("count", 0) or 0),
                int(votes.get("value", 0) or 0),
                float(votes.get("average", 0) or 0),
                comments_json,
                extras_json,
            ),
        )

        # Rewrite the dates table to exactly match the incoming dict (this
        # mirrors the old whole-file overwrite and supports add/delete by key).
        dates = data.get("dates")
        if isinstance(dates, dict):
            conn.execute("DELETE FROM dates")
            for position, (date_key, value) in enumerate(dates.items()):
                if not isinstance(value, dict):
                    continue
                conn.execute(
                    """
                    INSERT INTO dates (
                        date_key, date, time, tickets, tickets_available,
                        price, location, seating, position
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    """,
                    (
                        str(date_key),
                        value.get("date"),
                        value.get("time"),
                        value.get("tickets"),
                        value.get("tickets_available"),
                        value.get("price"),
                        value.get("location"),
                        1 if value.get("seating") else 0,
                        position,
                    ),
                )

        conn.commit()
    finally:
        conn.close()


def load_date(date: str):
    """Return the single date entry dict matching `date` (same shape as today),
    or {} if not found."""
    conn = get_db()
    try:
        d = conn.execute(
            "SELECT * FROM dates WHERE date = ?", (date,)
        ).fetchone()
    finally:
        conn.close()

    if d is None:
        print(f"No show found for date: {date}")
        return {}

    result = {
        "date": d["date"],
        "time": d["time"],
        "tickets": d["tickets"],
        "tickets_available": d["tickets_available"],
        "price": d["price"],
        "location": d["location"],
        "seating": bool(d["seating"]) if d["seating"] is not None else False,
    }
    print("Found show:", result)
    return result


def save_date(date: str, updated_data) -> None:
    """Update the single date row matching `date` with updated_data's fields."""
    if not isinstance(updated_data, dict):
        raise ValueError("save_date expects updated_data to be a dict")

    conn = get_db()
    try:
        conn.execute(
            """
            UPDATE dates SET
                date = ?,
                time = ?,
                tickets = ?,
                tickets_available = ?,
                price = ?,
                location = ?
            WHERE date = ?
            """,
            (
                updated_data.get("date", date),
                updated_data.get("time"),
                updated_data.get("tickets"),
                updated_data.get("tickets_available"),
                updated_data.get("price"),
                updated_data.get("location"),
                date,
            ),
        )
        conn.commit()
    finally:
        conn.close()


def decrement_availability(date: str, n: int) -> bool:
    """Atomically reserve `n` tickets for `date`. Returns True if the seats
    were available and decremented, False if it would oversell (no row matched).
    Single-statement UPDATE inside one transaction prevents the read-modify-write
    race between concurrent ticket-creation requests."""
    if n < 1:
        return False
    conn = get_db()
    try:
        cur = conn.execute(
            """
            UPDATE dates
               SET tickets_available = tickets_available - ?
             WHERE date = ?
               AND tickets_available >= ?
            """,
            (n, date, n),
        )
        conn.commit()
        return cur.rowcount > 0
    finally:
        conn.close()


def increment_availability(date: str, n: int) -> None:
    """Give `n` seats back to `date` on cancellation/refund, capped so the
    available count never exceeds the configured total `tickets` (a refund must
    not magically inflate capacity). Single-statement UPDATE so it is race-safe.
    Differs from release_availability (uncapped rollback of a fresh reservation):
    this is the inverse of a *settled* sale and is bounded by the cap."""
    if n < 1:
        return
    conn = get_db()
    try:
        conn.execute(
            """
            UPDATE dates
               SET tickets_available = MIN(tickets, tickets_available + ?)
             WHERE date = ?
            """,
            (n, date),
        )
        conn.commit()
    finally:
        conn.close()


def mark_ticket_cancelled(tid: str) -> bool:
    """Atomically flip a ticket from active -> cancelled. Returns True only if
    THIS call won (the ticket was still active), False if it was already
    cancelled. This single transition is the idempotency guard that prevents a
    double seat-release / double refund from concurrent or repeated cancels."""
    conn = get_db()
    try:
        cur = conn.execute(
            """
            UPDATE tickets
               SET status = 'cancelled', valid = 0
             WHERE tid = ?
               AND (status IS NULL OR status <> 'cancelled')
            """,
            (tid,),
        )
        conn.commit()
        return cur.rowcount > 0
    finally:
        conn.close()


def set_ticket_refund(tid: str, refund_id: str) -> None:
    """Record the Stripe refund id on a (already cancelled) ticket."""
    conn = get_db()
    try:
        conn.execute(
            "UPDATE tickets SET refund_id = ? WHERE tid = ?",
            (refund_id, tid),
        )
        conn.commit()
    finally:
        conn.close()


def get_intent_for_ticket(tid: str) -> Optional[str]:
    """Best-effort lookup of the Stripe payment_intent tied to a ticket: prefer
    the column on the ticket, fall back to the payment_intents consumption log."""
    conn = get_db()
    try:
        row = conn.execute(
            "SELECT payment_intent FROM tickets WHERE tid = ?", (tid,)
        ).fetchone()
        if row is not None and row["payment_intent"]:
            return row["payment_intent"]
        pi = conn.execute(
            "SELECT payment_intent_id FROM payment_intents WHERE tid = ?", (tid,)
        ).fetchone()
        return pi["payment_intent_id"] if pi is not None else None
    finally:
        conn.close()


def release_availability(date: str, n: int) -> None:
    """Atomically give `n` reserved tickets back to `date` (compensating action,
    the inverse of decrement_availability). Used to roll back a reservation when
    a ticket-creation flow fails after seats were decremented. Single-statement
    UPDATE so it is race-safe against concurrent reservations."""
    if n < 1:
        return
    conn = get_db()
    try:
        conn.execute(
            """
            UPDATE dates
               SET tickets_available = tickets_available + ?
             WHERE date = ?
            """,
            (n, date),
        )
        conn.commit()
    finally:
        conn.close()


# --------------------------------------------------------------------------- #
# TICKETS
# --------------------------------------------------------------------------- #
def _row_to_ticket(row: sqlite3.Row) -> Dict[str, Any]:
    try:
        attempts = json.loads(row["access_attempts"]) if row["access_attempts"] else []
    except (ValueError, TypeError):
        attempts = []
    keys = row.keys()
    return {
        "tid": row["tid"],
        "first_name": row["first_name"],
        "last_name": row["last_name"],
        "email": row["email"],
        "paid": bool(row["paid"]),
        "valid_date": row["valid_date"],
        "type": row["type"],
        "valid": bool(row["valid"]),
        "used_at": row["used_at"],
        "access_attempts": attempts,
        # Refund/cancellation columns (added by _migrate_ticket_columns); guard
        # with key checks so a row read before the migration can't KeyError.
        "status": (row["status"] if "status" in keys else None) or "active",
        "payment_intent": row["payment_intent"] if "payment_intent" in keys else None,
        "refund_id": row["refund_id"] if "refund_id" in keys else None,
        "method": row["method"] if "method" in keys else None,
        # Reserved-seating columns (added by _migrate_ticket_columns); guard with
        # key checks so a row read before the migration can't KeyError.
        "seat_id": row["seat_id"] if "seat_id" in keys else None,
        "seat_label": row["seat_label"] if "seat_label" in keys else None,
        "location": row["location"] if "location" in keys else None,
        "lang": row["lang"] if "lang" in keys else None,
    }


def load_tickets() -> Dict[str, Dict]:
    """{tid: ticket_dict} with access_attempts deserialized back into a list."""
    conn = get_db()
    try:
        rows = conn.execute("SELECT * FROM tickets").fetchall()
    finally:
        conn.close()
    return {row["tid"]: _row_to_ticket(row) for row in rows}


def load_ticket_id(tid: str) -> Optional[Dict]:
    """Return the ticket dict for `tid`, or None ONLY if the row genuinely does
    not exist. Real sqlite/DB errors (locks, transient failures) are allowed to
    PROPAGATE so the caller can distinguish "not found" from "could not look up"
    and respond with a 500 instead of falsely denying a valid customer. The only
    swallowed error is a corrupt access_attempts JSON blob, narrowly handled
    inside _row_to_ticket so one bad row doesn't crash the lookup."""
    conn = get_db()
    try:
        row = conn.execute(
            "SELECT * FROM tickets WHERE tid = ?", (tid,)
        ).fetchone()
    finally:
        conn.close()
    if row is None:
        return None
    return _row_to_ticket(row)


def save_tickets(tid: str, new_ticket: dict) -> None:
    """Upsert one ticket. access_attempts (a list) is serialized to JSON text."""
    attempts = new_ticket.get("access_attempts", [])
    if not isinstance(attempts, list):
        attempts = []
    attempts_json = json.dumps(attempts, ensure_ascii=False)

    conn = get_db()
    try:
        conn.execute(
            """
            INSERT INTO tickets (
                tid, first_name, last_name, email, paid, valid_date,
                type, valid, used_at, access_attempts,
                status, payment_intent, refund_id, method,
                seat_id, seat_label, location, lang
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(tid) DO UPDATE SET
                first_name=excluded.first_name,
                last_name=excluded.last_name,
                email=excluded.email,
                paid=excluded.paid,
                valid_date=excluded.valid_date,
                type=excluded.type,
                valid=excluded.valid,
                used_at=excluded.used_at,
                access_attempts=excluded.access_attempts,
                status=excluded.status,
                payment_intent=excluded.payment_intent,
                refund_id=excluded.refund_id,
                method=excluded.method,
                seat_id=excluded.seat_id,
                seat_label=excluded.seat_label,
                location=excluded.location,
                lang=excluded.lang
            """,
            (
                tid,
                new_ticket.get("first_name"),
                new_ticket.get("last_name"),
                new_ticket.get("email"),
                1 if new_ticket.get("paid") else 0,
                new_ticket.get("valid_date"),
                new_ticket.get("type"),
                1 if new_ticket.get("valid") else 0,
                new_ticket.get("used_at"),
                attempts_json,
                new_ticket.get("status") or "active",
                new_ticket.get("payment_intent"),
                new_ticket.get("refund_id"),
                new_ticket.get("method"),
                new_ticket.get("seat_id"),
                new_ticket.get("seat_label"),
                new_ticket.get("location"),
                new_ticket.get("lang"),
            ),
        )
        conn.commit()
    finally:
        conn.close()


def mark_ticket_used(tid: str, used_at: str) -> bool:
    """Atomically mark a ticket used. Returns True if THIS call won the race
    (the ticket was still unused), False if it was already used. Prevents the
    same ticket being accepted twice by concurrent validations."""
    conn = get_db()
    try:
        cur = conn.execute(
            """
            UPDATE tickets
               SET used_at = ?, valid = 0
             WHERE tid = ?
               AND used_at IS NULL
            """,
            (used_at, tid),
        )
        conn.commit()
        return cur.rowcount > 0
    finally:
        conn.close()


def append_access_attempt(tid: str, entry: dict) -> None:
    """Atomically append a single audit entry to a ticket's access_attempts JSON
    list, without rewriting the whole row. Uses SQLite JSON1 json_insert with the
    '$[#]' append path so concurrent audit appends and other column writes cannot
    clobber each other (no read-modify-write in Python)."""
    entry_json = json.dumps(entry, ensure_ascii=False)
    conn = get_db()
    try:
        conn.execute(
            """
            UPDATE tickets
               SET access_attempts = json_insert(
                   COALESCE(access_attempts, '[]'), '$[#]', json(?)
               )
             WHERE tid = ?
            """,
            (entry_json, tid),
        )
        conn.commit()
    finally:
        conn.close()


# --------------------------------------------------------------------------- #
# PAYMENT INTENTS (Stripe idempotency — consume each intent at most once)
# --------------------------------------------------------------------------- #
def is_intent_used(payment_intent_id: str) -> bool:
    """True if this Stripe payment_intent id has already been consumed."""
    conn = get_db()
    try:
        row = conn.execute(
            "SELECT 1 FROM payment_intents WHERE payment_intent_id = ?",
            (payment_intent_id,),
        ).fetchone()
    finally:
        conn.close()
    return row is not None


def mark_intent_used(payment_intent_id: str, tid: str, amount) -> bool:
    """Atomically record consumption of a Stripe payment_intent. Returns True if
    this call newly recorded it, False if it was already recorded. Race-safe:
    INSERT OR IGNORE + changes() means only one concurrent caller can win."""
    from assets.timeutil import local_now

    used_at = local_now().strftime("%Y.%m.%d - %H:%M:%S")
    conn = get_db()
    try:
        cur = conn.execute(
            """
            INSERT OR IGNORE INTO payment_intents
                (payment_intent_id, tid, amount, used_at)
            VALUES (?, ?, ?, ?)
            """,
            (payment_intent_id, tid, amount, used_at),
        )
        conn.commit()
        return cur.rowcount > 0
    finally:
        conn.close()


# --------------------------------------------------------------------------- #
# STATS helpers (used by stats.py; shapes preserved)
# --------------------------------------------------------------------------- #
def _load_stats_dict() -> Dict[str, Any]:
    conn = get_db()
    try:
        rows = conn.execute("SELECT * FROM daily_stats").fetchall()
    finally:
        conn.close()
    sales_by_date: Dict[str, Any] = {}
    income_by_date: Dict[str, Any] = {}
    for r in rows:
        sales_by_date[r["date"]] = r["sales"]
        income_by_date[r["date"]] = r["income"]
    return {"sales_by_date": sales_by_date, "income_by_date": income_by_date}


def _save_stats_dict(stats: Dict[str, Any]) -> None:
    sales = (stats or {}).get("sales_by_date", {}) or {}
    income = (stats or {}).get("income_by_date", {}) or {}
    all_dates = set(sales.keys()) | set(income.keys())
    conn = get_db()
    try:
        for date in all_dates:
            conn.execute(
                """
                INSERT INTO daily_stats (date, sales, income)
                VALUES (?, ?, ?)
                ON CONFLICT(date) DO UPDATE SET
                    sales=excluded.sales,
                    income=excluded.income
                """,
                (date, sales.get(date, 0) or 0, income.get(date, 0) or 0),
            )
        conn.commit()
    finally:
        conn.close()


def checkin_stats() -> Dict[str, Dict[str, int]]:
    """Per-date door check-in counts derived from the tickets table.
    A ticket counts as SOLD once it exists and isn't cancelled, and as
    CHECKED_IN once it has a used_at timestamp. Admin (unlimited) and dateless
    'Unlimited' tickets are excluded — they aren't per-date attendees.
    Returns {date: {"sold", "checked_in", "pending"}}."""
    conn = get_db()
    try:
        rows = conn.execute(
            """
            SELECT valid_date AS d,
                   COUNT(*) AS sold,
                   SUM(CASE WHEN used_at IS NOT NULL AND used_at <> ''
                            THEN 1 ELSE 0 END) AS checked_in
              FROM tickets
             WHERE (status IS NULL OR status <> 'cancelled')
               AND (type IS NULL OR type <> 'admin')
               AND valid_date IS NOT NULL AND valid_date <> 'Unlimited'
             GROUP BY valid_date
            """
        ).fetchall()
    finally:
        conn.close()
    out: Dict[str, Dict[str, int]] = {}
    for r in rows:
        sold = r["sold"] or 0
        ci = r["checked_in"] or 0
        out[r["d"]] = {"sold": sold, "checked_in": ci, "pending": max(0, sold - ci)}
    return out


def recent_checkins(limit: int = 20) -> list:
    """Most-recently checked-in tickets (used_at set), newest first. used_at is a
    zero-padded 'YYYY.MM.DD - HH:MM:SS' string, so a lexical DESC sort is also
    chronological. Cancelled tickets are excluded."""
    conn = get_db()
    try:
        rows = conn.execute(
            """
            SELECT tid, first_name, last_name, valid_date, used_at, seat_label
              FROM tickets
             WHERE used_at IS NOT NULL AND used_at <> ''
               AND (status IS NULL OR status <> 'cancelled')
             ORDER BY used_at DESC
             LIMIT ?
            """,
            (int(limit),),
        ).fetchall()
    finally:
        conn.close()
    result = []
    for r in rows:
        keys = r.keys()
        name = ((r["first_name"] or "") + " " + (r["last_name"] or "")).strip()
        result.append(
            {
                "tid": r["tid"],
                "name": name,
                "valid_date": r["valid_date"],
                "used_at": r["used_at"],
                "seat_label": r["seat_label"] if "seat_label" in keys else None,
            }
        )
    return result


def log_sale(date: str, count: int, income: float) -> None:
    """Atomically upsert a sale into daily_stats for `date`, accumulating sales
    and income. Single INSERT ... ON CONFLICT statement so concurrent buys can't
    lose updates (no read-modify-write in Python)."""
    conn = get_db()
    try:
        conn.execute(
            """
            INSERT INTO daily_stats (date, sales, income)
            VALUES (?, ?, ?)
            ON CONFLICT(date) DO UPDATE SET
                sales=sales+excluded.sales,
                income=income+excluded.income
            """,
            (date, int(count or 0), float(income or 0)),
        )
        conn.commit()
    finally:
        conn.close()


# Initialize the database (and run the one-time JSON migration) on import.
init_db()


def img_show(app=quart.Quart):
    @app.route("/image/show")  # pyright: ignore[reportCallIssue]
    async def show_img():
        # These images are keyed by an opaque per-image token (not the public
        # branding slots, which are served unauthenticated via /api/image/get).
        # Treat them as protected like every other non-branding endpoint.
        auth_key = quart.request.headers.get("Authorization")
        if not auth_key or not hmac.compare_digest(
            str(auth_key), str(config.Auth.auth_key)
        ):
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        tid = quart.request.args.get("img")
        if not tid:
            return quart.jsonify({"status": "error", "message": "Missing img"}), 400
        # Prevent path traversal: only allow a plain filename inside ./images
        safe_path = safe_join("./images", f"{tid}.png")
        if safe_path is None or not os.path.isfile(safe_path):
            return quart.jsonify({"status": "error", "message": "Image not found"}), 404
        return await quart.send_file(safe_path)


# --------------------------------------------------------------------------- #
# RESERVED SEATING
#
# One layout per location (`seatmaps`), plus per-date occupancy (`seat_status`).
# A seat is FREE when it has no seat_status row (or only an expired hold). The
# hold/bind operations run in one IMMEDIATE transaction so two buyers can never
# claim the same seat.
# --------------------------------------------------------------------------- #

def load_seatmap(location_id: str) -> Dict[str, Any]:
    """Return {"layout": {...}, "categories": [...]} for a location.
    Empty scaffold if none saved yet."""
    conn = get_db()
    try:
        row = conn.execute(
            "SELECT layout, categories FROM seatmaps WHERE location_id = ?",
            (location_id,),
        ).fetchone()
    finally:
        conn.close()
    if row is None:
        return {"layout": {"elements": []}, "categories": []}
    try:
        layout = json.loads(row["layout"]) if row["layout"] else {"elements": []}
    except (ValueError, TypeError):
        layout = {"elements": []}
    try:
        categories = json.loads(row["categories"]) if row["categories"] else []
    except (ValueError, TypeError):
        categories = []
    if not isinstance(layout, dict):
        layout = {"elements": []}
    layout.setdefault("elements", [])
    return {"layout": layout, "categories": categories}


def save_seatmap(location_id: str, layout: dict, categories: list) -> None:
    """Upsert the layout + categories JSON for a location."""
    if not location_id:
        raise ValueError("save_seatmap requires a location_id")
    layout = layout if isinstance(layout, dict) else {"elements": []}
    layout.setdefault("elements", [])
    categories = categories if isinstance(categories, list) else []
    from assets.timeutil import local_now
    conn = get_db()
    try:
        conn.execute(
            """
            INSERT INTO seatmaps (location_id, layout, categories, updated_at)
            VALUES (?, ?, ?, ?)
            ON CONFLICT(location_id) DO UPDATE SET
                layout=excluded.layout,
                categories=excluded.categories,
                updated_at=excluded.updated_at
            """,
            (
                location_id,
                json.dumps(layout, ensure_ascii=False),
                json.dumps(categories, ensure_ascii=False),
                local_now().isoformat(),
            ),
        )
        conn.commit()
    finally:
        conn.close()


def seat_elements(layout: dict) -> list:
    """All seat-type elements from a layout dict."""
    if not isinstance(layout, dict):
        return []
    return [
        e for e in layout.get("elements", [])
        if isinstance(e, dict) and e.get("type") == "seat" and e.get("id")
    ]


def seat_index(location_id: str) -> Dict[str, Dict[str, Any]]:
    """Map seat_id -> {row, number, category_id, label} for a location's map."""
    sm = load_seatmap(location_id)
    idx: Dict[str, Dict[str, Any]] = {}
    for s in seat_elements(sm["layout"]):
        row = s.get("row")
        number = s.get("number")
        if row and number:
            label = f"{row} · {number}"
        elif row:
            label = str(row)
        else:
            label = str(number) if number else str(s["id"])
        idx[str(s["id"])] = {
            "row": row,
            "number": number,
            "category_id": s.get("category_id"),
            "label": label,
        }
    return idx


def location_capacity(location_id: str) -> int:
    """Total seat count in a location's saved map (= capacity for seated dates)."""
    return len(seat_elements(load_seatmap(location_id)["layout"]))


def _sweep_expired_holds(conn: sqlite3.Connection, date_key: str) -> None:
    """Delete held rows whose TTL has passed (they are free again)."""
    conn.execute(
        "DELETE FROM seat_status WHERE date_key = ? AND status = 'held' "
        "AND hold_expires IS NOT NULL AND CAST(hold_expires AS REAL) < ?",
        (date_key, time.time()),
    )


def seat_occupancy(date_key: str) -> Dict[str, str]:
    """seat_id -> 'sold' | 'held' for a date (after expiring stale holds).
    Seats not present are free."""
    conn = get_db()
    try:
        _sweep_expired_holds(conn, date_key)
        conn.commit()
        rows = conn.execute(
            "SELECT seat_id, status FROM seat_status WHERE date_key = ?",
            (date_key,),
        ).fetchall()
    finally:
        conn.close()
    return {r["seat_id"]: r["status"] for r in rows}


def hold_seats(date_key: str, seat_ids: list, ttl_seconds: int):
    """Atomically place a checkout hold on `seat_ids` for a date.
    Returns (ok: bool, token: str|None, taken: list[str]). If any requested
    seat is already sold or actively held, nothing is held and it is listed
    in `taken`."""
    seat_ids = [str(s) for s in seat_ids if s]
    if not seat_ids:
        return False, None, []
    token = secrets.token_hex(16)
    expires = time.time() + max(30, int(ttl_seconds))
    conn = get_db()
    try:
        conn.execute("BEGIN IMMEDIATE")
        _sweep_expired_holds(conn, date_key)
        placeholders = ",".join("?" * len(seat_ids))
        taken_rows = conn.execute(
            f"SELECT seat_id FROM seat_status WHERE date_key = ? "
            f"AND seat_id IN ({placeholders})",
            (date_key, *seat_ids),
        ).fetchall()
        taken = [r["seat_id"] for r in taken_rows]
        if taken:
            conn.rollback()
            return False, None, taken
        for sid in seat_ids:
            conn.execute(
                "INSERT INTO seat_status (date_key, seat_id, status, hold_token, "
                "hold_expires) VALUES (?, ?, 'held', ?, ?)",
                (date_key, sid, token, str(expires)),
            )
        conn.commit()
        return True, token, []
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


def release_hold(date_key: str, hold_token: str) -> int:
    """Drop all still-held (not sold) seats owned by `hold_token`. Returns the
    number of seats freed."""
    if not hold_token:
        return 0
    conn = get_db()
    try:
        cur = conn.execute(
            "DELETE FROM seat_status WHERE date_key = ? AND hold_token = ? "
            "AND status = 'held'",
            (date_key, hold_token),
        )
        conn.commit()
        return cur.rowcount or 0
    finally:
        conn.close()


def bind_seats(date_key: str, mapping: Dict[str, str], hold_token: Optional[str] = None):
    """Atomically mark seats as sold. `mapping` is seat_id -> tid. Succeeds only
    if none of the seats is already sold (a stale/expired hold or matching hold
    token is re-claimed). Returns (ok: bool, taken: list[str])."""
    seat_ids = [str(s) for s in mapping.keys() if s]
    if not seat_ids:
        return True, []
    conn = get_db()
    try:
        conn.execute("BEGIN IMMEDIATE")
        _sweep_expired_holds(conn, date_key)
        placeholders = ",".join("?" * len(seat_ids))
        rows = conn.execute(
            f"SELECT seat_id, status, hold_token FROM seat_status "
            f"WHERE date_key = ? AND seat_id IN ({placeholders})",
            (date_key, *seat_ids),
        ).fetchall()
        taken = []
        for r in rows:
            # A seat already sold, or actively held by a DIFFERENT checkout,
            # cannot be claimed by this order.
            if r["status"] == "sold":
                taken.append(r["seat_id"])
            elif r["status"] == "held" and hold_token and r["hold_token"] != hold_token:
                taken.append(r["seat_id"])
        if taken:
            conn.rollback()
            return False, taken
        for sid, tid in mapping.items():
            conn.execute(
                "INSERT INTO seat_status (date_key, seat_id, status, tid, "
                "hold_token, hold_expires) VALUES (?, ?, 'sold', ?, NULL, NULL) "
                "ON CONFLICT(date_key, seat_id) DO UPDATE SET "
                "status='sold', tid=excluded.tid, hold_token=NULL, hold_expires=NULL",
                (date_key, str(sid), str(tid)),
            )
        conn.commit()
        return True, []
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


def release_seats_for_ticket(date_key: str, tid: str) -> int:
    """Free the seat(s) bound to a ticket (used on cancellation)."""
    conn = get_db()
    try:
        cur = conn.execute(
            "DELETE FROM seat_status WHERE date_key = ? AND tid = ?",
            (date_key, tid),
        )
        conn.commit()
        return cur.rowcount or 0
    finally:
        conn.close()
