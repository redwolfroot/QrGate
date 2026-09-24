import quart
import config.conf as config # type: ignore
from assets.data import save_tickets, load_ticket_id
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
from assets.data import log_sale
from assets.stats import log_ticket_refund
from assets.timeutil import local_now, today_iso
from assets.boxoffice import _seat_base_prices
import asyncio
import re
import hashlib
import hmac
import urllib.parse
import urllib.request
import urllib.error
from html import escape

from assets import ticket_pdf
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


# --------------------------------------------------------------------------- #
# Ticket email copy. Keyed by the buyer's UI language at purchase time (stored
# on the ticket as `lang`); unknown/missing falls back to English. German says
# "du", like the shop. The PDF has its own table in ticket_pdf.TEXT.
# --------------------------------------------------------------------------- #
TICKET_I18N = {
    "en": {
        "label_NAME": "Name", "label_DATE": "Date", "label_TIME": "Starts",
        "label_SEAT": "Seat", "label_LOCATION": "Venue",
        "free_seating": "Free seating",
        "kicker": "Admission ticket",
        "eticket": "E-ticket",
        "tid": "Ticket no.",
        "subject_ready": "Your ticket: {event}, {date}",
        "subject_unpaid": "Your reservation: {event}, {date}",
        "subject_paid": "Paid: your ticket for {event}",
        "chip_paid": "Paid", "chip_unpaid": "Payment due",
        "email_head_paid": "Thanks, your ticket is paid.",
        "email_status_paid_onsite": "We received your payment at the box office. "
            "Your ticket is now valid for entry.",
        "email_head_ready": "Your ticket is ready.",
        "email_status_ready": "Paid and valid. Show the QR code below at the door.",
        "email_head_ticket": "Your seats are reserved.",
        "email_status_unpaid": "Please pay at the box office on the day, before "
            "entry. The QR code is activated once you have paid.",
        "notes": [
            "Show the QR code at the door, on your phone or printed.",
            "Valid for one entry on the date shown.",
            "To re-enter, get a stamp or wristband at the exit.",
        ],
        "open_pdf": "Open ticket as PDF",
        "pdf_attached": "Your ticket is also attached as a PDF.",
        "email_cancel_pre": "Can't make it? ",
        "email_cancel_link": "Cancel this ticket",
        "email_cancel_post": ". Online payments are refunded automatically, up to "
            "24 hours before the event.",
        "contact": "Questions?",
        "footer": "Managed by QrGate · avocloud.net",
        # cancellation email
        "cx_right": "Cancellation",
        "cx_subject": "Cancelled: your ticket for {event}, {date}",
        "cx_chip": "Cancelled",
        "cx_head_self": "Your cancellation is confirmed.",
        "cx_head": "Your ticket has been cancelled.",
        "cx_msg": "Ticket {tid} is no longer valid and its seat has been released.",
        "cx_kicker": "Cancelled ticket",
        "cx_refund": "Refund",
        "cx_refund_ok": "{amount} is being refunded to your card. Depending on your bank this usually takes 5–10 business days.",
        "cx_refund_ok_noamt": "The amount is being refunded to your card. Depending on your bank this usually takes 5–10 business days.",
        "cx_refund_fail": "The automatic refund did not go through. Please contact the organiser so you get your money back.",
        "cx_refund_counter": "You paid at the box office. The organiser handles the refund with you directly.",
        "cx_refund_free": "This ticket was free, so there is nothing to refund.",
        "cx_refund_none": "This ticket had not been paid yet, so nothing was charged.",
    },
    "de": {
        "label_NAME": "Name", "label_DATE": "Datum", "label_TIME": "Beginn",
        "label_SEAT": "Platz", "label_LOCATION": "Ort",
        "free_seating": "Freie Platzwahl",
        "kicker": "Eintrittskarte",
        "eticket": "E-Ticket",
        "tid": "Ticket-Nr.",
        "subject_ready": "Dein Ticket: {event}, {date}",
        "subject_unpaid": "Deine Reservierung: {event}, {date}",
        "subject_paid": "Bezahlt: dein Ticket für {event}",
        "chip_paid": "Bezahlt", "chip_unpaid": "Zahlung offen",
        "email_head_paid": "Danke, dein Ticket ist bezahlt.",
        "email_status_paid_onsite": "Deine Zahlung an der Kasse ist eingegangen. "
            "Das Ticket ist jetzt für den Einlass gültig.",
        "email_head_ready": "Dein Ticket ist bereit.",
        "email_status_ready": "Bezahlt und gültig. Zeig den QR-Code unten am Einlass.",
        "email_head_ticket": "Deine Plätze sind reserviert.",
        "email_status_unpaid": "Bitte bezahle am Veranstaltungstag vor dem Einlass "
            "an der Kasse. Der QR-Code wird mit der Zahlung freigeschaltet.",
        "notes": [
            "QR-Code am Einlass zeigen, auf dem Handy oder ausgedruckt.",
            "Gilt für einen Eintritt am angegebenen Termin.",
            "Wiedereinlass nur mit Stempel oder Bändchen vom Ausgang.",
        ],
        "open_pdf": "Ticket als PDF öffnen",
        "pdf_attached": "Dein Ticket hängt außerdem als PDF an.",
        "email_cancel_pre": "Verhindert? ",
        "email_cancel_link": "Ticket stornieren",
        "email_cancel_post": ". Online-Zahlungen werden automatisch erstattet, bis "
            "24 Stunden vor der Veranstaltung.",
        "contact": "Fragen?",
        "footer": "Verwaltet mit QrGate · avocloud.net",
        # Storno-Mail
        "cx_right": "Stornierung",
        "cx_subject": "Storniert: dein Ticket für {event}, {date}",
        "cx_chip": "Storniert",
        "cx_head_self": "Deine Stornierung ist bestätigt.",
        "cx_head": "Dein Ticket wurde storniert.",
        "cx_msg": "Das Ticket {tid} ist nicht mehr gültig, der Platz ist wieder freigegeben.",
        "cx_kicker": "Storniertes Ticket",
        "cx_refund": "Erstattung",
        "cx_refund_ok": "{amount} werden auf deine Karte zurückerstattet. Je nach Bank dauert das meist 5–10 Werktage.",
        "cx_refund_ok_noamt": "Der Betrag wird auf deine Karte zurückerstattet. Je nach Bank dauert das meist 5–10 Werktage.",
        "cx_refund_fail": "Die automatische Erstattung hat nicht geklappt. Bitte melde dich beim Veranstalter, damit du dein Geld zurückbekommst.",
        "cx_refund_counter": "Du hast an der Kasse bezahlt. Die Erstattung klärt der Veranstalter direkt mit dir.",
        "cx_refund_free": "Das Ticket war kostenlos, es gibt nichts zu erstatten.",
        "cx_refund_none": "Das Ticket war noch nicht bezahlt, es wurde nichts abgebucht.",
    },
}


