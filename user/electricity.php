<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/electricity.php';
require_once __DIR__.'/core/beneficiaries.php';
auth_user();
$uid = (int)$_SESSION['user_id'];
$user = get_user($uid); 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) { 
    if (!verify_csrf($_POST['csrf_token'] ?? '')) json_response(['success' => false, 'message' => 'Invalid token'], 403); 
    if (!rate_limit('elec_'.$uid, 5, 60)) json_response(['success' => false, 'message' => 'Too many requests.'], 429); 
    
    $action = $_POST['action'] ?? ''; 
    if ($action === 'validate_meter') { 
        $r = validate_meter((int)($_POST['disco_id'] ?? 0), sanitize_phone($_POST['meter_number'] ?? ''), $_POST['meter_type'] ?? 'prepaid'); 
        json_response($r); 
    } elseif ($action === 'save_benef') { 
        require_once __DIR__.'/core/beneficiaries.php'; 
        json_response(save_beneficiary($uid, $_POST)); 
    } else { 
        json_response(process_electricity($uid, $_POST)); 
    }
} 

$csrf = csrf_token();
$discos = db()->prepare("SELECT * FROM electricity_discos WHERE is_active=1 ORDER BY disco_name");
$discos->execute();
$discos = $discos->fetchAll();
$benefs = get_beneficiaries($uid, 'electricity');

$logo_map = [ 
    'ikeja' => '/msjpay/assets/logos/ikeja.png', 
    'eko' => '/msjpay/assets/logos/eko.jpg', 
    'ibadan' => '/msjpay/assets/logos/ibadan.jpg', 
    'abuja' => '/msjpay/assets/logos/abuja.jpg', 
    'kano' => '/msjpay/assets/logos/kano.png', 
    'portharcourt' => '/msjpay/assets/logos/portharcourt.jpg', 
    'kaduna' => '/msjpay/assets/logos/kaduna.jpg', 
    'enugu' => '/msjpay/assets/logos/enugu.png', 
    'benin' => '/msjpay/assets/logos/benin.jpg', 
    'jos' => '/msjpay/assets/logos/jos.jpg', 
    'yola' => '/msjpay/assets/logos/yola.jpg'
];

