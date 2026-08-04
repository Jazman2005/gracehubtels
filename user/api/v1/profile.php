<?php
require_once __DIR__ . '/../../core/api_auth.php';

$user = authenticate_api();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed. Use GET.']);
    exit;
}

echo json_encode([
    'status' => 'success',
    'data' => [
        'name' => $user['full_name'],
        'email' => $user['email'],
        'phone' => $user['phone']
    ]
]);
