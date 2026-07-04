import hmac

import quart
import config.conf as config
from assets.data import (
    load_seatmap,
    save_seatmap,
    seat_index,
    seat_elements,
    location_capacity,
    seat_occupancy,
    hold_seats,
    release_hold,
    load_date,
)

# How long a checkout hold survives before the seats are released again.
HOLD_TTL_SECONDS = 600  # 10 minutes


def _authorized() -> bool:
    key = quart.request.headers.get("Authorization")
    return bool(key) and hmac.compare_digest(str(key), str(config.Auth.auth_key))


def _base_price(date: dict):
    """Fallback price for seats without their own category price."""
    try:
        return float(date.get("price"))
    except (TypeError, ValueError):
        return 0.0


def seatmap_routes(app: quart.Quart):
    # ----------------------------------------------------------------- #
    # Admin editor: load / save the layout for a location.
    # ----------------------------------------------------------------- #
    @app.route("/api/seatmap/get", methods=["GET"])
    async def seatmap_get():
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        location = quart.request.args.get("location")
        if not location:
            return quart.jsonify({"status": "error", "message": "Missing location"}), 400
        sm = load_seatmap(location)
        return quart.jsonify(
            {
                "status": "success",
                "location": location,
                "layout": sm["layout"],
                "categories": sm["categories"],
                "capacity": location_capacity(location),
            }
        ), 200

    @app.route("/api/seatmap/save", methods=["POST"])
    async def seatmap_save():
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        data = await quart.request.get_json(silent=True) or {}
        location = data.get("location")
        if not location:
            return quart.jsonify({"status": "error", "message": "Missing location"}), 400
        layout = data.get("layout")
        categories = data.get("categories")
        if not isinstance(layout, dict):
            return quart.jsonify({"status": "error", "message": "Invalid layout"}), 400
        if categories is None:
            categories = []
        if not isinstance(categories, list):
            return quart.jsonify({"status": "error", "message": "Invalid categories"}), 400

        # Reject duplicate seat ids: seat ids must be unique within a map or
        # occupancy/binding would be ambiguous.
        seats = seat_elements(layout)
        ids = [str(s["id"]) for s in seats]
        if len(ids) != len(set(ids)):
            return quart.jsonify(
                {"status": "error", "message": "Duplicate seat ids in layout"}
            ), 400

        save_seatmap(location, layout, categories)
        return quart.jsonify(
            {"status": "success", "message": "Seat map saved", "capacity": len(seats)}
        ), 200

    # ----------------------------------------------------------------- #
    # Buyer-facing: seats + live status for a given date.
    # ----------------------------------------------------------------- #
    @app.route("/api/seatmap/availability", methods=["GET", "POST"])
    async def seatmap_availability():
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        if quart.request.method == "POST":
            body = await quart.request.get_json(silent=True) or {}
            date = body.get("date")
        else:
            date = quart.request.args.get("date")
        if not date:
            return quart.jsonify({"status": "error", "message": "Missing date"}), 400

        d = load_date(date)
        if not d:
            return quart.jsonify({"status": "error", "message": "Invalid date"}), 400
        if not d.get("seating"):
            # General-admission date: no seat map in play.
            return quart.jsonify(
                {"status": "success", "seating": False, "date": date}
            ), 200

        location = d.get("location") or ""
        sm = load_seatmap(location)
        occupancy = seat_occupancy(date)
        base_price = _base_price(d)

        # Category price lookup for annotating seats.
        cat_price = {}
        for c in sm["categories"]:
            if isinstance(c, dict) and c.get("id") is not None:
                try:
                    cat_price[str(c["id"])] = float(c.get("price"))
                except (TypeError, ValueError):
                    pass

        elements = []
        free = 0
        for e in sm["layout"].get("elements", []):
            if not isinstance(e, dict):
                continue
            el = dict(e)
            if el.get("type") == "seat" and el.get("id"):
                sid = str(el["id"])
                status = occupancy.get(sid, "free")
                el["status"] = status
                cid = el.get("category_id")
                el["price"] = cat_price.get(str(cid), base_price) if cid is not None else base_price
                if status == "free":
                    free += 1
            elements.append(el)

        return quart.jsonify(
            {
                "status": "success",
                "seating": True,
                "date": date,
                "location": location,
                "elements": elements,
                "categories": sm["categories"],
                "base_price": base_price,
                "free": free,
                "capacity": location_capacity(location),
                "hold_ttl": HOLD_TTL_SECONDS,
            }
        ), 200

    # ----------------------------------------------------------------- #
    # Buyer-facing: place / release a checkout hold.
    # ----------------------------------------------------------------- #
    @app.route("/api/seat/hold", methods=["POST"])
    async def seat_hold():
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        data = await quart.request.get_json(silent=True) or {}
        date = data.get("date")
        seats = data.get("seats") or []
        if not date or not isinstance(seats, list) or not seats:
            return quart.jsonify({"status": "error", "message": "Missing date or seats"}), 400

        d = load_date(date)
        if not d or not d.get("seating"):
            return quart.jsonify({"status": "error", "message": "Date is not seated"}), 400

        # Only allow holding seats that actually exist in this location's map.
        valid = seat_index(d.get("location") or "")
        requested = [str(s) for s in seats]
        unknown = [s for s in requested if s not in valid]
        if unknown:
            return quart.jsonify(
                {"status": "error", "message": "Unknown seats", "seats": unknown}
            ), 400

        ok, token, taken = hold_seats(date, requested, HOLD_TTL_SECONDS)
        if not ok:
            return quart.jsonify(
                {"status": "error", "message": "seats_taken", "seats": taken}
            ), 409
        return quart.jsonify(
            {
                "status": "success",
                "hold_token": token,
                "ttl": HOLD_TTL_SECONDS,
                "seats": requested,
            }
        ), 200

    @app.route("/api/seat/release", methods=["POST"])
    async def seat_release():
        if not _authorized():
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        data = await quart.request.get_json(silent=True) or {}
        date = data.get("date")
        token = data.get("hold_token")
        if not date or not token:
            return quart.jsonify({"status": "error", "message": "Missing date or token"}), 400
        freed = release_hold(date, token)
        return quart.jsonify({"status": "success", "freed": freed}), 200
