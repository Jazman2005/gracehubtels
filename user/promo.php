<?php
require_once __DIR__.'/core/auth.php';
auth_user();
$uid = $_SESSION['user_id'];
$user = get_user($uid);
$name = explode(' ', $user['full_name'])[0];

if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    $action = $_POST['action']??'';
    if($action==='claim_promo'){
        $code = strtoupper(sanitize($_POST['code']??'', 50));
        if(!$code) { echo json_encode(['success'=>false,'message'=>'Please enter a promo code']); exit; }
        
        $promo = db()->prepare("SELECT * FROM promo_codes WHERE code=?");
        $promo->execute([$code]);
        $promo = $promo->fetch();
        
        if(!$promo) { echo json_encode(['success'=>false,'message'=>'Invalid promo code']); exit; }
        if($promo['expires_at'] && strtotime($promo['expires_at']) < time()) { echo json_encode(['success'=>false,'message'=>'This promo code has expired']); exit; }
        if($promo['used_count'] >= $promo['usage_limit']) { echo json_encode(['success'=>false,'message'=>'This promo code has reached its usage limit']); exit; }
        
        db()->beginTransaction();
        try {
            // Check if already claimed
            $chk = db()->prepare("SELECT id FROM promo_claims WHERE user_id=? AND promo_id=?");
            $chk->execute([$uid, $promo['id']]);
            if($chk->fetchColumn()) {
                db()->rollBack();
                echo json_encode(['success'=>false,'message'=>'You have already claimed this promo code']); exit;
            }
            
            // Claim it
            db()->prepare("INSERT INTO promo_claims (user_id, promo_id) VALUES (?, ?)")->execute([$uid, $promo['id']]);
            db()->prepare("UPDATE promo_codes SET used_count = used_count + 1 WHERE id=?")->execute([$promo['id']]);
            
            // Credit wallet
            $credit = credit_wallet($uid, $promo['amount'], 'promo_claim', "Claimed Promo Code: $code");
            if (!$credit['success']) {
                throw new Exception($credit['message']);
            }
            
            db()->commit();
            echo json_encode(['success'=>true,'message'=>"Promo code claimed! ₦".number_format($promo['amount'],2)." has been added to your wallet."]);
            exit;
        } catch(Exception $e) {
            if(db()->inTransaction()) {
                db()->rollBack();
            }
            echo json_encode(['success'=>false,'message'=>'An error occurred while claiming: '.$e->getMessage()]);
            exit;
        }
    }
}

$claims = db()->prepare("SELECT c.created_at, p.code, p.amount FROM promo_claims c JOIN promo_codes p ON c.promo_id=p.id WHERE c.user_id=? ORDER BY c.id DESC LIMIT 10");
$claims->execute([$uid]);
$claims = $claims->fetchAll();

$page_title = 'Promo Codes'; 
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
                <h1 class="text-2xl font-bold text-slate-800">Claim Promo Code</h1>
                <p class="text-sm text-slate-500">Have a voucher or promo code? Enter it below to claim your bonus.</p>
            </div>
        </div>
        <div class="bg-brand-orangeLight text-brand-orangeHover px-4 py-2 rounded-xl font-bold border border-brand-orange/20 shadow-sm">
            ₦<?=number_format((float)$user['wallet_balance'],2)?>
        </div>
    </div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-6">

    <div class="bg-white rounded-2xl border border-slate-200 p-6 card-shadow h-fit">
        <div class="w-16 h-16 bg-brand-orange/10 rounded-full flex items-center justify-center text-brand-orange mb-6 mx-auto">
            <i class="fa-solid fa-gift text-2xl"></i>
        </div>
        
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5 text-center">Enter your code</label>
                <input type="text" id="promo_code" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-slate-800 focus:outline-none focus:border-brand-navy focus:ring-2 focus:ring-brand-navy/20 transition-all text-center font-bold tracking-widest uppercase placeholder:font-normal placeholder:tracking-normal placeholder:text-slate-400" placeholder="e.g. WELCOME500">
            </div>
            <button onclick="claimPromo()" class="w-full bg-brand-navy hover:bg-brand-navyDark text-white py-3.5 rounded-xl font-bold text-sm transition-all shadow-lg hover:shadow-xl hover:-translate-y-0.5 active:translate-y-0 active:shadow-md flex justify-center items-center gap-2">
                <i class="fa-solid fa-check"></i> Claim Reward
            </button>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-6 card-shadow">
        <h3 class="text-lg font-bold text-slate-800 mb-4 border-b border-slate-100 pb-3">Your Recent Claims</h3>
        
        <div class="space-y-3">
            <?php foreach($claims as $c): ?>
            <div class="flex items-center justify-between p-3 rounded-xl bg-slate-50 border border-slate-100">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-green-100 text-green-600 flex items-center justify-center text-xs">
                        <i class="fa-solid fa-ticket"></i>
                    </div>
                    <div>
                        <p class="text-sm font-bold text-slate-700"><?=htmlspecialchars($c['code'])?></p>
                        <p class="text-[11px] text-slate-500"><?=date('d M Y, h:ia', strtotime($c['created_at']))?></p>
                    </div>
                </div>
                <div class="text-sm font-bold text-green-600">
                    +₦<?=number_format($c['amount'],2)?>
                </div>
            </div>
            <?php endforeach; if(empty($claims)): ?>
            <div class="text-center py-6 text-slate-500 text-sm">
                You haven't claimed any promo codes yet.
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>
</div>

<script>
async function claimPromo(){
  const c=document.getElementById('promo_code').value;
  if(!c)return Swal.fire('Notice', 'Please enter a code', 'info');
  const fd=new FormData();fd.append('action','claim_promo');fd.append('code',c);
  const r=await fetch('',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
  if(r.success) { showAlert(r.message, 'Success', 'check-circle', 'text-emerald-500'); location.reload(); }
  else showAlert(r.message, 'Error', 'exclamation-circle', 'text-red-500');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
