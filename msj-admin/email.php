<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    $subject=sanitize($_POST['subject']??'',255);$message=sanitize($_POST['message']??'',2000);$target=$_POST['target']??'all';
    if(!$subject||!$message)json_response(['success'=>false,'message'=>'Subject and message required']);
    $where=['all'=>'1=1','active'=>'is_active=1','inactive'=>'is_active=0','kyc_verified'=>'kyc_level>0','no_kyc'=>'kyc_level=0'][$target]??'1=1';
    $count=(int)db()->query("SELECT COUNT(*) FROM users WHERE $where AND email IS NOT NULL")->fetchColumn();
    db()->prepare("INSERT INTO email_campaigns (subject,message,target,total_recipients,status,sent_by) VALUES (?,?,?,?,'completed',?)")->execute([$subject,$message,$target,$count,$_SESSION['admin_id']]);
    json_response(['success'=>true,'message'=>"Campaign queued for {$count} recipients"]);
}
$csrf=admin_csrf();$campaigns=db()->query("SELECT * FROM email_campaigns ORDER BY created_at DESC LIMIT 20")->fetchAll();
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Email Blast – MsjPay Admin</title><link rel="icon" type="image/png" href="/msjpay/assets/images/msjpay-favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="/msjpay/msj-admin/css/admin.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="page-content">
<div class="page-head"><div><h2>Email Campaigns</h2><p>Send bulk emails to user segments</p></div></div>
<div class="card"><div class="card-header"><h3>New Campaign</h3></div>
<div class="modal-body" style="display:flex;flex-direction:column;gap:14px">
  <div class="form-group"><label>Subject</label><input type="text" class="form-control" id="subject"></div>
  <div class="form-group"><label>Message</label><textarea class="form-control" id="message" rows="5"></textarea></div>
  <div class="form-group"><label>Target Audience</label><select class="form-control" id="target"><option value="all">All Users</option><option value="active">Active Users</option><option value="inactive">Inactive Users</option><option value="kyc_verified">KYC Verified</option><option value="no_kyc">No KYC</option></select></div>
  <button class="btn-primary" onclick="sendCampaign()">Send Campaign</button>
</div></div>
<div class="card"><div class="card-header"><h3>Past Campaigns</h3></div>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Subject</th><th>Target</th><th>Recipients</th><th>Status</th><th>Date</th></tr></thead><tbody>
<?php foreach($campaigns as $c):?><tr><td><strong><?=htmlspecialchars($c['subject'])?></strong></td><td><?=ucwords(str_replace('_',' ',$c['target']))?></td><td><?=number_format($c['total_recipients'])?></td><td><span class="badge badge-success"><?=ucfirst($c['status'])?></span></td><td style="font-size:12px;color:#6B7280"><?=date('d M Y',strtotime($c['created_at']))?></td></tr><?php endforeach;if(empty($campaigns)):?><tr><td colspan="5" class="empty-state">No campaigns sent yet</td></tr><?php endif;?>
</tbody></table></div></div>
</div></div>
<div class="save-toast" id="toast"></div>
<script>
const CSRF='<?=$csrf?>';
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
async function sendCampaign(){const subject=document.getElementById('subject').value.trim();const message=document.getElementById('message').value.trim();if(!subject||!message)return showToast('Fill all fields',false);const fd=new FormData();fd.append('csrf',CSRF);fd.append('subject',subject);fd.append('message',message);fd.append('target',document.getElementById('target').value);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),1200);}
function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),2500);}
</script>
</body></html>
