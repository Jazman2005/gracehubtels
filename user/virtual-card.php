<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/virtual_card.php';
require_once __DIR__.'/core/kyc.php';
auth_user();
$uid = (int)$_SESSION['user_id'];
$user = get_user($uid);
$kyc_l = (int)$user['kyc_level']; 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) { 
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { 
        json_response(['success' => false, 'message' => 'Invalid token'], 403); 
    } 
    if (!rate_limit('vcard_'.$uid, 5, 60)) { 
        json_response(['success' => false, 'message' => 'Too many requests.'], 429); 
    } 
    $action = $_POST['action'] ?? ''; 
    if ($action === 'create') { 
        json_response(process_create_virtual_card($uid, $_POST)); 
    } 
    if (in_array($action, ['freeze', 'unfreeze', 'terminate', 'fund'])) { 
        json_response(process_card_action($uid, $action, $_POST['card_id'] ?? '', $_POST)); 
    } 
    json_response(['success' => false, 'message' => 'Unknown action'], 400);
} 

$csrf = csrf_token();
$cards = get_user_virtual_cards($uid);
$fee = (float)setting('virtual_card_creation_fee', '1000');

$page_title = 'Virtual Cards'; 
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
                <h1 class="text-2xl font-bold text-slate-800">Virtual Cards</h1>
                <p class="text-sm text-slate-500">Manage your USD cards for online payments</p>
            </div>
        </div>
        <div class="bg-brand-orangeLight text-brand-orangeHover px-4 py-2 rounded-xl font-bold border border-brand-orange/20 shadow-sm hidden sm:block">
            ₦<?=number_format((float)$user['wallet_balance'],2)?>
        </div>
    </div>

    <?php if (empty($cards)): ?>
        <div class="bg-white rounded-2xl border border-dashed border-slate-300 p-10 text-center card-shadow">
            <div class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-5 text-slate-300 shadow-inner">
                <i class="fas fa-credit-card text-4xl"></i>
            </div>
            <h3 class="text-xl font-bold text-slate-800 mb-2">No Virtual Cards Yet</h3>
            <p class="text-slate-500 mb-8 max-w-sm mx-auto">Create a USD virtual card to pay for online subscriptions, ads, and international shopping without restrictions.</p>
            
            <button onclick="openCreate()" class="bg-gradient-to-r from-brand-orange to-brand-orangeHover hover:from-brand-orangeHover hover:to-orange-600 text-white font-bold px-8 py-3.5 rounded-xl shadow-lg shadow-orange-500/30 transition-all hover:-translate-y-0.5 inline-flex items-center gap-2">
                <i class="fas fa-plus-circle"></i> Create New Card (₦<?= number_format($fee, 0) ?>)
            </button>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <?php foreach ($cards as $c): 
                $isFrozen = $c['status'] === 'frozen';
                $isTerm = $c['status'] === 'terminated';
                $bgClass = $isTerm ? 'from-slate-600 to-slate-800' : ($isFrozen ? 'from-blue-600 to-blue-800' : 'from-brand-navy to-slate-900');
            ?>
            <div class="relative group">
                <!-- Card Visual -->
                <div class="bg-gradient-to-br <?= $bgClass ?> rounded-2xl p-6 text-white card-shadow relative overflow-hidden h-52 flex flex-col justify-between transition-transform duration-300 group-hover:-translate-y-1">
                    
                    <!-- Decorative Elements -->
                    <div class="absolute top-0 right-0 w-32 h-32 bg-white/5 rounded-full -mr-16 -mt-16 pointer-events-none"></div>
                    <div class="absolute bottom-0 left-0 w-24 h-24 bg-white/5 rounded-full -ml-12 -mb-12 pointer-events-none"></div>
                    <div class="absolute inset-0 bg-[url('https://www.transparenttextures.com/patterns/cubes.png')] opacity-10 pointer-events-none"></div>
                    
                    <!-- Top -->
                    <div class="flex justify-between items-start relative z-10">
                        <div>
                            <p class="text-xs font-bold text-white/70 uppercase tracking-wider mb-1"><?= htmlspecialchars($c['brand']) ?> VIRTUAL</p>
                            <?php if($isTerm): ?>
                                <span class="bg-rose-500 text-white text-[10px] font-bold px-2 py-0.5 rounded uppercase">Closed</span>
                            <?php elseif($isFrozen): ?>
                                <span class="bg-blue-500 text-white text-[10px] font-bold px-2 py-0.5 rounded uppercase">Frozen</span>
                            <?php else: ?>
                                <span class="bg-emerald-500 text-white text-[10px] font-bold px-2 py-0.5 rounded uppercase">Active</span>
                            <?php endif; ?>
                        </div>
                        <i class="fab <?= strtolower($c['brand']) === 'visa' ? 'fa-cc-visa' : 'fa-cc-mastercard' ?> text-3xl opacity-80"></i>
                    </div>
                    
                    <!-- Middle -->
                    <div class="relative z-10 text-center">
                        <p class="text-2xl font-black tracking-widest font-mono text-white/90" style="text-shadow: 0 2px 4px rgba(0,0,0,0.3);">
                            <?= $c['masked_number'] ? implode(' ', str_split($c['masked_number'], 4)) : '**** **** **** ****' ?>
                        </p>
                    </div>
                    
                    <!-- Bottom -->
                    <div class="flex justify-between items-end relative z-10">
                        <div>
                            <p class="text-[10px] font-semibold text-white/60 uppercase tracking-wider mb-0.5">Balance</p>
                            <p class="text-lg font-bold font-mono">$<?= number_format((float)$c['balance'], 2) ?></p>
                        </div>
                        <div class="text-right">
                            <p class="text-[10px] font-semibold text-white/60 uppercase tracking-wider mb-0.5">Expires</p>
                            <p class="text-sm font-bold font-mono"><?= htmlspecialchars($c['expiry'] ?: '**/**') ?></p>
                        </div>
                    </div>
                </div>

                <!-- Card Actions -->
                <?php if (!$isTerm): ?>
                <div class="bg-white border border-slate-200 border-t-0 rounded-b-2xl p-3 flex justify-between gap-2 shadow-sm -mt-2 pt-4 relative z-0">
                    <button onclick="openFund('<?= $c['card_id'] ?>')" class="flex-1 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 py-2 rounded-lg text-xs font-bold transition-colors inline-flex items-center justify-center gap-1">
                        <i class="fas fa-plus"></i> Fund
                    </button>
                    
                    <?php if ($c['status'] === 'active'): ?>
                        <button onclick="cardAction('<?= $c['card_id'] ?>','freeze')" class="flex-1 bg-blue-50 hover:bg-blue-100 text-blue-700 py-2 rounded-lg text-xs font-bold transition-colors inline-flex items-center justify-center gap-1">
                            <i class="fas fa-snowflake"></i> Freeze
                        </button>
                    <?php else: ?>
                        <button onclick="cardAction('<?= $c['card_id'] ?>','unfreeze')" class="flex-1 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 py-2 rounded-lg text-xs font-bold transition-colors inline-flex items-center justify-center gap-1">
                            <i class="fas fa-fire"></i> Unfreeze
                        </button>
                    <?php endif; ?>
                    
                    <button onclick="cardAction('<?= $c['card_id'] ?>','terminate')" class="flex-1 bg-rose-50 hover:bg-rose-100 text-rose-700 py-2 rounded-lg text-xs font-bold transition-colors inline-flex items-center justify-center gap-1">
                        <i class="fas fa-times-circle"></i> Close
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (count($cards) < 3): ?>
            <div class="text-center mt-8">
                <button onclick="openCreate()" class="bg-brand-navy hover:bg-brand-navyLight text-white font-bold px-6 py-3 rounded-xl shadow-sm transition-colors inline-flex items-center gap-2">
                    <i class="fas fa-plus-circle"></i> Create Another Card (₦<?= number_format($fee, 0) ?>)
                </button>
                <p class="text-xs text-slate-500 mt-3 flex items-center justify-center gap-1">
                    <i class="fas fa-shield-alt text-green-500"></i> USD virtual cards powered by reliable providers
                </p>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <input type="hidden" id="csrfF" value="<?= $csrf ?>">
    <input type="hidden" id="activeAction" value="">
    <input type="hidden" id="activeCard" value="">
