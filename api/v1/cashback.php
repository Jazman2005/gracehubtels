<?php
define('IS_API', true);
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 2) . '/user/core/cashback.php';

$user = api_auth();
$uid = (int)$user['id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $history = get_cashback_history($uid);
    $withdrawals = get_cashback_withdrawals($uid);

    echo json_encode([
        'status' => 'success',
        'success' => true,
        'data' => [
            'cashback_balance' => (float)($user['cashback_balance'] ?? 0),
            'history' => $history ?: [],
            'withdrawals' => $withdrawals ?: []
        ]
    ]);
    exit;
}

if ($method === 'POST') {
    if (!rate_limit('api_purchase_' . $uid, 30, 60)) {
        http_response_code(429);
        echo json_encode(['status' => 'error', 'message' => 'Too many requests. Please slow down and try again shortly.']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';

    if ($action === 'withdraw') {
        $res = process_cashback_withdrawal($uid, [
            'amount' => $input['amount'] ?? 0,
            'pin' => $input['pin'] ?? ''
        ]);

        if ($res['success']) {
            echo json_encode([
                'status' => 'success',
                'message' => $res['message'],
                'data' => [
                    'reference' => $res['ref'],
                    'cashback_before' => $res['cashback_before'] ?? 0,
                    'cashback_after' => $res['cashback_after'] ?? 0,
                    'wallet_before' => $res['wallet_before'] ?? 0,
                    'wallet_after' => $res['wallet_after'] ?? 0
                ]
            ]);
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $res['message']]);
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Unknown action. Valid: withdraw']);
    exit;
}

http_response_code(405);
echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed.']);
