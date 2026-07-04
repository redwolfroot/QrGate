import quart
import config.conf as config # type: ignore
from assets.data import load_tickets, save_tickets, load_ticket_id
from assets.data import load_date, save_date, load_show, decrement_availability
from assets.data import release_availability, is_intent_used, mark_intent_used
from assets.data import (
    increment_availability,
    mark_ticket_cancelled,
    set_ticket_refund,
    get_intent_for_ticket,
    append_access_attempt,
    seat_index,
    bind_seats,
    release_seats_for_ticket,
)
from assets.stats import log_ticket_sale, log_ticket_refund
from assets.timeutil import local_now
import asyncio
import os
import hashlib
import hmac
import urllib.parse
import urllib.request
import urllib.error
from html import escape
from werkzeug.security import safe_join

from reportlab.lib.pagesizes import A4, A5
from reportlab.lib import colors
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.platypus import (
    SimpleDocTemplate,
    Paragraph,
    Image,
    Spacer,
    Table,
    TableStyle,
    PageBreak,
)
from reportlab.lib.units import inch
import io
import smtplib
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart
from email.mime.application import MIMEApplication
from email.mime.image import MIMEImage
import qrcode, string, random
from reds_simple_logger import Logger
from datetime import datetime
from typing import Optional, Dict, Any, List

logger = Logger()
logger.success("Ticket_manager.py loaded")


def _authorized() -> bool:
    """Timing-safe comparison of the Authorization header against the configured key."""
    key = quart.request.headers.get("Authorization")
    if not key:
        return False
    return hmac.compare_digest(str(key), str(config.Auth.auth_key))


def ticket_token(tid: str) -> str:
    """Derive a short, unguessable per-ticket access token from the secret
    auth_key. Used to gate the otherwise-unauthenticated /codes/pdf
    endpoint so tids (low-entropy, sequential-ish) can't be
    enumerated to harvest other people's QR/PDF and present them at the gate."""
    return hmac.new(
        str(config.Auth.auth_key).encode(),
        str(tid).encode(),
        hashlib.sha256,
    ).hexdigest()[:16]


def _token_valid(tid: str, token: Optional[str]) -> bool:
    """Timing-safe check that `token` matches the expected token for `tid`."""
    if not token:
        return False
    return hmac.compare_digest(str(token), ticket_token(tid))


# ---- avocloud brand palette for PDFs (mirrors frontend/assets/avocloud.css) ----
PDF_CORAL = colors.HexColor("#C73D20")
PDF_CORAL_LIGHT = colors.HexColor("#FFD1C6")
PDF_INK = colors.HexColor("#141414")
PDF_MUTED = colors.HexColor("#6B6B63")
PDF_LINE = colors.HexColor("#DCD8CB")

# A5 box-office ticket geometry
SIMPLE_MARGIN = 26
SIMPLE_CONTENT_W = A5[0] - 2 * SIMPLE_MARGIN

# A4 public-shop ticket geometry
STD_MARGIN = 36
STD_CONTENT_W = A4[0] - 2 * STD_MARGIN


def _fmt_ticket_date(d: str) -> str:
    """ISO yyyy-mm-dd -> dd.mm.yyyy; pass through anything else (e.g. Unlimited)."""
    if not d or d == "Unlimited":
        return d
    try:
        return datetime.strptime(d, "%Y-%m-%d").strftime("%d.%m.%Y")
    except Exception:
        return d


def _location_for_date(date: str, show_data: dict):
    """Resolve the (name, address) of the location a given event `date` belongs
    to, using the show's `locations` map and the per-date `location` id. Returns
    ("", "") for dateless/Unlimited tickets or when no location is assigned."""
    if not date or date == "Unlimited":
        return ("", "")
    locations = show_data.get("locations") or {}
    for d in (show_data.get("dates") or {}).values():
        if isinstance(d, dict) and d.get("date") == date:
            loc_id = d.get("location")
            loc = locations.get(loc_id) if loc_id else None
            if isinstance(loc, dict):
                return (
                    str(loc.get("name") or "").strip(),
                    str(loc.get("address") or "").strip(),
                )
            break
    return ("", "")


def _meaningful(s) -> bool:
    """True if `s` is a real value (not blank / Unknown / None placeholder)."""
    return bool(s) and str(s).strip().lower() not in ("", "unknown", "none")


def _qr_png_bytes(tid: str) -> bytes:
    """Render the QR for `tid` as PNG bytes, in memory. The QR is cheap to
    regenerate and is only ever needed transiently (PDF build, email CID), so
    we never persist it to disk anymore."""
    qr = qrcode.QRCode(
        version=1,
        error_correction=qrcode.constants.ERROR_CORRECT_L,  # type: ignore
        box_size=10,
        border=4,
    )
    qr.add_data(tid)
    qr.make(fit=True)
    buf = io.BytesIO()
    qr.make_image(fill_color="black", back_color="white").save(buf, format="PNG")
    return buf.getvalue()


def _qr_image_flowable(tid: str, width: float, height: float) -> Image:
    """A reportlab Image of the QR, fed from an in-memory PNG (no disk file).
    A fresh BytesIO is handed to reportlab and kept alive by the returned
    Image until the document is built."""
    return Image(io.BytesIO(_qr_png_bytes(tid)), width=width, height=height)


