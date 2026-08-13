<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
require_feature_admin('giftcard_sell');

if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    require_permission('manage_pricing');
    $action=$_POST['action']??'';
    if($action==='save'){
        $id=(int)($_POST['id']??0);
        $brand=sanitize($_POST['network']??'',50);
        $rate=(float)($_POST['discount_percent']??0);
        if(!$brand)json_response(['success'=>false,'message'=>'Brand is required'],400);
        if($rate<=0||$rate>100)json_response(['success'=>false,'message'=>'Payout rate must be between 1 and 100'],400);
        $active=isset($_POST['is_active'])?1:0;
        if($id){
            db()->prepare("UPDATE service_pricing SET network=?,discount_percent=?,is_active=? WHERE id=? AND service='giftcard_sell'")->execute([$brand,$rate,$active,$id]);
        }else{
            db()->prepare("INSERT INTO service_pricing (service,network,plan_name,discount_percent,is_active) VALUES ('giftcard_sell',?,?,?,?)")->execute([$brand,$brand,$rate,$active]);
        }
        json_response(['success'=>true,'message'=>'Rate saved']);
    }
    if($action==='delete'){
        db()->prepare("DELETE FROM service_pricing WHERE id=? AND service='giftcard_sell'")->execute([(int)$_POST['id']]);
        json_response(['success'=>true,'message'=>'Deleted']);
    }
    if($action==='toggle'){
        db()->prepare("UPDATE service_pricing SET is_active=NOT is_active WHERE id=? AND service='giftcard_sell'")->execute([(int)$_POST['id']]);
        json_response(['success'=>true]);
    }
    json_response(['success'=>false],400);
}
$csrf=admin_csrf();
$rates=db()->query("SELECT * FROM service_pricing WHERE service='giftcard_sell' ORDER BY network")->fetchAll();
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Gift Cards (Sell) – <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title><link rel="icon" type="image/png" href="/assets/images/favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header"><div><h2>Gift Cards — Sell for Cash</h2><p>Manage which brands you accept and the cash payout rate for each.</p></div><button class="btn-primary" onclick="openModal()"><i class="fas fa-plus"></i> Add Brand</button></div>

<div style="background:#FFF7ED;border:1px solid #FDE68A;border-radius:10px;padding:12px 20px;font-size:13px;color:#92400E;display:flex;align-items:flex-start;gap:12px;margin-bottom:16px;">
  <i class="fas fa-info-circle" style="margin-top:2px;"></i>
  <div>No review/payout workflow is wired up yet — brands and rates can be configured now, but customer submissions return "not live yet" and nothing is credited until the real flow is built.</div>
</div>

<div class="data-panel">
  <div class="responsive-grid-wrapper">
    <table class="data-table">
      <thead><tr><th>Brand</th><th>Payout Rate</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($rates as $p):?>
      <tr>
        <td><strong><?=htmlspecialchars($p['network'])?></strong></td>
        <td><span class="badge badge-info" style="font-weight:bold"><?=$p['discount_percent']?>%</span></td>
        <td><label class="toggle"><input type="checkbox" <?=$p['is_active']?'checked':''?> onchange="toggleP(<?=$p['id']?>)"><span class="slider"></span></label></td>
        <td style="white-space:nowrap"><button class="btn-ghost btn-sm" onclick='editP(<?=htmlspecialchars(json_encode($p),ENT_QUOTES)?>)'><i class="fas fa-edit"></i> Edit</button> <button class="btn-ghost btn-sm" style="color:var(--danger)" onclick="deleteP(<?=$p['id']?>)"><i class="fas fa-trash"></i> Delete</button></td>
      </tr>
      <?php endforeach;if(empty($rates)):?><tr><td colspan="4" class="empty-state">No brands accepted yet</td></tr><?php endif;?>
      </tbody>
    </table>
  </div>
</div>

</div></div>

<div class="modal-overlay" id="modalOvl" style="display:none">
  <div class="modal">
    <div class="modal-header"><h3 id="modalTitle">Add Brand</h3><button class="modal-close" onclick="closeModal()">✕</button></div>
    <div class="modal-body">
      <input type="hidden" id="pid" value="">
      <div style="display:flex;flex-direction:column;gap:14px">
        <div class="form-group"><label>Brand</label><input type="text" class="form-control" id="p_network" placeholder="e.g. Amazon, iTunes, Steam"></div>
        <div class="form-group"><label>Payout Rate (%)</label><input type="number" class="form-control" id="p_discount_percent" step="0.01" placeholder="e.g. 65"></div>
        <div class="flex items-center justify-between"><label class="font-semibold text-sm" style="color:var(--navy-dark)">Active</label><label class="toggle"><input type="checkbox" id="p_is_active" checked><span class="slider"></span></label></div>
        <button class="btn-primary" style="width:100%;height:44px;justify-content:center" onclick="saveP()"><i class="fas fa-save"></i> Save Brand</button>
      </div>
    </div>
  </div>
</div>
<div class="save-toast" id="toast"></div>
<script>
const CSRF='<?=$csrf?>';
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
function openModal(){document.getElementById('modalTitle').textContent='Add Brand';['pid','p_network','p_discount_percent'].forEach(id=>document.getElementById(id).value='');document.getElementById('p_is_active').checked=true;document.getElementById('modalOvl').style.display='flex';}
function editP(p){document.getElementById('modalTitle').textContent='Edit Brand';document.getElementById('pid').value=p.id;document.getElementById('p_network').value=p.network;document.getElementById('p_discount_percent').value=p.discount_percent;document.getElementById('p_is_active').checked=p.is_active==1;document.getElementById('modalOvl').style.display='flex';}
function closeModal(){document.getElementById('modalOvl').style.display='none';}
async function saveP(){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','save');fd.append('id',document.getElementById('pid').value);['p_network','p_discount_percent'].forEach(id=>fd.append(id.replace('p_',''),document.getElementById(id).value));if(document.getElementById('p_is_active').checked)fd.append('is_active','1');const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);}
async function deleteP(id){if(!confirm('Delete this brand?'))return;const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','delete');fd.append('id',id);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),700);}
async function toggleP(id){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','toggle');fd.append('id',id);await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});}
function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),2500);}
document.getElementById('modalOvl').addEventListener('click',e=>{if(e.target===e.currentTarget)closeModal();});
</script>
</body></html>
