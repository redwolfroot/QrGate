# Plan: CSV-Export, Gästeliste (PDF), automatische Backups

Stand: 2026-09-26. Drei unabhängige Blöcke, einzeln committbar. Muster aus CLAUDE.md gelten: eine Registrierfunktion pro Modul in `main.py`, `asyncio.to_thread` für SQLite/PDF, Auth-Key mit `hmac.compare_digest`, Downloads über ein PHP-Proxy-Skript wie `frontend/admin/backup.php`.

Bewusst **nicht** geplant (Entscheidung des Betreibers): Namenssuche im Scanner und Einlass rückgängig. Wer kein Ticket hat, geht zur Kasse; ein Ticket für den falschen Tag wird ohnehin abgelehnt.

---

## 1. CSV-Export

**Ziel:** Buchhaltung, Abrechnung und Auswertung ohne Datenbank-Zugriff.

**Exporte** (Reiter oder Karte "Export" im Admin, Rolle admin)
1. **Tickets**: `tid, Vorname, Nachname, E-Mail, Typ (visitor/vip/admin), Termin, Sitzplatz, bezahlt, Zahlart, Preis, Verkäufer, gekauft am, eingelassen um, Status (aktiv/storniert), Erstattungs-ID, Sprache`. Filter: Termin (oder alle), nur aktive / inkl. storniert.
2. **Einlass-Log**: eine Zeile pro Scanversuch aus `access_attempts` (`tid, Name, Zeit, Ergebnis, Gerät` falls vorhanden). Filter: Termin.
3. **Umsatz je Tag**: aus `daily_stats` plus Kassenverkäufe (Summe je Zahlart bar/Karte/online), damit der Steuerberater eine Zeile pro Tag bekommt.

**Backend** (neues Modul `backend/assets/export.py`, in `main.py` registrieren)
- `GET /api/export/<tickets|attempts|revenue>.csv?date=&include_cancelled=` (Auth-Key). Antwort `text/csv; charset=utf-8` mit `Content-Disposition: attachment`.
- Daten über neue Funktionen in `assets/data.py` holen (kein SQL in der Route). Tickets liegen bei `load_tickets()` schon als Dicts vor, nur die Zusatzspalten (`price`, `method`, `seller`, `created_at`, `seat_label`, Refund) prüfen, ob `_row_to_ticket` sie liefert, sonst ergänzen.
- **Excel-tauglich:** UTF-8 **mit BOM**, Trennzeichen `;`, Dezimalkomma bei Beträgen, Datum als `TT.MM.JJJJ HH:MM`. Ein Parameter `format=excel|plain` (plain = `,` und Punkt) für andere Tools.
- **CSV-Injection verhindern:** Zellen, die mit `=`, `+`, `-`, `@` oder Tab beginnen, bekommen ein führendes `'` (Namen und E-Mails kommen vom Besucher).
- Großes Ergebnis nicht komplett im Speicher aufbauen, `csv.writer` auf einen Generator/Stream, Datenbankzugriff in `asyncio.to_thread`.

**Frontend**
- `frontend/admin/export.php` als Proxy analog `backup.php` (Admin-Session prüfen, per curl streamen, Dateiname `qrgate-tickets-2026-09-26.csv`).
- Karte im Admin (`index.php` + `admin.js`): Auswahl Export, Termin, Häkchen "Stornierte einschließen", Knopf "Herunterladen".

**Akzeptanz:** Datei in LibreOffice/Excel öffnen: Umlaute korrekt, Spalten getrennt, Beträge als Zahlen. Testticket mit Namen `=1+1` erscheint als Text, nicht als Formel.

---

## 2. Gästeliste als PDF

**Ziel:** Papier-Backup am Einlass, falls Geräte oder Netz ausfallen.

