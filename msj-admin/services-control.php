<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    $action=$_POST['action']??'';
    if($action==='save_data_plan'){
        $id=(int)($_POST['id']??0);
        $f=['network'=>sanitize($_POST['network']??'',20),'network_id'=>(int)($_POST['network_id']??1),'plan_id'=>(int)($_POST['plan_id']??0),'plan_name'=>sanitize($_POST['plan_name']??'',200),'display_name'=>sanitize($_POST['display_name']??'',200)?:null,'data_type'=>sanitize($_POST['data_type']??'SME',50),'display_type_name'=>sanitize($_POST['display_type_name']??'',100)?:null,'data_size'=>sanitize($_POST['data_size']??'',50),'validity'=>sanitize($_POST['validity']??'',50),'cost_price'=>(float)($_POST['cost_price']??0),'selling_price'=>(float)($_POST['selling_price']??0),'is_active'=>isset($_POST['is_active'])?1:0];
        if($id){db()->prepare("UPDATE data_plans SET network=?,network_id=?,plan_id=?,plan_name=?,display_name=?,data_type=?,display_type_name=?,data_size=?,validity=?,cost_price=?,selling_price=?,is_active=? WHERE id=?")->execute([...array_values($f),$id]);}
        else{db()->prepare("INSERT INTO data_plans (network,network_id,plan_id,plan_name,display_name,data_type,display_type_name,data_size,validity,cost_price,selling_price,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")->execute(array_values($f));}
        json_response(['success'=>true,'message'=>'Data plan saved']);
    }
    if($action==='delete_data_plan'){db()->prepare("DELETE FROM data_plans WHERE id=?")->execute([(int)$_POST['id']]);json_response(['success'=>true,'message'=>'Deleted']);}
    if($action==='toggle_data_plan'){db()->prepare("UPDATE data_plans SET is_active=NOT is_active WHERE id=?")->execute([(int)$_POST['id']]);json_response(['success'=>true]);}

    if($action==='save_cable_plan'){
        $id=(int)($_POST['id']??0);
        $f=[sanitize($_POST['provider']??'',50),(int)($_POST['cable_id']??0),(int)($_POST['plan_id']??0),sanitize($_POST['plan_name']??'',200),sanitize($_POST['display_name']??'',200)?:null,(float)($_POST['cost_price']??0),(float)($_POST['selling_price']??0),isset($_POST['is_active'])?1:0];
        if($id){db()->prepare("UPDATE cable_plans SET provider=?,cable_id=?,plan_id=?,plan_name=?,display_name=?,cost_price=?,selling_price=?,is_active=? WHERE id=?")->execute([...$f,$id]);}
        else{db()->prepare("INSERT INTO cable_plans (provider,cable_id,plan_id,plan_name,display_name,cost_price,selling_price,is_active) VALUES (?,?,?,?,?,?,?,?)")->execute($f);}
        json_response(['success'=>true,'message'=>'Cable plan saved']);
    }
    if($action==='delete_cable_plan'){db()->prepare("DELETE FROM cable_plans WHERE id=?")->execute([(int)$_POST['id']]);json_response(['success'=>true,'message'=>'Deleted']);}

    if($action==='save_disco'){
        $id=(int)($_POST['id']??0);
        $f=[sanitize($_POST['disco_name']??'',100),(int)($_POST['disco_id']??0),sanitize($_POST['logo_key']??'',50),(float)($_POST['charge']??100),isset($_POST['is_active'])?1:0];
        if($id){db()->prepare("UPDATE electricity_discos SET disco_name=?,disco_id=?,logo_key=?,charge=?,is_active=? WHERE id=?")->execute([...$f,$id]);}
        else{db()->prepare("INSERT INTO electricity_discos (disco_name,disco_id,logo_key,charge,is_active) VALUES (?,?,?,?,?)")->execute($f);}
        json_response(['success'=>true,'message'=>'Disco saved']);
    }
    if($action==='save_pricing'){
        $id=(int)($_POST['id']??0);
        if($id){db()->prepare("UPDATE service_pricing SET selling_price=?,discount_percent=?,is_active=? WHERE id=?")->execute([(float)$_POST['selling_price'],(float)$_POST['discount_percent'],isset($_POST['is_active'])?1:0,$id]);json_response(['success'=>true,'message'=>'Pricing updated']);}
        json_response(['success'=>false,'message'=>'Missing ID']);
    }
    json_response(['success'=>false],400);
}
$csrf=admin_csrf();
$data_plans=db()->query("SELECT * FROM data_plans ORDER BY network,data_type,selling_price")->fetchAll();
$cable_plans=db()->query("SELECT * FROM cable_plans ORDER BY provider,selling_price")->fetchAll();
$discos=db()->query("SELECT * FROM electricity_discos ORDER BY disco_name")->fetchAll();
$pricing=db()->query("SELECT * FROM service_pricing ORDER BY service,network")->fetchAll();
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Services & Pricing – MsjPay Admin</title><link rel="icon" type="image/png" href="/msjpay/assets/images/msjpay-favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="/msjpay/msj-admin/css/admin.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="page-content">
<div class="page-head"><div><h2>Services & Pricing</h2><p>Manage data plans, cable bouquets, electricity discos, and pricing. Internal type values stay fixed for security — only the display label is editable.</p></div></div>

