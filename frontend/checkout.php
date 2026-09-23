<?php
/**
 * Public checkout endpoint for the ticket shop (JSON in, JSON out).
 *
 * The backend does the real work (see backend/assets/checkout.py): capacity
 * is claimed before any payment form is shown, and a card is only charged
 * after the tickets exist. This file is the gate in front of it:
 *
 *   - same-origin + CSRF token on every request
 *   - one open checkout per browser session; its token never leaves the
 *     server, so a client cannot act on someone else's hold
 *   - honeypot field and a minimum fill time before an order is accepted
 *   - per-session limits on top of the backend's per-IP limits
 *   - e-mail must be well-formed and its domain must accept mail
 *
 * Actions (POST body {action, ...}):
 *   seats     {date}                 seat map with live status (seated dates)
 *   start     {date, qty} | {date, seats:[...]}
 *   release   {}                     give the reserved tickets back
 *   intent    {}                     card: Stripe client secret for the hold
 *   validate  same body as complete; checks the details without ordering
 *              (run before the card is authorised)
 *   complete  {method, first_name, last_name, email, add_people, consent,
 *              lang, website}
 */
require_once 'config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function out($code, array $body)
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

function fail($message, $code = 400, array $extra = [])
{
    out($code, ['status' => 'error', 'message' => $message] + $extra);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('method_not_allowed', 405);
}

// Same-origin only. Browsers always send Origin on a cross-site POST.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
    fail('forbidden', 403);
}
if (!validateCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    fail('session_expired', 403);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    fail('bad_request');
}
$action = (string)($in['action'] ?? '');

/** Sliding-window counter kept in the session. */
function session_limit($key, $max, $window)
{
    $now = time();
    $log = array_filter($_SESSION['qg_rl'][$key] ?? [], fn($t) => $now - $t < $window);
    if (count($log) >= $max) {
        return false;
    }
    $log[] = $now;
    $_SESSION['qg_rl'][$key] = array_values($log);
    return true;
}

/** Pass a backend answer through, keeping only fields the shop uses. */
function relay(array $res)
{
    [$code, $json] = $res;
    if ($code === 0 || !is_array($json)) {
        fail('backend_unreachable', 503);
    }
    if (($json['status'] ?? '') !== 'success') {
        $keep = array_intersect_key($json, array_flip(['seats', 'available', 'limit']));
        fail((string)($json['message'] ?? 'error'), $code >= 400 ? $code : 400, $keep);
    }
    return $json;
}

function current_hold()
{
    return $_SESSION['qg_checkout'] ?? null;
}

function drop_hold()
{
    $h = current_hold();
    unset($_SESSION['qg_checkout']);
    if ($h && empty($h['done'])) {
        qrgate_api('/api/checkout/release', 'POST', ['hold_token' => $h['token']]);
    }
}

function mail_domain_ok($email)
{
    $domain = strtolower(substr(strrchr($email, '@'), 1));
    if ($domain === '' || !function_exists('checkdnsrr')) {
        return true;
    }
    $cache = $_SESSION['qg_mx'] ?? [];
    if (!array_key_exists($domain, $cache)) {
        $cache[$domain] = checkdnsrr($domain . '.', 'MX') || checkdnsrr($domain . '.', 'A');
        $_SESSION['qg_mx'] = array_slice($cache, -20, null, true);
    }
    return $cache[$domain];
}

/**
 * The buyer's details, checked the same way before the card is authorised
 * (action validate) and again when the order is placed.
 */
