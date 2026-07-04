<?php
/**
 * Public seat proxy for the buyer flow. Hides the API key. GET = availability
 * for a date; POST hold/release place & drop a checkout hold (CSRF-guarded).
 */
require_once 'config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'availability';
    $date = $_GET['date'] ?? '';
    if ($action !== 'availability' || $date === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Bad request']);
        exit;
    }
    echo json_encode(qrgate_seat_availability($date));
    exit;
}

if ($method === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
    $action = $_POST['action'] ?? '';
    $date = $_POST['date'] ?? '';
    if ($date === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing date']);
        exit;
    }

    if ($action === 'hold') {
        $seats = qrgate_sanitize_seats($_POST['seats'] ?? null);
        if (!$seats) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid seats']);
            exit;
        }
        // Cap the number of seats a single hold can grab (matches UI max).
        if (count($seats) > 10) {
            http_response_code(400);
            echo json_encode(['error' => 'Too many seats']);
            exit;
        }
        echo json_encode(makeApiCall('/api/seat/hold', 'POST', ['date' => $date, 'seats' => $seats]));
        exit;
    }

    if ($action === 'release') {
        $token = trim($_POST['hold_token'] ?? '');
        if ($token === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Missing token']);
            exit;
        }
        echo json_encode(makeApiCall('/api/seat/release', 'POST', ['date' => $date, 'hold_token' => $token]));
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
