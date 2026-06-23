<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/exam_pin.php';
auth_user(); 
$uid = (int) $_SESSION['user_id'];
$user = get_user($uid); 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) { 
    if (!verify_csrf($_POST['csrf_token'] ?? '')) json_response(['success' => false, 'message' => 'Invalid token'], 403); 
    if (!rate_limit('exam_' . $uid, 3, 60)) json_response(['success' => false, 'message' => 'Too many requests.'], 429); 
    json_response(process_exam_pin($uid, $_POST));
} 

$csrf = csrf_token(); 
$pricing=db()->prepare("SELECT plan_name,selling_price FROM service_pricing WHERE service='exam_pin' AND is_active=1 ORDER BY plan_name");
$pricing->execute();
$pricing=$pricing->fetchAll();

$prices = [];
foreach ($pricing as $p) { 
    $prices[$p['plan_name']] = (float) $p['selling_price'];
} 

$exam_info = [ 
    'WAEC' => ['icon' => '/msjpay/assets/logos/waec.png', 'full' => 'West African Examinations Council', 'color' => 'bg-blue-100', 'border' => 'border-blue-200', 'text' => 'text-blue-700'], 
    'NECO' => ['icon' => '/msjpay/assets/logos/neco.png', 'full' => 'National Examinations Council', 'color' => 'bg-green-100', 'border' => 'border-green-200', 'text' => 'text-green-700'], 
    'NABTEB' => ['icon' => '/msjpay/assets/logos/nabteb.png', 'full' => 'National Business and Technical Examinations Board', 'color' => 'bg-emerald-100', 'border' => 'border-emerald-200', 'text' => 'text-emerald-700'],
]; 

$page_title = 'Exam PIN'; 
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
                <h1 class="text-2xl font-bold text-slate-800">Exam PINs</h1>
                <p class="text-sm text-slate-500">Purchase scratch cards for results checking</p>
            </div>
        </div>
        <div class="bg-brand-orangeLight text-brand-orangeHover px-4 py-2 rounded-xl font-bold border border-brand-orange/20 shadow-sm">
            ₦<?=number_format((float)$user['wallet_balance'],2)?>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 card-shadow p-6 mb-6">
        
        <!-- Exam Selection -->
        <div class="mb-6">
            <label class="block text-sm font-semibold text-slate-700 mb-3">Select Exam Board</label>
            <div class="space-y-3">
                <?php foreach ($exam_info as $exam => $info): 
                    $price = $prices[$exam] ?? 0; 
                ?>
                <button type="button" class="plan-item w-full flex items-center justify-between p-4 rounded-xl border-2 border-slate-100 bg-slate-50 hover:border-brand-orange/50 transition-all text-left relative overflow-hidden group" data-exam="<?= $exam ?>" data-price="<?= $price ?>" onclick="selectExam(this,'<?= $exam ?>')">
                    <div class="flex items-center gap-4 relative z-10">
                        <div class="w-12 h-12 rounded-lg <?=$info['color']?> border <?=$info['border']?> flex items-center justify-center p-1 bg-white">
                            <img src="<?= $info['icon'] ?>" alt="<?= $exam ?>" class="w-full h-full object-contain" onerror="this.style.display='none';this.parentNode.innerHTML='<span class=\'font-bold <?=$info['text']?>\'><?=substr($exam,0,1)?></span>'">
                        </div>
                        <div>
                            <div class="font-bold text-slate-800 text-lg"><?= $exam ?></div>
                            <div class="text-xs text-slate-500 line-clamp-1"><?= $info['full'] ?></div>
                        </div>
                    </div>
                    <div class="text-right relative z-10">
                        <div class="font-bold text-brand-navy text-lg"><?= $price > 0 ? '₦' . number_format($price, 0) : 'N/A' ?></div>
                        <div class="text-[10px] uppercase font-semibold text-slate-400">Per PIN</div>
                    </div>
                    
                    <!-- Selected state indicator (hidden by default) -->
                    <div class="absolute inset-0 border-2 border-brand-orange bg-brand-orangeLight rounded-xl opacity-0 check-bg transition-opacity pointer-events-none"></div>
                    <div class="absolute top-3 right-3 text-brand-orange opacity-0 check-icon transition-opacity">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Quantity Selection -->
        <div class="mb-6">
            <label class="block text-sm font-semibold text-slate-700 mb-3">Quantity (1-5)</label>
            <div class="flex items-center justify-between bg-slate-50 border border-slate-200 rounded-xl p-2">
                <button type="button" onclick="changeQty(-1)" class="w-12 h-12 rounded-lg bg-white border border-slate-200 text-slate-600 hover:border-brand-orange hover:text-brand-orange flex items-center justify-center transition-colors shadow-sm">
                    <i class="fas fa-minus"></i>
                </button>
                
                <div class="flex-1 text-center">
                    <div id="qtyDisp" class="text-3xl font-black text-brand-navy">1</div>
                    <div class="text-xs font-semibold text-slate-400 uppercase tracking-wide">PIN(s)</div>
                </div>
                
                <button type="button" onclick="changeQty(1)" class="w-12 h-12 rounded-lg bg-white border border-slate-200 text-slate-600 hover:border-brand-orange hover:text-brand-orange flex items-center justify-center transition-colors shadow-sm">
                    <i class="fas fa-plus"></i>
                </button>
            </div>
            
            <div id="totalBox" class="hidden mt-4 p-4 bg-brand-orangeLight border border-brand-orange/30 rounded-xl flex justify-between items-center">
                <div>
                    <span class="block text-xs text-brand-orange/80 uppercase tracking-wider mb-0.5">Total Amount</span>
                    <span class="text-sm font-semibold text-brand-orangeHover"><span id="summaryQty">1</span>x <span id="summaryExam"></span></span>
                </div>
                <div class="text-right">
                    <span class="text-2xl font-black text-brand-orangeHover" id="totalDisp"></span>
                </div>
            </div>
        </div>

        <input type="hidden" id="selExam" value="">
        <input type="hidden" id="selPrice" value="0">
        <input type="hidden" id="qtyF" value="1">
        <input type="hidden" id="csrfF" value="<?= $csrf ?>">
        
        <button type="button" onclick="openConfirm()" class="w-full bg-gradient-to-r from-brand-orange to-brand-orangeHover hover:from-brand-orangeHover hover:to-orange-600 text-white font-bold text-lg py-4 rounded-xl shadow-lg shadow-orange-500/30 transition-all hover:-translate-y-0.5 flex items-center justify-center gap-2">
            Purchase PIN <i class="fas fa-arrow-right"></i>
        </button>
        
        <p class="text-center text-xs text-slate-500 mt-4 flex items-center justify-center gap-1">
            <i class="fas fa-graduation-cap text-teal-500"></i> PINs delivered instantly. Valid for official result portals.
        </p>
    </div>
