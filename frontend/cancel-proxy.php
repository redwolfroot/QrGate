<?php
/**
 * Public self-service cancellation proxy. Hides the API key from the browser.
 * POST only. Authorization is purely the per-ticket HMAC token that came in the
 * email link — the backend (/api/ticket/self-cancel) verifies it. There is no
 * admin session and no CSRF token here on purpose: knowing the secret token IS
 * the authorization, and a cancellation only ever runs on an explicit POST, so
 * an email/link prefetcher (which issues GETs) can never trigger one.
 */
require_once 'config.php';

header('Content-Type: application/json');

// GET = read-only preview for the cancel page (renders the ticket card + decides
// which state to show). Never cancels anything.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $tid = trim($_GET['tid'] ?? '');
    $token = trim($_GET['token'] ?? '');
    if ($tid === '' || $token === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing ticket or token']);
        exit;
    }
    $result = makeApiCall('/api/ticket/self-cancel/preview', 'POST', [
        'tid' => $tid,
        'token' => $token,
    ]);
    if (!is_array($result) || isset($result['error'])) {
        http_response_code(502);
        echo json_encode(['status' => 'error', 'message' => 'Service unavailable']);
        exit;
    }
    echo json_encode($result);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$tid = trim($input['tid'] ?? '');
$token = trim($input['token'] ?? '');
if ($tid === '' || $token === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing ticket or token']);
    exit;
}

$result = makeApiCall('/api/ticket/self-cancel', 'POST', [
    'tid' => $tid,
    'token' => $token,
    'reason' => 'self-service',
]);

if (!is_array($result) || isset($result['error'])) {
    http_response_code(502);
    echo json_encode(['status' => 'error', 'message' => 'Cancellation service unavailable']);
    exit;
}

echo json_encode($result);
