<?php
declare(strict_types=1);

// Never leak error details (stack traces, file paths, SQL fragments) to
// visitors on ANY request — the AJAX-only suppression below only covered
// XHR/JSON requests, leaving every normal page load exposed to whatever
// display_errors happens to be set to in php.ini. Errors are still written
// to LOG_DIR/php_errors.log for debugging.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Suppress errors for AJAX to prevent JSON corruption
$is_ajax_req = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || 
               (isset($_SERVER['HTTP_ACCEPT']) && strpos(strtolower($_SERVER['HTTP_ACCEPT']), 'application/json') !== false) || 
               isset($_POST['csrf_token']);
if ($is_ajax_req) {
    error_reporting(0);
    ini_set('display_errors', '0');
    ob_start();
    register_shutdown_function(function() {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR, E_PARSE])) {
            file_put_contents(__DIR__ . '/fatal_ajax.log', date('Y-m-d H:i:s') . ' ' . json_encode($error) . "\n", FILE_APPEND);
        }
    });
}

// ============================================
// DATABASE CONFIGURATION
// ============================================
// Credentials live in the untracked db_config.php (see .gitignore) so real
// secrets never end up in version control.
$db_config_file = __DIR__ . '/db_config.php';
if (!file_exists($db_config_file)) {
    http_response_code(500);
    die('Missing database configuration. Copy db_config.php.example to db_config.php and fill in your credentials.');
}
require_once $db_config_file;

// ============================================
// APPLICATION CONFIGURATION
// ============================================
define('LOG_DIR', dirname(__DIR__, 2) . '/logs');
define('APP_VERSION', '2.1.0');
// Set to true in db_config.php only if a trusted reverse proxy (not Cloudflare,
// which is handled separately) sits in front of the app and sets X-Forwarded-For
// / X-Real-IP itself. Left false, get_ip() ignores those client-settable headers.
if (!defined('TRUST_FORWARDED_IP')) define('TRUST_FORWARDED_IP', false);

// A pre-computed bcrypt hash of an unguessable, never-used password. Every
// login endpoint runs password_verify() against this when no matching account
// is found, so a request for a nonexistent identifier takes the same amount
// of time as a request for a real one with a wrong password — without this,
// the `!$user || !password_verify(...)` short-circuit skips the (slow)
// bcrypt check entirely for nonexistent identifiers, making "no such account"
// distinguishable from "wrong password" purely by response time, a classic
// account-enumeration side channel.
define('DUMMY_PASSWORD_HASH', '$2y$12$IYTJDG2gpLbdGJvY9YyC1uWowi1o5FT7s86wEldd4hrXcUVxXs9HW');

date_default_timezone_set('Africa/Lagos');

// Create log directory and files
if (!is_dir(LOG_DIR)) {
    @mkdir(LOG_DIR, 0750, true);
}

$log_channels = [
    'api_autopilot', 'api_palmpay', 'api_easeid',
    'api_telecomabode',
    'api_paymentpoint', 'api_moniepoint', 'api_payvessel',
    'api_rechargecardapi', 'api_examapi', 'api_apicashwyre',
    'api_onesignalapi', 'api_gladtidings',
    'security', 'transactions', 'auth', 'wallet', 'notifications',
    'cashback', 'email', 'palmpay_api', 'webhook_palmpay', 'contact'
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
            try {
                $pdo->exec("SET time_zone = '+01:00';");
            } catch (Exception $tzException) {
                log_error('db', 'Timezone not supported by MySQL, falling back to server default');
            }
        } catch (PDOException $e) {
            log_error('db', 'Database connection failed: ' . $e->getMessage());
            json_response(['success' => false, 'message' => 'Service unavailable'], 503);
        }
    }
    
    return $pdo;
}

function setting(string $key, string $default = ''): string
{
    if (isset($GLOBALS['_setting_cache']) && array_key_exists($key, $GLOBALS['_setting_cache'])) {
        return $GLOBALS['_setting_cache'][$key];
    }
    
    try {
        $stmt = db()->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        $GLOBALS['_setting_cache'][$key] = $result ? (string)$result['setting_value'] : $default;
    } catch (PDOException $e) {
        return $default;
    }
    
    return $GLOBALS['_setting_cache'][$key];
}

function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function setting_set(string $key, string $value): void
{
    try {
        $stmt = db()->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$key, $value, $value]);
        unset($GLOBALS['_setting_cache'][$key]);
    } catch (PDOException $e) {
        log_error('db', 'setting_set failed for key: ' . $key . ' - ' . $e->getMessage());
    }
}

function table_exists(string $name): bool {
    static $cache = [];
    if (isset($cache[$name])) return $cache[$name];
    $s = db()->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $s->execute([$name]);
    return $cache[$name] = ((int)$s->fetchColumn() > 0);
}
function column_exists(string $table, string $column): bool {
    static $cache = [];
    $key = "$table.$column";
    if (isset($cache[$key])) return $cache[$key];
    $s = db()->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $s->execute([$table, $column]);
    return $cache[$key] = ((int)$s->fetchColumn() > 0);
}

// Per-install feature flags — this codebase is resold to multiple clients,
// each on their own separate database, so this table has no tenant column;
// "per install" simply means "per database". Absence of a row = enabled
// (fail open), so shipping this never breaks an already-live install that
// has never had a flag explicitly set — only a brand-new client, where the
// vendor deliberately disables what hasn't been paid for via
// grace-hub-tels/vendor-access.php, ends up with anything actually locked.
const FEATURE_REGISTRY = [
    'airtime' => 'Airtime',
    'data' => 'Data Bundles',
    'cabletv' => 'Cable TV',
    'electricity' => 'Electricity',
    'betting' => 'Betting',
    'virtual_card' => 'Virtual Cards',
    'exam_pin' => 'Exam PINs',
    'recharge_card' => 'Recharge Cards',
    'data_card' => 'Data Cards',
    'airtime_to_cash' => 'Airtime to Cash',
    'bank_transfer' => 'Bank Transfer',
    'bulk_sms' => 'Bulk SMS',
    'esim' => 'eSIM',
    'international_airtime' => 'International Airtime',
    'giftcard_buy' => 'Gift Cards (Buy)',
    'giftcard_sell' => 'Gift Cards (Sell for Cash)',
];

// Separate from FEATURE_REGISTRY above — these gate ADMIN-side tooling
// pages, not customer-facing purchase services. Kept as a distinct registry
// (rather than merged into FEATURE_REGISTRY) so vendor-access.php can group
// them visibly apart from services, and so it's obvious at a glance which
// keys are safe to lock without risking a client being unable to run their
// business — deliberately excludes anything operationally essential
// (Withdrawals, Settings, Staff Management, Security, API Registry).
const ADMIN_FEATURE_REGISTRY = [
    'admin_accounting' => 'Accounting (Liability Overview)',
    'admin_cashback' => 'Cashback Management',
    'admin_messaging_hub' => 'Messaging Hub (Broadcast Composer)',
    'admin_webhook_logs' => 'Webhook Logs',
    'admin_provider_health' => 'System Health',
    'admin_promo_codes' => 'Promo Codes',
];