</div>

<script>
function openCreate() {
    if(confirm(`Create a new USD Virtual Card?\n\nA creation fee of ₦<?= number_format($fee, 0) ?> will be deducted from your wallet.\n\nEnter your 4-digit PIN to confirm:`)) {
        let pin = prompt("Enter 4-digit PIN:");
        if(pin && pin.length >= 4) {
            processAction('create', '', null, pin);
        }
    }
}

function openFund(cid) {
    let amt = prompt("Enter amount in USD to fund card (Min $2):");
    if(amt && !isNaN(amt) && parseFloat(amt) >= 2) {
        let pin = prompt("Enter 4-digit PIN:");
        if(pin && pin.length >= 4) {
            processAction('fund', cid, amt, pin);
        }
    }
}

function cardAction(cid, act) {
    const actNames = {
        'freeze': 'Freeze',
        'unfreeze': 'Unfreeze',
        'terminate': 'Terminate/Close'
    };
    
    if(confirm(`Are you sure you want to ${actNames[act]} this card?`)) {
        let pin = prompt("Enter 4-digit PIN:");
        if(pin && pin.length >= 4) {
            processAction(act, cid, null, pin);
        }
    }
}

function processAction(act, cid, amt, pin) {
    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('csrfF').value);
    fd.append('action', act);
    fd.append('pin', pin);
    
    if(cid) fd.append('card_id', cid);
    if(amt) fd.append('amount', amt);
    
    // Show loading
    document.body.style.cursor = 'wait';
    
    fetch('', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        document.body.style.cursor = 'default';
        if(res.success) {
            alert(`Success! ${res.message}`);
            location.reload();
        } else {
            alert(`Error: ${res.message}`);
        }
    })
    .catch(err => {
        document.body.style.cursor = 'default';
        alert('Network error occurred.');
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>