$page_title = 'Electricity'; 
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
                <h1 class="text-2xl font-bold text-slate-800">Pay Electricity</h1>
                <p class="text-sm text-slate-500">Instant token generation</p>
            </div>
        </div>
        <div class="bg-brand-orangeLight text-brand-orangeHover px-4 py-2 rounded-xl font-bold border border-brand-orange/20 shadow-sm">
            ₦<?=number_format((float)$user['wallet_balance'],2)?>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 card-shadow p-6 mb-6">
        
        <?php if(!empty($benefs)):?>
        <div class="mb-6">
            <label class="block text-sm font-semibold text-slate-700 mb-3">Saved Meters</label>
            <div class="flex gap-3 overflow-x-auto pb-2 custom-scrollbar">
                <?php foreach($benefs as $b):?>
                <button type="button" class="flex-shrink-0 flex items-center gap-3 p-3 rounded-xl border border-slate-200 hover:border-brand-orange hover:bg-brand-orangeLight transition-all text-left group min-w-[160px]" onclick="useMeter('<?=htmlspecialchars($b['value'])?>','<?=$b['id']?>')">
                    <div class="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center font-bold text-brand-navy relative">
                        <?=strtoupper(substr($b['label'],0,2))?>
                        <?php if($b['is_favourite']):?>
                        <div class="absolute -top-1 -right-1 text-amber-400 text-xs"><i class="fas fa-star"></i></div>
                        <?php endif;?>
                    </div>
                    <div>
                        <div class="text-sm font-bold text-slate-800 truncate max-w-[100px]"><?=htmlspecialchars($b['label'])?></div>
                        <div class="text-xs text-slate-500"><?=substr($b['value'],0,8)?>...</div>
                    </div>
                </button>
                <?php endforeach;?>
            </div>
        </div>
        <?php endif;?>

        <!-- Disco Selection -->
        <div class="mb-6">
            <label class="block text-sm font-semibold text-slate-700 mb-2">Select Provider</label>
            <div class="relative">
                <select class="block w-full px-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium appearance-none" id="discoSel" onchange="discoChanged(this)">
                    <option value="">-- Select Distribution Company --</option>
                    <?php foreach ($discos as $d): ?>
                    <option value="<?=$d['disco_id']?>" data-charge="<?=$d['charge']?>"><?=htmlspecialchars($d['disco_name'])?></option>
                    <?php endforeach; ?>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-4 text-slate-500">
                    <i class="fas fa-chevron-down"></i>
                </div>
            </div>
        </div>

        <!-- Meter Type -->
        <div class="mb-6">
            <label class="block text-sm font-semibold text-slate-700 mb-2">Meter Type</label>
            <div class="flex gap-2">
                <button type="button" class="flex-1 py-3.5 rounded-xl border-2 border-brand-orange bg-brand-orangeLight text-brand-orange font-bold transition-all" id="prepaidBtn" onclick="setType('prepaid', this)">
                    Prepaid
                </button>
                <button type="button" class="flex-1 py-3.5 rounded-xl border-2 border-slate-200 bg-slate-50 text-slate-500 font-bold transition-all hover:border-slate-300" id="postpaidBtn" onclick="setType('postpaid', this)">
                    Postpaid
                </button>
            </div>
        </div>

        <!-- Meter Number -->
        <div class="mb-6">
            <label class="block text-sm font-semibold text-slate-700 mb-2">Meter Number</label>
            <div class="flex gap-2">
                <div class="relative flex-1">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <i class="fas fa-hashtag text-slate-400"></i>
                    </div>
                    <input type="text" id="meterF" class="block w-full pl-11 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium text-lg tracking-wide" placeholder="Enter meter number" inputmode="numeric" oninput="resetVerify()">
                </div>
                <button type="button" onclick="verifyMeter()" id="verifyBtn" class="bg-brand-navy hover:bg-brand-navyLight text-white px-5 rounded-xl font-medium transition-colors shadow-sm whitespace-nowrap">
                    Verify
                </button>
            </div>
            
            <div id="verifyCard" class="hidden mt-3 p-4 bg-emerald-50 border border-emerald-200 rounded-xl">
                <div class="text-sm font-bold text-emerald-800" id="vName"></div>
                <div class="text-xs text-emerald-600 mt-1" id="vAddr"></div>
                <div class="text-xs font-semibold text-emerald-500 mt-2 flex items-center gap-1">
                    <i class="fas fa-check-circle"></i> Meter verified successfully
                </div>
            </div>
        </div>

        <!-- Amount -->
        <div class="mb-6">
            <div class="flex justify-between items-end mb-2">
                <label class="block text-sm font-semibold text-slate-700">Amount (₦) <span id="chargeNote" class="text-xs font-normal text-slate-500 ml-1"></span></label>
            </div>
            
            <div class="flex flex-wrap gap-2 mb-3">
                <?php foreach ([1000, 2000, 5000, 10000, 20000] as $q): ?>
                <button type="button" class="amt-chip px-4 py-2 bg-slate-100 hover:bg-brand-orange hover:text-white rounded-lg text-sm font-semibold text-slate-700 transition-colors border border-slate-200" onclick="setAmt(<?=$q?>, this)">₦<?=number_format($q)?></button>
                <?php endforeach; ?>
            </div>
            
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                    <span class="text-slate-500 font-bold">₦</span>
                </div>
                <input type="text" id="amtF" class="block w-full pl-10 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-bold text-xl" placeholder="Min 500" min="500" inputmode="decimal" oninput="updateTotal()">
            </div>
            <div id="totalDisp" class="text-sm font-semibold text-slate-600 mt-2 px-1"></div>
        </div>

        <!-- Phone -->
        <div class="mb-6">
            <label class="block text-sm font-semibold text-slate-700 mb-2">Phone (for token SMS) <span class="text-slate-400 font-normal text-xs ml-1">Optional</span></label>
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                    <i class="fas fa-phone text-slate-400"></i>
                </div>
                <input type="tel" id="phoneF" class="block w-full pl-11 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium text-lg" placeholder="<?=htmlspecialchars($user['phone'])?>" maxlength="11" value="<?=htmlspecialchars($user['phone'])?>">
            </div>
        </div>

        <input type="hidden" id="meterType" value="prepaid">
        <input type="hidden" id="verifiedF" value="0">
        <input type="hidden" id="discoCharge" value="100">
        <input type="hidden" id="csrfF" value="<?=$csrf?>">
        
        <button type="button" onclick="openConfirm()" class="w-full bg-gradient-to-r from-brand-orange to-brand-orangeHover hover:from-brand-orangeHover hover:to-orange-600 text-white font-bold text-lg py-4 rounded-xl shadow-lg shadow-orange-500/30 transition-all hover:-translate-y-0.5 flex items-center justify-center gap-2">
            Pay Electricity Bill <i class="fas fa-arrow-right"></i>
        </button>
        
        <p class="text-center text-xs text-slate-500 mt-4 flex items-center justify-center gap-1">
            <i class="fas fa-bolt text-yellow-500"></i> Token delivered instantly. Includes ₦<span id="chargeDisp">100</span> service charge.
        </p>
    </div>
</div>