<div class="filter-tabs" style="margin-bottom:16px">
  <button class="filter-tab active" onclick="showTab('data',this)">Data Plans</button>
  <button class="filter-tab" onclick="showTab('cable',this)">Cable Plans</button>
  <button class="filter-tab" onclick="showTab('disco',this)">Electricity Discos</button>
  <button class="filter-tab" onclick="showTab('pricing',this)">Other Pricing</button>
</div>

<!-- DATA PLANS -->
<div id="tab-data" class="card">
  <div class="card-header"><h3>Data Plans</h3><button class="btn-sm btn-primary" onclick="openDataModal()">+ Add Plan</button></div>
  <div style="background:#FFF7ED;border-bottom:1px solid #FDE68A;padding:10px 18px;font-size:12px;color:#92400E">
    ⚠️ <strong>Internal Type</strong> (e.g. "SME") is used for backend validation and CANNOT be spoofed by users. <strong>Display Type Name</strong> is just a cosmetic label shown to users (e.g. rename "SME" to "Jaz-Prime").
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Network</th><th>Plan Name</th><th>Internal Type</th><th>Display Type</th><th>Size</th><th>Validity</th><th>Cost</th><th>Selling</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($data_plans as $p):?>
      <tr>
        <td><strong><?=$p['network']?></strong></td>
        <td><?=htmlspecialchars($p['plan_name'])?></td>
        <td><code><?=htmlspecialchars($p['data_type'])?></code></td>
        <td><?=htmlspecialchars($p['display_type_name']??'—')?></td>
        <td><?=htmlspecialchars($p['data_size'])?></td>
        <td><?=htmlspecialchars($p['validity']??'')?></td>
        <td>₦<?=number_format((float)$p['cost_price'],2)?></td>
        <td><strong>₦<?=number_format((float)$p['selling_price'],2)?></strong></td>
        <td><label class="toggle"><input type="checkbox" <?=$p['is_active']?'checked':''?> onchange="toggleDataPlan(<?=$p['id']?>)"><span class="slider"></span></label></td>
        <td style="white-space:nowrap"><button class="btn-sm btn-ghost" onclick='editDataPlan(<?=htmlspecialchars(json_encode($p),ENT_QUOTES)?>)'>Edit</button> <button class="btn-sm btn-danger" onclick="deleteDataPlan(<?=$p['id']?>)">Del</button></td>
      </tr>
      <?php endforeach;?>
      </tbody>
    </table>
  </div>
</div>

