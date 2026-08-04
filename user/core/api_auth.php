<?php
require_once __DIR__ . '/db.php';

function authenticate_api() {
    header('Content-Type: application/json');
    $headers = apache_request_headers();
    $auth_header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    if (!$auth_header || !preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized: Missing or invalid Bearer token.']);
        exit;
    }
    
    $token = $matches[1];
    
    $stmt = db()->prepare("SELECT * FROM users WHERE api_token = ? AND is_active = 1 AND is_suspended = 0 LIMIT 1");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized: Invalid token or account suspended.']);
        exit;
    }
    
    return $user;
}
