<?php
require_once "../../config.php";

if (!isset($_SESSION["admin"]) && !isset($_SESSION["ticketflow_access"])) {
    header("Location: ../login.php?redirect=ticketflow");
    exit();
}
if (!empty($_SESSION["must_change_pw"])) {
    header("Location: ../change_password.php");
    exit();
}

$isAdmin  = !empty($_SESSION["admin"]);
$username = (string) ($_SESSION["username"] ?? "staff");

// CSRF guard for every POST. Token comes from the X-CSRF-Token header
// (fetch) or a csrf_token field (the language form).
function tf_require_csrf($asJson = false) {
    $token = $_SERVER["HTTP_X_CSRF_TOKEN"] ?? $_POST["csrf_token"] ?? "";
    if (!validateCsrfToken($token)) {
        http_response_code(403);
        if ($asJson) {
            header("Content-Type: application/json");
            echo json_encode(["status" => "error", "message" => "csrf"]);
        } else {
            echo "Invalid request. Please reload the page and try again.";
        }
        exit();
    }
}

// --- language switch ---------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["language"])) {
    tf_require_csrf(false);
    $_SESSION["language"] = in_array($_POST["language"], ["de", "en"], true) ? $_POST["language"] : "de";
    header("Location: index.php");
    exit();
}
$lang_code = in_array($_SESSION["language"] ?? "de", ["de", "en"], true) ? ($_SESSION["language"] ?? "de") : "de";

// --- same-origin PDF proxy ---------------------------------------------------
// Streams the combined multi-page ticket PDF from the backend so it can be
// loaded into a same-origin <iframe> and printed directly (no popups, one job).
if (isset($_GET["print"])) {
    $tids = preg_replace('/[^0-9A-Za-z,\-]/', '', $_GET["print"]);
    // The backend's /codes/pdf is gated by a per-ticket HMAC token. Build a
    // parallel ?tokens= list (same order as tids) so the batch print is accepted.
    $tidList = array_values(array_filter(array_map('trim', explode(',', $tids)), 'strlen'));
    $tokenList = array_map(
        fn($tid) => substr(hash_hmac('sha256', $tid, API_KEY), 0, 16),
        $tidList
    );
    $ch = curl_init(
        API_BASE_URL . "codes/pdf?tids=" . urlencode(implode(',', $tidList))
        . "&tokens=" . urlencode(implode(',', $tokenList))
    );
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ["Authorization: " . API_KEY],
    ]);
    $pdf  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200 && $pdf !== false && $pdf !== "") {
        header("Content-Type: application/pdf");
        header('Content-Disposition: inline; filename="tickets.pdf"');
        echo $pdf;
    } else {
        http_response_code(404);
        echo "PDF not found";
    }
    exit();
}

// --- backend proxy (keeps API_KEY server-side) -------------------------------
function call_api($endpoint, $data) {
    $ch = curl_init(API_BASE_URL . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: " . API_KEY,
            "Content-Type: application/json",
        ],
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$http_code, $response === false ? null : json_decode($response, true)];
}

// Sellable dates for the register: sorted, past dates hidden (unless nothing
// else is left), with the fields the UI needs.
function tf_dates($shows) {
    $today = (new DateTime("now", new DateTimeZone("Europe/Berlin")))->format("Y-m-d");
    $all = [];
    foreach (($shows["dates"] ?? []) as $d) {
        if (empty($d["date"])) continue;
        $all[] = [
            "date"     => (string) $d["date"],
            "time"     => (string) ($d["time"] ?? ""),
            "price"    => (float) ($d["price"] ?? 0),
            "avail"    => (int) ($d["tickets_available"] ?? 0),
            "seating"  => !empty($d["seating"]),
            "location" => (string) ($d["location"] ?? ""),
        ];
    }
    usort($all, fn($a, $b) => strcmp($a["date"] . $a["time"], $b["date"] . $b["time"]));
    $upcoming = array_values(array_filter($all, fn($d) => $d["date"] >= $today));
    return ["today" => $today, "dates" => $upcoming ?: $all];
}

// --- AJAX --------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ajax"])) {
    tf_require_csrf(true);
    header("Content-Type: application/json");
    $in = json_decode($_POST["data"] ?? "{}", true) ?: [];
    $out = null;

    switch ($_POST["ajax"]) {
        case "dates":
            $out = ["status" => "success"] + tf_dates(getShows() ?: []);
            break;

        case "seat_avail":
            [, $out] = call_api("api/seatmap/availability", ["date" => (string) ($in["date"] ?? "")]);
            break;

        case "sell":
            [, $out] = call_api("api/boxoffice/sell", [
                "type"       => (string) ($in["type"] ?? "visitor"),
                "valid_date" => (string) ($in["valid_date"] ?? ""),
                "items"      => is_array($in["items"] ?? null) ? $in["items"] : [],
                "seats"      => is_array($in["seats"] ?? null) ? $in["seats"] : [],
                "method"     => (string) ($in["method"] ?? "bar"),
                "first_name" => (string) ($in["first_name"] ?? ""),
                "last_name"  => (string) ($in["last_name"] ?? ""),
                "email"      => (string) ($in["email"] ?? ""),
                "lang"       => $lang_code,
                "seller"     => $username,
            ]);
            break;

        case "sales":
            // Cashiers see their own register; admins may look at all of them.
            $all = $isAdmin && !empty($in["all"]);
            [, $out] = call_api("api/boxoffice/sales", [
                "day"    => (string) ($in["day"] ?? ""),
                "seller" => $all ? "" : $username,
            ]);
            break;

        case "search":
            [, $out] = call_api("api/boxoffice/search", ["q" => (string) ($in["q"] ?? "")]);
            break;

        case "get":
            [$code, $resp] = call_api("api/ticket/get", ["tid" => (string) ($in["tid"] ?? "")]);
            if ($code === 200 && ($resp["status"] ?? "") === "success" && isset($resp["data"])) {
                $t = $resp["data"];
                unset($t["access_attempts"], $t["payment_intent"], $t["refund_id"]);
                $out = ["status" => "success", "ticket" => $t];
            } else {
                $out = ["status" => "error", "message" => "not_found"];
            }
            break;

        case "void":
            [, $out] = call_api("api/boxoffice/void", [
                "tids"   => is_array($in["tids"] ?? null) ? $in["tids"] : [],
                "reason" => (string) ($in["reason"] ?? ""),
                "actor"  => "ticketflow:" . $username,
            ]);
            break;

        case "collect":
            // Reservation paid at the counter: marks it paid and books it on
            // this register (shows up in the register report).
            [, $out] = call_api("api/boxoffice/collect", [
                "tid"    => (string) ($in["tid"] ?? ""),
                "method" => (string) ($in["method"] ?? "bar"),
                "seller" => $username,
            ]);
            break;

        case "pair_open":
            // Register scanner: this register shows a 4-digit code that a
            // handheld in "Kasse" mode joins; its scans open tickets here.
            [, $out] = call_api("api/boxoffice/pair/open", ["seller" => $username]);
            break;

        case "pair_poll":
        case "pair_close":
            [$code, $out] = call_api("api/boxoffice/pair/" . ($_POST["ajax"] === "pair_poll" ? "poll" : "close"), [
                "code"   => (string) ($in["code"] ?? ""),
                "secret" => (string) ($in["secret"] ?? ""),
            ]);
            if ($code === 410) $out = ["status" => "error", "message" => "pair_gone"];
            break;

        case "live":
            // Announcement banner + heartbeat (this register shows up in the
            // live device list). The session is not needed any more.
            session_write_close();
            $out = makeApiCall("api/live/state?" . http_build_query([
                "device" => substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($in["device"] ?? "")), 0, 64),
                "name"   => "Kasse " . $username,
                "role"   => "ticketflow",
            ]));
            if (isset($out["error"])) $out = null;
            break;

        case "resend":
            // Resend the ticket email, optionally to a corrected address
            // (stored on the ticket). Box office and admin alike.
            $payload = ["tid" => (string) ($in["tid"] ?? "")];
            if (trim((string) ($in["email"] ?? "")) !== "") $payload["email"] = trim((string) $in["email"]);
            [, $out] = call_api("api/ticket/resend", $payload);
            break;

        case "edit":
            if (!$isAdmin) {
                http_response_code(403);
                $out = ["status" => "error", "message" => "forbidden"];
                break;
            }
            $payload = ["tid" => (string) ($in["tid"] ?? "")];
            foreach (["first_name", "last_name", "valid_date", "type"] as $k) {
                if (isset($in[$k])) $payload[$k] = (string) $in[$k];
            }
            [, $out] = call_api("api/ticket/edit", $payload);
            break;
    }

    echo json_encode($out ?: ["status" => "error", "message" => "unavailable"]);
    exit();
}

// --- page data ---------------------------------------------------------------
$shows = getShows() ?: [];
$dateInfo = tf_dates($shows);
$categories = array_values(array_filter(
    is_array($shows["boxoffice_categories"] ?? null) ? $shows["boxoffice_categories"] : [],
    fn($c) => is_array($c) && !empty($c["id"]) && !empty($c["name"])
));

