<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/esim.php';
auth_user();
require_feature_user('esim');
$uid = (int)$_SESSION['user_id'];
$user = get_user($uid);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) json_response(['success' => false, 'message' => 'Invalid token'], 403);
    $action = $_POST['action'] ?? '';
    if ($action === 'get_packages') {
        if (!rate_limit('esim_browse_'.$uid, 20, 60)) json_response(['success' => false, 'message' => 'Too many requests.'], 429);
        $region = strtoupper(trim($_POST['region'] ?? ''));
        json_response(['success' => true, 'packages' => fetch_esim_catalog($region)]);
    }
    if ($action === 'refresh_status') {
        if (!rate_limit('esim_refresh_'.$uid, 4, 60)) json_response(['success' => false, 'message' => 'Too many requests. Please wait a moment.'], 429);
        $ref = trim($_POST['ref'] ?? '');
        $owns = db()->prepare("SELECT id FROM esim_orders WHERE transaction_ref=? AND user_id=? LIMIT 1");
        $owns->execute([$ref, $uid]);
        if (!$owns->fetch()) json_response(['success' => false, 'message' => 'Order not found'], 404);
        json_response(resolve_esim_order($ref));
    }
    if (!rate_limit('esim_'.$uid, 5, 60)) json_response(['success' => false, 'message' => 'Too many requests.'], 429);
    json_response(process_esim_purchase($uid, $_POST));
}

$csrf = csrf_token();
$regions = get_esim_regions();
$orders = db()->prepare("SELECT * FROM esim_orders WHERE user_id=? ORDER BY id DESC LIMIT 10");
$orders->execute([$uid]);
$orders = $orders->fetchAll();

$page_title = 'eSIM';
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
                <h1 class="text-lg sm:text-xl font-bold text-slate-800">eSIM</h1>
                <p class="text-xs sm:text-sm text-slate-500">Buy a travel data eSIM</p>
            </div>
        </div>
        <div class="bg-brand-orangeLight text-brand-orangeHover px-2.5 sm:px-4 py-1.5 sm:py-2 rounded-xl font-bold border border-brand-orange/20 shadow-sm text-sm">
            ₦<?=number_format((float)$user['wallet_balance'],2)?>
        </div>
    </div>

    <div class="bg-white rounded-xl sm:rounded-2xl border border-slate-200 card-shadow p-4 sm:p-5 mb-4 sm:mb-6">
        <div class="mb-4 sm:mb-5">
            <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5 sm:mb-2">Region</label>
            <div class="relative">
                <select class="block w-full px-3 sm:px-4 py-2.5 sm:py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium appearance-none text-sm" id="regionSel" onchange="loadPackages()">
                    <option value="">-- Select region --</option>
                    <?php foreach ($regions as $code => $name): ?>
                    <option value="<?=htmlspecialchars($code)?>"><?=htmlspecialchars($name)?></option>
                    <?php endforeach; ?>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 sm:pr-4 text-slate-500">
                    <i class="fas fa-chevron-down text-xs"></i>
                </div>
            </div>
        </div>

        <div id="packagesWrap" class="mb-4 sm:mb-5"></div>

        <div class="mb-4 sm:mb-5">
            <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5 sm:mb-2">Email (for receipt/backup)</label>
            <input type="email" id="email" class="w-full px-3 sm:px-4 py-2.5 sm:py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium text-sm" value="<?=htmlspecialchars($user['email']??'')?>">
        </div>

        <input type="hidden" id="csrfF" value="<?= $csrf ?>">
        <input type="hidden" id="selPackageCode" value="">
        <input type="hidden" id="selApiRegistryId" value="">

        <button type="button" onclick="buy()" id="buyBtn" class="w-full bg-gradient-to-r from-brand-orange to-brand-orangeHover hover:from-brand-orangeHover hover:to-orange-600 text-white font-bold text-sm sm:text-base py-3 sm:py-3.5 rounded-xl shadow-lg shadow-orange-500/30 transition-all hover:-translate-y-0.5 flex items-center justify-center gap-2">
            Buy eSIM <i class="fas fa-sim-card"></i>
        </button>
    </div>

    <?php if (!empty($orders)): ?>
    <div class="bg-white rounded-xl sm:rounded-2xl border border-slate-200 card-shadow overflow-hidden mb-4 sm:mb-6">
        <div class="bg-slate-50 border-b border-slate-100 px-4 sm:px-5 py-3 sm:py-4"><h3 class="font-bold text-slate-800 text-sm sm:text-base">My eSIM Orders</h3></div>
        <div class="divide-y divide-slate-100">
            <?php foreach ($orders as $o): ?>
            <div class="p-3 sm:p-4" id="order-<?=htmlspecialchars($o['transaction_ref'])?>">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-semibold text-slate-800"><?=htmlspecialchars($o['package_name'])?> (<?=htmlspecialchars($o['region'])?>)</p>
                        <p class="text-xs text-slate-500">₦<?=number_format((float)$o['amount_ngn'],2)?> — <?=htmlspecialchars($o['created_at'])?></p>
                    </div>
                    <span class="px-2 py-1 rounded-full text-[10px] font-bold uppercase <?= $o['status']==='success'?'text-emerald-600 bg-emerald-100':($o['status']==='processing'?'text-blue-600 bg-blue-100':($o['status']==='failed'?'text-rose-600 bg-rose-100':'text-slate-600 bg-slate-100')) ?>"><?=ucfirst($o['status'])?></span>
                </div>
                <?php if ($o['status']==='success' && $o['iccid']): ?>
                <div class="mt-2 text-xs text-slate-600">
                    <div>ICCID: <?=htmlspecialchars($o['iccid'])?></div>
                    <?php if ($o['qr_image']): ?><a href="<?=htmlspecialchars($o['qr_image'])?>" target="_blank" class="text-brand-orange font-semibold">View QR Code</a><?php endif; ?>
                </div>
                <?php elseif ($o['status']==='processing'): ?>
                <button type="button" onclick="refreshStatus('<?=htmlspecialchars($o['transaction_ref'])?>')" class="mt-2 text-xs text-blue-600 font-semibold underline">Refresh Status</button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
