<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
require_permission('view_logs'); // Require appropriate permission for security unit

if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    
    $action = $_POST['action'] ?? '';
    
    if($action === 'save_toggles'){
        $keys = ['login_enabled','registration_enabled','all_services_enabled'];
        foreach($keys as $k){
            $v = empty($_POST[$k]) ? '0' : '1';
            $chk = db()->query("SELECT id FROM site_settings WHERE setting_key='$k'")->fetchColumn();
            if($chk){
                db()->prepare("UPDATE site_settings SET setting_value=? WHERE setting_key=?")->execute([$v, $k]);
            } else {
                db()->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $v]);
            }
        }
        audit_log($_SESSION['admin_id'], 'UPDATE_SECURITY_TOGGLES', 'Updated global security toggles');
        json_response(['success'=>true,'message'=>'Global security toggles updated successfully']);
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

function get_sec_set($key){
    return db()->query("SELECT setting_value FROM site_settings WHERE setting_key='$key'")->fetchColumn() ?: '0';
}

$login_enabled = get_sec_set('login_enabled') == '1';
$registration_enabled = get_sec_set('registration_enabled') == '1';
$all_services_enabled = get_sec_set('all_services_enabled') == '1';

$blacklists = db()->query("SELECT * FROM blacklists ORDER BY id DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Security Unit – MsjPay Admin</title>
    <link rel="icon" type="image/png" href="/msjpay/assets/images/msjpay-favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/msjpay/msj-admin/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="page-content">

<div class="page-head" style="margin-bottom:24px;">
    <div>
        <h2>Security Unit</h2>
        <p>Manage platform-wide security, emergency switches, and blacklist access.</p>
    </div>
</div>

<div style="display:grid; grid-template-columns:1fr 2fr; gap:24px; align-items:start;">

    <div style="display:flex; flex-direction:column; gap:24px;">
        <!-- GLOBAL TOGGLES -->
        <div class="card" style="padding:0; overflow:hidden;">
            <div class="card-header" style="border-bottom:1px solid #E2E8F0; padding:20px 24px; background:#fff;">
                <h3 style="margin:0; font-size:16px; color:var(--navy-dark); display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-power-off" style="color:#EF4444;"></i> Global Emergency Toggles
                </h3>
            </div>
            <div style="padding:24px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; padding-bottom:16px; border-bottom:1px solid #F1F5F9;">
                    <div>
                        <div style="font-weight:600; font-size:14px; color:var(--navy-dark);">User Logins</div>
                        <div style="font-size:12px; color:var(--text-muted);">Allow users to log into their dashboard.</div>
                    </div>
                    <label class="toggle"><input type="checkbox" id="login_enabled" <?= $login_enabled ? 'checked' : '' ?>><span class="slider"></span></label>
                </div>
                
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; padding-bottom:16px; border-bottom:1px solid #F1F5F9;">
                    <div>
                        <div style="font-weight:600; font-size:14px; color:var(--navy-dark);">New Registrations</div>
                        <div style="font-size:12px; color:var(--text-muted);">Allow new users to sign up.</div>
                    </div>
                    <label class="toggle"><input type="checkbox" id="registration_enabled" <?= $registration_enabled ? 'checked' : '' ?>><span class="slider"></span></label>
                </div>
                
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px;">
                    <div>
                        <div style="font-weight:600; font-size:14px; color:var(--navy-dark);">All VTU Services</div>
                        <div style="font-size:12px; color:var(--text-muted);">Master switch for Data, Airtime, etc.</div>
                    </div>
                    <label class="toggle"><input type="checkbox" id="all_services_enabled" <?= $all_services_enabled ? 'checked' : '' ?>><span class="slider"></span></label>
                </div>
                
                <button class="btn-primary" style="width:100%;justify-content:center;padding:12px;" onclick="saveToggles()">
                    <i class="fas fa-save" style="margin-right:8px;"></i> Save Global States
                </button>
            </div>
        </div>
        
        <!-- LOGS NAVIGATION -->
        <div class="card" style="padding:0; overflow:hidden;">
            <div class="card-header" style="border-bottom:1px solid #E2E8F0; padding:20px 24px; background:#fff;">
                <h3 style="margin:0; font-size:16px; color:var(--navy-dark); display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-search" style="color:var(--primary);"></i> Activity Logs
                </h3>
            </div>
            <div style="padding:24px; display:flex; flex-direction:column; gap:12px;">
                <a href="audit-logs.php" style="display:flex; align-items:center; gap:12px; padding:12px 16px; background:#F8FAFC; border-radius:8px; text-decoration:none; color:var(--navy-dark); font-weight:600; border:1px solid #E2E8F0; transition:all 0.2s;">
                    <i class="fas fa-user-shield" style="color:#9CA3AF;"></i> Admin Activity Logs
                </a>
                <a href="users-management.php" style="display:flex; align-items:center; gap:12px; padding:12px 16px; background:#F8FAFC; border-radius:8px; text-decoration:none; color:var(--navy-dark); font-weight:600; border:1px solid #E2E8F0; transition:all 0.2s;">
                    <i class="fas fa-users" style="color:#9CA3AF;"></i> User Activity Logs
                </a>
            </div>
        </div>
    </div>

    <!-- BLACKLIST MANAGER -->
    <div class="card" style="padding:0; overflow:hidden;">
        <div class="card-header" style="border-bottom:1px solid #E2E8F0; padding:20px 24px; background:#fff; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0; font-size:16px; color:var(--navy-dark); display:flex; align-items:center; gap:8px;">
                <i class="fas fa-ban" style="color:#EF4444;"></i> Blacklist Manager
            </h3>
            <button class="btn-primary" onclick="openBlacklistModal()" style="padding:6px 12px; font-size:12px; border-radius:6px;">
                <i class="fas fa-plus"></i> Add Entry
            </button>
        </div>
        <div class="table-wrap" style="border:none;">
            <table class="data-table">
                <thead><tr><th style="padding-left:24px;">Type</th><th>Value</th><th>Reason</th><th>Date Added</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach($blacklists as $b): ?>
                <tr>
                    <td style="padding-left:24px;">
                        <span class="badge <?=$b['type']==='ip'?'badge-neutral':'badge-info'?>" style="text-transform:uppercase;">
                            <i class="fas <?=$b['type']==='ip'?'fa-network-wired':'fa-phone'?>" style="margin-right:4px;"></i> <?=$b['type']?>
                        </span>
                    </td>
                    <td style="font-weight:600; color:var(--navy-dark);"><?=htmlspecialchars($b['value'])?></td>
                    <td style="font-size:12px; color:var(--text-muted); max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                        <?=htmlspecialchars($b['reason']) ?: '<i>No reason provided</i>'?>
                    </td>
                    <td style="font-size:12px; color:var(--text-muted);"><?=date('d M Y', strtotime($b['created_at']))?></td>
                    <td>
                        <button class="btn-primary" style="background:#EF4444; padding:6px 12px; font-size:12px; border-radius:6px;" onclick="removeBlacklist(<?=$b['id']?>)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; if(empty($blacklists)): ?>
                <tr>
                    <td colspan="5" class="empty-state" style="padding:40px;">
                        <i class="fas fa-check-circle" style="font-size:32px; color:#10B981; margin-bottom:12px;"></i><br>
                        No blacklisted IPs or phone numbers.
                    </td>
                </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

</div></div>

<!-- MODAL -->
<div class="modal-overlay" id="modalOvl" style="display:none; align-items:center; justify-content:center;">
    <div class="modal-card" style="background:#fff; width:100%; max-width:500px; border-radius:16px; box-shadow:0 10px 25px rgba(0,0,0,0.15); overflow:hidden;">
        <div class="modal-header" style="padding:20px 24px; border-bottom:1px solid #E2E8F0; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="color:var(--navy-dark); font-size:18px; margin:0;"><i class="fas fa-ban" style="color:#EF4444; margin-right:8px;"></i> Add to Blacklist</h3>
            <button onclick="closeModal()" style="background:none; border:none; font-size:20px; color:#9CA3AF; cursor:pointer;">&times;</button>
        </div>
        <div class="modal-body" style="padding:24px;">
            <div style="display:flex;flex-direction:column;gap:16px">
                <div class="form-group">
                    <label style="font-weight:600; font-size:13px; color:var(--navy-dark); margin-bottom:6px; display:block;">Block Type</label>
                    <select class="form-control" id="b_type">
                        <option value="ip">IP Address</option>
                        <option value="phone">Phone Number</option>
                    </select>
                </div>
                <div class="form-group">
                    <label style="font-weight:600; font-size:13px; color:var(--navy-dark); margin-bottom:6px; display:block;">Value (IP or Phone)</label>
                    <input type="text" class="form-control" id="b_value" placeholder="e.g. 192.168.1.1 or 08012345678">
                </div>
                <div class="form-group">
                    <label style="font-weight:600; font-size:13px; color:var(--navy-dark); margin-bottom:6px; display:block;">Reason (Optional)</label>
                    <input type="text" class="form-control" id="b_reason" placeholder="e.g. Suspicious activity">
                </div>
                <button class="btn-primary" style="width:100%;height:48px;justify-content:center;font-size:15px;margin-top:8px;background:#EF4444;" onclick="addBlacklist()">
                    <i class="fas fa-lock" style="margin-right:8px;"></i> Block Access
                </button>
            </div>
        </div>
    </div>
</div>
<div class="save-toast" id="toast"></div>

<script>
const CSRF='<?=$csrf?>';
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}

