<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/airtime_to_cash.php';
auth_user();
$uid=(int)$_SESSION['user_id'];
$user=get_user($uid);
if($_SERVER['REQUEST_METHOD']==='POST'&&is_ajax()){ 
    if(!verify_csrf($_POST['csrf_token']??'')) json_response(['success'=>false,'message'=>'Invalid token'],403); 
    $action=$_POST['action']??'submit';
    try {
        if($action==='complete_otp'){
            if(!rate_limit('a2c_otp_'.$uid,5,120)) json_response(['success'=>false,'message'=>'Too many attempts. Wait 2 min.'],429);
            json_response(complete_a2c_with_otp($_POST['ref']??'',$_POST['otp']??'',$uid));
        }elseif($action==='resend_otp'){
            if(!rate_limit('a2c_otp_'.$uid,3,120)) json_response(['success'=>false,'message'=>'Too many attempts. Wait 2 min.'],429);
            json_response(resend_a2c_otp($_POST['ref']??'',$uid));
        }else{
            if(!rate_limit('a2c_'.$uid,3,300)) json_response(['success'=>false,'message'=>'Too many requests. Wait 5 min.'],429);
            json_response(submit_airtime_to_cash($uid,$_POST));
        }
    }
    catch(Throwable $e){ log_write('transactions','A2C_ERR',$e->getMessage()); json_response(['success'=>false,'message'=>'Server error: '.$e->getMessage()],500); }
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
$unique_rates=[];
foreach($rates as $r) $unique_rates[$r['network']]=$r;
$rates=array_values($unique_rates);
$rates_map=array_column($rates,'discount_percent','network');
$auto_provs=[];$aq=db()->prepare("SELECT network FROM api_providers WHERE service='airtime_to_cash' AND is_active=1");$aq->execute();
while($ar=$aq->fetch())$auto_provs[$ar['network']]=true;

$page_title = 'Airtime to Cash'; 
include __DIR__ . '/includes/header.php'; 
include __DIR__ . '/includes/sidebar.php'; 
?>

<div class="max-w-3xl mx-auto">
    <!-- Header -->
    <div class="flex items-center justify-between mb-4 sm:mb-6">
        <div class="flex items-center gap-4">
            <a href="/user/dashboard" class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-white border border-slate-200 flex items-center justify-center text-slate-600 hover:bg-slate-50 transition-colors">
                <i class="fas fa-arrow-left text-xs sm:text-sm"></i>
            </a>
            <div>
                <h1 class="text-lg sm:text-xl font-bold text-slate-800">Airtime to Cash</h1>
                <p class="text-xs sm:text-sm text-slate-500">Convert your excess airtime to cash instantly</p>
            </div>
        </div>
        <div class="bg-brand-orangeLight text-brand-orangeHover px-2.5 sm:px-4 py-1.5 sm:py-2 rounded-xl font-bold border border-brand-orange/20 shadow-sm text-sm">
            ₦<?=number_format((float)$user['wallet_balance'],2)?>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 sm:gap-6 mb-4 sm:mb-6">
        <!-- Rates Panel -->
        <div class="md:col-span-1 space-y-4 sm:space-y-6">
            <div class="bg-white rounded-xl sm:rounded-2xl border border-slate-200 card-shadow overflow-hidden">
                <div class="bg-slate-50 border-b border-slate-100 px-4 sm:px-5 py-3 sm:py-4">
                    <h3 class="font-bold text-slate-800 text-sm sm:text-base flex items-center gap-2">
                        <i class="fas fa-percent text-brand-orange text-xs sm:text-sm"></i> Current Rates
                    </h3>
                </div>
                <div class="divide-y divide-slate-100">
                    <?php foreach($rates as $r):?>
                    <div class="flex justify-between items-center p-3 sm:p-4">
                        <div class="flex items-center gap-2 sm:gap-3">
                            <img src="/assets/logos/<?=strtolower($r['network'])?>.png" alt="<?=$r['network']?>" class="w-7 h-7 sm:w-8 sm:h-8 rounded-full object-cover border border-slate-200" onerror="this.style.display='none'">
                            <span class="text-xs sm:text-sm font-semibold text-slate-700"><?=$r['network']?></span>
                        </div>
                        <div class="font-bold text-brand-orangeHover bg-brand-orangeLight px-2 sm:px-3 py-0.5 sm:py-1 rounded-full text-xs sm:text-sm border border-brand-orange/20">
                            <?=$r['discount_percent']?>%
                        </div>
                    </div>
                    <?php endforeach;?>
                </div>
            </div>
        </div>

        <!-- Conversion Form -->
        <div class="md:col-span-2">
            <div class="bg-white rounded-xl sm:rounded-2xl border border-slate-200 card-shadow p-4 sm:p-5">
                <!-- Network Selection -->
                <div class="mb-4 sm:mb-5">
                    <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-2 sm:mb-3">Select Network</label>
                    <div class="grid grid-cols-4 gap-2 sm:gap-3">
                        <?php foreach($ncolors as $n=>[$bg,$c]):?>
                        <button type="button" class="net-btn flex flex-col items-center justify-center p-2 sm:p-3 rounded-xl border-2 border-slate-100 bg-slate-50 hover:border-brand-orange/50 transition-all relative overflow-hidden text-center group" data-net="<?=$n?>" data-rate="<?=$rates_map[$n]??0?>" onclick="selectNet(this,'<?=$n?>')">
                            <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-full mb-1 sm:mb-2 overflow-hidden bg-white shadow-sm flex items-center justify-center <?=$bg?> <?=$c?> font-bold text-xs sm:text-base">
                                <img src="/assets/logos/<?=strtolower($n)?>.png" alt="<?=$n?>" class="w-full h-full object-cover" onerror="this.parentNode.style.color='<?=$c?>';this.parentNode.textContent='<?=substr($n,0,1)?>'">
                            </div>
                            <span class="text-[10px] sm:text-xs font-semibold text-slate-700"><?=$n?></span>
                            <div class="absolute top-1 sm:top-2 right-1 sm:right-2 text-brand-orange opacity-0 check-icon">
                                <i class="fas fa-check-circle text-[10px] sm:text-sm"></i>
                            </div>
                        </button>
                        <?php endforeach;?>
                    </div>
                </div>

                <!-- Amount -->
                <div class="mb-4 sm:mb-5">
                    <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5 sm:mb-2">Airtime Amount (₦)</label>
                    
                    <div class="flex flex-wrap gap-1.5 sm:gap-2 mb-2 sm:mb-3">
                        <?php foreach([500,1000,2000,5000,10000] as $q):?>
                        <button type="button" class="amt-chip px-2.5 sm:px-4 py-1.5 sm:py-2 bg-slate-100 hover:bg-brand-orange hover:text-white rounded-lg text-[10px] sm:text-sm font-semibold text-slate-700 transition-colors border border-slate-200" onclick="setAmt(<?=$q?>,this)">₦<?=number_format($q)?></button>
                        <?php endforeach;?>
                    </div>
                    
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 sm:pl-4 flex items-center pointer-events-none">
                            <span class="text-slate-500 font-bold text-sm">₦</span>
                        </div>
                        <input type="text" id="amtF" class="block w-full pl-8 sm:pl-10 pr-3 sm:pr-4 py-2.5 sm:py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-bold text-base sm:text-lg" placeholder="Min 500" min="500" inputmode="decimal" oninput="calcCash()">
                    </div>
                    
                    <div id="cashPreview" class="hidden mt-3 sm:mt-4 bg-brand-orangeLight border border-brand-orange/30 rounded-xl p-3 sm:p-4 text-center transition-all">
                        <div class="text-[10px] sm:text-xs font-bold text-brand-orange/80 uppercase tracking-wider mb-0.5 sm:mb-1">You will receive</div>
                        <div class="text-xl sm:text-2xl font-black text-brand-orangeHover my-1 sm:my-2" id="cashAmt"></div>
                        <div class="text-xs sm:text-sm font-medium text-slate-500" id="cashNote"></div>
                    </div>
                </div>

                <!-- Sender Phone -->
                <div class="mb-4 sm:mb-5">
                    <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5 sm:mb-2">Your Phone Number (sender)</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 sm:pl-4 flex items-center pointer-events-none">
                            <i class="fas fa-phone text-slate-400 text-xs"></i>
                        </div>
                        <input type="tel" id="senderF" class="block w-full pl-9 sm:pl-11 pr-3 sm:pr-4 py-2.5 sm:py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium text-sm sm:text-base" placeholder="<?=htmlspecialchars($user['phone'])?>" maxlength="11" value="<?=htmlspecialchars($user['phone'])?>">
                    </div>
                    <p class="text-[10px] sm:text-xs text-slate-500 mt-1.5 sm:mt-2"><i class="fas fa-info-circle"></i> The number you are transferring the airtime from</p>
                </div>

                <!-- Share PIN (for autosyncng) -->
                <div id="pinSection" class="mb-4 sm:mb-5 hidden">
                    <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5 sm:mb-2">Airtime Share PIN</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 sm:pl-4 flex items-center pointer-events-none">
                            <i class="fas fa-key text-slate-400 text-xs"></i>
                        </div>
                        <input type="password" id="pinF" class="block w-full pl-9 sm:pl-11 pr-3 sm:pr-4 py-2.5 sm:py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium text-sm sm:text-base" placeholder="Enter your MTN share PIN (e.g. 1234)" maxlength="10" inputmode="numeric">
                    </div>
                    <p class="text-[10px] sm:text-xs text-slate-500 mt-1.5 sm:mt-2"><i class="fas fa-info-circle"></i> Required for instant conversion. Dial <strong>*600*{PIN}#</strong> to set up your share PIN</p>
                </div>

                <!-- Destination Number Display -->
                <div id="ourNumCard" class="hidden mb-4 sm:mb-5 border-2 border-dashed border-brand-orange bg-brand-orangeLight/50 rounded-xl p-4 sm:p-5 text-center transition-all">
                    <div class="text-[10px] sm:text-xs font-bold text-brand-orange/80 uppercase tracking-wider mb-1.5 sm:mb-2">Send Airtime To This Number</div>
                    <div class="text-xl sm:text-2xl font-black tracking-wider text-slate-800 my-1 sm:my-2" id="ourNum"></div>
                    <div class="text-xs sm:text-sm font-medium text-slate-500 mb-3 sm:mb-4" id="ourNetLabel"></div>
                    <button type="button" onclick="copyOurNum()" class="bg-brand-orange hover:bg-brand-orangeHover text-white px-4 sm:px-6 py-2 sm:py-2.5 rounded-xl font-bold transition-colors shadow-sm inline-flex items-center gap-2 text-xs sm:text-sm">
                        <i class="fas fa-copy"></i> Copy Number
                    </button>
                </div>

                <input type="hidden" id="selNet" value="">
                <input type="hidden" id="selRate" value="0">
                <input type="hidden" id="csrfF" value="<?=$csrf?>">
                <input type="hidden" id="txnRef" value="">
                
                <button type="button" id="submitBtn" onclick="submitA2C()" class="w-full bg-gradient-to-r from-brand-orange to-brand-orangeHover hover:from-brand-orangeHover hover:to-orange-600 text-white font-bold text-sm sm:text-base py-3 sm:py-3.5 rounded-xl shadow-lg shadow-orange-500/30 transition-all hover:-translate-y-0.5 flex items-center justify-center gap-2">
                    Submit Request <i class="fas fa-paper-plane"></i>
                </button>
                
                <!-- OTP Input (hidden until initiate succeeds) -->
                <div id="otpSection" class="hidden mt-4 p-4 sm:p-5 bg-blue-50 border border-blue-200 rounded-xl">
                    <div class="text-xs sm:text-sm font-bold text-blue-800 mb-2">Enter OTP</div>
                    <p class="text-[10px] sm:text-xs text-blue-600 mb-3" id="otpMsg">OTP sent to your phone. Enter it below to complete.</p>
                    <div class="flex gap-2">
                        <input type="text" id="otpF" class="flex-1 px-3 sm:px-4 py-2.5 sm:py-3 bg-white border border-blue-300 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-400 font-bold text-base sm:text-lg text-center tracking-widest" placeholder="_ _ _ _ _ _" maxlength="6" inputmode="numeric">
                        <button type="button" onclick="submitOtp()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 sm:px-6 py-2.5 sm:py-3 rounded-xl font-bold transition-colors text-xs sm:text-sm">Verify</button>
                    </div>
                    <button type="button" onclick="resendOtp()" class="mt-2 text-blue-600 hover:text-blue-800 text-[10px] sm:text-xs font-semibold underline">Resend OTP</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Requests -->
    <?php if(!empty($my_requests)):?>
    <div class="bg-white rounded-xl sm:rounded-2xl border border-slate-200 card-shadow overflow-hidden mb-4 sm:mb-6">
        <div class="bg-slate-50 border-b border-slate-100 px-4 sm:px-5 py-3 sm:py-4 flex justify-between items-center">
            <h3 class="font-bold text-slate-800 text-sm sm:text-base">Recent Requests</h3>
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
            <div class="flex items-center p-3 sm:p-4 hover:bg-slate-50 transition-colors">
                <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl bg-slate-100 flex items-center justify-center flex-shrink-0 border border-slate-200 overflow-hidden p-1">
                    <img src="/assets/logos/<?=strtolower($r['network'])?>.png" alt="<?=$r['network']?>" class="w-full h-full object-contain" onerror="this.style.display='none';this.parentNode.innerHTML='<span class=\\'font-bold text-slate-500\\'><?=substr($r['network'],0,1)?></span>'">
                </div>
                <div class="ml-3 sm:ml-4 flex-1">
                    <p class="text-xs sm:text-sm font-semibold text-slate-800">
                        Airtime: ₦<?=number_format((float)$r['airtime_amount'],0)?> <i class="fas fa-arrow-right text-slate-400 text-[10px] mx-0.5 sm:mx-1"></i> Cash: <span class="text-brand-orange">₦<?=number_format((float)$r['cash_amount'],0)?></span>
                    </p>
                    <p class="text-[10px] sm:text-xs text-slate-500 mt-0.5 sm:mt-1"><?=date('d M Y, h:i A',strtotime($r['created_at']))?></p>
                </div>
                <div class="ml-3 sm:ml-4 text-right">
                    <span class="inline-block px-2 sm:px-2.5 py-0.5 sm:py-1 rounded-full text-[10px] sm:text-xs font-bold uppercase <?= $scls ?>"><?= $stxt ?></span>
                </div>
            </div>
            <?php endforeach;?>
        </div>
    </div>
    <?php endif;?>
</div>

<script>
const ourNums = <?=json_encode($net_nums)?>;
const autoNets = <?=json_encode($auto_provs)?>;

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
    
    if(autoNets[net]) {
        document.getElementById('pinSection').classList.remove('hidden');
        document.getElementById('ourNumCard').classList.add('hidden');
    } else {
        document.getElementById('pinSection').classList.add('hidden');
        if(ourNums[net]) {
            document.getElementById('ourNumCard').classList.remove('hidden');
            document.getElementById('ourNum').innerText = ourNums[net];
            document.getElementById('ourNetLabel').innerText = `${net} Receiver Number`;
        } else {
            document.getElementById('ourNumCard').classList.add('hidden');
        }
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
        Swal.fire('Notice', 'Receiver number copied to clipboard: ' + num);
    });
}

