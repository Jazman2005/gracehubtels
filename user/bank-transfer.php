<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/bank_transfer.php';
require_once __DIR__.'/core/beneficiaries.php';
auth_user();
$uid = (int)$_SESSION['user_id'];
$user = get_user($uid);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) { 
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { 
        json_response(['success' => false, 'message' => 'Invalid token'], 403); 
    } 
    if (!rate_limit('transfer_'.$uid, 5, 60)) { 
        json_response(['success' => false, 'message' => 'Too many requests.'], 429); 
    } 
    $action = $_POST['action'] ?? ''; 
    if ($action === 'get_banks') { 
        json_response(['success' => true, 'banks' => get_banks_list()]); 
    } elseif ($action === 'validate_account') { 
        json_response(validate_bank_account(trim($_POST['bank_code'] ?? ''), sanitize_phone($_POST['account_number'] ?? ''))); 
    } elseif ($action === 'save_benef') { 
        json_response(save_beneficiary($uid, $_POST)); 
    } else { 
        json_response(process_bank_transfer($uid, $_POST)); 
    }
}
$csrf = csrf_token();
$charge = (float)setting('transfer_charge', '25');
$limits = [
    'single' => (float)setting('transfer_limit_single', '100000'),
    'daily'  => (float)setting('transfer_limit_daily', '500000'),
];
$benefs = get_beneficiaries($uid, 'bank_transfer');

