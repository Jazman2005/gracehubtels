<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/kyc.php';
auth_user();
$uid=(int)$_SESSION['user_id'];
$user=get_user($uid);
$kyc_l=(int)$user['kyc_level'];

if ($_SERVER['REQUEST_METHOD']==='POST' && is_ajax()) { 
    if (!verify_csrf($_POST['csrf_token']??'')) json_response(['success'=>false,'message'=>'Invalid token'],403); 
    if (!rate_limit('kyc_'.$uid,3,60)) json_response(['success'=>false,'message'=>'Too many attempts'],429); 
    $act=$_POST['action']??''; 
    if ($act==='verify_nin') json_response(verify_nin($uid,$_POST)); 
    if ($act==='verify_bvn') json_response(verify_bvn($uid,$_POST)); 
    if ($act==='submit_t3') json_response(submit_tier3($uid,$_POST)); 
    json_response(['success'=>false,'message'=>'Unknown action'],400);
}

$csrf=csrf_token();
$limits=get_kyc_limits($kyc_l);

$tiers=[
    0=>['name'=>'Unverified','icon'=>'fas fa-user-slash','color'=>'text-slate-500','bg'=>'bg-slate-100','desc'=>'Fund and receive only'],
    1=>['name'=>'Tier 1 - NIN Verified','icon'=>'fas fa-id-card','color'=>'text-brand-orange','bg'=>'bg-brand-orangeLight','desc'=>"Daily: ₦".number_format($limits['daily'],0)." | Single: ₦".number_format($limits['single'],0)],
    2=>['name'=>'Tier 2 - BVN Verified','icon'=>'fas fa-fingerprint','color'=>'text-brand-navy','bg'=>'bg-blue-100','desc'=>'Higher limits + bank transfers'],
    3=>['name'=>'Tier 3 - Fully Verified','icon'=>'fas fa-check-circle','color'=>'text-emerald-500','bg'=>'bg-emerald-100','desc'=>'Maximum limits']
];

$page_title = 'KYC Verification'; 
include __DIR__ . '/includes/header.php'; 
include __DIR__ . '/includes/sidebar.php'; 
?>