def simple_ticket_flowables(
    tid: str,
    first_name: str,
    last_name: str,
    date: str,
    event_time: str,
    show_data: dict,
):
    """
    Flowables for ONE box-office ticket on an A5 page, avocloud-branded:
    coral header band, clean label/value info rows, framed QR, mono ID, footer.
    Shared by the single-ticket PDF and the combined batch PDF so they match.
    """
    base = getSampleStyleSheet()

    eyebrow_style = ParagraphStyle(
        "tf_eyebrow", parent=base["Normal"], fontName="Courier-Bold",
        fontSize=8, textColor=PDF_CORAL_LIGHT, leading=10, spaceAfter=3,
    )
    title_style = ParagraphStyle(
        "tf_title", parent=base["Normal"], fontName="Helvetica-Bold",
        fontSize=21, textColor=colors.white, leading=24,
    )
    label_style = ParagraphStyle(
        "tf_label", parent=base["Normal"], fontName="Helvetica-Bold",
        fontSize=7.5, textColor=PDF_MUTED, leading=10,
    )
    value_style = ParagraphStyle(
        "tf_value", parent=base["Normal"], fontName="Helvetica-Bold",
        fontSize=12.5, textColor=PDF_INK, leading=15,
    )
    id_style = ParagraphStyle(
        "tf_id", parent=base["Normal"], fontName="Courier-Bold",
        fontSize=13, textColor=PDF_INK, alignment=1, leading=16,
    )
    note_style = ParagraphStyle(
        "tf_note", parent=base["Normal"], fontName="Helvetica",
        fontSize=9, textColor=PDF_MUTED, alignment=1, leading=12,
    )
    foot_style = ParagraphStyle(
        "tf_foot", parent=base["Normal"], fontName="Helvetica",
        fontSize=7.5, textColor=PDF_MUTED, alignment=1, leading=10,
    )

    els: list = []

    # --- coral header band ---
    banner = Table(
        [[[Paragraph("// TICKET", eyebrow_style),
           Paragraph(show_data.get("orga_name", "Event"), title_style)]]],
        colWidths=[SIMPLE_CONTENT_W],
    )
    banner.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), PDF_CORAL),
        ("LEFTPADDING", (0, 0), (-1, -1), 20),
        ("RIGHTPADDING", (0, 0), (-1, -1), 20),
        ("TOPPADDING", (0, 0), (-1, -1), 18),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 18),
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
    ]))
    els.append(banner)
    els.append(Spacer(1, 24))

    # --- info rows (label / value) ---
    fn = str(first_name).strip() if _meaningful(first_name) else ""
    ln = str(last_name).strip() if _meaningful(last_name) else ""
    full_name = f"{fn} {ln}".strip()
    rows = []
    if full_name:                      # omit NAME row for unnamed/"Unknown" tickets
        rows.append(("NAME", full_name))
    date_val = _fmt_ticket_date(date) if date else ""
    if date_val:                       # always show a date (real date or "Unlimited")
        rows.append(("DATE", date_val))
        if date != "Unlimited" and event_time:
            rows.append(("TIME", event_time))
    loc_name, loc_addr = _location_for_date(date, show_data)
    if loc_name:
        rows.append(("LOCATION", loc_name))
    if loc_addr:
        rows.append(("ADDRESS", loc_addr))
    if rows:
        info = Table(
            [[Paragraph(l, label_style), Paragraph(v, value_style)] for l, v in rows],
            colWidths=[SIMPLE_CONTENT_W * 0.26, SIMPLE_CONTENT_W * 0.74],
        )
        info.setStyle(TableStyle([
            ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
            ("TOPPADDING", (0, 0), (-1, -1), 7),
            ("BOTTOMPADDING", (0, 0), (-1, -1), 7),
            ("LEFTPADDING", (0, 0), (-1, -1), 0),
            ("RIGHTPADDING", (0, 0), (-1, -1), 0),
            ("LINEBELOW", (0, 0), (-1, -2), 0.5, PDF_LINE),
        ]))
        els.append(info)
        els.append(Spacer(1, 28))

    # --- framed QR, centered ---
    qr_box = Table([[_qr_image_flowable(tid, 156, 156)]], colWidths=[188])
    qr_box.hAlign = "CENTER"
    qr_box.setStyle(TableStyle([
        ("ALIGN", (0, 0), (-1, -1), "CENTER"),
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
        ("BOX", (0, 0), (-1, -1), 1, PDF_LINE),
        ("BACKGROUND", (0, 0), (-1, -1), colors.white),
        ("TOPPADDING", (0, 0), (-1, -1), 16),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 16),
    ]))
    els.append(qr_box)
    els.append(Spacer(1, 12))
    els.append(Paragraph(tid, id_style))
    els.append(Spacer(1, 20))
    els.append(Paragraph("Show this ticket at the entrance.", note_style))
    els.append(Spacer(1, 6))
    els.append(Paragraph("QrGate · avocloud.net", foot_style))
    return els


def standard_ticket_flowables(
    tid: str,
    first_name: str,
    last_name: str,
    date: str,
    event_time: str,
    show_data: dict,
):
    """
    Flowables for the full A4 public-shop ticket, avocloud-branded:
    coral hero band (eyebrow + event name + subtitle), a clean info card,
    a large framed QR with mono ID, concise usage notes, and a footer.
    Visual sibling of simple_ticket_flowables, scaled up for A4.
    """
    base = getSampleStyleSheet()

    eyebrow_style = ParagraphStyle(
        "std_eyebrow", parent=base["Normal"], fontName="Courier-Bold",
        fontSize=9, textColor=PDF_CORAL_LIGHT, leading=12, spaceAfter=4,
    )
    title_style = ParagraphStyle(
        "std_title", parent=base["Normal"], fontName="Helvetica-Bold",
        fontSize=30, textColor=colors.white, leading=34,
    )
    subtitle_style = ParagraphStyle(
        "std_subtitle", parent=base["Normal"], fontName="Helvetica",
        fontSize=12, textColor=PDF_CORAL_LIGHT, leading=16, spaceBefore=6,
    )
    label_style = ParagraphStyle(
        "std_label", parent=base["Normal"], fontName="Helvetica-Bold",
        fontSize=8.5, textColor=PDF_MUTED, leading=11,
    )
    value_style = ParagraphStyle(
        "std_value", parent=base["Normal"], fontName="Helvetica-Bold",
        fontSize=15, textColor=PDF_INK, leading=19,
    )
    id_style = ParagraphStyle(
        "std_id", parent=base["Normal"], fontName="Courier-Bold",
        fontSize=16, textColor=PDF_INK, alignment=1, leading=20,
    )
    note_style = ParagraphStyle(
        "std_note", parent=base["Normal"], fontName="Helvetica",
        fontSize=9.5, textColor=PDF_MUTED, leading=14,
    )
    foot_style = ParagraphStyle(
        "std_foot", parent=base["Normal"], fontName="Helvetica",
        fontSize=8, textColor=PDF_MUTED, alignment=1, leading=11,
    )

    els: list = []

    # --- coral hero band: eyebrow + event name (+ optional subtitle) ---
    hero_cell = [
        Paragraph("// TICKET", eyebrow_style),
        Paragraph(show_data.get("orga_name", "Event"), title_style),
    ]
    subtitle = (show_data.get("subtitle") or show_data.get("title") or "").strip()
    if subtitle:
        hero_cell.append(Paragraph(subtitle, subtitle_style))
    hero = Table([[hero_cell]], colWidths=[STD_CONTENT_W])
    hero.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), PDF_CORAL),
        ("LEFTPADDING", (0, 0), (-1, -1), 30),
        ("RIGHTPADDING", (0, 0), (-1, -1), 30),
        ("TOPPADDING", (0, 0), (-1, -1), 22),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 22),
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
    ]))
    els.append(hero)
    els.append(Spacer(1, 18))

    # --- info card (label / value rows) ---
    fn = str(first_name).strip() if _meaningful(first_name) else ""
    ln = str(last_name).strip() if _meaningful(last_name) else ""
    full_name = f"{fn} {ln}".strip()
    rows = []
    if full_name:                      # omit NAME row for unnamed/"Unknown" tickets
        rows.append(("NAME", full_name))
    date_val = _fmt_ticket_date(date) if date else ""
    if date_val:                       # always show a date (real date or "Unlimited")
        rows.append(("DATE", date_val))
        if date != "Unlimited" and event_time:
            rows.append(("TIME", event_time))
    loc_name, loc_addr = _location_for_date(date, show_data)
    if loc_name:
        rows.append(("LOCATION", loc_name))
    if loc_addr:
        rows.append(("ADDRESS", loc_addr))
    if rows:
        info = Table(
            [[Paragraph(l, label_style), Paragraph(v, value_style)] for l, v in rows],
            colWidths=[STD_CONTENT_W * 0.22, STD_CONTENT_W * 0.78],
        )
        info.setStyle(TableStyle([
            ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
            ("BACKGROUND", (0, 0), (-1, -1), colors.HexColor("#F2EFE6")),
            ("BOX", (0, 0), (-1, -1), 1, PDF_LINE),
            ("TOPPADDING", (0, 0), (-1, -1), 9),
            ("BOTTOMPADDING", (0, 0), (-1, -1), 9),
            ("LEFTPADDING", (0, 0), (-1, -1), 22),
            ("RIGHTPADDING", (0, 0), (-1, -1), 22),
            ("LINEBELOW", (0, 0), (-1, -2), 0.5, PDF_LINE),
        ]))
        els.append(info)
        els.append(Spacer(1, 20))

    # --- large framed QR, centered, with mono ID ---
    qr_box = Table([[_qr_image_flowable(tid, 165, 165)]], colWidths=[209])
    qr_box.hAlign = "CENTER"
    qr_box.setStyle(TableStyle([
        ("ALIGN", (0, 0), (-1, -1), "CENTER"),
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
        ("BOX", (0, 0), (-1, -1), 1, PDF_LINE),
        ("BACKGROUND", (0, 0), (-1, -1), colors.white),
        ("TOPPADDING", (0, 0), (-1, -1), 14),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 14),
    ]))
    els.append(qr_box)
    els.append(Spacer(1, 10))
    els.append(Paragraph(tid, id_style))
    els.append(Spacer(1, 18))

    # --- usage notes ---
    notes = [
        "Have this QR code ready at the entrance — it will be scanned and validated on entry.",
        "Each ticket is valid for a single entry and only on the date shown above. "
        "To re-enter, ask for a stamp or wristband at the exit.",
    ]
    for n in notes:
        els.append(Paragraph(n, note_style))
        els.append(Spacer(1, 5))

    els.append(Spacer(1, 8))
    div = Table([[""]], colWidths=[STD_CONTENT_W])
    div.setStyle(TableStyle([("LINEABOVE", (0, 0), (-1, -1), 0.5, PDF_LINE)]))
    els.append(div)
    els.append(Spacer(1, 8))
    els.append(Paragraph("Managed by QrGate · avocloud.net", foot_style))
    return els


