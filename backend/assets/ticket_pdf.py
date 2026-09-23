"""
QrGate · ticket PDF (avocloud brand kit v4.2, light UI theme).

One drawing routine for both ticket formats:

  A4  "standard"  what the buyer gets by email: event banner, event block,
                  details grid, a perforated stub with the QR code.
  A5  "simple"    the box-office print: same design, no banner, tighter.

The page is drawn on a reportlab canvas instead of platypus flowables, so the
layout is exact and nothing reflows onto a second page. Everything comes from
the stored ticket, so a PDF is always current (paid state, name, seat) and
nothing needs to be cached on disk.

Type follows the UI theme: IBM Plex Mono for everything, Syne only for the
organiser wordmark. The fonts are vendored in ./fonts (SIL OFL, see the
licence files there); without them the PDF falls back to Courier/Helvetica.
"""
import io
import os
from datetime import datetime
from typing import Any, Dict, Optional, Tuple

import qrcode
from reportlab.lib import colors
from reportlab.lib.pagesizes import A4, A5
from reportlab.lib.utils import ImageReader, simpleSplit
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas as rl_canvas

_HERE = os.path.dirname(os.path.abspath(__file__))
FONT_DIR = os.path.join(_HERE, "fonts")
ASSET_DIR = os.path.join(os.path.dirname(_HERE), "data", "assets")

# ---- fonts ------------------------------------------------------------------
F_REG, F_MED, F_SEMI, F_MARK = "Courier", "Courier", "Courier-Bold", "Helvetica-Bold"


def _register_fonts() -> None:
    global F_REG, F_MED, F_SEMI, F_MARK
    files = {
        "PlexMono": "IBMPlexMono-Regular.ttf",
        "PlexMono-Medium": "IBMPlexMono-Medium.ttf",
        "PlexMono-SemiBold": "IBMPlexMono-SemiBold.ttf",
        "Syne-ExtraBold": "Syne-ExtraBold.ttf",
    }
    try:
        for name, fn in files.items():
            pdfmetrics.registerFont(TTFont(name, os.path.join(FONT_DIR, fn)))
    except Exception:
        return  # keep the built-in fallbacks
    F_REG, F_MED, F_SEMI, F_MARK = "PlexMono", "PlexMono-Medium", "PlexMono-SemiBold", "Syne-ExtraBold"


_register_fonts()

# ---- palette: the kit's light roles ---------------------------------------
INK = colors.HexColor("#0A0A0A")          # --avo-text (light)
MUTED = colors.HexColor("#555555")        # --avo-text-muted (light)
FAINT = colors.HexColor("#8A8C90")
LINE = colors.HexColor("#D6D9DC")         # rgba(0,0,0,.14) on white
LINE_SOFT = colors.HexColor("#E6E8EA")
PAPER = colors.white                      # --avo-surface (light)
WASH = colors.HexColor("#F4F6F7")         # --avo-canvas (light)
CORAL = colors.HexColor("#FF6B4A")        # --avo-primary: fills, black text on it
CORAL_TEXT = colors.HexColor("#C73D20")   # --avo-primary-text-small
SUCCESS = colors.HexColor("#46A758")
WARNING = colors.HexColor("#E0A33A")
ERROR = colors.HexColor("#DC3838")

