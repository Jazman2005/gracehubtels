<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/cabletv.php';
require_once __DIR__.'/core/beneficiaries.php';
auth_user(); 
$uid = (int)$_SESSION['user_id'];
$user = get_user($uid); 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) { 
    if (!verify_csrf($_POST['csrf_token'] ?? '')) json_response(['success' => false, 'message' => 'Invalid token'], 403); 
    if (!rate_limit('cable_'.$uid, 5, 60)) json_response(['success' => false, 'message' => 'Too many requests.'], 429); 
    $action = $_POST['action'] ?? ''; 
    if ($action === 'validate_smart') { 
        json_response(validate_smartcard( (int)($_POST['cable_id'] ?? 0), preg_replace('/\D/', '', $_POST['smartcard'] ?? '') )); 
    } else { 
        json_response(process_cabletv($uid, $_POST)); 
    }
} 

$csrf = csrf_token();
$providers = [ 
    'GOTV' => ['id' => 1, 'logo' => '/msjpay/assets/logos/gotv.png', 'color' => 'bg-green-600', 'border' => 'border-green-600'], 
    'DSTV' => ['id' => 2, 'logo' => '/msjpay/assets/logos/dstv.png', 'color' => 'bg-blue-600', 'border' => 'border-blue-600'], 
    'STARTIMES'=> ['id' => 3, 'logo' => '/msjpay/assets/logos/startimes.jpg', 'color' => 'bg-orange-500', 'border' => 'border-orange-500'],
]; 

$sel_prov = strtoupper($_GET['p'] ?? 'GOTV');
if (!isset($providers[$sel_prov])) $sel_prov = 'GOTV'; 

$s=db()->prepare("SELECT * FROM cable_plans WHERE provider=? AND is_active=1 ORDER BY selling_price");
$s->execute([$sel_prov]);
$plans = $s->fetchAll(); 
$cable_id = $providers[$sel_prov]['id'];
$benefs = get_beneficiaries($uid, 'cabletv');

$page_title = 'Cable TV'; 
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
                <h1 class="text-2xl font-bold text-slate-800">Cable TV</h1>
                <p class="text-sm text-slate-500">Instant subscription activation</p>
            </div>
        </div>
        <div class="bg-brand-orangeLight text-brand-orangeHover px-4 py-2 rounded-xl font-bold border border-brand-orange/20 shadow-sm">
            ₦<?=number_format((float)$user['wallet_balance'],2)?>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 card-shadow p-6 mb-6">
        
        <!-- Provider Selection -->
        <div class="mb-6">
            <label class="block text-sm font-semibold text-slate-700 mb-3">Select Provider</label>
            <div class="grid grid-cols-3 gap-3">
                <?php foreach($providers as $name => $pinfo):?>
                <a href="?p=<?=$name?>" class="flex flex-col items-center justify-center p-3 rounded-xl border-2 <?= $sel_prov===$name?'border-brand-orange bg-brand-orangeLight':'border-slate-100 bg-slate-50 hover:border-brand-orange/50' ?> transition-all relative overflow-hidden text-center">
                    <div class="w-14 h-14 rounded-full mb-2 overflow-hidden bg-white shadow-sm flex items-center justify-center <?= $pinfo['color'] ?> p-1">
                        <img src="<?=$pinfo['logo']?>" alt="<?=$name?>" class="w-full h-full object-contain rounded-full bg-white" onerror="this.style.display='none';this.parentNode.innerHTML='<?=substr($name,0,1)?>'">
                    </div>
                    <span class="text-xs font-bold text-slate-700"><?=$name?></span>
                    <div class="absolute top-2 right-2 text-brand-orange opacity-0 check-icon <?= $sel_prov===$name?'!opacity-100':'' ?>">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </a>
                <?php endforeach;?>
            </div>
        </div>

        <?php if(!empty($benefs)):?>
        <div class="mb-6">
            <label class="block text-sm font-semibold text-slate-700 mb-3">Saved Decoders</label>
            <div class="flex gap-3 overflow-x-auto pb-2 custom-scrollbar">
                <?php foreach($benefs as $b):?>
                <button type="button" class="flex-shrink-0 flex items-center gap-3 p-3 rounded-xl border border-slate-200 hover:border-brand-orange hover:bg-brand-orangeLight transition-all text-left group min-w-[160px]" onclick="document.getElementById('scF').value='<?=htmlspecialchars($b['value'])?>';resetVerify()">
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

        <!-- Smartcard Number -->
        <div class="mb-6">
            <label class="block text-sm font-semibold text-slate-700 mb-2">Smartcard / IUC Number</label>
            <div class="flex gap-2">
                <div class="relative flex-1">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <i class="fas fa-tv text-slate-400"></i>
                    </div>
                    <input type="text" id="scF" class="block w-full pl-11 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium text-lg tracking-wide" placeholder="Enter smartcard/IUC number" inputmode="numeric" oninput="resetVerify()">
                </div>
                <button type="button" onclick="verifySC()" id="verifyBtn" class="bg-brand-navy hover:bg-brand-navyLight text-white px-5 rounded-xl font-medium transition-colors shadow-sm whitespace-nowrap">
                    Verify
                </button>
            </div>
            
            <div id="verifyCard" class="hidden mt-3 p-4 bg-emerald-50 border border-emerald-200 rounded-xl flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-emerald-100 flex items-center justify-center text-emerald-600 shrink-0">
                    <i class="fas fa-user-check"></i>
                </div>
                <div>
                    <div class="text-sm font-bold text-emerald-800" id="vName"></div>
                    <div class="text-xs font-semibold text-emerald-600 mt-0.5">Smartcard verified successfully</div>
                </div>
            </div>
        </div>

        <!-- Plan Selection -->
        <div class="mb-6">
            <label class="block text-sm font-semibold text-slate-700 mb-2">Select Bouquet</label>
            <div class="relative">
                <select class="block w-full px-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium appearance-none" id="planSel" onchange="onPlan(this)">
                    <option value="">-- Choose a bouquet --</option>
                    <?php foreach ($plans as $p): ?>
                    <option value="<?=$p['id']?>" data-price="<?=$p['selling_price']?>" data-name="<?=htmlspecialchars($p['display_name'] ?? $p['plan_name'])?>">
                        <?=htmlspecialchars($p['display_name'] ?? $p['plan_name'])?> - ₦<?=number_format((float)$p['selling_price'], 0)?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-4 text-slate-500">
                    <i class="fas fa-chevron-down"></i>
                </div>
            </div>
            
            <div id="planDetail" class="hidden mt-3 p-4 bg-brand-orangeLight border border-brand-orange/30 rounded-xl flex justify-between items-center">
                <div>
                    <span class="block text-xs text-brand-orange/80 uppercase tracking-wider mb-0.5">Selected Bouquet</span>
                    <span class="text-sm font-bold text-brand-orangeHover" id="pdName"></span>
                </div>
                <div class="text-right">
                    <span class="block text-xs text-brand-orange/80 uppercase tracking-wider mb-0.5">Price</span>
                    <span class="text-lg font-bold text-brand-orangeHover" id="pdPrice"></span>
                </div>
            </div>
        </div>

        <input type="hidden" id="verifiedF" value="0">
        <input type="hidden" id="cableId" value="<?=$cable_id?>">
        <input type="hidden" id="csrfF" value="<?=$csrf?>">
        
        <button type="button" onclick="openConfirm()" class="w-full bg-gradient-to-r from-brand-orange to-brand-orangeHover hover:from-brand-orangeHover hover:to-orange-600 text-white font-bold text-lg py-4 rounded-xl shadow-lg shadow-orange-500/30 transition-all hover:-translate-y-0.5 flex items-center justify-center gap-2">
            Subscribe <i class="fas fa-arrow-right"></i>
        </button>
        
        <p class="text-center text-xs text-slate-500 mt-4 flex items-center justify-center gap-1">
            <i class="fas fa-tv text-purple-500"></i> Subscription activated instantly on your decoder
        </p>
    </div>