<!-- CABLE PLANS -->
<div id="tab-cable" class="card" style="display:none">
  <div class="card-header"><h3>Cable TV Plans</h3><button class="btn-sm btn-primary" onclick="openCableModal()">+ Add Plan</button></div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Provider</th><th>Plan Name</th><th>Display Name</th><th>Cost</th><th>Selling</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($cable_plans as $p):?>
      <tr>
        <td><strong><?=$p['provider']?></strong></td><td><?=htmlspecialchars($p['plan_name'])?></td><td><?=htmlspecialchars($p['display_name']??'—')?></td>
        <td>₦<?=number_format((float)$p['cost_price'],2)?></td><td><strong>₦<?=number_format((float)$p['selling_price'],2)?></strong></td>
        <td><span class="badge <?=$p['is_active']?'badge-success':'badge-neutral'?>"><?=$p['is_active']?'Active':'Inactive'?></span></td>
        <td style="white-space:nowrap"><button class="btn-sm btn-ghost" onclick='editCablePlan(<?=htmlspecialchars(json_encode($p),ENT_QUOTES)?>)'>Edit</button> <button class="btn-sm btn-danger" onclick="deleteCablePlan(<?=$p['id']?>)">Del</button></td>
      </tr>
      <?php endforeach;?>
      </tbody>
    </table>
  </div>
</div>

<!-- DISCOS -->
<div id="tab-disco" class="card" style="display:none">
  <div class="card-header"><h3>Electricity Discos</h3><button class="btn-sm btn-primary" onclick="openDiscoModal()">+ Add Disco</button></div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Disco Name</th><th>Disco ID</th><th>Logo Key</th><th>Charge</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($discos as $d):?>
      <tr><td><strong><?=htmlspecialchars($d['disco_name'])?></strong></td><td><?=$d['disco_id']?></td><td><?=htmlspecialchars($d['logo_key']??'')?></td><td>₦<?=number_format((float)$d['charge'],2)?></td><td><span class="badge <?=$d['is_active']?'badge-success':'badge-neutral'?>"><?=$d['is_active']?'Active':'Inactive'?></span></td><td><button class="btn-sm btn-ghost" onclick='editDisco(<?=htmlspecialchars(json_encode($d),ENT_QUOTES)?>)'>Edit</button></td></tr>
      <?php endforeach;?>
      </tbody>
    </table>
  </div>
</div>

<!-- OTHER PRICING -->
<div id="tab-pricing" class="card" style="display:none">
  <div class="card-header"><h3>Service Pricing (Airtime, A2C, Exam PIN, Transfer)</h3></div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Service</th><th>Network/Plan</th><th>Cost</th><th>Selling</th><th>Discount %</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($pricing as $p):?>
      <tr><td><?=ucwords(str_replace('_',' ',$p['service']))?></td><td><?=htmlspecialchars($p['network']??$p['plan_name']??'')?></td><td>₦<?=number_format((float)$p['cost_price'],2)?></td><td><strong>₦<?=number_format((float)$p['selling_price'],2)?></strong></td><td><?=$p['discount_percent']?>%</td><td><span class="badge <?=$p['is_active']?'badge-success':'badge-neutral'?>"><?=$p['is_active']?'Active':'Inactive'?></span></td><td><button class="btn-sm btn-ghost" onclick='editPricing(<?=htmlspecialchars(json_encode($p),ENT_QUOTES)?>)'>Edit</button></td></tr>
      <?php endforeach;?>
      </tbody>
    </table>
  </div>
</div>

</div></div>

