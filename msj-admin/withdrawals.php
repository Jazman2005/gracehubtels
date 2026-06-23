<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';

auth_admin();
$csrf = admin_csrf();

// Query all transactions where category is 'bank_transfer'
$txs = db()->query("
    SELECT t.*, u.full_name, u.email, u.phone 
    FROM transactions t 
    JOIN users u ON t.user_id = u.id 
    WHERE t.category = 'bank_transfer' 
    ORDER BY t.created_at DESC
")->fetchAll();

$total_volume = 0;
$total_count = count($txs);
$success_count = 0;
foreach($txs as $t) {
    if ($t['status'] === 'success') {
        $total_volume += (float)$t['amount'];
        $success_count++;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Bank Withdrawals – MsjPay Admin</title>
    <link rel="icon" type="image/png" href="/msjpay/assets/images/msjpay-favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/msjpay/msj-admin/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="page-content">

<div class="page-head">
    <div>
        <h2>Bank Withdrawals</h2>
        <p>Monitor all outward bank transfers from user wallets</p>
    </div>
</div>

<div class="row-4col" style="margin-bottom:24px;">
    <div class="card kpi-card" style="border-bottom-color:var(--primary)">
        <div class="kpi-icon" style="background:rgba(255,90,31,0.1);color:var(--primary)"><i class="fas fa-money-bill-transfer"></i></div>
        <div class="kpi-title">Total Volume (Success)</div>
        <div class="kpi-value">₦<?=number_format($total_volume, 2)?></div>
    </div>
    <div class="card kpi-card" style="border-bottom-color:#10B981">
        <div class="kpi-icon" style="background:#D1FAE5;color:#10B981"><i class="fas fa-check-circle"></i></div>
        <div class="kpi-title">Successful Transfers</div>
        <div class="kpi-value"><?=number_format($success_count)?></div>
    </div>
    <div class="card kpi-card" style="border-bottom-color:#64748B">
        <div class="kpi-icon" style="background:#F1F5F9;color:#64748B"><i class="fas fa-list"></i></div>
        <div class="kpi-title">Total Requests</div>
        <div class="kpi-value"><?=number_format($total_count)?></div>
    </div>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Ref</th>
                    <th>User</th>
                    <th>Recipient Details</th>
                    <th>Amount</th>
                    <th>Charge</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($txs as $tx): 
                    $scls = ['success'=>'badge-success','pending'=>'badge-pending','failed'=>'badge-danger','reversed'=>'badge-warning'][$tx['status']] ?? 'badge-pending';
                    $meta = json_decode($tx['meta'] ?? '{}', true);
                    $dest_bank = $meta['bank_code'] ?? 'Unknown Bank';
                    $dest_acct = $meta['account_number'] ?? 'Unknown Acct';
                    $dest_charge = $meta['charge'] ?? $tx['charge'];
                ?>
                <tr>
                    <td><code style="background:#f1f5f9;padding:4px 8px;border-radius:4px;color:#334155;font-size:12px;"><?=htmlspecialchars($tx['transaction_ref'])?></code></td>
                    <td>
                        <div style="font-weight:600;color:var(--navy-dark)"><?=htmlspecialchars($tx['full_name'])?></div>
                        <div style="font-size:12px;color:var(--text-muted)"><?=htmlspecialchars($tx['email'])?></div>
                    </td>
                    <td>
                        <div style="font-weight:600;color:#334155;font-size:13px;"><?=htmlspecialchars(explode('(', $tx['description'])[0] ?? '')?></div>
                        <div style="font-size:12px;color:var(--text-muted)"><?=htmlspecialchars($dest_bank)?> - <?=htmlspecialchars($dest_acct)?></div>
                    </td>
                    <td style="font-weight:700;color:var(--navy-dark)">₦<?=number_format((float)$tx['amount'], 2)?></td>
                    <td style="color:#EF4444">₦<?=number_format((float)$dest_charge, 2)?></td>
                    <td><span class="badge <?=$scls?>"><?=ucfirst($tx['status'])?></span></td>
                    <td style="font-size:12px;color:#6B7280"><?=date('d M, Y H:i', strtotime($tx['created_at']))?></td>
                    <td>
                        <a href="/msjpay/msj-admin/transaction-details.php?ref=<?=urlencode($tx['transaction_ref'])?>" class="btn-primary" style="padding:4px 8px;font-size:12px;border-radius:6px;background:var(--navy-light);text-decoration:none;">Details</a>
                    </td>
                </tr>
                <?php endforeach; if(empty($txs)): ?>
                <tr><td colspan="8" class="empty-state">No bank withdrawals found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div></div>
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
</script>
</body>
</html>
