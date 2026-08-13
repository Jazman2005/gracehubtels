<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_admin();
require_permission('view_profit');

$range = preg_replace('/[^a-z0-9_]/', '', strtolower($_GET['range'] ?? 'this_month'));
$custom_from = preg_replace('/[^0-9\-]/', '', $_GET['from'] ?? '');
$custom_to = preg_replace('/[^0-9\-]/', '', $_GET['to'] ?? '');

// Determine date range
$now = new DateTime();
switch($range) {
  case 'today': $start = (clone $now)->setTime(0,0,0); $end = (clone $now)->setTime(23,59,59); break;
  case 'yesterday': $start = (clone $now)->modify('-1 day')->setTime(0,0,0); $end = (clone $now)->modify('-1 day')->setTime(23,59,59); break;
  case 'this_week': $start = (clone $now)->modify('monday this week')->setTime(0,0,0); $end = (clone $now)->setTime(23,59,59); break;
  case 'last_7': $start = (clone $now)->modify('-6 days')->setTime(0,0,0); $end = (clone $now)->setTime(23,59,59); break;
  case 'last_month': $start = (clone $now)->modify('first day of last month')->setTime(0,0,0); $end = (clone $now)->modify('last day of last month')->setTime(23,59,59); break;
  case 'this_year': $start = (clone $now)->setDate($now->format('Y'),1,1)->setTime(0,0,0); $end = (clone $now)->setTime(23,59,59); break;
  case 'custom':
    $start = $custom_from ? new DateTime($custom_from) : (clone $now)->modify('-30 days')->setTime(0,0,0);
    $end = $custom_to ? (new DateTime($custom_to))->setTime(23,59,59) : (clone $now)->setTime(23,59,59);
    break;
  default: $start = (clone $now)->modify('first day of this month')->setTime(0,0,0); $end = (clone $now)->setTime(23,59,59);
}
$s = $start->format('Y-m-d H:i:s');
$e = $end->format('Y-m-d H:i:s');

// Previous period (same length before start)
$interval = $start->diff($end);
$prev_start = (clone $start)->sub($interval);
$prev_end = (clone $start)->modify('-1 second');
$ps = $prev_start->format('Y-m-d H:i:s');
$pe = $prev_end->format('Y-m-d H:i:s');

// Helper: query sum for a category in a date range
function cat_sum($cat, $from, $to) {
  $stmt = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE category=? AND status='success' AND type='debit' AND created_at BETWEEN ? AND ?");
  $stmt->execute([$cat, $from, $to]);
  return $stmt->fetchColumn();
}
function cat_count($cat, $from, $to) {
  $stmt = db()->prepare("SELECT COUNT(*) FROM transactions WHERE category=? AND status='success' AND type='debit' AND created_at BETWEEN ? AND ?");
  $stmt->execute([$cat, $from, $to]);
  return $stmt->fetchColumn();
}

// Revenue & transactions
$stmt = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status='success' AND type='debit' AND created_at BETWEEN ? AND ?");
$stmt->execute([$s, $e]); $total_revenue = $stmt->fetchColumn();
$stmt->execute([$ps, $pe]); $prev_revenue = $stmt->fetchColumn();
$stmt2 = db()->prepare("SELECT COUNT(*) FROM transactions WHERE status='success' AND type='debit' AND created_at BETWEEN ? AND ?");
$stmt2->execute([$s, $e]); $total_tx = $stmt2->fetchColumn();
$stmt2->execute([$ps, $pe]); $prev_tx = $stmt2->fetchColumn();
$stmt3 = db()->prepare("SELECT COUNT(DISTINCT user_id) FROM transactions WHERE status='success' AND type='debit' AND created_at BETWEEN ? AND ?");
$stmt3->execute([$s, $e]); $active_users = $stmt3->fetchColumn();
$stmt4 = db()->prepare("SELECT COALESCE(SUM(profit),0) FROM transactions WHERE status='success' AND profit IS NOT NULL AND created_at BETWEEN ? AND ?");
$stmt4->execute([$s, $e]); $total_profit = $stmt4->fetchColumn();