</div>

<script>
function onPlan(sel) {
    const opt = sel.options[sel.selectedIndex];
    const det = document.getElementById('planDetail');
    if(!opt.value) {
        det.classList.add('hidden');
        return;
    }
    
    document.getElementById('pdName').innerText = opt.getAttribute('data-name');
    document.getElementById('pdPrice').innerText = '₦' + Number(opt.getAttribute('data-price')).toLocaleString();
    det.classList.remove('hidden');
}

function resetVerify() {
    document.getElementById('verifiedF').value = '0';
    document.getElementById('verifyCard').classList.add('hidden');
}

function verifySC() {
    const cid = document.getElementById('cableId').value;
    const sc = document.getElementById('scF').value;
    
    if(!sc) return alert('Enter smartcard number');
    
    const btn = document.getElementById('verifyBtn');
    const ogHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    btn.disabled = true;
    
    const fd = new FormData();
    fd.append('action', 'validate_smart');
    fd.append('csrf_token', document.getElementById('csrfF').value);
    fd.append('cable_id', cid);
    fd.append('smartcard', sc);
    
    fetch('', { method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: fd })
    .then(r => r.json())
    .then(res => {
        btn.innerHTML = ogHtml;
        btn.disabled = false;
        
        if(res.success) {
            document.getElementById('verifiedF').value = '1';
            document.getElementById('vName').innerText = res.name;
            document.getElementById('verifyCard').classList.remove('hidden');
        } else {
            alert(res.message || 'Verification failed');
            resetVerify();
        }
    })
    .catch(err => {
        btn.innerHTML = ogHtml;
        btn.disabled = false;
        alert('Network error verifying smartcard');
    });
}

function openConfirm() {
    const sc = document.getElementById('scF').value;
    const isV = document.getElementById('verifiedF').value;
    const planSel = document.getElementById('planSel');
    const planId = planSel.value;
    
    if(!sc) return alert('Enter smartcard number');
    if(isV !== '1') return alert('Please verify smartcard first');
    if(!planId) return alert('Please select a bouquet');
    
    const opt = planSel.options[planSel.selectedIndex];
    const prc = opt.getAttribute('data-price');
    const name = document.getElementById('vName').innerText;
    const prov = '<?=$sel_prov?>';
    
    const pin = prompt(`Confirm Cable Subscription:\nProvider: ${prov}\nSmartcard: ${sc}\nName: ${name}\nAmount: ₦${prc}\n\nEnter your 4-digit PIN:`);
    
    if(pin && pin.length >= 4) {
        processPurchase(planId, sc, pin);
    }
}

function processPurchase(planId, sc, pin) {
    const btn = document.querySelector('button[onclick="openConfirm()"]');
    const ogHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    btn.disabled = true;
    
    const cid = document.getElementById('cableId').value;
    
    const fd = new FormData();
    fd.append('action', 'pay');
    fd.append('csrf_token', document.getElementById('csrfF').value);
    fd.append('cable_id', cid);
    fd.append('plan_id', planId);
    fd.append('smartcard', sc);
    fd.append('pin', pin);
    
    fetch('', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if(res.success) {
            alert(`Success! Subscription successful.\n${res.message}`);
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