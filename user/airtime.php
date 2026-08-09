<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/airtime.php';
require_once __DIR__.'/core/beneficiaries.php';
auth_user();
$uid=(int)$_SESSION['user_id'];$user=get_user($uid);
if($_SERVER['REQUEST_METHOD']==='POST'&&is_ajax()){
    if(!verify_csrf($_POST['csrf_token']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    if(!rate_limit('airtime_'.$uid,5,60))json_response(['success'=>false,'message'=>'Too many requests. Wait 1 minute.'],429);
    json_response(process_airtime($uid,$_POST));
}
$csrf=csrf_token();$user_benefs=get_beneficiaries($uid,'airtime');
$benef_id=(int)($_GET['benef']??0);$prefill_phone='';$prefill_net='';
if($benef_id){
    $b=array_filter($user_benefs,fn($x)=>$x['id']===$benef_id);
    if($b){
        $b=array_values($b)[0];
        $prefill_phone=$b['value'];
        $prefill_net=strtoupper($b['network']??'');
    }
}
$nets=[
    'MTN'=>['bg-yellow-400','text-slate-900'],
    'GLO'=>['bg-green-600','text-white'],
    'AIRTEL'=>['bg-red-600','text-white'],
    '9MOBILE'=>['bg-emerald-800','text-white']
];
$logo_map=[
    'MTN'=>'data:image/jpeg;base64,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',
    'GLO'=>'data:image/jpeg;base64,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',
    'AIRTEL'=>'data:image/jpeg;base64,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',
    '9MOBILE'=>'data:image/jpeg;base64,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'
];
$page_title = 'Buy Airtime'; 
include __DIR__ . '/includes/header.php'; 
include __DIR__ . '/includes/sidebar.php'; 
?>

<div class="max-w-2xl mx-auto">
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-4">
            <a href="dashboard.php" class="w-10 h-10 rounded-full bg-white border border-slate-200 flex items-center justify-center text-slate-600 hover:bg-slate-50 transition-colors">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-slate-800">Buy Airtime</h1>
                <p class="text-sm text-slate-500">Instant recharge for all networks</p>
            </div>
        </div>
        <div class="bg-brand-orangeLight text-brand-orangeHover px-4 py-2 rounded-xl font-bold border border-brand-orange/20 shadow-sm">
            ₦<?=number_format((float)$user['wallet_balance'],2)?>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 card-shadow p-6 mb-6">
        
        <?php if(!empty($user_benefs)):?>
        <div class="mb-6">
            <label class="block text-sm font-semibold text-slate-700 mb-3">Saved Numbers</label>
            <div class="flex gap-3 overflow-x-auto pb-2 custom-scrollbar">
                <?php foreach($user_benefs as $b):?>
                <button type="button" class="flex-shrink-0 flex items-center gap-3 p-3 rounded-xl border border-slate-200 hover:border-brand-orange hover:bg-brand-orangeLight transition-all text-left group min-w-[160px]" onclick="useBenef('<?=htmlspecialchars($b['value'])?>','<?=htmlspecialchars(strtoupper($b['network']??''))?>','<?=$b['id']?>')">
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

        <!-- Phone Number -->
        <div class="mb-6">
            <label class="block text-sm font-semibold text-slate-700 mb-2">Phone Number</label>
            <div class="flex gap-2">
                <div class="relative flex-1">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <i class="fas fa-phone text-slate-400"></i>
                    </div>
                    <input type="tel" id="phoneF" class="block w-full pl-11 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium text-lg tracking-wide" placeholder="08012345678" maxlength="11" inputmode="numeric" value="<?=$prefill_phone?>">
                </div>
                <button type="button" onclick="saveBenef()" class="bg-brand-navy hover:bg-brand-navyLight text-white px-5 rounded-xl font-medium transition-colors shadow-sm whitespace-nowrap flex items-center gap-2">
                    <i class="fas fa-bookmark"></i> <span class="hidden sm:inline">Save</span>
                </button>
            </div>
        </div>

        <!-- Network Selection — hidden until a phone number is typed; auto-highlights
             the detected network, but stays fully clickable so a ported number can
             be pointed at a different network. -->
        <div id="networkSection" class="mb-6 <?= empty($prefill_net) ? 'hidden' : '' ?>">
            <label class="block text-sm font-semibold text-slate-700 mb-3">Select Network</label>
            <div class="grid grid-cols-4 gap-3">
                <?php foreach($nets as $name=>[$bg,$color]):?>
                <button type="button" class="net-btn flex flex-col items-center justify-center p-3 rounded-xl border-2 <?= $prefill_net===$name?'border-brand-orange bg-brand-orangeLight':'border-slate-100 bg-slate-50 hover:border-brand-orange/50' ?> transition-all relative overflow-hidden" onclick="selectNet(this,'<?=$name?>',true)" data-net="<?=$name?>">
                    <div class="w-12 h-12 rounded-full mb-2 overflow-hidden bg-white shadow-sm flex items-center justify-center <?= $bg ?> <?= $color ?> font-bold">
                        <?php $logo_url = "https://api.msjpay.ng/assets/logos/" . strtolower($name) . ".png"; ?>
                        <img src="<?=$logo_url?>" alt="<?=$name?>" class="w-full h-full object-cover" onerror="this.style.display='none';this.parentNode.innerHTML='<?=substr($name,0,1)?>'">
                    </div>
                    <span class="text-xs font-semibold text-slate-700"><?=$name?></span>
                    <div class="absolute top-2 right-2 text-brand-orange opacity-0 check-icon <?= $prefill_net===$name?'!opacity-100':'' ?>">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </button>
                <?php endforeach;?>
            </div>
        </div>

        <p id="networkWarn" class="text-red-500 text-xs font-semibold mt-1.5 mb-6 hidden"></p>

        <!-- Amount -->
        <div class="mb-6">
            <div class="flex justify-between items-end mb-2">
                <label class="block text-sm font-semibold text-slate-700">Amount (₦)</label>
            </div>
            
            <div class="flex flex-wrap gap-2 mb-3">
                <?php foreach([50,100,200,500,1000,2000] as $q):?>
                <button type="button" class="amt-chip px-4 py-2 bg-slate-100 hover:bg-brand-orange hover:text-white rounded-lg text-sm font-semibold text-slate-700 transition-colors border border-slate-200" onclick="setAmt(<?=$q?>,this)">₦<?=$q?></button>
                <?php endforeach;?>
            </div>
            
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                    <span class="text-slate-500 font-bold">₦</span>
                </div>
                <input type="text" id="amtF" class="block w-full pl-10 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-bold text-xl" placeholder="50 - 100,000" min="50" inputmode="decimal">
            </div>
        </div>

        <input type="hidden" id="netF" value="<?=$prefill_net?>">
        <input type="hidden" id="csrfF" value="<?=$csrf?>">
        
        <button type="button" onclick="openConfirm()" class="w-full bg-gradient-to-r from-brand-orange to-brand-orangeHover hover:from-brand-orangeHover hover:to-orange-600 text-white font-bold text-lg py-4 rounded-xl shadow-lg shadow-orange-500/30 transition-all hover:-translate-y-0.5 flex items-center justify-center gap-2">
            Continue to Pay <i class="fas fa-arrow-right"></i>
        </button>
        
        <p class="text-center text-xs text-slate-500 mt-4 flex items-center justify-center gap-1">
            <i class="fas fa-bolt text-yellow-500"></i> Airtime delivered instantly
        </p>
    </div>
</div>

<!-- Scripts for UI logic -->
<script>
let manualNetSelection = <?= !empty($prefill_net) ? 'true' : 'false' ?>;

function selectNet(btn, net, isManual = false) {
    document.getElementById('networkSection').classList.remove('hidden');
    document.querySelectorAll('.net-btn').forEach(b => {
        b.classList.remove('border-brand-orange', 'bg-brand-orangeLight');
        b.classList.add('border-slate-100', 'bg-slate-50');
        b.querySelector('.check-icon').classList.remove('!opacity-100');
    });
    btn.classList.remove('border-slate-100', 'bg-slate-50');
    btn.classList.add('border-brand-orange', 'bg-brand-orangeLight');
    btn.querySelector('.check-icon').classList.add('!opacity-100');
    document.getElementById('netF').value = net;
    if (isManual) manualNetSelection = true;
}

function useBenef(phone, net, id) {
    document.getElementById('phoneF').value = phone;
    const btn = document.querySelector(`.net-btn[data-net="${net}"]`);
    if(btn) selectNet(btn, net, true);
}

function setAmt(amt, btn) {
    document.getElementById('amtF').value = amt;
    document.querySelectorAll('.amt-chip').forEach(b => {
        b.classList.remove('bg-brand-orange', 'text-white');
        b.classList.add('bg-slate-100', 'text-slate-700');
    });
    btn.classList.remove('bg-slate-100', 'text-slate-700');
    btn.classList.add('bg-brand-orange', 'text-white');
}

// Format amount input
document.getElementById('amtF').addEventListener('input', function(e) {
    let val = this.value.replace(/[^0-9]/g, '');
    if(val) {
        this.value = parseInt(val).toLocaleString('en-US');
    }
});

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

function resetNetSelection() {
    manualNetSelection = false;
    document.getElementById('netF').value = '';
    document.querySelectorAll('.net-btn').forEach(b => {
        b.classList.remove('border-brand-orange', 'bg-brand-orangeLight');
        b.classList.add('border-slate-100', 'bg-slate-50');
        b.querySelector('.check-icon').classList.remove('!opacity-100');
    });
    document.getElementById('networkSection').classList.add('hidden');
    document.getElementById('networkWarn').classList.add('hidden');
}

function handlePhoneInput() {
    let phone = document.getElementById('phoneF').value.replace(/[^0-9]/g, "");

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
    let currentNet = document.getElementById('netF').value;

    if (!manualNetSelection) {
        netSection.classList.remove('hidden');
    }

    if (detected) {
        if (!currentNet || !manualNetSelection) {
            // No manual choice yet (or a prior auto-pick) — safe to auto-select.
            let btn = document.querySelector(`.net-btn[data-net="${detected}"]`);
            if (btn) selectNet(btn, detected);
            warnEl.classList.add("hidden");
        } else if (currentNet !== detected) {
            // A manually/beneficiary-selected network differs from the detected one
            // (e.g. a ported number) — warn, but don't override the user's choice.
            warnEl.textContent = `Note: This looks like an ${detected} number, but ${currentNet} is selected.`;
            warnEl.classList.replace("text-brand-orange", "text-red-500");
            warnEl.classList.remove("hidden");
        } else {
            warnEl.classList.add("hidden");
        }
    } else {
        warnEl.classList.add("hidden");
    }
}
document.getElementById('phoneF').addEventListener('input', handlePhoneInput);
window.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('phoneF').value) { handlePhoneInput(); }
});