def _tx(lang):
    """Translation table for a ticket's language (falls back to English)."""
    return TICKET_I18N.get(str(lang or "en").lower(), TICKET_I18N["en"])


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
        error_correction=qrcode.constants.ERROR_CORRECT_M,  # type: ignore
        box_size=10,
        border=2,  # the white frame around it in the email adds the rest
    )
    qr.add_data(tid)
    qr.make(fit=True)
    buf = io.BytesIO()
    qr.make_image(fill_color="black", back_color="white").save(buf, format="PNG")
    return buf.getvalue()


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
            # Buyer's UI language at purchase; drives the email + PDF localization.
            lang: str = str(data.get("lang") or "en").lower()
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
                # Per-ticket price actually charged: seated dates use each
                # seat's category price (same source stripe-intent.php bills),
                # general admission the date price.
                if seated:
                    seat_prices = await asyncio.to_thread(
                        _seat_base_prices, location_id, price_per_ticket
                    )
                    ticket_prices = [
                        seat_prices.get(s, price_per_ticket) for s in seat_ids
                    ]
                    amount = round(sum(ticket_prices), 2)
                else:
                    ticket_prices = [price_per_ticket] * total_people
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

                await asyncio.to_thread(log_sale, today_iso(), sale_count, amount)

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
                    "lang": lang,
                    "price": ticket_prices[0],
                    "created_at": local_now().isoformat(timespec="seconds"),
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
                        "lang": lang,
                        "price": ticket_prices[idx + 1] if idx + 1 < len(ticket_prices) else price_per_ticket,
                        "created_at": local_now().isoformat(timespec="seconds"),
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
                    lang=lang,
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
                        lang=lang,
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


def ticket_view(tid: str, ticket: Optional[dict] = None,
                show_data: Optional[dict] = None, **fallback) -> Optional[dict]:
    """Everything the ticket PDF prints, taken from the stored ticket (so the
    paid state, name and seat are always current). `fallback` fills in for a
    ticket that is not stored (yet): first_name, last_name, date, event_time,
    seat_label, lang, paid."""
    if ticket is None:
        ticket = load_ticket_id(tid)
    if ticket is None and not fallback:
        return None
    ticket = ticket or {}
    show_data = show_data if show_data is not None else load_show()

    def pick(key, fb_key=None):
        val = ticket.get(key)
        return val if val not in (None, "") else fallback.get(fb_key or key)

    fn = str(pick("first_name") or "").strip()
    ln = str(pick("last_name") or "").strip()
    name = " ".join(x for x in (fn, ln) if _meaningful(x))
    date = str(pick("valid_date", "date") or "")
    event_time = fallback.get("event_time") or ""
    if not event_time and date and date != "Unlimited":
        di = load_date(date)
        event_time = (di or {}).get("time", "") if di else ""
    loc_name, loc_addr = _location_for_date(date, show_data)
    seat = pick("seat_label")
    if ticket.get("status") == "cancelled":
        status = "cancelled"
    else:
        paid = ticket.get("paid") if "paid" in ticket else fallback.get("paid", True)
        status = "paid" if paid else "unpaid"
    return {
        "tid": str(tid),
        "name": name,
        "date": date,
        "time": str(event_time or ""),
        "seat": str(seat).strip() if _meaningful(seat) else "",
        "venue": loc_name,
        "address": loc_addr,
        "status": status,
        "lang": str(pick("lang") or "en"),
        "orga": str(show_data.get("orga_name") or ""),
        "title": str(show_data.get("title") or "").strip(),
        "subtitle": str(show_data.get("subtitle") or "").strip(),
        "contact": str(show_data.get("contact_email") or "").strip(),
    }


def render_ticket_pdf(tids: List[str], fmt: str = "standard") -> Optional[bytes]:
    """One PDF with a page per stored ticket, or None if none exists.
    fmt "standard" = A4 (the buyer's ticket), "simple" = A5 (box-office print)."""
    show_data = load_show()
    views = [v for v in (ticket_view(t, show_data=show_data) for t in tids) if v]
    return ticket_pdf.render_pdf(views, fmt) if views else None


# ---- email palette: the kit's light roles (BRANDING §3, v4.2) ---------------
MAIL_CANVAS = "#F4F6F7"       # --avo-canvas (light)
MAIL_SURFACE = "#FFFFFF"      # --avo-surface (light)
MAIL_LINE = "#DADDE0"         # --avo-line (light) flattened onto white
MAIL_LINE_SOFT = "#E8EAEC"
MAIL_TEXT = "#0A0A0A"         # --avo-text (light)
MAIL_MUTED = "#555555"        # --avo-text-muted (light)
MAIL_FAINT = "#8A8C90"
MAIL_CORAL = "#FF6B4A"        # --avo-primary: fills, black text on it
MAIL_CORAL_TEXT = "#C73D20"   # small coral text on light
MAIL_SUCCESS = "#46A758"
MAIL_WARNING = "#E0A33A"
# The UI theme's faces with fallbacks. Apple Mail and iOS load the web font,
# Gmail and Outlook fall back to their system mono; the layout holds either way.
MAIL_MONO = ("'IBM Plex Mono','SFMono-Regular',Menlo,Consolas,'Liberation Mono',"
             "'Courier New',monospace")
