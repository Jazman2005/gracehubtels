<?php
define('IS_API', true);
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 2) . '/user/core/profile.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Method Not Allowed. Use POST.']);
    exit;
}

$user = api_auth();
$uid = (int)$user['id'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$new_pin = $input['new_pin'] ?? $input['pin'] ?? '';
$confirm_pin = $input['confirm_pin'] ?? '';

if (empty($new_pin) || empty($confirm_pin)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Missing required fields: new_pin, confirm_pin']);
    exit;
}

if (!preg_match('/^\d{4}$/', $new_pin)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => 'PIN must be exactly 4 digits.']);
    exit;
}

if ($new_pin !== $confirm_pin) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => 'PINs do not match.']);
    exit;
}

// verify_pin() below already locks out repeated wrong current_pin guesses
// once a PIN exists, but first-time PIN setup (no current_pin to check) had
// no rate limit at all on this endpoint being hit repeatedly.
if (!rate_limit('set_pin_' . $uid, 10, 900)) {
    http_response_code(429);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Too many attempts. Please try again in 15 minutes.']);
    exit;
}

// Check if user already has a PIN
$stmt = db()->prepare("SELECT transaction_pin FROM users WHERE id=?");
$stmt->execute([$uid]);
$row = $stmt->fetch();
$has_pin = $row && !empty($row['transaction_pin']);

if ($has_pin) {
    // Changing existing PIN — require current_pin
    $current_pin = $input['current_pin'] ?? $input['old_pin'] ?? '';
    if (empty($current_pin)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Current PIN is required to change your existing PIN.']);
        exit;
    }
    if (!verify_pin($uid, $current_pin)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Incorrect current transaction PIN.']);
        exit;
    }
}

$hash = password_hash($new_pin, PASSWORD_BCRYPT, ['cost' => 12]);
db()->prepare("UPDATE users SET transaction_pin=? WHERE id=?")->execute([$hash, $uid]);

echo json_encode([
    'status' => 'success',
    'success' => true,
    'message' => $has_pin ? 'Transaction PIN changed successfully.' : 'Transaction PIN set successfully.'
]);
