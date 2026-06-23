<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/data.php';
require_once __DIR__.'/core/beneficiaries.php';
auth_user(); 
$uid = (int)$_SESSION['user_id'];
$user = get_user($uid); 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) { 
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { 
        json_response(['success' => false, 'message' => 'Invalid token'], 403); 
    } 
    if (!rate_limit('data_'.$uid, 5, 60)) { 
        json_response(['success' => false, 'message' => 'Too many requests.'], 429); 
    } 
    json_response(process_data($uid, $_POST));
} 

$csrf = csrf_token(); 
$sel_net = strtoupper($_GET['net'] ?? 'MTN');
if (!in_array($sel_net, ['MTN', 'GLO', 'AIRTEL', '9MOBILE'])) { 
    $sel_net = 'MTN';
} 
$sel_type = $_GET['type'] ?? '';
$plans = get_data_plans($sel_net, $sel_type);
$types = get_data_types($sel_net);
$benefs = get_beneficiaries($uid, 'data'); 

$nets = [
    'MTN'=>['bg-yellow-400','text-slate-900', '/msjpay/assets/logos/mtn.png'],
    'GLO'=>['bg-green-600','text-white', '/msjpay/assets/logos/glo.png'],
    'AIRTEL'=>['bg-red-600','text-white', '/msjpay/assets/logos/airtel.png'],
    '9MOBILE'=>['bg-emerald-800','text-white', '/msjpay/assets/logos/9mobile.png']
];

$benef_id = (int)($_GET['benef'] ?? 0);
$prefill = '';
if ($benef_id) { 
    $b = array_filter($benefs, fn($x) => $x['id'] === $benef_id); 
    if ($b) { 
        $b = array_values($b)[0]; 
        $prefill = $b['value']; 
    }
}

$page_title = 'Buy Data'; 
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
                <h1 class="text-2xl font-bold text-slate-800">Buy Data</h1>
                <p class="text-sm text-slate-500">Instant data bundles for all networks</p>
            </div>
        </div>
        <div class="bg-brand-orangeLight text-brand-orangeHover px-4 py-2 rounded-xl font-bold border border-brand-orange/20 shadow-sm">
            ₦<?=number_format((float)$user['wallet_balance'],2)?>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 card-shadow p-6 mb-6">
        
        <?php if(!empty($benefs)):?>
        <div class="mb-6">
            <label class="block text-sm font-semibold text-slate-700 mb-3">Saved Numbers</label>
            <div class="flex gap-3 overflow-x-auto pb-2 custom-scrollbar">
                <?php foreach($benefs as $b):?>
                <button type="button" class="flex-shrink-0 flex items-center gap-3 p-3 rounded-xl border border-slate-200 hover:border-brand-orange hover:bg-brand-orangeLight transition-all text-left group min-w-[160px]" onclick="document.getElementById('phoneF').value='<?=htmlspecialchars($b['value'])?>'">
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

        <!-- Network Selection -->
        <div class="mb-6">
            <label class="block text-sm font-semibold text-slate-700 mb-3">Select Network</label>
            <div class="grid grid-cols-4 gap-3">
                <?php foreach($nets as $n=>[$bg, $color, $logo]):?>
                <a href="?net=<?=$n?>&type=<?=$sel_type?>" class="net-btn flex flex-col items-center justify-center p-3 rounded-xl border-2 <?= $sel_net===$n?'border-brand-orange bg-brand-orangeLight':'border-slate-100 bg-slate-50 hover:border-brand-orange/50' ?> transition-all relative overflow-hidden text-center">
                    <div class="w-12 h-12 rounded-full mb-2 overflow-hidden bg-white shadow-sm flex items-center justify-center <?= $bg ?> <?= $color ?> font-bold">
                        <img src="<?=$logo?>" alt="<?=$n?>" class="w-full h-full object-cover" onerror="this.style.display='none';this.parentNode.innerHTML='<?=substr($n,0,1)?>'">
                    </div>
                    <span class="text-xs font-semibold text-slate-700"><?=$n?></span>
                    <div class="absolute top-2 right-2 text-brand-orange opacity-0 check-icon <?= $sel_net===$n?'!opacity-100':'' ?>">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </a>
                <?php endforeach;?>
            </div>
        </div>
        
        <!-- Data Type Selection -->
        <?php if (!empty($types)): ?>
        <div class="mb-6">
            <label class="block text-sm font-semibold text-slate-700 mb-2">Data Type</label>
            <select class="block w-full px-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium appearance-none" id="typeSelect" onchange="location.href='?net=<?=$sel_net?>&type='+this.value">
                <option value="">All Types</option>
                <?php foreach ($types as $t): ?>
                <option value="<?=htmlspecialchars($t['data_type'])?>" <?=$sel_type === $t['data_type'] ? 'selected' : ''?>>
                    <?=htmlspecialchars($t['label'])?>
                </option>
                <?php endforeach; ?>
            </select>
            <!-- Custom chevron for select -->
            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-4 text-slate-500" style="margin-top:-45px">
                <i class="fas fa-chevron-down"></i>
            </div>
        </div>
        <?php endif; ?>

        <!-- Phone Number -->
        <div class="mb-6">
            <label class="block text-sm font-semibold text-slate-700 mb-2">Phone Number</label>
            <div class="flex gap-2">
                <div class="relative flex-1">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <i class="fas fa-phone text-slate-400"></i>
                    </div>
                    <input type="tel" id="phoneF" class="block w-full pl-11 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium text-lg tracking-wide" placeholder="08012345678" maxlength="11" inputmode="numeric" value="<?=$prefill?>">
                </div>
                <button type="button" onclick="alert('Save contact feature requires endpoint implementation')" class="bg-brand-navy hover:bg-brand-navyLight text-white px-5 rounded-xl font-medium transition-colors shadow-sm whitespace-nowrap flex items-center gap-2">
                    <i class="fas fa-bookmark"></i> <span class="hidden sm:inline">Save</span>
                </button>
            </div>
        </div>

        <!-- Plan Selection -->
        <div class="mb-6">
            <label class="block text-sm font-semibold text-slate-700 mb-2">Select Plan</label>
            <div class="relative">
                <select class="block w-full px-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium appearance-none" id="planSelect" onchange="onPlanChange(this)">
                    <option value="">-- Choose a plan --</option>
                    <?php foreach ($plans as $p): ?>
                    <option value="<?=$p['id']?>" data-price="<?=$p['selling_price']?>" data-size="<?=htmlspecialchars($p['data_size'])?>" data-validity="<?=htmlspecialchars($p['validity'] ?? '')?>">
                        <?=htmlspecialchars($p['display_name'] ?? $p['plan_name'])?> - <?=htmlspecialchars($p['data_size'])?> / <?=htmlspecialchars($p['validity'] ?? '')?> - ₦<?=number_format((float)$p['selling_price'], 0)?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-4 text-slate-500">
                    <i class="fas fa-chevron-down"></i>
                </div>
            </div>
            
            <div id="planDetail" class="hidden mt-3 p-4 bg-brand-orangeLight border border-brand-orange/30 rounded-xl text-sm font-semibold text-brand-orangeHover">
            </div>
        </div>

        <input type="hidden" id="selectedPlan" value="">
        <input type="hidden" id="csrfF" value="<?=$csrf?>">
        
        <button type="button" onclick="openConfirm()" class="w-full bg-gradient-to-r from-brand-orange to-brand-orangeHover hover:from-brand-orangeHover hover:to-orange-600 text-white font-bold text-lg py-4 rounded-xl shadow-lg shadow-orange-500/30 transition-all hover:-translate-y-0.5 flex items-center justify-center gap-2">
            Continue to Pay <i class="fas fa-arrow-right"></i>
        </button>
        
        <p class="text-center text-xs text-slate-500 mt-4 flex items-center justify-center gap-1">
            <i class="fas fa-wifi text-emerald-500"></i> Data delivered instantly after payment
        </p>
    </div>
