<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/data_card.php';
require_once __DIR__.'/core/data.php';
auth_user();
$uid = (int)$_SESSION['user_id'];
$user = get_user($uid); 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) { 
    if (!verify_csrf($_POST['csrf_token'] ?? '')) json_response(['success' => false, 'message' => 'Invalid token'], 403); 
    if (!rate_limit('dcard_'.$uid, 3, 60)) json_response(['success' => false, 'message' => 'Too many requests.'], 429); 
    json_response(process_data_card($uid, $_POST));
} 

$csrf = csrf_token();
$sel_net = strtoupper($_GET['net'] ?? 'MTN');
if (!in_array($sel_net, ['MTN', 'GLO', 'AIRTEL', '9MOBILE'])) $sel_net = 'MTN';
$plans = get_data_plans($sel_net);

$nets = [ 
    'MTN' => ['bg' => 'bg-yellow-400', 'color' => 'text-slate-900', 'logo' => '/msjpay/assets/logos/mtn.png'], 
    'GLO' => ['bg' => 'bg-green-600', 'color' => 'text-white', 'logo' => '/msjpay/assets/logos/glo.png'], 
    'AIRTEL' => ['bg' => 'bg-red-600', 'color' => 'text-white', 'logo' => '/msjpay/assets/logos/airtel.png'], 
    '9MOBILE' => ['bg' => 'bg-emerald-800', 'color' => 'text-white', 'logo' => '/msjpay/assets/logos/9mobile.png']
];

$page_title = 'Data Card'; 
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
                <h1 class="text-2xl font-bold text-slate-800">Data Card</h1>
                <p class="text-sm text-slate-500">Generate data e-pins for printing</p>
            </div>
        </div>
        <div class="bg-brand-orangeLight text-brand-orangeHover px-4 py-2 rounded-xl font-bold border border-brand-orange/20 shadow-sm">
            ₦<?=number_format((float)$user['wallet_balance'],2)?>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 card-shadow p-6 mb-6">
        
        <!-- Network Selection -->
        <div class="mb-6">
            <label class="block text-sm font-semibold text-slate-700 mb-3">Select Network</label>
            <div class="grid grid-cols-4 gap-3">
                <?php foreach($nets as $n => $info):?>
                <a href="?net=<?=$n?>" class="flex flex-col items-center justify-center p-3 rounded-xl border-2 transition-all relative overflow-hidden text-center group <?= $sel_net === $n ? 'border-brand-orange bg-brand-orangeLight' : 'border-slate-100 bg-slate-50 hover:border-brand-orange/50' ?>">
                    <div class="w-12 h-12 rounded-full mb-2 overflow-hidden bg-white shadow-sm flex items-center justify-center <?=$info['bg']?> <?=$info['color']?> font-bold">
                        <img src="<?=$info['logo']?>" alt="<?=$n?>" class="w-full h-full object-cover" onerror="this.parentNode.style.color='<?=$info['color']?>';this.parentNode.textContent='<?=substr($n,0,1)?>'">
                    </div>
                    <span class="text-xs font-semibold text-slate-700"><?=$n?></span>
                    
                    <?php if($sel_net === $n): ?>
                    <div class="absolute top-2 right-2 text-brand-orange">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <?php endif; ?>
                </a>
                <?php endforeach;?>
            </div>
        </div>

        <!-- Plan Selection -->
        <div class="mb-6">
            <label class="block text-sm font-semibold text-slate-700 mb-2">Select Plan</label>
            <div class="relative">
                <select class="block w-full px-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium appearance-none" id="planSel" onchange="onPlan(this)">
                    <option value="">-- Choose a data plan --</option>
                    <?php foreach ($plans as $p): ?> 
                    <option value="<?= $p['id'] ?>" data-price="<?= $p['selling_price'] ?>" data-size="<?= htmlspecialchars($p['data_size']) ?>" data-name="<?= htmlspecialchars($p['display_name'] ?? $p['plan_name']) ?>" data-type="<?= htmlspecialchars($p['data_type']) ?>"><?= htmlspecialchars($p['display_name'] ?? $p['plan_name']) ?> - <?= $p['data_size'] ?> (<?= $p['data_type'] ?>) - ₦<?= number_format((float)$p['selling_price'], 0) ?></option> 
                    <?php endforeach; ?>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-4 text-slate-500">
                    <i class="fas fa-chevron-down"></i>
                </div>
            </div>
            
            <div id="planDetail" class="hidden mt-3 p-4 bg-blue-50 border border-blue-200 rounded-xl flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 shrink-0">
                    <i class="fas fa-wifi"></i>
                </div>
                <div>
                    <div class="text-sm font-bold text-blue-800" id="pdName"></div>
                    <div class="text-xs font-semibold text-blue-600 mt-0.5">Type: <span id="pdType"></span> | Price: ₦<span id="pdPrice"></span></div>
                </div>
            </div>
        </div>

        <!-- Quantity Selection -->
        <div class="mb-6">
            <label class="block text-sm font-semibold text-slate-700 mb-3">Quantity (1-5)</label>
            <div class="flex items-center justify-between bg-slate-50 border border-slate-200 rounded-xl p-2">
                <button type="button" onclick="chgQty(-1)" class="w-12 h-12 rounded-lg bg-white border border-slate-200 text-slate-600 hover:border-brand-orange hover:text-brand-orange flex items-center justify-center transition-colors shadow-sm">
                    <i class="fas fa-minus"></i>
                </button>
                
                <div class="flex-1 text-center">
                    <div id="qtyD" class="text-3xl font-black text-brand-navy">1</div>
                    <div class="text-xs font-semibold text-slate-400 uppercase tracking-wide">PIN(s)</div>
                </div>
                
                <button type="button" onclick="chgQty(1)" class="w-12 h-12 rounded-lg bg-white border border-slate-200 text-slate-600 hover:border-brand-orange hover:text-brand-orange flex items-center justify-center transition-colors shadow-sm">
                    <i class="fas fa-plus"></i>
                </button>
            </div>
            
            <div id="totalBox" class="hidden mt-4 p-4 bg-brand-orangeLight border border-brand-orange/30 rounded-xl flex justify-between items-center">
                <div>
                    <span class="block text-xs text-brand-orange/80 uppercase tracking-wider mb-0.5">Total Amount</span>
                    <span class="text-sm font-semibold text-brand-orangeHover"><span id="summaryQty">1</span>x <span id="summaryName"></span></span>
                </div>
                <div class="text-right">
                    <span class="text-2xl font-black text-brand-orangeHover" id="totalD"></span>
                </div>
            </div>
        </div>

        <input type="hidden" id="selPlan" value="">
        <input type="hidden" id="selPrice" value="0">
        <input type="hidden" id="qty" value="1">
        <input type="hidden" id="csrfF" value="<?= $csrf ?>">
        
        <button type="button" onclick="openConf()" class="w-full bg-gradient-to-r from-brand-orange to-brand-orangeHover hover:from-brand-orangeHover hover:to-orange-600 text-white font-bold text-lg py-4 rounded-xl shadow-lg shadow-orange-500/30 transition-all hover:-translate-y-0.5 flex items-center justify-center gap-2">
            Purchase Data Cards <i class="fas fa-print"></i>
        </button>
        
        <p class="text-center text-xs text-slate-500 mt-4 flex items-center justify-center gap-1">
            <i class="fas fa-bolt text-yellow-500"></i> PINs printed instantly as digital codes
        </p>
    </div>
