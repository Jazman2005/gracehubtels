<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/internal_transfer.php';

auth_user();
$uid = (int)$_SESSION['user_id'];
$user = get_user($uid);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) { 
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { 
        json_response(['success' => false, 'message' => 'Invalid token'], 403); 
    } 
    if (!rate_limit('internal_transfer_'.$uid, 5, 60)) { 
        json_response(['success' => false, 'message' => 'Too many requests.'], 429); 
    } 
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'resolve') {
        $ident = $_POST['recipient'] ?? '';
        json_response(resolve_recipient($ident, $uid));
    } elseif ($action === 'transfer') {
        json_response(process_internal_transfer($uid, $_POST));
    }
}

$csrf = csrf_token();
// Must match process_internal_transfer()'s own fee lookup in
// core/internal_transfer.php exactly — otherwise the fee shown here before
// confirming wouldn't match what actually gets deducted.
$fee = 0.0;
if (setting('p2p_transfer_charge_enabled') === '1') {
    $fee = (float)setting('p2p_transfer_charge', '0');
}

$page_title = 'Wallet to Wallet Transfer'; 
include __DIR__ . '/includes/header.php'; 
include __DIR__ . '/includes/sidebar.php'; 
?>

<div class="max-w-2xl mx-auto">
    <!-- Header -->
    <div class="flex items-center justify-between mb-4 sm:mb-6">
        <div class="flex items-center gap-4">
            <a href="/user/dashboard" class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-white border border-slate-200 flex items-center justify-center text-slate-600 hover:bg-slate-50 transition-colors">
                <i class="fas fa-arrow-left text-xs sm:text-sm"></i>
            </a>
            <div>
                <h1 class="text-lg sm:text-xl font-bold text-slate-800"><?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Transfer</h1>
                <p class="text-xs sm:text-sm text-slate-500">Send money instantly for <?= $fee > 0 ? "₦$fee fee" : "free" ?></p>
            </div>
        </div>
        <div class="bg-brand-orangeLight text-brand-orangeHover px-2.5 sm:px-4 py-1.5 sm:py-2 rounded-xl font-bold border border-brand-orange/20 shadow-sm text-sm">
            ₦<?=number_format((float)$user['wallet_balance'],2)?>
        </div>
    </div>

    <div class="bg-white rounded-xl sm:rounded-2xl border border-slate-200 card-shadow p-4 sm:p-5 mb-4 sm:mb-6">
        
        <!-- Recipient Username / Email / Phone -->
        <div class="mb-4 sm:mb-5">
            <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5 sm:mb-2">Recipient (Username, Email or Phone)</label>
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 sm:pl-4 flex items-center pointer-events-none">
                    <i class="fas fa-user text-slate-400 text-xs"></i>
                </div>
                <input type="text" id="recipientF" class="block w-full pl-9 sm:pl-11 pr-3 sm:pr-4 py-2.5 sm:py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium text-sm sm:text-base" placeholder="Enter recipient info..." autocomplete="off">
            </div>
            <div id="recipientNameWrap" class="hidden mt-1.5 sm:mt-2 p-2 sm:p-3 bg-emerald-50 border border-emerald-100 rounded-xl flex items-center gap-2 text-emerald-700 font-semibold text-xs sm:text-sm">
                <i class="fas fa-check-circle"></i> <span id="recipientNameText"></span>
            </div>
        </div>

        <!-- Amount -->
        <div class="mb-4 sm:mb-5">
            <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5 sm:mb-2">Amount to Send (₦)</label>
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 sm:pl-4 flex items-center pointer-events-none">
                    <span class="text-slate-400 font-bold text-sm">₦</span>
                </div>
                <input type="number" id="amountF" class="block w-full pl-8 sm:pl-11 pr-3 sm:pr-4 py-2.5 sm:py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium text-sm sm:text-base" placeholder="0.00" step="0.01">
            </div>
            <?php if($fee > 0): ?>
            <p class="text-[10px] sm:text-xs text-rose-500 mt-1 sm:mt-1.5"><i class="fas fa-info-circle"></i> A transaction fee of ₦<?=$fee?> applies.</p>
            <?php endif; ?>
        </div>

        <input type="hidden" id="csrfF" value="<?=$csrf?>">
        
        <button type="button" id="verifyBtn" onclick="verifyAndConfirm()" class="w-full bg-brand-navy hover:bg-brand-navyLight text-white font-bold text-sm sm:text-base py-3 sm:py-3.5 rounded-xl shadow-lg shadow-brand-navy/30 transition-all hover:-translate-y-0.5 flex items-center justify-center gap-2">
            Verify Recipient <i class="fas fa-arrow-right"></i>
        </button>
        
        <p class="text-center text-[10px] sm:text-xs text-slate-500 mt-3 sm:mt-4 flex items-center justify-center gap-1">
            <i class="fas fa-bolt text-brand-orange"></i> Internal transfers are processed instantly
        </p>
    </div>
