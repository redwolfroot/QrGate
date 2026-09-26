"""
QrGate · guest list PDF: the paper backup at the door for when devices or the
network fail.

One A4 portrait list per date: active tickets sorted by last name, then first
name, with the ticket number in large monospace (to type it in by hand), the
category (VIP and admin marked), seat, status and an empty box to tick. Unpaid
tickets are set in bold and marked OFFEN, they still have to pay at the box
office. Cancelled tickets are left out. Tickets without a date (admin/VIP,
"Unlimited") follow in their own section at the end.

Each page repeats the date and "Seite x von y" at the top and the column
header of the table; a row never breaks across pages. Fonts and colours come
from ticket_pdf, so umlauts render in IBM Plex Mono like on the tickets.
"""
import io
import re
from typing import Any, Dict, List, Optional

from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.units import mm
from reportlab.pdfgen import canvas as rl_canvas
from reportlab.platypus import (
    Flowable, KeepTogether, Paragraph, SimpleDocTemplate, Spacer, Table, TableStyle,
)
from xml.sax.saxutils import escape

from assets import ticket_pdf as tp
from assets.data import export_tickets, load_show
from assets.timeutil import local_now

PAGE_W, PAGE_H = A4
MARGIN = 14 * mm
TOP = 24 * mm  # room for the running header
COLS = [  # (header, width)
    ("Nachname", 40 * mm), ("Vorname", 32 * mm), ("Ticket-Nr.", 37 * mm),
    ("Kategorie", 22 * mm), ("Platz", 22 * mm), ("Status", 21 * mm), ("", 8 * mm),
]


class _TickBox(Flowable):
    """The empty square to tick with a pen."""

    def __init__(self, size: float = 4.2 * mm):
        super().__init__()
        self.size = size

    def wrap(self, aw, ah):
        return self.size, self.size

    def draw(self):
        self.canv.setStrokeColor(tp.INK)
        self.canv.setLineWidth(0.9)
        self.canv.rect(0, 0, self.size, self.size, stroke=1, fill=0)


def _styles() -> Dict[str, ParagraphStyle]:
    base = ParagraphStyle("base", fontName=tp.F_REG, fontSize=8.8, leading=10.6, textColor=tp.INK)
    return {
        "cell": base,
        "bold": ParagraphStyle("bold", parent=base, fontName=tp.F_SEMI),
        "tid": ParagraphStyle("tid", parent=base, fontName=tp.F_SEMI, fontSize=10, leading=12),
        "small": ParagraphStyle("small", parent=base, fontSize=7.8, leading=9.4, textColor=tp.MUTED),
        "head": ParagraphStyle("head", parent=base, fontName=tp.F_MED, fontSize=7, leading=8.4, textColor=tp.MUTED),
        "open": ParagraphStyle("open", parent=base, fontName=tp.F_SEMI, textColor=tp.ERROR),
        "mark": ParagraphStyle("mark", parent=base, fontName=tp.F_SEMI, textColor=tp.CORAL_TEXT),
        "kicker": ParagraphStyle("kicker", parent=base, fontName=tp.F_MED, fontSize=8, leading=10, textColor=tp.MUTED),
        "title": ParagraphStyle("title", parent=base, fontName=tp.F_SEMI, fontSize=17, leading=21),
        "sub": ParagraphStyle("sub", parent=base, fontSize=10, leading=13),
        "section": ParagraphStyle("section", parent=base, fontName=tp.F_SEMI, fontSize=11, leading=14),
    }


def _p(text: Any, style: ParagraphStyle) -> Paragraph:
    return Paragraph(escape(str(text or "")), style)


def _admitted_at(raw: Optional[str]) -> str:
    m = re.search(r"(\d{2}):(\d{2})", str(raw or "")[10:])
    return f"drin {m[1]}:{m[2]}" if m else "drin"


def _row(t: Dict, st: Dict[str, ParagraphStyle]) -> list:
    unpaid = not t.get("paid")
    name = st["bold"] if unpaid else st["cell"]
    t_type = str(t.get("type") or "visitor")
    if t_type in ("vip", "admin"):
        category = _p("VIP" if t_type == "vip" else "ADMIN", st["mark"])
    else:
        category = _p(t.get("category") or "", st["small"])
    if t.get("used_at"):
        status = _p(_admitted_at(t.get("used_at")), st["small"])
    elif unpaid:
        status = _p("OFFEN", st["open"])
    else:
        status = _p("", st["small"])
    return [
        _p(t.get("last_name"), name), _p(t.get("first_name"), name), _p(t.get("tid"), st["tid"]),
        category, _p(t.get("seat_label") or "", st["small"]), status, _TickBox(),
    ]


def _table(tickets: List[Dict], st: Dict[str, ParagraphStyle]) -> Table:
    data = [[_p(h.upper(), st["head"]) for h, _ in COLS]] + [_row(t, st) for t in tickets]
    table = Table(data, colWidths=[w for _, w in COLS], repeatRows=1)
    style = [
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
        ("TOPPADDING", (0, 0), (-1, -1), 4.2),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 4.2),
        ("LEFTPADDING", (0, 0), (-1, -1), 4),
        ("RIGHTPADDING", (0, 0), (-1, -1), 4),
        ("LINEBELOW", (0, 0), (-1, 0), 1, tp.INK),
        ("LINEBELOW", (0, 1), (-1, -1), 0.4, tp.LINE),
        ("ALIGN", (-1, 0), (-1, -1), "CENTER"),
    ]
    for i in range(2, len(data), 2):  # zebra stripes
        style.append(("BACKGROUND", (0, i), (-1, i), tp.WASH))
    table.setStyle(TableStyle(style))
    return table