// Known upstream VTU/service provider identifiers — the exact substrings
// every service file's provider loop already keys on (stripos($pname,'x')).
// This is a DIFFERENT kind of lock than FEATURE_REGISTRY/ADMIN_FEATURE_REGISTRY:
// a client's own admin has full control of their own api_providers/api_registry
// tables (each client runs a separate DB), so nothing stops them from adding
// a row for any provider whose integration code ships in this codebase. This
// registry lets the vendor block specific provider INTEGRATIONS from ever
// being routed through on a given install, regardless of what the client's
// own admin configures — enforced centrally in get_api_providers() and
// get_plan_bindings() (api_router.php), not per service file.
const PROVIDER_REGISTRY = [
    'reteller' => 'Reteller',
    'autosyncng' => 'AutoSyncNG',
    'autopilot' => 'AutoPilot',
    'gladtidings' => 'Gladtidings',
    'msjdatasubs' => 'MsjDataSubs',
    'telecomabode' => 'TelecomAbode',
    'sagecloud' => 'SageCloud',
];

// True if ANY locked provider key is a substring of $provider_name — same
// matching convention as every stripos($pname,'x') check throughout the
// service files, so this stays in lockstep with however they identify a
// provider row. Fails open (unlocked) on a DB hiccup — a vendor's config
// error must never be able to take down a client's live purchase flow.
function provider_locked(?string $provider_name): bool {
    if (!$provider_name) return false;
    static $locked = null;
    if ($locked === null) {
        $locked = [];
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS provider_locks (
                provider_key VARCHAR(50) NOT NULL PRIMARY KEY,
                is_locked TINYINT(1) NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )");
            $rows = db()->query("SELECT provider_key FROM provider_locks WHERE is_locked=1")->fetchAll(PDO::FETCH_COLUMN);
            $locked = $rows ?: [];
        } catch (\Throwable $e) {
            return false;
        }
    }
    $pname = strtolower($provider_name);
    foreach ($locked as $key) {
        if (stripos($pname, $key) !== false) return true;
    }
    return false;
}

// Shared response for a service that's wired into the nav/feature-flag
// system but has no real upstream provider connected yet — used instead of
// a fake/mocked purchase so nothing ever debits a wallet for a purchase that
// can't actually be fulfilled. Swap the call site to a real
// try_api_providers()/debit_wallet() flow once the provider is integrated.
function service_not_live_yet(string $label): array {
    return ['success' => false, 'message' => "$label isn't live yet — check back soon!"];
}

function feature_enabled(string $key): bool {
    static $flags = null;
    if ($flags === null) {
        $flags = [];
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS feature_flags (
                feature_key VARCHAR(50) NOT NULL PRIMARY KEY,
                is_enabled TINYINT(1) NOT NULL DEFAULT 1,
                label VARCHAR(100) DEFAULT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )");
            $rows = db()->query("SELECT feature_key, is_enabled FROM feature_flags")->fetchAll();
            foreach ($rows as $r) $flags[$r['feature_key']] = (bool)$r['is_enabled'];
        } catch (\Throwable $e) {
            return true; // fail open — a DB hiccup must never lock out a paying client
        }
    }
    return $flags[$key] ?? true;
}

// Shared "you don't have this yet" fragment — no <html>/chrome, just the
// inner card, so every surface (admin/user full page, or a single tab of a
// multi-service admin page like service-pin-products.php) can drop it into
// whatever shell is already rendering.
function feature_locked_card_html(string $key, string $context = 'user'): string {
    $label = htmlspecialchars(FEATURE_REGISTRY[$key] ?? ADMIN_FEATURE_REGISTRY[$key] ?? ucwords(str_replace('_', ' ', $key)), ENT_QUOTES);
    $site = htmlspecialchars(setting('site_name', 'Gracehubtels'), ENT_QUOTES);
    if ($context === 'admin') {
        // The reader here is the business owner/staff running THIS install —
        // not a customer of theirs. "Contact our team" (site_email) would
        // point them at their own support inbox, which is wrong; only show a
        // CTA if the vendor has configured a real contact channel for
        // themselves (VENDOR_CONTACT_EMAIL, per-install, set alongside
        // VENDOR_ACCESS_KEY in db_config.php) — otherwise omit it rather
        // than link somewhere meaningless.
        $body = $label . ' isn\'t active on this ' . $site . ' installation yet. Contact your platform provider to have it enabled for your account.';
        $cta = (defined('VENDOR_CONTACT_EMAIL') && VENDOR_CONTACT_EMAIL !== '')
            ? '<a href="mailto:' . htmlspecialchars(VENDOR_CONTACT_EMAIL, ENT_QUOTES) . '" class="btn-primary" style="text-decoration:none;display:inline-flex;align-items:center;gap:8px;">Contact Provider</a>'
            : '';
    } else {
        $body = $label . ' isn\'t included in your current ' . $site . ' plan. Contact our team to unlock it for your account.';
        $cta = '<a href="/user/support" style="text-decoration:none;display:inline-flex;align-items:center;gap:8px;background:var(--brand-navy,#1E293B);color:#fff;padding:12px 24px;border-radius:9999px;font-weight:600;">Contact Support</a>';
    }
    return '
    <div style="max-width:480px;margin:60px auto;text-align:center;padding:40px 32px;background:#fff;border:1px solid #e2e8f0;border-radius:16px;">
        <div style="width:64px;height:64px;margin:0 auto 20px;border-radius:9999px;background:#fef3c7;display:flex;align-items:center;justify-content:center;">
            <i class="fas fa-lock" style="font-size:24px;color:#d97706;"></i>
        </div>
        <h2 style="font-size:20px;font-weight:700;margin:0 0 8px;color:#0f172a;">This is a Premium Feature</h2>
        <p style="color:#64748b;font-size:14px;line-height:1.6;margin:0 0 24px;">' . $body . '</p>
        ' . $cta . '
    </div>';
}

// Builds an absolute site URL without ever hardcoding a domain — prefers an
// explicit site_domain setting (needed for cron/CLI contexts with no
// $_SERVER, or to force a canonical domain), otherwise auto-detects from the
// current request's own Host header. This means a fresh deploy to a new
// domain needs zero configuration for links (password resets, webhook
// callbacks, CORS) to already point at the right place.
function site_url(string $path = ''): string {
    $domain = setting('site_domain', '') ?: ($_SERVER['HTTP_HOST'] ?? 'gracehubtels.com');
    return 'https://' . rtrim($domain, '/') . '/' . ltrim($path, '/');
}

// Bare domain only (no scheme/path) — used for placeholder emails sent to
// third-party provider APIs that require an email field even when the user
// never gave us one, so the placeholder stays consistent with whatever
// domain this deployment is actually running on.
function site_domain(): string {
    return setting('site_domain', '') ?: ($_SERVER['HTTP_HOST'] ?? 'gracehubtels.com');
}

// CORS allow-list of both www and bare variants of this deployment's own
// domain — used by every api/v1/*.php endpoint's Access-Control-Allow-Origin
// check instead of a hardcoded domain list.
function site_allowed_origins(): array {
    $bare = preg_replace('/^www\./i', '', site_domain());
    return ['https://' . $bare, 'https://www.' . $bare];
}

