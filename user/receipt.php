<?php
require_once __DIR__.'/core/auth.php';
auth_user();
$uid=(int)$_SESSION['user_id'];
$ref=trim($_GET['ref']??'');

if(!$ref){header('Location: /msjpay/user/transactions');exit;}
// Server-side: only allow user to see their own transactions
$tx=db()->prepare("SELECT * FROM transactions WHERE transaction_ref=? AND user_id=? LIMIT 1");
$tx->execute([$ref,$uid]);
$tx=$tx->fetch();
if(!$tx){header('Location: /msjpay/user/transactions');exit;}

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
    'cashback'=>['icon'=>'fas fa-gift', 'color'=>'text-pink-500', 'bg'=>'bg-pink-100'],
    'admin_credit'=>['icon'=>'fas fa-arrow-down', 'color'=>'text-green-500', 'bg'=>'bg-green-100'],
    'admin_debit'=>['icon'=>'fas fa-arrow-up', 'color'=>'text-red-500', 'bg'=>'bg-red-100']
];

$is_cr=$tx['type']==='credit';
$catConf = $cat_icons[$tx['category']] ?? ['icon'=>'fas fa-circle', 'color'=>'text-slate-500', 'bg'=>'bg-slate-100'];

$scls = '';
$stxt = ucfirst($tx['status']);
switch($tx['status']) {
    case 'success': $scls = 'text-emerald-600 bg-emerald-100 border-emerald-200'; break;
    case 'pending': $scls = 'text-amber-600 bg-amber-100 border-amber-200'; break;
    case 'failed': 
    case 'reversed': $scls = 'text-rose-600 bg-rose-100 border-rose-200'; break;
    default: $scls = 'text-slate-600 bg-slate-100 border-slate-200';
}

$meta=json_decode($tx['meta']??'{}',true);

$page_title = 'Receipt'; 
include __DIR__ . '/includes/header.php'; 
include __DIR__ . '/includes/sidebar.php'; 
?>