function showOtpSection(msg) {
    document.getElementById('otpSection').classList.remove('hidden');
    document.getElementById('otpMsg').innerText = msg || 'OTP sent to your phone. Enter it below to complete.';
    document.getElementById('otpF').value = '';
    document.getElementById('otpF').focus();
    document.getElementById('submitBtn').classList.add('hidden');
}

function hideOtpSection() {
    document.getElementById('otpSection').classList.add('hidden');
    document.getElementById('submitBtn').classList.remove('hidden');
}

async function submitA2C() {
    const net = document.getElementById('selNet').value;
    const sender = document.getElementById('senderF').value;
    const amtStr = document.getElementById('amtF').value.replace(/,/g, '');
    const amt = parseFloat(amtStr);
    
    if(!net) return Swal.fire('Notice', 'Select network', 'info');
    if(isNaN(amt) || amt < 500) return Swal.fire('Notice', 'Minimum amount is ₦500', 'info');
    if(!sender || sender.length < 10) return Swal.fire('Notice', 'Enter a valid sender phone number', 'info');
    
    const cash = amt * (parseFloat(document.getElementById('selRate').value) / 100);
    
    if(autoNets[net]) {
        const pin = document.getElementById('pinF').value.trim();
        if(!pin) return Swal.fire('Notice', 'Enter your airtime share PIN', 'info');
        if(await showConfirm(`Instant A2C with AutoSyncNG\n\nNetwork: ${net}\nSending From: ${sender}\nAirtime Value: ₦${amt}\nCash You Will Receive: ₦${cash}\n\nAn OTP will be sent to ${sender} via SMS.`, 'Confirm')) {
            processA2C(net, sender, amt, pin);
        }
    } else {
        if(await showConfirm(`Submit Airtime to Cash Request?\n\nNetwork: ${net}\nSending From: ${sender}\nAirtime Value: ₦${amt}\nCash You Will Receive: ₦${cash}\n\nMake sure you have already sent the airtime to our number!`, 'Confirm Request')) {
            processA2C(net, sender, amt, '');
        }
    }
}

