<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function update_profile(int $uid, array $data): array {
    $full_name = trim($data['full_name'] ?? '');
    if (strlen($full_name) < 3 || strlen($full_name) > 100) {
        return ['success' => false, 'message' => 'Full name must be 3-100 characters.'];
    }
    
    try {
        db()->prepare("UPDATE users SET full_name = ? WHERE id = ?")->execute([$full_name, $uid]);
        return ['success' => true, 'message' => 'Profile updated successfully.'];
    } catch (PDOException $e) {
        log_error('profile', 'update_profile failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to update profile.'];
    }
}

function change_password(int $uid, array $data): array {
    $current = $data['current_password'] ?? '';
    $new = $data['new_password'] ?? '';
    $confirm = $data['confirm_password'] ?? '';

    if (strlen($new) < 8) {
        return ['success' => false, 'message' => 'New password must be at least 8 characters.'];
    }
    if ($new !== $confirm) {
        return ['success' => false, 'message' => 'Passwords do not match.'];
    }
    // Nothing previously rate-limited how many times "current_password" could
    // be guessed here — a hijacked/stolen session (e.g. a shared computer
    // left logged in) had unlimited attempts to brute-force the account's
    // real password via this endpoint with no lockout at all.
    if (!rate_limit('change_password_' . $uid, 5, 900)) {
        return ['success' => false, 'message' => 'Too many attempts. Please try again in 15 minutes.'];
    }

    try {
        $stmt = db()->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$uid]);
        $user = $stmt->fetch();
        
        if (!$user || !password_verify($current, $user['password'])) {
            return ['success' => false, 'message' => 'Incorrect current password.'];
        }
        
        $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
        // Rotate api_token so a previously leaked/stolen bearer token stops
        // working the moment the account owner changes their password.
        db()->prepare("UPDATE users SET password = ?, api_token = NULL WHERE id = ?")->execute([$hash, $uid]);

        return ['success' => true, 'message' => 'Password changed successfully.'];
    } catch (PDOException $e) {
        log_error('profile', 'change_password failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to change password.'];
    }
}

function set_transaction_pin(int $uid, array $data): array {
    $current_pin = $data['current_pin'] ?? '';
    $pin = $data['pin'] ?? $data['new_pin'] ?? '';
    $confirm = $data['confirm_pin'] ?? '';
    
    if (!preg_match('/^\d{4}$/', $pin)) {
        return ['success' => false, 'message' => 'PIN must be exactly 4 digits.'];
    }
    if ($pin !== $confirm) {
        return ['success' => false, 'message' => 'PINs do not match.'];
    }
    // verify_pin() below already locks out repeated wrong current_pin guesses
    // once a PIN exists, but a brand-new PIN (first-time setup, no current_pin
    // to check) had no rate limit at all on this endpoint being hit repeatedly.
    if (!rate_limit('set_pin_' . $uid, 10, 900)) {
        return ['success' => false, 'message' => 'Too many attempts. Please try again in 15 minutes.'];
    }

    try {
        $stmt = db()->prepare("SELECT transaction_pin FROM users WHERE id = ?");
        $stmt->execute([$uid]);
        $user = $stmt->fetch();
        
        if ($user && !empty($user['transaction_pin'])) {
            if (!verify_pin($uid, $current_pin)) {
                return ['success' => false, 'message' => 'Incorrect current transaction PIN.'];
            }
        }
        
        $hash = password_hash($pin, PASSWORD_BCRYPT, ['cost' => 12]);
        db()->prepare("UPDATE users SET transaction_pin = ? WHERE id = ?")->execute([$hash, $uid]);
        
        return ['success' => true, 'message' => 'Transaction PIN changed successfully.'];
    } catch (PDOException $e) {
        log_error('profile', 'set_transaction_pin failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to set PIN.'];
    }
}