def create_ticket(app=quart.Quart):
    @app.route("/api/ticket/create", methods=["POST"])   # type: ignore
    async def create_ticket():
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        try:
            data: dict = await quart.request.get_json()
            print(data)

            paid: bool = data.get("paid", False)
            # Stripe PaymentIntent id the frontend confirmed the payment with.
            # We use it purely for replay/idempotency protection here.
            # NOTE: full webhook-based verification (verifying the Stripe
            # webhook signature + intent status server-side) is the
            # recommended next step and is out of scope for this pass.
            payment_intent_id: Optional[str] = data.get("payment_intent_id")
            valid_date: str = str(data.get("valid_date"))
            first_name: str = str(data.get("first_name"))
            last_name: str = str(data.get("last_name"))
            email: str = str(data.get("email"))
            try:
                tickets: int = int(data.get("tickets", 1))
            except (TypeError, ValueError):
                return (
                    quart.jsonify(
                        {"status": "error", "message": "Invalid ticket quantity"}
                    ),
                    400,
                )
            # Guard against zero/negative quantities that would otherwise
            # *increase* the available count (oversell) below.
            if tickets < 1:
                return (
                    quart.jsonify(
                        {"status": "error", "message": "Invalid ticket quantity"}
                    ),
                    400,
                )
            add_people: list = data.get("add_people", [])
            t_type: str = str(data.get("type", "visitor"))
            date = load_date(valid_date)
            if not date:
                return (
                    quart.jsonify(
                        {"status": "error", "message": "Invalid date provided"}
                    ),
                    400,
                )

            # --- payment idempotency (paid public flow) -----------------
            # If this is a paid order tied to a Stripe PaymentIntent, make
            # sure that intent has not already been consumed by a previous
            # (possibly replayed) request. The cheap pre-check below is a
            # fast-path; the real, race-safe guard is the atomic
            # mark_intent_used() *after* the order is created.
            enforce_intent = bool(paid) and bool(payment_intent_id)
            if enforce_intent:
                if await asyncio.to_thread(is_intent_used, payment_intent_id):
                    return (
                        quart.jsonify(
                            {"status": "error", "message": "payment_already_used"}
                        ),
                        409,
                    )

            # --- reserved seating vs general admission --------------------
            # A seated date's capacity IS its chosen seats (claimed atomically
            # via bind_seats below); a general-admission date uses the numeric
            # tickets_available counter. `seats`/`hold_token` come from the
            # buyer's seat picker.
            seated = bool(date.get("seating"))
            location_id = date.get("location") or ""
            seat_ids = [str(s) for s in (data.get("seats") or []) if s]
            hold_token = data.get("hold_token")
            total_people = 1 + len(add_people)
            seat_map_idx: dict = {}
            if seated:
                if len(seat_ids) != total_people:
                    return (
                        quart.jsonify(
                            {"status": "error", "message": "seat_count_mismatch"}
                        ),
                        400,
                    )
                seat_map_idx = await asyncio.to_thread(seat_index, location_id)
                unknown = [s for s in seat_ids if s not in seat_map_idx]
                if unknown:
                    return (
                        quart.jsonify(
                            {"status": "error", "message": "Unknown seats", "seats": unknown}
                        ),
                        400,
                    )
                # How many attendees this order represents (used for stats).
                sale_count = total_people
            else:
                sale_count = tickets
                # Atomically reserve the seats so two concurrent buyers can't
                # oversell the same date (single-statement guarded UPDATE).
                if not await asyncio.to_thread(decrement_availability, valid_date, tickets):
                    return (
                        quart.jsonify(
                            {"status": "error", "message": "Not enough tickets available"}
                        ),
                        409,
                    )

            # Everything after capacity is claimed must give it back if it
            # throws, otherwise capacity leaks with no ticket issued.
            all_tids: List[str] = []
            try:
                price_per_ticket = float(date["price"])
                amount = price_per_ticket * sale_count

                # Pre-generate an id for every attendee (main + add-people) so
                # seats can be bound to their tickets before anything persists.
                all_tids = [generate_ticket_id(valid_date) for _ in range(total_people)]
                main_tid = all_tids[0]

                # Claim the chosen seats atomically. If any was taken during
                # checkout, nothing is saved and the buyer must pick again.
                seat_assignment: dict = {}  # tid -> {"seat_id","seat_label"}
                if seated:
                    mapping = {seat_ids[i]: all_tids[i] for i in range(total_people)}
                    ok, taken = await asyncio.to_thread(
                        bind_seats, valid_date, mapping, hold_token
                    )
                    if not ok:
                        return (
                            quart.jsonify(
                                {"status": "error", "message": "seats_taken", "seats": taken}
                            ),
                            409,
                        )
                    for i, t in enumerate(all_tids):
                        info = seat_map_idx.get(seat_ids[i], {})
                        seat_assignment[t] = {
                            "seat_id": seat_ids[i],
                            "seat_label": info.get("label"),
                        }

                # Atomically consume the PaymentIntent BEFORE issuing tickets.
                # If another concurrent/replayed request already consumed it,
                # mark_intent_used returns False -> treat as a duplicate and
                # bail out (rolling back the capacity we just claimed) so a paid
                # intent yields exactly one ticket-order.
                if enforce_intent:
                    newly = await asyncio.to_thread(
                        mark_intent_used, payment_intent_id, main_tid, amount
                    )
                    if not newly:
                        if seated:
                            for t in all_tids:
                                await asyncio.to_thread(
                                    release_seats_for_ticket, valid_date, t
                                )
                        else:
                            await asyncio.to_thread(
                                release_availability, valid_date, tickets
                            )
                        return (
                            quart.jsonify(
                                {"status": "error", "message": "payment_already_used"}
                            ),
                            409,
                        )

                log_ticket_sale(valid_date, sale_count, price_per_ticket)

                created_tids: List[str] = []

                # Payment method + Stripe reference for later refunds. Only the
                # main ticket of a paid order carries the payment_intent (it is
                # the one the charge is tied to); add-people tickets ride along.
                method = str(data.get("method") or ("stripe" if payment_intent_id else ("paid" if paid else "free")))

                sa = seat_assignment.get(main_tid, {})
                ticket = {
                    "tid": main_tid,
                    "first_name": first_name,
                    "last_name": last_name,
                    "email": email,
                    "paid": paid,
                    "valid_date": valid_date,
                    "type": t_type,
                    "valid": paid,
                    "used_at": None,
                    "access_attempts": [],
                    "status": "active",
                    "method": method,
                    "payment_intent": payment_intent_id if (paid and payment_intent_id) else None,
                    "seat_id": sa.get("seat_id"),
                    "seat_label": sa.get("seat_label"),
                    "location": location_id if seated else None,
                }
                await asyncio.to_thread(save_tickets, main_tid, ticket)
                created_tids.append(main_tid)
                tid = main_tid  # response tid (overwritten by last add-person, if any)

                for idx, person in enumerate(add_people):
                    tid = all_tids[idx + 1]
                    sa = seat_assignment.get(tid, {})
                    ticket = {
                        "tid": tid,
                        "first_name": person,
                        "last_name": "",
                        "email": email,
                        "paid": paid,
                        "valid_date": valid_date,
                        "type": t_type,
                        "valid": paid,
                        "used_at": None,
                        "access_attempts": [],
                        "status": "active",
                        "method": method,
                        "payment_intent": None,
                        "seat_id": sa.get("seat_id"),
                        "seat_label": sa.get("seat_label"),
                        "location": location_id if seated else None,
                    }
                    await asyncio.to_thread(save_tickets, tid, ticket)
                    created_tids.append(tid)
            except Exception:
                # Roll back the claimed capacity so a mid-flight failure does not
                # silently burn seats with no ticket issued, then re-raise.
                if seated:
                    for t in all_tids:
                        await asyncio.to_thread(release_seats_for_ticket, valid_date, t)
                else:
                    await asyncio.to_thread(release_availability, valid_date, tickets)
                raise

            # Tickets are persisted; emailing must NOT be able to lose a paid
            # ticket. A slow/broken mail server only costs the email, never
            # the (already committed) order.
            try:
                await send_email(
                    first_name,
                    last_name,
                    email,
                    main_tid,
                    paid,
                    date=valid_date,
                    event_time=date["time"],
                )
                for tid, person in zip(created_tids[1:], add_people):
                    await send_email(
                        person,
                        "",
                        email,
                        tid,
                        paid,
                        date=valid_date,
                        event_time=date["time"],
                    )
            except Exception as mail_err:
                logger.error(
                    f"Ticket created but email delivery failed for "
                    f"{main_tid}: {mail_err}"
                )

            return (
                quart.jsonify(
                    {"status": "success", "message": "Tickets created", "tid": tid}
                ),
                200,
            )
        except Exception as e:
            print("Error:", str(e))
            return quart.jsonify({"status": "error", "message": str(e)}), 500

    @app.route("/api/ticketflow/create", methods=["POST"])   # type: ignore
    async def create_ticket_flow():
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401

        data: dict = await quart.request.get_json()
        print(data)

        
        paid: bool = data.get("paid", False)
        valid_date_input: Optional[str] = data.get("valid_date")
        t_type: str = data.get("type", "visitor")  
        first_name_input: Optional[str] = data.get("first_name", "").strip()
        last_name_input: Optional[str] = data.get("last_name", "").strip()
        email_input: Optional[str] = (data.get("email") or "").strip()
        tickets_input: Optional[int] = data.get("tickets", 1)  

        
        valid_date: str = valid_date_input if valid_date_input else ""
        first_name: str = first_name_input if first_name_input else "Unknown"
        last_name: str = last_name_input if last_name_input else "Unknown"
        email: Optional[str] = email_input if email_input else None
        try:
            tickets: int = int(tickets_input) if tickets_input else 1
        except (TypeError, ValueError):
            return (
                quart.jsonify({"status": "error", "message": "Invalid ticket quantity"}),
                400,
            )
        # Guard against zero/negative quantities that would otherwise
        # *increase* the available count (oversell) below.
        if tickets < 1:
            return (
                quart.jsonify({"status": "error", "message": "Invalid ticket quantity"}),
                400,
            )


        # Whether we committed a seat reservation that must be rolled back if
        # the rest of the flow fails (kept False for admin/vip/Unlimited).
        reserved = False
        if not valid_date or valid_date.strip() == "":
            if t_type not in ("admin", "vip"):
                return (
                    quart.jsonify(
                        {
                            "status": "error",
                            "message": "Valid date is required for this ticket type",
                        }
                    ),
                    400,
                )
            valid_date = "Unlimited"
        else:
            date_info:dict = load_date(valid_date)
            if t_type not in ("admin", "vip"):
                if not date_info:
                    return (
                        quart.jsonify(
                            {"status": "error", "message": "Invalid date provided"}
                        ),
                        400,
                    )
                # Atomically reserve the seats to prevent concurrent oversell.
                if not await asyncio.to_thread(decrement_availability, valid_date, tickets):
                    return (
                        quart.jsonify(
                            {
                                "status": "error",
                                "message": "Not enough tickets available",
                            }
                        ),
                        409,
                    )
                reserved = True

                price_per_ticket = float(date_info["price"])
                log_ticket_sale(valid_date, tickets, price_per_ticket)

        # Everything after the (committed) seat reservation must give the seats
        # back if it throws, otherwise capacity leaks with no ticket issued.
        try:
            raw_tid = data.get("tid")
            if raw_tid is None or str(raw_tid).strip() == "":
                tid = generate_ticket_id(valid_date)
            else:
                tid = str(raw_tid).strip()
                # Refuse to overwrite an existing ticket via a client-supplied id
                # (would otherwise allow forging/clobbering tickets, incl. admin/vip).
                if await asyncio.to_thread(load_ticket_id, tid) is not None:
                    if reserved:
                        await asyncio.to_thread(release_availability, valid_date, tickets)
                    return (
                        quart.jsonify(
                            {"status": "error", "message": "Ticket ID already exists"}
                        ),
                        409,
                    )

            ticket = {
                "tid": tid,
                "first_name": first_name,
                "last_name": last_name,
                "email": email,
                "paid": paid,
                "valid_date": valid_date,
                "type": t_type,
                "valid": paid,
                "used_at": None,
                "access_attempts": [],
                "status": "active",
                # Box-office sales are cash ("bar"); no Stripe intent to refund.
                "method": str(data.get("method") or ("bar" if paid else "free")),
                "payment_intent": None,
            }
            print(ticket)
            await asyncio.to_thread(save_tickets, tid, ticket)

            if valid_date != "Unlimited":
                date_info_loaded = await asyncio.to_thread(load_date, valid_date)
                date_info: dict = date_info_loaded if date_info_loaded is not None else {"time": ""}
            else:
                date_info = {"time": ""}

            event_time = date_info.get("time", "") if valid_date != "Unlimited" else ""
            await asyncio.to_thread(
                generate_ticket_pdf, tid, first_name, last_name, valid_date,
                event_time, "simple",
            )
        except Exception:
            # Roll back the reserved seats so a mid-flight failure does not
            # silently burn capacity with no ticket issued, then re-raise.
            if reserved:
                await asyncio.to_thread(release_availability, valid_date, tickets)
            raise

        # Ticket is persisted; emailing must NOT be able to lose it. A slow or
        # broken mail server only costs the email, never the committed ticket.
        if email:
            try:
                await send_email(
                    first_name,
                    last_name,
                    email,
                    tid,
                    paid,
                    date=valid_date,
                    event_time=event_time,
                )
            except Exception as mail_err:
                logger.error(
                    f"Ticket {tid} created but email delivery failed: {mail_err}"
                )

        return (
            quart.jsonify(
                {"status": "success", "message": "Ticket created", "tid": tid}
            ),
            200,
        )


