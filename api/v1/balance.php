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
        'wallet_balance' => (float) $user['wallet_balance'],
        'cashback_balance' => (float) $user['cashback_balance'],
        'currency' => 'NGN'
    ]
]);
