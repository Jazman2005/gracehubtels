<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_admin();$csrf=admin_csrf();$stats=admin_stats();$admin=get_admin();
$is_staff = ($_SESSION['admin_role'] ?? '') === 'support';
$hide_sensitive = $is_staff && setting('hide_sensitive_from_staff') === '1';
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Dashboard — Gracehubtels Admin</title>
  <link rel="icon" type="image/png" href="/assets/images/gracehubtels-favicon.png">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css">
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header"><div><h2>Dashboard</h2><p><?=date('l, j F Y')?></p></div></div>

<!-- Financial Overview -->
<div class="kpi-grid">
  <div class="kpi-card">
    <div class="kpi-icon indigo"><i class="fas fa-users"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">User Wallet</div>
      <div class="kpi-value">₦<?=number_format($stats['user_wallet'],0)?></div>
    </div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon green"><i class="fas fa-user-tie"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">Agent Wallet</div>
      <div class="kpi-value">₦<?=number_format($stats['agent_wallet'],0)?></div>
    </div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon blue"><i class="fas fa-store"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">Vendors Wallet</div>
      <div class="kpi-value">₦<?=number_format($stats['vendor_wallet'],0)?></div>
    </div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon purple"><i class="fas fa-gift"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">Referrals Wallet</div>
      <div class="kpi-value">₦<?=number_format($stats['referral_total'],0)?></div>
    </div>
  </div>
</div>

<!-- Platform Stats -->
<div class="kpi-grid">
  <div class="kpi-card">
    <div class="kpi-icon teal"><i class="fas fa-users"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">Subscribers</div>
      <div class="kpi-value"><?=number_format($stats['subscriber_count'])?></div>
      <div class="kpi-sub">+<?=$stats['new_today']?> today</div>
    </div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon slate"><i class="fas fa-user-tie"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">Agents</div>
      <div class="kpi-value"><?=number_format($stats['agent_count'])?></div>
    </div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon amber"><i class="fas fa-store-alt"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">Vendors</div>
      <div class="kpi-value"><?=number_format($stats['vendor_count'])?></div>
    </div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon pink"><i class="fas fa-share-alt"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">Referrals</div>
      <div class="kpi-value"><?=number_format($stats['referral_count'])?></div>
    </div>
  </div>
</div>

<!-- Performance -->
<div class="kpi-grid">
  <div class="kpi-card">
    <div class="kpi-icon indigo"><i class="fas fa-exchange-alt"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">Transactions</div>
      <div class="kpi-value"><?=number_format($stats['total_tx'])?></div>
      <div class="kpi-sub"><?=$stats['success_tx']?> success · <?=$stats['pending_tx']?> pending · <?=$stats['failed_tx']?> failed</div>
    </div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon green"><i class="fas fa-chart-line"></i></div>
    <div class="kpi-info">
      <div class="kpi-label"><?=$hide_sensitive?'Today Revenue':'Today Profit'?></div>
      <div class="kpi-value">₦<?=number_format($stats['today_profit'],0)?></div>
      <?php if(!$hide_sensitive):?><div class="kpi-sub">₦<?=number_format($stats['total_profit'],0)?> total</div><?php endif;?>
    </div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon red"><i class="fas fa-envelope"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">Unread Messages</div>
      <div class="kpi-value"><?=$stats['unread_tickets']?></div>
      <div class="kpi-sub"><a href="<?= ADMIN_URL ?>/tickets.php" style="color:var(--primary);font-weight:600">View tickets →</a></div>
    </div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon blue"><i class="fas fa-eye"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">Today's Visits</div>
      <div class="kpi-value"><?=$stats['today_visits']?></div>
      <div class="kpi-sub">Unique visitors</div>
    </div>
  </div>
</div>

