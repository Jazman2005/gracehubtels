<?php
// webhook/paymentpoint/index.php
require_once __DIR__ . '/../../user/core/db.php';
require_once __DIR__ . '/../../user/core/dashboard.php';

header('Content-Type: application/json');

$raw     = file_get_contents('php://input');
$sig     = $_SERVER['HTTP_PAYMENTPOINT_SIGNATURE'] ?? '';
$secret  = setting('paymentpoint_webhook_secret');
$ip      = get_ip();

$valid = false;
if (!empty($secret) && !empty($sig)) {
    $expected = hash_hmac('sha256', $raw, $secret);
    $valid     = hash_equals($expected, $sig);
}

$payload = json_decode($raw, true);
$event   = $payload['notification_status'] ?? '';
$amount  = (float)($payload['amount_paid'] ?? $payload['settlement_amount'] ?? 0);
$acct_no = $payload['receiver']['account_number'] ?? '';
$tx_ref  = $payload['transaction_id'] ?? '';

// Log the webhook
db()->prepare(
    "INSERT INTO webhook_logs (provider,event_type,transaction_ref,account_number,amount,payload,signature_valid,ip_address)
     VALUES ('paymentpoint',?,?,?,?,?,?,?)"
)->execute([$event,$tx_ref,$acct_no,$amount,$raw,(int)$valid,$ip]);

if (!$valid) {
    http_response_code(400);
    echo json_encode(['error'=>'Invalid signature']);
    exit;
}

if (!$payload) {
    http_response_code(400);
    echo json_encode(['error'=>'Invalid JSON']);
    exit;
}

if ($event !== 'payment_successful' || $amount <= 0 || empty($acct_no)) {
    http_response_code(200);
    echo json_encode(['status'=>'ignored']);
    exit;
}

// Duplicate check
$dup = db()->prepare("SELECT id FROM transactions WHERE external_ref=? LIMIT 1");
$dup->execute([$tx_ref]);
if ($dup->fetch()) {
    http_response_code(200);
    echo json_encode(['status'=>'duplicate']);
    exit;
}

// Find user by account number
$va = db()->prepare("SELECT user_id, bank_name FROM virtual_accounts WHERE account_number=? AND is_active=1 LIMIT 1");
$va->execute([$acct_no]);
$va_row = $va->fetch();

if (!$va_row) {
    db()->prepare("UPDATE webhook_logs SET error_message='No user found for account' WHERE transaction_ref=? ORDER BY id DESC LIMIT 1")
       ->execute([$tx_ref]);
    http_response_code(200);
    echo json_encode(['status'=>'user_not_found']);
    exit;
}

$user_id   = (int)$va_row['user_id'];
$bank_name = strtolower($va_row['bank_name'] ?? '');
$sender    = $payload['sender']['name'] ?? 'Unknown';

// Fee Calculation
$fee_type = 'flat';
$fee_val  = 0;

if (str_contains($bank_name, 'palmpay')) {
    $fee_type = setting('paymentpoint_palmpay_fee_type', 'flat');
    $fee_val  = (float)setting('paymentpoint_palmpay_fee_value', '0');
} elseif (str_contains($bank_name, 'opay') || str_contains($bank_name, 'paycom')) {
    $fee_type = setting('paymentpoint_opay_fee_type', 'flat');
    $fee_val  = (float)setting('paymentpoint_opay_fee_value', '0');
}

$fee = 0;
if ($fee_val > 0) {
    if ($fee_type === 'percent') {
        $fee = ($amount * $fee_val) / 100;
    } else {
        $fee = $fee_val;
    }
}

$credit_amount = max(0, $amount - $fee);

if ($credit_amount <= 0) {
    db()->prepare("UPDATE webhook_logs SET error_message='Amount less than or equal to fee' WHERE transaction_ref=? ORDER BY id DESC LIMIT 1")
       ->execute([$tx_ref]);
    http_response_code(200);
    echo json_encode(['status'=>'fee_too_high']);
    exit;
}

$desc = "Wallet funded via PaymentPoint ({$va_row['bank_name']}) - from {$sender}";
if ($fee > 0) $desc .= " (Fee: ₦".number_format($fee,2).")";

$result  = credit_wallet($user_id, $credit_amount, 'wallet_funding', $desc, [
    'provider'       =>'paymentpoint',
    'external_ref'   =>$tx_ref,
    'sender'         =>$sender,
    'account_number' =>$acct_no,
]);

if ($result['success']) {
    // Tag with external ref
    db()->prepare("UPDATE transactions SET external_ref=?, provider='paymentpoint' WHERE transaction_ref=? LIMIT 1")
       ->execute([$tx_ref,$result['ref']]);
    db()->prepare("UPDATE webhook_logs SET processed=1 WHERE transaction_ref=? ORDER BY id DESC LIMIT 1")
       ->execute([$tx_ref]);

    // Notify user
    db()->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,?)")
       ->execute([$user_id,'💰 Wallet Funded',"₦".number_format($credit_amount,2)." credited to your wallet.",'success']);
}

http_response_code(200);
echo json_encode(['status'=>'processed']);
