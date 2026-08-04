<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/virtual-account.php';
auth_user();
$uid=(int)$_SESSION['user_id'];
$user=get_user($uid);
$va_mgr=new VirtualAccountManager();
$accounts=$va_mgr->get_user_accounts($uid);

$manual_bank_name = setting('manual_bank_name') ?? '';
$manual_account_number = setting('manual_account_number') ?? '';
$manual_account_name = setting('manual_account_name') ?? '';

$csrf=csrf_token(); 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) { 
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { 
        json_response(['success' => false, 'message' => 'Invalid token'], 403); 
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'create_va') { 
        $nin_input = '';
        $original_nin = $user['nin'];
        if (!empty($_POST['nin']) && preg_match('/^\d{11}$/', $_POST['nin'])) {
            $nin_input = sanitize($_POST['nin']);
            db()->prepare("UPDATE users SET nin=? WHERE id=?")->execute([$nin_input, $uid]);
            $user['nin'] = $nin_input;
        }
        $result = $va_mgr->create_for_user($uid); 
        
        // Rollback NIN if creation failed
        if (!$result['success'] && $nin_input) {
            db()->prepare("UPDATE users SET nin=? WHERE id=?")->execute([$original_nin, $uid]);
        }
        
        json_response($result); 
    } elseif ($action === 'create_dynamic_va') {
        if(setting('palmpay_dynamic_enabled') !== '1') {
            json_response(['success'=>false, 'message'=>'Dynamic checkout disabled']);
        }
        $amt = (float)($_POST['amount'] ?? 0);
        if($amt < 100) json_response(['success'=>false, 'message'=>'Minimum amount is 100 NGN']);
        
        require_once __DIR__.'/core/palmpay.php';
        $api = new PalmPayAPI();
        if(!$api->is_configured()) json_response(['success'=>false, 'message'=>'PalmPay not configured']);
        
        $order_id = 'DYN_' . time() . '_' . $uid;
        $res = $api->create_dynamic_account($user, $amt, $order_id);
        
        if($res && ($res['respCode']??'') === '00000000') {
            // PalmPay dynamic VA usually returns account details in data
            $data = $res['data'] ?? [];
            json_response([
                'success' => true,
                'account_number' => $data['virtualAccountNo'] ?? '',
                'bank_name' => $data['bankName'] ?? 'PalmPay',
                'account_name' => $data['customerName'] ?? $user['full_name'],
                'amount' => $amt,
                'message' => 'Dynamic account generated successfully'
            ]);
        }
        json_response(['success'=>false, 'message'=>'Failed to generate dynamic account: ' . ($res['respMsg'] ?? 'Unknown error')]);
    } elseif ($action === 'submit_manual_funding') {
        $amount = (float)($_POST['amount'] ?? 0);
        $sender_name = sanitize($_POST['sender_name'] ?? '', 255);
        if ($amount < 100) json_response(['success'=>false, 'message'=>'Minimum amount is 100 NGN']);
        if (!$sender_name) json_response(['success'=>false, 'message'=>'Sender name is required']);
        
        $last_req = db()->prepare("SELECT created_at FROM manual_funding_requests WHERE user_id=? ORDER BY created_at DESC LIMIT 1");
        $last_req->execute([$uid]);
        $last_date = $last_req->fetchColumn();
        if($last_date) {
            $diff = time() - strtotime($last_date);
            $wait_time = 5 * 60; // 5 minutes
            if($diff < $wait_time) {
                $rem = ceil(($wait_time - $diff) / 60);
                json_response(['success'=>false, 'message'=>"We recently received your deposit notification. Please wait $rem minute(s) before submitting another one."]);
            }
        }
        
        try {
            db()->prepare("INSERT INTO manual_funding_requests (user_id, amount, sender_name, status) VALUES (?, ?, ?, 'pending')")->execute([$uid, $amount, $sender_name]);
            json_response(['success'=>true, 'message'=>'Your transfer receipt has been submitted! It will be approved shortly.']);
        } catch(Exception $e) {
            json_response(['success'=>false, 'message'=>'An error occurred. Please try again.']);
        }
    }
    json_response(['success' => false, 'message' => 'Unknown action'], 400);
}

$page_title = 'Fund Wallet'; 
include __DIR__ . '/includes/header.php'; 
include __DIR__ . '/includes/sidebar.php'; 
?>

