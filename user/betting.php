<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/betting.php';
auth_user();
$uid = (int)$_SESSION['user_id'];
$user = get_user($uid); 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) { 
    if (!verify_csrf($_POST['csrf_token'] ?? '')) json_response(['success' => false, 'message' => 'Invalid token'], 403); 
    if (!rate_limit('bet_'.$uid, 5, 60)) json_response(['success' => false, 'message' => 'Too many requests.'], 429); 
    
    $action = $_POST['action'] ?? ''; 
    if ($action === 'validate_customer') { 
        $r = validate_betting_customer((int)($_POST['plan_id'] ?? 0), sanitize_phone($_POST['customer_id'] ?? '')); 
        json_response($r); 
    } else { 
        json_response(process_betting($uid, $_POST)); 
    }
} 

$csrf = csrf_token();
$platforms = db()->prepare("SELECT * FROM betting_plans WHERE is_active=1 ORDER BY platform_name");
$platforms->execute();
$platforms = $platforms->fetchAll();

$page_title = 'Betting'; 
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
                <h1 class="text-lg sm:text-xl font-bold text-slate-800">Fund Betting Wallet</h1>
                <p class="text-xs sm:text-sm text-slate-500">Fund your betting account instantly</p>
            </div>
        </div>
        <div class="bg-brand-orangeLight text-brand-orangeHover px-2.5 sm:px-4 py-1.5 sm:py-2 rounded-xl font-bold border border-brand-orange/20 shadow-sm text-sm">
            ₦<?=number_format((float)$user['wallet_balance'],2)?>
        </div>
    </div>

    <div class="bg-white rounded-xl sm:rounded-2xl border border-slate-200 card-shadow p-4 sm:p-5 mb-4 sm:mb-6">
        <!-- Platform Selection -->
        <div class="mb-4 sm:mb-5">
            <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5 sm:mb-2">Select Platform</label>
            <div class="relative">
                <select class="block w-full px-3 sm:px-4 py-2.5 sm:py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium appearance-none text-sm" id="platformSel" onchange="platformChanged()">
                    <option value="">-- Select Betting Platform --</option>
                    <?php foreach ($platforms as $p): ?>
                    <option value="<?=$p['id']?>" data-charge="<?=$p['charge']?>"><?=htmlspecialchars($p['platform_name'])?></option>
                    <?php endforeach; ?>
                </select>
                <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-slate-400">
                    <i class="fas fa-chevron-down text-xs"></i>
                </div>
            </div>
        </div>

        <form id="betForm" onsubmit="return submitBet(event)" class="hidden">
            <input type="hidden" id="plan_id" name="plan_id" value="">
            <input type="hidden" name="csrf_token" value="<?=$csrf?>">
            
            <div class="mb-4 sm:mb-5">
                <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5 sm:mb-2">Customer ID</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 flex items-center pl-3.5 sm:pl-4 pointer-events-none text-slate-400">
                        <i class="fas fa-id-card text-sm"></i>
                    </div>
                    <input type="text" id="customerId" name="customer_id" class="block w-full pl-10 sm:pl-11 pr-3 sm:pr-4 py-2.5 sm:py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors text-sm" placeholder="e.g. 4403597" required onblur="validateCustomer()">
                </div>
                <div id="valResult" class="text-xs sm:text-sm mt-2 font-bold px-1 min-h-[20px]"></div>
                <input type="hidden" id="customerName" name="customer_name" value="">
            </div>
            
            <div class="mb-4 sm:mb-5">
                <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5 sm:mb-2">Amount (₦)</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 flex items-center pl-3.5 sm:pl-4 pointer-events-none text-slate-400 font-bold">₦</div>
                    <input type="number" id="amountInp" name="amount" min="100" max="500000" class="block w-full pl-10 sm:pl-11 pr-3 sm:pr-4 py-2.5 sm:py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-bold text-base" placeholder="Min: 100" required oninput="calcTotal()">
                </div>
                <div class="flex justify-between items-center mt-2 px-1">
                    <div class="text-xs text-slate-500 font-medium">Extra Charge: <span id="chargeAmt" class="font-bold text-slate-700">₦0.00</span></div>
                    <div class="text-xs sm:text-sm text-slate-500 font-medium">Total: <span id="totalAmt" class="font-bold text-brand-orange text-sm sm:text-base">₦0.00</span></div>
                </div>
            </div>

            <div class="mb-5 sm:mb-6">
                <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5 sm:mb-2">Transaction PIN</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 flex items-center pl-3.5 sm:pl-4 pointer-events-none text-slate-400">
                        <i class="fas fa-lock text-sm"></i>
                    </div>
                    <input type="password" name="pin" id="pinInp" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" class="block w-full pl-10 sm:pl-11 pr-3 sm:pr-4 py-2.5 sm:py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors tracking-[0.2em] font-bold text-base" placeholder="****" required autocomplete="off">
                </div>
            </div>

            <button type="submit" id="btnSubmit" class="w-full bg-brand-orange hover:bg-brand-orangeHover text-white py-3 sm:py-3.5 rounded-xl font-bold transition-all card-shadow flex items-center justify-center gap-2 text-sm sm:text-base group disabled:opacity-70 disabled:cursor-not-allowed">
                <span>Fund Wallet</span>
                <i class="fas fa-arrow-right text-xs sm:text-sm group-hover:translate-x-1 transition-transform"></i>
            </button>
        </form>
    </div>
