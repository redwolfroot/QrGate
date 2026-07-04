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
    'show' => '/api/show/get',
    'show_edit' => '/api/show/edit',
    'images' => '/api/image/current',
    'cast_image' => '/api/show/cast/image/',
    'users_list' => '/api/users/list',
    'users_create' => '/api/users/create',
    'users_update' => '/api/users/update',
    'users_delete' => '/api/users/delete',
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
    $result = makeApiCall($allowedEndpoints[$endpoint], 'POST', $data);
} else {
    $result = makeApiCall($allowedEndpoints[$endpoint]);
}

echo json_encode($result);