<div class="max-w-2xl mx-auto">
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-4">
            <a href="/user/dashboard" class="w-10 h-10 rounded-full bg-white border border-slate-200 flex items-center justify-center text-slate-600 hover:bg-slate-50 transition-colors">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-slate-800">Fund Wallet</h1>
                <p class="text-sm text-slate-500">Add money to your Gracehubtels account</p>
            </div>
        </div>
    </div>

    <!-- Balance Card -->
    <div class="bg-gradient-to-br from-brand-navy to-brand-navyDark rounded-2xl p-6 text-white card-shadow relative overflow-hidden mb-8">
        <div class="absolute top-0 right-0 w-32 h-32 bg-white/5 rounded-full -mr-16 -mt-16"></div>
        <div class="absolute bottom-0 left-0 w-24 h-24 bg-white/5 rounded-full -ml-12 -mb-12"></div>
        
        <div class="relative z-10 text-center">
            <p class="text-blue-100 text-sm font-medium mb-1 uppercase tracking-wider">Current Balance</p>
            <h3 class="text-4xl font-black font-outfit">
                ₦<?=number_format((float)$user['wallet_balance'],2)?>
            </h3>
        </div>
    </div>

    <!-- Tabs -->
    <div class="bg-slate-100 p-1.5 rounded-2xl flex gap-1 mb-6">
        <button class="fund-tab flex-1 py-2.5 rounded-xl text-sm font-bold transition-all text-slate-500 hover:text-slate-700 bg-transparent active-tab-class" onclick="switchFundTab('auto', this)" data-tab="auto">
            Automated Bank
        </button>
        <button class="fund-tab flex-1 py-2.5 rounded-xl text-sm font-bold transition-all text-slate-500 hover:text-slate-700 bg-transparent" onclick="switchFundTab('dynamic', this)" data-tab="dynamic">
            Dynamic Account
        </button>
        <button class="fund-tab flex-1 py-2.5 rounded-xl text-sm font-bold transition-all text-slate-500 hover:text-slate-700 bg-transparent" onclick="switchFundTab('manual', this)" data-tab="manual">
            Manual Funding
        </button>
    </div>

    <!-- AUTOMATED BANK TAB -->
    <div class="fund-section transition-all" id="sec-auto">
        <h3 class="font-bold text-slate-800 mb-4 text-lg">Your Dedicated Accounts</h3>
        
        <?php if (!empty($accounts)): ?>
            <div class="space-y-4">
            <?php foreach ($accounts as $va): ?>
                <div class="bg-white rounded-2xl border border-slate-200 card-shadow p-5 relative overflow-hidden group">
                    <div class="absolute top-0 left-0 w-1 h-full bg-brand-orange"></div>
                    
                    <div class="flex justify-between items-start mb-2 pl-3">
                        <div>
                            <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1"><?=htmlspecialchars($va['bank_name'])?></p>
                            <h4 class="text-2xl font-black text-slate-800 tracking-wider font-mono"><?=htmlspecialchars($va['account_number'])?></h4>
                            <p class="text-sm font-semibold text-slate-600 mt-1"><?=htmlspecialchars($va['account_name'])?></p>
                        </div>
                        <button onclick="copyAcct('<?=htmlspecialchars($va['account_number'])?>')" class="bg-brand-orangeLight hover:bg-brand-orange text-brand-orange hover:text-white w-10 h-10 rounded-full flex items-center justify-center transition-colors">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
            
            <div class="mt-4 text-center">
                <button onclick="createVA()" id="genBtn" class="bg-brand-navy hover:bg-brand-navyLight text-white px-6 py-3 rounded-xl font-bold transition-colors inline-flex items-center gap-2">
                    <i class="fas fa-plus"></i> Generate Another Account
                </button>
            </div>
            
        <?php else: ?>
            <div class="bg-white rounded-2xl border border-dashed border-slate-300 p-8 text-center">
                <div class="w-16 h-16 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4 text-slate-400">
                    <i class="fas fa-building-columns text-2xl"></i>
                </div>
                <h4 class="text-lg font-bold text-slate-800 mb-2">No Virtual Account</h4>
                <p class="text-sm text-slate-500 mb-6">Generate a dedicated account number to automatically fund your wallet via bank transfer.</p>
                
                <button onclick="createVA()" id="genBtn" class="bg-brand-navy hover:bg-brand-navyLight text-white px-6 py-3 rounded-xl font-bold transition-colors inline-flex items-center gap-2">
                    <i class="fas fa-plus"></i> Generate Account Number
                </button>
            </div>
        <?php endif; ?> 
        
        <div class="mt-6 bg-blue-50 border border-blue-200 rounded-xl p-4 flex gap-3 text-sm text-blue-800">
            <i class="fas fa-info-circle text-blue-500 mt-0.5 shrink-0"></i>
            <p>Transfer to any of the account numbers above from your bank app. Your Gracehubtels wallet will be credited automatically within seconds.</p>
        </div>
    </div>

    <!-- DYNAMIC TAB -->
    <div class="fund-section hidden transition-all" id="sec-dynamic">
        <h3 class="font-bold text-slate-800 mb-4 text-lg">Dynamic PalmPay Checkout</h3>
        
        <?php if(setting('palmpay_dynamic_enabled') === '1'): ?>
            <div id="dynamic-form-wrap">
                <div class="bg-white rounded-2xl border border-slate-200 card-shadow p-6 mb-4">
                    <p class="text-sm text-slate-500 mb-4">Generate a temporary, one-time account number to fund your wallet instantly.</p>
                    <div class="mb-4">
                        <label class="block text-sm font-semibold text-slate-700 mb-2">Amount to Fund</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <span class="text-slate-500 font-bold">₦</span>
                            </div>
                            <input type="number" id="dyn_amount" class="block w-full pl-10 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 transition-colors font-bold text-lg" placeholder="1000">
                        </div>
                    </div>
                    <button onclick="createDynamicVA()" id="genDynBtn" class="w-full bg-brand-navy hover:bg-brand-navyLight text-white py-3.5 rounded-xl font-bold transition-colors shadow-sm">
                        Generate Temporary Account
                    </button>
                </div>
            </div>

            <!-- Result Wrapper -->
            <div id="dynamic-result-wrap" class="hidden">
                <div class="bg-white rounded-2xl border border-brand-orange/30 card-shadow p-6 relative">
                    <div class="text-center mb-4">
                        <div class="w-12 h-12 bg-green-100 text-green-600 rounded-full flex items-center justify-center mx-auto mb-2 text-xl"><i class="fas fa-check"></i></div>
                        <h4 class="font-bold text-slate-800">Account Generated</h4>
                        <p class="text-xs text-slate-500">Please transfer exactly <strong id="res_amount" class="text-slate-700"></strong> to the account below. It expires in 30 minutes.</p>
                    </div>

                    <div class="space-y-4 bg-slate-50 p-4 rounded-xl border border-slate-200">
                        <div>
                            <p class="text-xs font-bold text-slate-500 uppercase">Bank Name</p>
                            <p class="text-lg font-semibold text-slate-800" id="res_bank">PalmPay</p>
                        </div>
                        <div>
                            <p class="text-xs font-bold text-slate-500 uppercase">Account Number</p>
                            <div class="flex items-center justify-between">
                                <p class="text-2xl font-black text-brand-navy font-mono" id="res_acct"></p>
                                <button onclick="copyAcct(document.getElementById('res_acct').innerText)" class="text-brand-orange hover:text-brand-orangeHover font-semibold text-sm px-3 py-1 bg-brand-orangeLight rounded-lg">Copy</button>
                            </div>
                        </div>
                        <div>
                            <p class="text-xs font-bold text-slate-500 uppercase">Account Name</p>
                            <p class="text-md font-semibold text-slate-800" id="res_name"></p>
                        </div>
                    </div>
                    
                    <button onclick="location.reload()" class="mt-4 w-full border border-slate-200 text-slate-600 py-3 rounded-xl font-bold hover:bg-slate-50 transition-colors">I have made the transfer</button>
                </div>
            </div>
        <?php else: ?>
            <div class="bg-white rounded-2xl border border-slate-200 card-shadow p-8 text-center">
                <div class="w-16 h-16 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4 text-slate-400">
                    <i class="fas fa-ban text-2xl"></i>
                </div>
                <h4 class="text-lg font-bold text-slate-800 mb-2">Disabled</h4>
                <p class="text-sm text-slate-500">Dynamic PalmPay checkout accounts are currently disabled by the administrator.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- MANUAL TAB -->
    <div class="fund-section hidden transition-all" id="sec-manual">
        <h3 class="font-bold text-slate-800 mb-4 text-lg">Manual Bank Transfer</h3>
        
        <?php if(!empty($manual_account_number)): ?>
            <div class="bg-white rounded-2xl border border-slate-200 card-shadow p-6 relative">
                <div class="space-y-4">
                    <div>
                        <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Bank Name</p>
                        <p class="text-lg font-semibold text-slate-800"><?= htmlspecialchars($manual_bank_name) ?></p>
                    </div>
                    
                    <div>
                        <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Account Number</p>
                        <div class="flex items-center justify-between bg-slate-50 border border-slate-200 rounded-xl p-3">
                            <p class="text-2xl font-black text-brand-navy tracking-wider font-mono"><?= htmlspecialchars($manual_account_number) ?></p>
                            <button onclick="copyAcct('<?=htmlspecialchars($manual_account_number)?>')" class="text-brand-orange hover:text-brand-orangeHover font-semibold text-sm px-3 py-1 bg-brand-orangeLight rounded-lg">Copy</button>
                        </div>
                    </div>
                    
                    <div>
                        <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Account Name</p>
                        <p class="text-lg font-semibold text-slate-800"><?= htmlspecialchars($manual_account_name) ?></p>
                    </div>
                </div>
            </div>
            
            <div class="mt-6 bg-blue-50 border border-blue-200 rounded-xl p-4 flex gap-3 text-sm text-blue-800">
                <i class="fas fa-info-circle text-blue-500 mt-0.5 shrink-0"></i>
                <p>After transferring the money to the account above, please fill out the form below so we can verify and credit your wallet.</p>
            </div>
            
            <div class="mt-6 bg-white rounded-2xl border border-slate-200 card-shadow p-6">
                <h4 class="font-bold text-slate-800 mb-4">Submit Transfer Details</h4>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Amount Sent (₦)</label>
                        <input type="number" id="mf_amount" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-slate-800 focus:outline-none focus:border-brand-navy focus:ring-1 focus:ring-brand-navy transition-colors" placeholder="e.g. 5000">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Sender Name / Narration</label>
                        <input type="text" id="mf_sender" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-slate-800 focus:outline-none focus:border-brand-navy focus:ring-1 focus:ring-brand-navy transition-colors" placeholder="e.g. John Doe">
                        <p class="text-xs text-slate-500 mt-1.5">Enter the account name you transferred from so we can trace it.</p>
                    </div>
                    <button onclick="submitManualFunding()" id="btnSubmitMF" class="w-full bg-brand-navy hover:bg-brand-navyDark text-white font-bold py-3.5 rounded-xl transition-colors shadow-sm mt-2">
                        Submit for Approval
                    </button>
                </div>
            </div>
        <?php else: ?>
            <div class="bg-white rounded-2xl border border-dashed border-slate-300 p-8 text-center">
                <div class="w-16 h-16 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4 text-slate-400">
                    <i class="fas fa-ban text-2xl"></i>
                </div>
                <h4 class="text-lg font-bold text-slate-800 mb-2">Disabled</h4>
                <p class="text-sm text-slate-500">Manual funding is currently disabled by the administrator.</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
