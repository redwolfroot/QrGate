<?php
/**
 * API Proxy - Hides the API key from client-side JavaScript
 * Only allows specific safe endpoints
 */
require_once 'config.php';

header('Content-Type: application/json');

// Only allow specific safe endpoints for public access
$allowedEndpoints = [
    // NOTE: never expose /api/show/get here; it carries the Stripe secret.
    // The shop reads the sanitised /api/show/public server-side.
    'payment_methods'  => '/api/show/get/payment_methods',
    'stripe_pub_key'   => '/api/show/get/stripe_pub_key',
    // Used by the setup wizard: poll install state (e.g. while the backend
    // restarts) and generate a random secret key server-side.
    'setup_status'     => '/api/setup/status',
    'setup_genkey'     => '/api/setup/genkey',
    // Foyer screens: the running announcement (public, read-only).
    'broadcast'        => '/api/broadcast/active?target=screens',
];

$endpoint = $_GET['endpoint'] ?? '';

if (!isset($allowedEndpoints[$endpoint])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid endpoint']);
    exit;
}

$result = makeApiCall($allowedEndpoints[$endpoint]);
echo json_encode($result);
