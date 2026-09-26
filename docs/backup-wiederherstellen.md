# Backup wiederherstellen

QrGate sichert die Datenbank automatisch (Admin → *Wartung*). Wiederherstellen geht bewusst nur von Hand: Es ersetzt **alle** aktuellen Daten, auch Verkäufe seit der Sicherung.

## Wo liegen die Sicherungen?

Im Datenordner unter `backups/`, also im Docker-Volume `qrgate_data`:

| Aufbau | Pfad im Container |
| --- | --- |
| Ein Container (`docker-compose.single.yml`) | `/app/backend/data/backups/` |
| Zwei Container (`docker-compose.yml`), Dienst `backend` | `/app/data/backups/` |
| Ohne Docker | `backend/data/backups/` |

Mit `QRGATE_BACKUP_DIR` lässt sich ein anderer Ordner einstellen, z. B. ein eingehängtes NAS.

Dateinamen:

- `qrgate-20260926-181200.db.gz`: automatisch
- `qrgate-manual-…`: über „Jetzt sichern“
- `qrgate-pre-wipe-…`, `qrgate-pre-reinstall-…`, `qrgate-pre-factory-reset-…`: direkt vor der jeweiligen Aktion in der Gefahrenzone

Jede Datei lässt sich auch im Admin herunterladen.

## Schritte

Beispiel für den Ein-Container-Aufbau. Beim Zwei-Container-Aufbau `qrgate` durch `backend` und `/app/backend/data` durch `/app/data` ersetzen.

1. **Die aktuelle Datenbank zuerst sichern.** Im Admin „Jetzt sichern“, oder im Container:
   ```bash
   docker compose -f docker-compose.single.yml exec qrgate cp /app/backend/data/qrgate.db /app/backend/data/qrgate.db.vorher
   ```
2. **QrGate stoppen**, damit nichts mehr schreibt:
   ```bash
   docker compose -f docker-compose.single.yml stop qrgate
   ```
3. **Sicherung entpacken und einsetzen.** Der Container ist gestoppt, daher über einen Hilfscontainer mit demselben Volume (Volume-Namen mit `docker volume ls` prüfen, meist `<projekt>_qrgate_data`):
   ```bash
   docker run --rm -v qrgate_qrgate_data:/data alpine sh -c '
     cd /data &&
     gunzip -c backups/qrgate-20260926-181200.db.gz > qrgate.db.neu &&
     rm -f qrgate.db-wal qrgate.db-shm &&
     mv qrgate.db.neu qrgate.db'
   ```
   Wichtig: `qrgate.db-wal` und `qrgate.db-shm` löschen. Sie gehören zur alten Datenbank und würden sonst in die eingespielte übernommen.
4. **Starten:**
   ```bash
   docker compose -f docker-compose.single.yml start qrgate
   ```
5. Im Admin prüfen: Termine, Ticketzahlen im Dashboard, ein Ticket in der Kasse suchen.

Ohne Docker: Backend stoppen, `gunzip -c backups/<datei>.db.gz > backend/data/qrgate.db`, `qrgate.db-wal` und `qrgate.db-shm` löschen, Backend starten.

## Was nicht in der Sicherung steckt

- **Der API-Schlüssel** (`data/secret.key` oder `QRGATE_AUTH_KEY`). Absichtlich, weil Sicherungen über den Browser heruntergeladen werden können. Die Links in Ticket-Mails (PDF, Storno, Kalender) sind mit diesem Schlüssel signiert. Auf demselben Server bleibt er erhalten. Auf einem **neuen** Server `secret.key` vom alten mitnehmen oder `QRGATE_AUTH_KEY` gleich setzen, sonst funktionieren die Links in bereits verschickten Mails nicht mehr. Die Tickets selbst (QR-Code am Einlass) funktionieren trotzdem.
- **Hochgeladene Bilder** (Banner, Logo, Hintergrund, Besetzung) liegen als Dateien neben der Datenbank. Beim Umzug den ganzen Datenordner kopieren.

## Probe

Einmal im Jahr bzw. vor der Saison auf einer Kopie ausprobieren:

```bash
gunzip -c qrgate-20260926-181200.db.gz > probe.db
sqlite3 probe.db 'PRAGMA integrity_check; SELECT COUNT(*) FROM tickets;'
```

`ok` und eine plausible Ticketzahl heißen: Die Sicherung ist brauchbar.