<!-- DATA PLAN MODAL -->
<div class="modal-overlay" id="dataModal" style="display:none"><div class="modal">
<div class="modal-header"><h3 id="dataModalTitle">Add Data Plan</h3><button class="modal-close" onclick="document.getElementById('dataModal').style.display='none'">✕</button></div>
<div class="modal-body"><input type="hidden" id="d_id">
<div style="display:flex;flex-direction:column;gap:12px">
  <div class="form-grid"><div class="form-group"><label>Network</label><select class="form-control" id="d_network"><option>MTN</option><option>GLO</option><option>AIRTEL</option><option>9MOBILE</option></select></div><div class="form-group"><label>Network ID</label><input type="number" class="form-control" id="d_network_id" value="1"></div></div>
  <div class="form-group"><label>Plan ID (provider's plan code)</label><input type="number" class="form-control" id="d_plan_id"></div>
  <div class="form-group"><label>Plan Name (internal)</label><input type="text" class="form-control" id="d_plan_name" placeholder="e.g. MTN 1GB SME2"></div>
  <div class="form-group"><label>Display Name <span style="font-weight:400">(optional override)</span></label><input type="text" class="form-control" id="d_display_name" placeholder="What user sees instead"></div>
  <div class="form-grid">
    <div class="form-group"><label>Internal Type <span style="color:#EF4444">(fixed, used for validation)</span></label><select class="form-control" id="d_data_type"><option>SME</option><option>Corporate</option><option>Gifting</option><option>Promo</option><option>Night</option><option>Special</option><option>Social</option></select></div>
    <div class="form-group"><label>Display Type Name <span style="font-weight:400">(cosmetic label)</span></label><input type="text" class="form-control" id="d_display_type_name" placeholder="e.g. Jaz-Prime"></div>
  </div>
  <div class="form-grid"><div class="form-group"><label>Data Size</label><input type="text" class="form-control" id="d_data_size" placeholder="1GB"></div><div class="form-group"><label>Validity</label><input type="text" class="form-control" id="d_validity" placeholder="30 days"></div></div>
  <div class="form-grid"><div class="form-group"><label>Cost Price (₦)</label><input type="number" class="form-control" id="d_cost_price" step="0.01"></div><div class="form-group"><label>Selling Price (₦)</label><input type="number" class="form-control" id="d_selling_price" step="0.01"></div></div>
  <div style="display:flex;justify-content:space-between"><label style="font-size:13px;font-weight:600">Active</label><label class="toggle"><input type="checkbox" id="d_is_active" checked><span class="slider"></span></label></div>
  <button class="btn-primary" style="width:100%;justify-content:center" onclick="saveDataPlan()">Save Plan</button>
</div></div></div></div>

<!-- CABLE MODAL -->
<div class="modal-overlay" id="cableModal" style="display:none"><div class="modal">
<div class="modal-header"><h3 id="cableModalTitle">Add Cable Plan</h3><button class="modal-close" onclick="document.getElementById('cableModal').style.display='none'">✕</button></div>
<div class="modal-body"><input type="hidden" id="c_id">
<div style="display:flex;flex-direction:column;gap:12px">
  <div class="form-group"><label>Provider</label><select class="form-control" id="c_provider"><option>GOTV</option><option>DSTV</option><option>STARTIMES</option></select></div>
  <div class="form-grid"><div class="form-group"><label>Cable ID</label><input type="number" class="form-control" id="c_cable_id"></div><div class="form-group"><label>Plan ID</label><input type="number" class="form-control" id="c_plan_id"></div></div>
  <div class="form-group"><label>Plan Name</label><input type="text" class="form-control" id="c_plan_name"></div>
  <div class="form-group"><label>Display Name</label><input type="text" class="form-control" id="c_display_name"></div>
  <div class="form-grid"><div class="form-group"><label>Cost Price</label><input type="number" class="form-control" id="c_cost_price" step="0.01"></div><div class="form-group"><label>Selling Price</label><input type="number" class="form-control" id="c_selling_price" step="0.01"></div></div>
  <div style="display:flex;justify-content:space-between"><label style="font-size:13px;font-weight:600">Active</label><label class="toggle"><input type="checkbox" id="c_is_active" checked><span class="slider"></span></label></div>
  <button class="btn-primary" style="width:100%;justify-content:center" onclick="saveCablePlan()">Save Plan</button>
</div></div></div></div>

<!-- DISCO MODAL -->
<div class="modal-overlay" id="discoModal" style="display:none"><div class="modal">
<div class="modal-header"><h3 id="discoModalTitle">Add Disco</h3><button class="modal-close" onclick="document.getElementById('discoModal').style.display='none'">✕</button></div>
<div class="modal-body"><input type="hidden" id="x_id">
<div style="display:flex;flex-direction:column;gap:12px">
  <div class="form-group"><label>Disco Name</label><input type="text" class="form-control" id="x_disco_name"></div>
  <div class="form-grid"><div class="form-group"><label>Disco ID</label><input type="number" class="form-control" id="x_disco_id"></div><div class="form-group"><label>Logo Key</label><input type="text" class="form-control" id="x_logo_key" placeholder="ikeja"></div></div>
  <div class="form-group"><label>Service Charge (₦)</label><input type="number" class="form-control" id="x_charge" step="0.01"></div>
  <div style="display:flex;justify-content:space-between"><label style="font-size:13px;font-weight:600">Active</label><label class="toggle"><input type="checkbox" id="x_is_active" checked><span class="slider"></span></label></div>
  <button class="btn-primary" style="width:100%;justify-content:center" onclick="saveDisco()">Save Disco</button>
</div></div></div></div>

<!-- PRICING MODAL -->
<div class="modal-overlay" id="pricingModal" style="display:none"><div class="modal">
<div class="modal-header"><h3>Edit Pricing</h3><button class="modal-close" onclick="document.getElementById('pricingModal').style.display='none'">✕</button></div>
<div class="modal-body"><input type="hidden" id="pr_id">
<div style="display:flex;flex-direction:column;gap:12px">
  <div class="form-group"><label>Selling Price (₦)</label><input type="number" class="form-control" id="pr_selling_price" step="0.01"></div>
  <div class="form-group"><label>Discount % <span style="font-weight:400">(for A2C this is the rate %)</span></label><input type="number" class="form-control" id="pr_discount_percent" step="0.01"></div>
  <div style="display:flex;justify-content:space-between"><label style="font-size:13px;font-weight:600">Active</label><label class="toggle"><input type="checkbox" id="pr_is_active" checked><span class="slider"></span></label></div>
  <button class="btn-primary" style="width:100%;justify-content:center" onclick="savePricing()">Save</button>
</div></div></div></div>

<div class="save-toast" id="toast"></div>
<script>
const CSRF='<?=$csrf?>';
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
function showTab(t,btn){document.querySelectorAll('[id^="tab-"]').forEach(e=>e.style.display='none');document.getElementById('tab-'+t).style.display='block';document.querySelectorAll('.filter-tab').forEach(b=>b.classList.remove('active'));btn.classList.add('active');}

// DATA PLANS
function openDataModal(){document.getElementById('dataModalTitle').textContent='Add Data Plan';['d_id','d_plan_id','d_plan_name','d_display_name','d_display_type_name','d_data_size','d_validity','d_cost_price','d_selling_price'].forEach(i=>document.getElementById(i).value='');document.getElementById('d_network_id').value=1;document.getElementById('d_is_active').checked=true;document.getElementById('dataModal').style.display='flex';}
function editDataPlan(p){document.getElementById('dataModalTitle').textContent='Edit Data Plan';document.getElementById('d_id').value=p.id;document.getElementById('d_network').value=p.network;document.getElementById('d_network_id').value=p.network_id;document.getElementById('d_plan_id').value=p.plan_id;document.getElementById('d_plan_name').value=p.plan_name;document.getElementById('d_display_name').value=p.display_name||'';document.getElementById('d_data_type').value=p.data_type;document.getElementById('d_display_type_name').value=p.display_type_name||'';document.getElementById('d_data_size').value=p.data_size;document.getElementById('d_validity').value=p.validity||'';document.getElementById('d_cost_price').value=p.cost_price;document.getElementById('d_selling_price').value=p.selling_price;document.getElementById('d_is_active').checked=p.is_active==1;document.getElementById('dataModal').style.display='flex';}
async function saveDataPlan(){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','save_data_plan');['id','network','network_id','plan_id','plan_name','display_name','data_type','display_type_name','data_size','validity','cost_price','selling_price'].forEach(k=>fd.append(k,document.getElementById('d_'+k).value));if(document.getElementById('d_is_active').checked)fd.append('is_active','1');const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);}
async function deleteDataPlan(id){if(!confirm('Delete this plan?'))return;const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','delete_data_plan');fd.append('id',id);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),700);}
async function toggleDataPlan(id){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','toggle_data_plan');fd.append('id',id);await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});}

// CABLE PLANS
function openCableModal(){document.getElementById('cableModalTitle').textContent='Add Cable Plan';['c_id','c_cable_id','c_plan_id','c_plan_name','c_display_name','c_cost_price','c_selling_price'].forEach(i=>document.getElementById(i).value='');document.getElementById('c_is_active').checked=true;document.getElementById('cableModal').style.display='flex';}
function editCablePlan(p){document.getElementById('cableModalTitle').textContent='Edit Cable Plan';document.getElementById('c_id').value=p.id;document.getElementById('c_provider').value=p.provider;document.getElementById('c_cable_id').value=p.cable_id;document.getElementById('c_plan_id').value=p.plan_id;document.getElementById('c_plan_name').value=p.plan_name;document.getElementById('c_display_name').value=p.display_name||'';document.getElementById('c_cost_price').value=p.cost_price;document.getElementById('c_selling_price').value=p.selling_price;document.getElementById('c_is_active').checked=p.is_active==1;document.getElementById('cableModal').style.display='flex';}
async function saveCablePlan(){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','save_cable_plan');['id','provider','cable_id','plan_id','plan_name','display_name','cost_price','selling_price'].forEach(k=>fd.append(k,document.getElementById('c_'+k).value));if(document.getElementById('c_is_active').checked)fd.append('is_active','1');const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);}
async function deleteCablePlan(id){if(!confirm('Delete?'))return;const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','delete_cable_plan');fd.append('id',id);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),700);}

// DISCOS
function openDiscoModal(){document.getElementById('discoModalTitle').textContent='Add Disco';['x_id','x_disco_id','x_disco_name','x_logo_key','x_charge'].forEach(i=>document.getElementById(i).value='');document.getElementById('x_is_active').checked=true;document.getElementById('discoModal').style.display='flex';}
function editDisco(d){document.getElementById('discoModalTitle').textContent='Edit Disco';document.getElementById('x_id').value=d.id;document.getElementById('x_disco_name').value=d.disco_name;document.getElementById('x_disco_id').value=d.disco_id;document.getElementById('x_logo_key').value=d.logo_key||'';document.getElementById('x_charge').value=d.charge;document.getElementById('x_is_active').checked=d.is_active==1;document.getElementById('discoModal').style.display='flex';}
async function saveDisco(){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','save_disco');['id','disco_name','disco_id','logo_key','charge'].forEach(k=>fd.append(k,document.getElementById('x_'+k).value));if(document.getElementById('x_is_active').checked)fd.append('is_active','1');const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);}

// PRICING
function editPricing(p){document.getElementById('pr_id').value=p.id;document.getElementById('pr_selling_price').value=p.selling_price;document.getElementById('pr_discount_percent').value=p.discount_percent;document.getElementById('pr_is_active').checked=p.is_active==1;document.getElementById('pricingModal').style.display='flex';}
async function savePricing(){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','save_pricing');fd.append('id',document.getElementById('pr_id').value);fd.append('selling_price',document.getElementById('pr_selling_price').value);fd.append('discount_percent',document.getElementById('pr_discount_percent').value);if(document.getElementById('pr_is_active').checked)fd.append('is_active','1');const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);}

function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),2500);}
document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',e=>{if(e.target===e.currentTarget)e.currentTarget.style.display='none';}));
</script>
</body></html>