function processA2C(net, sender, amt, pin) {
    const btn = document.getElementById('submitBtn');
    const ogHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    btn.disabled = true;
    
    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('csrfF').value);
    fd.append('network', net);
    fd.append('sender_phone', sender);
    fd.append('airtime_amount', amt);
    if(pin) fd.append('share_pin', pin);
    
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 60000);
    
    fetch('', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd,
        signal: controller.signal
    })
    .then(async r => { clearTimeout(timeout); const txt = await r.text(); try { return JSON.parse(txt); } catch(e) { console.error('Raw response:', txt); throw new Error(txt); } })
    .then(res => {
        btn.innerHTML = ogHtml;
        btn.disabled = false;
        if (res.success && res.requires_otp) {
            document.getElementById('txnRef').value = res.ref;
            showOtpSection(res.message);
        } else {
            showAlert(res.message || 'Request submitted', res.success ? 'Success' : 'Notice');
            if (res.success) {
                setTimeout(() => location.reload(), 2000);
            }
        }
    })
    .catch(err => {
        btn.innerHTML = ogHtml;
        btn.disabled = false;
        if (err.name === 'AbortError') {
            showAlert('Request timed out. Please try again.', 'Notice');
        } else {
            const msg = err.message && err.message.length < 200 ? err.message : 'Network error occurred. Please try again.';
            showAlert(msg, 'Notice');
        }
    });
}