// Initialize first tab styles
document.addEventListener('DOMContentLoaded', () => {
    const firstTab = document.querySelector('.fund-tab');
    if(firstTab) {
        firstTab.classList.add('bg-white', 'text-brand-orange', 'shadow-sm');
        firstTab.classList.remove('text-slate-500');
    }
});

function switchFundTab(tabId, el) {
    // Reset all tabs
    document.querySelectorAll('.fund-tab').forEach(t => {
        t.classList.remove('bg-white', 'text-brand-orange', 'shadow-sm');
        t.classList.add('text-slate-500');
    });
    
    // Hide all sections
    document.querySelectorAll('.fund-section').forEach(s => s.classList.add('hidden'));
    
    // Activate clicked tab
    el.classList.remove('text-slate-500');
    el.classList.add('bg-white', 'text-brand-orange', 'shadow-sm');
    
    // Show corresponding section
    document.getElementById('sec-' + tabId).classList.remove('hidden');
}

function copyAcct(num) {
    if(navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(num).then(()=>{
            if (typeof showAlert === 'function') showAlert('Account number copied to clipboard', 'Success', 'check-circle', 'text-emerald-500');
        });
    } else {
        const ta = document.createElement('textarea');
        ta.value = num;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            if (typeof showAlert === 'function') showAlert('Account number copied to clipboard', 'Success', 'check-circle', 'text-emerald-500');
        } catch (ex) {
            if (typeof showAlert === 'function') showAlert('Failed to copy', 'Error');
        }
        document.body.removeChild(ta);
    }
}