// ============================================
// UTILITY FUNCTIONS
// ============================================
function generate_ref(string $prefix = 'MSJ'): string
{
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $ref = $prefix . strtoupper(bin2hex(random_bytes(6))) . time();
        try {
            $stmt = db()->prepare("SELECT 1 FROM transactions WHERE transaction_ref = ? LIMIT 1");
            $stmt->execute([$ref]);
            if (!$stmt->fetch()) return $ref;
        } catch (\Throwable $e) {
            return $ref; // DB unavailable — fall back to the raw value, unique constraint still backstops it on insert
        }
    }
    return $ref; // exhausted retries (astronomically unlikely) — insert will fail cleanly on the UNIQUE constraint
}

// Atomically claims a one-time key, for webhook handlers that mutate state outside
// the `transactions` table (so credit_wallet()'s own idempotency_key guard doesn't
// apply) — e.g. crediting a virtual card balance directly. Returns true the first
// time a given key is claimed, false on every subsequent attempt (including
// concurrent ones — the PRIMARY KEY makes this race-free, same principle as
// transactions.idempotency_key).
function claim_idempotency_key(string $key): bool
{
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS webhook_idempotency (
            idem_key VARCHAR(191) NOT NULL PRIMARY KEY,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) {}

    try {
        db()->prepare("INSERT INTO webhook_idempotency (idem_key) VALUES (?)")->execute([$key]);
        return true;
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            return false;
        }
        log_error('db', 'claim_idempotency_key failed: ' . $e->getMessage(), ['key' => $key]);
        return false;
    }
}

