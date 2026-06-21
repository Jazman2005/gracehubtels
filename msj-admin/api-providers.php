<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    $action=$_POST['action']??'';
    if($action==='save'){
        $id=(int)($_POST['id']??0);
        $data=[sanitize($_POST['service']??'',50),sanitize($_POST['network']??'',20)?:null,sanitize($_POST['data_type']??'',50)?:null,sanitize($_POST['provider_name']??'',100),sanitize($_POST['api_base_url']??'',300),trim($_POST['api_key']??''),trim($_POST['api_secret']??''),trim($_POST['api_token']??''),(int)($_POST['priority']??1),isset($_POST['is_active'])?1:0];
        if($id){db()->prepare("UPDATE api_providers SET service=?,network=?,data_type=?,provider_name=?,api_base_url=?,api_key=?,api_secret=?,api_token=?,priority=?,is_active=? WHERE id=?")->execute([...$data,$id]);}
        else{db()->prepare("INSERT INTO api_providers (service,network,data_type,provider_name,api_base_url,api_key,api_secret,api_token,priority,is_active) VALUES (?,?,?,?,?,?,?,?,?,?)")->execute($data);}
        json_response(['success'=>true,'message'=>'Provider saved']);
    }
    if($action==='delete'){db()->prepare("DELETE FROM api_providers WHERE id=?")->execute([(int)$_POST['id']]);json_response(['success'=>true,'message'=>'Deleted']);}
    if($action==='toggle'){db()->prepare("UPDATE api_providers SET is_active=NOT is_active WHERE id=?")->execute([(int)$_POST['id']]);json_response(['success'=>true]);}
    json_response(['success'=>false],400);
}
$csrf=admin_csrf();
$providers=db()->query("SELECT * FROM api_providers ORDER BY service,network,priority")->fetchAll();
$services=['airtime','data','electricity','cabletv','airtime_to_cash','exam_pin','recharge_card','data_card'];
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>API Providers – MsjPay Admin</title><link rel="icon" type="image/png" href="/msjpay/assets/images/msjpay-favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="/msjpay/msj-admin/css/admin.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="page-content">

<div class="page-head"><div><h2>API Providers</h2><p>Configure per-service, per-network API endpoints. Nothing hardcoded.</p></div><button class="btn-primary" onclick="openModal()">+ Add Provider</button></div>

<div class="card">
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Service</th><th>Network</th><th>Data Type</th><th>Provider</th><th>Base URL</th><th>Priority</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($providers as $p):?>
      <tr>
        <td><strong><?=ucwords(str_replace('_',' ',$p['service']))?></strong></td>
        <td><?=$p['network']?:'<span style="color:#9CA3AF">All</span>'?></td>
        <td><?=$p['data_type']?:'<span style="color:#9CA3AF">—</span>'?></td>
        <td><?=htmlspecialchars($p['provider_name'])?></td>
        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;color:#6B7280"><?=htmlspecialchars($p['api_base_url'])?></td>
        <td><?=$p['priority']?></td>
        <td><label class="toggle"><input type="checkbox" <?=$p['is_active']?'checked':''?> onchange="toggleProvider(<?=$p['id']?>)"><span class="slider"></span></label></td>
        <td style="white-space:nowrap">
          <button class="btn-sm btn-ghost" onclick='editProvider(<?=htmlspecialchars(json_encode($p),ENT_QUOTES)?>)'>Edit</button>
          <button class="btn-sm btn-danger" onclick="deleteProvider(<?=$p['id']?>)">Delete</button>
        </td>
      </tr>
      <?php endforeach;if(empty($providers)):?><tr><td colspan="8" class="empty-state">No API providers configured</td></tr><?php endif;?>
      </tbody>
    </table>
  </div>
</div>

</div></div>

