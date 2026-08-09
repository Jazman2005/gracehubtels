<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
// Managing other admin accounts — including just viewing their emails/login
// IPs, and especially minting new ones with an arbitrary role — is the single
// most sensitive area of the panel. Superadmin-only, not just a checkable
// permission (a permission would let a superadmin hand out the ability to
// create MORE superadmins).
require_superadmin();
if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    if(!rate_limit('admin_mgmt',10,60))json_response(['success'=>false,'message'=>'Too many requests'],429);
    $action=$_POST['action']??'';
    if($action==='create_admin'){
        $email=strtolower(trim($_POST['email']??''));$password=$_POST['password']??'';
        $role=sanitize($_POST['role']??'admin',50);$is_active=(int)($_POST['is_active']??1);
        if(!in_array($role,['support','admin','superadmin'],true))json_response(['success'=>false,'message'=>'Invalid role']);
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))json_response(['success'=>false,'message'=>'Invalid email']);
        if(strlen($password)<8)json_response(['success'=>false,'message'=>'Password must be 8+ characters']);
        $s=db()->prepare("SELECT id FROM admin WHERE email=? LIMIT 1");$s->execute([$email]);
        if($s->fetch())json_response(['success'=>false,'message'=>'Email already registered']);
        db()->prepare("INSERT INTO admin (email,password,role,is_active,created_at) VALUES (?,?,?,?,NOW())")->execute([$email,password_hash($password,PASSWORD_DEFAULT),$role,$is_active]);
        audit_log($_SESSION['admin_id'], 'CREATE_ADMIN', "Created admin $email ($role)");
        json_response(['success'=>true,'message'=>'Admin created']);
    }
    if($action==='toggle_active'){
        $id=(int)($_POST['id']??0);
        if($id==($_SESSION['admin_id']??0))json_response(['success'=>false,'message'=>'Cannot modify yourself']);
        $s=db()->prepare("SELECT is_active,role FROM admin WHERE id=? LIMIT 1");$s->execute([$id]);$a=$s->fetch();
        if(!$a)json_response(['success'=>false,'message'=>'Admin not found']);
        if($a['role']==='superadmin')json_response(['success'=>false,'message'=>'Cannot deactivate a superadmin']);
        $nv=$a['is_active']?0:1;
        db()->prepare("UPDATE admin SET is_active=? WHERE id=?")->execute([$nv,$id]);
        audit_log($_SESSION['admin_id'], $nv?'ACTIVATE_ADMIN':'DEACTIVATE_ADMIN', "Admin ID $id ".($nv?'activated':'deactivated'));
        json_response(['success'=>true,'message'=>$nv?'Admin activated':'Admin deactivated']);
    }
    if($action==='delete_admin'){
        $id=(int)($_POST['id']??0);
        if($id==($_SESSION['admin_id']??0))json_response(['success'=>false,'message'=>'Cannot delete yourself']);
        $s=db()->prepare("SELECT email,role FROM admin WHERE id=? LIMIT 1");$s->execute([$id]);$a=$s->fetch();
        if(!$a)json_response(['success'=>false,'message'=>'Admin not found']);
        if($a['role']==='superadmin')json_response(['success'=>false,'message'=>'Cannot delete superadmin']);
        db()->prepare("DELETE FROM admin WHERE id=?")->execute([$id]);
        audit_log($_SESSION['admin_id'], 'DELETE_ADMIN', "Deleted admin ID $id ({$a['email']})");
        json_response(['success'=>true,'message'=>'Admin deleted']);
    }
    json_response(['success'=>false],400);
}
$csrf=admin_csrf();$admins=db()->query("SELECT * FROM admin ORDER BY created_at DESC")->fetchAll();
try{$roles=db()->query("SELECT role_name FROM admin_roles ORDER BY role_name")->fetchAll(PDO::FETCH_COLUMN);}catch(PDOException$e){$roles=['admin','superadmin','support'];}
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Admin Management — Gracehubtels Admin</title><link rel="icon" type="image/png" href="/assets/images/gracehubtels-favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">
<div class="section-header"><div><h2>Admin Management</h2><p><?=count($admins)?> administrator<?=count($admins)!==1?'s':''?></p></div><button class="btn-primary" onclick="openAddModal()"><svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:#fff;fill:none;stroke-width:2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Add New Admin</button></div>

