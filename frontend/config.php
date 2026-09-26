<?php
// Do not expose PHP errors (paths, stack traces, secrets) to visitors.
// Errors are still logged server-side via error_log().
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Harden session cookies (httponly, secure, samesite) before the session starts.
$cookieParams = [
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
];
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $cookieParams['secure'] = true;
}
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params($cookieParams);
} else {
    session_set_cookie_params(
        $cookieParams['lifetime'],
        $cookieParams['path'] . '; samesite=' . $cookieParams['samesite'],
        '',
        $cookieParams['secure'] ?? false,
        $cookieParams['httponly']
    );
}

session_start();

// Session idle/absolute timeout for authenticated (admin/ticketflow/handheld)
// sessions. Anonymous public visitors are NOT logged out mid-purchase: the
// timeout only applies once an auth flag is present in the session.
define('SESSION_IDLE_TIMEOUT', 30 * 60);      // 30 minutes of inactivity
define('SESSION_ABSOLUTE_TIMEOUT', 12 * 3600); // 12 hours hard cap

// Auth flags actually set by admin/login.php on successful login.
$_sessionIsAuthenticated = !empty($_SESSION['admin'])
    || !empty($_SESSION['ticketflow_access'])
    || !empty($_SESSION['handheld_access']);

if ($_sessionIsAuthenticated) {
    $now = time();
    $idleExpired = isset($_SESSION['last_activity'])
        && ($now - $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT;
    $absoluteExpired = isset($_SESSION['login_time'])
        && ($now - $_SESSION['login_time']) > SESSION_ABSOLUTE_TIMEOUT;

    if ($idleExpired || $absoluteExpired) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
        session_start();
        $_SESSION['error'] = 'Your session has expired. Please log in again.';
    } else {
        if (empty($_SESSION['login_time'])) {
            $_SESSION['login_time'] = $now;
        }
        $_SESSION['last_activity'] = $now;
    }
}

// env() lets Docker inject config without editing this file. Falls back to the
// hardcoded default when the env var is unset/empty (bare PHP server setups).
if (!function_exists('qrgate_env')) {
    function qrgate_env($name, $default) {
        $val = getenv($name);
        return ($val === false || $val === '') ? $default : $val;
    }
}

// API secret resolution mirrors the backend (config/conf.py):
//   1. shared key file (QRGATE_KEY_FILE) — written by the setup wizard
//   2. QRGATE_API_KEY env var
//   3. the insecure bootstrap default (must match the backend default)
if (!function_exists('qrgate_api_key')) {
    function qrgate_api_key() {
        $keyFile = getenv('QRGATE_KEY_FILE');
        if ($keyFile && is_readable($keyFile)) {
            $k = trim((string)@file_get_contents($keyFile));
            if ($k !== '') {
                return $k;
            }
        }
        return qrgate_env('QRGATE_API_KEY', 'qrgate-bootstrap-key-change-me');
    }
}
define('API_KEY', qrgate_api_key());
define('API_BASE_URL', qrgate_env('QRGATE_API_BASE_URL', 'https://qrgate-backend.example.com/'));
define('ORIGIN_URL', qrgate_env('QRGATE_ORIGIN_URL', 'https://qrgate.avocloud.net/'));

// Browser-facing base for images served by the backend (banner/logo/cast).
// API_BASE_URL points at the backend for SERVER-side calls — in the single
// container that's 127.0.0.1:1654, which a browser can't reach. So images are
// loaded SAME-ORIGIN (empty base -> "/api/image/...") and nginx proxies
// /api/image/ to the backend. Split deployments without that proxy can set
// QRGATE_PUBLIC_API_BASE to the public backend URL instead.
define('PUBLIC_API_BASE', rtrim(qrgate_env('QRGATE_PUBLIC_API_BASE', ''), '/'));

// Admin passwords - CHANGE THESE IN PRODUCTION!
define('ADMIN_PASSWORD', qrgate_env('QRGATE_ADMIN_PASSWORD', 'admin123'));
define('TICKETFLOW_PASSWORD', qrgate_env('QRGATE_TICKETFLOW_PASSWORD', 'ticketflow123'));
define('HANDHELD_PASSWORD', qrgate_env('QRGATE_HANDHELD_PASSWORD', 'handheld123'));

// CSRF Protection
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCsrfToken()) . '">';
}


function makeApiCall($endpoint, $method = 'GET', $data = null)
{
    try {
        $ch = curl_init(API_BASE_URL . $endpoint);
        if ($ch === false) {
            throw new Exception('Failed to initialize CURL');
        }

        $headers = [
            'Authorization: ' . API_KEY,
            'Content-Type: application/json'
        ];
        // Let the backend rate-limit per visitor instead of per PHP server.
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $headers[] = 'X-Forwarded-For: ' . $_SERVER['REMOTE_ADDR'];
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        // Never let a slow/hung backend freeze the PHP worker indefinitely.
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);

        if ($response === false) {
            throw new Exception('API call failed: ' . curl_error($ch));
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception('API returned error code: ' . $httpCode);
        }

        return json_decode($response, true);
    } catch (Exception $e) {
        error_log('API Error: ' . $e->getMessage());
        return ['error' => $e->getMessage()];
    }
}

