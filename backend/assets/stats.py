import hmac
import quart
from typing import Dict, Any
from reds_simple_logger import Logger
from assets.data import load_show, _load_stats_dict, _save_stats_dict, log_sale
from assets.data import checkin_stats, recent_checkins
from assets.timeutil import today_iso
import config.conf as config # type: ignore

logger = Logger()
logger.success("Stats.py loaded")


def load_stats() -> Dict[str, Any]:
    """Load statistics from the SQLite store.
    Shape: {"sales_by_date": {date: count}, "income_by_date": {date: amount}}."""
    try:
        return _load_stats_dict()
    except Exception:
        return {"sales_by_date": {}, "income_by_date": {}}


def save_stats(stats: Dict[str, Any]):
    """Persist statistics to the SQLite store (same shape as before)."""
    _save_stats_dict(stats)


def log_ticket_sale(date: str, tickets_sold: int, price_per_ticket: float):
    """Log a ticket sale to statistics.

    Keyed by today_iso() (the event-timezone calendar date), preserving the
    original "sales today" intent — the `date` param is intentionally ignored,
    same as before, but now we use the timezone-aware date instead of the
    server's naive UTC clock. Accumulation is done by the atomic data.log_sale
    upsert so concurrent buys can't lose updates (no load -> mutate -> save
    read-modify-write race)."""
    current_date = today_iso()
    income = tickets_sold * price_per_ticket
    log_sale(current_date, tickets_sold, income)
    logger.info(f"Logged sale: {tickets_sold} tickets on {current_date}, income: €{income:.2f}")


def log_ticket_refund(tickets_refunded: int, price_per_ticket: float):
    """Reverse a sale in the statistics (inverse of log_ticket_sale): subtract
    the refunded seats and income from today's bucket. Keyed by today_iso() to
    mirror how sales are logged. Relies on log_sale's atomic accumulating upsert
    accepting negative deltas."""
    current_date = today_iso()
    income = tickets_refunded * price_per_ticket
    log_sale(current_date, -int(tickets_refunded), -float(income))
    logger.info(f"Logged refund: {tickets_refunded} tickets on {current_date}, income: -€{income:.2f}")


def get_statistics():
    """Get all statistics"""
    return load_stats()


def get_stats_api(app=quart.Quart):
    @app.route("/api/stats", methods=["GET"])   # type: ignore
    async def get_stats():
        key = quart.request.headers.get("Authorization")
        if not key or not hmac.compare_digest(str(key), str(config.Auth.auth_key)):
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401

        try:
            stats = get_statistics()
            return quart.jsonify({
                "status": "success",
                "data": stats
            }), 200
        except Exception as e:
            logger.error(f"Error getting stats: {str(e)}")
            return quart.jsonify({"status": "error", "message": "Internal server error"}), 500


def get_checkins_api(app=quart.Quart):
    @app.route("/api/stats/checkins", methods=["GET"])   # type: ignore
    async def get_checkins():
        """Live door check-in stats: per-date sold/checked-in/pending counts plus
        the most recent entries. Polled by the admin dashboard."""
        key = quart.request.headers.get("Authorization")
        if not key or not hmac.compare_digest(str(key), str(config.Auth.auth_key)):
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        try:
            return quart.jsonify({
                "status": "success",
                "data": {
                    "by_date": checkin_stats(),
                    "today": today_iso(),
                    "recent": recent_checkins(20),
                },
            }), 200
        except Exception as e:
            logger.error(f"Error getting checkins: {str(e)}")
            return quart.jsonify({"status": "error", "message": "Internal server error"}), 500