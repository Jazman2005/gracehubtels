<?php
define('IS_API', true);
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 2) . '/user/core/international_airtime.php';

$user = api_auth();
require_feature_api('international_airtime');

// GET — country list, or live operators for a requested country
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $country = strtoupper(trim($_GET['country'] ?? ''));
    if ($country !== '') {
        echo json_encode(['success' => true, 'country' => $country, 'operators' => fetch_international_airtime_catalog($country)]);
    } else {
        echo json_encode(['success' => true, 'countries' => get_international_airtime_countries()]);
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
if (empty($input['country']) || empty($input['api_registry_id']) || empty($input['operator_id']) || empty($input['phone']) || empty($input['amount'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields: country, api_registry_id, operator_id, phone, amount']);
    exit;
}

$res = process_international_airtime_purchase((int)$user['id'], $input);

if ($res['success']) {
    echo json_encode(['status' => 'success', 'success' => true, 'message' => $res['message']]);
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => $res['message']]);
}
