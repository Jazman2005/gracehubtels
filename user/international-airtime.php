<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/international_airtime.php';
auth_user();
require_feature_user('international_airtime');
$uid = (int)$_SESSION['user_id'];
$user = get_user($uid);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) json_response(['success' => false, 'message' => 'Invalid token'], 403);
    $action = $_POST['action'] ?? '';
    if ($action === 'get_operators') {
        if (!rate_limit('intl_airtime_browse_'.$uid, 20, 60)) json_response(['success' => false, 'message' => 'Too many requests.'], 429);
        $country = strtoupper(trim($_POST['country'] ?? ''));
        json_response(['success' => true, 'operators' => fetch_international_airtime_catalog($country)]);
    }
    if (!rate_limit('intl_airtime_'.$uid, 5, 60)) json_response(['success' => false, 'message' => 'Too many requests.'], 429);
    json_response(process_international_airtime_purchase($uid, $_POST));
}

$csrf = csrf_token();
$countries = get_international_airtime_countries();

$page_title = 'International Airtime';
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
                <h1 class="text-lg sm:text-xl font-bold text-slate-800">International Airtime</h1>
                <p class="text-xs sm:text-sm text-slate-500">Top up a phone number abroad</p>
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
                <select class="block w-full px-3 sm:px-4 py-2.5 sm:py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium appearance-none text-sm" id="countrySel" onchange="loadOperators()">
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

        <div class="mb-4 sm:mb-5">
            <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5 sm:mb-2">Recipient Number</label>
            <input type="text" id="phone" class="w-full px-3 sm:px-4 py-2.5 sm:py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium text-sm" placeholder="Include country code, e.g. +1...">
        </div>

        <div id="operatorsWrap" class="mb-4 sm:mb-5"></div>

        <input type="hidden" id="csrfF" value="<?= $csrf ?>">
        <input type="hidden" id="selOperatorId" value="">
        <input type="hidden" id="selApiRegistryId" value="">
        <input type="hidden" id="selAmount" value="">

        <button type="button" onclick="buy()" id="buyBtn" class="w-full bg-gradient-to-r from-brand-orange to-brand-orangeHover hover:from-brand-orangeHover hover:to-orange-600 text-white font-bold text-sm sm:text-base py-3 sm:py-3.5 rounded-xl shadow-lg shadow-orange-500/30 transition-all hover:-translate-y-0.5 flex items-center justify-center gap-2">
            Buy Airtime <i class="fas fa-mobile-screen-button"></i>
        </button>
    </div>
</div>

<script>
let currentOperators = [];

async function loadOperators() {
    const country = document.getElementById('countrySel').value;
    const wrap = document.getElementById('operatorsWrap');
    document.getElementById('selOperatorId').value = '';
    document.getElementById('selApiRegistryId').value = '';
    document.getElementById('selAmount').value = '';
    if (!country) { wrap.innerHTML = ''; return; }

    wrap.innerHTML = '<div class="text-sm text-slate-400 py-4 text-center"><i class="fas fa-spinner fa-spin"></i> Loading operators...</div>';
    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('csrfF').value);
    fd.append('action', 'get_operators');
    fd.append('country', country);
    const r = await fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd }).then(r => r.json());
    currentOperators = r.operators || [];
    if (!currentOperators.length) { wrap.innerHTML = '<p class="text-sm text-slate-400 text-center py-4">No operators available for this country right now.</p>'; return; }

    let html = '<label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-2">Select Operator & Amount</label><div class="space-y-2">';
    currentOperators.forEach((op, i) => {
        if (op.type === 'RANGE') {
            html += `<div class="border border-slate-200 rounded-xl p-3">
                <div class="font-semibold text-sm text-slate-800 mb-2">${escapeHtml(op.operator_name)} (${op.min}–${op.max})</div>
                <input type="number" min="${op.min}" max="${op.max}" step="0.01" placeholder="Enter amount (${op.min}-${op.max})" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-sm" onchange="selectRange(${i}, this.value)">
            </div>`;
        } else {
            html += `<button type="button" class="w-full text-left border border-slate-200 rounded-xl p-3 hover:border-brand-orange transition-colors op-btn" data-idx="${i}" onclick="selectFixed(${i}, this)">
                <div class="font-semibold text-sm text-slate-800">${escapeHtml(op.operator_name)} — ${escapeHtml(op.label || '')}</div>
                <div class="text-xs text-slate-500">₦${Number(op.ngn_price).toLocaleString('en-US')}</div>
            </button>`;
        }
    });
    html += '</div>';
    wrap.innerHTML = html;
}

function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

function selectFixed(idx, btn) {
    document.querySelectorAll('.op-btn').forEach(b => b.classList.remove('border-brand-orange', 'bg-brand-orangeLight'));
    btn.classList.add('border-brand-orange', 'bg-brand-orangeLight');
    const op = currentOperators[idx];
    document.getElementById('selOperatorId').value = op.operator_id;
    document.getElementById('selApiRegistryId').value = op.api_registry_id;
    document.getElementById('selAmount').value = op.sender_amount;
}

function selectRange(idx, val) {
    const op = currentOperators[idx];
    document.getElementById('selOperatorId').value = op.operator_id;
    document.getElementById('selApiRegistryId').value = op.api_registry_id;
    document.getElementById('selAmount').value = val;
}

function buy() {
    const country = document.getElementById('countrySel').value;
    const phone = document.getElementById('phone').value;
    const operatorId = document.getElementById('selOperatorId').value;
    const apiRegistryId = document.getElementById('selApiRegistryId').value;
    const amount = document.getElementById('selAmount').value;

    if (!country) return Swal.fire('Notice', 'Select a country', 'info');
    if (!phone) return Swal.fire('Notice', 'Enter the recipient number', 'info');
    if (!operatorId || !amount) return Swal.fire('Notice', 'Select an operator/amount', 'info');

    confirmTransaction({
        "Country": country,
        "Recipient": phone,
        "Amount": amount
    }, function(pin) {
        processIntlAirtime(country, phone, operatorId, apiRegistryId, amount, pin);
    });
}

function processIntlAirtime(country, phone, operatorId, apiRegistryId, amount, pin) {
    const btn = document.getElementById('buyBtn');
    const og = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    btn.disabled = true;

    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('csrfF').value);
    fd.append('country', country);
    fd.append('phone', phone);
    fd.append('operator_id', operatorId);
    fd.append('api_registry_id', apiRegistryId);
    fd.append('amount', amount);
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