function checked_order(array $in)
{
    if (empty($in['consent'])) {
        fail('consent_required');
    }
    $first = mb_substr(trim((string)($in['first_name'] ?? '')), 0, 80);
    $last  = mb_substr(trim((string)($in['last_name'] ?? '')), 0, 80);
    if ($first === '' || $last === '' || preg_match('~https?://|www\.|[<>]~i', $first . ' ' . $last)) {
        fail('invalid_name');
    }
    $email = trim((string)($in['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 200) {
        fail('invalid_email');
    }
    if (!mail_domain_ok($email)) {
        fail('email_domain');
    }
    $extra = [];
    foreach ((array)($in['add_people'] ?? []) as $p) {
        if (!is_string($p)) {
            fail('invalid_names');
        }
        $extra[] = mb_substr(trim($p), 0, 80);
    }
    $method = (string)($in['method'] ?? '');
    if (!in_array($method, ['card', 'cash'], true)) {
        fail('method_not_allowed');
    }
    return [
        'method'     => $method,
        'first_name' => $first,
        'last_name'  => $last,
        'email'      => $email,
        'add_people' => array_slice($extra, 0, 9),
        'lang'       => ($in['lang'] ?? '') === 'de' ? 'de' : 'en',
    ];
}

switch ($action) {
    case 'seats': {
        $date = trim((string)($in['date'] ?? ''));
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            fail('invalid_date');
        }
        $av = relay(qrgate_api('/api/seatmap/availability?date=' . urlencode($date)));
        $elements = [];
        foreach (($av['elements'] ?? []) as $el) {
            unset($el['tid'], $el['hold_token']);
            $elements[] = $el;
        }
        $own = current_hold();
        out(200, [
            'status'     => 'success',
            'seating'    => !empty($av['seating']),
            'elements'   => $elements,
            'categories' => $av['categories'] ?? [],
            'base_price' => $av['base_price'] ?? 0,
            'free'       => $av['free'] ?? 0,
            // Seats held by this session's own checkout, so the map can show
            // them as the buyer's selection instead of as taken.
            'own_seats'  => ($own && ($own['date'] ?? '') === $date) ? ($own['seats'] ?? []) : [],
        ]);
    }

    case 'start': {
        if (!session_limit('start', 15, 600)) {
            fail('too_many_requests', 429);
        }
        $date = trim((string)($in['date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            fail('invalid_date');
        }
        $payload = ['date' => $date];
        if (isset($in['seats'])) {
            $seats = qrgate_sanitize_seats($in['seats']);
            if (!$seats || count($seats) > 10) {
                fail('invalid_seats');
            }
            $payload['seats'] = $seats;
        } else {
            $payload['qty'] = (int)($in['qty'] ?? 0);
        }
        // One checkout per session: starting again gives the old tickets back.
        drop_hold();
        $res = relay(qrgate_api('/api/checkout/start', 'POST', $payload));
        $_SESSION['qg_checkout'] = [
            'token'   => $res['hold_token'],
            'date'    => $date,
            'seats'   => $res['hold']['seats'] ?? [],
            'started' => time(),
            'done'    => false,
        ];
        out(200, ['status' => 'success', 'hold' => $res['hold'], 'methods' => $res['methods'] ?? []]);
    }

    case 'release': {
        drop_hold();
        out(200, ['status' => 'success']);
    }

    case 'intent': {
        $h = current_hold();
        if (!$h || !empty($h['done'])) {
            fail('hold_expired', 410);
        }
        if (!session_limit('intent', 12, 600)) {
            fail('too_many_requests', 429);
        }
        $res = relay(qrgate_api('/api/checkout/intent', 'POST', ['hold_token' => $h['token']]));
        out(200, ['status' => 'success', 'client_secret' => $res['client_secret'], 'hold' => $res['hold']]);
    }

    case 'validate':
    case 'complete': {
        $h = current_hold();
        if (!$h || !empty($h['done'])) {
            fail('hold_expired', 410);
        }
        // Honeypot: invisible to people, filled in by form bots.
        if (trim((string)($in['website'] ?? '')) !== '') {
            error_log('checkout: honeypot hit from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
            drop_hold();
            fail('rejected', 400);
        }
        if (!session_limit($action, $action === 'validate' ? 20 : 8, 600)) {
            fail('too_many_requests', 429);
        }
        // Nobody reads the form, types a name and an address in under 3 s.
        if (time() - (int)$h['started'] < 3) {
            fail('too_fast', 429);
        }
        $order = checked_order($in);
        if ($action === 'validate') {
            out(200, ['status' => 'success']);
        }
        $res = relay(qrgate_api('/api/checkout/complete', 'POST', ['hold_token' => $h['token']] + $order));
        $_SESSION['qg_checkout']['done'] = true;
        unset($res['status'], $res['tids'], $res['repeat']);
        out(200, ['status' => 'success', 'order' => $res]);
    }

    default:
        fail('unknown_action');
}
