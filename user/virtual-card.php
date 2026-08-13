<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/virtual_card.php';
auth_user();
require_feature_user('virtual_card');
$uid = (int)$_SESSION['user_id'];
$user = get_user($uid);

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
    if ($action === 'reveal') { 
        if (!verify_pin($uid, $_POST['pin'] ?? '')) { 
            json_response(['success' => false, 'message' => 'Incorrect transaction PIN'], 400); 
        } 
        $card_id = $_POST['card_id'] ?? ''; 
        $s = db()->prepare("SELECT * FROM virtual_cards WHERE card_id=? AND user_id=? LIMIT 1"); 
        $s->execute([$card_id, $uid]); 
        if (!$s->fetch()) { 
            json_response(['success' => false, 'message' => 'Card not found'], 400); 
        } 
        $cw = new CashwyreAPI(); 
        $cd = $cw->get_card($card_id); 
        if (!$cd['success']) {
            $u = get_user($uid);
            $cc = $u['cashwyre_customer_code'] ?? '';
            $em = $u['email'] ?? '';
            if ($cc && $em) {
                $cl = $cw->get_cards($cc, $em);
                if ($cl['success'] && !empty($cl['data'])) {
                    $all = $cl['data'];
                    if (isset($all['cards']) && is_array($all['cards'])) $all = $all['cards'];
                    if (isset($all['card']) && is_array($all['card'])) $all = $all['card'];
                    if (isset($all['data']) && is_array($all['data'])) $all = $all['data'];
                    if (isset($all[0])) {
                        $realId = $all[0]['cardCode'] ?? $all[0]['CardCode'] ?? $all[0]['code'] ?? '';
                        if ($realId) {
                            db()->prepare("UPDATE virtual_cards SET card_id=? WHERE card_id=?")->execute([$realId, $card_id]);
                            $card_id = $realId;
                            $cd = $cw->get_card($realId);
                        }
                    }
                }
            }
            if (!$cd['success']) {
                json_response(['success' => false, 'message' => $cd['message'] ?? 'Failed to fetch card details'], 400);
            }
        }
        $d = $cd['data'] ?? []; 
        if (isset($d['card']) && is_array($d['card'])) $d = $d['card']; 
        $expiryRaw = $d['validMonthYear'] ?? $d['ValidMonthYear'] ?? $d['expiryOnInfo'] ?? $d['expiryOn'] ?? $d['ExpiryOn'] ?? $d['expiry'] ?? $d['expiryDate'] ?? $d['expirationDate'] ?? $d['expireDate'] ?? $d['exp_date'] ?? $d['expMonth'] ?? '';
        if (!$expiryRaw) {
            log_error('api_cashwyre', 'EXPIRY_KEYS', ['keys' => array_keys($d), 'sample' => json_encode($d)]);
        }
        json_response(['success' => true, 'data' => [ 
            'card_number' => $d['cardNumber'] ?? $d['CardNumber'] ?? '', 
            'cvv' => $d['cvV2'] ?? $d['CvV2'] ?? '', 
            'expiry' => format_expiry($expiryRaw), 
        ]]); 
    } 
    if (in_array($action, ['freeze', 'unfreeze', 'terminate', 'fund', 'withdraw'])) { 
        json_response(process_card_action($uid, $action, $_POST['card_id'] ?? '', $_POST)); 
    } 
    json_response(['success' => false, 'message' => 'Unknown action'], 400);
} 

$csrf = csrf_token();
$cards = get_user_virtual_cards($uid);
$fee = get_creation_fee();
$rate = get_exchange_rate();
$setupUsd = get_setup_fee_usd();

