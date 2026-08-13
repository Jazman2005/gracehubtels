<?php
define('IS_API', true);
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 2) . '/user/core/airtime.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed. Use POST.']);
    exit;
}

$user = api_auth();
require_feature_api('airtime');

// claim_purchase_lock() below only blocks IDENTICAL repeated requests (same
// signature within a short bucket) — it's not a substitute for a general
// throughput limit, so a script varying phone/amount slightly on each call
// could otherwise fire unlimited purchase attempts per second.
if (!rate_limit('api_purchase_' . $user['id'], 30, 60)) {
    http_response_code(429);
    echo json_encode(['status' => 'error', 'message' => 'Too many requests. Please slow down and try again shortly.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if (empty($input['network']) || empty($input['phone']) || empty($input['amount'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields: network, phone, amount']);
    exit;
}

$res = process_airtime((int)$user['id'], $input);

if ($res['success']) {
    $u_bal = db()->prepare("SELECT wallet_balance FROM users WHERE id=?");
    $u_bal->execute([$user['id']]);
    $updated = $u_bal->fetch();
    echo json_encode([
        'status' => 'success',
        'message' => $res['message'],
        'data' => [
            'reference' => $res['ref'] ?? null,
            'cashback_earned' => $res['cashback'] ?? 0,
            'balance_before' => (float)$user['wallet_balance'],
            'balance_after' => (float)($updated['wallet_balance'] ?? 0)
        ]
    ]);
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $res['message']]);
}