def build_combined_simple_pdf(tids: List[str]):
    """
    Build ONE printable PDF holding every given ticket as its own A5 page
    (avocloud-branded 'simple' box-office layout). Returns an io.BytesIO, or
    None if none of the tids resolve to a stored ticket. Used by
    /codes/pdf?tids=a,b,c so the box office prints a whole batch in one job.
    """
    tickets = load_tickets()
    show_data = load_show()

    elements: list = []
    found = 0
    for tid in tids:
        ticket = tickets.get(tid)
        if not ticket:
            continue

        first_name = ticket.get("first_name", "") or ""
        last_name = ticket.get("last_name", "") or ""
        date = ticket.get("valid_date", "") or ""
        event_time = ""
        if date and date != "Unlimited":
            di = load_date(date)
            event_time = di.get("time", "") if di else ""

        if found > 0:
            elements.append(PageBreak())
        elements.extend(
            simple_ticket_flowables(
                tid, first_name, last_name, date, event_time, show_data
            )
        )
        found += 1

    if found == 0:
        return None

    buf = io.BytesIO()
    pdf = SimpleDocTemplate(
        buf, pagesize=A5,
        rightMargin=SIMPLE_MARGIN, leftMargin=SIMPLE_MARGIN,
        topMargin=SIMPLE_MARGIN, bottomMargin=SIMPLE_MARGIN,
    )
    pdf.build(elements)
    buf.seek(0)
    return buf