// Guards every purchase entrypoint against double-click / client-retry
// double-processing BEFORE any money moves — debit_wallet() itself accepts no
// idempotency key, and none of the purchase services checked for an
// already-in-flight identical request, so two near-simultaneous submissions
// of the same purchase (double-click, a browser retrying a "failed" request
// that actually just timed out, a replayed AJAX call) each independently
// passed CSRF + rate_limit() and produced two real debits. Builds a key from
// the user + category + the caller-supplied signature fields (e.g.
// phone+network+amount), bucketed into a short time window: two submissions
// of the *same* purchase within that window collide and the second is
// rejected outright before debit_wallet() is ever called; a genuine repeat
// purchase seconds later (different bucket) is unaffected. Call this as the
// very first thing in a service's process(), right after input validation.
function claim_purchase_lock(int $uid, string $category, array $signature_fields, int $window_seconds = 5): bool
{
    $bucket = (int) floor(time() / $window_seconds);
    $key = 'purchase:' . $category . ':' . $uid . ':' . md5(json_encode($signature_fields)) . ':' . $bucket;
    return claim_idempotency_key($key);
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

// Published Cloudflare IPv4 edge ranges (cloudflare.com/ips-v4) — anyone can set
// CF-Connecting-IP/X-Forwarded-For on a direct request, so those headers are only
// trustworthy when the TCP peer connecting to us is actually a Cloudflare edge node.
const CLOUDFLARE_IPV4_RANGES = [
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
    '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
    '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
    '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
];

function ip_in_cidr(string $ip, string $cidr): bool
{
    [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, '32');
    $ip_long = ip2long($ip);
    $subnet_long = ip2long($subnet);
    if ($ip_long === false || $subnet_long === false) return false;
    $mask = (int)$bits === 0 ? 0 : (-1 << (32 - (int)$bits));
    return ($ip_long & $mask) === ($subnet_long & $mask);
}

function get_ip(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $remote = filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';

    // Trust CF-Connecting-IP only when the direct TCP peer is a real Cloudflare edge IP.
    $from_cloudflare = false;
    foreach (CLOUDFLARE_IPV4_RANGES as $range) {
        if (ip_in_cidr($remote, $range)) { $from_cloudflare = true; break; }
    }
    if ($from_cloudflare && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = trim(explode(',', $_SERVER['HTTP_CF_CONNECTING_IP'])[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }

    // Explicit opt-in for other reverse-proxy setups (nginx, load balancer, etc.)
    // where the admin has confirmed X-Forwarded-For/X-Real-IP can be trusted.
    // Deliberately a file constant (not a DB setting): get_ip() is called from
    // db()'s own connection-failure handler, so it must never depend on db().
    if (defined('TRUST_FORWARDED_IP') && TRUST_FORWARDED_IP === true) {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $source) {
            if (!empty($_SERVER[$source])) {
                $ip = trim(explode(',', $_SERVER[$source])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
    }

    return $remote;
}

function get_ua(): string
{
    return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 400);
}

function db_timezone_is_utc(): bool {
    static $is_utc = null;
    if ($is_utc === null) {
        try {
            $s = db()->query("SELECT @@session.time_zone");
            $tz = $s->fetchColumn();
            $is_utc = ($tz === 'SYSTEM' || stripos($tz, '+00:00') !== false || $tz === '+00:00');
        } catch (Exception $e) {
            $is_utc = true;
        }
    }
    return $is_utc;
}

function fmt_tx_time(string $datetime, string $format = 'd M Y, g:i A'): string {
    if (db_timezone_is_utc()) {
        $dt = new DateTime($datetime, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Africa/Lagos'));
        return $dt->format($format);
    }
    return date($format, strtotime($datetime));
}

function format_naira(float $amount): string
{
    return '₦' . number_format($amount, 2);
}

function check_daily_limit(int $uid, float $amount, string $category = ''): ?string
{
    $user = get_user($uid);
    if (!$user) return 'User not found.';

    // Specific Limits
    if (in_array($category, ['airtime', 'data', 'electricity', 'cabletv', 'exam_pin'])) {
        $min = (float)setting('limit_airtime_min', '50');
        $max = (float)setting('limit_airtime_max', '10000');
        if ($category === 'airtime' && $user['custom_airtime_limit'] !== null) {
            $max = (float)$user['custom_airtime_limit'];
        }
        if ($amount < $min || $amount > $max) {
            return "Amount must be ₦" . number_format($min) . " – ₦" . number_format($max);
        }
    }

    if ($category === 'bank_transfer' || $category === 'internal_transfer') {
        $min = (float)setting('limit_transfer_min', '1000');
        $max = (float)setting('limit_transfer_max', '500000');
        if ($user['custom_transfer_limit'] !== null) {
            $max = (float)$user['custom_transfer_limit'];
        }
        if ($amount < $min || $amount > $max) {
            return "Transfer amount must be ₦" . number_format($min) . " – ₦" . number_format($max);
        }
    }

    // Daily Global Limit
    $limit = (float)setting('limit_daily_user', '50000');
    if ($user['user_type'] === 'agent') $limit = (float)setting('limit_daily_agent', '200000');
    if ($user['user_type'] === 'vendor') $limit = (float)setting('limit_daily_vendor', '1000000');

    // Override if set
    if ($user['custom_daily_limit'] !== null) {
        $limit = (float)$user['custom_daily_limit'];
    }

    $stmt = db()->prepare("SELECT SUM(amount) FROM transactions WHERE user_id = ? AND category != 'wallet_funding' AND DATE(created_at) = CURDATE() AND status IN ('success', 'pending', 'processing')");
    $stmt->execute([$uid]);
    $spent = (float)$stmt->fetchColumn();

    if (($spent + $amount) > $limit) {
        return 'Transaction limit exceeded. Contact admin.';
    }

    return null; // All good
}

// ============================================
// RESPONSE FUNCTIONS
// ============================================
function json_response(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    
    // Safely clear existing buffers without destroying them (prevents zlib infinite loop)
    while (ob_get_level() > 0) {
        if (!@ob_end_clean()) {
            break; // If we can't destroy the buffer (e.g. zlib), just break to avoid infinite loop
        }
    }
    
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Cache-Control: no-store, no-cache');
    
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $json = json_encode(['success' => false, 'message' => 'JSON Error: ' . json_last_error_msg()]);
    }
    echo $json;
    exit;
}

function is_ajax(): bool
{
    $ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
             strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
            (isset($_SERVER['HTTP_ACCEPT']) && strpos(strtolower($_SERVER['HTTP_ACCEPT']), 'application/json') !== false) ||
            isset($_POST['csrf_token']);
            
    if ($ajax) {
        error_reporting(0);
        ini_set('display_errors', '0');
    }
    return $ajax;
}

// ============================================
// LOGGING FUNCTIONS
// ============================================
const LOG_SENSITIVE_FIELDS = ['pin', 'password', 'token', 'secret', 'key', 'cvv', 'authorization', 'otp', 'bvn', 'nin'];

// Recursively masks sensitive fields (by key name) before anything is written to a
// log file. Also descends into values that are themselves JSON-encoded strings
// (e.g. a raw webhook payload stored under a 'payload' key), so a nested
// {"pin":"1234"} inside a logged string doesn't slip through unredacted.
function redact_sensitive($data)
{
    if (is_array($data)) {
        $out = [];
        foreach ($data as $k => $v) {
            $key_lower = is_string($k) ? strtolower($k) : '';
            $is_sensitive_key = false;
            foreach (LOG_SENSITIVE_FIELDS as $field) {
                if ($key_lower !== '' && str_contains($key_lower, $field)) { $is_sensitive_key = true; break; }
            }
            $out[$k] = $is_sensitive_key ? '***' : redact_sensitive($v);
        }
        return $out;
    }
    if (is_string($data)) {
        $trimmed = ltrim($data);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($data, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return json_encode(redact_sensitive($decoded), JSON_UNESCAPED_UNICODE);
            }
        }
        return $data;
    }
    return $data;
}

function log_write(string $channel, string $level, string $message, array $context = []): void
{
    $log_file = LOG_DIR . '/' . $channel . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $ip = get_ip();
    $safe_context = redact_sensitive($context);
    $context_str = empty($safe_context) ? '' : ' | ' . json_encode($safe_context, JSON_UNESCAPED_UNICODE);

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
    $log_entry = "[{$timestamp}] [TX] USER:{$user_id} REF:{$ref} {$action} | " . json_encode(redact_sensitive($data)) . "\n";
    @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

function log_security(string $event, array $data = []): void
{
    $log_file = LOG_DIR . '/security.log';
    $timestamp = date('Y-m-d H:i:s');
    $ip = get_ip();
    $ua = substr(get_ua(), 0, 80);
    $log_entry = "[{$timestamp}] [SEC] [{$ip}] {$event} | UA:{$ua} | " . json_encode(redact_sensitive($data)) . "\n";
    @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

function log_api(string $provider, string $endpoint, array $request, $response, bool $success): void
{
    $channel = 'api_' . preg_replace('/[^a-z0-9]/', '', strtolower($provider));
    $log_file = LOG_DIR . '/' . $channel . '.log';

    $sanitized_request = redact_sensitive($request);
    $sanitized_response = is_array($response) ? redact_sensitive($response) : $response;

    $timestamp = date('Y-m-d H:i:s');
    $status = $success ? 'OK' : 'FAIL';
    $log_entry = "[{$timestamp}] [{$status}] {$endpoint} REQ:" . json_encode($sanitized_request) .
                 " RES:" . json_encode($sanitized_response) . "\n";
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

    // Read-check-write on the counter file must happen under a single
    // exclusive lock — without it, concurrent/parallel requests within the
    // same window all read the same pre-increment count before any of them
    // writes back, letting an attacker burst past $max_requests by firing
    // requests in parallel instead of serially.
    $fh = @fopen($cache_file, 'c+');
    if (!$fh) {
        return true;
    }

    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        return true;
    }

    $data = stream_get_contents($fh);
    $allowed = true;

    if ($data) {
        list($count, $reset_time) = explode('|', $data . '|0');
        $count = (int)$count;
        $reset_time = (int)$reset_time;

        if ($now < $reset_time) {
            if ($count >= $max_requests) {
                $allowed = false;
            } else {
                $count++;
            }
        } else {
            $count = 1;
            $reset_time = $now + $time_window;
        }
    } else {
        $count = 1;
        $reset_time = $now + $time_window;
    }

    if ($allowed) {
        rewind($fh);
        ftruncate($fh, 0);
        fwrite($fh, $count . '|' . $reset_time);
        fflush($fh);
    }

    flock($fh, LOCK_UN);
    fclose($fh);

    return $allowed;
}

// ============================================
// USER FUNCTIONS
// ============================================
function get_user(int $id = 0): array
{
    static $cache = [];
    $user_id = $id ?: (int)($_SESSION['user_id'] ?? 0);
    
    if (!$user_id) {
        return [];
    }
    
    if (isset($cache[$user_id])) return $cache[$user_id];
    
    try {
        $stmt = db()->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch() ?: [];
        if ($result) $cache[$user_id] = $result;
        return $result;
    } catch (PDOException $e) {
        return [];
    }
}

function notify_user(int $user_id, string $title, string $message, string $type = 'info', ?string $url = null, ?float $balance_before = null, ?float $balance_after = null): void
{
    try {
        if ($balance_before !== null && $balance_after !== null) {
            $message .= " | Bal: ₦" . number_format($balance_before, 2) . " → ₦" . number_format($balance_after, 2);
        }
        db()->prepare(
            "INSERT INTO notifications (user_id, title, message, type, action_url) 
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$user_id, $title, $message, $type, $url]);
        
        // Also send email. $title/$message are plain text everywhere in this
        // codebase (no caller embeds real HTML), but a few paths — the vtu webhook
        // and cron_check_vtu.php's provider-status poll — forward a raw upstream
        // provider "message"/"details" string here. Escape before it lands in the
        // HTML email body so a malicious/spoofed provider response can't inject
        // markup into a real customer's inbox. The in-app notifications feed and
        // push payload are unaffected — both are stored/sent as plain text already
        // and escape independently at their own render points.
        $user = get_user($user_id);
        if ($user && !empty($user['email'])) {
            send_email($user['email'], htmlspecialchars($title, ENT_QUOTES), htmlspecialchars($message, ENT_QUOTES));
        }
        
        // Notify admin for funding events (covers webhooks, admin manual funding, etc.)
        if (stripos($title, 'wallet funded') !== false || stripos($title, 'funded') !== false) {
            $amount = '';
            if (preg_match('/₦([\d,]+(?:\.\d{2})?)/', $message, $m)) {
                $amount = $m[1];
            }
            notify_admin('Wallet Funded', "
<div style='text-align:center;padding:10px 0 16px;'>
<span style='font-size:28px;line-height:1;font-weight:800;color:#16a34a;'>+$</span>
<h3 style='color:#4F46E5;font-size:18px;margin:8px 0 4px;'>Wallet Funding Notification</h3>
<p style='color:#475569;font-size:13px;margin:0;'>A user's wallet has been funded.</p>
</div>
<table style='width:100%;border-collapse:collapse;'>
<tr><td style='padding:8px 12px;background:#f8fafc;border-radius:8px;'>
<table width='100%'>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>User</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>" . htmlspecialchars($user['full_name']??$user['phone']??'Unknown', ENT_QUOTES) . "</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Phone</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>" . htmlspecialchars($user['phone']??'', ENT_QUOTES) . "</td></tr>
" . ($amount ? "<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Amount</td><td style='padding:4px 0;text-align:right;font-weight:700;color:#16a34a;font-size:14px;'>₦{$amount}</td></tr>" : "") . "
</table>
</td></tr>
</table>");
        }
        
        // Send OneSignal push if user has it enabled
        if ($user && !empty($user['notify_push'])) {
            require_once __DIR__ . '/onesignal.php';
            $oneSignal = new OneSignalAPI();
            $oneSignal->send_push($title, $message, (string)$user_id, ['type' => $type, 'url' => $url]);
        }
    } catch (PDOException $e) {
        log_error('notifications', $e->getMessage());
    }
}

function notify_admin(string $subject, string $message): void {
    $email = setting('admin_notify_email', '') ?: setting('site_email', '');
    if ($email) {
        try { send_email($email, $subject, $message); } catch (\Throwable $e) { log_error('admin_notify', $e->getMessage()); }
    }
}

$GLOBALS['_last_email_error'] = '';
function send_email(string $to, string $subject, string $body): bool
{
    $GLOBALS['_last_email_error'] = '';
    try {
        $site_name = setting('site_name', 'Gracehubtels');
        $site_email = setting('site_email', 'noreply@gracehubtels.com');
        $site_phone = setting('site_phone', '');
        $site_address = setting('site_address', '');
        $site_tagline = setting('site_tagline', 'Secure VTU Platform');
        
        $htmlBody = "
<!DOCTYPE html>
<html>
<head><meta charset='UTF-8'><meta name='viewport' content='width=device-width, initial-scale=1.0'></head>
<body style='margin:0;padding:0;background-color:#f0f2f5;font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,Helvetica,Arial,sans-serif;'>
<table role='presentation' width='100%' cellpadding='0' cellspacing='0' style='background-color:#f0f2f5;padding:30px 10px;'>
<tr><td align='center'>
<table role='presentation' width='100%' style='max-width:600px;background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.05);'>
<!-- Letterhead Header -->
<tr><td style='padding:0;'>
<table role='presentation' width='100%' style='background:linear-gradient(135deg,#4F46E5 0%,#6366F1 100%);'>
<tr><td style='padding:30px 40px 20px;text-align:center;'>
<div style='display:inline-block;width:64px;height:64px;background:rgba(255,255,255,0.12);border-radius:50%;line-height:64px;font-size:24px;font-weight:800;color:#ffffff;margin-bottom:10px;'>MSJ</div>
<h1 style='color:#ffffff;font-size:24px;font-weight:800;margin:0;letter-spacing:-0.5px;'>{$site_name}</h1>
<p style='color:rgba(255,255,255,0.75);font-size:13px;margin:4px 0 0;font-weight:400;'>{$site_tagline}</p>
</td></tr>
" . ($site_address || $site_phone ? "
<tr><td style='padding:10px 40px 20px;text-align:center;border-top:1px solid rgba(255,255,255,0.1);'>
<table role='presentation' align='center' cellpadding='0' cellspacing='0'>
<tr>" . ($site_address ? "<td style='padding:0 10px;color:rgba(255,255,255,0.6);font-size:11px;'>{$site_address}</td>" : "") . ($site_phone ? "<td style='padding:0 10px;color:rgba(255,255,255,0.6);font-size:11px;'>Phone: {$site_phone}</td>" : "") . "<td style='padding:0 10px;color:rgba(255,255,255,0.6);font-size:11px;'>Email: {$site_email}</td></tr>
</table>
</td></tr>" : "") . "
</table>
</td></tr>
<!-- Decorative Divider -->
<tr><td style='padding:0 40px;'><div style='height:4px;background:linear-gradient(90deg,#4F46E5,#3b82f6,#4F46E5);border-radius:2px;margin-top:-2px;'></div></td></tr>
<!-- Content -->
<tr><td style='padding:32px 40px;'>
<h2 style='color:#4F46E5;font-size:18px;font-weight:700;margin:0 0 20px;letter-spacing:-0.3px;'>{$subject}</h2>
<div style='color:#475569;font-size:14px;line-height:1.8;'>{$body}</div>
</td></tr>
<!-- Footer with Company Letterhead -->
<tr><td style='background-color:#f8fafc;padding:24px 40px;text-align:center;border-top:2px solid #e2e8f0;'>
<div style='margin-bottom:10px;'>
<span style='font-weight:700;color:#4F46E5;font-size:14px;'>{$site_name}</span>
</div>
" . ($site_address ? "<p style='margin:0 0 3px;font-size:11px;color:#64748b;'>{$site_address}</p>" : "") . "
<p style='margin:0 0 3px;font-size:11px;color:#64748b;'>" . ($site_phone ? "Phone: {$site_phone} | " : "") . "Email: {$site_email}</p>
<hr style='border:none;border-top:1px solid #e2e8f0;margin:12px 0;'>
<p style='margin:0 0 2px;font-size:11px;color:#94a3b8;'>&copy; " . date('Y') . " {$site_name}. All rights reserved.</p>
<p style='margin:0;font-size:10px;color:#94a3b8;'>This is an automated message. Please do not reply directly.</p>
</td></tr>
</table>
</td></tr></table>
</body>
</html>";

        $smtp_host = setting('smtp_host');
        if (!$smtp_host) { $GLOBALS['_last_email_error'] = 'SMTP host not configured'; return false; }
        
        $smtp_port = (int)setting('smtp_port', '465');
        $smtp_user = setting('smtp_user');
        $smtp_pass = setting('smtp_pass');
        $smtp_from = setting('smtp_from_email', $site_email);
        $smtp_name = setting('smtp_from_name', $site_name);
        $enc = setting('smtp_encryption', '');
        $use_tls = ($enc === 'tls') || ($enc === '' && $smtp_port === 587);
        
        // Use PHPMailer if available, otherwise native mail()
        $base_dir = dirname(__DIR__, 2);
        $vendor_dir = $base_dir . '/vendor';
        $pm_dir = file_exists($vendor_dir . '/PHPMailer') ? $vendor_dir . '/PHPMailer' : $vendor_dir . '/phpmailer';
        if (file_exists($pm_dir . '/src/PHPMailer.php')) {
            require_once $pm_dir . '/src/Exception.php';
            require_once $pm_dir . '/src/PHPMailer.php';
            require_once $pm_dir . '/src/SMTP.php';
            try {
                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                // Without a cap, an unreachable/slow SMTP host can hang this
                // call for minutes (PHPMailer's own default), blocking
                // whatever synchronous flow triggered the email — e.g. the
                // admin OTP login step, which never reaches its redirect
                // until send_email() returns.
                $mail->Timeout = 8;
                $mail->Host = $smtp_host; $mail->SMTPAuth = true;
                $mail->Username = $smtp_user; $mail->Password = $smtp_pass;
                $mail->SMTPSecure = $use_tls ? 'tls' : 'ssl';
                $mail->Port = $smtp_port;
                $mail->setFrom($smtp_from, $smtp_name);
                $mail->addAddress($to);
                $mail->isHTML(true); $mail->CharSet = 'UTF-8'; $mail->Subject = $subject; $mail->Body = $htmlBody;
                $mail->SMTPDebug = 0;
                $mail->Debugoutput = function($str, $lvl) { log_error('email', "SMTP DEBUG [$lvl] $str"); };
                $mail->send();
                return true;
            } catch (Exception $e) {
                $err = isset($mail) ? $mail->ErrorInfo : $e->getMessage();
                $GLOBALS['_last_email_error'] = $err;
                log_error('email', "PHPMailer failed: $err");
                return false;
            }
        }
        
        // Fallback: native mail()
        $headers = "From: $smtp_name <$smtp_from>\r\nReply-To: $smtp_from\r\nMIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\n";
        $ok = @mail($to, $subject, $htmlBody, $headers, "-f $smtp_from");
        if (!$ok) { $GLOBALS['_last_email_error'] = 'PHP mail() returned false'; return false; }
        return true;
    } catch (\Throwable $t) {
        $GLOBALS['_last_email_error'] = $t->getMessage();
        log_error('email_fatal', "Fatal error sending email: " . $t->getMessage());
        return false;
    }
}

// ============================================
// WHATSAPP NOTIFICATIONS (Akmond Notify)
// ============================================
function send_whatsapp_alert(string $message, string $recipient = ''): bool
{
    $id_instance = setting('green_api_id_instance', '');
    $token_instance = setting('green_api_token_instance', '');
    if (empty($id_instance) || empty($token_instance)) {
        return false;
    }
    if (empty($recipient)) {
        $recipient = setting('green_api_recipient', '');
        if (empty($recipient)) {
            return false;
        }
        
        // Admin Notification Preferences Check
        if (stripos($message, 'Withdrawal') !== false && setting('wa_notif_withdrawals', '1') === '0') return false;
        if (stripos($message, 'A2C') !== false && setting('wa_notif_a2c', '1') === '0') return false;
        if ((stripos($message, 'Manual') !== false || stripos($message, 'Deposit') !== false) && setting('wa_notif_manual_funding', '1') === '0') return false;
        if (stripos($message, 'Admin Login') !== false && setting('wa_notif_admin_login', '1') === '0') return false;
        if (stripos($message, 'Failed') !== false && setting('wa_notif_failed_tx', '1') === '0') return false;
    }

    $chat_id = preg_replace('/[^0-9]/', '', $recipient) . '@c.us';
    $payload = ['chatId' => $chat_id, 'message' => $message];
    $url = "https://api.green-api.com/waInstance{$id_instance}/sendMessage/{$token_instance}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $res = @curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($res === false || $http_code !== 200) {
        log_error('whatsapp', "Alert failed: $error (HTTP $http_code)");
        return false;
    }
    return true;
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
        // Setting key uses 'user' for the regular tier (matches check_daily_limit()
        // and the admin Settings/Transaction Limits UI) — 'regular' itself is never
        // written anywhere, so using it here silently ignored the admin-configured
        // limit for every non-bank_transfer/betting/bulk_sms/cabletv/internal_transfer
        // debit (airtime, data, electricity, exam_pin, virtual cards, ...).
        $setting_role = $role === 'regular' ? 'user' : $role;
        $global_limit = (float)setting("limit_daily_{$setting_role}", $role === 'vendor' ? '1000000' : ($role === 'agent' ? '200000' : '50000'));
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

    // Generic per-category daily cap for every other category (betting,
    // bulk_sms, cabletv, electricity, data, exam_pin, ...). No admin UI
    // currently exposes a limit_<category>_daily setting for these the way
    // it does for airtime/bank_transfer above, so this defaults to 0
    // (disabled) and is a no-op today — but it's checked under the same row
    // lock as the two blocks above, so the moment such a setting IS
    // configured (directly or via a future admin UI addition), it's
    // enforced atomically from day one rather than only via the separate,
    // unlocked check_daily_limit() pre-check that per-transaction bounds
    // currently rely on.
    if (!in_array($category, ['airtime', 'bank_transfer'], true)) {
        $cat_limit = (float)setting("limit_{$category}_daily", '0');
        if ($cat_limit > 0) {
            $service_spent_stmt = $db->prepare("SELECT SUM(amount) as spent FROM transactions WHERE user_id = ? AND category = ? AND DATE(created_at) = CURDATE() AND status != 'failed' AND type = 'debit'");
            $service_spent_stmt->execute([$user_id, $category]);
            $service_spent = (float)($service_spent_stmt->fetch()['spent'] ?? 0);
            if (($service_spent + $amount) > $cat_limit) {
                return ['success' => false, 'message' => 'Transaction limit reached. Contact Admin.'];
            }
        }
    }

    return ['success' => true];
}

// Single definition of "what makes two purchases the same" — shared by
// claim_purchase_lock() (cheap first-pass, fixed 5s window) and
// debit_wallet()'s $dedupe_signature check (state-based, no time window).
function purchase_signature(string $category, array $fields): string {
    return $category . ':' . md5(json_encode($fields));
}

function debit_wallet(int $user_id, float $amount, string $category, string $description, array $meta = [], ?string $dedupe_signature = null): array
{
    if ($amount <= 0) {
        return ['success' => false, 'message' => 'Invalid amount'];
    }

    $db = db();
    $in_transaction = $db->inTransaction();
    if (!$in_transaction) {
        $db->beginTransaction();
    }

    try {
        // Lock user record for update
        $stmt = $db->prepare("SELECT wallet_balance, user_type, custom_daily_limit, custom_airtime_limit, custom_transfer_limit FROM users WHERE id = ? AND is_active = 1 FOR UPDATE");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();

        if (!$user) {
            throw new RuntimeException('User not found');
        }

        // claim_purchase_lock() (called earlier by every service, before this
        // function) only dedupes within a fixed 5-second window — a provider
        // call slower than that, followed by a retry after the window rolls
        // over, computes a different lock key and would otherwise sail
        // through as a "new" purchase. This check has no time window at all:
        // it's protected by the row lock just acquired above (which already
        // serializes every debit_wallet() call for this user), and only
        // clears once the prior matching transaction reaches a terminal
        // status (success/failed/refunded) — exactly the guarantee needed.
        if ($dedupe_signature !== null) {
            $dupe = $db->prepare("SELECT transaction_ref FROM transactions WHERE user_id = ? AND status IN ('pending','processing') AND dedupe_signature = ? LIMIT 1");
            $dupe->execute([$user_id, $dedupe_signature]);
            if ($dupe->fetch()) {
                throw new RuntimeException('A similar transaction is already being processed. Please wait for it to complete before retrying.');
            }
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
                ip_address, user_agent, status, dedupe_signature
            ) VALUES (?, ?, 'debit', ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)"
        )->execute([
            $user_id, $ref, $category, $amount,
            $balance_before, $balance_after, $description,
            json_encode($meta), get_ip(), get_ua(), $dedupe_signature
        ]);
        
        $tx_id = (int)$db->lastInsertId();
        if (!$in_transaction) {
            $db->commit();
        }
        
        log_transaction($ref, $user_id, 'DEBIT', ['amount' => $amount, 'category' => $category]);
        
        return [
            'success' => true,
            'ref' => $ref,
            'tx_id' => $tx_id,
            'balance_before' => $balance_before,
            'balance_after' => $balance_after
        ];
        
    } catch (RuntimeException $e) {
        if (!$in_transaction) {
            $db->rollBack();
        }
        return ['success' => false, 'message' => $e->getMessage()];
    } catch (PDOException $e) {
        if (!$in_transaction) {
            $db->rollBack();
        }
        log_error('wallet', 'Debit failed: ' . $e->getMessage(), ['user_id' => $user_id, 'amount' => $amount]);
        return ['success' => false, 'message' => 'Transaction failed. Please try again.'];
    }
}

function credit_wallet(int $user_id, float $amount, string $category, string $description, array $meta = [], ?string $idempotency_key = null): array
{
    if ($amount <= 0) {
        return ['success' => false, 'message' => 'Invalid amount'];
    }

    $db = db();
    $in_transaction = $db->inTransaction();
    if (!$in_transaction) {
        $db->beginTransaction();
    }

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

        // Create transaction record. When $idempotency_key is supplied, the UNIQUE
        // constraint on transactions.idempotency_key makes this insert the single
        // atomic gate against double-crediting from a replayed/duplicated webhook —
        // a second attempt with the same key throws a duplicate-key error below and
        // the whole transaction (including the balance update above) rolls back.
        $ref = generate_ref('MSJ');
        $db->prepare(
            "INSERT INTO transactions (
                user_id, transaction_ref, idempotency_key, type, category, amount,
                balance_before, balance_after, description, meta,
                ip_address, status
            ) VALUES (?, ?, ?, 'credit', ?, ?, ?, ?, ?, ?, ?, 'success')"
        )->execute([
            $user_id, $ref, $idempotency_key, $category, $amount,
            $balance_before, $balance_after, $description,
            json_encode($meta), get_ip()
        ]);
        if (!$in_transaction) {
            $db->commit();
        }

        log_transaction($ref, $user_id, 'CREDIT', ['amount' => $amount, 'category' => $category]);

        return [
            'success' => true,
            'ref' => $ref,
            'balance_before' => $balance_before,
            'balance_after' => $balance_after
        ];

    } catch (RuntimeException $e) {
        if (!$in_transaction) {
            $db->rollBack();
        }
        return ['success' => false, 'message' => $e->getMessage()];
    } catch (PDOException $e) {
        if (!$in_transaction) {
            $db->rollBack();
        }
        if ($idempotency_key !== null && (int)($e->errorInfo[1] ?? 0) === 1062) {
            log_info('wallet', 'Duplicate credit_wallet call ignored', ['idempotency_key' => $idempotency_key]);
            return ['success' => false, 'duplicate' => true, 'message' => 'Duplicate transaction (already processed)'];
        }
        log_error('wallet', 'Credit failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Transaction failed.'];
    }
}