<div class="data-panel"><div class="responsive-grid-wrapper">
<table class="admin-table">
<thead><tr><th>Admin</th><th>Role</th><th>Last Login</th><th>IP</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach($admins as $a):$is_self=$a['id']==($_SESSION['admin_id']??0);$role_cls=in_array($a['role'],['superadmin','admin','support'])?$a['role']:'admin';?>
<tr>
<td><div class="user-cell"><div class="u-avatar"><?=strtoupper(substr($a['email'],0,1))?></div><div><span class="u-name"><?=htmlspecialchars($a['email'])?></span><?php if($is_self):?><span class="u-phone">(you)</span><?php endif;?></div></div></td>
<td><span class="role-badge <?=$role_cls?>"><?=htmlspecialchars(ucfirst($a['role']))?></span></td>
<td><?=$a['last_login']?date('d M Y, H:i',strtotime($a['last_login'])):'<span style="color:#9CA3AF">Never</span>'?></td>
<td style="font-size:12px;color:var(--text-muted)"><?=htmlspecialchars($a['last_login_ip']??'—')?></td>
<td><span class="badge <?=$a['is_active']?'badge-success':'badge-danger'?>"><?=$a['is_active']?'Active':'Inactive'?></span></td>
<td style="font-size:12px;color:var(--text-muted)"><?=date('d M Y',strtotime($a['created_at']))?></td>
<td style="white-space:nowrap"><?php if(!$is_self):?><button class="btn-sm <?=$a['is_active']?'btn-warning':'btn-success'?>" onclick="toggleActive(<?=$a['id']?>,<?=$a['is_active']?>)"><?=$a['is_active']?'Deactivate':'Activate'?></button> <button class="btn-sm btn-danger" onclick="deleteAdmin(<?=$a['id']?>)">Delete</button><?php else:?><span style="font-size:12px;color:var(--text-muted)">—</span><?php endif;?></td>
</tr>
<?php endforeach;if(empty($admins)):?><tr><td colspan="7" class="empty-state">No admins found</td></tr><?php endif;?>
</tbody>
</table></div></div>
</div></div>

<div class="modal-overlay" id="modalOvl" style="display:none">
<div class="modal">
<div class="modal-header"><h3>Add New Admin</h3><button class="modal-close" onclick="document.getElementById('modalOvl').style.display='none'">&times;</button></div>
<div class="modal-body">
<form onsubmit="return createAdmin(event)">
<div class="form-group" style="margin-bottom:14px"><label>Email</label><input type="email" class="form-control" id="email" required autocomplete="off"></div>
<div class="form-group" style="margin-bottom:14px"><label>Password</label><input type="password" class="form-control" id="password" minlength="8" required placeholder="Min 8 characters" autocomplete="new-password"></div>
<div class="form-group" style="margin-bottom:14px"><label>Role</label><select class="form-control" id="role"><?php foreach($roles as $r):?><option value="<?=htmlspecialchars($r)?>" <?=$r==='admin'?'selected':''?>><?=htmlspecialchars(ucfirst($r))?></option><?php endforeach;?></select></div>
<div class="form-group" style="margin-bottom:14px"><label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" id="isActive" checked style="width:18px;height:18px;accent-color:var(--primary)"> Active</label></div>
<button type="submit" class="btn-primary" style="width:100%;justify-content:center" id="submitBtn">Create Admin</button>
</form>
</div></div></div>
<div class="save-toast" id="toast"></div>
<script>
const CSRF='<?=$csrf?>';
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');document.getElementById('sidebarOverlay')?.classList.toggle('show');}
function openAddModal(){document.getElementById('modalOvl').style.display='flex';document.getElementById('email').focus();}
async function createAdmin(e){e.preventDefault();const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','create_admin');
fd.append('email',document.getElementById('email').value);fd.append('password',document.getElementById('password').value);
fd.append('role',document.getElementById('role').value);fd.append('is_active',document.getElementById('isActive').checked?1:0);
const btn=document.getElementById('submitBtn');btn.disabled=true;btn.textContent='Creating...';
const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
showToast(r.message,r.success);btn.disabled=false;btn.textContent='Create Admin';
if(r.success)setTimeout(()=>location.reload(),900);}
async function toggleActive(id,cur){if(!confirm((cur?'Deactivate':'Activate')+' this admin?'))return;
const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','toggle_active');fd.append('id',id);
const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);}
async function deleteAdmin(id){if(!confirm('Delete this admin? This cannot be undone.'))return;
if(!confirm('Are you sure? This action is permanent.'))return;
const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','delete_admin');fd.append('id',id);
const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);}
function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),2500);}
document.getElementById('modalOvl').addEventListener('click',e=>{if(e.target===e.currentTarget)e.currentTarget.style.display='none';});
</script>
</body></html>
