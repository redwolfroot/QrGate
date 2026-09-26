# Plan: Live-Zähler, Durchsagen, Live-Dashboard, Mail erneut senden, Kalendereintrag

Stand: 2026-09-26. Umsetzung durch Claude Opus. Reihenfolge unten beachten, jeder Block ist einzeln committbar.

**Vorab:** Im Working Tree liegen uncommittete Änderungen (Reminder-Mail, `data.py`, `ticket_manager.py`, `admin.js`, `index.php`, `checkout.php`, `shop.css`). Vor dem Start `git diff` lesen und nicht darüber hinwegbügeln. Vorhandene Muster aus CLAUDE.md nutzen: Routen-Registrierfunktion pro Modul in `main.py`, `asyncio.to_thread` für SQLite/SMTP, Auth per `hmac.compare_digest`, neue Spalten über `_migrate_ticket_columns`.

## Entscheidungen (Defaults, gern ändern)

- **Kein WebSocket.** Polling alle 3-4 s reicht bei ~10 Geräten und SQLite (WAL). Einfacher, läuft durch jeden Proxy, überlebt Reconnects ohne Zusatzcode.
- **Ein gemeinsamer Live-Endpunkt** `GET /api/live/state` liefert Zähler, aktive Scanner und aktive Durchsage in einem Aufruf. Alle Oberflächen (Handheld, Kasse, Screens, Live-Dashboard) nutzen nur diesen.
- **Apple/Google Wallet entfällt** (Apple Developer Program kostet 99 $/Jahr). Stattdessen `.ics`, funktioniert auf allen Geräten kostenlos.

---

## 1. Live-Zähler über alle Handhelds

**Ziel:** Jedes Handheld zeigt "142 / 230 drin" für den heutigen Termin, alle Geräte sehen denselben Stand innerhalb weniger Sekunden. Dazu eine Liste der aktiven Scanner.

**Backend** (neues Modul `backend/assets/live.py`, in `main.py` registrieren)
- `GET /api/live/state?device=<id>&name=<label>&role=<handheld|kasse|...>`, Auth-Key wie bei den anderen Routen.
  - Antwort: `{today, checked_in, sold, pending, scanners:[{name, role, last_seen_s, scans}], broadcast: {...}|null}`.
  - Zahlen aus `checkin_stats()` (`data.py`) für `today_iso()` holen, nicht neu berechnen.
  - Der Aufruf ist gleichzeitig der **Heartbeat**: `device`/`name` in ein In-Memory-Dict `{device: {name, role, last_seen, scans}}` schreiben. "Aktiv" = zuletzt gesehen vor weniger als 15 s. In-Memory genügt, der Zustand füllt sich nach Neustart in Sekunden wieder.
- Scan-Zähler pro Gerät: in `vaildate.py` beim erfolgreichen Einlass den Header `X-Device` lesen und hochzählen. Zusätzlich `counts` (checked_in/sold) in die Validate-Antwort packen, damit das scannende Gerät sofort aktuell ist, ohne auf den nächsten Poll zu warten.

**Frontend**
- `frontend/admin/handheld/index.php` (und `inspector.php`, `kasse.php`): Zähler im Header neben `hhNet`/`hhClock`. Poll-Logik in `scanner.js`, nicht pro Seite duplizieren. Der Poll läuft über dasselbe PHP-Self-Post-Muster wie `validate` (Auth-Key bleibt serverseitig, siehe Umgang in `scanner.js` um Zeile 277).
- Gerätename: einmal beim ersten Start abfragen ("Tor 1"), in `localStorage`, plus zufällige `device`-ID.
- Bei fehlgeschlagenem Poll den letzten Wert grau stehen lassen, kein Fehler-Toast (der Verbindungs-Pill zeigt das schon).

**Akzeptanz:** Zwei Browser-Tabs als Scanner, in Tab A scannen, Tab B zeigt den neuen Stand in unter 5 s. Tab A schließen, nach ca. 15 s verschwindet er aus der Scanner-Liste.

---

## 2. Durchsagen (Broadcast-System)

**Ziel:** Im Admin einen Text mit Kategorie eingeben, er erscheint sofort auf Foyer-Screens, Handhelds, Kasse und Live-Dashboard. Beispiele: "Pause endet in 5 Minuten", "Die Vorstellung geht gleich weiter".

**Datenmodell** (in `init_db`, Tabelle `broadcasts`)
`id, category, text, text_en (optional), targets (JSON: screens/staff), created_at, expires_at (NULL = bis manuell beendet), cleared_at, created_by`.
- Kategorien: `info` (blau), `attention` (gelb, "Achtung"), `alert` (rot, "Dringend"), `success` (grün, z. B. "Einlass geöffnet").
- Es ist immer höchstens **eine** aktive Durchsage angezeigt: die neueste nicht abgelaufene, nicht beendete. Ältere bleiben in der Historie.