$page_title = 'Virtual Cards'; 
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
                <h1 class="text-lg sm:text-xl font-bold text-slate-800">Virtual Cards</h1>
                <p class="text-xs sm:text-sm text-slate-500">Manage your USD cards for online payments</p>
            </div>
        </div>
        <div class="bg-brand-orangeLight text-brand-orangeHover px-2.5 sm:px-4 py-1.5 sm:py-2 rounded-xl font-bold border border-brand-orange/20 shadow-sm hidden sm:block text-sm">
            ₦<?=number_format((float)$user['wallet_balance'],2)?>
        </div>
    </div>

    <?php if (empty($cards)): ?>
        <div class="bg-white rounded-xl sm:rounded-2xl border border-dashed border-slate-300 p-5 sm:p-8 text-center card-shadow">
            <div class="w-14 h-14 sm:w-20 sm:h-20 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-3 sm:mb-4 text-slate-300 shadow-inner">
                <i class="fas fa-credit-card text-2xl sm:text-4xl"></i>
            </div>
            <h3 class="text-base sm:text-xl font-bold text-slate-800 mb-1 sm:mb-2">No Virtual Cards Yet</h3>
            <p class="text-xs sm:text-sm text-slate-500 mb-4 sm:mb-6 max-w-sm mx-auto">Create a USD virtual card to pay for online subscriptions, ads, and international shopping without restrictions.</p>
            
            <button onclick="openCreate()" class="bg-gradient-to-r from-brand-orange to-brand-orangeHover hover:from-brand-orangeHover hover:to-orange-600 text-white font-bold px-5 sm:px-8 py-2.5 sm:py-3.5 rounded-xl shadow-lg shadow-orange-500/30 transition-all hover:-translate-y-0.5 inline-flex items-center gap-2 text-sm sm:text-base">
                <i class="fas fa-plus-circle"></i> Create New Card (₦<?= number_format($fee, 0) ?>)
            </button>
            <div class="bg-white rounded-xl border border-slate-200 p-4 mt-4 text-xs text-slate-500 leading-relaxed text-left">
                <p class="font-semibold text-slate-700 mb-1">Card fees &amp; topup</p>
                <p>• Creation fee: <strong>$<?= number_format($setupUsd, 2) ?></strong></p>
                <p>• Min topup: <strong>$5</strong></p>
                <p>• Rate: <strong>$1 = ₦<?= number_format($rate, 0) ?></strong></p>
            </div>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6 mb-4 sm:mb-6">
            <?php foreach ($cards as $c): 
                $isFrozen = $c['status'] === 'frozen';
                $isTerm = $c['status'] === 'terminated';
                $cid = preg_replace('/[^a-zA-Z0-9]/', '_', $c['card_id']);
            ?>
            <div class="relative group">
                <!-- Card Visual -->
                <div id="card_<?=$cid?>" class="rounded-2xl p-5 text-white relative overflow-hidden h-52 flex flex-col justify-between shadow-xl transition-transform duration-300 group-hover:-translate-y-1 <?= $isTerm ? 'bg-gradient-to-br from-slate-600 to-slate-800' : ($isFrozen ? 'bg-gradient-to-br from-blue-700 to-blue-900' : 'bg-gradient-to-br from-[#0C2D7A] to-[#061A5E]') ?>" style="border: 1px solid rgba(255,255,255,0.08);" data-revealed="0" data-card-number="" data-cvv="" data-expiry="">
                    
                    <!-- Background orbs -->
                    <div class="absolute -top-20 -right-16 w-52 h-52 rounded-full pointer-events-none" style="background: radial-gradient(circle, rgba(255,122,0,0.3), transparent 70%);"></div>
                    <div class="absolute -bottom-24 -left-16 w-56 h-56 rounded-full pointer-events-none" style="background: radial-gradient(circle, rgba(10,42,122,0.4), transparent 70%);"></div>
                    <div class="absolute inset-0 pointer-events-none" style="background: linear-gradient(180deg, rgba(255,255,255,0.05) 0%, transparent 50%);"></div>
                    
                    <!-- Top -->
                    <div class="flex justify-between items-start relative z-10">
                        <div>
                            <p class="text-sm font-black tracking-wider text-white">GRACEHUBTELS</p>
                            <p class="text-[9px] font-bold text-white/50 tracking-wide mt-0.5">PREPAID DOLLAR</p>
                        </div>
                        <i class="fab <?= strtolower($c['brand'] ?: 'Visa') === 'visa' ? 'fa-cc-visa' : 'fa-cc-mastercard' ?> text-3xl text-white/80"></i>
                    </div>
                    
                    <!-- Chip -->
                    <div class="flex items-center relative z-10">
                        <div class="w-[38px] h-[28px] rounded" style="background: linear-gradient(135deg, #D4AF37, #B8962E); border: 1px solid #A07820;">
                            <div class="flex flex-col items-center justify-center h-full gap-[3px]">
                                <div class="w-[22px] h-[1.5px] bg-black/20"></div>
                                <div class="w-[22px] h-[1.5px] bg-black/20"></div>
                            </div>
                        </div>
                        <i class="fas fa-wifi text-sm text-white/50 ml-2.5 rotate-90"></i>
                    </div>
                    
                    <!-- Number -->
                    <p id="num_<?=$cid?>" data-masked="<?=htmlspecialchars($c['masked_number'] ?: '**** **** **** ****')?>" class="text-xl font-bold tracking-[3px] font-mono text-white/90 text-center relative z-10" style="text-shadow: 0 2px 4px rgba(0,0,0,0.3);">
                        <?= htmlspecialchars($c['masked_number'] ?: '**** **** **** ****') ?>
                    </p>
                    
                    <!-- Bottom -->
                    <div class="flex justify-between items-end relative z-10">
                        <div>
                            <p class="text-[9px] font-bold text-white/50 tracking-wider mb-0.5">BALANCE</p>
                            <p class="text-base font-bold font-mono">$<?= number_format((float)$c['balance'], 2) ?></p>
                        </div>
                        <div class="flex gap-4 items-end">
                            <div class="text-right">
                                <p class="text-[9px] font-bold text-white/50 tracking-wider mb-0.5">EXPIRES</p>
                                <p id="exp_<?=$cid?>" class="text-sm font-bold font-mono"><?= htmlspecialchars($c['expiry'] ?: '**/**') ?></p>
                            </div>
                            <div id="cvv_<?=$cid?>" class="text-right" style="display:none;">
                                <p class="text-[9px] font-bold text-white/50 tracking-wider mb-0.5">CVV</p>
                                <p class="text-sm font-bold font-mono text-white"></p>
                            </div>
                            <?php if ($isFrozen): ?>
                                <div class="bg-black/70 border border-red-500 rounded-md px-2.5 py-1 flex items-center gap-1.5 rotate-[-10deg]">
                                    <i class="fas fa-lock text-xs text-red-500"></i>
                                    <span class="text-red-500 text-[10px] font-black tracking-wider">FROZEN</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div id="hide_<?=$cid?>" class="absolute top-2 right-2 z-20" style="display:none;">
                        <button onclick="hideCard('<?=$c['card_id']?>')" class="text-white/60 hover:text-white text-xs bg-black/20 hover:bg-black/40 rounded-full px-2 py-0.5 transition-colors">
                            <i class="fas fa-eye-slash"></i> Hide
                        </button>
                    </div>
                </div>

                <!-- Card Actions -->
                <?php if (!$isTerm): ?>
                <div class="bg-white border border-slate-200 border-t-0 rounded-b-2xl p-3 flex justify-between gap-2 shadow-sm -mt-2 pt-4 relative z-0">
                    <button onclick="openFund('<?= $c['card_id'] ?>')" class="flex-1 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 py-2 rounded-lg text-xs font-bold transition-colors inline-flex items-center justify-center gap-1">
                        <i class="fas fa-plus"></i> Fund
                    </button>
                    
                    <button onclick="openWithdraw('<?= $c['card_id'] ?>')" class="flex-1 bg-teal-50 hover:bg-teal-100 text-teal-700 py-2 rounded-lg text-xs font-bold transition-colors inline-flex items-center justify-center gap-1">
                        <i class="fas fa-arrow-up"></i> Withdraw
                    </button>
                    
                    <button onclick="revealCard('<?= $c['card_id'] ?>')" class="flex-1 bg-purple-50 hover:bg-purple-100 text-purple-700 py-2 rounded-lg text-xs font-bold transition-colors inline-flex items-center justify-center gap-1">
                        <i class="fas fa-eye"></i> View
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
    
    <?php if (!empty($cards)): ?>
    <div class="bg-white rounded-xl border border-slate-200 p-4 mt-4 text-xs text-slate-500 leading-relaxed">
        <p class="font-semibold text-slate-700 mb-1">How it works</p>
        <p>• Card setup: <strong>$<?= number_format($setupUsd, 2) ?> (₦<?= number_format($fee, 0) ?>)</strong></p>
        <p>• Minimum topup: <strong>$5</strong></p>
        <p>• Rate: <strong>$1 = ₦<?= number_format($rate, 0) ?></strong></p>
    </div>
    <?php endif; ?>

    <input type="hidden" id="csrfF" value="<?= $csrf ?>">
    <input type="hidden" id="activeAction" value="">
    <input type="hidden" id="activeCard" value="">