</div>

<script>
let currentCharge = 0;
function platformChanged(){
    const sel = document.getElementById('platformSel');
    const frm = document.getElementById('betForm');
    const pid = document.getElementById('plan_id');
    const cus = document.getElementById('customerId');
    const valRes = document.getElementById('valResult');
    
    if(!sel.value){ frm.classList.add('hidden'); return; }
    
    const opt = sel.options[sel.selectedIndex];
    currentCharge = parseFloat(opt.getAttribute('data-charge')||0);
    pid.value = sel.value;
    frm.classList.remove('hidden');
    cus.value = '';
    valRes.innerHTML = '';
    document.getElementById('customerName').value = '';
    calcTotal();
}

function calcTotal(){
    const amt = parseFloat(document.getElementById('amountInp').value) || 0;
    const tot = amt + currentCharge;
    document.getElementById('chargeAmt').textContent = '₦' + currentCharge.toLocaleString('en-US',{minimumFractionDigits:2});
    document.getElementById('totalAmt').textContent = '₦' + tot.toLocaleString('en-US',{minimumFractionDigits:2});
}

function validateCustomer(){
    const cus = document.getElementById('customerId').value.trim();
    const pid = document.getElementById('plan_id').value;
    const res = document.getElementById('valResult');
    const nameInp = document.getElementById('customerName');
    
    if(cus.length < 3) return;
    
    res.innerHTML = '<span class="text-slate-500"><i class="fas fa-spinner fa-spin mr-1"></i> Validating ID...</span>';
    const fd = new FormData();
    fd.append('action', 'validate_customer');
    fd.append('plan_id', pid);
    fd.append('customer_id', cus);
    fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
    
    fetch('',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json())
    .then(d=>{
        if(d.success){
            res.innerHTML = `<span class="text-green-600"><i class="fas fa-check-circle mr-1"></i> ${d.name}</span>`;
            nameInp.value = d.name;
        } else {
            res.innerHTML = `<span class="text-red-500"><i class="fas fa-times-circle mr-1"></i> ${d.message}</span>`;
            nameInp.value = '';
        }
    }).catch(e=>{
        res.innerHTML = '<span class="text-red-500"><i class="fas fa-exclamation-triangle mr-1"></i> Validation error</span>';
    });
}

function submitBet(e){
    e.preventDefault();
    const f = e.target;
    const b = document.getElementById('btnSubmit');
    if(!document.getElementById('customerName').value) {
        Swal.fire({icon:'warning', title:'Validation Required', text:'Please wait for Customer ID to validate successfully.', confirmButtonColor:'#EA580C'});
        return false;
    }
    b.disabled = true;
    b.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    
    fetch('',{method:'POST',body:new FormData(f),headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json())
    .then(d=>{
        b.disabled = false;
        b.innerHTML = '<span>Fund Wallet</span><i class="fas fa-arrow-right text-xs sm:text-sm group-hover:translate-x-1 transition-transform"></i>';
        if(d.success){
            Swal.fire({icon:'success', title:'Success', text:d.message, confirmButtonColor:'#16A34A', allowOutsideClick:false}).then(()=>{
                window.location.href = '/user/receipt?ref='+d.ref;
            });
        }else{
            Swal.fire({icon:'error', title:'Failed', text:d.message, confirmButtonColor:'#EA580C'});
            document.getElementById('pinInp').value='';
        }
    }).catch(e=>{
        b.disabled = false;
        b.innerHTML = '<span>Fund Wallet</span><i class="fas fa-arrow-right text-xs sm:text-sm group-hover:translate-x-1 transition-transform"></i>';
        Swal.fire({icon:'error', title:'Error', text:'Network error occurred', confirmButtonColor:'#EA580C'});
    });
    return false;
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
