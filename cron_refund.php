<?php
// cron_refund.php
// Run this file via CLI/cPanel cron every 5-10 minutes
// Protected from direct web access — must run via CLI or with cron_secret key
require_once __DIR__ . '/user/core/db.php';
if (php_sapi_name() !== 'cli' && !defined('STDIN')) {
    $cron_key = $_GET['key'] ?? '';
    $expected = setting('cron_secret', '');
    if (empty($expected) || !hash_equals($expected, $cron_key)) {
        http_response_code(403);
        exit('Access denied');
    }
}

$lockFile = __DIR__ . '/cron_refund.lock';
if (file_exists($lockFile) && (time() - filemtime($lockFile) < 300)) {
    exit("Cron is already running or ran too recently.\n");
}
touch($lockFile);

try {
    // We target VTU transactions that have been pending for more than 10 minutes
    $stmt = db()->prepare("
        SELECT * FROM transactions 
        WHERE status = 'pending' 
        AND category IN ('data', 'airtime', 'cabletv', 'electricity') 
        AND created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
    ");
    $stmt->execute();
    $pending_txs = $stmt->fetchAll();

    $count = 0;
    foreach ($pending_txs as $tx) {
        $user_id = $tx['user_id'];
        $amount = (float)$tx['amount'];
        $ref = $tx['transaction_ref'];
        $type = $tx['category'];

        // In a live integration, you'd make an API request to the VTU provider here to verify
        // For this automation, if it's been pending for >10 mins, we assume it dropped and refund
        // Also refund the service charge if present
        $charge = (float)($tx['charge'] ?? 0);
        
        db()->beginTransaction();

        // 1. Mark transaction as failed — guarded on status='pending' so this is an
        // atomic claim: a webhook callback or cron_check_vtu.php could resolve the
        // exact same transaction between our SELECT above and this UPDATE. If
        // rowCount() is 0, someone else already claimed it — back off rather than
        // still crediting a refund for a transaction that may have just succeeded.
        $up = db()->prepare("UPDATE transactions SET status = 'failed' WHERE id = ? AND status = 'pending'");
        $up->execute([$tx['id']]);

        if ($up->rowCount() === 0) {
            db()->rollBack();
            log_write('cron_refund', 'INFO', "Skipped $ref — already resolved by another process");
            continue;
        }

        // 2. Refund the wallet. Shares the same idempotency key convention as the
        // vtu webhook/cron_check_vtu refund paths for the same category set, so a
        // credit_wallet()-level duplicate is also blocked even if the claim above
        // somehow raced.
        $refund_note = "Auto-refund for failed $type purchase ($ref)";
        $res = credit_wallet($user_id, $amount + $charge, 'refund', $refund_note, ['original_ref' => $ref], 'vtu_refund:' . $ref);

        if ($res['success']) {
            db()->commit();
            notify_user($user_id, "Refund Issued", "Your failed $type transaction of ₦" . number_format($amount, 2) . " has been automatically refunded to your wallet", 'info', null, $res['balance_before'], $res['balance_after']);
            log_write('cron_refund', 'INFO', "Refunded $ref to User $user_id for $amount");
            $count++;
        } else {
            db()->rollBack();
            log_write('cron_refund', 'ERROR', "Failed to refund $ref to User $user_id" . (!empty($res['duplicate']) ? ' (duplicate)' : ''));
        }
    }
    
    echo "Processed $count pending transactions.\n";
    
} catch (Exception $e) {
    log_write('cron_refund', 'ERROR', "Cron error: " . $e->getMessage());
    echo "Error: " . $e->getMessage();
}

if (file_exists($lockFile)) unlink($lockFile);
