<?php
/**
 * Phase 3 reconciliation-logic validation — standalone CLI script.
 * Run via: php scratch/security_tests/phase3_reconciliation_tests.php
 *
 * Exercises the exact SQL reconciliation model used by
 * grace-hub-tels/reconciliation.php (opening balance + category/type-aware
 * ledger sum vs actual balance) against seeded fixtures covering every
 * scenario the model has to get right: a healthy user, a genuine mismatch,
 * a negative balance, an orphaned transaction, a legacy-migrated opening
 * balance, cashback-only rows, a cashback-withdrawal pair, an in-place
 * refunded failed debit, and a genuinely ambiguous failed debit.
 *
 * Read/write only against sectest3_<runid>_-prefixed fixtures in the local
 * msjdkjef_api test database; everything created is deleted in a finally
 * block regardless of pass/fail. Never point this at a production database.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../user/core/db.php';

const TOL = 0.01;
$RUNID = bin2hex(random_bytes(4));
$PASS = 0;
$FAIL = 0;
$FAILURES = [];
$cleanup = ['user_ids' => []];

function check(string $name, bool $cond, string $detail = ''): void {
    global $PASS, $FAIL, $FAILURES;
    if ($cond) { $PASS++; echo "  [PASS] $name\n"; }
    else { $FAIL++; $FAILURES[] = $name . ($detail ? " ($detail)" : ''); echo "  [FAIL] $name" . ($detail ? " -- $detail" : '') . "\n"; }
}

function make_user(string $runid, string $tag, float $wallet, float $cashback): int {
    static $seq = 0; $seq++;
    $pw = password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]);
    db()->prepare("INSERT INTO users (full_name, username, phone, email, password, wallet_balance, cashback_balance, is_active, created_at) VALUES (?,?,?,?,?,?,?,1,NOW())")
        ->execute(["SecTest3 $tag", "sectest3_{$runid}_{$tag}", "070{$runid}" . str_pad((string)$seq, 2, '0', STR_PAD_LEFT), "sectest3_{$runid}_{$tag}@example.test", $pw, $wallet, $cashback]);
    return (int)db()->lastInsertId();
}

function insert_tx(int $uid, string $ref, string $type, string $category, float $amount, float $before, float $after, string $status): void {
    db()->prepare("INSERT INTO transactions (user_id, transaction_ref, type, category, amount, balance_before, balance_after, status, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())")
        ->execute([$uid, $ref, $type, $category, $amount, $before, $after, $status]);
}

// Reconciles ONE user using the identical CASE-expression logic the live
// reconciliation.php page runs, scoped to just that user.
function reconcile_wallet(int $uid): array {
    $sql = "SELECT
        u.wallet_balance,
        COALESCE(lg.opening_wallet,0) AS opening_wallet,
        COALESCE(wl.wallet_ledger_sum,0) AS wallet_ledger_sum
        FROM users u
        LEFT JOIN (SELECT new_user_id, SUM(old_wallet_snapshot) AS opening_wallet FROM legacy_migration_log WHERE new_user_id IS NOT NULL GROUP BY new_user_id) lg ON lg.new_user_id = u.id
        LEFT JOIN (SELECT user_id, SUM(CASE
            WHEN category = 'cashback' THEN 0
            WHEN category = 'cashback_withdrawal' AND type = 'debit' THEN 0
            WHEN type = 'debit' AND status = 'failed' AND ABS(balance_after - balance_before) < " . TOL . " THEN 0
            WHEN type = 'credit' THEN amount
            WHEN type = 'debit' THEN -amount
            ELSE 0
        END) AS wallet_ledger_sum FROM transactions GROUP BY user_id) wl ON wl.user_id = u.id
        WHERE u.id = ?";
    $s = db()->prepare($sql); $s->execute([$uid]); $r = $s->fetch();
    $expected = (float)$r['opening_wallet'] + (float)$r['wallet_ledger_sum'];
    return ['expected' => $expected, 'actual' => (float)$r['wallet_balance'], 'diff' => round((float)$r['wallet_balance'] - $expected, 2)];
}

function reconcile_cashback(int $uid): array {
    $sql = "SELECT
        u.cashback_balance,
        COALESCE(lg.opening_cashback,0) AS opening_cashback,
        COALESCE(cl.cashback_ledger_sum,0) AS cashback_ledger_sum
        FROM users u
        LEFT JOIN (SELECT new_user_id, SUM(old_cashback_snapshot) AS opening_cashback FROM legacy_migration_log WHERE new_user_id IS NOT NULL GROUP BY new_user_id) lg ON lg.new_user_id = u.id
        LEFT JOIN (SELECT user_id, SUM(CASE
            WHEN category = 'cashback' THEN amount
            WHEN category = 'cashback_withdrawal' AND type = 'debit' THEN -amount
            ELSE 0
        END) AS cashback_ledger_sum FROM transactions WHERE category IN ('cashback','cashback_withdrawal') GROUP BY user_id) cl ON cl.user_id = u.id
        WHERE u.id = ?";
    $s = db()->prepare($sql); $s->execute([$uid]); $r = $s->fetch();
    $expected = (float)$r['opening_cashback'] + (float)$r['cashback_ledger_sum'];
    return ['expected' => $expected, 'actual' => (float)$r['cashback_balance'], 'diff' => round((float)$r['cashback_balance'] - $expected, 2)];
}

echo "=== Phase 3 Reconciliation Logic Validation (run $RUNID) ===\n\n";

try {
    // ------------------------------------------------------------------
    // 1. Healthy user: one funding credit, one purchase debit. Ledger
    // should exactly match the actual balance with zero diff.
    // ------------------------------------------------------------------
    echo "[1] Healthy user (no mismatch)\n";
    $u1 = make_user($RUNID, 'healthy', 400, 0);
    $cleanup['user_ids'][] = $u1;
    insert_tx($u1, "SECT3A{$RUNID}1", 'credit', 'wallet_funding', 1000, 0, 1000, 'success');
    insert_tx($u1, "SECT3A{$RUNID}2", 'debit', 'airtime', 600, 1000, 400, 'success');
    $r = reconcile_wallet($u1);
    check("Healthy user: expected matches actual (diff=0)", abs($r['diff']) < TOL, "expected={$r['expected']} actual={$r['actual']}");

    // ------------------------------------------------------------------
    // 2. Genuine mismatch: simulate a duplicate-credit bug by crediting
    // the wallet directly without a matching ledger row.
    // ------------------------------------------------------------------
    echo "\n[2] Genuine mismatch (unexplained duplicate credit)\n";
    $u2 = make_user($RUNID, 'mismatch', 1500, 0); // 500 more than the ledger below explains
    $cleanup['user_ids'][] = $u2;
    insert_tx($u2, "SECT3B{$RUNID}1", 'credit', 'wallet_funding', 1000, 0, 1000, 'success');
    $r = reconcile_wallet($u2);
    check("Mismatched user: diff correctly detected as +500", abs($r['diff'] - 500.0) < TOL, "diff={$r['diff']}");

    // ------------------------------------------------------------------
    // 3. In-place-refunded failed debit (refund_failed_transaction() style)
    // must be excluded — net zero, no false mismatch.
    // ------------------------------------------------------------------
    echo "\n[3] In-place-refunded failed debit (refund_failed_transaction pattern)\n";
    $u3 = make_user($RUNID, 'inplace', 1000, 0);
    $cleanup['user_ids'][] = $u3;
    insert_tx($u3, "SECT3C{$RUNID}1", 'credit', 'wallet_funding', 1000, 0, 1000, 'success');
    // debit_wallet() originally recorded before=1000, after=700 (debit 300);
    // refund_failed_transaction() then overwrote balance_after back to 1000.
    insert_tx($u3, "SECT3C{$RUNID}2", 'debit', 'airtime', 300, 1000, 1000, 'failed');
    $r = reconcile_wallet($u3);
    check("In-place-refunded failed debit excluded from ledger sum (diff=0)", abs($r['diff']) < TOL, "diff={$r['diff']}");

    // ------------------------------------------------------------------
    // 4. Failed debit that was NEVER refunded (admin 'fail_only') — money
    // genuinely stayed gone, must be counted as a real debit.
    // ------------------------------------------------------------------
    echo "\n[4] Failed debit that was never refunded (kept)\n";
    $u4 = make_user($RUNID, 'kept', 700, 0);
    $cleanup['user_ids'][] = $u4;
    insert_tx($u4, "SECT3D{$RUNID}1", 'credit', 'wallet_funding', 1000, 0, 1000, 'success');
    insert_tx($u4, "SECT3D{$RUNID}2", 'debit', 'airtime', 300, 1000, 700, 'failed');
    $r = reconcile_wallet($u4);
    check("Never-refunded failed debit still counted (diff=0, balance genuinely 700)", abs($r['diff']) < TOL, "diff={$r['diff']}");

    // ------------------------------------------------------------------
    // 5. Failed debit resolved via a SEPARATE reversing credit row
    // (cron_refund.php / webhook pattern) — both rows included normally,
    // net zero achieved via two rows rather than one in-place edit.
    // ------------------------------------------------------------------
    echo "\n[5] Failed debit + separate reversing credit row (cron/webhook pattern)\n";
    $u5 = make_user($RUNID, 'separate', 1000, 0);
    $cleanup['user_ids'][] = $u5;
    insert_tx($u5, "SECT3E{$RUNID}1", 'credit', 'wallet_funding', 1000, 0, 1000, 'success');
    insert_tx($u5, "SECT3E{$RUNID}2", 'debit', 'airtime', 300, 1000, 700, 'failed'); // balance_after untouched
    insert_tx($u5, "SECT3E{$RUNID}3", 'credit', 'refund', 300, 700, 1000, 'success'); // separate reversing row
    $r = reconcile_wallet($u5);
    check("Debit + separate reversing credit nets to zero (diff=0)", abs($r['diff']) < TOL, "diff={$r['diff']}");

    // ------------------------------------------------------------------
    // 6. Genuinely ambiguous failed debit — balance_after matches neither
    // heuristic (simulating interleaved activity). Must not be silently
    // guessed; the platform-level ambiguous-detection query should catch it.
    // ------------------------------------------------------------------
    echo "\n[6] Ambiguous failed debit (matches neither heuristic)\n";
    $u6 = make_user($RUNID, 'ambiguous', 850, 0);
    $cleanup['user_ids'][] = $u6;
    $ambigRef = "SECT3F{$RUNID}1";
    insert_tx($u6, $ambigRef, 'debit', 'airtime', 300, 1000, 850, 'failed'); // neither 1000 nor 700
    $ambigCheck = db()->prepare("
        SELECT COUNT(*) FROM transactions
        WHERE transaction_ref = ? AND type='debit' AND status='failed'
          AND ABS(balance_after - balance_before) > " . TOL . "
          AND ABS(balance_after - (balance_before - amount)) > " . TOL
    );
    $ambigCheck->execute([$ambigRef]);
    check("Ambiguous failed debit flagged by the platform-level query", (int)$ambigCheck->fetchColumn() === 1);

    // ------------------------------------------------------------------
    // 7. Cashback earning must never leak into the wallet ledger sum.
    // ------------------------------------------------------------------
    echo "\n[7] Cashback earning excluded from wallet ledger\n";
    $u7 = make_user($RUNID, 'cbearn', 1000, 50);
    $cleanup['user_ids'][] = $u7;
    insert_tx($u7, "SECT3G{$RUNID}1", 'credit', 'wallet_funding', 1000, 0, 1000, 'success');
    insert_tx($u7, "SECT3G{$RUNID}2", 'credit', 'cashback', 50, 0, 50, 'success'); // cashback_balance row, NOT wallet
    $rw = reconcile_wallet($u7);
    $rc = reconcile_cashback($u7);
    check("Cashback-earning row does not affect wallet reconciliation (diff=0)", abs($rw['diff']) < TOL, "diff={$rw['diff']}");
    check("Cashback-earning row correctly counted in cashback reconciliation (diff=0)", abs($rc['diff']) < TOL, "diff={$rc['diff']}");

    // ------------------------------------------------------------------
    // 8. Cashback withdrawal pair: debit half hits cashback_balance,
    // credit half hits wallet_balance — must be split correctly.
    // ------------------------------------------------------------------
    echo "\n[8] Cashback withdrawal pair split correctly between the two ledgers\n";
    $u8 = make_user($RUNID, 'cbwd', 1100, 0);
    $cleanup['user_ids'][] = $u8;
    insert_tx($u8, "SECT3H{$RUNID}1", 'credit', 'wallet_funding', 1000, 0, 1000, 'success');
    insert_tx($u8, "SECT3H{$RUNID}2", 'credit', 'cashback', 100, 0, 100, 'success');
    insert_tx($u8, "SECT3H{$RUNID}3", 'debit', 'cashback_withdrawal', 100, 100, 0, 'success'); // cashback_balance -100
    insert_tx($u8, "SECT3H{$RUNID}4", 'credit', 'cashback_withdrawal', 100, 1000, 1100, 'success'); // wallet_balance +100
    $rw = reconcile_wallet($u8);
    $rc = reconcile_cashback($u8);
    check("Wallet ledger includes the withdrawal's credit half only (diff=0)", abs($rw['diff']) < TOL, "diff={$rw['diff']}");
    check("Cashback ledger includes the withdrawal's debit half only, nets to 0 (diff=0)", abs($rc['diff']) < TOL, "diff={$rc['diff']}");

    // ------------------------------------------------------------------
    // 9. Negative balance detection.
    // ------------------------------------------------------------------
    echo "\n[9] Negative balance detection\n";
    $u9 = make_user($RUNID, 'negative', -50, 0);
    $cleanup['user_ids'][] = $u9;
    $negCheck = db()->prepare("SELECT wallet_balance FROM users WHERE id=? AND wallet_balance < 0");
    $negCheck->execute([$u9]);
    check("Negative wallet balance is detected by the platform-level query", $negCheck->fetchColumn() !== false);

    // ------------------------------------------------------------------
    // 10. Orphaned transaction detection (user_id with no matching user).
    // ------------------------------------------------------------------
    echo "\n[10] Orphaned transaction detection\n";
    $fakeUid = 999999000 + random_int(1, 999);
    $orphanRef = "SECT3I{$RUNID}1";
    insert_tx($fakeUid, $orphanRef, 'debit', 'airtime', 100, 1000, 900, 'success');
    $orphanCheck = db()->prepare("SELECT COUNT(*) FROM transactions t LEFT JOIN users u ON u.id=t.user_id WHERE u.id IS NULL AND t.transaction_ref=?");
    $orphanCheck->execute([$orphanRef]);
    check("Orphaned transaction (nonexistent user_id) is detected", (int)$orphanCheck->fetchColumn() === 1);
    db()->prepare("DELETE FROM transactions WHERE transaction_ref=?")->execute([$orphanRef]);

    // ------------------------------------------------------------------
    // 11. Legacy opening balance: a migrated user's pre-ledger balance is
    // correctly folded in via legacy_migration_log, not flagged as a
    // mismatch just because the ledger alone doesn't explain it.
    // ------------------------------------------------------------------
    echo "\n[11] Legacy migration opening balance handled correctly\n";
    $u11 = make_user($RUNID, 'legacy', 1300, 0); // 300 opening + 1000 ledger credit
    $cleanup['user_ids'][] = $u11;
    db()->prepare("INSERT INTO legacy_migration_log (old_user_id, action, new_user_id, note, old_wallet_snapshot, old_cashback_snapshot) VALUES (?, 'inserted', ?, 'test', 300, 0)")->execute([900000 + $u11, $u11]);
    insert_tx($u11, "SECT3J{$RUNID}1", 'credit', 'wallet_funding', 1000, 300, 1300, 'success');
    $r = reconcile_wallet($u11);
    check("Legacy opening balance + ledger correctly explains actual balance (diff=0)", abs($r['diff']) < TOL, "expected={$r['expected']} actual={$r['actual']}");

} finally {
    echo "\n[cleanup] removing test fixtures\n";
    db()->exec("DELETE FROM legacy_migration_log WHERE note='test' AND new_user_id IN (" . implode(',', array_map('intval', $cleanup['user_ids'] ?: [0])) . ")");
    db()->exec("DELETE FROM transactions WHERE transaction_ref LIKE 'SECT3%{$RUNID}%'");
    if ($cleanup['user_ids']) {
        $in = implode(',', array_fill(0, count($cleanup['user_ids']), '?'));
        db()->prepare("DELETE FROM users WHERE id IN ($in)")->execute($cleanup['user_ids']);
    }
}

echo "\n=== RESULTS: $PASS passed, $FAIL failed ===\n";
if ($FAILURES) {
    echo "\nFailed checks:\n";
    foreach ($FAILURES as $f) echo " - $f\n";
}
exit($FAIL > 0 ? 1 : 0);
