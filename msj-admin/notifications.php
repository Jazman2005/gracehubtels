<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    
    $action = $_POST['action'] ?? 'send';
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            db()->prepare("DELETE FROM notifications WHERE id = ?")->execute([$id]);
            json_response(['success'=>true,'message'=>'Broadcast deleted']);
        }
        json_response(['success'=>false,'message'=>'Invalid ID']);
    }

    $title=sanitize($_POST['title']??'',200);$msg=sanitize($_POST['message']??'',500);$type=in_array($_POST['type']??'',['info','success','warning','danger'])?$_POST['type']:'info';
    if(!$title||!$msg)json_response(['success'=>false,'message'=>'Title and message required']);
    db()->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (NULL,?,?,?)")->execute([$title,$msg,$type]);
    json_response(['success'=>true,'message'=>'Broadcast sent to all users']);
}
$csrf=admin_csrf();
$broadcasts=db()->query("SELECT * FROM notifications WHERE user_id IS NULL ORDER BY created_at DESC LIMIT 30")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Notifications – MsjPay Admin</title>
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
        <h2>Broadcast Notifications</h2>
        <p>Send global alerts that appear on every user's dashboard marquee.</p>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 2fr;gap:24px;align-items:start">
    
    <!-- COMPOSE FORM -->
    <div class="card" style="padding:0; overflow:hidden;">
        <div class="card-header" style="border-bottom:1px solid #E2E8F0; padding:20px 24px; background:#fff;">
            <h3 style="display:flex; align-items:center; gap:8px; margin:0;">
                <div style="width:32px;height:32px;border-radius:8px;background:rgba(255,90,31,0.1);color:var(--primary);display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-bullhorn"></i>
                </div>
                New Broadcast
            </h3>
        </div>
        <div class="modal-body" style="display:flex;flex-direction:column;gap:16px;padding:24px;">
            <div class="form-group">
                <label style="font-weight:600; font-size:13px; color:var(--navy-dark); margin-bottom:6px; display:block;">Title</label>
                <input type="text" class="form-control" id="title" placeholder="e.g. System Upgrade Notice">
            </div>
            <div class="form-group">
                <label style="font-weight:600; font-size:13px; color:var(--navy-dark); margin-bottom:6px; display:block;">Message</label>
                <textarea class="form-control" id="message" rows="4" placeholder="Enter the notification content here..."></textarea>
            </div>
            <div class="form-group">
                <label style="font-weight:600; font-size:13px; color:var(--navy-dark); margin-bottom:6px; display:block;">Alert Type</label>
                <select class="form-control" id="type">
                    <option value="info">Info (Blue)</option>
                    <option value="success">Success (Green)</option>
                    <option value="warning">Warning (Yellow)</option>
                    <option value="danger">Danger (Red)</option>
                </select>
            </div>
            <button class="btn-primary" id="sendBtn" style="width:100%;justify-content:center;padding:12px;font-size:14px;margin-top:8px;" onclick="sendBroadcast()">
                <i class="fas fa-paper-plane" style="margin-right:8px;"></i> Send to All Users
            </button>
        </div>
    </div>
    
    <!-- RECENT BROADCASTS TABLE -->
    <div class="card" style="padding:0; overflow:hidden;">
        <div class="card-header" style="border-bottom:1px solid #E2E8F0; padding:20px 24px; background:#fff;">
            <h3 style="display:flex; align-items:center; gap:8px; margin:0;">
                <i class="fas fa-history" style="color:#9CA3AF;"></i> Recent Broadcasts
            </h3>
        </div>
        <div class="table-wrap" style="border:none;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="padding-left:24px;">Title & Message</th>
                        <th>Type</th>
                        <th>Sent Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($broadcasts as $b):
                    $badgeClass = 'badge-info';
                    $icon = 'fa-info-circle';
                    if($b['type'] === 'success'){ $badgeClass = 'badge-success'; $icon = 'fa-check-circle'; }
                    if($b['type'] === 'warning'){ $badgeClass = 'badge-warning'; $icon = 'fa-exclamation-triangle'; }
                    if($b['type'] === 'danger'){ $badgeClass = 'badge-danger'; $icon = 'fa-times-circle'; }
                ?>
                <tr>
                    <td style="padding-left:24px; max-width:280px;">
                        <div style="font-weight:700; color:var(--navy-dark); font-size:13px; margin-bottom:4px;"><?=htmlspecialchars($b['title'])?></div>
                        <div style="font-size:12px; color:var(--text-muted); line-height:1.4;"><?=htmlspecialchars(substr($b['message'],0,100))?><?=strlen($b['message'])>100?'...':''?></div>
                    </td>
                    <td><span class="badge <?=$badgeClass?>" style="display:inline-flex;align-items:center;gap:4px;"><i class="fas <?=$icon?>"></i> <?=ucfirst($b['type'])?></span></td>
                    <td style="font-size:12px; color:var(--text-muted); font-weight:500;">
                        <?=date('d M Y',strtotime($b['created_at']))?><br>
                        <span style="font-size:11px; opacity:0.7;"><?=date('h:i A',strtotime($b['created_at']))?></span>
                    </td>
                    <td>
                        <button class="btn-primary" style="background:#EF4444; padding:6px 10px; font-size:12px; border-radius:6px;" onclick="deleteBroadcast(<?=$b['id']?>)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach;if(empty($broadcasts)):?>
                <tr>
                    <td colspan="4" class="empty-state" style="padding:40px;">
                        <i class="fas fa-bell-slash" style="font-size:32px; color:#CBD5E1; margin-bottom:12px;"></i><br>
                        No broadcasts sent yet.
                    </td>
                </tr>
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
    
    const btn = document.getElementById('sendBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:8px;"></i> Sending...';
    
    const fd=new FormData();
    fd.append('csrf',CSRF);
    fd.append('action','send');
    fd.append('title',title);
    fd.append('message',msg);
    fd.append('type',document.getElementById('type').value);
    
    try {
        const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
        showToast(r.message,r.success);
        if(r.success)setTimeout(()=>location.reload(),1000);
    } catch(e) {
        showToast('Network error occurred',false);
    }
    
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-paper-plane" style="margin-right:8px;"></i> Send to All Users';
}

async function deleteBroadcast(id){
    if(!confirm('Delete this broadcast? It will be removed from the user dashboard marquee immediately.'))return;
    const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','delete');fd.append('id',id);
    const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    showToast(r.message,r.success);
    if(r.success)setTimeout(()=>location.reload(),1000);
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
