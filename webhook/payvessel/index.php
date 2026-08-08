<?php
require_once __DIR__ . '/../../user/core/db.php';

// Debug: log incoming request details
$payload = file_get_contents('php://input');
$remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';
$all_headers = getallheaders();

log_write('webhook_payvessel', 'INFO', 'Webhook received', [
    'ip' => $remote_ip,
    'headers' => $all_headers,
    'payload' => $payload,
    'uri' => $_SERVER['REQUEST_URI'] ?? ''
]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// IP whitelist – PayVessel
$allowed_ips = ['3.255.23.38', '3.255.23.0/24'];
$ok = false;
foreach ($allowed_ips as $range) {
    if (str_contains($range, '/')) {
        [$subnet, $bits] = explode('/', $range);
        $ip_long = ip2long($remote_ip);
        $subnet_long = ip2long($subnet);
        $mask = -1 << (32 - (int)$bits);
        if (($ip_long & $mask) === ($subnet_long & $mask)) { $ok = true; break; }
    } elseif ($remote_ip === $range) {
        $ok = true; break;
    }
}
if (!$ok) {
    log_write('webhook_payvessel', 'WARN', 'IP not in whitelist, processing anyway', ['ip' => $remote_ip]);
    // Do not block — allow processing but log for monitoring
}

$secret = setting('gw_payvessel_secret');
if (empty($secret)) {
    log_write('webhook_payvessel', 'ERROR', 'Webhook secret not configured — rejecting');
    http_response_code(500);
    exit('Webhook secret not configured');
}

// Try multiple possible signature header names
$signature = '';
foreach (['HTTP_PAYVESSEL_HTTP_SIGNATURE', 'HTTP_X_PAYVESSEL_SIGNATURE', 'HTTP_PAYVESSEL_SIGNATURE', 'HTTP_X_PAYVESSEL_HASH', 'HTTP_SIGNATURE'] as $h) {
    if (!empty($_SERVER[$h])) { $signature = $_SERVER[$h]; break; }
}

if (empty($payload)) {
    http_response_code(400);
    exit('Missing payload');
}
if (empty($signature)) {
    log_write('webhook_payvessel', 'ERROR', 'No signature header found', ['server_keys' => array_keys($_SERVER)]);
    http_response_code(400);
    exit('Missing signature');
}

$hash = hash_hmac('sha512', $payload, $secret);
if (!hash_equals($hash, $signature)) {
    log_write('webhook_payvessel', 'ERROR', 'Invalid signature', ['expected' => $hash, 'got' => $signature]);
    http_response_code(400);
    exit('Invalid signature');
}

$data = json_decode($payload, true);
if (!$data || !isset($data['order'])) {
    http_response_code(400);
    exit('Invalid payload');
}

$order = $data['order'];
$tx = $data['transaction'] ?? [];
$amount = (float)($order['amount'] ?? 0);
$settled = (float)($order['settlement_amount'] ?? $amount);
$ref = $tx['reference'] ?? $tx['sessionid'] ?? '';
$accountNumber = $data['virtualAccount']['virtualAccountNumber'] ?? null;

if (!$accountNumber) {
    http_response_code(400);
    exit('Missing account number');
}

// Check if transaction already processed. credit_wallet() always mints its own
// MSJ... ref for transaction_ref, so that column never equals PayVessel's $ref —
// checking it here was a no-op. idempotency_key is what credit_wallet() actually
// stores this under, and is also the atomic guard below against a concurrent
// replay of the same webhook.
// When PayVessel's payload has neither a reference nor a sessionid, $ref is
// empty — previously that made $idem_key null, which disables credit_wallet()'s
// unique-constraint dedup entirely, so a duplicate delivery of a ref-less
// payload could double-credit with no protection at all. Fall back to a hash
// of the raw payload body: an identical retried delivery (same bytes) still
// collides and gets blocked, while genuinely different transactions produce
// different hashes.
$idem_key = $ref !== '' ? 'payvessel:' . $ref : 'payvessel:hash:' . hash('sha256', $payload);
$stmt = db()->prepare("SELECT id FROM transactions WHERE idempotency_key = ? LIMIT 1");
$stmt->execute([$idem_key]);
if ($stmt->fetch()) {
    http_response_code(200);
    exit('Already processed');
}

// Find user by account number
$stmt = db()->prepare("SELECT user_id FROM virtual_accounts WHERE account_number = ? AND provider = 'payvessel' LIMIT 1");
$stmt->execute([$accountNumber]);
$acc = $stmt->fetch();

if (!$acc) {
    log_write('webhook_payvessel', 'ERROR', 'Account not found', ['account' => $accountNumber]);
    http_response_code(404);
    exit('Account not found');
}

$user_id = $acc['user_id'];

// Charge fee if applicable
$fee_type = setting('gw_payvessel_fee_type', 'percent');
$fee_value = (float)setting('gw_payvessel_fee_value', '1');
if ($fee_type === 'flat') {
    $fee = min($fee_value, $settled * 0.5);
} else {
    $fee = ($settled * $fee_value) / 100;
}
$credit_amount = $settled - $fee;
if ($credit_amount <= 0) {
    http_response_code(200);
    exit('Amount too low after fees');
}

$res = credit_wallet($user_id, $credit_amount, 'wallet_funding', 'PayVessel Funding - ' . $ref, ['ref' => $ref], $idem_key);

if ($res['success']) {
    notify_user($user_id, 'Wallet Funded', "Your wallet has been funded with ₦" . number_format($credit_amount, 2) . " via bank transfer", 'info', null, $res['balance_before'], $res['balance_after']);
    http_response_code(200);
    exit('Success');
} elseif (!empty($res['duplicate'])) {
    http_response_code(200);
    exit('Already processed');
} else {
    log_write('webhook_payvessel', 'ERROR', 'Failed to credit', ['res' => $res]);
    http_response_code(500);
    exit('Failed to credit');
}
