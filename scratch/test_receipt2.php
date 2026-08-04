<?php
require_once __DIR__.'/core/auth.php';
$uid = 1; <?php
require_once __DIR__.'/core/auth.php';
auth_user();
$uid=(int)$_SESSION['user_id'];
$ref=trim($_GET['ref']??'');

if(!$ref){header('Location: /user/transactions');exit;}
$tx=db()->prepare("SELECT * FROM transactions WHERE transaction_ref=? AND user_id=? LIMIT 1");
$tx->execute([$ref,$uid]);
$tx=$tx->fetch();
if(!$tx){header('Location: /user/transactions');exit;}

$user=get_user($uid);

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
    'bank_transfer'=>['icon'=>'fas fa-university', 'color'=>'text-slate-700', 'bg'=>'bg-slate-200'],
    'wallet_transfer'=>['icon'=>'fas fa-users', 'color'=>'text-blue-600', 'bg'=>'bg-blue-100'],
    'withdrawal'=>['icon'=>'fas fa-money-bill-wave', 'color'=>'text-red-500', 'bg'=>'bg-red-100'],
    'cashback'=>['icon'=>'fas fa-gift', 'color'=>'text-pink-500', 'bg'=>'bg-pink-100'],
    'admin_credit'=>['icon'=>'fas fa-arrow-down', 'color'=>'text-green-500', 'bg'=>'bg-green-100'],
    'admin_debit'=>['icon'=>'fas fa-arrow-up', 'color'=>'text-red-500', 'bg'=>'bg-red-100']
];

$is_cr=$tx['type']==='credit';
$catConf = $cat_icons[$tx['category']] ?? ['icon'=>'fas fa-circle', 'color'=>'text-slate-500', 'bg'=>'bg-slate-100'];

$meta=json_decode($tx['meta']??'{}',true);
$is_pending_approval = !empty($meta['pending_approval']) && $tx['status']==='pending';

// Build a rich description from available data
$cat=$tx['category'];
$rich_desc = $tx['description'] ?? '';

if ($cat === 'data') {
    $plan_name = $meta['plan_name'] ?? '';
    $plan_id_fallback = 0;
    if (!$plan_name && !empty($meta['plan_id'])) {
        $plan_id_fallback = (int)$meta['plan_id'];
        $pn = db()->prepare("SELECT plan_name, data_size, validity FROM data_plans WHERE id=? LIMIT 1");
        $pn->execute([$plan_id_fallback]);
        $plan_row = $pn->fetch();
        if ($plan_row) {
            $plan_name = $plan_row['plan_name'] ?: '';
            $meta['data_size'] = $plan_row['data_size'] ?: ($meta['data_size'] ?? '');
            $meta['validity'] = $plan_row['validity'];
        }
    }
    $dsize = $meta['data_size'] ?? '';
    $valid = $meta['validity'] ?? '';
    $parts = [$plan_name];
    if ($dsize && stripos($plan_name, $dsize) === false) $parts[] = $dsize;
    if ($valid) $parts[] = $valid;
    $phone = $meta['phone'] ?? '';
    $rich_desc = implode(' - ', $parts) . ($phone ? " for {$phone}" : '');
} elseif ($cat === 'airtime') {
    $net = $meta['network'] ?? '';
    $phone = $meta['phone'] ?? '';
    if ($net) $rich_desc = $net . ($phone ? " - {$phone}" : '');
} elseif ($cat === 'cabletv') {
    $sc = $meta['smartcard'] ?? '';
    $prov = $meta['provider'] ?? '';
    $plan_name = $meta['plan_name'] ?? $tx['description'] ?? '';
    $rich_desc = $plan_name . ($sc ? " for {$sc}" : '');
    if ($prov) $rich_desc .= " ({$prov})";
} elseif ($cat === 'electricity') {
    $meter = $meta['meter'] ?? '';
    $disco = $meta['disco'] ?? '';
    $mtype = $meta['type'] ?? '';
    $rich_desc = ($disco ? "{$disco} " : '') . ($meter ? "Meter: {$meter}" : '');
    if ($mtype) $rich_desc .= " ({$mtype})";
    if (!empty($meta['token'])) $rich_desc .= '. Token: ' . $meta['token'];
} elseif ($cat === 'bank_transfer') {
    $payee = $meta['payee_name'] ?? '';
    $acct = $meta['account_number'] ?? '';
    $rich_desc = $payee ? "Transfer to {$payee}" : ($tx['description'] ?? '');
    if ($acct) $rich_desc .= " ({$acct})";
} elseif ($cat === 'wallet_transfer') {
    $payee = $meta['payee_name'] ?? '';
    $rich_desc = $payee ? "Transfer to {$payee}" : ($tx['description'] ?? 'Wallet Transfer');
} elseif ($cat === 'withdrawal') {
    // keep description as-is
} elseif ($cat === 'exam_pin') {
    $token = $meta['token'] ?? '';
    if ($token) $rich_desc .= ' - PIN: ' . $token;
} elseif ($cat === 'recharge_card') {
}
// Provider Ref is now explicitly handled in $details_map

