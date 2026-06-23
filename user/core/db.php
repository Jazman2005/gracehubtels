<?php
declare(strict_types=1);

// ============================================
// DATABASE CONFIGURATION
// ============================================
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'msjpay');
define('DB_CHARSET', 'utf8mb4');

// ============================================
// APPLICATION CONFIGURATION
// ============================================
define('LOG_DIR', dirname(__DIR__, 2) . '/logs');
define('APP_VERSION', '2.1.0');

date_default_timezone_set('Africa/Lagos');

// Create log directory and files
if (!is_dir(LOG_DIR)) {
    @mkdir(LOG_DIR, 0750, true);
}

$log_channels = [
    'api_gladtidings', 'api_autopilot', 'api_palmpay', 'api_easeid',
    'security', 'transactions', 'auth', 'wallet', 'notifications', 
    'cashback', 'kyc'
];

foreach ($log_channels as $channel) {
    $log_file = LOG_DIR . '/' . $channel . '.log';
    if (!file_exists($log_file)) {
        @touch($log_file);
    }
}

// ============================================
// DATABASE FUNCTIONS
// ============================================
function db(): PDO
{
    static $pdo = null;
    
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
        } catch (PDOException $e) {
            log_error('db', 'Database connection failed: ' . $e->getMessage());
            json_response(['success' => false, 'message' => 'Service unavailable'], 503);
        }
    }
    
    return $pdo;
}

function setting(string $key, string $default = ''): string
{
    static $cache = [];
    
    if (!isset($cache[$key])) {
        try {
            $stmt = db()->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1");
            $stmt->execute([$key]);
            $result = $stmt->fetch();
            $cache[$key] = $result ? (string)$result['setting_value'] : $default;
        } catch (PDOException $e) {
            return $default;
        }
    }
    
    return $cache[$key];
}

function setting_set(string $key, string $value): void
{
    try {
        db()->prepare("UPDATE site_settings SET setting_value = ? WHERE setting_key = ?")
            ->execute([$value, $key]);
        unset($GLOBALS['setting_cache'][$key]);
    } catch (PDOException $e) {
        log_error('db', 'setting_set failed: ' . $e->getMessage());
    }
}

// ============================================
// UTILITY FUNCTIONS
// ============================================
function generate_ref(string $prefix = 'MSJ'): string
{
    return $prefix . strtoupper(bin2hex(random_bytes(6))) . time();
}

function sanitize(string $value, int $max = 500): string
{
    $cleaned = htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
    return substr($cleaned, 0, $max);
}

function sanitize_phone(string $phone): string
{
    return preg_replace('/\D/', '', $phone);
}

function validate_phone(string $phone): bool
{
    return (bool)preg_match('/^0[7-9][0-1]\d{8}$/', $phone);
}

