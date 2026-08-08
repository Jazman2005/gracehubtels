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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method Not Allowed');
}

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
            // This idempotency key alone is not enough: it only stops the SAME
            // webhook event being processed twice, but the synchronous fund
            // flow in virtual_card.php's process_card_action('fund') credits
            // the card independently for the exact same real-world top-up,
            // using its own claim (update_tx_status() flipping the internal
            // transaction from pending/processing to success). Under normal
            // Cashwyre delivery behavior this webhook ALSO fires for that
            // same top-up with a fresh event id, which previously credited
            // the card a second time for one real funding — a guaranteed
            // double-credit, not merely a theoretical replay. Cashwyre's
            // topup API also accepts no merchant reference we could echo
            // back, so reconcile by matching the deterministic description
            // fund_card() records at debit time, and claim the SAME
            // pending/processing -> success transition the synchronous path
            // uses: whichever side resolves the transaction first is the
            // only one that touches virtual_cards.balance. This also means
            // an attacker who somehow obtained the webhook secret can no
            // longer mint an arbitrary/unbounded balance — they can only
            // trigger crediting an amount that matches an actual pending
            // transaction already on record for that card.
            $desc_prefix = 'Fund virtual card ' . substr($cardId, -6) . ' with $' . $usdAmount;
            $txStmt = db()->prepare("SELECT transaction_ref FROM transactions WHERE category='virtual_card' AND type='debit' AND status IN ('pending','processing') AND description LIKE ? ORDER BY created_at ASC LIMIT 1");
            $txStmt->execute([$desc_prefix . '%']);
            $ref = $txStmt->fetchColumn();

            if ($ref && update_tx_status($ref, 'success')) {
                db()->prepare("UPDATE virtual_cards SET balance = balance + ? WHERE card_id=?")
                    ->execute([$usdAmount, $cardId]);
                log_api('webhook_cashwyre', 'card_funded', ['card_id' => $cardId, 'amount' => $usdAmount, 'ref' => $ref], 'ok', 200);
            } else {
                log_write('webhook_cashwyre', 'INFO', 'Fund event has no claimable matching transaction — not credited (already resolved by the synchronous flow, or no matching pending record)', ['card_id' => $cardId, 'amount' => $usdAmount, 'matched_ref' => $ref ?: null]);
            }
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
