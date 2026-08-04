<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/recharge_card.php';
auth_user();
$uid = (int)$_SESSION['user_id'];
$user = get_user($uid); 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) { 
    if (!verify_csrf($_POST['csrf_token'] ?? '')) json_response(['success' => false, 'message' => 'Invalid token'], 403); 
    if (!rate_limit('rcard_'.$uid, 3, 60)) json_response(['success' => false, 'message' => 'Too many requests.'], 429); 
    json_response(process_recharge_card($uid, $_POST));
} 

$csrf = csrf_token();
$nets = [ 
    1 => ['name' => 'MTN', 'bg' => 'bg-yellow-400', 'color' => 'text-slate-900', 'logo' => '../assets/logos/mtn.png'], 
    2 => ['name' => 'GLO', 'bg' => 'bg-green-600', 'color' => 'text-white', 'logo' => '../assets/logos/glo.png'], 
    3 => ['name' => 'AIRTEL', 'bg' => 'bg-red-600', 'color' => 'text-white', 'logo' => '../assets/logos/airtel.png'], 
    4 => ['name' => '9MOBILE', 'bg' => 'bg-emerald-800', 'color' => 'text-white', 'logo' => '../assets/logos/9mobile.png']
];

$page_title = 'Recharge Card'; 
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
                <h1 class="text-lg sm:text-xl font-bold text-slate-800">Recharge Card</h1>
                <p class="text-xs sm:text-sm text-slate-500">Generate e-pins for printing</p>
            </div>
        </div>
        <div class="bg-brand-orangeLight text-brand-orangeHover px-2.5 sm:px-4 py-1.5 sm:py-2 rounded-xl font-bold border border-brand-orange/20 shadow-sm text-sm">
            ₦<?=number_format((float)$user['wallet_balance'],2)?>
        </div>
    </div>

    <div class="bg-white rounded-xl sm:rounded-2xl border border-slate-200 card-shadow p-4 sm:p-5 mb-4 sm:mb-6">
        
        <!-- Network Selection -->
        <div class="mb-4 sm:mb-5">
            <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-2 sm:mb-3">Select Network</label>
            <div class="grid grid-cols-4 gap-2 sm:gap-3">
                <?php foreach($nets as $id => $n):?>
                <button type="button" class="net-btn flex flex-col items-center justify-center p-2 sm:p-3 rounded-xl border-2 border-slate-100 bg-slate-50 hover:border-brand-orange/50 transition-all relative overflow-hidden text-center group" data-id="<?=$id?>" data-name="<?=$n['name']?>" onclick="selNet(this, <?=$id?>, '<?=$n['name']?>')">
                    <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-full mb-1 sm:mb-2 overflow-hidden bg-white shadow-sm flex items-center justify-center <?=$n['bg']?> <?=$n['color']?> font-bold text-xs sm:text-base">
                        <img src="<?=$n['logo']?>" alt="<?=$n['name']?>" class="w-full h-full object-cover" onerror="this.parentNode.style.color='<?=$n['color']?>';this.parentNode.textContent='<?=substr($n['name'],0,1)?>'">
                    </div>
                    <span class="text-[10px] sm:text-xs font-semibold text-slate-700"><?=$n['name']?></span>
                    <div class="absolute top-1 sm:top-2 right-1 sm:right-2 text-brand-orange opacity-0 check-icon">
                        <i class="fas fa-check-circle text-[10px] sm:text-xs"></i>
                    </div>
                </button>
                <?php endforeach;?>
            </div>
        </div>

        <!-- Denomination -->
        <div class="mb-4 sm:mb-5">
            <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-2 sm:mb-3">Denomination (₦)</label>
            <div class="grid grid-cols-4 gap-1.5 sm:gap-2">
                <?php foreach([100, 200, 500, 1000] as $d):?>
                <button type="button" class="amt-chip py-2 sm:py-3 bg-slate-50 border-2 border-slate-100 hover:border-brand-orange hover:bg-brand-orangeLight hover:text-brand-orange rounded-xl text-[10px] sm:text-sm font-bold text-slate-700 transition-all" onclick="selDenom(<?=$d?>,this)">₦<?=$d?></button>
                <?php endforeach;?>
            </div>
        </div>

        <!-- Quantity Selection -->
        <div class="mb-4 sm:mb-5">
            <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-2 sm:mb-3">Quantity (1-5)</label>
            <div class="flex items-center justify-between bg-slate-50 border border-slate-200 rounded-xl p-1.5 sm:p-2">
                <button type="button" onclick="chgQty(-1)" class="w-10 h-10 sm:w-12 sm:h-12 rounded-lg bg-white border border-slate-200 text-slate-600 hover:border-brand-orange hover:text-brand-orange flex items-center justify-center transition-colors shadow-sm text-xs sm:text-sm">
                    <i class="fas fa-minus"></i>
                </button>
                
                <div class="flex-1 text-center">
                    <div id="qtyD" class="text-2xl sm:text-3xl font-black text-brand-navy">1</div>
                    <div class="text-[10px] sm:text-xs font-semibold text-slate-400 uppercase tracking-wide">PIN(s)</div>
                </div>
                
                <button type="button" onclick="chgQty(1)" class="w-10 h-10 sm:w-12 sm:h-12 rounded-lg bg-white border border-slate-200 text-slate-600 hover:border-brand-orange hover:text-brand-orange flex items-center justify-center transition-colors shadow-sm text-xs sm:text-sm">
                    <i class="fas fa-plus"></i>
                </button>
            </div>
            
            <div id="totalBox" class="hidden mt-3 sm:mt-4 p-3 sm:p-4 bg-brand-orangeLight border border-brand-orange/30 rounded-xl flex justify-between items-center">
                <div>
                    <span class="block text-[10px] sm:text-xs text-brand-orange/80 uppercase tracking-wider mb-0.5">Total Amount</span>
                    <span class="text-xs sm:text-sm font-semibold text-brand-orangeHover"><span id="summaryQty">1</span>x <span id="summaryNet"></span> (₦<span id="summaryDenom">0</span>)</span>
                </div>
                <div class="text-right">
                    <span class="text-lg sm:text-2xl font-black text-brand-orangeHover" id="totalD"></span>
                </div>
            </div>
        </div>

        <!-- Name on Card -->
        <div class="mb-4 sm:mb-5">
            <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5 sm:mb-2">Name on Card <span class="text-slate-400 font-normal text-[10px] sm:text-xs ml-1">Optional</span></label>
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 sm:pl-4 flex items-center pointer-events-none">
                    <i class="fas fa-id-badge text-slate-400 text-xs"></i>
                </div>
                <input type="text" id="nameF" class="block w-full pl-9 sm:pl-11 pr-3 sm:pr-4 py-2.5 sm:py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium text-sm" placeholder="E.g. Your Business Name" maxlength="30">
            </div>
        </div>

        <input type="hidden" id="netId" value="">
        <input type="hidden" id="denom" value="0">
        <input type="hidden" id="qty" value="1">
        <input type="hidden" id="csrfF" value="<?=$csrf?>">
        
        <button type="button" onclick="openConf()" class="w-full bg-gradient-to-r from-brand-orange to-brand-orangeHover hover:from-brand-orangeHover hover:to-orange-600 text-white font-bold text-sm sm:text-base py-3 sm:py-3.5 rounded-xl shadow-lg shadow-orange-500/30 transition-all hover:-translate-y-0.5 flex items-center justify-center gap-2">
            Print Recharge Cards <i class="fas fa-print"></i>
        </button>
        
        <p class="text-center text-[10px] sm:text-xs text-slate-500 mt-3 sm:mt-4 flex items-center justify-center gap-1">
            <i class="fas fa-bolt text-yellow-500"></i> PINs printed instantly as digital codes
        </p>
    </div>
