<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/airtime_to_cash.php';
auth_user();
$uid=(int)$_SESSION['user_id'];
$user=get_user($uid);
if($_SERVER['REQUEST_METHOD']==='POST'&&is_ajax()){ 
    if(!verify_csrf($_POST['csrf_token']??'')) json_response(['success'=>false,'message'=>'Invalid token'],403); 
    if(!rate_limit('a2c_'.$uid,3,300)) json_response(['success'=>false,'message'=>'Too many requests. Wait 5 min.'],429); 
    json_response(submit_airtime_to_cash($uid,$_POST));
}
$csrf=csrf_token();
$svc=new AirtimeToCashService();
$rates=db()->prepare("SELECT * FROM service_pricing WHERE service='airtime_to_cash' AND is_active=1 ORDER BY network");
$rates->execute();
$rates=$rates->fetchAll();
$my_requests=$svc->get_user_requests($uid);

$nets=['MTN','GLO','AIRTEL','9MOBILE'];
$net_nums=[];
foreach($nets as $n) $net_nums[$n]=$svc->get_our_number($n);

$ncolors=[
    'MTN'=>['bg-yellow-400','text-slate-900'],
    'GLO'=>['bg-green-600','text-white'],
    'AIRTEL'=>['bg-red-600','text-white'],
    '9MOBILE'=>['bg-emerald-800','text-white']
];
$rates_map=array_column($rates,'discount_percent','network');

$page_title = 'Airtime to Cash'; 
include __DIR__ . '/includes/header.php'; 
include __DIR__ . '/includes/sidebar.php'; 
?>

