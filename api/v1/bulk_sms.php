<?php
define('IS_API', true);
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 2) . '/user/core/bulk_sms.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed. Use POST.']);
    exit;
}

$user = api_auth();
$input = json_decode(file_get_contents('php://input'), true) ?? [];

if (empty($input['sender_id']) || empty($input['message']) || empty($input['recipients'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields: sender_id, message, recipients']);
    exit;
}

$service = new BulkSMSService();
$res = $service->process((int)$user['id'], $input);

if ($res['success']) {
    echo json_encode([
        'status' => 'success',
        'message' => $res['message'],
        'data' => [
            'reference' => $res['ref'] ?? null
        ]
    ]);
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $res['message']]);
}
