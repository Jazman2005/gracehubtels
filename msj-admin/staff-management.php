<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_admin('superadmin'); // Only superadmin can manage staff

$csrf=admin_csrf();

if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    $action=$_POST['action']??'';
    
    if($action==='save_staff'){
        $id=(int)($_POST['id']??0);
        $username=sanitize($_POST['username']??'',100);
        $email=strtolower(trim($_POST['email']??''));
        $role=in_array($_POST['role']??'',['superadmin','admin','support'])?$_POST['role']:'support';
        $password=$_POST['password']??'';
        
        $perms = isset($_POST['permissions']) && is_array($_POST['permissions']) ? $_POST['permissions'] : [];
        $permissions_json = json_encode(array_values(array_unique($perms)));
        
        if(!$username||!filter_var($email,FILTER_VALIDATE_EMAIL))json_response(['success'=>false,'message'=>'Valid username and email required']);
        
        if($id){
            $q="UPDATE admin SET username=?,email=?,role=?,permissions=? WHERE id=?";
            $p=[$username,$email,$role,$permissions_json,$id];
            if($password){
                if(strlen($password)<6)json_response(['success'=>false,'message'=>'Password must be 6+ chars']);
                $q="UPDATE admin SET username=?,email=?,role=?,permissions=?,password=? WHERE id=?";
                $p=[$username,$email,$role,$permissions_json,password_hash($password,PASSWORD_DEFAULT),$id];
            }
            db()->prepare($q)->execute($p);
            audit_log($_SESSION['admin_id'], 'UPDATE_STAFF', "Updated staff ID $id ($username)");
        }else{
            if(strlen($password)<6)json_response(['success'=>false,'message'=>'Password required (min 6 chars)']);
            $s=db()->prepare("SELECT id FROM admin WHERE email=? OR username=?");
            $s->execute([$email,$username]);
            if($s->fetch())json_response(['success'=>false,'message'=>'Email or username already exists']);
            
            db()->prepare("INSERT INTO admin (username,email,password,role,permissions) VALUES (?,?,?,?,?)")
                ->execute([$username,$email,password_hash($password,PASSWORD_DEFAULT),$role,$permissions_json]);
            audit_log($_SESSION['admin_id'], 'CREATE_STAFF', "Created new staff $username ($role)");
        }
        json_response(['success'=>true,'message'=>'Staff saved successfully']);
    }
    
    if($action==='toggle_staff'){
        $id=(int)$_POST['id'];
        if($id===$_SESSION['admin_id'])json_response(['success'=>false,'message'=>'Cannot toggle your own account']);
        db()->prepare("UPDATE admin SET is_active=NOT is_active WHERE id=?")->execute([$id]);
        audit_log($_SESSION['admin_id'], 'TOGGLE_STAFF', "Toggled active status for staff ID $id");
        json_response(['success'=>true]);
    }
    
    if($action==='delete_staff'){
        $id=(int)$_POST['id'];
        if($id===$_SESSION['admin_id'])json_response(['success'=>false,'message'=>'Cannot delete yourself']);
        db()->prepare("DELETE FROM admin WHERE id=?")->execute([$id]);
        audit_log($_SESSION['admin_id'], 'DELETE_STAFF', "Deleted staff ID $id");
        json_response(['success'=>true,'message'=>'Staff deleted']);
    }
    json_response(['success'=>false],400);
}