let currentPackages = [];

async function loadPackages() {
    const region = document.getElementById('regionSel').value;
    const wrap = document.getElementById('packagesWrap');
    document.getElementById('selPackageCode').value = '';
    document.getElementById('selApiRegistryId').value = '';
    if (!region) { wrap.innerHTML = ''; return; }

    wrap.innerHTML = '<div class="text-sm text-slate-400 py-4 text-center"><i class="fas fa-spinner fa-spin"></i> Loading packages...</div>';
    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('csrfF').value);
    fd.append('action', 'get_packages');
    fd.append('region', region);
    const r = await fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd }).then(r => r.json());
    currentPackages = r.packages || [];
    if (!currentPackages.length) { wrap.innerHTML = '<p class="text-sm text-slate-400 text-center py-4">No packages available for this region right now.</p>'; return; }

    let html = '<label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-2">Select Package</label><div class="space-y-2">';
    currentPackages.forEach((p, i) => {
        html += `<button type="button" class="w-full text-left border border-slate-200 rounded-xl p-3 hover:border-brand-orange transition-colors pkg-btn" data-idx="${i}" onclick="selectPackage(${i}, this)">
            <div class="font-semibold text-sm text-slate-800">${escapeHtml(p.package_name)}</div>
            <div class="text-xs text-slate-500">${escapeHtml(p.data_size)} · ${p.days} days · ₦${Number(p.ngn_price).toLocaleString('en-US')}</div>
        </button>`;
    });
    html += '</div>';
    wrap.innerHTML = html;
}

function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

function selectPackage(idx, btn) {
    document.querySelectorAll('.pkg-btn').forEach(b => b.classList.remove('border-brand-orange', 'bg-brand-orangeLight'));
    btn.classList.add('border-brand-orange', 'bg-brand-orangeLight');
    const p = currentPackages[idx];
    document.getElementById('selPackageCode').value = p.package_code;
    document.getElementById('selApiRegistryId').value = p.api_registry_id;
}

function buy() {
    const region = document.getElementById('regionSel').value;
    const email = document.getElementById('email').value;
    const packageCode = document.getElementById('selPackageCode').value;
    const apiRegistryId = document.getElementById('selApiRegistryId').value;

    if (!region) return Swal.fire('Notice', 'Select a region', 'info');
    if (!packageCode) return Swal.fire('Notice', 'Select a package', 'info');
    if (!email) return Swal.fire('Notice', 'Enter your email', 'info');

    confirmTransaction({
        "Region": region,
        "Email": email
    }, function(pin) {
        processEsim(region, packageCode, apiRegistryId, email, pin);
    });
}

function processEsim(region, packageCode, apiRegistryId, email, pin) {
    const btn = document.getElementById('buyBtn');
    const og = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    btn.disabled = true;

    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('csrfF').value);
    fd.append('region', region);
    fd.append('package_code', packageCode);
    fd.append('api_registry_id', apiRegistryId);
    fd.append('email', email);
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

async function refreshStatus(ref) {
    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('csrfF').value);
    fd.append('action', 'refresh_status');
    fd.append('ref', ref);
    const r = await fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd }).then(r => r.json());
    showAlert(r.message, r.success ? 'Notice' : 'Notice');
    if (r.ready) setTimeout(() => location.reload(), 1500);
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
