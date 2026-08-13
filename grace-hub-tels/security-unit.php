<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
require_permission('manage_security');

$SERVICES = [
    'airtime'       => ['label' => 'Airtime VTU',      'icon' => 'fa-phone-alt'],
    'data'          => ['label' => 'Data Bundles',      'icon' => 'fa-wifi'],
    'electricity'   => ['label' => 'Electricity Bill',  'icon' => 'fa-bolt'],
    'cabletv'       => ['label' => 'Cable TV',          'icon' => 'fa-tv'],
    'exam_pin'      => ['label' => 'Exam PIN',           'icon' => 'fa-graduation-cap'],
    'recharge_card' => ['label' => 'Recharge Card',      'icon' => 'fa-credit-card'],
    'data_card'     => ['label' => 'Data Card',         'icon' => 'fa-sim-card'],
    'airtime_to_cash'=>['label' => 'Airtime to Cash',   'icon' => 'fa-exchange-alt'],
    'bank_transfer'  =>['label' => 'Bank Transfer',     'icon' => 'fa-university'],
    'bulk_sms'      => ['label' => 'Bulk SMS',          'icon' => 'fa-sms'],
    'virtual_card'  => ['label' => 'Virtual Card',      'icon' => 'fa-id-card'],
];

if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    $action = $_POST['action'] ?? '';

    if($action === 'save_toggles'){
        $keys = ['login_enabled','registration_enabled','all_services_enabled'];
        foreach($SERVICES as $sk=>$sv){$keys[]='service_'.$sk.'_enabled';}
        foreach($keys as $k){
            setting_set($k, empty($_POST[$k]) ? '0' : '1');
        }
        audit_log($_SESSION['admin_id'], 'UPDATE_SECURITY_TOGGLES', 'Updated all security/service toggles');
        json_response(['success'=>true,'message'=>'All toggles updated successfully']);
    }

    if($action === 'add_blacklist'){
        $type = in_array($_POST['type']??'',['ip','phone']) ? $_POST['type'] : 'ip';
        $val = sanitize($_POST['value']??'', 100);
        $reason = sanitize($_POST['reason']??'', 500);
        if(!$val) json_response(['success'=>false,'message'=>'Value is required']);
        try {
            db()->prepare("INSERT INTO blacklists (type, value, reason) VALUES (?, ?, ?)")->execute([$type, $val, $reason]);
            audit_log($_SESSION['admin_id'], 'ADD_BLACKLIST', "Added $type to blacklist: $val");
            json_response(['success'=>true,'message'=>'Added to blacklist successfully']);
        } catch(PDOException $e) {
            json_response(['success'=>false,'message'=>'This value is already blacklisted']);
        }
    }

    if($action === 'remove_blacklist'){
        $id = (int)$_POST['id'];
        db()->prepare("DELETE FROM blacklists WHERE id=?")->execute([$id]);
        audit_log($_SESSION['admin_id'], 'REMOVE_BLACKLIST', "Removed ID $id from blacklist");
        json_response(['success'=>true,'message'=>'Removed from blacklist']);
    }
}

$csrf=admin_csrf();
function get_sec_set($key){$s=db()->prepare("SELECT setting_value FROM site_settings WHERE setting_key=?");$s->execute([$key]);return $s->fetchColumn()?:'0';}
$login_enabled = get_sec_set('login_enabled') == '1';
$registration_enabled = get_sec_set('registration_enabled') == '1';
$all_services_enabled = get_sec_set('all_services_enabled') == '1';
$service_states = [];
foreach($SERVICES as $sk=>$sv){$service_states[$sk]=get_sec_set('service_'.$sk.'_enabled')=='1';}
$blacklists = db()->query("SELECT * FROM blacklists ORDER BY id DESC")->fetchAll();
?><!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Security Unit – <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title><link rel="icon" type="image/png" href="/assets/images/favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header"><div><h2><i class="fas fa-shield-halved" style="color:var(--primary);margin-right:10px"></i>Security Unit</h2><p>Platform-wide emergency switches and service toggles</p></div></div>

<div class="row-2col" style="align-items:start">
<div style="display:flex;flex-direction:column;gap:24px">