<div class="max-w-xl mx-auto pb-10">
    <!-- Header (Hidden on print) -->
    <div class="flex items-center justify-between mb-6 print-hide">
        <div class="flex items-center gap-4">
            <a href="/msjpay/user/transactions" class="w-10 h-10 rounded-full bg-white border border-slate-200 flex items-center justify-center text-slate-600 hover:bg-slate-50 transition-colors">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-slate-800">Receipt</h1>
                <p class="text-sm text-slate-500">Transaction details</p>
            </div>
        </div>
    </div>

    <!-- Receipt Card -->
    <div class="bg-white rounded-3xl card-shadow overflow-hidden relative" id="receiptCard">
        <!-- Top decorative edge -->
        <div class="h-2 w-full bg-brand-navy"></div>
        
        <div class="p-8 pb-4 text-center border-b border-dashed border-slate-200 relative">
            <!-- Cutout circles for receipt effect -->
            <div class="absolute -left-3 -bottom-3 w-6 h-6 rounded-full bg-slate-50 border-r border-t border-slate-200 transform rotate-45"></div>
            <div class="absolute -right-3 -bottom-3 w-6 h-6 rounded-full bg-slate-50 border-l border-t border-slate-200 transform -rotate-45"></div>
            
            <div class="w-20 h-20 rounded-2xl <?=$catConf['bg']?> <?=$catConf['color']?> flex items-center justify-center text-3xl mx-auto mb-4 border-4 border-white shadow-sm">
                <i class="<?=$catConf['icon']?>"></i>
            </div>
            
            <h3 class="text-sm font-bold text-slate-500 uppercase tracking-wider mb-2"><?=ucwords(str_replace('_',' ',$tx['category']))?></h3>
            <div class="text-4xl font-black <?= $is_cr ? 'text-emerald-600' : 'text-slate-800' ?> mb-3">
                <?=$is_cr?'+':'-'?>₦<?=number_format((float)$tx['amount'],2)?>
            </div>
            <div class="inline-block px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider border <?=$scls?>">
                <?=$stxt?>
            </div>
        </div>
        
        <div class="p-8 pt-4">
            <div class="space-y-4">
                <div class="flex justify-between items-center py-2 border-b border-slate-50">
                    <span class="text-sm font-semibold text-slate-500">Description</span>
                    <span class="text-sm font-bold text-slate-800 text-right max-w-[60%]"><?=htmlspecialchars($tx['description']??'')?></span>
                </div>
                
                <div class="flex justify-between items-center py-2 border-b border-slate-50">
                    <span class="text-sm font-semibold text-slate-500">Amount</span>
                    <span class="text-sm font-bold text-slate-800">₦<?=number_format((float)$tx['amount'],2)?></span>
                </div>
                
                <?php if((float)$tx['charge']>0):?>
                <div class="flex justify-between items-center py-2 border-b border-slate-50">
                    <span class="text-sm font-semibold text-slate-500">Charge</span>
                    <span class="text-sm font-bold text-rose-600">₦<?=number_format((float)$tx['charge'],2)?></span>
                </div>
                <?php endif;?>
                
                <div class="flex justify-between items-center py-2 border-b border-slate-50">
                    <span class="text-sm font-semibold text-slate-500">Balance Before</span>
                    <span class="text-sm font-bold text-slate-800">₦<?=number_format((float)$tx['balance_before'],2)?></span>
                </div>
                
                <div class="flex justify-between items-center py-2 border-b border-slate-50">
                    <span class="text-sm font-semibold text-slate-500">Balance After</span>
                    <span class="text-sm font-bold text-slate-800">₦<?=number_format((float)$tx['balance_after'],2)?></span>
                </div>
                
                <?php if($tx['phone']):?>
                <div class="flex justify-between items-center py-2 border-b border-slate-50">
                    <span class="text-sm font-semibold text-slate-500">Phone</span>
                    <span class="text-sm font-bold text-slate-800"><?=htmlspecialchars($tx['phone'])?></span>
                </div>
                <?php endif;?>
                
                <?php if($tx['network']):?>
                <div class="flex justify-between items-center py-2 border-b border-slate-50">
                    <span class="text-sm font-semibold text-slate-500">Network</span>
                    <span class="text-sm font-bold text-slate-800"><?=htmlspecialchars($tx['network'])?><?=$tx['network_type']?' ('.$tx['network_type'].')':''?></span>
                </div>
                <?php endif;?>
                
                <?php if(!empty($meta['token'])):?>
                <div class="flex flex-col gap-2 py-3 border-b border-slate-50">
                    <span class="text-sm font-semibold text-slate-500">Token</span>
                    <div class="bg-amber-50 border border-amber-200 p-3 rounded-lg text-center">
                        <span class="text-lg font-black text-brand-orange font-mono tracking-wider"><?=htmlspecialchars($meta['token'])?></span>
                    </div>
                </div>
                <?php endif;?>
                
                <?php if($tx['provider_ref']):?>
                <div class="flex justify-between items-center py-2 border-b border-slate-50">
                    <span class="text-sm font-semibold text-slate-500">Provider Ref</span>
                    <span class="text-xs font-mono font-bold text-slate-600 bg-slate-100 px-2 py-1 rounded"><?=htmlspecialchars(substr($tx['provider_ref'],0,20))?>...</span>
                </div>
                <?php endif;?>
                
                <div class="flex justify-between items-center py-2 border-b border-slate-50">
                    <span class="text-sm font-semibold text-slate-500">Transaction ID</span>
                    <span class="text-xs font-mono font-bold text-slate-600 bg-slate-100 px-2 py-1 rounded"><?=htmlspecialchars(substr($tx['transaction_ref'],0,20))?>...</span>
                </div>
                
                <div class="flex justify-between items-center py-2 border-b border-slate-50">
                    <span class="text-sm font-semibold text-slate-500">Date & Time</span>
                    <span class="text-sm font-bold text-slate-800"><?=date('d M Y, g:i A',strtotime($tx['created_at']))?></span>
                </div>
                
                <?php if((float)($tx['cashback_earned']??0)>0):?>
                <div class="flex justify-between items-center p-3 bg-brand-orangeLight border border-brand-orange/30 rounded-xl mt-4">
                    <span class="text-sm font-bold text-brand-orange flex items-center gap-2"><i class="fas fa-gift"></i> Cashback Earned</span>
                    <span class="text-sm font-black text-brand-orangeHover">+₦<?=number_format((float)$tx['cashback_earned'],2)?></span>
                </div>
                <?php endif;?>
            </div>
            
            <div class="mt-8 text-center print-only hidden">
                <img src="/msjpay/assets/images/msjpay-logo.png" alt="MsjPay" class="h-8 mx-auto mb-2 opacity-50 grayscale">
                <p class="text-xs text-slate-400">Generated securely by MsjPay</p>
            </div>
        </div>
    </div>
    
    <!-- Action Buttons -->
    <div class="mt-6 print-hide">
        <div class="grid grid-cols-4 gap-3 mb-6">
            <button onclick="shareWA()" class="flex flex-col items-center justify-center p-3 bg-white rounded-xl border border-slate-200 hover:border-green-500 hover:text-green-500 text-slate-600 transition-colors shadow-sm group">
                <div class="w-10 h-10 rounded-full bg-green-50 flex items-center justify-center text-lg mb-2 group-hover:scale-110 transition-transform">
                    <i class="fab fa-whatsapp"></i>
                </div>
                <span class="text-xs font-bold">WhatsApp</span>
            </button>
            
            <button onclick="shareEmail()" class="flex flex-col items-center justify-center p-3 bg-white rounded-xl border border-slate-200 hover:border-blue-500 hover:text-blue-500 text-slate-600 transition-colors shadow-sm group">
                <div class="w-10 h-10 rounded-full bg-blue-50 flex items-center justify-center text-lg mb-2 group-hover:scale-110 transition-transform">
                    <i class="fas fa-envelope"></i>
                </div>
                <span class="text-xs font-bold">Email</span>
            </button>
            
            <button onclick="downloadPDF()" class="flex flex-col items-center justify-center p-3 bg-white rounded-xl border border-slate-200 hover:border-rose-500 hover:text-rose-500 text-slate-600 transition-colors shadow-sm group">
                <div class="w-10 h-10 rounded-full bg-rose-50 flex items-center justify-center text-lg mb-2 group-hover:scale-110 transition-transform">
                    <i class="fas fa-file-pdf"></i>
                </div>
                <span class="text-xs font-bold">PDF</span>
            </button>
            
            <button onclick="shareFB()" class="flex flex-col items-center justify-center p-3 bg-white rounded-xl border border-slate-200 hover:border-indigo-500 hover:text-indigo-500 text-slate-600 transition-colors shadow-sm group">
                <div class="w-10 h-10 rounded-full bg-indigo-50 flex items-center justify-center text-lg mb-2 group-hover:scale-110 transition-transform">
                    <i class="fab fa-facebook-f"></i>
                </div>
                <span class="text-xs font-bold">Facebook</span>
            </button>
        </div>
        
        <a href="/msjpay/user/transactions" class="block w-full bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-center py-4 rounded-xl shadow-sm transition-colors border border-slate-200">
            Back to Transactions
        </a>
    </div>