<script>
function discoChanged(sel) {
    const opt = sel.options[sel.selectedIndex];
    let chg = 100;
    if(opt && opt.value) {
        chg = parseFloat(opt.getAttribute('data-charge')) || 100;
    }
    document.getElementById('discoCharge').value = chg;
    document.getElementById('chargeDisp').innerText = chg;
    document.getElementById('chargeNote').innerText = `+₦${chg} fee`;
    updateTotal();
    resetVerify();
}

function setType(type, btn) {
    document.getElementById('meterType').value = type;
    document.getElementById('prepaidBtn').className = 'flex-1 py-3.5 rounded-xl border-2 border-slate-200 bg-slate-50 text-slate-500 font-bold transition-all hover:border-slate-300';
    document.getElementById('postpaidBtn').className = 'flex-1 py-3.5 rounded-xl border-2 border-slate-200 bg-slate-50 text-slate-500 font-bold transition-all hover:border-slate-300';
    btn.className = 'flex-1 py-3.5 rounded-xl border-2 border-brand-orange bg-brand-orangeLight text-brand-orange font-bold transition-all';
    resetVerify();
}

function setAmt(amt, btn) {
    document.getElementById('amtF').value = amt;
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
    let chg = parseFloat(document.getElementById('discoCharge').value);
    
    if(val) {
        document.getElementById('amtF').value = amt.toLocaleString('en-US');
    }
    
    if(amt > 0) {
        document.getElementById('totalDisp').innerHTML = `Total to pay: <span class="text-brand-orange">₦${(amt + chg).toLocaleString('en-US')}</span>`;
    } else {
        document.getElementById('totalDisp').innerHTML = '';
    }
}

function resetVerify() {
    document.getElementById('verifiedF').value = '0';
    document.getElementById('verifyCard').classList.add('hidden');
}

function useMeter(num, id) {
    document.getElementById('meterF').value = num;
    resetVerify();
}

function verifyMeter() {
    const disco = document.getElementById('discoSel').value;
    const meter = document.getElementById('meterF').value;
    const type = document.getElementById('meterType').value;
    
    if(!disco) return alert('Select disco first');
    if(!meter) return alert('Enter meter number');
    
    const btn = document.getElementById('verifyBtn');
    const ogHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    btn.disabled = true;
    
    const fd = new FormData();
    fd.append('action', 'validate_meter');
    fd.append('csrf_token', document.getElementById('csrfF').value);
    fd.append('disco_id', disco);
    fd.append('meter_number', meter);
    fd.append('meter_type', type);
    
    fetch('', { method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: fd })
    .then(r => r.json())
    .then(res => {
        btn.innerHTML = ogHtml;
        btn.disabled = false;
        
        if(res.success) {
            document.getElementById('verifiedF').value = '1';
            document.getElementById('vName').innerText = res.name;
            document.getElementById('vAddr').innerText = res.address || '';
            document.getElementById('verifyCard').classList.remove('hidden');
        } else {
            alert(res.message || 'Verification failed');
            resetVerify();
        }
    })
    .catch(err => {
        btn.innerHTML = ogHtml;
        btn.disabled = false;
        alert('Network error verifying meter');
    });
}

function openConfirm() {
    const disco = document.getElementById('discoSel').value;
    const meter = document.getElementById('meterF').value;
    const isV = document.getElementById('verifiedF').value;
    const amtStr = document.getElementById('amtF').value.replace(/,/g, '');
    const amt = parseFloat(amtStr);
    
    if(!disco) return alert('Select disco');
    if(!meter) return alert('Enter meter number');
    if(isV !== '1') return alert('Please verify meter number first');
    if(isNaN(amt) || amt < 500) return alert('Minimum amount is ₦500');
    
    const chg = parseFloat(document.getElementById('discoCharge').value);
    const total = amt + chg;
    
    const name = document.getElementById('vName').innerText;
    const pin = prompt(`Confirm Electricity Payment:\nMeter: ${meter}\nName: ${name}\nAmount: ₦${amt}\nFee: ₦${chg}\nTotal: ₦${total}\n\nEnter your 4-digit PIN:`);
    
    if(pin && pin.length >= 4) {
        processPurchase(disco, meter, amt, pin);
    }
}

function processPurchase(disco, meter, amt, pin) {
    const btn = document.querySelector('button[onclick="openConfirm()"]');
    const ogHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    btn.disabled = true;
    
    const type = document.getElementById('meterType').value;
    const phone = document.getElementById('phoneF').value;
    
    const fd = new FormData();
    fd.append('action', 'pay');
    fd.append('csrf_token', document.getElementById('csrfF').value);
    fd.append('disco_id', disco);
    fd.append('meter_number', meter);
    fd.append('meter_type', type);
    fd.append('amount', amt);
    fd.append('phone', phone);
    fd.append('pin', pin);
    
    fetch('', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if(res.success) {
            alert(`Success! Payment successful.\n${res.message}`);
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