function save_tx_response(string $ref, string $response_msg): void {
    if (!$response_msg) return;
    try {
        $stmt = db()->prepare("UPDATE transactions SET meta = JSON_SET(COALESCE(meta, '{}'), '$.response', ?) WHERE transaction_ref=?");
        $stmt->execute([$response_msg, $ref]);
        // A silent catch here previously meant this write could fail with
        // zero trace anywhere — confirmed live: a successful data purchase's
        // meta never got its 'response' key set, with no error surfaced
        // (refund/failure cases masked the same class of failure because
        // refund_failed_transaction() also writes meta.response itself as a
        // second, redundant path — success has no such backup). If this ever
        // affects zero rows, or throws, we now have a real trace instead of
        // silently losing the write.
        if ($stmt->rowCount() === 0) {
            log_error('db', 'save_tx_response: UPDATE affected 0 rows — ref not found?', ['ref' => $ref, 'len' => strlen($response_msg)]);
        }
    } catch (\Throwable $e) {
        log_error('db', 'save_tx_response failed: ' . $e->getMessage(), ['ref' => $ref, 'len' => strlen($response_msg)]);
    }
}
function refund_failed_transaction(string $ref, int $uid, float $amount, string $err_msg = ''): array
{
    $db = db();
    $in_transaction = $db->inTransaction();
    if (!$in_transaction) {
        $db->beginTransaction();
    }

    try {
        // Atomically claim this transaction BEFORE touching the wallet at all —
        // this used to credit the wallet unconditionally with no check on the
        // transaction's current status, so two concurrent/duplicate calls for
        // the same ref (a webhook and a cron timeout-sweep both firing for the
        // same stuck transaction, or a retried request) would each credit the
        // wallet separately: a real double-refund. This UPDATE is the single
        // atomic gate — only the caller that actually flips the row from
        // pending/processing to failed proceeds; everyone else sees
        // rowCount()===0 and must treat it as already handled, exactly like
        // update_tx_status()'s guard.
        $claim = $db->prepare("UPDATE transactions SET status = 'failed', updated_at = NOW() WHERE transaction_ref = ? AND user_id = ? AND status IN ('pending','processing')");
        $claim->execute([$ref, $uid]);
        if ($claim->rowCount() === 0) {
            if (!$in_transaction) $db->rollBack();
            log_info('wallet', 'refund_failed_transaction: ref already resolved, skipping duplicate refund', ['ref' => $ref, 'uid' => $uid]);
            return ['success' => false, 'duplicate' => true, 'message' => 'Transaction already resolved'];
        }

        // Trust the amount actually debited (recorded on the row itself) over
        // whatever the caller passed in — a caller-side bug computing the wrong
        // figure (e.g. forgetting to include a charge) would otherwise silently
        // under- or over-refund the user. Mismatches are logged so a genuine bug
        // still surfaces, but the wallet always gets made whole by the true amount.
        $orig = $db->prepare("SELECT amount FROM transactions WHERE transaction_ref = ? AND user_id = ? LIMIT 1");
        $orig->execute([$ref, $uid]);
        $orig_row = $orig->fetch();
        $recorded_amount = $orig_row ? (float)$orig_row['amount'] : $amount;
        if ($orig_row && abs($recorded_amount - $amount) > 0.01) {
            log_error('wallet', 'refund_failed_transaction: caller-supplied amount does not match recorded transaction amount — using recorded amount', ['ref' => $ref, 'uid' => $uid, 'caller_amount' => $amount, 'recorded_amount' => $recorded_amount]);
        }
        $amount = $recorded_amount;

        $stmt = $db->prepare("SELECT wallet_balance FROM users WHERE id = ? AND is_active = 1 FOR UPDATE");
        $stmt->execute([$uid]);
        $user = $stmt->fetch();
        if (!$user) throw new RuntimeException('User not found');

        $balance_before = (float)$user['wallet_balance'];
        $new_balance = $balance_before + $amount;
        $db->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?")->execute([$new_balance, $uid]);

        $sql = "UPDATE transactions SET balance_after = ?, updated_at = NOW()";
        if ($err_msg !== '') {
            $sql .= ", meta = JSON_SET(COALESCE(meta, '{}'), '$.response', ?)";
            $db->prepare($sql . " WHERE transaction_ref = ?")->execute([$new_balance, $err_msg, $ref]);
        } else {
            $db->prepare($sql . " WHERE transaction_ref = ?")->execute([$new_balance, $ref]);
        }

        if (!$in_transaction) $db->commit();

        log_transaction($ref, $uid, 'SILENT_REFUND', ['amount' => $amount, 'reason' => 'Transaction failed']);
        notify_user($uid, 'Transaction Failed', "Your transaction was unsuccessful and ₦".number_format($amount, 2)." has been refunded. Reason: " . ($err_msg ?: 'Failed'), 'danger', "/user/receipt.php?ref=$ref", $balance_before, $new_balance);
        return ['success' => true];
    } catch (\Throwable $e) {
        if (!$in_transaction) {
            try { $db->rollBack(); } catch (\Throwable $t) {}
        }
        log_error('wallet', 'Silent Refund failed: ' . $e->getMessage(), ['ref' => $ref, 'uid' => $uid]);
        return ['success' => false, 'message' => 'Refund failed: ' . $e->getMessage()];
    }
}