<div class="max-w-3xl mx-auto">
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-4">
            <a href="/msjpay/user/dashboard" class="w-10 h-10 rounded-full bg-white border border-slate-200 flex items-center justify-center text-slate-600 hover:bg-slate-50 transition-colors">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-slate-800">Airtime to Cash</h1>
                <p class="text-sm text-slate-500">Convert your excess airtime to cash instantly</p>
            </div>
        </div>
        <div class="bg-brand-orangeLight text-brand-orangeHover px-4 py-2 rounded-xl font-bold border border-brand-orange/20 shadow-sm">
            ₦<?=number_format((float)$user['wallet_balance'],2)?>
        </div>
    </div>

    <!-- How it Works -->
    <div class="bg-blue-50 border border-blue-200 rounded-2xl p-5 mb-6 flex items-start gap-4">
        <div class="w-10 h-10 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center shrink-0">
            <i class="fas fa-info-circle text-xl"></i>
        </div>
        <div>
            <h3 class="font-bold text-blue-900 mb-2">How it Works</h3>
            <ol class="text-sm text-blue-800 space-y-1 ml-4 list-decimal">
                <li>Select your network and enter the airtime amount</li>
                <li>Send the airtime to the generated phone number</li>
                <li>Cash is credited to your wallet automatically via Autopilot</li>
            </ol>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
        <!-- Rates Panel -->
        <div class="md:col-span-1 space-y-6">
            <div class="bg-white rounded-2xl border border-slate-200 card-shadow overflow-hidden">
                <div class="bg-slate-50 border-b border-slate-100 px-5 py-4">
                    <h3 class="font-bold text-slate-800 flex items-center gap-2">
                        <i class="fas fa-percent text-brand-orange"></i> Current Rates
                    </h3>
                </div>
                <div class="divide-y divide-slate-100">
                    <?php foreach($rates as $r):?>
                    <div class="flex justify-between items-center p-4">
                        <div class="flex items-center gap-3">
                            <img src="/msjpay/assets/logos/<?=strtolower($r['network'])?>.png" alt="<?=$r['network']?>" class="w-8 h-8 rounded-full object-cover border border-slate-200" onerror="this.style.display='none'">
                            <span class="font-semibold text-slate-700"><?=$r['network']?></span>
                        </div>
                        <div class="font-bold text-brand-orangeHover bg-brand-orangeLight px-3 py-1 rounded-full text-sm border border-brand-orange/20">
                            <?=$r['discount_percent']?>%
                        </div>
                    </div>
                    <?php endforeach;?>
                </div>
            </div>
        </div>

        <!-- Conversion Form -->
        <div class="md:col-span-2">
            <div class="bg-white rounded-2xl border border-slate-200 card-shadow p-6">
                <!-- Network Selection -->
                <div class="mb-6">
                    <label class="block text-sm font-semibold text-slate-700 mb-3">Select Network</label>
                    <div class="grid grid-cols-4 gap-3">
                        <?php foreach($ncolors as $n=>[$bg,$c]):?>
                        <button type="button" class="net-btn flex flex-col items-center justify-center p-3 rounded-xl border-2 border-slate-100 bg-slate-50 hover:border-brand-orange/50 transition-all relative overflow-hidden text-center group" data-net="<?=$n?>" data-rate="<?=$rates_map[$n]??0?>" onclick="selectNet(this,'<?=$n?>')">
                            <div class="w-12 h-12 rounded-full mb-2 overflow-hidden bg-white shadow-sm flex items-center justify-center <?=$bg?> <?=$c?> font-bold">
                                <img src="/msjpay/assets/logos/<?=strtolower($n)?>.png" alt="<?=$n?>" class="w-full h-full object-cover" onerror="this.parentNode.style.color='<?=$c?>';this.parentNode.textContent='<?=substr($n,0,1)?>'">
                            </div>
                            <span class="text-xs font-semibold text-slate-700"><?=$n?></span>
                            <div class="absolute top-2 right-2 text-brand-orange opacity-0 check-icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                        </button>
                        <?php endforeach;?>
                    </div>
                </div>

                <!-- Amount -->
                <div class="mb-6">
                    <label class="block text-sm font-semibold text-slate-700 mb-2">Airtime Amount (₦)</label>
                    
                    <div class="flex flex-wrap gap-2 mb-3">
                        <?php foreach([500,1000,2000,5000,10000] as $q):?>
                        <button type="button" class="amt-chip px-4 py-2 bg-slate-100 hover:bg-brand-orange hover:text-white rounded-lg text-sm font-semibold text-slate-700 transition-colors border border-slate-200" onclick="setAmt(<?=$q?>,this)">₦<?=number_format($q)?></button>
                        <?php endforeach;?>
                    </div>
                    
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <span class="text-slate-500 font-bold">₦</span>
                        </div>
                        <input type="text" id="amtF" class="block w-full pl-10 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-bold text-xl" placeholder="Min 500" min="500" inputmode="decimal" oninput="calcCash()">
                    </div>
                    
                    <div id="cashPreview" class="hidden mt-4 bg-brand-orangeLight border border-brand-orange/30 rounded-xl p-5 text-center transition-all">
                        <div class="text-xs font-bold text-brand-orange/80 uppercase tracking-wider mb-1">You will receive</div>
                        <div class="text-3xl font-black text-brand-orangeHover my-2" id="cashAmt"></div>
                        <div class="text-sm font-medium text-slate-500" id="cashNote"></div>
                    </div>
                </div>

                <!-- Sender Phone -->
                <div class="mb-6">
                    <label class="block text-sm font-semibold text-slate-700 mb-2">Your Phone Number (sender)</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i class="fas fa-phone text-slate-400"></i>
                        </div>
                        <input type="tel" id="senderF" class="block w-full pl-11 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium text-lg tracking-wide" placeholder="<?=htmlspecialchars($user['phone'])?>" maxlength="11" value="<?=htmlspecialchars($user['phone'])?>">
                    </div>
                    <p class="text-xs text-slate-500 mt-2"><i class="fas fa-info-circle"></i> The number you are transferring the airtime from</p>
                </div>

                <!-- Destination Number Display -->
                <div id="ourNumCard" class="hidden mb-6 border-2 border-dashed border-brand-orange bg-brand-orangeLight/50 rounded-xl p-6 text-center transition-all">
                    <div class="text-xs font-bold text-brand-orange/80 uppercase tracking-wider mb-2">Send Airtime To This Number</div>
                    <div class="text-3xl font-black tracking-wider text-slate-800 my-2" id="ourNum"></div>
                    <div class="text-sm font-medium text-slate-500 mb-4" id="ourNetLabel"></div>
                    <button type="button" onclick="copyOurNum()" class="bg-brand-orange hover:bg-brand-orangeHover text-white px-6 py-2.5 rounded-xl font-bold transition-colors shadow-sm inline-flex items-center gap-2">
                        <i class="fas fa-copy"></i> Copy Number
                    </button>
                </div>

                <input type="hidden" id="selNet" value="">
                <input type="hidden" id="selRate" value="0">
                <input type="hidden" id="csrfF" value="<?=$csrf?>">
                
                <button type="button" onclick="submitA2C()" class="w-full bg-gradient-to-r from-brand-orange to-brand-orangeHover hover:from-brand-orangeHover hover:to-orange-600 text-white font-bold text-lg py-4 rounded-xl shadow-lg shadow-orange-500/30 transition-all hover:-translate-y-0.5 flex items-center justify-center gap-2">
                    Submit Request <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Recent Requests -->
    <?php if(!empty($my_requests)):?>
    <div class="bg-white rounded-2xl border border-slate-200 card-shadow overflow-hidden mb-6">
        <div class="bg-slate-50 border-b border-slate-100 px-5 py-4 flex justify-between items-center">
            <h3 class="font-bold text-slate-800">Recent Requests</h3>
        </div>
        <div class="divide-y divide-slate-100">
            <?php foreach($my_requests as $r): 
                $scls = '';
                $stxt = ucfirst($r['status']);
                switch($r['status']) {
                    case 'success': $scls = 'text-emerald-600 bg-emerald-100'; break;
                    case 'pending': $scls = 'text-amber-600 bg-amber-100'; break;
                    case 'processing': $scls = 'text-blue-600 bg-blue-100'; break;
                    case 'failed': $scls = 'text-rose-600 bg-rose-100'; break;
                    default: $scls = 'text-slate-600 bg-slate-100';
                }
            ?>
            <div class="flex items-center p-4 hover:bg-slate-50 transition-colors">
                <div class="w-12 h-12 rounded-xl bg-slate-100 flex items-center justify-center flex-shrink-0 border border-slate-200 overflow-hidden p-1">
                    <img src="/msjpay/assets/logos/<?=strtolower($r['network'])?>.png" alt="<?=$r['network']?>" class="w-full h-full object-contain" onerror="this.style.display='none';this.parentNode.innerHTML='<span class=\\'font-bold text-slate-500\\'><?=substr($r['network'],0,1)?></span>'">
                </div>
                <div class="ml-4 flex-1">
                    <p class="text-sm font-semibold text-slate-800">
                        Airtime: ₦<?=number_format((float)$r['airtime_amount'],0)?> <i class="fas fa-arrow-right text-slate-400 text-xs mx-1"></i> Cash: <span class="text-brand-orange">₦<?=number_format((float)$r['cash_amount'],0)?></span>
                    </p>
                    <p class="text-xs text-slate-500 mt-1"><?=date('d M Y, h:i A',strtotime($r['created_at']))?></p>
                </div>
                <div class="ml-4 text-right">
                    <span class="inline-block px-2.5 py-1 rounded-full text-xs font-bold uppercase <?= $scls ?>"><?= $stxt ?></span>
                </div>
            </div>
            <?php endforeach;?>
        </div>
    </div>
    <?php endif;?>