/**
 * Like makeApiCall, but keeps the HTTP status and the error body, which the
 * checkout needs to tell "sold out" from "backend down".
 * Returns [int $httpCode, array|null $json].
 */
function qrgate_api($endpoint, $method = 'GET', $data = null, $timeout = 30)
{
    $ch = curl_init(API_BASE_URL . $endpoint);
    $headers = ['Authorization: ' . API_KEY, 'Content-Type: application/json'];
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $headers[] = 'X-Forwarded-For: ' . $_SERVER['REMOTE_ADDR'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data ?? new stdClass()));
    }
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        error_log('qrgate_api ' . $endpoint . ' failed: ' . $err);
        return [0, null];
    }
    $json = json_decode($body, true);
    return [$code, is_array($json) ? $json : null];
}

/**
 * Stream a file download from the backend straight to the browser without
 * buffering it in PHP (backups, CSV and PDF exports). The backend's filename
 * and type are passed through; on an error the backend's JSON message is
 * returned with the backend's status (or 502 when it is unreachable).
 */
function qrgate_stream_download($endpoint, $fallbackName, $fallbackType)
{
    // A download can take minutes; don't hold the session lock meanwhile, or
    // every other request of this admin waits for it.
    session_write_close();
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $headers = ['Authorization: ' . API_KEY];
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $headers[] = 'X-Forwarded-For: ' . $_SERVER['REMOTE_ADDR'];
    }
    $st = ['name' => $fallbackName, 'type' => $fallbackType, 'len' => null, 'code' => 0, 'started' => false, 'err' => ''];
    $start = function ($ch) use (&$st) {
        $st['code'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($st['code'] !== 200) {
            return;
        }
        $st['started'] = true;
        header('Content-Type: ' . $st['type']);
        header('Content-Disposition: attachment; filename="' . $st['name'] . '"');
        header('Cache-Control: no-store');
        header('X-Accel-Buffering: no');
        if ($st['len'] !== null) {
            header('Content-Length: ' . $st['len']);
        }
    };
    $ch = curl_init(API_BASE_URL . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$st) {
            if (preg_match('/^Content-Disposition:.*filename="?([A-Za-z0-9._-]+)"?/i', $line, $m)) {
                $st['name'] = $m[1];
            } elseif (preg_match('#^Content-Type:\s*([\w.+-]+/[\w.+-]+(?:;\s*charset=[\w-]+)?)#i', $line, $m)) {
                $st['type'] = $m[1];
            } elseif (preg_match('/^Content-Length:\s*(\d+)/i', $line, $m)) {
                $st['len'] = $m[1];
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$st, $start) {
            if (!$st['started'] && $st['code'] === 0) {
                $start($ch);
            }
            if ($st['started']) {
                echo $chunk;
                flush();
            } elseif (strlen($st['err']) < 4096) {
                $st['err'] .= $chunk;
            }
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($ch);
    if ($ok !== false && $st['code'] === 0) {
        $start($ch); // empty 200 body
    }
    curl_close($ch);
    if ($st['started']) {
        return;
    }
    $json = json_decode($st['err'], true);
    http_response_code($st['code'] >= 400 ? $st['code'] : 502);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'error' => is_array($json) ? ($json['error'] ?? null) : null,
        'message' => is_array($json) && !empty($json['message']) ? $json['message'] : 'Download failed (backend returned ' . $st['code'] . ')',
    ]);
}

/**
 * The show as the public shop may see it (no payment secrets, seated
 * availability resolved). Null if the backend is unreachable.
 */
function qrgate_public_show()
{
    [$code, $json] = qrgate_api('/api/show/public');
    if ($code !== 200 || !is_array($json) || ($json['status'] ?? '') !== 'success') {
        error_log('qrgate_public_show failed: HTTP ' . $code);
        return null;
    }
    return $json['show'];
}

function getShows()
{
    $shows = makeApiCall('/api/show/get');
    if (isset($shows['error'])) {
        // Log the technical detail (HTTP code / cURL string) server-side only.
        // Do NOT leak it to visitors and do NOT set $_SESSION['error'] here:
        // the caller surfaces a single clean friendly message for the null case.
        error_log('getShows() failed: ' . $shows['error']);
        return null;
    }
    return $shows;
}

function updateShow($data)
{

    if (isset($data['store_lock'])) {
        $data['store_lock'] = filter_var($data['store_lock'], FILTER_VALIDATE_BOOLEAN);
    }

    return makeApiCall('/api/show/edit', 'POST', $data);
}

