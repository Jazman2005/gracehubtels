<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_admin();
$csrf=admin_csrf();

$tab = $_GET['tab'] ?? 'all';
$msg = '';

if(isset($_GET['export']) && $_GET['export']==='csv'){
    $filters=['status'=>$_GET['status']??'','category'=>$_GET['category']??''];
    $data=get_transactions_list(1, 999999, $filters);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=transactions_export_'.date('YmdHis').'.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Reference', 'User Name', 'Type', 'Category', 'Amount', 'Charge', 'Cost Price', 'Profit', 'Network', 'Provider', 'Status', 'Date']);
    foreach($data['data'] as $tx) {
        fputcsv($out, [
            $tx['transaction_ref'], $tx['full_name'], $tx['type'], $tx['category'],
            $tx['amount'], $tx['charge'], $tx['cost_price'], $tx['profit'],
            $tx['network'], $tx['provider'], $tx['status'], $tx['created_at']
        ]);
    }
    fclose($out);
    exit;
}

$cats=['airtime'=>'📱','data'=>'📶','electricity'=>'⚡','cabletv'=>'📺','wallet_funding'=>'💰','bank_transfer'=>'🏦','airtime_to_cash'=>'🔄','exam_pin'=>'🎓','recharge_card'=>'🃏','data_card'=>'💳','cashback'=>'🎁'];

// For lists
if(in_array($tab, ['all','success','pending','failed'])){
    $page=max(1,(int)($_GET['page']??1));
    $status_filter = $tab==='all' ? ($_GET['status']??'') : $tab;
    $filters=['status'=>$status_filter,'category'=>$_GET['category']??''];
    $data=get_transactions_list($page,25,$filters);
}

// For Sales Analysis
if($tab === 'sales'){
    $today_sales = db()->query("SELECT SUM(amount) FROM transactions WHERE DATE(created_at) = CURDATE() AND status='success'")->fetchColumn() ?: 0;
    $today_profit = db()->query("SELECT SUM(profit) FROM transactions WHERE DATE(created_at) = CURDATE() AND status='success'")->fetchColumn() ?: 0;
    
    $month_sales = db()->query("SELECT SUM(amount) FROM transactions WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status='success'")->fetchColumn() ?: 0;
    $month_profit = db()->query("SELECT SUM(profit) FROM transactions WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status='success'")->fetchColumn() ?: 0;
    
    $cat_breakdown = db()->query("SELECT category, COUNT(*) as count, SUM(amount) as vol, SUM(profit) as prof FROM transactions WHERE status='success' GROUP BY category ORDER BY vol DESC")->fetchAll();
}

