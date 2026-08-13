<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/giftcard.php';
auth_user();
require_feature_user('giftcard_buy');
$uid = (int)$_SESSION['user_id'];
$user = get_user($uid);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) json_response(['success' => false, 'message' => 'Invalid token'], 403);
    $action = $_POST['action'] ?? '';
    if ($action === 'get_cards') {
        if (!rate_limit('gc_buy_browse_'.$uid, 20, 60)) json_response(['success' => false, 'message' => 'Too many requests.'], 429);
        $country = strtoupper(trim($_POST['country'] ?? ''));
        json_response(['success' => true, 'cards' => fetch_giftcard_buy_catalog($country)]);
    }
    if (!rate_limit('gc_buy_'.$uid, 5, 60)) json_response(['success' => false, 'message' => 'Too many requests.'], 429);
    json_response(process_giftcard_buy_purchase($uid, $_POST));
}

$csrf = csrf_token();
$countries = get_giftcard_buy_countries();

$page_title = 'Buy Gift Cards';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="max-w-2xl mx-auto">
    <div class="flex items-center justify-between mb-4 sm:mb-6">
        <div class="flex items-center gap-4">
            <a href="dashboard.php" class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-white border border-slate-200 flex items-center justify-center text-slate-600 hover:bg-slate-50 transition-colors">
                <i class="fas fa-arrow-left text-xs sm:text-sm"></i>
            </a>
            <div>
                <h1 class="text-lg sm:text-xl font-bold text-slate-800">Buy Gift Cards</h1>
                <p class="text-xs sm:text-sm text-slate-500">Purchase a gift card, delivered by email</p>
            </div>
        </div>
        <div class="bg-brand-orangeLight text-brand-orangeHover px-2.5 sm:px-4 py-1.5 sm:py-2 rounded-xl font-bold border border-brand-orange/20 shadow-sm text-sm">
            ₦<?=number_format((float)$user['wallet_balance'],2)?>
        </div>
    </div>

    <div class="bg-white rounded-xl sm:rounded-2xl border border-slate-200 card-shadow p-4 sm:p-5 mb-4 sm:mb-6">
        <div class="mb-4 sm:mb-5">
            <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5 sm:mb-2">Country</label>
            <div class="relative">
                <select class="block w-full px-3 sm:px-4 py-2.5 sm:py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium appearance-none text-sm" id="countrySel" onchange="loadCards()">
                    <option value="">-- Select country --</option>
                    <?php foreach ($countries as $code => $name): ?>
                    <option value="<?=htmlspecialchars($code)?>"><?=htmlspecialchars($name)?></option>
                    <?php endforeach; ?>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 sm:pr-4 text-slate-500">
                    <i class="fas fa-chevron-down text-xs"></i>
                </div>
            </div>
        </div>

        <div id="cardsWrap" class="mb-4 sm:mb-5"></div>

        <div class="mb-4 sm:mb-5">
            <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5 sm:mb-2">Recipient Email</label>
            <input type="email" id="email" class="w-full px-3 sm:px-4 py-2.5 sm:py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium text-sm" placeholder="recipient@example.com">
        </div>

        <input type="hidden" id="csrfF" value="<?= $csrf ?>">
        <input type="hidden" id="selProductId" value="">
        <input type="hidden" id="selApiRegistryId" value="">

        <button type="button" onclick="buy()" id="buyBtn" class="w-full bg-gradient-to-r from-brand-orange to-brand-orangeHover hover:from-brand-orangeHover hover:to-orange-600 text-white font-bold text-sm sm:text-base py-3 sm:py-3.5 rounded-xl shadow-lg shadow-orange-500/30 transition-all hover:-translate-y-0.5 flex items-center justify-center gap-2">
            Buy Gift Card <i class="fas fa-gift"></i>
        </button>
    </div>
</div>

<script>
let currentCards = [];

async function loadCards() {
    const country = document.getElementById('countrySel').value;
    const wrap = document.getElementById('cardsWrap');
    document.getElementById('selProductId').value = '';
    document.getElementById('selApiRegistryId').value = '';
    if (!country) { wrap.innerHTML = ''; return; }

    wrap.innerHTML = '<div class="text-sm text-slate-400 py-4 text-center"><i class="fas fa-spinner fa-spin"></i> Loading gift cards...</div>';
    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('csrfF').value);
    fd.append('action', 'get_cards');
    fd.append('country', country);
    const r = await fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd }).then(r => r.json());
    currentCards = r.cards || [];
    if (!currentCards.length) { wrap.innerHTML = '<p class="text-sm text-slate-400 text-center py-4">No gift cards available for this country right now.</p>'; return; }

    let html = '<label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-2">Select Card</label><div class="grid grid-cols-2 gap-2">';
    currentCards.forEach((c, i) => {
        html += `<button type="button" class="text-left border border-slate-200 rounded-xl p-3 hover:border-brand-orange transition-colors card-btn" data-idx="${i}" onclick="selectCard(${i}, this)">
            <div class="font-semibold text-sm text-slate-800">${escapeHtml(c.brand)}</div>
            <div class="text-xs text-slate-500">₦${Number(c.ngn_price).toLocaleString('en-US')}</div>
        </button>`;
    });
    html += '</div>';
    wrap.innerHTML = html;
}

function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

function selectCard(idx, btn) {
    document.querySelectorAll('.card-btn').forEach(b => b.classList.remove('border-brand-orange', 'bg-brand-orangeLight'));
    btn.classList.add('border-brand-orange', 'bg-brand-orangeLight');
    const c = currentCards[idx];
    document.getElementById('selProductId').value = c.product_id;
    document.getElementById('selApiRegistryId').value = c.api_registry_id;
}

function buy() {
    const country = document.getElementById('countrySel').value;
    const email = document.getElementById('email').value;
    const productId = document.getElementById('selProductId').value;
    const apiRegistryId = document.getElementById('selApiRegistryId').value;

    if (!country) return Swal.fire('Notice', 'Select a country', 'info');
    if (!productId) return Swal.fire('Notice', 'Select a gift card', 'info');
    if (!email) return Swal.fire('Notice', 'Enter the recipient email', 'info');

    confirmTransaction({
        "Country": country,
        "Recipient": email
    }, function(pin) {
        processGiftcardBuy(country, productId, apiRegistryId, email, pin);
    });
}

function processGiftcardBuy(country, productId, apiRegistryId, email, pin) {
    const btn = document.getElementById('buyBtn');
    const og = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    btn.disabled = true;

    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('csrfF').value);
    fd.append('country', country);
    fd.append('product_id', productId);
    fd.append('api_registry_id', apiRegistryId);
    fd.append('recipient_email', email);
    fd.append('pin', pin);

    fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(r => r.json())
        .then(res => {
            btn.innerHTML = og;
            btn.disabled = false;
            showAlert(res.message, res.success ? 'Success' : 'Notice');
            if (res.success) setTimeout(() => location.reload(), 2000);
        })
        .catch(err => {
            btn.innerHTML = og;
            btn.disabled = false;
            showAlert('Network error occurred. Please try again.', 'Notice');
        });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