function openBlacklistModal(){
    document.getElementById('b_value').value = '';
    document.getElementById('b_reason').value = '';
    document.getElementById('modalOvl').style.display='flex';
}
function closeModal(){document.getElementById('modalOvl').style.display='none';}

async function saveToggles(){
    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('action', 'save_toggles');
    if(document.getElementById('login_enabled').checked) fd.append('login_enabled', '1');
    if(document.getElementById('registration_enabled').checked) fd.append('registration_enabled', '1');
    if(document.getElementById('all_services_enabled').checked) fd.append('all_services_enabled', '1');
    
    const r = await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    showToast(r.message, r.success);
}

async function addBlacklist(){
    const val = document.getElementById('b_value').value.trim();
    if(!val) return showToast('Value is required', false);
    
    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('action', 'add_blacklist');
    fd.append('type', document.getElementById('b_type').value);
    fd.append('value', val);
    fd.append('reason', document.getElementById('b_reason').value);
    
    const r = await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    showToast(r.message, r.success);
    if(r.success) setTimeout(()=>location.reload(), 800);
}

async function removeBlacklist(id){
    if(!confirm('Remove this entry from the blacklist?')) return;
    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('action', 'remove_blacklist');
    fd.append('id', id);
    const r = await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    showToast(r.message, r.success);
    if(r.success) setTimeout(()=>location.reload(), 800);
}

function showToast(msg,ok){
    const t=document.getElementById('toast');
    t.textContent=msg;
    t.className='save-toast show '+(ok?'ok':'err');
    setTimeout(()=>t.classList.remove('show'),2500);
}
</script>
</body>
</html>
