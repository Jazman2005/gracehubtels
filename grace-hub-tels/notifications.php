<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) { db()->prepare("DELETE FROM notifications WHERE id = ?")->execute([$id]); json_response(['success'=>true,'message'=>'Deleted']); }
        json_response(['success'=>false,'message'=>'Invalid ID']);
    }
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) { db()->prepare("UPDATE notifications SET is_active = NOT is_active WHERE id = ?")->execute([$id]); json_response(['success'=>true,'message'=>'Toggled']); }
        json_response(['success'=>false,'message'=>'Invalid ID']);
    }
    $title=sanitize($_POST['title']??'',200);
    $msg=trim($_POST['message']??'');
    $msg=htmlspecialchars(strip_tags($msg), ENT_QUOTES, 'UTF-8');
    $type=in_array($_POST['type']??'',['info','success','warning','danger'])?$_POST['type']:'info';
    $target_role=in_array($_POST['target_role']??'',['all','agent','vendor'])?$_POST['target_role']:'all';
    $display_type=in_array($_POST['display_type']??'',['marquee','modal'])?$_POST['display_type']:'marquee';
    if(!$title||!$msg)json_response(['success'=>false,'message'=>'Title and message required']);
    db()->prepare("INSERT INTO notifications (user_id,title,message,type,target_role,display_type,is_active) VALUES (NULL,?,?,?,?,?,1)")->execute([$title,$msg,$type,$target_role,$display_type]);
    json_response(['success'=>true,'message'=>'Broadcast sent']);
}
$csrf=admin_csrf();
$tab = preg_replace('/[^a-z_]/', '', strtolower($_GET['tab'] ?? 'marquee'));
$marquees=db()->query("SELECT * FROM notifications WHERE user_id IS NULL AND display_type='marquee' ORDER BY created_at DESC LIMIT 30")->fetchAll();
$modals=db()->query("SELECT * FROM notifications WHERE user_id IS NULL AND display_type='modal' ORDER BY created_at DESC LIMIT 30")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Notifications — Gracehubtels Admin</title>
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

<div class="section-header" style="margin-bottom:24px;">
    <div><h2>Broadcast Notifications</h2><p>Manage marquee scrolling alerts and center pop-up announcements independently.</p></div>
</div>

<!-- Tabs -->
<div class="page-tabs">
    <a href="?tab=marquee" class="page-tab <?=$tab==='marquee'?'active':''?>">
        <i class="fas fa-scroll"></i> Marquee <span class="badge badge-primary" style="font-size:10px;margin-left:6px"><?=count($marquees)?></span>
    </a>
    <a href="?tab=modal" class="page-tab <?=$tab==='modal'?'active':''?>">
        <i class="fas fa-window-maximize"></i> Center Pop-up <span class="badge badge-primary" style="font-size:10px;margin-left:6px"><?=count($modals)?></span>
    </a>
</div>

<?php $rows = $tab === 'modal' ? $modals : $marquees; $dt = $tab === 'modal' ? 'modal' : 'marquee'; ?>