MAIL_WORDMARK = "'Syne','Arial Black','Helvetica Neue',Arial,sans-serif"
MAIL_BANNER_ASPECT = 3.2      # same crop as the PDF banner


MAIL_ERROR = "#DC3838"
_M = f"font-family:{MAIL_MONO};"


def _m_label(text: str) -> str:
    return (f'<div style="{_M}font-size:10px;font-weight:500;letter-spacing:1.4px;'
            f'text-transform:uppercase;color:{MAIL_MUTED};margin:0 0 6px 0;">{text}</div>')


def _m_field(label: str, value: str, extra: str = "", seat: bool = False) -> str:
    if seat:
        val = (f'<span style="display:inline-block;padding:5px 10px;border-radius:6px;'
               f'background-color:{MAIL_CORAL};color:#000000;{_M}font-size:15px;'
               f'font-weight:600;">{value}</span>')
    else:
        val = (f'<div style="{_M}font-size:15px;font-weight:500;line-height:1.4;'
               f'color:{MAIL_TEXT};">{value}</div>')
    if extra:
        val += (f'<div style="{_M}font-size:12px;line-height:1.5;color:{MAIL_MUTED};'
                f'margin-top:2px;">{extra}</div>')
    return _m_label(label) + val


def _m_grid(fields: List[str]) -> str:
    """Details in two columns; one column on a phone (the .col rule)."""
    rows = []
    for i in range(0, len(fields), 2):
        right = fields[i + 1] if i + 1 < len(fields) else ""
        rows.append(
            "<tr>"
            f'<td class="col" width="50%" valign="top" style="padding:0 12px 20px 0;">{fields[i]}</td>'
            f'<td class="col" width="50%" valign="top" style="padding:0 0 20px 12px;">{right}</td>'
            "</tr>"
        )
    return ('<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
            f'style="border-collapse:collapse;">{"".join(rows)}</table>')


def _m_chip(color: str, text: str) -> str:
    return (f'<span style="display:inline-block;padding:5px 10px 5px 9px;border:1px solid {color};'
            f'border-radius:6px;{_M}font-size:10px;font-weight:500;letter-spacing:1.4px;'
            f'text-transform:uppercase;color:{MAIL_TEXT};">'
            f'<span style="color:{color};">&#9679;</span>&nbsp; {text}</span>')


def _m_details(T: dict, *, full_name: str, date_val: str, event_time: str, seat_label: str,
               location_name: str, location_address: str, tid: str = "") -> List[str]:
    fields = []
    if tid:
        fields.append(_m_field(T["tid"], tid))
    if full_name:
        fields.append(_m_field(T["label_NAME"], full_name))
    if date_val:
        fields.append(_m_field(T["label_DATE"], date_val))
    if event_time:
        fields.append(_m_field(T["label_TIME"], event_time))
    fields.append(_m_field(T["label_SEAT"], seat_label or T["free_seating"], seat=bool(seat_label)))
    if location_name or location_address:
        fields.append(_m_field(T["label_LOCATION"], location_name or location_address,
                               location_address if location_name else ""))
    return fields


def _m_event_block(T: dict, kicker: str, title: str, subtitle: str, grid: str) -> str:
    subtitle_html = (
        f'<div style="{_M}font-size:13px;line-height:1.5;color:{MAIL_MUTED};margin-top:6px;">'
        f'{subtitle}</div>' if subtitle else ""
    )
    return f"""
                <tr>
                  <td class="pad" style="padding:24px 32px 6px 32px;">
                    <div style="{_M}font-size:10px;font-weight:500;letter-spacing:1.4px;text-transform:uppercase;color:{MAIL_MUTED};"><span style="color:{MAIL_CORAL_TEXT};">//</span>&nbsp; {kicker}</div>
                    <div style="margin-top:10px;{_M}font-size:19px;font-weight:600;line-height:1.3;color:{MAIL_TEXT};">{title}</div>
                    {subtitle_html}
                    <div style="height:22px;line-height:22px;font-size:0;">&nbsp;</div>
                    {grid}
                  </td>
                </tr>"""


def _m_status_block(chip: str, headline: str, text: str, border: bool = True) -> str:
    line = f"border-bottom:1px solid {MAIL_LINE_SOFT};" if border else ""
    return f"""
                <tr>
                  <td class="pad" style="padding:28px 32px 24px 32px;{line}">
                    {chip}
                    <h1 style="margin:16px 0 8px 0;{_M}font-size:22px;font-weight:600;line-height:1.3;color:{MAIL_TEXT};">{headline}</h1>
                    <p style="margin:0;{_M}font-size:13px;line-height:1.6;color:{MAIL_MUTED};">{text}</p>
                  </td>
                </tr>"""


