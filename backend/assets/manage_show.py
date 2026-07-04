import quart
import config.conf as config
from assets.data import load_show, save_show, location_capacity, seat_occupancy
from reds_simple_logger import Logger
import os
import hmac
import uuid
from werkzeug.security import safe_join

logger = Logger()
logger.success("Manage_show.py loaded")

# Hard cap per uploaded image (also enforced globally via MAX_CONTENT_LENGTH).
MAX_IMAGE_BYTES = 32 * 1024 * 1024


def _sniff_image_type(data: bytes):
    """Return a safe MIME type if `data` starts with a known image magic-byte
    signature, else None. Never trust the client-supplied extension alone."""
    if data[:8] == b"\x89PNG\r\n\x1a\n":
        return "image/png"
    if data[:3] == b"\xff\xd8\xff":
        return "image/jpeg"
    if data[:6] in (b"GIF87a", b"GIF89a"):
        return "image/gif"
    if data[:4] == b"RIFF" and data[8:12] == b"WEBP":
        return "image/webp"
    return None


def _authorized() -> bool:
    """Timing-safe comparison of the Authorization header against the configured key."""
    key = quart.request.headers.get("Authorization")
    if not key:
        return False
    return hmac.compare_digest(str(key), str(config.Auth.auth_key))


def edit_show(app=quart.Quart):
    @app.route("/api/show/edit", methods=["POST"])
    async def edit_show():
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        try:
            data: dict = await quart.request.get_json()
            show: dict = load_show()

            orga_name: str = (
                data.get("orga_name")
                if data.get("orga_name")
                else show.get("orga_name")
            )
            title: str = data.get("title") if data.get("title") else show.get("title")
            dates: dict = data.get("dates") if data.get("dates") else show.get("dates")
            banner: str = (
                data.get("banner") if data.get("banner") else show.get("banner")
            )

            show["orga_name"] = orga_name
            show["banner"] = banner
            show["title"] = title
            show["subtitle"] = data.get("subtitle", show.get("subtitle"))
            show["dates"] = dates
            show["store_lock"] = bool(data.get("store_lock", show.get("store_lock")))
            show["payment_methods"] = data.get("payment_methods", show.get("payment_methods", "both"))

            if "locations" in data:
                show["locations"] = data["locations"]

            if "screens" in data:
                show["screens"] = data["screens"]

            if "stripe" in data:
                show["stripe"] = data["stripe"]

            # Public contact address shown to customers (storno / questions).
            if "contact_email" in data:
                show["contact_email"] = str(data["contact_email"]).strip()

            # Public app/frontend domain used to build links in emails (e.g. the
            # self-service cancel link). Stored verbatim; scheme is normalized at
            # use-time in ticket_manager.send_email.
            if "app_domain" in data:
                show["app_domain"] = str(data["app_domain"]).strip()

            save_show(show)
            return (
                quart.jsonify({"status": "success", "message": "Show config saved"}),
                200,
            )
        except Exception as e:
            return quart.jsonify({"status": "error", "message": str(e)}), 500


def _enrich_seated_availability(show: dict) -> None:
    """For reserved-seating dates, the numeric tickets/tickets_available counters
    are meaningless (seated sales live in seat_status, not that counter). Override
    them in-place with the TRUTH derived from the seat map: capacity = number of
    seats in the location's map, available = capacity − sold seats for that date.
    General-admission dates are left untouched."""
    dates = show.get("dates") if isinstance(show, dict) else None
    if not isinstance(dates, dict):
        return
    cap_cache: dict = {}
    for d in dates.values():
        if not isinstance(d, dict) or not d.get("seating"):
            continue
        loc = d.get("location") or ""
        try:
            if loc not in cap_cache:
                cap_cache[loc] = location_capacity(loc) if loc else 0
            cap = cap_cache[loc]
            occ = seat_occupancy(d.get("date")) if d.get("date") else {}
            sold = sum(1 for s in occ.values() if s == "sold")
            d["tickets"] = cap
            d["tickets_available"] = max(0, cap - sold)
        except Exception as e:
            logger.error(f"seated availability enrichment failed for {d.get('date')}: {e}")