</div>

<script>
function selectExam(btn, exam) {
    // Reset all
    document.querySelectorAll('.plan-item').forEach(b => {
        b.classList.remove('border-brand-orange', '!border-transparent');
        b.querySelector('.check-bg').classList.remove('opacity-100');
        b.querySelector('.check-icon').classList.remove('opacity-100');
        // Reset internal text colors
        const priceDiv = b.querySelector('.text-right .font-bold');
        if(priceDiv) priceDiv.classList.remove('!text-brand-orangeHover');
    });
    
    // Set active
    btn.classList.add('border-brand-orange', '!border-transparent');
    btn.querySelector('.check-bg').classList.add('opacity-100');
    btn.querySelector('.check-icon').classList.add('opacity-100');
    const activePrice = btn.querySelector('.text-right .font-bold');
    if(activePrice) activePrice.classList.add('!text-brand-orangeHover');
    
    document.getElementById('selExam').value = exam;
    document.getElementById('selPrice').value = btn.getAttribute('data-price');
    
    document.getElementById('summaryExam').innerText = exam;
    updateTotal();
}

function changeQty(delta) {
    let q = parseInt(document.getElementById('qtyF').value);
    q += delta;
    if(q < 1) q = 1;
    if(q > 5) q = 5;
    
    document.getElementById('qtyF').value = q;
    document.getElementById('qtyDisp').innerText = q;
    document.getElementById('summaryQty').innerText = q;
    updateTotal();
}

function updateTotal() {
    const prc = parseFloat(document.getElementById('selPrice').value) || 0;
    const q = parseInt(document.getElementById('qtyF').value) || 1;
    const box = document.getElementById('totalBox');
    
    if(prc > 0 && document.getElementById('selExam').value) {
        document.getElementById('totalDisp').innerText = '₦' + (prc * q).toLocaleString();
        box.classList.remove('hidden');
    } else {
        box.classList.add('hidden');
    }
}

function openConfirm() {
    const exam = document.getElementById('selExam').value;
    const prc = parseFloat(document.getElementById('selPrice').value);
    const q = parseInt(document.getElementById('qtyF').value);
    
    if(!exam || prc <= 0) return alert('Please select an exam board');
    
    const total = prc * q;
    
    const pin = prompt(`Confirm Exam PIN Purchase:\n\nExam: ${exam}\nQuantity: ${q}\nTotal Amount: ₦${total.toLocaleString()}\n\nEnter your 4-digit PIN:`);
    
    if(pin && pin.length >= 4) {
        processPurchase(exam, q, pin);
    }
}

function processPurchase(exam, q, pin) {
    const btn = document.querySelector('button[onclick="openConfirm()"]');
    const ogHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    btn.disabled = true;
    
    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('csrfF').value);
    fd.append('exam', exam);
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
            // Need a custom alert for pins to show them properly
            let pinText = res.data && res.data.pins ? res.data.pins.join('\n') : '';
            alert(`Success! PINs Generated.\n\n${res.message}\n\n${pinText}`);
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