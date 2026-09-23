import hmac
import quart
from assets.data import load_show, save_show
import config.conf as config


def vote(app: quart.Quart):
    @app.route("/api/vote", methods=["POST"])
    async def vote():
        key = quart.request.headers.get("Authorization")
        if not key or not hmac.compare_digest(str(key), str(config.Auth.auth_key)):
            return quart.jsonify({"status": "error", "message": "Unauthorized"}), 401
        try:
            data = await quart.request.get_json(silent=True) or {}
            new_value = int(data.get("value"))
        except (TypeError, ValueError):
            return quart.jsonify({"status": "error", "message": "Invalid vote value"}), 400
        # Reject out-of-range votes so a single request can't skew the average.
        if new_value < 0 or new_value > 5:
            return quart.jsonify({"status": "error", "message": "Vote value out of range"}), 400

        show = load_show()
        vote_data = show.get("votes")
        new_count: int = vote_data.get("count") + 1
        vote_data["count"] = new_count
        vote_data["value"] = vote_data.get("value", 0) + new_value
        vote_data["average"] = round(vote_data["value"] / new_count, 1)
        comment = data.get("comment", "")
        if comment:
            if "comments" not in vote_data:
                vote_data["comments"] = []
            vote_data["comments"].append(str(comment))

        save_show(show, write_dates=False)
        return quart.jsonify({"status": "success", "message": "Vote received"}), 200
