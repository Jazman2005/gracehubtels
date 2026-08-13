<?php
require_once __DIR__.'/core/auth.php';
auth_user();
$uid = (int)$_SESSION['user_id'];
$user = get_user($uid);

$base_url = rtrim(site_url(), '/');
$ref_code = $user['referral_code'] ?: $user['phone'];
$ref_link = $base_url . "/user/signup?ref=" . urlencode($ref_code);

// Fetch stats
$tr = db()->prepare("SELECT COUNT(*) FROM users WHERE referred_by = ?"); $tr->execute([$uid]); $total_referred = $tr->fetchColumn() ?: 0;
$te = db()->prepare("SELECT SUM(reward_amount) FROM referral_rewards WHERE referrer_id = ? AND status = 'paid'"); $te->execute([$uid]); $total_earned = $te->fetchColumn() ?: 0;
$pe = db()->prepare("SELECT SUM(reward_amount) FROM referral_rewards WHERE referrer_id = ? AND status = 'pending'"); $pe->execute([$uid]); $pending_earned = $pe->fetchColumn() ?: 0;

$bonus_amt = (float)setting('referral_bonus', '0');

// Fetch referred users list
$ru = db()->prepare("
    SELECT u.full_name, u.created_at, 
           (SELECT status FROM referral_rewards WHERE referred_id = u.id AND referrer_id = ? LIMIT 1) as bonus_status
    FROM users u 
    WHERE u.referred_by = ? 
    ORDER BY u.id DESC 
    LIMIT 50
"); $ru->execute([$uid, $uid]); $referred_users = $ru->fetchAll();

$page_title = 'Referrals & Earnings'; 
include __DIR__ . '/includes/header.php'; 
include __DIR__ . '/includes/sidebar.php'; 
?>

<div class="max-w-4xl mx-auto">
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-4">
            <a href="/user/dashboard" class="w-10 h-10 rounded-full bg-white border border-slate-200 flex items-center justify-center text-slate-600 hover:bg-slate-50 transition-colors">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-slate-800">Refer & Earn</h1>
                <p class="text-sm text-slate-500">Invite friends and earn bonuses</p>
            </div>
        </div>
        <div class="bg-brand-orangeLight text-brand-orangeHover px-4 py-2 rounded-xl font-bold border border-brand-orange/20 shadow-sm">
            ₦<?=number_format((float)$user['wallet_balance'],2)?>
        </div>
    </div>

    <!-- Referral Link Card -->
    <div class="bg-gradient-to-br from-brand-navy to-brand-navyDark rounded-2xl p-6 md:p-8 text-white shadow-xl mb-6 relative overflow-hidden">
        <div class="absolute top-0 right-0 w-64 h-64 bg-white/5 rounded-full -translate-y-1/2 translate-x-1/3"></div>
        <div class="relative z-10">
            <div class="flex flex-col md:flex-row gap-6 items-center justify-between">
                <div>
                    <h2 class="text-2xl font-bold mb-2">Share the <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Experience</h2>
                    <p class="text-blue-100 max-w-md">
                        Earn <span class="font-bold text-white bg-white/20 px-2 py-0.5 rounded">₦<?=number_format($bonus_amt, 2)?></span> for every friend who signs up with your link and funds their wallet.
                    </p>
                </div>
                <div class="w-full md:w-auto flex flex-col gap-2">
                    <label class="text-xs text-blue-200 font-semibold uppercase tracking-wider">Your Referral Link</label>
                    <div class="flex bg-white/10 rounded-xl overflow-hidden border border-white/20 backdrop-blur-md max-w-full">
                        <input type="text" id="refLink" value="<?=htmlspecialchars($ref_link, ENT_QUOTES)?>" class="bg-transparent text-white px-4 py-3 outline-none w-full md:w-64 text-sm font-medium" readonly>
                        <button onclick="copyRefLink()" class="bg-brand-orange hover:bg-brand-orangeHover px-5 py-3 font-bold transition-colors flex items-center gap-2">
                            <i class="fas fa-copy"></i> Copy
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
        <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-blue-50 text-blue-500 flex items-center justify-center text-xl">
                <i class="fas fa-users"></i>
            </div>
            <div>
                <p class="text-sm text-slate-500 font-semibold">Total Invited</p>
                <h3 class="text-2xl font-bold text-slate-800"><?=$total_referred?></h3>
            </div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-500 flex items-center justify-center text-xl">
                <i class="fas fa-money-bill-wave"></i>
            </div>
            <div>
                <p class="text-sm text-slate-500 font-semibold">Total Earned</p>
                <h3 class="text-2xl font-bold text-slate-800">₦<?=number_format($total_earned, 2)?></h3>
            </div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-amber-50 text-amber-500 flex items-center justify-center text-xl">
                <i class="fas fa-clock"></i>
            </div>
            <div>
                <p class="text-sm text-slate-500 font-semibold">Pending Bonus</p>
                <h3 class="text-2xl font-bold text-slate-800">₦<?=number_format($pending_earned, 2)?></h3>
            </div>
        </div>
    </div>

    <!-- Referred Users Table -->
    <div class="bg-white rounded-2xl border border-slate-200 card-shadow overflow-hidden">
        <div class="p-5 border-b border-slate-200">
            <h3 class="font-bold text-slate-800">My Referrals</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wider">
                        <th class="p-4 font-semibold">User</th>
                        <th class="p-4 font-semibold">Joined Date</th>
                        <th class="p-4 font-semibold">Bonus Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-sm">
                    <?php if(empty($referred_users)): ?>
                    <tr>
                        <td colspan="3" class="p-8 text-center text-slate-500">
                            <div class="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center text-slate-300 mx-auto mb-3 text-2xl">
                                <i class="fas fa-user-plus"></i>
                            </div>
                            <p>You haven't referred anyone yet.</p>
                            <p class="text-xs mt-1">Share your link to start earning!</p>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach($referred_users as $ru): ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="p-4 font-medium text-slate-800">
                                <?=htmlspecialchars($ru['full_name'])?>
                            </td>
                            <td class="p-4 text-slate-500">
                                <?=date('M j, Y', strtotime($ru['created_at']))?>
                            </td>
                            <td class="p-4">
                                <?php if($ru['bonus_status'] === 'paid'): ?>
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold bg-emerald-100 text-emerald-700">Paid</span>
                                <?php elseif($ru['bonus_status'] === 'pending'): ?>
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold bg-amber-100 text-amber-700">Pending</span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold bg-slate-100 text-slate-600">Awaiting Funding</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function copyRefLink() {
    const linkInput = document.getElementById('refLink');
    linkInput.select();
    linkInput.setSelectionRange(0, 99999); 
    document.execCommand("copy");
    
    // Optional: show a quick toast or alert
    Swal.fire('Notice', "Referral link copied to clipboard!", 'info');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
