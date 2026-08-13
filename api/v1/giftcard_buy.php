<?php
define('IS_API', true);
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 2) . '/user/core/giftcard.php';

$user = api_auth();
require_feature_api('giftcard_buy');

// GET — country list, or live gift cards for a requested country
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $country = strtoupper(trim($_GET['country'] ?? ''));
    if ($country !== '') {
        echo json_encode(['success' => true, 'country' => $country, 'cards' => fetch_giftcard_buy_catalog($country)]);
    } else {
        echo json_encode(['success' => true, 'countries' => get_giftcard_buy_countries()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed. Use GET or POST.']);
    exit;
}

if (!rate_limit('api_purchase_' . $user['id'], 30, 60)) {
    http_response_code(429);
    echo json_encode(['status' => 'error', 'message' => 'Too many requests. Please slow down and try again shortly.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (empty($input['country']) || empty($input['api_registry_id']) || empty($input['product_id']) || empty($input['recipient_email'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields: country, api_registry_id, product_id, recipient_email']);
    exit;
}

$res = process_giftcard_buy_purchase((int)$user['id'], $input);

if ($res['success']) {
    $u_bal = db()->prepare("SELECT wallet_balance FROM users WHERE id=?");
    $u_bal->execute([$user['id']]);
    $updated = $u_bal->fetch();
    echo json_encode([
        'status' => 'success', 'success' => true, 'message' => $res['message'],
        'data' => [
            'reference' => $res['ref'] ?? null,
            'balance_before' => (float)$user['wallet_balance'],
            'balance_after' => (float)($updated['wallet_balance'] ?? 0),
        ],
    ]);
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => $res['message']]);
}