def get_show(app=quart.Quart):
    @app.route("/api/show/get", methods=["POST", "GET"])
    async def get_show():
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        try:
            show: dict = load_show()
            _enrich_seated_availability(show)
            return show, 200
        except Exception as e:
            return quart.jsonify({"status": "error", "message": str(e)}), 500

    @app.route("/api/show/get/logo", methods=["POST", "GET"])
    async def get_show_logo():
        try:

            logo_path = os.path.join(
                os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
                "data",
                "assets",
                "logo.png",
            )
            with open(logo_path, "rb") as f:
                image_data = f.read()
            response = quart.Response(image_data, mimetype="image/png")
            return response
        except Exception as e:
            return quart.jsonify({"status": "error", "message": str(e)}), 500

    @app.route("/api/show/get/wallpaper", methods=["POST", "GET"])
    async def get_show_wallpaper():
        try:
            wallpaper_path = os.path.join(
                os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
                "data",
                "assets",
                "wallpaper.png",
            )
            with open(wallpaper_path, "rb") as f:
                image_data = f.read()
            response = quart.Response(image_data, mimetype="image/png")
            return response
        except Exception as e:
            return quart.jsonify({"status": "error", "message": str(e)}), 500

    @app.route("/api/show/get/price", methods=["POST", "GET"])
    async def get_show_price():
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        try:
            data: dict = await quart.request.get_json()
            date: str = data.get("date")

            show: dict = load_show()
            dates = show.get("dates")

            if not isinstance(dates, dict):
                return (
                    quart.jsonify(
                        {"status": "error", "message": "Invalid dates format"}
                    ),
                    400,
                )

            for key, value in dates.items():
                if value.get("date") == date:
                    price = value.get("price", "0")
                    return quart.jsonify({"status": "success", "price": price}), 200

            return quart.jsonify({"status": "error", "message": "Date not found"}), 404
        except Exception as e:
            return quart.jsonify({"status": "error", "message": str(e)}), 500

    @app.route("/api/show/get/stripe_pub_key", methods=["POST", "GET"])
    async def get_stripe_pub_key():
        try:
            show: dict = load_show()
            stripe_cfg = show.get("stripe", {})
            pub_key = stripe_cfg.get("publishable_key", "")
            return quart.jsonify({"publishable_key": pub_key}), 200
        except Exception as e:
            return quart.jsonify({"status": "error", "message": str(e)}), 500

    @app.route("/api/show/get/stripe", methods=["POST", "GET"])
    async def get_stripe_config():
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        try:
            show: dict = load_show()
            stripe_cfg = show.get("stripe", {})
            return quart.jsonify(stripe_cfg), 200
        except Exception as e:
            return quart.jsonify({"status": "error", "message": str(e)}), 500

    @app.route("/api/show/get/payment_methods", methods=["POST", "GET"])
    async def get_payment_methods():
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        try:
            show: dict = load_show()
            payment_methods = show.get("payment_methods", "both")
            return quart.jsonify({"status": "success", "payment_methods": payment_methods}), 200
        except Exception as e:
            return quart.jsonify({"status": "error", "message": str(e)}), 500

    @app.route("/api/show/cast/image/<filename>", methods=["GET"])
    async def get_cast_image(filename):
        try:
            cast_dir = os.path.join(
                os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
                "data", "assets", "cast",
            )
            # Prevent path traversal via the <filename> route segment
            file_path = safe_join(cast_dir, filename)
            if file_path is None or not os.path.isfile(file_path):
                return quart.jsonify({"status": "error", "message": "File not found"}), 404
            with open(file_path, "rb") as f:
                image_data = f.read()
            # Derive the Content-Type from the actual file content, not the
            # (attacker-controllable) name, and forbid MIME-sniffing so a file
            # that somehow slipped through can't be re-interpreted as HTML/JS.
            mimetype = _sniff_image_type(image_data) or "application/octet-stream"
            response = quart.Response(image_data, mimetype=mimetype)
            response.headers["X-Content-Type-Options"] = "nosniff"
            return response
        except Exception as e:
            return quart.jsonify({"status": "error", "message": str(e)}), 500


def cast_image_upload(app=quart.Quart):
    @app.route("/api/show/cast/upload", methods=["POST"])
    async def upload_cast_image():
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        try:
            files = await quart.request.files
            if "file" not in files:
                return quart.jsonify({"status": "error", "message": "No file provided"}), 400

            file = files["file"]
            if not file.filename:
                return quart.jsonify({"status": "error", "message": "Empty filename"}), 400

            cast_dir = os.path.join(
                os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
                "data", "assets", "cast",
            )
            os.makedirs(cast_dir, exist_ok=True)

            ext = file.filename.rsplit(".", 1)[-1].lower() if "." in file.filename else "png"
            allowed_ext = {"png", "jpg", "jpeg", "gif", "webp"}
            if ext not in allowed_ext:
                return quart.jsonify({"status": "error", "message": "Invalid file type"}), 400

            file_data = file.read()
            # Enforce a per-file cap and verify the bytes are actually an image
            # (don't trust the extension): rejects HTML/SVG/script disguised as
            # an image that could later be served and executed in the browser.
            if len(file_data) > MAX_IMAGE_BYTES:
                return quart.jsonify({"status": "error", "message": "File too large"}), 413
            if _sniff_image_type(file_data) is None:
                return quart.jsonify({"status": "error", "message": "Invalid image data"}), 400

            filename = f"cast_{uuid.uuid4().hex[:8]}.{ext}"
            file_path = os.path.join(cast_dir, filename)
            with open(file_path, "wb") as f:
                f.write(file_data)

            return quart.jsonify({"status": "success", "filename": filename}), 200
        except Exception as e:
            return quart.jsonify({"status": "error", "message": str(e)}), 500