def _mail_page(*, lang: str, title: str, preheader: str, event_name: str, right_label: str,
               card: str, contact: str = "") -> str:
    """The frame every QrGate email shares: organiser wordmark, one card on the
    light canvas, the [>|] footer. `card` is a run of <tr> rows."""
    T = _tx(lang)
    contact_html = (
        f'{T["contact"]} <a href="mailto:{contact}" style="color:{MAIL_MUTED};'
        f'text-decoration:underline;">{contact}</a>' if contact else ""
    )
    return f"""\
<!DOCTYPE html>
<html lang="{lang}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="light">
  <meta name="supported-color-schemes" content="light">
  <title>{title}</title>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&amp;family=Syne:wght@800&amp;display=swap" rel="stylesheet">
  <style>
    :root {{ color-scheme: light; supported-color-schemes: light; }}
    body {{ margin:0; padding:0; background-color:{MAIL_CANVAS}; }}
    a {{ color:{MAIL_CORAL_TEXT}; }}
    @media (max-width: 620px) {{
      .wrap {{ width:100% !important; }}
      .pad {{ padding-left:22px !important; padding-right:22px !important; }}
      .col {{ display:block !important; width:100% !important; padding:0 0 18px 0 !important; }}
    }}
  </style>
</head>
<body style="margin:0;padding:0;background-color:{MAIL_CANVAS};">
  <div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:{MAIL_CANVAS};">{preheader}</div>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:{MAIL_CANVAS};">
    <tr>
      <td align="center" style="padding:28px 12px 36px 12px;">
        <table role="presentation" class="wrap" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;">

          <!-- header: organiser wordmark + // label -->
          <tr>
            <td style="padding:0 4px 16px 4px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
                <td valign="middle" style="font-family:{MAIL_WORDMARK};font-size:18px;font-weight:800;letter-spacing:-0.2px;text-transform:uppercase;color:{MAIL_TEXT};">{event_name}</td>
                <td valign="middle" align="right" style="{_M}font-size:10px;font-weight:500;letter-spacing:1.4px;text-transform:uppercase;color:{MAIL_MUTED};white-space:nowrap;"><span style="color:{MAIL_CORAL_TEXT};">//</span>&nbsp; {right_label}</td>
              </tr></table>
            </td>
          </tr>

          <!-- the card -->
          <tr>
            <td style="background-color:{MAIL_SURFACE};border:1px solid {MAIL_LINE};border-radius:8px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;">
                {card}
              </table>
            </td>
          </tr>

          <!-- footer -->
          <tr>
            <td style="padding:18px 4px 0 4px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
                <td valign="top" style="{_M}font-size:11px;line-height:1.6;color:{MAIL_FAINT};"><span style="color:{MAIL_TEXT};font-weight:600;">[&gt;<span style="color:{MAIL_CORAL_TEXT};">|</span>]</span>&nbsp; {T["footer"]}</td>
                <td valign="top" align="right" style="{_M}font-size:11px;line-height:1.6;color:{MAIL_FAINT};">{contact_html}</td>
              </tr></table>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>"""


def _ticket_email_html(
    *,
    event_name: str,
    title: str,
    subtitle: str,
    headline: str,
    status_msg: str,
    status: str,
    full_name: str,
    date_val: str,
    event_time: str,
    location_name: str,
    location_address: str,
    tid: str,
    pdf_url: str,
    cancel_url: str = "",
    seat_label: str = "",
    contact: str = "",
    has_banner: bool = False,
    lang: str = "en",
) -> str:
    """
    The ticket email, avocloud kit v4.2 light theme: table layout, inline
    styles, no positioning, no animation; the web fonts are an enhancement.
    All dynamic strings must already be HTML-escaped by the caller.
    """
    T = _tx(lang)
    grid = _m_grid(_m_details(
        T, full_name=full_name, date_val=date_val, event_time=event_time,
        seat_label=seat_label, location_name=location_name,
        location_address=location_address,
    ))
    chip = (_m_chip(MAIL_SUCCESS, T["chip_paid"]) if status == "paid"
            else _m_chip(MAIL_WARNING, T["chip_unpaid"]))
    banner_html = (
        '<tr><td style="padding:0;line-height:0;font-size:0;">'
        f'<img src="cid:banner" width="600" alt="{event_name}" '
        'style="display:block;width:100%;max-width:600px;height:auto;border:0;'
        'border-radius:8px 8px 0 0;"></td></tr>'
        if has_banner else ""
    )
    notes = "".join(
        '<tr>'
        f'<td width="30" valign="top" style="{_M}font-size:12px;line-height:1.55;'
        f'font-weight:500;color:{MAIL_CORAL_TEXT};padding:0 0 8px 0;">{i + 1:02d}</td>'
        f'<td valign="top" style="{_M}font-size:12px;line-height:1.55;color:{MAIL_MUTED};'
        f'padding:0 0 8px 0;">{n}</td></tr>'
        for i, n in enumerate(T["notes"])
    )
    cancel_html = (
        f'<p style="margin:14px 0 0 0;{_M}font-size:12px;line-height:1.6;color:{MAIL_MUTED};">'
        f'{T["email_cancel_pre"]}<a href="{cancel_url}" style="color:{MAIL_CORAL_TEXT};'
        f'font-weight:600;text-decoration:underline;">{T["email_cancel_link"]}</a>'
        f'{T["email_cancel_post"]}</p>'
        if cancel_url else ""
    )
    # One perforation notch: a canvas-coloured disc centred on the card edge,
    # so it reads as a bite out of the card (clients that drop the negative
    # margin just show a dot on the dashed line).
    notch = (f'<div style="width:18px;height:18px;border-radius:50%;background-color:{MAIL_CANVAS};'
             f'margin-{{side}}:-10px;"></div>')

    card = f"""{banner_html}
                {_m_status_block(chip, headline, status_msg)}
                {_m_event_block(T, T["kicker"], title or event_name, subtitle, grid)}

                <!-- perforation -->
                <tr>
                  <td style="padding:0;line-height:0;font-size:0;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                      <tr>
                        <td width="10" valign="middle" style="line-height:0;font-size:0;">{notch.replace("{side}", "left")}</td>
                        <td valign="middle" style="line-height:0;font-size:0;padding:0 8px;"><div style="border-top:1px dashed #BFC3C7;height:0;line-height:0;font-size:0;">&nbsp;</div></td>
                        <td width="10" valign="middle" align="right" style="line-height:0;font-size:0;">{notch.replace("{side}", "right")}</td>
                      </tr>
                    </table>
                  </td>
                </tr>

                <!-- stub: QR + ticket number -->
                <tr>
                  <td align="center" class="pad" style="padding:26px 32px 8px 32px;">
                    <table role="presentation" cellpadding="0" cellspacing="0" style="border:1px solid {MAIL_LINE};border-radius:8px;background-color:#FFFFFF;">
                      <tr><td style="padding:14px;">
                        <img src="cid:qrcode" alt="QR {tid}" width="196" height="196" style="display:block;width:196px;height:196px;border:0;">
                      </td></tr>
                    </table>
                    <div style="margin:16px 0 2px 0;">{_m_label(T["tid"])}</div>
                    <div style="{_M}font-size:20px;font-weight:600;letter-spacing:0.5px;color:{MAIL_TEXT};">{tid}</div>
                    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:18px auto 0 auto;"><tr>
                      <td style="border:1px solid #C8CCD0;border-radius:6px;">
                        <a href="{pdf_url}" style="display:inline-block;padding:10px 16px;{_M}font-size:11px;font-weight:500;letter-spacing:1.2px;text-transform:uppercase;color:{MAIL_TEXT};text-decoration:none;">{T["open_pdf"]}&nbsp; &#8599;</a>
                      </td>
                    </tr></table>
                  </td>
                </tr>

                <!-- how it works -->
                <tr>
                  <td class="pad" style="padding:22px 32px 28px 32px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid {MAIL_LINE_SOFT};">
                      <tr><td colspan="2" style="height:18px;line-height:18px;font-size:0;">&nbsp;</td></tr>
                      {notes}
                    </table>
                    <p style="margin:6px 0 0 0;{_M}font-size:12px;line-height:1.6;color:{MAIL_MUTED};">{T["pdf_attached"]}</p>
                    {cancel_html}
                  </td>
                </tr>"""
    preheader = " · ".join(x for x in (title or event_name, date_val, event_time, tid) if x)
    return _mail_page(lang=lang, title=f"{event_name} · Ticket {tid}", preheader=preheader,
                      event_name=event_name, right_label=T["eticket"], card=card, contact=contact)


