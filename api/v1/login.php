<?php
require_once dirname(__DIR__, 2) . '/user/core/db.php';
require_once dirname(__DIR__, 2) . '/user/core/auth.php';

header("Content-Type: application/json");
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['https://gracehubtels.com', 'https://www.gracehubtels.com'];
header("Access-Control-Allow-Origin: " . (in_array($origin, $allowed) ? $origin : 'https://gracehubtels.com'));
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed. Use POST.']);
    exit;
}

if (!rate_limit('api_login', 10, 900)) {
    http_response_code(429);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Too many login attempts. Try again later.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$identifier = trim($input['phone'] ?? $input['username'] ?? '');
if (empty($identifier) || empty($input['password'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields: phone/username, password']);
    exit;
}

$password = $input['password'];

// Detect if identifier is a phone number (starts with digit) or username
$phone = '';
$username = '';
if (preg_match('/^\d/', $identifier)) {
    $phone = preg_replace('/\D/', '', $identifier);
    if (strpos($phone, '234') === 0 && strlen($phone) === 13) {
        $phone = '0' . substr($phone, 3);
    }
} else {
    $username = $identifier;
}

$stmt = db()->prepare("SELECT * FROM users WHERE (phone = ? OR username = ?) AND is_active = 1 LIMIT 1");
$stmt->execute([$phone ?: $username, $username ?: $phone]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid credentials or account not active.']);
    exit;
}

if (!empty($user['is_suspended'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Account suspended. Contact support.']);
    exit;
}

if (empty($user['api_token'])) {
    $token = 'msj_live_' . bin2hex(random_bytes(24));
    try {
        $upd = db()->prepare("UPDATE users SET api_token = ? WHERE id = ?");
        $upd->execute([$token, $user['id']]);
        $user['api_token'] = $token;
    } catch (PDOException $e) {
        // api_token column may not exist yet, use a session-based token
        $user['api_token'] = $token;
    }
}

echo json_encode([
    'status' => 'success',
    'data' => [
        'token' => $user['api_token'],
        'user' => [
            'id' => (int)$user['id'],
            'name' => $user['full_name'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'wallet_balance' => (float)$user['wallet_balance'],
            'cashback_balance' => (float)$user['cashback_balance'],
            'user_type' => $user['user_type']
        ]
    ]
]);
