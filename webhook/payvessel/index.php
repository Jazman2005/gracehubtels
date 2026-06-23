<?php
// webhook/payvessel/index.php
require_once __DIR__ . '/../../user/core/db.php';
require_once __DIR__ . '/../../user/core/dashboard.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['message' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$payvessel_signature = $_SERVER['HTTP_PAYVESSEL_HTTP_SIGNATURE'] ?? '';
$ip_address = get_ip(); 
$secret = setting('gw_payvessel_secret');

$valid = false;
if (!empty($secret) && !empty($payvessel_signature)) {
    $hashkey = hash_hmac('sha512', $raw, $secret);
    $valid = hash_equals($hashkey, $payvessel_signature);
}

// Optionally enforce IP addresses according to docs, but we'll stick to signature first
// $ipAddress = ["3.255.23.38", "162.246.254.36"];

$payload = json_decode($raw, true);

$amount = isset($payload['order']['amount']) ? floatval($payload['order']['amount']) : 0;
$reference = $payload['transaction']['reference'] ?? '';
$description = $payload['order']['description'] ?? '';
$acct_no = $payload['customer']['virtualAccount'] ?? ''; 

// We also need to get account number from description or payload if virtualAccount isn't explicit
// Let's log first
db()->prepare(
    "INSERT INTO webhook_logs (provider,event_type,transaction_ref,account_number,amount,payload,signature_valid,ip_address)
     VALUES ('payvessel','payment',?,?,?,?,?,?)"
)->execute([$reference, $acct_no, $amount, $raw, (int)$valid, $ip_address]);


if (!$valid) {
    http_response_code(400);
    echo json_encode(['message' => 'Permission denied, invalid hash or ip address.']);
    exit;
}

if (!$payload || $amount <= 0 || empty($reference)) {
    http_response_code(400);
    echo json_encode(['message' => 'Invalid JSON or parameters']);
    exit;
}

// Duplicate check
$dup = db()->prepare("SELECT id FROM transactions WHERE external_ref=? LIMIT 1");
$dup->execute([$reference]);
if ($dup->fetch()) {
    http_response_code(200);
    echo json_encode(['message' => 'transaction already exist']);
    exit;
}

// Find user by account number (or transaction if tied)
$va = null;
if (!empty($acct_no)) {
    $stmt = db()->prepare("SELECT user_id, bank_name FROM virtual_accounts WHERE account_number=? AND is_active=1 LIMIT 1");
    $stmt->execute([$acct_no]);
    $va = $stmt->fetch();
}

if (!$va) {
    // Attempt to extract 10 digit account number from description
    if (preg_match('/(?:to|account)\s*(\d{10})/i', $description, $m) || preg_match('/(\d{10})/', $description, $m)) {
        $found_acct = $m[1];
        $stmt = db()->prepare("SELECT user_id, bank_name FROM virtual_accounts WHERE account_number=? AND is_active=1 LIMIT 1");
        $stmt->execute([$found_acct]);
        $va = $stmt->fetch();
        if ($va) $acct_no = $found_acct;
    }
}

if (!$va) {
    // Try to find if PayVessel sent it inside 'customer' object or similar
    $possible_acct = $payload['customer']['virtualAccount'] ?? $payload['virtualAccountNum'] ?? $payload['virtualAccount'] ?? null;
    if ($possible_acct) {
        $stmt = db()->prepare("SELECT user_id, bank_name FROM virtual_accounts WHERE account_number=? AND is_active=1 LIMIT 1");
        $stmt->execute([$possible_acct]);
        $va = $stmt->fetch();
        if ($va) $acct_no = $possible_acct;
    }
}

if (!$va) {
    db()->prepare("UPDATE webhook_logs SET error_message='No user found for account' WHERE transaction_ref=? ORDER BY id DESC LIMIT 1")
       ->execute([$reference]);
    http_response_code(200);
    echo json_encode(['message' => 'success']); // return 200 so they stop retrying
    exit;
}

$user_id   = (int)$va['user_id'];
$bank_name = strtolower($va['bank_name'] ?? '');
$sender    = $payload['customer']['name'] ?? 'Unknown';

// Fee Calculation
$fee_type = setting('gw_payvessel_fee_type', 'flat');
$fee_val  = (float)setting('gw_payvessel_fee_value', '0');

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
       ->execute([$reference]);
    http_response_code(200);
    echo json_encode(['message' => 'fee too high']);
    exit;
}

$desc_msg = "Wallet funded via PayVessel - from {$sender}";
if ($fee > 0) $desc_msg .= " (Fee: ₦".number_format($fee,2).")";

$result  = credit_wallet($user_id, $credit_amount, 'wallet_funding', $desc_msg, [
    'provider'       => 'payvessel',
    'external_ref'   => $reference,
    'sender'         => $sender,
    'account_number' => $acct_no,
]);

if ($result['success']) {
    // Tag with external ref
    db()->prepare("UPDATE transactions SET external_ref=?, provider='payvessel' WHERE transaction_ref=? LIMIT 1")
       ->execute([$reference, $result['ref']]);
    db()->prepare("UPDATE webhook_logs SET processed=1 WHERE transaction_ref=? ORDER BY id DESC LIMIT 1")
       ->execute([$reference]);

    // Notify user
    db()->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,?)")
       ->execute([$user_id, '💰 Wallet Funded', "₦".number_format($credit_amount,2)." credited to your wallet via PayVessel.", 'success']);
}

http_response_code(200);
echo json_encode(['message' => 'success']);