<div style="display:grid;grid-template-columns:1fr 2fr;gap:24px;align-items:start">
    <div class="data-panel">
        <div class="panel-title-bar" style="border-bottom:1px solid #E2E8F0;padding:20px 24px;background:#fff;">
            <h3 style="display:flex;align-items:center;gap:8px;margin:0;">
                <div style="width:32px;height:32px;border-radius:8px;background:rgba(79,70,229,0.1);color:var(--primary);display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-<?=$tab==='modal'?'window-maximize':'scroll'?>"></i>
                </div>
                New <?=ucfirst($dt)?>
            </h3>
        </div>
        <div class="modal-body" style="display:flex;flex-direction:column;gap:16px;padding:24px;">
            <div class="form-group">
                <label style="font-weight:600;font-size:13px;color:var(--navy-dark);margin-bottom:6px;display:block;">Title</label>
                <input type="text" class="form-control" id="title" placeholder="e.g. System Upgrade Notice">
            </div>
            <div class="form-group">
                <label style="font-weight:600;font-size:13px;color:var(--navy-dark);margin-bottom:6px;display:block;">Message</label>
                <textarea class="form-control" id="message" rows="4" placeholder="Enter the notification content here..."></textarea>
            </div>
            <div class="form-group">
                <label style="font-weight:600;font-size:13px;color:var(--navy-dark);margin-bottom:6px;display:block;">Alert Type</label>
                <select class="form-control" id="type">
                    <option value="info">Info (Blue)</option>
                    <option value="success">Success (Green)</option>
                    <option value="warning">Warning (Yellow)</option>
                    <option value="danger">Danger (Red)</option>
                </select>
            </div>
            <div class="form-group">
                <label style="font-weight:600;font-size:13px;color:var(--navy-dark);margin-bottom:6px;display:block;">Target Audience</label>
                <select class="form-control" id="target_role">
                    <option value="all">All Users</option>
                    <option value="agent">Agents Only</option>
                    <option value="vendor">Vendors Only</option>
                </select>
            </div>
            <input type="hidden" id="display_type" value="<?=$dt?>">
            <button class="btn-primary" id="sendBtn" style="width:100%;justify-content:center;padding:12px;font-size:14px;margin-top:8px;" onclick="sendBroadcast()">
                <i class="fas fa-paper-plane" style="margin-right:8px;"></i> Send <?=ucfirst($dt)?>
            </button>
        </div>
    </div>

    <div class="data-panel">
        <div class="panel-title-bar" style="border-bottom:1px solid #E2E8F0;padding:20px 24px;background:#fff;">
            <h3 style="display:flex;align-items:center;gap:8px;margin:0;">
                <i class="fas fa-history" style="color:#9CA3AF;"></i> <?=ucfirst($dt)?> History
            </h3>
        </div>
        <div class="responsive-grid-wrapper" style="border:none;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="padding-left:24px;">Title & Message</th>
                        <th>Type</th>
                        <th>Target</th>
                        <th>Status</th>
                        <th>Sent Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($rows as $b):
                    $badgeClass = 'badge-info'; $icon = 'fa-info-circle';
                    if($b['type'] === 'success'){ $badgeClass = 'badge-success'; $icon = 'fa-check-circle'; }
                    if($b['type'] === 'warning'){ $badgeClass = 'badge-warning'; $icon = 'fa-exclamation-triangle'; }
                    if($b['type'] === 'danger'){ $badgeClass = 'badge-danger'; $icon = 'fa-times-circle'; }
                    $roleLabel = $b['target_role'] === 'all' ? 'All' : ucfirst($b['target_role']);
                ?>
                <tr>
                    <td style="padding-left:24px;max-width:260px;">
                        <div style="font-weight:700;color:var(--navy-dark);font-size:13px;margin-bottom:4px;"><?=htmlspecialchars($b['title'])?></div>
                        <div style="font-size:12px;color:var(--text-muted);line-height:1.4;"><?=esc(mb_substr($b['message'],0,100))?><?=mb_strlen($b['message'])>100?'...':''?></div>
                    </td>
                    <td><span class="badge <?=$badgeClass?>" style="display:inline-flex;align-items:center;gap:4px;"><i class="fas <?=$icon?>"></i> <?=ucfirst($b['type'])?></span></td>
                    <td style="font-size:12px;font-weight:600;color:#64748B;"><?=esc($roleLabel)?></td>
                    <td>
                        <label class="toggle" style="margin:0">
                            <input type="checkbox" <?=$b['is_active']?'checked':''?> onchange="toggleActive(<?=$b['id']?>, this)">
                            <span class="slider"></span>
                        </label>
                    </td>
                    <td style="font-size:12px;color:var(--text-muted);font-weight:500;">
                        <?=date('d M Y',strtotime($b['created_at']))?><br>
                        <span style="font-size:11px;opacity:0.7;"><?=date('h:i A',strtotime($b['created_at']))?></span>
                    </td>
                    <td>
                        <button class="btn-primary" style="background:var(--danger);padding:6px 10px;font-size:12px;border-radius:6px;" onclick="deleteBroadcast(<?=$b['id']?>)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach;if(empty($rows)):?>
                <tr><td colspan="6" class="empty-state" style="padding:40px;">
                    <i class="fas fa-bell-slash" style="font-size:32px;color:#CBD5E1;margin-bottom:12px;"></i><br>
                    No <?=$dt?> broadcasts yet.
                </td></tr>
                <?php endif;?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</div></div>
<div class="save-toast" id="toast"></div>

<script>
const CSRF='<?=$csrf?>';
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
async function sendBroadcast(){
    const title=document.getElementById('title').value.trim();
    const msg=document.getElementById('message').value.trim();
    if(!title||!msg)return showToast('Please enter both title and message.',false);
    const btn=document.getElementById('sendBtn');
    btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin" style="margin-right:8px;"></i> Sending...';
    const fd=new FormData();
    fd.append('csrf',CSRF); fd.append('action','send'); fd.append('title',title); fd.append('message',msg);
    fd.append('type',document.getElementById('type').value);
    fd.append('target_role',document.getElementById('target_role').value);
    fd.append('display_type',document.getElementById('display_type').value);
    try {
        const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
        showToast(r.message,r.success);
        if(r.success)setTimeout(()=>location.reload(),1000);
    } catch(e){showToast('Network error',false);}
    btn.disabled=false; btn.innerHTML='<i class="fas fa-paper-plane" style="margin-right:8px;"></i> Send <?=ucfirst($dt)?>';
}
async function toggleActive(id, el){
    const fd=new FormData(); fd.append('csrf',CSRF); fd.append('action','toggle'); fd.append('id',id);
    const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    if(!r.success){ el.checked=!el.checked; showToast('Failed to toggle',false); }
}
async function deleteBroadcast(id){
    if(!confirm('Delete this broadcast?'))return;
    const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','delete');fd.append('id',id);
    const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    showToast(r.message,r.success);
    if(r.success)setTimeout(()=>location.reload(),1000);
}
function showToast(msg,ok){
    const t=document.getElementById('toast');
    t.textContent=msg; t.className='save-toast show '+(ok?'ok':'err');
    setTimeout(()=>t.classList.remove('show'),2500);
}
</script>
</body>
</html>
