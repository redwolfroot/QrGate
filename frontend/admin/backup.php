<?php
/**
 * Database backup downloads for the admin. Without `name` it streams a fresh
 * snapshot of the database; with `name` one of the stored backups from the
 * backup folder (the backend checks the name against its own listing).
 * Requires an authenticated admin session; the backend API key never reaches
 * the browser (the curl call adds it server-side). Read-only (GET), so no CSRF
 * token is required.
 */
require_once '../config.php';

if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$name = (string)($_GET['name'] ?? '');
if ($name !== '') {
    qrgate_stream_download('/api/admin/backups/download?name=' . rawurlencode($name), 'qrgate-backup.db.gz', 'application/gzip');
} else {
    qrgate_stream_download('/api/admin/backup', 'qrgate-backup-' . date('Ymd-His') . '.db', 'application/x-sqlite3');
}
