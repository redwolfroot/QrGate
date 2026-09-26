"""Automatic database backups.

A background loop checks every 10 minutes whether the newest backup is older
than the configured interval and, if so, writes a new one. The admin danger
zone (admin_ops.py) also writes one before every destructive action.

Files live in config.Backup.dir (default data/backups, inside the data volume):

    qrgate-YYYYMMDD-HHMMSS.db.gz                    automatic
    qrgate-manual-YYYYMMDD-HHMMSS.db.gz             "Jetzt sichern"
    qrgate-pre-<action>-YYYYMMDD-HHMMSS.db.gz       before wipe/reinstall/reset

Each file is a gzipped, integrity-checked SQLite snapshot taken with the online
backup API, so it is consistent even while tickets are being sold. Only file
names matching this pattern are ever listed, served or deleted.

Settings live in the show extras (edited under "Wartung"):
    backup_enabled         bool, default on
    backup_interval_hours  1 / 6 / 12 / 24 / 168, default 24
    backup_keep            automatic + manual backups to keep, default 14
    backup_event_hourly    back up hourly on days with a performance, default on

The API key (data/secret.key or QRGATE_AUTH_KEY) is not part of the backup: it
is a secret that must not leave the server through a download. Ticket PDF and
cancel links only keep working after a restore if the key is the same.
"""

import asyncio
import datetime as dt
import gzip
import os
import re
import shutil
import sqlite3
import tempfile
import threading
import time
from typing import Dict, List, Optional

import quart

import config.conf as config
from assets.data import DB_PATH, get_db, load_show
from assets.setup import is_installed
from assets.ticket_manager import _authorized
from assets.timeutil import local_now, today_iso
from reds_simple_logger import Logger

logger = Logger()
logger.success("Backup.py loaded")

CHECK_INTERVAL_SECONDS = 10 * 60
INTERVAL_CHOICES = (1, 6, 12, 24, 168)
DEFAULT_INTERVAL_HOURS = 24
DEFAULT_KEEP = 14
MAX_KEEP = 100
PRE_KEEP = 3  # backups kept per danger action
PRE_KINDS = ("pre-wipe", "pre-reinstall", "pre-factory-reset")
KINDS = ("auto", "manual") + PRE_KINDS

_NAME_RE = re.compile(
    r"^qrgate-(?:(manual|pre-wipe|pre-reinstall|pre-factory-reset)-)?(\d{8}-\d{6})\.db\.gz$"
)
_TMP_PREFIX = ".qrgate-backup-"
_STAMP = "%Y%m%d-%H%M%S"

# Loop, "Jetzt sichern" and danger actions can overlap; one backup at a time.
_lock = threading.Lock()


class BackupError(Exception):
    """A backup could not be written. `code` is shown to the admin UI."""

    def __init__(self, code: str, message: str):
        super().__init__(message)
        self.code = code


def backup_dir() -> str:
    return config.Backup.dir


def backup_settings(show: dict) -> Dict:
    try:
        hours = int(show.get("backup_interval_hours") or DEFAULT_INTERVAL_HOURS)
    except (TypeError, ValueError):
        hours = DEFAULT_INTERVAL_HOURS
    if hours not in INTERVAL_CHOICES:
        hours = DEFAULT_INTERVAL_HOURS
    try:
        keep = int(show.get("backup_keep") or DEFAULT_KEEP)
    except (TypeError, ValueError):
        keep = DEFAULT_KEEP
    return {
        "enabled": bool(show.get("backup_enabled", True)),
        "interval_hours": hours,
        "keep": min(MAX_KEEP, max(1, keep)),
        "event_hourly": bool(show.get("backup_event_hourly", True)),
    }


def effective_interval_hours(show: dict, settings: dict) -> int:
    """The configured interval, or one hour on a day with a performance."""
    if settings["event_hourly"]:
        today = today_iso()
        if any(str(d.get("date")) == today for d in (show.get("dates") or {}).values()):
            return 1
    return settings["interval_hours"]


def snapshot_db(dest_path: str) -> None:
    """Write a consistent copy of the live database to `dest_path` using the
    SQLite online-backup API (a plain file copy of a WAL database can tear)."""
    src = get_db()
    try:
        dst = sqlite3.connect(dest_path)
        try:
            src.backup(dst)
        finally:
            dst.close()
    finally:
        src.close()


def _ensure_dir() -> str:
    path = backup_dir()
    if not os.path.isdir(path):
        # Visitor names and emails are in here: owner only. An existing,
        # operator-mounted folder keeps the permissions it was given.
        os.makedirs(path, mode=0o700, exist_ok=True)
        try:
            os.chmod(path, 0o700)
        except OSError:
            pass
    return path


