<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_admin();$csrf=admin_csrf();$stats=admin_stats();$admin=get_admin();
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Dashboard – MsjPay Admin</title><link rel="icon" type="image/png" href="/msjpay/assets/images/msjpay-favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800;900&display=swap" rel="stylesheet"><link rel="stylesheet" href="/msjpay/msj-admin/css/admin.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="page-content">

<div class="kpi-grid">
  <div class="kpi-card"><div class="kpi-icon blue"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg></div><div class="kpi-info"><span class="kpi-label">Total Users</span><span class="kpi-value"><?=number_format($stats['users'])?></span><span class="kpi-sub">+<?=$stats['new_today']?> today<?=$stats['kyc_pending']>0?' · '.$stats['kyc_pending'].' KYC pending':''?></span></div></div>
  <div class="kpi-card"><div class="kpi-icon green"><svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div><div class="kpi-info"><span class="kpi-label">Total Volume</span><span class="kpi-value">₦<?=number_format($stats['total_vol'],0)?></span><span class="kpi-sub">₦<?=number_format($stats['today_vol'],0)?> today</span></div></div>
  <div class="kpi-card"><div class="kpi-icon orange"><svg viewBox="0 0 24 24"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg></div><div class="kpi-info"><span class="kpi-label">Total Profit</span><span class="kpi-value">₦<?=number_format($stats['total_profit'],0)?></span><span class="kpi-sub">₦<?=number_format($stats['today_profit'],0)?> today</span></div></div>
  <div class="kpi-card"><div class="kpi-icon purple"><svg viewBox="0 0 24 24"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg></div><div class="kpi-info"><span class="kpi-label">Transactions</span><span class="kpi-value"><?=number_format($stats['total_tx'])?></span><span class="kpi-sub"><?=$stats['success_tx']?> success · <?=$stats['failed_tx']?> failed</span></div></div>
  <div class="kpi-card"><div class="kpi-icon teal"><svg viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg></div><div class="kpi-info"><span class="kpi-label">Wallet Holdings</span><span class="kpi-value">₦<?=number_format($stats['wallet_sum'],0)?></span><span class="kpi-sub">₦<?=number_format($stats['cashback_sum'],0)?> cashback</span></div></div>
  <div class="kpi-card <?= $stats['pending_a2c'] > 0 ? 'highlight' : '' ?>"><div class="kpi-icon <?= $stats['pending_a2c'] > 0 ? 'yellow' : 'gray' ?>"><svg viewBox="0 0 24 24"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg></div><div class="kpi-info"><span class="kpi-label">Pending A2C</span><span class="kpi-value"><?= $stats['pending_a2c'] ?></span><span class="kpi-sub"><a href="/msjpay/msj-admin/a2c-management.php">Review now →</a></span></div></div>
  <div class="kpi-card"><div class="kpi-icon red"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div><div class="kpi-info"><span class="kpi-label">KYC Pending</span><span class="kpi-value"><?=$stats['kyc_pending']?></span><span class="kpi-sub"><a href="/msjpay/msj-admin/kyc-management.php">Review →</a></span></div></div>
  <div class="kpi-card"><div class="kpi-icon pink"><svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg></div><div class="kpi-info"><span class="kpi-label">Failed Tx (All)</span><span class="kpi-value"><?=number_format($stats['failed_tx'])?></span><span class="kpi-sub">Need attention</span></div></div>
</div>

<div class="card">
  <div class="card-header">
    <h3><svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" fill="none" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg> Quick Actions</h3>
  </div>
  <div>
    <div class="action-grid">
      <a href="/msjpay/msj-admin/users-management.php" class="action-tile blue"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>Manage Users</a>
      <a href="/msjpay/msj-admin/dashboard-settings.php" class="action-tile orange"><svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>App Settings</a>
      <a href="/msjpay/msj-admin/notifications.php" class="action-tile green"><svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>Broadcast</a>
      <a href="/msjpay/msj-admin/settings.php" class="action-tile gray"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/></svg>API Config</a>
    </div>
  </div>
</div>

