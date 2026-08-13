<?php
define('IS_API', true);
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 2) . '/user/core/esim.php';

$user = api_auth();
require_feature_api('esim');

// GET — region list, live packages for a region, or status of a specific order
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $ref = trim($_GET['ref'] ?? '');
    if ($ref !== '') {
        $owns = db()->prepare("SELECT id FROM esim_orders WHERE transaction_ref=? AND user_id=? LIMIT 1");
        $owns->execute([$ref, (int)$user['id']]);
        if (!$owns->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Order not found']); exit; }
        if (!rate_limit('api_esim_refresh_'.$user['id'], 4, 60)) { http_response_code(429); echo json_encode(['success'=>false,'message'=>'Too many requests.']); exit; }
        $result = resolve_esim_order($ref);
        $order = db()->prepare("SELECT status, iccid, activation_code, qr_image, smdp FROM esim_orders WHERE transaction_ref=?");
        $order->execute([$ref]);
        if ($row = $order->fetch()) {
            $result['status'] = $row['status'];
            $result['iccid'] = $row['iccid'];
            $result['activation_code'] = $row['activation_code'];
            $result['qr_image'] = $row['qr_image'];
            $result['smdp'] = $row['smdp'];
        }
        echo json_encode($result);
        exit;
    }
    $region = strtoupper(trim($_GET['region'] ?? ''));
    if ($region !== '') {
        echo json_encode(['success' => true, 'region' => $region, 'packages' => fetch_esim_catalog($region)]);
    } else {
        echo json_encode(['success' => true, 'regions' => get_esim_regions()]);
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
if (empty($input['region']) || empty($input['api_registry_id']) || empty($input['package_code']) || empty($input['email'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields: region, api_registry_id, package_code, email']);
    exit;
}

$res = process_esim_purchase((int)$user['id'], $input);

if ($res['success']) {
    $u_bal = db()->prepare("SELECT wallet_balance FROM users WHERE id=?");
    $u_bal->execute([$user['id']]);
    $updated = $u_bal->fetch();
    $esim_data = ['iccid' => null, 'activation_code' => null, 'qr_image' => null, 'smdp' => null];
    if (!empty($res['ref'])) {
        $eo = db()->prepare("SELECT iccid, activation_code, qr_image, smdp FROM esim_orders WHERE transaction_ref=?");
        $eo->execute([$res['ref']]);
        if ($row = $eo->fetch()) { $esim_data = $row; }
    }
    echo json_encode([
        'status' => 'success', 'success' => true, 'message' => $res['message'],
        'pending' => $res['pending'] ?? false,
        'data' => array_merge([
            'reference' => $res['ref'] ?? null,
            'balance_before' => (float)$user['wallet_balance'],
            'balance_after' => (float)($updated['wallet_balance'] ?? 0),
        ], $esim_data),
    ]);
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => $res['message']]);
}
