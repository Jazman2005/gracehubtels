<?php
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 2) . '/user/core/cabletv.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Method Not Allowed. Use POST.']);
    exit;
}

$user = api_auth();

// No throttle here previously let an attacker script mass enumeration of
// smartcard/IUC numbers to harvest customer names at no cost to themselves,
// and cost the platform per-lookup fees charged by the upstream biller API.
if (!rate_limit('validate_lookup_' . $user['id'], 20, 300)) {
    http_response_code(429);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Too many requests. Please try again later.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$cable_id = (int)($input['cable_id'] ?? $input['provider'] ?? 0);
$smartcard = $input['smartcard'] ?? $input['iuc_number'] ?? '';

if (empty($smartcard)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Missing required fields: smartcard/iuc_number']);
    exit;
}

$result = validate_smartcard($cable_id, $smartcard);

if ($result['success']) {
    echo json_encode([
        'status' => 'success',
        'success' => true,
        'message' => 'SmartCard verified successfully.',
        'data' => ['name' => $result['name'], 'customer_name' => $result['name'], 'smartcard' => $smartcard]
    ]);
} else {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'message' => $result['message']
    ]);
}
