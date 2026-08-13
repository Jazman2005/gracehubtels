<?php
define('IS_API', true);
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 2) . '/user/core/betting.php';

$user = api_auth();
require_feature_api('betting');
$uid = (int)$user['id'];

// GET — list active betting platforms
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $platforms = db()->prepare("SELECT id, platform_name, charge FROM betting_plans WHERE is_active=1 ORDER BY platform_name");
    $platforms->execute();
    echo json_encode(['status' => 'success', 'success' => true, 'platforms' => $platforms->fetchAll()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed. Use GET or POST.']);
    exit;
}

if (!rate_limit('api_purchase_' . $uid, 30, 60)) {
    http_response_code(429);
    echo json_encode(['status' => 'error', 'message' => 'Too many requests. Please slow down and try again shortly.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

// Customer-ID lookup — same read-only, pre-payment validation the website's
// own onblur handler calls, kept as its own action so the app can show the
// resolved account name before the user commits to a PIN/purchase.
if ($action === 'validate_customer') {
    if (empty($input['plan_id']) || empty($input['customer_id'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing required fields: plan_id, customer_id']);
        exit;
    }
    echo json_encode(validate_betting_customer((int)$input['plan_id'], sanitize_phone((string)$input['customer_id'])));
    exit;
}

if (empty($input['plan_id']) || empty($input['customer_id']) || empty($input['amount'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields: plan_id, customer_id, amount']);
    exit;
}

$res = process_betting($uid, $input);

if ($res['success']) {
    $u_bal = db()->prepare("SELECT wallet_balance FROM users WHERE id=?");
    $u_bal->execute([$uid]);
    $updated = $u_bal->fetch();
    echo json_encode([
        'status' => 'success', 'success' => true, 'message' => $res['message'],
        'pending' => $res['pending'] ?? false,
        'data' => [
            'reference' => $res['ref'] ?? null,
            'cashback_earned' => $res['cashback'] ?? 0,
            'balance_before' => (float)$user['wallet_balance'],
            'balance_after' => (float)($updated['wallet_balance'] ?? 0),
        ],
    ]);
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => $res['message']]);
}
