import asyncio
import smtplib
import datetime as dt
from typing import Dict, List, Tuple

import quart
import config.conf as config
from assets.data import load_show, reminder_candidates, claim_reminder, unclaim_reminder
from assets.timeutil import local_now
from reds_simple_logger import Logger

logger = Logger()
logger.success("Reminder.py loaded")

# --------------------------------------------------------------------------- #
# Pre-event reminder emails. Admin settings on the show:
#   reminder_enabled (bool) and reminder_days (1-7): how many days before the
#   date the reminder goes out. A background loop checks every few minutes and
#   mails each buyer once per date, all their tickets in one email. Tickets
#   bought inside the window are skipped, their ticket email is recent enough.
# --------------------------------------------------------------------------- #
CHECK_INTERVAL_SECONDS = 15 * 60
SEND_FROM_HOUR = 9  # local time; nobody wants a reminder at midnight
MIN_DAYS, MAX_DAYS = 1, 7


def reminder_settings(show: dict) -> Tuple[bool, int]:
    try:
        days = int(show.get("reminder_days") or 1)
    except (TypeError, ValueError):
        days = 1
    return bool(show.get("reminder_enabled")), min(MAX_DAYS, max(MIN_DAYS, days))


def _due_groups(now: dt.datetime, days: int) -> Dict[Tuple[str, str], List[dict]]:
    today = now.date()
    first = (today + dt.timedelta(days=1)).isoformat()
    last = (today + dt.timedelta(days=days)).isoformat()
    groups: Dict[Tuple[str, str], List[dict]] = {}
    for t in reminder_candidates(first, last):
        try:
            event_day = dt.date.fromisoformat(str(t["valid_date"]))
        except ValueError:
            continue
        created = str(t.get("created_at") or "")[:10]
        window_start = (event_day - dt.timedelta(days=days)).isoformat()
        if created and created >= window_start:
            continue
        key = (str(t["email"]).strip().lower(), str(t["valid_date"]))
        groups.setdefault(key, []).append(t)
    return groups


async def send_due_reminders() -> int:
    """Send every reminder that is due now. Returns the number of emails."""
    from assets.ticket_manager import send_reminder_email  # avoids a cycle

    enabled, days = reminder_settings(await asyncio.to_thread(load_show))
    now = local_now()
    if not enabled or now.hour < SEND_FROM_HOUR or not config.Mail.smtp_server:
        return 0
    groups = await asyncio.to_thread(_due_groups, now, days)
    sent = 0
    for (_, date), tickets in groups.items():
        tids = await asyncio.to_thread(
            claim_reminder, [t["tid"] for t in tickets],
            now.isoformat(timespec="seconds"),
        )
        tickets = [t for t in tickets if t["tid"] in tids]
        if not tickets:
            continue
        days_left = (dt.date.fromisoformat(date) - now.date()).days
        try:
            await send_reminder_email(tickets, days_left)
            sent += 1
        except smtplib.SMTPRecipientsRefused:
            # The address itself is rejected; retrying would not help.
            logger.warn(f"Reminder for {date} refused by the mail server: {tids}")
        except Exception as e:
            await asyncio.to_thread(unclaim_reminder, tids)
            logger.error(f"Reminder for {date} failed, will retry: {e}")
    if sent:
        logger.info(f"Sent {sent} reminder email(s).")
    return sent


async def reminder_loop() -> None:
    while True:
        try:
            await send_due_reminders()
        except Exception as e:  # never let the loop die
            logger.error(f"Reminder sweep failed: {e}")
        await asyncio.sleep(CHECK_INTERVAL_SECONDS)


def reminder_routes(app: quart.Quart):
    @app.before_serving
    async def _start_reminders():
        app.add_background_task(reminder_loop)