<div class="row-2col">
  <div class="card">
    <div class="card-header"><h3>7-Day Volume & Profit</h3></div>
    <div class="chart-wrap"><canvas id="volChart"></canvas></div>
  </div>
  <div class="card">
    <div class="card-header"><h3>Today by Service</h3></div>
    <div class="cat-list">
      <?php $cat_icons=['wallet_funding'=>'💰','airtime'=>'📱','data'=>'🌐','electricity'=>'⚡','cabletv'=>'📺','airtime_to_cash'=>'💸','exam_pin'=>'🎓','recharge_card'=>'🃏','data_card'=>'🎟️','bank_transfer'=>'🏦','cashback'=>'🎁','admin_credit'=>'🟢','admin_debit'=>'🔴'];
      foreach($stats['categories'] as $c):$ic=$cat_icons[$c['category']]??'💳';?>
      <div class="cat-item"><span class="cat-icon"><?=$ic?></span><div class="cat-info"><span class="cat-name"><?=ucwords(str_replace('_',' ',$c['category']))?></span><span class="cat-cnt"><?=$c['cnt']?> txns · ₦<?=number_format((float)$c['profit'],0)?> profit</span></div><span class="cat-vol">₦<?=number_format((float)$c['vol'],0)?></span></div>
      <?php endforeach;if(empty($stats['categories'])):?><div class="empty-state">No transactions today</div><?php endif;?>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3>Recent Transactions</h3><a href="/msjpay/msj-admin/transactions-management.php" class="card-link">View All</a></div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Ref</th><th>User</th><th>Type</th><th>Amount</th><th>Profit</th><th>Category</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach($stats['recent_tx'] as $tx):$scls=['success'=>'badge-success','pending'=>'badge-pending','failed'=>'badge-danger','reversed'=>'badge-warning'][$tx['status']]??'badge-pending';?>
      <tr>
        <td><code><?=substr($tx['transaction_ref'],0,12)?>…</code></td>
        <td><div class="user-cell"><div class="u-avatar"><?=strtoupper(substr($tx['full_name']??'?',0,1))?></div><div><span class="u-name"><?=htmlspecialchars($tx['full_name']??'—')?></span><span class="u-phone"><?=htmlspecialchars($tx['phone']??'')?></span></div></div></td>
        <td><span class="badge <?= $tx['type'] === 'credit' ? 'badge-success' : 'badge-neutral' ?>"><?= strtoupper($tx['type']) ?></span></td>
        <td class="<?=$tx['type']==='credit'?'text-success':'text-danger'?>"><?=$tx['type']==='credit'?'+':'-'?>₦<?=number_format((float)$tx['amount'],2)?></td>
        <td><?=$tx['profit']!==null?'<span class="text-success">₦'.number_format((float)$tx['profit'],2).'</span>':'<span style="color:#9CA3AF">—</span>'?></td>
        <td style="font-size:12px"><?=ucwords(str_replace('_',' ',$tx['category']))?></td>
        <td><span class="badge <?=$scls?>"><?=ucfirst($tx['status'])?></span></td>
        <td style="font-size:12px;color:#6B7280"><?=date('d M, H:i',strtotime($tx['created_at']))?></td>
      </tr>
      <?php endforeach;?>
      </tbody>
    </table>
  </div>
</div>

</div><!-- /page-content -->
</div><!-- /main-wrap -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');document.getElementById('sidebarOverlay').classList.toggle('show');}
const chartData=<?=json_encode($stats['chart'])?>;
const labels=chartData.map(d=>d.d);const vols=chartData.map(d=>parseFloat(d.vol));const profits=chartData.map(d=>parseFloat(d.profit));
new Chart(document.getElementById('volChart'),{type:'bar',data:{labels,datasets:[{label:'Volume',data:vols,backgroundColor:'rgba(249,115,22,0.3)',borderColor:'#F97316',borderWidth:2},{label:'Profit',data:profits,backgroundColor:'rgba(34,197,94,0.5)',borderColor:'#22C55E',borderWidth:2}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'top',labels:{font:{family:'Sora',size:11}}}},scales:{x:{grid:{display:false},ticks:{font:{family:'Sora',size:10}}},y:{grid:{color:'#F3F4F6'},ticks:{font:{family:'Sora',size:10},callback:v=>'₦'+v.toLocaleString()}}}}});
</script>
</body></html>