<!-- MODAL -->
<div class="modal-overlay" id="modalOvl" style="display:none">
  <div class="modal">
    <div class="modal-header"><h3 id="modalTitle">Add API Provider</h3><button class="modal-close" onclick="closeModal()">✕</button></div>
    <div class="modal-body">
      <input type="hidden" id="pid" value="">
      <div style="display:flex;flex-direction:column;gap:14px">
        <div class="form-group"><label>Service</label><select class="form-control" id="service"><?php foreach($services as $s):?><option value="<?=$s?>"><?=ucwords(str_replace('_',' ',$s))?></option><?php endforeach;?></select></div>
        <div class="form-grid">
          <div class="form-group"><label>Network <span style="font-weight:400">(leave blank for all)</span></label><select class="form-control" id="network"><option value="">All Networks</option><option value="MTN">MTN</option><option value="GLO">GLO</option><option value="AIRTEL">AIRTEL</option><option value="9MOBILE">9MOBILE</option></select></div>
          <div class="form-group"><label>Data Type <span style="font-weight:400">(data service only)</span></label><input type="text" class="form-control" id="data_type" placeholder="e.g. SME, Corporate"></div>
        </div>
        <div class="form-group"><label>Provider Name</label><input type="text" class="form-control" id="provider_name" placeholder="e.g. Gladtidings, Autopilot"></div>
        <div class="form-group"><label>API Base URL</label><input type="text" class="form-control" id="api_base_url" placeholder="https://api.example.com"></div>
        <div class="form-grid">
          <div class="form-group"><label>API Key / Token</label><input type="password" class="form-control" id="api_key" placeholder="API key"></div>
          <div class="form-group"><label>API Secret <span style="font-weight:400">(optional)</span></label><input type="password" class="form-control" id="api_secret" placeholder="Secret if required"></div>
        </div>
        <div class="form-group"><label>Priority <span style="font-weight:400">(lower = higher priority)</span></label><input type="number" class="form-control" id="priority" value="1" min="1"></div>
        <div style="display:flex;justify-content:space-between;align-items:center"><label style="font-size:13px;font-weight:600">Active</label><label class="toggle"><input type="checkbox" id="is_active" checked><span class="slider"></span></label></div>
        <button class="btn-primary" style="width:100%;height:44px;justify-content:center" onclick="saveProvider()">Save Provider</button>
      </div>
    </div>
  </div>
</div>
<div class="save-toast" id="toast"></div>

<script>
const CSRF='<?=$csrf?>';
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
function openModal(){document.getElementById('modalTitle').textContent='Add API Provider';['pid','network','data_type','provider_name','api_base_url','api_key','api_secret'].forEach(id=>document.getElementById(id).value='');document.getElementById('priority').value=1;document.getElementById('is_active').checked=true;document.getElementById('modalOvl').style.display='flex';}
function editProvider(p){document.getElementById('modalTitle').textContent='Edit API Provider';document.getElementById('pid').value=p.id;document.getElementById('service').value=p.service;document.getElementById('network').value=p.network||'';document.getElementById('data_type').value=p.data_type||'';document.getElementById('provider_name').value=p.provider_name;document.getElementById('api_base_url').value=p.api_base_url;document.getElementById('api_key').value=p.api_key||'';document.getElementById('api_secret').value=p.api_secret||'';document.getElementById('priority').value=p.priority;document.getElementById('is_active').checked=p.is_active==1;document.getElementById('modalOvl').style.display='flex';}
function closeModal(){document.getElementById('modalOvl').style.display='none';}
async function saveProvider(){
  const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','save');fd.append('id',document.getElementById('pid').value);
  ['service','network','data_type','provider_name','api_base_url','api_key','api_secret','priority'].forEach(id=>fd.append(id,document.getElementById(id).value));
  if(document.getElementById('is_active').checked)fd.append('is_active','1');
  const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
  showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);
}
async function deleteProvider(id){if(!confirm('Delete this provider?'))return;const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','delete');fd.append('id',id);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),700);}
async function toggleProvider(id){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','toggle');fd.append('id',id);await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});}
function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),2500);}
document.getElementById('modalOvl').addEventListener('click',e=>{if(e.target===e.currentTarget)closeModal();});
</script>
</body></html>
