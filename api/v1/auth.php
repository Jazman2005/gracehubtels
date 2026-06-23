<?php
require_once dirname(__DIR__, 2) . '/user/core/db.php';
require_once dirname(__DIR__, 2) . '/user/core/auth.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Authorization, Content-Type");

function api_auth() {
    $headers = apache_request_headers();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    if (!$auth || !preg_match('/Bearer\s+(msj_live_[a-zA-Z0-9]+)/', $auth, $matches)) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized: Invalid or missing Bearer token']);
        exit;
    }
    
    $token = $matches[1];
    $stmt = db()->prepare("SELECT * FROM users WHERE api_token = ? AND is_active=1 AND is_suspended=0");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    
    if (!$user) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized: Invalid API key or suspended account']);
        exit;
    }
    
    return $user;
}
