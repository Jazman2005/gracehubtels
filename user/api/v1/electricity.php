<?php
require_once __DIR__ . '/../../core/api_auth.php';
require_once __DIR__ . '/../../core/electricity.php';

$user = authenticate_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed. Use POST.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON payload.']);
    exit;
}

if (empty($input['pin'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Transaction PIN is required in payload.']);
    exit;
}

$service = new ElectricityService();
$result = $service->process($user['id'], $input);

if (!$result['success']) {
    http_response_code(400);
}

echo json_encode($result);
