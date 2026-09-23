"""Admin maintenance operations: database backup + the destructive
"danger zone" actions exposed in the admin dashboard.

All routes here are gated by the shared API `auth_key` (same trust model as the
rest of the admin API) and are only ever reached through the authenticated,
CSRF-protected admin PHP proxy. The destructive actions additionally require an
explicit `{"confirm": true}` in the body so a stray request can never wipe a
live system by accident — the UI pairs this with a type-to-confirm prompt.

Action semantics (kept deliberately distinct):

  * backup        — download a consistent snapshot of the SQLite database.
  * wipe-data     — delete all tickets / sales / stats, free every seat back to
                    capacity. Keeps the event config, accounts and install state.
  * reinstall     — clear only the "installed" flag so the setup wizard runs
                    again on the next visit. All data is preserved.
  * factory-reset — blank slate: drop every domain table, clear settings, reset
                    accounts to the default admin, and delete generated
                    PDFs/QRs + uploaded images. The system becomes uninstalled.
"""

import os
import glob
import sqlite3
import tempfile

import quart
import hmac

import config.conf as config
from assets.data import DB_PATH, get_db, init_db
from assets.accounts import init_accounts
from assets.setup import set_setting, get_setting
from assets.timeutil import local_now
from reds_simple_logger import Logger

logger = Logger()
logger.success("Admin_ops.py loaded")

# Where generated artifacts live (cwd is backend/ at runtime).
CODES_DIR = "./codes"
IMAGES_DIR = "./images"


def _authorized() -> bool:
    """Timing-safe comparison of the Authorization header against the API key."""
    key = quart.request.headers.get("Authorization")
    if not key:
        return False
    return hmac.compare_digest(str(key), str(config.Auth.auth_key))


async def _confirmed() -> bool:
    """The destructive endpoints require an explicit {"confirm": true} body so a
    blank/accidental POST can never trigger them."""
    try:
        data = await quart.request.get_json(silent=True) or {}
    except Exception:
        data = {}
    return data.get("confirm") is True


def _snapshot_db_bytes() -> bytes:
    """Return a consistent point-in-time copy of the SQLite database as bytes.
    Uses the sqlite3 online-backup API so an in-flight WAL write can't produce a
    torn/half-written file (a plain file read of a WAL db would)."""
    src = get_db()
    tmp_fd, tmp_path = tempfile.mkstemp(suffix=".db")
    os.close(tmp_fd)
    try:
        dst = sqlite3.connect(tmp_path)
        try:
            src.backup(dst)
        finally:
            dst.close()
        with open(tmp_path, "rb") as fh:
            return fh.read()
    finally:
        src.close()
        try:
            os.remove(tmp_path)
        except OSError:
            pass


def _purge_dir(path: str, patterns=("*",)) -> int:
    """Delete files matching patterns inside `path` (non-recursive). Returns the
    number of files removed. The directory itself is kept."""
    removed = 0
    if not os.path.isdir(path):
        return 0
    for pattern in patterns:
        for f in glob.glob(os.path.join(path, pattern)):
            if os.path.isfile(f):
                try:
                    os.remove(f)
                    removed += 1
                except OSError as e:
                    logger.error(f"Could not delete {f}: {e}")
    return removed


def admin_ops(app=quart.Quart):
    @app.route("/api/admin/backup", methods=["GET"])  # type: ignore
    async def admin_backup():
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        try:
            blob = _snapshot_db_bytes()
        except Exception as e:
            logger.error(f"Backup failed: {e}")
            return quart.jsonify({"status": "error", "message": str(e)}), 500
        stamp = local_now().strftime("%Y%m%d-%H%M%S")
        filename = f"qrgate-backup-{stamp}.db"
        return quart.Response(
            blob,
            mimetype="application/x-sqlite3",
            headers={
                "Content-Disposition": f'attachment; filename="{filename}"',
                "Content-Length": str(len(blob)),
            },
        )

    @app.route("/api/admin/wipe-data", methods=["POST"])  # type: ignore
    async def admin_wipe_data():
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        if not await _confirmed():
            return quart.jsonify({"status": "error", "message": "Confirmation required"}), 400
        conn = get_db()
        try:
            conn.execute("DELETE FROM tickets")
            conn.execute("DELETE FROM daily_stats")
            conn.execute("DELETE FROM payment_intents")
            # Sold/held seats and open online checkouts go with the tickets;
            # a hold left behind would hand capacity back on expiry twice.
            conn.execute("DELETE FROM seat_status")
            conn.execute("DELETE FROM checkout_holds")
            # Free every reserved seat back to the configured capacity.
            conn.execute("UPDATE dates SET tickets_available = tickets")
            conn.commit()
        finally:
            conn.close()
        removed = _purge_dir(CODES_DIR, ("*.pdf", "*.png"))
        logger.warn(f"Danger zone: all ticket/sales data wiped ({removed} files removed).")
        return quart.jsonify(
            {"status": "success", "message": "All tickets, sales and statistics were deleted. Seats reset to capacity."}
        ), 200

    @app.route("/api/admin/reinstall", methods=["POST"])  # type: ignore
    async def admin_reinstall():
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        if not await _confirmed():
            return quart.jsonify({"status": "error", "message": "Confirmation required"}), 400
        # Only clear the install flag — every event/ticket/account stays put.
        conn = get_db()
        try:
            conn.execute("DELETE FROM settings WHERE key IN ('installed', 'installed_at')")
            conn.commit()
        finally:
            conn.close()
        logger.warn("Danger zone: install flag cleared — setup wizard will run again.")
        return quart.jsonify(
            {"status": "success", "message": "System marked as not installed. The setup wizard will run on the next visit."}
        ), 200

    @app.route("/api/admin/factory-reset", methods=["POST"])  # type: ignore
    async def admin_factory_reset():
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        if not await _confirmed():
            return quart.jsonify({"status": "error", "message": "Confirmation required"}), 400
        conn = get_db()
        try:
            for table in (
                "tickets", "daily_stats", "payment_intents",
                "dates", "show", "settings", "users",
                "seat_status", "checkout_holds", "seatmaps",
            ):
                conn.execute(f"DELETE FROM {table}")
            conn.commit()
        finally:
            conn.close()
        # Recreate any missing tables and reseed the default admin (admin/admin,
        # forced password change) so the operator can always get back in.
        init_db()
        init_accounts()
        files = _purge_dir(CODES_DIR, ("*.pdf", "*.png")) + _purge_dir(IMAGES_DIR, ("*.png",))
        logger.warn(f"Danger zone: FACTORY RESET performed ({files} files removed). System is now uninstalled.")
        return quart.jsonify(
            {
                "status": "success",
                "message": "Factory reset complete. All data was erased and the admin account reset to admin/admin. Restart the backend, then re-run the setup wizard.",
            }
        ), 200