async function createDynamicVA() {
    const amt = document.getElementById('dyn_amount').value;
    if(!amt || isNaN(amt) || amt < 100) {
        if (typeof showAlert === 'function') showAlert("Please enter a valid amount of at least 100 NGN", "Notice");
        return;
    }
    
    const btn = document.getElementById('genDynBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
    
    const fd = new FormData();
    fd.append('csrf_token', '<?=$csrf?>');
    fd.append('action', 'create_dynamic_va');
    fd.append('amount', amt);
    
    try {
        const res = await fetch(location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        }).then(r => r.json());
        
        if(res.success) {
            document.getElementById('res_amount').innerText = '₦' + parseFloat(res.amount).toLocaleString('en-US', {minimumFractionDigits: 2});
            document.getElementById('res_bank').innerText = res.bank_name;
            document.getElementById('res_acct').innerText = res.account_number;
            document.getElementById('res_name').innerText = res.account_name;
            
            document.getElementById('dynamic-form-wrap').classList.add('hidden');
            document.getElementById('dynamic-result-wrap').classList.remove('hidden');
        } else {
            if (typeof showAlert === 'function') showAlert(res.message || "Failed to generate dynamic account.", "Error");
        }
    } catch(e) {
        if (typeof showAlert === 'function') showAlert("Network error. Please try again.", "Error");
    }
    
    btn.disabled = false;
    btn.innerHTML = 'Generate Temporary Account';
}

