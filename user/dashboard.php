<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/dashboard.php';
require_once __DIR__ . '/core/beneficiaries.php';
auth_user(); 
$uid = (int) $_SESSION['user_id'];

// Handle AJAX PIN Submission directly on Dashboard
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_pin') {
    require_once __DIR__ . '/core/profile.php';
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { 
        json_response(['success' => false, 'message' => 'Invalid token'], 403); 
    }
    json_response(set_transaction_pin($uid, $_POST));
}

$user = get_user($uid);
$data = get_dashboard_data($uid);
$vas = $data['virtual_accounts'];
$txs = $data['transactions'];
$nc = $data['notif_count'];
$name = explode(' ', $user['full_name'])[0];
$benefs = get_beneficiaries($uid);
// Find PalmPay account for dashboard display
$palmpay_acct = null;
foreach ($vas as $va) {
    if (stripos($va['bank_name'], 'palm') !== false || $va['provider'] === 'palmpay') {
        $palmpay_acct = $va;
        break;
    }
}
try {
    $slider = db()->query("SELECT title, message FROM notifications WHERE user_id IS NULL AND display_type = 'marquee' AND is_active = 1 ORDER BY created_at DESC LIMIT 5")->fetchAll();
} catch (Exception $e) {
    $slider = [];
    error_log("MARQUEE_QUERY_ERROR: " . $e->getMessage());
}

try {
    $modal_stmt = db()->query("SELECT title, message, type FROM notifications WHERE user_id IS NULL AND display_type = 'modal' AND is_active = 1 ORDER BY created_at DESC LIMIT 1");
    $modal_ann = $modal_stmt->fetch();
} catch (Exception $e) {
    $modal_ann = null;
    error_log("MODAL_QUERY_ERROR: " . $e->getMessage());
}

$csrf = csrf_token(); 
$cat_icons=['wallet_funding'=>'<i class="fas fa-wallet"></i>','airtime'=>'<i class="fas fa-mobile-alt"></i>','data'=>'<i class="fas fa-wifi"></i>','electricity'=>'<i class="fas fa-bolt"></i>','cabletv'=>'<i class="fas fa-tv"></i>','airtime_to_cash'=>'<i class="fas fa-exchange-alt"></i>','exam_pin'=>'<i class="fas fa-graduation-cap"></i>','recharge_card'=>'<i class="fas fa-print"></i>','data_card'=>'<i class="fas fa-credit-card"></i>','bank_transfer'=>'<i class="fas fa-university"></i>','cashback'=>'<i class="fas fa-gift"></i>','admin_credit'=>'<i class="fas fa-arrow-down"></i>','admin_debit'=>'<i class="fas fa-arrow-up"></i>'];
$cat_colors=['wallet_funding'=>'text-green-500 bg-green-100','airtime'=>'text-blue-500 bg-blue-100','data'=>'text-emerald-500 bg-emerald-100','electricity'=>'text-yellow-500 bg-yellow-100','cabletv'=>'text-purple-500 bg-purple-100','airtime_to_cash'=>'text-orange-500 bg-orange-100','exam_pin'=>'text-teal-500 bg-teal-100','recharge_card'=>'text-pink-500 bg-pink-100','data_card'=>'text-cyan-500 bg-cyan-100','bank_transfer'=>'text-indigo-500 bg-indigo-100','cashback'=>'text-amber-500 bg-amber-100','admin_credit'=>'text-green-500 bg-green-100','admin_debit'=>'text-red-500 bg-red-100'];

