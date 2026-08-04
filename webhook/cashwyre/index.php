<?php
/**
 * Cashwyre Webhook Handler
 * 
 * Receives callbacks for:
 *  - Card funding confirmations
 *  - Card status changes
 *  - Transaction updates
 *
 * Expected payload from Cashwyre docs: JSON with event type + data
 * Configure the webhook URL at https://business.cashwyre.com/settings
 */

require_once __DIR__.'/../../user/core/db.php';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: [];

if (empty($data)) {
    http_response_code(400);
    die('Invalid payload');
}

// Verify HMAC signature
$secret = setting('cashwyre_webhook_secret', '');
$signature = $_SERVER['HTTP_X_CASHWYRE_SIGNATURE'] ?? '';
if (empty($secret)) {
    http_response_code(500);
    die('Webhook secret not configured');
}
if (empty($signature)) {
    http_response_code(400);
    die('Missing signature');
}
$expected = hash_hmac('sha256', $raw, $secret);
if (!hash_equals($expected, $signature)) {
    http_response_code(400);
    die('Invalid signature');
}

$event = substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', $data['event'] ?? $data['type'] ?? $data['status'] ?? 'unknown'), 0, 50);
$log_raw = substr(str_replace(["\n","\r"], ' ', $raw), 0, 2000);

file_put_contents(__DIR__.'/../../logs/webhook_cashwyre.log', 
    '[' . date('Y-m-d H:i:s') . '] EVENT=' . $event . ' ' . $log_raw . "\n", 
    FILE_APPEND | LOCK_EX
);

// Handle different event types
if (preg_match('/fund|credit|deposit/i', $event)) {
    $cardId = $data['cardId'] ?? $data['card_id'] ?? $data['reference'] ?? '';
    $usdAmount = (float)($data['amount'] ?? $data['value'] ?? 0);

    if ($cardId && $usdAmount > 0) {
        // Card funding bypasses credit_wallet()'s own idempotency_key guard (it
        // mutates virtual_cards.balance directly, not the transactions table), and
        // the HMAC signature check above verifies integrity but not one-time use —
        // a captured valid request, or Cashwyre's own retry after a timeout, could
        // otherwise be replayed to credit the same funding event repeatedly.
        $event_id = $data['id'] ?? $data['transactionId'] ?? $data['eventId'] ?? $data['reference'] ?? hash('sha256', $raw);
        $idem_key = 'cashwyre:fund:' . $event_id;
        if (claim_idempotency_key($idem_key)) {
            db()->prepare("UPDATE virtual_cards SET balance = balance + ? WHERE card_id=?")
                ->execute([$usdAmount, $cardId]);
            log_api('webhook_cashwyre', 'card_funded', ['card_id' => $cardId, 'amount' => $usdAmount], 'ok', 200);
        } else {
            log_write('webhook_cashwyre', 'INFO', 'Duplicate fund event ignored', ['card_id' => $cardId, 'idem_key' => $idem_key]);
        }
    }
}

elseif (preg_match('/freeze/i', $event)) {
    $cardId = $data['cardId'] ?? $data['card_id'] ?? '';
    if ($cardId) {
        db()->prepare("UPDATE virtual_cards SET status='frozen' WHERE card_id=?")->execute([$cardId]);
    }
}

elseif (preg_match('/unfreeze|activate/i', $event)) {
    $cardId = $data['cardId'] ?? $data['card_id'] ?? '';
    if ($cardId) {
        db()->prepare("UPDATE virtual_cards SET status='active' WHERE card_id=?")->execute([$cardId]);
    }
}

elseif (preg_match('/terminate|close|delete/i', $event)) {
    $cardId = $data['cardId'] ?? $data['card_id'] ?? '';
    if ($cardId) {
        db()->prepare("UPDATE virtual_cards SET status='terminated' WHERE card_id=?")->execute([$cardId]);
    }
}

http_response_code(200);
echo 'OK';
