<?php
require_once dirname(__DIR__, 2) . '/user/core/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

echo json_encode([
    'status' => 'success',
    'success' => true,
    'data' => [
        'site_name' => setting('site_name', 'Gracehubtels'),
        'support_email' => setting('site_email', 'support@gracehubtels.com'),
        'support_phone' => setting('site_phone', ''),
        'whatsapp_support' => setting('site_whatsapp_support', ''),
        'whatsapp_channel' => setting('site_whatsapp_channel', ''),
    ],
]);
