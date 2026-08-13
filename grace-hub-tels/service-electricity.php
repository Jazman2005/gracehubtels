<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
require_feature_admin('electricity');
if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){

    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    require_permission('manage_pricing');
    $action=$_POST['action']??'';

    if($action==='save_disco'){
        // Plan IDs (per API provider) are managed separately on the Data Plan IDs
        // page via plan_api_bindings — this form no longer collects or touches them,
        // so an edit here can't wipe out mappings set up over there.
        $id=(int)($_POST['id']??0);
        foreach(['charge'=>(float)($_POST['charge']??100),'agent_charge'=>(float)($_POST['agent_charge']??0),'vendor_charge'=>(float)($_POST['vendor_charge']??0)] as $label=>$val){
            if($val<0)json_response(['success'=>false,'message'=>ucwords(str_replace('_',' ',$label)).' cannot be negative'],400);
        }
        // Blank means "no postpaid override" — falls back to the base charge
        // for postpaid too, same as before this field existed.
        $postpaid_raw=trim($_POST['postpaid_charge']??'');
        if($postpaid_raw!==''&&(float)$postpaid_raw<0)json_response(['success'=>false,'message'=>'Postpaid Charge cannot be negative'],400);
        $postpaid_charge=$postpaid_raw===''?null:(float)$postpaid_raw;
        $f=[sanitize($_POST['disco_name']??'',100),(int)($_POST['disco_id']??0),(float)($_POST['charge']??100),$postpaid_charge,(float)($_POST['agent_charge']??0),(float)($_POST['vendor_charge']??0),isset($_POST['is_active'])?1:0];
        if($id){db()->prepare("UPDATE electricity_discos SET disco_name=?,disco_id=?,charge=?,postpaid_charge=?,agent_charge=?,vendor_charge=?,is_active=? WHERE id=?")->execute([...$f,$id]);}
        else{db()->prepare("INSERT INTO electricity_discos (disco_name,disco_id,charge,postpaid_charge,agent_charge,vendor_charge,is_active,product_code) VALUES (?,?,?,?,?,?,?,NULL)")->execute($f);}
        json_response(['success'=>true,'message'=>'Disco saved']);
    }

    json_response(['success'=>false],400);
}
$csrf=admin_csrf();
$discos=db()->query("SELECT * FROM electricity_discos ORDER BY disco_name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Electricity Discos – <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title>
    <link rel="icon" type="image/png" href="/assets/images/favicon.png">
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
            <h2>Electricity Discos</h2>
            <p>Manage Electricity Discos</p>
        </div>
    </div>

    <div class="data-panel">
  <div class="panel-title-bar">
      <h3><i class="fas fa-lightbulb" style="color:var(--primary);margin-right:8px"></i> Electricity Discos</h3>
      <button class="btn-primary" onclick="openDiscoModal()" style="font-size:13px; padding:8px 16px; border-radius:8px;">
          <i class="fas fa-plus"></i> Add Disco
      </button>
  </div>
  <div class="responsive-grid-wrapper">
    <table class="data-table">
      <thead><tr><th>Disco Name</th><th>Disco ID</th><th>Product Code</th><th>Charge (₦)</th><th>Postpaid Charge (₦)</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($discos as $d):?>
      <tr>
          <td><strong style="color:var(--navy-dark)"><?=htmlspecialchars($d['disco_name'])?></strong></td>
          <td><code style="background:var(--bg);padding:4px 6px;border-radius:4px;color:var(--text-muted);font-size:12px;font-weight:600;"><?=$d['disco_id']?></code></td>
          <td><code style="background:rgba(217,119,6,0.1);padding:4px 6px;border-radius:4px;color:var(--warning);font-size:12px;font-weight:600;"><?=htmlspecialchars($d['product_code']??'')?></code></td>
          <td style="font-size:14px; color:var(--success); font-weight:bold;">₦<?=number_format((float)$d['charge'],2)?></td>
          <td style="font-size:14px; color:var(--text-muted); font-weight:bold;"><?=$d['postpaid_charge']!==null?'₦'.number_format((float)$d['postpaid_charge'],2):'<span style="font-weight:400;font-style:italic;">Same as Charge</span>'?></td>
          <td><span class="badge <?=$d['is_active']?'badge-success':'badge-neutral'?>"><?=$d['is_active']?'Active':'Inactive'?></span></td>
          <td>
            <button class="btn-primary" style="background:var(--navy-light); padding:6px 12px; font-size:12px; border-radius:6px;" onclick='editDisco(<?=htmlspecialchars(json_encode($d),ENT_QUOTES)?>)'><i class="fas fa-edit"></i> Edit</button>
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




<div class="modal-overlay" id="discoModal" style="display:none; align-items:center; justify-content:center;">
    <div class="modal-card" style="background:transparent; width:100%; max-width:600px; border-radius:8px; box-shadow:0 10px 25px rgba(0,0,0,0.15); overflow:hidden;">
        <div style="background:var(--primary); color:#fff; padding:16px 20px; font-weight:600; font-size:16px;">
            <span id="discoModalTitle">Add Disco</span>
        </div>
        <div class="modal-body" style="padding:24px; max-height:80vh; overflow-y:auto; background:var(--surface); display:flex; flex-direction:column; gap:16px;">
            <input type="hidden" id="x_id">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="form-group"><label>Disco Name</label><input type="text" class="form-control" id="x_disco_name" placeholder="Ikeja Electric"></div>
                <div class="form-group"><label>Disco ID</label><input type="text" class="form-control" id="x_disco_id"></div>
            </div>
            <div style="padding:10px 14px;background:rgba(37,99,235,0.08);border:1px solid rgba(37,99,235,0.2);border-radius:6px;font-size:12px;color:var(--info);">
                <i class="fas fa-circle-info" style="margin-right:6px;"></i> Provider Plan IDs are configured separately on the <a href="<?= ADMIN_URL ?>/data-plan-mapping" style="color:var(--info);font-weight:600;text-decoration:underline;">Plan ID Mapping</a> page after saving this disco.
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="form-group"><label>Base Charge (Prepaid)</label><input type="number" class="form-control" id="x_charge"></div>
                <div class="form-group"><label>Postpaid Charge</label><input type="number" class="form-control" id="x_postpaid_charge" placeholder="Leave blank to use Base Charge"></div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="form-group"><label>Agent Charge</label><input type="number" class="form-control" id="x_agent_charge"></div>
                <div class="form-group"><label>Vendor Charge</label><input type="number" class="form-control" id="x_vendor_charge"></div>
            </div>
            <div style="display:flex; align-items:center;">
                <label style="font-size:14px; font-weight:600; color:var(--navy-dark); margin-right:16px;">Active</label>
                <label class="toggle"><input type="checkbox" id="x_is_active" checked><span class="slider"></span></label>
            </div>
            <div style="display:flex; justify-content:space-between; margin-top:8px;">
                <button class="btn-primary" onclick="saveDisco()"><i class="fas fa-save" style="margin-right:6px;"></i> Save</button>
                <button class="btn-ghost" onclick="closeDiscoModal()">CLOSE</button>
            </div>
        </div>
    </div>
</div>
<div class="save-toast" id="toast"></div>
<script>
const CSRF='<?=$csrf?>';
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}

