<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
require_once __DIR__.'/../user/core/bank_transfer.php';

auth_admin();
$csrf = admin_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    // Approving/rejecting a withdrawal moves real money via PalmPay — requires
    // the explicit "manage_withdrawals" permission, not just any admin session.
    require_permission('manage_withdrawals');
    if (!verify_admin_csrf($_POST['csrf']??'')) json_response(['success'=>false,'message'=>'Invalid token'],403);
    $action = $_POST['action'] ?? '';
    $ref = $_POST['ref'] ?? '';
    if (!$ref) json_response(['success'=>false,'message'=>'Missing ref'],400);

    // The atomic pending->processing claim below is the real gate against a
    // concurrent double-approve, but a clean/fast rejection from PalmPay (or
    // it simply being unconfigured) resets the claim straight back to
    // 'pending' with no delay — a tight burst of concurrent clicks can each
    // in turn claim, get an instant local/fast-fail response, and release,
    // meaning several of them can genuinely reach the live transfer call
    // rather than being turned away with "already processed". This lock
    // closes that window without touching the legitimate retry-after-a-
    // real-failure flow, which still works fine after it expires.
    if (!claim_purchase_lock((int)$_SESSION['admin_id'], 'withdrawal_' . $action, [$ref], 10)) {
        json_response(['success'=>false,'message'=>'Please wait a moment before retrying this action.']);
    }

    $tx = db()->prepare("SELECT t.*, u.full_name, u.email, u.phone, u.wallet_balance FROM transactions t JOIN users u ON t.user_id=u.id WHERE t.transaction_ref=? AND t.category='bank_transfer' AND t.status='pending' LIMIT 1");
    $tx->execute([$ref]);
    $tx = $tx->fetch();
    if (!$tx) json_response(['success'=>false,'message'=>'Transaction not found or already processed']);

    $meta = json_decode($tx['meta'] ?? '{}', true);
    $amount = (float)$tx['amount'];
    $charge = (float)$meta['charge'] ?? $tx['charge'];
    $total = $amount + $charge;

    if ($action === 'approve') {
        // Atomically claim this withdrawal BEFORE calling the live PalmPay
        // transfer API. Previously the transfer fired first and
        // update_tx_status() only ran (with its return value discarded)
        // *after* a successful response came back — two concurrent approve
        // requests for the same ref (double-click, two admin tabs, a
        // duplicate AJAX retry) could both pass the initial unlocked SELECT
        // and both execute a real bank transfer for the same withdrawal
        // before either write landed. This UPDATE is the single atomic gate:
        // only the request that flips pending -> processing here is allowed
        // to call the transfer API at all; a loser sees rowCount()===0 and
        // must not send anything.
        $claim = db()->prepare("UPDATE transactions SET status='processing', updated_at=NOW() WHERE transaction_ref=? AND status='pending'");
        $claim->execute([$ref]);
        if ($claim->rowCount() === 0) {
            json_response(['success'=>false,'message'=>'Transaction already processed']);
        }

        // Execute the PalmPay transfer
        $api = new PalmPayAPI();
        if (!$api->is_configured()) {
            update_tx_status($ref, 'pending'); // release the claim — nothing was sent
            json_response(['success'=>false,'message'=>'PalmPay not configured']);
        }
        $notify = 'https://www.gracehubtels.com/webhook/palmpay/index.php';
        $res = $api->transfer($ref, $meta['payee_name'] ?? '', $meta['bank_code'], $meta['account_number'], $tx['phone'], $amount, $notify, $meta['narration'] ?? '');
        if ($res && ($res['respCode']??'') === '00000000') {
            if (update_tx_status($ref, 'success', $res['data']['orderNo'] ?? null)) {
                $cb = apply_cashback($tx['user_id'], $amount, 'bank_transfer', null);
                notify_user($tx['user_id'], 'Transfer Successful', number_format($amount,2) . " sent to " . ($meta['bank_code']??'') . " - " . ($meta['account_number']??'') . ".", 'success');
                send_whatsapp_alert("✅ *Withdrawal Approved & Sent!*\nUser: " . ($tx['full_name'] ?? 'Unknown') . " (" . ($tx['email'] ?? $tx['phone'] ?? '') . ")\nAmount: ₦" . number_format($amount, 2) . "\nRecipient: " . ($meta['payee_name'] ?? 'N/A') . " - " . ($meta['bank_code'] ?? '') . " " . ($meta['account_number'] ?? '') . "\nRef: {$ref}");
                try{notify_admin('Withdrawal Approved',"
<div style='text-align:center;padding:10px 0 16px;'>
<span style='font-size:28px;line-height:1;font-weight:800;color:#16a34a;'>OK</span>
<h3 style='color:#FF5A1F;font-size:18px;margin:8px 0 4px;'>Withdrawal Approved &amp; Sent</h3>
<p style='color:#475569;font-size:13px;margin:0;'>A withdrawal has been approved and paid out.</p>
</div>
<table style='width:100%;border-collapse:collapse;'>
<tr><td style='padding:8px 12px;background:#f3f4f6;border-radius:8px;'>
<table width='100%'>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>User</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>".htmlspecialchars($tx['full_name']??'Unknown',ENT_QUOTES)."</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Phone</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>".htmlspecialchars($tx['phone']??'',ENT_QUOTES)."</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Amount</td><td style='padding:4px 0;text-align:right;font-weight:700;color:#16a34a;font-size:14px;'>₦".number_format($amount,2)."</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Recipient</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>".htmlspecialchars(($meta['payee_name']??'N/A').' - '.($meta['bank_code']??''),ENT_QUOTES)."</td></tr>
</table>
</td></tr>
</table>");}catch(\Throwable$e){}
            }
            audit_log($_SESSION['admin_id'], 'APPROVE_WITHDRAWAL', "Approved withdrawal $ref for user #{$tx['user_id']}, N$amount sent to " . ($meta['bank_code']??'') . ' ' . ($meta['account_number']??''));
            json_response(['success'=>true,'message'=>'Transfer approved and executed successfully']);
        }
        if ($res === null) {
            // Network-level uncertainty on the transfer call itself (cURL
            // timeout/error — PalmPayAPI::request() returns null in that case,
            // never a decoded response) — PalmPay may or may not have actually
            // received and executed this transfer. We must NOT guess: resetting
            // to 'pending' would let an admin retry and risk sending the money
            // a second time; crediting a refund would risk giving the money
            // back on top of an actual successful transfer. Query PalmPay's
            // own status endpoint for the record and leave the transaction
            // claimed at 'processing' (no longer shown with Approve/Reject
            // buttons) until a human confirms the real outcome and resolves it
            // via transaction-details.php.
            $check = $api->query_transfer($ref);
            save_tx_response($ref, 'Uncertain outcome after transfer call (network error) — manual verification required. Provider query result: ' . json_encode($check));
            log_error('bank_transfer', 'Approval transfer outcome unknown after network error', ['ref'=>$ref, 'query_result'=>$check]);
            json_response(['success'=>false,'message'=>'Transfer outcome unknown due to a network error. This transaction is now LOCKED for manual review — do not retry. Verify directly with PalmPay, then resolve it from Transaction Details.']);
        }
        // Clean, confirmed provider response with a non-success code — PalmPay
        // explicitly did not execute this transfer, so it's safe to release
        // the claim back to pending for a legitimate retry.
        update_tx_status($ref, 'pending');
        $err = $res['respMsg'] ?? 'API error';
        log_error('bank_transfer', 'Approval transfer failed', ['ref'=>$ref,'response'=>$res]);
        json_response(['success'=>false,'message'=>'Transfer failed: ' . $err]);
    } elseif ($action === 'reject') {
        // Atomic claim — same principle as approve: only the request that
        // actually flips pending -> failed here proceeds to refund; a second
        // concurrent/duplicate reject click sees update_tx_status() return
        // false and must not credit the wallet again.
        if (!update_tx_status($ref, 'failed')) {
            json_response(['success'=>false,'message'=>'Transaction already processed']);
        }
        $rej_res = credit_wallet($tx['user_id'], $total, 'bank_transfer', 'Reversal: withdrawal rejected', ['rejected_ref'=>$ref], 'withdrawal_reject:'.$ref);
        notify_user($tx['user_id'], 'Withdrawal Rejected', "Your withdrawal of " . number_format($amount,2) . " has been rejected. " . number_format($total,2) . " has been refunded to your wallet", 'error', null, $rej_res['balance_before'], $rej_res['balance_after']);
        send_whatsapp_alert("❌ *Withdrawal Rejected*\nUser: " . ($tx['full_name'] ?? 'Unknown') . " (" . ($tx['email'] ?? $tx['phone'] ?? '') . ")\nAmount: ₦" . number_format($amount, 2) . "\nRef: {$ref}");
        try{notify_admin('Withdrawal Rejected',"
<div style='text-align:center;padding:10px 0 16px;'>
<span style='font-size:28px;line-height:1;font-weight:800;color:#dc2626;'>X</span>
<h3 style='color:#FF5A1F;font-size:18px;margin:8px 0 4px;'>Withdrawal Rejected</h3>
<p style='color:#475569;font-size:13px;margin:0;'>A withdrawal request has been rejected and refunded.</p>
</div>
<table style='width:100%;border-collapse:collapse;'>
<tr><td style='padding:8px 12px;background:#f3f4f6;border-radius:8px;'>
<table width='100%'>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>User</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>".htmlspecialchars($tx['full_name']??'Unknown',ENT_QUOTES)."</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Amount</td><td style='padding:4px 0;text-align:right;font-weight:700;color:#dc2626;font-size:14px;'>₦".number_format($amount,2)."</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Refunded</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#16a34a;font-size:13px;'>₦".number_format($total,2)."</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Ref</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>".htmlspecialchars($ref,ENT_QUOTES)."</td></tr>
</table>
</td></tr>
</table>");}catch(\Throwable$e){}
        audit_log($_SESSION['admin_id'], 'REJECT_WITHDRAWAL', "Rejected withdrawal $ref for user #{$tx['user_id']}, N$total refunded to wallet");
        json_response(['success'=>true,'message'=>'Withdrawal rejected and wallet refunded']);
    }
    json_response(['success'=>false,'message'=>'Unknown action']);
}

$tab = preg_replace('/[^a-z_]/', '', strtolower($_GET['tab'] ?? 'pending'));
$where = match($tab){
    'pending' => "t.category='bank_transfer' AND t.status='pending'",
    'approved' => "t.category='bank_transfer' AND t.status='success'",
    'rejected' => "t.category='bank_transfer' AND t.status='failed'",
    default => "t.category='bank_transfer'"
};

$txs = db()->query("
    SELECT t.*, u.full_name, u.email, u.phone 
    FROM transactions t 
    JOIN users u ON t.user_id = u.id 
    WHERE $where 
    ORDER BY t.created_at DESC
")->fetchAll();

$counts = db()->query("SELECT status, COUNT(*) as c FROM transactions WHERE category='bank_transfer' GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$pending_count = (int)($counts['pending'] ?? 0);
$success_count = (int)($counts['success'] ?? 0);
$failed_count = (int)($counts['failed'] ?? 0);

$total_volume = 0;
foreach($txs as $t) {
    if ($t['status'] === 'success') $total_volume += (float)$t['amount'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Bank Withdrawals – Gracehubtels Admin</title>
    <link rel="icon" type="image/png" href="/assets/images/gracehubtels-favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .tab-bar{display:flex;gap:4px;background:var(--bg);padding:4px;border-radius:10px;margin-bottom:20px;flex-wrap:wrap;border:1px solid var(--border)}
        .tab-btn{padding:8px 18px;border-radius:8px;font-size:13px;font-weight:600;border:none;cursor:pointer;background:transparent;color:var(--text-muted);transition:all .15s}
        .tab-btn.active{background:var(--surface);color:var(--primary);box-shadow:var(--shadow-sm)}
        .tab-btn .badge{display:inline-block;background:var(--border);color:var(--text-muted);padding:1px 8px;border-radius:99px;font-size:11px;margin-left:6px}
        .tab-btn.active .badge{background:var(--primary-light);color:var(--primary)}
        .action-group{display:flex;gap:6px}
        .btn-approve{background:rgba(5,150,105,0.1);color:var(--success);border:1px solid rgba(5,150,105,0.25);padding:6px 14px;border-radius:8px;font-weight:600;font-size:12px;cursor:pointer;transition:all .15s}
        .btn-approve:hover{background:var(--success);color:#fff}
        .btn-reject{background:rgba(220,38,38,0.1);color:var(--danger);border:1px solid rgba(220,38,38,0.25);padding:6px 14px;border-radius:8px;font-weight:600;font-size:12px;cursor:pointer;transition:all .15s}
        .btn-reject:hover{background:var(--danger);color:#fff}
    </style>
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header">
    <div>
        <h2>Bank Withdrawals</h2>
        <p>Review and approve/reject pending withdrawal requests</p>
    </div>
</div>

<div class="kpi-grid">
    <div class="data-panel kpi-card">
        <div class="kpi-icon yellow"><i class="fas fa-clock"></i></div>
        <div class="kpi-info">
            <div class="kpi-label">Pending</div>
            <div class="kpi-value"><?=$pending_count?></div>
        </div>
    </div>
    <div class="data-panel kpi-card">
        <div class="kpi-icon green"><i class="fas fa-check-circle"></i></div>
        <div class="kpi-info">
            <div class="kpi-label">Approved</div>
            <div class="kpi-value"><?=$success_count?></div>
        </div>
    </div>
    <div class="data-panel kpi-card">
        <div class="kpi-icon red"><i class="fas fa-times-circle"></i></div>
        <div class="kpi-info">
            <div class="kpi-label">Rejected</div>
            <div class="kpi-value"><?=$failed_count?></div>
        </div>
    </div>
    <div class="data-panel kpi-card">
        <div class="kpi-icon blue"><i class="fas fa-money-bill-transfer"></i></div>
        <div class="kpi-info">
            <div class="kpi-label">Volume (Approved)</div>
            <div class="kpi-value">₦<?=number_format($total_volume,2)?></div>
        </div>
    </div>
</div>

<div class="tab-bar">
    <a href="?tab=pending" class="tab-btn <?=$tab==='pending'?'active':''?>">Pending <?php if($pending_count):?><span class="badge"><?=$pending_count?></span><?php endif;?></a>
    <a href="?tab=approved" class="tab-btn <?=$tab==='approved'?'active':''?>">Approved</a>
    <a href="?tab=rejected" class="tab-btn <?=$tab==='rejected'?'active':''?>">Rejected</a>
    <a href="?tab=all" class="tab-btn <?=$tab==='all'?'active':''?>">All</a>
</div>

<div class="data-panel">
    <div class="responsive-grid-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Ref</th>
                    <th>User</th>
                    <th>Recipient</th>
                    <th>Amount</th>
                    <th>Charge</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($txs as $tx): 
                    $scls = ['success'=>'badge-success','pending'=>'badge-pending','failed'=>'badge-danger'][$tx['status']] ?? 'badge-pending';
                    $meta = json_decode($tx['meta'] ?? '{}', true);
                    $dest_bank = $meta['bank_code'] ?? 'Unknown';
                    $dest_acct = $meta['account_number'] ?? 'Unknown';
                    $payee_name = $meta['payee_name'] ?? explode('(', $tx['description'])[1] ?? '';
                    $payee_name = trim($payee_name, ') ');
                ?>
                <tr>
                    <td><code style="background:#f1f5f9;padding:4px 8px;border-radius:4px;color:#334155;font-size:12px;"><?=htmlspecialchars($tx['transaction_ref'])?></code></td>
                    <td>
                        <div style="font-weight:600;color:var(--navy-dark)"><?=htmlspecialchars($tx['full_name'])?></div>
                        <div style="font-size:12px;color:var(--text-muted)"><?=htmlspecialchars($tx['email'])?></div>
                        <div style="font-size:11px;color:var(--text-muted)">₦<?=number_format((float)($tx['wallet_balance'] ?? 0),2)?></div>
                    </td>
                    <td>
                        <div style="font-weight:600;color:#334155;font-size:13px;"><?=htmlspecialchars($payee_name ?: 'Unknown')?></div>
                        <div style="font-size:12px;color:var(--text-muted)"><?=htmlspecialchars($dest_bank)?> - <?=htmlspecialchars($dest_acct)?></div>
                    </td>
                    <td style="font-weight:700;color:var(--navy-dark)">₦<?=number_format((float)$tx['amount'],2)?></td>
                    <td style="color:var(--danger)">₦<?=number_format((float)($meta['charge'] ?? $tx['charge']),2)?></td>
                    <td><span class="badge <?=$scls?>"><?=ucfirst($tx['status'])?></span></td>
                    <td style="font-size:12px;color:#6B7280"><?=date('d M, Y H:i', strtotime($tx['created_at']))?></td>
                    <td>
                        <?php if($tx['status'] === 'pending'): ?>
                        <div class="action-group">
                            <button onclick="processWithdrawal('<?=htmlspecialchars($tx['transaction_ref'])?>','approve')" class="btn-approve"><i class="fas fa-check mr-1"></i>Approve</button>
                            <button onclick="processWithdrawal('<?=htmlspecialchars($tx['transaction_ref'])?>','reject')" class="btn-reject"><i class="fas fa-times mr-1"></i>Reject</button>
                        </div>
                        <?php else: ?>
                        <a href="<?= ADMIN_URL ?>/transaction-details.php?ref=<?=urlencode($tx['transaction_ref'])?>" class="btn-primary" style="padding:4px 8px;font-size:12px;border-radius:6px;background:var(--navy-light);text-decoration:none;">Details</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; if(empty($txs)): ?>
                <tr><td colspan="8" class="empty-state">No withdrawals found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div></div>
<div class="save-toast" id="toast"></div>

<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),3000);}

async function processWithdrawal(ref, action) {
    if(action === 'reject') {
        if(!confirm('Reject this withdrawal? The wallet will be refunded.')) return;
    } else {
        if(!confirm('Approve and execute this withdrawal? Money will be sent to the recipient.')) return;
    }
    
    const fd = new FormData();
    fd.append('csrf', '<?=$csrf?>');
    fd.append('ref', ref);
    fd.append('action', action);
    
    try {
        const r = await fetch(location.href, {
            method: 'POST',
            headers: {'X-Requested-With': 'XMLHttpRequest'},
            body: fd
        }).then(r => r.json());
        
        showToast(r.message, r.success);
        if(r.success) setTimeout(() => location.reload(), 1500);
    } catch(e) {
        showToast('Network error', false);
    }
}
</script>
</body>
</html>
