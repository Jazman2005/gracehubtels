<?php
/**
 * Wallet & Cashback Reconciliation Report — READ ONLY.
 *
 * This page never writes to users.wallet_balance, users.cashback_balance,
 * virtual_cards.balance, or the transactions table. It only computes what
 * each balance *should* be from the ledger and compares it against what is
 * actually stored, surfacing differences for a human to investigate.
 *
 * Reconciliation model (see Phase 3 audit report for the full reasoning):
 *
 * expected_wallet_balance   = opening_wallet_balance   + wallet_ledger_sum
 * expected_cashback_balance = opening_cashback_balance + cashback_ledger_sum
 *
 * opening_*_balance comes from legacy_migration_log (old_wallet_snapshot /
 * old_cashback_snapshot), which is the only record of a balance that
 * predates this ledger — 0 for every user who was never touched by the
 * legacy migration. If that table doesn't exist on this install, opening
 * balance is treated as 0 for everyone (flagged in the summary).
 *
 * wallet_ledger_sum / cashback_ledger_sum are computed per transaction row:
 *   - category='cashback' rows only ever move cashback_balance (never wallet)
 *     — apply_cashback() writes cashback_balance and stamps balance_before/
 *     after with THAT balance, not wallet_balance.
 *   - category='cashback_withdrawal' is two rows per event: a type='debit'
 *     row against cashback_balance and a type='credit' row against
 *     wallet_balance (see user/core/cashback.php::process_cashback_withdrawal).
 *   - a type='debit' row with status='failed' is ambiguous: refund_failed_
 *     transaction() does NOT insert a reversing ledger row — it overwrites
 *     the ORIGINAL debit row's balance_after in place, so the row's `amount`
 *     still shows the original debit even though the net effect was zero.
 *     Other failure paths (cron_refund.php, webhook/vtu, cron_check_vtu)
 *     instead leave the original row alone and credit_wallet() a SEPARATE
 *     reversing row. We can't tell these apart from status/category alone,
 *     but we CAN tell from the numbers: an in-place-refunded row ends up
 *     with balance_after ≈ balance_before (nothing was really kept), while
 *     a row that was either kept or reversed via a separate credit still
 *     has balance_after ≈ balance_before - amount (the original debit
 *     stands on its own, and if it WAS reversed, the separate credit row
 *     nets it out on its own). So: balance_after≈balance_before → treat as
 *     net-zero and exclude; otherwise include as a normal debit. Rows where
 *     neither holds are flagged separately as ambiguous rather than guessed.
 *   - every other row (any status pending/processing/success, any non-
 *     cashback category) is included at face value: credit adds, debit
 *     subtracts. A 'pending'/'processing' debit already reduced the real
 *     wallet_balance the moment debit_wallet() ran, so it must be counted
 *     even though the purchase hasn't resolved yet.
 */
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_admin();
require_permission('manage_transactions');

const TOL = 0.01; // rounding tolerance in Naira/USD

// table_exists()/column_exists() now live in user/core/db.php (shared).

$has_legacy_log = table_exists('legacy_migration_log')
    && column_exists('legacy_migration_log', 'new_user_id')
    && column_exists('legacy_migration_log', 'old_wallet_snapshot')
    && column_exists('legacy_migration_log', 'old_cashback_snapshot');

// ---------------------------------------------------------------------
// Per-user wallet & cashback ledger sums
// ---------------------------------------------------------------------
$wallet_case = "
    SUM(CASE
        WHEN category = 'cashback' THEN 0
        WHEN category = 'cashback_withdrawal' AND type = 'debit' THEN 0
        WHEN type = 'debit' AND status = 'failed' AND ABS(balance_after - balance_before) < " . TOL . " THEN 0
        WHEN type = 'credit' THEN amount
        WHEN type = 'debit' THEN -amount
        ELSE 0
    END) AS wallet_ledger_sum
";
$cashback_case = "
    SUM(CASE
        WHEN category = 'cashback' THEN amount
        WHEN category = 'cashback_withdrawal' AND type = 'debit' THEN -amount
        ELSE 0
    END) AS cashback_ledger_sum
";

