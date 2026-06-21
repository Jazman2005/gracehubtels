<?php
// webhook/palmpay/index.php
require_once __DIR__ . '/../../user/core/db.php';
require_once __DIR__ . '/../../user/core/dashboard.php';

header('Content-Type: application/json');

$raw    = file_get_contents('php://input');
$ip     = get_ip();

// PalmPay sends a signature in X-PalmPay-Signature or similar header
$sig    = $_SERVER['HTTP_X_PALMPAY_SIGNATURE'] ?? $_SERVER['HTTP_SIGNATURE'] ?? '';
$secret = setting('palmpay_secret');

$valid = true; // Default allow if no secret configured (sandbox)
if (!empty($secret) && !empty($sig)) {
    $expected = base64_encode(hash_hmac('sha256', $raw, $secret, true));
    $valid    = hash_equals($expected, $sig);
}

$payload = json_decode($raw, true);

// Log it
db()->prepare(
    "INSERT INTO webhook_logs (provider,event_type,transaction_ref,account_number,amount,payload,signature_valid,ip_address)
     VALUES ('palmpay',?,?,?,?,?,?,?)"
)->execute([
    $payload['type'] ?? $payload['event'] ?? 'payment',
    $payload['data']['orderId'] ?? $payload['orderId'] ?? '',
    $payload['data']['virtualAccountNo'] ?? $payload['data']['payeeBankAccNo'] ?? '',
    (float)($payload['data']['amount'] ?? 0) / 100,
    $raw,
    (int)$valid,
    $ip
]);

if (!$valid) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

if (!$payload) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Determine notification type
$order_id   = $payload['data']['orderId']     ?? '';
$acct_no    = $payload['data']['virtualAccountNo'] ?? '';
$amount_raw = (float)($payload['data']['amount'] ?? 0);
$amount     = $amount_raw / 100; // PalmPay sends in kobo
$event_type = $payload['type'] ?? $payload['respCode'] ?? '';

// Handle virtual account credit (inbound payment)
if ($acct_no && $amount > 0) {
    // Duplicate check
    $ext_ref = $payload['data']['orderNo'] ?? $order_id;
    if ($ext_ref) {
        $dup = db()->prepare("SELECT id FROM transactions WHERE external_ref=? LIMIT 1");
        $dup->execute([$ext_ref]);
        if ($dup->fetch()) {
            http_response_code(200);
            echo json_encode(['status' => 'duplicate']);
            exit;
        }
    }

    // Find user by virtual account
    $va = db()->prepare("SELECT user_id FROM virtual_accounts WHERE account_number=? AND is_active=1 AND provider='palmpay_direct' LIMIT 1");
    $va->execute([$acct_no]);
    $va_row = $va->fetch();

    if ($va_row) {
        $user_id = (int)$va_row['user_id'];
        $payer   = $payload['data']['payerName'] ?? 'Unknown';
        $result  = credit_wallet($user_id, $amount, 'wallet_funding', "Wallet funded via PalmPay - from {$payer}", [
            'provider'       => 'palmpay_direct',
            'external_ref'   => $ext_ref,
            'payer'          => $payer,
            'account_number' => $acct_no,
        ]);

        if ($result['success']) {
            if ($ext_ref) {
                db()->prepare("UPDATE transactions SET external_ref=?, provider='palmpay_direct' WHERE transaction_ref=? LIMIT 1")
                   ->execute([$ext_ref, $result['ref']]);
            }
            db()->prepare("UPDATE webhook_logs SET processed=1 WHERE transaction_ref=? ORDER BY id DESC LIMIT 1")
               ->execute([$ext_ref ?? '']);
            db()->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,?)")
               ->execute([$user_id, '💰 Wallet Funded', '₦' . number_format($amount, 2) . ' credited to your wallet via PalmPay.', 'success']);
        }
    }
}

// Handle transfer/payout callback
if ($order_id && empty($acct_no)) {
    $status     = $payload['data']['orderStatus'] ?? 0;
    $tx_status  = $status == 2 ? 'success' : ($status == 3 ? 'failed' : 'pending');
    $provider_ref = $payload['data']['orderNo'] ?? '';
    if ($order_id) {
        db()->prepare("UPDATE transactions SET status=?, provider_ref=? WHERE transaction_ref=? AND status='pending'")
           ->execute([$tx_status, $provider_ref, $order_id]);
        if ($tx_status === 'failed') {
            // Find transaction and reverse
            $tx = db()->prepare("SELECT * FROM transactions WHERE transaction_ref=? LIMIT 1");
            $tx->execute([$order_id]); $t = $tx->fetch();
            if ($t) {
                credit_wallet((int)$t['user_id'], (float)$t['amount'], 'bank_transfer', "Transfer reversal - provider rejected", ['reversed_tx' => $order_id]);
                db()->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,?)")
                   ->execute([$t['user_id'], '❌ Transfer Failed', 'Your transfer was rejected by the bank. Wallet refunded.', 'danger']);
            }
        }
    }
}

http_response_code(200);
echo json_encode(['status' => 'ok']);
