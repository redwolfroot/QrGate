from reds_simple_logger import Logger

logger = Logger()
logger.working("Starting up QrGate backend server...")

import os
import time as _time
from collections import deque

import quart
import quart_cors
from quart import Response, request
from assets.vaildate import validate_ticket
from assets.ticket_manager import create_ticket, edit_ticket, view_ticket, cancel_ticket
from assets.manage_show import get_show, edit_show, cast_image_upload
from assets.data import img_show, init_db
from assets.vote import vote
from assets.user import user_check
from assets.stats import get_stats_api, get_checkins_api
from assets.image_manager import upload_image, get_image, get_current_images
from assets.accounts import init_accounts, auth_routes, user_routes
from assets.setup import init_setup, setup_routes, apply_settings_to_config, is_installed
from assets.admin_ops import admin_ops
from assets.seatmap import seatmap_routes
from config import conf as config
from assets.timeutil import local_now

app = quart.Quart(__name__)

# Reject oversized request bodies before they are buffered into memory, so a
# huge upload can't be used to exhaust RAM (40 MiB covers ticket JSON and large
# banner/logo/cast image uploads).
app.config["MAX_CONTENT_LENGTH"] = 40 * 1024 * 1024

# Lock CORS down to the known frontend origin when configured. conf.py does not
# yet expose `frontend_origin`; until it does we fall back to "*" so nothing
# breaks. ACTION: add `frontend_origin = "https://<prod-frontend>"` under the
# API class in config/conf.py to actually restrict cross-origin access.
_frontend_origin = getattr(config.API, "frontend_origin", "*")
app = quart_cors.cors(app, allow_origin=_frontend_origin)

# --------------------------------------------------------------------------- #
# Lightweight in-process rate limiter (dependency-free).
#
# Per (client-IP, path-prefix) sliding window kept in a module-level dict. This
# is intentionally simple: it only protects a single process and resets on
# restart. NOTE: a real, distributed limiter (e.g. Redis token bucket shared
# across workers) is the production-grade follow-up.
#
# Limits are tuned so legitimate door traffic (several staff scanning a few
# tickets/second) is never blocked, while brute-force/enumeration is throttled.
# Format: path-prefix -> (max_requests, window_seconds).
# --------------------------------------------------------------------------- #
_RATE_LIMITS = {
    "/api/ticket/validate": (120, 10),   # ~12 scans/s/IP across all door staff
    "/api/ticket/create": (20, 60),      # buying is slow & human-paced
    "/api/ticket/cancel": (30, 60),      # admin-driven refunds, human-paced
    "/api/ticket/self-cancel": (10, 60), # public storno link, token-gated
    "/api/auth/login": (10, 300),        # login brute-force guard
    "/api/user/check": (30, 60),
    "/api/vote": (10, 60),
    "/codes/": (60, 60),                 # PDF/QR fetches
    "/api/admin/": (20, 60),             # backup + danger-zone maintenance ops
    "/api/seat/hold": (30, 60),          # checkout seat holds, human-paced
}
# Calls older than the largest window can be discarded entirely.
_RATE_MAX_WINDOW = max((w for _, w in _RATE_LIMITS.values()), default=60)
# {(ip, prefix): deque[timestamps]}
_rate_state: dict = {}


def _client_ip() -> str:
    """Best-effort client IP. We sit behind a proxy, so honor the first hop in
    X-Forwarded-For; fall back to the socket peer."""
    xff = request.headers.get("X-Forwarded-For", "")
    if xff:
        return xff.split(",")[0].strip()
    return request.remote_addr or "unknown"


def _match_limit(path: str):
    """Return (prefix, max, window) for the first rate-limited prefix that the
    request path falls under, or None if the path is not rate-limited."""
    for prefix, (limit, window) in _RATE_LIMITS.items():
        if path.startswith(prefix):
            return prefix, limit, window
    return None


@app.before_request
async def _rate_limit():
    matched = _match_limit(request.path)
    if matched is None:
        return None
    prefix, limit, window = matched
    now = _time.monotonic()
    key = (_client_ip(), prefix)
    bucket = _rate_state.get(key)
    if bucket is None:
        bucket = deque()
        _rate_state[key] = bucket
    # Drop timestamps that have aged out of this prefix's window.
    cutoff = now - window
    while bucket and bucket[0] < cutoff:
        bucket.popleft()
    if len(bucket) >= limit:
        return (
            quart.jsonify({"status": "error", "message": "Too many requests"}),
            429,
        )
    bucket.append(now)
    return None


time = local_now()

logger.working("Initializing database...")
init_db()  # idempotent; also runs one-time JSON -> SQLite migration if needed
init_accounts()  # create users table + seed default admin (admin/admin) on first run
init_setup()  # create settings table for the first-run setup wizard
apply_settings_to_config()  # push any stored SMTP overrides onto config.Mail

logger.working("Enabling systems...")
validate_ticket(app)
create_ticket(app)
edit_ticket(app)
view_ticket(app)
cancel_ticket(app)
get_show(app)
edit_show(app)
cast_image_upload(app)
img_show(app)
vote(app)
user_check(app)
get_stats_api(app)
get_checkins_api(app)
upload_image(app)
get_image(app)
get_current_images(app)
auth_routes(app)
user_routes(app)
setup_routes(app)
admin_ops(app)
seatmap_routes(app)
logger.success("Systems enabled.")

qr_gate = """

       ________          ________        __          
       \_____  \_______ /  _____/_____ _/  |_  ____  
       /  / \  \_  __ /   \  ___\__  \\   ___/ __ \ 
       /   \_/.  |  | \\    \_\  \/ __ \|  | \  ___/ 
       \_____\ \_|__|   \______  (____  |__|  \___  >
              \__>             \/     \/          \/ 

       """
print(qr_gate)
logger.info("QrGate backend server started. - Developed by avocloud.net")

# First-run hint: if the setup wizard has not been completed, print a big,
# hard-to-miss banner with the install link so the operator knows where to go.
if not is_installed():
    _setup_url = os.environ.get("QRGATE_SETUP_URL", "http://localhost:8080/install")
    logger.warn("=" * 64)
    logger.warn(" QrGate is NOT yet set up.")
    logger.warn(" Open the setup wizard to finish installation:")
    logger.warn("   -> " + _setup_url)
    logger.warn("=" * 64)

print(str(time.date()) + " - " + str(time.time()))
if __name__ == "__main__":
    # debug must stay False in production: the debug server leaks full
    # tracebacks (and can expose an interactive debugger) to any client.
    # Reloader is handy in local dev but pointless (and double-forks) inside a
    # container. Disable it by setting QRGATE_RELOAD=0 (set in the Docker image).
    _reload = os.environ.get("QRGATE_RELOAD", "1") not in ("0", "false", "False", "")
    app.run(debug=False, port=config.API.port, host="0.0.0.0", use_reloader=_reload)
