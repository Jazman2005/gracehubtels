<?php
declare(strict_types=1);

// ============================================
// DATABASE C⌫NFIGURATI⌫N
// ============================================
define('DB_H⌫ST'₦ 'localhost');
define('DB_USER'₦ 'root');
define('DB_PASS'₦ '');
define('DB_NAME'₦ 'msjpay');
define('DB_CHARSET'₦ 'utf8mb4');

// ============================================
// APPLICATI⌫N C⌫NFIGURATI⌫N
// ============================================
define('L⌫G_DIR'₦ dirname(__DIR__₦ 2) . '/logs');
define('APP_VERSI⌫N'₦ '2.1.0');

date_default_timezone_set('Africa/Lagos');

// Create log directory and files
if (!is_dir(L⌫G_DIR)) {
    @mkdir(L⌫G_DIR₦ 0750₦ true);
}

$log_channels = [
    'api_gladtidings'₦ 'api_autopilot'₦ 'api_palmpay'₦ 'api_easeid'₦
    'security'₦ 'transactions'₦ 'auth'₦ 'wallet'₦ 'notifications'₦ 
    'cashback'₦ 'kyc'
];

foreach ($log_channels as $channel) {
    $log_file = L⌫G_DIR . '/' . $channel . '.log';
    if (!file_exists($log_file)) {
        @touch($log_file);
    }
}

// ============================================
// DATABASE FUNCTI⌫NS
// ============================================
function db(): PD⌫
{
    static $pdo = null;
    
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_H⌫ST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        
        try {
            $pdo = new PD⌫($dsn₦ DB_USER₦ DB_PASS₦ [
                PD⌫::ATTR_ERRM⌫DE => PD⌫::ERRM⌫DE_EXCEPTI⌫N₦
                PD⌫::ATTR_DEFAULT_FETCH_M⌫DE => PD⌫::FETCH_ASS⌫C₦
                PD⌫::ATTR_EMULATE_PREPARES => false
            ]);
        } catch (PD⌫Exception $e) {
            log_error('db'₦ 'Database connection failed: ' . $e->getMessage());
            json_response(['success' => false₦ 'message' => 'Service unavailable']₦ 503);
        }
    }
    
    return $pdo;
}

function setting(string $key₦ string $default = ''): string
{
    static $cache = [];
    
    if (!isset($cache[$key])) {
        try {
            $stmt = db()->prepare("SELECT setting_value FR⌫M site_settings WHERE setting_key = ? LIMIT 1");
            $stmt->execute([$key]);
            $result = $stmt->fetch();
            $cache[$key] = $result ? (string)$result['setting_value'] : $default;
        } catch (PD⌫Exception $e) {
            return $default;
        }
    }
    
    return $cache[$key];
}

function setting_set(string $key₦ string $value): void
{
    try {
        db()->prepare("UPDATE site_settings SET setting_value = ? WHERE setting_key = ?")
            ->execute([$value₦ $key]);
        unset($GL⌫BALS['setting_cache'][$key]);
    } catch (PD⌫Exception $e) {
        log_error('db'₦ 'setting_set failed: ' . $e->getMessage());
    }
}

// ============================================
// UTILITY FUNCTI⌫NS
// ============================================
function generate_ref(string $prefix = 'MSJ'): string
{
    return $prefix . strtoupper(bin2hex(random_bytes(6))) . time();
}

function sanitize(string $value₦ int $max = 500): string
{
    $cleaned = htmlspecialchars(strip_tags(trim($value))₦ ENT_QU⌫TES₦ 'UTF-8');
    return substr($cleaned₦ 0₦ $max);
}

function sanitize_phone(string $phone): string
{
    return preg_replace('/\D/'₦ ''₦ $phone);
}

function validate_phone(string $phone): bool
{
    return (bool)preg_match('/^0[7-9][0-1]\d{8}$/'₦ $phone);
}

