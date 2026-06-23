<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/bulk_sms.php';

auth_user();
$uid = (int)$_SESSION['user_id'];
$user = get_user($uid);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        json_response(['success' => false, 'message' => 'Invalid security token'], 403);
    }
    if (!rate_limit('bulk_sms_' . $uid, 5, 60)) {
        json_response(['success' => false, 'message' => 'Too many requests. Please wait.'], 429);
    }
    json_response(process_bulk_sms($uid, $_POST));
}

$csrf = csrf_token();

// Fetch pricing logic for JS calculations
$pr = db()->prepare("SELECT selling_price, discount_percent FROM service_pricing WHERE service='bulk_sms' AND is_active=1 LIMIT 1");
$pr->execute();
$p = $pr->fetch();

$base_selling = $p ? (float)$p['selling_price'] : 3.00;
$discount = $p ? (float)$p['discount_percent'] : 0;
$price_per_page = $base_selling * ((100 - $discount) / 100);

$page_title = 'Bulk SMS';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="max-w-2xl mx-auto">
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-4">
            <a href="/msjpay/user/dashboard" class="w-10 h-10 rounded-full bg-white border border-slate-200 flex items-center justify-center text-slate-600 hover:bg-slate-50 transition-colors">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-slate-800">Bulk SMS</h1>
                <p class="text-sm text-slate-500">Send custom messages to multiple contacts</p>
            </div>
        </div>
        <div class="bg-brand-orangeLight text-brand-orangeHover px-4 py-2 rounded-xl font-bold border border-brand-orange/20 shadow-sm">
            ₦<?=number_format((float)$user['wallet_balance'], 2)?>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 card-shadow p-6 mb-6">
        
        <!-- Sender ID -->
        <div class="mb-6">
            <label class="block text-sm font-semibold text-slate-700 mb-2">Sender ID <span class="text-slate-400 font-normal text-xs ml-1">(Max 11 chars)</span></label>
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                    <i class="fas fa-id-badge text-slate-400"></i>
                </div>
                <input type="text" id="senderF" class="block w-full pl-11 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium tracking-wide" placeholder="e.g. MY BUSINESS" maxlength="11">
            </div>
        </div>

        <!-- Recipients -->
        <div class="mb-6">
            <div class="flex justify-between items-end mb-2">
                <label class="block text-sm font-semibold text-slate-700">Recipients <span class="text-slate-400 font-normal text-xs ml-1">(Comma or newline separated)</span></label>
                <div class="text-xs font-bold text-brand-orange">
                    Valid: <span id="recCount" class="text-brand-orangeHover text-sm">0</span>
                </div>
            </div>
            <textarea id="recipientsF" rows="4" class="block w-full p-4 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-mono text-sm resize-y" placeholder="08012345678&#10;09087654321"></textarea>
        </div>

        <!-- Message -->
        <div class="mb-6">
            <label class="block text-sm font-semibold text-slate-700 mb-2">Message</label>
            <textarea id="messageF" rows="5" class="block w-full p-4 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors text-sm resize-y" placeholder="Type your message here..."></textarea>
            
            <div class="flex justify-between items-center mt-2 text-xs font-semibold text-slate-500">
                <span>Chars: <span id="charCount" class="text-slate-800">0</span></span>
                <span>Pages: <span id="pageCount" class="text-slate-800">1</span></span>
            </div>
        </div>

        <!-- Cost Estimate -->
        <div class="mb-6 bg-brand-orangeLight border border-brand-orange/30 rounded-xl p-5 flex justify-between items-center">
            <div>
                <span class="block text-xs font-bold text-brand-orange/80 uppercase tracking-wider mb-1">Estimated Cost</span>
                <span class="text-3xl font-black text-brand-orangeHover">₦<span id="totalCost">0.00</span></span>
            </div>
            <div class="text-right">
                <span class="block text-xs font-semibold text-brand-orange/80">Rate</span>
                <span class="text-sm font-bold text-brand-orangeHover">₦<?= number_format($price_per_page, 2) ?> / page</span>
            </div>
        </div>

        <input type="hidden" id="csrfF" value="<?= $csrf ?>">
        
        <button type="button" onclick="openConfirm()" class="w-full bg-gradient-to-r from-brand-orange to-brand-orangeHover hover:from-brand-orangeHover hover:to-orange-600 text-white font-bold text-lg py-4 rounded-xl shadow-lg shadow-orange-500/30 transition-all hover:-translate-y-0.5 flex items-center justify-center gap-2">
            Send Campaign <i class="fas fa-paper-plane"></i>
        </button>
        
        <p class="text-center text-xs text-slate-500 mt-4 flex items-center justify-center gap-1">
            <i class="fas fa-bolt text-yellow-500"></i> SMS delivered instantly to all valid recipients
        </p>
    </div>