// DISCOS
function openDiscoModal(){document.getElementById('discoModalTitle').innerHTML='<i class="fas fa-plus"></i> Add Disco';['x_id','x_disco_id','x_disco_name','x_charge','x_postpaid_charge','x_agent_charge','x_vendor_charge'].forEach(i=>document.getElementById(i).value='');document.getElementById('x_is_active').checked=true;document.getElementById('discoModal').style.display='flex';}
function editDisco(d){
    document.getElementById('discoModalTitle').innerHTML='<i class="fas fa-edit"></i> Edit Disco';
    document.getElementById('x_id').value=d.id;document.getElementById('x_disco_name').value=d.disco_name;
    document.getElementById('x_disco_id').value=d.disco_id;
    document.getElementById('x_charge').value=d.charge;
    document.getElementById('x_postpaid_charge').value=(d.postpaid_charge===null||d.postpaid_charge===undefined)?'':d.postpaid_charge;
    document.getElementById('x_agent_charge').value=d.agent_charge||'0';document.getElementById('x_vendor_charge').value=d.vendor_charge||'0';
    document.getElementById('x_is_active').checked=d.is_active==1;
    document.getElementById('discoModal').style.display='flex';
}
let discosChanged=false;
async function saveDisco(){
    const wasNew=!document.getElementById('x_id').value;
    const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','save_disco');
    ['id','disco_name','disco_id','charge','postpaid_charge','agent_charge','vendor_charge'].forEach(k=>fd.append(k,document.getElementById('x_'+k).value));
    if(document.getElementById('x_is_active').checked)fd.append('is_active','1');
    const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    showToast(r.message,r.success);
    if(r.success){
        if(wasNew){
            discosChanged=true;
            openDiscoModal();
        } else {
            setTimeout(()=>location.reload(),900);
        }
    }
}
function closeDiscoModal(){
    document.getElementById('discoModal').style.display='none';
    if(discosChanged) location.reload();
}

function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),2500);}
</script>

</body>
</html>