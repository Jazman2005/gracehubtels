<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    require_permission('manage_messaging');
    $action = $_POST['action'] ?? '';
    if($action === 'save_smtp') {
        $allowed=['smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from_name','smtp_from_email','smtp_encryption'];
        foreach($_POST as $key=>$val){if(in_array($key,$allowed)){setting_set($key,trim((string)$val));}}
        json_response(['success'=>true,'message'=>'SMTP settings saved']);
    }
    if($action === 'test_email'){
        $to = trim($_POST['to'] ?? '');
        if(!$to) json_response(['success'=>false,'message'=>'Recipient email required']);
        $subject = 'Test Email from '.setting('site_name','Gracehubtels');
        $body = 'This is a test email to confirm your SMTP configuration is working correctly.';
        $ok = send_email($to, $subject, $body);
        $err = $GLOBALS['_last_email_error'] ?? '';
        if(!$ok && !$err){ $err = 'Unknown error — check logs/api_*.log'; }
        json_response(['success'=>$ok,'message'=>$ok?'Test email sent successfully! Check your inbox.':'Failed: '.$err]);
    }
    if($action === 'send_bulk') {
        $subject=sanitize($_POST['subject']??'',255);$message=sanitize($_POST['message']??'',2000);$target=$_POST['target']??'all';
        if(!$subject||!$message)json_response(['success'=>false,'message'=>'Subject and message required']);
        $targets=['all','active','inactive','kyc_verified','no_kyc'];
        $where=in_array($target,$targets,true)?match($target){'active'=>'is_active=1','inactive'=>'is_active=0',default=>'1=1'}:'1=1';
        $users=db()->query("SELECT id,email,full_name FROM users WHERE $where AND email IS NOT NULL LIMIT 200")->fetchAll();
        $sent=0;$fail=0;
        foreach($users as $u){$body=str_replace(['{name}','{email}'],[$u['full_name'],$u['email']],$message);if(send_email($u['email'],$subject,$body)){$sent++;}else{$fail++;}}
        db()->prepare("INSERT INTO email_campaigns (subject,message,target,total_recipients,status,sent_by) VALUES (?,?,?,?,'completed',?)")->execute([$subject,$message,$target,$sent,$_SESSION['admin_id']]);
        json_response(['success'=>true,'message'=>"Sent to {$sent} users" . ($fail?", {$fail} failed":'')]);
    }
}
function k(string $key):string{return htmlspecialchars(setting($key));}
// SMTP password must not render for viewers who can't act on it — auth_admin()
// alone (no role/permission) lets any admin, including 'support', load this
// page; only the manage_messaging-gated POST above can change it.
function k_secret(string $key):string{return has_permission('manage_messaging')?htmlspecialchars(setting($key)):'';}
$csrf=admin_csrf();$campaigns=db()->query("SELECT * FROM email_campaigns ORDER BY created_at DESC LIMIT 20")->fetchAll();
$smtp_encryption = setting('smtp_encryption', 'tls');
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Email Configuration – <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title><link rel="icon" type="image/png" href="/assets/images/favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"><style>
.smtp-guide{background:#F8FAFC;border:1px solid #E2E8F0;border-radius:12px;padding:20px;margin-top:16px;}
.smtp-guide h4{font-weight:700;font-size:14px;color:var(--navy-dark);margin:0 0 12px 0;}
.smtp-guide code{background:#E2E8F0;padding:2px 6px;border-radius:4px;font-size:12px;}
.smtp-guide .provider-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #E2E8F0;}
.smtp-guide .provider-row:last-child{border-bottom:none;}
</style></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header"><div><h2><i class="fas fa-envelope" style="margin-right:10px;color:var(--primary);"></i> Email Configuration</h2><p>Configure SMTP so users receive email notifications for logins, signups, and transactions.</p></div></div>

<div class="data-panel" style="margin-bottom:24px;">
  <div class="panel-title-bar"><h3><i class="fas fa-server" style="margin-right:8px;"></i> SMTP Connection</h3></div>
  <div class="modal-body" style="display:flex;flex-direction:column;gap:14px">
    <div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;padding:14px;font-size:13px;color:#1E40AF;display:flex;align-items:flex-start;gap:10px;">
      <i class="fas fa-info-circle" style="margin-top:2px;"></i>
      <div>Email notifications are sent for: <strong>user login</strong>, <strong>new signups</strong>, <strong>transactions</strong> (debit/credit), <strong>cashback</strong>, and <strong>KYC updates</strong>. Configure SMTP below to enable delivery.</div>
    </div>
    <div class="form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="form-group"><label>SMTP Host <span style="color:var(--danger);">*</span></label><input type="text" class="form-control" id="smtp_host" value="<?=k('smtp_host')?>" placeholder="e.g. smtp.gmail.com"></div>
        <div class="form-group"><label>Port <span style="color:var(--danger);">*</span></label><input type="text" class="form-control" id="smtp_port" value="<?=k('smtp_port')?>" placeholder="587 (TLS) or 465 (SSL)"></div>
    </div>
    <div class="form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="form-group"><label>Encryption</label>
            <select class="form-control" id="smtp_encryption">
                <option value="tls" <?=$smtp_encryption==='tls'?'selected':''?>>TLS (Port 587)</option>
                <option value="ssl" <?=$smtp_encryption==='ssl'?'selected':''?>>SSL (Port 465)</option>
                <option value="none" <?=$smtp_encryption==='none'?'selected':''?>>None</option>
            </select></div>
        <div class="form-group"><label>SMTP Username <span style="color:var(--danger);">*</span></label><input type="text" class="form-control" id="smtp_user" value="<?=k('smtp_user')?>" placeholder="your@email.com"></div>
    </div>
    <div class="form-group"><label>SMTP Password <span style="color:var(--danger);">*</span></label>
        <div style="display:flex;gap:8px;"><input type="password" class="form-control" id="smtp_pass" value="<?=k_secret('smtp_pass')?>" placeholder="App password or SMTP password" style="flex:1;">
        <button class="btn-primary" onclick="togglePass()" style="padding:0 14px;border-radius:8px;font-size:14px;background:#E2E8F0;color:#475569;" type="button"><i class="fas fa-eye" id="eyeIcon"></i></button></div>
    </div>
    <div class="form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="form-group"><label>From Name</label><input type="text" class="form-control" id="smtp_from_name" value="<?=k('smtp_from_name')?>" placeholder="Gracehubtels"></div>
        <div class="form-group"><label>From Email</label><input type="text" class="form-control" id="smtp_from_email" value="<?=k('smtp_from_email')?>" placeholder="noreply@gracehubtels.com"></div>
    </div>
    <div style="display:flex;gap:12px;margin-top:4px;">
        <button class="btn-primary" onclick="saveSMTP()" style="background:var(--primary);"><i class="fas fa-save" style="margin-right:6px;"></i> Save SMTP</button>
        <button class="btn-primary" onclick="testSMTP()" style="background:var(--success);"><i class="fas fa-paper-plane" style="margin-right:6px;"></i> Send Test Email</button>
    </div>

    <!-- SMTP Provider Guide -->
    <div class="smtp-guide">
        <h4><i class="fas fa-book" style="margin-right:6px;"></i> SMTP Provider Quick Setup</h4>
        <div class="provider-row">
            <span><strong>Gmail</strong></span>
            <span style="font-size:12px;color:var(--text-muted);">Host: <code>smtp.gmail.com</code> | Port: <code>587</code> | Use App Password (enable 2FA → Google Account → App Passwords)</span>
        </div>
        <div class="provider-row">
            <span><strong>Outlook/Hotmail</strong></span>
            <span style="font-size:12px;color:var(--text-muted);">Host: <code>smtp.office365.com</code> | Port: <code>587</code> | Use your regular email password</span>
        </div>
        <div class="provider-row">
            <span><strong>Yahoo Mail</strong></span>
            <span style="font-size:12px;color:var(--text-muted);">Host: <code>smtp.mail.yahoo.com</code> | Port: <code>465</code> | Encryption: SSL | Use App Password</span>
        </div>
        <div class="provider-row">
            <span><strong>cPanel Email</strong></span>
            <span style="font-size:12px;color:var(--text-muted);">Host: <code>mail.yourdomain.com</code> | Port: <code>465</code> or <code>587</code> | Use your cPanel email address & password</span>
        </div>
        <div class="provider-row">
            <span><strong>SendGrid</strong></span>
            <span style="font-size:12px;color:var(--text-muted);">Host: <code>smtp.sendgrid.net</code> | Port: <code>587</code> | User: <code>apikey</code> | Pass: Your SendGrid API Key</span>
        </div>
        <div class="provider-row">
            <span><strong>Mailgun</strong></span>
            <span style="font-size:12px;color:var(--text-muted);">Host: <code>smtp.mailgun.org</code> | Port: <code>587</code> | Use your Mailgun SMTP credentials</span>
        </div>
    </div>
  </div>
</div>

<div class="data-panel" style="margin-bottom:24px;">
  <div class="panel-title-bar"><h3><i class="fas fa-bullhorn" style="margin-right:8px;"></i> Bulk Email Campaign</h3></div>
  <div class="modal-body" style="display:flex;flex-direction:column;gap:14px">
    <div class="form-group"><label>Subject</label><input type="text" class="form-control" id="subject"></div>
    <div class="form-group"><label>Message</label><textarea class="form-control" id="message" rows="5"></textarea></div>
    <div class="form-group"><label>Target Audience</label><select class="form-control" id="target"><option value="all">All Users</option><option value="active">Active Users</option><option value="inactive">Inactive Users</option><option value="kyc_verified">KYC Verified</option><option value="no_kyc">No KYC</option></select></div>
    <button class="btn-primary" onclick="sendCampaign()"><i class="fas fa-paper-plane" style="margin-right:6px;"></i> Send Campaign</button>
  </div>
</div>

<div class="data-panel"><div class="panel-title-bar"><h3>Past Campaigns</h3></div>
<div class="responsive-grid-wrapper"><table class="data-table"><thead><tr><th>Subject</th><th>Target</th><th>Recipients</th><th>Status</th><th>Date</th></tr></thead><tbody>
<?php foreach($campaigns as $c):?><tr><td><strong><?=htmlspecialchars($c['subject'])?></strong></td><td><?=ucwords(str_replace('_',' ',$c['target']))?></td><td><?=number_format($c['total_recipients'])?></td><td><span class="badge badge-success"><?=ucfirst($c['status'])?></span></td><td style="font-size:12px;color:#6B7280"><?=date('d M Y',strtotime($c['created_at']))?></td></tr><?php endforeach;if(empty($campaigns)):?><tr><td colspan="5" class="empty-state">No campaigns sent yet</td></tr><?php endif;?>
</tbody></table></div></div>

</div></div>
<div class="save-toast" id="toast"></div>
<script>
const CSRF='<?=$csrf?>';
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}

function togglePass(){
    const p = document.getElementById('smtp_pass');
    const e = document.getElementById('eyeIcon');
    if(p.type==='password'){p.type='text';e.className='fas fa-eye-slash';} else {p.type='password';e.className='fas fa-eye';}
}

async function saveSMTP(){
    const fd=new FormData();
    fd.append('csrf',CSRF);
    fd.append('action','save_smtp');
    ['smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from_name','smtp_from_email','smtp_encryption'].forEach(id=>fd.append(id,document.getElementById(id).value));
    const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    showToast(r.message,r.success);
}

async function testSMTP(){
    const email = prompt('Enter email address to send test email:');
    if(!email) return;
    const fd=new FormData();
    fd.append('csrf',CSRF);
    fd.append('action','test_email');
    fd.append('to',email);
    const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    showToast(r.message,r.success);
}

async function sendCampaign(){const subject=document.getElementById('subject').value.trim();const message=document.getElementById('message').value.trim();if(!subject||!message)return showToast('Fill all fields',false);const fd=new FormData();fd.append('csrf',CSRF);fd.append('subject',subject);fd.append('message',message);fd.append('target',document.getElementById('target').value);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),1200);}
function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),2500);}
</script>
</body></html>