def _cancel_email_html(
    *,
    event_name: str,
    title: str,
    subtitle: str,
    headline: str,
    status_msg: str,
    refund_text: str,
    refund_color: str,
    full_name: str,
    date_val: str,
    event_time: str,
    location_name: str,
    location_address: str,
    tid: str,
    seat_label: str = "",
    contact: str = "",
    lang: str = "en",
) -> str:
    """The cancellation confirmation, same frame as the ticket email. No QR,
    no PDF: the ticket is void. All dynamic strings must be HTML-escaped."""
    T = _tx(lang)
    grid = _m_grid(_m_details(
        T, tid=tid, full_name=full_name, date_val=date_val, event_time=event_time,
        seat_label=seat_label, location_name=location_name,
        location_address=location_address,
    ))
    refund = f"""
                <tr>
                  <td class="pad" style="padding:0 32px 4px 32px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;"><tr>
                      <td style="border-left:3px solid {refund_color};padding:2px 0 2px 14px;">
                        {_m_label(T["cx_refund"])}
                        <div style="{_M}font-size:13px;line-height:1.6;color:{MAIL_TEXT};">{refund_text}</div>
                      </td>
                    </tr></table>
                  </td>
                </tr>"""
    card = (_m_status_block(_m_chip(MAIL_ERROR, T["cx_chip"]), headline, status_msg, border=False)
            + refund
            + '<tr><td style="padding:22px 32px 0 32px;" class="pad"><div style="border-top:1px solid '
            + MAIL_LINE_SOFT + ';height:0;line-height:0;font-size:0;">&nbsp;</div></td></tr>'
            + _m_event_block(T, T["cx_kicker"], title or event_name, subtitle, grid)
            + '<tr><td style="height:10px;line-height:10px;font-size:0;">&nbsp;</td></tr>')
    preheader = " · ".join(x for x in (T["cx_chip"], title or event_name, date_val, tid) if x)
    return _mail_page(lang=lang, title=f"{event_name} · {T['cx_chip']} {tid}", preheader=preheader,
                      event_name=event_name, right_label=T["cx_right"], card=card, contact=contact)


def _frontend_base(show_data: dict) -> str:
    """The shop's public base URL without a trailing slash, or "" if none is
    configured. The admin may enter a bare host ("tickets.example.com"), so a
    missing scheme defaults to https://."""
    base = str(
        show_data.get("app_domain")
        or getattr(config.API, "frontend_origin", "")
        or ""
    ).strip().rstrip("/")
    if not base or base.startswith("*"):
        return ""
    if not re.match(r"^https?://", base, re.IGNORECASE):
        base = "https://" + base
    return base


def _clean_recipient(email) -> str:
    """The address, or "" when there is none or it is unusable. CR/LF would
    allow SMTP header injection through the recipient."""
    email = str(email or "").strip()
    if not email:
        return ""
    if any(c in email for c in "\r\n") or "@" not in email:
        logger.error(f"Refusing to send email to invalid address: {email!r}")
        return ""
    return email


async def _smtp_send(message, email: str) -> None:
    """Send a built message. The SMTP handshake (connect/STARTTLS/login/
    sendmail) is blocking and can take seconds against a slow server, so it
    runs in a worker thread; the timeout keeps a dead server from hanging it."""
    raw_message = message.as_string()

    def _send_blocking() -> None:
        with smtplib.SMTP(
            config.Mail.smtp_server, config.Mail.smtp_port, timeout=15
        ) as server:
            server.starttls()
            server.login(config.Mail.smtp_user, config.Mail.smtp_password)
            server.sendmail(config.Mail.smtp_user, email, raw_message)

    await asyncio.to_thread(_send_blocking)


def _money_text(amount, lang: str) -> str:
    try:
        v = float(amount)
    except (TypeError, ValueError):
        return ""
    if str(lang).lower() == "de":
        return f"{v:,.2f} €".replace(",", "X").replace(".", ",").replace("X", ".")
    return f"€{v:,.2f}"


