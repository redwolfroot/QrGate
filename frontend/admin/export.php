<?php
/**
 * Streams a CSV export (tickets, access log, revenue) or the printable guest
 * list (PDF, one date) from the backend to the admin as a file download. Requires an
 * authenticated admin session; the backend API key never reaches the browser.
 * Read-only (GET), so no CSRF token is required.
 */
require_once '../config.php';

if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$kinds = [
    'tickets' => ['/api/export/tickets.csv', 'text/csv; charset=utf-8', 'csv'],
    'attempts' => ['/api/export/attempts.csv', 'text/csv; charset=utf-8', 'csv'],
    'revenue' => ['/api/export/revenue.csv', 'text/csv; charset=utf-8', 'csv'],
    'guestlist' => ['/api/export/guestlist.pdf', 'application/pdf', 'pdf'],
];
$kind = (string)($_GET['kind'] ?? '');
$date = (string)($_GET['date'] ?? '');
$validDate = $date === '' || $date === 'Unlimited' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
if ($kind === 'guestlist') {
    $validDate = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $date); // one real date
}
if (!isset($kinds[$kind]) || !$validDate) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Invalid export']);
    exit;
}

[$endpoint, $type, $ext] = $kinds[$kind];
$query = http_build_query(array_filter([
    'date' => $date,
    'include_cancelled' => !empty($_GET['include_cancelled']) ? '1' : '',
    'format' => ($_GET['format'] ?? '') === 'plain' ? 'plain' : 'excel',
], 'strlen'));
qrgate_stream_download($endpoint . '?' . $query, 'qrgate-' . $kind . '-' . date('Y-m-d') . '.' . $ext, $type);
