<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_admin('admin'); // Admin or Superadmin

if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    $action = $_POST['action']??'';
    if($action==='send_campaign'){
        $subject = sanitize($_POST['subject']??'', 255);
        $message = trim($_POST['message']??'');
        $target = in_array($_POST['target']??'',['all','active','vendors'])?$_POST['target']:'all';
        
        if(!$subject || !$message) {
            echo json_encode(['success'=>false,'message'=>'Subject and Message are required']);
            exit;
        }
        
        $where = "is_active=1";
        if($target==='active') $where .= " AND last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        if($target==='vendors') $where .= " AND user_type='vendor'";
        
        $count = (int)db()->query("SELECT COUNT(*) FROM users WHERE $where")->fetchColumn();
        
        if($count === 0){
            echo json_encode(['success'=>false,'message'=>'No users match this target group']);
            exit;
        }
        
        // Log the campaign (pretend it's queued since no SMTP is active)
        db()->prepare("INSERT INTO email_campaigns (subject, message, target, total_recipients, status, sent_by) VALUES (?, ?, ?, ?, 'queued', ?)")
            ->execute([$subject, $message, $target, $count, $_SESSION['admin_id']]);
            
        audit_log($_SESSION['admin_id'], 'EMAIL_CAMPAIGN', "Queued email campaign '$subject' to $count recipients ($target)");
        
        echo json_encode(['success'=>true,'message'=>"Campaign queued! It will be sent to $count users."]);
        exit;
    }
}

$campaigns = db()->query("SELECT e.*, a.username FROM email_campaigns e LEFT JOIN admin a ON e.sent_by=a.id ORDER BY e.id DESC LIMIT 50")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Email Marketing – MsjPay Admin</title><link rel="icon" type="image/png" href="/msjpay/assets/images/msjpay-favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="/msjpay/msj-admin/css/admin.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="page-content">

<div class="page-head" style="margin-bottom:20px">
  <div><h2>Email Campaigns</h2><p>Send marketing and announcement blasts to users</p></div>
  <button class="btn-primary" onclick="document.getElementById('composeModal').style.display='flex'">+ Compose Email</button>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Date</th><th>Subject</th><th>Target Audience</th><th>Recipients</th><th>Status</th><th>Sender</th></tr></thead>
      <tbody>
      <?php foreach($campaigns as $c):?>
      <tr>
        <td style="font-size:12px;color:var(--text-muted);white-space:nowrap"><?=date('d M Y, H:i',strtotime($c['created_at']))?></td>
        <td><strong><?=htmlspecialchars($c['subject'])?></strong></td>
        <td><span class="badge badge-neutral"><?=strtoupper($c['target'])?></span></td>
        <td style="font-weight:700"><?=number_format($c['total_recipients'])?></td>
        <td><span class="badge badge-<?=$c['status']==='completed'?'success':($c['status']==='queued'?'warning':'danger')?>"><?=strtoupper($c['status'])?></span></td>
        <td style="font-size:12px"><?=htmlspecialchars($c['username']??'Admin')?></td>
      </tr>
      <?php endforeach;if(empty($campaigns)):?><tr><td colspan="6" class="empty-state">No email campaigns sent yet</td></tr><?php endif;?>
      </tbody>
    </table>
  </div>
</div>

</div></div>

<!-- Compose Modal -->
<div class="modal-overlay" id="composeModal" style="display:none">
  <div class="modal" style="max-width:700px">
    <div class="modal-header"><h3>Compose Email Campaign</h3><button class="modal-close" onclick="document.getElementById('composeModal').style.display='none'">✕</button></div>
    <div class="modal-body">
      <div style="background:#FEF3C7;color:#D97706;padding:12px;border-radius:8px;font-size:12px;margin-bottom:16px;border:1px solid #FDE68A">
        <strong>Notice:</strong> SMTP is not currently configured. Emails will be logged to the database but not actually delivered to user inboxes.
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label>Target Audience</label>
        <select class="form-control" id="target">
          <option value="all">All Registered Users</option>
          <option value="active">Active Users (Logged in within 30 days)</option>
          <option value="vendors">Vendors Only</option>
        </select>
      </div>
      <div class="form-group" style="margin-bottom:12px"><label>Subject Line</label><input type="text" class="form-control" id="subject" placeholder="e.g. Exciting New Data Prices!"></div>
      <div class="form-group" style="margin-bottom:16px"><label>Email Message (HTML supported)</label><textarea class="form-control" id="message" rows="8" placeholder="<h1>Hello Users!</h1>..."></textarea></div>
      <button class="btn-primary" style="width:100%;height:44px;justify-content:center" onclick="sendCampaign()">Queue Campaign</button>
    </div>
  </div>
</div>

<div class="save-toast" id="toast"></div>

<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
async function sendCampaign(){
  const s=document.getElementById('subject').value, m=document.getElementById('message').value, t=document.getElementById('target').value;
  if(!s||!m)return showToast('Subject and message required',false);
  const fd=new FormData();fd.append('action','send_campaign');fd.append('subject',s);fd.append('message',m);fd.append('target',t);
  const r=await fetch('',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
  showToast(r.message,r.success);
  if(r.success)setTimeout(()=>location.reload(),1500);
}
function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),3000);}
</script>
</body></html>