$page_title = 'Bank Transfer'; 
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
                <h1 class="text-lg sm:text-xl font-bold text-slate-800">Send Money</h1>
                <p class="text-xs sm:text-sm text-slate-500">Instant transfer to any bank</p>
            </div>
        </div>
        <div class="bg-brand-orangeLight text-brand-orangeHover px-2.5 sm:px-4 py-1.5 sm:py-2 rounded-xl font-bold border border-brand-orange/20 shadow-sm text-sm">
            ₦<?=number_format((float)$user['wallet_balance'],2)?>
        </div>
    </div>

    <div class="bg-white rounded-xl sm:rounded-2xl border border-slate-200 card-shadow p-4 sm:p-5 mb-4 sm:mb-6">
        
        <?php if(!empty($benefs)):?>
        <div class="mb-4 sm:mb-5">
            <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-2 sm:mb-3">Saved Beneficiaries</label>
            <div class="flex gap-2 sm:gap-3 overflow-x-auto pb-1 sm:pb-2 custom-scrollbar">
                <?php foreach($benefs as $b):?>
                <button type="button" class="flex-shrink-0 flex items-center gap-3 p-3 rounded-xl border border-slate-200 hover:border-brand-orange hover:bg-brand-orangeLight transition-all text-left group min-w-[160px]" onclick="useBenef('<?=htmlspecialchars($b['value'])?>', '<?=htmlspecialchars($b['bank_code'] ?? '')?>', '<?=htmlspecialchars($b['bank_name'] ?? '')?>', '<?=htmlspecialchars($b['account_name'] ?? '')?>', '<?=$b['id']?>')">
                    <div class="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center font-bold text-brand-navy relative">
                        <?=strtoupper(substr($b['label'],0,2))?>
                        <?php if($b['is_favourite']):?>
                        <div class="absolute -top-1 -right-1 text-amber-400 text-xs"><i class="fas fa-star"></i></div>
                        <?php endif;?>
                    </div>
                    <div>
                        <div class="text-xs sm:text-sm font-bold text-slate-800 truncate max-w-[80px] sm:max-w-[100px]"><?=htmlspecialchars($b['label'])?></div>
                        <div class="text-[10px] sm:text-xs text-slate-500"><?=substr($b['value'],0,8)?>...</div>
                    </div>
                </button>
                <?php endforeach;?>
            </div>
        </div>
        <?php endif;?>

        <!-- Bank Selection -->
        <div class="mb-4 sm:mb-5">
            <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5 sm:mb-2">Select Bank</label>
            <div class="relative">
                <select class="block w-full px-3 sm:px-4 py-2.5 sm:py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium appearance-none text-sm" id="bankSel" onchange="resetVerify()">
                    <option value="">-- Loading banks... --</option>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 sm:px-4 text-slate-500">
                    <i class="fas fa-chevron-down text-xs"></i>
                </div>
            </div>
        </div>

        <!-- Account Number -->
        <div class="mb-4 sm:mb-5">
            <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5 sm:mb-2">Account Number</label>
            <div class="flex gap-2">
                <div class="relative flex-1">
                    <div class="absolute inset-y-0 left-0 pl-3 sm:pl-4 flex items-center pointer-events-none">
                        <i class="fas fa-hashtag text-slate-400 text-xs"></i>
                    </div>
                    <input type="text" id="acctF" class="block w-full pl-9 sm:pl-11 pr-3 sm:pr-4 py-2.5 sm:py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium text-sm sm:text-base" placeholder="10-digit account number" maxlength="10" inputmode="numeric" oninput="if(this.value.length===10)verifyAcct();else resetVerify()">
                </div>
                <button type="button" onclick="verifyAcct()" id="verifyBtn" class="bg-brand-navy hover:bg-brand-navyLight text-white px-4 sm:px-5 rounded-xl font-medium transition-colors shadow-sm whitespace-nowrap text-xs sm:text-sm">
                    Verify
                </button>
            </div>
            
            <div id="verifyCard" class="hidden mt-2 sm:mt-3 p-3 sm:p-4 bg-emerald-50 border border-emerald-200 rounded-xl flex items-center gap-2 sm:gap-3">
                <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-emerald-100 flex items-center justify-center text-emerald-600 shrink-0">
                    <i class="fas fa-user-check text-xs sm:text-sm"></i>
                </div>
                <div>
                    <div class="text-xs sm:text-sm font-bold text-emerald-800" id="vName"></div>
                    <div class="text-[10px] sm:text-xs font-semibold text-emerald-600 mt-0.5">Account verified successfully</div>
                </div>
            </div>
        </div>

        <!-- Amount -->
        <div class="mb-4 sm:mb-5">
            <div class="flex justify-between items-end mb-1.5 sm:mb-2">
                <label class="block text-xs sm:text-sm font-semibold text-slate-700">Amount (₦) <span class="text-[10px] sm:text-xs font-normal text-slate-500 ml-1">+ ₦<?=number_format($charge, 0)?> fee | Limit: ₦<?=number_format($limits['single'], 0)?>/txn</span></label>
            </div>
            
            <div class="flex flex-wrap gap-1.5 sm:gap-2 mb-2 sm:mb-3">
                <?php foreach ([1000, 2000, 5000, 10000, 20000, 50000] as $q): ?>
                <button type="button" class="amt-chip px-2.5 sm:px-4 py-1.5 sm:py-2 bg-slate-100 hover:bg-brand-orange hover:text-white rounded-lg text-[10px] sm:text-sm font-semibold text-slate-700 transition-colors border border-slate-200" onclick="setAmt(<?=$q?>, this)">₦<?=number_format($q)?></button>
                <?php endforeach; ?>
            </div>
            
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 sm:pl-4 flex items-center pointer-events-none">
                    <span class="text-slate-500 font-bold text-sm">₦</span>
                </div>
                <input type="text" id="amtF" class="block w-full pl-8 sm:pl-10 pr-3 sm:pr-4 py-2.5 sm:py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-bold text-base sm:text-lg" placeholder="Enter amount" min="100" inputmode="decimal" oninput="updateTotal()">
            </div>
            <div id="totalDisp" class="text-xs sm:text-sm font-semibold text-slate-600 mt-1.5 sm:mt-2 px-1"></div>
        </div>

        <!-- Narration -->
        <div class="mb-4 sm:mb-5">
            <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5 sm:mb-2">Narration <span class="text-slate-400 font-normal text-[10px] sm:text-xs ml-1">Optional</span></label>
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 sm:pl-4 flex items-center pointer-events-none">
                    <i class="fas fa-comment-alt text-slate-400 text-xs"></i>
                </div>
                <input type="text" id="narF" class="block w-full pl-9 sm:pl-11 pr-3 sm:pr-4 py-2.5 sm:py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium text-sm" placeholder="Payment description..." maxlength="50">
            </div>
        </div>

        <!-- Save Beneficiary Toggle -->
        <div class="mb-4 sm:mb-5 flex items-center justify-between bg-slate-50 border border-slate-200 rounded-xl p-3 sm:p-4">
            <div>
                <label class="font-bold text-slate-700 text-sm">Save as Beneficiary</label>
                <p class="text-[10px] sm:text-xs text-slate-500">Save this account for faster transfers</p>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" id="saveBenef" class="sr-only peer">
                <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-brand-orange"></div>
            </label>
        </div>

        <input type="hidden" id="verifiedF" value="0">
        <input type="hidden" id="payeeName" value="">
        <input type="hidden" id="csrfF" value="<?=$csrf?>">
        
        <button type="button" onclick="openConfirm()" class="w-full bg-gradient-to-r from-brand-orange to-brand-orangeHover hover:from-brand-orangeHover hover:to-orange-600 text-white font-bold text-sm sm:text-base py-3 sm:py-3.5 rounded-xl shadow-lg shadow-orange-500/30 transition-all hover:-translate-y-0.5 flex items-center justify-center gap-2">
            Send Money <i class="fas fa-paper-plane"></i>
        </button>
        
        <p class="text-center text-[10px] sm:text-xs text-slate-500 mt-3 sm:mt-4 flex items-center justify-center gap-1">
            <i class="fas fa-lock text-green-500"></i> Secured by PalmPay. Daily limit: ₦<?=number_format($limits['daily'], 0)?>
        </p>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    loadBanks();
});

