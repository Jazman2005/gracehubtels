<?php
require_once dirname(__DIR__, 2) . '/user/core/db.php';
require_once dirname(__DIR__, 2) . '/user/core/auth.php';

header("Content-Type: application/json");
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = site_allowed_origins();
if (preg_match('/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/', $origin) || preg_match('/^https?:\/\/192\.168\.\d+\.\d+(:\d+)?$/', $origin)) {
    $allowed[] = $origin;
}
header("Access-Control-Allow-Origin: " . (in_array($origin, $allowed) ? $origin : $allowed[0]));
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type, Accept");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function api_auth() {
    $headers = [];
    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
    }
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (!$auth || !preg_match('/Bearer\s+(msj_live_[a-zA-Z0-9]+)/', $auth, $matches)) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Unauthorized: Invalid or missing token']);
        exit;
    }

    $token = $matches[1];

    try {
        $stmt = db()->prepare("SELECT * FROM users WHERE api_token = ? AND is_active=1");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Database error.']);
        exit;
    }

    if (!$user || !empty($user['is_suspended'])) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Unauthorized: Invalid API key or suspended account']);
        exit;
    }

    return $user;
}

// Per-install feature gate for the public partner API — call right after
// api_auth(). Matches api_auth()'s own two-key response shape (status +
// success) so partner integrations checking either key behave consistently.
function require_feature_api(string $key): void {
    if (feature_enabled($key)) return;
    http_response_code(403);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => "This feature isn't included in your plan."]);
    exit;
}