# ---- copy -------------------------------------------------------------------
TEXT = {
    "de": {
        "kicker": "Eintrittskarte",
        "eticket": "E-Ticket",
        "name": "Name", "date": "Datum", "time": "Beginn", "seat": "Platz",
        "venue": "Ort", "tid": "Ticket-Nr.",
        "free_seating": "Freie Platzwahl",
        "any_date": "Beliebiger Termin",
        "paid": "Bezahlt",
        "unpaid": "Zahlung offen",
        "unpaid_hint": "An der Abendkasse bezahlen",
        "cancelled": "Storniert",
        "cancelled_hint": "Dieses Ticket ist ungültig",
        "notes": [
            "QR-Code am Einlass zeigen, auf dem Handy oder ausgedruckt.",
            "Gilt für einen Eintritt am angegebenen Termin.",
            "Wiedereinlass nur mit Stempel oder Bändchen vom Ausgang.",
        ],
        "note_short": "QR-Code am Einlass zeigen. Gilt für einen Eintritt.",
        "contact": "Fragen?",
        "footer": "Verwaltet mit QrGate · avocloud.net",
        "weekdays": ["Montag", "Dienstag", "Mittwoch", "Donnerstag", "Freitag", "Samstag", "Sonntag"],
        "months": ["Januar", "Februar", "März", "April", "Mai", "Juni", "Juli",
                   "August", "September", "Oktober", "November", "Dezember"],
        "date_fmt": "{wd}, {d}. {m} {y}",
        "time_fmt": "{t} Uhr",
    },
    "en": {
        "kicker": "Admission ticket",
        "eticket": "E-ticket",
        "name": "Name", "date": "Date", "time": "Starts", "seat": "Seat",
        "venue": "Venue", "tid": "Ticket no.",
        "free_seating": "Free seating",
        "any_date": "Any date",
        "paid": "Paid",
        "unpaid": "Payment due",
        "unpaid_hint": "Pay at the box office",
        "cancelled": "Cancelled",
        "cancelled_hint": "This ticket is not valid",
        "notes": [
            "Show the QR code at the door, on your phone or printed.",
            "Valid for one entry on the date shown.",
            "To re-enter, get a stamp or wristband at the exit.",
        ],
        "note_short": "Show the QR code at the door. Valid for one entry.",
        "contact": "Questions?",
        "footer": "Managed by QrGate · avocloud.net",
        "weekdays": ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"],
        "months": ["January", "February", "March", "April", "May", "June", "July",
                   "August", "September", "October", "November", "December"],
        "date_fmt": "{wd}, {d} {m} {y}",
        "time_fmt": "{t}",
    },
}


def _t(lang: Optional[str]) -> Dict[str, Any]:
    return TEXT.get(str(lang or "en").lower(), TEXT["en"])


def long_date(iso: str, lang: Optional[str]) -> str:
    """2026-03-14 -> 'Samstag, 14. März 2026' / 'Saturday, 14 March 2026'."""
    T = _t(lang)
    if not iso or iso == "Unlimited":
        return T["any_date"]
    try:
        d = datetime.strptime(iso, "%Y-%m-%d")
    except ValueError:
        return iso
    return T["date_fmt"].format(wd=T["weekdays"][d.weekday()], d=d.day,
                                m=T["months"][d.month - 1], y=d.year)


def long_time(hhmm: str, lang: Optional[str]) -> str:
    return _t(lang)["time_fmt"].format(t=hhmm) if hhmm else ""


# ---- images -----------------------------------------------------------------
_img_cache: Dict[Tuple[str, float, float, int], bytes] = {}


def _asset_path(name: str) -> Optional[str]:
    p = os.path.join(ASSET_DIR, name)
    return p if os.path.isfile(p) else None


