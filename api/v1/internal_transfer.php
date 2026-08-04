<?php
define('IS_API', true);
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 2) . '/user/core/internal_transfer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed. Use POST.']);
    exit;
}

$user = api_auth();
$input = json_decode(file_get_contents('php://input'), true) ?? [];

if (empty($input['recipient']) || empty($input['amount']) || empty($input['pin'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields: recipient, amount, pin']);
    exit;
}

$res = process_internal_transfer((int)$user['id'], $input);

if ($res['success']) {
    echo json_encode([
        'status' => 'success',
        'success' => true,
        'message' => $res['message'],
        'reference' => $res['ref'] ?? null,
        'ref' => $res['ref'] ?? null,
        'transaction_status' => 'success',
        'data' => [
            'reference' => $res['ref'] ?? null,
            'balance_before' => (float)($res['balance_before'] ?? $user['wallet_balance']),
            'balance_after' => (float)($res['balance_after'] ?? 0),
            'transaction_status' => 'success',
            'recipient_name' => $res['recipient_name'] ?? ''
        ]
    ]);
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => $res['message']]);
}
