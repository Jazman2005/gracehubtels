<?php
require_once __DIR__ . '/../../user/core/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$payload = file_get_contents('php://input');

if (empty($payload)) {
    http_response_code(400);
    exit('Missing payload');
}

// Verify signature — docs say header PAYMENTPOINT-SIGNATURE, HMAC-SHA256
$secret = setting('paymentpoint_webhook_secret');
$signature = $_SERVER['HTTP_PAYMENTPOINT_SIGNATURE'] ?? '';

if (empty($secret)) {
    log_write('webhook_paymentpoint', 'ERROR', 'Webhook secret not configured — rejecting');
    http_response_code(500);
    exit('Webhook secret not configured');
}
if (empty($signature)) {
    log_write('webhook_paymentpoint', 'ERROR', 'Missing signature header');
    http_response_code(400);
    exit('Missing signature');
}
$expected = hash_hmac('sha256', $payload, $secret);
if (!hash_equals($expected, $signature)) {
    log_write('webhook_paymentpoint', 'ERROR', 'Invalid signature');
    http_response_code(400);
    exit('Invalid signature');
}

// Only log the raw payload once the signature is verified — logging it
// unconditionally let anyone spam the log file with arbitrary content via an
// unauthenticated POST, with no proof it ever came from PaymentPoint.
log_write('webhook_paymentpoint', 'INFO', 'Received webhook', ['payload' => $payload]);

$data = json_decode($payload, true);
if (!$data) {
    http_response_code(400);
    exit('Invalid JSON');
}

$notification_status = $data['notification_status'] ?? $data['type'] ?? '';
$transaction_status  = $data['transaction_status'] ?? '';

// Support both documented format and legacy type+data format
$is_success = ($notification_status === 'payment_successful' && $transaction_status === 'success')
           || $data['type'] === 'collection.successful';

if (!$is_success) {
    http_response_code(200);
    exit('Unhandled event');
}

$ref = $data['transaction_id'] ?? $data['data']['reference'] ?? '';
$amount = (float)($data['amount_paid'] ?? $data['data']['amount'] ?? 0);
$settled = (float)($data['settlement_amount'] ?? $data['data']['settlementAmount'] ?? $amount);
$accountNumber = $data['receiver']['account_number'] ?? $data['data']['accountNumber'] ?? null;

if (empty($ref) || $amount <= 0 || empty($accountNumber)) {
    log_write('webhook_paymentpoint', 'WARN', 'Missing required webhook fields', [
        'ref' => $ref, 'amount' => $amount, 'account' => $accountNumber, 'rawKeys' => array_keys($data)
    ]);
    http_response_code(200);
    exit('Missing fields');
}

// Check if already processed. credit_wallet() always mints its own MSJ... ref for
// transaction_ref, so that column never equals PaymentPoint's $ref — checking it
// here was a no-op. idempotency_key is what credit_wallet() actually stores this
// value under, and (below) is also the atomic guard against a concurrent replay.
$idem_key = 'paymentpoint:' . $ref;
$stmt = db()->prepare("SELECT id FROM transactions WHERE idempotency_key = ? LIMIT 1");
$stmt->execute([$idem_key]);
if ($stmt->fetch()) {
    http_response_code(200);
    exit('Already processed');
}

// Find user by account number
$stmt = db()->prepare("SELECT user_id, bank_name FROM virtual_accounts WHERE account_number = ? AND provider = 'paymentpoint' LIMIT 1");
$stmt->execute([$accountNumber]);
$acc = $stmt->fetch();

if (!$acc) {
    log_write('webhook_paymentpoint', 'ERROR', 'Account not found', ['account' => $accountNumber]);
    http_response_code(200);
    exit('Account not found');
}

$user_id = (int)$acc['user_id'];

// Fee — per-bank fee settings with global fallback
$bank_name = $acc['bank_name'] ?? '';
$fee_type = '';
$fee_value = 0;
if (stripos($bank_name, 'palmpay') !== false) {
    $fee_type = setting('paymentpoint_palmpay_fee_type', '');
    $fee_value = (float)setting('paymentpoint_palmpay_fee_value', '0');
} elseif (stripos($bank_name, 'opay') !== false) {
    $fee_type = setting('paymentpoint_opay_fee_type', '');
    $fee_value = (float)setting('paymentpoint_opay_fee_value', '0');
}
if (empty($fee_type)) {
    $fee_type = setting('paymentpoint_charge_pct', '1');
    $fee_value = (float)setting('paymentpoint_charge_cap', '50');
    if (is_numeric($fee_type)) {
        $fee = ($settled * (float)$fee_type) / 100;
        if ($fee > $fee_value) $fee = $fee_value;
    } else {
        $fee = ($fee_type === 'flat') ? min($fee_value, $settled * 0.5) : ($settled * $fee_value) / 100;
    }
} else {
    $fee = ($fee_type === 'flat') ? min($fee_value, $settled * 0.5) : ($settled * $fee_value) / 100;
}
$credit_amount = $settled - $fee;
if ($credit_amount <= 0) {
    http_response_code(200);
    exit('Amount too low after fees');
}

$res = credit_wallet($user_id, $credit_amount, 'wallet_funding', 'PaymentPoint Funding - ' . $ref, ['ref' => $ref], $idem_key);

if ($res['success']) {
    notify_user($user_id, 'Wallet Funded', "Your wallet has been funded with \u20A6" . number_format($credit_amount, 2) . " via bank transfer", 'info', null, $res['balance_before'], $res['balance_after']);
    http_response_code(200);
    exit('Success');
}

if (!empty($res['duplicate'])) {
    http_response_code(200);
    exit('Already processed');
}

log_write('webhook_paymentpoint', 'ERROR', 'Failed to credit', ['res' => $res]);
http_response_code(500);
exit('Failed to credit');
