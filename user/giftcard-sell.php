<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/giftcard.php';
auth_user();
require_feature_user('giftcard_sell');
$uid = (int)$_SESSION['user_id'];
$user = get_user($uid);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) json_response(['success' => false, 'message' => 'Invalid token'], 403);
    if (!rate_limit('gc_sell_'.$uid, 5, 60)) json_response(['success' => false, 'message' => 'Too many requests.'], 429);
    json_response(process_giftcard_sell_submission($uid, $_POST));
}

$csrf = csrf_token();
$brands = get_giftcard_sell_brands();
$submissions = get_giftcard_sell_submissions($uid);

$page_title = 'Sell Gift Cards';
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
                <h1 class="text-lg sm:text-xl font-bold text-slate-800">Sell Gift Cards</h1>
                <p class="text-xs sm:text-sm text-slate-500">Trade your gift card for cash</p>
            </div>
        </div>
        <div class="bg-brand-orangeLight text-brand-orangeHover px-2.5 sm:px-4 py-1.5 sm:py-2 rounded-xl font-bold border border-brand-orange/20 shadow-sm text-sm">
            ₦<?=number_format((float)$user['wallet_balance'],2)?>
        </div>
    </div>

    <div class="bg-blue-50 border border-blue-200 text-blue-800 text-sm rounded-xl p-4 mb-4 sm:mb-6 flex items-start gap-3">
        <i class="fas fa-circle-info mt-0.5"></i>
        <span>Submissions are reviewed manually before payout — you'll be notified once yours is processed, usually within a few hours.</span>
    </div>

    <div class="bg-white rounded-xl sm:rounded-2xl border border-slate-200 card-shadow p-4 sm:p-5 mb-4 sm:mb-6">

        <?php if (empty($brands)): ?>
        <p class="text-sm text-slate-400 text-center py-6">No gift card brands are being accepted yet.</p>
        <?php else: ?>
        <div class="mb-4 sm:mb-5">
            <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5 sm:mb-2">Brand</label>
            <div class="relative">
                <select class="block w-full px-3 sm:px-4 py-2.5 sm:py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium appearance-none text-sm" id="brandSel" onchange="updRate()">
                    <?php foreach ($brands as $b): ?>
                    <option value="<?=htmlspecialchars($b['network'])?>" data-rate="<?=$b['discount_percent']?>"><?=htmlspecialchars($b['network'])?> — <?=$b['discount_percent']?>% payout</option>
                    <?php endforeach; ?>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 sm:pr-4 text-slate-500">
                    <i class="fas fa-chevron-down text-xs"></i>
                </div>
            </div>
        </div>

        <div class="mb-4 sm:mb-5">
            <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5 sm:mb-2">Card Value (₦ equivalent)</label>
            <input type="number" id="cardValue" oninput="updRate()" class="w-full px-3 sm:px-4 py-2.5 sm:py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium text-sm" placeholder="e.g. 20000">
        </div>

        <div class="mb-4 sm:mb-5">
            <label class="block text-xs sm:text-sm font-semibold text-slate-700 mb-1.5 sm:mb-2">Card Code</label>
            <textarea id="cardCode" rows="2" class="w-full px-3 sm:px-4 py-2.5 sm:py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium text-sm" placeholder="Enter the card's code/pin"></textarea>
        </div>

        <div id="rateBox" class="hidden mb-4 sm:mb-5 p-3 sm:p-4 bg-brand-orangeLight border border-brand-orange/30 rounded-xl flex justify-between items-center">
            <span class="text-xs sm:text-sm font-semibold text-brand-orangeHover">You'll receive (est.)</span>
            <span class="text-lg sm:text-xl font-black text-brand-orangeHover" id="rateOut"></span>
        </div>

        <input type="hidden" id="csrfF" value="<?= $csrf ?>">

        <button type="button" onclick="submitCard()" class="w-full bg-gradient-to-r from-brand-orange to-brand-orangeHover hover:from-brand-orangeHover hover:to-orange-600 text-white font-bold text-sm sm:text-base py-3 sm:py-3.5 rounded-xl shadow-lg shadow-orange-500/30 transition-all hover:-translate-y-0.5 flex items-center justify-center gap-2">
            Submit Card <i class="fas fa-arrow-right"></i>
        </button>
        <?php endif; ?>
    </div>

    <?php if (!empty($submissions)): ?>
    <div class="bg-white rounded-xl sm:rounded-2xl border border-slate-200 card-shadow p-4 sm:p-5">
        <h2 class="text-sm font-bold text-slate-700 mb-3">Recent Submissions</h2>
        <div class="space-y-2">
            <?php foreach ($submissions as $s):
                $badge = $s['status']==='approved' ? 'bg-green-100 text-green-700' : ($s['status']==='rejected' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700');
            ?>
            <div class="flex items-center justify-between py-2 border-b border-slate-50 last:border-0">
                <div>
                    <div class="text-sm font-semibold text-slate-800"><?=htmlspecialchars($s['brand'])?> — ₦<?=number_format($s['card_value'],2)?></div>
                    <div class="text-xs text-slate-400"><?=date('d M Y, H:i',strtotime($s['created_at']))?><?php if($s['status']==='rejected' && $s['admin_note']):?> — <?=htmlspecialchars($s['admin_note'])?><?php endif;?></div>
                </div>
                <span class="text-xs font-bold px-2.5 py-1 rounded-full <?=$badge?>"><?=strtoupper($s['status'])?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function updRate() {
    const sel = document.getElementById('brandSel');
    const rate = parseFloat(sel.options[sel.selectedIndex]?.getAttribute('data-rate')) || 0;
    const val = parseFloat(document.getElementById('cardValue').value) || 0;
    const box = document.getElementById('rateBox');
    if (val > 0 && rate > 0) {
        document.getElementById('rateOut').innerText = '₦' + (val * rate / 100).toLocaleString();
        box.classList.remove('hidden');
    } else {
        box.classList.add('hidden');
    }
}
function submitCard() {
    const brand = document.getElementById('brandSel').value;
    const value = document.getElementById('cardValue').value;
    const code = document.getElementById('cardCode').value;
    if (!value) return Swal.fire('Notice', 'Enter the card value', 'info');
    if (!code) return Swal.fire('Notice', 'Enter the card code', 'info');
    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('csrfF').value);
    fd.append('brand', brand);
    fd.append('card_value', value);
    fd.append('card_code', code);
    fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(r => r.json())
        .then(res => {
            Swal.fire(res.success ? 'Success' : 'Notice', res.message, res.success ? 'success' : 'info')
                .then(() => { if (res.success) window.location.reload(); });
        })
        .catch(err => Swal.fire('Server Error', String(err), 'error'));
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