def generate_ticket_pdf(
    tid: str,
    first_name: str,
    last_name: str,
    date: str,
    event_time: str,
    variant: str = "standard",
):
    """
    Generates a printable PDF ticket and saves it to ./codes/{tid}.pdf
    The QR code is rendered in memory and embedded directly into the PDF.

    Variants:
      - "standard": Full A4 ticket with banner and detailed info (default)
      - "simple": Minimal A5 ticket for fast printing at the box office
    """
    pdf_filename = f"./codes/{tid}.pdf"

    os.makedirs("./codes", exist_ok=True)

    # The QR is drawn straight into the PDF from an in-memory PNG by the
    # flowables below — no standalone .png file is written to disk.
    show_data = load_show()
    styles = getSampleStyleSheet()

    if variant == "simple":
        pdf = SimpleDocTemplate(
            pdf_filename,
            pagesize=A5,
            rightMargin=SIMPLE_MARGIN,
            leftMargin=SIMPLE_MARGIN,
            topMargin=SIMPLE_MARGIN,
            bottomMargin=SIMPLE_MARGIN,
        )
        elements = simple_ticket_flowables(
            tid, first_name, last_name, date, event_time, show_data
        )

    else:
        pdf = SimpleDocTemplate(
            pdf_filename,
            pagesize=A4,
            rightMargin=STD_MARGIN,
            leftMargin=STD_MARGIN,
            topMargin=STD_MARGIN,
            bottomMargin=STD_MARGIN,
        )
        elements = standard_ticket_flowables(
            tid, first_name, last_name, date, event_time, show_data
        )

    pdf.build(elements)
    return pdf_filename