$page_title = 'Dashboard';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<style>
    /* The account number/name card uses a raw bg-[#F8F9FD] (near-white) —
       header.php's dark-mode overrides only swap .bg-white/.bg-slate-50, so
       this card's background never switched to dark. Its text still got
       swapped to light colors (text-slate-800/text-slate-500 are covered),
       leaving light text on a background that stayed light too. */
    html.dark-mode .acct-card { background-color:#1e293b !important; border-color:#334155 !important; }
</style>

<!-- Welcome Section -->
<div class="mb-3 sm:mb-6">
    <div class="flex items-center justify-between">
        <div>
            <div class="flex items-center gap-2">
                <h2 class="text-base sm:text-lg font-bold text-slate-800">Hello, <?= htmlspecialchars($name) ?> 👋</h2>
                <?php 
                    $u_type = strtolower($user['user_type'] ?? 'subscriber');
                    if ($u_type === 'vendor') {
                        $u_label = 'API';
                        $u_bg = 'bg-purple-100 text-purple-700 border-purple-200';
                    } elseif ($u_type === 'agent') {
                        $u_label = 'AGENT';
                        $u_bg = 'bg-blue-100 text-blue-700 border-blue-200';
                    } else {
                        $u_label = 'USER';
                        $u_bg = 'bg-slate-100 text-slate-600 border-slate-200';
                    }
                ?>
                <span class="<?= $u_bg ?> border text-[9px] font-bold px-2 py-0.5 rounded uppercase tracking-wider shadow-sm"><?= $u_label ?></span>
            </div>
            <p class="text-[10px] sm:text-xs text-slate-500 mt-0.5">Welcome back to <?=htmlspecialchars(setting('site_name','Gracehubtels'))?></p>
        </div>
        <div class="flex items-center gap-3">
            <button id="darkModeToggle" class="text-slate-500 hover:text-brand-orange transition-colors w-8 h-8 rounded-full flex items-center justify-center hover:bg-slate-100" onclick="toggleTheme()">
                <i class="fa-solid fa-moon text-lg" id="themeIcon"></i>
            </button>
            <a href="/user/notifications" class="text-slate-500 hover:text-brand-orange transition-colors relative w-8 h-8 rounded-full flex items-center justify-center hover:bg-slate-100">
                <i class="fa-regular fa-bell text-lg"></i>
                <?php if(isset($nc) && $nc > 0): ?>
                <span class="absolute top-0 right-0 bg-red-500 text-white text-[9px] font-bold px-1.5 py-0.5 rounded-full"><?= min($nc, 99) ?></span>
                <?php endif; ?>
            </a>
            
            <a href="/user/profile" class="flex items-center gap-2 pl-3 border-l border-slate-200">
                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-brand-navy to-brand-orange flex items-center justify-center text-white font-bold text-xs shadow-sm">
                    <?= isset($name) ? strtoupper(substr($name, 0, 1)) : 'U' ?>
                </div>
            </a>
        </div>
    </div>
</div>

<!-- Main Wallet + PalmPay Account -->
<div class="bg-white rounded-[1.25rem] p-4 sm:p-5 card-shadow relative overflow-hidden mb-4 sm:mb-6 border border-slate-100/80">
    <!-- Wavy Decoration on the Right -->
    <div class="absolute bottom-0 right-0 w-[180px] sm:w-[250px] h-[150px] sm:h-[200px] pointer-events-none" style="z-index: 1;">
        <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="w-full h-full opacity-95">
            <!-- Yellow/Orange/Pink waves -->
            <path d="M20 100 Q 60 70, 100 40 L 100 100 Z" fill="#FCA608" />
            <path d="M40 100 Q 80 50, 100 10 L 100 100 Z" fill="#F36D16" />
            <path d="M60 100 C 80 70, 90 40, 100 0 L 100 100 Z" fill="#E50A6E" />
        </svg>
    </div>
    
    <div class="relative z-10">
        <div class="flex justify-between items-start mb-3">
            <div>
                <p class="text-slate-500 text-[11px] sm:text-xs font-medium mb-1">Wallet Balance</p>
                <h3 class="text-2xl sm:text-3xl font-bold font-outfit text-[#061D8F]">
                    ₦<span class="bal-amt" data-val="<?= number_format((float) $user['wallet_balance'], 2) ?>"><?= number_format((float) $user['wallet_balance'], 2) ?></span>
                </h3>
            </div>
            <div class="flex items-center gap-2">
                <span class="bg-[#061D8F]/5 text-[#061D8F] text-[10px] sm:text-[11px] font-bold px-2 py-1 rounded-md bal-amt" data-val="$<?= number_format((float)($data['usd_balance'] ?? 0), 2) ?>">$<?= number_format((float)($data['usd_balance'] ?? 0), 2) ?></span>
                <button onclick="toggleBalance()" class="text-[#061D8F] hover:bg-[#061D8F]/10 transition-colors bg-[#061D8F]/5 p-1.5 sm:p-2 rounded-lg">
                    <i class="fas fa-eye text-xs sm:text-sm" id="bal-eye-icon"></i>
                </button>
            </div>
        </div>
        
        <?php if ($palmpay_acct): ?>
        <div class="acct-card bg-[#F8F9FD] rounded-xl p-2.5 sm:p-3 border border-[#E9EEF7] mb-4 shadow-[0_2px_10px_-4px_rgba(0,0,0,0.05)] w-[85%] sm:max-w-md relative z-20 overflow-hidden">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[9px] sm:text-[10px] text-[#061D8F] font-bold uppercase tracking-widest mb-0.5"><?= htmlspecialchars($palmpay_acct['bank_name']) ?> ACCOUNT</p>
                    <p class="text-lg sm:text-xl font-bold text-slate-800 font-mono tracking-wider"><?= htmlspecialchars($palmpay_acct['account_number']) ?></p>
                    <p class="text-[10px] sm:text-xs text-slate-500 truncate max-w-[150px] sm:max-w-[220px]"><?= htmlspecialchars($palmpay_acct['account_name']) ?></p>
                </div>
                <button onclick="copyText('<?= htmlspecialchars($palmpay_acct['account_number']) ?>', event)" class="bg-white hover:bg-slate-50 text-[#061D8F] w-8 h-8 sm:w-10 sm:h-10 rounded-xl flex items-center justify-center transition-colors shadow-sm border border-slate-100 flex-shrink-0" title="Copy account number">
                    <i class="fas fa-copy text-sm sm:text-base"></i>
                </button>
            </div>
        </div>
        <?php else: ?>
        <div class="h-4"></div> <!-- Spacing if no palmpay account -->
        <?php endif; ?>
        
        <div class="flex gap-2 sm:gap-3 relative z-20">
            <a href="/user/fund" class="flex-1 bg-[#E50A6E] hover:bg-[#D40864] text-white py-2 sm:py-2.5 rounded-xl sm:rounded-[0.85rem] font-medium text-center transition-colors text-[13px] sm:text-sm shadow-lg shadow-[#E50A6E]/30 flex items-center justify-center">
                <i class="fas fa-plus mr-1.5"></i> Fund Wallet
            </a>
            <a href="/user/internal-transfer" class="flex-1 bg-white hover:bg-slate-50 text-[#061D8F] py-2 sm:py-2.5 rounded-xl sm:rounded-[0.85rem] font-medium text-center transition-colors text-[13px] sm:text-sm shadow-[0_2px_10px_-4px_rgba(0,0,0,0.05)] border border-[#E9EEF7] flex items-center justify-center">
                <i class="fas fa-paper-plane mr-1.5"></i> Send Money
            </a>
        </div>
    </div>
</div>

<!-- MARQUEE_DEBUG: slider_count=<?= count($slider) ?> -->
<?php if (!empty($slider)): ?>
<!-- Notification Marquee -->
<div class="bg-[#FFF0F4] border border-[#FEE2E2] rounded-xl p-2 sm:p-3 mb-4 sm:mb-8 flex items-center gap-2 sm:gap-3">
    <div class="text-[#E50A6E] flex-shrink-0 animate-pulse text-sm sm:text-base">
        <i class="fas fa-bullhorn"></i>
    </div>
    <div class="overflow-hidden relative w-full">
        <div class="whitespace-nowrap inline-block marquee-run text-xs sm:text-sm text-slate-800 font-medium">
            <?php foreach ($slider as $n): ?>
                <span class="mx-8">• <?= htmlspecialchars($n['title']) ?>: <span class="text-[#E50A6E]"><?= htmlspecialchars($n['message']) ?></span></span>
            <?php endforeach; ?>
        </div>
        <style>
            @keyframes marquee { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }
            .marquee-run { animation: marquee 20s linear infinite; }
        </style>
    </div>
</div>
<?php else: ?>
<div style="display:none"><!-- MARQUEE_EMPTY: slider is empty or query failed --></div>
<?php endif; ?>

<?php if ($modal_ann):
$_mt = $modal_ann['type'] ?? 'info';
$_m_colors = ['info'=>['bg'=>'bg-indigo-500/10','border'=>'border-indigo-500/20','text'=>'text-indigo-500','btn'=>'bg-indigo-500 hover:bg-indigo-600 shadow-indigo-500/20','icon'=>'fa-info-circle'],'success'=>['bg'=>'bg-emerald-500/10','border'=>'border-emerald-500/20','text'=>'text-emerald-500','btn'=>'bg-emerald-500 hover:bg-emerald-600 shadow-emerald-500/20','icon'=>'fa-check-circle'],'warning'=>['bg'=>'bg-amber-500/10','border'=>'border-amber-500/20','text'=>'text-amber-500','btn'=>'bg-amber-500 hover:bg-amber-600 shadow-amber-500/20','icon'=>'fa-exclamation-triangle'],'danger'=>['bg'=>'bg-red-500/10','border'=>'border-red-500/20','text'=>'text-red-500','btn'=>'bg-red-500 hover:bg-red-600 shadow-red-500/20','icon'=>'fa-times-circle']];
$_mc = $_m_colors[$_mt] ?? $_m_colors['info'];
?>
<div id="announcementModal" data-title="<?= htmlspecialchars($modal_ann['title']) ?>" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm px-4" style="display: none;">
    <div class="bg-white rounded-2xl w-full max-w-md p-6 card-shadow text-center relative animate-[fade-in_0.3s_ease-out]">
        <div class="w-16 h-16 rounded-full <?=$_mc['border']?> <?=$_mc['bg']?> <?=$_mc['text']?> flex items-center justify-center text-3xl mx-auto mb-4">
            <i class="fas <?=$_mc['icon']?>"></i>
        </div>
        <h3 class="text-xl font-bold text-slate-800 mb-2"><?= htmlspecialchars($modal_ann['title']) ?></h3>
        <p class="text-slate-600 mb-6 leading-relaxed text-sm whitespace-pre-wrap"><?= htmlspecialchars($modal_ann['message']) ?></p>
        <button onclick="closeAnnouncement()" class="<?=$_mc['btn']?> text-white px-8 py-2.5 rounded-xl font-bold transition-colors shadow-md">
            OK
        </button>
    </div>
</div>
<script>
document.addEventListener("DOMContentLoaded", () => {
    const modal = document.getElementById('announcementModal');
    if (modal) {
        modal.style.display = 'flex';
    }
});
function closeAnnouncement() {
    document.getElementById('announcementModal').style.display = 'none';
}
</script>
<?php endif; ?>

<!-- Services Grid -->
<div class="mb-6 sm:mb-8" id="quick-services">
    <h3 class="text-base sm:text-lg font-bold text-slate-800 mb-3 sm:mb-4">Quick Services</h3>
    <div class="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-5 xl:grid-cols-8 gap-2 sm:gap-4">
        
        <a href="/user/airtime" class="relative flex flex-col items-center justify-center p-4 bg-white rounded-2xl border border-slate-100 card-shadow hover:border-[#EC4899]/30 transition-all hover:-translate-y-1 group">
            <?php if(!feature_enabled('airtime')):?><span class="absolute top-1.5 right-1.5 bg-amber-100 text-amber-700 text-[8px] font-bold px-1.5 py-0.5 rounded-full">PRO</span><?php endif;?>
            <div class="w-12 h-12 rounded-full bg-[#EC4899]/10 text-[#EC4899] flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                <i class="fa-solid fa-mobile-screen-button text-xl"></i>
            </div>
            <span class="text-xs sm:text-sm font-semibold text-slate-700 text-center whitespace-nowrap">Buy Airtime</span>
        </a>

        <a href="/user/data" class="relative flex flex-col items-center justify-center p-4 bg-white rounded-2xl border border-slate-100 card-shadow hover:border-[#2563EB]/30 transition-all hover:-translate-y-1 group">
            <?php if(!feature_enabled('data')):?><span class="absolute top-1.5 right-1.5 bg-amber-100 text-amber-700 text-[8px] font-bold px-1.5 py-0.5 rounded-full">PRO</span><?php endif;?>
            <div class="w-12 h-12 rounded-full bg-[#2563EB]/10 text-[#2563EB] flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                <i class="fa-solid fa-wifi text-xl"></i>
            </div>
            <span class="text-xs sm:text-sm font-semibold text-slate-700 text-center whitespace-nowrap">Buy Data</span>
        </a>

        <a href="/user/cabletv" class="relative flex flex-col items-center justify-center p-4 bg-white rounded-2xl border border-slate-100 card-shadow hover:border-[#F97316]/30 transition-all hover:-translate-y-1 group">
            <?php if(!feature_enabled('cabletv')):?><span class="absolute top-1.5 right-1.5 bg-amber-100 text-amber-700 text-[8px] font-bold px-1.5 py-0.5 rounded-full">PRO</span><?php endif;?>
            <div class="w-12 h-12 rounded-full bg-[#F97316]/10 text-[#F97316] flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                <i class="fa-solid fa-tv text-xl"></i>
            </div>
            <span class="text-xs sm:text-sm font-semibold text-slate-700 text-center whitespace-nowrap">Cable TV</span>
        </a>

        <a href="/user/electricity" class="relative flex flex-col items-center justify-center p-4 bg-white rounded-2xl border border-slate-100 card-shadow hover:border-[#7C3AED]/30 transition-all hover:-translate-y-1 group">
            <?php if(!feature_enabled('electricity')):?><span class="absolute top-1.5 right-1.5 bg-amber-100 text-amber-700 text-[8px] font-bold px-1.5 py-0.5 rounded-full">PRO</span><?php endif;?>
            <div class="w-12 h-12 rounded-full bg-[#7C3AED]/10 text-[#7C3AED] flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                <i class="fa-solid fa-bolt text-xl"></i>
            </div>
            <span class="text-xs sm:text-sm font-semibold text-slate-700 text-center whitespace-nowrap">Electricity</span>
        </a>

        <a href="/user/exam-pin" class="relative flex flex-col items-center justify-center p-4 bg-white rounded-2xl border border-slate-100 card-shadow hover:border-[#F97316]/30 transition-all hover:-translate-y-1 group">
            <?php if(!feature_enabled('exam_pin')):?><span class="absolute top-1.5 right-1.5 bg-amber-100 text-amber-700 text-[8px] font-bold px-1.5 py-0.5 rounded-full">PRO</span><?php endif;?>
            <div class="w-12 h-12 rounded-full bg-[#F97316]/10 text-[#F97316] flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                <i class="fa-solid fa-graduation-cap text-xl"></i>
            </div>
            <span class="text-xs sm:text-sm font-semibold text-slate-700 text-center whitespace-nowrap">Exam PINs</span>
        </a>

        <a href="/user/betting" class="relative flex flex-col items-center justify-center p-4 bg-white rounded-2xl border border-slate-100 card-shadow hover:border-rose-500/30 transition-all hover:-translate-y-1 group">
            <?php if(!feature_enabled('betting')):?><span class="absolute top-1.5 right-1.5 bg-amber-100 text-amber-700 text-[8px] font-bold px-1.5 py-0.5 rounded-full">PRO</span><?php endif;?>
            <div class="w-12 h-12 rounded-full bg-rose-500/10 text-rose-500 flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                <i class="fa-solid fa-futbol text-xl"></i>
            </div>
            <span class="text-xs sm:text-sm font-semibold text-slate-700 text-center whitespace-nowrap">Betting</span>
        </a>

        <a href="/user/bulk-sms" class="relative flex flex-col items-center justify-center p-4 bg-white rounded-2xl border border-slate-100 card-shadow hover:border-[#EC4899]/30 transition-all hover:-translate-y-1 group">
            <?php if(!feature_enabled('bulk_sms')):?><span class="absolute top-1.5 right-1.5 bg-amber-100 text-amber-700 text-[8px] font-bold px-1.5 py-0.5 rounded-full">PRO</span><?php endif;?>
            <div class="w-12 h-12 rounded-full bg-[#EC4899]/10 text-[#EC4899] flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                <i class="fa-solid fa-sms text-xl"></i>
            </div>
            <span class="text-xs sm:text-sm font-semibold text-slate-700 text-center whitespace-nowrap">Bulk SMS</span>
        </a>

        <a href="/user/airtime-to-cash" class="relative flex flex-col items-center justify-center p-4 bg-white rounded-2xl border border-slate-100 card-shadow hover:border-[#2563EB]/30 transition-all hover:-translate-y-1 group">
            <?php if(!feature_enabled('airtime_to_cash')):?><span class="absolute top-1.5 right-1.5 bg-amber-100 text-amber-700 text-[8px] font-bold px-1.5 py-0.5 rounded-full">PRO</span><?php endif;?>
            <div class="w-12 h-12 rounded-full bg-[#2563EB]/10 text-[#2563EB] flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                <i class="fa-solid fa-exchange-alt text-xl"></i>
            </div>
            <span class="text-xs sm:text-sm font-semibold text-slate-700 text-center whitespace-nowrap">Airtime to Cash</span>
        </a>

        <button onclick="openMoreServices()" class="flex flex-col items-center justify-center p-4 bg-white rounded-2xl border border-dashed border-slate-300 card-shadow hover:border-brand-orange/40 transition-all hover:-translate-y-1 group cursor-pointer">
            <div class="w-12 h-12 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mb-3 group-hover:scale-110 transition-transform group-hover:bg-brand-orangeLight group-hover:text-brand-orange">
                <i class="fa-solid fa-ellipsis-h text-xl"></i>
            </div>
            <span class="text-xs sm:text-sm font-semibold text-slate-500 text-center whitespace-nowrap group-hover:text-brand-orange">More</span>
        </button>

    </div>
</div>
<!-- More Services Modal -->
<div id="moreServicesModal" class="fixed inset-0 z-[100] flex items-end sm:items-center justify-center bg-slate-900/60 backdrop-blur-sm px-4" style="display:none;" onclick="closeMoreServices(event)">
    <div class="bg-white rounded-t-2xl sm:rounded-2xl w-full max-w-sm shadow-xl relative transform transition-all duration-300" style="max-height:80vh; overflow-y:auto;" onclick="event.stopPropagation()">
        <div class="flex items-center justify-between p-4 border-b border-slate-100 sticky top-0 bg-white rounded-t-2xl z-10">
            <h3 class="text-base font-bold text-slate-800">All Services</h3>
            <button onclick="closeMoreServices()" class="w-8 h-8 rounded-full bg-slate-100 text-slate-500 hover:bg-slate-200 flex items-center justify-center transition-colors">
                <i class="fas fa-times text-sm"></i>
            </button>
        </div>
        <div class="p-4 grid grid-cols-3 sm:grid-cols-4 gap-3">
            <a href="/user/airtime" class="relative flex flex-col items-center justify-center p-3 bg-white rounded-xl border border-slate-100 card-shadow hover:border-[#EC4899]/30 transition-all hover:-translate-y-0.5 group">
                <?php if(!feature_enabled('airtime')):?><span class="absolute top-1 right-1 bg-amber-100 text-amber-700 text-[8px] font-bold px-1.5 py-0.5 rounded-full">PRO</span><?php endif;?>
                <div class="w-10 h-10 rounded-full bg-[#EC4899]/10 text-[#EC4899] flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                    <i class="fa-solid fa-mobile-screen-button"></i>
                </div>
                <span class="text-[11px] font-semibold text-slate-700 text-center leading-tight">Airtime</span>
            </a>
            <a href="/user/data" class="relative flex flex-col items-center justify-center p-3 bg-white rounded-xl border border-slate-100 card-shadow hover:border-[#2563EB]/30 transition-all hover:-translate-y-0.5 group">
                <?php if(!feature_enabled('data')):?><span class="absolute top-1 right-1 bg-amber-100 text-amber-700 text-[8px] font-bold px-1.5 py-0.5 rounded-full">PRO</span><?php endif;?>
                <div class="w-10 h-10 rounded-full bg-[#2563EB]/10 text-[#2563EB] flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                    <i class="fa-solid fa-wifi"></i>
                </div>
                <span class="text-[11px] font-semibold text-slate-700 text-center leading-tight">Data</span>
            </a>
            <a href="/user/cabletv" class="relative flex flex-col items-center justify-center p-3 bg-white rounded-xl border border-slate-100 card-shadow hover:border-[#F97316]/30 transition-all hover:-translate-y-0.5 group">
                <?php if(!feature_enabled('cabletv')):?><span class="absolute top-1 right-1 bg-amber-100 text-amber-700 text-[8px] font-bold px-1.5 py-0.5 rounded-full">PRO</span><?php endif;?>
                <div class="w-10 h-10 rounded-full bg-[#F97316]/10 text-[#F97316] flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                    <i class="fa-solid fa-tv"></i>
                </div>
                <span class="text-[11px] font-semibold text-slate-700 text-center leading-tight">Cable TV</span>
            </a>
            <a href="/user/electricity" class="relative flex flex-col items-center justify-center p-3 bg-white rounded-xl border border-slate-100 card-shadow hover:border-[#7C3AED]/30 transition-all hover:-translate-y-0.5 group">
                <?php if(!feature_enabled('electricity')):?><span class="absolute top-1 right-1 bg-amber-100 text-amber-700 text-[8px] font-bold px-1.5 py-0.5 rounded-full">PRO</span><?php endif;?>
                <div class="w-10 h-10 rounded-full bg-[#7C3AED]/10 text-[#7C3AED] flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                    <i class="fa-solid fa-bolt"></i>
                </div>
                <span class="text-[11px] font-semibold text-slate-700 text-center leading-tight">Electricity</span>
            </a>
            <a href="/user/exam-pin" class="relative flex flex-col items-center justify-center p-3 bg-white rounded-xl border border-slate-100 card-shadow hover:border-[#F97316]/30 transition-all hover:-translate-y-0.5 group">
                <?php if(!feature_enabled('exam_pin')):?><span class="absolute top-1 right-1 bg-amber-100 text-amber-700 text-[8px] font-bold px-1.5 py-0.5 rounded-full">PRO</span><?php endif;?>
                <div class="w-10 h-10 rounded-full bg-[#F97316]/10 text-[#F97316] flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                    <i class="fa-solid fa-graduation-cap"></i>
                </div>
                <span class="text-[11px] font-semibold text-slate-700 text-center leading-tight">Exam PIN</span>
            </a>
            <a href="/user/virtual-card" class="relative flex flex-col items-center justify-center p-3 bg-white rounded-xl border border-slate-100 card-shadow hover:border-[#14B8A6]/30 transition-all hover:-translate-y-0.5 group">
                <?php if(!feature_enabled('virtual_card')):?><span class="absolute top-1 right-1 bg-amber-100 text-amber-700 text-[8px] font-bold px-1.5 py-0.5 rounded-full">PRO</span><?php endif;?>
                <div class="w-10 h-10 rounded-full bg-[#14B8A6]/10 text-[#14B8A6] flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                    <i class="fa-solid fa-credit-card"></i>
                </div>
                <span class="text-[11px] font-semibold text-slate-700 text-center leading-tight">Virtual Card</span>
            </a>
            <a href="/user/betting" class="relative flex flex-col items-center justify-center p-3 bg-white rounded-xl border border-slate-100 card-shadow hover:border-rose-500/30 transition-all hover:-translate-y-0.5 group">
                <?php if(!feature_enabled('betting')):?><span class="absolute top-1 right-1 bg-amber-100 text-amber-700 text-[8px] font-bold px-1.5 py-0.5 rounded-full">PRO</span><?php endif;?>
                <div class="w-10 h-10 rounded-full bg-rose-500/10 text-rose-500 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                    <i class="fa-solid fa-futbol"></i>
                </div>
                <span class="text-[11px] font-semibold text-slate-700 text-center leading-tight">Betting</span>
            </a>
            <a href="/user/internal-transfer" class="flex flex-col items-center justify-center p-3 bg-white rounded-xl border border-slate-100 card-shadow hover:border-[#8B5CF6]/30 transition-all hover:-translate-y-0.5 group">
                <div class="w-10 h-10 rounded-full bg-[#8B5CF6]/10 text-[#8B5CF6] flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                    <i class="fa-solid fa-user-group"></i>
                </div>
                <span class="text-[11px] font-semibold text-slate-700 text-center leading-tight">Send Money</span>
            </a>
            <a href="/user/airtime-to-cash" class="relative flex flex-col items-center justify-center p-3 bg-white rounded-xl border border-slate-100 card-shadow hover:border-[#2563EB]/30 transition-all hover:-translate-y-0.5 group">
                <?php if(!feature_enabled('airtime_to_cash')):?><span class="absolute top-1 right-1 bg-amber-100 text-amber-700 text-[8px] font-bold px-1.5 py-0.5 rounded-full">PRO</span><?php endif;?>
                <div class="w-10 h-10 rounded-full bg-[#2563EB]/10 text-[#2563EB] flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                    <i class="fa-solid fa-exchange-alt"></i>
                </div>
                <span class="text-[11px] font-semibold text-slate-700 text-center leading-tight">A2C</span>
            </a>
            <a href="/user/recharge-card" class="relative flex flex-col items-center justify-center p-3 bg-white rounded-xl border border-slate-100 card-shadow hover:border-brand-orange/30 transition-all hover:-translate-y-0.5 group">
                <?php if(!feature_enabled('recharge_card')):?><span class="absolute top-1 right-1 bg-amber-100 text-amber-700 text-[8px] font-bold px-1.5 py-0.5 rounded-full">PRO</span><?php endif;?>
                <div class="w-10 h-10 rounded-full bg-brand-orangeLight text-brand-orange flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                    <i class="fa-solid fa-print"></i>
                </div>
                <span class="text-[11px] font-semibold text-slate-700 text-center leading-tight">Recharge Card</span>
            </a>
            <a href="/user/data-card" class="relative flex flex-col items-center justify-center p-3 bg-white rounded-xl border border-slate-100 card-shadow hover:border-[#14B8A6]/30 transition-all hover:-translate-y-0.5 group">
                <?php if(!feature_enabled('data_card')):?><span class="absolute top-1 right-1 bg-amber-100 text-amber-700 text-[8px] font-bold px-1.5 py-0.5 rounded-full">PRO</span><?php endif;?>
                <div class="w-10 h-10 rounded-full bg-[#14B8A6]/10 text-[#14B8A6] flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                    <i class="fa-solid fa-sd-card"></i>
                </div>
                <span class="text-[11px] font-semibold text-slate-700 text-center leading-tight">Data Card</span>
            </a>
            <a href="/user/bulk-sms" class="relative flex flex-col items-center justify-center p-3 bg-white rounded-xl border border-slate-100 card-shadow hover:border-[#EC4899]/30 transition-all hover:-translate-y-0.5 group">
                <?php if(!feature_enabled('bulk_sms')):?><span class="absolute top-1 right-1 bg-amber-100 text-amber-700 text-[8px] font-bold px-1.5 py-0.5 rounded-full">PRO</span><?php endif;?>
                <div class="w-10 h-10 rounded-full bg-[#EC4899]/10 text-[#EC4899] flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                    <i class="fa-solid fa-sms"></i>
                </div>
                <span class="text-[11px] font-semibold text-slate-700 text-center leading-tight">Bulk SMS</span>
            </a>
            <a href="/user/esim" class="relative flex flex-col items-center justify-center p-3 bg-white rounded-xl border border-slate-100 card-shadow hover:border-indigo-500/30 transition-all hover:-translate-y-0.5 group">
                <?php if(!feature_enabled('esim')):?><span class="absolute top-1 right-1 bg-amber-100 text-amber-700 text-[8px] font-bold px-1.5 py-0.5 rounded-full">PRO</span><?php endif;?>
                <div class="w-10 h-10 rounded-full bg-indigo-50 text-indigo-500 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                    <i class="fa-solid fa-sim-card"></i>
                </div>
                <span class="text-[11px] font-semibold text-slate-700 text-center leading-tight">eSIM</span>
            </a>
            <a href="/user/international-airtime" class="relative flex flex-col items-center justify-center p-3 bg-white rounded-xl border border-slate-100 card-shadow hover:border-teal-500/30 transition-all hover:-translate-y-0.5 group">
                <?php if(!feature_enabled('international_airtime')):?><span class="absolute top-1 right-1 bg-amber-100 text-amber-700 text-[8px] font-bold px-1.5 py-0.5 rounded-full">PRO</span><?php endif;?>
                <div class="w-10 h-10 rounded-full bg-teal-50 text-teal-500 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                    <i class="fa-solid fa-earth-americas"></i>
                </div>
                <span class="text-[11px] font-semibold text-slate-700 text-center leading-tight">Intl. Airtime</span>
            </a>
            <a href="/user/giftcard-buy" class="relative flex flex-col items-center justify-center p-3 bg-white rounded-xl border border-slate-100 card-shadow hover:border-fuchsia-500/30 transition-all hover:-translate-y-0.5 group">
                <?php if(!feature_enabled('giftcard_buy')):?><span class="absolute top-1 right-1 bg-amber-100 text-amber-700 text-[8px] font-bold px-1.5 py-0.5 rounded-full">PRO</span><?php endif;?>
                <div class="w-10 h-10 rounded-full bg-fuchsia-50 text-fuchsia-500 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                    <i class="fa-solid fa-gift"></i>
                </div>
                <span class="text-[11px] font-semibold text-slate-700 text-center leading-tight">Buy Gift Card</span>
            </a>
            <a href="/user/giftcard-sell" class="relative flex flex-col items-center justify-center p-3 bg-white rounded-xl border border-slate-100 card-shadow hover:border-lime-500/30 transition-all hover:-translate-y-0.5 group">
                <?php if(!feature_enabled('giftcard_sell')):?><span class="absolute top-1 right-1 bg-amber-100 text-amber-700 text-[8px] font-bold px-1.5 py-0.5 rounded-full">PRO</span><?php endif;?>
                <div class="w-10 h-10 rounded-full bg-lime-50 text-lime-600 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                    <i class="fa-solid fa-money-bill-transfer"></i>
                </div>
                <span class="text-[11px] font-semibold text-slate-700 text-center leading-tight">Sell Gift Card</span>
            </a>
            <a href="/user/cashback" class="flex flex-col items-center justify-center p-3 bg-white rounded-xl border border-slate-100 card-shadow hover:border-rose-500/30 transition-all hover:-translate-y-0.5 group">
                <div class="w-10 h-10 rounded-full bg-rose-50 text-rose-500 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                    <i class="fa-solid fa-gift"></i>
                </div>
                <span class="text-[11px] font-semibold text-slate-700 text-center leading-tight">Cashback</span>
            </a>
            <a href="/user/referrals" class="flex flex-col items-center justify-center p-3 bg-white rounded-xl border border-slate-100 card-shadow hover:border-emerald-500/30 transition-all hover:-translate-y-0.5 group">
                <div class="w-10 h-10 rounded-full bg-emerald-50 text-emerald-500 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                    <i class="fa-solid fa-user-plus"></i>
                </div>
                <span class="text-[11px] font-semibold text-slate-700 text-center leading-tight">Refer & Earn</span>
            </a>
        </div>
    </div>
</div>

<?php 
$active_banners = db()->query("SELECT image_url, link FROM banners WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();
$base_url = rtrim((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'], '/');
if (!empty($active_banners)): 
?>
<!-- Promo Banners Carousel -->
<div class="mb-6 sm:mb-8 rounded-2xl overflow-hidden relative card-shadow h-[140px] sm:h-44 group">
    <?php foreach ($active_banners as $index => $bn): 
        $img = $bn['image_url'];
        if (!empty($img) && strpos($img, '://') === false) $img = $base_url . '/' . ltrim($img, '/');
        $link = $bn['link'] ?? '';
    ?>
    <!-- Banner <?= $index + 1 ?> -->
    <div class="promo-slide absolute inset-0 transition-opacity duration-1000 ease-in-out <?= $index === 0 ? 'opacity-100 z-10' : 'opacity-0 z-0' ?>">
        <?php if ($link): ?><a href="<?= htmlspecialchars($link) ?>" target="_blank" rel="noopener" class="block w-full h-full"><?php endif; ?>
        <img src="<?= htmlspecialchars($img) ?>" alt="Promo" class="w-full h-full object-cover" onerror="this.parentElement.innerHTML='<div class=&quot;flex items-center justify-center w-full h-full bg-slate-200 text-slate-400 text-sm&quot;>Banner</div>'">
        <?php if ($link): ?></a><?php endif; ?>
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

<div class="grid grid-cols-1 gap-4 sm:gap-8">
    <!-- Transactions -->
    <div class="w-full">
        <div class="bg-white rounded-2xl border border-slate-200 card-shadow overflow-hidden h-full">
            <div class="p-3 sm:p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                <h3 class="text-sm sm:text-base font-bold text-slate-800">Recent Transactions</h3>
                <a href="/user/transactions" class="text-sm font-medium text-brand-orange hover:text-brand-orangeHover">View All</a>
            </div>
            
            <div class="p-0">
                <?php if (empty($txs)): ?>
                <div class="p-4 sm:p-8 text-center text-slate-500">
                    <div class="w-12 h-12 sm:w-16 sm:h-16 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-2 sm:mb-3 text-slate-400">
                        <i class="fas fa-receipt text-lg sm:text-2xl"></i>
                    </div>
                    <p class="text-sm sm:text-base font-medium text-slate-600">No transactions yet</p>
                    <p class="text-xs sm:text-sm mt-0.5 sm:mt-1">Your recent activity will appear here</p>
                </div>
                <?php else: ?>
                <div class="divide-y divide-slate-100 txs-list">
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
                    <a href="/user/receipt.php?ref=<?= urlencode($tx['transaction_ref']) ?>" class="flex items-center p-4 hover:bg-slate-50 transition-colors block">
                        <div class="w-10 h-10 rounded-full <?= $cc ?> flex items-center justify-center flex-shrink-0 overflow-hidden relative">
                            <?php 
                                $_txmeta = json_decode($tx['meta'] ?? '{}', true);
                                $desc_logo = ($tx['description'] ?? '') . ' ' . ($_txmeta['network'] ?? '') . ' ' . ($tx['category'] ?? '');
                            ?>
                            <?= get_tx_logo_html($desc_logo, $ic) ?>
                        </div>
                        <div class="ml-4 flex-1 min-w-0">
                            <p class="text-sm font-semibold text-slate-800 truncate"><?= htmlspecialchars($tx['description'] ?? '') ?></p>
                            <p class="text-xs text-slate-500 mt-0.5"><?= fmt_tx_time($tx['created_at']) ?></p>
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
/* Mobile compact dashboard */
@media (max-width: 639px) {
    #quick-services a, #quick-services button { padding: .5rem .375rem; }
    #quick-services a > div:first-child, #quick-services button > div:first-child { width: 2.25rem; height: 2.25rem; margin-bottom: .25rem; }
    #quick-services a > div:first-child i, #quick-services button > div:first-child i { font-size: .75rem; }
    #quick-services a > span, #quick-services button > span { font-size: .625rem; }
    .promo-slide > div > div:first-child span { font-size: .625rem; }
    .promo-slide h3 { font-size: 1rem; }
    .promo-slide p { font-size: .75rem; }
    .txs-list a { padding: .625rem .75rem; }
    .txs-list a > div:first-child { width: 2rem; height: 2rem; }
    .txs-list a > div:first-child i { font-size: .75rem; }
    .txs-list a > div:nth-child(2) p:first-child { font-size: .75rem; }
    .txs-list a > div:nth-child(2) p:last-child { font-size: .6875rem; }
    .txs-list a > div:last-child p:first-child { font-size: .8125rem; }
    .txs-list a > div:last-child span { font-size: .625rem; }
}
</style>

<script>
function openMoreServices() {
    document.getElementById('moreServicesModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeMoreServices(e) {
    if (e && e.target !== e.currentTarget) return;
    document.getElementById('moreServicesModal').style.display = 'none';
    document.body.style.overflow = '';
}

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

</script>

<?php if (empty($user['transaction_pin'])): ?>
<!-- Setup PIN Modal -->
<div id="setupPinModal" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/80 backdrop-blur-sm px-4">
    <div class="bg-white rounded-2xl w-full max-w-md p-6 card-shadow text-center relative animate-[fade-in_0.3s_ease-out]">
        <div class="w-16 h-16 rounded-full border-2 border-brand-orange/20 text-brand-orange flex items-center justify-center text-3xl mx-auto mb-4 bg-brand-orangeLight">
            <i class="fas fa-lock"></i>
        </div>
        <h3 class="text-xl font-bold text-slate-800 mb-2">Create Transaction PIN</h3>
        <p class="text-slate-600 mb-6 leading-relaxed text-sm">You need to set up a 4-digit transaction PIN to secure your account before you can perform any transactions.</p>
        
        <form id="setupPinForm" onsubmit="setupInitialPin(event)">
            <div class="grid grid-cols-2 gap-4 mb-6">
                <div>
                    <label class="block text-xs font-bold text-slate-500 mb-2 uppercase tracking-wider text-left">New PIN</label>
                    <input type="password" name="pin" maxlength="4" placeholder="••••" class="block w-full text-center py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-black text-2xl tracking-[0.5em]" required inputmode="numeric">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 mb-2 uppercase tracking-wider text-left">Confirm PIN</label>
                    <input type="password" name="confirm_pin" maxlength="4" placeholder="••••" class="block w-full text-center py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-black text-2xl tracking-[0.5em]" required inputmode="numeric">
                </div>
            </div>
            <input type="hidden" name="action" value="set_pin">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <button type="submit" id="setupPinBtn" class="w-full bg-brand-orange hover:bg-brand-orangeHover text-white font-bold py-3.5 rounded-xl shadow-md shadow-brand-orange/20 transition-all active:scale-[0.98]">
                Save PIN & Continue
            </button>
        </form>
    </div>
</div>
<script>
function setupInitialPin(e) {
    e.preventDefault();
    const form = document.getElementById('setupPinForm');
    const btn = document.getElementById('setupPinBtn');
    const ogHtml = btn.innerHTML;
    
    if(form.pin.value !== form.confirm_pin.value) {
        showAlert("PINs do not match!", 'Invalid Input');
        return;
    }
    if(form.pin.value.length !== 4) {
        showAlert("PIN must be exactly 4 digits.", 'Invalid Input');
        return;
    }
    
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    btn.disabled = true;
    
    const fd = new FormData(form);
    
    fetch('', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if(res.success) {
            showAlert("PIN set successfully!", 'Success', 'check-circle', 'text-emerald-500');
            document.getElementById('setupPinModal').remove();
        } else {
            showAlert(res.message || 'Error occurred', 'Error', 'exclamation-circle', 'text-red-500');
            btn.innerHTML = ogHtml;
            btn.disabled = false;
        }
    })
    .catch(err => {
        showAlert('Network error occurred. Please try again.', 'Error');
        btn.innerHTML = ogHtml;
        btn.disabled = false;
    });
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
