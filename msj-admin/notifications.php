<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    $title=sanitize($_POST['title']??'',200);$msg=sanitize($_POST['message']??'',500);$type=in_array($_POST['type']??'',['info','success','warning','danger'])?$_POST['type']:'info';
    if(!$title||!$msg)json_response(['success'=>false,'message'=>'Title and message required']);
    db()->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (NULL,?,?,?)")->execute([$title,$msg,$type]);
    json_response(['success'=>true,'message'=>'Broadcast sent to all users']);
}
$csrf=admin_csrf();
$broadcasts=db()->query("SELECT * FROM notifications WHERE user_id IS NULL ORDER BY created_at DESC LIMIT 30")->fetchAll();
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Notifications – MsjPay Admin</title><link rel="icon" type="image/png" href="/msjpay/assets/images/msjpay-favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="/msjpay/msj-admin/css/admin.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="page-content">
<div class="page-head"><div><h2>Notifications</h2><p>Send broadcast notifications shown in the dashboard ticker</p></div></div>
<div class="card"><div class="card-header"><h3>New Broadcast</h3></div>
<div class="modal-body" style="display:flex;flex-direction:column;gap:14px">
  <div class="form-group"><label>Title</label><input type="text" class="form-control" id="title" placeholder="e.g. New Feature!"></div>
  <div class="form-group"><label>Message</label><textarea class="form-control" id="message" rows="3" placeholder="Notification content..."></textarea></div>
  <div class="form-group"><label>Type</label><select class="form-control" id="type"><option value="info">Info</option><option value="success">Success</option><option value="warning">Warning</option><option value="danger">Danger</option></select></div>
  <button class="btn-primary" onclick="sendBroadcast()">Send to All Users</button>
</div></div>
<div class="card"><div class="card-header"><h3>Recent Broadcasts</h3></div>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Title</th><th>Message</th><th>Type</th><th>Date</th></tr></thead><tbody>
<?php foreach($broadcasts as $b):?><tr><td><strong><?=htmlspecialchars($b['title'])?></strong></td><td style="font-size:12px;max-width:300px"><?=htmlspecialchars(substr($b['message'],0,80))?></td><td><span class="badge badge-info"><?=ucfirst($b['type'])?></span></td><td style="font-size:12px;color:#6B7280"><?=date('d M, H:i',strtotime($b['created_at']))?></td></tr><?php endforeach;if(empty($broadcasts)):?><tr><td colspan="4" class="empty-state">No broadcasts sent yet</td></tr><?php endif;?>
</tbody></table></div></div>
</div></div>
<div class="save-toast" id="toast"></div>
<script>
const CSRF='<?=$csrf?>';
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
async function sendBroadcast(){const title=document.getElementById('title').value.trim();const msg=document.getElementById('message').value.trim();if(!title||!msg)return showToast('Fill all fields',false);const fd=new FormData();fd.append('csrf',CSRF);fd.append('title',title);fd.append('message',msg);fd.append('type',document.getElementById('type').value);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),1000);}
function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),2500);}
</script>
</body></html>
