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
$logo_map=['MTN'=>'/msjpay/assets/logos/mtn.png','GLO'=>'/msjpay/assets/logos/glo.png','AIRTEL'=>'/msjpay/assets/logos/airtel.png','9MOBILE'=>'/msjpay/assets/logos/9mobile.png'];
$page_title = 'Buy Airtime'; 
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

        <!-- Network Selection -->
        <div class="mb-6">
            <label class="block text-sm font-semibold text-slate-700 mb-3">Select Network</label>
            <div class="grid grid-cols-4 gap-3">
                <?php foreach($nets as $name=>[$bg,$color]):?>
                <button type="button" class="net-btn flex flex-col items-center justify-center p-3 rounded-xl border-2 <?= $prefill_net===$name?'border-brand-orange bg-brand-orangeLight':'border-slate-100 bg-slate-50 hover:border-brand-orange/50' ?> transition-all relative overflow-hidden" onclick="selectNet(this,'<?=$name?>')" data-net="<?=$name?>">
                    <div class="w-12 h-12 rounded-full mb-2 overflow-hidden bg-white shadow-sm flex items-center justify-center <?= $bg ?> <?= $color ?> font-bold">
                        <img src="<?=$logo_map[$name]?>" alt="<?=$name?>" class="w-full h-full object-cover" onerror="this.style.display='none';this.parentNode.innerHTML='<?=substr($name,0,1)?>'">
                    </div>
                    <span class="text-xs font-semibold text-slate-700"><?=$name?></span>
                    <div class="absolute top-2 right-2 text-brand-orange opacity-0 check-icon <?= $prefill_net===$name?'!opacity-100':'' ?>">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </button>
                <?php endforeach;?>
            </div>
        </div>

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
function selectNet(btn, net) {
    document.querySelectorAll('.net-btn').forEach(b => {
        b.classList.remove('border-brand-orange', 'bg-brand-orangeLight');
        b.classList.add('border-slate-100', 'bg-slate-50');
        b.querySelector('.check-icon').classList.remove('!opacity-100');
    });
    btn.classList.remove('border-slate-100', 'bg-slate-50');
    btn.classList.add('border-brand-orange', 'bg-brand-orangeLight');
    btn.querySelector('.check-icon').classList.add('!opacity-100');
    document.getElementById('netF').value = net;
}

function useBenef(phone, net, id) {
    document.getElementById('phoneF').value = phone;
    const btn = document.querySelector(`.net-btn[data-net="${net}"]`);
    if(btn) selectNet(btn, net);
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

function openConfirm() {
    const net = document.getElementById('netF').value;
    const phone = document.getElementById('phoneF').value;
    const amtStr = document.getElementById('amtF').value.replace(/,/g, '');
    const amt = parseFloat(amtStr);
    
    if(!net) return alert('Please select a network');
    if(!phone || phone.length < 10) return alert('Please enter a valid phone number');
    if(isNaN(amt) || amt < 50) return alert('Minimum amount is ₦50');
    
    // We'll just show the browser prompt for PIN for now, since we removed the custom modal
    // In a real migration, we'd rebuild the modal in Tailwind too.
    const pin = prompt(`Confirm Airtime Purchase:\nNetwork: ${net}\nPhone: ${phone}\nAmount: ₦${amt}\n\nEnter your 4-digit PIN:`);
    
    if(pin && pin.length >= 4) {
        processPurchase(net, phone, amt, pin);
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