**Backend** (`backend/assets/broadcast.py`)
- `POST /api/broadcast/send` (Auth-Key): `{category, text, duration_min|null, targets}`. Text auf 200 Zeichen kürzen, nur Klartext (Frontend escaped beim Rendern immer).
- `POST /api/broadcast/clear` (Auth-Key): aktive beenden.
- `GET /api/broadcast/active` **öffentlich** und read-only, nur `{id, category, text, expires_in}`. Screens laufen ohne Login, dort darf nichts Sensibles hin.
- `GET /api/broadcast/history` (Auth-Key), letzte 20.
- Aktive Durchsage zusätzlich in `/api/live/state` (Block 1).
- Rate-Limit nutzen (`ratelimit.py`), damit ein hängender Client das nicht flutet.

**Schnelltasten (Presets):** In den Show-`extras` (siehe `save_show`) eine Liste `broadcast_presets` mit `{label, category, text}`, Defaults mitliefern:
"Einlass geöffnet" (success), "Pause: noch 5 Minuten" (attention), "Vorstellung geht weiter" (info), "Bitte Notausgänge freihalten" (alert). Im Admin editierbar.

**Admin-UI** (`frontend/admin/index.php`, `admin.js`, `api.php` um Actions `broadcast_send`, `broadcast_clear` erweitern, `api.php` hat eine Action-Switch-Liste)
- Neue Karte "Durchsage": Textfeld, Kategorie-Auswahl, Dauer (1 / 5 / 15 min / bis beendet), Zielgruppe, Preset-Buttons (ein Klick sendet), darunter die aktive Durchsage mit "Beenden"-Knopf.
- Zusätzlich als kleines Bedienfeld im Live-Dashboard (Block 3).

**Anzeige**
- **Screens** (`frontend/screens/title.php`, `welcome.php`, `feedback.php`): gemeinsames Include `screens/_broadcast.php` mit Vollbild-Overlay in Kategoriefarbe, große Schrift, poll alle 3 s. Verschwindet automatisch bei Ablauf. Kein Ton.
- **Handheld/Kasse/Ticketflow:** Banner-Streifen oben, kommt über den Live-Poll. Bei `attention`/`alert` einmal vibrieren (`vibrate()` gibt es schon in `scanner.js`). Der Banner darf den Scan-Screen nie verdecken, nur antippbar zum Einklappen.

**Akzeptanz:** Im Admin "Pause endet" senden, Foyer-Screen und Handheld zeigen es binnen 4 s, nach der gewählten Dauer verschwindet es ohne Zutun.

---

## 3. Live-Dashboard (Backstage/Leitung), kombiniert mit Screens

**Ziel:** Eine Vollbild-Ansicht für den Abend, auf einem Monitor oder Tablet.

- Neue Seite `frontend/screens/live.php`, im Screen-Selector `frontend/screens/index.html` verlinkt (gleiche Gestaltung wie die vorhandenen Screens).
- Inhalte (alles aus `/api/live/state` und `dashboard_overview()`): Einlass-Ring (drin / verkauft), Restplätze pro Termin, Kassenumsatz heute, aktive Scanner mit Zuletzt-gesehen, die letzten 8 Einlässe, aktive Durchsage, darunter das Bedienfeld zum Senden (Preset-Buttons).
- **Zugriff:** Backstage-Monitor kann sich nicht einloggen. Deshalb ein **Display-Token**: im Admin unter Einstellungen generieren, in der `settings`-Tabelle speichern, Aufruf `live.php?token=...`. Das Token darf nur lesen, das Senden von Durchsagen bleibt an eine Admin-Session gebunden (Bedienfeld nur sichtbar, wenn eingeloggt).
- Große Schrift, Dark-Theme, kein Scrollen, Auto-Reconnect-Hinweis, wenn der Poll ausfällt.

**Akzeptanz:** Seite auf einem zweiten Gerät öffnen, dort scannen und verkaufen, Zahlen ziehen innerhalb weniger Sekunden nach.

---

## 4. E-Mail erneut senden

**Backend** (`ticket_manager.py`)
- `POST /api/ticket/resend` (Auth-Key): `{tid, email (optional)}`.
  - Ticket laden, abgebrochene Tickets ablehnen, Ticket ohne E-Mail nur mit übergebener Adresse erlauben.
  - Wird eine neue Adresse mitgegeben und ist gültig (`_clean_recipient`), wird sie am Ticket gespeichert (Tippfehler korrigieren) und dann gesendet.
  - Versand über das vorhandene `send_email(...)` mit den gespeicherten Feldern, Sprache aus dem Ticket. Nicht neu bauen.
  - Sperre: höchstens 1 Versand pro Ticket alle 30 s (verhindert Doppelklick-Spam). Neue Spalten `mail_sent_at`, `mail_count` über `_migrate_ticket_columns`, dabei auch der Erstversand beim Kauf setzen.
  - Antwort: `{status, sent_to (maskiert: j***@example.com)}`.
