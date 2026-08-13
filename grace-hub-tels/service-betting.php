<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
require_feature_admin('betting');
if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    require_permission('manage_pricing');
    $action=$_POST['action']??'';
    if($action==='save_platform'){
        $id=(int)($_POST['id']??0);
        foreach(['charge'=>(float)($_POST['charge']??0),'agent_charge'=>(float)($_POST['agent_charge']??0),'vendor_charge'=>(float)($_POST['vendor_charge']??0)] as $label=>$val){
            if($val<0)json_response(['success'=>false,'message'=>ucwords(str_replace('_',' ',$label)).' cannot be negative'],400);
        }
        $f=[sanitize($_POST['platform_name']??'',100),sanitize($_POST['provider_code']??'',100),(float)($_POST['charge']??0),(float)($_POST['agent_charge']??0),(float)($_POST['vendor_charge']??0),isset($_POST['is_active'])?1:0];
        if($id){db()->prepare("UPDATE betting_plans SET platform_name=?,provider_code=?,charge=?,agent_charge=?,vendor_charge=?,is_active=? WHERE id=?")->execute([...$f,$id]);}
        else{db()->prepare("INSERT INTO betting_plans (platform_name,provider_code,charge,agent_charge,vendor_charge,is_active) VALUES (?,?,?,?,?,?)")->execute($f);}
        json_response(['success'=>true,'message'=>'Betting platform saved']);
    }
    if($action==='delete_platform'){db()->prepare("DELETE FROM betting_plans WHERE id=?")->execute([(int)$_POST['id']]);json_response(['success'=>true,'message'=>'Deleted']);}
    if($action==='toggle_platform'){db()->prepare("UPDATE betting_plans SET is_active=NOT is_active WHERE id=?")->execute([(int)$_POST['id']]);json_response(['success'=>true]);}
    
    json_response(['success'=>false],400);
}
$csrf=admin_csrf();