// For Users Analysis
if($tab === 'users'){
    $top_users = db()->query("
        SELECT u.id, u.full_name, u.email, u.phone, COUNT(t.id) as tx_count, SUM(t.amount) as tx_vol
        FROM users u
        JOIN transactions t ON u.id = t.user_id
        WHERE t.status = 'success'
        GROUP BY u.id
        ORDER BY tx_vol DESC
        LIMIT 50
    ")->fetchAll();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Transactions – MsjPay Admin</title>
    <link rel="icon" type="image/png" href="/msjpay/assets/images/msjpay-favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/msjpay/msj-admin/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .page-tabs { display:flex; gap:8px; border-bottom:1px solid #E2E8F0; margin-bottom:24px; padding-bottom:0; overflow-x:auto; }
        .page-tab { padding:12px 20px; font-weight:600; font-size:14px; color:#64748B; text-decoration:none; border-bottom:3px solid transparent; transition:all 0.2s ease; white-space:nowrap; }
        .page-tab:hover { color:var(--navy-dark); }
        .page-tab.active { color:var(--primary); border-bottom-color:var(--primary); }
    </style>
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="page-content">

<div class="page-head" style="margin-bottom:16px;">
  <div>
      <h2>Transactions Manager</h2>
      <p>Monitor platform activity, analyze sales, and manage user financial records.</p>
  </div>
  <?php if(in_array($tab, ['all','success','pending','failed'])): ?>
  <a href="?export=csv&status=<?=$filters['status']?>&category=<?=$filters['category']?>" class="btn-primary" style="background:#10B981;text-decoration:none"><i class="fas fa-file-csv" style="margin-right:6px"></i> Export CSV</a>
  <?php endif; ?>
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
    <div class="card">
      <form class="search-bar" method="GET" style="margin-bottom:16px;">
        <input type="hidden" name="tab" value="<?=$tab?>">
        <select name="category" class="form-control" style="width:auto; min-width:200px;" onchange="this.form.submit()">
          <option value="">All Categories</option>
          <?php foreach($cats as $k=>$ic):?><option value="<?=$k?>" <?=$filters['category']===$k?'selected':''?>><?=$ic?> <?=ucwords(str_replace('_',' ',$k))?></option><?php endforeach;?>
        </select>
        <span style="font-size:13px; color:var(--text-muted); margin-left:12px;"><?=number_format($data['total'])?> records found</span>
      </form>
      
      <div class="table-wrap" style="border:none;">
        <table class="data-table">
          <thead><tr><th>#</th><th>User Info</th><th>Ref Id</th><th>Description</th><th>Profit</th></tr></thead>
          <tbody>
          <?php $i=1; foreach($data['data'] as $tx):
            $scls=['success'=>'badge-success','pending'=>'badge-pending','failed'=>'badge-danger','reversed'=>'badge-warning'][$tx['status']]??'badge-pending';
            $sclr=['success'=>'#10B981','pending'=>'#F59E0B','failed'=>'#EF4444','reversed'=>'#F59E0B'][$tx['status']]??'#F59E0B';
            // Specific category colors or default orange
            $cat_color = '#F97316';
          ?>
          <tr>
            <td style="color:#64748B; font-weight:500; width:40px; vertical-align:top;"><?=$i++?></td>
            <td style="vertical-align:top;">
                <div style="font-weight:600; color:#475569; font-size:13px; margin-bottom:4px;"><?=htmlspecialchars($tx['email']??$tx['full_name']??'—')?></div>
                <div style="color:#64748B; font-size:12px; margin-bottom:4px;"><?=htmlspecialchars($tx['phone']??'')?></div>
                <div style="color:#64748B; font-size:12px; margin-bottom:8px;">(Subscriber)</div>
                <div style="color:#64748B; font-size:12px; margin-bottom:2px;">Old Balance: ₦<?=number_format((float)$tx['balance_before'],2)?></div>
                <div style="color:#475569; font-size:12px; font-weight:600;">New Balance: ₦<?=number_format((float)$tx['balance_after'],2)?></div>
            </td>
            <td style="vertical-align:top;">
                <div style="font-weight:600; font-size:13px; color:<?=$sclr?>; margin-bottom:4px;">Status: <?=ucfirst($tx['status'])?></div>
                <div style="color:#64748B; font-size:12px; margin-bottom:4px;"><?=htmlspecialchars($tx['transaction_ref'])?></div>
                <div style="color:#64748B; font-size:12px; margin-bottom:8px;"><?=date('d M Y h:iA',strtotime($tx['created_at']))?></div>
                <a href="/msjpay/msj-admin/transaction-details.php?ref=<?=urlencode($tx['transaction_ref'])?>" style="color:#0CA4A5; font-size:12px; font-weight:600; text-decoration:none;">View Details</a>
            </td>
            <td style="vertical-align:top;">
                <div style="color:<?=$cat_color?>; font-weight:600; font-size:13px; margin-bottom:4px;">
                    <?php if($tx['category']==='data_plan') echo 'Data'; else if($tx['category']==='wallet_funding') echo 'Wallet Topup'; else echo ucwords(str_replace('_',' ',$tx['category'])); ?>
                </div>
                <div style="color:#64748B; font-size:12px; line-height:1.6;">
                    <?=htmlspecialchars($tx['description'])?> <br>
                    <span style="color:#9CA3AF;">(Amount: ₦<?=number_format((float)$tx['amount'])?>)</span>
                </div>
            </td>
            <td style="font-weight:600; font-size:13px; color:#475569; vertical-align:top;">
                ₦<?=$tx['profit']!==null?number_format((float)$tx['profit']):'0'?>
            </td>
          </tr>
          <?php endforeach;if(empty($data['data'])):?><tr><td colspan="5" class="empty-state">No transactions found</td></tr><?php endif;?>
          </tbody>
        </table>
      </div>
      <?php if($data['pages']>1):?>
      <div class="pagination" style="margin-top:20px; border-top:1px solid #E2E8F0; padding-top:20px;"><span class="page-info">Page <?=$page?> of <?=$data['pages']?></span>
      <?php for($i=max(1,$page-2);$i<=min($data['pages'],$page+2);$i++):?><a href="?tab=<?=$tab?>&page=<?=$i?>&category=<?=$filters['category']?>" class="page-btn <?=$i===$page?'active':''?>"><?=$i?></a><?php endfor;?>
      </div>
      <?php endif;?>
    </div>

<?php elseif($tab === 'sales'): ?>
    
    <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:20px; margin-bottom:24px;">
        <div class="card" style="padding:20px;">
            <div style="font-size:13px;color:var(--text-muted);font-weight:600;margin-bottom:8px;">TODAY'S SALES</div>
            <div style="font-size:24px;font-weight:800;color:var(--navy-dark);">₦<?=number_format($today_sales, 2)?></div>
        </div>
        <div class="card" style="padding:20px;">
            <div style="font-size:13px;color:var(--text-muted);font-weight:600;margin-bottom:8px;">TODAY'S PROFIT</div>
            <div style="font-size:24px;font-weight:800;color:var(--success);">₦<?=number_format($today_profit, 2)?></div>
        </div>
        <div class="card" style="padding:20px;">
            <div style="font-size:13px;color:var(--text-muted);font-weight:600;margin-bottom:8px;">MONTHLY SALES</div>
            <div style="font-size:24px;font-weight:800;color:var(--navy-dark);">₦<?=number_format($month_sales, 2)?></div>
        </div>
        <div class="card" style="padding:20px;">
            <div style="font-size:13px;color:var(--text-muted);font-weight:600;margin-bottom:8px;">MONTHLY PROFIT</div>
            <div style="font-size:24px;font-weight:800;color:var(--success);">₦<?=number_format($month_profit, 2)?></div>
        </div>
    </div>
    
    <div class="card" style="padding:0; overflow:hidden;">
        <div class="card-header" style="border-bottom:1px solid #E2E8F0; padding:20px; background:#F8FAFC;">
            <h3 style="margin:0;font-size:16px;color:var(--navy-dark);"><i class="fas fa-chart-pie" style="color:var(--primary);margin-right:8px;"></i> Sales by Category (All Time)</h3>
        </div>
        <div class="table-wrap" style="border:none;">
            <table class="data-table">
                <thead><tr><th style="padding-left:20px;">Category</th><th>Total Transactions</th><th>Total Volume (₦)</th><th>Total Profit (₦)</th></tr></thead>
                <tbody>
                    <?php foreach($cat_breakdown as $c): ?>
                    <tr>
                        <td style="padding-left:20px;font-weight:600;color:var(--navy-dark);"><?=$cats[$c['category']]??''?> <?=ucwords(str_replace('_',' ',$c['category']))?></td>
                        <td><span class="badge badge-info"><?=number_format($c['count'])?></span></td>
                        <td style="font-weight:700;">₦<?=number_format($c['vol'], 2)?></td>
                        <td style="font-weight:700;color:var(--success);">₦<?=number_format($c['prof'], 2)?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif($tab === 'users'): ?>

    <div class="card" style="padding:0; overflow:hidden;">
        <div class="card-header" style="border-bottom:1px solid #E2E8F0; padding:20px; background:#F8FAFC;">
            <h3 style="margin:0;font-size:16px;color:var(--navy-dark);"><i class="fas fa-users" style="color:var(--primary);margin-right:8px;"></i> Top 50 Users by Transaction Volume</h3>
        </div>
        <div class="table-wrap" style="border:none;">
            <table class="data-table">
                <thead><tr><th style="padding-left:20px;">User</th><th>Total Transactions</th><th>Total Volume (₦)</th></tr></thead>
                <tbody>
                    <?php foreach($top_users as $u): ?>
                    <tr class="clickable-row" onclick="location.href='users-management.php?q=<?=$u['id']?>'">
                        <td style="padding-left:20px;">
                            <div class="user-cell">
                                <div class="u-avatar" style="background:var(--navy);color:#fff"><?=strtoupper(substr($u['full_name'],0,1))?></div>
                                <div><span class="u-name"><?=htmlspecialchars($u['full_name'])?></span><span class="u-phone"><?=htmlspecialchars($u['phone'])?></span></div>
                            </div>
                        </td>
                        <td><span style="background:#F1F5F9;padding:4px 10px;border-radius:20px;font-weight:700;font-size:13px;color:var(--navy-dark);"><?=number_format($u['tx_count'])?></span></td>
                        <td><span class="badge badge-success" style="font-size:14px;padding:6px 12px;">₦<?=number_format($u['tx_vol'], 2)?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php endif; ?>

</div></div>
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
</script>
</body>
</html>