- Fehlerfall SMTP: klare Meldung an die Kasse ("Mailserver nicht erreichbar"), Ticket bleibt unverändert.

**Frontend**
- `frontend/admin/ticketflow/index.php`, Ticket-Dialog (`tdBody`, ab ca. Zeile 1595): Block "E-Mail" mit Feld (vorbelegt), Knopf "Erneut senden", Status "Zuletzt gesendet: 19:04 (2x)". Für Ticketflow- und Admin-Rolle sichtbar, nicht nur Admin. Texte in beiden Sprachen (`$L`).
- Optional gleicher Knopf im Handheld-Inspector.

**Akzeptanz:** Ticket suchen, Adresse ändern, senden, Mail kommt mit PDF an, zweiter Klick innerhalb von 30 s wird freundlich abgelehnt.

---

## 5. Kalendereintrag (.ics)

**Backend** (`ticket_manager.py` oder eigenes `assets/ics.py`)
- `build_ics(view) -> bytes` nach RFC 5545:
  - `UID: <tid>@<Domain>`, `DTSTAMP`, `DTSTART;TZID=<Zeitzone aus config>` aus Datum + `event_time`, `DTEND` = Start + Dauer. Dauer aus einer neuen Show-Einstellung `event_duration_min` (Default 120, im Admin unter "Veranstaltung").
  - `SUMMARY` (Titel), `LOCATION` (Ort + Adresse), `DESCRIPTION` (Ticket-ID, Sitzplatz, Link zum PDF und zur Stornoseite), `VALARM` 2 Stunden vorher.
  - Zeilen bei 75 Oktetten falten, `\r\n`, Komma/Semikolon/Backslash/Zeilenumbruch escapen.
  - Tickets ohne festes Datum (`Unlimited`, z. B. VIP/Admin ohne Datum) bekommen keine `.ics`.
- **Anhang** `event.ics` (`text/calendar; method=PUBLISH`) an die Ticket-Mail und die Erinnerungs-Mail. Gmail/Apple Mail zeigen dann automatisch "Zum Kalender hinzufügen".
- **Link:** Neue Route `GET /codes/ics?tid&token` (Token wie beim PDF, `_token_valid`) und `frontend/ics.php` als Proxy analog zu `ticket.php`. In der Mail und auf der Bestätigungsseite nach dem Kauf ein Button "Zum Kalender hinzufügen".
- Beim Storno optional dieselbe UID mit `STATUS:CANCELLED` und `SEQUENCE:1` senden, damit der Eintrag verschwindet. Nice-to-have, erst nach dem Rest.

**Akzeptanz:** Ticket-Mail öffnen, `.ics` in Apple Kalender, Google Kalender und Outlook importieren: richtige Zeit (Sommer-/Winterzeit prüfen, Zeitzone `Europe/Berlin`), Ort, Erinnerung vorhanden.

---

## Reihenfolge und Aufwand

1. **Resend-Mail (4)**, klein, unabhängig.
2. **`.ics` (5)**, klein/mittel, unabhängig, greift in dieselbe Mail wie 4.
3. **Live-Endpunkt + Zähler (1)**, Grundlage für 2 und 3.
4. **Durchsagen (2)**, baut auf dem Live-Poll auf.
5. **Live-Dashboard (3)**, fügt nur noch zusammen.

Pro Block ein Commit im Stil der Historie (`feat(email): ...`, `feat(live): ...`, Autor redwolfroot, siehe Memory).

## Prüfen (es gibt keine Testsuite)

- Backend lokal mit `python main.py`, Endpunkte per `curl` mit dem Auth-Key testen (leeres/falsches Token, zu langer Text, abgelaufene Durchsage, Doppel-Resend).
- Frontend im Browser: zwei Tabs, Dark/Light, DE/EN, Handy-Breite für Handheld und Kasse.
- Beim Zähler und bei den Durchsagen prüfen, dass ein Netzausfall den Scanner nicht blockiert (Poll darf nur anzeigen, nie ein Ticket-Ergebnis beeinflussen).
- Vor Abschluss die Änderungen reviewen (`/code-review`).

## Später / offen

Offline-Scanning, Namenssuche im Scanner, Einlass rückgängig, CSV-Export, Gästeliste-PDF, Rabattcodes, Warteliste (siehe Ideenliste in der Session). Google Wallet wäre ohne Kosten möglich (Issuer-Konto), Apple Wallet nur mit Developer-Account.
