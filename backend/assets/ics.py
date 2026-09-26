import datetime as dt
from functools import lru_cache
from typing import Optional
from urllib.parse import urlparse

import config.conf as config
from assets.timeutil import _tzinfo

# --------------------------------------------------------------------------- #
# Calendar entries (RFC 5545) for a ticket or a date. Attached to the ticket
# and reminder emails and served by /codes/ics. Times carry the configured
# zone (TZID) with a VTIMEZONE block built from zoneinfo, so Outlook gets the
# daylight-saving switch right too.
# --------------------------------------------------------------------------- #
DEFAULT_DURATION_MIN = 120
ALARM_MINUTES_BEFORE = 120

I18N = {
    "en": {"ticket": "Ticket", "seat": "Seat", "pdf": "Ticket (PDF)", "cancel": "Cancel"},
    "de": {"ticket": "Ticket", "seat": "Platz", "pdf": "Ticket (PDF)", "cancel": "Stornieren"},
}


def event_duration_min(show: dict) -> int:
    try:
        return min(24 * 60, max(15, int(show.get("event_duration_min") or DEFAULT_DURATION_MIN)))
    except (TypeError, ValueError):
        return DEFAULT_DURATION_MIN


def _esc(text) -> str:
    """TEXT value escaping (RFC 5545 3.3.11)."""
    return (str(text or "").replace("\\", "\\\\").replace(";", "\\;")
            .replace(",", "\\,").replace("\r\n", "\\n").replace("\n", "\\n").replace("\r", ""))


def _fold(line: str) -> str:
    """Fold at 75 octets without splitting a UTF-8 sequence."""
    out, cur, size = [], "", 0
    for ch in line:
        n = len(ch.encode("utf-8"))
        if size + n > 75:
            out.append(cur)
            cur, size = " ", 1
        cur += ch
        size += n
    out.append(cur)
    return "\r\n".join(out)


def _fmt_local(t: dt.datetime) -> str:
    return t.strftime("%Y%m%dT%H%M%S")


def _fmt_utc(t: dt.datetime) -> str:
    return t.astimezone(dt.timezone.utc).strftime("%Y%m%dT%H%M%SZ")