// Category breakdowns
$cats = [
  'airtime' => ['label'=>'Airtime Sold', 'icon'=>'fa-phone', 'color'=>'#F97316', 'unit'=>'transactions'],
  'data' => ['label'=>'Data Sold', 'icon'=>'fa-wifi', 'color'=>'#3B82F6', 'unit'=>'transactions'],
  'cabletv' => ['label'=>'Cable Subscriptions', 'icon'=>'fa-tv', 'color'=>'#8B5CF6', 'unit'=>'subscriptions'],
  'electricity' => ['label'=>'Electricity Bills', 'icon'=>'fa-bolt', 'color'=>'#D97706', 'unit'=>'payments'],
  'exam_pin' => ['label'=>'Exam PINs', 'icon'=>'fa-graduation-cap', 'color'=>'#14B8A6', 'unit'=>'PINs'],
  'airtime_to_cash' => ['label'=>'Airtime to Cash', 'icon'=>'fa-arrows-rotate', 'color'=>'#EC4899', 'unit'=>'transactions'],
  'recharge_card' => ['label'=>'Airtime PINs', 'icon'=>'fa-ticket', 'color'=>'#16A34A', 'unit'=>'PINs'],
  'data_card' => ['label'=>'Data Cards', 'icon'=>'fa-sd-card', 'color'=>'#FF5A1F', 'unit'=>'cards'],
  'wallet_funding' => ['label'=>'Wallet Funding', 'icon'=>'fa-wallet', 'color'=>'#10B981', 'unit'=>'fundings'],
  'bank_transfer' => ['label'=>'Bank Transfers', 'icon'=>'fa-building-columns', 'color'=>'#3B82F6', 'unit'=>'transfers'],
  'wallet_transfer' => ['label'=>'Wallet Transfers', 'icon'=>'fa-arrow-right-arrow-left', 'color'=>'#8B5CF6', 'unit'=>'transfers'],
];

// Bulk SMS & Virtual Cards (separate tables)
$stmt5 = db()->prepare("SELECT COALESCE(SUM(total_cost),0) FROM bulk_sms_history WHERE status='success' AND created_at BETWEEN ? AND ?");
$stmt5->execute([$s, $e]); $bulk_total = $stmt5->fetchColumn();
$stmt6 = db()->prepare("SELECT COUNT(*) FROM bulk_sms_history WHERE status='success' AND created_at BETWEEN ? AND ?");
$stmt6->execute([$s, $e]); $bulk_count = $stmt6->fetchColumn();
$stmt7 = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE category='virtual_card' AND type='debit' AND status='success' AND created_at BETWEEN ? AND ?");
$stmt7->execute([$s, $e]); $vc_total = $stmt7->fetchColumn();
$stmt8 = db()->prepare("SELECT COUNT(*) FROM transactions WHERE category='virtual_card' AND type='debit' AND status='success' AND created_at BETWEEN ? AND ?");
$stmt8->execute([$s, $e]); $vc_count = $stmt8->fetchColumn();

// Revenue change %
$revenue_change = $prev_revenue > 0 ? round(($total_revenue - $prev_revenue) / $prev_revenue * 100, 1) : ($total_revenue > 0 ? 100 : 0);
$tx_change = $prev_tx > 0 ? round(($total_tx - $prev_tx) / $prev_tx * 100, 1) : ($total_tx > 0 ? 100 : 0);

// CSV export
if(isset($_GET['export']) && $_GET['export']==='csv'){
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=sales_analysis_'.date('Ymd').'.csv');
  $out=fopen('php://output','w');
  fputcsv($out,['Category','Transactions','Revenue (₦)']);
  foreach($cats as $k=>$c){
    $amt=cat_sum($k,$s,$e);
    $cnt=cat_count($k,$s,$e);
    if($amt>0||$cnt>0)fputcsv($out,[$c['label'],$cnt,$amt]);
  }
  if($bulk_total>0)fputcsv($out,['Bulk SMS',$bulk_count,$bulk_total]);
  if($vc_total>0)fputcsv($out,['Virtual Cards',$vc_count,$vc_total]);
  fputcsv($out,['TOTAL','',$total_revenue]);
  fclose($out);exit;
}

