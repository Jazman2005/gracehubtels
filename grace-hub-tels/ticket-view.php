<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_admin('support');
$csrf = admin_csrf();

$ticket_id = (int)($_GET['id']??0);
if(!$ticket_id) { header("Location: tickets.php"); exit; }

$ticket = db()->prepare("SELECT t.*, u.full_name, u.email, u.phone FROM tickets t JOIN users u ON t.user_id=u.id WHERE t.id=?");
$ticket->execute([$ticket_id]);
$ticket = $ticket->fetch();
if(!$ticket) { header("Location: tickets.php"); exit; }

if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??'')){ echo json_encode(['success'=>false,'message'=>'Invalid token']); exit; }
    $action = $_POST['action']??'';
    if($action==='reply'){
        $message = trim($_POST['message']??'');
        if(!$message) { echo json_encode(['success'=>false]); exit; }
        
        try {
            db()->prepare("INSERT INTO ticket_messages (ticket_id, sender_type, sender_id, message) VALUES (?, 'admin', ?, ?)")->execute([$ticket_id, $_SESSION['admin_id'], $message]);
            db()->prepare("UPDATE tickets SET status='pending', updated_at=NOW() WHERE id=?")->execute([$ticket_id]);
            // Here you would trigger an email to the user if SMTP was configured.
            echo json_encode(['success'=>true]);
            exit;
        } catch(Exception $e) {
            echo json_encode(['success'=>false,'message'=>'Failed to send message']);
            exit;
        }
    }
    if($action==='change_status'){
        $status = $_POST['status']??'open';
        db()->prepare("UPDATE tickets SET status=?, updated_at=NOW() WHERE id=?")->execute([$status, $ticket_id]);
        echo json_encode(['success'=>true]);
        exit;
    }
}

$messages = db()->prepare("SELECT m.*, a.username FROM ticket_messages m LEFT JOIN admin a ON m.sender_id=a.id AND m.sender_type='admin' WHERE m.ticket_id=? ORDER BY m.id ASC");
$messages->execute([$ticket_id]);
$messages = $messages->fetchAll();

$badgeColor = $ticket['status'] === 'open' ? 'danger' : ($ticket['status'] === 'pending' ? 'warning' : 'success');
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Ticket #<?=$ticket_id?> — <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title><link rel="icon" type="image/png" href="/assets/images/favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css">
<style>
.chat-box{height:calc(100vh - 280px);min-height:400px;background:#F9FAFB;border-radius:12px;padding:20px;overflow-y:auto;display:flex;flex-direction:column;gap:16px;border:1px solid #E5E7EB}
.msg{max-width:70%;padding:14px;border-radius:14px;font-size:14px;line-height:1.5}
.msg.user{background:#fff;border:1px solid #E5E7EB;color:#1F2937;align-self:flex-start;border-top-left-radius:4px}
.msg.admin{background:var(--navy);color:#fff;align-self:flex-end;border-top-right-radius:4px}
.msg-meta{font-size:11px;opacity:0.7;margin-bottom:4px;display:block}
.ticket-layout{display:grid;grid-template-columns:3fr 1fr;gap:20px}
@media (max-width:1024px){.ticket-layout{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header" style="margin-bottom:14px">
  <div style="display:flex;align-items:center;gap:12px">
    <a href="tickets.php" class="btn-ghost" style="padding:8px 12px">&larr; Back</a>
    <div><h2 style="font-size:20px"><?=htmlspecialchars($ticket['subject'])?></h2><p>Ticket #<?=$ticket_id?> &bull; Opened <?=date('d M, Y', strtotime($ticket['created_at']))?></p></div>
  </div>
  <div style="display:flex;gap:10px;align-items:center">
    <span class="badge badge-<?=$badgeColor?>" id="statusBadge"><?=strtoupper($ticket['status'])?></span>
    <select class="form-control" style="width:140px;height:38px" onchange="changeStatus(this.value)">
      <option value="open" <?=$ticket['status']==='open'?'selected':''?>>Mark Open</option>
      <option value="pending" <?=$ticket['status']==='pending'?'selected':''?>>Mark Pending</option>
      <option value="resolved" <?=$ticket['status']==='resolved'?'selected':''?>>Mark Resolved</option>
    </select>
  </div>
</div>

<div class="ticket-layout">
  
  <div class="data-panel" >
    <div class="chat-box" id="chatWindow">
      <?php foreach($messages as $m): $is_admin = $m['sender_type']==='admin'; ?>
      <div class="msg <?=$is_admin?'admin':'user'?>">
        <span class="msg-meta"><?=$is_admin ? ($m['username']??'Admin') : htmlspecialchars($ticket['full_name'])?> &bull; <?=date('h:i A', strtotime($m['created_at']))?></span>
        <?=nl2br(htmlspecialchars($m['message']))?>
      </div>
      <?php endforeach; ?>
    </div>
    
    <div style="padding:16px;border-top:1px solid #E5E7EB;display:flex;gap:12px">
      <textarea id="replyMsg" class="form-control" rows="2" style="resize:none;flex-grow:1" placeholder="Type your reply to <?=htmlspecialchars($ticket['full_name'])?>..."></textarea>
      <button class="btn-primary" style="height:auto" onclick="sendReply()">Send Reply</button>
    </div>
  </div>
  
  <div>
    <div class="data-panel" style="margin-bottom:20px">
      <h3 style="font-size:14px;border-bottom:1px solid var(--border);padding-bottom:10px;margin-bottom:10px">User Details</h3>
      <div style="font-size:13px;line-height:1.8">
        <strong>Name:</strong> <?=htmlspecialchars($ticket['full_name'])?><br>
        <strong>Email:</strong> <?=htmlspecialchars($ticket['email']??'-')?><br>
        <strong>Phone:</strong> <?=htmlspecialchars($ticket['phone']??'-')?><br>
        <button class="btn-primary btn-sm" style="width:100%;justify-content:center;margin-top:10px" onclick="location.href='users-management.php?q=<?=$ticket['user_id']?>'">Manage Wallet</button>
      </div>
    </div>
    <div class="data-panel">
      <h3 style="font-size:14px;border-bottom:1px solid var(--border);padding-bottom:10px;margin-bottom:10px">Ticket Info</h3>
      <div style="font-size:13px;line-height:1.8">
        <strong>Priority:</strong> <?=ucfirst($ticket['priority'])?><br>
        <strong>Created:</strong> <?=date('d M Y, h:ia', strtotime($ticket['created_at']))?><br>
        <strong>Last Update:</strong> <span id="lUpdate"><?=date('d M Y, h:ia', strtotime($ticket['updated_at']))?></span>
      </div>
    </div>
  </div>

</div>

</div></div>
<div class="save-toast" id="toast"></div>

<script>
const CSRF_TOKEN = <?=json_encode($csrf)?>;
const chat = document.getElementById('chatWindow');
if(chat) chat.scrollTop = chat.scrollHeight;

async function sendReply() {
    const m = document.getElementById('replyMsg').value;
    if(!m.trim()) return;
    const fd = new FormData(); fd.append('action','reply'); fd.append('message',m); fd.append('csrf',CSRF_TOKEN);
    const r = await fetch('',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    if(r.success) location.reload();
}
async function changeStatus(st) {
    const fd = new FormData(); fd.append('action','change_status'); fd.append('status',st); fd.append('csrf',CSRF_TOKEN);
    const r = await fetch('',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    if(r.success) showToast("Ticket marked as " + st, true);
    setTimeout(() => location.reload(), 800);
}
function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),2500);}
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
</script>
</body></html>