def _ticket_email_html(
    *,
    event_name: str,
    subtitle: str,
    headline: str,
    status_msg: str,
    full_name: str,
    date_val: str,
    event_time: str,
    location_name: str,
    location_address: str,
    tid: str,
    qr_url: str,
) -> str:
    """
    Build an email-client-safe, avocloud-branded ticket email (table layout,
    inline styles, no fixed positioning / CSS animations / web fonts).
    All dynamic strings must already be HTML-escaped by the caller.
    """
    def _row(label: str, value: str, last: bool = False) -> str:
        border = "" if last else "border-bottom:1px solid #DCD8CB;"
        return (
            "<tr>"
            f'<td style="padding:11px 0;{border}font-family:Arial,Helvetica,sans-serif;'
            "font-size:11px;font-weight:bold;letter-spacing:1px;color:#6B6B63;"
            'text-transform:uppercase;vertical-align:middle;width:30%;">'
            f"{label}</td>"
            f'<td style="padding:11px 0;{border}font-family:Arial,Helvetica,sans-serif;'
            'font-size:15px;font-weight:bold;color:#141414;vertical-align:middle;">'
            f"{value}</td>"
            "</tr>"
        )

    info: list = []
    if full_name:
        info.append(("NAME", full_name))
    if date_val:
        info.append(("DATE", date_val))
        if date_val != "Unlimited" and event_time:
            info.append(("TIME", event_time))
    if location_name:
        info.append(("LOCATION", location_name))
    if location_address:
        info.append(("ADDRESS", location_address))
    rows_html = "".join(
        _row(lbl, val, last=(i == len(info) - 1)) for i, (lbl, val) in enumerate(info)
    )
    info_table = (
        '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
        'style="margin:0 0 28px 0;border-collapse:collapse;">'
        f"{rows_html}</table>"
        if rows_html
        else ""
    )

    subtitle_html = (
        f'<div style="margin-top:6px;font-family:Arial,Helvetica,sans-serif;'
        f'font-size:13px;color:#FFD8CF;">{subtitle}</div>'
        if subtitle
        else ""
    )

    return f"""\
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="light">
  <title>{event_name} · Ticket</title>
</head>
<body style="margin:0;padding:0;background-color:#F2EFE6;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F2EFE6;margin:0;padding:0;">
    <tr>
      <td align="center" style="padding:28px 12px;">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;background-color:#FFFFFF;border:1px solid #DCD8CB;border-radius:14px;overflow:hidden;">

          <!-- coral header band -->
          <tr>
            <td style="background-color:#C73D20;padding:34px 36px;">
              <div style="font-family:'Courier New',Courier,monospace;font-size:12px;font-weight:bold;letter-spacing:3px;color:#FFD8CF;">// TICKET</div>
              <div style="margin-top:8px;font-family:Arial,Helvetica,sans-serif;font-size:28px;font-weight:bold;line-height:1.15;color:#FFFFFF;">{event_name}</div>
              {subtitle_html}
            </td>
          </tr>

          <!-- body -->
          <tr>
            <td style="padding:32px 36px 8px 36px;">
              <h1 style="margin:0 0 12px 0;font-family:Arial,Helvetica,sans-serif;font-size:21px;font-weight:bold;color:#141414;">{headline}</h1>
              <p style="margin:0 0 26px 0;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#6B6B63;">{status_msg}</p>
              {info_table}
            </td>
          </tr>

          <!-- QR -->
          <tr>
            <td align="center" style="padding:0 36px 8px 36px;">
              <table role="presentation" cellpadding="0" cellspacing="0" style="border:1px solid #DCD8CB;border-radius:12px;background-color:#FFFFFF;">
                <tr><td style="padding:18px;">
                  <img src="cid:qrcode" alt="Ticket QR code" width="200" height="200" style="display:block;width:200px;height:200px;">
                </td></tr>
              </table>
              <div style="margin:14px 0 4px 0;font-family:'Courier New',Courier,monospace;font-size:16px;font-weight:bold;letter-spacing:1px;color:#141414;">
                <a href="{qr_url}" style="color:#141414;text-decoration:none;">{tid}</a>
              </div>
            </td>
          </tr>

          <!-- usage note -->
          <tr>
            <td style="padding:22px 36px 8px 36px;">
              <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:12.5px;line-height:1.6;color:#6B6B63;">
                Have this QR code ready at the entrance — it is scanned and validated on entry. Each ticket is valid for a single entry on the date shown above. To re-enter, ask for a stamp or wristband at the exit. Your ticket is also attached as a PDF.
              </p>
            </td>
          </tr>

          <!-- footer -->
          <tr>
            <td style="padding:18px 36px 28px 36px;border-top:1px solid #DCD8CB;">
              <div style="font-family:Arial,Helvetica,sans-serif;font-size:11px;color:#6B6B63;">Managed by QrGate · avocloud.net</div>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>"""


async def send_email(
    first_name: str,
    last_name: str,
    email: str,
    tid: str,
    paid: bool,
    date: str,
    event_time: str,
    type: str = "normal",
):
    """
    Sends an email with the ticket PDF attached.
    Automatically generates the PDF if needed.
    """
    if not email:
        return

    # Reject addresses containing CR/LF (or stray whitespace) to prevent
    # SMTP/email header injection via the recipient address.
    email = str(email).strip()
    if not email or any(c in email for c in "\r\n") or "@" not in email:
        logger.error(f"Refusing to send email to invalid address: {email!r}")
        return

    
    pdf_path = f"./codes/{tid}.pdf"
    if not os.path.exists(pdf_path):
        generate_ticket_pdf(tid, first_name, last_name, date, event_time)

    message = MIMEMultipart("mixed")
    message["From"] = config.Mail.smtp_user
    message["To"] = email

    show_data = load_show()

    # --- shared, escaped values for the branded template ---
    fn = str(first_name).strip() if _meaningful(first_name) else ""
    ln = str(last_name).strip() if _meaningful(last_name) else ""
    full_name = escape(f"{fn} {ln}".strip())
    date_val = escape(_fmt_ticket_date(date)) if date else ""
    event_name = escape(str(show_data.get("orga_name", "Event")))
    subtitle = escape(
        str(show_data.get("subtitle") or show_data.get("title") or "").strip()
    )
    event_time_e = escape(str(event_time)) if event_time else ""
    loc_name, loc_addr = _location_for_date(date, show_data)
    location_name = escape(loc_name) if loc_name else ""
    location_address = escape(loc_addr) if loc_addr else ""
    safe_tid = escape(str(tid))
    # The tid under the QR links to the ticket PDF. The per-ticket HMAC token
    # lets the (otherwise public) /codes/pdf endpoint accept this legitimate
    # request while still rejecting tid enumeration.
    qr_url = (
        f"{config.API.backend_url}/codes/pdf?tid={tid}"
        f"&token={ticket_token(tid)}"
    )

    if type != "normal":
        message["Subject"] = (config.Mail.mail_title_paid).format(id=str(first_name))
        headline = 'Your ticket has been <span style="color:#C73D20;">paid</span>'
        status_msg = (
            "Your ticket was paid for on site — this email confirms the payment. "
            "Your ticket below (and the attached PDF) is ready for entry."
        )
    else:
        message["Subject"] = (config.Mail.mail_title).format(id=str(first_name))
        if paid:
            headline = 'Your ticket is <span style="color:#C73D20;">ready</span>'
            status_msg = "Your ticket is paid and ready to use."
        else:
            headline = 'Your <span style="color:#C73D20;">ticket</span>'
            status_msg = (
                "Your ticket has not been paid yet, so it can't be used. "
                "Please pay at the entrance on the day of the event to activate it."
            )

    html_content = _ticket_email_html(
        event_name=event_name,
        subtitle=subtitle,
        headline=headline,
        status_msg=status_msg,
        full_name=full_name,
        date_val=date_val,
        event_time=event_time_e,
        location_name=location_name,
        location_address=location_address,
        tid=safe_tid,
        qr_url=qr_url,
    )

    # Embed the QR as an inline (cid) image instead of a remote <img src> URL.
    # A remote URL pointing at config.API.backend_url is often not publicly
    # reachable, and most mail clients block remote images by default — a cid
    # image lives in a multipart/related part next to the HTML and always
    # renders offline. The PNG is rendered in memory; nothing is read from disk.
    related = MIMEMultipart("related")
    related.attach(MIMEText(html_content, "html"))

    qr_img = MIMEImage(_qr_png_bytes(tid), _subtype="png")
    qr_img.add_header("Content-ID", "<qrcode>")
    qr_img.add_header("Content-Disposition", "inline", filename=f"{tid}.png")
    related.attach(qr_img)

    message.attach(related)

    with open(pdf_path, "rb") as pdf_file:
        part = MIMEApplication(pdf_file.read(), Name=os.path.basename(pdf_path))
        part["Content-Disposition"] = (
            f'attachment; filename="{os.path.basename(pdf_path)}"'
        )
        message.attach(part)

    # The whole SMTP handshake (connect/STARTTLS/login/sendmail) is blocking
    # and can take seconds against a slow server; running it directly in this
    # async function would freeze the entire event loop. Offload it to a worker
    # thread so other requests keep being served. A connection timeout keeps a
    # dead mail server from hanging the worker indefinitely.
    raw_message = message.as_string()

    def _send_blocking() -> None:
        with smtplib.SMTP(
            config.Mail.smtp_server, config.Mail.smtp_port, timeout=15
        ) as server:
            server.starttls()
            server.login(config.Mail.smtp_user, config.Mail.smtp_password)
            server.sendmail(config.Mail.smtp_user, email, raw_message)

    await asyncio.to_thread(_send_blocking)


