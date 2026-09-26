<?php
/**
 * Live poll for the handheld pages (door counter, active scanners, current
 * announcement). Each page includes this after its login check and before
 * its own POST handling; scanner.js posts {"action":"live", device, name,
 * role} to the page itself, so the API key stays on the server.
 */
if (!defined('API_KEY')) {
    http_response_code(404);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hhLiveIn = json_decode(file_get_contents('php://input'), true);
    if (is_array($hhLiveIn) && ($hhLiveIn['action'] ?? '') === 'live') {
        // Release the session lock first: a poll must never hold up a scan
        // from the same browser.
        session_write_close();
        $query = http_build_query([
            'device' => substr((string) ($hhLiveIn['device'] ?? ''), 0, 64),
            'name'   => substr((string) ($hhLiveIn['name'] ?? ''), 0, 80),
            'role'   => (string) ($hhLiveIn['role'] ?? ''),
        ]);
        $headers = ['Authorization: ' . API_KEY];
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $headers[] = 'X-Forwarded-For: ' . $_SERVER['REMOTE_ADDR'];
        }
        $ch = curl_init(rtrim(API_BASE_URL, '/') . '/api/live/state?' . $query);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        http_response_code($code === 200 && $body !== false ? 200 : 502);
        echo ($code === 200 && $body !== false) ? $body : json_encode(['status' => 'error', 'message' => 'unavailable']);
        exit;
    }
}
