<?php
require_once dirname(__DIR__, 2) . '/user/core/db.php';
require_once dirname(__DIR__, 2) . '/user/core/auth.php';

$token = $_GET['token'] ?? '';
if (empty($token)) {
    die("Invalid or missing token.");
}

// Static, non-rotating API tokens passed via GET are more prone to leaking
// (browser history, access/proxy logs, Referer headers) than an Authorization
// header — rate-limit so a leaked/guessed token can't be hammered here.
if (!rate_limit('webview_auth', 10, 300)) {
    die("Too many attempts. Please try again later.");
}

$stmt = db()->prepare("SELECT * FROM users WHERE api_token = ? AND is_active = 1 LIMIT 1");
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    log_security('WEBVIEW_AUTH_FAIL', ['token_prefix' => substr($token, 0, 12)]);
    die("Authentication failed or session expired. Please log in again.");
}

// Start PHP session and log the user in
start_session();
session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];
$_SESSION['user_phone'] = $user['phone'];
$_SESSION['login_ts'] = time();

// Redirect to dashboard
header("Location: /user/dashboard");
exit;