def edit_ticket(app=quart.Quart):
    @app.route("/api/ticket/edit", methods=["POST"])   # type: ignore
    async def edit_ticket():
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401

        try:
            data: dict = await quart.request.get_json()
            tid: str = str(data.get("tid")).upper()
            print(tid)

            if tid is None:
                return (
                    quart.jsonify({"status": "error", "message": "Ticket not found"}),
                    404,
                )

            ticket = load_ticket_id(tid)

            if ticket is None:
                return (
                    quart.jsonify({"status": "error", "message": "Ticket not found"}),
                    404,
                )

            print(data.get("valid"))
            print(data.get("paid"))
            print(data.get("valid_date"))
            print(data.get("first_name"))
            print(data.get("last_name"))
            print(data.get("type"))

            paid: bool = data.get("paid", ticket["paid"])
            paid_old: bool = ticket["paid"]
            valid_date: str = data.get("valid_date", ticket["valid_date"])
            first_name: str = data.get("first_name", ticket["first_name"])
            last_name: str = data.get("last_name", ticket["last_name"])
            valid: bool = data.get("valid", ticket["valid"])

            ticket.update(
                {
                    "paid": paid,
                    "valid_date": valid_date,
                    "first_name": first_name,
                    "last_name": last_name,
                    "valid": valid,
                    "type": data.get("type", ticket["type"]),
                }
            )

            if paid and not paid_old:
                ticket["valid"] = True

            save_tickets(tid, ticket)
            if paid and not paid_old:
                print("Sending email")
                date = load_date(valid_date)
                await send_email(
                    first_name,
                    last_name,
                    ticket.get("email"), 
                    tid,
                    paid,
                    date=valid_date,
                    event_time=date["time"], 
                )
            return quart.jsonify({"status": "success", "message": "Ticket edited"}), 200
        except Exception as e:
            print(e)
            return quart.jsonify({"status": "error", "message": str(e)}), 500


def view_ticket(app=quart.Quart):
    @app.route("/api/ticket/get", methods=["GET", "POST"])    # type: ignore
    async def view_ticket():
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        try:
            data: dict = await quart.request.get_json()
            tid: str = str(data.get("tid")).upper()

            ticket = load_ticket_id(tid)
            if ticket == None:
                data = {
                    "tid": f"{tid}",
                    "first_name": "Unknown",
                    "last_name": "Unknown",
                    "type": "Unknown",
                    "paid": "Unknown",
                    "valid_date": "Unknown",
                    "valid": "Unknown",
                    "used_at": "Unknown",
                    "access_attempts": [],
                }
                logger.debug.info(f"Ticket not found: {data}")
                return (
                    quart.jsonify(
                        {"status": "error", "message": "Ticket not found", "data": data}
                    ),
                    200,
                )
            return (
                quart.jsonify(
                    {"status": "success", "message": "Ticket loaded", "data": ticket}
                ),
                200,
            )
        except Exception as e:
            return quart.jsonify({"status": "error", "message": str(e)}), 500

    @app.route("/codes/pdf", methods=["GET"])    # type: ignore
    async def show_pdf():
        # Batch: one combined multi-page PDF for ?tids=a,b,c (box-office print job).
        # Each tid must carry its token in a parallel ?tokens=t1,t2,t3 list so the
        # batch endpoint can't be used to enumerate arbitrary tickets either.
        tids_param = quart.request.args.get("tids")
        if tids_param:
            tids = [t.strip() for t in tids_param.split(",") if t.strip()]
            tokens_param = quart.request.args.get("tokens", "")
            tokens = [t.strip() for t in tokens_param.split(",")]
            if len(tokens) != len(tids) or not all(
                _token_valid(t, tok) for t, tok in zip(tids, tokens)
            ):
                return quart.jsonify({"error": "Forbidden"}), 403
            buf = build_combined_simple_pdf(tids)
            if buf is None:
                return quart.jsonify({"error": "PDF not found"}), 404
            return quart.Response(buf.getvalue(), mimetype="application/pdf")

        tid = quart.request.args.get("tid")
        if not tid:
            return quart.jsonify({"error": "Missing tid"}), 400
        # Require a valid per-ticket HMAC token (see ticket_token).
        if not _token_valid(tid, quart.request.args.get("token")):
            return quart.jsonify({"error": "Forbidden"}), 403
        # Prevent path traversal: only serve files inside ./codes
        pdf_path = safe_join("./codes", f"{tid}.pdf")
        if pdf_path is not None and os.path.isfile(pdf_path):
            return await quart.send_file(pdf_path, mimetype="application/pdf")
        else:
            return quart.jsonify({"error": "PDF not found"}), 404