def cover_jpeg(path: str, aspect: float, max_w: int = 1600) -> Optional[bytes]:
    """The image cropped to `aspect` (w/h) around its centre, scaled down and
    re-encoded as JPEG. Uploads can be up to 32 MB; the ticket needs ~150 KB."""
    try:
        key = (path, os.path.getmtime(path), round(aspect, 3), max_w)
        if key in _img_cache:
            return _img_cache[key]
        from PIL import Image as PILImage

        with PILImage.open(path) as im:
            im = im.convert("RGBA")
            bg = PILImage.new("RGB", im.size, (255, 255, 255))
            bg.paste(im, mask=im.split()[3])
            w, h = bg.size
            if w / h > aspect:            # too wide: trim the sides
                nw = int(h * aspect)
                box = ((w - nw) // 2, 0, (w - nw) // 2 + nw, h)
            else:                         # too tall: trim top and bottom
                nh = int(w / aspect)
                box = (0, (h - nh) // 2, w, (h - nh) // 2 + nh)
            out = bg.crop(box)
            if out.width > max_w:
                out = out.resize((max_w, int(max_w / aspect)), PILImage.LANCZOS)
            buf = io.BytesIO()
            out.save(buf, "JPEG", quality=84, optimize=True)
        _img_cache.clear()                # one banner at a time is plenty
        _img_cache[key] = buf.getvalue()
        return _img_cache[key]
    except Exception:
        return None


def banner_jpeg(aspect: float, max_w: int = 1600) -> Optional[bytes]:
    p = _asset_path("banner.png")
    return cover_jpeg(p, aspect, max_w) if p else None


# ---- drawing helpers ----------------------------------------------------------
def _qr_matrix(tid: str):
    qr = qrcode.QRCode(error_correction=qrcode.constants.ERROR_CORRECT_M, border=0)
    qr.add_data(tid)
    qr.make(fit=True)
    return qr.get_matrix()


def _draw_qr(c, tid: str, x: float, y: float, size: float) -> None:
    """The QR as vector squares: sharp at any zoom and on any printer."""
    m = _qr_matrix(tid)
    n = len(m)
    cell = size / n
    c.setFillColor(colors.black)
    for r, row in enumerate(m):
        run = None
        for col, on in enumerate(row + [False]):
            if on and run is None:
                run = col
            elif not on and run is not None:
                # tiny overlap so no hairline gaps show between modules
                c.rect(x + run * cell, y + size - (r + 1) * cell,
                       (col - run) * cell + 0.05, cell + 0.05, stroke=0, fill=1)
                run = None


def _corner_marks(c, x: float, y: float, w: float, h: float, arm: float, lw: float) -> None:
    """The kit's corner marks (.avo-mk): four coral L-brackets on a box."""
    c.setStrokeColor(CORAL)
    c.setLineWidth(lw)
    c.setLineCap(1)
    for cx, cy, dx, dy in ((x, y + h, 1, -1), (x + w, y + h, -1, -1),
                           (x, y, 1, 1), (x + w, y, -1, 1)):
        p = c.beginPath()
        p.moveTo(cx, cy + dy * arm)
        p.lineTo(cx, cy)
        p.lineTo(cx + dx * arm, cy)
        c.drawPath(p, stroke=1, fill=0)


def _mark(c, x: float, y: float, size: float) -> None:
    """The avocloud mark (light-bg variant), from logo/avocloud-mark-light.svg."""
    s = size / 72.0

    def P(px, py):
        return x + px * s, y + (72 - py) * s

    c.saveState()
    c.setLineCap(1)
    c.setLineJoin(1)
    c.setStrokeColor(colors.HexColor("#141414"))
    for pts, lw in (([(22, 18), (12, 18), (12, 54), (22, 54)], 5.5),
                    ([(50, 18), (60, 18), (60, 54), (50, 54)], 5.5),
                    ([(26, 30), (33, 36), (26, 42)], 5.0)):
        c.setLineWidth(lw * s)
        p = c.beginPath()
        p.moveTo(*P(*pts[0]))
        for pt in pts[1:]:
            p.lineTo(*P(*pt))
        c.drawPath(p, stroke=1, fill=0)
    c.setFillColor(CORAL_TEXT)
    bx, by = P(40, 44)
    c.roundRect(bx, by, 4 * s, 16 * s, 2 * s, stroke=0, fill=1)
    c.restoreState()


def _label(c, x: float, y: float, text: str, size: float, color=MUTED) -> None:
    c.setFont(F_MED, size)
    c.setFillColor(color)
    c.drawString(x, y, text.upper(), charSpace=size * 0.12)


def _kicker(c, x: float, y: float, text: str, size: float) -> None:
    c.setFont(F_MED, size)
    c.setFillColor(CORAL_TEXT)
    c.drawString(x, y, "//", charSpace=size * 0.12)
    c.setFillColor(MUTED)
    c.drawString(x + c.stringWidth("// ", F_MED, size) + size * 0.36, y,
                 text.upper(), charSpace=size * 0.14)


def _fit_lines(text: str, font: str, size: float, width: float, max_lines: int):
    lines = simpleSplit(text, font, size, width) or [""]
    if len(lines) > max_lines:
        lines = lines[:max_lines]
        last = lines[-1]
        while last and pdfmetrics.stringWidth(last + "…", font, size) > width:
            last = last[:-1]
        lines[-1] = last.rstrip() + "…"
    return lines


# ---- the ticket ---------------------------------------------------------------
def draw_ticket(c, page, v: Dict[str, Any], compact: bool = False) -> None:
    """Draw one ticket on the current canvas page.

    `v` (all strings already plain text):
      tid, name, date, time, seat, venue, address, status ('paid' | 'unpaid'
      | 'cancelled'), lang, orga, title, subtitle, contact
    """
    T = _t(v.get("lang"))
    W, H = page
    M = 24 if compact else 40                 # page margin
    CW = W - 2 * M                            # card width
    P = 20 if compact else 30                 # card padding
    S = 0.82 if compact else 1.0              # type scale
    R = 8                                     # --avo-radius-lg

    c.setFillColor(PAPER)
    c.rect(0, 0, W, H, stroke=0, fill=1)

    # -- header: organiser wordmark (Syne) + "// E-TICKET" ---------------------
    top = H - M
    hx = M
    logo = _asset_path("logo.png")
    logo_sz = 24 * S
    if logo:
        try:
            ir = ImageReader(logo)
            iw, ih = ir.getSize()
            k = min(logo_sz / iw, logo_sz / ih)
            c.drawImage(ir, hx, top - logo_sz + (logo_sz - ih * k) / 2, iw * k, ih * k,
                        mask="auto")
            hx += iw * k + 9
        except Exception:
            pass
    lab = 7.5 * S
    et = T["eticket"].upper()
    et_w = (c.stringWidth("// ", F_MED, lab) + lab * 0.36
            + c.stringWidth(et, F_MED, lab) + len(et) * lab * 0.12)
    _kicker(c, W - M - et_w, top - logo_sz / 2 - lab * 0.35, et, lab)
    # The organiser name is the wordmark: shrink it to fit before cutting it.
    orga = (v.get("orga") or "").upper()
    room = W - M - et_w - 20 - hx
    wm_size = 15 * S
    while wm_size > 9 and pdfmetrics.stringWidth(orga, F_MARK, wm_size) > room:
        wm_size -= 0.5
    c.setFont(F_MARK, wm_size)
    c.setFillColor(INK)
    c.drawString(hx, top - logo_sz / 2 - wm_size * 0.35,
                 _fit_lines(orga, F_MARK, wm_size, room, 1)[0])

    # -- card geometry ---------------------------------------------------------
    card_top = top - logo_sz - (14 if compact else 18)
    qr_size = 128 if compact else 168
    qx = M + P + 6
    sx = qx + qr_size + (26 if compact else 36)
    sw = M + CW - P - sx
    l_size = 7 * S
    tid_size = 15 if compact else 20
    cs = 7.5 * S                              # status chip label
    n_size = 8.5 * S
    status = v.get("status") or "paid"
    st_col, st_txt, st_hint = {
        "paid": (SUCCESS, T["paid"], ""),
        "unpaid": (WARNING, T["unpaid"], T["unpaid_hint"]),
        "cancelled": (ERROR, T["cancelled"], T["cancelled_hint"]),
    }.get(status, (SUCCESS, T["paid"], ""))
    notes = [T["note_short"]] if compact else T["notes"]
    note_x = 0 if compact else 24
    wrapped = [_fit_lines(n, F_REG, n_size, sw - note_x, 2) for n in notes]
    notes_h = sum(len(w) for w in wrapped) * n_size * 1.4 + (len(notes) - 1) * 5
    right_h = (l_size + 8 + tid_size * 0.85 + 16 + cs + 12
               + (8 + n_size * 1.2 if st_hint else 0) + 18 + notes_h)
    inner_h = max(qr_size, right_h)
    stub_h = inner_h + 2 * P + (8 if compact else 12)

    # Measure the event block first so the card height is known.
    title = (v.get("title") or "").strip() or (v.get("orga") or "").strip()
    subtitle = (v.get("subtitle") or "").strip()
    if subtitle == title:
        subtitle = ""
    t_size = 17 if compact else 23
    t_lead = t_size * 1.18
    title_lines = _fit_lines(title, F_SEMI, t_size, CW - 2 * P, 2)
    sub_size = 10.5 * S
    sub_lines = _fit_lines(subtitle, F_REG, sub_size, CW - 2 * P, 2) if subtitle else []

    col_w = (CW - 2 * P - 18) / 2
    v_size = 11.5 * S
    l_size = 7 * S
    fields = []                           # (label, value, extra, kind)
    if v.get("name"):
        fields.append((T["name"], v["name"], "", "text"))
    fields.append((T["date"], long_date(v.get("date") or "", v.get("lang")), "", "text"))
    if v.get("time") and v.get("date") not in ("", "Unlimited"):
        fields.append((T["time"], long_time(v["time"], v.get("lang")), "", "text"))
    fields.append((T["seat"], v.get("seat") or T["free_seating"], "",
                   "seat" if v.get("seat") else "text"))
    if v.get("venue") or v.get("address"):
        fields.append((T["venue"], v.get("venue") or v.get("address"),
                       v.get("address") if v.get("venue") else "", "text"))

    def field_h(f):
        lines = len(_fit_lines(f[1], F_MED, v_size, col_w, 2))
        extra = len(_fit_lines(f[2], F_REG, 9 * S, col_w, 2)) if f[2] else 0
        h = l_size + 7 + lines * v_size * 1.3 + extra * 9 * S * 1.35
        return h + (6 if f[3] == "seat" else 0)

    rows = [fields[i:i + 2] for i in range(0, len(fields), 2)]
    row_gap = 14 * S
    grid_h = sum(max(field_h(f) for f in r) for r in rows) + row_gap * (len(rows) - 1)

    kick = 7.5 * S
    # Baseline to baseline: kicker -> title -> subtitle -> rule -> grid.
    title_gap = 14 + t_size * 0.78
    sub_gap = 9 + sub_size
    text_h = (title_gap + (len(title_lines) - 1) * t_lead
              + (sub_gap + (len(sub_lines) - 1) * sub_size * 1.35 if sub_lines else 0))
    event_h = P + kick + text_h + 18 * S + 18 * S + grid_h + P
    # The banner takes what is left above the footer, capped at 3.2:1; below
    # 70pt a strip of picture is noise, so it is dropped instead.
    banner_h = 0
    banner = None
    if not compact:
        room = card_top - (M + 30) - event_h - stub_h
        banner_h = min(round(CW / 3.2), int(room))
        banner = banner_jpeg(CW / banner_h) if banner_h >= 70 else None
        if not banner:
            banner_h = 0
    card_h = banner_h + event_h + stub_h
    card_y = card_top - card_h
    x0 = M

    # -- card ------------------------------------------------------------------
    c.setFillColor(PAPER)
    c.setStrokeColor(LINE)
    c.setLineWidth(0.8)
    c.roundRect(x0, card_y, CW, card_h, R, stroke=1, fill=1)

    if banner:
        c.saveState()
        p = c.beginPath()
        p.roundRect(x0, card_y, CW, card_h, R)
        c.clipPath(p, stroke=0, fill=0)
        c.drawImage(ImageReader(io.BytesIO(banner)), x0, card_top - banner_h, CW, banner_h)
        c.restoreState()
        c.setStrokeColor(LINE)
        c.setLineWidth(0.8)
        c.line(x0, card_top - banner_h, x0 + CW, card_top - banner_h)
        c.roundRect(x0, card_y, CW, card_h, R, stroke=1, fill=0)

    # -- event block -----------------------------------------------------------
    y = card_top - banner_h - P - kick
    _kicker(c, x0 + P, y, T["kicker"], kick)
    y -= title_gap
    c.setFont(F_SEMI, t_size)
    c.setFillColor(INK)
    for i, ln in enumerate(title_lines):
        c.drawString(x0 + P, y - i * t_lead, ln)
    y -= (len(title_lines) - 1) * t_lead
    if sub_lines:
        y -= sub_gap
        c.setFont(F_REG, sub_size)
        c.setFillColor(MUTED)
        for i, ln in enumerate(sub_lines):
            c.drawString(x0 + P, y - i * sub_size * 1.35, ln)
        y -= (len(sub_lines) - 1) * sub_size * 1.35
    y -= 18 * S
    c.setStrokeColor(LINE_SOFT)
    c.setLineWidth(0.8)
    c.line(x0 + P, y, x0 + CW - P, y)
    y -= 18 * S

    for r in rows:
        rh = max(field_h(f) for f in r)
        for i, (lab_t, val, extra, kind) in enumerate(r):
            fx = x0 + P + i * (col_w + 18)
            fy = y - l_size
            _label(c, fx, fy, lab_t, l_size)
            fy -= 7 + v_size
            if kind == "seat":
                tw = c.stringWidth(val, F_SEMI, v_size + 1)
                bw = min(col_w, tw + 16)
                c.setFillColor(CORAL)
                c.roundRect(fx, fy - 6, bw, v_size + 12, 6, stroke=0, fill=1)
                c.setFillColor(colors.black)      # black on coral, never white
                c.setFont(F_SEMI, v_size + 1)
                c.drawString(fx + 8, fy - 0.5,
                             _fit_lines(val, F_SEMI, v_size + 1, col_w - 16, 1)[0])
                continue
            c.setFont(F_MED, v_size)
            c.setFillColor(INK)
            for ln in _fit_lines(val, F_MED, v_size, col_w, 2):
                c.drawString(fx, fy, ln)
                fy -= v_size * 1.3
            if extra:
                c.setFont(F_REG, 9 * S)
                c.setFillColor(MUTED)
                fy += v_size * 1.3 - 9 * S * 1.35 - 1
                for ln in _fit_lines(extra, F_REG, 9 * S, col_w, 2):
                    c.drawString(fx, fy, ln)
                    fy -= 9 * S * 1.35
        y -= rh + row_gap

    # -- perforation: dashed tear line with a notch bitten out of each edge ----
    py = card_y + stub_h
    notch = 8 if compact else 10
    c.setFillColor(PAPER)
    for nx in (x0, x0 + CW):
        c.circle(nx, py, notch, stroke=0, fill=1)
    c.saveState()
    p = c.beginPath()
    p.rect(x0, card_y, CW, card_h)
    c.clipPath(p, stroke=0, fill=0)
    c.setStrokeColor(LINE)
    c.setLineWidth(0.8)
    for nx in (x0, x0 + CW):
        c.circle(nx, py, notch, stroke=1, fill=0)
    c.restoreState()
    c.setStrokeColor(colors.HexColor("#BFC3C7"))
    c.setLineWidth(0.9)
    c.setDash(3, 3)
    c.line(x0 + notch + 6, py, x0 + CW - notch - 6, py)
    c.setDash()

    # -- stub: QR with corner marks; number, status and notes beside it -----
    inner_top = card_y + stub_h - (stub_h - inner_h) / 2
    qy = inner_top - inner_h / 2 - qr_size / 2
    _draw_qr(c, v["tid"], qx, qy, qr_size)
    _corner_marks(c, qx - 8, qy - 8, qr_size + 16, qr_size + 16, 12 if compact else 15, 1.4)

    sy = inner_top
    _label(c, sx, sy - l_size, T["tid"], l_size)
    sy -= l_size + 8 + tid_size * 0.85
    c.setFont(F_SEMI, tid_size)
    c.setFillColor(INK)
    c.drawString(sx, sy, v["tid"], charSpace=0.5)

    chip_t = st_txt.upper()
    chip_h = cs + 12
    chip_w = c.stringWidth(chip_t, F_MED, cs) + len(chip_t) * cs * 0.12 + 26
    sy -= 16 + chip_h                         # chip bottom edge
    c.setStrokeColor(st_col)
    c.setLineWidth(0.9)
    c.setFillColor(PAPER)
    c.roundRect(sx, sy, chip_w, chip_h, 6, stroke=1, fill=1)
    c.setFillColor(st_col)
    c.circle(sx + 10, sy + chip_h / 2, 2.6, stroke=0, fill=1)
    _label(c, sx + 18, sy + 5.5, chip_t, cs, INK)
    if st_hint:
        sy -= 8 + n_size
        c.setFont(F_REG, n_size)
        c.setFillColor(MUTED)
        c.drawString(sx, sy, _fit_lines(st_hint, F_REG, n_size, sw, 1)[0])
        sy -= n_size * 0.2

    ny = sy - 18 - n_size
    for i, lines in enumerate(wrapped):
        if not compact:
            c.setFont(F_MED, n_size)
            c.setFillColor(CORAL_TEXT)
            c.drawString(sx, ny, f"{i + 1:02d}")
        c.setFont(F_REG, n_size)
        c.setFillColor(MUTED)
        for ln in lines:
            c.drawString(sx + note_x, ny, ln)
            ny -= n_size * 1.4
        ny -= 5

    # -- footer ------------------------------------------------------------------
    fy = M + 2
    _mark(c, M, fy - 4, 16)
    c.setFont(F_REG, 7.5 * S)
    c.setFillColor(FAINT)
    c.drawString(M + 22, fy + 1, T["footer"])
    contact = (v.get("contact") or "").strip()
    if contact:
        txt = f"{T['contact']} {contact}"
        c.drawRightString(W - M, fy + 1, _fit_lines(txt, F_REG, 7.5 * S, CW * 0.45, 1)[0])


def render_pdf(views, fmt: str = "standard") -> bytes:
    """One page per ticket view. fmt 'standard' = A4, 'simple' = A5."""
    page = A5 if fmt == "simple" else A4
    buf = io.BytesIO()
    c = rl_canvas.Canvas(buf, pagesize=page, pageCompression=1)
    first = views[0] if views else {}
    c.setTitle(f"{first.get('orga') or 'Ticket'} · Ticket {first.get('tid', '')}".strip())
    c.setAuthor(first.get("orga") or "QrGate")
    c.setCreator("QrGate · avocloud.net")
    for v in views:
        draw_ticket(c, page, v, compact=(fmt == "simple"))
        c.showPage()
    c.save()
    return buf.getvalue()
