<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/dashboard.php';
require_once __DIR__ . '/core/beneficiaries.php';
require_once __DIR__ . '/core/kyc.php'; 

auth_user(); 
$uid = (int) $_SESSION['user_id'];
$user = get_user($uid);
$data = get_dashboard_data($uid);
$vas = $data['virtual_accounts'];
$txs = $data['transactions'];
$nc = $data['notif_count'];
$name = explode(' ', $user['full_name'])[0];
$kyc_l = (int) $user['kyc_level'];
$benefs = get_beneficiaries($uid);
$slider = db()->query( "SELECT title, message FROM notifications WHERE user_id IS NULL ORDER BY created_at DESC LIMIT 5" )->fetchAll();
$csrf = csrf_token(); 
$cat_icons=['wallet_funding'=>'<i class="fas fa-wallet"></i>','airtime'=>'<i class="fas fa-mobile-alt"></i>','data'=>'<i class="fas fa-wifi"></i>','electricity'=>'<i class="fas fa-bolt"></i>','cabletv'=>'<i class="fas fa-tv"></i>','airtime_to_cash'=>'<i class="fas fa-exchange-alt"></i>','exam_pin'=>'<i class="fas fa-graduation-cap"></i>','recharge_card'=>'<i class="fas fa-print"></i>','data_card'=>'<i class="fas fa-credit-card"></i>','bank_transfer'=>'<i class="fas fa-university"></i>','cashback'=>'<i class="fas fa-gift"></i>','admin_credit'=>'<i class="fas fa-arrow-down"></i>','admin_debit'=>'<i class="fas fa-arrow-up"></i>'];
$cat_colors=['wallet_funding'=>'text-green-500 bg-green-100','airtime'=>'text-blue-500 bg-blue-100','data'=>'text-emerald-500 bg-emerald-100','electricity'=>'text-yellow-500 bg-yellow-100','cabletv'=>'text-purple-500 bg-purple-100','airtime_to_cash'=>'text-orange-500 bg-orange-100','exam_pin'=>'text-teal-500 bg-teal-100','recharge_card'=>'text-pink-500 bg-pink-100','data_card'=>'text-cyan-500 bg-cyan-100','bank_transfer'=>'text-indigo-500 bg-indigo-100','cashback'=>'text-amber-500 bg-amber-100','admin_credit'=>'text-green-500 bg-green-100','admin_debit'=>'text-red-500 bg-red-100'];

$page_title = 'Dashboard'; 
include __DIR__ . '/includes/header.php'; 
include __DIR__ . '/includes/sidebar.php'; 
?>

<!-- Welcome Section -->
<div class="mb-8">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-bold text-slate-800">Hello, <?= htmlspecialchars($name) ?> 👋</h2>
            <p class="text-slate-500 mt-1">Welcome back to MsjPay</p>
        </div>
    </div>
</div>

<!-- Balance Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
    <!-- Main Wallet -->
    <div class="bg-gradient-to-br from-brand-navy to-brand-navyDark rounded-2xl p-6 text-white card-shadow relative overflow-hidden">
        <div class="absolute top-0 right-0 w-32 h-32 bg-white/5 rounded-full -mr-16 -mt-16"></div>
        <div class="absolute bottom-0 left-0 w-24 h-24 bg-white/5 rounded-full -ml-12 -mb-12"></div>
        
        <div class="relative z-10 flex justify-between items-start mb-6">
            <div>
                <p class="text-blue-100 text-sm font-medium mb-1">Wallet Balance</p>
                <h3 class="text-3xl font-bold font-outfit">
                    N<span class="bal-amt" data-val="<?= number_format((float) $user['wallet_balance'], 2) ?>"><?= number_format((float) $user['wallet_balance'], 2) ?></span>
                </h3>
            </div>
            <button onclick="toggleBalance()" class="text-blue-200 hover:text-white transition-colors bg-white/10 p-2 rounded-lg backdrop-blur-sm">
                <i class="fas fa-eye" id="bal-eye-icon"></i>
            </button>
        </div>
        
        <div class="relative z-10 flex gap-3 mt-4">
            <a href="/msjpay/user/fund" class="flex-1 bg-brand-orange hover:bg-brand-orangeHover text-white py-2.5 rounded-xl font-medium text-center transition-colors text-sm shadow-lg shadow-orange-500/30">
                <i class="fas fa-plus mr-1.5"></i> Fund Wallet
            </a>
            <a href="/msjpay/user/bank-transfer" class="flex-1 bg-white/10 hover:bg-white/20 text-white py-2.5 rounded-xl font-medium text-center transition-colors text-sm backdrop-blur-sm border border-white/10">
                <i class="fas fa-paper-plane mr-1.5"></i> Transfer
            </a>
        </div>
    </div>
    
    
    <!-- Virtual Accounts -->
    <div class="bg-white rounded-2xl p-6 border border-slate-200 card-shadow flex flex-col">
        <div class="flex justify-between items-center mb-4">
            <h3 class="font-bold text-slate-800">Bank Accounts</h3>
            <span class="text-xs text-slate-500">Auto-funding</span>
        </div>
        
        <div class="flex-1 overflow-y-auto pr-2 custom-scrollbar" style="max-height: 140px;">
            <?php if (!empty($vas)): ?>
                <div class="space-y-3">
                <?php foreach ($vas as $va): ?>
                    <div class="bg-slate-50 border border-slate-100 rounded-xl p-3 hover:bg-brand-orangeLight hover:border-brand-orange/30 transition-colors cursor-pointer group" onclick="copyText('<?= htmlspecialchars($va['account_number']) ?>', event)">
                        <div class="flex justify-between items-start mb-1">
                            <span class="text-xs font-bold text-brand-navy uppercase"><?= htmlspecialchars($va['bank_name']) ?></span>
                            <span class="text-[10px] text-brand-orange opacity-0 group-hover:opacity-100 transition-opacity"><i class="fas fa-copy"></i></span>
                        </div>
                        <div class="text-lg font-bold text-slate-800 tracking-wider mb-0.5"><?= htmlspecialchars($va['account_number']) ?></div>
                        <div class="text-xs text-slate-500 truncate"><?= htmlspecialchars($va['account_name']) ?></div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="h-full flex flex-col items-center justify-center text-center p-4 border-2 border-dashed border-slate-200 rounded-xl cursor-pointer hover:bg-slate-50 hover:border-brand-orange/50 transition-colors" onclick="createVA()">
                    <div class="w-10 h-10 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mb-2">
                        <i class="fas fa-building-columns"></i>
                    </div>
                    <p class="text-sm font-semibold text-slate-700">Get Account Number</p>
                    <p class="text-xs text-slate-500 mt-1">Tap to generate virtual account for auto-funding</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!empty($slider)): ?>
