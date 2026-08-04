<?php
define('IS_API', true);
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 2) . '/user/core/electricity.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed. Use POST.']);
    exit;
}

$user = api_auth();
$input = json_decode(file_get_contents('php://input'), true) ?? [];

if (empty($input['disco_id']) || empty($input['meter_number']) || empty($input['meter_type'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields: disco_id, meter_number, meter_type']);
    exit;
}

$res = (new ElectricityService())->validate_meter((int)$input['disco_id'], $input['meter_number'], $input['meter_type']);

if ($res['success']) {
    echo json_encode(['status' => 'success', 'data' => ['name' => $res['name'], 'address' => $res['address'] ?? '']]);
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $res['message']]);
}