function get_ip(): string
{
    $ip_sources = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    
    foreach ($ip_sources as $source) {
        if (!empty($_SERVER[$source])) {
            $ip = trim(explode(',', $_SERVER[$source])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    
    return '0.0.0.0';
}

function get_ua(): string
{
    return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 400);
}

function format_naira(float $amount): string
{
    return ',' . number_format($amount, 2);
}

// ============================================
// RESPONSE FUNCTIONS
// ============================================
function json_response(array $data, int $code = 200): never
{
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Cache-Control: no-store, no-cache');
    
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function is_ajax(): bool
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

// ============================================
// LOGGING FUNCTIONS
// ============================================
function log_write(string $channel, string $level, string $message, array $context = []): void
{
    $log_file = LOG_DIR . '/' . $channel . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $ip = get_ip();
    $context_str = empty($context) ? '' : ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    
    $log_entry = "[{$timestamp}] [{$level}] [{$ip}] {$message}{$context_str}\n";
    @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

function log_error(string $channel, string $message, array $context = []): void
{
    log_write($channel, 'ERROR', $message, $context);
}

function log_info(string $channel, string $message, array $context = []): void
{
    log_write($channel, 'INFO', $message, $context);
}

function log_transaction(string $ref, int $user_id, string $action, array $data = []): void
{
    $log_file = LOG_DIR . '/transactions.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] [TX] USER:{$user_id} REF:{$ref} {$action} | " . json_encode($data) . "\n";
    @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

function log_security(string $event, array $data = []): void
{
    $log_file = LOG_DIR . '/security.log';
    $timestamp = date('Y-m-d H:i:s');
    $ip = get_ip();
    $ua = substr(get_ua(), 0, 80);
    $log_entry = "[{$timestamp}] [SEC] [{$ip}] {$event} | UA:{$ua} | " . json_encode($data) . "\n";
    @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

function log_api(string $provider, string $endpoint, array $request, $response, bool $success): void
{
    $channel = 'api_' . strtolower(preg_replace('/[^a-z0-9]/', '', $provider));
    $log_file = LOG_DIR . '/' . $channel . '.log';
    
    // Sanitize sensitive data
    $sanitized_request = $request;
    $sensitive_fields = ['pin', 'password', 'token', 'secret', 'key', 'cvv', 'authorization'];
    foreach ($sensitive_fields as $field) {
        if (isset($sanitized_request[$field])) {
            $sanitized_request[$field] = '***';
        }
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $status = $success ? 'OK' : 'FAIL';
    $log_entry = "[{$timestamp}] [{$status}] {$endpoint} REQ:" . json_encode($sanitized_request) . 
                 " RES:" . json_encode($response) . "\n";
    @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

// ============================================
// SECURITY FUNCTIONS
// ============================================
function check_blocked_ip(): void
{
    try {
        $stmt = db()->prepare(
            "SELECT id FROM blocked_ips 
             WHERE ip_address = ? AND (expires_at IS NULL OR expires_at > NOW()) 
             LIMIT 1"
        );
        $stmt->execute([get_ip()]);
        
        if ($stmt->fetch()) {
            log_security('BLOCKED_IP_HIT');
            json_response(['success' => false, 'message' => 'Access denied'], 403);
        }
    } catch (PDOException $e) {
        // Silently fail
    }
}

function detect_tamper(array $post): bool
{
    $patterns = [
        '<script', 'javascript:', 'data:text', 'eval(',
        'UNION SELECT', 'DROP TABLE', 'INSERT INTO',
        '../..', 'php://', 'file://',
        'base64_decode', 'system(', 'exec(', 'shell_exec(',
        'passthru(', 'popen('
    ];
    
    $all_input = strtolower(implode(' ', array_map('strval', $post)));
    
    foreach ($patterns as $pattern) {
        if (str_contains($all_input, strtolower($pattern))) {
            log_security('TAMPER_DETECTED', [
                'pattern' => $pattern,
                'url' => $_SERVER['REQUEST_URI'] ?? ''
            ]);
            return true;
        }
    }
    
    return false;
}

function rate_limit(string $key, int $max_requests, int $time_window): bool
{
    $ip = get_ip();
    $cache_file = sys_get_temp_dir() . '/rl_' . md5($key . '_' . $ip);
    $now = time();
    
    $data = @file_get_contents($cache_file);
    
    if ($data) {
        list($count, $reset_time) = explode('|', $data . '|0');
        
        if ($now < (int)$reset_time) {
            if ((int)$count >= $max_requests) {
                return false;
            }
            @file_put_contents($cache_file, ($count + 1) . '|' . $reset_time);
            return true;
        }
    }
    
    @file_put_contents($cache_file, '1|' . ($now + $time_window));
    return true;
}

// ============================================
// USER FUNCTIONS
// ============================================
function get_user(int $id = 0): array
{
    $user_id = $id ?: (int)($_SESSION['user_id'] ?? 0);
    
    if (!$user_id) {
        return [];
    }
    
    try {
        $stmt = db()->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$user_id]);
        return $stmt->fetch() ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

function notify_user(int $user_id, string $title, string $message, string $type = 'info', ?string $url = null): void
{
    try {
        db()->prepare(
            "INSERT INTO notifications (user_id, title, message, type, action_url) 
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$user_id, $title, $message, $type, $url]);
        
        // Also send email
        $user = get_user($user_id);
        if ($user && !empty($user['email'])) {
            send_email($user['email'], $title, $message);
        }
    } catch (PDOException $e) {
        log_error('notifications', $e->getMessage());
    }
}

function send_email(string $to, string $subject, string $body): bool
{
    $site_name = setting('site_name', 'MSJPay');
    $site_email = setting('site_email', 'noreply@msjpay.com');
    
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$site_name} <{$site_email}>\r\n";
    $headers .= "Reply-To: {$site_email}\r\n";
    
    $htmlBody = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden;'>
        <div style='background-color: #0f172a; padding: 20px; text-align: center;'>
            <h2 style='color: #ffffff; margin: 0;'>{$site_name}</h2>
        </div>
        <div style='padding: 30px; background-color: #ffffff; color: #334155; line-height: 1.6;'>
            <h3 style='color: #0f172a; margin-top: 0;'>{$subject}</h3>
            <p>{$body}</p>
        </div>
        <div style='background-color: #f8fafc; padding: 15px; text-align: center; font-size: 12px; color: #64748b;'>
            <p>&copy; " . date('Y') . " {$site_name}. All rights reserved.</p>
        </div>
    </div>";
    
    return @mail($to, $subject, $htmlBody, $headers);
}

// ============================================
// WALLET FUNCTIONS
// ============================================
function check_transaction_limits(int $user_id, float $amount, string $category, array $user_data): array {
    $db = db();
    // Daily spent amount
    $stmt = $db->prepare("SELECT SUM(amount) as spent FROM transactions WHERE user_id = ? AND DATE(created_at) = CURDATE() AND status != 'failed' AND type = 'debit'");
    $stmt->execute([$user_id]);
    $res = $stmt->fetch();
    $daily_spent = (float)($res['spent'] ?? 0);
    $proposed_spent = $daily_spent + $amount;

    // 1. Check Global Daily Limit
    $custom_daily = $user_data['custom_daily_limit'];
    $global_limit = -1;
    if ($custom_daily !== null) {
        $global_limit = (float)$custom_daily;
    } else {
        $role = $user_data['user_type'] ?? 'regular';
        $global_limit = (float)setting("limit_daily_{$role}", $role === 'vendor' ? '1000000' : ($role === 'agent' ? '200000' : '50000'));
    }
    
    if ($global_limit > 0 && $proposed_spent > $global_limit) {
        return ['success' => false, 'message' => 'Transaction limit reached. Contact Admin.'];
    }

    // 2. Check Service Specific Daily Limits
    if ($category === 'airtime') {
        $service_spent_stmt = $db->prepare("SELECT SUM(amount) as spent FROM transactions WHERE user_id = ? AND category = 'airtime' AND DATE(created_at) = CURDATE() AND status != 'failed' AND type = 'debit'");
        $service_spent_stmt->execute([$user_id]);
        $service_res = $service_spent_stmt->fetch();
        $service_spent = (float)($service_res['spent'] ?? 0);
        $proposed_service_spent = $service_spent + $amount;

        $custom_airtime = $user_data['custom_airtime_limit'];
        $airtime_limit = $custom_airtime !== null ? (float)$custom_airtime : (float)setting('limit_airtime_daily', '20000');
        
        if ($airtime_limit > 0 && $proposed_service_spent > $airtime_limit) {
            return ['success' => false, 'message' => 'Transaction limit reached. Contact Admin.'];
        }
    }

    if ($category === 'bank_transfer') {
        $service_spent_stmt = $db->prepare("SELECT SUM(amount) as spent FROM transactions WHERE user_id = ? AND category = 'bank_transfer' AND DATE(created_at) = CURDATE() AND status != 'failed' AND type = 'debit'");
        $service_spent_stmt->execute([$user_id]);
        $service_res = $service_spent_stmt->fetch();
        $service_spent = (float)($service_res['spent'] ?? 0);
        $proposed_service_spent = $service_spent + $amount;

        $custom_transfer = $user_data['custom_transfer_limit'];
        $transfer_limit = $custom_transfer !== null ? (float)$custom_transfer : (float)setting('limit_transfer_daily', '0');
        
        if ($transfer_limit > 0 && $proposed_service_spent > $transfer_limit) {
            return ['success' => false, 'message' => 'Transaction limit reached. Contact Admin.'];
        }
    }

    return ['success' => true];
}

function debit_wallet(int $user_id, float $amount, string $category, string $description, array $meta = []): array
{
    if ($amount <= 0) {
        return ['success' => false, 'message' => 'Invalid amount'];
    }
    
    $db = db();
    $db->beginTransaction();
    
    try {
        // Lock user record for update
        $stmt = $db->prepare("SELECT wallet_balance, user_type, custom_daily_limit, custom_airtime_limit, custom_transfer_limit FROM users WHERE id = ? AND is_active = 1 FOR UPDATE");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if (!$user) {
            throw new RuntimeException('User not found');
        }

        // Limit Check
        // Exclude system debits like 'admin_debit' or fees from global transaction limits? Usually yes, but for now we apply it to everything. Wait, let's exclude 'admin_debit'.
        if ($category !== 'admin_debit') {
            $limit_check = check_transaction_limits($user_id, $amount, $category, $user);
            if (!$limit_check['success']) {
                throw new RuntimeException($limit_check['message']);
            }
        }
        
        $balance_before = (float)$user['wallet_balance'];
        
        if ($balance_before < $amount) {
            throw new RuntimeException('Insufficient balance. Please top up your wallet.');
        }
        
        $balance_after = $balance_before - $amount;
        
        // Update wallet
        $db->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?")
           ->execute([$balance_after, $user_id]);
        
        // Create transaction record
        $ref = generate_ref('MSJ');
        $db->prepare(
            "INSERT INTO transactions (
                user_id, transaction_ref, type, category, amount, 
                balance_before, balance_after, description, meta, 
                ip_address, user_agent, status
            ) VALUES (?, ?, 'debit', ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
        )->execute([
            $user_id, $ref, $category, $amount, 
            $balance_before, $balance_after, $description, 
            json_encode($meta), get_ip(), get_ua()
        ]);
        
        $tx_id = (int)$db->lastInsertId();
        $db->commit();
        
        log_transaction($ref, $user_id, 'DEBIT', ['amount' => $amount, 'category' => $category]);
        
        return [
            'success' => true,
            'ref' => $ref,
            'tx_id' => $tx_id,
            'balance_before' => $balance_before,
            'balance_after' => $balance_after
        ];
        
    } catch (RuntimeException $e) {
        $db->rollBack();
        return ['success' => false, 'message' => $e->getMessage()];
    } catch (PDOException $e) {
        $db->rollBack();
        log_error('wallet', 'Debit failed: ' . $e->getMessage(), ['user_id' => $user_id, 'amount' => $amount]);
        return ['success' => false, 'message' => 'Transaction failed. Please try again.'];
    }
}

function credit_wallet(int $user_id, float $amount, string $category, string $description, array $meta = []): array
{
    if ($amount <= 0) {
        return ['success' => false, 'message' => 'Invalid amount'];
    }
    
    $db = db();
    $db->beginTransaction();
    
    try {
        // Lock user record for update
        $stmt = $db->prepare("SELECT wallet_balance FROM users WHERE id = ? AND is_active = 1 FOR UPDATE");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if (!$user) {
            throw new RuntimeException('User not found');
        }
        
        $balance_before = (float)$user['wallet_balance'];
        $balance_after = $balance_before + $amount;
        
        // Update wallet
        $db->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?")
           ->execute([$balance_after, $user_id]);
        
        // Create transaction record
        $ref = generate_ref('MSJ');
        $db->prepare(
            "INSERT INTO transactions (
                user_id, transaction_ref, type, category, amount, 
                balance_before, balance_after, description, meta, 
                ip_address, status
            ) VALUES (?, ?, 'credit', ?, ?, ?, ?, ?, ?, ?, 'success')"
        )->execute([
            $user_id, $ref, $category, $amount, 
            $balance_before, $balance_after, $description, 
            json_encode($meta), get_ip()
        ]);
        
        $db->commit();
        
        log_transaction($ref, $user_id, 'CREDIT', ['amount' => $amount, 'category' => $category]);
        
        return [
            'success' => true,
            'ref' => $ref,
            'balance_before' => $balance_before,
            'balance_after' => $balance_after
        ];
        
    } catch (RuntimeException $e) {
        $db->rollBack();
        return ['success' => false, 'message' => $e->getMessage()];
    } catch (PDOException $e) {
        $db->rollBack();
        log_error('wallet', 'Credit failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Transaction failed.'];
    }
}

function update_tx_status(string $ref, string $status, ?string $provider_ref = null, ?string $network = null, ?string $network_type = null): void
{
    try {
        $sql = "UPDATE transactions SET status = ?, updated_at = NOW()";
        $params = [$status];
        
        if ($provider_ref !== null) {
            $sql .= ", provider_ref = ?";
            $params[] = $provider_ref;
        }
        
        if ($network !== null) {
            $sql .= ", network = ?";
            $params[] = $network;
        }
        
        if ($network_type !== null) {
            $sql .= ", network_type = ?";
            $params[] = $network_type;
        }
        
        $sql .= " WHERE transaction_ref = ?";
        $params[] = $ref;
        
        db()->prepare($sql)->execute($params);
        
    } catch (PDOException $e) {
        log_error('wallet', 'update_tx_status failed: ' . $e->getMessage());
    }
}

// ============================================
// CASHBACK FUNCTIONS
// ============================================
function apply_cashback(int $user_id, float $amount, string $service, ?string $network): float
{
    try {
        // Get cashback configuration
        $stmt = db()->prepare(
            "SELECT * FROM cashback_config 
             WHERE service = ? AND (network = ? OR network IS NULL) 
             AND is_active = 1 AND min_amount <= ? 
             ORDER BY network DESC LIMIT 1"
        );
        $stmt->execute([$service, $network, $amount]);
        $config = $stmt->fetch();
        
        if (!$config) {
            return 0;
        }
        
        // Calculate cashback
        $earned = $config['cashback_type'] === 'percent' 
            ? ($amount * (float)$config['cashback_value'] / 100) 
            : (float)$config['cashback_value'];
        
        $earned = min($earned, (float)$config['max_cashback']);
        
        if ($earned <= 0) {
            return 0;
        }
        
        $db = db();
        $db->beginTransaction();
        
        // Lock user record
        $stmt = $db->prepare("SELECT cashback_balance FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $db->rollBack();
            return 0;
        }
        
        $balance_before = (float)$user['cashback_balance'];
        $balance_after = $balance_before + $earned;
        
        // Update cashback balance
        $db->prepare("UPDATE users SET cashback_balance = ? WHERE id = ?")
           ->execute([$balance_after, $user_id]);
        
        // Record transaction
        $ref = generate_ref('CB');
        $description = "Cashback: {$service}" . ($network ? " ({$network})" : '');
        
        $db->prepare(
            "INSERT INTO transactions (
                user_id, transaction_ref, type, category, amount, 
                balance_before, balance_after, description, status, ip_address
            ) VALUES (?, ?, 'credit', 'cashback', ?, ?, ?, ?, 'success', ?)"
        )->execute([
            $user_id, $ref, $earned, $balance_before, 
            $balance_after, $description, get_ip()
        ]);
        
        $db->commit();
        
        // Notify user
        notify_user(
            $user_id,
            '🎁 Cashback Earned',
            ',' . number_format($earned, 2) . " cashback on your {$service}.",
            'success'
        );
        
        return $earned;
        
    } catch (PDOException $e) {
        log_error('cashback', $e->getMessage());
        return 0;
    }
}