**Inhalt** (ein PDF je Termin, A4 hoch)
- Kopf: Veranstaltungstitel, Datum, Uhrzeit, Ort, "Stand: 26.09.2026 18:12", Zähler "212 Gäste".
- Tabelle, sortiert nach **Nachname**, dann Vorname: Nachname, Vorname, Ticket-ID (Monospace, groß genug zum Abtippen), Kategorie/Typ (VIP/Admin markiert), Sitzplatz, Status, **leeres Häkchenfeld**.
- **Unbezahlte** Tickets klar markieren (z. B. Fettdruck oder Kennzeichen "offen"), da sie an der Kasse bezahlt werden müssen.
- Stornierte Tickets **nicht** aufnehmen. Ticketflow-/Admin-Tickets ohne festen Termin (`Unlimited`) in einem eigenen Abschnitt "Ohne Termin" am Ende.
- Seitenkopf mit Termin und "Seite x von y" auf jeder Seite, Zebra-Streifen, Zeilen nicht über Seitenumbruch zerreißen.
- Wiederholte Namen (mehrere Tickets eines Käufers) untereinander lassen, optional Spalte "Anzahl" bei Gruppierung nach Käufer-E-Mail. Erst einfach starten: eine Zeile pro Ticket.

**Backend**
- Neue Funktion `render_guestlist_pdf(date) -> bytes` in eigener Datei `assets/guestlist_pdf.py` (nutzt Fonts und Farben aus `ticket_pdf.py`, insbesondere `_register_fonts`, damit Umlaute funktionieren). Nicht in `ticket_pdf.py` hineinquetschen, die Datei ist schon groß.
- Route `GET /api/export/guestlist.pdf?date=YYYY-MM-DD` (Auth-Key, PDF rendern in `asyncio.to_thread`).
- Datenabfrage: neue Funktion in `data.py`, die aktive Tickets eines Termins sortiert liefert (SQL `ORDER BY last_name COLLATE NOCASE, first_name COLLATE NOCASE`, damit "Ö" und Kleinschreibung sinnvoll sortieren, ggf. in Python mit `str.casefold()` nacharbeiten).

**Frontend**
- Im Karten-Block "Export" (siehe 1) ein Termin-Dropdown und "Gästeliste (PDF)" über dasselbe `export.php`. Zusätzlich Knopf "Gästeliste" pro Termin in der Terminliste im Admin.

**Akzeptanz:** Termin mit >100 Tickets, mehreren Seiten, VIP, unbezahltem Ticket und Umlauten erzeugen. Ausdruck lesbar, Häkchenfeld nutzbar, Storno fehlt.

---

## 3. Automatische Backups

**Ziel:** Ein Serverausfall oder ein Fehlklick in der Gefahrenzone kostet keine Daten.

**Verhalten**
- Hintergrundschleife (Muster `reminder.py`: `@app.before_serving` + `app.add_background_task`), Prüfung alle 10 Minuten. Ist das neueste Backup älter als das Intervall, wird ein neues erzeugt.
- **Einstellungen** (Show-`extras`, im Admin bei "Wartung"): aktiv an/aus (Default **an**), Intervall in Stunden (Default 24, Auswahl 6 / 12 / 24 / 168), Aufbewahrung Anzahl (Default 14).
- **Sicherung vor Gefahrenaktionen:** Vor `wipe-data`, `reinstall` und `factory-reset` in `admin_ops.py` immer automatisch ein Backup mit Präfix `pre-<aktion>-` erzeugen, das nicht von der Aufbewahrung gelöscht wird (oder nur die letzten 3 davon). Das ist der wichtigste Einzelfall.
- Während eines Events (Termin heute) kürzeres Intervall, z. B. stündlich, als Option.

