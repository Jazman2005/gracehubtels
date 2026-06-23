<?php
require_once __DIR__.'/core/auth.php';
auth_user();
$uid = (int)$_SESSION['user_id'];
$user = get_user($uid);

$nets = ['MTN', 'GLO', 'AIRTEL', '9MOBILE'];
$sel_net = strtoupper($_GET['net'] ?? 'MTN');
if(!in_array($sel_net, $nets)) $sel_net = 'MTN';

// Fetch data plans for the selected network
$data_plans = db()->prepare("SELECT * FROM data_plans WHERE network = ? AND is_active = 1 ORDER BY CAST(selling_price AS DECIMAL)");
$data_plans->execute([$sel_net]);
$plans = $data_plans->fetchAll();

// Group by data type (SME, CG, etc)
$grouped_plans = [];
foreach($plans as $p) {
    $type = $p['display_type_name'] ?: $p['data_type'];
    if(!isset($grouped_plans[$type])) $grouped_plans[$type] = [];
    $grouped_plans[$type][] = $p;
}

$net_styles = [
    'MTN' => ['bg'=>'bg-yellow-400', 'text'=>'text-slate-900', 'logo'=>'/msjpay/assets/logos/mtn.png', 'light'=>'bg-yellow-50', 'border'=>'border-yellow-200'],
    'GLO' => ['bg'=>'bg-green-600', 'text'=>'text-white', 'logo'=>'/msjpay/assets/logos/glo.png', 'light'=>'bg-green-50', 'border'=>'border-green-200'],
    'AIRTEL' => ['bg'=>'bg-red-600', 'text'=>'text-white', 'logo'=>'/msjpay/assets/logos/airtel.png', 'light'=>'bg-red-50', 'border'=>'border-red-200'],
    '9MOBILE' => ['bg'=>'bg-emerald-800', 'text'=>'text-white', 'logo'=>'/msjpay/assets/logos/9mobile.png', 'light'=>'bg-emerald-50', 'border'=>'border-emerald-200']
];

