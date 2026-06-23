<?php
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed. Use GET.']);
    exit;
}

$user = api_auth();

echo json_encode([
    'status' => 'success',
    'data' => [
        'user_id' => $user['id'],
        'name' => $user['full_name'],
        'email' => $user['email'],
        'phone' => $user['phone'],
        'user_type' => $user['user_type'],
        'webhook_url' => $user['api_webhook_url']
    ]
]);