// Ensure tables exist and migrate old column names
try { db()->exec("CREATE TABLE IF NOT EXISTS recharge_cards (id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,transaction_ref VARCHAR(60) NOT NULL,network VARCHAR(20) NOT NULL,denomination DECIMAL(10,2) NOT NULL,quantity TINYINT(2) NOT NULL DEFAULT 1,amount DECIMAL(15,2) NOT NULL DEFAULT 0,name_on_card VARCHAR(100) DEFAULT NULL,pins_data TEXT DEFAULT NULL,status ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY transaction_ref (transaction_ref))"); } catch (\Throwable $e) {}
try { db()->exec("CREATE TABLE IF NOT EXISTS data_cards (id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,transaction_ref VARCHAR(60) NOT NULL,network VARCHAR(20) NOT NULL,plan_name VARCHAR(100) DEFAULT NULL,amount DECIMAL(15,2) NOT NULL DEFAULT 0,pins TEXT DEFAULT NULL,status ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY transaction_ref (transaction_ref))"); } catch (\Throwable $e) {}
try { db()->exec("ALTER TABLE recharge_cards CHANGE COLUMN business_name name_on_card VARCHAR(100) DEFAULT NULL"); } catch (\Throwable $e) {}
try { db()->exec("ALTER TABLE recharge_cards CHANGE COLUMN pins pins_data TEXT DEFAULT NULL"); } catch (\Throwable $e) {}

// Look up service-specific tables for extra details (pins for exam/recharge)
$service_details = [];
if ($cat === 'exam_pin') {
    $ep = db()->prepare("SELECT pins_data FROM exam_pins WHERE transaction_ref=? LIMIT 1");
    $ep->execute([$tx['transaction_ref']]);
    $ep_row = $ep->fetch();
    if ($ep_row) {
        $pins = json_decode($ep_row['pins_data'], true);
        if (is_array($pins)) $service_details['pins_list'] = $pins;
    }
}
if ($cat === 'recharge_card') {
    $rc = db()->prepare("SELECT pins_data FROM recharge_cards WHERE transaction_ref=? LIMIT 1");
    $rc->execute([$tx['transaction_ref']]);
    $rc_row = $rc->fetch();
    if ($rc_row) {
        $pins = json_decode($rc_row['pins_data'], true);
        if (is_array($pins)) $service_details['pins_list'] = $pins;
    }
}
if ($cat === 'data_card') {
    $dc = db()->prepare("SELECT pins FROM data_cards WHERE transaction_ref=? LIMIT 1");
    $dc->execute([$tx['transaction_ref']]);
    $dc_row = $dc->fetch();
    if ($dc_row) {
        $pins = json_decode($dc_row['pins'], true);
        if (is_array($pins)) $service_details['pins_list'] = $pins;
    }
}

// Price breakdown for transparency
$price_breakdown = [];
if ($tx['type'] === 'debit' && $cat === 'data' && !empty($meta['plan_id'])) {
    $pb = db()->prepare("SELECT selling_price, agent_price, vendor_price, cost_price FROM data_plans WHERE id=? LIMIT 1");
    $pb->execute([(int)$meta['plan_id']]);
    $plan_prices = $pb->fetch();
    if ($plan_prices) {
        $ut = $user['user_type'] ?? 'regular';
        $price_breakdown['plan_selling'] = (float)$plan_prices['selling_price'];
        $price_breakdown['user_type'] = $ut;
        if ($ut === 'agent') $price_breakdown['tier_price'] = (float)$plan_prices['agent_price'];
        elseif ($ut === 'vendor') $price_breakdown['tier_price'] = (float)$plan_prices['vendor_price'];
        else $price_breakdown['tier_price'] = (float)$plan_prices['selling_price'];
        $price_breakdown['charged'] = (float)$tx['amount'];
    }
}

$status_config = [
    'success' => ['label' => 'Successful', 'icon' => 'fas fa-check-circle', 'color' => 'text-emerald-600', 'bg' => 'bg-emerald-50', 'border' => 'border-emerald-200', 'badge' => 'bg-emerald-500'],
    'pending' => ['label' => $is_pending_approval ? 'Pending Approval' : 'Pending', 'icon' => 'fas fa-clock', 'color' => 'text-amber-600', 'bg' => 'bg-amber-50', 'border' => 'border-amber-200', 'badge' => 'bg-amber-500'],
    'failed'  => ['label' => 'Failed', 'icon' => 'fas fa-times-circle', 'color' => 'text-rose-600', 'bg' => 'bg-rose-50', 'border' => 'border-rose-200', 'badge' => 'bg-rose-500'],
    'reversed'=> ['label' => 'Reversed', 'icon' => 'fas fa-undo', 'color' => 'text-slate-600', 'bg' => 'bg-slate-50', 'border' => 'border-slate-200', 'badge' => 'bg-slate-500'],
];
$sc = $status_config[$tx['status']] ?? $status_config['pending'];

