<?php
// webhook/moniepoint/index.php
// Handles Monnify webhooks (used for Moniepoint virtual accounts)
require_once __DIR__ . '/../../user/core/db.php';
require_once __DIR__ . '/../../user/core/dashboard.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['message' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$signature = $_SERVER['HTTP_MONNIFY_SIGNATURE'] ?? '';
$ip_address = get_ip(); 
$secret = setting('gw_moniepoint_secret');

$valid = false;
if (!empty($secret) && !empty($signature)) {
    $hashkey = hash_hmac('sha512', $raw, $secret);
    $valid = hash_equals($hashkey, $signature);
}

$payload = json_decode($raw, true);

$event_type = $payload['eventType'] ?? '';
if ($event_type !== 'SUCCESSFUL_TRANSACTION') {
    // Only process successful transactions
    http_response_code(200);
    echo json_encode(['message' => 'ignored event type']);
    exit;
}

$event_data = $payload['eventData'] ?? [];
$amount = isset($event_data['settlementAmount']) ? floatval($event_data['settlementAmount']) : (isset($event_data['amountPaid']) ? floatval($event_data['amountPaid']) : 0);
$reference = $event_data['transactionReference'] ?? '';
$account_ref = $event_data['product']['reference'] ?? ''; 
$sender = $event_data['customer']['name'] ?? 'Unknown';

// Log the incoming webhook
db()->prepare(
    "INSERT INTO webhook_logs (provider,event_type,transaction_ref,account_number,amount,payload,signature_valid,ip_address)
     VALUES ('moniepoint',?,?,?,?,?,?,?)"
)->execute([$event_type, $reference, $account_ref, $amount, $raw, (int)$valid, $ip_address]);


if (!$valid) {
    http_response_code(400);
    echo json_encode(['message' => 'Permission denied, invalid signature.']);
    exit;
}

if (!$payload || $amount <= 0 || empty($reference) || empty($account_ref)) {
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

// Find user by account reference (product reference)
$va = db()->prepare("SELECT user_id, account_number FROM virtual_accounts WHERE account_reference=? AND is_active=1 LIMIT 1");
$va->execute([$account_ref]);
$va = $va->fetch();

if (!$va) {
    db()->prepare("UPDATE webhook_logs SET error_message='No user found for account reference' WHERE transaction_ref=? ORDER BY id DESC LIMIT 1")
       ->execute([$reference]);
    http_response_code(200);
    echo json_encode(['message' => 'success']); // return 200 so they stop retrying
    exit;
}

$user_id = (int)$va['user_id'];
$acct_no = $va['account_number'];

// Fee Calculation
$fee_type = setting('gw_moniepoint_fee_type', 'flat');
$fee_val  = (float)setting('gw_moniepoint_fee_value', '0');

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

$desc_msg = "Wallet funded via Moniepoint - from {$sender}";
if ($fee > 0) $desc_msg .= " (Fee: ₦".number_format($fee,2).")";

$result  = credit_wallet($user_id, $credit_amount, 'wallet_funding', $desc_msg, [
    'provider'       => 'moniepoint',
    'external_ref'   => $reference,
    'sender'         => $sender,
    'account_number' => $acct_no,
]);

if ($result['success']) {
    // Tag with external ref
    db()->prepare("UPDATE transactions SET external_ref=?, provider='moniepoint' WHERE transaction_ref=? LIMIT 1")
       ->execute([$reference, $result['ref']]);
    db()->prepare("UPDATE webhook_logs SET processed=1 WHERE transaction_ref=? ORDER BY id DESC LIMIT 1")
       ->execute([$reference]);

    // Notify user
    db()->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,?)")
       ->execute([$user_id, '💰 Wallet Funded', "₦".number_format($credit_amount,2)." credited to your wallet via Moniepoint.", 'success']);
}

http_response_code(200);
echo json_encode(['message' => 'success']);