function loadBanks() {
    fetch('', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: 'action=get_banks&csrf_token=' + encodeURIComponent('<?=$csrf?>')
    })
    .then(async r => { const txt = await r.text(); try { return JSON.parse(txt); } catch(e) { console.error('Raw response:', txt); throw new Error(txt); } })
    .then(res => {
        if(res.success && res.banks) {
            const sel = document.getElementById('bankSel');
            sel.innerHTML = '<option value="">-- Select Bank --</option>';
            res.banks.forEach(b => {
                sel.innerHTML += `<option value="${b.bankCode}">${b.bankName}</option>`;
            });
        }
    })
    .catch(err => console.log('Error loading banks'));
}

function resetVerify() {
    document.getElementById('verifiedF').value = '0';
    document.getElementById('verifyCard').classList.add('hidden');
    document.getElementById('payeeName').value = '';
}

function verifyAcct() {
    const bank = document.getElementById('bankSel').value;
    const acct = document.getElementById('acctF').value;
    
    if(!bank) return Swal.fire('Notice', 'Select a bank first', 'info');
    if(!acct || acct.length !== 10) return Swal.fire('Notice', 'Enter a 10-digit account number', 'info');
    
    const btn = document.getElementById('verifyBtn');
    const ogHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    btn.disabled = true;
    
    const fd = new FormData();
    fd.append('action', 'validate_account');
    fd.append('csrf_token', document.getElementById('csrfF').value);
    fd.append('bank_code', bank);
    fd.append('account_number', acct);
    
    fetch('', { method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: fd })
    .then(async r => { const txt = await r.text(); try { return JSON.parse(txt); } catch(e) { console.error('Raw response:', txt); throw new Error(txt); } })
    .then(res => {
        btn.innerHTML = ogHtml;
        btn.disabled = false;
        
        if(res.success) {
            document.getElementById('verifiedF').value = '1';
            document.getElementById('payeeName').value = res.name;
            document.getElementById('vName').innerText = res.name;
            document.getElementById('verifyCard').classList.remove('hidden');
        } else {
            showAlert(res.message || 'Verification failed', 'Error');
            resetVerify();
        }
    })
    .catch(err => {
        btn.innerHTML = ogHtml;
        btn.disabled = false;
        Swal.fire('Notice', 'Network error verifying account', 'info');
    });
}

