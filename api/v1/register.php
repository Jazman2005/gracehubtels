<?php
require_once dirname(__DIR__, 2) . '/user/core/db.php';

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

if (!rate_limit('api_register', 5, 3600)) {
    http_response_code(429);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Too many registration attempts. Try again later.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if (empty($input['phone']) || empty($input['password']) || empty($input['full_name'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields: full_name, phone, password']);
    exit;
}

$phone = trim($input['phone']);
$password = $input['password'];
$full_name = trim($input['full_name']);
$email = trim($input['email'] ?? '');
$username = trim($input['username'] ?? '');

$chk = db()->prepare("SELECT id FROM users WHERE phone = ? LIMIT 1");
$chk->execute([$phone]);
if ($chk->fetch()) {
    http_response_code(409);
    echo json_encode(['status' => 'error', 'message' => 'Phone number already registered.']);
    exit;
}

if ($email) {
    $chk2 = db()->prepare("SELECT id FROM users WHERE email = ? AND email != '' LIMIT 1");
    $chk2->execute([$email]);
    if ($chk2->fetch()) {
        http_response_code(409);
        echo json_encode(['status' => 'error', 'message' => 'Email already registered.']);
        exit;
    }
}

$ref_code = $phone;
$referred = null;
if (!empty($input['referral_code'])) {
    $ref_input = trim($input['referral_code']);
    $rs = db()->prepare("SELECT id FROM users WHERE referral_code=? OR phone=? LIMIT 1");
    $rs->execute([$ref_input, $ref_input]);
    $rf = $rs->fetch();
    if ($rf) $referred = (int)$rf['id'];
}

$hashed = password_hash($password, PASSWORD_DEFAULT);
$token = 'msj_live_' . bin2hex(random_bytes(24));

$stmt = db()->prepare("INSERT INTO users (full_name, phone, email, username, password, api_token, referral_code, referred_by, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())");
$stmt->execute([$full_name, $phone, $email, $username ?: $phone, $hashed, $token, $ref_code, $referred]);
$user_id = (int)db()->lastInsertId();

echo json_encode([
    'status' => 'success',
    'message' => 'Account created successfully.',
    'data' => [
        'token' => $token,
        'user' => [
            'id' => $user_id,
            'name' => $full_name,
            'phone' => $phone,
            'email' => $email,
        ]
    ]
]);
