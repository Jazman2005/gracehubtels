<?php
define('IS_API', true);
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed. Use POST.']);
    exit;
}

// PIN resets get their own columns — never reuse users.reset_token /
// reset_token_expiry, which gate the unauthenticated account password reset
// (api/v1/forgot-password.php). Sharing that column let a weak 6-digit PIN
// OTP be replayed there to take over the account password.
try { db()->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS pin_reset_token VARCHAR(10) DEFAULT NULL"); } catch (\Throwable $e) {}
try { db()->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS pin_reset_token_expiry DATETIME DEFAULT NULL"); } catch (\Throwable $e) {}

$user = api_auth();
$uid = (int)$user['id'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$step = $input['step'] ?? '';

// STEP 1: Request OTP
if ($step === 'request') {
    if (!rate_limit('pin_reset_request_' . $uid, 5, 900)) {
        http_response_code(429);
        echo json_encode(['status' => 'error', 'message' => 'Too many requests. Please try again later.']);
        exit;
    }

    $otp = sprintf('%06d', random_int(100000, 999999));
    $expiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));

    $stmt = db()->prepare("UPDATE users SET pin_reset_token=?, pin_reset_token_expiry=? WHERE id=?");
    $stmt->execute([$otp, $expiry, $uid]);
    
    $stmt = db()->prepare("SELECT email, full_name FROM users WHERE id=?");
    $stmt->execute([$uid]);
    $u = $stmt->fetch();
    
    $html = "<div style='font-family:Arial;max-width:480px;margin:0 auto;padding:24px;background:#fff;border-radius:12px;border:1px solid #E2E8F0;'>
        <h2 style='color:#0A2A7A;margin-top:0;'>PIN Reset OTP</h2>
        <p style='color:#475569;font-size:14px;'>Hi <strong>" . htmlspecialchars($u['full_name'], ENT_QUOTES) . "</strong>,</p>
        <p style='color:#475569;font-size:14px;'>You requested to reset your transaction PIN. Your One-Time Password (OTP) is:</p>
        <div style='text-align:center;margin:20px 0;'>
            <span style='display:inline-block;padding:14px 32px;background:#F1F5F9;color:#0A2A7A;border-radius:8px;font-size:24px;letter-spacing:4px;font-weight:900;'>" . $otp . "</span>
        </div>
        <p style='color:#64748B;font-size:13px;'>This OTP expires in 15 minutes.</p>
        <p style='color:#94A3B8;font-size:12px;'>If you didn't request this, please secure your account immediately.</p>
    </div>";
    
    if (function_exists('send_email')) {
        send_email($u['email'], 'PIN Reset OTP - '.setting('site_name','Gracehubtels'), $html);
    }
    
    echo json_encode(['status' => 'success', 'message' => 'An OTP has been sent to your registered email address.']);
    exit;
}

// STEP 2: Verify OTP and Reset PIN
if ($step === 'reset') {
    $otp = trim($input['otp'] ?? '');
    $new_pin = trim($input['new_pin'] ?? '');
    $confirm_pin = trim($input['confirm_pin'] ?? '');

    if (empty($otp) || empty($new_pin) || empty($confirm_pin)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing required fields: otp, new_pin, confirm_pin']);
        exit;
    }

    if (!preg_match('/^\d{4}$/', $new_pin)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'PIN must be exactly 4 digits.']);
        exit;
    }

    if ($new_pin !== $confirm_pin) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'PINs do not match.']);
        exit;
    }

    if (!rate_limit('pin_reset_verify_' . $uid, 10, 900)) {
        http_response_code(429);
        echo json_encode(['status' => 'error', 'message' => 'Too many attempts. Please try again later.']);
        exit;
    }

    $stmt = db()->prepare("SELECT id FROM users WHERE id=? AND pin_reset_token=? AND pin_reset_token_expiry > NOW() LIMIT 1");
    $stmt->execute([$uid, $otp]);
    $valid = $stmt->fetch();

    if (!$valid) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid or expired OTP.']);
        exit;
    }

    $hash = password_hash($new_pin, PASSWORD_BCRYPT, ['cost' => 12]);
    db()->prepare("UPDATE users SET transaction_pin=?, pin_reset_token=NULL, pin_reset_token_expiry=NULL WHERE id=?")->execute([$hash, $uid]);

    echo json_encode(['status' => 'success', 'message' => 'Transaction PIN reset successfully.']);
    exit;
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Unknown step. Use step=request or step=reset.']);