$page_title = 'Receipt';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<div class="max-w-md mx-auto pb-12 pt-4">
    <!-- Back Button -->
    <div class="flex items-center justify-between mb-6 print-hide px-4">
        <a href="/user/transactions" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-500 hover:text-brand-orange transition-colors">
            <i class="fas fa-arrow-left"></i> Back
        </a>
        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Receipt</span>
        <button onclick="window.print()" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-500 hover:text-brand-orange transition-colors">
            <i class="fas fa-print"></i> Print
        </button>
    </div>

    <!-- Receipt Card -->
    <div class="mx-4" id="receiptContainer">
        <div class="bg-white rounded-3xl shadow-xl shadow-slate-200/60 border border-slate-100 overflow-hidden" id="receiptCard">
            <!-- Status Stripe -->
            <div class="h-2 w-full <?=$sc['bg']?>"></div>

            <div class="p-6">
                <!-- Header -->
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-lg bg-brand-navy flex items-center justify-center text-white font-black text-xs">M</div>
                        <div>
                            <p class="text-sm font-bold text-slate-800">Gracehubtels</p>
                            <p class="text-[10px] text-slate-400">Receipt</p>
                        </div>
                    </div>
                    <div class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-[10px] font-bold uppercase tracking-wider border <?=$sc['border']?> <?=$sc['color']?> <?=$sc['bg']?>">
                        <i class="fas fa-circle text-[5px]"></i> <?=$sc['label']?>
                    </div>
                </div>

                <!-- Details List -->
                <div class="space-y-0 divide-y divide-slate-100">
                    <!-- Ref No -->
                    <div class="flex items-center justify-between py-2.5">
                        <span class="text-xs font-semibold text-slate-400">Ref No</span>
                        <span class="text-xs font-mono font-bold text-slate-700"><?=htmlspecialchars($tx['transaction_ref'])?></span>
                    </div>

                    <!-- Date -->
                    <div class="flex items-center justify-between py-2.5">
                        <span class="text-xs font-semibold text-slate-400">Date</span>
                        <span class="text-xs font-bold text-slate-700"><?=fmt_tx_time($tx['created_at'])?></span>
                    </div>

                    <!-- Service -->
                    <div class="flex items-center justify-between py-2.5">
                        <span class="text-xs font-semibold text-slate-400">Service</span>
                        <div class="flex items-center gap-1.5">
                            <i class="<?=$catConf['icon']?> <?=$catConf['color']?> text-xs"></i>
                            <span class="text-xs font-bold text-slate-700"><?=ucwords(str_replace('_',' ',$tx['category']))?></span>
                        </div>
                    </div>

                    <!-- Dynamic Details -->
                    <?php 
                    $details_map = [];
                    if (!empty($rich_desc) || !empty($tx['description'])) {
                        $details_map['Description'] = $rich_desc ?: ($tx['description'] ?? '');
                    }
                    if (!empty($meta['phone'])) $details_map['Phone Number'] = $meta['phone'];
                    if (!empty($meta['network'])) $details_map['Network'] = $meta['network'];
                    if (!empty($meta['data_size'])) $details_map['Data Size'] = $meta['data_size'];
                    if (!empty($meta['validity'])) $details_map['Validity'] = $meta['validity'];
                    if (!empty($meta['smartcard'])) $details_map['Smartcard No'] = $meta['smartcard'];
                    if (!empty($meta['meter'])) $details_map['Meter No'] = $meta['meter'];
                    if (!empty($meta['disco'])) $details_map['Disco'] = $meta['disco'];
                    if (!empty($meta['type'])) $details_map['Meter Type'] = $meta['type'];
                    if (!empty($meta['token'])) $details_map['Token/PIN'] = $meta['token'];
                    if (!empty($tx['provider_ref'])) $details_map['Provider Ref'] = $tx['provider_ref'];
                    if (!empty($meta['response'])) $details_map['API Response'] = $meta['response'];

                    foreach($details_map as $label => $val): 
                    ?>
                    <div class="py-2.5">
                        <span class="text-xs font-semibold text-slate-400 block mb-1"><?=htmlspecialchars($label)?></span>
                        <span class="text-sm font-semibold text-slate-800 leading-relaxed"><?=htmlspecialchars($val)?></span>
                    </div>
                    <?php endforeach; ?>

                    <!-- Amount -->
                    <div class="flex items-center justify-between py-2.5">
                        <span class="text-xs font-semibold text-slate-400">Amount</span>
                        <span class="text-sm font-black <?=$is_cr ? 'text-emerald-600' : 'text-slate-800'?>"><?=$is_cr?'+':'-'?>₦<?=number_format((float)$tx['amount'], 2)?></span>
                    </div>

                    <!-- Price Breakdown (data transactions) -->
                    <?php if(!empty($price_breakdown) && $price_breakdown['tier_price'] != $price_breakdown['charged']): ?>
                    <div class="py-2.5">
                        <div class="text-xs font-semibold text-slate-400 mb-1">Price Breakdown</div>
                        <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 space-y-1">
                            <div class="flex justify-between text-xs"><span class="text-slate-500">Plan price</span><span class="font-bold text-slate-700">₦<?=number_format($price_breakdown['plan_selling'],2)?></span></div>
                            <div class="flex justify-between text-xs"><span class="text-slate-500 capitalize"><?=$price_breakdown['user_type']?> tier</span><span class="font-bold text-slate-700">₦<?=number_format($price_breakdown['tier_price'],2)?></span></div>
                            <div class="flex justify-between text-xs border-t border-amber-200 pt-1"><span class="text-slate-600 font-semibold">Charged</span><span class="font-bold text-slate-800">₦<?=number_format($price_breakdown['charged'],2)?></span></div>
                        </div>
                    </div>
                    <?php elseif(!empty($price_breakdown) && $price_breakdown['tier_price'] != $price_breakdown['plan_selling']): ?>
                    <div class="py-2.5">
                        <div class="text-xs font-semibold text-slate-400 mb-1">Price Breakdown</div>
                        <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 space-y-1">
                            <div class="flex justify-between text-xs"><span class="text-slate-500">Plan price</span><span class="font-bold text-slate-700">₦<?=number_format($price_breakdown['plan_selling'],2)?></span></div>
                            <div class="flex justify-between text-xs"><span class="text-slate-500 capitalize"><?=$price_breakdown['user_type']?> tier</span><span class="font-bold text-slate-700">₦<?=number_format($price_breakdown['tier_price'],2)?></span></div>
                            <div class="flex justify-between text-xs border-t border-slate-200 pt-1"><span class="text-slate-600 font-semibold">Charged</span><span class="font-bold text-slate-800">₦<?=number_format($price_breakdown['charged'],2)?></span></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Charge -->
                    <?php if((float)$tx['charge'] > 0): ?>
                    <div class="flex items-center justify-between py-2.5">
                        <span class="text-xs font-semibold text-slate-400">Fee</span>
                        <span class="text-xs font-bold text-rose-500">-₦<?=number_format((float)$tx['charge'], 2)?></span>
                    </div>
                    <?php endif; ?>

                    <!-- Old Balance -->
                    <div class="flex items-center justify-between py-2.5">
                        <span class="text-xs font-semibold text-slate-400">Old Balance</span>
                        <span class="text-xs font-bold text-slate-700">₦<?=number_format((float)$tx['balance_before'], 2)?></span>
                    </div>

                    <!-- New Balance -->
                    <div class="flex items-center justify-between py-2.5">
                        <span class="text-xs font-semibold text-slate-400">New Balance</span>
                        <span class="text-xs font-bold text-slate-800">₦<?=number_format((float)$tx['balance_after'], 2)?></span>
                    </div>
                </div>

                <!-- Pending Approval Notice -->
                <?php if($is_pending_approval): ?>
                <div class="mt-5 p-3 bg-amber-50 border border-amber-200 rounded-xl flex gap-2.5 text-xs text-amber-800">
                    <i class="fas fa-clock text-amber-500 mt-0.5 shrink-0"></i>
                    <p>Your withdrawal is awaiting admin approval.</p>
                </div>
                <?php endif; ?>

                <!-- Token / Pins -->
                <?php if(!empty($meta['token'])): ?>
                <div class="mt-5 p-4 bg-slate-50 border border-slate-200 rounded-xl text-center">
                    <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Token PIN</p>
                    <p class="text-lg font-black text-brand-orange font-mono tracking-widest"><?=htmlspecialchars($meta['token'])?></p>
                </div>
                <?php endif; ?>
                <?php if(!empty($service_details['pins_list'])): ?>
                <div class="mt-5 p-4 bg-slate-50 border border-slate-200 rounded-xl">
                    <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-2.5 text-center">Pins Delivered</p>
                    <div class="space-y-1.5">
                        <?php foreach($service_details['pins_list'] as $pi): $pin_val = is_array($pi) ? ($pi['pin']??$pi['serial']??json_encode($pi)) : $pi; $memo = is_array($pi) ? ($pi['memo']??'') : ''; ?>
                        <div class="bg-white rounded-lg px-3 py-2 border border-slate-100">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-xs font-mono font-bold text-slate-800 tracking-wider"><?=htmlspecialchars($pin_val)?></span>
                                <button onclick="navigator.clipboard.writeText('<?=addslashes(htmlspecialchars($pin_val))?>')" class="text-brand-orange hover:text-brand-orangeHover text-xs shrink-0" title="Copy"><i class="fas fa-copy"></i></button>
                            </div>
                            <?php if ($memo): ?>
                            <p class="text-[10px] text-slate-500 mt-1"><?=htmlspecialchars($memo)?></p>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div class="mt-8 px-4 print-hide">
        <div class="flex flex-col gap-3">
            <button onclick="shareReceipt()" class="w-full flex items-center justify-center gap-3 p-4 bg-brand-orange hover:bg-brand-orangeHover text-white rounded-xl font-bold transition-all shadow-lg shadow-orange-500/25 active:scale-[0.98]">
                <i class="fas fa-share-nodes text-lg"></i> Share Receipt
            </button>
            <button onclick="downloadReceipt()" class="w-full flex items-center justify-center gap-3 p-4 bg-white border-2 border-slate-200 hover:border-slate-300 text-slate-700 rounded-xl font-bold transition-all active:scale-[0.98]">
                <i class="fas fa-download text-lg"></i> Download PDF
            </button>
        </div>
    </div>
