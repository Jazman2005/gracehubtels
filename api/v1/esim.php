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
        echo json_encode(resolve_esim_order($ref));
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
    echo json_encode(['status' => 'success', 'success' => true, 'message' => $res['message'], 'pending' => $res['pending'] ?? false]);
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => $res['message']]);
}