// Returns true only if this call actually transitioned the row (i.e. it was still
// pending/processing at the time). Multiple independent processes can race to
// resolve the same transaction — a webhook callback, cron_check_vtu.php's polling
// fallback, and cron_refund.php's timeout sweep can all fire for the same ref
// within moments of each other. Guarding on the current status and checking
// rowCount() makes this an atomic claim: only the process that gets true here
// should go on to credit/refund the wallet or notify the user: everyone else
// lost the race and must treat it as already handled.
function update_tx_status(string $ref, string $status, ?string $provider_ref = null, ?string $network = null, ?string $network_type = null): bool
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

        $sql .= " WHERE transaction_ref = ? AND status IN ('pending','processing')";
        $params[] = $ref;

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $changed = $stmt->rowCount() > 0;

        // WhatsApp alert on failed transactions — only for the process that actually
        // performed the transition, so a replayed/duplicate callback doesn't re-alert.
        if ($changed && $status === 'failed') {
            try {
                $tx = db()->prepare("SELECT t.*, u.full_name, u.email, u.phone FROM transactions t JOIN users u ON t.user_id = u.id WHERE t.transaction_ref = ? LIMIT 1");
                $tx->execute([$ref]);
                $tx_data = $tx->fetch();
                if ($tx_data) {
                    $msg = "⚠️ *Transaction Failed!*\n"
                         . "Ref: {$ref}\n"
                         . "User: " . htmlspecialchars($tx_data['full_name'] ?? 'Unknown') . " (" . htmlspecialchars($tx_data['email'] ?? $tx_data['phone'] ?? '') . ")\n"
                         . "Amount: ₦" . number_format((float)$tx_data['amount'], 2) . "\n"
                         . "Category: " . ($tx_data['category'] ?? 'N/A') . "\n"
                         . "Time: " . date('d M Y, H:i:s');
                    send_whatsapp_alert($msg);
                }
            } catch (\Throwable $e) {
                log_error('whatsapp', "Failed to send tx failure alert: " . $e->getMessage());
            }
        }

        return $changed;
    } catch (PDOException $e) {
        log_error('wallet', 'update_tx_status failed: ' . $e->getMessage());
        return false;
    }
}

