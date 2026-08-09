<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/cashback.php';

auth_user();
$uid = (int)$_SESSION['user_id'];

// Handle withdrawal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'withdraw') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        json_response(['success' => false, 'message' => 'Invalid token'], 403);
    }
    json_response(process_cashback_withdrawal($uid, $_POST));
}

$user = get_user($uid);
$cb_balance = (float)$user['cashback_balance'];
$history = get_cashback_history($uid);
$withdrawals = get_cashback_withdrawals($uid);

$page_title = 'Cashback';
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
                <h1 class="text-lg sm:text-xl font-bold text-slate-800">Cashback</h1>
                <p class="text-xs sm:text-sm text-slate-500">Earn cashback on every transaction you make</p>
            </div>
        </div>
        <div class="bg-brand-orangeLight text-brand-orangeHover px-2.5 sm:px-4 py-1.5 sm:py-2 rounded-xl font-bold border border-brand-orange/20 shadow-sm text-sm">
            ₦<?=number_format((float)$user['wallet_balance'],2)?>
        </div>
    </div>

    <!-- Cashback Balance Card -->
    <div class="bg-gradient-to-br from-pink-500 to-rose-600 rounded-2xl p-6 text-white card-shadow mb-4 sm:mb-6 relative overflow-hidden">
        <div class="absolute top-0 right-0 w-32 h-32 bg-white/10 rounded-full -mr-16 -mt-16 pointer-events-none"></div>
        <div class="absolute bottom-0 left-0 w-24 h-24 bg-white/10 rounded-full -ml-12 -mb-12 pointer-events-none"></div>
        <div class="relative z-10">
            <div class="flex items-center gap-2 mb-2">
                <i class="fas fa-gift text-lg"></i>
                <span class="text-sm font-medium text-white/80">Available Cashback Balance</span>
            </div>
            <p class="text-3xl sm:text-4xl font-black mb-4">₦<?=number_format($cb_balance,2)?></p>
            <?php if ($cb_balance >= 100): ?>
            <button onclick="openWithdrawModal()" class="bg-white text-rose-600 font-bold px-5 py-2.5 rounded-xl shadow-lg hover:bg-rose-50 transition-all inline-flex items-center gap-2 text-sm">
                <i class="fas fa-arrow-right"></i> Withdraw to Wallet
            </button>
            <?php else: ?>
            <p class="text-sm text-white/70">Minimum ₦100 required to withdraw</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Withdraw Modal -->
    <div id="withdrawModal" class="fixed inset-0 z-50 hidden bg-black/50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl w-full max-w-sm p-6 shadow-2xl relative">
            <button onclick="closeWithdrawModal()" class="absolute top-4 right-4 w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 hover:text-slate-600 hover:bg-slate-200 transition-colors">
                <i class="fas fa-times text-sm"></i>
            </button>
            <div class="text-center mb-5">
                <div class="w-12 h-12 bg-pink-100 rounded-full flex items-center justify-center mx-auto mb-3">
                    <i class="fas fa-gift text-pink-500 text-xl"></i>
                </div>
                <h3 class="text-lg font-bold text-slate-800">Withdraw Cashback</h3>
                <p class="text-sm text-slate-500 mt-1">Move cashback to your main wallet</p>
            </div>
            <p class="text-xs text-slate-500 mb-2 text-center">Available: <span class="font-bold text-pink-600">₦<?=number_format($cb_balance,2)?></span></p>
            <input type="number" id="withdrawAmount" class="w-full px-4 py-3 border border-slate-200 rounded-xl text-lg font-bold text-center focus:ring-2 focus:ring-pink-500 focus:border-pink-500 outline-none" placeholder="Enter amount" min="100" max="<?=$cb_balance?>" step="100">
            <div class="flex gap-2 mt-3 mb-4">
                <?php foreach ([1000, 2000, 5000, 10000] as $preset): if ($preset <= $cb_balance): ?>
                <button onclick="setAmount(<?=$preset?>)" class="flex-1 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs font-bold text-slate-600 hover:bg-pink-50 hover:border-pink-200 hover:text-pink-600 transition-colors">₦<?=number_format($preset)?></button>
                <?php endif; endforeach; ?>
            </div>
            <input type="password" id="withdrawPin" class="w-full px-4 py-3 border border-slate-200 rounded-xl text-center text-lg font-bold tracking-widest focus:ring-2 focus:ring-pink-500 focus:border-pink-500 outline-none mb-4" placeholder="Enter PIN" maxlength="4" inputmode="numeric">
            <button onclick="submitWithdraw()" class="w-full bg-gradient-to-r from-pink-500 to-rose-600 text-white font-bold py-3 rounded-xl hover:from-pink-600 hover:to-rose-700 transition-all shadow-lg shadow-pink-500/30 inline-flex items-center justify-center gap-2" id="withdrawBtn">
                <i class="fas fa-arrow-right"></i> Withdraw to Wallet
            </button>
        </div>
    </div>

    <!-- Cashback Earned History -->
    <div class="bg-white rounded-2xl border border-slate-200 card-shadow mb-4 sm:mb-6 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-2">
            <i class="fas fa-gift text-pink-500"></i>
            <h2 class="font-bold text-slate-800">Cashback Earned</h2>
            <span class="text-xs bg-pink-100 text-pink-600 font-bold px-2 py-0.5 rounded-full"><?=count($history)?></span>
        </div>
        <?php if (empty($history)): ?>
        <div class="p-8 text-center">
            <div class="w-14 h-14 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-3 text-slate-300">
                <i class="fas fa-gift text-2xl"></i>
            </div>
            <p class="text-sm text-slate-500">No cashback earned yet. Make transactions to earn cashback!</p>
        </div>
        <?php else: ?>
        <div class="divide-y divide-slate-100">
            <?php foreach ($history as $tx): ?>
            <div class="px-5 py-3.5 flex items-center justify-between hover:bg-slate-50 transition-colors">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 bg-pink-100 rounded-full flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-gift text-pink-500 text-xs"></i>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-slate-700"><?=htmlspecialchars($tx['description'])?></p>
                        <p class="text-xs text-slate-400"><?=date('M d, Y h:i A', strtotime($tx['created_at']))?></p>
                    </div>
                </div>
                <span class="font-bold text-emerald-600 text-sm">+₦<?=number_format((float)$tx['amount'],2)?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Withdrawal History -->
    <div class="bg-white rounded-2xl border border-slate-200 card-shadow overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-2">
            <i class="fas fa-arrow-right text-blue-500"></i>
            <h2 class="font-bold text-slate-800">Withdrawal History</h2>
            <span class="text-xs bg-blue-100 text-blue-600 font-bold px-2 py-0.5 rounded-full"><?=count($withdrawals)?></span>
        </div>
        <?php if (empty($withdrawals)): ?>
        <div class="p-8 text-center">
            <div class="w-14 h-14 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-3 text-slate-300">
                <i class="fas fa-arrow-right text-2xl"></i>
            </div>
            <p class="text-sm text-slate-500">No withdrawals yet</p>
        </div>
        <?php else: ?>
        <div class="divide-y divide-slate-100">
            <?php foreach ($withdrawals as $tx): ?>
            <div class="px-5 py-3.5 flex items-center justify-between hover:bg-slate-50 transition-colors">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 bg-blue-100 rounded-full flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-arrow-right text-blue-500 text-xs"></i>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-slate-700"><?=htmlspecialchars($tx['description'])?></p>
                        <p class="text-xs text-slate-400"><?=date('M d, Y h:i A', strtotime($tx['created_at']))?></p>
                    </div>
                </div>
                <span class="font-bold text-rose-500 text-sm">-₦<?=number_format((float)$tx['amount'],2)?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function openWithdrawModal() {
    document.getElementById('withdrawModal').classList.remove('hidden');
    document.getElementById('withdrawAmount').focus();
}

function closeWithdrawModal() {
    document.getElementById('withdrawModal').classList.add('hidden');
}

function setAmount(val) {
    document.getElementById('withdrawAmount').value = val;
}

async function submitWithdraw() {
    const amount = document.getElementById('withdrawAmount').value;
    const pin = document.getElementById('withdrawPin').value;

    if (!amount || parseFloat(amount) < 100) {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Minimum withdrawal is ₦100' });
        return;
    }
    if (parseFloat(amount) > <?=$cb_balance?>) {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Insufficient cashback balance' });
        return;
    }
    if (!pin || pin.length !== 4) {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Please enter your 4-digit PIN' });
        return;
    }

    const btn = document.getElementById('withdrawBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

    try {
        const res = await fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'withdraw',
                csrf_token: '<?=csrf_token()?>',
                amount: amount,
                pin: pin
            })
        });
        const data = await res.json();
        if (data.success) {
            Swal.fire({ icon: 'success', title: 'Success', text: data.message });
            setTimeout(() => location.reload(), 1500);
        } else {
            Swal.fire({ icon: 'error', title: 'Failed', text: data.message });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' });
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-arrow-right"></i> Withdraw to Wallet';
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
