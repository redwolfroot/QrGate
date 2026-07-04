<?php
/**
 * Seat map proxy - hides the API key from the browser.
 * Requires an admin session. GET = load a location's map, POST = save it.
 */
require_once '../config.php';

if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    // Saving mutates backend state -> CSRF-guard it.
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid or missing CSRF token']);
        exit;
    }
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) || empty($data['location'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing location']);
        exit;
    }
    echo json_encode(makeApiCall('/api/seatmap/save', 'POST', $data));
    exit;
}

// GET: load one location's map.
$location = $_GET['location'] ?? '';
if ($location === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing location']);
    exit;
}
echo json_encode(makeApiCall('/api/seatmap/get?location=' . urlencode($location)));