// ============================================
// CASHBACK FUNCTIONS
// ============================================
function apply_cashback(int $user_id, float $amount, string $service, ?string $network, int $plan_id = 0): float
{
    try {
        // Check per-plan cashback first (overrides global)
        if ($plan_id > 0) {
            $pstmt = db()->prepare("SELECT cashback_type, cashback_value FROM data_plans WHERE id = ? AND cashback_type IS NOT NULL AND cashback_value IS NOT NULL AND cashback_value > 0 LIMIT 1");
            $pstmt->execute([$plan_id]);
            $plan_cb = $pstmt->fetch();
            if ($plan_cb) {
                $earned = $plan_cb['cashback_type'] === 'percent' 
                    ? ($amount * (float)$plan_cb['cashback_value'] / 100) 
                    : (float)$plan_cb['cashback_value'];
                if ($earned <= 0) return 0;
                // Per-plan cashback has no cap — use value as-is
                $db = db();
                $in_tx = $db->inTransaction();
                if (!$in_tx) $db->beginTransaction();
                $stmt = $db->prepare("SELECT cashback_balance FROM users WHERE id = ? FOR UPDATE");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
                if (!$user) { if (!$in_tx) $db->rollBack(); return 0; }
                $balance_before = (float)$user['cashback_balance'];
                $balance_after = $balance_before + $earned;
                $db->prepare("UPDATE users SET cashback_balance = ? WHERE id = ?")->execute([$balance_after, $user_id]);
                $ref = generate_ref('CB');
                $description = "Cashback: {$service}" . ($network ? " ({$network})" : '') . " (plan)";
                $db->prepare("INSERT INTO transactions (user_id, transaction_ref, type, category, amount, balance_before, balance_after, description, status, ip_address) VALUES (?, ?, 'credit', 'cashback', ?, ?, ?, ?, 'success', ?)")->execute([$user_id, $ref, $earned, $balance_before, $balance_after, $description, get_ip()]);
                if (!$in_tx) $db->commit();
                notify_user($user_id, 'Cashback Earned', '₦' . number_format($earned, 2) . " cashback on your {$service}.", 'success');
                return $earned;
            }
        }

        // Fall back to global cashback configuration
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
        $in_tx = $db->inTransaction();
        if (!$in_tx) $db->beginTransaction();

        // Lock user record
        $stmt = $db->prepare("SELECT cashback_balance FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();

        if (!$user) {
            if (!$in_tx) $db->rollBack();
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

        if (!$in_tx) $db->commit();
        
        // Notify user
        notify_user(
            $user_id,
            'Cashback Earned',
            '₦' . number_format($earned, 2) . " cashback on your {$service}.",
            'success'
        );
        
        return $earned;

    } catch (PDOException $e) {
        try { if (db()->inTransaction()) db()->rollBack(); } catch (\Throwable $t) {}
        log_error('cashback', $e->getMessage());
        return 0;
    }
}