async def send_cancel_email(ticket: dict, actor: str, refund_id: Optional[str],
                            refund_error: Optional[str]) -> None:
    """Confirm a cancellation to the buyer: which ticket, and what happens to
    the money. Sent for every cancellation (self-service link, admin, box
    office); the internal reason is never included."""
    email = _clean_recipient(ticket.get("email"))
    if not email:
        return
    tid = str(ticket.get("tid") or "")
    lang = str(ticket.get("lang") or "en")
    T = _tx(lang)
    show_data = load_show()
    view = ticket_view(tid, ticket=ticket, show_data=show_data)

    # What happens to the money. Only a refund Stripe confirmed is promised.
    price = ticket.get("price")
    if price is None and view["date"] and view["date"] != "Unlimited":
        price = (load_date(view["date"]) or {}).get("price")
    try:
        price_f = float(price) if price is not None else None
    except (TypeError, ValueError):
        price_f = None
    if refund_id:
        amount = _money_text(price_f, lang) if price_f else ""
        refund_text = (T["cx_refund_ok"].format(amount=amount) if amount
                       else T["cx_refund_ok_noamt"])
        refund_color = MAIL_SUCCESS
    elif refund_error:
        refund_text, refund_color = T["cx_refund_fail"], MAIL_WARNING
    elif not ticket.get("paid"):
        refund_text, refund_color = T["cx_refund_none"], MAIL_LINE
    elif price_f == 0 or str(ticket.get("type") or "") in ("admin", "vip"):
        refund_text, refund_color = T["cx_refund_free"], MAIL_LINE
    else:
        refund_text, refund_color = T["cx_refund_counter"], MAIL_WARNING

    event_raw = str(show_data.get("orga_name") or "Event")
    title_raw = str(show_data.get("title") or "").strip()
    date = view["date"]
    date_long = ticket_pdf.long_date(date, lang) if date else ""
    time_long = (ticket_pdf.long_time(view["time"], lang)
                 if view["time"] and date and date != "Unlimited" else "")

    html_content = _cancel_email_html(
        event_name=escape(event_raw),
        title=escape(title_raw),
        subtitle=escape(str(show_data.get("subtitle") or "").strip()),
        headline=escape(T["cx_head_self"] if actor == "self-service" else T["cx_head"]),
        status_msg=escape(T["cx_msg"].format(tid=tid)),
        refund_text=escape(refund_text),
        refund_color=refund_color,
        full_name=escape(view["name"]),
        date_val=escape(date_long),
        event_time=escape(time_long),
        location_name=escape(view["venue"]),
        location_address=escape(view["address"]),
        tid=escape(tid),
        seat_label=escape(view["seat"]),
        contact=escape(view["contact"]),
        lang=lang if lang in TICKET_I18N else "en",
    )
    message = MIMEMultipart("alternative")
    message["From"] = config.Mail.smtp_user
    message["To"] = email
    message["Subject"] = T["cx_subject"].format(
        event=title_raw or event_raw,
        date=_fmt_ticket_date(date) if date and date != "Unlimited" else "",
    ).rstrip(", ")
    message.attach(MIMEText(html_content, "html", "utf-8"))
    await _smtp_send(message, email)


async def send_email(
    first_name: str,
    last_name: str,
    email: str,
    tid: str,
    paid: bool,
    date: str,
    event_time: str,
    type: str = "normal",
    seat_label: Optional[str] = None,
    lang: Optional[str] = None,
):
    """
    Email one ticket: the branded HTML body with the QR inline, the ticket PDF
    attached. The PDF is rendered fresh from the stored ticket, so it matches
    the email (paid state, name, seat).

    seat_label / lang: when None they are looked up from the stored ticket, so
    the seat is shown and the email + PDF use the buyer's language.
    """
    email = _clean_recipient(email)
    if not email:
        return

    try:
        stored = load_ticket_id(tid)
    except Exception:
        stored = None
    if seat_label is None:
        seat_label = (stored or {}).get("seat_label") or ""
    if lang is None:
        lang = (stored or {}).get("lang") or "en"
    T = _tx(lang)

    show_data = load_show()
    view = ticket_view(
        tid, ticket=stored, show_data=show_data,
        first_name=first_name, last_name=last_name, date=date,
        event_time=event_time, seat_label=seat_label, lang=lang, paid=paid,
    )
    # An explicit seat/lang from the caller wins over the stored ticket.
    view["seat"] = str(seat_label).strip() if _meaningful(seat_label) else view["seat"]
    view["lang"] = str(lang)
    pdf_bytes = await asyncio.to_thread(ticket_pdf.render_pdf, [view], "standard")

    status = "paid" if (paid or type != "normal") else "unpaid"
    event_raw = str(show_data.get("orga_name") or "Event")
    title_raw = str(show_data.get("title") or "").strip()
    date_long = ticket_pdf.long_date(date, lang) if date else ""
    time_long = (ticket_pdf.long_time(event_time, lang)
                 if event_time and date and date != "Unlimited" else "")

    # Links in the email point at the shop (admin setting "app_domain", else
    # the env-configured frontend_origin), never at the backend, which buyers
    # usually cannot reach. Both pages are thin PHP proxies to token-gated
    # backend routes.
    frontend_base = _frontend_base(show_data)
    token = ticket_token(tid)
    # Self-service cancel link: only for real dated tickets (a dateless
    # admin/vip ticket has no online cancellation).
    cancel_url = ""
    if frontend_base and date and date != "Unlimited":
        cancel_url = f"{frontend_base}/cancel.php?tid={tid}&token={token}"
    # The PDF link: the shop's ticket.php streams /codes/pdf. Without a shop
    # address the backend URL is the only one there is.
    pdf_url = (
        f"{frontend_base}/ticket.php?tid={tid}&token={token}" if frontend_base
        else f"{str(config.API.backend_url).rstrip('/')}/codes/pdf?tid={tid}&token={token}"
    )

    if type != "normal":
        subject = T["subject_paid"]
        headline, status_msg = T["email_head_paid"], T["email_status_paid_onsite"]
    elif paid:
        subject = T["subject_ready"]
        headline, status_msg = T["email_head_ready"], T["email_status_ready"]
    else:
        subject = T["subject_unpaid"]
        headline, status_msg = T["email_head_ticket"], T["email_status_unpaid"]
    subject = subject.format(event=title_raw or event_raw,
                             date=_fmt_ticket_date(date) if date else "").rstrip(", ")

    banner = await asyncio.to_thread(ticket_pdf.banner_jpeg, MAIL_BANNER_ASPECT, 1200)

    html_content = _ticket_email_html(
        event_name=escape(event_raw),
        title=escape(title_raw),
        subtitle=escape(str(show_data.get("subtitle") or "").strip()),
        headline=escape(headline),
        status_msg=escape(status_msg),
        status=status,
        full_name=escape(view["name"]),
        date_val=escape(date_long),
        event_time=escape(time_long),
        location_name=escape(view["venue"]),
        location_address=escape(view["address"]),
        tid=escape(str(tid)),
        pdf_url=escape(pdf_url),
        cancel_url=escape(cancel_url),
        seat_label=escape(view["seat"]),
        contact=escape(view["contact"]),
        has_banner=bool(banner),
        lang=view["lang"] if view["lang"] in TICKET_I18N else "en",
    )

    message = MIMEMultipart("mixed")
    message["From"] = config.Mail.smtp_user
    message["To"] = email
    message["Subject"] = subject

    # Images go inline (cid) instead of remote URLs: the backend is often not
    # publicly reachable and most clients block remote images by default.
    related = MIMEMultipart("related")
    related.attach(MIMEText(html_content, "html", "utf-8"))
    qr_img = MIMEImage(_qr_png_bytes(tid), _subtype="png")
    qr_img.add_header("Content-ID", "<qrcode>")
    qr_img.add_header("Content-Disposition", "inline", filename=f"{tid}.png")
    related.attach(qr_img)
    if banner:
        b_img = MIMEImage(banner, _subtype="jpeg")
        b_img.add_header("Content-ID", "<banner>")
        b_img.add_header("Content-Disposition", "inline", filename="banner.jpg")
        related.attach(b_img)
    message.attach(related)

    part = MIMEApplication(pdf_bytes, _subtype="pdf")
    part.add_header("Content-Disposition", "attachment", filename=f"Ticket-{tid}.pdf")
    message.attach(part)

    await _smtp_send(message, email)


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
                    lang=ticket.get("lang") or "en",
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
            pdf = await asyncio.to_thread(render_ticket_pdf, tids, "simple")
            if pdf is None:
                return quart.jsonify({"error": "PDF not found"}), 404
            return quart.Response(pdf, mimetype="application/pdf")

        tid = quart.request.args.get("tid")
        if not tid:
            return quart.jsonify({"error": "Missing tid"}), 400
        # Require a valid per-ticket HMAC token (see ticket_token).
        if not _token_valid(tid, quart.request.args.get("token")):
            return quart.jsonify({"error": "Forbidden"}), 403
        # Rendered from the stored ticket on every request, so the PDF always
        # shows the current state (paid, name, seat, cancelled).
        pdf = await asyncio.to_thread(render_ticket_pdf, [tid], "standard")
        if pdf is None:
            return quart.jsonify({"error": "PDF not found"}), 404
        return quart.Response(pdf, mimetype="application/pdf", headers={
            "Content-Disposition": f'inline; filename="Ticket-{tid}.pdf"',
            "Cache-Control": "no-store",
        })


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