<div class="max-w-3xl mx-auto pb-10">
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-4">
            <a href="/msjpay/user/profile" class="w-10 h-10 rounded-full bg-white border border-slate-200 flex items-center justify-center text-slate-600 hover:bg-slate-50 transition-colors">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-slate-800">Verification</h1>
                <p class="text-sm text-slate-500">Upgrade your account limits</p>
            </div>
        </div>
    </div>

    <!-- CURRENT LEVEL -->
    <div class="bg-gradient-to-br from-brand-navy to-slate-900 rounded-3xl p-8 text-white card-shadow mb-6 relative overflow-hidden">
        <!-- Decorative Elements -->
        <div class="absolute top-0 right-0 w-40 h-40 bg-white/5 rounded-full -mr-20 -mt-20 pointer-events-none"></div>
        <div class="absolute bottom-0 left-0 w-32 h-32 bg-white/5 rounded-full -ml-16 -mb-16 pointer-events-none"></div>
        <div class="absolute inset-0 bg-[url('https://www.transparenttextures.com/patterns/cubes.png')] opacity-10 pointer-events-none"></div>
        
        <div class="relative z-10 text-center">
            <div class="text-5xl mb-3 text-brand-orange">
                <i class="<?=$tiers[$kyc_l]['icon']?>"></i>
            </div>
            <h2 class="text-2xl font-black tracking-wide"><?=$tiers[$kyc_l]['name']?></h2>
            <p class="text-sm text-white/70 font-medium mt-1 mb-6"><?=$tiers[$kyc_l]['desc']?></p>
            
            <div class="flex justify-center items-center gap-2 max-w-xs mx-auto">
                <?php for($i=0;$i<3;$i++):?>
                <div class="h-2 flex-1 rounded-full transition-all duration-500 <?=$i<$kyc_l?'bg-brand-orange':'bg-white/20'?>"></div>
                <?php endfor;?>
            </div>
            <p class="text-xs text-white/50 uppercase tracking-widest mt-3 font-bold">Progress</p>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
            <!-- VERIFICATION FORMS -->
            <?php if($kyc_l<1):?>
            <!-- TIER 1: NIN -->
            <div class="bg-white rounded-2xl border border-slate-200 card-shadow p-6 mb-6">
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-10 h-10 rounded-xl bg-brand-orangeLight text-brand-orangeHover flex items-center justify-center text-lg">
                        <i class="fas fa-id-card"></i>
                    </div>
                    <h3 class="font-bold text-slate-800">Tier 1 - NIN Verification</h3>
                </div>
                
                <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 flex gap-3 text-sm text-blue-800 mb-6">
                    <i class="fas fa-info-circle text-blue-500 mt-0.5 shrink-0"></i>
                    <p>Ensure names match your National Identity Number (NIN) records exactly.</p>
                </div>
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-2">NIN (11 digits)</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <i class="fas fa-hashtag text-slate-400"></i>
                            </div>
                            <input type="tel" id="nin_num" placeholder="12345678901" maxlength="11" class="block w-full pl-11 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium tracking-widest" inputmode="numeric">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-2">First Name</label>
                            <input type="text" id="nin_first" placeholder="As on NIN" class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-2">Last Name</label>
                            <input type="text" id="nin_last" placeholder="As on NIN" class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-2">Middle Name <span class="text-slate-400 font-normal">(Optional)</span></label>
                        <input type="text" id="nin_mid" placeholder="If applicable" class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium">
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-2">Gender</label>
                            <div class="relative">
                                <select id="nin_gender" class="block w-full pl-4 pr-10 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium appearance-none">
                                    <option value="">Select</option>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-4 text-slate-500">
                                    <i class="fas fa-chevron-down"></i>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-2">Date of Birth</label>
                            <input type="date" id="nin_dob" class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium">
                        </div>
                    </div>
                    
                    <button onclick="verifyNIN(this)" class="w-full bg-brand-orange hover:bg-brand-orangeHover text-white font-bold py-3.5 rounded-xl shadow-sm transition-colors mt-2">
                        Verify NIN
                    </button>
                    <div id="nin_result" class="mt-2"></div>
                </div>
            </div>
            
            <?php elseif($kyc_l===1):?>
            <!-- TIER 2: BVN -->
            <div class="bg-white rounded-2xl border border-slate-200 card-shadow p-6 mb-6">
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-lg">
                        <i class="fas fa-fingerprint"></i>
                    </div>
                    <h3 class="font-bold text-slate-800">Tier 2 - BVN Verification</h3>
                </div>
                
                <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 flex gap-3 text-sm text-blue-800 mb-6">
                    <i class="fas fa-info-circle text-blue-500 mt-0.5 shrink-0"></i>
                    <p>Your BVN is 11 digits, usually starting with 22. It is safe and does not give us access to your bank account.</p>
                </div>
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-2">BVN (11 digits)</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <i class="fas fa-hashtag text-slate-400"></i>
                            </div>
                            <input type="tel" id="bvn_num" placeholder="22000000000" maxlength="11" class="block w-full pl-11 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium tracking-widest" inputmode="numeric">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-2">First Name</label>
                            <input type="text" id="bvn_first" placeholder="As on BVN" class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-2">Last Name</label>
                            <input type="text" id="bvn_last" placeholder="As on BVN" class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-2">Gender</label>
                            <div class="relative">
                                <select id="bvn_gender" class="block w-full pl-4 pr-10 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium appearance-none">
                                    <option value="">Select</option>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-4 text-slate-500">
                                    <i class="fas fa-chevron-down"></i>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-2">Date of Birth</label>
                            <input type="date" id="bvn_dob" class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium">
                        </div>
                    </div>
                    
                    <button onclick="verifyBVN(this)" class="w-full bg-brand-navy hover:bg-brand-navyLight text-white font-bold py-3.5 rounded-xl shadow-sm transition-colors mt-2">
                        Verify BVN
                    </button>
                    <div id="bvn_result" class="mt-2"></div>
                </div>
            </div>
            
            <?php elseif($kyc_l===2):?>
            <!-- TIER 3: Full KYC -->
            <div class="bg-white rounded-2xl border border-slate-200 card-shadow p-6 mb-6">
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-10 h-10 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center text-lg">
                        <i class="fas fa-file-signature"></i>
                    </div>
                    <h3 class="font-bold text-slate-800">Tier 3 - Full Verification</h3>
                </div>
                
                <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 flex gap-3 text-sm text-amber-800 mb-6">
                    <i class="fas fa-clock text-amber-500 mt-0.5 shrink-0"></i>
                    <p>Manual review is required for Tier 3. This process can take up to 24 hours to complete.</p>
                </div>
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-2">Full Home Address</label>
                        <textarea id="t3_addr" rows="4" placeholder="Enter your full residential address..." class="block w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium resize-none"></textarea>
                    </div>
                    
                    <button onclick="submitTier3(this)" class="w-full bg-brand-orange hover:bg-brand-orangeHover text-white font-bold py-3.5 rounded-xl shadow-sm transition-colors">
                        Submit for Review
                    </button>
                    <div id="t3_result" class="mt-2"></div>
                </div>
            </div>
            
            <?php else:?>
            <div class="bg-white rounded-2xl border border-slate-200 card-shadow p-10 text-center mb-6">
                <div class="w-24 h-24 bg-emerald-50 text-emerald-500 rounded-full flex items-center justify-center text-5xl mx-auto mb-6 relative">
                    <i class="fas fa-check-circle"></i>
                    <div class="absolute inset-0 border-4 border-emerald-500 rounded-full animate-ping opacity-20"></div>
                </div>
                <h3 class="text-2xl font-black text-slate-800 mb-2">Fully Verified!</h3>
                <p class="text-slate-500 font-medium">You have unlocked maximum transaction limits and all features.</p>
            </div>
            <?php endif;?>
        </div>
        
        <div>
            <!-- TIER LIMITS TABLE -->
            <div class="bg-white rounded-2xl border border-slate-200 card-shadow overflow-hidden sticky top-6">
                <div class="px-6 py-4 border-b border-slate-100 bg-slate-50">
                    <h3 class="font-bold text-slate-800 flex items-center gap-2 text-sm uppercase tracking-wider">
                        <i class="fas fa-tachometer-alt text-slate-400"></i> Tier Limits
                    </h3>
                </div>
                
                <div class="divide-y divide-slate-100">
                    <?php 
                    $tier_info=[
                        [0,'Unverified','₦0','₦0','No transfers', 'fas fa-user-slash'],
                        [1,'Tier 1 (NIN)', '₦'.number_format((float)setting('kyc_tier1_limit_single','20000'),0), '₦'.number_format((float)setting('kyc_tier1_limit_daily','50000'),0),'Basic services', 'fas fa-id-card'],
                        [2,'Tier 2 (BVN)', '₦'.number_format((float)setting('kyc_tier2_limit_single','100000'),0), '₦'.number_format((float)setting('kyc_tier2_limit_daily','200000'),0),'Bank transfers', 'fas fa-fingerprint'],
                        [3,'Tier 3 (Full)', '₦'.number_format((float)setting('kyc_tier3_limit_single','1000000'),0), '₦'.number_format((float)setting('kyc_tier3_limit_daily','5000000'),0),'Max limits', 'fas fa-check-circle']
                    ]; 
                    foreach($tier_info as [$lv,$tname,$single,$daily,$note,$icon]):
                        $isActive = $lv === $kyc_l;
                        $isPast = $lv < $kyc_l;
                    ?>
                    <div class="flex items-center gap-4 p-4 <?= $isActive ? 'bg-brand-orangeLight/30' : '' ?>">
                        <div class="w-10 h-10 rounded-full flex items-center justify-center text-lg <?= $isActive ? 'bg-brand-orange text-white shadow-md' : ($isPast ? 'bg-emerald-100 text-emerald-500' : 'bg-slate-100 text-slate-400') ?>">
                            <?php if($isPast): ?>
                                <i class="fas fa-check"></i>
                            <?php else: ?>
                                <i class="<?=$icon?>"></i>
                            <?php endif; ?>
                        </div>
                        
                        <div class="flex-1 min-w-0">
                            <div class="flex justify-between items-end mb-1">
                                <h4 class="text-sm font-bold <?= $isActive ? 'text-brand-orangeHover' : 'text-slate-800' ?> truncate pr-2"><?=$tname?></h4>
                                <span class="text-xs font-black text-slate-700 whitespace-nowrap"><?=$single?> <span class="text-[10px] text-slate-400 font-semibold">/TXN</span></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <p class="text-xs font-semibold text-slate-500 truncate"><?=$note?></p>
                                <span class="text-xs font-bold text-slate-600 whitespace-nowrap"><?=$daily?> <span class="text-[10px] text-slate-400">/DAY</span></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach;?>
                </div>
            </div>
        </div>
    </div>