async function submitManualFunding() {
    const amt = document.getElementById('mf_amount').value;
    const snd = document.getElementById('mf_sender').value;
    
    if(!amt || isNaN(amt) || amt < 100) {
        if (typeof showAlert === 'function') showAlert("Please enter a valid amount of at least 100 NGN", "Notice");
        return;
    }
    if(!snd.trim()) {
        if (typeof showAlert === 'function') showAlert("Please enter the sender name", "Notice");
        return;
    }
    
    const btn = document.getElementById('btnSubmitMF');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    
    const fd = new FormData();
    fd.append('csrf_token', '<?=$csrf?>');
    fd.append('action', 'submit_manual_funding');
    fd.append('amount', amt);
    fd.append('sender_name', snd);
    
    try {
        const res = await fetch(location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        }).then(r => r.json());
        
        if (typeof showAlert === 'function') showAlert(res.message, res.success ? 'Success' : 'Notice', res.success ? 'check-circle' : 'exclamation-circle', res.success ? 'text-emerald-500' : 'text-amber-500');
        if(res.success) {
            document.getElementById('mf_amount').value = '';
            document.getElementById('mf_sender').value = '';
        }
    } catch(e) {
        if (typeof showAlert === 'function') showAlert("Network error. Please try again.", "Error");
    }
    
    btn.disabled = false;
    btn.innerHTML = 'Submit for Approval';
}

function createVA() {
    const hasNin = '<?= !empty($user["nin"]) ? "1" : "" ?>';
    if (!hasNin) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: 'NIN Required',
                text: 'Please provide your 11-digit NIN (National Identification Number) to generate a virtual account.',
                input: 'text',
                inputAttributes: {
                    maxlength: 11,
                    pattern: '\\d{11}',
                    placeholder: 'Enter your 11-digit NIN'
                },
                showCancelButton: true,
                confirmButtonText: 'Generate Account',
                confirmButtonColor: '#0f172a',
                preConfirm: (nin) => {
                    if (!nin || !/^\d{11}$/.test(nin)) {
                        Swal.showValidationMessage('Please enter a valid 11-digit NIN');
                        return false;
                    }
                    return nin;
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    processCreateVA(result.value);
                }
            });
        } else {
            const nin = prompt("Please provide your 11-digit NIN to generate a virtual account:");
            if (nin) {
                if (!/^\d{11}$/.test(nin)) {
                    alert('Please enter a valid 11-digit NIN');
                    return;
                }
                processCreateVA(nin);
            }
        }
    } else {
        processCreateVA('');
    }
}

function processCreateVA(nin = '') {
    const btn = document.getElementById('genBtn');
    const ogHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
    btn.disabled = true;
    
    const fd = new FormData();
    fd.append('action', 'create_va');
    fd.append('csrf_token', '<?=$csrf?>');
    if (nin) fd.append('nin', nin);
    
    fetch('', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if(res.success) {
            if (typeof Swal !== 'undefined') {
                Swal.fire('Success', 'Virtual Account generated successfully!', 'success').then(() => location.reload());
            } else {
                alert('Virtual Account generated successfully!');
                location.reload();
            }
        } else {
            if (typeof Swal !== 'undefined') {
                Swal.fire('Error', res.message, 'error');
            } else {
                alert('Error: ' + res.message);
            }
            btn.innerHTML = ogHtml;
            btn.disabled = false;
        }
    })
    .catch(err => {
        if (typeof Swal !== 'undefined') {
            Swal.fire('Error', 'Network error generating account.', 'error');
        } else {
            alert('Network error generating account.');
        }
        btn.innerHTML = ogHtml;
        btn.disabled = false;
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