def _stripe_refund(payment_intent: str) -> Dict[str, Any]:
    """Issue a full Stripe refund for a PaymentIntent. Returns
    {"ok": True, "refund_id": ...} on success, or {"ok": False, "error": ...}.
    Blocking (urllib) — call via asyncio.to_thread. The secret key lives in the
    show config (same place get_stripe_config reads it)."""
    show = load_show()
    secret_key = str((show.get("stripe") or {}).get("secret_key") or "").strip()
    if not secret_key:
        return {"ok": False, "error": "Stripe secret key not configured"}

    body = urllib.parse.urlencode({"payment_intent": payment_intent}).encode()
    req = urllib.request.Request(
        "https://api.stripe.com/v1/refunds",
        data=body,
        headers={
            "Authorization": f"Bearer {secret_key}",
            "Content-Type": "application/x-www-form-urlencoded",
        },
        method="POST",
    )
    import json as _json
    try:
        with urllib.request.urlopen(req, timeout=20) as resp:
            payload = _json.loads(resp.read().decode())
        return {"ok": True, "refund_id": payload.get("id", "")}
    except urllib.error.HTTPError as e:
        try:
            err = _json.loads(e.read().decode()).get("error", {}).get("message", str(e))
        except Exception:
            err = f"HTTP {e.code}"
        return {"ok": False, "error": err}
    except Exception as e:
        return {"ok": False, "error": str(e)}


def cancel_ticket(app=quart.Quart):
    @app.route("/api/ticket/cancel", methods=["POST"])   # type: ignore
    async def cancel_ticket_route():
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        try:
            data: dict = await quart.request.get_json(silent=True) or {}
            tid = str(data.get("tid") or "").strip().upper()
            reason = str(data.get("reason") or "").strip()
            actor = str(data.get("scanner") or data.get("actor") or "admin").strip()
            if not tid:
                return quart.jsonify({"status": "error", "message": "Missing tid"}), 200

            ticket = await asyncio.to_thread(load_ticket_id, tid)
            if ticket is None:
                return quart.jsonify({"status": "error", "message": "Ticket not found"}), 200

            # Idempotency guard: the active -> cancelled flip is the single source
            # of truth. If we don't win it, the ticket was already cancelled, so
            # do NOT release a seat / refund again.
            won = await asyncio.to_thread(mark_ticket_cancelled, tid)
            if not won:
                return (
                    quart.jsonify(
                        {"status": "error", "message": "Ticket already cancelled"}
                    ),
                    200,
                )

            valid_date = ticket.get("valid_date")
            t_type = str(ticket.get("type") or "")
            seat_released = False
            # Only dated visitor seats consume capacity; admin/vip/Unlimited don't.
            if valid_date and valid_date != "Unlimited" and t_type not in ("admin", "vip"):
                date_info = await asyncio.to_thread(load_date, valid_date)
                if date_info:
                    if date_info.get("seating"):
                        # Reserved seating: free the exact seat(s) bound to this
                        # ticket instead of bumping a numeric counter.
                        await asyncio.to_thread(release_seats_for_ticket, valid_date, tid)
                    else:
                        await asyncio.to_thread(increment_availability, valid_date, 1)
                    seat_released = True
                    # A sale is logged at creation for every dated seat (paid or
                    # not), so reverse the stats whenever we release that seat.
                    try:
                        price = float(date_info.get("price") or 0)
                        await asyncio.to_thread(log_ticket_refund, 1, price)
                    except Exception as se:
                        logger.error(f"Stat reversal failed for {tid}: {se}")

            # Stripe refund (only for Stripe-paid tickets). Stripe itself rejects a
            # second full refund of the same intent, so even a racing call is safe.
            refund_id = None
            refund_error = None
            method = str(ticket.get("method") or "")
            payment_intent = ticket.get("payment_intent") or await asyncio.to_thread(
                get_intent_for_ticket, tid
            )
            if method == "stripe" or payment_intent:
                if payment_intent:
                    res = await asyncio.to_thread(_stripe_refund, payment_intent)
                    if res.get("ok"):
                        refund_id = res.get("refund_id")
                        await asyncio.to_thread(set_ticket_refund, tid, refund_id or "")
                    else:
                        refund_error = res.get("error")
                        logger.error(f"Stripe refund failed for {tid}: {refund_error}")
                else:
                    refund_error = "No payment_intent on record"

            # Audit trail entry (free-form access_attempts list).
            await asyncio.to_thread(
                append_access_attempt,
                tid,
                {
                    "type": "cancelled",
                    "status": "cancelled",
                    "time": local_now().isoformat(),
                    "scanner": actor,
                    "reason": reason,
                    "refund_id": refund_id,
                },
            )

            msg = "Ticket cancelled."
            if seat_released:
                msg += " Seat released."
            if refund_id:
                msg += f" Refunded ({refund_id})."
            elif refund_error:
                msg += f" Refund FAILED: {refund_error} — refund manually in Stripe."

            return (
                quart.jsonify(
                    {
                        "status": "success",
                        "message": msg,
                        "seat_released": seat_released,
                        "refund_id": refund_id,
                        "refund_error": refund_error,
                    }
                ),
                200,
            )
        except Exception as e:
            logger.error(f"cancel_ticket error: {e}")
            return quart.jsonify({"status": "error", "message": str(e)}), 500


def get_available_tickets(app=quart.Quart):
    @app.route("/api/ticket/available_tickets/<show_id>", methods=["GET"])   # type: ignore
    async def available_tickets(show_id):
        try:

            shows_data = load_show()

            if show_id not in shows_data["dates"]:
                return (
                    quart.jsonify({"status": "error", "message": "Show ID not found"}),
                    404,
                )

            available_tickets = shows_data["dates"][show_id]["tickets_available"]

            return (
                quart.jsonify(
                    {"status": "success", "available_tickets": available_tickets}
                ),
                200,
            )
        except Exception as e:
            return quart.jsonify({"status": "error", "message": str(e)}), 500


def generate_ticket_id(valid_date):
    date_parts = str(valid_date or "").split("-")
    if len(date_parts) == 3 and all(date_parts):
        year, month, day = date_parts[0], date_parts[1], date_parts[2]
    else:
        # Dateless tickets (admin/vip -> "Unlimited") have no YYYY-MM-DD to
        # derive the prefix from; fall back to today's local date so the ID
        # keeps the YYYY-DDMM-XXXX format and stays unique.
        today = local_now().date()
        year, month, day = f"{today.year:04d}", f"{today.month:02d}", f"{today.day:02d}"

    letters = string.ascii_uppercase
    digits = string.digits
    random_part = "".join(random.choice(letters + digits) for _ in range(4))

    ticket_id = f"{year}-{day}{month}-{random_part}"
    return ticket_id