</div>

<input type="hidden" id="csrfF" value="<?=$csrf?>">

<script>
function postReq(act, data, btn, resDivId) {
    const ogHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    btn.disabled = true;
    const resDiv = document.getElementById(resDivId);
    resDiv.innerHTML = '';
    
    const fd = new FormData();
    fd.append('action', act);
    fd.append('csrf_token', document.getElementById('csrfF').value);
    for(let k in data) fd.append(k, data[k]);
    
    fetch('', { method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: fd })
    .then(r => r.json())
    .then(res => {
        if(res.success) {
            resDiv.innerHTML = `<div class="p-3 bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm font-semibold rounded-xl flex items-center gap-2"><i class="fas fa-check-circle"></i> ${res.message}</div>`;
            setTimeout(() => location.reload(), 2000);
        } else {
            resDiv.innerHTML = `<div class="p-3 bg-rose-50 border border-rose-200 text-rose-700 text-sm font-semibold rounded-xl flex items-center gap-2"><i class="fas fa-exclamation-circle"></i> ${res.message}</div>`;
            btn.innerHTML = ogHtml;
            btn.disabled = false;
        }
    }).catch(e => {
        resDiv.innerHTML = `<div class="p-3 bg-rose-50 border border-rose-200 text-rose-700 text-sm font-semibold rounded-xl flex items-center gap-2"><i class="fas fa-wifi"></i> Network error</div>`;
        btn.innerHTML = ogHtml;
        btn.disabled = false;
    });
}

