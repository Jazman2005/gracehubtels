<?php
// cron_check_vtu.php
// Periodically checks pending VTU transactions with the provider API.
// If provider confirms success → updates DB with token/message.
// If provider confirms failure → marks failed, refunds wallet.
// Run every 2-5 minutes via cron.
// If a webhook is implemented, this is a fallback for missed callbacks.
require_once __DIR__ . '/user/core/db.php';
require_once __DIR__ . '/user/core/api_router.php';
if (php_sapi_name() !== 'cli' && !defined('STDIN')) {
    $cron_key = $_GET['key'] ?? '';
    $expected = setting('cron_secret', '');
    if (empty($expected) || !hash_equals($expected, $cron_key)) {
        http_response_code(403);
        exit('Access denied');
    }
}

// A real flock() held for the whole run, not a check-then-act mtime window —
// the OS releases the lock automatically the instant this process ends, crash
// or clean exit alike, so there's no stale-lock cleanup to get wrong and no
// risk of two overlapping runs both proceeding past a stale check.
$lockFile = __DIR__ . '/cron_check_vtu.lock';
$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    exit("Already running.\n");
}

// 'esim' is deliberately NOT included here — its pending state is resolved
// exclusively via resolve_esim_order() (GET /esim/status/{reference}, a
// richer endpoint returning iccid/qr/activation_code). The generic
// /transactions/{ref} lookup query_provider_status() uses for every other
// category here would report success/failure but never populate those
// fields, leaving a transaction marked 'success' with an order the user
// still can't use. Do not "fix" this by adding esim to the list.
$service_categories = ['electricity', 'airtime', 'data', 'cabletv', 'exam_pin', 'recharge_card', 'bulk_sms', 'data_card', 'international_airtime', 'giftcard_buy'];

// A run's completion (success or caught error) is recorded here so the admin
// System Health page can show "last run Xm ago" and flag a cron that's
// silently stopped firing — flock() alone releases cleanly on any exit but
// leaves no trace of WHEN a run last actually happened.
$heartbeat_status = 'ok'; $heartbeat_detail = '';