$legacy_join = '';
$legacy_select = '0 AS opening_wallet, 0 AS opening_cashback';
if ($has_legacy_log) {
    $legacy_select = 'COALESCE(lg.opening_wallet,0) AS opening_wallet, COALESCE(lg.opening_cashback,0) AS opening_cashback';
    $legacy_join = "LEFT JOIN (
        SELECT new_user_id, SUM(old_wallet_snapshot) AS opening_wallet, SUM(old_cashback_snapshot) AS opening_cashback
        FROM legacy_migration_log WHERE new_user_id IS NOT NULL GROUP BY new_user_id
    ) lg ON lg.new_user_id = u.id";
}

$sql = "
SELECT
    u.id, u.full_name, u.email, u.phone, u.wallet_balance, u.cashback_balance,
    $legacy_select,
    COALESCE(wl.wallet_ledger_sum,0) AS wallet_ledger_sum,
    COALESCE(cl.cashback_ledger_sum,0) AS cashback_ledger_sum
FROM users u
$legacy_join
LEFT JOIN (SELECT user_id, $wallet_case FROM transactions GROUP BY user_id) wl ON wl.user_id = u.id
LEFT JOIN (SELECT user_id, $cashback_case FROM transactions WHERE category IN ('cashback','cashback_withdrawal') GROUP BY user_id) cl ON cl.user_id = u.id
";

$rows = db()->query($sql)->fetchAll();

$wallet_mismatches = [];
$cashback_mismatches = [];
$negative_wallet = [];
$negative_cashback = [];
$total_expected_wallet = 0;
$total_actual_wallet = 0;

foreach ($rows as $r) {
    $expected_wallet = (float)$r['opening_wallet'] + (float)$r['wallet_ledger_sum'];
    $actual_wallet = (float)$r['wallet_balance'];
    $wallet_diff = round($actual_wallet - $expected_wallet, 2);
    $total_expected_wallet += $expected_wallet;
    $total_actual_wallet += $actual_wallet;

    $expected_cashback = (float)$r['opening_cashback'] + (float)$r['cashback_ledger_sum'];
    $actual_cashback = (float)$r['cashback_balance'];
    $cashback_diff = round($actual_cashback - $expected_cashback, 2);

    if (abs($wallet_diff) > TOL) {
        $wallet_mismatches[] = $r + ['expected_wallet' => $expected_wallet, 'actual_wallet' => $actual_wallet, 'diff' => $wallet_diff];
    }
    if (abs($cashback_diff) > TOL) {
        $cashback_mismatches[] = $r + ['expected_cashback' => $expected_cashback, 'actual_cashback' => $actual_cashback, 'diff' => $cashback_diff];
    }
    if ($actual_wallet < 0) $negative_wallet[] = $r;
    if ($actual_cashback < 0) $negative_cashback[] = $r;
}
usort($wallet_mismatches, fn($a, $b) => abs($b['diff']) <=> abs($a['diff']));
usort($cashback_mismatches, fn($a, $b) => abs($b['diff']) <=> abs($a['diff']));