function submitOtp() {
    const ref = document.getElementById('txnRef').value;
    const otp = document.getElementById('otpF').value.trim();
    if(!ref) return showAlert('No pending transaction', 'Notice');
    if(!otp || otp.length < 4) return showAlert('Enter the OTP sent to your phone', 'Notice');
    
    const btn = document.querySelector('#otpSection button:not([onclick*="resend"])');
    if(!btn) return;
    const og = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    btn.disabled = true;
    
    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('csrfF').value);
    fd.append('action', 'complete_otp');
    fd.append('ref', ref);
    fd.append('otp', otp);
    
    fetch('', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        btn.innerHTML = og;
        btn.disabled = false;
        showAlert(res.message, res.success ? 'Success' : 'Notice');
        if(res.success) {
            setTimeout(() => location.reload(), 2000);
        }
    })
    .catch(() => {
        btn.innerHTML = og;
        btn.disabled = false;
        showAlert('Network error', 'Notice');
    });
}

function resendOtp() {
    const ref = document.getElementById('txnRef').value;
    if(!ref) return showAlert('No pending transaction', 'Notice');
    
    const btn = document.querySelector('#otpSection button[onclick*="resend"]');
    const og = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    btn.disabled = true;
    
    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('csrfF').value);
    fd.append('action', 'resend_otp');
    fd.append('ref', ref);
    
    fetch('', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        btn.innerHTML = og;
        btn.disabled = false;
        showAlert(res.message, res.success ? 'Success' : 'Notice');
        if(res.success) {
            document.getElementById('otpF').value = '';
            document.getElementById('otpF').focus();
        }
    })
    .catch(() => {
        btn.innerHTML = og;
        btn.disabled = false;
        showAlert('Network error', 'Notice');
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
