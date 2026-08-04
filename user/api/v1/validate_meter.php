<?php
require_once __DIR__ . '/../../core/api_auth.php';
require_once __DIR__ . '/../../core/electricity.php';

authenticate_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed. Use POST.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['disco_id']) || empty($input['meter_number']) || empty($input['meter_type'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields: disco_id, meter_number, meter_type.']);
    exit;
}

$service = new ElectricityService();
$result = $service->validate_meter((int)$input['disco_id'], $input['meter_number'], $input['meter_type']);

if (!$result['success']) {
    http_response_code(400);
}

echo json_encode($result);
