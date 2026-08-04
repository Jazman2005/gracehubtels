<?php
require_once __DIR__.'/core/auth.php';
auth_user();
$uid = $_SESSION['user_id'];
$user = get_user($uid);
$name = explode(' ', $user['full_name'])[0];

if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_csrf($_POST['csrf']??'')){echo json_encode(['success'=>false,'message'=>'Invalid token']);exit;}
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

$csrf = csrf_token();
$tickets = db()->prepare("SELECT * FROM tickets WHERE user_id=? ORDER BY updated_at DESC");
$tickets->execute([$uid]);
$tickets = $tickets->fetchAll();

$page_title = 'Help & Support'; 
include __DIR__ . '/includes/header.php'; 
include __DIR__ . '/includes/sidebar.php'; 
?>

<div class="max-w-4xl mx-auto">
    <!-- Header -->
    <div class="flex items-center justify-between mb-4 sm:mb-6">
        <div class="flex items-center gap-4">
            <a href="/user/dashboard" class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-white border border-slate-200 flex items-center justify-center text-slate-600 hover:bg-slate-50 transition-colors">
                <i class="fas fa-arrow-left text-xs sm:text-sm"></i>
            </a>
            <div>
                <h1 class="text-lg sm:text-xl font-bold text-slate-800">Help & Support</h1>
                <p class="text-xs sm:text-sm text-slate-500">Need help? Reach out to us or open a ticket.</p>
            </div>
        </div>
        <div class="flex items-center gap-2 sm:gap-3">
            <div class="bg-brand-orangeLight text-brand-orangeHover px-2.5 sm:px-4 py-1.5 sm:py-2 rounded-xl font-bold border border-brand-orange/20 shadow-sm text-sm">
                ₦<?=number_format((float)$user['wallet_balance'],2)?>
            </div>
            <button onclick="document.getElementById('ticketModal').classList.remove('hidden')" class="bg-brand-navy hover:bg-brand-navyDark text-white px-3 sm:px-4 py-1.5 sm:py-2.5 rounded-xl font-medium transition-colors text-xs sm:text-sm shadow-sm flex items-center gap-1.5 sm:gap-2">
                <i class="fa-solid fa-plus text-xs"></i> <span class="hidden sm:inline">New Ticket</span>
            </button>
        </div>
    </div>

