<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/../user/core/api_router.php';
auth_admin();
require_feature_admin('international_airtime');

if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    require_permission('manage_pricing');
    $action=$_POST['action']??'';
    if($action==='save_markup'){
        $pct=(float)($_POST['markup_percent']??0);
        if($pct<0)json_response(['success'=>false,'message'=>'Markup cannot be negative'],400);
        setting_set('international_airtime_markup_percent',(string)$pct);
        audit_log((int)$_SESSION['admin_id'],'INTL_AIRTIME_MARKUP',"Set international airtime markup to {$pct}%");
        json_response(['success'=>true,'message'=>'Markup saved']);
    }
    json_response(['success'=>false],400);
}
$csrf=admin_csrf();
$markup=(float)setting('international_airtime_markup_percent','0');
$providers=get_api_providers('international_airtime');
$orders=db()->query("SELECT o.*, u.full_name, u.phone AS user_phone FROM international_airtime_orders o LEFT JOIN users u ON u.id=o.user_id ORDER BY o.id DESC LIMIT 50")->fetchAll();
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>International Airtime – <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title><link rel="icon" type="image/png" href="/assets/images/favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header"><div><h2>International Airtime</h2><p>Live catalog pulled from registered providers, priced at their real NGN rate plus your markup.</p></div></div>

<?php if(empty($providers)):?>
<div style="background:#FFF7ED;border:1px solid #FDE68A;border-radius:10px;padding:12px 20px;font-size:13px;color:#92400E;display:flex;align-items:flex-start;gap:12px;margin-bottom:16px;">
  <i class="fas fa-info-circle" style="margin-top:2px;"></i>
  <div>No provider is registered for this service yet — checkout stays disabled until at least one is active. Register one via <a href="<?=ADMIN_URL?>/api-registry" style="color:#92400E;font-weight:700;text-decoration:underline;">API Registry</a>, then add a route for it under <a href="<?=ADMIN_URL?>/api-providers" style="color:#92400E;font-weight:700;text-decoration:underline;">API Route Manager</a> with service set to <code>international_airtime</code>.</div>
</div>
<?php endif;?>

<div class="data-panel" style="margin-bottom:20px;">
  <div class="panel-title-bar"><h3><i class="fas fa-percent" style="color:var(--primary);margin-right:8px"></i> Markup</h3></div>
  <div style="padding:16px 20px;display:flex;align-items:flex-end;gap:16px;">
    <div class="form-group" style="margin:0;max-width:200px;">
      <label>Markup on provider's NGN price (%)</label>
      <input type="number" class="form-control" id="markupInput" value="<?=htmlspecialchars((string)$markup)?>" step="0.1" min="0">
    </div>
    <button class="btn-primary" onclick="saveMarkup()"><i class="fas fa-save"></i> Save</button>
  </div>
</div>

<div class="data-panel" style="margin-bottom:20px;">
  <div class="panel-title-bar"><h3><i class="fas fa-plug" style="color:var(--primary);margin-right:8px"></i> Active Providers</h3></div>
  <div class="responsive-grid-wrapper">
    <table class="data-table">
      <thead><tr><th>Provider</th><th>Priority</th><th>Region</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach($providers as $p):?>
      <tr>
        <td><strong><?=htmlspecialchars($p['provider_name'])?></strong></td>
        <td><?=(int)$p['priority']?></td>
        <td><?=htmlspecialchars($p['network']?:'ALL')?></td>
        <td><span class="badge <?=$p['is_active']?'badge-success':'badge-neutral'?>"><?=$p['is_active']?'Active':'Inactive'?></span></td>
      </tr>
      <?php endforeach; if(empty($providers)):?><tr><td colspan="4" class="empty-state">No providers registered</td></tr><?php endif;?>
      </tbody>
    </table>
  </div>
</div>

<div class="data-panel">
  <div class="panel-title-bar"><h3><i class="fas fa-receipt" style="color:var(--primary);margin-right:8px"></i> Recent Orders</h3></div>
  <div class="responsive-grid-wrapper">
    <table class="data-table">
      <thead><tr><th>User</th><th>Country</th><th>Operator</th><th>Phone</th><th>Amount</th><th>Provider</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach($orders as $o):
        $scls='text-slate-600 bg-slate-100';
        if($o['status']==='success')$scls='text-emerald-600 bg-emerald-100';
        elseif($o['status']==='processing')$scls='text-blue-600 bg-blue-100';
        elseif($o['status']==='failed')$scls='text-rose-600 bg-rose-100';
      ?>
      <tr>
        <td><?=htmlspecialchars($o['full_name']??$o['user_phone']??('User #'.$o['user_id']))?></td>
        <td><?=htmlspecialchars($o['country'])?></td>
        <td><?=htmlspecialchars($o['operator_name']??'')?></td>
        <td><?=htmlspecialchars($o['phone'])?></td>
        <td>₦<?=number_format((float)$o['amount_ngn'],2)?></td>
        <td><?=htmlspecialchars($o['provider_name'])?></td>
        <td><span class="badge" style="background:transparent;padding:2px 8px;border-radius:12px;font-weight:700;font-size:11px;" class="<?=$scls?>"><?=ucfirst($o['status'])?></span></td>
        <td><?=htmlspecialchars($o['created_at'])?></td>
      </tr>
      <?php endforeach; if(empty($orders)):?><tr><td colspan="8" class="empty-state">No orders yet</td></tr><?php endif;?>
      </tbody>
    </table>
  </div>
</div>

</div></div>
<div class="save-toast" id="toast"></div>
<script>
const CSRF='<?=$csrf?>';
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
async function saveMarkup(){
  const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','save_markup');fd.append('markup_percent',document.getElementById('markupInput').value);
  const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
  showToast(r.message,r.success);
}
function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),2500);}
</script>
</body></html>