</div>

<script>
function onPlan(sel) {
    const opt = sel.options[sel.selectedIndex];
    if(opt.value) {
        document.getElementById('selPlan').value = opt.value;
        const prc = parseFloat(opt.getAttribute('data-price'));
        document.getElementById('selPrice').value = prc;
        
        document.getElementById('pdName').innerText = opt.getAttribute('data-name') + ' - ' + opt.getAttribute('data-size');
        document.getElementById('pdType').innerText = opt.getAttribute('data-type');
        document.getElementById('pdPrice').innerText = prc.toLocaleString();
        
        document.getElementById('summaryName').innerText = opt.getAttribute('data-size');
        
        document.getElementById('planDetail').classList.remove('hidden');
        updTot();
    } else {
        document.getElementById('selPlan').value = '';
        document.getElementById('selPrice').value = '0';
        document.getElementById('planDetail').classList.add('hidden');
        document.getElementById('totalBox').classList.add('hidden');
    }
}

function chgQty(dir) {
    let q = parseInt(document.getElementById('qty').value) + dir;
    if(q < 1) q = 1;
    if(q > 5) q = 5;
    document.getElementById('qty').value = q;
    document.getElementById('qtyD').innerText = q;
    document.getElementById('summaryQty').innerText = q;
    updTot();
}

function updTot() {
    const prc = parseFloat(document.getElementById('selPrice').value) || 0;
    const q = parseInt(document.getElementById('qty').value) || 1;
    const box = document.getElementById('totalBox');
    
    if(prc > 0 && document.getElementById('selPlan').value) {
        document.getElementById('totalD').innerText = '₦' + (prc * q).toLocaleString();
        box.classList.remove('hidden');
    } else {
        box.classList.add('hidden');
    }
}

function openConf() {
    const pid = document.getElementById('selPlan').value;
    const prc = parseFloat(document.getElementById('selPrice').value);
    const q = parseInt(document.getElementById('qty').value);
    
    if(!pid) return alert('Select a data plan');
    
    const tot = prc * q;
    const selName = document.getElementById('pdName').innerText;
    
    const pin = prompt(`Confirm Data Card Purchase:\n\nNetwork: <?=$sel_net?>\nPlan: ${selName}\nQuantity: ${q}\nTotal: ₦${tot}\n\nEnter your 4-digit PIN:`);
    
    if(pin && pin.length >= 4) {
        proc(pid, q, pin);
    }
}

function proc(pid, q, pin) {
    const btn = document.querySelector('button[onclick="openConf()"]');
    const ogHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    btn.disabled = true;
    
    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('csrfF').value);
    fd.append('plan_id', pid);
    fd.append('quantity', q);
    fd.append('pin', pin);
    
    fetch('', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if(res.success) {
            let phtml = '';
            if(res.data && res.data.pins) {
                res.data.pins.forEach((p, i) => {
                    phtml += `PIN ${i+1}: ${p}\n`;
                });
            }
            alert(`Success! Data Cards Generated.\n\n${res.message}\n\n${phtml}`);
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