<?php
define('IS_API', true);
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 2) . '/user/core/virtual-account.php';

$user = api_auth();
$uid = (int)$user['id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $vm = new VirtualAccountManager();
    $accounts = $vm->get_user_accounts($uid);
    echo json_encode([
        'status' => 'success',
        'data' => ['virtual_accounts' => $accounts]
    ]);
    exit;
}

if ($method === 'POST') {
    $vm = new VirtualAccountManager();
    $res = $vm->create_for_user($uid);
    if ($res['success']) {
        $accounts = $vm->get_user_accounts($uid);
        echo json_encode([
            'status' => 'success',
            'message' => 'Virtual accounts created/refreshed',
            'data' => ['virtual_accounts' => $accounts]
        ]);
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $res['message'] ?? 'Failed to create virtual accounts']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed. Use GET or POST.']);
