"""Exports for bookkeeping and evaluation, without database access.

    GET /api/export/tickets.csv    one row per ticket
    GET /api/export/attempts.csv   one row per scan at the door
    GET /api/export/revenue.csv    one row per day: statistics and payments

Query parameters:
    date               a performance date (YYYY-MM-DD) or "Unlimited"; empty
                       means all (tickets and attempts only)
    include_cancelled  1 to include cancelled tickets (tickets only)
    format             "excel" (default): UTF-8 with BOM, ";" and decimal
                       comma, dates as TT.MM.JJJJ HH:MM, a total row;
                       "plain": ",", decimal point and ISO dates for tools

Names and emails come from buyers, so every text cell that a spreadsheet
would read as a formula (= + - @ tab CR) gets a leading apostrophe.
"""

import asyncio
import csv
import io
import re
from typing import Dict, Iterable, List, Optional

import quart

from assets.data import daily_stats_rows, export_tickets
from assets.ticket_manager import _authorized
from assets.timeutil import today_iso
from reds_simple_logger import Logger

logger = Logger()
logger.success("Export.py loaded")

_DATE_RE = re.compile(r"^\d{4}-\d{2}-\d{2}$")
_TS_RE = re.compile(r"(\d{4})[-.](\d{2})[-.](\d{2})(?:\D+(\d{2}):(\d{2})(?::(\d{2}))?)?")
_FORMULA_START = ("=", "+", "-", "@", "\t", "\r")
CHUNK_ROWS = 500

METHOD_LABELS = {"bar": "Bar", "card": "Karte", "stripe": "Online (Stripe)", "paid": "bezahlt", "free": "kostenlos"}
TYPE_LABELS = {"visitor": "Besucher", "vip": "VIP", "admin": "Admin"}
SCAN_LABELS = {
    "valid": "Einlass",
    "valid - ADMIN ACCESS": "Einlass (Admin)",
    "already_used": "Bereits benutzt",
    "not_paid": "Nicht bezahlt",
    "invalid_date": "Falscher Tag",
}


class Num(str):
    """A formatted number: written as is, never escaped as a formula."""


class Fmt:
    def __init__(self, excel: bool):
        self.excel = excel
        self.delimiter = ";" if excel else ","

    def money(self, v) -> Num:
        s = f"{float(v or 0):.2f}"
        return Num(s.replace(".", ",") if self.excel else s)

    def count(self, v) -> Num:
        return Num(str(int(v or 0)))

    def day(self, iso: Optional[str]) -> str:
        """Performance date "2026-10-03" -> "03.10.2026"; "Unlimited" -> "ohne Termin"."""
        iso = str(iso or "")
        if iso == "Unlimited":
            return "ohne Termin"
        m = _TS_RE.match(iso)
        if not m:
            return iso
        return f"{m[3]}.{m[2]}.{m[1]}" if self.excel else f"{m[1]}-{m[2]}-{m[3]}"

    def ts(self, raw: Optional[str]) -> str:
        """Any stored timestamp ("2026-09-26T18:12:03+02:00", "2026.09.26 - 18:12:03")."""
        m = _TS_RE.search(str(raw or ""))
        if not m:
            return str(raw or "")
        if not m[4]:
            return self.day(m[0])
        if self.excel:
            return f"{m[3]}.{m[2]}.{m[1]} {m[4]}:{m[5]}"
        return f"{m[1]}-{m[2]}-{m[3]} {m[4]}:{m[5]}:{m[6] or '00'}"


def _sort_ts(raw: Optional[str]) -> str:
    m = _TS_RE.search(str(raw or ""))
    return "".join(g or "00" for g in m.groups()) if m else ""


def safe_cell(v) -> str:
    if v is None:
        return ""
    if isinstance(v, Num):
        return str(v)
    s = str(v)
    return "'" + s if s.startswith(_FORMULA_START) else s


# --------------------------------------------------------------------------- #
# Rows
# --------------------------------------------------------------------------- #
TICKET_HEADER = [
    "Ticket-ID", "Vorname", "Nachname", "E-Mail", "Typ", "Kategorie", "Termin", "Sitzplatz",
    "Bezahlt", "Zahlart", "Preis", "Verkäufer", "Gekauft am", "Bezahlt am", "Eingelassen um",
    "Status", "Erstattungs-ID", "Sprache",
]


def ticket_rows(tickets: List[Dict], f: Fmt) -> Iterable[list]:
    for t in tickets:
        method = str(t.get("method") or "")
        yield [
            t.get("tid"), t.get("first_name"), t.get("last_name"), t.get("email"),
            TYPE_LABELS.get(str(t.get("type") or ""), t.get("type")), t.get("category"),
            f.day(t.get("valid_date")), t.get("seat_label"),
            "ja" if t.get("paid") else "nein", METHOD_LABELS.get(method, method),
            f.money(t.get("price")) if t.get("price") is not None else "",
            t.get("seller"), f.ts(t.get("created_at")), f.ts(t.get("paid_at")), f.ts(t.get("used_at")),
            "storniert" if t.get("status") == "cancelled" else "aktiv",
            t.get("refund_id"), t.get("lang"),
        ]


ATTEMPT_HEADER = ["Zeit", "Ticket-ID", "Vorname", "Nachname", "Typ", "Termin", "Ergebnis", "Gerät"]