try {
    // Find pending/processing transactions older than 2 minutes, younger than 30 minutes (so refund cron handles the very old ones)
    $stmt = db()->prepare("
        SELECT * FROM transactions
        WHERE status IN ('pending', 'processing')
        AND category IN ('electricity', 'airtime', 'data', 'cabletv', 'exam_pin', 'recharge_card', 'bulk_sms', 'data_card', 'international_airtime', 'giftcard_buy')
        AND created_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)
        AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        ORDER BY created_at ASC
        LIMIT 20
    ");
    $stmt->execute();
    $txs = $stmt->fetchAll();

    $count = 0;
    foreach ($txs as $tx) {
        $ref = $tx['transaction_ref'];
        $provider_name = $tx['provider'] ?? '';
        $category = $tx['category'];

        if (!$provider_name) continue;

        // Look up the provider config
        $prov = db()->prepare("SELECT * FROM api_providers WHERE provider_name=? AND service=? AND is_active=1 LIMIT 1");
        $prov->execute([$provider_name, $category]);
        $provider_config = $prov->fetch();

        if (!$provider_config || empty($provider_config['api_base_url'])) continue;

        $result = query_provider_status($provider_config, $tx);
        if (!$result) continue;

        $count++;

        // update_tx_status() only reports true if THIS call actually moved the row
        // out of pending/processing — false means a webhook or cron_refund.php
        // already resolved this same transaction_ref in the meantime. Skipping the
        // side effects below in that case prevents double notifications and,
        // critically, a double refund racing a webhook that just marked it success.
        if ($result['success'] && !empty($result['token'])) {
            // Success with token
            if (update_tx_status($ref, 'success', $result['provider_ref'] ?? '')) {
                $paths = ["'$.token', ?"]; $vals = [$result['token']];
                if (!empty($result['message'])) { $paths[] = "'$.response', ?"; $vals[] = $result['message']; }
                if (!empty($result['provider_ref'])) { $paths[] = "'$.provider_ref', ?"; $vals[] = $result['provider_ref']; }
                $sql = "UPDATE transactions SET meta = JSON_SET(COALESCE(meta, '{}'), " . implode(', ', $paths) . ") WHERE transaction_ref=?";
                db()->prepare($sql)->execute(array_merge($vals, [$ref]));
                $uid = (int)$tx['user_id'];
                notify_user($uid, ucwords(str_replace('_', ' ', $category)) . ' Completed', "Token: {$result['token']} - {$result['message']}", 'success', null, $tx['balance_before'], $tx['balance_after']);
                log_write('cron_check_vtu', 'INFO', "Updated $ref as success via status check");
            }
        } elseif ($result['success']) {
            // Success without token (e.g., airtime)
            if (update_tx_status($ref, 'success', $result['provider_ref'] ?? '')) {
                if (!empty($result['message'])) {
                    db()->prepare("UPDATE transactions SET meta = JSON_SET(COALESCE(meta, '{}'), '$.response', ?) WHERE transaction_ref=?")->execute([$result['message'], $ref]);
                }
                $uid = (int)$tx['user_id'];
                notify_user($uid, ucwords(str_replace('_', ' ', $category)) . ' Completed', $result['message'] ?: "Your $category purchase was successful.", 'success', null, $tx['balance_before'], $tx['balance_after']);
                log_write('cron_check_vtu', 'INFO', "Updated $ref as success via status check");
            }
        } elseif ($result['failed']) {
            // Claim + refund must be one DB transaction — previously these were
            // two separate autocommitting statements (update_tx_status() then
            // credit_wallet()), so a process death between them left the row
            // permanently 'failed' with no refund ever issued and nothing to
            // retry it (both refund-sweep crons only scan pending/processing
            // rows). Raw claim UPDATE used instead of update_tx_status() so its
            // internal WhatsApp-alert HTTP call doesn't run inside an open
            // transaction — fired after commit instead, below.
            $uid = (int)$tx['user_id'];
            $charge = (float)($tx['charge'] ?? 0);
            $amount_to_refund = (float)$tx['amount'] + $charge;
            $credit = null;
            db()->beginTransaction();
            try {
                $claim = db()->prepare("UPDATE transactions SET status='failed', updated_at=NOW() WHERE transaction_ref=? AND status IN ('pending','processing')");
                $claim->execute([$ref]);
                if ($claim->rowCount() === 0) {
                    db()->rollBack();
                } else {
                    if (!empty($result['message'])) {
                        db()->prepare("UPDATE transactions SET meta = JSON_SET(COALESCE(meta, '{}'), '$.response', ?) WHERE transaction_ref=?")->execute([$result['message'], $ref]);
                    }
                    $credit = credit_wallet($uid, $amount_to_refund, $category, ucwords(str_replace('_', ' ', $category)) . ' reversal - ' . ($result['message'] ?: 'Provider reported failure'), [], 'vtu_refund:' . $ref);
                    if (!$credit['success']) {
                        db()->rollBack();
                        log_write('cron_check_vtu', 'ERROR', "Refund credit failed mid-transaction for $ref — rolled back, remains pending/processing for retry");
                    } else {
                        db()->commit();
                    }
                }
            } catch (\Throwable $e) {
                if (db()->inTransaction()) db()->rollBack();
                log_write('cron_check_vtu', 'ERROR', "Failed-branch transaction error for $ref: " . $e->getMessage());
            }
            if (!empty($credit['success'])) {
                try {
                    $u = db()->prepare("SELECT full_name, email, phone FROM users WHERE id=? LIMIT 1");
                    $u->execute([$uid]);
                    $ud = $u->fetch();
                    send_whatsapp_alert("⚠️ *Transaction Failed!*\n"
                        . "Ref: {$ref}\n"
                        . "User: " . htmlspecialchars($ud['full_name'] ?? 'Unknown') . " (" . htmlspecialchars($ud['email'] ?? $ud['phone'] ?? '') . ")\n"
                        . "Amount: ₦" . number_format($amount_to_refund, 2) . "\n"
                        . "Category: {$category}\n"
                        . "Time: " . date('d M Y, H:i:s'));
                } catch (\Throwable $e) {
                    log_error('whatsapp', 'Failed to send tx failure alert: ' . $e->getMessage());
                }
                notify_user($uid, ucwords(str_replace('_', ' ', $category)) . ' Failed', ($result['message'] ?: "Your $category transaction failed. Wallet refunded."), 'error', null, $tx['balance_before'], $tx['balance_after']);
                log_write('cron_check_vtu', 'INFO', "Updated $ref as failed via status check");
            }
        }
        // If status is still pending, do nothing - check again next run
    }

    echo "Checked $count pending transactions.\n";
    $heartbeat_detail = "Checked $count pending transaction(s)";

} catch (Exception $e) {
    log_write('cron_check_vtu', 'ERROR', "Cron error: " . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
    $heartbeat_status = 'error'; $heartbeat_detail = $e->getMessage();
}

@file_put_contents(LOG_DIR . '/cron_heartbeat_check_vtu.json', json_encode([
    'ran_at' => date('Y-m-d H:i:s'),
    'status' => $heartbeat_status,
    'detail' => $heartbeat_detail,
]));

flock($lockHandle, LOCK_UN);
fclose($lockHandle);

// query_provider_status() now lives in user/core/api_router.php (required at
// the top of this file) so cron_refund.php can share the exact same
// provider-verification logic instead of refunding blindly on a timeout.