</div>

<script>
let selNetworkName = '';

function selNet(btn, id, name) {
    document.querySelectorAll('.net-btn').forEach(b => {
        b.classList.remove('border-brand-orange', 'bg-brand-orangeLight');
        b.classList.add('border-slate-100', 'bg-slate-50');
        b.querySelector('.check-icon').classList.remove('!opacity-100');
    });
    btn.classList.remove('border-slate-100', 'bg-slate-50');
    btn.classList.add('border-brand-orange', 'bg-brand-orangeLight');
    btn.querySelector('.check-icon').classList.add('!opacity-100');
    
    document.getElementById('netId').value = id;
    selNetworkName = name;
    document.getElementById('summaryNet').innerText = name;
    updTot();
}

function selDenom(d, btn) {
    document.getElementById('denom').value = d;
    document.querySelectorAll('.amt-chip').forEach(b => {
        b.classList.remove('!border-brand-orange', '!bg-brand-orangeLight', '!text-brand-orange');
    });
    btn.classList.add('!border-brand-orange', '!bg-brand-orangeLight', '!text-brand-orange');
    document.getElementById('summaryDenom').innerText = d;
    updTot();
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
    const d = parseFloat(document.getElementById('denom').value) || 0;
    const q = parseInt(document.getElementById('qty').value) || 1;
    const box = document.getElementById('totalBox');
    
    if(d > 0 && document.getElementById('netId').value) {
        document.getElementById('totalD').innerText = '₦' + (d * q).toLocaleString();
        box.classList.remove('hidden');
    } else {
        box.classList.add('hidden');
    }
}

function openConf() {
    const nid = document.getElementById('netId').value;
    const d = parseFloat(document.getElementById('denom').value);
    const q = parseInt(document.getElementById('qty').value);
    
    if(!nid) return Swal.fire('Notice', 'Select network', 'info');
    if(!d || d <= 0) return Swal.fire('Notice', 'Select denomination', 'info');
    
    const tot = d * q;
    confirmTransaction({
        "Transaction": "Recharge Card Purchase",
        "Network": selNetworkName,
        "Denomination": "₦" + d,
        "Quantity": q,
        "Total Amount": "₦" + tot.toLocaleString('en-US')
    }, function(pin) {
        proc(nid, d, q, pin);
    });
}

function proc(nid, d, q, pin) {
    const btn = document.querySelector('button[onclick="openConf()"]');
    const ogHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    btn.disabled = true;
    
    const nameStr = document.getElementById('nameF').value;
    
    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('csrfF').value);
    fd.append('network_id', nid);
    fd.append('denomination', d);
    fd.append('quantity', q);
    fd.append('name_on_card', nameStr);
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
