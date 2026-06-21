<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_admin();
$csrf=admin_csrf();$page=max(1,(int)($_GET['page']??1));
$filters=['status'=>$_GET['status']??'','category'=>$_GET['category']??''];
$data=get_transactions_list($page,25,$filters);
$cats=['airtime'=>'📱','data'=>'📶','electricity'=>'⚡','cabletv'=>'📺','wallet_funding'=>'💰','bank_transfer'=>'🏦','airtime_to_cash'=>'🔄','exam_pin'=>'🎓','recharge_card'=>'🃏','data_card'=>'💳','cashback'=>'🎁'];
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Transactions – MsjPay Admin</title><link rel="icon" type="image/png" href="/msjpay/assets/images/msjpay-favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="/msjpay/msj-admin/css/admin.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="page-content">
<div class="page-head"><div><h2>Transactions</h2><p><?=number_format($data['total'])?> total transactions</p></div></div>
<div class="card">
  <form class="search-bar" method="GET">
    <div class="filter-tabs">
      <a href="?status=" class="filter-tab <?=$filters['status']===''?'active':''?>">All Status</a>
      <a href="?status=success" class="filter-tab <?=$filters['status']==='success'?'active':''?>">Success</a>
      <a href="?status=pending" class="filter-tab <?=$filters['status']==='pending'?'active':''?>">Pending</a>
      <a href="?status=failed" class="filter-tab <?=$filters['status']==='failed'?'active':''?>">Failed</a>
    </div>
    <select name="category" class="form-control" style="width:auto" onchange="this.form.submit()">
      <option value="">All Categories</option>
      <?php foreach($cats as $k=>$ic):?><option value="<?=$k?>" <?=$filters['category']===$k?'selected':''?>><?=$ic?> <?=ucwords(str_replace('_',' ',$k))?></option><?php endforeach;?>
    </select>
  </form>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Ref</th><th>User</th><th>Type</th><th>Category</th><th>Amount</th><th>Profit</th><th>Network</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach($data['data'] as $tx):$scls=['success'=>'badge-success','pending'=>'badge-pending','failed'=>'badge-danger','reversed'=>'badge-warning'][$tx['status']]??'badge-pending';?>
      <tr>
        <td><code><?=substr($tx['transaction_ref'],0,14)?>…</code></td>
        <td><div class="user-cell"><div class="u-avatar"><?=strtoupper(substr($tx['full_name']??'?',0,1))?></div><div><span class="u-name"><?=htmlspecialchars($tx['full_name']??'—')?></span><span class="u-phone"><?=htmlspecialchars($tx['phone']??'')?></span></div></div></td>
        <td><span class="badge <?=$tx['type']==='credit'?'badge-success':'badge-neutral'?>"><?=strtoupper($tx['type'])?></span></td>
        <td style="font-size:12px"><?=$cats[$tx['category']]??''?> <?=ucwords(str_replace('_',' ',$tx['category']))?></td>
        <td class="<?=$tx['type']==='credit'?'text-success':'text-danger'?>"><?=$tx['type']==='credit'₦'+':'-'?>₦<?=number_format((float)$tx['amount'],2)?></td>
        <td><?=$tx['profit']!==null?'<span class="text-success">₦'.number_format((float)$tx['profit'],2).'</span>':'—'?></td>
        <td style="font-size:12px"><?=htmlspecialchars($tx['network']??'—')?><?=$tx['network_type']?' ('.$tx['network_type'].')':''?></td>
        <td><span class="badge <?=$scls?>"><?=ucfirst($tx['status'])?></span></td>
        <td style="font-size:12px;color:#6B7280"><?=date('d M, H:i',strtotime($tx['created_at']))?></td>
      </tr>
      <?php endforeach;if(empty($data['data'])):?><tr><td colspan="9" class="empty-state">No transactions found</td></tr><?php endif;?>
      </tbody>
    </table>
  </div>
  <?php if($data['pages']>1):?>
  <div class="pagination"><span class="page-info">Page <?=$page?> of <?=$data['pages']?></span>
  <?php for($i=max(1,$page-2);$i<=min($data['pages'],$page+2);$i++):?><a href="?page=<?=$i?>&status=<?=$filters['status']?>&category=<?=$filters['category']?>" class="page-btn <?=$i===$page?'active':''?>"><?=$i?></a><?php endfor;?>
  </div>
  <?php endif;?>
</div>
</div></div>
<script>function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}</script>
</body></html>
