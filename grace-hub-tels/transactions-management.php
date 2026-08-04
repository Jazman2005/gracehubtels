<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_admin();
$csrf=admin_csrf();
$is_staff = ($_SESSION['admin_role'] ?? '') === 'support';
$hide_sensitive = $is_staff && setting('hide_sensitive_from_staff') === '1';

$tab = preg_replace('/[^a-z_]/', '', strtolower($_GET['tab'] ?? 'all'));

// Summary stats for KPI row
$total_tx = db()->query("SELECT COUNT(*) FROM transactions")->fetchColumn();
$total_volume = db()->query("SELECT SUM(amount) FROM transactions WHERE status='success'")->fetchColumn() ?: 0;
$pending_count = db()->query("SELECT COUNT(*) FROM transactions WHERE status='pending'")->fetchColumn();
$failed_count = db()->query("SELECT COUNT(*) FROM transactions WHERE status='failed'")->fetchColumn();
$today_count = db()->query("SELECT COUNT(*) FROM transactions WHERE DATE(created_at)=CURDATE()")->fetchColumn();

if(isset($_GET['export']) && $_GET['export']==='csv'){
    $filters=['status'=>$_GET['status']??'','category'=>$_GET['category']??''];
    $data=get_transactions_list(1, 999999, $filters);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=transactions_export_'.date('YmdHis').'.csv');
    $out = fopen('php://output', 'w');
    $csv_header = ['Reference', 'User Name', 'Type', 'Category', 'Amount', 'Charge'];
    if (!$hide_sensitive) { $csv_header[] = 'Cost Price'; $csv_header[] = 'Profit'; }
    $csv_header = array_merge($csv_header, ['Network', 'Provider', 'Status', 'Date']);
    fputcsv($out, $csv_header);
    foreach($data['data'] as $tx) {
        $row = [$tx['transaction_ref'], $tx['full_name'], $tx['type'], $tx['category'], $tx['amount'], $tx['charge']];
        if (!$hide_sensitive) { $row[] = $tx['cost_price']; $row[] = $tx['profit']; }
        $row = array_merge($row, [$tx['network'], $tx['provider'], $tx['status'], $tx['created_at']]);
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

$cats=['airtime'=>'[A]','data'=>'[D]','electricity'=>'[E]','cabletv'=>'[C]','wallet_funding'=>'[F]','bank_transfer'=>'[B]','wallet_transfer'=>'[W]','airtime_to_cash'=>'[X]','exam_pin'=>'[P]','recharge_card'=>'[R]','data_card'=>'[K]','cashback'=>'[G]'];

if(in_array($tab, ['all','success','pending','failed'])){
    $page=max(1,(int)($_GET['page']??1));
    $status_filter = $tab==='all' ? ($_GET['status']??'') : $tab;
    $filters=[
        'status'=>$status_filter,
        'category'=>$_GET['category']??'',
        'search'=>$_GET['search']??'',
        'date_from'=>$_GET['date_from']??'',
        'date_to'=>$_GET['date_to']??''
    ];
    $data=get_transactions_list($page,25,$filters);
}

if($tab === 'sales'){
    $today_sales = db()->query("SELECT SUM(amount) FROM transactions WHERE DATE(created_at) = CURDATE() AND status='success' AND type='debit'")->fetchColumn() ?: 0;
    $today_profit = db()->query("SELECT SUM(profit) FROM transactions WHERE DATE(created_at) = CURDATE() AND status='success' AND type='debit'")->fetchColumn() ?: 0;
    $month_sales = db()->query("SELECT SUM(amount) FROM transactions WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status='success' AND type='debit'")->fetchColumn() ?: 0;
    $month_profit = db()->query("SELECT SUM(profit) FROM transactions WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status='success' AND type='debit'")->fetchColumn() ?: 0;
    $cat_breakdown = db()->query("SELECT category, COUNT(*) as count, SUM(amount) as vol, SUM(profit) as prof FROM transactions WHERE status='success' AND type='debit' GROUP BY category ORDER BY vol DESC")->fetchAll();
}

if($tab === 'users'){
    $top_users = db()->query("SELECT u.id, u.full_name, u.email, u.phone, COUNT(t.id) as tx_count, SUM(t.amount) as tx_vol FROM users u JOIN transactions t ON u.id = t.user_id WHERE t.status = 'success' GROUP BY u.id ORDER BY tx_vol DESC LIMIT 50")->fetchAll();
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Transactions — Gracehubtels Admin</title>
  <link rel="icon" type="image/png" href="/assets/images/gracehubtels-favicon.png">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header">
  <div>
    <h2>Transactions Manager</h2>
    <p>Monitor platform activity, analyze sales, and manage user financial records.</p>
  </div>
  <?php if(in_array($tab, ['all','success','pending','failed']) && isset($filters)): ?>
  <a href="?export=csv&status=<?=urlencode($filters['status'])?>&category=<?=urlencode($filters['category'])?>" class="btn-primary" style="background:var(--success)"><i class="fas fa-file-csv"></i> Export CSV</a>
  <?php endif; ?>
</div>

<!-- Summary KPI Row -->
<div class="kpi-grid">
  <div class="kpi-card">
    <div class="kpi-icon blue"><i class="fas fa-exchange-alt"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">Total Transactions</div>
      <div class="kpi-value"><?=number_format($total_tx)?></div>
    </div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon green"><i class="fas fa-coins"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">Total Volume</div>
      <div class="kpi-value">₦<?=number_format($total_volume,0)?></div>
    </div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon yellow"><i class="fas fa-clock"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">Pending</div>
      <div class="kpi-value"><?=number_format($pending_count)?></div>
    </div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon orange"><i class="fas fa-calendar-day"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">Today</div>
      <div class="kpi-value"><?=number_format($today_count)?></div>
    </div>
  </div>
</div>

<div class="page-tabs">
  <a href="?tab=all" class="page-tab <?=$tab==='all'?'active':''?>">All Transactions</a>
  <a href="?tab=pending" class="page-tab <?=$tab==='pending'?'active':''?>">Pending</a>
  <a href="?tab=success" class="page-tab <?=$tab==='success'?'active':''?>">Successful</a>
  <a href="?tab=failed" class="page-tab <?=$tab==='failed'?'active':''?>">Failed</a>
  <a href="?tab=sales" class="page-tab <?=$tab==='sales'?'active':''?>">Sales Analysis</a>
  <a href="?tab=users" class="page-tab <?=$tab==='users'?'active':''?>">Users Analysis</a>
</div>

<?php if(in_array($tab, ['all','success','pending','failed'])): ?>
<div class="data-panel">
  <form class="search-bar" method="GET">
    <input type="hidden" name="tab" value="<?=esc($tab)?>">
    <select name="category" class="form-control" style="width:auto;min-width:140px">
      <option value="">All Categories</option>
      <?php foreach($cats as $k=>$ic):?><option value="<?=$k?>" <?=($filters['category']??'')===$k?'selected':''?>><?=$ic?> <?=ucwords(str_replace('_',' ',$k))?></option><?php endforeach;?>
    </select>
    <input type="text" name="search" class="form-control" style="width:auto;min-width:200px" placeholder="Search ref, phone, user, description..." value="<?=htmlspecialchars($filters['search']??'')?>">
    <input type="date" name="date_from" class="form-control" style="width:auto" value="<?=htmlspecialchars($filters['date_from']??'')?>" title="From date">
    <input type="date" name="date_to" class="form-control" style="width:auto" value="<?=htmlspecialchars($filters['date_to']??'')?>" title="To date">
    <button type="submit" class="btn-primary"><i class="fas fa-search"></i> Filter</button>
    <a href="?tab=<?=esc($tab)?>" class="btn-ghost"><i class="fas fa-times"></i> Clear</a>
    <span class="mini-stat" style="margin-left:auto"><?=number_format($data['total'])?> records found</span>
  </form>

  <div class="responsive-grid-wrapper">
    <table class="data-table">
      <thead><tr><th>#</th><th>User Info</th><th>Ref Id</th><th>Description</th><?=$hide_sensitive?'':'<th>Profit</th>'?></tr></thead>
      <tbody>
      <?php $i=($page-1)*25+1; foreach($data['data'] as $tx):
        $scls=['success'=>'badge-success','pending'=>'badge-pending','failed'=>'badge-danger','reversed'=>'badge-warning'][$tx['status']]??'badge-pending';
        $sclr=['success'=>'var(--success)','pending'=>'var(--warning)','failed'=>'var(--danger)','reversed'=>'var(--warning)'][$tx['status']]??'var(--warning)';
      ?>
      <tr>
        <td class="text-muted" style="width:40px"><?=$i++?></td>
        <td>
          <div class="user-cell" style="margin-bottom:4px">
            <div class="u-avatar"><?=strtoupper(substr($tx['full_name']??($tx['email']??'?'),0,1))?></div>
            <div>
              <span class="u-name"><?=htmlspecialchars($tx['email']??$tx['full_name']??'—')?></span>
              <span class="u-phone"><?=htmlspecialchars($tx['phone']??'')?></span>
            </div>
          </div>
          <div style="font-size:12px;color:var(--text-muted);margin-top:4px">
            Bal: ₦<?=number_format((float)$tx['balance_before'],2)?> → ₦<?=number_format((float)$tx['balance_after'],2)?>
          </div>
        </td>
        <td>
          <div style="font-weight:600;font-size:13px;color:<?=$sclr?>;margin-bottom:4px"><?=ucfirst($tx['status'])?></div>
          <div style="font-size:12px;color:var(--text-muted);margin-bottom:2px"><?=htmlspecialchars($tx['transaction_ref'])?></div>
          <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px"><?=date('d M Y h:iA',strtotime($tx['created_at']))?></div>
          <a href="<?= ADMIN_URL ?>/transaction-details.php?ref=<?=urlencode($tx['transaction_ref'])?>" class="card-link" style="font-size:12px">Details</a>
        </td>
        <td>
          <div style="color:var(--primary);font-weight:600;font-size:13px;margin-bottom:4px">
            <?php if($tx['category']==='data_plan') echo 'Data'; elseif($tx['category']==='wallet_funding') echo 'Wallet Topup'; else echo ucwords(str_replace('_',' ',$tx['category'])); ?>
          </div>
          <div style="font-size:12px;color:var(--text-muted);line-height:1.6">
            <?=htmlspecialchars($tx['description'])?><br>
            <span style="color:#9CA3AF">Amount: ₦<?=number_format((float)$tx['amount'])?></span>
          </div>
        </td>
        <?php if(!$hide_sensitive):?>
        <td style="font-weight:600;color:var(--navy-dark)">₦<?=$tx['profit']!==null?number_format((float)$tx['profit']):'0'?></td>
        <?php endif;?>
      </tr>
      <?php endforeach;if(empty($data['data'])):?><tr><td colspan="<?=$hide_sensitive?4:5?>" class="empty-state">No transactions found</td></tr><?php endif;?>
      </tbody>
    </table>
  </div>
  <?php if($data['pages']>1):?>
  <div class="pagination">
    <span class="page-info">Page <?=$page?> of <?=$data['pages']?></span>
    <?php $qstr='tab='.urlencode($tab).'&category='.urlencode($filters['category']??'').'&search='.urlencode($filters['search']??'').'&date_from='.urlencode($filters['date_from']??'').'&date_to='.urlencode($filters['date_to']??'');?>
    <?php for($i=max(1,$page-2);$i<=min($data['pages'],$page+2);$i++):?><a href="?<?=$qstr?>&page=<?=$i?>" class="page-btn <?=$i===$page?'active':''?>"><?=$i?></a><?php endfor;?>
  </div>
  <?php endif;?>
</div>

<?php elseif($tab === 'sales'): ?>

<div class="kpi-grid">
  <div class="kpi-card">
    <div class="kpi-icon orange"><i class="fas fa-chart-bar"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">Today's Sales</div>
      <div class="kpi-value">₦<?=number_format($today_sales,0)?></div>
    </div>
  </div>
  <?php if(!$hide_sensitive):?>
  <div class="kpi-card">
    <div class="kpi-icon green"><i class="fas fa-chart-line"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">Today's Profit</div>
      <div class="kpi-value">₦<?=number_format($today_profit,0)?></div>
    </div>
  </div>
  <?php endif;?>
  <div class="kpi-card">
    <div class="kpi-icon blue"><i class="fas fa-calendar-alt"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">Monthly Sales</div>
      <div class="kpi-value">₦<?=number_format($month_sales,0)?></div>
    </div>
  </div>
  <?php if(!$hide_sensitive):?>
  <div class="kpi-card">
    <div class="kpi-icon teal"><i class="fas fa-wallet"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">Monthly Profit</div>
      <div class="kpi-value">₦<?=number_format($month_profit,0)?></div>
    </div>
  </div>
  <?php endif;?>
</div>

<div class="data-panel">
  <div class="panel-title-bar">
    <h3><i class="fas fa-chart-pie" style="color:var(--primary)"></i> Sales by Category (All Time)</h3>
  </div>
  <div class="responsive-grid-wrapper">
    <table class="data-table">
      <thead><tr><th>Category</th><th>Transactions</th><th>Volume (₦)</th><?=$hide_sensitive?'':'<th>Profit (₦)</th>'?></tr></thead>
      <tbody>
        <?php foreach($cat_breakdown as $c):?>
        <tr>
          <td style="font-weight:600;color:var(--navy-dark)"><?=$cats[$c['category']]??''?> <?=ucwords(str_replace('_',' ',$c['category']))?></td>
          <td><span class="badge badge-info"><?=number_format($c['count'])?></span></td>
          <td style="font-weight:700">₦<?=number_format($c['vol'],2)?></td>
          <?php if(!$hide_sensitive):?>
          <td style="font-weight:700;color:var(--success)">₦<?=number_format($c['prof'],2)?></td>
          <?php endif;?>
        </tr>
        <?php endforeach;?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif($tab === 'users'): ?>

<div class="data-panel">
  <div class="panel-title-bar">
    <h3><i class="fas fa-users" style="color:var(--primary)"></i> Top 50 Users by Transaction Volume</h3>
  </div>
  <div class="responsive-grid-wrapper">
    <table class="data-table">
      <thead><tr><th>User</th><th>Transactions</th><th>Volume (₦)</th></tr></thead>
      <tbody>
        <?php foreach($top_users as $u):?>
        <tr class="clickable-row" onclick="location.href='users-management.php?q=<?=$u['id']?>'">
          <td>
            <div class="user-cell">
              <div class="u-avatar" style="background:var(--navy);color:#fff"><?=strtoupper(substr($u['full_name'],0,1))?></div>
              <div>
                <span class="u-name"><?=htmlspecialchars($u['full_name'])?></span>
                <span class="u-phone"><?=htmlspecialchars($u['phone'])?></span>
              </div>
            </div>
          </td>
          <td><span style="background:var(--bg);padding:4px 12px;border-radius:20px;font-weight:700;font-size:13px;color:var(--navy-dark)"><?=number_format($u['tx_count'])?></span></td>
          <td><span class="badge badge-success" style="font-size:14px;padding:6px 12px">₦<?=number_format($u['tx_vol'],2)?></span></td>
        </tr>
        <?php endforeach;?>
      </tbody>
    </table>
  </div>
</div>

<?php endif;?>

</div></div>
<script>
</script>
</body>
</html>
