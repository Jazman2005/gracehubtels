<?php
require_once __DIR__ . '/../../user/core/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

try {
    $payload = file_get_contents('php://input');

    if (empty($payload)) {
        log_write('webhook_palmpay', 'WARN', 'Empty payload received');
        http_response_code(400);
        exit('Missing payload');
    }

    $data = json_decode($payload, true);
    if (!$data) {
        log_write('webhook_palmpay', 'ERROR', 'Invalid JSON', ['payload' => mb_substr($payload, 0, 1000)]);
        http_response_code(400);
        exit('Invalid JSON');
    }

    // Check orderStatus — 2 = success
    $order_status = (int)($data['orderStatus'] ?? 0);
    if ($order_status !== 2) {
        log_write('webhook_palmpay', 'INFO', 'Ignored non-success status', ['orderStatus' => $order_status, 'allFields' => array_keys($data)]);
        http_response_code(200);
        exit('success');
    }

    // Signature verification using notify secret — MUST be fail-closed. This
    // used to only verify "if a signature was present", meaning a forged
    // payload that simply omitted the `sign` field entirely skipped
    // verification altogether and went straight to crediting a wallet.
    $sign = $data['sign'] ?? '';
    $notify_secret = setting('palmpay_notify_secret', '');
    if (empty($notify_secret)) {
        log_write('webhook_palmpay', 'ERROR', 'notify_secret not configured — rejecting');
        http_response_code(400);
        exit('Webhook secret not configured');
    }
    if (empty($sign)) {
        log_write('webhook_palmpay', 'ERROR', 'Missing signature — rejecting');
        http_response_code(400);
        exit('Missing signature');
    }
    $verify_data = $data;
    unset($verify_data['sign']);
    ksort($verify_data);
    $str = '';
    foreach ($verify_data as $k => $v) {
        if ($v !== '' && $v !== null && !is_array($v)) {
            $str .= "{$k}={$v}&";
        }
    }
    $str = rtrim($str, '&');

    $expected = strtoupper(hash_hmac('sha256', $str, $notify_secret));
    if (!hash_equals($expected, strtoupper($sign))) {
        log_write('webhook_palmpay', 'ERROR', 'Invalid signature', ['expected' => $expected, 'received' => $sign]);
        http_response_code(400);
        exit('Invalid signature');
    }
    // Only log the raw payload once the signature is verified — logging it
    // unconditionally let anyone spam the log file with arbitrary content via
    // an unauthenticated POST, with no proof it ever came from PalmPay.
    log_write('webhook_palmpay', 'INFO', 'Received webhook', ['payload' => $payload]);
    log_write('webhook_palmpay', 'INFO', 'Signature verified via HMAC');

    // Try orderNo (dynamic VA) or virtualAccountNo (permanent VA)
    $ref = $data['orderNo'] ?? $data['virtualAccountNo'] ?? $data['accountNumber'] ?? $data['orderId'] ?? '';
    $amount_kobo = (int)($data['amount'] ?? $data['totalAmount'] ?? 0);
    $amount = $amount_kobo / 100;

    if (empty($ref) || $amount <= 0) {
        log_write('webhook_palmpay', 'WARN', 'Invalid order data', ['ref' => $ref, 'amount' => $amount, 'rawKeys' => array_keys($data)]);
        http_response_code(200);
        exit('success');
    }

    // Check if already processed. This SELECT is a cheap fast-path only — the real
    // guard against a concurrent replay of the same webhook is the UNIQUE constraint
    // on transactions.idempotency_key, enforced atomically inside credit_wallet().
    // (A plain "SELECT then credit" check alone has a race: two simultaneous
    // deliveries of the same payload could both pass this SELECT before either has
    // inserted its row, double-crediting the wallet.)
    $idem_key = 'palmpay:' . $ref;
    $stmt = db()->prepare("SELECT id FROM transactions WHERE idempotency_key = ? LIMIT 1");
    $stmt->execute([$idem_key]);
    if ($stmt->fetch()) {
        log_write('webhook_palmpay', 'INFO', 'Already processed', ['ref' => $ref]);
        http_response_code(200);
        exit('success');
    }

    // Find user — match by bank_code (stores orderNo or virtualAccountNo) or account_number
    $user_id = 0;
    $stmt = db()->prepare("SELECT user_id FROM virtual_accounts WHERE (bank_code = ? OR account_number = ?) AND provider = 'palmpay' LIMIT 1");
    $stmt->execute([$ref, $ref]);
    $acc = $stmt->fetch();
    if ($acc) {
        $user_id = (int)$acc['user_id'];
    }

    if (!$user_id) {
        log_write('webhook_palmpay', 'WARN', 'Could not match user for webhook', ['ref' => $ref]);
        http_response_code(200);
        exit('success');
    }

    // Fee handling
    $fee_type = setting('palmpay_fee_type', 'percent');
    $fee_value = (float)setting('palmpay_fee_value', '1');
    if ($fee_type === 'flat') {
        $fee = min($fee_value, $amount * 0.5);
    } else {
        $fee = ($amount * $fee_value) / 100;
    }
    $credit_amount = $amount - $fee;
    if ($credit_amount <= 0) {
        log_write('webhook_palmpay', 'WARN', 'Credit amount <= 0', ['amount' => $amount, 'fee' => $fee]);
        http_response_code(200);
        exit('success');
    }

    $res = credit_wallet($user_id, $credit_amount, 'wallet_funding', 'PalmPay Funding - ' . $ref, ['ref' => $ref], $idem_key);

    if ($res['success']) {
        notify_user($user_id, 'Wallet Funded', "Your wallet has been funded with \u20A6" . number_format($credit_amount, 2) . " via PalmPay", 'info', null, $res['balance_before'], $res['balance_after']);
        log_write('webhook_palmpay', 'INFO', 'Wallet credited', ['user_id' => $user_id, 'amount' => $credit_amount, 'ref' => $ref]);

        $stmt = db()->prepare("UPDATE virtual_accounts SET is_active = 0 WHERE bank_code = ? AND provider = 'palmpay'");
        $stmt->execute([$ref]);
    } elseif (!empty($res['duplicate'])) {
        log_write('webhook_palmpay', 'INFO', 'Already processed (race-detected at insert)', ['ref' => $ref]);
    } else {
        log_write('webhook_palmpay', 'ERROR', 'credit_wallet failed', ['ref' => $ref, 'reason' => $res['message'] ?? 'unknown']);
    }
} catch (Throwable $e) {
    log_write('webhook_palmpay', 'ERROR', 'Unhandled exception', ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
}

http_response_code(200);
exit('success');
