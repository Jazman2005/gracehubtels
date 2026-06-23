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
$fee = 0.0;
if (setting('internal_transfer_charge_enabled') === '1') {
    $fee = (float)setting('internal_transfer_charge', '0');
}

$page_title = 'Wallet to Wallet Transfer'; 
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
                <h1 class="text-2xl font-bold text-slate-800">MsjPay Transfer</h1>
                <p class="text-sm text-slate-500">Send money instantly for <?= $fee > 0 ? "₦$fee fee" : "free" ?></p>
            </div>
        </div>
        <div class="bg-brand-orangeLight text-brand-orangeHover px-4 py-2 rounded-xl font-bold border border-brand-orange/20 shadow-sm">
            ₦<?=number_format((float)$user['wallet_balance'],2)?>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 card-shadow p-6 mb-6">
        
        <!-- Recipient Username / Email / Phone -->
        <div class="mb-6">
            <label class="block text-sm font-semibold text-slate-700 mb-2">Recipient (Username, Email or Phone)</label>
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                    <i class="fas fa-user text-slate-400"></i>
                </div>
                <input type="text" id="recipientF" class="block w-full pl-11 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium text-lg" placeholder="Enter recipient info..." autocomplete="off">
            </div>
            <div id="recipientNameWrap" class="hidden mt-2 p-3 bg-emerald-50 border border-emerald-100 rounded-xl flex items-center gap-2 text-emerald-700 font-semibold text-sm">
                <i class="fas fa-check-circle"></i> <span id="recipientNameText"></span>
            </div>
        </div>

        <!-- Amount -->
        <div class="mb-6">
            <label class="block text-sm font-semibold text-slate-700 mb-2">Amount to Send (₦)</label>
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                    <span class="text-slate-400 font-bold">₦</span>
                </div>
                <input type="number" id="amountF" class="block w-full pl-11 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium text-lg tracking-wide" placeholder="0.00" step="0.01">
            </div>
            <?php if($fee > 0): ?>
            <p class="text-xs text-rose-500 mt-1.5"><i class="fas fa-info-circle"></i> A transaction fee of ₦<?=$fee?> applies.</p>
            <?php endif; ?>
        </div>

        <input type="hidden" id="csrfF" value="<?=$csrf?>">
        
        <button type="button" id="verifyBtn" onclick="verifyAndConfirm()" class="w-full bg-brand-navy hover:bg-brand-navyLight text-white font-bold text-lg py-4 rounded-xl shadow-lg shadow-brand-navy/30 transition-all hover:-translate-y-0.5 flex items-center justify-center gap-2">
            Verify Recipient <i class="fas fa-arrow-right"></i>
        </button>
        
        <p class="text-center text-xs text-slate-500 mt-4 flex items-center justify-center gap-1">
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
    
    if(!ident) return alert('Please enter recipient username, email, or phone');
    if(!amt || amt < 50) return alert('Minimum transfer amount is ₦50');
    
    if(!isVerified) {
        // Resolve Recipient
        const btn = document.getElementById('verifyBtn');
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';
        btn.disabled = true;
        
        const fd = new FormData();
        fd.append('csrf_token', document.getElementById('csrfF').value);
        fd.append('action', 'resolve');
        fd.append('recipient', ident);
        
        fetch('', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd })
        .then(r=>r.json())
        .then(res => {
            btn.disabled = false;
            if(res.success) {
                isVerified = true;
                verifiedUsername = res.username || res.full_name;
                
                document.getElementById('recipientNameText').innerText = res.full_name + ' (' + res.username + ')';
                document.getElementById('recipientNameWrap').classList.remove('hidden');
                
                btn.innerHTML = 'Proceed to Transfer <i class="fas fa-paper-plane"></i>';
                btn.className = "w-full bg-gradient-to-r from-brand-orange to-brand-orangeHover hover:from-brand-orangeHover hover:to-orange-600 text-white font-bold text-lg py-4 rounded-xl shadow-lg shadow-orange-500/30 transition-all hover:-translate-y-0.5 flex items-center justify-center gap-2";
            } else {
                btn.innerHTML = 'Verify Recipient <i class="fas fa-arrow-right"></i>';
                alert(res.message);
            }
        })
        .catch(e => {
            btn.disabled = false;
            btn.innerHTML = 'Verify Recipient <i class="fas fa-arrow-right"></i>';
            alert('Network Error');
        });
        
    } else {
        // Confirm Transfer
        const pin = prompt(`CONFIRM INTERNAL TRANSFER\n\nRecipient: ${document.getElementById('recipientNameText').innerText}\nAmount: ₦${amt}\n\nEnter your 4-digit PIN to authorize:`);
        if(pin && pin.length >= 4) {
            processTransfer(ident, amt, pin);
        }
    }
}

function processTransfer(ident, amt, pin) {
    const btn = document.getElementById('verifyBtn');
    const ogHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    btn.disabled = true;
    
    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('csrfF').value);
    fd.append('action', 'transfer');
    fd.append('recipient', ident);
    fd.append('amount', amt);
    fd.append('pin', pin);
    
    fetch('', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd })
    .then(r=>r.json())
    .then(res => {
        if(res.success) {
            alert(`Success! Transfer Completed.\n${res.message}`);
            location.href = '/msjpay/user/dashboard';
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
