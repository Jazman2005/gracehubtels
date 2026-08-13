<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_admin('admin'); // Admin or Superadmin
$csrf = admin_csrf();

if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??'')){ echo json_encode(['success'=>false,'message'=>'Invalid token']); exit; }
    $action = $_POST['action']??'';
    if($action==='send_campaign'){
        $subject = sanitize($_POST['subject']??'', 255);
        $message = trim($_POST['message']??'');
        $target = in_array($_POST['target']??'',['all','active','vendors','specific'])?$_POST['target']:'all';
        
        if(!$subject || !$message) {
            echo json_encode(['success'=>false,'message'=>'Subject and Message are required']);
            exit;
        }
        
        if ($target === 'specific') {
            $identifier = trim($_POST['user_identifier']??'');
            if (!$identifier) {
                echo json_encode(['success'=>false,'message'=>'Enter user email or phone']);
                exit;
            }
            $field = str_contains($identifier, '@') ? 'email' : 'phone';
            $stmt = db()->prepare("SELECT email, full_name FROM users WHERE $field = ? AND is_active=1 LIMIT 1");
            $stmt->execute([$identifier]);
            $user = $stmt->fetch();
            if (!$user) {
                echo json_encode(['success'=>false,'message'=>'User not found with that '.$field]);
                exit;
            }
            $users = [$user];
        } else {
            $where = match($target){'active'=>'is_active=1 AND last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY)','vendors'=>"is_active=1 AND user_type='vendor'",default=>'is_active=1'};
            $users = db()->query("SELECT email, full_name FROM users WHERE $where")->fetchAll();
        }
        $count = count($users);
        
        if($count === 0){
            echo json_encode(['success'=>false,'message'=>'No users match this target group']);
            exit;
        }

        // Close connection so the frontend doesn't hang while sending emails
        echo json_encode(['success'=>true,'message'=>"Campaign queued! It will be sent to $count users."]);
        
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            session_write_close();
            ob_end_flush();
            flush();
        }

        ignore_user_abort(true);
        set_time_limit(0);

        $sent = 0;
        foreach($users as $u) {
            if(!empty($u['email']) && filter_var($u['email'], FILTER_VALIDATE_EMAIL)) {
                $personalized_message = str_replace('{name}', $u['full_name'], $message);
                if (send_email($u['email'], $subject, $personalized_message)) {
                    $sent++;
                }
            }
        }
        
        // Log the campaign
        db()->prepare("INSERT INTO email_campaigns (subject, message, target, total_recipients, status, sent_by) VALUES (?, ?, ?, ?, 'completed', ?)")
            ->execute([$subject, $message, $target, $sent, $_SESSION['admin_id']]);
            
        audit_log($_SESSION['admin_id'], 'EMAIL_CAMPAIGN', "Sent email campaign '$subject' to $sent recipients ($target)");
        exit;
    }
}

$campaigns = db()->query("SELECT e.*, a.username FROM email_campaigns e LEFT JOIN admin a ON e.sent_by=a.id ORDER BY e.id DESC LIMIT 50")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Email Marketing — <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title><link rel="icon" type="image/png" href="/assets/images/favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header" style="margin-bottom:20px">
  <div><h2>Email Campaigns</h2><p>Send marketing and announcement blasts to users</p></div>
  <button class="btn-primary" onclick="document.getElementById('composeModal').style.display='flex'">+ Compose Email</button>
</div>

<div class="data-panel">
  <div class="responsive-grid-wrapper">
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
    <div class="modal-header"><h3>Compose Email Campaign</h3><button class="modal-close" onclick="document.getElementById('composeModal').style.display='none'">&times;</button></div>
    <div class="modal-body">
      <div class="form-group" style="margin-bottom:12px">
        <label>Target Audience</label>
        <select class="form-control" id="target" onchange="toggleUserInput()">
          <option value="all">All Registered Users</option>
          <option value="active">Active Users (Logged in within 30 days)</option>
          <option value="vendors">Vendors Only</option>
          <option value="specific">Specific User</option>
        </select>
      </div>
      <div class="form-group" style="margin-bottom:12px;display:none" id="userField">
        <label>User Email or Phone</label>
        <input type="text" class="form-control" id="user_identifier" placeholder="e.g. user@email.com or 08012345678">
      </div>
      <div class="form-group" style="margin-bottom:12px"><label>Subject Line</label><input type="text" class="form-control" id="subject" placeholder="e.g. Exciting New Data Prices!"></div>
      <div class="form-group" style="margin-bottom:16px"><label>Email Message (HTML supported)</label><textarea class="form-control" id="message" rows="8" placeholder="<h1>Hello Users!</h1>..."></textarea></div>
      <button class="btn-primary" style="width:100%;height:44px;justify-content:center" onclick="sendCampaign()">Queue Campaign</button>
    </div>
  </div>
</div>

<div class="save-toast" id="toast"></div>

<script>
const CSRF_TOKEN = <?=json_encode($csrf)?>;
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
function toggleUserInput(){document.getElementById('userField').style.display=document.getElementById('target').value==='specific'?'block':'none';}
async function sendCampaign(){
  const s=document.getElementById('subject').value, m=document.getElementById('message').value, t=document.getElementById('target').value;
  if(!s||!m)return showToast('Subject and message required',false);
  const fd=new FormData();fd.append('action','send_campaign');fd.append('subject',s);fd.append('message',m);fd.append('target',t);fd.append('csrf',CSRF_TOKEN);
  if(t==='specific')fd.append('user_identifier',document.getElementById('user_identifier').value);
  const r=await fetch('',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
  showToast(r.message,r.success);
  if(r.success)setTimeout(()=>location.reload(),1500);
}
function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),3000);}
</script>
</body></html>