<!-- Alternative Support Means -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-2 sm:gap-4 mb-4 sm:mb-6">
    <?php
    $wa = setting('site_whatsapp_support');
    $phone = setting('site_phone');
    $email = setting('site_email');
    $chan = setting('site_whatsapp_channel');
    ?>
    <a href="https://wa.me/<?= $wa ? preg_replace('/[^0-9]/', '', $wa) : '2348000000000' ?>" target="_blank" class="bg-white rounded-xl sm:rounded-2xl border border-slate-200 p-3 sm:p-4 flex items-center gap-3 sm:gap-4 hover:border-[#25D366] hover:bg-[#25D366]/5 transition-colors card-shadow group">
        <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-[#25D366]/10 text-[#25D366] flex items-center justify-center text-base sm:text-lg group-hover:scale-110 transition-transform shrink-0">
            <i class="fab fa-whatsapp"></i>
        </div>
        <div class="min-w-0">
            <h3 class="font-bold text-slate-800 text-xs sm:text-sm">WhatsApp Support</h3>
            <p class="text-[10px] sm:text-xs text-slate-500 mt-0.5 truncate"><?= $wa ? htmlspecialchars($wa) : 'Chat with us' ?></p>
        </div>
    </a>

    <a href="tel:<?= $phone ? preg_replace('/[^0-9+]/', '', $phone) : '' ?>" class="bg-white rounded-xl sm:rounded-2xl border border-slate-200 p-3 sm:p-4 flex items-center gap-3 sm:gap-4 hover:border-green-500 hover:bg-green-50 transition-colors card-shadow group">
        <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-green-100 text-green-600 flex items-center justify-center text-base sm:text-lg group-hover:scale-110 transition-transform shrink-0">
            <i class="fa-solid fa-phone"></i>
        </div>
        <div class="min-w-0">
            <h3 class="font-bold text-slate-800 text-xs sm:text-sm">Call Support</h3>
            <p class="text-[10px] sm:text-xs text-slate-500 mt-0.5 truncate"><?= $phone ? htmlspecialchars($phone) : 'Call us' ?></p>
        </div>
    </a>

    <a href="mailto:<?= $email ? htmlspecialchars($email) : '' ?>" class="bg-white rounded-xl sm:rounded-2xl border border-slate-200 p-3 sm:p-4 flex items-center gap-3 sm:gap-4 hover:border-brand-navy hover:bg-brand-navy/5 transition-colors card-shadow group">
        <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-brand-navy/10 text-brand-navy flex items-center justify-center text-base sm:text-lg group-hover:scale-110 transition-transform shrink-0">
            <i class="fa-solid fa-envelope"></i>
        </div>
        <div class="min-w-0">
            <h3 class="font-bold text-slate-800 text-xs sm:text-sm">Email Support</h3>
            <p class="text-[10px] sm:text-xs text-slate-500 mt-0.5 truncate"><?= $email ? htmlspecialchars($email) : 'Send an email' ?></p>
        </div>
    </a>

    <a href="<?= $chan ? htmlspecialchars($chan) : 'https://whatsapp.com/channel/' ?>" target="_blank" class="bg-white rounded-xl sm:rounded-2xl border border-slate-200 p-3 sm:p-4 flex items-center gap-3 sm:gap-4 hover:border-[#075E54] hover:bg-[#075E54]/5 transition-colors card-shadow group">
        <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-[#075E54]/10 text-[#075E54] flex items-center justify-center text-base sm:text-lg group-hover:scale-110 transition-transform shrink-0">
            <i class="fa-solid fa-bullhorn"></i>
        </div>
        <div class="min-w-0">
            <h3 class="font-bold text-slate-800 text-xs sm:text-sm">WhatsApp Channel</h3>
            <p class="text-[10px] sm:text-xs text-slate-500 mt-0.5 truncate">Join for Updates</p>
        </div>
    </a>
</div>

<h3 class="text-base sm:text-lg font-bold text-slate-800 mb-3 sm:mb-4">Support Tickets</h3>
<div class="space-y-3 sm:space-y-4">
    <?php foreach($tickets as $t): 
        $badgeColor = $t['status'] === 'open' ? 'bg-red-100 text-red-600' : ($t['status'] === 'pending' ? 'bg-amber-100 text-amber-600' : 'bg-green-100 text-green-600');
    ?>
    <a href="/user/ticket-view.php?id=<?=$t['id']?>" class="block bg-white rounded-xl sm:rounded-2xl p-3 sm:p-4 border border-slate-200 hover:border-brand-orange transition-colors card-shadow group">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 sm:gap-4">
            <div class="flex-1">
                <div class="flex items-center gap-2 sm:gap-3 mb-1 sm:mb-2">
                    <span class="text-[10px] sm:text-xs font-bold px-2 sm:px-2.5 py-0.5 sm:py-1 rounded-md <?=$badgeColor?> uppercase tracking-wider"><?=htmlspecialchars($t['status'])?></span>
                    <h3 class="font-bold text-slate-800 text-xs sm:text-base group-hover:text-brand-navy transition-colors"><?=htmlspecialchars($t['subject'])?></h3>
                </div>
                <div class="flex flex-wrap gap-x-3 sm:gap-x-4 gap-y-1 sm:gap-y-2 text-[10px] sm:text-sm text-slate-500">
                    <span class="flex items-center gap-1 sm:gap-1.5"><i class="fa-solid fa-hashtag text-slate-400"></i> Ticket #<?=$t['id']?></span>
                    <span class="flex items-center gap-1 sm:gap-1.5"><i class="fa-regular fa-clock text-slate-400"></i> Last Updated: <?=date('d M Y, h:i A', strtotime($t['updated_at']))?></span>
                </div>
            </div>
            <div class="text-brand-orange bg-orange-50 p-1.5 sm:p-2.5 rounded-full group-hover:bg-brand-orange group-hover:text-white transition-colors self-start sm:self-center">
                <i class="fa-solid fa-chevron-right text-xs"></i>
            </div>
        </div>
    </a>
    <?php endforeach; if(empty($tickets)): ?>
    <div class="bg-white rounded-xl sm:rounded-2xl p-5 sm:p-8 border border-slate-200 text-center card-shadow">
        <div class="w-12 h-12 sm:w-16 sm:h-16 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-3 sm:mb-4 text-slate-400">
            <i class="fa-solid fa-life-ring text-xl sm:text-2xl"></i>
        </div>
        <h3 class="text-base sm:text-lg font-bold text-slate-800 mb-1">No Tickets Found</h3>
        <p class="text-xs sm:text-sm text-slate-500 mb-4 sm:mb-6">You don't have any open support tickets right now.</p>
        <button onclick="document.getElementById('ticketModal').classList.remove('hidden')" class="bg-brand-orange hover:bg-brand-orangeHover text-white px-4 sm:px-5 py-2 sm:py-2.5 rounded-xl font-medium transition-colors text-xs sm:text-sm shadow-md inline-block">
            Open a Ticket
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- Modal -->
<div id="ticketModal" class="fixed inset-0 z-[100] hidden">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity" onclick="document.getElementById('ticketModal').classList.add('hidden')"></div>
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
        <div class="relative bg-white rounded-xl sm:rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:max-w-lg w-full">
            <div class="bg-white px-4 pt-4 pb-4 sm:p-5 sm:pb-4 border-b border-slate-100">
                <div class="flex justify-between items-center mb-3 sm:mb-4">
                    <h3 class="text-base sm:text-lg leading-6 font-bold text-slate-800 font-outfit">Open Support Ticket</h3>
                    <button onclick="document.getElementById('ticketModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 transition-colors">
                        <i class="fa-solid fa-xmark text-lg sm:text-xl"></i>
                    </button>
                </div>
                <div class="space-y-3 sm:space-y-4">
                    <div>
                        <label class="block text-xs sm:text-sm font-medium text-slate-700 mb-1 sm:mb-1.5">Subject</label>
                        <input type="text" id="subject" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 sm:px-4 py-2.5 sm:py-3 text-sm text-slate-800 focus:outline-none focus:border-brand-navy focus:ring-1 focus:ring-brand-navy transition-colors placeholder:text-slate-400" placeholder="e.g. Failed Data Recharge">
                    </div>
                    <div>
                        <label class="block text-xs sm:text-sm font-medium text-slate-700 mb-1 sm:mb-1.5">Priority</label>
                        <select id="priority" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 sm:px-4 py-2.5 sm:py-3 text-sm text-slate-800 focus:outline-none focus:border-brand-navy transition-colors">
                            <option value="low">Low (General Inquiry)</option>
                            <option value="medium" selected>Medium (Standard Issue)</option>
                            <option value="high">High (Urgent/Emergency)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs sm:text-sm font-medium text-slate-700 mb-1 sm:mb-1.5">Message</label>
                        <textarea id="message" rows="3" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 sm:px-4 py-2.5 sm:py-3 text-sm text-slate-800 focus:outline-none focus:border-brand-navy focus:ring-1 focus:ring-brand-navy transition-colors placeholder:text-slate-400 resize-none" placeholder="Describe the issue..."></textarea>
                    </div>
                </div>
            </div>
            <div class="bg-slate-50 px-4 py-3 sm:px-5 sm:flex sm:flex-row-reverse border-t border-slate-200 gap-2 sm:gap-3">
                <button type="button" onclick="createTicket()" class="w-full inline-flex justify-center rounded-xl border border-transparent shadow-sm px-3 sm:px-4 py-2 sm:py-2.5 bg-brand-navy text-sm font-medium text-white hover:bg-brand-navyDark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand-navy sm:w-auto transition-colors">
                    Submit Ticket
                </button>
                <button type="button" onclick="document.getElementById('ticketModal').classList.add('hidden')" class="mt-2 sm:mt-0 w-full inline-flex justify-center rounded-xl border border-slate-300 shadow-sm px-3 sm:px-4 py-2 sm:py-2.5 bg-white text-sm font-medium text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand-navy sm:w-auto transition-colors">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>
</div>

<script>
const CSRF='<?=$csrf?>';
async function createTicket(){
  const s=document.getElementById('subject').value, p=document.getElementById('priority').value, m=document.getElementById('message').value;
  if(!s||!m)return Swal.fire('Notice', 'Subject and message required', 'info');
  const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','create_ticket');fd.append('subject',s);fd.append('priority',p);fd.append('message',m);
  const r=await fetch('',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
  if(r.success) location.reload();
  else showAlert(r.message, 'Error', 'exclamation-circle', 'text-red-500');
}
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
