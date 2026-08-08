<?php
define('IS_API', true);
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 2) . '/user/core/beneficiaries.php';

$user = api_auth();
$uid = (int)$user['id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $service = $_GET['service'] ?? '';
    $beneficiaries = get_beneficiaries($uid, $service);
    echo json_encode([
        'status' => 'success',
        'data' => ['beneficiaries' => $beneficiaries]
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
    $action = $input['action'] ?? 'create';

    if ($action === 'create') {
        if (empty($input['service']) || empty($input['label']) || empty($input['value'])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing required fields: service, label, value']);
            exit;
        }
        $res = save_beneficiary($uid, $input);
        if ($res['success']) {
            echo json_encode(['status' => 'success', 'message' => $res['message']]);
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $res['message']]);
        }
        exit;
    }

    if ($action === 'delete') {
        if (empty($input['id'])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing required field: id']);
            exit;
        }
        $res = delete_beneficiary($uid, (int)$input['id']);
        if ($res['success']) {
            echo json_encode(['status' => 'success', 'message' => $res['message']]);
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $res['message']]);
        }
        exit;
    }

    if ($action === 'favourite') {
        if (empty($input['id'])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing required field: id']);
            exit;
        }
        $res = toggle_fav($uid, (int)$input['id']);
        echo json_encode(['status' => 'success', 'message' => $res['message']]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Unknown action. Supported: create, delete, favourite']);
    exit;
}

http_response_code(405);
echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed. Use GET or POST.']);
