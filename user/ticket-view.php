<?php
require_once __DIR__.'/core/auth.php';
auth_user();
$uid = $_SESSION['user_id'];
$user = get_user($uid);
$name = explode(' ', $user['full_name'])[0];

$ticket_id = (int)($_GET['id']??0);
if(!$ticket_id) { header("Location: support.php"); exit; }

$ticket = db()->prepare("SELECT * FROM tickets WHERE id=? AND user_id=?");
$ticket->execute([$ticket_id, $uid]);
$ticket = $ticket->fetch();
if(!$ticket) { header("Location: support.php"); exit; }

if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    $action = $_POST['action']??'';
    if($action==='reply'){
        $message = trim($_POST['message']??'');
        if(!$message) { echo json_encode(['success'=>false]); exit; }
        
        try {
            db()->prepare("INSERT INTO ticket_messages (ticket_id, sender_type, sender_id, message) VALUES (?, 'user', ?, ?)")->execute([$ticket_id, $uid, $message]);
            db()->prepare("UPDATE tickets SET updated_at=NOW() WHERE id=?")->execute([$ticket_id]);
            echo json_encode(['success'=>true]);
            exit;
        } catch(Exception $e) {
            echo json_encode(['success'=>false,'message'=>'Failed to send message']);
            exit;
        }
    }
}

$messages = db()->prepare("SELECT * FROM ticket_messages WHERE ticket_id=? ORDER BY id ASC");
$messages->execute([$ticket_id]);
$messages = $messages->fetchAll();

$page_title = 'Ticket #'.$ticket_id; 
include __DIR__ . '/includes/header.php'; 
include __DIR__ . '/includes/sidebar.php'; 

$badgeColor = $ticket['status'] === 'open' ? 'bg-red-100 text-red-600' : ($ticket['status'] === 'pending' ? 'bg-amber-100 text-amber-600' : 'bg-green-100 text-green-600');
?>

<div class="mb-6 flex items-center justify-between">
    <div class="flex items-center gap-3">
        <a href="support.php" class="w-10 h-10 bg-white rounded-full flex items-center justify-center text-slate-500 hover:text-brand-navy hover:bg-slate-100 shadow-sm border border-slate-200 transition-colors">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div>
            <h2 class="text-xl font-bold text-slate-800 leading-tight"><?=htmlspecialchars($ticket['subject'])?></h2>
            <div class="flex items-center gap-2 mt-1">
                <span class="text-[10px] font-bold px-2 py-0.5 rounded-md <?=$badgeColor?> uppercase tracking-wider"><?=htmlspecialchars($ticket['status'])?></span>
                <span class="text-xs text-slate-500">Ticket #<?=$ticket_id?></span>
            </div>
        </div>
    </div>
</div>

<div class="bg-white rounded-2xl border border-slate-200 card-shadow flex flex-col" style="height:calc(100vh - 220px);min-height:500px">
    <div class="flex-1 overflow-y-auto p-4 sm:p-6 space-y-4 bg-slate-50/50" id="chatWindow">
        <?php foreach($messages as $m): 
            $is_user = $m['sender_type']==='user';
        ?>
        <div class="flex <?= $is_user ? 'justify-end' : 'justify-start' ?>">
            <div class="max-w-[85%] sm:max-w-[70%]">
                <div class="text-[11px] text-slate-400 mb-1 <?= $is_user ? 'text-right mr-1' : 'ml-1' ?>">
                    <?= $is_user ? 'You' : 'Support Admin' ?> • <?= date('h:i A', strtotime($m['created_at'])) ?>
                </div>
                <div class="<?= $is_user ? 'bg-brand-navy text-white rounded-2xl rounded-tr-sm' : 'bg-white border border-slate-200 text-slate-800 rounded-2xl rounded-tl-sm card-shadow-sm' ?> px-4 py-3 text-sm leading-relaxed shadow-sm">
                    <?= nl2br(htmlspecialchars($m['message'])) ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <?php if($ticket['status']==='resolved'): ?>
    <div class="p-4 border-t border-slate-200 bg-slate-50 text-center rounded-b-2xl">
        <p class="text-slate-500 text-sm"><i class="fa-solid fa-lock mr-1"></i> This ticket has been marked as resolved and is closed to new replies.</p>
    </div>
    <?php else: ?>
    <div class="p-4 border-t border-slate-200 bg-white rounded-b-2xl">
        <div class="flex gap-2">
            <textarea id="replyMsg" rows="1" class="flex-1 bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-slate-800 focus:outline-none focus:border-brand-navy focus:ring-1 focus:ring-brand-navy transition-colors placeholder:text-slate-400 resize-none" placeholder="Type your reply..."></textarea>
            <button onclick="sendReply()" class="bg-brand-orange hover:bg-brand-orangeHover text-white w-12 h-12 rounded-xl flex items-center justify-center transition-colors shadow-md shrink-0">
                <i class="fa-solid fa-paper-plane"></i>
            </button>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
const chat = document.getElementById('chatWindow');
if(chat) chat.scrollTop = chat.scrollHeight;

async function sendReply() {
    const m = document.getElementById('replyMsg').value;
    if(!m.trim()) return;
    const fd = new FormData(); fd.append('action','reply'); fd.append('message',m);
    const r = await fetch('',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    if(r.success) location.reload();
}

// Auto-expand textarea
const tx = document.getElementById('replyMsg');
if(tx) {
    tx.setAttribute('style', 'height:' + (tx.scrollHeight) + 'px;overflow-y:hidden;');
    tx.addEventListener("input", OnInput, false);
}
function OnInput() {
  this.style.height = 'auto';
  this.style.height = (this.scrollHeight) + 'px';
  if(this.scrollHeight > 100) this.style.overflowY = 'auto';
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
