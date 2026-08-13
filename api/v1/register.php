<?php
require_once dirname(__DIR__, 2) . '/user/core/db.php';

header("Content-Type: application/json");
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = site_allowed_origins();
header("Access-Control-Allow-Origin: " . (in_array($origin, $allowed) ? $origin : $allowed[0]));
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

if (empty($input['phone']) || empty($input['password']) || empty($input['full_name']) || empty($input['pin'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields: full_name, phone, password, pin']);
    exit;
}

$phone = trim($input['phone']);
$password = $input['password'];
$full_name = trim($input['full_name']);
$email = trim($input['email'] ?? '');
$username = trim($input['username'] ?? '');
$pin = trim($input['pin'] ?? '');

if (strlen($password) < 8) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Password must be at least 8 characters.']);
    exit;
}

if (!preg_match('/^\d{4}$/', $pin)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'PIN must be exactly 4 digits.']);
    exit;
}

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

if ($username) {
    if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Username must be 3-30 characters (letters, numbers, underscore only).']);
        exit;
    }
    $chk3 = db()->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $chk3->execute([$username]);
    if ($chk3->fetch()) {
        http_response_code(409);
        echo json_encode(['status' => 'error', 'message' => 'Username already taken.']);
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

// Explicit cost=12 to match every other password_hash() call site in the
// codebase (user/core/auth.php's register_user(), change-password, PIN
// flows, etc). PASSWORD_DEFAULT currently resolves to bcrypt cost=10, which
// made api-registered accounts measurably cheaper to verify than the
// DUMMY_PASSWORD_HASH used to equalize login timing — surfaced by the
// Phase 2A timing side-channel test.
$hashed = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
$pin_hashed = password_hash($pin, PASSWORD_BCRYPT, ['cost' => 12]);
$token = 'msj_live_' . bin2hex(random_bytes(24));

$stmt = db()->prepare("INSERT INTO users (full_name, phone, email, username, password, transaction_pin, api_token, referral_code, referred_by, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())");
$stmt->execute([$full_name, $phone, $email, $username ?: $phone, $hashed, $pin_hashed, $token, $ref_code, $referred]);
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