function useBenef(acct, code, name, acctName, id) {
    const sel = document.getElementById('bankSel');
    sel.value = code;
    document.getElementById('acctF').value = acct;
    
    if(acctName) {
        document.getElementById('verifiedF').value = '1';
        document.getElementById('payeeName').value = acctName;
        document.getElementById('vName').innerText = acctName;
        document.getElementById('verifyCard').classList.remove('hidden');
    } else {
        verifyAcct();
    }
}

function setAmt(amt, btn) {
    document.getElementById('amtF').value = amt.toLocaleString('en-US');
    document.querySelectorAll('.amt-chip').forEach(b => {
        b.classList.remove('bg-brand-orange', 'text-white');
        b.classList.add('bg-slate-100', 'text-slate-700');
    });
    btn.classList.remove('bg-slate-100', 'text-slate-700');
    btn.classList.add('bg-brand-orange', 'text-white');
    updateTotal();
}

function updateTotal() {
    let val = document.getElementById('amtF').value.replace(/[^0-9]/g, '');
    let amt = val ? parseFloat(val) : 0;
    let chg = <?=$charge?>;
    
    if(val) {
        document.getElementById('amtF').value = amt.toLocaleString('en-US');
    }
    
    if(amt > 0) {
        document.getElementById('totalDisp').innerHTML = `Total to pay: <span class="text-brand-orange">₦${(amt + chg).toLocaleString('en-US')}</span> (Includes ₦${chg} fee)`;
    } else {
        document.getElementById('totalDisp').innerHTML = '';
    }
}

function openConfirm() {
    const bank = document.getElementById('bankSel');
    const bName = bank.options[bank.selectedIndex].text;
    const bCode = bank.value;
    const acct = document.getElementById('acctF').value;
    const isV = document.getElementById('verifiedF').value;
    const pName = document.getElementById('payeeName').value;
    const amtStr = document.getElementById('amtF').value.replace(/,/g, '');
    const amt = parseFloat(amtStr);
    
    if(!bCode) return Swal.fire('Notice', 'Select bank', 'info');
    if(!acct) return Swal.fire('Notice', 'Enter account number', 'info');
    if(isV !== '1') return Swal.fire('Notice', 'Please verify account first', 'info');
    if(isNaN(amt) || amt < 100) return Swal.fire('Notice', 'Minimum amount is ₦100', 'info');
    
    const chg = <?=$charge?>;
    const total = amt + chg;
    
    confirmTransaction({
        "Transaction": "Bank Transfer",
        "Bank": bName,
        "Account": acct,
        "Name": pName,
        "Amount": "₦" + amt.toLocaleString('en-US'),
        "Fee": "₦" + chg.toLocaleString('en-US'),
        "Total": "₦" + total.toLocaleString('en-US')
    }, function(pin) {
        processTransfer(bCode, bName, acct, pName, amt, pin);
    });
}

function processTransfer(bCode, bName, acct, pName, amt, pin) {
    const btn = document.querySelector('button[onclick="openConfirm()"]');
    const ogHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    btn.disabled = true;
    
    const nar = document.getElementById('narF').value;
    const save = document.getElementById('saveBenef').checked ? 1 : 0;
    
    const fd = new FormData();
    fd.append('action', 'pay');
    fd.append('csrf_token', document.getElementById('csrfF').value);
    fd.append('bank_code', bCode);
    fd.append('bank_name', bName);
    fd.append('account_number', acct);
    fd.append('account_name', pName);
    fd.append('amount', amt);
    fd.append('narration', nar);
    fd.append('save_beneficiary', save);
    fd.append('pin', pin);
    
    fetch('', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(async r => { const txt = await r.text(); try { return JSON.parse(txt); } catch(e) { console.error('Raw response:', txt); throw new Error(txt); } })
    .then(res => {
        if (res.ref) {
            window.location.href = '/user/receipt.php?ref=' + res.ref;
        } else {
            Swal.fire(res.success ? 'Success' : 'Notice', res.message, res.success ? 'success' : 'info');
            if (typeof btn !== 'undefined' && typeof ogHtml !== 'undefined') {
                btn.innerHTML = ogHtml;
                btn.disabled = false;
            }
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