<!-- Notification Marquee -->
<div class="bg-brand-orangeLight border border-brand-orange/20 rounded-xl p-3 mb-8 flex items-center gap-3">
    <div class="text-brand-orange flex-shrink-0 animate-pulse">
        <i class="fas fa-bullhorn"></i>
    </div>
    <div class="overflow-hidden relative w-full">
        <div class="whitespace-nowrap inline-block animate-[marquee_20s_linear_infinite] text-sm text-brand-orangeHover font-medium">
            <?php foreach ($slider as $n): ?>
                <span class="mx-8">• <?= htmlspecialchars($n['title']) ?>: <?= htmlspecialchars($n['message']) ?></span>
            <?php endforeach; ?>
        </div>
        <style>
            @keyframes marquee { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }
        </style>
    </div>
</div>
<?php endif; ?>

<!-- Services Grid -->
<div class="mb-8">
    <h3 class="text-lg font-bold text-slate-800 mb-4">Quick Services</h3>
    <div class="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-5 xl:grid-cols-8 gap-3 sm:gap-4">
        <?php 
        $dashboard_services = json_decode(setting('dashboard_services', '[]'), true) ?: [];
        foreach ($dashboard_services as $svc): 
            if (empty($svc['active'])) continue;
        ?>
        <a href="/msjpay/user/<?= htmlspecialchars($svc['link']) ?>" class="flex flex-col items-center justify-center p-4 bg-white rounded-2xl border border-slate-100 card-shadow <?= htmlspecialchars($svc['hover_border']) ?> transition-all hover:-translate-y-1 group">
            <div class="w-12 h-12 rounded-full <?= htmlspecialchars($svc['icon_bg']) ?> <?= htmlspecialchars($svc['icon_color']) ?> flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                <i class="fa-solid <?= htmlspecialchars($svc['icon']) ?> text-xl"></i>
            </div>
            <span class="text-xs sm:text-sm font-semibold text-slate-700 text-center whitespace-nowrap"><?= htmlspecialchars($svc['label']) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<?php 
