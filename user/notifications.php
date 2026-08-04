<?php
require_once __DIR__ . '/core/auth.php';
auth_user();
$uid = (int)$_SESSION['user_id']; 
db()->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? OR user_id IS NULL")->execute([$uid]);
$stmt = db()->prepare("SELECT * FROM notifications WHERE user_id=? OR user_id IS NULL ORDER BY created_at DESC LIMIT 50");
$stmt->execute([$uid]);
$notifs = $stmt->fetchAll();

$page_title = 'Notifications'; 
include __DIR__ . '/includes/header.php'; 
include __DIR__ . '/includes/sidebar.php'; 
?>

<div class="max-w-2xl mx-auto pb-6">
    <!-- Header -->
    <div class="flex items-center justify-between mb-4 sm:mb-5">
        <div class="flex items-center gap-4">
            <a href="/user/dashboard" class="w-8 h-8 sm:w-9 sm:h-9 rounded-full bg-white border border-slate-200 flex items-center justify-center text-slate-600 hover:bg-slate-50 transition-colors">
                <i class="fas fa-arrow-left text-xs"></i>
            </a>
            <div>
                <h1 class="text-base sm:text-xl font-bold text-slate-800">Notifications</h1>
                <p class="text-xs sm:text-sm text-slate-500">Updates and alerts</p>
            </div>
        </div>
        <div class="bg-brand-orangeLight text-brand-orangeHover px-2.5 sm:px-3.5 py-1.5 sm:py-2 rounded-xl font-bold border border-brand-orange/20 shadow-sm text-xs sm:text-sm">
            ₦<?=number_format((float)$user['wallet_balance'],2)?>
        </div>
    </div>

    <div class="bg-white rounded-xl sm:rounded-2xl border border-slate-200 card-shadow overflow-hidden mb-4 sm:mb-6">
        <?php if (empty($notifs)): ?>
            <div class="p-10 sm:p-12 text-center">
                <div class="w-16 h-16 sm:w-20 sm:h-20 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-3 sm:mb-4 text-slate-300 shadow-inner">
                    <i class="fas fa-bell-slash text-2xl sm:text-3xl"></i>
                </div>
                <h3 class="text-base sm:text-lg font-bold text-slate-800 mb-1 sm:mb-2">No notifications yet</h3>
                <p class="text-xs sm:text-sm text-slate-500 max-w-xs mx-auto">When you receive updates about your account, transactions, or new features, they will appear here.</p>
            </div>
        <?php else: ?>
            <div class="divide-y divide-slate-100">
                <?php foreach ($notifs as $n): 
                    $conf = [
                        'info' => ['icon' => 'fas fa-info-circle', 'color' => 'text-blue-500', 'bg' => 'bg-blue-100'],
                        'success' => ['icon' => 'fas fa-check-circle', 'color' => 'text-emerald-500', 'bg' => 'bg-emerald-100'],
                        'warning' => ['icon' => 'fas fa-exclamation-triangle', 'color' => 'text-amber-500', 'bg' => 'bg-amber-100'],
                        'danger' => ['icon' => 'fas fa-times-circle', 'color' => 'text-rose-500', 'bg' => 'bg-rose-100']
                    ];
                    $c = $conf[$n['type']] ?? $conf['info'];
                ?>
                <div class="p-3 sm:p-3.5 hover:bg-slate-50 transition-colors flex gap-3 sm:gap-3.5 group">
                    <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-full <?=$c['bg']?> <?=$c['color']?> flex items-center justify-center shrink-0 border border-white shadow-sm group-hover:scale-110 transition-transform">
                        <i class="<?=$c['icon']?> text-sm sm:text-base"></i>
                    </div>
                    
                    <div class="flex-1 min-w-0">
                        <h4 class="text-xs sm:text-sm font-bold text-slate-800 mb-0.5 leading-tight">
                            <?= htmlspecialchars($n['title']) ?>
                        </h4>
                        <p class="text-[11px] sm:text-xs text-slate-600 leading-relaxed mb-1.5 sm:mb-2">
                            <?= htmlspecialchars($n['message']) ?>
                        </p>
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider flex items-center gap-1">
                            <i class="far fa-clock"></i> <?= date('d M Y, g:i A', strtotime($n['created_at'])) ?>
                        </p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
