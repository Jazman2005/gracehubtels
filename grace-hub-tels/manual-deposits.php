<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_admin();
require_permission('manage_users');

if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??'')) json_response(['success'=>false,'message'=>'Invalid token'],403);
    
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    
    if($action === 'approve_deposit' || $action === 'reject_deposit'){
        $req = db()->prepare("SELECT * FROM manual_funding_requests WHERE id=? AND status='pending'");
        $req->execute([$id]);
        $row = $req->fetch();
        if(!$row) json_response(['success'=>false,'message'=>'Request not found or already processed']);
        
        $user = get_user((int)$row['user_id']);
        
        if($action === 'approve_deposit') {
            $res = credit_wallet((int)$row['user_id'], (float)$row['amount'], 'wallet_funding', 'Manual Funding via Bank Transfer', ['manual_deposit_id'=>$id]);
            if($res['success']) {
                db()->prepare("UPDATE manual_funding_requests SET status='approved' WHERE id=?")->execute([$id]);
                notify_user((int)$row['user_id'], 'Deposit Approved', 'Your manual deposit of ₦'.number_format($row['amount'], 2).' has been approved and credited', 'success', null, $res['balance_before'], $res['balance_after']);
                send_whatsapp_alert("✅ *Manual Deposit Approved*\nUser: " . ($user['full_name'] ?? 'Unknown') . " (" . ($user['email'] ?? $user['phone'] ?? '') . ")\nAmount: ₦" . number_format((float)$row['amount'], 2) . "\nRef: " . ($res['ref'] ?? 'N/A'));
                json_response(['success'=>true,'message'=>'Deposit approved and wallet credited']);
            } else {
                json_response(['success'=>false,'message'=>'Failed to credit wallet']);
            }
        } elseif($action === 'reject_deposit') {
            db()->prepare("UPDATE manual_funding_requests SET status='rejected' WHERE id=?")->execute([$id]);
            notify_user((int)$row['user_id'], 'Deposit Rejected', 'Your manual deposit of ₦'.number_format($row['amount'], 2).' could not be verified. Please contact support.', 'danger');
            send_whatsapp_alert("❌ *Manual Deposit Rejected*\nUser: " . ($user['full_name'] ?? 'Unknown') . " (" . ($user['email'] ?? $user['phone'] ?? '') . ")\nAmount: ₦" . number_format((float)$row['amount'], 2) . "\nSender: " . ($row['sender_name'] ?? 'N/A'));
            json_response(['success'=>true,'message'=>'Deposit rejected']);
        }
    }
}
$csrf=admin_csrf();

$page=max(1,(int)($_GET['page']??1));
$limit=20;
$offset=($page-1)*$limit;

$filter=$_GET['filter']??'pending';
$q=$_GET['q']??'';

$where="1=1";$params=[];
if($filter==='pending'){$where.=" AND m.status='pending'";}
elseif($filter==='approved'){$where.=" AND m.status='approved'";}
elseif($filter==='rejected'){$where.=" AND m.status='rejected'";}

if($q){$where.=" AND (m.sender_name LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";$l="%$q%";$params=[$l,$l,$l];}

$total=db()->prepare("SELECT COUNT(*) FROM manual_funding_requests m JOIN users u ON m.user_id=u.id WHERE $where");
$total->execute($params);
$total=$total->fetchColumn();
$pages=ceil($total/$limit);

$s=db()->prepare("SELECT m.*, u.full_name, u.email FROM manual_funding_requests m JOIN users u ON m.user_id=u.id WHERE $where ORDER BY m.created_at DESC LIMIT $limit OFFSET $offset");
$s->execute($params);
$requests=$s->fetchAll();

