import quart
import hmac
import asyncio
import config.conf as config
from typing import Dict, Optional
from assets.data import (
    load_tickets,
    save_tickets,
    load_ticket_id,
    mark_ticket_used,
    append_access_attempt,
)
from assets.timeutil import local_now
from assets.live import count_scan, today_counts
from reds_simple_logger import Logger

logger = Logger()
logger.success("Validate.py loaded")


async def _admitted(body: dict) -> dict:
    """An admitted guest: count the scan for the device that sent it
    (X-Device) and hand the fresh door numbers back, so that device shows
    them at once. Bookkeeping only; a failure here never touches the verdict."""
    try:
        count_scan(quart.request.headers.get("X-Device"))
        c = await asyncio.to_thread(today_counts)
        body["counts"] = {"checked_in": c["checked_in"], "sold": c["sold"]}
    except Exception as e:
        logger.error(f"Live counts after a scan failed: {e}")
    return body


def validate_ticket(app: quart.Quart):
    @app.route("/api/ticket/validate", methods=["POST", "GET"])
    async def validate_ticket():

        time = local_now()

        auth_key = quart.request.headers.get("Authorization")
        if not auth_key or not hmac.compare_digest(str(auth_key), str(config.Auth.auth_key)):
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401

        try:
            data: Dict = await quart.request.get_json()
            ticket_id: Optional[str] = data.get("tid")

            if not ticket_id:

                return (
                    quart.jsonify(
                        {"status": "error", "message": "Ticket ID is required"}
                    ),
                    400,
                )

            # Look up the ticket OFF the event loop. A genuine "no such row"
            # returns None and falls through to the 200 "not found" denial; a
            # real DB error (lock/transient) raises and is turned into a 500
            # below so we never deny a valid customer because of a DB hiccup.
            try:
                ticket: Optional[Dict] = await asyncio.to_thread(
                    load_ticket_id, ticket_id
                )
            except Exception as e:
                app.logger.error(f"DB error loading ticket {ticket_id}: {e}")
                return (
                    quart.jsonify(
                        {
                            "status": "error",
                            "message": "internal error, try again",
                        }
                    ),
                    500,
                )
            app.logger.debug(f"Loaded ticket: {ticket}")

            if not ticket:
                logger.debug.info(f"Ticket not found: {ticket_id}")
                temp_data = {
                    "tid": "Unknown",
                    "first_name": "Unknown",
                    "last_name": "Unknown",
                    "paid": "Unknown",
                    "valid_date": "Unknown",
                    "type": "Unknown",
                    "valid": "Unknown",
                    "used_at": "Unknown",
                    "access_attempts": [],
                }
                return (
                    quart.jsonify(
                        {
                            "status": "error",
                            "message": "Ticket not found",
                            "data": temp_data,
                        }
                    ),
                    200,
                )

            now_str = str(time.strftime("%Y.%m.%d - %H:%M:%S"))

            if ticket.get("type") == "admin":
                ticket["access_attempts"].append(
                    {
                        "status": "success",
                        "type": "valid - ADMIN ACCESS",
                        "time": now_str,
                    }
                )
                ticket["used_at"] = now_str
                await asyncio.to_thread(save_tickets, ticket_id, ticket)
                return (
                    quart.jsonify(await _admitted(
                        {
                            "status": "success",
                            "message": "Ticket is valid - ADMIN",
                            "data": ticket,
                        }
                    )),
                    200,
                )

            if ticket.get("type") == "vip":
                ticket["access_attempts"].append(
                    {
                        "status": "success",
                        "type": "valid",
                        "time": now_str,
                    }
                )
                ticket["used_at"] = now_str
                await asyncio.to_thread(save_tickets, ticket_id, ticket)
                return (
                    quart.jsonify(await _admitted(
                        {
                            "status": "success",
                            "message": "Ticket is valid - VIP",
                            "data": ticket,
                        }
                    )),
                    200,
                )

            if not ticket.get("paid", False):
                logger.debug.info(f"Ticket is not paid: {ticket_id}")
                attempt = {
                    "status": "error",
                    "type": "not_paid",
                    "time": now_str,
                }
                await asyncio.to_thread(append_access_attempt, ticket_id, attempt)
                ticket["access_attempts"].append(attempt)
                return (
                    quart.jsonify(
                        {
                            "status": "error",
                            "message": "Ticket is not paid",
                            "data": ticket,
                        }
                    ),
                    200,
                )

            if not ticket.get("valid", False):
                logger.debug.info(f"Ticket is not valid: {ticket_id}")
                attempt = {
                    "status": "error",
                    "type": "already_used",
                    "time": now_str,
                }
                await asyncio.to_thread(append_access_attempt, ticket_id, attempt)
                ticket["access_attempts"].append(attempt)
                return (
                    quart.jsonify(
                        {
                            "status": "error",
                            "message": "Ticket already used",
                            "data": ticket,
                        }
                    ),
                    200,
                )

            valid_date = str(ticket.get("valid_date") or "").strip()

            if valid_date != time.date().isoformat():
                attempt = {
                    "status": "error",
                    "type": "invalid_date",
                    "time": now_str,
                }
                await asyncio.to_thread(append_access_attempt, ticket_id, attempt)
                ticket["access_attempts"].append(attempt)
                logger.debug.info(f"Ticket is not valid today: {ticket_id}")
                return (
                    quart.jsonify(
                        {
                            "status": "error",
                            "message": "Ticket is not valid today",
                            "data": ticket,
                        }
                    ),
                    200,
                )

            used_at = now_str
            # Atomically claim the ticket: only ONE concurrent validation can
            # win this (used_at goes from NULL -> set in a single UPDATE).
            if not await asyncio.to_thread(mark_ticket_used, ticket_id, used_at):
                # Lost the race: another scan already used this ticket.
                attempt = {
                    "status": "error",
                    "type": "already_used",
                    "time": used_at,
                }
                await asyncio.to_thread(append_access_attempt, ticket_id, attempt)
                logger.debug.info(f"Ticket already used (race): {ticket_id}")
                return (
                    quart.jsonify(
                        {
                            "status": "error",
                            "message": "Ticket already used",
                            "data": await asyncio.to_thread(load_ticket_id, ticket_id),
                        }
                    ),
                    200,
                )

            # We won the claim; record the successful access attempt atomically.
            # mark_ticket_used already set used_at and valid=0, so only append the
            # audit entry (targeted JSON1 update, not a full-row rewrite).
            await asyncio.to_thread(
                append_access_attempt,
                ticket_id,
                {
                    "status": "success",
                    "type": "valid",
                    "time": used_at,
                },
            )
            ticket = await asyncio.to_thread(load_ticket_id, ticket_id)
            logger.info(f"Ticket validated: {ticket_id}")
            return (
                quart.jsonify(await _admitted(
                    {"status": "success", "message": "Ticket is valid", "data": ticket}
                )),
                200,
            )

        except Exception as e:
            app.logger.error(f"Error validating ticket: {e}")
            return (
                quart.jsonify({"status": "error", "message": "Internal server error"}),
                500,
            )