</div>

<script>
async function openCreate() {
    let topup = await showPrompt("Topup amount (USD) — min $5:", 'Fund Card', 'USD Amount');
    if(!topup || topup.trim() === '') return;
    let topupUsd = parseFloat(topup);
    if(isNaN(topupUsd) || topupUsd < 5) {
        Swal.fire('Notice', 'Minimum topup amount is $5.', 'info');
        return;
    }
    let setupFee = <?= number_format($fee, 0, '.', '') ?>;
    let rate = <?= $rate ?>;
    let topupNgn = topupUsd * rate;
    let total = setupFee + topupNgn;
    confirmTransaction({
        "Card Setup Fee": "₦" + setupFee.toLocaleString() + " ($<?= number_format($setupUsd, 2) ?>)",
        "Topup Amount": "$" + topupUsd.toFixed(2) + " (₦" + topupNgn.toLocaleString() + ")",
        "Total Charge": "₦" + total.toLocaleString()
    }, function(pin) {
        processAction('create', '', topupUsd.toString(), pin);
    });
}

async function openFund(cid) {
    let amt = await showPrompt("Amount in USD (Min $5):", 'Fund Card', 'USD Amount');
    if(amt && !isNaN(amt) && parseFloat(amt) >= 5) {
        let ngnAmt = (parseFloat(amt) * <?= $rate ?>).toLocaleString('en-US');
        confirmTransaction({
            "Transaction": "Fund Virtual Card",
            "USD Amount": "$" + amt,
            "Rate": "₦<?= $rate ?>/$1",
            "Naira Cost": "₦" + ngnAmt
        }, function(pin) {
            processAction('fund', cid, amt, pin);
        });
    } else if (amt) {
        Swal.fire('Notice', "Minimum funding amount is $5.", 'info');
    }
}