$counts = db()->query("SELECT status, COUNT(*) as c FROM manual_funding_requests GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$c_pending = $counts['pending']??0;
$c_approved = $counts['approved']??0;
$c_rejected = $counts['rejected']??0;
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Manual Deposits — Gracehubtels Admin</title><link rel="icon" type="image/png" href="/assets/images/gracehubtels-favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header"><div><h2>Manual Deposits</h2><p>Review and approve user bank transfer deposits</p></div></div>

<div class="metrics-grid" style="margin-bottom:20px;grid-template-columns:repeat(3,1fr)">
  <a href="?filter=pending" class="metric-card" style="border-left:4px solid var(--warning);text-decoration:none">
    <div class="m-info"><span class="m-label">Pending Approval</span><strong class="m-val"><?=$c_pending?></strong></div>
  </a>
  <a href="?filter=approved" class="metric-card" style="border-left:4px solid var(--success);text-decoration:none">
    <div class="m-info"><span class="m-label">Approved Deposits</span><strong class="m-val"><?=$c_approved?></strong></div>
  </a>
  <a href="?filter=rejected" class="metric-card" style="border-left:4px solid var(--danger);text-decoration:none">
    <div class="m-info"><span class="m-label">Rejected Deposits</span><strong class="m-val"><?=$c_rejected?></strong></div>
  </a>
</div>

<div class="data-panel">
  <form class="filters" style="display:flex; gap:12px; margin-bottom:24px; padding: 16px; background: var(--surface-hover); border-radius: var(--radius-sm); border: 1px solid var(--border-light);">
    <input type="text" name="q" class="form-control" placeholder="Search sender, user, or email" value="<?=htmlspecialchars($q)?>" style="flex:1;">
    <button class="btn-primary" style="height:42px; padding: 0 24px;">Search</button>
  </form>
  
  <div class="responsive-grid-wrapper">
    <table class="data-table">
      <thead><tr><th>Date</th><th>User</th><th>Sender/Narration</th><th>Amount</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($requests as $r):?>
      <tr>
        <td style="font-size:12px;color:var(--text-muted)"><?=date('d M Y, H:i',strtotime($r['created_at']))?></td>
        <td>
          <div class="user-cell">
            <div class="u-avatar" style="background:var(--navy);color:#fff"><?=strtoupper(substr($r['full_name'],0,1))?></div>
            <div><span class="u-name"><?=htmlspecialchars($r['full_name'])?></span><span class="u-phone"><?=htmlspecialchars($r['email']??'-')?></span></div>
          </div>
        </td>
        <td><strong><?=htmlspecialchars($r['sender_name'])?></strong></td>
        <td style="font-weight:700;color:var(--navy-dark)">₦<?=number_format($r['amount'],2)?></td>
        <td><span class="badge badge-<?=$r['status']==='rejected'?'danger':($r['status']==='pending'?'warning':'success')?>"><?=strtoupper($r['status'])?></span></td>
        <td>
          <?php if($r['status']==='pending'): ?>
          <div style="display:flex;flex-direction:column;gap:6px">
            <button class="btn-primary btn-sm" style="background:var(--success);background-image:none;border-radius:6px;padding:8px" onclick="processDeposit(<?=$r['id']?>, 'approve_deposit')"><i class="fas fa-check"></i> Approve</button>
            <button class="btn-primary btn-sm" style="background:var(--danger);background-image:none;border-radius:6px;padding:8px" onclick="processDeposit(<?=$r['id']?>, 'reject_deposit')"><i class="fas fa-times"></i> Reject</button>
          </div>
          <?php else: ?>
            <span style="font-size:12px;color:#9CA3AF">Processed</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach;if(empty($requests)):?><tr><td colspan="6" class="empty-state">No requests found</td></tr><?php endif;?>
      </tbody>
    </table>
  </div>
  <?php if($pages>1):?>
  <div class="pagination"><span class="page-info">Page <?=$page?> of <?=$pages?></span>
  <?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++):?><a href="?page=<?=$i?>&q=<?=urlencode($q)?>&filter=<?=urlencode($filter)?>" class="page-btn <?=$i===$page?'active':''?>"><?=$i?></a><?php endfor;?>
  </div>
  <?php endif;?>
</div>

</div></div>
<div class="save-toast" id="toast"></div>

<script>
const CSRF = '<?=$csrf?>';
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),2500);}

async function processDeposit(id, action) {
    if(action === 'approve_deposit') {
        if(!confirm("Are you sure you want to approve this deposit and credit the user's wallet?")) return;
    } else {
        if(!confirm("Are you sure you want to reject this deposit?")) return;
    }
    
    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('action', action);
    fd.append('id', id);
    
    try {
        const r = await fetch(location.href, {method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
        showToast(r.message, r.success);
        if(r.success) setTimeout(() => location.reload(), 1000);
    } catch(e) {
        showToast("Network error", false);
    }
}
</script>
</body></html>