// ---------------------------------------------------------------------
// Ambiguous failed debits — status='failed' rows where balance_after
// matches neither "refunded in place" (≈balance_before) nor "kept /
// separately reversed" (≈balance_before-amount). Genuinely unclear which
// bucket these fall into; surfaced for manual review rather than guessed.
// ---------------------------------------------------------------------
$ambiguous = db()->query("
    SELECT t.*, u.full_name, u.phone FROM transactions t
    LEFT JOIN users u ON u.id = t.user_id
    WHERE t.type='debit' AND t.status='failed'
      AND ABS(t.balance_after - t.balance_before) > " . TOL . "
      AND ABS(t.balance_after - (t.balance_before - t.amount)) > " . TOL . "
    ORDER BY t.created_at DESC LIMIT 100
")->fetchAll();

// ---------------------------------------------------------------------
// Orphaned transactions — user_id with no matching row in users. There is
// no foreign key enforcing this at the DB level, so it is possible.
// ---------------------------------------------------------------------
$orphaned = db()->query("
    SELECT t.id, t.transaction_ref, t.user_id, t.amount, t.category, t.status, t.created_at
    FROM transactions t
    LEFT JOIN users u ON u.id = t.user_id
    WHERE u.id IS NULL
    ORDER BY t.created_at DESC LIMIT 100
")->fetchAll();
$orphaned_count = db()->query("
    SELECT COUNT(*) FROM transactions t LEFT JOIN users u ON u.id = t.user_id WHERE u.id IS NULL
")->fetchColumn();

// ---------------------------------------------------------------------
// Duplicate idempotency_key — should be structurally impossible given the
// UNIQUE constraint on transactions.idempotency_key, checked anyway as a
// direct sanity check on that constraint actually being in place.
// ---------------------------------------------------------------------
$has_idempotency_key = column_exists('transactions', 'idempotency_key');
$dup_idem = $has_idempotency_key ? db()->query("
    SELECT idempotency_key, COUNT(*) c FROM transactions
    WHERE idempotency_key IS NOT NULL
    GROUP BY idempotency_key HAVING c > 1
")->fetchAll() : [];

// ---------------------------------------------------------------------
// Impossible / suspicious transaction states.
// ---------------------------------------------------------------------
$bad_amount = db()->query("SELECT id, transaction_ref, user_id, amount, category, status, created_at FROM transactions WHERE amount <= 0 ORDER BY created_at DESC LIMIT 50")->fetchAll();
$bad_amount_count = db()->query("SELECT COUNT(*) FROM transactions WHERE amount <= 0")->fetchColumn();

$has_virtual_cards = table_exists('virtual_cards');
$negative_cards = $has_virtual_cards ? db()->query("SELECT card_id, user_id, balance, status FROM virtual_cards WHERE balance < 0")->fetchAll() : [];

// Long-stuck pending/processing transactions — operational, not necessarily
// a ledger error, but a debit that has been sitting unresolved this long is
// worth a human's attention.
$stuck = db()->query("
    SELECT id, transaction_ref, user_id, category, amount, status, created_at
    FROM transactions
    WHERE status IN ('pending','processing') AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ORDER BY created_at ASC LIMIT 100
")->fetchAll();
$stuck_count = db()->query("SELECT COUNT(*) FROM transactions WHERE status IN ('pending','processing') AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();

$csrf = admin_csrf();
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Wallet Reconciliation — <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title><link rel="icon" type="image/png" href="/assets/images/favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header"><div><h2>Wallet &amp; Cashback Reconciliation</h2><p>Read-only report. Nothing on this page ever changes a balance.</p></div></div>

<?php if (!$has_legacy_log): ?>
<div class="save-toast show err" style="position:static;display:block;margin-bottom:16px;padding:12px 16px;">
  <i class="fas fa-triangle-exclamation"></i> <code>legacy_migration_log</code> not found on this database — opening balances are assumed to be ₦0 for every user. If any users were carried over from a previous system without a matching row in that table, they will show up below as a wallet mismatch that is not actually an error.
</div>
<?php endif; ?>
<?php if (!$has_virtual_cards): ?>
<div class="save-toast show err" style="position:static;display:block;margin-bottom:16px;padding:12px 16px;">
  <i class="fas fa-triangle-exclamation"></i> <code>virtual_cards</code> table not found on this database — the negative-balance check below skips virtual cards entirely rather than failing the whole page.
</div>
<?php endif; ?>
<?php if (!$has_idempotency_key): ?>
<div class="save-toast show err" style="position:static;display:block;margin-bottom:16px;padding:12px 16px;">
  <i class="fas fa-triangle-exclamation"></i> <code>transactions.idempotency_key</code> column not found on this database — the duplicate idempotency key check below is skipped rather than failing the whole page.
</div>
<?php endif; ?>

<div class="kpi-grid">
  <div class="metric-box"><div class="kpi-icon blue"><i class="fas fa-users" style="font-size:18px;"></i></div><div class="kpi-info"><span class="kpi-label">Users Checked</span><span class="kpi-value"><?=number_format(count($rows))?></span></div></div>
  <div class="metric-box"><div class="kpi-icon orange"><i class="fas fa-scale-unbalanced" style="font-size:18px;"></i></div><div class="kpi-info"><span class="kpi-label">Wallet Mismatches</span><span class="kpi-value"><?=number_format(count($wallet_mismatches))?></span></div></div>
  <div class="metric-box"><div class="kpi-icon orange"><i class="fas fa-scale-unbalanced" style="font-size:18px;"></i></div><div class="kpi-info"><span class="kpi-label">Cashback Mismatches</span><span class="kpi-value"><?=number_format(count($cashback_mismatches))?></span></div></div>
  <div class="metric-box"><div class="kpi-icon red"><i class="fas fa-circle-exclamation" style="font-size:18px;"></i></div><div class="kpi-info"><span class="kpi-label">Negative Balances</span><span class="kpi-value"><?=number_format(count($negative_wallet)+count($negative_cashback)+count($negative_cards))?></span></div></div>
</div>
<div class="kpi-grid">
  <div class="metric-box"><div class="kpi-icon red"><i class="fas fa-link-slash" style="font-size:18px;"></i></div><div class="kpi-info"><span class="kpi-label">Orphaned Transactions</span><span class="kpi-value"><?=number_format($orphaned_count)?></span></div></div>
  <div class="metric-box"><div class="kpi-icon red"><i class="fas fa-copy" style="font-size:18px;"></i></div><div class="kpi-info"><span class="kpi-label">Duplicate Idempotency Keys</span><span class="kpi-value"><?=number_format(count($dup_idem))?></span></div></div>
  <div class="metric-box"><div class="kpi-icon orange"><i class="fas fa-circle-question" style="font-size:18px;"></i></div><div class="kpi-info"><span class="kpi-label">Ambiguous Failed Debits</span><span class="kpi-value"><?=number_format(count($ambiguous))?></span></div></div>
  <div class="metric-box"><div class="kpi-icon orange"><i class="fas fa-clock" style="font-size:18px;"></i></div><div class="kpi-info"><span class="kpi-label">Stuck &gt;24h Pending</span><span class="kpi-value"><?=number_format($stuck_count)?></span></div></div>
</div>

<div class="data-panel">
  <div class="panel-title-bar"><h3>Platform Totals</h3></div>
  <div style="padding:16px 24px;display:grid;grid-template-columns:repeat(3,1fr);gap:16px;">
    <div><div style="font-size:12px;color:var(--text-muted)">Sum of actual wallet_balance</div><div style="font-weight:700;font-size:16px;">₦<?=number_format($total_actual_wallet,2)?></div></div>
    <div><div style="font-size:12px;color:var(--text-muted)">Sum of expected (ledger-derived) balance</div><div style="font-weight:700;font-size:16px;">₦<?=number_format($total_expected_wallet,2)?></div></div>
    <div><div style="font-size:12px;color:var(--text-muted)">Platform-wide delta</div><div style="font-weight:700;font-size:16px;<?=abs($total_actual_wallet-$total_expected_wallet)>TOL?'color:var(--danger)':'color:var(--success)'?>">₦<?=number_format($total_actual_wallet-$total_expected_wallet,2)?></div></div>
  </div>
</div>

<div class="data-panel">
  <div class="panel-title-bar"><h3>Wallet Balance Mismatches (top <?=min(200,count($wallet_mismatches))?> of <?=count($wallet_mismatches)?>)</h3></div>
  <div class="responsive-grid-wrapper"><table class="data-table">
    <thead><tr><th>User</th><th>Opening</th><th>Ledger Sum</th><th>Expected</th><th>Actual</th><th>Diff</th></tr></thead>
    <tbody>
    <?php foreach(array_slice($wallet_mismatches,0,200) as $m):?>
    <tr class="clickable-row" onclick="location.href='users-management.php?q=<?=$m['id']?>'">
      <td><?=htmlspecialchars($m['full_name'])?><br><span style="color:var(--text-muted);font-size:11px;"><?=htmlspecialchars($m['email']?:$m['phone'])?></span></td>
      <td>₦<?=number_format((float)$m['opening_wallet'],2)?></td>
      <td>₦<?=number_format((float)$m['wallet_ledger_sum'],2)?></td>
      <td>₦<?=number_format($m['expected_wallet'],2)?></td>
      <td>₦<?=number_format($m['actual_wallet'],2)?></td>
      <td style="font-weight:700;<?=$m['diff']>0?'color:var(--success)':'color:var(--danger)'?>"><?=$m['diff']>0?'+':''?>₦<?=number_format($m['diff'],2)?></td>
    </tr>
    <?php endforeach;if(empty($wallet_mismatches)):?><tr><td colspan="6" class="empty-state">No wallet mismatches found.</td></tr><?php endif;?>
    </tbody>
  </table></div>
</div>

<div class="data-panel">
  <div class="panel-title-bar"><h3>Cashback Balance Mismatches (top <?=min(200,count($cashback_mismatches))?> of <?=count($cashback_mismatches)?>)</h3></div>
  <div class="responsive-grid-wrapper"><table class="data-table">
    <thead><tr><th>User</th><th>Opening</th><th>Ledger Sum</th><th>Expected</th><th>Actual</th><th>Diff</th></tr></thead>
    <tbody>
    <?php foreach(array_slice($cashback_mismatches,0,200) as $m):?>
    <tr class="clickable-row" onclick="location.href='users-management.php?q=<?=$m['id']?>'">
      <td><?=htmlspecialchars($m['full_name'])?><br><span style="color:var(--text-muted);font-size:11px;"><?=htmlspecialchars($m['email']?:$m['phone'])?></span></td>
      <td>₦<?=number_format((float)$m['opening_cashback'],2)?></td>
      <td>₦<?=number_format((float)$m['cashback_ledger_sum'],2)?></td>
      <td>₦<?=number_format($m['expected_cashback'],2)?></td>
      <td>₦<?=number_format($m['actual_cashback'],2)?></td>
      <td style="font-weight:700;<?=$m['diff']>0?'color:var(--success)':'color:var(--danger)'?>"><?=$m['diff']>0?'+':''?>₦<?=number_format($m['diff'],2)?></td>
    </tr>
    <?php endforeach;if(empty($cashback_mismatches)):?><tr><td colspan="6" class="empty-state">No cashback mismatches found.</td></tr><?php endif;?>
    </tbody>
  </table></div>
</div>

<div class="data-panel">
  <div class="panel-title-bar"><h3>Negative Balances</h3></div>
  <div class="responsive-grid-wrapper"><table class="data-table">
    <thead><tr><th>Type</th><th>User / Card</th><th>Balance</th></tr></thead>
    <tbody>
    <?php foreach($negative_wallet as $n):?><tr><td>Wallet</td><td><?=htmlspecialchars($n['full_name'])?></td><td style="color:var(--danger);font-weight:700;">₦<?=number_format((float)$n['wallet_balance'],2)?></td></tr><?php endforeach;?>
    <?php foreach($negative_cashback as $n):?><tr><td>Cashback</td><td><?=htmlspecialchars($n['full_name'])?></td><td style="color:var(--danger);font-weight:700;">₦<?=number_format((float)$n['cashback_balance'],2)?></td></tr><?php endforeach;?>
    <?php foreach($negative_cards as $n):?><tr><td>Virtual Card</td><td>Card <?=htmlspecialchars($n['card_id'])?> (user #<?=$n['user_id']?>)</td><td style="color:var(--danger);font-weight:700;">$<?=number_format((float)$n['balance'],2)?></td></tr><?php endforeach;?>
    <?php if(empty($negative_wallet)&&empty($negative_cashback)&&empty($negative_cards)):?><tr><td colspan="3" class="empty-state">No negative balances found.</td></tr><?php endif;?>
    </tbody>
  </table></div>
</div>

<div class="data-panel">
  <div class="panel-title-bar"><h3>Ambiguous Failed Debits (needs manual review)</h3></div>
  <p style="padding:0 24px;font-size:12px;color:var(--text-muted);">These rows are marked failed but their before/after balance doesn't cleanly match either "fully refunded in place" or "kept as a real debit". Treated as neither in the totals above — investigate individually via Transaction Details.</p>
  <div class="responsive-grid-wrapper"><table class="data-table">
    <thead><tr><th>Ref</th><th>User</th><th>Category</th><th>Amount</th><th>Before</th><th>After</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach($ambiguous as $a):?>
    <tr class="clickable-row" onclick="location.href='transaction-details.php?ref=<?=urlencode($a['transaction_ref'])?>'">
      <td><code><?=htmlspecialchars($a['transaction_ref'])?></code></td>
      <td><?=htmlspecialchars($a['full_name']??'Unknown')?></td>
      <td><?=ucwords(str_replace('_',' ',$a['category']))?></td>
      <td>₦<?=number_format((float)$a['amount'],2)?></td>
      <td>₦<?=number_format((float)$a['balance_before'],2)?></td>
      <td>₦<?=number_format((float)$a['balance_after'],2)?></td>
      <td><?=fmt_tx_time($a['created_at'])?></td>
    </tr>
    <?php endforeach;if(empty($ambiguous)):?><tr><td colspan="7" class="empty-state">None found.</td></tr><?php endif;?>
    </tbody>
  </table></div>
</div>

<div class="data-panel">
  <div class="panel-title-bar"><h3>Orphaned Transactions (user_id has no matching user)</h3></div>
  <div class="responsive-grid-wrapper"><table class="data-table">
    <thead><tr><th>Ref</th><th>User ID</th><th>Category</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach($orphaned as $o):?>
    <tr>
      <td><code><?=htmlspecialchars($o['transaction_ref'])?></code></td>
      <td><?=$o['user_id']?></td>
      <td><?=ucwords(str_replace('_',' ',$o['category']))?></td>
      <td>₦<?=number_format((float)$o['amount'],2)?></td>
      <td><?=ucfirst($o['status'])?></td>
      <td><?=fmt_tx_time($o['created_at'])?></td>
    </tr>
    <?php endforeach;if(empty($orphaned)):?><tr><td colspan="6" class="empty-state">None found.</td></tr><?php endif;?>
    </tbody>
  </table></div>
</div>

<div class="data-panel">
  <div class="panel-title-bar"><h3>Transactions With Zero/Negative Amount (<?=number_format($bad_amount_count)?> total)</h3></div>
  <div class="responsive-grid-wrapper"><table class="data-table">
    <thead><tr><th>Ref</th><th>User ID</th><th>Category</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach($bad_amount as $b):?>
    <tr>
      <td><code><?=htmlspecialchars($b['transaction_ref'])?></code></td>
      <td><?=$b['user_id']?></td>
      <td><?=ucwords(str_replace('_',' ',$b['category']))?></td>
      <td>₦<?=number_format((float)$b['amount'],2)?></td>
      <td><?=ucfirst($b['status'])?></td>
      <td><?=fmt_tx_time($b['created_at'])?></td>
    </tr>
    <?php endforeach;if(empty($bad_amount)):?><tr><td colspan="6" class="empty-state">None found.</td></tr><?php endif;?>
    </tbody>
  </table></div>
</div>

<div class="data-panel">
  <div class="panel-title-bar"><h3>Stuck Pending/Processing &gt; 24h (<?=number_format($stuck_count)?> total)</h3></div>
  <p style="padding:0 24px;font-size:12px;color:var(--text-muted);">Money already left the wallet for these — they are counted as real debits above. Operational issue: cron_refund.php / cron_check_vtu.php should normally resolve these within minutes.</p>
  <div class="responsive-grid-wrapper"><table class="data-table">
    <thead><tr><th>Ref</th><th>User ID</th><th>Category</th><th>Amount</th><th>Status</th><th>Age</th></tr></thead>
    <tbody>
    <?php foreach($stuck as $s):?>
    <tr class="clickable-row" onclick="location.href='transaction-details.php?ref=<?=urlencode($s['transaction_ref'])?>'">
      <td><code><?=htmlspecialchars($s['transaction_ref'])?></code></td>
      <td><?=$s['user_id']?></td>
      <td><?=ucwords(str_replace('_',' ',$s['category']))?></td>
      <td>₦<?=number_format((float)$s['amount'],2)?></td>
      <td><?=ucfirst($s['status'])?></td>
      <td><?=fmt_tx_time($s['created_at'])?></td>
    </tr>
    <?php endforeach;if(empty($stuck)):?><tr><td colspan="6" class="empty-state">None found.</td></tr><?php endif;?>
    </tbody>
  </table></div>
</div>

</div></div>
<script>function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}</script>
</body></html>
