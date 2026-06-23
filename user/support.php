<?php
require_once __DIR__.'/core/auth.php';
auth_user();
$uid = $_SESSION['user_id'];
$user = get_user($uid);
$name = explode(' ', $user['full_name'])[0];

if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    $action = $_POST['action']??'';
    if($action==='create_ticket'){
        $subject = sanitize($_POST['subject']??'', 255);
        $message = trim($_POST['message']??'');
        $priority = in_array($_POST['priority']??'',['low','medium','high'])?$_POST['priority']:'medium';
        
        if(!$subject || !$message) {
            echo json_encode(['success'=>false,'message'=>'Subject and message are required']);
            exit;
        }
        
        try {
            db()->prepare("INSERT INTO tickets (user_id, subject, priority) VALUES (?, ?, ?)")->execute([$uid, $subject, $priority]);
            $ticket_id = db()->lastInsertId();
            db()->prepare("INSERT INTO ticket_messages (ticket_id, sender_type, sender_id, message) VALUES (?, 'user', ?, ?)")->execute([$ticket_id, $uid, $message]);
            echo json_encode(['success'=>true,'message'=>'Ticket created successfully']);
            exit;
        } catch(Exception $e) {
            echo json_encode(['success'=>false,'message'=>'Failed to create ticket']);
            exit;
        }
    }
}

$tickets = db()->prepare("SELECT * FROM tickets WHERE user_id=? ORDER BY updated_at DESC");
$tickets->execute([$uid]);
$tickets = $tickets->fetchAll();

$page_title = 'Help & Support'; 
include __DIR__ . '/includes/header.php'; 
include __DIR__ . '/includes/sidebar.php'; 
?>

<div class="flex items-center justify-between mb-8">
    <div>
        <h2 class="text-2xl font-bold text-slate-800">Help & Support</h2>
        <p class="text-slate-500 mt-1">Need help? Open a ticket below.</p>
    </div>
    <button onclick="document.getElementById('ticketModal').classList.remove('hidden')" class="bg-brand-navy hover:bg-brand-navyDark text-white px-4 py-2.5 rounded-xl font-medium transition-colors text-sm shadow-sm flex items-center gap-2">
        <i class="fa-solid fa-plus"></i> <span class="hidden sm:inline">New Ticket</span>
    </button>
</div>

<div class="space-y-4">
    <?php foreach($tickets as $t): 
        $badgeColor = $t['status'] === 'open' ? 'bg-red-100 text-red-600' : ($t['status'] === 'pending' ? 'bg-amber-100 text-amber-600' : 'bg-green-100 text-green-600');
    ?>
    <a href="/msjpay/user/ticket-view.php?id=<?=$t['id']?>" class="block bg-white rounded-2xl p-5 border border-slate-200 hover:border-brand-orange transition-colors card-shadow group">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div class="flex-1">
                <div class="flex items-center gap-3 mb-2">
                    <span class="text-xs font-bold px-2.5 py-1 rounded-md <?=$badgeColor?> uppercase tracking-wider"><?=htmlspecialchars($t['status'])?></span>
                    <h3 class="font-bold text-slate-800 group-hover:text-brand-navy transition-colors"><?=htmlspecialchars($t['subject'])?></h3>
                </div>
                <div class="flex flex-wrap gap-x-4 gap-y-2 text-sm text-slate-500">
                    <span class="flex items-center gap-1.5"><i class="fa-solid fa-hashtag text-slate-400"></i> Ticket #<?=$t['id']?></span>
                    <span class="flex items-center gap-1.5"><i class="fa-regular fa-clock text-slate-400"></i> Last Updated: <?=date('d M Y, h:i A', strtotime($t['updated_at']))?></span>
                </div>
            </div>
            <div class="text-brand-orange bg-orange-50 p-2.5 rounded-full group-hover:bg-brand-orange group-hover:text-white transition-colors self-start sm:self-center">
                <i class="fa-solid fa-chevron-right"></i>
            </div>
        </div>
    </a>
    <?php endforeach; if(empty($tickets)): ?>
    <div class="bg-white rounded-2xl p-10 border border-slate-200 text-center card-shadow">
        <div class="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-4 text-slate-400">
            <i class="fa-solid fa-life-ring text-2xl"></i>
        </div>
        <h3 class="text-lg font-bold text-slate-800 mb-1">No Tickets Found</h3>
        <p class="text-slate-500 mb-6">You don't have any open support tickets right now.</p>
        <button onclick="document.getElementById('ticketModal').classList.remove('hidden')" class="bg-brand-orange hover:bg-brand-orangeHover text-white px-5 py-2.5 rounded-xl font-medium transition-colors text-sm shadow-md inline-block">
            Open a Ticket
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- Modal -->
<div id="ticketModal" class="fixed inset-0 z-[100] hidden">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity" onclick="document.getElementById('ticketModal').classList.add('hidden')"></div>
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
        <div class="relative bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:max-w-lg w-full">
            <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4 border-b border-slate-100">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg leading-6 font-bold text-slate-800 font-outfit">Open Support Ticket</h3>
                    <button onclick="document.getElementById('ticketModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 transition-colors">
                        <i class="fa-solid fa-xmark text-xl"></i>
                    </button>
                </div>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Subject</label>
                        <input type="text" id="subject" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-slate-800 focus:outline-none focus:border-brand-navy focus:ring-1 focus:ring-brand-navy transition-colors placeholder:text-slate-400" placeholder="e.g. Failed Data Recharge">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Priority</label>
                        <select id="priority" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-slate-800 focus:outline-none focus:border-brand-navy transition-colors">
                            <option value="low">Low (General Inquiry)</option>
                            <option value="medium" selected>Medium (Standard Issue)</option>
                            <option value="high">High (Urgent/Emergency)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Message</label>
                        <textarea id="message" rows="4" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-slate-800 focus:outline-none focus:border-brand-navy focus:ring-1 focus:ring-brand-navy transition-colors placeholder:text-slate-400 resize-none" placeholder="Describe the issue..."></textarea>
                    </div>
                </div>
            </div>
            <div class="bg-slate-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse border-t border-slate-200 gap-3">
                <button type="button" onclick="createTicket()" class="w-full inline-flex justify-center rounded-xl border border-transparent shadow-sm px-4 py-2.5 bg-brand-navy text-base font-medium text-white hover:bg-brand-navyDark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand-navy sm:w-auto sm:text-sm transition-colors">
                    Submit Ticket
                </button>
                <button type="button" onclick="document.getElementById('ticketModal').classList.add('hidden')" class="mt-3 w-full inline-flex justify-center rounded-xl border border-slate-300 shadow-sm px-4 py-2.5 bg-white text-base font-medium text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand-navy sm:mt-0 sm:w-auto sm:text-sm transition-colors">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<script>
async function createTicket(){
  const s=document.getElementById('subject').value, p=document.getElementById('priority').value, m=document.getElementById('message').value;
  if(!s||!m)return alert('Subject and message required');
  const fd=new FormData();fd.append('action','create_ticket');fd.append('subject',s);fd.append('priority',p);fd.append('message',m);
  const r=await fetch('',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
  if(r.success) location.reload();
  else alert(r.message);
}
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>