// Ensure betting_plans exists
try {
    db()->exec("CREATE TABLE IF NOT EXISTS betting_plans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        platform_name VARCHAR(100) NOT NULL,
        provider_code VARCHAR(100) NOT NULL,
        charge DECIMAL(10,2) DEFAULT 0.00,
        agent_charge DECIMAL(10,2) DEFAULT 0.00,
        vendor_charge DECIMAL(10,2) DEFAULT 0.00,
        is_active TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (\Throwable $e) {}

$platforms=db()->query("SELECT * FROM betting_plans ORDER BY platform_name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Betting Platforms – <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title>
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
            <h2>Betting Platforms</h2>
            <p>Manage Betting Platforms and extra charges</p>
        </div>
    </div>

    <div class="data-panel" style="display:block;">
  <div class="panel-title-bar" style="border-bottom:1px solid #E2E8F0; padding-bottom:16px;">
      <h3><i class="fas fa-futbol" style="color:var(--primary);margin-right:8px"></i> Betting Platforms</h3>
      <button class="btn-primary" onclick="openPlatformModal()" style="font-size:13px; padding:8px 16px; border-radius:8px;">
          <i class="fas fa-plus"></i> Add Platform
      </button>
  </div>
  <div class="responsive-grid-wrapper">
    <table class="data-table">
      <thead><tr><th>Platform Name</th><th>Provider Code</th><th>Charge (₦)</th><th>Agent (₦)</th><th>Vendor (₦)</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($platforms as $p):?>
      <tr>
          <td><strong style="color:var(--navy-dark)"><?=htmlspecialchars($p['platform_name'])?></strong></td>
          <td><code style="background:#F1F5F9;padding:4px 6px;border-radius:4px;color:#475569;font-size:12px;font-weight:600;"><?=htmlspecialchars($p['provider_code'])?></code></td>
          <td style="font-size:14px; color:var(--success); font-weight:bold;">₦<?=number_format((float)$p['charge'],2)?></td>
          <td style="font-size:14px; color:var(--success); font-weight:bold;">₦<?=number_format((float)$p['agent_charge'],2)?></td>
          <td style="font-size:14px; color:var(--success); font-weight:bold;">₦<?=number_format((float)$p['vendor_charge'],2)?></td>
          <td>
            <label class="toggle" style="transform:scale(0.8); margin:0;">
                <input type="checkbox" <?=$p['is_active']?'checked':''?> onchange="togglePlatform(<?=$p['id']?>)">
                <span class="slider"></span>
            </label>
          </td>
          <td>
            <button class="btn-primary" style="background:var(--navy-light); padding:6px 12px; font-size:12px; border-radius:6px;" onclick='editPlatform(<?=htmlspecialchars(json_encode($p),ENT_QUOTES)?>)'><i class="fas fa-edit"></i> Edit</button>
            <button class="btn-primary" style="background:#ef4444; padding:6px 12px; font-size:12px; border-radius:6px; margin-left:8px;" onclick="deletePlatform(<?=$p['id']?>)"><i class="fas fa-trash"></i></button>
          </td>
      </tr>
      <?php endforeach;?>
      <?php if(empty($platforms)): ?>
      <tr><td colspan="7" style="text-align:center; padding:30px; color:#64748b;">No platforms configured. Add one to get started.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div>
</div>

<div class="modal-overlay" id="platformModal" style="display:none; align-items:center; justify-content:center;">
    <div class="modal-card" style="background:transparent; width:100%; max-width:500px; border-radius:8px; box-shadow:0 10px 25px rgba(0,0,0,0.15); overflow:hidden;">
        <div style="background:var(--primary); color:#fff; padding:16px 20px; font-weight:600; font-size:16px;">
            <span id="platformModalTitle">Add Platform</span>
        </div>
        <div class="modal-body" style="padding:24px; max-height:80vh; overflow-y:auto; background:#fff; display:flex; flex-direction:column; gap:16px;">
            <input type="hidden" id="p_id">
            <div class="form-group"><label>Platform Name</label><input type="text" class="form-control" id="p_platform_name" placeholder="e.g. Bet9ja"></div>
            <div class="form-group"><label>Provider Code (API mapped value)</label><input type="text" class="form-control" id="p_provider_code" placeholder="e.g. Bet9ja"></div>
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px;">
                <div class="form-group"><label>Charge</label><input type="number" class="form-control" id="p_charge" value="0"></div>
                <div class="form-group"><label>Agent Charge</label><input type="number" class="form-control" id="p_agent_charge" value="0"></div>
                <div class="form-group"><label>Vendor Charge</label><input type="number" class="form-control" id="p_vendor_charge" value="0"></div>
            </div>
            <div style="display:flex; align-items:center;">
                <label style="font-size:14px; font-weight:600; color:var(--navy-dark); margin-right:16px;">Active</label>
                <label class="toggle"><input type="checkbox" id="p_is_active" checked><span class="slider"></span></label>
            </div>
            <div style="display:flex; justify-content:space-between; margin-top:8px;">
                <button class="btn-primary" onclick="savePlatform()"><i class="fas fa-save" style="margin-right:6px;"></i> Save</button>
                <button class="btn-ghost" onclick="closePlatformModal()">CLOSE</button>
            </div>
        </div>
    </div>
</div>
<div class="save-toast" id="toast"></div>

<script>
const CSRF='<?=$csrf?>';
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
function showToast(m,s=true){const t=document.getElementById('toast');t.textContent=m;t.style.background=s?'#10B981':'#EF4444';t.classList.add('show');setTimeout(()=>t.classList.remove('show'),3000);}

function openPlatformModal(){
    document.getElementById('platformModalTitle').innerHTML='Add Betting Platform';
    ['p_id','p_platform_name','p_provider_code'].forEach(i=>document.getElementById(i).value='');
    ['p_charge','p_agent_charge','p_vendor_charge'].forEach(i=>document.getElementById(i).value='0');
    document.getElementById('p_is_active').checked=true;
    document.getElementById('platformModal').style.display='flex';
}
function editPlatform(p){
    document.getElementById('platformModalTitle').innerHTML='Edit Platform';
    document.getElementById('p_id').value=p.id;
    document.getElementById('p_platform_name').value=p.platform_name;
    document.getElementById('p_provider_code').value=p.provider_code;
    document.getElementById('p_charge').value=p.charge;
    document.getElementById('p_agent_charge').value=p.agent_charge;
    document.getElementById('p_vendor_charge').value=p.vendor_charge;
    document.getElementById('p_is_active').checked=p.is_active==1;
    document.getElementById('platformModal').style.display='flex';
}
let platformsChanged=false;
async function savePlatform(){
    const wasNew=!document.getElementById('p_id').value;
    const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','save_platform');
    ['id','platform_name','provider_code','charge','agent_charge','vendor_charge'].forEach(k=>{
        fd.append(k,document.getElementById('p_'+k).value);
    });
    if(document.getElementById('p_is_active').checked) fd.append('is_active','1');
    const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    showToast(r.message,r.success);
    if(r.success){
        if(wasNew){
            platformsChanged=true;
            openPlatformModal();
        } else {
            setTimeout(()=>location.reload(),700);
        }
    }
}
function closePlatformModal(){
    document.getElementById('platformModal').style.display='none';
    if(platformsChanged) location.reload();
}
async function deletePlatform(id){
    if(!confirm('Delete this platform?'))return;
    const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','delete_platform');fd.append('id',id);
    const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),700);
}
async function togglePlatform(id){
    const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','toggle_platform');fd.append('id',id);
    await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});
}
</script>
</body>
</html>