$dashboard_banners = json_decode(setting('dashboard_banners', '[]'), true) ?: [];
$active_banners = array_filter($dashboard_banners, fn($b) => !empty($b['active']));
$active_banners = array_values($active_banners);
if (!empty($active_banners)): 
?>
<!-- Promo Banners Carousel -->
<div class="mb-8 rounded-2xl overflow-hidden relative card-shadow h-[220px] sm:h-40 group">
    <?php foreach ($active_banners as $index => $bn): ?>
    <!-- Banner <?= $index + 1 ?> -->
    <div class="promo-slide absolute inset-0 transition-opacity duration-1000 ease-in-out <?= $index === 0 ? 'opacity-100 z-10' : 'opacity-0 z-0' ?>">
        <div class="absolute inset-0 bg-gradient-to-r <?= htmlspecialchars($bn['bg_gradient']) ?> opacity-90"></div>
        <div class="relative p-6 sm:p-8 flex items-center justify-between z-20 h-full">
            <div>
                <span class="inline-block px-3 py-1 bg-white/20 text-white rounded-full text-xs font-bold mb-3 backdrop-blur-sm"><?= htmlspecialchars($bn['tag']) ?></span>
                <h3 class="text-xl sm:text-2xl font-black text-white mb-2"><?= htmlspecialchars($bn['title']) ?></h3>
                <p class="text-white/80 text-sm font-medium max-w-md"><?= htmlspecialchars($bn['subtitle']) ?></p>
            </div>
            <div class="hidden sm:flex w-20 h-20 bg-white/10 rounded-full items-center justify-center backdrop-blur-sm border border-white/20 shrink-0">
                <i class="<?= htmlspecialchars($bn['icon']) ?> text-3xl text-white"></i>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    
    <!-- Slide Indicators -->
    <?php if (count($active_banners) > 1): ?>
    <div class="absolute bottom-3 left-0 right-0 flex justify-center gap-2 z-30">
        <?php foreach ($active_banners as $index => $bn): ?>
        <div class="slide-dot w-2 h-2 rounded-full bg-white <?= $index === 0 ? 'opacity-100' : 'opacity-50' ?> transition-opacity"></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 gap-8">
    <!-- Transactions -->
    <div class="w-full">
        <div class="bg-white rounded-2xl border border-slate-200 card-shadow overflow-hidden h-full">
            <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                <h3 class="font-bold text-slate-800">Recent Transactions</h3>
                <a href="/msjpay/user/transactions" class="text-sm font-medium text-brand-orange hover:text-brand-orangeHover">View All</a>
            </div>
            
            <div class="p-0">
                <?php if (empty($txs)): ?>
                <div class="p-8 text-center text-slate-500">
                    <div class="w-16 h-16 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-3 text-slate-400">
                        <i class="fas fa-receipt text-2xl"></i>
                    </div>
                    <p class="font-medium text-slate-600">No transactions yet</p>
                    <p class="text-sm mt-1">Your recent activity will appear here</p>
                </div>
                <?php else: ?>
                <div class="divide-y divide-slate-100">
                    <?php foreach ($txs as $tx): 
                        $ic = $cat_icons[$tx['category']] ?? '<i class="fas fa-wallet"></i>'; 
                        $cc = $cat_colors[$tx['category']] ?? 'text-slate-500 bg-slate-100';
                        $cr = $tx['type'] === 'credit'; 
                        
                        $scls = '';
                        $stxt = '';
                        switch($tx['status']) {
                            case 'success': $scls = 'text-emerald-600 bg-emerald-100'; $stxt = 'Success'; break;
                            case 'pending': $scls = 'text-amber-600 bg-amber-100'; $stxt = 'Pending'; break;
                            case 'failed': $scls = 'text-rose-600 bg-rose-100'; $stxt = 'Failed'; break;
                            case 'reversed': $scls = 'text-slate-600 bg-slate-200'; $stxt = 'Reversed'; break;
                            default: $scls = 'text-slate-600 bg-slate-100'; $stxt = ucfirst($tx['status']);
                        }
                    ?>
                    <a href="/msjpay/user/receipt?ref=<?= urlencode($tx['transaction_ref']) ?>" class="flex items-center p-4 hover:bg-slate-50 transition-colors block">
                        <div class="w-10 h-10 rounded-full <?= $cc ?> flex items-center justify-center flex-shrink-0">
                            <?= $ic ?>
                        </div>
                        <div class="ml-4 flex-1 min-w-0">
                            <p class="text-sm font-semibold text-slate-800 truncate"><?= htmlspecialchars($tx['description'] ?? '') ?></p>
                            <p class="text-xs text-slate-500 mt-0.5"><?= date('d M Y, g:i A', strtotime($tx['created_at'])) ?></p>
                        </div>
                        <div class="ml-4 text-right">
                            <p class="text-sm font-bold <?= $cr ? 'text-emerald-600' : 'text-slate-800' ?>">
                                <?= $cr ? '+' : '-' ?>N<?= number_format((float) $tx['amount'], 2) ?>
                            </p>
                            <span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold mt-1 uppercase <?= $scls ?>"><?= $stxt ?></span>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
/* Custom scrollbar for small areas like virtual accounts */
.custom-scrollbar::-webkit-scrollbar { width: 4px; }
.custom-scrollbar::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
</style>

<script>
// Banner Carousel Logic
document.addEventListener('DOMContentLoaded', () => {
    let currentSlide = 0;
    const slides = document.querySelectorAll('.promo-slide');
    const dots = document.querySelectorAll('.slide-dot');

    if (slides.length > 0) {
        setInterval(() => {
            slides[currentSlide].classList.replace('opacity-100', 'opacity-0');
            slides[currentSlide].classList.replace('z-10', 'z-0');
            dots[currentSlide].classList.replace('opacity-100', 'opacity-50');
            
            currentSlide = (currentSlide + 1) % slides.length;
            
            slides[currentSlide].classList.replace('opacity-0', 'opacity-100');
            slides[currentSlide].classList.replace('z-0', 'z-10');
            dots[currentSlide].classList.replace('opacity-50', 'opacity-100');
        }, 5000);
    }
});

// Mock function for VA creation if it wasn't defined elsewhere
if (typeof createVA !== 'function') {
    window.createVA = function() {
        if(confirm("Generate a new virtual account for auto-funding?")) {
            // In a real app, this would be an AJAX call
            window.location.href = "/msjpay/user/virtual-account-generate";
        }
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>