def _fmt_offset(off: dt.timedelta) -> str:
    mins = int(off.total_seconds() // 60)
    sign = "+" if mins >= 0 else "-"
    mins = abs(mins)
    return f"{sign}{mins // 60:02d}{mins % 60:02d}"


def _transitions(tz, year: int) -> list:
    """(local onset, offset before, offset after, abbreviation) for each UTC
    offset change in `year`, found by scanning hours."""
    found = []
    t = dt.datetime(year, 1, 1, tzinfo=dt.timezone.utc)
    end = dt.datetime(year + 1, 1, 1, tzinfo=dt.timezone.utc)
    prev = t.astimezone(tz).utcoffset()
    while t < end:
        nxt = t + dt.timedelta(hours=1)
        off = nxt.astimezone(tz).utcoffset()
        if off != prev:
            # Onset in the local time that was in effect before the switch.
            onset = (nxt.replace(tzinfo=None) + prev)
            found.append((onset, prev, off, nxt.astimezone(tz).tzname() or ""))
            prev = off
        t = nxt
    return found


@lru_cache(maxsize=16)
def _vtimezone(tz, tzid: str, year: int) -> tuple:
    """Depends only on zone and year; cached, the hour scan is not free."""
    lines = ["BEGIN:VTIMEZONE", f"TZID:{tzid}"]
    trans = _transitions(tz, year)
    if not trans:
        ref = dt.datetime(year, 1, 1, 12, tzinfo=tz)
        off = _fmt_offset(ref.utcoffset() or dt.timedelta(0))
        lines += ["BEGIN:STANDARD", "DTSTART:19700101T000000",
                  f"TZOFFSETFROM:{off}", f"TZOFFSETTO:{off}",
                  f"TZNAME:{ref.tzname() or tzid}", "END:STANDARD"]
    for onset, before, after, name in trans:
        kind = "DAYLIGHT" if after > before else "STANDARD"
        lines += [f"BEGIN:{kind}", f"DTSTART:{_fmt_local(onset)}",
                  f"TZOFFSETFROM:{_fmt_offset(before)}", f"TZOFFSETTO:{_fmt_offset(after)}",
                  f"TZNAME:{name}", f"END:{kind}"]
    lines.append("END:VTIMEZONE")
    return tuple(lines)


def _host(base_url: str) -> str:
    """Domain part of the UID: the shop's host, stable across emails."""
    return (urlparse(base_url).hostname if base_url else None) or "qrgate.local"


def build_ics(*, uid: str, date: str, time: str, title: str, location: str = "",
              description: str = "", duration_min: int = DEFAULT_DURATION_MIN,
              url: str = "", cancelled: bool = False,
              organizer: str = "", organizer_name: str = "") -> Optional[bytes]:
    """One VEVENT as bytes, or None when `date` is not a calendar date
    (dateless 'Unlimited' tickets get no entry). A missing or malformed time
    gives an all-day entry. cancelled=True produces METHOD:CANCEL with the
    same UID, so a client that imported the entry can remove it."""
    try:
        day = dt.date.fromisoformat(str(date))
    except (TypeError, ValueError):
        return None
    tz = _tzinfo()
    tzid = getattr(tz, "key", None) or getattr(config, "timezone", None) or "UTC"
    now = dt.datetime.now(dt.timezone.utc)

    try:
        hh, mm = (int(x) for x in str(time).strip().split(":")[:2])
        start = dt.datetime(day.year, day.month, day.day, hh, mm)
    except (TypeError, ValueError):
        start = None

    lines = ["BEGIN:VCALENDAR", "VERSION:2.0", "PRODID:-//avocloud//QrGate//EN",
             "CALSCALE:GREGORIAN", f"METHOD:{'CANCEL' if cancelled else 'PUBLISH'}"]
    if start:
        lines += list(_vtimezone(tz, tzid, day.year))
    lines += ["BEGIN:VEVENT", f"UID:{uid}", f"DTSTAMP:{_fmt_utc(now)}"]
    if start:
        end = start + dt.timedelta(minutes=duration_min)
        lines += [f"DTSTART;TZID={tzid}:{_fmt_local(start)}",
                  f"DTEND;TZID={tzid}:{_fmt_local(end)}"]
    else:
        lines += [f"DTSTART;VALUE=DATE:{day.strftime('%Y%m%d')}",
                  f"DTEND;VALUE=DATE:{(day + dt.timedelta(days=1)).strftime('%Y%m%d')}"]
    lines.append(f"SUMMARY:{_esc(title)}")
    if location:
        lines.append(f"LOCATION:{_esc(location)}")
    if description:
        lines.append(f"DESCRIPTION:{_esc(description)}")
    if url:
        lines.append(f"URL:{url}")
    if organizer and "\n" not in organizer and "\r" not in organizer:
        cn = f";CN={_esc(organizer_name)}" if organizer_name else ""
        lines.append(f"ORGANIZER{cn}:mailto:{organizer}")
    if cancelled:
        lines += ["STATUS:CANCELLED", "SEQUENCE:1"]
    else:
        lines += ["STATUS:CONFIRMED", "SEQUENCE:0", "TRANSP:OPAQUE"]
        if start:
            lines += ["BEGIN:VALARM", "ACTION:DISPLAY", f"DESCRIPTION:{_esc(title)}",
                      f"TRIGGER:-PT{ALARM_MINUTES_BEFORE}M", "END:VALARM"]
    lines += ["END:VEVENT", "END:VCALENDAR"]
    return ("\r\n".join(_fold(l) for l in lines) + "\r\n").encode("utf-8")


def ticket_ics(view: dict, show: dict, *, base_url: str = "", pdf_url: str = "",
               cancel_url: str = "", cancelled: bool = False) -> Optional[bytes]:
    """Calendar entry for one ticket (a `ticket_view` dict). UID is the ticket
    id, so the cancellation entry replaces exactly this one."""
    lang = view.get("lang") if view.get("lang") in I18N else "en"
    T = I18N[lang]
    desc = [f"{T['ticket']}: {view['tid']}"]
    if view.get("seat"):
        desc.append(f"{T['seat']}: {view['seat']}")
    if pdf_url and not cancelled:
        desc.append(f"{T['pdf']}: {pdf_url}")
    if cancel_url and not cancelled:
        desc.append(f"{T['cancel']}: {cancel_url}")
    return build_ics(
        uid=f"{view['tid']}@{_host(base_url)}",
        date=view.get("date") or "", time=view.get("time") or "",
        title=_event_title(show),
        location=", ".join(x for x in (view.get("venue"), view.get("address")) if x),
        description="\n".join(desc),
        duration_min=event_duration_min(show),
        url=pdf_url if not cancelled else "",
        cancelled=cancelled,
        organizer=str(show.get("contact_email") or "").strip() if cancelled else "",
        organizer_name=str(show.get("orga_name") or ""),
    )


def date_ics(date: str, time: str, location: str, show: dict, base_url: str = "") -> Optional[bytes]:
    """Calendar entry for a date without any ticket data (shop confirmation
    page: the browser never sees ticket ids)."""
    return build_ics(
        uid=f"event-{date}@{_host(base_url)}", date=date, time=time,
        title=_event_title(show), location=location,
        description=base_url or "", duration_min=event_duration_min(show), url=base_url,
    )


def _event_title(show: dict) -> str:
    title = str(show.get("title") or "").strip()
    orga = str(show.get("orga_name") or "").strip()
    if title and orga and orga.lower() not in title.lower():
        return f"{title} · {orga}"
    return title or orga or "Event"
