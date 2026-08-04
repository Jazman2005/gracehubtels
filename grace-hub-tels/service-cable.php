<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){

    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    $action=$_POST['action']??'';

    if($action==='save_cable_plan'){
        $id=(int)($_POST['id']??0);
        $f=[sanitize($_POST['provider']??'',50),(int)($_POST['cable_id']??0),sanitize($_POST['plan_id']??'',100),sanitize($_POST['plan_name']??'',200),sanitize($_POST['display_name']??'',200)?:null,(float)($_POST['cost_price']??0),(float)($_POST['selling_price']??0),(float)($_POST['agent_price']??0),(float)($_POST['vendor_price']??0),isset($_POST['is_active'])?1:0];
        if($id){db()->prepare("UPDATE cable_plans SET provider=?,cable_id=?,plan_id=?,plan_name=?,display_name=?,cost_price=?,selling_price=?,agent_price=?,vendor_price=?,is_active=? WHERE id=?")->execute([...$f,$id]);}
        else{db()->prepare("INSERT INTO cable_plans (provider,cable_id,plan_id,plan_name,display_name,cost_price,selling_price,agent_price,vendor_price,is_active) VALUES (?,?,?,?,?,?,?,?,?,?)")->execute($f);$id=(int)db()->lastInsertId();}
        // Save plan API bindings
        db()->prepare("DELETE FROM plan_api_bindings WHERE service='cabletv' AND plan_id=?")->execute([$id]);
        foreach(['backup_1'=>2,'backup_2'=>3,'backup_3'=>4,'backup_4'=>5] as $key=>$prio){
            $reg_id=(int)($_POST['api_registry_'.$key]??0);
            $code=sanitize($_POST['api_code_'.$key]??'',100);
            if($reg_id){
                db()->prepare("INSERT INTO plan_api_bindings (service,plan_id,api_registry_id,priority,product_code) VALUES ('cabletv',?,?,?,?)")->execute([$id,$reg_id,$prio,$code?:null]);
            }
        }
        json_response(['success'=>true,'message'=>'Cable plan saved']);
    }

    if($action==='delete_cable_plan'){
        db()->prepare("DELETE FROM cable_plans WHERE id=?")->execute([(int)$_POST['id']]);
        json_response(['success'=>true,'message'=>'Deleted']);
    }

    if($action==='bulk_pricing_cable'){
        $b_provider = sanitize($_POST['provider']??'',50);
        $markup_user = (float)($_POST['markup_user']??0);
        $markup_agent = (float)($_POST['markup_agent']??0);
        $markup_vendor = (float)($_POST['markup_vendor']??0);
        
        $sql = "UPDATE cable_plans SET 
                selling_price = CEILING(cost_price + (cost_price * (? / 100))),
                agent_price = CEILING(cost_price + (cost_price * (? / 100))),
                vendor_price = CEILING(cost_price + (cost_price * (? / 100)))
                WHERE provider=?";
        db()->prepare($sql)->execute([$markup_user, $markup_agent, $markup_vendor, $b_provider]);
        json_response(['success'=>true, 'message'=>"Bulk pricing applied successfully for $b_provider"]);
    }

    if($action==='get_plan_bindings'){
        $pid=(int)($_POST['plan_id']??0); $svc=sanitize($_POST['service']??'cabletv',50);
        $b_stmt=db()->prepare("SELECT b.*,r.name as api_name FROM plan_api_bindings b LEFT JOIN api_registry r ON r.id=b.api_registry_id WHERE b.service=? AND b.plan_id=? ORDER BY b.priority");
        $b_stmt->execute([$svc,$pid]);
        json_response(['success'=>true,'bindings'=>$b_stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    json_response(['success'=>false],400);
}
$csrf=admin_csrf();
$cable_plans=db()->query("SELECT * FROM cable_plans ORDER BY provider,selling_price")->fetchAll();
$api_regs = db()->query("SELECT id, name FROM api_registry WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Cable TV – Gracehubtels Admin</title>
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
            <h2>Cable TV</h2>
            <p>Manage Cable TV Packages</p>
        </div>
    </div>

    <div class="data-panel" style="display:block;">
  <div class="panel-title-bar" style="border-bottom:1px solid #E2E8F0; padding-bottom:16px; display:flex; align-items:center;">
      <h3><i class="fas fa-tv" style="color:var(--primary);margin-right:8px"></i> Cable TV Plans</h3>
      <div style="margin-left:auto; display:flex; gap:8px;">
          <button class="btn-primary" onclick="openBulkPricingCable()" style="background:#fff; color:var(--primary); border:1px solid var(--primary); font-size:13px; padding:8px 16px; border-radius:8px;">
              <i class="fas fa-percent"></i> Bulk Pricing
          </button>
          <button class="btn-primary" onclick="openCableModal()" style="font-size:13px; padding:8px 16px; border-radius:8px;">
              <i class="fas fa-plus"></i> Add Plan
          </button>
      </div>
  </div>
  <div class="responsive-grid-wrapper">
    <table class="data-table">
      <thead><tr><th>Provider</th><th>Plan Name</th><th>Display Name</th><th>Cost</th><th>Selling</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($cable_plans as $p):?>
      <tr>
        <td><strong style="color:var(--navy-dark)"><?=$p['provider']?></strong></td>
        <td style="font-weight:600; font-size:13px;"><?=htmlspecialchars($p['plan_name'])?></td>
        <td style="color:var(--primary); font-weight:600; font-size:13px;"><?=htmlspecialchars($p['display_name']??'—')?></td>
        <td style="font-size:13px; color:var(--danger);">₦<?=number_format((float)$p['cost_price'],2)?></td>
        <td style="font-size:14px; color:var(--success); font-weight:bold;">₦<?=number_format((float)$p['selling_price'],2)?></td>
        <td><span class="badge <?=$p['is_active']?'badge-success':'badge-neutral'?>"><?=$p['is_active']?'Active':'Inactive'?></span></td>
        <td style="white-space:nowrap">
            <button class="btn-primary" style="background:var(--navy-light); padding:6px 12px; font-size:12px; border-radius:6px; margin-right:4px;" onclick='editCablePlan(<?=htmlspecialchars(json_encode($p),ENT_QUOTES)?>)'><i class="fas fa-edit"></i></button>
            <button class="btn-primary" style="background:var(--danger); padding:6px 12px; font-size:12px; border-radius:6px;" onclick="deleteCablePlan(<?=$p['id']?>)"><i class="fas fa-trash"></i></button>
        </td>
      </tr>
      <?php endforeach;?>
      </tbody>
    </table>
  </div>
</div>


</div>

</div>
</div>




<div class="modal-overlay" id="cableModal" style="display:none; align-items:center; justify-content:center;">
    <div class="modal-card" style="background:transparent; width:100%; max-width:600px; border-radius:8px; box-shadow:0 10px 25px rgba(0,0,0,0.15); overflow:hidden;">
        <div style="background:var(--primary); color:#fff; padding:16px 20px; font-weight:600; font-size:16px;">
            <span id="cableModalTitle">Add Cable Plan</span>
        </div>
        <div class="modal-body" style="padding:24px; max-height:80vh; overflow-y:auto; background:#fff; display:flex; flex-direction:column; gap:16px;">
            <input type="hidden" id="c_id">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="form-group"><label>Provider</label><input type="text" class="form-control" id="c_provider" placeholder="DSTV"></div>
                <div class="form-group"><label>Cable ID</label><input type="text" class="form-control" id="c_cable_id"></div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="form-group"><label>Plan ID</label><input type="text" class="form-control" id="c_plan_id"></div>
                <div class="form-group"><label>Plan Name</label><input type="text" class="form-control" id="c_plan_name"></div>
            </div>
            <div class="form-group"><label>Display Name</label><input type="text" class="form-control" id="c_display_name"></div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="form-group"><label>Cost Price</label><input type="number" class="form-control" id="c_cost_price"></div>
                <div class="form-group"><label>Selling Price</label><input type="number" class="form-control" id="c_selling_price"></div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="form-group"><label>Agent Price</label><input type="number" class="form-control" id="c_agent_price"></div>
                <div class="form-group"><label>Vendor Price</label><input type="number" class="form-control" id="c_vendor_price"></div>
            </div>
            <div style="display:flex; align-items:center;">
                <label style="font-size:14px; font-weight:600; color:var(--navy-dark); margin-right:16px;">Active</label>
                <label class="toggle"><input type="checkbox" id="c_is_active" checked><span class="slider"></span></label>
            </div>
            <div style="border-top:1px solid #E2E8F0; padding-top:16px;">
                <h5 style="margin:0 0 12px; font-size:14px; color:#334155;"><i class="fas fa-plug" style="color:var(--primary);margin-right:6px;"></i> Backup APIs (failover) <span style="font-weight:400;font-size:11px;color:#94A3B8;">(optional — tried in order if primary fails)</span> <span style="font-weight:400;font-size:11px;color:#64748B;display:block;margin-top:4px;">The system plan ID at the top is used as primary. These backups are tried when the primary API returns insufficient balance.</span></h5>
                <?php $api_keys=['backup_1'=>['Backup 1',2],'backup_2'=>['Backup 2',3],'backup_3'=>['Backup 3',4],'backup_4'=>['Backup 4',5]]; foreach($api_keys as $key=>$label): ?>
                <div style="display:grid; grid-template-columns:2fr 1fr; gap:12px; margin-bottom:8px;">
                    <div class="form-group"><label><?=$label[0]?></label>
                        <select class="form-control" id="c_api_registry_<?=$key?>">
                            <option value="">-- Default (provider loop) --</option>
                            <?php foreach($api_regs as $reg):?>
                            <option value="<?=$reg['id']?>"><?=htmlspecialchars($reg['name'])?></option>
                            <?php endforeach;?>
                        </select>
                    </div>
                    <div class="form-group"><label>Plan ID</label><input type="text" class="form-control" id="c_api_code_<?=$key?>" placeholder="e.g. DSTV-PREMIUM"></div>
                </div>
                <?php endforeach; ?>
            </div>
            <div style="display:flex; justify-content:space-between; margin-top:8px;">
                <button class="btn-primary" onclick="saveCablePlan()"><i class="fas fa-save" style="margin-right:6px;"></i> Save</button>
                <button class="btn-ghost" onclick="document.getElementById('cableModal').style.display='none'">CLOSE</button>
            </div>
        </div>
    </div>
</div>

<div class="modal-overlay" id="bulkPricingCableModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,.5); z-index:999; align-items:center; justify-content:center;">
  <div class="modal-card" style="background:#fff; width:95%; max-width:500px; border-radius:8px; box-shadow:0 10px 25px rgba(0,0,0,0.15); overflow:hidden; position:relative;">
    <div style="background:var(--primary); color:#fff; padding:16px 20px; display:flex; justify-content:space-between; align-items:center; font-weight:600; font-size:16px;">
      <span style="margin:0;"><i class="fas fa-tags" style="margin-right:8px"></i> Bulk Set Cable Prices</span>
      <button onclick="document.getElementById('bulkPricingCableModal').style.display='none'" style="background:none; border:none; color:#fff; cursor:pointer; font-size:16px; padding:4px;"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" style="padding:20px; max-height:80vh; overflow-y:auto; display:flex; flex-direction:column; gap:16px;">
      
      <div style="font-size:13px; color:var(--text-muted); line-height:1.5;">
          Select a provider, then enter the <b>percentage markup</b> you want to apply on top of the <b>Cost Price</b>.
      </div>
      
      <?php
      $providers = [];
      foreach($cable_plans as $p) {
          if(!in_array($p['provider'], $providers)) $providers[] = $p['provider'];
      }
      ?>
      <div class="form-group">
        <label>Select Provider</label>
        <select class="form-control" id="bpc_provider">
            <option value="">-- Choose --</option>
            <?php foreach($providers as $prov):?>
            <option value="<?=htmlspecialchars($prov)?>"><?=htmlspecialchars($prov)?></option>
            <?php endforeach;?>
        </select>
      </div>

      <div class="form-group">
        <label>User Tier Markup (%)</label>
        <input type="number" step="any" class="form-control" id="bpc_markup_user" placeholder="e.g. 10">
      </div>
      <div class="form-group">
        <label>Agent Tier Markup (%)</label>
        <input type="number" step="any" class="form-control" id="bpc_markup_agent" placeholder="e.g. 5">
      </div>
      <div class="form-group">
        <label>Vendor Tier Markup (%)</label>
        <input type="number" step="any" class="form-control" id="bpc_markup_vendor" placeholder="e.g. 2">
      </div>
      
      <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:8px;">
        <button class="btn-ghost" onclick="document.getElementById('bulkPricingCableModal').style.display='none'" style="border:1px solid var(--border); padding:8px 16px;">Cancel</button>
        <button class="btn-primary" onclick="applyBulkPricingCable()" style="padding:8px 16px;"><i class="fas fa-check"></i> Apply Pricing</button>
      </div>
    </div>
  </div>
</div>

<div class="save-toast" id="toast"></div>
<script>
const CSRF='<?=$csrf?>';
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
function showTab(t,btn){
    document.querySelectorAll('[id^="tab-"]').forEach(e=>e.style.display='none');
    document.getElementById('tab-'+t).style.display='block';
    document.querySelectorAll('.filter-tab').forEach(b=>b.classList.remove('active'));
    if(btn) btn.classList.add('active');
    else {
        // If btn not passed, find the tab and activate it
        const tabs = document.querySelectorAll('.filter-tab');
        if(t==='data') tabs[0].classList.add('active');
        if(t==='cable') tabs[1].classList.add('active');
        if(t==='disco') tabs[2].classList.add('active');
        if(t==='pricing') tabs[3].classList.add('active');
    }
}

document.addEventListener("DOMContentLoaded", () => {
    const activeTab = sessionStorage.getItem('serviceTab');
    if (activeTab) {
        showTab(activeTab, null);
        sessionStorage.removeItem('serviceTab'); // Clear it so it doesn't stick forever
    }
});

// DATA PLANS
function openDataModal(){
    document.getElementById('dataModalTitle').innerHTML='Add New Plan';
    ['d_id','d_plan_id','d_plan_name','d_data_type','d_validity','d_cost_price','d_selling_price','d_agent_price','d_vendor_price','d_network'].forEach(i=>{if(document.getElementById(i))document.getElementById(i).value=''});
    document.getElementById('dataModal').style.display='flex';
}
function editDataPlan(p){
    document.getElementById('dataModalTitle').innerHTML='Edit Data Plan';
    document.getElementById('d_id').value=p.id;
    document.getElementById('d_network').value=p.network;
    document.getElementById('d_plan_id').value=p.plan_id;
    document.getElementById('d_plan_name').value=p.plan_name;
    document.getElementById('d_data_type').value=p.data_type;
    document.getElementById('d_validity').value=p.validity||'';
    document.getElementById('d_cost_price').value=p.cost_price;
    document.getElementById('d_selling_price').value=p.selling_price;
    document.getElementById('d_agent_price').value=p.agent_price||'0';
    document.getElementById('d_vendor_price').value=p.vendor_price||'0';
    document.getElementById('dataModal').style.display='flex';
}
async function saveDataPlan(){
    const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','save_data_plan');
    ['id','network','network_id','plan_id','plan_name','display_name','data_type','display_type_name','data_size','validity','cost_price','selling_price','agent_price','vendor_price'].forEach(k=>{
        if(document.getElementById('d_'+k)) fd.append(k,document.getElementById('d_'+k).value);
    });
    fd.append('is_active','1');
    const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);
}
async function deleteDataPlan(id){if(!confirm('Delete this plan?'))return;const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','delete_data_plan');fd.append('id',id);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),700);}
async function toggleDataPlan(id){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','toggle_data_plan');fd.append('id',id);await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});}

// CABLE PLANS
function openCableModal(){
    document.getElementById('cableModalTitle').innerHTML='<i class="fas fa-plus"></i> Add Cable Plan';
    ['c_id','c_cable_id','c_plan_id','c_plan_name','c_display_name','c_cost_price','c_selling_price','c_agent_price','c_vendor_price'].forEach(i=>document.getElementById(i).value='');
    document.getElementById('c_is_active').checked=true;
    ['backup_1','backup_2','backup_3','backup_4'].forEach(function(k){
        const sel=document.getElementById('c_api_registry_'+k);
        if(sel) sel.value='';
        const inp=document.getElementById('c_api_code_'+k);
        if(inp) inp.value='';
    });
    document.getElementById('cableModal').style.display='flex';
}
async function loadPlanBindings(svc, pid){
    const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','get_plan_bindings');fd.append('service',svc);fd.append('plan_id',pid);
    try {
        const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
        if(!r.bindings) return;
        r.bindings.forEach(function(b){
            if(b.priority<2) return;
            const key=['','','backup_1','backup_2','backup_3','backup_4'][b.priority];
            if(!key) return;
            const sel=document.getElementById('c_api_registry_'+key);
            if(sel) sel.value=b.api_registry_id||'';
            const inp=document.getElementById('c_api_code_'+key);
            if(inp) inp.value=b.product_code||'';
        });
    } catch(e){}
}
function editCablePlan(p){
    document.getElementById('cableModalTitle').innerHTML='<i class="fas fa-edit"></i> Edit Cable Plan';
    document.getElementById('c_id').value=p.id;
    document.getElementById('c_provider').value=p.provider;
    document.getElementById('c_cable_id').value=p.cable_id;
    document.getElementById('c_plan_id').value=p.plan_id;
    document.getElementById('c_plan_name').value=p.plan_name;
    document.getElementById('c_display_name').value=p.display_name||'';
    document.getElementById('c_cost_price').value=p.cost_price;
    document.getElementById('c_selling_price').value=p.selling_price;
    document.getElementById('c_agent_price').value=p.agent_price||'0';
    document.getElementById('c_vendor_price').value=p.vendor_price||'0';
    document.getElementById('c_is_active').checked=p.is_active==1;
    document.getElementById('cableModal').style.display='flex';
    ['backup_1','backup_2','backup_3','backup_4'].forEach(function(k){
        const sel=document.getElementById('c_api_registry_'+k); if(sel) sel.value='';
        const inp=document.getElementById('c_api_code_'+k); if(inp) inp.value='';
    });
    loadPlanBindings('cabletv', p.id);
}
async function saveCablePlan(){
    const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','save_cable_plan');
    ['id','provider','cable_id','plan_id','plan_name','display_name','cost_price','selling_price','agent_price','vendor_price'].forEach(k=>fd.append(k,document.getElementById('c_'+k).value));
    if(document.getElementById('c_is_active').checked)fd.append('is_active','1');
    ['backup_1','backup_2','backup_3','backup_4'].forEach(function(k){
        const sel=document.getElementById('c_api_registry_'+k);
        const inp=document.getElementById('c_api_code_'+k);
        if(sel) fd.append('api_registry_'+k, sel.value);
        if(inp) fd.append('api_code_'+k, inp.value);
    });
    const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);}
async function deleteCablePlan(id){if(!confirm('Delete?'))return;const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','delete_cable_plan');fd.append('id',id);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),700);}

function openBulkPricingCable() {
    document.getElementById('bpc_provider').value = '';
    document.getElementById('bpc_markup_user').value = '';
    document.getElementById('bpc_markup_agent').value = '';
    document.getElementById('bpc_markup_vendor').value = '';
    document.getElementById('bulkPricingCableModal').style.display = 'flex';
}

async function applyBulkPricingCable() {
    const prov = document.getElementById('bpc_provider').value;
    if(!prov) return alert('Please select a provider first.');
    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('action', 'bulk_pricing_cable');
    fd.append('provider', prov);
    fd.append('markup_user', document.getElementById('bpc_markup_user').value || 0);
    fd.append('markup_agent', document.getElementById('bpc_markup_agent').value || 0);
    fd.append('markup_vendor', document.getElementById('bpc_markup_vendor').value || 0);
    
    if(!confirm('Are you sure you want to apply this percentage markup to ALL ' + prov + ' plans? This will overwrite their current selling prices based on their Cost Price.')) return;
    
    const r = await fetch(location.href, {method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: fd}).then(r => r.json());
    showToast(r.message, r.success);
    if(r.success) setTimeout(() => location.reload(), 900);
}

// DISCOS
function openDiscoModal(){document.getElementById('discoModalTitle').innerHTML='<i class="fas fa-plus"></i> Add Disco';['x_id','x_disco_id','x_disco_name','x_logo_key','x_charge','x_agent_charge','x_vendor_charge'].forEach(i=>document.getElementById(i).value='');document.getElementById('x_is_active').checked=true;document.getElementById('discoModal').style.display='flex';}
function editDisco(d){document.getElementById('discoModalTitle').innerHTML='<i class="fas fa-edit"></i> Edit Disco';document.getElementById('x_id').value=d.id;document.getElementById('x_disco_name').value=d.disco_name;document.getElementById('x_disco_id').value=d.disco_id;document.getElementById('x_logo_key').value=d.logo_key||'';document.getElementById('x_charge').value=d.charge;document.getElementById('x_agent_charge').value=d.agent_charge||'0';document.getElementById('x_vendor_charge').value=d.vendor_charge||'0';document.getElementById('x_is_active').checked=d.is_active==1;document.getElementById('discoModal').style.display='flex';}
async function saveDisco(){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','save_disco');['id','disco_name','disco_id','logo_key','charge','agent_charge','vendor_charge'].forEach(k=>fd.append(k,document.getElementById('x_'+k).value));if(document.getElementById('x_is_active').checked)fd.append('is_active','1');const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);}

// PRICING
function editPricing(p){document.getElementById('pr_id').value=p.id;document.getElementById('pr_selling_price').value=p.selling_price;document.getElementById('pr_discount_percent').value=p.discount_percent;document.getElementById('pr_agent_price').value=p.agent_price||'0';document.getElementById('pr_vendor_price').value=p.vendor_price||'0';document.getElementById('pr_agent_discount').value=p.agent_discount||'0';document.getElementById('pr_vendor_discount').value=p.vendor_discount||'0';document.getElementById('pr_is_active').checked=p.is_active==1;document.getElementById('pricingModal').style.display='flex';}
async function savePricing(){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','save_pricing');fd.append('id',document.getElementById('pr_id').value);fd.append('selling_price',document.getElementById('pr_selling_price').value);fd.append('discount_percent',document.getElementById('pr_discount_percent').value);fd.append('agent_price',document.getElementById('pr_agent_price').value);fd.append('vendor_price',document.getElementById('pr_vendor_price').value);fd.append('agent_discount',document.getElementById('pr_agent_discount').value);fd.append('vendor_discount',document.getElementById('pr_vendor_discount').value);if(document.getElementById('pr_is_active').checked)fd.append('is_active','1');const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);}

function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),2500);}
</script>

</body>
</html>