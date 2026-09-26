<?php
/**
 * Calendar entry (.ics), served from the shop's own address.
 *
 * ?tid=…&token=…  the ticket's entry, linked from the ticket email (same
 *                 per-ticket HMAC token as ticket.php; the backend checks it).
 * ?date=YYYY-MM-DD the date's entry without ticket data, linked from the
 *                 shop's confirmation page (the browser never sees ticket ids).
 *
 * Streams the backend's /codes/ics through. Read-only, so a GET is fine.
 */
require_once 'config.php';

function ics_fail(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

$tid = trim((string)($_GET['tid'] ?? ''));
$token = trim((string)($_GET['token'] ?? ''));
$date = trim((string)($_GET['date'] ?? ''));
if ($date !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) ics_fail(400, 'Invalid date.');
    $query = 'date=' . urlencode($date);
    $name = $date . '.ics';
} else {
    if (!preg_match('/^[0-9A-Za-z-]{4,40}$/', $tid) || !preg_match('/^[0-9a-f]{16}$/', $token)) {
        ics_fail(400, 'Invalid ticket link.');
    }
    $query = 'tid=' . urlencode($tid) . '&token=' . urlencode($token);
    $name = 'Ticket-' . $tid . '.ics';
}

$ch = curl_init(rtrim(API_BASE_URL, '/') . '/codes/ics?' . $query);
$headers = [];
if (!empty($_SERVER['REMOTE_ADDR'])) {
    // The backend rate-limits /codes/ per client.
    $headers[] = 'X-Forwarded-For: ' . $_SERVER['REMOTE_ADDR'];
}
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 10,
]);
$body = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($code === 200 && $body !== false && $body !== '' && strpos($type, 'text/calendar') === 0) {
    header('Content-Type: text/calendar; charset=utf-8; method=PUBLISH');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . strlen($body));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $body;
    exit;
}

$lang = ($_SESSION['language'] ?? 'de') === 'en' ? 'en' : 'de';
if ($code === 403 || $code === 404) {
    ics_fail(404, $lang === 'de' ? 'Termin nicht gefunden.' : 'Date not found.');
}
if ($code === 429) {
    ics_fail(429, $lang === 'de' ? 'Zu viele Anfragen. Bitte gleich nochmal versuchen.' : 'Too many requests. Please try again shortly.');
}
ics_fail(502, $lang === 'de' ? 'Der Kalendereintrag kann gerade nicht geladen werden.' : 'The calendar entry cannot be loaded right now.');
