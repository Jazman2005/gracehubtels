<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_user();
$uid=(int)$_SESSION['user_id'];
$user=get_user($uid);
$page=max(1,(int)($_GET['page']??1));
$filter=trim($_GET['filter']??'');
$data=get_user_transactions($uid,$page,20,$filter);

$cat_icons=[
    'wallet_funding'=>['icon'=>'fas fa-wallet', 'color'=>'text-emerald-500', 'bg'=>'bg-emerald-100'],
    'airtime'=>['icon'=>'fas fa-mobile-alt', 'color'=>'text-blue-500', 'bg'=>'bg-blue-100'],
    'data'=>['icon'=>'fas fa-wifi', 'color'=>'text-indigo-500', 'bg'=>'bg-indigo-100'],
    'electricity'=>['icon'=>'fas fa-bolt', 'color'=>'text-yellow-500', 'bg'=>'bg-yellow-100'],
    'cabletv'=>['icon'=>'fas fa-tv', 'color'=>'text-purple-500', 'bg'=>'bg-purple-100'],
    'airtime_to_cash'=>['icon'=>'fas fa-exchange-alt', 'color'=>'text-teal-500', 'bg'=>'bg-teal-100'],
    'exam_pin'=>['icon'=>'fas fa-graduation-cap', 'color'=>'text-orange-500', 'bg'=>'bg-orange-100'],
    'recharge_card'=>['icon'=>'fas fa-print', 'color'=>'text-rose-500', 'bg'=>'bg-rose-100'],
    'data_card'=>['icon'=>'fas fa-ticket-alt', 'color'=>'text-cyan-500', 'bg'=>'bg-cyan-100'],
    'bulk_sms'=>['icon'=>'fas fa-sms', 'color'=>'text-indigo-500', 'bg'=>'bg-indigo-100'],
    'bank_transfer'=>['icon'=>'fas fa-university', 'color'=>'text-slate-700', 'bg'=>'bg-slate-200'],
    'cashback'=>['icon'=>'fas fa-gift', 'color'=>'text-pink-500', 'bg'=>'bg-pink-100'],
    'admin_credit'=>['icon'=>'fas fa-arrow-down', 'color'=>'text-green-500', 'bg'=>'bg-green-100'],
    'admin_debit'=>['icon'=>'fas fa-arrow-up', 'color'=>'text-red-500', 'bg'=>'bg-red-100']
];

$cats=[
    'wallet_funding'=>'Funding',
    'airtime'=>'Airtime',
    'data'=>'Data',
    'electricity'=>'Electricity',
    'cabletv'=>'Cable TV',
    'airtime_to_cash'=>'A2C',
    'exam_pin'=>'Exam PIN',
    'recharge_card'=>'Recharge',
    'data_card'=>'Data Card',
    'bulk_sms'=>'Bulk SMS',
    'bank_transfer'=>'Transfer',
    'cashback'=>'Cashback'
];

$page_title = 'Transactions'; 
include __DIR__ . '/includes/header.php'; 
include __DIR__ . '/includes/sidebar.php'; 
?>

