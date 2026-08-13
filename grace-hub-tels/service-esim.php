<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/../user/core/api_router.php';
require_once __DIR__.'/../user/core/esim.php';
auth_admin();
require_feature_admin('esim');

if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    require_permission('manage_pricing');
    $action=$_POST['action']??'';
    if($action==='save_markup'){
        $pct=(float)($_POST['markup_percent']??0);
        if($pct<0)json_response(['success'=>false,'message'=>'Markup cannot be negative'],400);
        setting_set('esim_markup_percent',(string)$pct);
        audit_log((int)$_SESSION['admin_id'],'ESIM_MARKUP',"Set eSIM markup to {$pct}%");
        json_response(['success'=>true,'message'=>'Markup saved']);
    }
    if($action==='check_status'){
        $ref=trim($_POST['ref']??'');
        $res=resolve_esim_order($ref);
        audit_log((int)$_SESSION['admin_id'],'ESIM_CHECK_STATUS',"Checked status for eSIM order $ref: ".($res['message']??''));
        json_response($res);
    }
    json_response(['success'=>false],400);
}
$csrf=admin_csrf();
$markup=(float)setting('esim_markup_percent','0');
$providers=get_api_providers('esim');
$orders=db()->query("SELECT o.*, u.full_name, u.phone AS user_phone FROM esim_orders o LEFT JOIN users u ON u.id=o.user_id ORDER BY o.id DESC LIMIT 50")->fetchAll();
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>eSIM – <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title><link rel="icon" type="image/png" href="/assets/images/favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header"><div><h2>eSIM</h2><p>Live catalog pulled from registered providers, priced at their real NGN rate plus your markup.</p></div></div>

<?php if(empty($providers)):?>
<div style="background:#FFF7ED;border:1px solid #FDE68A;border-radius:10px;padding:12px 20px;font-size:13px;color:#92400E;display:flex;align-items:flex-start;gap:12px;margin-bottom:16px;">
  <i class="fas fa-info-circle" style="margin-top:2px;"></i>
  <div>No provider is registered for this service yet — checkout stays disabled until at least one is active. Register one via <a href="<?=ADMIN_URL?>/api-registry" style="color:#92400E;font-weight:700;text-decoration:underline;">API Registry</a>, then add a route for it under <a href="<?=ADMIN_URL?>/api-providers" style="color:#92400E;font-weight:700;text-decoration:underline;">API Route Manager</a> with service set to <code>esim</code>.</div>
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
      <thead><tr><th>User</th><th>Region</th><th>Package</th><th>Email</th><th>Amount</th><th>Provider</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($orders as $o):
        $scls='text-slate-600 bg-slate-100';
        if($o['status']==='success')$scls='text-emerald-600 bg-emerald-100';
        elseif($o['status']==='processing')$scls='text-blue-600 bg-blue-100';
        elseif($o['status']==='failed')$scls='text-rose-600 bg-rose-100';
      ?>
      <tr id="row-<?=htmlspecialchars($o['transaction_ref'])?>">
        <td><?=htmlspecialchars($o['full_name']??$o['user_phone']??('User #'.$o['user_id']))?></td>
        <td><?=htmlspecialchars($o['region'])?></td>
        <td><?=htmlspecialchars($o['package_name']??'')?></td>
        <td><?=htmlspecialchars($o['email']??'')?></td>
        <td>₦<?=number_format((float)$o['amount_ngn'],2)?></td>
        <td><?=htmlspecialchars($o['provider_name'])?></td>
        <td><span class="status-badge" style="padding:2px 8px;border-radius:12px;font-weight:700;font-size:11px;" class="<?=$scls?>"><?=ucfirst($o['status'])?></span></td>
        <td><?=htmlspecialchars($o['created_at'])?></td>
        <td><?php if($o['status']==='processing'):?><button class="btn-ghost btn-sm" onclick="checkStatus('<?=htmlspecialchars($o['transaction_ref'])?>')"><i class="fas fa-sync"></i> Check Status</button><?php endif;?></td>
      </tr>
      <?php endforeach; if(empty($orders)):?><tr><td colspan="9" class="empty-state">No orders yet</td></tr><?php endif;?>
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
async function checkStatus(ref){
  const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','check_status');fd.append('ref',ref);
  const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
  showToast(r.message,r.success);
  if(r.ready)setTimeout(()=>location.reload(),1200);
}
function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),2500);}
</script>
</body></html>
