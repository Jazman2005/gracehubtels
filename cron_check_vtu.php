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

$lockFile = __DIR__ . '/cron_check_vtu.lock';
if (file_exists($lockFile) && (time() - filemtime($lockFile) < 120)) {
    exit("Already running or ran recently.\n");
}
touch($lockFile);

$service_categories = ['electricity', 'airtime', 'data', 'cabletv', 'exam_pin', 'recharge_card', 'bulk_sms', 'data_card'];

try {
    // Find pending/processing transactions older than 2 minutes, younger than 30 minutes (so refund cron handles the very old ones)
    $stmt = db()->prepare("
        SELECT * FROM transactions 
        WHERE status IN ('pending', 'processing') 
        AND category IN ('electricity', 'airtime', 'data', 'cabletv', 'exam_pin', 'recharge_card', 'bulk_sms', 'data_card')
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
            if (update_tx_status($ref, 'failed')) {
                if (!empty($result['message'])) {
                    db()->prepare("UPDATE transactions SET meta = JSON_SET(COALESCE(meta, '{}'), '$.response', ?) WHERE transaction_ref=?")->execute([$result['message'], $ref]);
                }
                $uid = (int)$tx['user_id'];
                $amount = (float)$tx['amount'];
                credit_wallet($uid, $amount, $category, ucwords(str_replace('_', ' ', $category)) . ' reversal - ' . ($result['message'] ?: 'Provider reported failure'), [], 'vtu_refund:' . $ref);
                notify_user($uid, ucwords(str_replace('_', ' ', $category)) . ' Failed', ($result['message'] ?: "Your $category transaction failed. Wallet refunded."), 'error', null, $tx['balance_before'], $tx['balance_after']);
                log_write('cron_check_vtu', 'INFO', "Updated $ref as failed via status check");
            }
        }
        // If status is still pending, do nothing - check again next run
    }

    echo "Checked $count pending transactions.\n";

} catch (Exception $e) {
    log_write('cron_check_vtu', 'ERROR', "Cron error: " . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
}

if (file_exists($lockFile)) unlink($lockFile);

// query_provider_status() now lives in user/core/api_router.php (required at
// the top of this file) so cron_refund.php can share the exact same
// provider-verification logic instead of refunding blindly on a timeout.