def _db_size() -> int:
    total = 0
    for p in (DB_PATH, DB_PATH + "-wal"):
        try:
            total += os.path.getsize(p)
        except OSError:
            pass
    return total


def _check_space(path: str) -> None:
    need = 3 * _db_size()
    free = shutil.disk_usage(path).free
    if free < need:
        logger.error(
            f"BACKUP SKIPPED: only {free // 1024} KiB free in {path}, "
            f"need {need // 1024} KiB (3x the database). Free up disk space!"
        )
        raise BackupError("no_space", "Not enough free disk space for a backup")


def _verify_sqlite(path: str) -> None:
    conn = sqlite3.connect(path)
    try:
        result = conn.execute("PRAGMA integrity_check").fetchone()
    finally:
        conn.close()
    if not result or result[0] != "ok":
        raise BackupError("corrupt", f"Snapshot failed the integrity check: {result}")


def _verify_gzip(path: str, expected_size: int) -> None:
    size = 0
    with gzip.open(path, "rb") as fh:  # reading to the end checks the CRC
        while True:
            chunk = fh.read(1 << 20)
            if not chunk:
                break
            size += len(chunk)
    if size != expected_size or size == 0:
        raise BackupError("corrupt", f"Compressed backup is incomplete ({size} of {expected_size} bytes)")


def _entry(name: str, path: str) -> Optional[Dict]:
    m = _NAME_RE.match(name)
    if not m:
        return None
    try:
        st = os.stat(path)
        created = dt.datetime.strptime(m.group(2), _STAMP)
    except (OSError, ValueError):
        return None
    return {
        "name": name,
        "kind": m.group(1) or "auto",
        "size": st.st_size,
        "created": created.isoformat(),
        "mtime": st.st_mtime,
    }


def list_backups() -> List[Dict]:
    """All backups in the backup folder, newest first."""
    path = backup_dir()
    try:
        names = os.listdir(path)
    except OSError:
        return []
    out = []
    for name in names:
        full = os.path.join(path, name)
        if not os.path.isfile(full):
            continue
        e = _entry(name, full)
        if e:
            out.append(e)
    out.sort(key=lambda e: (e["created"], e["mtime"]), reverse=True)
    return out


def _remove(name: str) -> None:
    if not _NAME_RE.match(name):  # never touch anything we did not write
        return
    try:
        os.remove(os.path.join(backup_dir(), name))
    except OSError as e:
        logger.error(f"Could not delete backup {name}: {e}")


def _sweep_tmp(path: str) -> None:
    """Remove temp files left behind by a crash mid-backup (older than 1 h)."""
    cutoff = time.time() - 3600
    try:
        names = os.listdir(path)
    except OSError:
        return
    for name in names:
        if name.startswith(_TMP_PREFIX) and name.endswith(".tmp"):
            full = os.path.join(path, name)
            try:
                if os.path.getmtime(full) < cutoff:
                    os.remove(full)
            except OSError:
                pass


def prune(keep: int) -> int:
    """Delete automatic/manual backups beyond `keep` and pre-action backups
    beyond PRE_KEEP per action. Returns the number of files removed."""
    backups = list_backups()
    regular = [b for b in backups if b["kind"] in ("auto", "manual")]
    doomed = regular[keep:]
    for kind in PRE_KINDS:
        doomed += [b for b in backups if b["kind"] == kind][PRE_KEEP:]
    for b in doomed:
        _remove(b["name"])
    if doomed:
        logger.info(f"Backup retention: removed {len(doomed)} old backup(s).")
    return len(doomed)


def create_backup(kind: str = "auto") -> Dict:
    """Write one backup and apply the retention. Raises BackupError."""
    if kind not in KINDS:
        raise ValueError(f"unknown backup kind {kind}")
    with _lock:
        path = _ensure_dir()
        _sweep_tmp(path)
        _check_space(path)
        prefix = "qrgate-" if kind == "auto" else f"qrgate-{kind}-"
        name = prefix + local_now().strftime(_STAMP) + ".db.gz"
        while os.path.exists(os.path.join(path, name)):  # two in one second
            time.sleep(1)
            name = prefix + local_now().strftime(_STAMP) + ".db.gz"
        final = os.path.join(path, name)

        snap_fd, snap = tempfile.mkstemp(dir=path, prefix=_TMP_PREFIX, suffix=".db.tmp")
        os.close(snap_fd)
        gz_fd, gz_tmp = tempfile.mkstemp(dir=path, prefix=_TMP_PREFIX, suffix=".gz.tmp")
        os.close(gz_fd)
        try:
            snapshot_db(snap)
            _verify_sqlite(snap)
            raw_size = os.path.getsize(snap)
            with open(gz_tmp, "wb") as raw:
                with gzip.GzipFile(filename=name[:-3], mode="wb", fileobj=raw) as gz:
                    with open(snap, "rb") as src:
                        shutil.copyfileobj(src, gz, 1 << 20)
                raw.flush()
                os.fsync(raw.fileno())
            _verify_gzip(gz_tmp, raw_size)
            os.chmod(gz_tmp, 0o600)
            os.replace(gz_tmp, final)
        except BackupError:
            raise
        except Exception as e:
            logger.error(f"Backup {name} failed: {e}")
            raise BackupError("failed", f"Backup failed: {e}")
        finally:
            for p in (snap, gz_tmp):
                try:
                    os.remove(p)
                except OSError:
                    pass
        logger.info(f"Backup written: {name} ({os.path.getsize(final) // 1024} KiB)")
        prune(backup_settings(load_show())["keep"])
        return _entry(name, final)


