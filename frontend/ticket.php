<?php
/**
 * Public ticket PDF, served from the shop's own address.
 *
 * The "open ticket as PDF" link in the ticket email points here, so it uses
 * the shop address set in the admin (show setting "app_domain") instead of the
 * backend URL, which buyers usually cannot reach. The PDF is fetched
 * server-side from the backend's /codes/pdf and streamed through.
 *
 * Authorization is the per-ticket HMAC token from the email link; the backend
 * checks it. No session, no API key needed for that check, and nothing here
 * changes state, so a GET is fine.
 */
require_once 'config.php';

function ticket_fail(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

$tid = trim((string)($_GET['tid'] ?? ''));
$token = trim((string)($_GET['token'] ?? ''));
if (!preg_match('/^[0-9A-Za-z-]{4,40}$/', $tid) || !preg_match('/^[0-9a-f]{16}$/', $token)) {
    ticket_fail(400, 'Invalid ticket link.');
}

$ch = curl_init(rtrim(API_BASE_URL, '/') . '/codes/pdf?tid=' . urlencode($tid) . '&token=' . urlencode($token));
$headers = [];
if (!empty($_SERVER['REMOTE_ADDR'])) {
    // The backend rate-limits /codes/ per client; without this every buyer
    // would share the proxy's bucket.
    $headers[] = 'X-Forwarded-For: ' . $_SERVER['REMOTE_ADDR'];
}
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 20,
]);
$pdf = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($code === 200 && $pdf !== false && $pdf !== '' && strpos($type, 'application/pdf') === 0) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Ticket-' . $tid . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $pdf;
    exit;
}

$lang = ($_SESSION['language'] ?? 'de') === 'en' ? 'en' : 'de';
if ($code === 403 || $code === 404) {
    ticket_fail(404, $lang === 'de' ? 'Ticket nicht gefunden.' : 'Ticket not found.');
}
if ($code === 429) {
    ticket_fail(429, $lang === 'de' ? 'Zu viele Anfragen. Bitte gleich nochmal versuchen.' : 'Too many requests. Please try again shortly.');
}
ticket_fail(502, $lang === 'de' ? 'Das Ticket kann gerade nicht geladen werden.' : 'The ticket cannot be loaded right now.');
