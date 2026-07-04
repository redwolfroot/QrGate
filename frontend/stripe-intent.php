<?php
require_once 'config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

$tickets = intval($_POST['tickets'] ?? 1);
$validDate = $_POST['date'] ?? '';
$seated = !empty($_POST['seated']);

if ($seated) {
    // Reserved seating: the amount is the authoritative sum of the chosen
    // seats' prices — never trust a client-supplied price here.
    $seats = qrgate_sanitize_seats($_POST['seats'] ?? null);
    if (!$seats || $validDate === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid seat selection']);
        exit;
    }
    $sum = qrgate_seat_order_total($validDate, $seats);
    if (!$sum['ok'] || $sum['total'] <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Seats unavailable. Please pick again.']);
        exit;
    }
    $amountCents = (int)round($sum['total'] * 100);
} else {
    $pricePerTicket = floatval($_POST['price'] ?? 0);
    if ($pricePerTicket <= 0 || $tickets < 1) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid price or ticket count']);
        exit;
    }
    $amountCents = (int)round($pricePerTicket * $tickets * 100);
}

$stripeConfig = makeApiCall('/api/show/get/stripe');

if (isset($stripeConfig['error']) || empty($stripeConfig['secret_key'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Payment not configured. Please contact the organizer.']);
    exit;
}

$secretKey = $stripeConfig['secret_key'];

$ch = curl_init('https://api.stripe.com/v1/payment_intents');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_USERPWD        => $secretKey . ':',
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_POSTFIELDS     => http_build_query([
        'amount'                         => $amountCents,
        'currency'                       => 'eur',
        'automatic_payment_methods[enabled]' => 'true',
    ]),
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($response, true);

if ($httpCode !== 200) {
    http_response_code(500);
    echo json_encode(['error' => $data['error']['message'] ?? 'Stripe error']);
    exit;
}

echo json_encode([
    'client_secret'      => $data['client_secret'],
    'payment_intent_id'  => $data['id'],
]);
