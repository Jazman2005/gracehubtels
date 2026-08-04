<?php
require_once __DIR__ . '/../../core/api_auth.php';
require_once __DIR__ . '/../../core/cabletv.php';

authenticate_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed. Use POST.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['cable_id']) || empty($input['smartcard'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields: cable_id, smartcard.']);
    exit;
}

$service = new CableTVService();
$result = $service->validate_smartcard((int)$input['cable_id'], $input['smartcard']);

if (!$result['success']) {
    http_response_code(400);
}

echo json_encode($result);
