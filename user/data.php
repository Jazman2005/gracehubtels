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
    if (($_POST['action'] ?? '') === 'save_benef') {
        json_response(save_beneficiary($uid, $_POST));
    }
    json_response(process_data($uid, $_POST));
}

$csrf = csrf_token(); 
$sel_net = htmlspecialchars(strtoupper($_GET['net'] ?? ''));
if (!in_array($sel_net, ['MTN', 'GLO', 'AIRTEL', '9MOBILE'])) { 
    $sel_net = '';
} 
// get_data_types('') returns types across ALL networks (no filter applied),
// not "no types" — without this guard the Data Type dropdown showed up on
// first page load before the user had typed a phone number or picked a
// network at all, since $types was never actually empty.
$types = $sel_net ? get_data_types($sel_net) : [];
$sel_type = htmlspecialchars($_GET['type'] ?? '');
$plans = !empty($sel_type) ? get_data_plans($sel_net, $sel_type) : [];
$benefs = get_beneficiaries($uid, 'data'); 

$nets = [
    'MTN'=>['bg-yellow-400','text-slate-900'],
    'GLO'=>['bg-green-600','text-white'],
    'AIRTEL'=>['bg-red-600','text-white'],
    '9MOBILE'=>['bg-emerald-800','text-white'],
];

