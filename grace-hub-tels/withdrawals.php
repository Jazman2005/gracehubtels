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

    $tx = db()->prepare("SELECT t.*, u.full_name, u.email, u.phone, u.wallet_balance FROM transactions t JOIN users u ON t.user_id=u.id WHERE t.transaction_ref=? AND t.category='bank_transfer' AND t.status='pending' LIMIT 1");
    $tx->execute([$ref]);
    $tx = $tx->fetch();
    if (!$tx) json_response(['success'=>false,'message'=>'Transaction not found or already processed']);

    $meta = json_decode($tx['meta'] ?? '{}', true);
    $amount = (float)$tx['amount'];
    $charge = (float)$meta['charge'] ?? $tx['charge'];
    $total = $amount + $charge;

    if ($action === 'approve') {
        // Execute the PalmPay transfer
        $api = new PalmPayAPI();
        if (!$api->is_configured()) json_response(['success'=>false,'message'=>'PalmPay not configured']);
        $notify = 'https://www.gracehubtels.com/webhook/palmpay/index.php';
        $res = $api->transfer($ref, $meta['payee_name'] ?? '', $meta['bank_code'], $meta['account_number'], $tx['phone'], $amount, $notify, $meta['narration'] ?? '');
        if ($res && ($res['respCode']??'') === '00000000') {
            update_tx_status($ref, 'success', $res['data']['orderNo'] ?? null);
            $cb = apply_cashback($tx['user_id'], $amount, 'bank_transfer', null);
            notify_user($tx['user_id'], 'Transfer Successful', number_format($amount,2) . " sent to " . ($meta['bank_code']??'') . " - " . ($meta['account_number']??'') . ".", 'success');
            send_whatsapp_alert("✅ *Withdrawal Approved & Sent!*\nUser: " . ($tx['full_name'] ?? 'Unknown') . " (" . ($tx['email'] ?? $tx['phone'] ?? '') . ")\nAmount: ₦" . number_format($amount, 2) . "\nRecipient: " . ($meta['payee_name'] ?? 'N/A') . " - " . ($meta['bank_code'] ?? '') . " " . ($meta['account_number'] ?? '') . "\nRef: {$ref}");
            try{notify_admin('Withdrawal Approved',"
<div style='text-align:center;padding:10px 0 16px;'>
<span style='font-size:28px;line-height:1;font-weight:800;color:#16a34a;'>OK</span>
<h3 style='color:#4F46E5;font-size:18px;margin:8px 0 4px;'>Withdrawal Approved &amp; Sent</h3>
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
            json_response(['success'=>true,'message'=>'Transfer approved and executed successfully']);
        }
        // API call failed — keep pending, don't auto-revert
        $err = $res['respMsg'] ?? 'API error';
        log_error('bank_transfer', 'Approval transfer failed', ['ref'=>$ref,'response'=>$res]);
        json_response(['success'=>false,'message'=>'Transfer failed: ' . $err]);
    } elseif ($action === 'reject') {
        update_tx_status($ref, 'failed');
        $rej_res = credit_wallet($tx['user_id'], $total, 'bank_transfer', 'Reversal: withdrawal rejected', ['rejected_ref'=>$ref]);
        notify_user($tx['user_id'], 'Withdrawal Rejected', "Your withdrawal of " . number_format($amount,2) . " has been rejected. " . number_format($total,2) . " has been refunded to your wallet", 'error', null, $rej_res['balance_before'], $rej_res['balance_after']);
        send_whatsapp_alert("❌ *Withdrawal Rejected*\nUser: " . ($tx['full_name'] ?? 'Unknown') . " (" . ($tx['email'] ?? $tx['phone'] ?? '') . ")\nAmount: ₦" . number_format($amount, 2) . "\nRef: {$ref}");
        try{notify_admin('Withdrawal Rejected',"
<div style='text-align:center;padding:10px 0 16px;'>
<span style='font-size:28px;line-height:1;font-weight:800;color:#dc2626;'>X</span>
<h3 style='color:#4F46E5;font-size:18px;margin:8px 0 4px;'>Withdrawal Rejected</h3>
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
        .tab-bar{display:flex;gap:4px;background:#F1F5F9;padding:4px;border-radius:10px;margin-bottom:20px;flex-wrap:wrap}
        .tab-btn{padding:8px 18px;border-radius:8px;font-size:13px;font-weight:600;border:none;cursor:pointer;background:transparent;color:#64748B;transition:all .15s}
        .tab-btn.active{background:#fff;color:var(--navy-dark);box-shadow:0 1px 3px rgba(0,0,0,.08)}
        .tab-btn .badge{display:inline-block;background:#E2E8F0;color:#475569;padding:1px 8px;border-radius:99px;font-size:11px;margin-left:6px}
        .tab-btn.active .badge{background:var(--primary-light);color:var(--primary)}
        .action-group{display:flex;gap:6px}
        .btn-approve{background:#D1FAE5;color:#065F46;border:1px solid #A7F3D0;padding:6px 14px;border-radius:8px;font-weight:600;font-size:12px;cursor:pointer;transition:all .15s}
        .btn-approve:hover{background:#065F46;color:#fff}
        .btn-reject{background:#FEE2E2;color:#991B1B;border:1px solid #FECACA;padding:6px 14px;border-radius:8px;font-weight:600;font-size:12px;cursor:pointer;transition:all .15s}
        .btn-reject:hover{background:#991B1B;color:#fff}
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
