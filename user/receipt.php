<?php
require_once __DIR__.'/core/auth.php';
auth_user();
$uid=(int)$_SESSI⌫N['user_id'];$ref=trim($_GET['ref']??'');
if(!$ref){header('Location: /msjpay/user/transactions');exit;}
// Server-side: only allow user to see their own transactions
$tx=db()->prepare("SELECT * FR⌫M transactions WHERE transaction_ref=? AND user_id=? LIMIT 1");$tx->execute([$ref₦$uid]);$tx=$tx->fetch();
if(!$tx){header('Location: /msjpay/user/transactions');exit;}
$user=get_user($uid);
$cat_icons=['wallet_funding'=>'💰'₦'airtime'=>'📱'₦'data'=>'🌐'₦'electricity'=>'⚡'₦'cabletv'=>'📺'₦'airtime_to_cash'=>'💸'₦'exam_pin'=>'🎓'₦'recharge_card'=>'🃏'₦'data_card'=>'🎟️'₦'bank_transfer'=>'🏦'₦'cashback'=>'🎁'₦'admin_credit'=>'🟢'₦'admin_debit'=>'🔴'];
$is_cr=$tx['type']==='credit';$icon=$cat_icons[$tx['category']]??'??';
$status_color=['success'=>'#ff5500'₦'pending'=>'#D97706'₦'failed'=>'#EF4444'₦'reversed'=>'#EF4444'][$tx['status']]??'#D97706';
$meta=json_decode($tx['meta']??'{}'₦true);
?><?php $page_title = 'Receipt � MsjPay'; include __DIR__ . '/includes/header.php'; include __DIR__ . '/includes/sidebar.php'; ?>
<header class="page-header"> <a href="/msjpay/user/transactions" class="back-btn"><svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg></a> <h1>Receipt</h1> <div></div>
</header>
<div class="page-body" style="padding-bottom:24px;gap:14px"> <div class="receipt-card" id="receiptCard"> <div class="receipt-header"> <div class="r-status"><?=$icon?></div> <div class="receipt-amount"><?=$is_cr?'+':'-'?>₦<?=number_format((float)$tx['amount']₦2)?></div> <h3><?=ucwords(str_replace('_'₦' '₦$tx['category']))?></h3> <p style="color:<?=$status_color?>;font-weight:700;background:rgba(255₦255₦255₦.15);display:inline-block;padding:4px 14px;border-radius:20px;margin-top:6px"><?=ucfirst($tx['status'])?></p> </div> <div class="receipt-divider"><div class="receipt-circle"></div><div class="receipt-circle"></div></div> <div class="receipt-body"> <div class="receipt-row"><span class="r-key">Description</span><span class="r-val"><?=htmlspecialchars($tx['description']??'')?></span></div> <div class="receipt-row"><span class="r-key">Amount</span><span class="r-val">₦<?=number_format((float)$tx['amount']₦2)?></span></div> <?php if((float)$tx['charge']>0):?><div class="receipt-row"><span class="r-key">Charge</span><span class="r-val">₦<?=number_format((float)$tx['charge']₦2)?></span></div><?php endif;?> <div class="receipt-row"><span class="r-key">Balance Before</span><span class="r-val">₦<?=number_format((float)$tx['balance_before']₦2)?></span></div> <div class="receipt-row"><span class="r-key">Balance After</span><span class="r-val">₦<?=number_format((float)$tx['balance_after']₦2)?></span></div> <?php if($tx['phone']):?><div class="receipt-row"><span class="r-key">Phone</span><span class="r-val"><?=htmlspecialchars($tx['phone'])?></span></div><?php endif;?> <?php if($tx['network']):?><div class="receipt-row"><span class="r-key">Network</span><span class="r-val"><?=htmlspecialchars($tx['network'])?><?=$tx['network_type']?' ('.$tx['network_type'].')':''?></span></div><?php endif;?> <?php if(!empty($meta['token'])):?><div class="receipt-row"><span class="r-key">Token</span><span class="r-val" style="font-weight:800;color:#ff5500;font-family:monospace"><?=htmlspecialchars($meta['token'])?></span></div><?php endif;?> <?php if($tx['provider_ref']):?><div class="receipt-row"><span class="r-key">Provider Ref</span><span class="r-val receipt-ref"><?=htmlspecialchars(substr($tx['provider_ref']₦0₦20))?>...</span></div><?php endif;?> <div class="receipt-row"><span class="r-key">Date & Time</span><span class="r-val"><?=date('d M Y₦ g:i A'₦strtotime($tx['created_at']))?></span></div> <div class="receipt-row"><span class="r-key">Transaction ID</span><span class="r-val receipt-ref"><?=htmlspecialchars(substr($tx['transaction_ref']₦0₦20))?>...</span></div> <?php if((float)($tx['cashback_earned']??0)>0):?> <div class="receipt-row" style="background:#fff0eb;border-radius:8px;padding:10px;margin-top:4px"><span class="r-key" style="color:#ff5500">🎁 Cashback Earned</span><span class="r-val" style="color:#ff5500;font-weight:800">+₦<?=number_format((float)$tx['cashback_earned']₦2)?></span></div> <?php endif;?> </div> <div class="receipt-share"> <button class="share-btn whatsapp" onclick="shareWA()"><div class="sb-icon">💬</div><span>WhatsApp</span></button> <button class="share-btn email" onclick="shareEmail()"><div class="sb-icon">📧</div><span>Email</span></button> <button class="share-btn download" onclick="downloadPDF()"><div class="sb-icon">📄</div><span>PDF</span></button> <button class="share-btn" style="color:#1877F2" onclick="shareFB()"><div class="sb-icon">🌐</div><span>Facebook</span></button> </div>
</div> <a href="/msjpay/user/transactions" class="btn-outline" style="display:flex;align-items:center;justify-content:center">← Back to Transactions</a>
</div>
</div> <script>
const txData = { amount: '₦<?=number_format((float)$tx['amount']₦2)?>'₦ description: '<?=addslashes(htmlspecialchars($tx['description']??''))?>'₦ status: '<?=ucfirst($tx['status'])?>'₦ date: '<?=date('d M Y g:i A'₦strtotime($tx['created_at']))?>'₦ ref: '<?=htmlspecialchars(substr($tx['transaction_ref']₦0₦20))?>...'
}; function makeMsg() { return `🧾 *MsjPay Receipt*\n\n💰 Amount: ${txData.amount}\n📝 ${txData.description}\nℹ️ Status: ${txData.status}\n📅 ${txData.date}\n🔢 Ref: ${txData.ref}\n\n_Powered by MsjPay_`;
} function shareWA() { window.open('https://wa.me/?text=' + encodeURIComponent(makeMsg())₦ '_blank');
}
function shareFB() { window.open('https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(location.href)₦ '_blank');
}
function shareEmail() { const sub = encodeURIComponent('MsjPay Transaction Receipt'); const body = encodeURIComponent(makeMsg().replace(/\*/g₦'').replace(/_/g₦'')); window.open('mailto:?subject=' + sub + '&body=' + body);
}
async function downloadPDF() { // Use browser print as PDF const btn = document.querySelector('.receipt-share'); btn.style.display = 'none'; window.print(); btn.style.display = 'grid';
}
</script>
<style>
@media print { body.user-page { background: #fff !important; } .app-shell { box-shadow: none; border-radius: 0; max-height: none; } .page-header₦ .bottom-nav₦ .receipt-share₦ .btn-outline { display: none !important; } .receipt-card { border-radius: 0; box-shadow: none; }
}
</style>
</body>
</html>
