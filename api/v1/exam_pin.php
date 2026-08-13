<?php
define('IS_API', true);
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 2) . '/user/core/exam_pin.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed. Use POST.']);
    exit;
}

$user = api_auth();
require_feature_api('exam_pin');

if (!rate_limit('api_purchase_' . $user['id'], 30, 60)) {
    http_response_code(429);
    echo json_encode(['status' => 'error', 'message' => 'Too many requests. Please slow down and try again shortly.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if (empty($input['exam_name']) || empty($input['quantity'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields: exam_name, quantity']);
    exit;
}

$res = process_exam_pin((int)$user['id'], $input);

if ($res['success']) {
    $u_bal = db()->prepare("SELECT wallet_balance FROM users WHERE id=?");
    $u_bal->execute([$user['id']]);
    $updated = $u_bal->fetch();
    echo json_encode([
        'status' => 'success',
        'message' => $res['message'],
        'data' => [
            'reference' => $res['ref'] ?? null,
            'pins' => $res['pins'] ?? [],
            'balance_before' => (float)$user['wallet_balance'],
            'balance_after' => (float)($updated['wallet_balance'] ?? 0)
        ]
    ]);
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $res['message']]);
}