def _canvas_class(running: str, stamp: str):
    """A canvas that knows the page count when the document is finished, so
    every page can say "Seite x von y"."""

    class _Numbered(rl_canvas.Canvas):
        def __init__(self, *args, **kwargs):
            super().__init__(*args, **kwargs)
            self._pages = []

        def showPage(self):
            self._pages.append(dict(self.__dict__))
            self._startPage()

        def save(self):
            total = len(self._pages)
            for state in self._pages:
                self.__dict__.update(state)
                self._chrome(total)
                super().showPage()
            super().save()

        def _chrome(self, total: int):
            y = PAGE_H - 12 * mm
            tp._mark(self, MARGIN, y - 2.2 * mm, 6.5 * mm)
            self.setFont(tp.F_MED, 8)
            self.setFillColor(tp.INK)
            self.drawString(MARGIN + 9 * mm, y, running)
            self.drawRightString(PAGE_W - MARGIN, y, f"Seite {self._pageNumber} von {total}")
            self.setStrokeColor(tp.LINE)
            self.setLineWidth(0.6)
            self.line(MARGIN, y - 4 * mm, PAGE_W - MARGIN, y - 4 * mm)
            self.setFont(tp.F_REG, 7)
            self.setFillColor(tp.FAINT)
            self.drawString(MARGIN, 9 * mm, f"Stand: {stamp}")
            self.drawRightString(PAGE_W - MARGIN, 9 * mm, "Verwaltet mit QrGate · avocloud.net")

    return _Numbered


def render_guestlist_pdf(date: str) -> Optional[bytes]:
    """The guest list for performance date `date` (YYYY-MM-DD), or None if
    there is no such date. Blocking (SQLite + reportlab): call via to_thread."""
    show = load_show()
    entry = next((d for d in (show.get("dates") or {}).values() if str(d.get("date")) == date), None)
    if entry is None:
        return None
    return _render(show, entry, export_tickets(date), export_tickets("Unlimited"),
                   local_now().strftime("%d.%m.%Y %H:%M"))


def _render(show: Dict, date: Dict, tickets: List[Dict], undated: List[Dict], stamp: str) -> bytes:
    """`date`: the date entry (date, time, location). `tickets`: its active
    tickets, sorted. `undated`: active tickets without a date. `stamp`: when
    the list was made, "26.09.2026 18:12"."""
    st = _styles()
    iso = str(date.get("date") or "")
    loc = (show.get("locations") or {}).get(date.get("location") or "") or {}
    title = str(show.get("title") or "Veranstaltung")
    when = tp.long_date(iso, "de") + (" · " + tp.long_time(date.get("time") or "", "de") if date.get("time") else "")
    short = f"{iso[8:10]}.{iso[5:7]}.{iso[0:4]}" + (f" {date.get('time')}" if date.get("time") else "")
    running = f"Gästeliste · {title} · {short}"
    if len(running) > 70:
        running = running[:69] + "…"

    unpaid = sum(1 for t in tickets if not t.get("paid"))
    counts = f"{len(tickets)} {'Gast' if len(tickets) == 1 else 'Gäste'}"
    if unpaid:
        counts += f" · davon {unpaid} offen (an der Kasse bezahlen)"

    story: list = [
        _p("// Gästeliste", st["kicker"]), Spacer(1, 1.5 * mm),
        _p(title, st["title"]),
    ]
    if show.get("subtitle"):
        story.append(_p(show["subtitle"], st["sub"]))
    story += [
        Spacer(1, 2 * mm),
        _p(when + (" · " + loc["name"] if loc.get("name") else ""), st["sub"]),
        _p(f"Stand: {stamp} · {counts}", st["bold"]),
        Spacer(1, 5 * mm),
    ]
    if tickets:
        story.append(_table(tickets, st))
    else:
        story.append(_p("Für diesen Termin gibt es keine Tickets.", st["cell"]))
    if undated:
        story += [
            Spacer(1, 8 * mm),
            KeepTogether([
                _p(f"Ohne Termin · {len(undated)}", st["section"]),
                _p("Admin- und VIP-Tickets, die an jedem Termin gelten.", st["small"]),
                Spacer(1, 2 * mm),
                _table(undated, st),
            ]),
        ]

    buf = io.BytesIO()
    doc = SimpleDocTemplate(
        buf, pagesize=A4, leftMargin=MARGIN, rightMargin=MARGIN, topMargin=TOP, bottomMargin=16 * mm,
        title=f"Gästeliste {title} {short}", author=str(show.get("orga_name") or "QrGate"),
        creator="QrGate · avocloud.net",
    )
    doc.build(story, canvasmaker=_canvas_class(running, stamp))
    return buf.getvalue()
