<?php
// cron_refund.php
// Run this file via CLI/cPanel cron every 5-10 minutes
// Protected from direct web access — must run via CLI or with cron_secret key
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
$lockFile = __DIR__ . '/cron_refund.lock';
$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    exit("Cron is already running.\n");
}

// A run's completion (success or caught error) is recorded here so the admin
// System Health page can show "last run Xm ago" and flag a cron that's
// silently stopped firing — flock() alone releases cleanly on any exit but
// leaves no trace of WHEN a run last actually happened.
$heartbeat_status = 'ok'; $heartbeat_detail = '';

try {
    // We target VTU transactions that have been pending/processing for more
    // than 10 minutes. 'processing' is included (not just 'pending') because
    // a network-level failure during the purchase attempt now leaves a
    // transaction in 'processing' pending verification (see airtime.php,
    // cabletv.php, electricity.php, recharge_card.php, data_card.php,
    // betting.php, virtual_card.php) — those must still eventually get
    // resolved or escalated by this sweep, not sit untouched forever.
    // 'esim' is deliberately excluded — see the matching note in
    // cron_check_vtu.php. Its stuck-pending resolution goes exclusively
    // through resolve_esim_order() (a dedicated /esim/status/{reference}
    // endpoint), never this generic provider-status sweep.
    $stmt = db()->prepare("
        SELECT * FROM transactions
        WHERE status IN ('pending', 'processing')
        AND category IN ('data', 'airtime', 'cabletv', 'electricity', 'data_card', 'recharge_card', 'bulk_sms', 'exam_pin', 'betting', 'virtual_card', 'international_airtime', 'giftcard_buy')
        AND created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
    ");
    $stmt->execute();
    $pending_txs = $stmt->fetchAll();

    $count = 0;
    $escalated = 0;
    foreach ($pending_txs as $tx) {
        $user_id = $tx['user_id'];
        $amount = (float)$tx['amount'];
        $ref = $tx['transaction_ref'];
        $type = $tx['category'];
        $charge = (float)($tx['charge'] ?? 0);
        $provider_name = $tx['provider'] ?? '';

        // This used to assume "still pending after 10 minutes = dropped" and
        // refund blindly, with zero contact to the actual provider — a
        // transaction that was merely slow (provider still processing, or
        // about to deliver) got refunded on top of a purchase that could
        // still complete, a straightforward double-payout. Now: only ever
        // refund a transaction whose provider explicitly confirms it was NOT
        // processed. If we can't ask the provider at all (no provider
        // recorded yet, or this provider has no status-check implementation),
        // we do not guess — we flag it for a human to verify manually via
        // Transaction Details instead of silently crediting or silently
        // doing nothing forever.
        if (empty($provider_name) || !provider_is_verifiable($provider_name)) {
            // Give cron_check_vtu.php (which runs far more often) and any
            // in-flight webhook every reasonable chance first — only escalate
            // once this has been stuck for a genuinely long time.
            $age_stmt = db()->prepare("SELECT TIMESTAMPDIFF(MINUTE, created_at, NOW()) FROM transactions WHERE id=?");
            $age_stmt->execute([$tx['id']]);
            $age_minutes = (int)$age_stmt->fetchColumn();
            if ($age_minutes < 30) continue;

            // Mark 'processing' (distinct from fresh 'pending') so it stops
            // being picked up by this same blind sweep every run, and alert
            // an admin to resolve it manually — this is the "escalate rather
            // than guess" behavior required when automated verification
            // isn't possible for this provider.
            if (update_tx_status($ref, 'processing')) {
                log_write('cron_refund', 'INFO', "Escalated $ref for manual review — provider '$provider_name' has no status-check support");
                notify_admin('Stuck Transaction Needs Manual Review', "Transaction <b>{$ref}</b> (category: {$type}, amount: ₦" . number_format($amount, 2) . ") has been unresolved for over {$age_minutes} minutes and its provider ('" . htmlspecialchars($provider_name ?: 'unknown', ENT_QUOTES) . "') has no automated status-check support. Please verify manually with the provider and resolve it via Transaction Details.");
                $escalated++;
            }
            continue;
        }

        // Provider supports a real status check — ask it directly rather
        // than guessing from elapsed time.
        $prov_stmt = db()->prepare("SELECT * FROM api_providers WHERE provider_name=? AND service=? AND is_active=1 LIMIT 1");
        $prov_stmt->execute([$provider_name, $type]);
        $prov_cfg = $prov_stmt->fetch();
        $result = $prov_cfg ? query_provider_status($prov_cfg, $tx) : null;

        if ($result === null) {
            // Still genuinely unknown — leave it pending, try again next run.
            continue;
        }

        if ($result['success']) {
            // Provider confirms it actually succeeded — resolve as success,
            // never as a refund, so the customer isn't paid twice.
            if (update_tx_status($ref, 'success', $result['provider_ref'] ?? '')) {
                if (!empty($result['message'])) {
                    db()->prepare("UPDATE transactions SET meta = JSON_SET(COALESCE(meta, '{}'), '$.response', ?) WHERE transaction_ref=?")->execute([$result['message'], $ref]);
                }
                notify_user($user_id, ucwords(str_replace('_', ' ', $type)) . ' Completed', $result['message'] ?: "Your $type purchase was successful.", 'success', null, $tx['balance_before'], $tx['balance_after']);
                log_write('cron_refund', 'INFO', "Resolved $ref as success via provider verification (was stuck pending)");
            }
            continue;
        }

        if (empty($result['failed'])) {
            // Ambiguous provider response — not a confirmed decline, don't act.
            continue;
        }

        // Provider explicitly confirmed this was NOT processed — safe to refund.
        db()->beginTransaction();

        // 1. Mark transaction as failed — guarded on status IN ('pending','processing')
        // so this is an atomic claim: a webhook callback or cron_check_vtu.php could
        // resolve the exact same transaction between our SELECT above and this UPDATE.
        // If rowCount() is 0, someone else already claimed it — back off rather than
        // still crediting a refund for a transaction that may have just succeeded.
        $up = db()->prepare("UPDATE transactions SET status = 'failed' WHERE id = ? AND status IN ('pending','processing')");
        $up->execute([$tx['id']]);

        if ($up->rowCount() === 0) {
            db()->rollBack();
            log_write('cron_refund', 'INFO', "Skipped $ref — already resolved by another process");
            continue;
        }
        if (!empty($result['message'])) {
            db()->prepare("UPDATE transactions SET meta = JSON_SET(COALESCE(meta, '{}'), '$.response', ?) WHERE transaction_ref=?")->execute([$result['message'], $ref]);
        }

        // 2. Refund the wallet. Shares the same idempotency key convention as the
        // vtu webhook/cron_check_vtu refund paths for the same category set, so a
        // credit_wallet()-level duplicate is also blocked even if the claim above
        // somehow raced.
        $refund_note = "Auto-refund for failed $type purchase ($ref) — provider confirmed: " . ($result['message'] ?: 'not processed');
        $res = credit_wallet($user_id, $amount + $charge, 'refund', $refund_note, ['original_ref' => $ref], 'vtu_refund:' . $ref);

        if ($res['success']) {
            db()->commit();
            notify_user($user_id, "Refund Issued", "Your failed $type transaction of ₦" . number_format($amount, 2) . " has been automatically refunded to your wallet", 'info', null, $res['balance_before'], $res['balance_after']);
            log_write('cron_refund', 'INFO', "Refunded $ref to User $user_id for $amount (provider-confirmed failure)");
            $count++;
        } else {
            db()->rollBack();
            log_write('cron_refund', 'ERROR', "Failed to refund $ref to User $user_id" . (!empty($res['duplicate']) ? ' (duplicate)' : ''));
        }
    }

    echo "Refunded $count provider-confirmed failures, escalated $escalated unverifiable transactions.\n";
    $heartbeat_detail = "Refunded $count, escalated $escalated";

} catch (Exception $e) {
    log_write('cron_refund', 'ERROR', "Cron error: " . $e->getMessage());
    echo "Error: " . $e->getMessage();
    $heartbeat_status = 'error'; $heartbeat_detail = $e->getMessage();
}

@file_put_contents(LOG_DIR . '/cron_heartbeat_refund.json', json_encode([
    'ran_at' => date('Y-m-d H:i:s'),
    'status' => $heartbeat_status,
    'detail' => $heartbeat_detail,
]));

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