</div>

<!-- Scripts for UI logic -->
<script>
function onPlanChange(sel) {
    const opt = sel.options[sel.selectedIndex];
    const det = document.getElementById('planDetail');
    if(!opt.value) {
        det.style.display = 'none';
        document.getElementById('selectedPlan').value = '';
        return;
    }
    
    document.getElementById('selectedPlan').value = opt.value;
    const size = opt.getAttribute('data-size');
    const val = opt.getAttribute('data-validity');
    const prc = opt.getAttribute('data-price');
    
    det.innerHTML = `<div class="flex justify-between items-center">
        <div><span class="block text-xs text-brand-orange/80 uppercase tracking-wider mb-0.5">Selected Plan</span>
        <span class="text-lg">${size}</span></div>
        <div class="text-right"><span class="block text-xs text-brand-orange/80 uppercase tracking-wider mb-0.5">Price</span>
        <span class="text-lg">₦${Number(prc).toLocaleString()}</span></div>
    </div>`;
    det.style.display = 'block';
}

function openConfirm() {
    const phone = document.getElementById('phoneF').value;
    const planId = document.getElementById('selectedPlan').value;
    
    if(!phone || phone.length < 10) return alert('Please enter a valid phone number');
    if(!planId) return alert('Please select a data plan');
    
    const sel = document.getElementById('planSelect');
    const opt = sel.options[sel.selectedIndex];
    const size = opt.getAttribute('data-size');
    const prc = opt.getAttribute('data-price');
    const net = '<?=$sel_net?>';
    
    const pin = prompt(`Confirm Data Purchase:\nNetwork: ${net}\nPhone: ${phone}\nPlan: ${size}\nAmount: ₦${prc}\n\nEnter your 4-digit PIN:`);
    
    if(pin && pin.length >= 4) {
        processPurchase(planId, phone, pin);
    }
}

function processPurchase(planId, phone, pin) {
    const btn = document.querySelector('button[onclick="openConfirm()"]');
    const ogHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    btn.disabled = true;
    
    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('csrfF').value);
    fd.append('plan_id', planId);
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
            alert(`Success! Data Sent.\n${res.message}`);
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