// For display formatting
$range_labels = [
  'today'=>'Today','yesterday'=>'Yesterday','this_week'=>'This Week','last_7'=>'Last 7 Days',
  'this_month'=>'This Month','last_month'=>'Last Month','this_year'=>'This Year','custom'=>'Custom'
];
$range_label = $range_labels[$range]??'This Month';
$date_display = $start->format('M d, Y').' — '.$end->format('M d, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Sales Analysis — <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title>
  <link rel="icon" type="image/png" href="/assets/images/favicon.png">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    .analytics-bar{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-bottom:24px;padding:16px 20px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-sm)}
    .analytics-bar .date-nav{display:flex;gap:4px;flex-wrap:wrap}
    .analytics-bar .date-nav a{padding:7px 14px;font-size:12px;font-weight:600;border-radius:8px;color:var(--text-muted);text-decoration:none;transition:all .2s;white-space:nowrap}
    .analytics-bar .date-nav a:hover{background:var(--bg);color:var(--navy-dark)}
    .analytics-bar .date-nav a.active{background:var(--primary);color:#fff;box-shadow:0 2px 8px rgba(255,90,31,.25)}
    .analytics-bar .date-range{display:flex;align-items:center;gap:8px;margin-left:auto}
    .analytics-bar .date-range input{height:36px;padding:0 10px;border:1px solid var(--border);border-radius:8px;font-size:12px;font-family:inherit;background:var(--bg);outline:none}
    .analytics-bar .date-range input:focus{border-color:var(--primary)}
    .cat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-bottom:24px}
    .cat-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:18px;display:flex;flex-direction:column;gap:6px;transition:all .2s;box-shadow:var(--shadow-sm)}
    .cat-card:hover{box-shadow:var(--shadow-md);border-color:var(--border)}
    .cat-card .cat-head{display:flex;align-items:center;gap:10px}
    .cat-card .cat-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
    .cat-card .cat-label{font-size:12px;font-weight:600;color:var(--text-muted)}
    .cat-card .cat-amount{font-size:17px;font-weight:800;color:var(--navy-dark)}
    .cat-card .cat-sub{font-size:11px;color:var(--text-muted)}
    .change-up{color:var(--success);font-weight:600}
    .change-down{color:var(--danger);font-weight:600}
  </style>
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header">
  <div>
    <h2><i class="fas fa-chart-simple" style="color:var(--primary);margin-right:10px"></i> Sales Statistics &amp; Analytics</h2>
    <p>Revenue and transaction breakdown by service category</p>
  </div>
  <div style="display:flex;gap:8px">
    <a href="?export=csv&range=<?=$range?>&from=<?=urlencode($custom_from)?>&to=<?=urlencode($custom_to)?>" class="btn-primary" style="background:var(--success)"><i class="fas fa-file-csv"></i> Export CSV</a>
    <a href="sales-analysis.php" class="btn-ghost"><i class="fas fa-rotate"></i> Refresh</a>
  </div>
</div>

<!-- Date Range Selector -->
<div class="analytics-bar">
  <div class="date-nav">
    <a href="?range=today" class="<?=$range==='today'?'active':''?>">Today</a>
    <a href="?range=yesterday" class="<?=$range==='yesterday'?'active':''?>">Yesterday</a>
    <a href="?range=this_week" class="<?=$range==='this_week'?'active':''?>">This Week</a>
    <a href="?range=last_7" class="<?=$range==='last_7'?'active':''?>">Last 7 Days</a>
    <a href="?range=this_month" class="<?=$range==='this_month'?'active':''?>">This Month</a>
    <a href="?range=last_month" class="<?=$range==='last_month'?'active':''?>">Last Month</a>
    <a href="?range=this_year" class="<?=$range==='this_year'?'active':''?>">This Year</a>
    <a href="?range=custom" class="<?=$range==='custom'?'active':''?>">Custom</a>
  </div>
  <div class="date-range">
    <span style="font-size:13px;color:var(--text-muted);font-weight:600"><?=$date_display?></span>
    <?php if($range==='custom'):?>
    <form method="GET" style="display:flex;gap:6px;align-items:center">
      <input type="hidden" name="range" value="custom">
      <input type="date" name="from" value="<?=esc($custom_from)?>" required>
      <span style="color:var(--text-muted)">→</span>
      <input type="date" name="to" value="<?=esc($custom_to)?>" required>
      <button type="submit" class="btn-primary" style="padding:6px 12px;font-size:12px">Apply</button>
    </form>
    <?php endif;?>
  </div>
