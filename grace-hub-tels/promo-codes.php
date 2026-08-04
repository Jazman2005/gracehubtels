<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_admin('admin');
$csrf = admin_csrf();

if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??'')){ echo json_encode(['success'=>false,'message'=>'Invalid token']); exit; }
    $action = $_POST['action']??'';
    if($action==='create_promo'){
        $code = sanitize($_POST['code']??'', 50);
        $amount = (float)($_POST['amount']??0);
        $limit = (int)($_POST['usage_limit']??0);
        $expires = $_POST['expires_at']??null;
        
        if(!$code || $amount<=0 || $limit<=0){
            echo json_encode(['success'=>false,'message'=>'Invalid input data']);
            exit;
        }
        if($expires && strtotime($expires)<time()){
            echo json_encode(['success'=>false,'message'=>'Expiry date must be in the future']);
            exit;
        }
        
        try{
            db()->prepare("INSERT INTO promo_codes (code, amount, usage_limit, expires_at) VALUES (?, ?, ?, ?)")
                ->execute([$code, $amount, $limit, $expires ?: null]);
            audit_log($_SESSION['admin_id'], 'CREATE_PROMO', "Created promo code $code for N$amount");
            echo json_encode(['success'=>true,'message'=>'Promo code generated!']);
            exit;
        } catch(PDOException $e){
            if($e->getCode() == 23000) echo json_encode(['success'=>false,'message'=>'Promo code already exists']);
            else echo json_encode(['success'=>false,'message'=>'Database error']);
            exit;
        }
    }
    if($action==='delete_promo'){
        $id = (int)$_POST['id'];
        db()->prepare("DELETE FROM promo_codes WHERE id=?")->execute([$id]);
        echo json_encode(['success'=>true]);
        exit;
    }
}

$promos = db()->query("SELECT * FROM promo_codes ORDER BY id DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Promo Codes — Gracehubtels Admin</title><link rel="icon" type="image/png" href="/assets/images/gracehubtels-favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header" style="margin-bottom:20px">
  <div><h2>Promo Codes & Vouchers</h2><p>Generate claimable funds for user retention and marketing.</p></div>
  <button class="btn-primary" onclick="document.getElementById('newPromoModal').style.display='flex'">+ Generate Code</button>
</div>

<div class="data-panel">
  <div class="responsive-grid-wrapper">
    <table class="data-table">
      <thead><tr><th>Code</th><th>Reward Amount</th><th>Usage Limit</th><th>Status / Expiry</th><th>Created</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($promos as $p):
        $is_expired = $p['expires_at'] && strtotime($p['expires_at']) < time();
        $is_exhausted = $p['used_count'] >= $p['usage_limit'];
      ?>
      <tr>
        <td><code style="background:#F3F4F6;padding:4px 8px;border-radius:6px;font-weight:700;color:var(--primary)"><?=htmlspecialchars($p['code'])?></code></td>
        <td style="font-weight:700;color:var(--success)">₦<?=number_format($p['amount'],2)?></td>
        <td><?=$p['used_count']?> / <?=$p['usage_limit']?> claimed</td>
        <td>
            <?php if($is_exhausted): ?><span class="badge badge-warning">Exhausted</span>
            <?php elseif($is_expired): ?><span class="badge badge-danger">Expired</span>
            <?php else: ?><span class="badge badge-success">Active</span><br><span style="font-size:11px;color:var(--text-muted)"><?= $p['expires_at'] ? 'Ends '.date('d M y',strtotime($p['expires_at'])) : 'No Expiry'?></span>
            <?php endif; ?>
        </td>
        <td style="font-size:12px;color:var(--text-muted)"><?=date('d M Y',strtotime($p['created_at']))?></td>
        <td><button class="btn-ghost" style="color:var(--danger)" onclick="deletePromo(<?=$p['id']?>)"><svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg></button></td>
      </tr>
      <?php endforeach;if(empty($promos)):?><tr><td colspan="6" class="empty-state">No promo codes active.</td></tr><?php endif;?>
      </tbody>
    </table>
  </div>
</div>

</div></div>

<!-- Compose Modal -->
<div class="modal-overlay" id="newPromoModal" style="display:none">
  <div class="modal" style="max-width:500px">
    <div class="modal-header"><h3>Generate Promo Code</h3><button class="modal-close" onclick="document.getElementById('newPromoModal').style.display='none'">&times;</button></div>
    <div class="modal-body">
      <div class="form-group" style="margin-bottom:12px">
        <label>Promo Code (e.g. WELCOME500)</label>
        <input type="text" class="form-control" id="code" style="text-transform:uppercase" placeholder="Must be unique">
      </div>
      <div class="form-group" style="margin-bottom:12px"><label>Reward Amount (₦)</label><input type="number" class="form-control" id="amount" placeholder="500.00"></div>
      <div class="form-group" style="margin-bottom:12px"><label>Max Usage Limit (How many users can claim this?)</label><input type="number" class="form-control" id="usage_limit" placeholder="100"></div>
      <div class="form-group" style="margin-bottom:16px"><label>Expiry Date (Optional)</label><input type="datetime-local" class="form-control" id="expires_at"></div>
      <button class="btn-primary" style="width:100%;height:44px;justify-content:center" onclick="createPromo()">Generate Code</button>
    </div>
  </div>
</div>

<div class="save-toast" id="toast"></div>

<script>
const CSRF_TOKEN = <?=json_encode($csrf)?>;
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
async function createPromo(){
  const c=document.getElementById('code').value.toUpperCase(), a=document.getElementById('amount').value, l=document.getElementById('usage_limit').value, e=document.getElementById('expires_at').value;
  if(!c||!a||!l)return showToast('Code, amount, and limit required',false);
  const fd=new FormData();fd.append('action','create_promo');fd.append('code',c);fd.append('amount',a);fd.append('usage_limit',l);if(e)fd.append('expires_at',e);fd.append('csrf',CSRF_TOKEN);
  const r=await fetch('',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
  showToast(r.message,r.success);
  if(r.success)setTimeout(()=>location.reload(),1000);
}
async function deletePromo(id){
    if(!confirm('Delete this promo code?'))return;
    const fd=new FormData();fd.append('action','delete_promo');fd.append('id',id);fd.append('csrf',CSRF_TOKEN);
    const r=await fetch('',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    if(r.success)location.reload();
}
function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),3000);}
</script>
</body></html>
