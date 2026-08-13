<?php
define('IS_API', true);
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 2) . '/user/core/giftcard.php';

$user = api_auth();
require_feature_api('giftcard_sell');

// GET — accepted brands + this user's own submission history
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'status' => 'success', 'success' => true,
        'brands' => get_giftcard_sell_brands(),
        'submissions' => get_giftcard_sell_submissions((int)$user['id']),
    ]);
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
if (empty($input['brand']) || empty($input['card_value']) || empty($input['card_code'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields: brand, card_value, card_code']);
    exit;
}

$res = process_giftcard_sell_submission((int)$user['id'], $input);

if ($res['success']) {
    echo json_encode(['status' => 'success', 'success' => true, 'message' => $res['message']]);
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => $res['message']]);
}
