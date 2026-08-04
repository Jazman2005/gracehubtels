<?php
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 2) . '/user/core/profile.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed. Use POST.']);
    exit;
}

$user = api_auth();
$uid = (int)$user['id'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$allowed_fields = ['full_name'];
$updates = [];
$params = [];

foreach ($allowed_fields as $field) {
    if (isset($input[$field]) && $input[$field] !== '') {
        $val = trim($input[$field]);
        if ($field === 'full_name' && (strlen($val) < 3 || strlen($val) > 100)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Full name must be 3-100 characters.']);
            exit;
        }
        $updates[] = "$field=?";
        $params[] = $val;
    }
}

if (isset($input['email']) && $input['email'] !== '') {
    $email = strtolower(trim($input['email']));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid email address.']);
        exit;
    }
    $chk = db()->prepare("SELECT id FROM users WHERE email=? AND id!=? LIMIT 1");
    $chk->execute([$email, $uid]);
    if ($chk->fetch()) {
        http_response_code(409);
        echo json_encode(['status' => 'error', 'message' => 'Email already in use.']);
        exit;
    }
    $updates[] = "email=?";
    $params[] = $email;
}

if (empty($updates)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'No fields to update. Supported: full_name, email']);
    exit;
}

$params[] = $uid;
try {
    db()->prepare("UPDATE users SET " . implode(',', $updates) . " WHERE id=?")->execute($params);
    echo json_encode(['status' => 'success', 'message' => 'Profile updated successfully.']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to update profile.']);
}