# Self-service cancellation is only allowed up to this many hours before the
# event starts. After that, only an admin can cancel (at the box office).
CANCEL_DEADLINE_HOURS = 24


def _self_cancel_deadline_ok(valid_date: str, event_time: str, now) -> bool:
    """True if `now` is at least CANCEL_DEADLINE_HOURS before the event start
    (valid_date + event_time). A missing/unknown time is treated as 00:00 of the
    event day. Returns False for unparseable/dateless tickets (self-cancel then
    simply isn't offered)."""
    from datetime import datetime, timedelta

    try:
        y, m, d = (int(x) for x in str(valid_date).split("-"))
    except Exception:
        return False
    hh, mm = 0, 0
    tm = re.match(r"\s*(\d{1,2}):(\d{2})", str(event_time or ""))
    if tm:
        hh, mm = int(tm.group(1)), int(tm.group(2))
    try:
        event_start = datetime(y, m, d, hh, mm, tzinfo=getattr(now, "tzinfo", None))
    except Exception:
        return False
    return now <= event_start - timedelta(hours=CANCEL_DEADLINE_HOURS)


async def _do_cancel(tid: str, actor: str, reason: str):
    """Shared cancellation core used by BOTH the admin endpoint and the
    self-service link: idempotent active->cancelled flip, seat/availability
    release, stats reversal, Stripe refund and an audit entry. Returns
    (payload_dict, http_status). The caller is responsible for authorization."""
    ticket = await asyncio.to_thread(load_ticket_id, tid)
    if ticket is None:
        return {"status": "error", "message": "Ticket not found"}, 200

    # Idempotency guard: the active -> cancelled flip is the single source of
    # truth. If we don't win it, the ticket was already cancelled, so do NOT
    # release a seat / refund again.
    won = await asyncio.to_thread(mark_ticket_cancelled, tid)
    if not won:
        return {"status": "error", "message": "Ticket already cancelled"}, 200

    valid_date = ticket.get("valid_date")
    t_type = str(ticket.get("type") or "")
    seat_released = False
    # Only dated visitor seats consume capacity; admin/vip/Unlimited don't.
    if valid_date and valid_date != "Unlimited" and t_type not in ("admin", "vip"):
        date_info = await asyncio.to_thread(load_date, valid_date)
        if date_info:
            if date_info.get("seating"):
                # Reserved seating: free the exact seat(s) bound to this ticket
                # instead of bumping a numeric counter.
                await asyncio.to_thread(release_seats_for_ticket, valid_date, tid)
            else:
                await asyncio.to_thread(increment_availability, valid_date, 1)
            seat_released = True
            # A sale is logged at creation for every dated seat (paid or not),
            # so reverse the stats whenever we release that seat. Newer tickets
            # store the amount actually booked; older ones fall back to the
            # date price, which is what was logged for them.
            try:
                stored = ticket.get("price")
                price = float(stored) if stored is not None else float(date_info.get("price") or 0)
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

    # Tell the buyer, in the background: a slow mail server must never hold
    # up the cancellation (or a whole box-office void) it confirms.
    if ticket.get("email"):
        async def _notify():
            try:
                await send_cancel_email(ticket, actor, refund_id, refund_error)
            except Exception as e:
                logger.error(f"Cancellation email for {tid} failed: {e}")
        asyncio.get_running_loop().create_task(_notify())

    msg = "Ticket cancelled."
    if seat_released:
        msg += " Seat released."
    if refund_id:
        msg += f" Refunded ({refund_id})."
    elif refund_error:
        msg += f" Refund FAILED: {refund_error} — refund manually in Stripe."

    return (
        {
            "status": "success",
            "message": msg,
            "seat_released": seat_released,
            "refund_id": refund_id,
            "refund_error": refund_error,
        },
        200,
    )


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
            payload, status = await _do_cancel(tid, actor, reason)
            return quart.jsonify(payload), status
        except Exception as e:
            logger.error(f"cancel_ticket error: {e}")
            return quart.jsonify({"status": "error", "message": str(e)}), 500

    @app.route("/api/ticket/self-cancel", methods=["POST"])   # type: ignore
    async def self_cancel_route():
        """Buyer-facing cancellation from the email link. Authorized NOT by the
        shared admin key but by the ticket's per-ticket HMAC token (see
        ticket_token), and only allowed up to CANCEL_DEADLINE_HOURS before the
        event. On success it runs the exact same refund/seat-release path as the
        admin cancel."""
        try:
            data: dict = await quart.request.get_json(silent=True) or {}
            tid = str(data.get("tid") or "").strip().upper()
            token = str(data.get("token") or "").strip()
            reason = str(data.get("reason") or "").strip() or "self-service"
            if not tid:
                return quart.jsonify({"status": "error", "message": "Missing tid"}), 400
            # Timing-safe per-ticket token check (tids are low-entropy; the token
            # is what actually authorizes this public request).
            if not _token_valid(tid, token):
                return quart.jsonify({"status": "error", "message": "Forbidden"}), 403

            ticket = await asyncio.to_thread(load_ticket_id, tid)
            if ticket is None:
                return quart.jsonify({"status": "error", "message": "Ticket not found"}), 200
            if str(ticket.get("status") or "active") == "cancelled":
                return (
                    quart.jsonify({"status": "error", "message": "Ticket already cancelled"}),
                    200,
                )

            valid_date = str(ticket.get("valid_date") or "")
            t_type = str(ticket.get("type") or "")
            # Dateless admin/vip tickets can't be self-cancelled.
            if not valid_date or valid_date == "Unlimited" or t_type in ("admin", "vip"):
                return (
                    quart.jsonify(
                        {"status": "error", "message": "This ticket can't be cancelled online."}
                    ),
                    200,
                )

            date_info = await asyncio.to_thread(load_date, valid_date)
            event_time = (date_info or {}).get("time", "")
            if not _self_cancel_deadline_ok(valid_date, event_time, local_now()):
                return (
                    quart.jsonify(
                        {
                            "status": "error",
                            "message": (
                                "The cancellation deadline has passed "
                                f"({CANCEL_DEADLINE_HOURS}h before the event)."
                            ),
                            "code": "deadline_passed",
                        }
                    ),
                    200,
                )

            payload, status = await _do_cancel(tid, "self-service", reason)
            return quart.jsonify(payload), status
        except Exception as e:
            logger.error(f"self_cancel error: {e}")
            return quart.jsonify({"status": "error", "message": str(e)}), 500

    @app.route("/api/ticket/self-cancel/preview", methods=["POST"])   # type: ignore
    async def self_cancel_preview_route():
        """Read-only summary for the self-service cancel page: enough to render a
        ticket card (event, seat, date) and decide which state to show, WITHOUT
        cancelling anything. Same per-ticket HMAC token authorization as the
        cancel route; returns only the buyer's own non-sensitive fields."""
        try:
            data: dict = await quart.request.get_json(silent=True) or {}
            tid = str(data.get("tid") or "").strip().upper()
            token = str(data.get("token") or "").strip()
            if not tid:
                return quart.jsonify({"status": "error", "message": "Missing tid"}), 400
            if not _token_valid(tid, token):
                return quart.jsonify({"status": "error", "message": "Forbidden"}), 403

            ticket = await asyncio.to_thread(load_ticket_id, tid)
            if ticket is None:
                return quart.jsonify({"status": "error", "message": "Ticket not found"}), 200

            valid_date = str(ticket.get("valid_date") or "")
            t_type = str(ticket.get("type") or "")
            cancelled = str(ticket.get("status") or "active") == "cancelled"
            dateless = (
                not valid_date or valid_date == "Unlimited" or t_type in ("admin", "vip")
            )

            show_data = await asyncio.to_thread(load_show)
            event_time = ""
            deadline_ok = False
            if not dateless:
                date_info = await asyncio.to_thread(load_date, valid_date)
                event_time = (date_info or {}).get("time", "") or ""
                deadline_ok = _self_cancel_deadline_ok(valid_date, event_time, local_now())
            loc_name, _ = _location_for_date(valid_date, show_data)

            cancellable = (not cancelled) and (not dateless) and deadline_ok
            return (
                quart.jsonify(
                    {
                        "status": "success",
                        "data": {
                            "tid": tid,
                            "first_name": ticket.get("first_name") or "",
                            "seat_label": ticket.get("seat_label") or "",
                            "valid_date": valid_date,
                            "event_time": event_time,
                            "location": loc_name or "",
                            "event_name": show_data.get("orga_name") or "",
                            "used": bool(ticket.get("used_at")),
                            "cancelled": cancelled,
                            "dateless": dateless,
                            "deadline_passed": (not dateless) and (not deadline_ok),
                            "cancellable": cancellable,
                            "deadline_hours": CANCEL_DEADLINE_HOURS,
                        },
                    }
                ),
                200,
            )
        except Exception as e:
            logger.error(f"self_cancel_preview error: {e}")
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


def generate_ticket_id(valid_date=None):
    """A fully random ticket id, XXXX-XXXX-XXXX (uppercase letters + digits).
    No longer date-derived: with the register scanner, staff scan a QR
    instead of typing an id at speed, so there is nothing left for the date
    prefix to make faster to read or type, and a random id can't be guessed
    from a date. `valid_date` is accepted (unused) so existing callers don't
    need to change."""
    alphabet = string.ascii_uppercase + string.digits
    groups = ["".join(random.choice(alphabet) for _ in range(4)) for _ in range(3)]
    return "-".join(groups)