def delete_backup(name: str) -> bool:
    if name not in {b["name"] for b in list_backups()}:
        return False
    _remove(name)
    return True


def backup_path(name: str) -> Optional[str]:
    """Absolute path of a listed backup, or None. The name is matched against
    the listing, so no path or `..` can ever get through."""
    for b in list_backups():
        if b["name"] == name:
            return os.path.abspath(os.path.join(backup_dir(), b["name"]))
    return None


def _backup_due() -> bool:
    if not is_installed():  # an empty system would push real backups out
        return False
    show = load_show()
    s = backup_settings(show)
    if not s["enabled"]:
        return False
    hours = effective_interval_hours(show, s)
    newest = next((b for b in list_backups() if b["kind"] in ("auto", "manual")), None)
    if newest is None:
        return True
    # Half a tick of slack, so a 24 h interval does not drift by 10 min a day.
    return time.time() - newest["mtime"] >= hours * 3600 - CHECK_INTERVAL_SECONDS / 2


async def backup_loop() -> None:
    await asyncio.sleep(60)  # let startup finish first
    while True:
        try:
            if await asyncio.to_thread(_backup_due):
                await asyncio.to_thread(create_backup, "auto")
        except BackupError as e:
            logger.error(f"Automatic backup failed: {e}")
        except Exception as e:  # never let the loop die
            logger.error(f"Backup check failed: {e}")
        await asyncio.sleep(CHECK_INTERVAL_SECONDS)


def _overview() -> Dict:
    show = load_show()
    s = backup_settings(show)
    backups = list_backups()
    now = time.time()
    last = next((b for b in backups if b["kind"] in ("auto", "manual")), None)
    return {
        "status": "success",
        "settings": s,
        "effective_interval_hours": effective_interval_hours(show, s),
        "last_age_s": int(now - last["mtime"]) if last else None,
        "backups": [
            {k: b[k] for k in ("name", "kind", "size", "created")} | {"age_s": int(now - b["mtime"])}
            for b in backups
        ],
    }


def backup_routes(app: quart.Quart):
    @app.before_serving
    async def _start_backups():
        app.add_background_task(backup_loop)

    @app.route("/api/admin/backups", methods=["GET"])  # type: ignore
    async def backups_list():
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        return quart.jsonify(await asyncio.to_thread(_overview)), 200

    @app.route("/api/admin/backups/run", methods=["POST"])  # type: ignore
    async def backups_run():
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        try:
            entry = await asyncio.to_thread(create_backup, "manual")
        except BackupError as e:
            return quart.jsonify({"status": "error", "error": e.code, "message": str(e)}), 500
        return quart.jsonify({"status": "success", "backup": entry["name"]}), 200

    @app.route("/api/admin/backups/download", methods=["GET"])  # type: ignore
    async def backups_download():
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        name = quart.request.args.get("name", "")
        path = await asyncio.to_thread(backup_path, name)
        if not path:
            return quart.jsonify({"status": "error", "error": "not_found", "message": "No such backup"}), 404
        return await quart.send_file(
            path, mimetype="application/gzip", as_attachment=True, attachment_filename=os.path.basename(path)
        )

    @app.route("/api/admin/backups/delete", methods=["POST"])  # type: ignore
    async def backups_delete():
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        data = await quart.request.get_json(silent=True) or {}
        if data.get("confirm") is not True:
            return quart.jsonify({"status": "error", "message": "Confirmation required"}), 400
        name = str(data.get("name") or "")
        if not await asyncio.to_thread(delete_backup, name):
            return quart.jsonify({"status": "error", "error": "not_found", "message": "No such backup"}), 404
        logger.warn(f"Backup deleted by admin: {name}")
        return quart.jsonify({"status": "success"}), 200