$T = [
    "de" => [
        "subtitle" => "Kasse", "tab_sell" => "Kasse", "tab_sales" => "Verkäufe",
        "normal" => "Normal", "seat_price" => "Sitzpreis",
        "left" => "frei", "sold_out" => "ausverkauft", "today" => "Heute",
        "no_dates" => "Keine kommenden Termine. Termine im Admin-Panel anlegen.",
        "receipt" => "Bon", "clear" => "Leeren", "empty_cart" => "Kategorie antippen, um Tickets hinzuzufügen.",
        "total" => "Summe", "cash" => "Bar", "card" => "Karte",
        "given" => "Gegeben", "exact" => "Passend", "change" => "Rückgeld", "missing" => "Fehlt",
        "customer" => "Name / E-Mail (optional)", "first_name" => "Vorname", "last_name" => "Nachname",
        "email" => "E-Mail", "email_hint" => "Mit E-Mail wird das Ticket zusätzlich verschickt.",
        "charge" => "Kassieren", "issue" => "Ausgeben", "and_print" => "& drucken",
        "autoprint" => "Automatisch drucken", "selling" => "Verbuche…",
        "sold" => "Verkauft", "print_again" => "Nochmal drucken", "undo" => "Stornieren",
        "next_sale" => "Nächster Verkauf", "undo_confirm" => "Diesen Verkauf komplett stornieren? Die Tickets werden ungültig und die Plätze wieder frei.",
        "undone" => "Verkauf storniert.",
        "seats" => "Plätze", "seats_pick" => "Plätze wählen", "seats_change" => "Plätze ändern",
        "seats_auto" => "automatisch gewählt", "seats_manual" => "von Hand gewählt",
        "seats_mismatch" => "Plätze passen nicht zur Anzahl", "seats_none" => "Erst Tickets in den Bon legen.",
        "seat_sold" => "Belegt", "seat_auto" => "Automatisch", "seat_fit" => "Ganzer Saal", "done" => "Fertig",
        "seat_unavail" => "Sitzplan nicht verfügbar.", "seat_loading" => "Lade Sitzplan…", "chosen" => "gewählt",
        "special" => "Sonderticket (VIP / Admin)…", "special_title" => "Sonderticket",
        "special_hint" => "Kostenlos, für alle Termine gültig, zählt nicht zur Kapazität.",
        "qty" => "Anzahl", "type" => "Typ", "create_print" => "Erstellen & drucken",
        "err" => "Fehler", "err_seats_taken" => "Plätze wurden gerade anderweitig verkauft – neu gewählt, bitte prüfen.",
        "err_sold_out" => "Nicht mehr genug Tickets frei.", "err_net" => "Keine Verbindung zum Server.",
        "err_csrf" => "Sitzung abgelaufen – Seite neu laden.",
        "sales_today" => "Heute", "mine" => "Meine Kasse", "all" => "Alle Kassen",
        "tickets" => "Tickets", "free_tickets" => "Frei-/Sondertickets", "cancelled" => "Storniert",
        "close_register" => "Kassenabschluss drucken", "search_ph" => "Ticket-ID, Name oder E-Mail (oder scannen)",
        "search" => "Suchen", "no_results" => "Nichts gefunden.", "no_sales" => "Noch keine Verkäufe heute.",
        "sale" => "Verkauf", "partial" => "teilweise storniert", "status_cancelled" => "storniert",
        "status_used" => "eingelassen", "status_unpaid" => "unbezahlt", "status_valid" => "gültig",
        "ticket" => "Ticket", "date" => "Termin", "seat" => "Platz", "price" => "Preis", "method" => "Zahlart",
        "seller" => "Verkauft von", "created" => "Erstellt", "print" => "Drucken",
        "collect" => "Kassieren", "collected" => "Bezahlt – Ticket ist jetzt gültig.", "reservation" => "Reservierung",
        "cancel_reason" => "Storno-Grund (optional)", "cancel_ticket" => "Ticket stornieren",
        "cancel_confirm" => "Ticket stornieren? Online bezahlte Tickets werden automatisch erstattet. Nicht umkehrbar.",
        "cancel_ok" => "Ticket storniert.", "edit" => "Bearbeiten (Admin)", "save" => "Speichern", "saved" => "Gespeichert.",
        "unlimited" => "Alle Termine", "switch_app" => "App wechseln", "logout" => "Abmelden", "language" => "Sprache",
        "pair" => "Scanner koppeln", "pair_title" => "Handy als Kassen-Scanner",
        "pair_hint" => "Am Handy den Einlass-Scanner öffnen, unten „Kasse“ wählen und diesen Code eingeben. Jedes gescannte Ticket öffnet sich dann hier zum Kassieren.",
        "pair_wait" => "Warte auf Handy…", "pair_ok" => "Handy verbunden", "pair_end" => "Kopplung beenden",
        "pair_gone" => "Scanner-Kopplung beendet.", "pair_scanned" => "Gescannt:", "scanner" => "Scanner",
        "method_bar" => "Bar", "method_card" => "Karte", "method_free" => "Frei", "method_stripe" => "Online", "method_paid" => "Vor Ort zahlen",
        "report_title" => "Kassenabschluss", "report_by" => "Kasse", "report_sales" => "Verkäufe",
        "shortcuts" => "Tasten: 1–9 Kategorie · Entf letzte entfernen · Enter kassieren · Esc leeren",
        "mail_resend" => "Erneut senden", "mail_last" => "Zuletzt gesendet", "mail_never" => "Noch nicht per E-Mail gesendet.",
        "mail_hint" => "Eine geänderte Adresse wird am Ticket gespeichert.", "mail_sent" => "Gesendet an",
        "mail_cooldown" => "Gerade erst gesendet. Bitte in {s} s noch einmal.", "mail_smtp" => "Mailserver nicht erreichbar. Das Ticket bleibt unverändert.",
        "mail_noconf" => "Kein Mailserver eingerichtet.", "mail_invalid" => "Ungültige E-Mail-Adresse.", "mail_none" => "Bitte eine E-Mail-Adresse eingeben.",
        "cast_info" => "Info", "cast_attention" => "Achtung", "cast_alert" => "Dringend", "cast_success" => "Hinweis",
    ],
    "en" => [
        "subtitle" => "Box office", "tab_sell" => "Register", "tab_sales" => "Sales",
        "normal" => "Regular", "seat_price" => "Seat price",
        "left" => "left", "sold_out" => "sold out", "today" => "Today",
        "no_dates" => "No upcoming dates. Add dates in the admin panel.",
        "receipt" => "Receipt", "clear" => "Clear", "empty_cart" => "Tap a category to add tickets.",
        "total" => "Total", "cash" => "Cash", "card" => "Card",
        "given" => "Given", "exact" => "Exact", "change" => "Change", "missing" => "Missing",
        "customer" => "Name / email (optional)", "first_name" => "First name", "last_name" => "Last name",
        "email" => "Email", "email_hint" => "With an email address the ticket is also sent by mail.",
        "charge" => "Charge", "issue" => "Issue", "and_print" => "& print",
        "autoprint" => "Print automatically", "selling" => "Booking…",
        "sold" => "Sold", "print_again" => "Print again", "undo" => "Void",
        "next_sale" => "Next sale", "undo_confirm" => "Void this whole sale? The tickets become invalid and the seats are released.",
        "undone" => "Sale voided.",
        "seats" => "Seats", "seats_pick" => "Choose seats", "seats_change" => "Change seats",
        "seats_auto" => "picked automatically", "seats_manual" => "picked by hand",
        "seats_mismatch" => "Seats don't match the ticket count", "seats_none" => "Add tickets to the receipt first.",
        "seat_sold" => "Taken", "seat_auto" => "Auto-pick", "seat_fit" => "Whole hall", "done" => "Done",
        "seat_unavail" => "Seat map unavailable.", "seat_loading" => "Loading seat map…", "chosen" => "chosen",
        "special" => "Special ticket (VIP / Admin)…", "special_title" => "Special ticket",
        "special_hint" => "Free, valid for every date, does not count against capacity.",
        "qty" => "Quantity", "type" => "Type", "create_print" => "Create & print",
        "err" => "Error", "err_seats_taken" => "Seats were just sold elsewhere – re-picked, please check.",
        "err_sold_out" => "Not enough tickets left.", "err_net" => "Cannot reach the server.",
        "err_csrf" => "Session expired – reload the page.",
        "sales_today" => "Today", "mine" => "My register", "all" => "All registers",
        "tickets" => "Tickets", "free_tickets" => "Free / special tickets", "cancelled" => "Voided",
        "close_register" => "Print register report", "search_ph" => "Ticket ID, name or email (or scan)",
        "search" => "Search", "no_results" => "Nothing found.", "no_sales" => "No sales today yet.",
        "sale" => "Sale", "partial" => "partly voided", "status_cancelled" => "cancelled",
        "status_used" => "checked in", "status_unpaid" => "unpaid", "status_valid" => "valid",
        "ticket" => "Ticket", "date" => "Date", "seat" => "Seat", "price" => "Price", "method" => "Payment",
        "seller" => "Sold by", "created" => "Created", "print" => "Print",
        "collect" => "Collect", "collected" => "Paid – the ticket is now valid.", "reservation" => "Reservation",
        "cancel_reason" => "Reason (optional)", "cancel_ticket" => "Cancel ticket",
        "cancel_confirm" => "Cancel this ticket? Tickets paid online are refunded automatically. Cannot be undone.",
        "cancel_ok" => "Ticket cancelled.", "edit" => "Edit (admin)", "save" => "Save", "saved" => "Saved.",
        "unlimited" => "All dates", "switch_app" => "Switch app", "logout" => "Log out", "language" => "Language",
        "pair" => "Pair scanner", "pair_title" => "Phone as register scanner",
        "pair_hint" => "On the phone, open the door scanner, choose “Kasse” at the bottom and enter this code. Every scanned ticket then opens here for payment.",
        "pair_wait" => "Waiting for phone…", "pair_ok" => "Phone connected", "pair_end" => "End pairing",
        "pair_gone" => "Scanner pairing ended.", "pair_scanned" => "Scanned:", "scanner" => "Scanner",
        "method_bar" => "Cash", "method_card" => "Card", "method_free" => "Free", "method_stripe" => "Online", "method_paid" => "Pay at venue",
        "report_title" => "Register report", "report_by" => "Register", "report_sales" => "Sales",
        "shortcuts" => "Keys: 1–9 category · Del remove last · Enter charge · Esc clear",
        "mail_resend" => "Send again", "mail_last" => "Last sent", "mail_never" => "Not sent by email yet.",
        "mail_hint" => "A changed address is saved on the ticket.", "mail_sent" => "Sent to",
        "mail_cooldown" => "Just sent. Please try again in {s} s.", "mail_smtp" => "Mail server unreachable. The ticket is unchanged.",
        "mail_noconf" => "No mail server set up.", "mail_invalid" => "Invalid email address.", "mail_none" => "Please enter an email address.",
        "cast_info" => "Info", "cast_attention" => "Attention", "cast_alert" => "Urgent", "cast_success" => "Notice",
    ],
];
$L = $T[$lang_code];

