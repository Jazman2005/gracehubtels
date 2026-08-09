<?php
// Health check — visit https://gracehubtels.com/chatbot/health.php to verify bot is reachable
require_once __DIR__ . '/../user/core/db.php';
$diag_key = setting('bot_diag_key', '');
if (empty($diag_key) || !isset($_GET['key']) || !hash_equals($diag_key, $_GET['key'])) {
    http_response_code(403); die("Access denied");
}
$status = 'OK';
$errors = [];

// PHP version
$php_ver = phpversion();

// cURL check
$curl_ok = function_exists('curl_version');
if (!$curl_ok) $errors[] = 'cURL not available';

// DB check & Whapi credentials
try {
    $token = setting('whapi_token', '');
    $creds = $token ? 'configured' : 'missing';
    if (!$token) $errors[] = 'whapi_token not set';
} catch (Throwable $e) {
    $creds = 'error: ' . $e->getMessage();
    $errors[] = 'DB/settings error';
}

// Session table check
try {
    $s = db()->prepare("SELECT COUNT(*) FROM chatbot_sessions");
    $s->execute();
    $session_count = (int)$s->fetchColumn();
} catch (Throwable $e) {
    $session_count = 'error: table missing? ' . $e->getMessage();
    $errors[] = 'chatbot_sessions table missing';
}

header('Content-Type: application/json');
echo json_encode([
    'status' => empty($errors) ? 'OK' : 'ISSUES',
    'php_version' => $php_ver,
    'curl' => $curl_ok,
    'whapi_credentials' => $creds,
    'bot_sessions_table' => $session_count,
    'errors' => $errors,
    'webhook_url' => 'https://gracehubtels.com/chatbot/index.php',
], JSON_PRETTY_PRINT);
