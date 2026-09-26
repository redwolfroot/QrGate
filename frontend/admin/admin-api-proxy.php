<?php
/**
 * Admin API Proxy - Hides the API key from client-side JavaScript
 * Requires admin session
 */
require_once '../config.php';

// Check admin authentication
if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

// Allowed endpoints for admin
$allowedEndpoints = [
    'stats' => '/api/stats',
    'checkins' => '/api/stats/checkins',
    'overview' => '/api/stats/overview',
    'show_edit' => '/api/show/edit',
    'images' => '/api/image/current',
    'cast_image' => '/api/show/cast/image/',
    'users_list' => '/api/users/list',
    'users_create' => '/api/users/create',
    'users_update' => '/api/users/update',
    'users_delete' => '/api/users/delete',
    'broadcast_send' => '/api/broadcast/send',
    'broadcast_clear' => '/api/broadcast/clear',
    'broadcast_history' => '/api/broadcast/history',
    'display_token' => '/api/live/display-token',
    'backups_list' => '/api/admin/backups',
    'backups_run' => '/api/admin/backups/run',
    'backups_delete' => '/api/admin/backups/delete',
];

$endpoint = $_GET['endpoint'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if (!isset($allowedEndpoints[$endpoint])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid endpoint']);
    exit;
}

if ($method === 'POST') {
    // CSRF protection: any POST here mutates backend state (e.g. show_edit).
    // GET requests are pure reads and stay unguarded so dashboards load.
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid or missing CSRF token']);
        exit;
    }

    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    if ($endpoint === 'broadcast_send' && is_array($data)) {
        $data['created_by'] = (string)($_SESSION['username'] ?? 'admin');
    }
    if (strpos($endpoint, 'broadcast_') === 0 || strpos($endpoint, 'backups_') === 0) {
        // Keep the backend's error code (empty_text, no_space, ...) for the UI.
        [$code, $body] = qrgate_api($allowedEndpoints[$endpoint], 'POST', $data);
        http_response_code($code ?: 502);
        echo json_encode($body ?? ['status' => 'error', 'message' => 'unavailable']);
        exit;
    }
    $result = makeApiCall($allowedEndpoints[$endpoint], 'POST', $data);
} else {
    $result = makeApiCall($allowedEndpoints[$endpoint]);
}

echo json_encode($result);