</div>

<style>
@media print {
    body { background: white !important; }
    .print-hide { display: none !important; }
    .print-only { display: block !important; }
    #receiptCard { box-shadow: none !important; border: 1px solid #e2e8f0 !important; }
    .page-wrapper { margin: 0 !important; padding: 0 !important; }
    nav, header, footer { display: none !important; }
}
</style>

<script>
const txData = { 
    amount: '<?=number_format((float)$tx['amount'],2)?>', 
    description: '<?=addslashes(htmlspecialchars($tx['description']??''))?>', 
    status: '<?=ucfirst($tx['status'])?>', 
    date: '<?=date('d M Y g:i A',strtotime($tx['created_at']))?>', 
    ref: '<?=htmlspecialchars(substr($tx['transaction_ref'],0,20))?>...'
}; 

function makeMsg() { 
    return `*MsjPay Receipt*\n\n💰 Amount: ₦${txData.amount}\n📝 ${txData.description}\n📌 Status: ${txData.status}\n📅 ${txData.date}\n🔖 Ref: ${txData.ref}\n\n_Powered by MsjPay_`;
} 

function shareWA() { 
    window.open('https://wa.me/?text=' + encodeURIComponent(makeMsg()), '_blank');
}

function shareFB() { 
    window.open('https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(location.href), '_blank');
}

function shareEmail() { 
    const sub = encodeURIComponent('MsjPay Transaction Receipt'); 
    const body = encodeURIComponent(makeMsg().replace(/\*/g,'').replace(/_/g,'')); 
    window.open('mailto:?subject=' + sub + '&body=' + body);
}

function downloadPDF() { 
    window.print(); 
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>