$page_title = 'Pricing Catalog'; 
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
                <h1 class="text-2xl font-bold text-slate-800">Pricing Catalog</h1>
                <p class="text-sm text-slate-500">Compare discounts for Regular, Agent, and Vendor roles.</p>
            </div>
        </div>
    </div>

    <!-- Upgrade Banner -->
    <?php if($user['user_type'] === 'regular'): ?>
    <div class="bg-gradient-to-r from-brand-orange to-brand-orangeHover rounded-2xl p-6 text-white shadow-lg mb-6 flex flex-col sm:flex-row items-center justify-between gap-4">
        <div>
            <h3 class="text-lg font-bold mb-1"><i class="fas fa-rocket mr-2"></i>Upgrade to Agent or Vendor!</h3>
            <p class="text-orange-100 text-sm">You are currently a Regular user. Upgrade your account to get massive discounts on all services and maximize your profits.</p>
        </div>
        <a href="/msjpay/user/support" class="bg-white text-brand-orange font-bold px-6 py-2.5 rounded-xl hover:bg-orange-50 transition-colors whitespace-nowrap shadow-sm">
            Contact Admin
        </a>
    </div>
    <?php endif; ?>

    <!-- Network Selector -->
    <div class="bg-white rounded-2xl border border-slate-200 p-3 mb-6 flex overflow-x-auto gap-2 custom-scrollbar">
        <?php foreach($nets as $n): $st = $net_styles[$n]; ?>
        <a href="?net=<?=$n?>" class="flex items-center gap-3 px-5 py-3 rounded-xl border-2 transition-all min-w-[140px] justify-center <?= $sel_net === $n ? 'border-brand-orange bg-brand-orangeLight' : 'border-slate-100 bg-slate-50 hover:border-slate-200 text-slate-600' ?>">
            <div class="w-8 h-8 rounded-full overflow-hidden shadow-sm flex items-center justify-center <?=$st['bg']?> <?=$st['text']?> font-bold text-xs">
                <img src="<?=$st['logo']?>" class="w-full h-full object-cover" onerror="this.style.display='none';this.parentNode.innerHTML='<?=substr($n,0,1)?>'">
            </div>
            <span class="font-bold text-sm <?= $sel_net === $n ? 'text-brand-orangeHover' : '' ?>"><?=$n?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Pricing Tables -->
    <div class="space-y-6">
        <?php if(empty($grouped_plans)): ?>
        <div class="bg-white rounded-2xl border border-slate-200 p-12 text-center text-slate-500">
            <div class="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center text-slate-300 mx-auto mb-3 text-2xl">
                <i class="fas fa-box-open"></i>
            </div>
            <h3 class="text-lg font-bold text-slate-700 mb-1">No Plans Found</h3>
            <p>We couldn't find any active data plans for <?=$sel_net?>.</p>
        </div>
        <?php else: ?>
            <?php foreach($grouped_plans as $type => $plan_list): ?>
            <div class="bg-white rounded-2xl border border-slate-200 card-shadow overflow-hidden">
                <div class="<?=$net_styles[$sel_net]['light']?> border-b <?=$net_styles[$sel_net]['border']?> px-5 py-4 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center shadow-sm <?=$net_styles[$sel_net]['bg']?> <?=$net_styles[$sel_net]['text']?>">
                        <i class="fas fa-wifi"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-slate-800 text-lg"><?=$sel_net?> <?=$type?> Data</h3>
                        <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Plan Pricing Comparison</p>
                    </div>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wider border-b border-slate-200">
                                <th class="p-4 font-semibold w-1/4">Plan / Size</th>
                                <th class="p-4 font-semibold text-center border-l border-slate-200 bg-slate-50">Regular</th>
                                <th class="p-4 font-semibold text-center border-l border-slate-200 bg-amber-50 text-amber-700">Agent</th>
                                <th class="p-4 font-semibold text-center border-l border-slate-200 bg-purple-50 text-purple-700">Vendor</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-sm font-medium">
                            <?php foreach($plan_list as $p): ?>
                            <tr class="hover:bg-slate-50 transition-colors group">
                                <td class="p-4">
                                    <div class="text-slate-800 font-bold"><?=htmlspecialchars($p['data_size'])?></div>
                                    <div class="text-xs text-slate-500 mt-0.5"><?=htmlspecialchars($p['validity'])?></div>
                                </td>
                                
                                <td class="p-4 text-center border-l border-slate-100 <?= $user['user_type']==='regular' ? 'bg-slate-100' : '' ?>">
                                    <div class="text-slate-800 font-bold text-base">₦<?=number_format((float)$p['selling_price'], 2)?></div>
                                </td>
                                
                                <?php 
                                    $agt_price = $p['agent_price'] > 0 ? $p['agent_price'] : $p['selling_price'];
                                    $vnd_price = $p['vendor_price'] > 0 ? $p['vendor_price'] : $p['selling_price'];
                                ?>
                                <td class="p-4 text-center border-l border-slate-100 <?= $user['user_type']==='agent' ? 'bg-amber-100' : 'group-hover:bg-amber-50' ?>">
                                    <div class="text-amber-700 font-bold text-base">₦<?=number_format((float)$agt_price, 2)?></div>
                                    <?php if($agt_price < $p['selling_price']): ?>
                                    <div class="text-[10px] text-amber-600 font-bold uppercase mt-1">Save ₦<?=number_format($p['selling_price'] - $agt_price, 2)?></div>
                                    <?php endif; ?>
                                </td>
                                
                                <td class="p-4 text-center border-l border-slate-100 <?= $user['user_type']==='vendor' ? 'bg-purple-100' : 'group-hover:bg-purple-50' ?>">
                                    <div class="text-purple-700 font-bold text-base">₦<?=number_format((float)$vnd_price, 2)?></div>
                                    <?php if($vnd_price < $p['selling_price']): ?>
                                    <div class="text-[10px] text-purple-600 font-bold uppercase mt-1">Save ₦<?=number_format($p['selling_price'] - $vnd_price, 2)?></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
