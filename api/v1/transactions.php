<?php
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 2) . '/user/core/dashboard.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed. Use GET.']);
    exit;
}

$user = api_auth();

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = min(50, max(10, (int)($_GET['per_page'] ?? 20)));
$filter = $_GET['filter'] ?? '';

$result = get_user_transactions((int)$user['id'], $page, $per_page, $filter);

echo json_encode([
    'status' => 'success',
    'data' => $result
]);
