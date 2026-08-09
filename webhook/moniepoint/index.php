<?php
require_once __DIR__ . '/../../user/core/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$payload = file_get_contents('php://input');
log_write('webhook_moniepoint', 'INFO', 'Received webhook', ['payload' => $payload]);

$secret = setting('gw_moniepoint_secret');
$signature = $_SERVER['HTTP_X_MONIEPOINT_SIGNATURE'] ?? '';

if (empty($secret)) {
    log_write('webhook_moniepoint', 'ERROR', 'Webhook secret not configured — rejecting');
    http_response_code(500);
    exit('Webhook secret not configured');
}
if (empty($payload) || empty($signature)) {
    http_response_code(400);
    exit('Missing payload or signature');
}

$hash = hash_hmac('sha512', $payload, $secret);
if (!hash_equals($hash, $signature)) {
    log_write('webhook_moniepoint', 'ERROR', 'Invalid signature', ['expected' => $hash, 'got' => $signature]);
    http_response_code(400);
    exit('Invalid signature');
}

$data = json_decode($payload, true);
if (!$data || !isset($data['eventType'])) {
    http_response_code(400);
    exit('Invalid payload');
}

if ($data['eventType'] === 'SUCCESSFUL_TRANSACTION') {
    $eventData = $data['eventData'];
    $ref = $eventData['transactionReference'] ?? '';
    $settled = (float)($eventData['settlementAmount'] ?? 0);
    $amount = (float)($eventData['amountPaid'] ?? 0);
    $accountReference = $eventData['product']['reference'] ?? null;

    if ($ref === '') {
        http_response_code(400);
        exit('Missing transactionReference');
    }

    // Check if transaction already processed. credit_wallet() always mints its own
    // MSJ... ref for transaction_ref, so that column never equals Moniepoint's
    // $ref — checking it here was a no-op. idempotency_key is what credit_wallet()
    // actually stores this under, and is also the atomic guard below against a
    // concurrent replay of the same webhook.
    $idem_key = 'moniepoint:' . $ref;
    $stmt = db()->prepare("SELECT id FROM transactions WHERE idempotency_key = ? LIMIT 1");
    $stmt->execute([$idem_key]);
    if ($stmt->fetch()) {
        http_response_code(200);
        exit('Already processed');
    }

    // Find user by account number. This used to try an `account_reference`
    // column first — that column has never existed on virtual_accounts (the
    // Monnify onboarding flow in virtual-account.php builds an
    // 'account_reference' array key but save_account() never persists it),
    // so that query threw an uncaught PDOException on every single delivery,
    // crashing the handler before it ever reached this account-number match.
    // In effect every Moniepoint funding webhook has been failing outright.
    $accountNumber = $eventData['destinationAccountInformation']['accountNumber'] ?? null;
    $acc = null;
    if ($accountNumber) {
        $stmt = db()->prepare("SELECT user_id FROM virtual_accounts WHERE account_number = ? AND provider = 'moniepoint' LIMIT 1");
        $stmt->execute([$accountNumber]);
        $acc = $stmt->fetch();
    }

    if (!$acc) {
        log_write('webhook_moniepoint', 'ERROR', 'Account not found', ['ref' => $accountReference, 'account_number' => $accountNumber]);
        http_response_code(404);
        exit('Account not found');
    }

    $user_id = $acc['user_id'];

    // Charge fee if applicable
    $fee_type = setting('gw_moniepoint_fee_type', 'percent');
    $fee_value = (float)setting('gw_moniepoint_fee_value', '1');
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

    $res = credit_wallet($user_id, $credit_amount, 'wallet_funding', 'Moniepoint Funding - ' . $ref, ['ref' => $ref], $idem_key);

    if ($res['success']) {
        notify_user($user_id, 'Wallet Funded', "Your wallet has been funded with ₦" . number_format($credit_amount, 2) . " via Moniepoint", 'info', null, $res['balance_before'], $res['balance_after']);
        http_response_code(200);
        exit('Success');
    } elseif (!empty($res['duplicate'])) {
        http_response_code(200);
        exit('Already processed');
    } else {
        log_write('webhook_moniepoint', 'ERROR', 'Failed to credit', ['res' => $res]);
        http_response_code(500);
        exit('Failed to credit');
    }
} else {
    http_response_code(200);
    exit('Unhandled event type');
}