<div class="data-panel">
<div class="panel-title-bar"><h3><i class="fas fa-power-off" style="color:var(--danger)"></i> Global Toggles</h3></div>
<div style="padding:20px 24px">
<div class="flex items-center justify-between" style="padding:14px 0;border-bottom:1px solid var(--border)">
<div><div class="font-bold" style="font-size:14px">User Logins</div><div class="text-xs text-muted" style="margin-top:2px">Allow users to log into their dashboard.</div></div>
<label class="toggle"><input type="checkbox" id="login_enabled" <?=$login_enabled?'checked':''?>><span class="slider"></span></label>
</div>
<div class="flex items-center justify-between" style="padding:14px 0;border-bottom:1px solid var(--border)">
<div><div class="font-bold" style="font-size:14px">New Registrations</div><div class="text-xs text-muted" style="margin-top:2px">Allow new users to sign up.</div></div>
<label class="toggle"><input type="checkbox" id="registration_enabled" <?=$registration_enabled?'checked':''?>><span class="slider"></span></label>
</div>
<div class="flex items-center justify-between" style="padding:14px 0;border-bottom:1px solid var(--border)">
<div><div class="font-bold" style="font-size:14px">ALL VTU Services</div><div class="text-xs text-muted" style="margin-top:2px">Master switch — overrides all service toggles below.</div></div>
<label class="toggle"><input type="checkbox" id="all_services_enabled" <?=$all_services_enabled?'checked':''?>><span class="slider"></span></label>
</div>

<div style="margin-top:20px">
<div class="text-xs font-bold text-muted" style="text-transform:uppercase;letter-spacing:0.8px;margin-bottom:14px">Individual Services</div>
<?php foreach($SERVICES as $sk=>$sv): $checked=$service_states[$sk]; ?>
<div class="flex items-center justify-between" style="padding:10px 0;border-bottom:1px solid var(--border-light)">
<div class="flex items-center gap-3" style="font-size:13px;font-weight:600">
<i class="fas <?=$sv['icon']?>" style="width:18px;color:var(--primary);text-align:center"></i>
<?=$sv['label']?>
</div>
<label class="toggle"><input type="checkbox" class="service-toggle" data-key="service_<?=$sk?>_enabled" <?=$checked?'checked':''?>><span class="slider"></span></label>
</div>
<?php endforeach; ?>
</div>

<button class="btn-primary" onclick="saveToggles()" style="width:100%;justify-content:center;padding:12px;margin-top:20px"><i class="fas fa-save"></i> Save All Toggles</button>
</div>
</div>

<div class="data-panel">
<div class="panel-title-bar"><h3><i class="fas fa-search" style="color:var(--primary)"></i> Activity Logs</h3></div>
<div style="padding:16px 20px;display:flex;flex-direction:column;gap:10px">
<a href="audit-logs.php" class="flex items-center gap-3" style="padding:14px 16px;background:var(--bg);border-radius:var(--radius-sm);text-decoration:none;color:var(--text);font-weight:600;border:1px solid var(--border);transition:var(--transition)">
<i class="fas fa-user-shield" style="color:var(--text-muted);width:20px;text-align:center"></i> Admin Activity Logs
<i class="fas fa-chevron-right" style="margin-left:auto;font-size:11px;color:var(--text-muted)"></i>
</a>
<a href="users-management.php" class="flex items-center gap-3" style="padding:14px 16px;background:var(--bg);border-radius:var(--radius-sm);text-decoration:none;color:var(--text);font-weight:600;border:1px solid var(--border);transition:var(--transition)">
<i class="fas fa-users" style="color:var(--text-muted);width:20px;text-align:center"></i> User Activity Logs
<i class="fas fa-chevron-right" style="margin-left:auto;font-size:11px;color:var(--text-muted)"></i>
</a>
</div>
</div>
</div>