</div>

<script>
const ppp = <?= $price_per_page ?>;

function extractPhones(str) {
    let arr = str.split(/[\n,;]+/).map(s => s.trim()).filter(s => s.length >= 10);
    let valid = [];
    arr.forEach(num => {
        let n = num.replace(/\D/g, '');
        if(n.length === 11 || n.length === 13 || n.length === 10) valid.push(n);
    });
    // Distinct
    return [...new Set(valid)];
}

function updateCounts() {
    const recStr = document.getElementById('recipientsF').value;
    const msg = document.getElementById('messageF').value;
    
    const validPhones = extractPhones(recStr);
    const recCount = validPhones.length;
    document.getElementById('recCount').innerText = recCount;
    
    const chars = msg.length;
    document.getElementById('charCount').innerText = chars;
    
    let pages = 1;
    if(chars > 160) {
        pages = Math.ceil(chars / 153);
    }
    if(chars === 0) pages = 0;
    
    document.getElementById('pageCount').innerText = pages;
    
    const cost = recCount * pages * ppp;
    document.getElementById('totalCost').innerText = cost.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

document.getElementById('recipientsF').addEventListener('input', updateCounts);
document.getElementById('messageF').addEventListener('input', updateCounts);

function openConfirm() {
    const sid = document.getElementById('senderF').value.trim();
    const recStr = document.getElementById('recipientsF').value;
    const msg = document.getElementById('messageF').value;
    
    const validPhones = extractPhones(recStr);
    
    if(!sid) return alert('Enter a Sender ID');
    if(validPhones.length === 0) return alert('Enter at least one valid phone number');
    if(!msg) return alert('Message cannot be empty');
    
    const chars = msg.length;
    let pages = chars > 160 ? Math.ceil(chars / 153) : 1;
    const cost = validPhones.length * pages * ppp;
    
    const pin = prompt(`Confirm Bulk SMS:\n\nSender ID: ${sid}\nRecipients: ${validPhones.length}\nPages: ${pages}\nTotal Cost: ₦${cost.toLocaleString('en-US', {minimumFractionDigits: 2})}\n\nEnter your 4-digit PIN:`);
    
    if(pin && pin.length >= 4) {
        processSMS(sid, validPhones.join(','), msg, pin);
    }
}

function processSMS(sid, phones, msg, pin) {
    const btn = document.querySelector('button[onclick="openConfirm()"]');
    const ogHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
    btn.disabled = true;
    
    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('csrfF').value);
    fd.append('sender_id', sid);
    fd.append('recipients', phones);
    fd.append('message', msg);
    fd.append('pin', pin);
    
    fetch('', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if(res.success) {
            alert(`Success! Campaign Sent.\n\n${res.message}`);
            location.reload();
        } else {
            alert(`Error: ${res.message}`);
            btn.innerHTML = ogHtml;
            btn.disabled = false;
        }
    })
    .catch(err => {
        alert('Network error occurred.');
        btn.innerHTML = ogHtml;
        btn.disabled = false;
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>