$pageTitle = "TicketFlow";
$assetBase = "../../";
$csrfToken = generateCsrfToken();
$extraHead = '<meta name="csrf-token" content="' . htmlspecialchars($csrfToken, ENT_QUOTES) . '">';
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="<?php echo $lang_code; ?>" class="avo-ui">
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body>
<style>
    body { min-height: 100vh; min-height: 100dvh; display: flex; flex-direction: column; background: var(--avo-bg); }
    [hidden] { display: none !important; }
    .tf-mono { font-family: var(--avo-font-mono); }
    .tf-num { font-variant-numeric: tabular-nums; }

    /* ---- top bar ---- */
    .tf-top { display: flex; align-items: center; gap: 12px; padding: 10px 16px; background: var(--avo-surface); border-bottom: 1px solid var(--avo-border); position: sticky; top: 0; z-index: 30; }
    .tf-brand { display: flex; align-items: center; gap: 8px; font-family: var(--avo-font-display); font-weight: 800; font-size: 1.25rem; white-space: nowrap; }
    .tf-tabs { display: flex; gap: 4px; background: var(--avo-bg); padding: 4px; border-radius: var(--avo-radius-md); border: 1px solid var(--avo-border); }
    .tf-tab { padding: 8px 16px; border-radius: 8px; border: 0; background: transparent; color: var(--avo-text-muted); font-weight: 700; cursor: pointer; font-size: .95rem; }
    .tf-tab[aria-selected="true"] { background: var(--avo-primary); color: var(--avo-primary-on); }
    .tf-spacer { flex: 1; }
    /* register scanner pairing */
    .tf-pairbtn { display: inline-flex; align-items: center; gap: 8px; height: 38px; padding: 0 12px; border-radius: var(--avo-radius-md); border: 1px solid var(--avo-border); background: var(--avo-bg); color: var(--avo-text); cursor: pointer; font-family: var(--avo-font-mono); font-size: .8rem; font-weight: 600; white-space: nowrap; }
    .tf-pairbtn:hover { border-color: var(--avo-primary); }
    .tf-pairbtn svg { width: 16px; height: 16px; flex-shrink: 0; }
    .tf-pairbtn .dot { width: 8px; height: 8px; border-radius: 999px; background: var(--avo-text-muted); }
    .tf-pairbtn.is-live .dot { background: var(--avo-success); }
    .tf-pairbtn.is-wait .dot { background: var(--avo-warning); animation: tfPulse 1s ease-in-out infinite; }
    @keyframes tfPulse { 50% { opacity: .3; } }
    .tf-paircode { font-family: var(--avo-font-mono); font-size: 3.4rem; font-weight: 600; letter-spacing: .3em; text-align: center; padding: 18px 0 18px .3em; border: 1px solid var(--avo-border); border-radius: var(--avo-radius-lg); background: var(--avo-bg); }
    .tf-pairstate { display: flex; align-items: center; justify-content: center; gap: 8px; font-family: var(--avo-font-mono); font-size: .85rem; color: var(--avo-text-muted); }
    .tf-pairstate .dot { width: 9px; height: 9px; border-radius: 999px; background: var(--avo-warning); animation: tfPulse 1s ease-in-out infinite; }
    .tf-pairstate.is-live { color: var(--avo-success); }
    .tf-pairstate.is-live .dot { background: var(--avo-success); animation: none; }
    .tf-user { position: relative; }
    .tf-userbtn { display: flex; align-items: center; gap: 6px; padding: 8px 12px; border-radius: var(--avo-radius-md); border: 1px solid var(--avo-border); background: var(--avo-bg); color: var(--avo-text); cursor: pointer; font-weight: 700; max-width: 180px; }
    .tf-userbtn span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .tf-menu { position: absolute; right: 0; top: calc(100% + 6px); min-width: 220px; background: var(--avo-surface); border: 1px solid var(--avo-border); border-radius: var(--avo-radius-md); box-shadow: 0 12px 32px rgba(0,0,0,.25); padding: 6px; z-index: 40; }
    .tf-menu a, .tf-menu button { display: flex; width: 100%; align-items: center; gap: 8px; padding: 10px 12px; border-radius: 8px; border: 0; background: none; color: var(--avo-text); text-decoration: none; font-weight: 600; cursor: pointer; text-align: left; font-size: .95rem; }
    .tf-menu a:hover, .tf-menu button:hover { background: var(--avo-bg); }
    .tf-menu .sep { height: 1px; background: var(--avo-border); margin: 6px 4px; }
    .tf-menu .lbl { padding: 6px 12px 2px; font-size: .75rem; color: var(--avo-text-muted); font-family: var(--avo-font-mono); text-transform: uppercase; letter-spacing: .1em; }
    .tf-menu .cur { color: var(--avo-primary); }

    /* ---- sell view ---- */
    .tf-sell { flex: 1; display: grid; grid-template-columns: minmax(0, 1fr) 400px; min-height: 0; }
    .tf-products { padding: 18px 20px 28px; overflow: auto; }
    .tf-cart { border-left: 1px solid var(--avo-border); background: var(--avo-surface); display: flex; flex-direction: column; position: sticky; top: 59px; height: calc(100dvh - 59px); }
    .tf-dates { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 4px; margin-bottom: 18px; scrollbar-width: thin; }
    .tf-date { flex: 0 0 auto; text-align: left; padding: 10px 14px; border-radius: var(--avo-radius-md); border: 2px solid var(--avo-border); background: var(--avo-surface); color: var(--avo-text); cursor: pointer; min-width: 150px; }
    .tf-date.active { border-color: var(--avo-primary); background: color-mix(in oklab, var(--avo-primary) 12%, var(--avo-surface)); }
    .tf-date .d1 { font-weight: 800; font-family: var(--avo-font-display); }
    .tf-date .d2 { font-size: .8rem; color: var(--avo-text-muted); margin-top: 2px; }
    .tf-date .d2 .low { color: var(--avo-warning); font-weight: 700; }
    .tf-date .d2 .out { color: var(--avo-error); font-weight: 700; }
    .tf-cats { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 12px; }
    .tf-cat { position: relative; min-height: 118px; text-align: left; padding: 16px; border-radius: var(--avo-radius-lg); border: 2px solid var(--avo-border); background: var(--avo-surface); color: var(--avo-text); cursor: pointer; display: flex; flex-direction: column; justify-content: space-between; transition: border-color .1s, transform .06s; user-select: none; -webkit-user-select: none; touch-action: manipulation; }
    .tf-cat:hover:not(:disabled) { border-color: var(--avo-primary); }
    .tf-cat:active:not(:disabled) { transform: scale(.98); }
    .tf-cat:disabled { opacity: .4; cursor: not-allowed; }
    .tf-cat .c-name { font-weight: 800; font-size: 1.15rem; line-height: 1.2; padding-right: 38px; overflow-wrap: anywhere; }
    .tf-cat .c-price { font-weight: 800; font-size: 1.6rem; line-height: 1.1; color: var(--avo-primary); white-space: nowrap; }
    .tf-cat .c-price.rule { font-size: 1.15rem; }
    .tf-cat .c-key { position: absolute; left: 16px; bottom: 44px; font-size: .7rem; color: var(--avo-text-muted); font-family: var(--avo-font-mono); }
    .tf-cat .c-badge { position: absolute; top: 12px; right: 12px; min-width: 32px; height: 32px; padding: 0 8px; border-radius: 999px; background: var(--avo-primary); color: var(--avo-primary-on); font-weight: 800; display: flex; align-items: center; justify-content: center; }
    .tf-seatbox { margin-top: 16px; padding: 14px 16px; border-radius: var(--avo-radius-lg); border: 1px solid var(--avo-border); background: var(--avo-surface); display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .tf-seatbox .sb-info { flex: 1; min-width: 200px; }
    .tf-seatbox .sb-labels { font-weight: 700; }
    .tf-seatbox.warn { border-color: var(--avo-warning); }
    .tf-linkbtn { margin-top: 22px; background: none; border: 0; color: var(--avo-text-muted); font-weight: 700; cursor: pointer; text-decoration: underline; text-underline-offset: 3px; padding: 4px 0; }
    .tf-linkbtn:hover { color: var(--avo-primary); }
    .tf-hint { margin-top: 18px; font-size: .75rem; color: var(--avo-text-muted); font-family: var(--avo-font-mono); }

    /* ---- cart ---- */
    .tf-cart-head { display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; border-bottom: 1px dashed var(--avo-border); }
    .tf-cart-head h2 { font-size: 1.1rem; font-weight: 800; font-family: var(--avo-font-display); }
    .tf-lines { flex: 1; overflow: auto; padding: 8px 18px; }
    .tf-line { display: grid; grid-template-columns: minmax(0,1fr) auto auto; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--avo-border); }
    .tf-line .l-name { font-weight: 700; }
    .tf-line .l-sub { font-size: .8rem; color: var(--avo-text-muted); }
    .tf-line .l-sum { font-weight: 800; min-width: 72px; text-align: right; }
    .tf-qty { display: inline-flex; align-items: center; border: 1px solid var(--avo-border); border-radius: 10px; overflow: hidden; }
    .tf-qty button { width: 38px; height: 38px; border: 0; background: var(--avo-bg); color: var(--avo-text); font-size: 1.2rem; font-weight: 800; cursor: pointer; }
    .tf-qty span { min-width: 30px; text-align: center; font-weight: 800; }
    .tf-empty { color: var(--avo-text-muted); text-align: center; padding: 40px 10px; }
    .tf-pay { padding: 14px 18px 18px; border-top: 1px solid var(--avo-border); display: grid; gap: 12px; }
    .tf-total { display: flex; justify-content: space-between; align-items: baseline; }
    .tf-total b { font-family: var(--avo-font-display); font-size: 2.2rem; font-weight: 800; }
    .tf-seg { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
    .tf-seg button { padding: 12px; border-radius: var(--avo-radius-md); border: 2px solid var(--avo-border); background: var(--avo-bg); color: var(--avo-text); font-weight: 800; cursor: pointer; font-size: 1rem; }
    .tf-seg button.active { border-color: var(--avo-primary); background: color-mix(in oklab, var(--avo-primary) 14%, var(--avo-bg)); }
    .tf-given { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
    .tf-given button { flex: 1 0 auto; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--avo-border); background: var(--avo-bg); color: var(--avo-text); font-weight: 700; cursor: pointer; }
    .tf-given button.active { border-color: var(--avo-primary); color: var(--avo-primary); }
    .tf-given input { width: 90px; flex: 1 0 70px; }
    .tf-change { display: flex; justify-content: space-between; align-items: baseline; padding: 8px 12px; border-radius: 10px; background: color-mix(in oklab, var(--avo-success) 16%, transparent); }
    .tf-change.neg { background: color-mix(in oklab, var(--avo-error) 16%, transparent); }
    .tf-change b { font-size: 1.5rem; font-family: var(--avo-font-display); }
    details.tf-cust summary { cursor: pointer; font-weight: 700; color: var(--avo-text-muted); font-size: .9rem; }
    details.tf-cust .grid { margin-top: 8px; }
    .tf-cta { width: 100%; padding: 18px; font-size: 1.2rem; font-weight: 800; border-radius: var(--avo-radius-lg); border: 0; background: var(--avo-primary); color: var(--avo-primary-on); cursor: pointer; }
    .tf-cta:disabled { opacity: .45; cursor: not-allowed; }
    .tf-opt { display: flex; align-items: center; gap: 8px; font-size: .85rem; color: var(--avo-text-muted); }
    .tf-reason { font-size: .8rem; color: var(--avo-warning); text-align: center; min-height: 1em; }

    /* ---- done ---- */
    .tf-done { flex: 1; display: flex; flex-direction: column; padding: 22px 18px; gap: 14px; overflow: auto; }
    .tf-done .ok { display: flex; align-items: center; gap: 10px; color: var(--avo-success); font-weight: 800; font-size: 1.3rem; font-family: var(--avo-font-display); }
    .tf-done .big { font-family: var(--avo-font-display); font-weight: 800; font-size: 2.2rem; }
    .tf-done .chg { font-size: 1.1rem; }
    .tf-done .chg b { font-size: 1.8rem; font-family: var(--avo-font-display); }
    .tf-tidlist { display: grid; gap: 4px; font-size: .9rem; max-height: 220px; overflow: auto; }
    .tf-tidlist div { display: flex; justify-content: space-between; gap: 10px; }
    .tf-done .actions { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: auto; }

    /* ---- sales view ---- */
    .tf-sales { flex: 1; padding: 18px 20px 40px; max-width: 1100px; width: 100%; margin: 0 auto; }
    .tf-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin: 14px 0 20px; }
    .tf-kpi { padding: 14px 16px; border-radius: var(--avo-radius-lg); background: var(--avo-surface); border: 1px solid var(--avo-border); }
    .tf-kpi .k { font-size: .75rem; color: var(--avo-text-muted); font-family: var(--avo-font-mono); text-transform: uppercase; letter-spacing: .1em; }
    .tf-kpi .v { font-size: 1.45rem; font-weight: 800; margin-top: 4px; white-space: nowrap; }
    .tf-search { display: flex; gap: 8px; margin-bottom: 14px; }
    .tf-search input { flex: 1; font-size: 1.05rem; padding: 12px 14px; height: auto; }
    .tf-list { display: grid; gap: 8px; }
    .tf-sale { border: 1px solid var(--avo-border); border-radius: var(--avo-radius-md); background: var(--avo-surface); overflow: hidden; }
    .tf-sale > button { width: 100%; display: grid; grid-template-columns: 64px minmax(0,1fr) auto auto; gap: 12px; align-items: center; padding: 12px 14px; background: none; border: 0; color: var(--avo-text); cursor: pointer; text-align: left; }
    .tf-sale .s-time { font-family: var(--avo-font-mono); color: var(--avo-text-muted); }
    .tf-sale .s-sum { font-weight: 800; }
    .tf-sale.void > button { opacity: .55; }
    .tf-sale.void .s-sum { text-decoration: line-through; }
    .tf-sale .s-body { border-top: 1px dashed var(--avo-border); padding: 6px 14px 10px; }
    .tf-trow { width: 100%; display: grid; grid-template-columns: minmax(0,1fr) auto auto; gap: 12px; align-items: center; padding: 8px 0; background: none; border: 0; border-bottom: 1px solid var(--avo-border); color: var(--avo-text); cursor: pointer; text-align: left; }
    .tf-trow:last-child { border-bottom: 0; }
    .tf-card-list { border: 1px solid var(--avo-border); border-radius: var(--avo-radius-md); background: var(--avo-surface); padding: 4px 14px; }
    .tf-badge { display: inline-block; font-size: .72rem; font-weight: 800; padding: 2px 8px; border-radius: 999px; background: var(--avo-bg); border: 1px solid var(--avo-border); white-space: nowrap; }
    .tf-badge.ok { color: var(--avo-success); border-color: currentColor; }
    .tf-badge.err { color: var(--avo-error); border-color: currentColor; }
    .tf-badge.warn { color: var(--avo-warning); border-color: currentColor; }
    .tf-badge.info { color: var(--avo-info); border-color: currentColor; }
    .tf-h3 { font-weight: 800; font-family: var(--avo-font-display); font-size: 1.05rem; margin: 22px 0 10px; }

    /* ---- dialogs ---- */
    dialog.tf-dlg { position: fixed; inset: 0; margin: auto; border: 1px solid var(--avo-border); border-radius: var(--avo-radius-xl); background: var(--avo-surface); color: var(--avo-text); padding: 0; width: min(560px, calc(100vw - 24px)); max-height: calc(100dvh - 24px); }
    dialog.tf-dlg::backdrop { background: rgba(0,0,0,.55); }
    dialog.tf-dlg .d-head { display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; border-bottom: 1px solid var(--avo-border); }
    dialog.tf-dlg .d-head h3 { font-weight: 800; font-family: var(--avo-font-display); font-size: 1.2rem; }
    dialog.tf-dlg .d-body { padding: 16px 18px; display: grid; gap: 14px; }
    .tf-x { width: 38px; height: 38px; border-radius: 10px; border: 1px solid var(--avo-border); background: var(--avo-bg); color: var(--avo-text); cursor: pointer; font-size: 1.1rem; }
    dialog#seatDlg { width: calc(100vw - 24px); max-width: 1400px; height: calc(100dvh - 24px); }
    dialog#seatDlg[open] { display: flex; flex-direction: column; }
    #seatScroll { flex: 1; overflow: auto; background: var(--avo-bg); touch-action: none; }
    .tf-kv { display: grid; grid-template-columns: 120px minmax(0,1fr); gap: 6px 12px; font-size: .95rem; }
    .tf-kv dt { color: var(--avo-text-muted); }
    .tf-kv dd { font-weight: 700; word-break: break-word; }

    /* ---- announcement from the admin: top of the sell area, tap folds it ---- */
    .tf-cast { position: sticky; top: 0; z-index: 5; width: 100%; margin: 0 0 14px; display: flex; align-items: flex-start; gap: 12px; padding: 12px 16px; border: 0; border-radius: var(--avo-radius-lg); background: var(--tf-cast-bg); color: var(--tf-cast-fg); font: inherit; font-weight: 800; font-size: 1.05rem; line-height: 1.3; text-align: left; cursor: pointer; box-shadow: 0 10px 30px rgba(0,0,0,.3); }
    .tf-cast[data-cat="info"] { --tf-cast-bg: #1d4ed8; --tf-cast-fg: #fff; }
    .tf-cast[data-cat="attention"] { --tf-cast-bg: #f5b400; --tf-cast-fg: #111; }
    .tf-cast[data-cat="alert"] { --tf-cast-bg: #c81e1e; --tf-cast-fg: #fff; }
    .tf-cast[data-cat="success"] { --tf-cast-bg: #15803d; --tf-cast-fg: #fff; }
    .tf-cast .tag { flex-shrink: 0; margin-top: 3px; font-family: var(--avo-font-mono); font-size: .68rem; letter-spacing: .12em; text-transform: uppercase; padding: 2px 8px; border: 1.5px solid currentColor; border-radius: 999px; }
    .tf-cast .txt { min-width: 0; overflow-wrap: anywhere; }
    .tf-cast[hidden] { display: none; }
    .tf-sales .tf-cast { top: 67px; }
    .tf-cast.folded { padding: 6px 12px; font-size: .9rem; }
    .tf-cast.folded .txt { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    /* ---- toast ---- */
    #tf-toast { position: fixed; top: 70px; left: 50%; transform: translateX(-50%); z-index: 60; display: flex; flex-direction: column; gap: 8px; width: min(520px, calc(100vw - 24px)); }
    .tf-toastmsg { padding: 12px 18px; border-radius: var(--avo-radius-md); color: #fff; font-weight: 700; box-shadow: 0 6px 20px rgba(0,0,0,.25); animation: avoFadeInUp .25s ease-out; }
    .tf-toastmsg.ok { background: var(--avo-success); }
    .tf-toastmsg.err { background: var(--avo-error); }

    /* ---- mobile ---- */
    .tf-mbar { display: none; }
    @media (max-width: 900px) {
        .tf-sell { grid-template-columns: 1fr; }
        .tf-cart { position: static; height: auto; border-left: 0; border-top: 1px solid var(--avo-border); }
        .tf-lines { overflow: visible; }
        .tf-products { padding: 14px 16px 18px; }
        .tf-cats { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); }
        .tf-cat { min-height: 100px; }
        .tf-cat .c-key { display: none; }
        .tf-hint { display: none; }
        .tf-mbar { display: flex; position: sticky; bottom: 0; z-index: 25; gap: 10px; align-items: center; padding: 10px 16px; background: var(--avo-surface); border-top: 1px solid var(--avo-border); }
        .tf-mbar .mb-sum { flex: 1; font-weight: 800; }
        .tf-mbar button { padding: 12px 18px; border-radius: var(--avo-radius-md); border: 0; background: var(--avo-primary); color: var(--avo-primary-on); font-weight: 800; }
        .tf-brand .t { display: none; }
        .tf-sales { padding: 14px 16px 40px; }
        .tf-sale > button { grid-template-columns: 52px minmax(0,1fr) auto; }
        .tf-sale > button .s-meth { display: none; }
    }
    @media (max-width: 520px) {
        .tf-top { gap: 8px; padding: 8px 10px; }
        .tf-tab { padding: 8px 10px; }
        .tf-userbtn span { display: none; }
    }

    /* ---- print: register report only ---- */
    #printArea { display: none; }
    @media print {
        body > *:not(#printArea) { display: none !important; }
        #printArea { display: block !important; color: #000; background: #fff; font-family: var(--avo-font-body); }
        #printArea table { width: 100%; border-collapse: collapse; margin: 8px 0 16px; }
        #printArea td, #printArea th { border-bottom: 1px solid #ccc; padding: 4px 6px; text-align: left; font-size: 11pt; }
        #printArea .r { text-align: right; }
    }
</style>

<div id="tf-toast" role="status" aria-live="polite"></div>

<header class="tf-top">
    <div class="tf-brand">
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="avo-coral" aria-hidden="true">
            <rect width="5" height="5" x="3" y="3" rx="1"/><rect width="5" height="5" x="16" y="3" rx="1"/><rect width="5" height="5" x="3" y="16" rx="1"/><path d="M21 16h-3a2 2 0 0 0-2 2v3"/><path d="M21 21v.01"/><path d="M12 7v3a2 2 0 0 1-2 2H7"/><path d="M3 12h.01"/><path d="M12 3h.01"/><path d="M12 16v.01"/><path d="M16 12h1"/><path d="M21 12v.01"/><path d="M12 21v-1"/>
        </svg>
        <span class="t">Ticket<span class="avo-hl">Flow</span></span>
    </div>
    <nav class="tf-tabs" role="tablist">
        <button class="tf-tab" role="tab" aria-selected="true" data-view="sell"><?php echo $h($L["tab_sell"]); ?></button>
        <button class="tf-tab" role="tab" aria-selected="false" data-view="sales"><?php echo $h($L["tab_sales"]); ?></button>
    </nav>
    <div class="tf-spacer"></div>
    <button type="button" class="tf-pairbtn" id="pairBtn" aria-haspopup="dialog">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="14" height="20" x="5" y="2" rx="2"/><path d="M12 18h.01"/></svg>
        <span id="pairBtnLabel"><?php echo $h($L["pair"]); ?></span>
        <span class="dot" id="pairBtnDot" hidden></span>
    </button>
    <button type="button" class="avo-theme-toggle" data-avo-theme-toggle aria-label="Theme">
        <svg class="icon-moon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
        <svg class="icon-sun" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
    </button>
    <div class="tf-user">
        <button type="button" class="tf-userbtn" id="userBtn" aria-haspopup="true" aria-expanded="false">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
            <span><?php echo $h($username); ?></span>
        </button>
        <div class="tf-menu" id="userMenu" hidden>
            <div class="lbl"><?php echo $h($L["language"]); ?></div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrfToken); ?>">
                <button name="language" value="de" class="<?php echo $lang_code === "de" ? "cur" : ""; ?>">🇩🇪 Deutsch</button>
                <button name="language" value="en" class="<?php echo $lang_code === "en" ? "cur" : ""; ?>">🇬🇧 English</button>
            </form>
            <div class="sep"></div>
            <a href="../apps.php"><?php echo $h($L["switch_app"]); ?></a>
            <a href="../logout.php" style="color: var(--avo-error);"><?php echo $h($L["logout"]); ?></a>
        </div>
    </div>
</header>

<!-- ======================= SELL ======================= -->
<main class="tf-sell" id="view-sell">
    <section class="tf-products">
        <button type="button" class="tf-cast" id="tfCast" hidden aria-live="assertive"><span class="tag" id="tfCastTag"></span><span class="txt" id="tfCastText"></span></button>
        <?php if (empty($dateInfo["dates"])): ?>
            <div class="tf-empty"><?php echo $h($L["no_dates"]); ?></div>
        <?php endif; ?>
        <div class="tf-dates" id="dateBar"></div>
        <div class="tf-cats" id="catGrid"></div>
        <div class="tf-seatbox" id="seatBox" hidden>
            <div class="sb-info">
                <div class="avo-muted text-sm"><?php echo $h($L["seats"]); ?> · <span id="seatMode"></span></div>
                <div class="sb-labels" id="seatLabels"></div>
            </div>
            <button type="button" class="btn-secondary" id="seatOpen"><?php echo $h($L["seats_change"]); ?></button>
        </div>
        <button type="button" class="tf-linkbtn" id="specialOpen"><?php echo $h($L["special"]); ?></button>
        <div class="tf-hint"><?php echo $h($L["shortcuts"]); ?></div>
    </section>

    <aside class="tf-cart" id="cart">
        <div id="cartView" style="display:flex;flex-direction:column;flex:1;min-height:0;">
            <div class="tf-cart-head">
                <h2><?php echo $h($L["receipt"]); ?></h2>
                <button type="button" class="btn-secondary" id="cartClear" style="padding:.35rem .8rem;"><?php echo $h($L["clear"]); ?></button>
            </div>
            <div class="tf-lines" id="cartLines"></div>
            <div class="tf-pay">
                <div class="tf-total"><span class="avo-muted"><?php echo $h($L["total"]); ?></span><b class="tf-num" id="cartTotal">0,00 €</b></div>
                <div class="tf-seg" id="methodSeg">
                    <button type="button" data-method="bar" class="active"><?php echo $h($L["cash"]); ?></button>
                    <button type="button" data-method="card"><?php echo $h($L["card"]); ?></button>
                </div>
                <div id="cashBox" class="grid gap-2">
                    <div class="tf-given" id="givenBtns"></div>
                    <div class="tf-change" id="changeBox" hidden><span id="changeLbl"></span><b class="tf-num" id="changeVal"></b></div>
                </div>
                <details class="tf-cust" id="custBox">
                    <summary>+ <?php echo $h($L["customer"]); ?></summary>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="text" id="cFirst" class="input" placeholder="<?php echo $h($L["first_name"]); ?>" autocomplete="off">
                        <input type="text" id="cLast" class="input" placeholder="<?php echo $h($L["last_name"]); ?>" autocomplete="off">
                        <input type="email" id="cEmail" class="input col-span-2" placeholder="<?php echo $h($L["email"]); ?>" autocomplete="off">
                    </div>
                    <p class="avo-muted text-xs mt-1"><?php echo $h($L["email_hint"]); ?></p>
                </details>
                <button type="button" class="tf-cta" id="chargeBtn" disabled></button>
                <div class="tf-reason" id="chargeReason"></div>
                <label class="tf-opt"><input type="checkbox" id="autoPrint" checked> <?php echo $h($L["autoprint"]); ?></label>
            </div>
        </div>

        <div class="tf-done" id="doneView" hidden>
            <div class="ok">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                <span id="doneTitle"></span>
            </div>
            <div class="big tf-num" id="doneTotal"></div>
            <div class="chg" id="doneChange" hidden><?php echo $h($L["change"]); ?>: <b class="tf-num" id="doneChangeVal"></b></div>
            <div class="tf-tidlist tf-mono" id="doneTids"></div>
            <div class="actions">
                <button type="button" class="btn-secondary" id="donePrint"><?php echo $h($L["print_again"]); ?></button>
                <button type="button" class="btn-destructive" id="doneUndo"><?php echo $h($L["undo"]); ?></button>
                <button type="button" class="tf-cta" id="doneNext" style="grid-column: 1 / -1;"><?php echo $h($L["next_sale"]); ?></button>
            </div>
        </div>
    </aside>
</main>

<div class="tf-mbar" id="mbar">
    <div class="mb-sum tf-num" id="mbarSum"></div>
    <button type="button" id="mbarGo"><?php echo $h($L["charge"]); ?> ↓</button>
</div>

<!-- ======================= SALES ======================= -->
<main class="tf-sales" id="view-sales" hidden>
    <form class="tf-search" id="searchForm" autocomplete="off">
        <input type="search" class="input" id="searchInput" placeholder="<?php echo $h($L["search_ph"]); ?>">
        <button type="submit" class="btn-primary"><?php echo $h($L["search"]); ?></button>
    </form>
    <div id="searchResults" hidden></div>

    <div class="flex flex-wrap items-center gap-3 justify-between" style="margin-top: 10px;">
        <h2 class="text-2xl font-bold"><?php echo $h($L["sales_today"]); ?></h2>
        <div class="flex flex-wrap gap-2 items-center">
            <?php if ($isAdmin): ?>
            <div class="tf-seg" id="scopeSeg" style="grid-template-columns: auto auto;">
                <button type="button" data-all="0" class="active" style="padding:8px 12px;"><?php echo $h($L["mine"]); ?></button>
                <button type="button" data-all="1" style="padding:8px 12px;"><?php echo $h($L["all"]); ?></button>
            </div>
            <?php endif; ?>
            <button type="button" class="btn-secondary" id="reportBtn"><?php echo $h($L["close_register"]); ?></button>
        </div>
    </div>
    <div class="tf-kpis" id="kpis"></div>
    <div class="tf-list" id="salesList"></div>
</main>

<!-- seat map -->
<dialog class="tf-dlg" id="seatDlg">
    <div class="d-head" style="flex-wrap: wrap; gap: 8px;">
        <h3><?php echo $h($L["seats_pick"]); ?> <span class="avo-muted tf-num" id="seatCount" style="font-size: 1rem;"></span></h3>
        <div class="flex gap-2 flex-wrap">
            <button type="button" class="btn-secondary" id="seatAuto"><?php echo $h($L["seat_auto"]); ?></button>
            <button type="button" class="btn-secondary" id="zoomOut" aria-label="Zoom out">−</button>
            <button type="button" class="btn-secondary" id="zoomIn" aria-label="Zoom in">+</button>
            <button type="button" class="btn-secondary" id="zoomFit"><?php echo $h($L["seat_fit"]); ?></button>
            <button type="button" class="btn-primary" id="seatDone"><?php echo $h($L["done"]); ?></button>
        </div>
    </div>
    <div id="seatLegend" class="flex flex-wrap gap-x-4 gap-y-1 text-xs px-4 py-2 avo-muted"></div>
    <div id="seatScroll"><svg id="seatSvg" xmlns="http://www.w3.org/2000/svg" style="display:block;"></svg></div>
    <div id="seatInfo" class="px-4 py-3 text-sm font-semibold" style="border-top: 1px solid var(--avo-border);"></div>
</dialog>

<!-- special ticket -->
<dialog class="tf-dlg" id="specialDlg">
    <div class="d-head"><h3><?php echo $h($L["special_title"]); ?></h3><button type="button" class="tf-x" data-close>✕</button></div>
    <div class="d-body">
        <p class="avo-muted text-sm"><?php echo $h($L["special_hint"]); ?></p>
        <div class="tf-seg" id="spType">
            <button type="button" data-type="vip" class="active">VIP</button>
            <button type="button" data-type="admin">Admin</button>
        </div>
        <label class="grid gap-1"><span class="text-sm avo-muted"><?php echo $h($L["qty"]); ?></span>
            <input type="number" id="spQty" class="input" value="1" min="1" max="50" inputmode="numeric"></label>
        <div class="grid grid-cols-2 gap-2">
            <input type="text" id="spFirst" class="input" placeholder="<?php echo $h($L["first_name"]); ?>">
            <input type="text" id="spLast" class="input" placeholder="<?php echo $h($L["last_name"]); ?>">
        </div>
        <button type="button" class="tf-cta" id="spGo"><?php echo $h($L["create_print"]); ?></button>
    </div>
</dialog>

<!-- register scanner pairing -->
<dialog class="tf-dlg" id="pairDlg">
    <div class="d-head"><h3><?php echo $h($L["pair_title"]); ?></h3><button type="button" class="tf-x" data-close>✕</button></div>
    <div class="d-body" style="display:grid;gap:16px">
        <p style="margin:0;color:var(--avo-text-muted);line-height:1.5"><?php echo $h($L["pair_hint"]); ?></p>
        <div class="tf-paircode" id="pairCode">····</div>
        <div class="tf-pairstate" id="pairState"><span class="dot"></span><span id="pairStateText"><?php echo $h($L["pair_wait"]); ?></span></div>
        <button type="button" class="btn-outline" id="pairEnd"><?php echo $h($L["pair_end"]); ?></button>
    </div>
</dialog>

<!-- ticket detail -->
<dialog class="tf-dlg" id="ticketDlg">
    <div class="d-head"><h3 class="tf-mono" id="tdTitle"></h3><button type="button" class="tf-x" data-close>✕</button></div>
    <div class="d-body" id="tdBody"></div>
</dialog>

<div id="printArea"></div>

<script>
const TF = <?php echo json_encode([
    "lang"       => $lang_code,
    "csrf"       => $csrfToken,
    "isAdmin"    => $isAdmin,
    "user"       => $username,
    "today"      => $dateInfo["today"],
    "dates"      => $dateInfo["dates"],
    "categories" => $categories,
    "L"          => $L,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const L = TF.L;
const $ = id => document.getElementById(id);
const esc = s => String(s ?? "").replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
const round2 = n => Math.round(n * 100) / 100;
const money = n => (Number(n) || 0).toLocaleString(TF.lang === "de" ? "de-DE" : "en-GB", { style: "currency", currency: "EUR" });
const store = {
    get(k, d) { try { const v = localStorage.getItem("tf-" + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
    set(k, v) { try { localStorage.setItem("tf-" + k, JSON.stringify(v)); } catch (e) {} },
};

function toast(msg, kind = "ok") {
    const el = document.createElement("div");
    el.className = "tf-toastmsg " + kind;
    el.textContent = msg;
    $("tf-toast").appendChild(el);
    setTimeout(() => el.remove(), kind === "err" ? 6000 : 3000);
}

async function api(action, data = {}) {
    const body = new URLSearchParams({ ajax: action, data: JSON.stringify(data) });
    let res;
    try {
        res = await fetch("", { method: "POST", headers: { "X-CSRF-Token": TF.csrf }, body });
    } catch (e) { throw new Error(L.err_net); }
    if (res.status === 403) throw new Error(L.err_csrf);
    try { return await res.json(); } catch (e) { throw new Error(L.err_net); }
}

function fmtDate(iso, withWeekday = true) {
    if (!iso || iso === "Unlimited") return L.unlimited;
    const d = new Date(iso + "T12:00:00");
    if (isNaN(d)) return iso;
    const loc = TF.lang === "de" ? "de-DE" : "en-GB";
    return d.toLocaleDateString(loc, withWeekday ? { weekday: "short", day: "2-digit", month: "2-digit" } : { day: "2-digit", month: "2-digit", year: "numeric" });
}
const fmtTime = iso => (iso || "").slice(11, 16);
const methodLabel = m => L["method_" + m] || m || "–";

// print one or many tickets as a single same-origin PDF, straight to the
// print dialog via a hidden iframe (no new tabs, all pages in one job).
function printTickets(tids) {
    if (!tids || !tids.length) return;
    const src = "?print=" + encodeURIComponent(tids.join(","));
    const old = $("tf-printframe");
    if (old) old.remove();
    const f = document.createElement("iframe");
    f.id = "tf-printframe";
    f.style.cssText = "position:fixed;right:0;bottom:0;width:0;height:0;border:0;";
    f.src = src;
    f.onload = () => {
        try { f.contentWindow.focus(); f.contentWindow.print(); }
        catch (e) { window.open(src, "_blank"); }
    };
    document.body.appendChild(f);
}

/* =====================================================================
   State
   ===================================================================== */
const CATS = [{ id: "normal", name: L.normal, mode: null, value: 0 }].concat(TF.categories || []);
const state = {
    dates: TF.dates || [],
    date: null,               // current date object
    cart: [],                 // [{id, qty}] in insertion order
    method: store.get("method", "bar"),
    given: null,              // cash handed over (null = not entered)
    seats: [],                // [{id, label, price}] in pick order
    manualSeats: false,
    seatData: null,
    busy: false,
    lastSale: null,
    view: "sell",
};
$("autoPrint").checked = store.get("autoprint", true);
$("autoPrint").addEventListener("change", e => { store.set("autoprint", e.target.checked); renderCart(); });

function catPrice(base, cat) {
    if (!cat || !cat.mode) return round2(base);
    const v = Number(cat.value) || 0;
    if (cat.mode === "fixed") return round2(v);
    if (cat.mode === "minus") return round2(Math.max(0, base - v));
    return round2(base * (1 - v / 100));
}
function catRule(cat) {
    if (!cat.mode) return L.seat_price;
    const v = Number(cat.value) || 0;
    if (cat.mode === "fixed") return money(v);
    if (cat.mode === "minus") return "− " + money(v);
    return "− " + v.toLocaleString() + " %";
}
const catById = id => CATS.find(c => c.id === id);
const cartCount = () => state.cart.reduce((a, l) => a + l.qty, 0);
const isSeated = () => !!(state.date && state.date.seating);

/* Per-ticket prices in cart order; seated dates pair tickets with the picked
   seats in pick order (the backend applies the same pairing). */
function ticketPrices() {
    const out = [];
    let i = 0;
    state.cart.forEach(l => {
        const cat = catById(l.id);
        for (let k = 0; k < l.qty; k++, i++) {
            const base = isSeated() && state.seats[i] ? state.seats[i].price : (state.date ? state.date.price : 0);
            out.push({ line: l.id, price: catPrice(base, cat) });
        }
    });
    return out;
}
const cartTotal = () => round2(ticketPrices().reduce((a, t) => a + t.price, 0));

/* =====================================================================
   Dates
   ===================================================================== */
function renderDates() {
    const bar = $("dateBar");
    bar.hidden = state.dates.length <= 1 && !!state.date;
    bar.innerHTML = "";
    state.dates.forEach(d => {
        const b = document.createElement("button");
        b.type = "button";
        b.className = "tf-date" + (state.date && state.date.date === d.date && state.date.time === d.time ? " active" : "");
        const isToday = d.date === TF.today;
        const availTxt = d.avail <= 0 ? '<span class="out">' + esc(L.sold_out) + "</span>"
            : '<span class="' + (d.avail <= 10 ? "low" : "") + '">' + d.avail + " " + esc(L.left) + "</span>";
        b.innerHTML = '<div class="d1">' + (isToday ? esc(L.today) + " · " : "") + esc(fmtDate(d.date)) + "</div>" +
            '<div class="d2">' + esc(d.time) + " · " + availTxt + "</div>";
        b.addEventListener("click", () => selectDate(d));
        bar.appendChild(b);
    });
}

function selectDate(d) {
    const changed = !state.date || state.date.date !== d.date;
    state.date = d;
    if (changed) { state.seats = []; state.manualSeats = false; state.seatData = null; }
    renderDates();
    renderCats();
    clampCart();
    if (isSeated() && changed) loadSeats();
    renderAll();
}

async function refreshDates() {
    try {
        const r = await api("dates");
        if (r.status !== "success") return;
        state.dates = r.dates || [];
        if (state.date) {
            const same = state.dates.find(d => d.date === state.date.date && d.time === state.date.time);
            if (same) state.date = same;
        }
        renderDates(); renderCats(); renderAll();
    } catch (e) { /* offline: keep the last known values */ }
}

/* =====================================================================
   Category tiles
   ===================================================================== */
function available() {
    if (!state.date) return 0;
    if (isSeated()) return state.seatData && state.seatData.free != null ? state.seatData.free : state.date.avail;
    return state.date.avail;
}
function renderCats() {
    const grid = $("catGrid");
    grid.innerHTML = "";
    if (!state.date) return;
    const full = cartCount() >= available();
    CATS.forEach((c, i) => {
        const line = state.cart.find(l => l.id === c.id);
        const b = document.createElement("button");
        b.type = "button";
        b.className = "tf-cat";
        b.disabled = full;
        const rule = isSeated() && c.mode !== "fixed";
        const price = rule ? catRule(c) : money(catPrice(state.date.price, c));
        b.innerHTML = '<div class="c-name">' + esc(c.name) + "</div>" +
            (i < 9 ? '<div class="c-key">' + (i + 1) + "</div>" : "") +
            '<div class="c-price tf-num' + (rule ? " rule" : "") + '">' + esc(price) + "</div>" +
            (line ? '<div class="c-badge">' + line.qty + "</div>" : "");
        b.addEventListener("click", () => addToCart(c.id, 1));
        grid.appendChild(b);
    });
}

/* =====================================================================
   Cart
   ===================================================================== */
function startNewSaleIfDone() {
    if (!$("doneView").hidden) showCart();
}
function addToCart(id, delta) {
    startNewSaleIfDone();
    if (!state.date) return;
    if (delta > 0 && cartCount() >= available()) { toast(L.err_sold_out, "err"); return; }
    let line = state.cart.find(l => l.id === id);
    if (!line) { if (delta <= 0) return; line = { id, qty: 0 }; state.cart.push(line); }
    line.qty += delta;
    state.cart = state.cart.filter(l => l.qty > 0);
    state.given = null;
    onCountChanged();
}
function removeLast() {
    const l = state.cart[state.cart.length - 1];
    if (l) addToCart(l.id, -1);
}
function clearCart() {
    state.cart = []; state.given = null;
    state.manualSeats = false;
    ["cFirst", "cLast", "cEmail"].forEach(id => $(id).value = "");
    $("custBox").open = false;
    onCountChanged();
}
function clampCart() {
    let over = cartCount() - available();
    for (let i = state.cart.length - 1; i >= 0 && over > 0; i--) {
        const take = Math.min(over, state.cart[i].qty);
        state.cart[i].qty -= take; over -= take;
    }
    state.cart = state.cart.filter(l => l.qty > 0);
}
function onCountChanged() {
    if (isSeated() && state.seatData && !state.manualSeats) autoPickSeats();
    renderAll();
}

function renderCart() {
    const box = $("cartLines");
    if (!state.cart.length) {
        box.innerHTML = '<div class="tf-empty">' + esc(state.date ? L.empty_cart : L.no_dates) + "</div>";
    } else {
        const prices = ticketPrices();
        box.innerHTML = "";
        state.cart.forEach(l => {
            const cat = catById(l.id);
            const mine = prices.filter(p => p.line === l.id).map(p => p.price);
            const sum = round2(mine.reduce((a, b) => a + b, 0));
            const uniform = mine.every(p => p === mine[0]);
            const row = document.createElement("div");
            row.className = "tf-line";
            row.innerHTML = '<div><div class="l-name">' + esc(cat ? cat.name : l.id) + '</div><div class="l-sub tf-num">' +
                (uniform ? l.qty + " × " + esc(money(mine[0])) : esc(L.seat_price)) + "</div></div>" +
                '<div class="tf-qty"><button type="button" data-d="-1" aria-label="−">−</button><span class="tf-num">' + l.qty +
                '</span><button type="button" data-d="1" aria-label="+">+</button></div>' +
                '<div class="l-sum tf-num">' + esc(money(sum)) + "</div>";
            row.querySelectorAll("button").forEach(b => b.addEventListener("click", () => addToCart(l.id, parseInt(b.dataset.d))));
            box.appendChild(row);
        });
    }

    const total = cartTotal();
    $("cartTotal").textContent = money(total);
    document.querySelectorAll("#methodSeg button").forEach(b => b.classList.toggle("active", b.dataset.method === state.method));
    $("methodSeg").hidden = total <= 0;
    $("cashBox").hidden = total <= 0 || state.method !== "bar";
    renderGiven(total);

    // CTA + reason why it is blocked
    const n = cartCount();
    let reason = "";
    if (n && isSeated()) {
        if (!state.seatData) reason = L.seat_loading;
        else if (state.seats.length !== n) reason = L.seats_mismatch + " (" + state.seats.length + "/" + n + ")";
    }
    const btn = $("chargeBtn");
    btn.disabled = !n || !!reason || state.busy;
    btn.textContent = state.busy ? L.selling
        : !n ? L.charge
        : (total > 0 ? L.charge + " " + money(total) : L.issue) + ($("autoPrint").checked ? " " + L.and_print : "");
    $("chargeReason").textContent = reason;
    $("mbarSum").textContent = n ? n + " " + L.tickets + " · " + money(total) : "";
    $("mbar").hidden = !n || state.view !== "sell";
}

function renderGiven(total) {
    const box = $("givenBtns");
    box.innerHTML = '<span class="text-sm avo-muted">' + esc(L.given) + "</span>";
    if (total <= 0) return;
    const opts = [total];
    [5, 10, 20, 50, 100, 200].forEach(step => {
        const v = Math.ceil(total / step) * step;
        if (v > total && !opts.includes(v)) opts.push(v);
    });
    opts.slice(0, 4).forEach((v, i) => {
        const b = document.createElement("button");
        b.type = "button";
        b.className = "tf-num" + (state.given === v ? " active" : "");
        b.textContent = i === 0 ? L.exact : money(v).replace(/,00|\.00/, "");
        b.addEventListener("click", () => { state.given = v; $("givenInput").value = ""; renderAll(); });
        box.appendChild(b);
    });
    const inp = document.createElement("input");
    inp.type = "number"; inp.min = "0"; inp.step = "0.01"; inp.inputMode = "decimal";
    inp.className = "input"; inp.id = "givenInput"; inp.placeholder = "€";
    if (state.given !== null && !opts.slice(0, 4).includes(state.given)) inp.value = state.given;
    inp.addEventListener("input", () => {
        state.given = inp.value === "" ? null : parseFloat(inp.value.replace(",", ".")) || 0;
        renderChange(total);
    });
    box.appendChild(inp);
    renderChange(total);
}
function renderChange(total) {
    const cb = $("changeBox");
    if (state.given === null || state.method !== "bar" || total <= 0) { cb.hidden = true; return; }
    const diff = round2(state.given - total);
    cb.hidden = false;
    cb.classList.toggle("neg", diff < 0);
    $("changeLbl").textContent = diff < 0 ? L.missing : L.change;
    $("changeVal").textContent = money(Math.abs(diff));
}

document.querySelectorAll("#methodSeg button").forEach(b => b.addEventListener("click", () => {
    state.method = b.dataset.method; store.set("method", state.method); renderAll();
}));
$("cartClear").addEventListener("click", clearCart);
$("chargeBtn").addEventListener("click", checkout);
$("mbarGo").addEventListener("click", () => $("cart").scrollIntoView({ behavior: "smooth", block: "end" }));

/* =====================================================================
   Checkout
   ===================================================================== */
async function checkout() {
    if (state.busy || !cartCount() || $("chargeBtn").disabled) return;
    const total = cartTotal();
    const given = state.method === "bar" ? state.given : null;
    const payload = {
        type: "visitor",
        valid_date: state.date.date,
        items: state.cart.map(l => ({ category: l.id, qty: l.qty })),
        seats: isSeated() ? state.seats.map(s => s.id) : [],
        method: state.method,
        first_name: $("cFirst").value.trim(),
        last_name: $("cLast").value.trim(),
        email: $("cEmail").value.trim(),
    };
    state.busy = true; renderAll();
    try {
        const r = await api("sell", payload);
        if (r.status !== "success") throw r;
        const change = given !== null && r.total > 0 ? round2(given - r.total) : null;
        state.lastSale = { ...r, change };
        if ($("autoPrint").checked) printTickets(r.tids);
        state.busy = false;
        clearCart();
        showDone();
        afterSale();
    } catch (e) {
        state.busy = false;
        if (e && e.message === "seats_taken") {
            toast(L.err_seats_taken, "err");
            state.manualSeats = false;
            await loadSeats();
        } else if (e && /Not enough/.test(e.message || "")) {
            toast(L.err_sold_out, "err");
            refreshDates();
        } else {
            toast(L.err + ": " + ((e && e.message) || "?"), "err");
        }
        renderAll();
    }
}

function afterSale() {
    salesDirty = true;
    refreshDates();
    if (isSeated()) loadSeats();
}

function showDone() {
    const s = state.lastSale;
    $("cartView").style.display = "none";
    $("doneView").hidden = false;
    $("doneTitle").textContent = L.sold + " · " + s.tids.length + " " + L.tickets + (s.total > 0 ? " · " + methodLabel(s.method) : "");
    $("doneTotal").textContent = money(s.total);
    $("doneChange").hidden = s.change === null || s.change < 0;
    $("doneChangeVal").textContent = money(s.change || 0);
    $("doneTids").innerHTML = s.tickets.map(t =>
        "<div><span>" + esc(t.tid) + "</span><span class=\"avo-muted\">" + esc(t.category || "") +
        (t.seat_label ? " · " + esc(t.seat_label) : "") + "</span></div>").join("");
    $("doneNext").focus();
    renderAll();
}
function showCart() {
    $("doneView").hidden = true;
    $("cartView").style.display = "flex";
    renderAll();
}
$("doneNext").addEventListener("click", showCart);
$("donePrint").addEventListener("click", () => state.lastSale && printTickets(state.lastSale.tids));
$("doneUndo").addEventListener("click", async () => {
    const s = state.lastSale;
    if (!s || !confirm(L.undo_confirm)) return;
    try {
        const r = await api("void", { tids: s.tids, reason: "undo" });
        if (r.status === "success") toast(L.undone);
        else toast(L.err + ": " + (r.results || []).filter(x => x.status !== "success").map(x => x.tid + " " + x.message).join(", "), "err");
    } catch (e) { toast(e.message, "err"); }
    state.lastSale = null;
    showCart();
    afterSale();
});

/* =====================================================================
   Special tickets (VIP / Admin)
   ===================================================================== */
let spType = "vip";
document.querySelectorAll("#spType button").forEach(b => b.addEventListener("click", () => {
    spType = b.dataset.type;
    document.querySelectorAll("#spType button").forEach(x => x.classList.toggle("active", x === b));
}));
$("specialOpen").addEventListener("click", () => $("specialDlg").showModal());
$("spGo").addEventListener("click", async () => {
    const qty = Math.max(1, Math.min(50, parseInt($("spQty").value) || 1));
    $("spGo").disabled = true;
    try {
        const r = await api("sell", {
            type: spType, items: [{ category: "normal", qty }], method: "bar",
            first_name: $("spFirst").value.trim(), last_name: $("spLast").value.trim(),
        });
        if (r.status !== "success") throw r;
        $("specialDlg").close();
        $("spQty").value = 1; $("spFirst").value = ""; $("spLast").value = "";
        state.lastSale = { ...r, change: null };
        printTickets(r.tids);
        showDone();
        salesDirty = true;
    } catch (e) { toast(L.err + ": " + ((e && e.message) || "?"), "err"); }
    $("spGo").disabled = false;
});

/* =====================================================================
   Reserved seating
   ===================================================================== */
const SEAT_R = 12;
let seatVB = { w: 200, h: 120 }, seatZoom = 1, seatPanMoved = false;

async function loadSeats() {
    const date = state.date && state.date.date;
    if (!date) return;
    state.seatData = null; state.seats = [];
    renderAll();
    $("seatSvg").innerHTML = '<text x="10" y="24" fill="#888" font-size="13">' + esc(L.seat_loading) + "</text>";
    let data = null;
    try { data = await api("seat_avail", { date }); } catch (e) { data = null; }
    if (!state.date || state.date.date !== date) return;   // date changed meanwhile
    if (!data || data.status !== "success" || !data.seating) {
        $("seatSvg").innerHTML = '<text x="10" y="24" fill="#e00" font-size="13">' + esc(L.seat_unavail) + "</text>";
        toast(L.seat_unavail, "err");
        renderAll();
        return;
    }
    state.seatData = data;
    renderSeatMap(data);
    clampCart();
    if (!state.manualSeats) autoPickSeats();
    renderAll();
}

function renderSeatBox() {
    const box = $("seatBox");
    box.hidden = !isSeated();
    if (box.hidden) return;
    const n = cartCount();
    $("seatMode").textContent = !state.seatData ? L.seat_loading : (state.manualSeats ? L.seats_manual : L.seats_auto);
    $("seatLabels").textContent = !n ? L.seats_none : (state.seats.map(s => s.label).join(", ") || "–");
    box.classList.toggle("warn", n > 0 && state.seats.length !== n);
    $("seatOpen").disabled = !state.seatData;
}

function renderSeatMap(data) {
    const els = data.elements || [];
    const cats = {};
    (data.categories || []).forEach(c => { cats[String(c.id)] = c; });
    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    els.forEach(e => {
        if (e.type === "seat") {
            minX = Math.min(minX, e.x - SEAT_R); minY = Math.min(minY, e.y - SEAT_R);
            maxX = Math.max(maxX, e.x + SEAT_R); maxY = Math.max(maxY, e.y + SEAT_R);
        } else {
            const w = e.w || 40, h = e.h || 40;
            minX = Math.min(minX, e.x); minY = Math.min(minY, e.y);
            maxX = Math.max(maxX, e.x + w); maxY = Math.max(maxY, e.y + h);
        }
    });
    if (!isFinite(minX)) { minX = 0; minY = 0; maxX = 200; maxY = 120; }
    const pad = 24, vbW = (maxX - minX) + pad * 2, vbH = (maxY - minY) + pad * 2;
    let out = "";
    els.forEach(e => {
        if (e.type === "seat") return;
        const w = e.w || 40, h = e.h || 40;
        const rot = e.rotation ? ` transform="rotate(${e.rotation} ${e.x + w / 2} ${e.y + h / 2})"` : "";
        if (e.type === "table") {
            out += `<ellipse cx="${e.x + w / 2}" cy="${e.y + h / 2}" rx="${w / 2}" ry="${h / 2}" fill="#8b5cf6" opacity="0.5"${rot}/>`;
        } else if (e.type === "label") {
            out += `<text x="${e.x}" y="${e.y + 14}" fill="var(--avo-text,#ccc)" font-size="14"${rot}>${esc(e.text || "")}</text>`;
        } else {
            const fill = e.type === "screen" ? "#0ea5e9" : "#6b7280";
            const lbl = e.type === "stage" ? "STAGE" : e.type === "screen" ? "SCREEN" : "";
            out += `<rect x="${e.x}" y="${e.y}" width="${w}" height="${h}" rx="4" fill="${fill}" opacity="0.55"${rot}/>`;
            if (lbl) out += `<text x="${e.x + w / 2}" y="${e.y + h / 2 + 4}" fill="#fff" font-size="11" text-anchor="middle"${rot}>${lbl}</text>`;
        }
    });
    els.forEach(e => {
        if (e.type !== "seat") return;
        const cat = e.category_id != null ? cats[String(e.category_id)] : null;
        const sold = e.status === "sold" || e.status === "held";
        const fill = sold ? "#6b7280" : (cat ? cat.color : "#3b82f6");
        const label = ((e.row || "") + (e.number != null ? e.number : "")) || "";
        const human = e.row && e.number != null ? e.row + " · " + e.number : (label || e.id);
        out += `<g class="tf-seat" data-seat="${esc(e.id)}" data-price="${e.price || 0}" data-label="${esc(human)}" data-sold="${sold ? 1 : 0}" style="cursor:${sold ? "not-allowed" : "pointer"}">`;
        out += `<circle cx="${e.x}" cy="${e.y}" r="${SEAT_R}" fill="${fill}" data-basefill="${fill}" fill-opacity="${sold ? 0.4 : 1}" stroke="#0008" stroke-width="1"/>`;
        if (label) out += `<text x="${e.x}" y="${e.y + 3}" fill="#fff" font-size="9" text-anchor="middle" pointer-events="none">${esc(label)}</text>`;
        out += `</g>`;
    });
    const svg = $("seatSvg");
    svg.setAttribute("viewBox", `${minX - pad} ${minY - pad} ${vbW} ${vbH}`);
    svg.innerHTML = out;
    seatVB = { w: vbW, h: vbH };
    svg.querySelectorAll(".tf-seat").forEach(g => {
        if (g.getAttribute("data-sold") === "1") return;
        g.addEventListener("click", () => { if (!seatPanMoved) toggleSeat(g); });
    });
    let leg = "";
    (data.categories || []).forEach(c => {
        leg += `<span class="inline-flex items-center gap-1"><span style="width:.8rem;height:.8rem;border-radius:50%;background:${esc(c.color)};display:inline-block;"></span>${esc(c.name)} · ${esc(money(c.price))}</span>`;
    });
    if (!(data.categories || []).length) leg += `<span class="inline-flex items-center gap-1"><span style="width:.8rem;height:.8rem;border-radius:50%;background:#3b82f6;display:inline-block;"></span>${esc(money(data.base_price || 0))}</span>`;
    leg += `<span class="inline-flex items-center gap-1"><span style="width:.8rem;height:.8rem;border-radius:50%;background:#6b7280;display:inline-block;"></span>${esc(L.seat_sold)}</span>`;
    $("seatLegend").innerHTML = leg;
    state.seats.forEach(s => { const g = seatNode(s.id); if (g) markSeat(g, true); });
}

function applySeatZoom() {
    const svg = $("seatSvg");
    svg.style.width = (seatVB.w * seatZoom) + "px";
    svg.style.height = (seatVB.h * seatZoom) + "px";
}
function fitSeatZoom() {
    const box = $("seatScroll");
    const cw = (box.clientWidth || 800) - 2, ch = (box.clientHeight || 500) - 2;
    seatZoom = Math.max(0.1, Math.min(cw / seatVB.w, ch / seatVB.h));
    applySeatZoom();
}
function zoomSeatBy(f) { seatZoom = Math.max(0.25, Math.min(4, seatZoom * f)); applySeatZoom(); }
$("zoomIn").addEventListener("click", () => zoomSeatBy(1.3));
$("zoomOut").addEventListener("click", () => zoomSeatBy(1 / 1.3));
$("zoomFit").addEventListener("click", fitSeatZoom);
(function initPan() {
    const box = $("seatScroll");
    let dragging = false, sx = 0, sy = 0, sl = 0, st = 0;
    box.addEventListener("pointerdown", e => {
        if (e.pointerType === "mouse" && e.button !== 0) return;
        dragging = true; seatPanMoved = false;
        sx = e.clientX; sy = e.clientY; sl = box.scrollLeft; st = box.scrollTop;
    });
    box.addEventListener("pointermove", e => {
        if (!dragging) return;
        const dx = e.clientX - sx, dy = e.clientY - sy;
        if (!seatPanMoved && Math.abs(dx) + Math.abs(dy) > 6) seatPanMoved = true;
        if (seatPanMoved) { box.scrollLeft = sl - dx; box.scrollTop = st - dy; box.style.cursor = "grabbing"; }
    });
    const end = () => { dragging = false; box.style.cursor = ""; setTimeout(() => { seatPanMoved = false; }, 0); };
    box.addEventListener("pointerup", end);
    box.addEventListener("pointercancel", end);
    box.addEventListener("pointerleave", () => { dragging = false; box.style.cursor = ""; });
})();

const seatNode = id => document.querySelector('.tf-seat[data-seat="' + (window.CSS && CSS.escape ? CSS.escape(id) : id) + '"]');
function markSeat(g, on) {
    const c = g.querySelector("circle"); if (!c) return;
    if (on) { c.setAttribute("fill", "var(--avo-success,#46A758)"); c.setAttribute("stroke", "#fff"); c.setAttribute("stroke-width", "3"); g.setAttribute("data-picked", "1"); }
    else { c.setAttribute("fill", c.getAttribute("data-basefill") || "#3b82f6"); c.setAttribute("stroke", "#0008"); c.setAttribute("stroke-width", "1"); g.removeAttribute("data-picked"); }
}
const seatObj = g => ({ id: g.getAttribute("data-seat"), label: g.getAttribute("data-label"), price: parseFloat(g.getAttribute("data-price")) || 0 });
function toggleSeat(g) {
    const id = g.getAttribute("data-seat");
    const idx = state.seats.findIndex(s => s.id === id);
    if (idx >= 0) { state.seats.splice(idx, 1); markSeat(g, false); }
    else {
        if (state.seats.length >= cartCount()) {
            // Picking beyond the cart count grows the cart with a regular ticket.
            if (cartCount() >= available()) return;
            const line = state.cart.find(l => l.id === "normal") || (state.cart.push({ id: "normal", qty: 0 }), state.cart[state.cart.length - 1]);
            line.qty++;
        }
        state.seats.push(seatObj(g));
        markSeat(g, true);
    }
    state.manualSeats = true;
    renderSeatInfo();
    renderAll();
}
function setSeatSelection(ids) {
    state.seats.forEach(s => { const g = seatNode(s.id); if (g) markSeat(g, false); });
    state.seats = [];
    ids.forEach(id => {
        const g = seatNode(id);
        if (!g || g.getAttribute("data-sold") === "1") return;
        state.seats.push(seatObj(g));
        markSeat(g, true);
    });
    renderSeatInfo();
}
function renderSeatInfo() {
    const n = cartCount();
    $("seatCount").textContent = "(" + state.seats.length + " / " + n + " " + L.chosen + ")";
    $("seatInfo").textContent = state.seats.length
        ? state.seats.map(s => s.label).join(", ") + " · " + money(cartTotal())
        : L.seats_none;
}

// auto-pick: best contiguous block, avoiding single-seat gaps, near the middle
function seatRows() {
    const seats = ((state.seatData && state.seatData.elements) || []).filter(e => e.type === "seat");
    let rows = [];
    const useRow = seats.some(s => s.row != null && String(s.row).trim() !== "");
    if (useRow) {
        const m = {};
        seats.forEach(s => { const k = String(s.row); (m[k] = m[k] || []).push(s); });
        rows = Object.keys(m).map(k => ({ seats: m[k], y: m[k][0].y }));
        rows.sort((a, b) => a.y - b.y);
    } else {
        const sorted = seats.slice().sort((a, b) => a.y - b.y);
        const tol = SEAT_R * 1.5; let cur = [], cy = null;
        sorted.forEach(s => {
            if (cy === null || Math.abs(s.y - cy) <= tol) { cur.push(s); cy = cy === null ? s.y : (cy + s.y) / 2; }
            else { rows.push({ seats: cur, y: cy }); cur = [s]; cy = s.y; }
        });
        if (cur.length) rows.push({ seats: cur, y: cy });
    }
    rows.forEach(r => r.seats.sort((a, b) => a.x - b.x));
    return rows;
}
function rowRuns(row) {
    const ss = row.seats, gaps = [];
    for (let i = 1; i < ss.length; i++) gaps.push(ss[i].x - ss[i - 1].x);
    const med = gaps.length ? gaps.slice().sort((a, b) => a - b)[Math.floor(gaps.length / 2)] : 0;
    const thr = med ? med * 1.6 : Infinity;
    const runs = []; let seg = [];
    for (let i = 0; i < ss.length; i++) { if (i > 0 && (ss[i].x - ss[i - 1].x) > thr) { runs.push(seg); seg = []; } seg.push(ss[i]); }
    if (seg.length) runs.push(seg);
    return runs;
}
const seatFree = s => s.status !== "sold" && s.status !== "held";
function autoPickSeats() {
    if (!state.seatData) return;
    const n = cartCount();
    if (!n) { setSeatSelection([]); return; }
    const rows = seatRows(), mid = (rows.length - 1) / 2;
    let best = null;
    rows.forEach((row, ri) => rowRuns(row).forEach(run => {
        const rcx = (run[0].x + run[run.length - 1].x) / 2;
        let cur = [];
        const flush = () => {
            const L2 = cur.length;
            for (let off = 0; off <= L2 - n; off++) {
                const leftRem = off, rightRem = L2 - n - off;
                let sc = 0;
                if (leftRem === 1) sc += 1000;
                if (rightRem === 1) sc += 1000;
                if (L2 === n) sc -= 200;
                const block = cur.slice(off, off + n);
                sc += Math.abs(ri - mid) * 5 + Math.abs((block[0].x + block[n - 1].x) / 2 - rcx) / 40;
                if (!best || sc < best.sc) best = { sc, ids: block.map(b => b.id) };
            }
            cur = [];
        };
        run.forEach(s => { if (seatFree(s)) cur.push(s); else flush(); });
        flush();
    }));
    if (!best) {
        // No contiguous block: take the first n free seats anywhere.
        best = { ids: (state.seatData.elements || []).filter(e => e.type === "seat" && seatFree(e)).slice(0, n).map(e => e.id) };
    }
    setSeatSelection(best.ids);
}
$("seatAuto").addEventListener("click", () => { state.manualSeats = false; autoPickSeats(); renderAll(); });
$("seatOpen").addEventListener("click", () => {
    $("seatDlg").showModal();
    renderSeatInfo();
    requestAnimationFrame(fitSeatZoom);
});
$("seatDone").addEventListener("click", () => $("seatDlg").close());

/* =====================================================================
   Sales / search / register report
   ===================================================================== */
let salesDirty = true, salesAll = false, salesTickets = [];

async function loadSales() {
    salesDirty = false;
    $("salesList").innerHTML = '<div class="tf-empty">…</div>';
    try {
        const r = await api("sales", { all: salesAll });
        if (r.status !== "success") throw r;
        salesTickets = r.tickets || [];
    } catch (e) {
        salesTickets = [];
        toast(L.err + ": " + ((e && e.message) || "?"), "err");
    }
    renderSales();
}

function summarize(tickets) {
    const active = tickets.filter(t => t.status !== "cancelled");
    const sum = m => round2(active.filter(t => t.method === m).reduce((a, t) => a + (Number(t.price) || 0), 0));
    return {
        tickets: active.filter(t => t.type === "visitor").length,
        bar: sum("bar"),
        card: sum("card"),
        free: active.filter(t => !(Number(t.price) > 0)).length,
        cancelled: tickets.length - active.length,
    };
}
function groupSales(tickets) {
    const map = new Map();
    tickets.forEach(t => { if (!map.has(t.sale_id)) map.set(t.sale_id, []); map.get(t.sale_id).push(t); });
    return [...map.values()].reverse();
}
function itemsSummary(ts) {
    const c = {};
    ts.forEach(t => { const k = t.category || L.reservation; c[k] = (c[k] || 0) + 1; });
    return Object.entries(c).map(([k, v]) => v + "× " + k).join(", ");
}

function renderSales() {
    const s = summarize(salesTickets);
    $("kpis").innerHTML = [
        [L.tickets, s.tickets], [L.cash, money(s.bar)], [L.card, money(s.card)],
        [L.free_tickets, s.free], [L.cancelled, s.cancelled],
    ].map(([k, v]) => '<div class="tf-kpi"><div class="k">' + esc(k) + '</div><div class="v tf-num">' + esc(v) + "</div></div>").join("");

    const list = $("salesList");
    const groups = groupSales(salesTickets);
    if (!groups.length) { list.innerHTML = '<div class="tf-empty">' + esc(L.no_sales) + "</div>"; return; }
    list.innerHTML = "";
    groups.forEach(ts => {
        const active = ts.filter(t => t.status !== "cancelled");
        const total = round2(ts.reduce((a, t) => a + (Number(t.price) || 0), 0));
        const el = document.createElement("div");
        el.className = "tf-sale" + (active.length ? "" : " void");
        const status = !active.length ? '<span class="tf-badge err">' + esc(L.status_cancelled) + "</span>"
            : active.length < ts.length ? '<span class="tf-badge warn">' + esc(L.partial) + "</span>" : "";
        const seats = ts.map(t => t.seat_label).filter(Boolean).join(", ");
        el.innerHTML = '<button type="button"><span class="s-time">' + esc(fmtTime(ts[0].paid_at)) + "</span>" +
            '<span><b>' + esc(itemsSummary(ts)) + "</b> " + status +
            '<div class="text-xs avo-muted">' + esc(ts[0].type !== "visitor" ? ts[0].type.toUpperCase() : fmtDate(ts[0].valid_date)) +
            (seats ? " · " + esc(seats) : "") + (salesAll && ts[0].seller ? " · " + esc(ts[0].seller) : "") + "</div></span>" +
            '<span class="s-meth"><span class="tf-badge">' + esc(methodLabel(ts[0].method)) + "</span></span>" +
            '<span class="s-sum tf-num">' + esc(money(total)) + "</span></button>" +
            '<div class="s-body" hidden></div>';
        const body = el.querySelector(".s-body");
        el.querySelector("button").addEventListener("click", () => {
            body.hidden = !body.hidden;
            if (!body.hidden && !body.childElementCount) ts.forEach(t => body.appendChild(ticketRow(t)));
        });
        list.appendChild(el);
    });
}

function statusBadge(t) {
    if (t.status === "cancelled") return '<span class="tf-badge err">' + esc(L.status_cancelled) + "</span>";
    if (t.used_at) return '<span class="tf-badge info">' + esc(L.status_used) + "</span>";
    if (!t.paid) return '<span class="tf-badge warn">' + esc(L.status_unpaid) + "</span>";
    return '<span class="tf-badge ok">' + esc(L.status_valid) + "</span>";
}
function ticketRow(t) {
    const b = document.createElement("button");
    b.type = "button";
    b.className = "tf-trow";
    const name = [t.first_name, t.last_name].filter(n => n && n !== "Unknown").join(" ");
    b.innerHTML = '<span><span class="tf-mono">' + esc(t.tid) + "</span> " +
        '<span class="text-sm avo-muted">' + esc([t.category, t.seat_label, name, fmtDate(t.valid_date)].filter(Boolean).join(" · ")) + "</span></span>" +
        statusBadge(t) + '<span class="tf-num" style="min-width:64px;text-align:right;">' + (t.price != null ? esc(money(t.price)) : "") + "</span>";
    b.addEventListener("click", () => openTicket(t.tid));
    return b;
}

let lastQuery = "";
async function runSearch(q, openExact) {
    const box = $("searchResults");
    lastQuery = q;
    if (q.length < 2) { box.hidden = true; return; }
    box.hidden = false;
    box.innerHTML = '<div class="tf-empty">…</div>';
    try {
        const r = await api("search", { q });
        const ts = r.tickets || [];
        // An exact hit (e.g. a scanned QR code) opens directly.
        const exact = openExact && ts.find(t => t.tid.toUpperCase() === q.toUpperCase());
        if (exact) { box.hidden = true; lastQuery = ""; $("searchInput").select(); openTicket(exact.tid); return; }
        if (!ts.length) { box.innerHTML = '<div class="tf-empty">' + esc(L.no_results) + "</div>"; return; }
        box.innerHTML = '<div class="tf-card-list"></div>';
        ts.forEach(t => box.firstChild.appendChild(ticketRow(t)));
    } catch (err) { box.innerHTML = '<div class="tf-empty">' + esc(err.message) + "</div>"; }
}
$("searchForm").addEventListener("submit", e => { e.preventDefault(); runSearch($("searchInput").value.trim(), true); });

document.querySelectorAll("#scopeSeg button").forEach(b => b.addEventListener("click", () => {
    salesAll = b.dataset.all === "1";
    document.querySelectorAll("#scopeSeg button").forEach(x => x.classList.toggle("active", x === b));
    loadSales();
}));

$("reportBtn").addEventListener("click", () => {
    const s = summarize(salesTickets);
    const active = salesTickets.filter(t => t.status !== "cancelled");
    const byCat = {};
    active.forEach(t => {
        const k = t.category || L.reservation;
        byCat[k] = byCat[k] || { n: 0, sum: 0 };
        byCat[k].n++; byCat[k].sum = round2(byCat[k].sum + (Number(t.price) || 0));
    });
    const now = new Date();
    $("printArea").innerHTML =
        "<h1 style='font-size:18pt;margin:0 0 4px;'>" + esc(L.report_title) + "</h1>" +
        "<div>" + esc(fmtDate(TF.today, false)) + " · " + esc(now.toLocaleTimeString().slice(0, 5)) + " · " +
        esc(L.report_by) + ": " + esc(salesAll ? L.all : TF.user) + "</div>" +
        "<table><tr><th>" + esc(L.cash) + "</th><td class='r'>" + esc(money(s.bar)) + "</td></tr>" +
        "<tr><th>" + esc(L.card) + "</th><td class='r'>" + esc(money(s.card)) + "</td></tr>" +
        "<tr><th>" + esc(L.total) + "</th><td class='r'><b>" + esc(money(round2(s.bar + s.card))) + "</b></td></tr>" +
        "<tr><th>" + esc(L.tickets) + "</th><td class='r'>" + s.tickets + "</td></tr>" +
        "<tr><th>" + esc(L.free_tickets) + "</th><td class='r'>" + s.free + "</td></tr>" +
        "<tr><th>" + esc(L.cancelled) + "</th><td class='r'>" + s.cancelled + "</td></tr></table>" +
        "<table><tr><th></th><th class='r'>#</th><th class='r'>€</th></tr>" +
        Object.entries(byCat).map(([k, v]) => "<tr><td>" + esc(k) + "</td><td class='r'>" + v.n + "</td><td class='r'>" + esc(money(v.sum)) + "</td></tr>").join("") +
        "</table><h2 style='font-size:13pt;'>" + esc(L.report_sales) + "</h2><table>" +
        groupSales(salesTickets).reverse().map(ts => {
            const act = ts.filter(t => t.status !== "cancelled");
            const tot = round2(act.reduce((a, t) => a + (Number(t.price) || 0), 0));
            return "<tr><td>" + esc(fmtTime(ts[0].paid_at)) + "</td><td>" + esc(itemsSummary(ts)) +
                (act.length < ts.length ? " (" + esc(L.status_cancelled) + ": " + (ts.length - act.length) + ")" : "") +
                "</td><td>" + esc(methodLabel(ts[0].method)) + (salesAll ? " · " + esc(ts[0].seller || "") : "") +
                "</td><td class='r'>" + esc(money(tot)) + "</td></tr>";
        }).join("") + "</table>";
    window.print();
});

/* ---- ticket detail ---- */
async function openTicket(tid) {
    const dlg = $("ticketDlg");
    $("tdTitle").textContent = tid;
    $("tdBody").innerHTML = '<div class="tf-empty">…</div>';
    if (!dlg.open) dlg.showModal();
    let t;
    try {
        const r = await api("get", { tid });
        if (r.status !== "success") throw new Error(L.no_results);
        t = r.ticket;
    } catch (e) { $("tdBody").innerHTML = '<div class="tf-empty">' + esc(e.message) + "</div>"; return; }
    renderTicket(t);
}

function renderTicket(t) {
    const name = [t.first_name, t.last_name].filter(n => n && n !== "Unknown").join(" ");
    const rows = [
        ["Status", statusBadge(t)],
        [L.date, esc(fmtDate(t.valid_date))],
        [L.type, esc(t.type || "")],
        t.category ? [L.price, esc(t.category) + (t.price != null ? " · " + esc(money(t.price)) : "")] : null,
        t.seat_label ? [L.seat, esc(t.seat_label)] : null,
        name ? [L.first_name + " / " + L.last_name, esc(name)] : null,
        t.email && t.status === "cancelled" ? [L.email, esc(t.email)] : null,
        t.method ? [L.method, esc(methodLabel(t.method))] : null,
        t.seller ? [L.seller, esc(t.seller)] : null,
        t.created_at ? [L.created, esc(fmtDate(t.created_at.slice(0, 10), false) + " " + fmtTime(t.created_at))] : null,
        t.used_at ? [L.status_used, esc(t.used_at)] : null,
    ].filter(Boolean);
    const active = t.status !== "cancelled";
    let html = '<dl class="tf-kv">' + rows.map(([k, v]) => "<dt>" + esc(k) + "</dt><dd>" + v + "</dd>").join("") + "</dl>";
    if (active && t.paid) html += '<div><button type="button" class="btn-primary" id="tdPrint">' + esc(L.print) + "</button></div>";
    if (active && !t.paid) {
        const amount = t.price != null ? money(t.price) : "";
        html += '<div class="grid gap-2"><div class="font-bold">' + esc(L.collect) + (amount ? " · " + esc(amount) : "") + "</div>" +
            '<div class="tf-seg"><button type="button" data-collect="bar">' + esc(L.cash) + '</button><button type="button" data-collect="card">' + esc(L.card) + "</button></div></div>";
    }
    if (active && TF.isAdmin) {
        const opts = ['<option value="Unlimited">' + esc(L.unlimited) + "</option>"].concat(
            state.dates.map(d => '<option value="' + esc(d.date) + '">' + esc(fmtDate(d.date) + " " + d.time) + "</option>"));
        if (t.valid_date && t.valid_date !== "Unlimited" && !state.dates.some(d => d.date === t.valid_date)) {
            opts.push('<option value="' + esc(t.valid_date) + '">' + esc(fmtDate(t.valid_date)) + "</option>");
        }
        html += '<details><summary class="cursor-pointer font-bold avo-muted">' + esc(L.edit) + '</summary><div class="grid gap-2 mt-3">' +
            '<div class="grid grid-cols-2 gap-2"><input type="text" class="input" id="tdFirst" placeholder="' + esc(L.first_name) + '"><input type="text" class="input" id="tdLast" placeholder="' + esc(L.last_name) + '"></div>' +
            '<div class="grid grid-cols-2 gap-2"><select class="select" id="tdDate">' + opts.join("") + '</select>' +
            '<select class="select" id="tdType"><option value="visitor">Visitor</option><option value="vip">VIP</option><option value="admin">Admin</option></select></div>' +
            '<p class="text-xs avo-muted">' + esc(t.seat_label ? L.seat + ": " + t.seat_label : "") + "</p>" +
            '<button type="button" class="btn-secondary" id="tdSave">' + esc(L.save) + "</button></div></details>";
    }
    if (active) {
        html += '<div class="grid gap-2" style="border-top:1px solid var(--avo-border);padding-top:14px;">' +
            '<div class="font-bold">' + esc(L.email) + "</div>" +
            '<form class="tf-search" id="tdMailForm" style="margin:0;" autocomplete="off">' +
            '<input type="email" class="input" id="tdMail" placeholder="' + esc(L.email) + '">' +
            '<button type="submit" class="btn-secondary" id="tdResend">' + esc(L.mail_resend) + "</button></form>" +
            '<p class="text-xs avo-muted" id="tdMailInfo"></p></div>';
    }
    if (active) {
        html += '<div class="grid gap-2" style="border-top:1px solid var(--avo-border);padding-top:14px;">' +
            '<input type="text" class="input" id="tdReason" maxlength="200" placeholder="' + esc(L.cancel_reason) + '">' +
            '<button type="button" class="btn-destructive" id="tdCancel">' + esc(L.cancel_ticket) + "</button></div>";
    }
    $("tdBody").innerHTML = html;

    $("tdPrint") && $("tdPrint").addEventListener("click", () => printTickets([t.tid]));
    if ($("tdMailForm")) {
        $("tdMail").value = t.email || "";
        const mailInfo = () => {
            const at = t.mail_sent_at || "";
            $("tdMailInfo").textContent = at
                ? L.mail_last + ": " + (at.slice(0, 10) === TF.today ? "" : fmtDate(at.slice(0, 10), false) + " ") +
                  fmtTime(at) + (t.mail_count > 1 ? " (" + t.mail_count + "×)" : "") + " · " + L.mail_hint
                : L.mail_never + " " + L.mail_hint;
        };
        mailInfo();
        $("tdMailForm").addEventListener("submit", async e => {
            e.preventDefault();
            const email = $("tdMail").value.trim();
            if (!email) { toast(L.mail_none, "err"); return; }
            const btn = $("tdResend");
            btn.disabled = true;
            try {
                const r = await api("resend", { tid: t.tid, email });
                if (r.status !== "success") {
                    const m = {
                        cooldown: L.mail_cooldown.replace("{s}", r.retry_in || 30),
                        smtp_failed: L.mail_smtp, mail_not_configured: L.mail_noconf,
                        invalid_email: L.mail_invalid, no_email: L.mail_none,
                    }[r.message];
                    throw new Error(m || r.message || "?");
                }
                toast(L.mail_sent + " " + r.sent_to);
                Object.assign(t, { email, mail_sent_at: r.mail_sent_at, mail_count: r.mail_count });
                mailInfo();
                salesDirty = true;
            } catch (e) { toast(e.message, "err"); }
            finally { btn.disabled = false; }
        });
    }
    $("tdBody").querySelectorAll("[data-collect]").forEach(b => b.addEventListener("click", async () => {
        try {
            const r = await api("collect", { tid: t.tid, method: b.dataset.collect });
            if (r.status !== "success") throw r;
            toast(L.collected);
            salesDirty = true;
            if ($("autoPrint").checked) printTickets([t.tid]);
            openTicket(t.tid);
        } catch (e) { toast(L.err + ": " + ((e && e.message) || "?"), "err"); }
    }));
    if ($("tdSave")) {
        $("tdFirst").value = t.first_name && t.first_name !== "Unknown" ? t.first_name : "";
        $("tdLast").value = t.last_name && t.last_name !== "Unknown" ? t.last_name : "";
        $("tdDate").value = t.valid_date || "Unlimited";
        $("tdType").value = t.type || "visitor";
        $("tdSave").addEventListener("click", async () => {
            try {
                const r = await api("edit", {
                    tid: t.tid, first_name: $("tdFirst").value.trim(), last_name: $("tdLast").value.trim(),
                    valid_date: $("tdDate").value, type: $("tdType").value,
                });
                if (r.status !== "success") throw r;
                toast(L.saved);
                salesDirty = true;
                openTicket(t.tid);
            } catch (e) { toast(L.err + ": " + ((e && e.message) || "?"), "err"); }
        });
    }
    $("tdCancel") && $("tdCancel").addEventListener("click", async () => {
        if (!confirm(L.cancel_confirm)) return;
        try {
            const r = await api("void", { tids: [t.tid], reason: $("tdReason").value.trim() });
            const res = (r.results || [])[0] || {};
            if (res.status !== "success") throw res;
            toast(res.message || L.cancel_ok);
            afterSale();
            openTicket(t.tid);
        } catch (e) { toast(L.err + ": " + ((e && e.message) || "?"), "err"); }
    });
}

$("ticketDlg").addEventListener("close", () => {
    if (state.view !== "sales") return;
    if (salesDirty) loadSales();
    if (lastQuery && !$("searchResults").hidden) runSearch(lastQuery, false);
});
document.querySelectorAll("dialog [data-close]").forEach(b => b.addEventListener("click", () => b.closest("dialog").close()));

/* =====================================================================
   Shell: views, menu, keyboard, refresh
   ===================================================================== */
function setView(v) {
    state.view = v;
    document.querySelectorAll(".tf-tab").forEach(t => t.setAttribute("aria-selected", String(t.dataset.view === v)));
    $("view-sell").hidden = v !== "sell";
    $("view-sales").hidden = v !== "sales";
    // The announcement banner follows the visible view.
    (v === "sales" ? $("view-sales") : document.querySelector(".tf-products")).prepend($("tfCast"));
    if (v === "sales") { if (salesDirty) loadSales(); setTimeout(() => $("searchInput").focus(), 0); }
    renderAll();
}
document.querySelectorAll(".tf-tab").forEach(t => t.addEventListener("click", () => setView(t.dataset.view)));

$("userBtn").addEventListener("click", e => {
    e.stopPropagation();
    const m = $("userMenu");
    m.hidden = !m.hidden;
    $("userBtn").setAttribute("aria-expanded", String(!m.hidden));
});
document.addEventListener("click", e => { if (!e.target.closest(".tf-user")) $("userMenu").hidden = true; });

document.addEventListener("keydown", e => {
    if (state.view !== "sell" || document.querySelector("dialog[open]")) return;
    const tag = (e.target.tagName || "").toLowerCase();
    if (tag === "input" || tag === "textarea" || tag === "select") return;
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    if (/^[1-9]$/.test(e.key) && CATS[+e.key - 1]) { e.preventDefault(); addToCart(CATS[+e.key - 1].id, 1); }
    else if (e.key === "Enter") {
        e.preventDefault();
        if (!$("doneView").hidden) showCart(); else checkout();
    }
    else if (e.key === "Escape") { if (!$("doneView").hidden) showCart(); else clearCart(); }
    else if (e.key === "Backspace" || e.key === "Delete" || e.key === "-") { e.preventDefault(); removeLast(); }
});

/* =====================================================================
   Register scanner: a phone in the handheld's "Kasse" mode joins with the
   code shown here; each ticket it scans opens in the ticket dialog, ready to
   collect. The pairing survives a reload of this tab (sessionStorage).
   ===================================================================== */
const pair = {
    code: null, secret: null, live: false, timer: null,
    load() {
        try { Object.assign(this, JSON.parse(sessionStorage.getItem("tf-pair") || "{}")); } catch (e) {}
    },
    save() {
        try {
            if (this.code) sessionStorage.setItem("tf-pair", JSON.stringify({ code: this.code, secret: this.secret }));
            else sessionStorage.removeItem("tf-pair");
        } catch (e) {}
    },
};

function renderPair() {
    const on = !!pair.code;
    $("pairBtn").classList.toggle("is-live", on && pair.live);
    $("pairBtn").classList.toggle("is-wait", on && !pair.live);
    $("pairBtnDot").hidden = !on;
    $("pairBtnLabel").textContent = on ? L.scanner + " " + pair.code : L.pair;
    $("pairCode").textContent = pair.code || "····";
    $("pairState").classList.toggle("is-live", pair.live);
    $("pairStateText").textContent = pair.live ? L.pair_ok : L.pair_wait;
}

async function pairOpen() {
    try {
        const r = await api("pair_open");
        if (r.status !== "success") throw new Error(r.message || L.err_net);
        pair.code = r.code; pair.secret = r.secret; pair.live = false;
        pair.save(); renderPair(); pairSchedule(0);
    } catch (e) { toast(e.message, "err"); }
}

function pairDrop(msg) {
    pair.code = pair.secret = null; pair.live = false;
    clearTimeout(pair.timer);
    pair.save(); renderPair();
    if (msg) toast(msg, "err");
}

function pairSchedule(ms) {
    clearTimeout(pair.timer);
    if (pair.code) pair.timer = setTimeout(pairPoll, ms);
}

async function pairPoll() {
    if (!pair.code) return;
    let r;
    try { r = await api("pair_poll", { code: pair.code, secret: pair.secret }); }
    catch (e) { pairSchedule(4000); return; }          // offline: keep trying
    if (r.message === "pair_gone") {
        // Backend restarted or the pairing timed out: open a fresh one while
        // the dialog is up, otherwise tell the cashier it ended.
        if ($("pairDlg").open) { pairDrop(); pairOpen(); } else pairDrop(L.pair_gone);
        return;
    }
    if (r.status === "success") {
        if (r.handheld !== pair.live) { pair.live = r.handheld; renderPair(); }
        const scans = r.scans || [];
        if (scans.length) {
            if ($("pairDlg").open) $("pairDlg").close();
            const tid = scans[scans.length - 1].tid;       // the latest scan wins
            toast(L.pair_scanned + " " + tid);
            openTicket(tid);
        }
    }
    pairSchedule(document.hidden ? 5000 : 1200);
}

$("pairBtn").addEventListener("click", () => {
    if (!pair.code) pairOpen();
    renderPair();
    $("pairDlg").showModal();
});
$("pairEnd").addEventListener("click", async () => {
    const { code, secret } = pair;
    pairDrop();
    $("pairDlg").close();
    if (code) { try { await api("pair_close", { code, secret }); } catch (e) {} }
});
document.addEventListener("visibilitychange", () => { if (!document.hidden) pairSchedule(0); });
pair.load(); renderPair(); pairSchedule(0);

/* =====================================================================
   Live poll: the admin's announcement as a banner, and this register's
   heartbeat for the live device list. Display only; failures are silent.
   ===================================================================== */
const cast = { id: null, timer: null };
function tfDevice() {
    let id = "";
    try { id = sessionStorage.getItem("tf-device") || ""; } catch (e) {}
    if (!/^[A-Za-z0-9_-]{8,64}$/.test(id)) {
        id = "tf" + Array.from(crypto.getRandomValues(new Uint8Array(8)), b => b.toString(16).padStart(2, "0")).join("");
        try { sessionStorage.setItem("tf-device", id); } catch (e) {}
    }
    return id;
}
function renderCast(b) {
    const el = $("tfCast");
    clearTimeout(cast.timer);
    if (!b || !L["cast_" + b.category]) { el.hidden = true; cast.id = null; return; }
    if (b.id !== cast.id) { el.classList.remove("folded"); cast.id = b.id; }
    el.dataset.cat = b.category;
    $("tfCastTag").textContent = L["cast_" + b.category];
    $("tfCastText").textContent = b.text || "";
    el.hidden = false;
    if (typeof b.expires_in === "number") cast.timer = setTimeout(() => renderCast(null), b.expires_in * 1000 + 300);
}
$("tfCast").addEventListener("click", () => $("tfCast").classList.toggle("folded"));
async function livePoll() {
    try {
        const r = await api("live", { device: tfDevice() });
        if (r && r.status === "success") renderCast(r.broadcast);
    } catch (e) { /* keep the banner; its timer still ends it on time */ }
    setTimeout(livePoll, document.hidden ? 10000 : 4000);
}
livePoll();

function renderAll() {
    renderCats();
    renderCart();
    renderSeatBox();
}

// Keep availability fresh (online sales happen in parallel). This also keeps
// the session alive while the register is open.
setInterval(() => { if (!document.hidden && !state.busy) refreshDates(); }, 60000);

// Start on today's date, else the next upcoming one.
(function init() {
    const first = state.dates.find(d => d.date === TF.today && d.avail > 0)
        || state.dates.find(d => d.avail > 0) || state.dates[0];
    if (first) selectDate(first); else renderAll();
    renderDates();
})();
</script>
</body>
</html>
