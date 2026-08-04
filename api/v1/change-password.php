<?php
define('IS_API', true);
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Method Not Allowed. Use POST.']);
    exit;
}

$user = api_auth();
$uid = (int)$user['id'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$current_password = $input['current_password'] ?? '';
$new_password = $input['new_password'] ?? '';
$confirm_password = $input['confirm_password'] ?? '';

if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Missing required fields: current_password, new_password, confirm_password']);
    exit;
}

if (strlen($new_password) < 8) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => 'New password must be at least 8 characters.']);
    exit;
}

if ($new_password !== $confirm_password) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Passwords do not match.']);
    exit;
}

$stmt = db()->prepare("SELECT password FROM users WHERE id = ?");
$stmt->execute([$uid]);
$row = $stmt->fetch();

if (!$row || !password_verify($current_password, $row['password'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Current password is incorrect.']);
    exit;
}

$hash = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12]);
db()->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $uid]);

echo json_encode([
    'status' => 'success',
    'success' => true,
    'message' => 'Password changed successfully.'
]);