</div>

<script>
let isVerified = false;
let verifiedUsername = '';

document.getElementById('recipientF').addEventListener('input', function() {
    isVerified = false;
    document.getElementById('recipientNameWrap').classList.add('hidden');
    const btn = document.getElementById('verifyBtn');
    btn.innerHTML = 'Verify Recipient <i class="fas fa-arrow-right"></i>';
    btn.className = "w-full bg-brand-navy hover:bg-brand-navyLight text-white font-bold text-lg py-4 rounded-xl shadow-lg shadow-brand-navy/30 transition-all hover:-translate-y-0.5 flex items-center justify-center gap-2";
});

function verifyAndConfirm() {
    const ident = document.getElementById('recipientF').value.trim();
    const amt = parseFloat(document.getElementById('amountF').value);
    const btn = document.getElementById('verifyBtn');
    
    if(!ident) return Swal.fire('Notice', 'Please enter recipient username, email, or phone', 'info');
    if(!amt || amt < 50) return Swal.fire('Notice', 'Minimum transfer amount is ₦50', 'info');
    
    if(!isVerified) {
        const ogHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';
        btn.disabled = true;
        
        const fd = new FormData();
        fd.append('csrf_token', document.getElementById('csrfF').value);
        fd.append('action', 'resolve');
        fd.append('recipient', ident);
        
        fetch('', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd })
        .then(async r => { const txt = await r.text(); try { return JSON.parse(txt); } catch(e) { console.error('Raw response:', txt); throw new Error(txt); } })
        .then(res => {
            if (res.success && res.full_name) {
                isVerified = true;
                verifiedUsername = res.username || ident;
                document.getElementById('recipientNameText').textContent = res.full_name;
                document.getElementById('recipientNameWrap').classList.remove('hidden');
                btn.innerHTML = 'Send <i class="fas fa-paper-plane"></i>';
                btn.disabled = false;
            } else {
                Swal.fire('Notice', res.message || 'Recipient not found', 'info');
                btn.innerHTML = ogHtml;
                btn.disabled = false;
            }
        })
        .catch(err => {
            console.error(err); Swal.fire('Server Error', err.message.substring(0, 200), 'error');
            btn.innerHTML = ogHtml;
            btn.disabled = false;
        });
    } else {
        const recName = document.getElementById('recipientNameText').textContent;
        confirmTransaction({
            "Transaction": "Wallet Transfer",
            "Recipient": recName + ' (' + verifiedUsername + ')',
            "Amount": "₦" + amt.toLocaleString('en-US')<?php if($fee > 0): ?>,
            "Fee": "₦<?=$fee?>"<?php endif; ?>
        }, function(pin) {
            processInternalTransfer(verifiedUsername, amt, pin);
        });
    }
}

function processInternalTransfer(recipient, amount, pin) {
    const btn = document.getElementById('verifyBtn');
    const ogHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    btn.disabled = true;
    
    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('csrfF').value);
    fd.append('action', 'transfer');
    fd.append('recipient', recipient);
    fd.append('amount', amount.toString());
    fd.append('pin', pin);
    
    fetch('', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd })
    .then(async r => { const txt = await r.text(); try { return JSON.parse(txt); } catch(e) { console.error('Raw response:', txt); throw new Error(txt); } })
    .then(res => {
        if (res.ref) {
            window.location.href = '/user/receipt.php?ref=' + res.ref;
        } else {
            Swal.fire(res.success ? 'Success' : 'Notice', res.message, res.success ? 'success' : 'info');
            btn.innerHTML = ogHtml;
            btn.disabled = false;
        }
    })
    .catch(err => {
        console.error(err); Swal.fire('Server Error', err.message.substring(0, 200), 'error');
        btn.innerHTML = ogHtml;
        btn.disabled = false;
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