/**
 * Reserved-seating helpers. Availability is the authoritative source for which
 * seats exist, their per-seat price (category price or the date's base price)
 * and their live status. Never trust a client-supplied seat price.
 */
function qrgate_seat_availability($validDate)
{
    return makeApiCall('/api/seatmap/availability?date=' . urlencode($validDate));
}

/**
 * Authoritative total for a seated order. Returns
 * ['ok'=>bool, 'total'=>float, 'count'=>int, 'error'=>string].
 */
function qrgate_seat_order_total($validDate, array $seatIds)
{
    $av = qrgate_seat_availability($validDate);
    if (!is_array($av) || ($av['status'] ?? '') !== 'success' || empty($av['seating'])) {
        return ['ok' => false, 'error' => 'not_seated'];
    }
    $base = (float)($av['base_price'] ?? 0);
    $priceById = [];
    $statusById = [];
    foreach (($av['elements'] ?? []) as $el) {
        if (($el['type'] ?? '') === 'seat' && isset($el['id'])) {
            $priceById[(string)$el['id']] = isset($el['price']) ? (float)$el['price'] : $base;
            $statusById[(string)$el['id']] = $el['status'] ?? 'free';
        }
    }
    $total = 0.0;
    foreach ($seatIds as $sid) {
        $sid = (string)$sid;
        if (!isset($priceById[$sid])) {
            return ['ok' => false, 'error' => 'unknown_seat'];
        }
        if (($statusById[$sid] ?? 'free') === 'sold') {
            return ['ok' => false, 'error' => 'seat_sold'];
        }
        $total += $priceById[$sid];
    }
    return ['ok' => true, 'total' => round($total, 2), 'count' => count($seatIds)];
}

/**
 * Resolve human seat labels (e.g. "Row B · 12") for a list of seat ids, in the
 * given order, from the authoritative availability data. Mirrors the backend's
 * seat_index() label rule. Unknown ids fall back to the raw id.
 */
function qrgate_seat_labels($validDate, array $seatIds)
{
    $av = qrgate_seat_availability($validDate);
    if (!is_array($av) || ($av['status'] ?? '') !== 'success') {
        return array_map('strval', $seatIds);
    }
    $byId = [];
    foreach (($av['elements'] ?? []) as $el) {
        if (($el['type'] ?? '') === 'seat' && isset($el['id'])) {
            $row = $el['row'] ?? '';
            $num = $el['number'] ?? '';
            if ($row !== '' && $num !== '') {
                $label = $row . ' · ' . $num;
            } elseif ($row !== '') {
                $label = (string)$row;
            } elseif ($num !== '') {
                $label = (string)$num;
            } else {
                $label = (string)$el['id'];
            }
            $byId[(string)$el['id']] = $label;
        }
    }
    $out = [];
    foreach ($seatIds as $sid) {
        $out[] = $byId[(string)$sid] ?? (string)$sid;
    }
    return $out;
}

/**
 * Validate + normalize a client-submitted seats payload into a flat list of
 * bounded strings, or return null if it is malformed.
 */
function qrgate_sanitize_seats($raw)
{
    if (!is_array($raw)) return null;
    $out = [];
    foreach ($raw as $s) {
        if (!is_string($s) && !is_numeric($s)) return null;
        $s = mb_substr(trim((string)$s), 0, 100);
        if ($s === '') return null;
        $out[] = $s;
    }
    return $out;
}

/**
 * First-run setup guard.
 *
 * Returns true once the backend reports the wizard as completed. The "installed"
 * state only ever flips false -> true, so we cache a positive result in the
 * session and stop polling the backend afterwards. A negative or unreachable
 * backend is NOT cached (so the guard re-checks on the next request, and a
 * temporary backend outage can never permanently lock visitors into /install).
 */
function isSetupComplete()
{
    if (!empty($_SESSION['qrgate_installed'])) {
        return true;
    }
    $resp = makeApiCall('/api/setup/status');
    if (is_array($resp) && !isset($resp['error']) && array_key_exists('installed', $resp)) {
        if ($resp['installed'] === true) {
            $_SESSION['qrgate_installed'] = true;
            return true;
        }
        return false; // backend reachable and explicitly NOT installed
    }
    // Backend unreachable / malformed answer: fail open, don't redirect.
    return true;
}

/**
 * Redirect to the setup wizard if the system is not yet installed. Called once
 * here for every page that includes config.php; the wizard page itself is
 * exempted to avoid a redirect loop.
 */
function enforceSetup()
{
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    // install.php drives the wizard; the proxies are used BY it. Let them pass.
    $exempt = ['install.php', 'api-proxy.php'];
    if (in_array($script, $exempt, true)) {
        return;
    }
    if (!isSetupComplete()) {
        header('Location: /install');
        exit;
    }
}

enforceSetup();
