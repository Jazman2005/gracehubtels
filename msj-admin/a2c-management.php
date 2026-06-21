<?php
require_once __DIR__.'/core/auth.php';
require_once dirname(__DIR__).'/msjpay/user/core/airtime_to_cash.php';
auth_admin();
if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    $action=$_POST['action']??'';$ref=$_POST['ref']??'';
    if($action==='credit'){
        $amount=(float)($_POST['amount']??0);
        if($amount<=0)json_response(['success'=>false,'message'=>'Invalid amount']);
        $ok=(new AirtimeToCashService())->credit_confirmed($ref,$amount);
        json_response($ok?['success'=>true,'message'=>'Credited successfully']:['success'=>false,'message'=>'Failed - request not found or already processed']);
    }
    if($action==='reject'){
        $s=db()->prepare("SELECT * FROM airtime_to_cash WHERE transaction_ref=? LIMIT 1");$s->execute([$ref]);$req=$s->fetch();
        if(!$req)json_response(['success'=>false,'message'=>'Not found']);
        db()->prepare("UPDATE airtime_to_cash SET status='failed',admin_note=? WHERE transaction_ref=?")->execute([sanitize($_POST['reason']??'Rejected by admin',300),$ref]);
        notify_user((int)$req['user_id'],'❌ A2C Rejected','Your airtime to cash request was rejected. Contact support if you sent the airtime.','danger');
        json_response(['success'=>true,'message'=>'Rejected']);
    }
    json_response(['success'=>false],400);
}
$csrf=admin_csrf();
$filter=$_GET['status']??'pending';
$where=$filter==='all'?'':"WHERE a.status='".addslashes($filter)."'";
if($filter==='active')$where="WHERE a.status IN ('pending','processing')";
$requests=db()->query("SELECT a.*,u.full_name,u.phone as user_phone FROM airtime_to_cash a LEFT JOIN users u ON a.user_id=u.id $where ORDER BY a.created_at DESC LIMIT 100")->fetchAll();
$counts=db()->query("SELECT status,COUNT(*) c FROM airtime_to_cash GROUP BY status")->fetchAll();$cmap=[];foreach($counts as $c)$cmap[$c['status']]=$c['c'];
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>A2C Requests – MsjPay Admin</title><link rel="icon" type="image/png" href="/msjpay/assets/images/msjpay-favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="/msjpay/msj-admin/css/admin.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="page-content">

<div class="page-head"><div><h2>Airtime to Cash Requests</h2><p>Autopilot handles most automatically. Manually resolve stuck requests below.</p></div></div>

<div class="filter-tabs" style="margin-bottom:16px">
  <a href="?status=active" class="filter-tab <?=$filter==='active'?'active':''?>">Active (<?=($cmap['pending']??0)+($cmap['processing']??0)?>)</a>
  <a href="?status=pending" class="filter-tab <?=$filter==='pending'?'active':''?>">Pending (<?=$cmap['pending']??0?>)</a>
  <a href="?status=processing" class="filter-tab <?=$filter==='processing'?'active':''?>">Processing (<?=$cmap['processing']??0?>)</a>
  <a href="?status=success" class="filter-tab <?=$filter==='success'?'active':''?>">Success (<?=$cmap['success']??0?>)</a>
  <a href="?status=failed" class="filter-tab <?=$filter==='failed'?'active':''?>">Failed (<?=$cmap['failed']??0?>)</a>
  <a href="?status=all" class="filter-tab <?=$filter==='all'?'active':''?>">All</a>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>User</th><th>Network</th><th>Airtime</th><th>Cash Due</th><th>Sender</th><th>Our Number</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($requests as $r):$scls=['pending'=>'badge-pending','processing'=>'badge-info','success'=>'badge-success','failed'=>'badge-danger'][$r['status']]??'badge-pending';?>
      <tr>
        <td><div class="user-cell"><div class="u-avatar"><?=strtoupper(substr($r['full_name']??'?',0,1))?></div><div><span class="u-name"><?=htmlspecialchars($r['full_name']??'—')?></span><span class="u-phone"><?=htmlspecialchars($r['user_phone']??'')?></span></div></div></td>
        <td><strong><?=$r['network']?></strong></td>
        <td>₦<?=number_format((float)$r['airtime_amount'],0)?></td>
        <td><strong class="text-success">₦<?=number_format((float)$r['cash_amount'],2)?></strong></td>
        <td><?=htmlspecialchars($r['sender_phone'])?></td>
        <td style="font-size:11px"><?=htmlspecialchars($r['our_number']??'—')?></td>
        <td><span class="badge <?=$scls?>"><?=ucfirst($r['status'])?></span></td>
        <td style="font-size:12px;color:#6B7280"><?=date('d M, H:i',strtotime($r['created_at']))?></td>
        <td style="white-space:nowrap">
          <?php if(in_array($r['status'],['pending','processing'])):?>
          <button class="btn-sm btn-success" onclick="creditPrompt('<?=$r['transaction_ref']?>',<?=$r['cash_amount']?>)">Credit</button>
          <button class="btn-sm btn-danger" onclick="rejectPrompt('<?=$r['transaction_ref']?>')">Reject</button>
          <?php else:?><span style="color:#9CA3AF;font-size:12px">—</span><?php endif;?>
        </td>
      </tr>
      <?php endforeach;if(empty($requests)):?><tr><td colspan="9" class="empty-state">No A2C requests found</td></tr><?php endif;?>
      </tbody>
    </table>
  </div>
</div>

</div></div>
<div class="save-toast" id="toast"></div>
<script>
const CSRF='<?=$csrf?>';
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
function creditPrompt(ref,suggested){const amt=prompt('Confirm cash amount to credit:',suggested);if(!amt||isNaN(amt))return;credit(ref,parseFloat(amt));}
async function credit(ref,amount){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','credit');fd.append('ref',ref);fd.append('amount',amount);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);}
function rejectPrompt(ref){const reason=prompt('Reason for rejection:');if(!reason)return;reject(ref,reason);}
async function reject(ref,reason){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','reject');fd.append('ref',ref);fd.append('reason',reason);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);}
function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),2500);}
</script>
</body></html>