**Umsetzung** (neues Modul `backend/assets/backup.py`, Logik aus `admin_ops._snapshot_db_bytes()` wiederverwenden bzw. dorthin auslagern)
- Ablageort: `backend/data/backups/` (liegt im Docker-Volume `qrgate_data:/app/data`, überlebt Container-Neustarts). Überschreibbar über `QRGATE_BACKUP_DIR` (in `config/conf.py` und `.env.example`), damit man z. B. einen Nextcloud- oder NAS-Ordner einhängen kann.
- Snapshot per SQLite-Backup-API (schon vorhanden, WAL-sicher), danach **gzip**, Dateiname `qrgate-YYYYMMDD-HHMMSS.db.gz`. Rechte `0600`, Verzeichnis `0700`, da E-Mail-Adressen der Besucher enthalten sind.
- Erst in `*.tmp` schreiben, dann umbenennen, damit ein Abbruch keine halbe Datei hinterlässt. Nach dem Schreiben kurz prüfen (`PRAGMA integrity_check` auf einer entpackten Kopie oder wenigstens Dateigröße > 0) und bei Fehler loggen.
- Aufbewahrung: nach jedem Lauf alte Dateien über der Grenze löschen (nur Dateien mit passendem Namensmuster anfassen, nie beliebige Dateien im Ordner).
- Speicherfüllstand: Ist der freie Platz kleiner als 3× die DB-Größe, kein Backup schreiben und laut loggen.
- **Prüfen:** Woher nimmt `ticket_token()` seinen Schlüssel (vermutlich `data/secret.key`)? Wenn das Backup ohne diesen Schlüssel wiederhergestellt wird, funktionieren alte PDF-/Storno-Links nicht mehr. Dann `secret.key` mit ins Archiv legen (tar.gz statt nur DB) oder im Admin auf den Hinweis verweisen. Uploads unter `images/` optional mitsichern.

**Routen** (Auth-Key, über `admin_ops.py` oder `backup.py`)
- `GET /api/admin/backups`: Liste (Name, Größe, Zeitpunkt, Art: auto / manuell / pre-wipe).
- `POST /api/admin/backups/run`: jetzt sichern.
- `GET /api/admin/backups/download?name=`: Datei streamen. **Name strikt gegen die Liste prüfen** (kein Pfad, kein `..`).
- `POST /api/admin/backups/delete`: einzelne Datei löschen (mit `confirm: true`).
- Bestehendes `GET /api/admin/backup` (manueller Download-Snapshot) bleibt unverändert.

**Frontend** (`admin/index.php`, `admin.js`, `api.php`/`backup.php`)
- Karte "Datenbank-Backup" erweitern: Schalter, Intervall, Aufbewahrung, Tabelle der vorhandenen Backups (Datum, Größe, Art, Download, Löschen), Knopf "Jetzt sichern", Hinweis "Zuletzt gesichert: vor 3 Stunden" (rot, wenn länger als 2× Intervall her).
- Wiederherstellen **nicht** per Knopf (zu riskant). Stattdessen eine kurze Anleitung in `README.md`/`docs/` (Container stoppen, `.db.gz` entpacken, als `data/qrgate.db` ersetzen, `-wal`/`-shm` löschen, starten).

**Akzeptanz:** Intervall auf 1 Stunde und Aufbewahrung 3 stellen, Backups laufen lassen bzw. zeitlich vorziehen, ältere werden gelöscht. "Daten löschen" auslösen und prüfen, dass ein `pre-wipe`-Backup existiert und sich wieder einspielen lässt (auf einer Kopie testen). Den Download-Endpunkt mit `../../etc/passwd` als Namen aufrufen: muss abgelehnt werden.

---

## Reihenfolge

1. **Backup (3)**, wichtigstes Sicherheitsnetz und unabhängig.
2. **CSV-Export (1)**.
3. **Gästeliste (2)**, nutzt dieselbe Export-Karte im Admin.

Ein Commit pro Block im Stil der Historie (`feat(backup): ...`), Autor redwolfroot. Es gibt keine Testsuite, also die Akzeptanzpunkte oben von Hand prüfen und vor Abschluss `/code-review` laufen lassen.