</div>

<style>
@media print {
    body { background: white !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .print-hide { display: none !important; }
    #receiptCard { box-shadow: none !important; border: 2px solid #e2e8f0 !important; border-radius: 24px !important; margin-top: 20px; }
    .page-wrapper, .main-wrap { margin: 0 !important; padding: 0 !important; }
    nav, header, footer, aside, .sidebar { display: none !important; }
    @page { margin: 0.5in; size: auto; }
    .max-w-md { max-width: 100% !important; }
}
</style>

<script>
const txData = {
    amount: '₦<?=number_format((float)$tx['amount'],2)?>',
    description: '<?=addslashes(htmlspecialchars($rich_desc ?: ($tx['description']??'')))?>',
    status: '<?=$sc['label']?>',
    date: '<?=fmt_tx_time($tx['created_at'], 'd M Y g:i A')?>',
    ref: '<?=htmlspecialchars($tx['transaction_ref'])?>',
    url: window.location.href
};

function makeMsg() {
    return `Gracehubtels Receipt\n\nAmount: ${txData.amount}\n${txData.description}\nStatus: ${txData.status}\n${txData.date}\nRef: ${txData.ref}\n\nPowered by Gracehubtels`;
}

function shareReceipt() {
    if (navigator.share) {
        navigator.share({ title: 'Gracehubtels Receipt', text: makeMsg(), url: txData.url }).catch(() => {});
    } else {
        const sub = encodeURIComponent('Gracehubtels Transaction Receipt');
        const body = encodeURIComponent(makeMsg().replace(/\*/g,'').replace(/_/g,''));
        window.open('mailto:?subject=' + sub + '&body=' + body);
    }
}

function downloadReceipt() {
    window.print();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
GET['ref'] = 'MSJFB7A539F4EF01785518091';
$uid=(int)$_SESSION['user_id'];
$ref=trim($_GET['ref']??'');

if(!$ref){header('Location: /user/transactions');exit;}
$tx=db()->prepare("SELECT * FROM transactions WHERE transaction_ref=? AND user_id=? LIMIT 1");
$tx->execute([$ref,$uid]);
$tx=$tx->fetch();
if(!$tx){header('Location: /user/transactions');exit;}

$user=get_user($uid);

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
    'bank_transfer'=>['icon'=>'fas fa-university', 'color'=>'text-slate-700', 'bg'=>'bg-slate-200'],
    'wallet_transfer'=>['icon'=>'fas fa-users', 'color'=>'text-blue-600', 'bg'=>'bg-blue-100'],
    'withdrawal'=>['icon'=>'fas fa-money-bill-wave', 'color'=>'text-red-500', 'bg'=>'bg-red-100'],
    'cashback'=>['icon'=>'fas fa-gift', 'color'=>'text-pink-500', 'bg'=>'bg-pink-100'],
    'admin_credit'=>['icon'=>'fas fa-arrow-down', 'color'=>'text-green-500', 'bg'=>'bg-green-100'],
    'admin_debit'=>['icon'=>'fas fa-arrow-up', 'color'=>'text-red-500', 'bg'=>'bg-red-100']
];

$is_cr=$tx['type']==='credit';
$catConf = $cat_icons[$tx['category']] ?? ['icon'=>'fas fa-circle', 'color'=>'text-slate-500', 'bg'=>'bg-slate-100'];

$meta=json_decode($tx['meta']??'{}',true);
$is_pending_approval = !empty($meta['pending_approval']) && $tx['status']==='pending';

// Build a rich description from available data
$cat=$tx['category'];
$rich_desc = $tx['description'] ?? '';

if ($cat === 'data') {
    $plan_name = $meta['plan_name'] ?? '';
    $plan_id_fallback = 0;
    if (!$plan_name && !empty($meta['plan_id'])) {
        $plan_id_fallback = (int)$meta['plan_id'];
        $pn = db()->prepare("SELECT plan_name, data_size, validity FROM data_plans WHERE id=? LIMIT 1");
        $pn->execute([$plan_id_fallback]);
        $plan_row = $pn->fetch();
        if ($plan_row) {
            $plan_name = $plan_row['plan_name'] ?: '';
            $meta['data_size'] = $plan_row['data_size'] ?: ($meta['data_size'] ?? '');
            $meta['validity'] = $plan_row['validity'];
        }
    }
    $dsize = $meta['data_size'] ?? '';
    $valid = $meta['validity'] ?? '';
    $parts = [$plan_name];
    if ($dsize && stripos($plan_name, $dsize) === false) $parts[] = $dsize;
    if ($valid) $parts[] = $valid;
    $phone = $meta['phone'] ?? '';
    $rich_desc = implode(' - ', $parts) . ($phone ? " for {$phone}" : '');
} elseif ($cat === 'airtime') {
    $net = $meta['network'] ?? '';
    $phone = $meta['phone'] ?? '';
    if ($net) $rich_desc = $net . ($phone ? " - {$phone}" : '');
} elseif ($cat === 'cabletv') {
    $sc = $meta['smartcard'] ?? '';
    $prov = $meta['provider'] ?? '';
    $plan_name = $meta['plan_name'] ?? $tx['description'] ?? '';
    $rich_desc = $plan_name . ($sc ? " for {$sc}" : '');
    if ($prov) $rich_desc .= " ({$prov})";
} elseif ($cat === 'electricity') {
    $meter = $meta['meter'] ?? '';
    $disco = $meta['disco'] ?? '';
    $mtype = $meta['type'] ?? '';
    $rich_desc = ($disco ? "{$disco} " : '') . ($meter ? "Meter: {$meter}" : '');
    if ($mtype) $rich_desc .= " ({$mtype})";
    if (!empty($meta['token'])) $rich_desc .= '. Token: ' . $meta['token'];
} elseif ($cat === 'bank_transfer') {
    $payee = $meta['payee_name'] ?? '';
    $acct = $meta['account_number'] ?? '';
    $rich_desc = $payee ? "Transfer to {$payee}" : ($tx['description'] ?? '');
    if ($acct) $rich_desc .= " ({$acct})";
} elseif ($cat === 'wallet_transfer') {
    $payee = $meta['payee_name'] ?? '';
    $rich_desc = $payee ? "Transfer to {$payee}" : ($tx['description'] ?? 'Wallet Transfer');
} elseif ($cat === 'withdrawal') {
    // keep description as-is
} elseif ($cat === 'exam_pin') {
    $token = $meta['token'] ?? '';
    if ($token) $rich_desc .= ' - PIN: ' . $token;
} elseif ($cat === 'recharge_card') {
}
// Provider Ref is now explicitly handled in $details_map

// Ensure tables exist and migrate old column names
try { db()->exec("CREATE TABLE IF NOT EXISTS recharge_cards (id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,transaction_ref VARCHAR(60) NOT NULL,network VARCHAR(20) NOT NULL,denomination DECIMAL(10,2) NOT NULL,quantity TINYINT(2) NOT NULL DEFAULT 1,amount DECIMAL(15,2) NOT NULL DEFAULT 0,name_on_card VARCHAR(100) DEFAULT NULL,pins_data TEXT DEFAULT NULL,status ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY transaction_ref (transaction_ref))"); } catch (\Throwable $e) {}
try { db()->exec("CREATE TABLE IF NOT EXISTS data_cards (id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,transaction_ref VARCHAR(60) NOT NULL,network VARCHAR(20) NOT NULL,plan_name VARCHAR(100) DEFAULT NULL,amount DECIMAL(15,2) NOT NULL DEFAULT 0,pins TEXT DEFAULT NULL,status ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY transaction_ref (transaction_ref))"); } catch (\Throwable $e) {}
try { db()->exec("ALTER TABLE recharge_cards CHANGE COLUMN business_name name_on_card VARCHAR(100) DEFAULT NULL"); } catch (\Throwable $e) {}
try { db()->exec("ALTER TABLE recharge_cards CHANGE COLUMN pins pins_data TEXT DEFAULT NULL"); } catch (\Throwable $e) {}

// Look up service-specific tables for extra details (pins for exam/recharge)
$service_details = [];
if ($cat === 'exam_pin') {
    $ep = db()->prepare("SELECT pins_data FROM exam_pins WHERE transaction_ref=? LIMIT 1");
    $ep->execute([$tx['transaction_ref']]);
    $ep_row = $ep->fetch();
    if ($ep_row) {
        $pins = json_decode($ep_row['pins_data'], true);
        if (is_array($pins)) $service_details['pins_list'] = $pins;
    }
}
if ($cat === 'recharge_card') {
    $rc = db()->prepare("SELECT pins_data FROM recharge_cards WHERE transaction_ref=? LIMIT 1");
    $rc->execute([$tx['transaction_ref']]);
    $rc_row = $rc->fetch();
    if ($rc_row) {
        $pins = json_decode($rc_row['pins_data'], true);
        if (is_array($pins)) $service_details['pins_list'] = $pins;
    }
}
if ($cat === 'data_card') {
    $dc = db()->prepare("SELECT pins FROM data_cards WHERE transaction_ref=? LIMIT 1");
    $dc->execute([$tx['transaction_ref']]);
    $dc_row = $dc->fetch();
    if ($dc_row) {
        $pins = json_decode($dc_row['pins'], true);
        if (is_array($pins)) $service_details['pins_list'] = $pins;
    }
}

// Price breakdown for transparency
$price_breakdown = [];
if ($tx['type'] === 'debit' && $cat === 'data' && !empty($meta['plan_id'])) {
    $pb = db()->prepare("SELECT selling_price, agent_price, vendor_price, cost_price FROM data_plans WHERE id=? LIMIT 1");
    $pb->execute([(int)$meta['plan_id']]);
    $plan_prices = $pb->fetch();
    if ($plan_prices) {
        $ut = $user['user_type'] ?? 'regular';
        $price_breakdown['plan_selling'] = (float)$plan_prices['selling_price'];
        $price_breakdown['user_type'] = $ut;
        if ($ut === 'agent') $price_breakdown['tier_price'] = (float)$plan_prices['agent_price'];
        elseif ($ut === 'vendor') $price_breakdown['tier_price'] = (float)$plan_prices['vendor_price'];
        else $price_breakdown['tier_price'] = (float)$plan_prices['selling_price'];
        $price_breakdown['charged'] = (float)$tx['amount'];
    }
}

$status_config = [
    'success' => ['label' => 'Successful', 'icon' => 'fas fa-check-circle', 'color' => 'text-emerald-600', 'bg' => 'bg-emerald-50', 'border' => 'border-emerald-200', 'badge' => 'bg-emerald-500'],
    'pending' => ['label' => $is_pending_approval ? 'Pending Approval' : 'Pending', 'icon' => 'fas fa-clock', 'color' => 'text-amber-600', 'bg' => 'bg-amber-50', 'border' => 'border-amber-200', 'badge' => 'bg-amber-500'],
    'failed'  => ['label' => 'Failed', 'icon' => 'fas fa-times-circle', 'color' => 'text-rose-600', 'bg' => 'bg-rose-50', 'border' => 'border-rose-200', 'badge' => 'bg-rose-500'],
    'reversed'=> ['label' => 'Reversed', 'icon' => 'fas fa-undo', 'color' => 'text-slate-600', 'bg' => 'bg-slate-50', 'border' => 'border-slate-200', 'badge' => 'bg-slate-500'],
];
$sc = $status_config[$tx['status']] ?? $status_config['pending'];

$page_title = 'Receipt';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<div class="max-w-md mx-auto pb-12 pt-4">
    <!-- Back Button -->
    <div class="flex items-center justify-between mb-6 print-hide px-4">
        <a href="/user/transactions" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-500 hover:text-brand-orange transition-colors">
            <i class="fas fa-arrow-left"></i> Back
        </a>
        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Receipt</span>
        <button onclick="window.print()" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-500 hover:text-brand-orange transition-colors">
            <i class="fas fa-print"></i> Print
        </button>
    </div>

    <!-- Receipt Card -->
    <div class="mx-4" id="receiptContainer">
        <div class="bg-white rounded-3xl shadow-xl shadow-slate-200/60 border border-slate-100 overflow-hidden" id="receiptCard">
            <!-- Status Stripe -->
            <div class="h-2 w-full <?=$sc['bg']?>"></div>

            <div class="p-6">
                <!-- Header -->
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-lg bg-brand-navy flex items-center justify-center text-white font-black text-xs">M</div>
                        <div>
                            <p class="text-sm font-bold text-slate-800">Gracehubtels</p>
                            <p class="text-[10px] text-slate-400">Receipt</p>
                        </div>
                    </div>
                    <div class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-[10px] font-bold uppercase tracking-wider border <?=$sc['border']?> <?=$sc['color']?> <?=$sc['bg']?>">
                        <i class="fas fa-circle text-[5px]"></i> <?=$sc['label']?>
                    </div>
                </div>

                <!-- Details List -->
                <div class="space-y-0 divide-y divide-slate-100">
                    <!-- Ref No -->
                    <div class="flex items-center justify-between py-2.5">
                        <span class="text-xs font-semibold text-slate-400">Ref No</span>
                        <span class="text-xs font-mono font-bold text-slate-700"><?=htmlspecialchars($tx['transaction_ref'])?></span>
                    </div>

                    <!-- Date -->
                    <div class="flex items-center justify-between py-2.5">
                        <span class="text-xs font-semibold text-slate-400">Date</span>
                        <span class="text-xs font-bold text-slate-700"><?=fmt_tx_time($tx['created_at'])?></span>
                    </div>

                    <!-- Service -->
                    <div class="flex items-center justify-between py-2.5">
                        <span class="text-xs font-semibold text-slate-400">Service</span>
                        <div class="flex items-center gap-1.5">
                            <i class="<?=$catConf['icon']?> <?=$catConf['color']?> text-xs"></i>
                            <span class="text-xs font-bold text-slate-700"><?=ucwords(str_replace('_',' ',$tx['category']))?></span>
                        </div>
                    </div>

                    <!-- Dynamic Details -->
                    <?php 
                    $details_map = [];
                    if (!empty($rich_desc) || !empty($tx['description'])) {
                        $details_map['Description'] = $rich_desc ?: ($tx['description'] ?? '');
                    }
                    if (!empty($meta['phone'])) $details_map['Phone Number'] = $meta['phone'];
                    if (!empty($meta['network'])) $details_map['Network'] = $meta['network'];
                    if (!empty($meta['data_size'])) $details_map['Data Size'] = $meta['data_size'];
                    if (!empty($meta['validity'])) $details_map['Validity'] = $meta['validity'];
                    if (!empty($meta['smartcard'])) $details_map['Smartcard No'] = $meta['smartcard'];
                    if (!empty($meta['meter'])) $details_map['Meter No'] = $meta['meter'];
                    if (!empty($meta['disco'])) $details_map['Disco'] = $meta['disco'];
                    if (!empty($meta['type'])) $details_map['Meter Type'] = $meta['type'];
                    if (!empty($meta['token'])) $details_map['Token/PIN'] = $meta['token'];
                    if (!empty($tx['provider_ref'])) $details_map['Provider Ref'] = $tx['provider_ref'];
                    if (!empty($meta['response'])) $details_map['API Response'] = $meta['response'];

                    foreach($details_map as $label => $val): 
                    ?>
                    <div class="py-2.5">
                        <span class="text-xs font-semibold text-slate-400 block mb-1"><?=htmlspecialchars($label)?></span>
                        <span class="text-sm font-semibold text-slate-800 leading-relaxed"><?=htmlspecialchars($val)?></span>
                    </div>
                    <?php endforeach; ?>

                    <!-- Amount -->
                    <div class="flex items-center justify-between py-2.5">
                        <span class="text-xs font-semibold text-slate-400">Amount</span>
                        <span class="text-sm font-black <?=$is_cr ? 'text-emerald-600' : 'text-slate-800'?>"><?=$is_cr?'+':'-'?>₦<?=number_format((float)$tx['amount'], 2)?></span>
                    </div>

                    <!-- Price Breakdown (data transactions) -->
                    <?php if(!empty($price_breakdown) && $price_breakdown['tier_price'] != $price_breakdown['charged']): ?>
                    <div class="py-2.5">
                        <div class="text-xs font-semibold text-slate-400 mb-1">Price Breakdown</div>
                        <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 space-y-1">
                            <div class="flex justify-between text-xs"><span class="text-slate-500">Plan price</span><span class="font-bold text-slate-700">₦<?=number_format($price_breakdown['plan_selling'],2)?></span></div>
                            <div class="flex justify-between text-xs"><span class="text-slate-500 capitalize"><?=$price_breakdown['user_type']?> tier</span><span class="font-bold text-slate-700">₦<?=number_format($price_breakdown['tier_price'],2)?></span></div>
                            <div class="flex justify-between text-xs border-t border-amber-200 pt-1"><span class="text-slate-600 font-semibold">Charged</span><span class="font-bold text-slate-800">₦<?=number_format($price_breakdown['charged'],2)?></span></div>
                        </div>
                    </div>
                    <?php elseif(!empty($price_breakdown) && $price_breakdown['tier_price'] != $price_breakdown['plan_selling']): ?>
                    <div class="py-2.5">
                        <div class="text-xs font-semibold text-slate-400 mb-1">Price Breakdown</div>
                        <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 space-y-1">
                            <div class="flex justify-between text-xs"><span class="text-slate-500">Plan price</span><span class="font-bold text-slate-700">₦<?=number_format($price_breakdown['plan_selling'],2)?></span></div>
                            <div class="flex justify-between text-xs"><span class="text-slate-500 capitalize"><?=$price_breakdown['user_type']?> tier</span><span class="font-bold text-slate-700">₦<?=number_format($price_breakdown['tier_price'],2)?></span></div>
                            <div class="flex justify-between text-xs border-t border-slate-200 pt-1"><span class="text-slate-600 font-semibold">Charged</span><span class="font-bold text-slate-800">₦<?=number_format($price_breakdown['charged'],2)?></span></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Charge -->
                    <?php if((float)$tx['charge'] > 0): ?>
                    <div class="flex items-center justify-between py-2.5">
                        <span class="text-xs font-semibold text-slate-400">Fee</span>
                        <span class="text-xs font-bold text-rose-500">-₦<?=number_format((float)$tx['charge'], 2)?></span>
                    </div>
                    <?php endif; ?>

                    <!-- Old Balance -->
                    <div class="flex items-center justify-between py-2.5">
                        <span class="text-xs font-semibold text-slate-400">Old Balance</span>
                        <span class="text-xs font-bold text-slate-700">₦<?=number_format((float)$tx['balance_before'], 2)?></span>
                    </div>

                    <!-- New Balance -->
                    <div class="flex items-center justify-between py-2.5">
                        <span class="text-xs font-semibold text-slate-400">New Balance</span>
                        <span class="text-xs font-bold text-slate-800">₦<?=number_format((float)$tx['balance_after'], 2)?></span>
                    </div>
                </div>

                <!-- Pending Approval Notice -->
                <?php if($is_pending_approval): ?>
                <div class="mt-5 p-3 bg-amber-50 border border-amber-200 rounded-xl flex gap-2.5 text-xs text-amber-800">
                    <i class="fas fa-clock text-amber-500 mt-0.5 shrink-0"></i>
                    <p>Your withdrawal is awaiting admin approval.</p>
                </div>
                <?php endif; ?>

                <!-- Token / Pins -->
                <?php if(!empty($meta['token'])): ?>
                <div class="mt-5 p-4 bg-slate-50 border border-slate-200 rounded-xl text-center">
                    <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Token PIN</p>
                    <p class="text-lg font-black text-brand-orange font-mono tracking-widest"><?=htmlspecialchars($meta['token'])?></p>
                </div>
                <?php endif; ?>
                <?php if(!empty($service_details['pins_list'])): ?>
                <div class="mt-5 p-4 bg-slate-50 border border-slate-200 rounded-xl">
                    <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-2.5 text-center">Pins Delivered</p>
                    <div class="space-y-1.5">
                        <?php foreach($service_details['pins_list'] as $pi): $pin_val = is_array($pi) ? ($pi['pin']??$pi['serial']??json_encode($pi)) : $pi; $memo = is_array($pi) ? ($pi['memo']??'') : ''; ?>
                        <div class="bg-white rounded-lg px-3 py-2 border border-slate-100">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-xs font-mono font-bold text-slate-800 tracking-wider"><?=htmlspecialchars($pin_val)?></span>
                                <button onclick="navigator.clipboard.writeText('<?=addslashes(htmlspecialchars($pin_val))?>')" class="text-brand-orange hover:text-brand-orangeHover text-xs shrink-0" title="Copy"><i class="fas fa-copy"></i></button>
                            </div>
                            <?php if ($memo): ?>
                            <p class="text-[10px] text-slate-500 mt-1"><?=htmlspecialchars($memo)?></p>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div class="mt-8 px-4 print-hide">
        <div class="flex flex-col gap-3">
            <button onclick="shareReceipt()" class="w-full flex items-center justify-center gap-3 p-4 bg-brand-orange hover:bg-brand-orangeHover text-white rounded-xl font-bold transition-all shadow-lg shadow-orange-500/25 active:scale-[0.98]">
                <i class="fas fa-share-nodes text-lg"></i> Share Receipt
            </button>
            <button onclick="downloadReceipt()" class="w-full flex items-center justify-center gap-3 p-4 bg-white border-2 border-slate-200 hover:border-slate-300 text-slate-700 rounded-xl font-bold transition-all active:scale-[0.98]">
                <i class="fas fa-download text-lg"></i> Download PDF
            </button>
        </div>
    </div>
</div>

<style>
@media print {
    body { background: white !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .print-hide { display: none !important; }
    #receiptCard { box-shadow: none !important; border: 2px solid #e2e8f0 !important; border-radius: 24px !important; margin-top: 20px; }
    .page-wrapper, .main-wrap { margin: 0 !important; padding: 0 !important; }
    nav, header, footer, aside, .sidebar { display: none !important; }
    @page { margin: 0.5in; size: auto; }
    .max-w-md { max-width: 100% !important; }
}
</style>

<script>
const txData = {
    amount: '₦<?=number_format((float)$tx['amount'],2)?>',
    description: '<?=addslashes(htmlspecialchars($rich_desc ?: ($tx['description']??'')))?>',
    status: '<?=$sc['label']?>',
    date: '<?=fmt_tx_time($tx['created_at'], 'd M Y g:i A')?>',
    ref: '<?=htmlspecialchars($tx['transaction_ref'])?>',
    url: window.location.href
};

function makeMsg() {
    return `Gracehubtels Receipt\n\nAmount: ${txData.amount}\n${txData.description}\nStatus: ${txData.status}\n${txData.date}\nRef: ${txData.ref}\n\nPowered by Gracehubtels`;
}

function shareReceipt() {
    if (navigator.share) {
        navigator.share({ title: 'Gracehubtels Receipt', text: makeMsg(), url: txData.url }).catch(() => {});
    } else {
        const sub = encodeURIComponent('Gracehubtels Transaction Receipt');
        const body = encodeURIComponent(makeMsg().replace(/\*/g,'').replace(/_/g,''));
        window.open('mailto:?subject=' + sub + '&body=' + body);
    }
}

function downloadReceipt() {
    window.print();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