</div>

<script>
const ourNums = <?=json_encode($net_nums)?>;

function selectNet(btn, net) {
    document.querySelectorAll('.net-btn').forEach(b => {
        b.classList.remove('border-brand-orange', 'bg-brand-orangeLight');
        b.classList.add('border-slate-100', 'bg-slate-50');
        b.querySelector('.check-icon').classList.remove('!opacity-100');
    });
    btn.classList.remove('border-slate-100', 'bg-slate-50');
    btn.classList.add('border-brand-orange', 'bg-brand-orangeLight');
    btn.querySelector('.check-icon').classList.add('!opacity-100');
    
    document.getElementById('selNet').value = net;
    document.getElementById('selRate').value = btn.getAttribute('data-rate');
    
    if(ourNums[net]) {
        document.getElementById('ourNumCard').classList.remove('hidden');
        document.getElementById('ourNum').innerText = ourNums[net];
        document.getElementById('ourNetLabel').innerText = `${net} Receiver Number`;
    } else {
        document.getElementById('ourNumCard').classList.add('hidden');
    }
    calcCash();
}

function setAmt(amt, btn) {
    document.getElementById('amtF').value = amt.toLocaleString('en-US');
    document.querySelectorAll('.amt-chip').forEach(b => {
        b.classList.remove('bg-brand-orange', 'text-white');
        b.classList.add('bg-slate-100', 'text-slate-700');
    });
    btn.classList.remove('bg-slate-100', 'text-slate-700');
    btn.classList.add('bg-brand-orange', 'text-white');
    calcCash();
}

function calcCash() {
    let val = document.getElementById('amtF').value.replace(/[^0-9]/g, '');
    let amt = val ? parseFloat(val) : 0;
    
    if(val) {
        document.getElementById('amtF').value = amt.toLocaleString('en-US');
    }
    
    let rate = parseFloat(document.getElementById('selRate').value) || 0;
    let prv = document.getElementById('cashPreview');
    
    if(amt >= 500 && rate > 0) {
        let cash = amt * (rate / 100);
        document.getElementById('cashAmt').innerText = '₦' + cash.toLocaleString('en-US');
        document.getElementById('cashNote').innerText = `Based on ${rate}% conversion rate`;
        prv.classList.remove('hidden');
    } else {
        prv.classList.add('hidden');
    }
}

function copyOurNum() {
    const num = document.getElementById('ourNum').innerText;
    navigator.clipboard.writeText(num).then(() => {
        alert('Receiver number copied to clipboard: ' + num);
    });
}

function submitA2C() {
    const net = document.getElementById('selNet').value;
    const sender = document.getElementById('senderF').value;
    const amtStr = document.getElementById('amtF').value.replace(/,/g, '');
    const amt = parseFloat(amtStr);
    
    if(!net) return alert('Select network');
    if(isNaN(amt) || amt < 500) return alert('Minimum amount is ₦500');
    if(!sender || sender.length < 10) return alert('Enter a valid sender phone number');
    
    const cash = amt * (parseFloat(document.getElementById('selRate').value) / 100);
    
    if(confirm(`Submit Airtime to Cash Request?\n\nNetwork: ${net}\nSending From: ${sender}\nAirtime Value: ₦${amt}\nCash You Will Receive: ₦${cash}\n\nMake sure you have already sent the airtime to our number!`)) {
        processA2C(net, sender, amt);
    }
}

function processA2C(net, sender, amt) {
    const btn = document.querySelector('button[onclick="submitA2C()"]');
    const ogHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    btn.disabled = true;
    
    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('csrfF').value);
    fd.append('network', net);
    fd.append('sender_phone', sender);
    fd.append('airtime_amount', amt);
    
    fetch('', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if(res.success) {
            alert(`Request Submitted!\n\n${res.message}`);
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