def attempt_rows(tickets: List[Dict], f: Fmt) -> List[list]:
    rows = []
    for t in tickets:
        for a in t.get("access_attempts") or []:
            if not isinstance(a, dict) or a.get("status") == "cancelled":
                continue  # the cancel audit entry is not a scan
            kind = str(a.get("type") or "")
            rows.append((_sort_ts(a.get("time")), [
                f.ts(a.get("time")), t.get("tid"), t.get("first_name"), t.get("last_name"),
                TYPE_LABELS.get(str(t.get("type") or ""), t.get("type")), f.day(t.get("valid_date")),
                SCAN_LABELS.get(kind, kind or a.get("status")),
                a.get("device") or a.get("scanner") or "",
            ]))
    rows.sort(key=lambda r: r[0])
    return [r[1] for r in rows]


REVENUE_HEADER = [
    "Tag", "Tickets (Statistik)", "Umsatz (Statistik)", "Bar", "Karte", "Online (Stripe)",
    "Sonstige", "Erstattet", "Einnahmen netto",
]
_BUCKETS = {"bar": 0, "card": 1, "stripe": 2}


def revenue_rows(tickets: List[Dict], stats: list, f: Fmt) -> List[list]:
    """One row per calendar day. The statistics columns are the shop's sales
    counter (sale day, refunds subtracted, reservations included). The payment
    columns come from the tickets: money taken on the day it was paid, by
    method; refunds on the day of the cancellation."""
    days: Dict[str, list] = {}

    def bucket(day: str) -> list:
        return days.setdefault(day, [0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0])

    for day, sales, income in stats:
        b = bucket(str(day))
        b[0] += int(sales or 0)
        b[1] += float(income or 0)
    for t in tickets:
        price = float(t.get("price") or 0)
        if not t.get("paid") or price <= 0:
            continue
        m = _TS_RE.search(str(t.get("paid_at") or t.get("created_at") or ""))
        if not m:
            continue
        paid_day = f"{m[1]}-{m[2]}-{m[3]}"
        bucket(paid_day)[2 + _BUCKETS.get(str(t.get("method") or ""), 3)] += price
        if t.get("status") == "cancelled":
            cancel = next((a for a in (t.get("access_attempts") or [])
                           if isinstance(a, dict) and a.get("status") == "cancelled"), None)
            cm = _TS_RE.search(str((cancel or {}).get("time") or ""))
            bucket(f"{cm[1]}-{cm[2]}-{cm[3]}" if cm else paid_day)[6] += price

    rows, total = [], [0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0]
    for day in sorted(days):
        b = days[day]
        total = [x + y for x, y in zip(total, b)]
        rows.append(_revenue_line(f.day(day), b, f))
    if f.excel and rows:
        rows.append(_revenue_line("Summe", total, f))
    return rows


def _revenue_line(label: str, b: list, f: Fmt) -> list:
    net = b[2] + b[3] + b[4] + b[5] - b[6]
    return [label, f.count(b[0]), f.money(b[1]), f.money(b[2]), f.money(b[3]), f.money(b[4]),
            f.money(b[5]), f.money(b[6]), f.money(net)]


# --------------------------------------------------------------------------- #
# Streaming
# --------------------------------------------------------------------------- #
async def _csv_stream(header: list, rows: Iterable[list], f: Fmt):
    buf = io.StringIO()
    w = csv.writer(buf, delimiter=f.delimiter, lineterminator="\r\n")
    if f.excel:
        buf.write("﻿")  # BOM: Excel reads UTF-8 only with it
    w.writerow(header)
    n = 0
    for row in rows:
        w.writerow([safe_cell(v) for v in row])
        n += 1
        if n % CHUNK_ROWS == 0:
            yield buf.getvalue().encode("utf-8")
            buf.seek(0)
            buf.truncate()
            await asyncio.sleep(0)
    yield buf.getvalue().encode("utf-8")


def export_filename(kind: str, date: Optional[str], ext: str) -> str:
    part = f"-termin-{'ohne' if date == 'Unlimited' else date}" if date else ""
    return f"qrgate-{kind}{part}-{today_iso()}.{ext}"


def parse_date(raw: Optional[str]) -> Optional[str]:
    """"" -> None (all), "Unlimited", or a YYYY-MM-DD date. Raises ValueError."""
    raw = str(raw or "").strip()
    if not raw:
        return None
    if raw == "Unlimited" or _DATE_RE.match(raw):
        return raw
    raise ValueError(raw)


def export_routes(app: quart.Quart):
    @app.route("/api/export/<kind>.csv", methods=["GET"])  # type: ignore
    async def export_csv(kind: str):
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        if kind not in ("tickets", "attempts", "revenue"):
            return quart.jsonify({"status": "error", "error": "unknown_export", "message": "Unknown export"}), 404
        args = quart.request.args
        try:
            date = parse_date(args.get("date"))
        except ValueError:
            return quart.jsonify({"status": "error", "error": "invalid_date", "message": "Invalid date"}), 400
        f = Fmt(excel=args.get("format", "excel") != "plain")

        if kind == "tickets":
            include_cancelled = args.get("include_cancelled") in ("1", "true", "yes")
            tickets = await asyncio.to_thread(export_tickets, date, include_cancelled)
            header, rows = TICKET_HEADER, ticket_rows(tickets, f)
        elif kind == "attempts":
            tickets = await asyncio.to_thread(export_tickets, date, True)
            header, rows = ATTEMPT_HEADER, attempt_rows(tickets, f)
        else:
            date = None  # a day of sales is not a performance date
            tickets = await asyncio.to_thread(export_tickets, None, True)
            stats = await asyncio.to_thread(daily_stats_rows)
            header, rows = REVENUE_HEADER, revenue_rows(tickets, stats, f)

        return quart.Response(
            _csv_stream(header, rows, f),
            mimetype="text/csv",
            headers={
                "Content-Type": "text/csv; charset=utf-8",
                "Content-Disposition": f'attachment; filename="{export_filename(kind, date, "csv")}"',
                "Cache-Control": "no-store",
            },
        )
