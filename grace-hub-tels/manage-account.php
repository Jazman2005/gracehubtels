<?php
require_once __DIR__.'/core/auth.php';
auth_admin();

if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    
    $action = $_POST['action'] ?? '';
    
    if($action === 'update_password'){
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        
        if(!$current || !$new || !$confirm) json_response(['success'=>false,'message'=>'All fields are required']);
        if($new !== $confirm) json_response(['success'=>false,'message'=>'New passwords do not match']);
        if(strlen($new) < 8) json_response(['success'=>false,'message'=>'Password must be at least 8 characters']);

        // Nothing previously rate-limited how many times "current_password"
        // could be guessed here — a hijacked admin session had unlimited
        // attempts to brute-force this (higher-privilege) account's real
        // password with no lockout at all.
        if(!rate_limit('admin_change_password_'.$_SESSION['admin_id'], 5, 900)) {
            json_response(['success'=>false,'message'=>'Too many attempts. Please try again in 15 minutes.']);
        }

        $admin = get_admin($_SESSION['admin_id']);
        if(!password_verify($current, $admin['password'])) {
            json_response(['success'=>false,'message'=>'Incorrect current password']);
        }
        
        db()->prepare("UPDATE admin SET password=? WHERE id=?")->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['admin_id']]);
        audit_log($_SESSION['admin_id'], 'UPDATE_PASSWORD', 'Admin updated their own password');
        json_response(['success'=>true,'message'=>'Password updated successfully. Please use it on your next login.']);
    }
}
$csrf=admin_csrf();
$admin = get_admin($_SESSION['admin_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Manage Account — Gracehubtels Admin</title>
    <link rel="icon" type="image/png" href="/assets/images/gracehubtels-favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>.two-col-page{display:grid;grid-template-columns:1fr 2fr;gap:24px;align-items:start}@media (max-width:1024px){.two-col-page{grid-template-columns:1fr}}</style>
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header" style="margin-bottom:24px;">
    <div>
        <h2>Manage Account</h2>
        <p>Update your personal admin account security and settings.</p>
    </div>
</div>

<div class="two-col-page">

    <!-- PROFILE PREVIEW -->
    <div class="data-panel" style="display:flex; flex-direction:column; align-items:center; padding:32px 24px; text-align:center;">
        <div style="width:80px; height:80px; border-radius:50%; background:var(--primary); color:#fff; display:flex; align-items:center; justify-content:center; font-size:32px; font-weight:700; margin-bottom:16px; box-shadow:0 4px 15px rgba(255,90,31,0.3);">
            <?=strtoupper(substr($admin['username'],0,1))?>
        </div>
        <h3 style="margin:0 0 4px 0; color:var(--navy-dark); font-size:18px;"><?=htmlspecialchars($admin['username'])?></h3>
        <p style="margin:0 0 16px 0; color:var(--text-muted); font-size:13px;"><?=htmlspecialchars($admin['email'])?></p>
        <span class="badge badge-info" style="text-transform:uppercase; font-size:12px; padding:6px 12px; border-radius:20px;">
            <?=htmlspecialchars($admin['role'])?>
        </span>
    </div>

    <!-- UPDATE PASSWORD FORM -->
    <div class="data-panel" >
        <div class="panel-title-bar" style="border-bottom:1px solid #E2E8F0; padding:20px 24px; background:#fff;">
            <h3 style="margin:0; font-size:16px; color:var(--navy-dark); display:flex; align-items:center; gap:8px;">
                <i class="fas fa-lock" style="color:var(--primary);"></i> Change Password
            </h3>
        </div>
        <div style="padding:24px;">
            <div class="form-group" style="margin-bottom:16px;">
                <label style="font-weight:600; font-size:13px; color:var(--navy-dark); margin-bottom:6px; display:block;">Current Password</label>
                <input type="password" class="form-control" id="current_password" placeholder="Enter your current password">
            </div>
            
            <div class="form-grid" style="margin-bottom:24px;">
                <div class="form-group">
                    <label style="font-weight:600; font-size:13px; color:var(--navy-dark); margin-bottom:6px; display:block;">New Password</label>
                    <input type="password" class="form-control" id="new_password" placeholder="Minimum 6 characters">
                </div>
                <div class="form-group">
                    <label style="font-weight:600; font-size:13px; color:var(--navy-dark); margin-bottom:6px; display:block;">Confirm New Password</label>
                    <input type="password" class="form-control" id="confirm_password" placeholder="Re-type new password">
                </div>
            </div>
            
            <button class="btn-primary" id="saveBtn" style="padding:12px 24px; font-size:14px; width:auto;" onclick="updatePassword()">
                <i class="fas fa-save" style="margin-right:8px;"></i> Update Password
            </button>
        </div>
    </div>

</div>

</div></div>
<div class="save-toast" id="toast"></div>

<script>
const CSRF='<?=$csrf?>';
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}

async function updatePassword(){
    const cur = document.getElementById('current_password').value;
    const ne = document.getElementById('new_password').value;
    const conf = document.getElementById('confirm_password').value;
    
    if(!cur || !ne || !conf) return showToast('All fields are required', false);
    if(ne !== conf) return showToast('New passwords do not match', false);
    
    const btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:8px;"></i> Updating...';
    
    const fd=new FormData();
    fd.append('csrf',CSRF);
    fd.append('action','update_password');
    fd.append('current_password',cur);
    fd.append('new_password',ne);
    fd.append('confirm_password',conf);
    
    const r = await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    showToast(r.message, r.success);
    
    if(r.success){
        document.getElementById('current_password').value = '';
        document.getElementById('new_password').value = '';
        document.getElementById('confirm_password').value = '';
    }
    
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-save" style="margin-right:8px;"></i> Update Password';
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