<div class="max-w-4xl mx-auto">
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-4">
            <a href="/msjpay/user/dashboard" class="w-10 h-10 rounded-full bg-white border border-slate-200 flex items-center justify-center text-slate-600 hover:bg-slate-50 transition-colors">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-slate-800">Transactions</h1>
                <p class="text-sm text-slate-500">History of your account activities</p>
            </div>
        </div>
        <div class="bg-brand-orangeLight text-brand-orangeHover px-4 py-2 rounded-xl font-bold border border-brand-orange/20 shadow-sm hidden sm:flex items-center gap-2">
            <span class="text-xs font-semibold uppercase tracking-wider opacity-80">Total Txns:</span>
            <span><?=number_format($data['total'])?></span>
        </div>
    </div>

    <!-- Filter Chips -->
    <div class="bg-white rounded-2xl border border-slate-200 card-shadow p-3 mb-6 overflow-hidden">
        <div class="flex gap-2 overflow-x-auto pb-2 custom-scrollbar">
            <a href="?filter=" class="flex-shrink-0 px-4 py-2 rounded-xl text-sm font-bold transition-colors <?= $filter==='' ? 'bg-brand-orange text-white' : 'bg-slate-50 text-slate-600 hover:bg-slate-100 border border-slate-200' ?>">
                All Transactions
            </a>
            <?php foreach($cats as $k=>$lbl): 
                $icon = $cat_icons[$k]['icon'] ?? 'fas fa-circle';
            ?>
            <a href="?filter=<?=$k?>" class="flex-shrink-0 px-4 py-2 rounded-xl text-sm font-bold transition-colors inline-flex items-center gap-2 <?= $filter===$k ? 'bg-brand-orange text-white' : 'bg-slate-50 text-slate-600 hover:bg-slate-100 border border-slate-200' ?>">
                <i class="<?=$icon?> <?= $filter===$k ? 'text-white/80' : 'text-slate-400' ?>"></i> <?=$lbl?>
            </a>
            <?php endforeach;?>
        </div>
    </div>

    <!-- Transactions List -->
    <div class="bg-white rounded-2xl border border-slate-200 card-shadow overflow-hidden mb-6">
        <?php if(empty($data['data'])):?>
            <div class="p-12 text-center">
                <div class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-4 text-slate-300">
                    <i class="fas fa-receipt text-3xl"></i>
                </div>
                <h3 class="text-lg font-bold text-slate-800 mb-1">No transactions found</h3>
                <p class="text-slate-500 text-sm"><?=$filter?'Try a different filter':'Your transactions will appear here once you make one'?></p>
                <?php if($filter): ?>
                <a href="?filter=" class="mt-4 inline-block text-brand-orange font-semibold hover:underline">Clear Filters</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="divide-y divide-slate-100">
                <?php foreach($data['data'] as $tx): 
                    $catConf = $cat_icons[$tx['category']] ?? ['icon'=>'fas fa-circle', 'color'=>'text-slate-500', 'bg'=>'bg-slate-100'];
                    $cr = $tx['type']==='credit'; 
                    
                    $scls = '';
                    $stxt = ucfirst($tx['status']);
                    switch($tx['status']) {
                        case 'success': $scls = 'text-emerald-600 bg-emerald-100 border-emerald-200'; break;
                        case 'pending': $scls = 'text-amber-600 bg-amber-100 border-amber-200'; break;
                        case 'failed': 
                        case 'reversed': $scls = 'text-rose-600 bg-rose-100 border-rose-200'; break;
                        default: $scls = 'text-slate-600 bg-slate-100 border-slate-200';
                    }
                ?>
                <a href="/msjpay/user/receipt?ref=<?=urlencode($tx['transaction_ref'])?>" class="block p-4 hover:bg-slate-50 transition-colors group">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl <?=$catConf['bg']?> <?=$catConf['color']?> flex items-center justify-center shrink-0 border border-white shadow-sm group-hover:scale-105 transition-transform">
                            <i class="<?=$catConf['icon']?> text-xl"></i>
                        </div>
                        
                        <div class="flex-1 min-w-0">
                            <div class="flex justify-between items-start mb-1">
                                <h4 class="text-sm font-bold text-slate-800 truncate pr-4">
                                    <?=htmlspecialchars($tx['description'])?>
                                </h4>
                                <span class="font-bold whitespace-nowrap <?= $cr ? 'text-emerald-600' : 'text-slate-800' ?>">
                                    <?=$cr?'+':'-'?>₦<?=number_format((float)$tx['amount'],2)?>
                                </span>
                            </div>
                            
                            <div class="flex justify-between items-center">
                                <div class="text-xs text-slate-500">
                                    <span class="font-mono bg-slate-100 px-1.5 py-0.5 rounded text-slate-600 mr-2 border border-slate-200"><?=substr($tx['transaction_ref'],0,8)?>...</span>
                                    <?=date('d M Y, h:i A',strtotime($tx['created_at']))?>
                                </div>
                                <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded border <?=$scls?>"><?=$stxt?></span>
                            </div>
                        </div>
                    </div>
                </a>
                <?php endforeach;?>
            </div>
        <?php endif;?>
    </div>

    <!-- Pagination -->
    <?php if($data['total_pages']>1):?>
    <div class="flex justify-center items-center gap-2 mb-8">
        <?php if($page>1):?>
        <a href="?page=<?=$page-1?>&filter=<?=$filter?>" class="w-10 h-10 flex items-center justify-center rounded-xl bg-white border border-slate-200 text-slate-600 hover:border-brand-orange hover:text-brand-orange transition-colors">
            <i class="fas fa-chevron-left"></i>
        </a>
        <?php endif;?>
        
        <div class="px-4 py-2 bg-white border border-slate-200 rounded-xl text-sm font-semibold text-slate-600 shadow-sm">
            Page <span class="text-brand-navy font-bold"><?=$page?></span> of <?=$data['total_pages']?>
        </div>
        
        <?php if($page<$data['total_pages']):?>
        <a href="?page=<?=$page+1?>&filter=<?=$filter?>" class="w-10 h-10 flex items-center justify-center rounded-xl bg-white border border-slate-200 text-slate-600 hover:border-brand-orange hover:text-brand-orange transition-colors">
            <i class="fas fa-chevron-right"></i>
        </a>
        <?php endif;?>
    </div>
    <?php endif;?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>