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

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$step = $input['step'] ?? '';

// STEP 1: Request reset — send OTP to email
if ($step === 'request') {
    if (!rate_limit('forgot_password_request', 10, 900)) {
        http_response_code(429);
        echo json_encode(['status' => 'error', 'message' => 'Too many requests. Please try again later.']);
        exit;
    }

    $identifier = strtolower(trim($input['identifier'] ?? ''));
    if (empty($identifier)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Enter your email or phone number.']);
        exit;
    }

    $stmt = db()->prepare("SELECT id, email, full_name FROM users WHERE (email=? OR phone=?) AND is_active=1 LIMIT 1");
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch();

    // Always respond the same way (prevent email enumeration)
    if ($user) {
        $reset_token = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
        db()->prepare("UPDATE users SET reset_token=?, reset_token_expiry=? WHERE id=?")->execute([$reset_token, $expiry, $user['id']]);

        $name = $user['full_name'];
        $reset_url = 'https://gracehubtels.com/api/v1/forgot-password.php?step=reset&token=' . $reset_token;
        $html = "<div style='font-family:Arial;max-width:480px;margin:0 auto;padding:24px;background:#fff;border-radius:12px;border:1px solid #E2E8F0;'>
            <h2 style='color:#0A2A7A;margin-top:0;'>Password Reset</h2>
            <p style='color:#475569;font-size:14px;'>Hi <strong>" . htmlspecialchars($name, ENT_QUOTES) . "</strong>,</p>
            <p style='color:#475569;font-size:14px;'>Click the button below to reset your password:</p>
            <div style='text-align:center;margin:20px 0;'>
                <a href='" . htmlspecialchars($reset_url, ENT_QUOTES) . "' style='display:inline-block;padding:14px 32px;background:#0A2A7A;color:#fff;text-decoration:none;border-radius:8px;font-weight:700;'>Reset Password</a>
            </div>
            <p style='color:#64748B;font-size:13px;'>Link expires in 1 hour.</p>
            <p style='color:#94A3B8;font-size:12px;'>If you didn't request this, ignore this email.</p>
        </div>";
        send_email($user['email'], 'Password Reset - Gracehubtels', $html);
    }

    echo json_encode(['status' => 'success', 'message' => 'If an account with that email/phone exists, a reset link has been sent.']);
    exit;
}

// STEP 2: Reset password with token
if ($step === 'reset') {
    if (!rate_limit('forgot_password_reset', 20, 900)) {
        http_response_code(429);
        echo json_encode(['status' => 'error', 'message' => 'Too many attempts. Please try again later.']);
        exit;
    }

    $token = trim($input['token'] ?? '');
    $new_password = $input['new_password'] ?? '';
    $confirm_password = $input['confirm_password'] ?? '';

    if (empty($token) || empty($new_password) || empty($confirm_password)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing required fields: token, new_password, confirm_password']);
        exit;
    }

    if (strlen($new_password) < 8) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Password must be at least 8 characters.']);
        exit;
    }

    if ($new_password !== $confirm_password) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Passwords do not match.']);
        exit;
    }

    $stmt = db()->prepare("SELECT id FROM users WHERE reset_token=? AND reset_token_expiry > NOW() LIMIT 1");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid or expired reset token.']);
        exit;
    }

    $hash = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12]);
    db()->prepare("UPDATE users SET password=?, reset_token=NULL, reset_token_expiry=NULL WHERE id=?")->execute([$hash, $user['id']]);

    echo json_encode(['status' => 'success', 'message' => 'Password reset successfully. You can now log in.']);
    exit;
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Unknown step. Use step=request or step=reset.']);