</div>

<!-- Top KPI Cards -->
<div class="kpi-grid">
  <div class="kpi-card">
    <div class="kpi-icon orange"><i class="fas fa-chart-line"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">Total Revenue</div>
      <div class="kpi-value">₦<?=number_format($total_revenue,2)?></div>
      <div class="kpi-sub"><span class="<?=$revenue_change>=0?'change-up':'change-down'?>"><?=$revenue_change>=0?'▲':'▼'?> <?=abs($revenue_change)?>%</span> vs prev period</div>
    </div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon blue"><i class="fas fa-exchange-alt"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">Total Transactions</div>
      <div class="kpi-value"><?=number_format($total_tx)?></div>
      <div class="kpi-sub"><span class="<?=$tx_change>=0?'change-up':'change-down'?>"><?=$tx_change>=0?'▲':'▼'?> <?=abs($tx_change)?>%</span> vs prev period</div>
    </div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon green"><i class="fas fa-users"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">Active Users</div>
      <div class="kpi-value"><?=number_format($active_users)?></div>
      <div class="kpi-sub">Transacting in this period</div>
    </div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon purple"><i class="fas fa-chart-line"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">Profit</div>
      <div class="kpi-value">₦<?=number_format($total_profit,2)?></div>
      <div class="kpi-sub">Net earnings for selected period</div>
    </div>
  </div>
</div>

<!-- Category Breakdown -->
<div class="section-header" style="margin-top:8px"><div><h2>Service Breakdown</h2></div></div>

<div class="cat-grid">
  <?php foreach($cats as $k=>$c):
    $amt=cat_sum($k,$s,$e);
    $cnt=cat_count($k,$s,$e);
  ?>
  <?php $rgb = sscanf($c['color'], '#%02x%02x%02x'); $rgba = $rgb[0].','.$rgb[1].','.$rgb[2];?>
  <div class="cat-card">
    <div class="cat-head">
      <div class="cat-icon" style="background:rgba(<?=$rgba?>,0.1);color:<?=$c['color']?>"><i class="fas <?=$c['icon']?>"></i></div>
      <div class="cat-label"><?=$c['label']?></div>
    </div>
    <div class="cat-amount">₦<?=number_format($amt,2)?></div>
    <div class="cat-sub"><?=number_format($cnt)?> <?=$c['unit']?></div>
  </div>
  <?php endforeach;?>

  <!-- Bulk SMS -->
  <div class="cat-card">
    <div class="cat-head">
      <div class="cat-icon" style="background:rgba(16,185,129,0.1);color:var(--success)"><i class="fas fa-comment-sms"></i></div>
      <div class="cat-label">Bulk SMS Sent</div>
    </div>
    <div class="cat-amount">₦<?=number_format($bulk_total,2)?></div>
    <div class="cat-sub"><?=number_format($bulk_count)?> batches</div>
  </div>

  <!-- Virtual Cards -->
  <div class="cat-card">
    <div class="cat-head">
      <div class="cat-icon" style="background:rgba(255,90,31,0.1);color:#FF5A1F"><i class="fas fa-credit-card"></i></div>
      <div class="cat-label">Virtual Cards</div>
    </div>
    <div class="cat-amount">₦<?=number_format($vc_total,2)?></div>
    <div class="cat-sub"><?=number_format($vc_count)?> cards</div>
  </div>
</div>

</div></div>
<script>
</script>
</body>
</html>
