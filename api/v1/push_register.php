<?php
// POST /api/v1/push_register.php — Register push notification token from native app
// The native app initializes OneSignal and sends the player ID here after login
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed. Use POST.']);
    exit;
}

$user = api_auth();
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$player_id = trim($input['player_id'] ?? '');

if (empty($player_id)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required field: player_id']);
    exit;
}

try {
    $db = db();
    $stmt = $db->prepare("UPDATE users SET push_token = ? WHERE id = ?");
    $stmt->execute([$player_id, (int)$user['id']]);
    echo json_encode(['status' => 'success', 'message' => 'Push token registered']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to save push token']);
}