<!-- Charts Row -->
<div class="dash-row-2col">
  <div class="data-panel">
    <div class="panel-title-bar"><h3><?=$hide_sensitive?'7-Day Volume':'7-Day Volume & Profit'?></h3></div>
    <div class="chart-panel"><canvas id="volChart"></canvas></div>
  </div>
  <div class="data-panel">
    <div class="panel-title-bar"><h3>Today by Service</h3></div>
    <div class="cat-list" style="padding:8px">
      <?php $cat_icons=['wallet_funding'=>'W','airtime'=>'A','data'=>'D','electricity'=>'E','cabletv'=>'C','airtime_to_cash'=>'X','exam_pin'=>'P','recharge_card'=>'R','data_card'=>'K','bank_transfer'=>'B','wallet_transfer'=>'T','cashback'=>'G','admin_credit'=>'+','admin_debit'=>'-'];
      foreach($stats['categories'] as $c):$ic=$cat_icons[$c['category']]??'?';?>
      <div class="cat-item"><span class="cat-icon"><?=$ic?></span><div class="cat-info"><span class="cat-name"><?=ucwords(str_replace('_',' ',$c['category']))?></span><span class="cat-cnt"><?=$c['cnt']?> txns<?=$hide_sensitive?'':', ₦'.number_format((float)$c['profit'],0).' profit'?></span></div><span class="cat-vol">₦<?=number_format((float)$c['vol'],0)?></span></div>
      <?php endforeach;if(empty($stats['categories'])):?><div class="empty-state">No transactions today</div><?php endif;?>
    </div>
  </div>
</div>

<!-- Recent Transactions -->
<div class="data-panel">
  <div class="panel-title-bar"><h3>Recent Transactions</h3><a href="<?= ADMIN_URL ?>/transactions-management.php" class="card-link">View All</a></div>
  <div class="responsive-grid-wrapper">
    <table class="data-table">
      <thead><tr><th>Ref</th><th>User</th><th>Type</th><th>Amount</th><?=$hide_sensitive?'':'<th>Profit</th>'?><th>Category</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach($stats['recent_tx'] as $tx):$scls=['success'=>'badge-success','pending'=>'badge-pending','failed'=>'badge-danger','reversed'=>'badge-warning'][$tx['status']]??'badge-pending';?>
      <tr>
        <td><code><?=substr($tx['transaction_ref'],0,12)?>...</code></td>
        <td><div class="user-cell"><div class="u-avatar"><?=strtoupper(substr($tx['full_name']??'?',0,1))?></div><div><span class="u-name"><?=htmlspecialchars($tx['full_name']??'—')?></span><span class="u-phone"><?=htmlspecialchars($tx['phone']??'')?></span></div></div></td>
        <td><span class="badge <?=$tx['type']==='credit'?'badge-success':'badge-neutral'?>"><?=strtoupper($tx['type'])?></span></td>
        <td class="<?=$tx['type']==='credit'?'text-success':'text-danger'?>"><?=$tx['type']==='credit'?'+':'-'?>₦<?=number_format((float)$tx['amount'],2)?></td>
        <?php if(!$hide_sensitive):?>
        <td><?=$tx['profit']!==null?'<span class="text-success">₦'.number_format((float)$tx['profit'],2).'</span>':'<span style="color:#9CA3AF">—</span>'?></td>
        <?php endif;?>
        <td style="font-size:12px"><?=ucwords(str_replace('_',' ',$tx['category']))?></td>
        <td><span class="badge <?=$scls?>"><?=ucfirst($tx['status'])?></span></td>
        <td style="font-size:12px;color:#6B7280"><?=date('d M, H:i',strtotime($tx['created_at']))?></td>
      </tr>
      <?php endforeach;?>
      </tbody>
    </table>
  </div>
</div>

</div></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');document.getElementById('sidebarOverlay').classList.toggle('show');}
const chartData=<?=json_encode($stats['chart'])?>;
const labels=chartData.map(d=>d.d);const vols=chartData.map(d=>parseFloat(d.vol));const profits=chartData.map(d=>parseFloat(d.profit));
const hideProfit=<?=$hide_sensitive?'true':'false'?>;
new Chart(document.getElementById('volChart'),{type:'bar',data:{labels,datasets:[{label:'Volume',data:vols,backgroundColor:'rgba(79,70,229,0.2)',borderColor:'#4F46E5',borderWidth:2},...(hideProfit?[]:[{label:'Profit',data:profits,backgroundColor:'rgba(5,150,105,0.4)',borderColor:'#059669',borderWidth:2}])]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'top',labels:{font:{family:'Sora',size:11}}}},scales:{x:{grid:{display:false},ticks:{font:{family:'Sora',size:10}}},y:{grid:{color:'#F3F4F6'},ticks:{font:{family:'Sora',size:10},callback:v=>'₦'+v.toLocaleString()}}}}});
</script>
</body></html>
