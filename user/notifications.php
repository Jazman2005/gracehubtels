<?php
require_once __DIR__ . '/core/auth.php';
auth_user();
$uid = (int)$_SESSION['user_id']; 
db()->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? OR user_id IS NULL")->execute([$uid]);
$notifs = db()->query("SELECT * FROM notifications WHERE user_id=$uid OR user_id IS NULL ORDER BY created_at DESC LIMIT 50")->fetchAll();

$page_title = 'Notifications'; 
include __DIR__ . '/includes/header.php'; 
include __DIR__ . '/includes/sidebar.php'; 
?>

<div class="max-w-2xl mx-auto pb-10">
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-4">
            <a href="/msjpay/user/dashboard" class="w-10 h-10 rounded-full bg-white border border-slate-200 flex items-center justify-center text-slate-600 hover:bg-slate-50 transition-colors">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-slate-800">Notifications</h1>
                <p class="text-sm text-slate-500">Updates and alerts</p>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 card-shadow overflow-hidden mb-6">
        <?php if (empty($notifs)): ?>
            <div class="p-16 text-center">
                <div class="w-24 h-24 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-5 text-slate-300 shadow-inner">
                    <i class="fas fa-bell-slash text-4xl"></i>
                </div>
                <h3 class="text-xl font-bold text-slate-800 mb-2">No notifications yet</h3>
                <p class="text-slate-500 max-w-xs mx-auto">When you receive updates about your account, transactions, or new features, they will appear here.</p>
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
                <div class="p-4 hover:bg-slate-50 transition-colors flex gap-4 group">
                    <div class="w-12 h-12 rounded-full <?=$c['bg']?> <?=$c['color']?> flex items-center justify-center shrink-0 border border-white shadow-sm group-hover:scale-110 transition-transform">
                        <i class="<?=$c['icon']?> text-xl"></i>
                    </div>
                    
                    <div class="flex-1 min-w-0">
                        <h4 class="text-sm font-bold text-slate-800 mb-1 leading-tight">
                            <?= htmlspecialchars($n['title']) ?>
                        </h4>
                        <p class="text-xs text-slate-600 leading-relaxed mb-2">
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