function verifyNIN(btn) {
    const data = {
        nin: document.getElementById('nin_num').value,
        first_name: document.getElementById('nin_first').value,
        last_name: document.getElementById('nin_last').value,
        middle_name: document.getElementById('nin_mid').value,
        gender: document.getElementById('nin_gender').value,
        dob: document.getElementById('nin_dob').value
    };
    if(!data.nin || !data.first_name || !data.last_name || !data.gender || !data.dob) {
        return alert("Please fill all required fields");
    }
    postReq('verify_nin', data, btn, 'nin_result');
}

function verifyBVN(btn) {
    const data = {
        bvn: document.getElementById('bvn_num').value,
        first_name: document.getElementById('bvn_first').value,
        last_name: document.getElementById('bvn_last').value,
        gender: document.getElementById('bvn_gender').value,
        dob: document.getElementById('bvn_dob').value
    };
    if(!data.bvn || !data.first_name || !data.last_name || !data.gender || !data.dob) {
        return alert("Please fill all required fields");
    }
    postReq('verify_bvn', data, btn, 'bvn_result');
}

function submitTier3(btn) {
    const addr = document.getElementById('t3_addr').value;
    if(!addr || addr.length < 10) return alert("Please enter a valid home address");
    postReq('submit_t3', {address: addr}, btn, 't3_result');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>