async function openWithdraw(cid) {
    let amt = await showPrompt("Amount in USD to withdraw:", 'Withdraw from Card', 'USD Amount');
    if(amt && !isNaN(amt) && parseFloat(amt) > 0) {
        confirmTransaction({
            "Transaction": "Withdraw from Virtual Card",
            "USD Amount": "$" + amt,
            "Naira Credit": "₦" + (parseFloat(amt) * <?= $rate ?>).toLocaleString('en-US')
        }, function(pin) {
            processAction('withdraw', cid, amt, pin);
        });
    } else if (amt) {
        Swal.fire('Notice', "Invalid amount entered.", 'info');
    }
}

async function revealCard(cid) {
    let sid = cid.replace(/[^a-zA-Z0-9]/g, '_');
    let cardEl = document.getElementById('card_' + sid);
    if (cardEl && cardEl.dataset.revealed === '1') { hideCard(cid); return; }
    let pin = await showPrompt('Enter your PIN to view card details:', 'Reveal Card', '4-digit PIN');
    if (!pin || pin.trim() === '') return;
    document.body.style.cursor = 'wait';
    try {
        const fd = new FormData();
        fd.append('csrf_token', document.getElementById('csrfF').value);
        fd.append('action', 'reveal');
        fd.append('card_id', cid);
        fd.append('pin', pin);
        const r = await fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd });
        const res = await r.json();
        document.body.style.cursor = 'default';
        if (res.success && res.data) {
            let numEl = document.getElementById('num_' + sid);
            let expEl = document.getElementById('exp_' + sid);
            let cvvEl = document.getElementById('cvv_' + sid);
            let hideEl = document.getElementById('hide_' + sid);
            if (!numEl) { Swal.fire('Error', 'Card element not found.', 'error'); return; }
            cardEl.dataset.cardNumber = res.data.card_number || '';
            cardEl.dataset.cvv = res.data.cvv || '';
            cardEl.dataset.expiry = res.data.expiry || '';
            let formatted = (res.data.card_number || '').replace(/.{1,4}/g, '$& ').trim();
            numEl.textContent = formatted;
            numEl.style.cursor = 'pointer';
            numEl.title = 'Click to copy';
            numEl.onclick = function(e) { e.stopPropagation(); copyVal(this.textContent.replace(/ /g, ''), this); };
            if (expEl && res.data.expiry) {
                expEl.textContent = res.data.expiry;
                expEl.style.cursor = 'pointer';
                expEl.title = 'Click to copy';
                expEl.onclick = function(e) { e.stopPropagation(); copyVal(res.data.expiry, this); };
            }
            if (cvvEl && res.data.cvv) {
                cvvEl.style.display = 'block';
                let cvvText = cvvEl.querySelector('p:last-child');
                cvvText.textContent = res.data.cvv;
                cvvText.style.cursor = 'pointer';
                cvvText.title = 'Click to copy';
                cvvText.onclick = function(e) { e.stopPropagation(); copyVal(res.data.cvv, this); };
            }
            if (hideEl) hideEl.style.display = 'block';
            cardEl.dataset.revealed = '1';
            if (cardEl._hideTimer) clearTimeout(cardEl._hideTimer);
            cardEl._hideTimer = setTimeout(() => hideCard(cid), 30000);
        } else {
            Swal.fire('Error', res.message || 'Failed to fetch card details.', 'error');
        }
    } catch(e) {
        document.body.style.cursor = 'default';
        Swal.fire('Error', 'Could not fetch card details.', 'error');
    }
}