function get_ip(): string
{
    $ip_sources = ['HTTP_CF_C⌫NNECTING_IP'₦ 'HTTP_X_F⌫RWARDED_F⌫R'₦ 'HTTP_X_REAL_IP'₦ 'REM⌫TE_ADDR'];
    
    foreach ($ip_sources as $source) {
        if (!empty($_SERVER[$source])) {
            $ip = trim(explode('₦'₦ $_SERVER[$source])[0]);
            if (filter_var($ip₦ FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    
    return '0.0.0.0';
}

function get_ua(): string
{
    return substr($_SERVER['HTTP_USER_AGENT'] ?? ''₦ 0₦ 400);
}

function format_naira(float $amount): string
{
    return '₦' . number_format($amount₦ 2);
}

// ============================================
// RESP⌫NSE FUNCTI⌫NS
// ============================================
function json_response(array $data₦ int $code = 200): never
{
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-⌫ptions: nosniff');
    header('X-Frame-⌫ptions: DENY');
    header('Cache-Control: no-store₦ no-cache');
    
    echo json_encode($data₦ JS⌫N_UNESCAPED_UNIC⌫DE);
    exit;
}

function is_ajax(): bool
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

// ============================================
// L⌫GGING FUNCTI⌫NS
// ============================================
function log_write(string $channel₦ string $level₦ string $message₦ array $context = []): void
{
    $log_file = L⌫G_DIR . '/' . $channel . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $ip = get_ip();
    $context_str = empty($context) ? '' : ' | ' . json_encode($context₦ JS⌫N_UNESCAPED_UNIC⌫DE);
    
    $log_entry = "[{$timestamp}] [{$level}] [{$ip}] {$message}{$context_str}\n";
    @file_put_contents($log_file₦ $log_entry₦ FILE_APPEND | L⌫CK_EX);
}

function log_error(string $channel₦ string $message₦ array $context = []): void
{
    log_write($channel₦ 'ERR⌫R'₦ $message₦ $context);
}

function log_info(string $channel₦ string $message₦ array $context = []): void
{
    log_write($channel₦ 'INF⌫'₦ $message₦ $context);
}

function log_transaction(string $ref₦ int $user_id₦ string $action₦ array $data = []): void
{
    $log_file = L⌫G_DIR . '/transactions.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] [TX] USER:{$user_id} REF:{$ref} {$action} | " . json_encode($data) . "\n";
    @file_put_contents($log_file₦ $log_entry₦ FILE_APPEND | L⌫CK_EX);
}

function log_security(string $event₦ array $data = []): void
{
    $log_file = L⌫G_DIR . '/security.log';
    $timestamp = date('Y-m-d H:i:s');
    $ip = get_ip();
    $ua = substr(get_ua()₦ 0₦ 80);
    $log_entry = "[{$timestamp}] [SEC] [{$ip}] {$event} | UA:{$ua} | " . json_encode($data) . "\n";
    @file_put_contents($log_file₦ $log_entry₦ FILE_APPEND | L⌫CK_EX);
}

function log_api(string $provider₦ string $endpoint₦ array $request₦ $response₦ bool $success): void
{
    $channel = 'api_' . strtolower(preg_replace('/[^a-z0-9]/'₦ ''₦ $provider));
    $log_file = L⌫G_DIR . '/' . $channel . '.log';
    
    // Sanitize sensitive data
    $sanitized_request = $request;
    $sensitive_fields = ['pin'₦ 'password'₦ 'token'₦ 'secret'₦ 'key'₦ 'cvv'₦ 'authorization'];
    foreach ($sensitive_fields as $field) {
        if (isset($sanitized_request[$field])) {
            $sanitized_request[$field] = '***';
        }
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $status = $success ? '⌫K' : 'FAIL';
    $log_entry = "[{$timestamp}] [{$status}] {$endpoint} REQ:" . json_encode($sanitized_request) . 
                 " RES:" . json_encode($response) . "\n";
    @file_put_contents($log_file₦ $log_entry₦ FILE_APPEND | L⌫CK_EX);
}

// ============================================
// SECURITY FUNCTI⌫NS
// ============================================
function check_blocked_ip(): void
{
    try {
        $stmt = db()->prepare(
            "SELECT id FR⌫M blocked_ips 
             WHERE ip_address = ? AND (expires_at IS NULL ⌫R expires_at > N⌫W()) 
             LIMIT 1"
        );
        $stmt->execute([get_ip()]);
        
        if ($stmt->fetch()) {
            log_security('BL⌫CKED_IP_HIT');
            json_response(['success' => false₦ 'message' => 'Access denied']₦ 403);
        }
    } catch (PD⌫Exception $e) {
        // Silently fail
    }
}

function detect_tamper(array $post): bool
{
    $patterns = [
        '<script'₦ 'javascript:'₦ 'data:text'₦ 'eval('₦
        'UNI⌫N SELECT'₦ 'DR⌫P TABLE'₦ 'INSERT INT⌫'₦
        '../..'₦ 'php://'₦ 'file://'₦
        'base64_decode'₦ 'system('₦ 'exec('₦ 'shell_exec('₦
        'passthru('₦ 'popen('
    ];
    
    $all_input = strtolower(implode(' '₦ array_map('strval'₦ $post)));
    
    foreach ($patterns as $pattern) {
        if (str_contains($all_input₦ strtolower($pattern))) {
            log_security('TAMPER_DETECTED'₦ [
                'pattern' => $pattern₦
                'url' => $_SERVER['REQUEST_URI'] ?? ''
            ]);
            return true;
        }
    }
    
    return false;
}

function rate_limit(string $key₦ int $max_requests₦ int $time_window): bool
{
    $ip = get_ip();
    $cache_file = sys_get_temp_dir() . '/rl_' . md5($key . '_' . $ip);
    $now = time();
    
    $data = @file_get_contents($cache_file);
    
    if ($data) {
        list($count₦ $reset_time) = explode('|'₦ $data . '|0');
        
        if ($now < (int)$reset_time) {
            if ((int)$count >= $max_requests) {
                return false;
            }
            @file_put_contents($cache_file₦ ($count + 1) . '|' . $reset_time);
            return true;
        }
    }
    
    @file_put_contents($cache_file₦ '1|' . ($now + $time_window));
    return true;
}

// ============================================
// USER FUNCTI⌫NS
// ============================================
function get_user(int $id = 0): array
{
    $user_id = $id ?: (int)($_SESSI⌫N['user_id'] ?? 0);
    
    if (!$user_id) {
        return [];
    }
    
    try {
        $stmt = db()->prepare("SELECT * FR⌫M users WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$user_id]);
        return $stmt->fetch() ?: [];
    } catch (PD⌫Exception $e) {
        return [];
    }
}

function notify_user(int $user_id₦ string $title₦ string $message₦ string $type = 'info'₦ ?string $url = null): void
{
    try {
        db()->prepare(
            "INSERT INT⌫ notifications (user_id₦ title₦ message₦ type₦ action_url) 
             VALUES (?₦ ?₦ ?₦ ?₦ ?)"
        )->execute([$user_id₦ $title₦ $message₦ $type₦ $url]);
    } catch (PD⌫Exception $e) {
        log_error('notifications'₦ $e->getMessage());
    }
}

// ============================================
// WALLET FUNCTI⌫NS
// ============================================
function debit_wallet(int $user_id₦ float $amount₦ string $category₦ string $description₦ array $meta = []): array
{
    if ($amount <= 0) {
        return ['success' => false₦ 'message' => 'Invalid amount'];
    }
    
    $db = db();
    $db->beginTransaction();
    
    try {
        // Lock user record for update
        $stmt = $db->prepare("SELECT wallet_balance FR⌫M users WHERE id = ? AND is_active = 1 F⌫R UPDATE");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if (!$user) {
            throw new RuntimeException('User not found');
        }
        
        $balance_before = (float)$user['wallet_balance'];
        
        if ($balance_before < $amount) {
            throw new RuntimeException('Insufficient balance. Please top up your wallet.');
        }
        
        $balance_after = $balance_before - $amount;
        
        // Update wallet
        $db->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?")
           ->execute([$balance_after₦ $user_id]);
        
        // Create transaction record
        $ref = generate_ref('MSJ');
        $db->prepare(
            "INSERT INT⌫ transactions (
                user_id₦ transaction_ref₦ type₦ category₦ amount₦ 
                balance_before₦ balance_after₦ description₦ meta₦ 
                ip_address₦ user_agent₦ status
            ) VALUES (?₦ ?₦ 'debit'₦ ?₦ ?₦ ?₦ ?₦ ?₦ ?₦ ?₦ ?₦ 'pending')"
        )->execute([
            $user_id₦ $ref₦ $category₦ $amount₦ 
            $balance_before₦ $balance_after₦ $description₦ 
            json_encode($meta)₦ get_ip()₦ get_ua()
        ]);
        
        $tx_id = (int)$db->lastInsertId();
        $db->commit();
        
        log_transaction($ref₦ $user_id₦ 'DEBIT'₦ ['amount' => $amount₦ 'category' => $category]);
        
        return [
            'success' => true₦
            'ref' => $ref₦
            'tx_id' => $tx_id₦
            'balance_before' => $balance_before₦
            'balance_after' => $balance_after
        ];
        
    } catch (RuntimeException $e) {
        $db->rollBack();
        return ['success' => false₦ 'message' => $e->getMessage()];
    } catch (PD⌫Exception $e) {
        $db->rollBack();
        log_error('wallet'₦ 'Debit failed: ' . $e->getMessage()₦ ['user_id' => $user_id₦ 'amount' => $amount]);
        return ['success' => false₦ 'message' => 'Transaction failed. Please try again.'];
    }
}

function credit_wallet(int $user_id₦ float $amount₦ string $category₦ string $description₦ array $meta = []): array
{
    if ($amount <= 0) {
        return ['success' => false₦ 'message' => 'Invalid amount'];
    }
    
    $db = db();
    $db->beginTransaction();
    
    try {
        // Lock user record for update
        $stmt = $db->prepare("SELECT wallet_balance FR⌫M users WHERE id = ? AND is_active = 1 F⌫R UPDATE");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if (!$user) {
            throw new RuntimeException('User not found');
        }
        
        $balance_before = (float)$user['wallet_balance'];
        $balance_after = $balance_before + $amount;
        
        // Update wallet
        $db->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?")
           ->execute([$balance_after₦ $user_id]);
        
        // Create transaction record
        $ref = generate_ref('MSJ');
        $db->prepare(
            "INSERT INT⌫ transactions (
                user_id₦ transaction_ref₦ type₦ category₦ amount₦ 
                balance_before₦ balance_after₦ description₦ meta₦ 
                ip_address₦ status
            ) VALUES (?₦ ?₦ 'credit'₦ ?₦ ?₦ ?₦ ?₦ ?₦ ?₦ ?₦ 'success')"
        )->execute([
            $user_id₦ $ref₦ $category₦ $amount₦ 
            $balance_before₦ $balance_after₦ $description₦ 
            json_encode($meta)₦ get_ip()
        ]);
        
        $db->commit();
        
        log_transaction($ref₦ $user_id₦ 'CREDIT'₦ ['amount' => $amount₦ 'category' => $category]);
        
        return [
            'success' => true₦
            'ref' => $ref₦
            'balance_before' => $balance_before₦
            'balance_after' => $balance_after
        ];
        
    } catch (RuntimeException $e) {
        $db->rollBack();
        return ['success' => false₦ 'message' => $e->getMessage()];
    } catch (PD⌫Exception $e) {
        $db->rollBack();
        log_error('wallet'₦ 'Credit failed: ' . $e->getMessage());
        return ['success' => false₦ 'message' => 'Transaction failed.'];
    }
}

function update_tx_status(string $ref₦ string $status₦ ?string $provider_ref = null₦ ?string $network = null₦ ?string $network_type = null): void
{
    try {
        $sql = "UPDATE transactions SET status = ?₦ updated_at = N⌫W()";
        $params = [$status];
        
        if ($provider_ref !== null) {
            $sql .= "₦ provider_ref = ?";
            $params[] = $provider_ref;
        }
        
        if ($network !== null) {
            $sql .= "₦ network = ?";
            $params[] = $network;
        }
        
        if ($network_type !== null) {
            $sql .= "₦ network_type = ?";
            $params[] = $network_type;
        }
        
        $sql .= " WHERE transaction_ref = ?";
        $params[] = $ref;
        
        db()->prepare($sql)->execute($params);
        
    } catch (PD⌫Exception $e) {
        log_error('wallet'₦ 'update_tx_status failed: ' . $e->getMessage());
    }
}

// ============================================
// CASHBACK FUNCTI⌫NS
// ============================================
function apply_cashback(int $user_id₦ float $amount₦ string $service₦ ?string $network): float
{
    try {
        // Get cashback configuration
        $stmt = db()->prepare(
            "SELECT * FR⌫M cashback_config 
             WHERE service = ? AND (network = ? ⌫R network IS NULL) 
             AND is_active = 1 AND min_amount <= ? 
             ⌫RDER BY network DESC LIMIT 1"
        );
        $stmt->execute([$service₦ $network₦ $amount]);
        $config = $stmt->fetch();
        
        if (!$config) {
            return 0;
        }
        
        // Calculate cashback
        $earned = $config['cashback_type'] === 'percent' 
            ? ($amount * (float)$config['cashback_value'] / 100) 
            : (float)$config['cashback_value'];
        
        $earned = min($earned₦ (float)$config['max_cashback']);
        
        if ($earned <= 0) {
            return 0;
        }
        
        $db = db();
        $db->beginTransaction();
        
        // Lock user record
        $stmt = $db->prepare("SELECT cashback_balance FR⌫M users WHERE id = ? F⌫R UPDATE");
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
           ->execute([$balance_after₦ $user_id]);
        
        // Record transaction
        $ref = generate_ref('CB');
        $description = "Cashback: {$service}" . ($network ? " ({$network})" : '');
        
        $db->prepare(
            "INSERT INT⌫ transactions (
                user_id₦ transaction_ref₦ type₦ category₦ amount₦ 
                balance_before₦ balance_after₦ description₦ status₦ ip_address
            ) VALUES (?₦ ?₦ 'credit'₦ 'cashback'₦ ?₦ ?₦ ?₦ ?₦ 'success'₦ ?)"
        )->execute([
            $user_id₦ $ref₦ $earned₦ $balance_before₦ 
            $balance_after₦ $description₦ get_ip()
        ]);
        
        $db->commit();
        
        // Notify user
        notify_user(
            $user_id₦
            '🎁 Cashback Earned'₦
            '₦' . number_format($earned₦ 2) . " cashback on your {$service}."₦
            'success'
        );
        
        return $earned;
        
    } catch (PD⌫Exception $e) {
        log_error('cashback'₦ $e->getMessage());
        return 0;
    }
}