$staff=db()->query("SELECT id,username,email,role,is_active,permissions,last_login,created_at FROM admin ORDER BY id DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Staff Management – MsjPay Admin</title>
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

<div class="page-head">
    <div>
        <h2>Staff Management</h2>
        <p>Manage admin access, assign roles, and set granular access permissions.</p>
    </div>
    <button class="btn-primary" onclick="openStaffModal()" style="padding:10px 20px; font-size:14px; border-radius:8px;">
        <i class="fas fa-plus"></i> Add Staff
    </button>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Staff User</th><th>Role</th><th>Status</th><th>Last Login</th><th>Added</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($staff as $s):?>
      <tr>
        <td>
          <div class="user-cell">
            <div class="u-avatar" style="background:var(--primary);color:#fff"><?=strtoupper(substr($s['username'],0,1))?></div>
            <div><span class="u-name"><?=htmlspecialchars($s['username'])?></span><span class="u-phone"><?=htmlspecialchars($s['email'])?></span></div>
          </div>
        </td>
        <td>
            <?php if($s['role']==='superadmin'): ?>
                <span class="badge badge-danger" style="text-transform:uppercase"><i class="fas fa-crown"></i> Superadmin</span>
            <?php else: ?>
                <span class="badge badge-info" style="text-transform:uppercase"><?=htmlspecialchars($s['role'])?></span>
            <?php endif; ?>
        </td>
        <td><label class="toggle"><input type="checkbox" <?=$s['is_active']?'checked':''?> <?=$s['id']===$_SESSION['admin_id']?'disabled':''?> onchange="toggleStaff(<?=$s['id']?>)"><span class="slider"></span></label></td>
        <td style="font-size:12px;color:var(--text-muted)"><?=$s['last_login']?date('d M, H:i',strtotime($s['last_login'])):'Never'?></td>
        <td style="font-size:12px;color:var(--text-muted)"><?=date('d M Y',strtotime($s['created_at']))?></td>
        <td style="white-space:nowrap">
          <button class="btn-primary" style="background:var(--navy-light); padding:6px 12px; font-size:12px; border-radius:6px; margin-right:4px;" onclick='editStaff(<?=htmlspecialchars(json_encode($s),ENT_QUOTES)?>)'><i class="fas fa-edit"></i> Edit</button>
          <?php if($s['id']!==$_SESSION['admin_id']):?>
          <button class="btn-primary" style="background:#EF4444; padding:6px 12px; font-size:12px; border-radius:6px;" onclick="deleteStaff(<?=$s['id']?>)"><i class="fas fa-trash"></i></button>
          <?php endif;?>
        </td>
      </tr>
      <?php endforeach;?>
      </tbody>
    </table>
  </div>
</div>

</div></div>

<!-- MODAL -->
<div class="modal-overlay" id="modalOvl" style="display:none; align-items:center; justify-content:center;">
    <div class="modal-card" style="background:#fff; width:100%; max-width:600px; border-radius:16px; box-shadow:0 10px 25px rgba(0,0,0,0.15); overflow:hidden;">
        <div class="modal-header" style="padding:20px 24px; border-bottom:1px solid #E2E8F0; display:flex; justify-content:space-between; align-items:center;">
            <h3 id="modalTitle" style="color:var(--navy-dark); font-size:18px;">Add Staff Member</h3>
            <button onclick="closeModal()" style="background:none; border:none; font-size:20px; color:#9CA3AF; cursor:pointer;">&times;</button>
        </div>
        <div class="modal-body" style="padding:24px; max-height:80vh; overflow-y:auto;">
            <input type="hidden" id="pid" value="">
            <div style="display:flex;flex-direction:column;gap:16px">
                <div class="form-grid">
                    <div class="form-group"><label>Username</label><input type="text" class="form-control" id="username" placeholder="e.g. jdoe"></div>
                    <div class="form-group"><label>Email Address</label><input type="email" class="form-control" id="email" placeholder="email@example.com"></div>
                </div>
                <div class="form-grid">
                    <div class="form-group"><label>Role <span style="font-size:11px;color:#EF4444">(Superadmin ignores permissions)</span></label><select class="form-control" id="role"><option value="support">Support</option><option value="admin">Admin</option><option value="superadmin">Superadmin</option></select></div>
                    <div class="form-group"><label>Password <span style="font-weight:400;color:#64748B" id="pwHint">(required for new staff)</span></label><input type="password" class="form-control" id="password" placeholder="Min 6 characters"></div>
                </div>
                
                <div style="margin-top:8px; border-top:1px solid #E2E8F0; padding-top:16px;">
                    <label style="font-weight:700; color:var(--navy-dark); font-size:14px; margin-bottom:12px; display:block;"><i class="fas fa-key" style="color:var(--primary);margin-right:6px;"></i> Granular Permissions</label>
                    <p style="font-size:12px; color:var(--text-muted); margin-bottom:16px;">Check the specific sections this staff member is allowed to access and manage.</p>
                    
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; background:#F8FAFC; padding:16px; border-radius:8px; border:1px solid #E2E8F0;">
                        <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;cursor:pointer;"><input type="checkbox" name="perms[]" value="view_users"> View Users</label>
                        <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;cursor:pointer;"><input type="checkbox" name="perms[]" value="manage_users"> Manage Users & KYC</label>
                        <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;cursor:pointer;"><input type="checkbox" name="perms[]" value="view_transactions"> View Transactions</label>
                        <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;cursor:pointer;"><input type="checkbox" name="perms[]" value="manage_transactions"> Edit/Refund Transactions</label>
                        <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;cursor:pointer;"><input type="checkbox" name="perms[]" value="manage_services"> Manage Services & Pricing</label>
                        <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;cursor:pointer;"><input type="checkbox" name="perms[]" value="manage_messaging"> Messaging Hub (Email/Push)</label>
                        <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;cursor:pointer;"><input type="checkbox" name="perms[]" value="view_logs"> View Audit Logs</label>
                        <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;cursor:pointer;"><input type="checkbox" name="perms[]" value="manage_api"> Manage API Configurations</label>
                    </div>
                </div>
                
                <button class="btn-primary" style="width:100%;height:48px;justify-content:center;font-size:15px;margin-top:12px;" onclick="saveStaff()"><i class="fas fa-save" style="margin-right:8px;"></i> Save Staff Member</button>
            </div>
        </div>
    </div>
</div>
<div class="save-toast" id="toast"></div>

<script>
const CSRF='<?=$csrf?>';
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
function openStaffModal(){
    document.getElementById('modalTitle').innerHTML='<i class="fas fa-plus"></i> Add Staff Member';
    ['pid','username','email','password'].forEach(id=>document.getElementById(id).value='');
    document.getElementById('role').value='support';
    document.getElementById('pwHint').textContent='(required for new staff)';
    document.querySelectorAll('input[name="perms[]"]').forEach(cb => cb.checked = false);
    document.getElementById('modalOvl').style.display='flex';
}
function editStaff(s){
    document.getElementById('modalTitle').innerHTML='<i class="fas fa-edit"></i> Edit Staff Member';
    document.getElementById('pid').value=s.id;
    document.getElementById('username').value=s.username;
    document.getElementById('email').value=s.email;
    document.getElementById('role').value=s.role;
    document.getElementById('password').value='';
    document.getElementById('pwHint').textContent='(leave blank to keep current)';
    
    // Check boxes based on JSON
    let p = [];
    try { p = JSON.parse(s.permissions || '[]'); } catch(e){}
    document.querySelectorAll('input[name="perms[]"]').forEach(cb => {
        cb.checked = p.includes(cb.value);
    });
    
    document.getElementById('modalOvl').style.display='flex';
}
function closeModal(){document.getElementById('modalOvl').style.display='none';}

async function saveStaff(){
  const fd=new FormData();
  fd.append('csrf',CSRF);
  fd.append('action','save_staff');
  fd.append('id',document.getElementById('pid').value);
  ['username','email','role','password'].forEach(id=>fd.append(id,document.getElementById(id).value));
  
  // Append permissions array
  document.querySelectorAll('input[name="perms[]"]:checked').forEach(cb => {
      fd.append('permissions[]', cb.value);
  });
  
  const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
  showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);
}

async function toggleStaff(id){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','toggle_staff');fd.append('id',id);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());if(!r.success){showToast(r.message,false);setTimeout(()=>location.reload(),900);}}
async function deleteStaff(id){if(!confirm('Permanently delete this staff member?'))return;const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','delete_staff');fd.append('id',id);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),700);}
function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),2500);}
document.getElementById('modalOvl').addEventListener('click',e=>{if(e.target===e.currentTarget)closeModal();});
</script>
</body>
</html>