function openConfirm() {
    const net = document.getElementById('netF').value;
    const phone = document.getElementById('phoneF').value;
    const amtStr = document.getElementById('amtF').value.replace(/,/g, '');
    const amt = parseFloat(amtStr);
    
    if(!net) return typeof Swal !== 'undefined' ? Swal.fire('Notice', 'Please select a network', 'info') : alert('Please select a network');
    if(!phone || phone.length < 10) return typeof Swal !== 'undefined' ? Swal.fire('Notice', 'Please enter a valid phone number', 'info') : alert('Please enter a valid phone number');
    if(isNaN(amt) || amt < 50) return typeof Swal !== 'undefined' ? Swal.fire('Notice', 'Minimum amount is ₦50', 'info') : alert('Minimum amount is ₦50');
    
    if(typeof confirmTransaction === 'function') {
        confirmTransaction({
            "Transaction": "Airtime Purchase",
            "Network": net,
            "Phone": phone,
            "Amount": "₦" + amt
        }, function(pin) {
            processPurchase(net, phone, amt, pin);
        });
    } else {
        const pin = prompt(`Confirm Airtime Purchase:\nNetwork: ${net}\nPhone: ${phone}\nAmount: ₦${amt}\n\nEnter your 4-digit PIN:`);
        if(pin && pin.length >= 4) processPurchase(net, phone, amt, pin);
    }
}

function processPurchase(net, phone, amt, pin) {
    const btn = document.querySelector('button[onclick="openConfirm()"]');
    const ogHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    btn.disabled = true;
    
    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('csrfF').value);
    fd.append('network', net);
    fd.append('phone', phone);
    fd.append('amount', amt);
    fd.append('pin', pin);
    
    fetch('', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if(res.ref) {
            window.location.href = '/user/receipt.php?ref=' + res.ref;
            return;
        }
        if(res.success) {
            alert(`Success! Airtime Sent.\n${res.message}`);
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

function saveBenef() {
    alert("Bookmark feature works similarly. (Requires backend endpoint).");
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>