function copyVal(text, el) {
    if (!text) return;
    navigator.clipboard.writeText(text).then(() => {
        let orig = el.innerHTML;
        el.innerHTML = '<i class="fas fa-check"></i> Copied!';
        el.style.fontSize = '11px';
        el.style.letterSpacing = '0';
        setTimeout(() => { el.innerHTML = orig; el.style.fontSize = ''; el.style.letterSpacing = ''; }, 1200);
    }).catch(() => {
        let ta = document.createElement('textarea');
        ta.value = text; ta.style.position = 'fixed'; ta.style.left = '-9999px';
        document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); alert('Copied!'); } catch(e) { prompt('Manual copy:', text); }
        document.body.removeChild(ta);
    });
}

function hideCard(cid) {
    let sid = cid.replace(/[^a-zA-Z0-9]/g, '_');
    let cardEl = document.getElementById('card_' + sid);
    let numEl = document.getElementById('num_' + sid);
    let cvvEl = document.getElementById('cvv_' + sid);
    let hideEl = document.getElementById('hide_' + sid);
    if (!cardEl) return;
    if (cardEl._hideTimer) clearTimeout(cardEl._hideTimer);
    if (numEl) {
        numEl.textContent = numEl.dataset.masked || '**** **** **** ****';
        numEl.style.cursor = '';
        numEl.title = '';
        numEl.onclick = null;
    }
    if (cvvEl) {
        cvvEl.style.display = 'none';
        let cvvText = cvvEl.querySelector('p:last-child');
        cvvText.style.cursor = '';
        cvvText.title = '';
        cvvText.onclick = null;
    }
    let expEl = document.getElementById('exp_' + sid);
    if (expEl) { expEl.style.cursor = ''; expEl.title = ''; expEl.onclick = null; }
    if (hideEl) hideEl.style.display = 'none';
    cardEl.dataset.revealed = '0';
}

function cardAction(cid, act) {
    const actNames = {
        'freeze': 'Freeze',
        'unfreeze': 'Unfreeze',
        'terminate': 'Terminate/Close'
    };
    
    confirmTransaction({
        "Transaction": actNames[act] + " Virtual Card",
        "Action": "You are about to " + actNames[act] + " this card."
    }, function(pin) {
        processAction(act, cid, null, pin);
    });
}

function processAction(act, cid, amt, pin) {
    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('csrfF').value);
    fd.append('action', act);
    fd.append('pin', pin);
    
    if(cid) fd.append('card_id', cid);
    if(amt) fd.append('amount', amt);
    if(act === 'create' && amt) fd.append('topup', amt);
    
    // Show loading
    document.body.style.cursor = 'wait';
    
    fetch('', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(async r => { const txt = await r.text(); try { return JSON.parse(txt); } catch(e) { console.error('Raw response:', txt); throw new Error(txt); } })
    .then(res => {
        document.body.style.cursor = 'default';
        if(res.success) {
            if(res.ref) { window.location.href = '/user/receipt?ref=' + res.ref; } else { window.location.href = '/user/transactions'; }
        } else {
            Swal.fire('Error', res.message, 'error');
        }
    })
    .catch(err => {
        document.body.style.cursor = 'default';
        console.error(err); Swal.fire('Server Error', err.message.substring(0, 200), 'error');
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