<div class="data-panel">
<div class="panel-title-bar"><h3><i class="fas fa-ban" style="color:var(--danger)"></i> Blacklist Manager</h3>
<button class="btn-primary btn-sm" onclick="openBlacklistModal()"><i class="fas fa-plus"></i> Add Entry</button>
</div>
<div class="responsive-grid-wrapper">
<table class="data-table">
<thead><tr><th style="padding-left:24px">Type</th><th>Value</th><th>Reason</th><th>Date</th><th>Action</th></tr></thead>
<tbody>
<?php foreach($blacklists as $b): ?>
<tr>
<td style="padding-left:24px"><span class="badge <?=$b['type']==='ip'?'badge-neutral':'badge-info'?>"><i class="fas <?=$b['type']==='ip'?'fa-network-wired':'fa-phone'?>"></i> <?=strtoupper($b['type'])?></span></td>
<td style="font-weight:600;color:var(--text);font-family:monospace"><?=htmlspecialchars($b['value'])?></td>
<td style="font-size:13px;color:var(--text-muted);max-width:220px" class="truncate"><?=htmlspecialchars($b['reason'])?:'<span style="color:#9CA3AF;font-style:italic">No reason given</span>'?></td>
<td style="font-size:12px;color:var(--text-muted);white-space:nowrap"><?=date('d M Y',strtotime($b['created_at']))?></td>
<td><button class="btn-sm btn-danger" onclick="removeBlacklist(<?=$b['id']?>)"><i class="fas fa-trash"></i></button></td>
</tr>
<?php endforeach; if(empty($blacklists)): ?>
<tr><td colspan="5"><div class="empty-state" style="padding:48px 20px"><i class="fas fa-check-circle" style="font-size:36px;color:var(--success);margin-bottom:12px;display:block"></i><h4 style="font-size:16px;margin-bottom:6px">All Clear</h4><p style="color:var(--text-muted)">No blacklisted IPs or phone numbers.</p></div></td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
</div>

</div></div>

<div class="modal-overlay" id="modalOvl" style="display:none">
<div class="modal modal-card">
<div class="modal-header"><h3><i class="fas fa-ban" style="color:var(--danger);margin-right:8px"></i> Add to Blacklist</h3><button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button></div>
<div class="modal-body">
<div style="display:flex;flex-direction:column;gap:16px">
<div class="form-group"><label>Block Type</label><select class="form-control" id="b_type"><option value="ip">IP Address</option><option value="phone">Phone Number</option></select></div>
<div class="form-group"><label>Value (IP or Phone)</label><input type="text" class="form-control" id="b_value" placeholder="e.g. 192.168.1.1 or 08012345678"></div>
<div class="form-group"><label>Reason (Optional)</label><input type="text" class="form-control" id="b_reason" placeholder="e.g. Suspicious activity"></div>
<button class="btn-primary" onclick="addBlacklist()" style="width:100%;height:46px;justify-content:center;background:var(--danger);margin-top:4px"><i class="fas fa-lock"></i> Block Access</button>
</div>
</div>
</div>
</div>

<div class="save-toast" id="toast"></div>

<script>
const CSRF='<?=$csrf?>';
function openBlacklistModal(){document.getElementById('b_value').value='';document.getElementById('b_reason').value='';document.getElementById('modalOvl').style.display='flex';}
function closeModal(){document.getElementById('modalOvl').style.display='none';}

async function saveToggles(){
const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','save_toggles');
if(document.getElementById('login_enabled').checked)fd.append('login_enabled','1');
if(document.getElementById('registration_enabled').checked)fd.append('registration_enabled','1');
if(document.getElementById('all_services_enabled').checked)fd.append('all_services_enabled','1');
document.querySelectorAll('.service-toggle').forEach(el=>{if(el.checked)fd.append(el.dataset.key,'1');});
const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
showToast(r.message,r.success);
}

async function addBlacklist(){
const val=document.getElementById('b_value').value.trim();if(!val)return showToast('Value is required',false);
const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','add_blacklist');fd.append('type',document.getElementById('b_type').value);fd.append('value',val);fd.append('reason',document.getElementById('b_reason').value);
const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),800);
}

async function removeBlacklist(id){if(!confirm('Remove this entry from the blacklist?'))return;
const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','remove_blacklist');fd.append('id',id);
const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),800);
}

function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),2500);}
</script>
</body>
</html>
