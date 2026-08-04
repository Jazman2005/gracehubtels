<?php
require_once __DIR__ . '/../../core/api_auth.php';
require_once __DIR__ . '/../../core/data.php';

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

// B2B API requests don't have a transaction PIN, or maybe they do?
// In standard B2B API integrations, the API Key itself is the authorization.
// But the core DataService->process() checks `verify_pin($uid, $pin)`.
// We need to bypass or mock the pin check if it comes from the API, 
// OR we require the user to send their PIN in the API payload. 
// Requiring PIN in API is common in Nigerian VTU scripts for extra security.

if (empty($input['pin'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Transaction PIN is required in payload.']);
    exit;
}

$service = new DataService();
$result = $service->process($user['id'], $input);

if (!$result['success']) {
    http_response_code(400);
}

echo json_encode($result);