$benef_id = (int)($_GET['benef'] ?? 0);
$prefill = htmlspecialchars($_GET['phone'] ?? '');
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
<style>
    /* Plan size text (e.g. "1GB") was too faint in dark mode — text-slate-900
       isn't covered by header.php's dark-mode overrides, so it stayed near-
       black on a dark background. Force bold + black in light mode, bold +
       white in dark mode. */
    .plan-size-text { color:#000 !important; font-weight:900 !important; }
    html.dark-mode .plan-size-text { color:#fff !important; }
</style>

<div class="max-w-2xl mx-auto">
    <!-- Header -->
    <div class="flex items-center justify-between mb-4 sm:mb-6">
        <div class="flex items-center gap-4">
            <a href="dashboard.php" class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-white border border-slate-200 flex items-center justify-center text-slate-600 hover:bg-slate-50 transition-colors">
                <i class="fas fa-arrow-left text-xs sm:text-sm"></i>
            </a>
            <div>
                <h1 class="text-lg sm:text-xl font-bold text-slate-800">Buy Data</h1>
                <p class="text-xs sm:text-sm text-slate-500">Instant data bundles for all networks</p>
            </div>
        </div>
        <div class="bg-brand-orangeLight text-brand-orangeHover px-2.5 sm:px-4 py-1.5 sm:py-2 rounded-xl font-bold border border-brand-orange/20 shadow-sm text-sm">
            ₦<?=number_format((float)$user['wallet_balance'],2)?>
        </div>
    </div>

    <div class="bg-white rounded-xl sm:rounded-2xl border border-slate-200 card-shadow p-4 sm:p-5 mb-4 sm:mb-6">
        
        <?php if(!empty($benefs)):?>
        <div class="mb-4 sm:mb-5">
            <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-2 sm:mb-3">Saved Numbers</label>
            <div class="flex gap-2 sm:gap-3 overflow-x-auto pb-1 sm:pb-2 custom-scrollbar">
                <?php foreach($benefs as $b):?>
                <button type="button" class="flex-shrink-0 flex items-center gap-2 sm:gap-3 p-2 sm:p-3 rounded-xl border border-slate-200 hover:border-brand-orange hover:bg-brand-orangeLight transition-all text-left group min-w-[140px] sm:min-w-[160px]" onclick="let el = document.getElementById('phoneF'); el.value='<?=htmlspecialchars($b['value'])?>'; el.dispatchEvent(new Event('input'));">
                    <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-slate-100 flex items-center justify-center font-bold text-brand-navy relative text-xs sm:text-sm">
                        <?=strtoupper(substr($b['label'],0,2))?>
                        <?php if($b['is_favourite']):?>
                        <div class="absolute -top-1 -right-1 text-amber-400 text-[8px] sm:text-xs"><i class="fas fa-star"></i></div>
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

        <!-- Phone Number -->
        <div class="mb-4 sm:mb-5">
            <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5 sm:mb-2">Phone Number</label>
            <div class="flex gap-2">
                <div class="relative flex-1">
                    <div class="absolute inset-y-0 left-0 pl-3 sm:pl-4 flex items-center pointer-events-none">
                        <i class="fas fa-phone text-slate-400 text-xs"></i>
                    </div>
                    <input type="tel" id="phoneF" class="block w-full pl-9 sm:pl-11 pr-3 sm:pr-4 py-2.5 sm:py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium text-sm sm:text-base" placeholder="08012345678" maxlength="11" inputmode="numeric" value="<?=htmlspecialchars($prefill)?>">
                </div>
                <button type="button" onclick="saveBenef()" class="bg-brand-navy hover:bg-brand-navyLight text-white px-3 sm:px-5 rounded-xl font-medium transition-colors shadow-sm whitespace-nowrap flex items-center gap-1.5 sm:gap-2 text-xs sm:text-sm">
                    <i class="fas fa-bookmark text-xs"></i> <span class="hidden sm:inline">Save</span>
                </button>
            </div>
        </div>

        <!-- Network Selection — hidden until a phone number is typed; auto-highlights
             the detected network, but stays fully clickable so a ported number can
             be pointed at a different network. -->
        <div id="networkSection" class="mb-4 sm:mb-5 <?= empty($sel_net) ? 'hidden' : '' ?>">
            <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-2 sm:mb-3">Select Network</label>
            <div class="grid grid-cols-4 gap-2 sm:gap-3">
                <?php foreach($nets as $n=>$pinfo):
    $bg = $pinfo[0];
    $color = $pinfo[1];
    $logo = $pinfo[2];?>
                <a href="?net=<?=$n?>" data-net="<?=$n?>" class="net-btn flex flex-col items-center justify-center p-2 sm:p-3 rounded-xl border-2 <?= $sel_net===$n?'border-brand-orange bg-brand-orangeLight':'border-slate-100 bg-slate-50 hover:border-brand-orange/50' ?> transition-all relative overflow-hidden text-center">
                    <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-full mb-1 sm:mb-2 overflow-hidden bg-white shadow-sm flex items-center justify-center <?= $bg ?> <?= $color ?> font-bold text-xs sm:text-base">
                        <?php $logo_url = "https://api.msjpay.ng/assets/logos/" . strtolower($n) . ".png"; ?>
                        <img src="<?=$logo_url?>" alt="<?=$n?>" class="w-full h-full object-cover" onerror="this.style.display='none';this.parentNode.innerHTML='<?=substr($n,0,1)?>'">
                    </div>
                    <span class="text-[10px] sm:text-xs font-semibold text-slate-700"><?=$n?></span>
                    <div class="absolute top-1 sm:top-2 right-1 sm:right-2 text-brand-orange opacity-0 check-icon <?= $sel_net===$n?'!opacity-100':'' ?>">
                        <i class="fas fa-check-circle text-[10px] sm:text-xs"></i>
                    </div>
                </a>
                <?php endforeach;?>
            </div>
        </div>

        <!-- Data Type Selection -->
        <?php if (!empty($types)): ?>
        <div class="mb-4 sm:mb-5">
            <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5 sm:mb-2">Data Type</label>
            <div class="relative inline-block w-full">
                <select class="block w-full px-3 sm:px-4 py-2.5 sm:py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium appearance-none text-sm" id="typeSelect" onchange="location.href='?net=<?=$sel_net?>&type='+this.value+'&phone='+encodeURIComponent(document.getElementById('phoneF').value)">
                    <option value="" <?=empty($sel_type) ? 'selected' : ''?> disabled>-- Select Data Type --</option>
                    <?php foreach ($types as $t): ?>
                    <option value="<?=htmlspecialchars($t['data_type'])?>" <?=$sel_type === $t['data_type'] ? 'selected' : ''?>>
                        <?=htmlspecialchars($t['label'])?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 sm:pr-4 text-slate-500">
                    <i class="fas fa-chevron-down text-xs"></i>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <p id="networkWarn" class="text-red-500 text-[11px] sm:text-xs font-semibold mt-1.5 hidden"></p>
          <!-- Plan Selection -->
        <div class="mb-4 sm:mb-5">
            <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5 sm:mb-2">Select Plan</label>
            <div class="grid grid-cols-3 gap-2 sm:gap-3" id="planGrid">
                <?php foreach ($plans as $p): ?>
                <div class="plan-card cursor-pointer border-2 border-slate-100 bg-slate-50 hover:border-brand-orange/50 hover:bg-white rounded-xl p-2 sm:p-2.5 relative overflow-hidden transition-all text-center flex flex-col justify-center min-h-[90px] sm:min-h-[100px]" 
                     data-id="<?=$p['id']?>" 
                     data-price="<?=$p['selling_price']?>" 
                     data-size="<?=$sel_net?> <?=htmlspecialchars($p['data_size'])?>" 
                     data-validity="<?=htmlspecialchars($p['validity'] ?? '')?>"
                     onclick="selectPlanCard(this)">
                    <?php $clean_name = trim(str_ireplace([$sel_net, 'data'], '', $p['display_name'] ?? $p['plan_name'] ?? '')); ?>
                    <div class="text-[9px] sm:text-[10px] font-extrabold plan-size-text uppercase tracking-wider mb-0.5" style="font-weight:900;color:#000;"><?=htmlspecialchars($clean_name ?: ($p['data_type'] ?? 'BUNDLE'))?></div>
                    <div class="text-sm sm:text-base font-black plan-size-text my-0.5 sm:my-1" style="font-weight:900;color:#000;"><?=htmlspecialchars($p['data_size'])?></div>
                    <div class="text-sm sm:text-base font-black text-brand-orange">₦<?=number_format((float)$p['selling_price'], 0)?></div>
                    <div class="text-[9px] sm:text-[10px] text-slate-500 mt-0.5 sm:mt-1 font-semibold flex items-center justify-center gap-1"><i class="far fa-clock"></i> <?=htmlspecialchars($p['validity'] ?? '30 Days')?></div>
                    <div class="absolute top-1 sm:top-2 right-1 sm:right-2 text-brand-orange opacity-0 check-icon transition-opacity duration-300">
                        <i class="fas fa-check-circle text-[10px] sm:text-xs"></i>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if(empty($plans)): ?>
                <div class="col-span-full p-4 sm:p-6 text-center text-slate-500 bg-slate-50 rounded-xl border border-dashed border-slate-300">
                    <i class="fas <?=empty($sel_type) ? 'fa-hand-pointer' : 'fa-box-open'?> text-xl sm:text-3xl mb-1 sm:mb-2 text-slate-300"></i>
                    <p class="text-xs sm:text-sm"><?=empty($sel_type) ? 'Please select a data type to view plans' : 'No plans available for this category'?></p>
                </div>
                <?php endif; ?>
            </div>
            
            <div id="planDetail" class="hidden mt-3 sm:mt-4 p-3 sm:p-4 bg-brand-orangeLight border border-brand-orange/30 rounded-xl text-xs sm:text-sm font-semibold text-brand-orangeHover flex justify-between items-center">
                <div>
                    <span class="block text-[10px] sm:text-xs text-brand-orange/80 uppercase tracking-wider mb-0.5">Selected Plan</span>
                    <span class="text-base sm:text-lg font-bold" id="detailSize"></span>
                </div>
                <div class="text-right">
                    <span class="block text-[10px] sm:text-xs text-brand-orange/80 uppercase tracking-wider mb-0.5">Price</span>
                    <span class="text-base sm:text-lg font-bold" id="detailPrice"></span>
                </div>
            </div>
        </div>

        <input type="hidden" id="selectedPlan" value="">
        <input type="hidden" id="csrfF" value="<?=$csrf?>">
        
        <button type="button" onclick="openConfirm()" class="w-full bg-gradient-to-r from-brand-orange to-brand-orangeHover hover:from-brand-orangeHover hover:to-orange-600 text-white font-bold text-sm sm:text-base py-3 sm:py-3.5 rounded-xl shadow-lg shadow-orange-500/30 transition-all hover:-translate-y-0.5 flex items-center justify-center gap-2">
            Continue to Pay <i class="fas fa-arrow-right"></i>
        </button>
        
        <p class="text-center text-[10px] sm:text-xs text-slate-500 mt-3 sm:mt-4 flex items-center justify-center gap-1">
            <i class="fas fa-wifi text-emerald-500"></i> Data delivered instantly after payment
        </p>
    </div>
</div>

<!-- Scripts for UI logic -->
<script>

const netPrefixes = {
    "MTN": ["0803","0806","0814","0810","0813","0816","0703","0706","0903","0906","0913","0916"],
    "AIRTEL": ["0802","0808","0812","0701","0708","0902","0907","0901","0912","0904"],
    "GLO": ["0805","0807","0811","0705","0905","0915"],
    "9MOBILE": ["0809","0817","0818","0908","0909"]
};

function detectNetwork(phone) {
    if(phone.length >= 4) {
        let prefix = phone.substring(0, 4);
        for(let net in netPrefixes) {
            if(netPrefixes[net].includes(prefix)) return net;
        }
    }
    return null;
}

// Mutable client-side copy of the server-selected network. Starts as whatever
// $sel_net the page loaded with, but — unlike the PHP value — this can be
// cleared client-side when the phone number is erased, so a freshly typed
// number isn't stuck inheriting the previous number's network.
let effectiveNet = "<?=$sel_net?>";

function resetNetSelection() {
    effectiveNet = "";
    document.getElementById("networkSection").classList.add('hidden');
    document.getElementById("networkWarn").classList.add('hidden');
    document.querySelectorAll('.net-btn').forEach(a => {
        a.classList.remove('border-brand-orange', 'bg-brand-orangeLight');
        a.classList.add('border-slate-100', 'bg-slate-50');
        a.querySelector('.check-icon').classList.remove('!opacity-100');
    });
}

function handlePhoneInput() {
    let phone = document.getElementById("phoneF").value.replace(/[^0-9]/g, "");

    // Update all network links to preserve the typed phone number
    document.querySelectorAll('.net-btn').forEach(a => {
        let url = new URL(a.href, window.location.origin);
        url.searchParams.set('phone', phone);
        a.href = url.toString();
    });

    // Erased back below the detection threshold — drop whatever network was
    // selected (auto or manual) so the next number typed gets its own fresh
    // detection instead of inheriting the previous number's network.
    if (phone.length < 4) {
        resetNetSelection();
        return;
    }

    let detected = detectNetwork(phone);
    let warnEl = document.getElementById("networkWarn");
    let netSection = document.getElementById("networkSection");

    // No network locked in yet — reveal the picker only once enough digits
    // are typed to attempt detection, and live-preview the detected network
    // so it's obvious what's about to be auto-selected. The picker stays
    // fully clickable throughout, so a ported number can still be pointed at
    // a different network instead.
    if (!effectiveNet) {
        netSection.classList.remove('hidden');
        document.querySelectorAll('.net-btn').forEach(a => {
            const isMatch = detected && a.dataset.net === detected;
            a.classList.toggle('border-brand-orange', isMatch);
            a.classList.toggle('bg-brand-orangeLight', isMatch);
            a.classList.toggle('border-slate-100', !isMatch);
            a.classList.toggle('bg-slate-50', !isMatch);
            a.querySelector('.check-icon').classList.toggle('!opacity-100', isMatch);
        });
    }

    if (detected) {
        if (!effectiveNet) {
            warnEl.textContent = `Note: This looks like an ${detected} number. Auto-selecting...`;
            warnEl.classList.remove("hidden");
            warnEl.classList.replace("text-red-500", "text-brand-orange");

            if (phone.length >= 10) {
                window.location.href = '?net=' + detected + '&phone=' + phone + '&autofocus=1';
            }
        } else if (effectiveNet !== detected) {
            warnEl.textContent = "Note: This looks like an " + detected + " number.";
            warnEl.classList.replace("text-brand-orange", "text-red-500");
            warnEl.classList.remove("hidden");
        } else {
            warnEl.classList.add("hidden");
        }
    } else {
        warnEl.classList.add("hidden");
    }
}

document.getElementById("phoneF").addEventListener("input", handlePhoneInput);

// Restore focus if auto-selected, and reveal the network picker immediately
// if a phone number was already prefilled server-side (e.g. a saved number
// or ?phone=) without requiring the user to type anything first.
window.addEventListener('DOMContentLoaded', (event) => {
    if (document.getElementById('phoneF').value) {
        handlePhoneInput();
    }
    let urlParams = new URLSearchParams(window.location.search);
    if(urlParams.get('autofocus') == '1') {
        let phoneInput = document.getElementById('phoneF');
        phoneInput.focus();
        let val = phoneInput.value;
        phoneInput.value = '';
        phoneInput.value = val;
    }
});

function selectPlanCard(card) {
    document.querySelectorAll('.plan-card').forEach(c => {
        c.classList.remove('border-brand-orange', 'bg-brand-orangeLight', 'ring-2', 'ring-brand-orange/20');
        c.classList.add('border-slate-100', 'bg-slate-50');
        c.querySelector('.check-icon').classList.remove('opacity-100');
    });
    
    card.classList.remove('border-slate-100', 'bg-slate-50');
    card.classList.add('border-brand-orange', 'bg-brand-orangeLight', 'ring-2', 'ring-brand-orange/20');
    card.querySelector('.check-icon').classList.add('opacity-100');
    
    document.getElementById('selectedPlan').value = card.dataset.id;
    
    // Show detail strip
    const detail = document.getElementById('planDetail');
    document.getElementById('detailSize').textContent = card.dataset.size;
    document.getElementById('detailPrice').textContent = '₦' + parseInt(card.dataset.price).toLocaleString();
    detail.classList.remove('hidden');
}

function saveBenef() {
    let phone = document.getElementById('phoneF').value;
    if(!phone) return showAlert('Enter a phone number first', 'Error');
    let label = prompt("Enter a name for this number:");
    if(!label) return;
    
    let fd = new FormData();
    fd.append('action', 'save_benef');
    fd.append('service', 'data');
    fd.append('label', label);
    fd.append('value', phone);
    
    fetch('', {method: 'POST', headers: {'X-Requested-With':'XMLHttpRequest'}, body: fd})
    .then(r=>r.json())
    .then(res => {
        if(res.success) {
            showAlert('Number saved successfully!', 'Success');
            setTimeout(()=>location.reload(), 1500);
        } else showAlert(res.message, 'Error');
    });
}

function openConfirm() {
    let planId = document.getElementById('selectedPlan').value;
    let phone = document.getElementById('phoneF').value;
    
    if(!planId) return showAlert('Please select a data plan', 'Error', 'exclamation-triangle', 'text-amber-500');
    if(!phone || phone.length < 10) return showAlert('Please enter a valid phone number', 'Error', 'exclamation-triangle', 'text-amber-500');
    
    let pCard = document.querySelector('.plan-card[data-id="'+planId+'"]');
    let planSize = pCard.dataset.size;
    let amt = parseInt(pCard.dataset.price);
    let net = "<?=$sel_net?>";
    
    if(typeof confirmTransaction === 'function') {
        confirmTransaction({
            "Transaction": "Data Purchase",
            "Network": net,
            "Plan": planSize,
            "Phone": phone,
            "Amount": "₦" + amt.toLocaleString()
        }, function(pin) {
            processTx(pin);
        });
    } else {
        const pin = prompt(`Confirm Data Purchase:\nNetwork: ${net}\nPlan: ${planSize}\nPhone: ${phone}\nAmount: ₦${amt.toLocaleString()}\n\nEnter your 4-digit PIN:`);
        if(pin && pin.length >= 4) processTx(pin);
    }
}

function processTx(pin) {
    if(!pin || pin.length < 4) return showAlert('Enter 4-digit PIN', 'Error');
    
    let btn = document.querySelector('button[onclick="openConfirm()"]');
    let ogHtml = btn ? btn.innerHTML : '';
    if(btn) { btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...'; btn.disabled = true; }
    
    let planId = document.getElementById('selectedPlan').value;
    let phone = document.getElementById('phoneF').value;
    
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
            if (res.ref) { window.location.href = '/user/receipt.php?ref=' + res.ref; return; }
            showAlert(res.message || 'Data sent successfully!', 'Success', 'check-circle', 'text-emerald-500');
            setTimeout(()=>location.reload(), 1500);
        } else {
            showAlert(res.message || 'An error occurred', 'Error', 'exclamation-circle', 'text-red-500');
            if(btn) { btn.innerHTML = ogHtml; btn.disabled = false; }
        }
    })
    .catch(err => {
        showAlert('Network error occurred. Please try again.', 'Error');
        if(btn) { btn.innerHTML = ogHtml; btn.disabled = false; }
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
