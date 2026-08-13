<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
require_once dirname(__DIR__).'/user/core/giftcard.php';
auth_admin();
require_permission('manage_withdrawals');

if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??'')) json_response(['success'=>false,'message'=>'Invalid token'],403);

    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if($action === 'approve' || $action === 'reject'){
        $req = db()->prepare("SELECT * FROM giftcard_sell_submissions WHERE id=?");
        $req->execute([$id]);
        $row = $req->fetch();
        if(!$row) json_response(['success'=>false,'message'=>'Submission not found']);

        $new_status = $action === 'approve' ? 'approved' : 'rejected';
        $note = sanitize($_POST['note'] ?? '', 500);

        // Atomic claim, same pattern as manual-deposits.php — only the
        // request that flips pending -> approved/rejected here is allowed
        // to proceed, so a double-click or two admins acting at once can't
        // both credit the wallet or both act on the same submission.
        $claim = db()->prepare("UPDATE giftcard_sell_submissions SET status=?, admin_note=?, reviewed_by=?, reviewed_at=NOW() WHERE id=? AND status='pending'");
        $claim->execute([$new_status, $note, $_SESSION['admin_id'], $id]);
        if ($claim->rowCount() === 0) {
            json_response(['success'=>false,'message'=>'Submission not found or already processed']);
        }

        if($action === 'approve') {
            $res = credit_wallet((int)$row['user_id'], (float)$row['payout_amount'], 'giftcard_sell', "{$row['brand']} gift card sold (₦".number_format((float)$row['card_value'],2)." value)", ['submission_id'=>$id,'brand'=>$row['brand']], 'giftcard_sell:'.$id);
            if($res['success']) {
                notify_user((int)$row['user_id'], 'Gift Card Approved', "Your {$row['brand']} gift card was approved — ₦".number_format((float)$row['payout_amount'],2)." credited to your wallet.", 'success', null, $res['balance_before'], $res['balance_after']);
                audit_log($_SESSION['admin_id'], 'APPROVE_GIFTCARD_SELL', "Approved gift card sell #$id for user #{$row['user_id']}, {$row['brand']}, N{$row['payout_amount']} credited, ref={$res['ref']}");
                json_response(['success'=>true,'message'=>'Approved and wallet credited']);
            } else {
                // Credit failed after we already claimed the row — release
                // the claim so it can be retried/investigated instead of
                // sitting 'approved' with no actual payout.
                db()->prepare("UPDATE giftcard_sell_submissions SET status='pending', reviewed_by=NULL, reviewed_at=NULL WHERE id=?")->execute([$id]);
                json_response(['success'=>false,'message'=>$res['duplicate'] ?? false ? 'This submission was already credited' : 'Failed to credit wallet']);
            }
        } else {
            notify_user((int)$row['user_id'], 'Gift Card Rejected', "Your {$row['brand']} gift card submission was not approved." . ($note ? " Reason: {$note}" : ''), 'danger');
            audit_log($_SESSION['admin_id'], 'REJECT_GIFTCARD_SELL', "Rejected gift card sell #$id for user #{$row['user_id']}, {$row['brand']}" . ($note ? " — {$note}" : ''));
            json_response(['success'=>true,'message'=>'Submission rejected']);
        }
    }
}

$csrf = admin_csrf();
$page = max(1,(int)($_GET['page']??1));
$limit = 20;
$offset = ($page-1)*$limit;
$filter = $_GET['filter'] ?? 'pending';
$q = $_GET['q'] ?? '';

$where = "1=1"; $params = [];
if($filter==='pending'){$where.=" AND g.status='pending'";}
elseif($filter==='approved'){$where.=" AND g.status='approved'";}
elseif($filter==='rejected'){$where.=" AND g.status='rejected'";}
if($q){$where.=" AND (g.brand LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";$l="%$q%";$params=[$l,$l,$l];}

$total = db()->prepare("SELECT COUNT(*) FROM giftcard_sell_submissions g JOIN users u ON g.user_id=u.id WHERE $where");
$total->execute($params);
$total = $total->fetchColumn();
$pages = ceil($total/$limit);

$s = db()->prepare("SELECT g.*, u.full_name, u.email FROM giftcard_sell_submissions g JOIN users u ON g.user_id=u.id WHERE $where ORDER BY g.created_at DESC LIMIT $limit OFFSET $offset");
$s->execute($params);
$submissions = $s->fetchAll();

$counts = db()->query("SELECT status, COUNT(*) as c FROM giftcard_sell_submissions GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$c_pending = $counts['pending'] ?? 0;
$c_approved = $counts['approved'] ?? 0;
$c_rejected = $counts['rejected'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Gift Card Sell Review — <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title><link rel="icon" type="image/png" href="/assets/images/favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header"><div><h2>Gift Card Sell Review</h2><p>Verify submitted cards and approve or reject payouts — nothing is credited automatically.</p></div></div>

<div class="metrics-grid" style="margin-bottom:20px;grid-template-columns:repeat(3,1fr)">
  <a href="?filter=pending" class="metric-card" style="border-left:4px solid var(--warning);text-decoration:none">
    <div class="m-info"><span class="m-label">Pending Review</span><strong class="m-val"><?=$c_pending?></strong></div>
  </a>
  <a href="?filter=approved" class="metric-card" style="border-left:4px solid var(--success);text-decoration:none">
    <div class="m-info"><span class="m-label">Approved</span><strong class="m-val"><?=$c_approved?></strong></div>
  </a>
  <a href="?filter=rejected" class="metric-card" style="border-left:4px solid var(--danger);text-decoration:none">
    <div class="m-info"><span class="m-label">Rejected</span><strong class="m-val"><?=$c_rejected?></strong></div>
  </a>
</div>

<div class="data-panel">
  <form class="filters" style="display:flex; gap:12px; margin-bottom:24px; padding: 16px; background: var(--surface-hover); border-radius: var(--radius-sm); border: 1px solid var(--border-light);">
    <input type="hidden" name="filter" value="<?=htmlspecialchars($filter)?>">
    <input type="text" name="q" class="form-control" placeholder="Search brand, user, or email" value="<?=htmlspecialchars($q)?>" style="flex:1;">
    <button class="btn-primary" style="height:42px; padding: 0 24px;">Search</button>
  </form>

  <div class="responsive-grid-wrapper">
    <table class="data-table">
      <thead><tr><th>Date</th><th>User</th><th>Brand</th><th>Value / Payout</th><th>Card Code</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($submissions as $r):?>
      <tr>
        <td style="font-size:12px;color:var(--text-muted)"><?=date('d M Y, H:i',strtotime($r['created_at']))?></td>
        <td>
          <div class="user-cell">
            <div class="u-avatar" style="background:var(--navy);color:#fff"><?=strtoupper(substr($r['full_name'],0,1))?></div>
            <div><span class="u-name"><?=htmlspecialchars($r['full_name'])?></span><span class="u-phone"><?=htmlspecialchars($r['email']??'-')?></span></div>
          </div>
        </td>
        <td><strong><?=htmlspecialchars($r['brand'])?></strong></td>
        <td style="font-size:12px">₦<?=number_format($r['card_value'],2)?> value<br><strong style="color:var(--navy-dark)">₦<?=number_format($r['payout_amount'],2)?> payout (<?=$r['payout_rate']?>%)</strong></td>
        <td><code style="font-size:11px;background:#F3F4F6;padding:4px 6px;border-radius:6px;word-break:break-all;display:inline-block;max-width:180px"><?=htmlspecialchars($r['card_code'])?></code></td>
        <td><span class="badge badge-<?=$r['status']==='rejected'?'danger':($r['status']==='pending'?'warning':'success')?>"><?=strtoupper($r['status'])?></span>
          <?php if($r['admin_note']):?><div style="font-size:11px;color:var(--text-muted);margin-top:4px"><?=htmlspecialchars($r['admin_note'])?></div><?php endif;?>
        </td>
        <td>
          <?php if($r['status']==='pending'): ?>
          <div style="display:flex;flex-direction:column;gap:6px">
            <button class="btn-primary btn-sm" style="background:var(--success);background-image:none;border-radius:6px;padding:8px" onclick="processSubmission(<?=$r['id']?>, 'approve')"><i class="fas fa-check"></i> Approve</button>
            <button class="btn-primary btn-sm" style="background:var(--danger);background-image:none;border-radius:6px;padding:8px" onclick="processSubmission(<?=$r['id']?>, 'reject')"><i class="fas fa-times"></i> Reject</button>
          </div>
          <?php else: ?>
            <span style="font-size:12px;color:#9CA3AF">Processed</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach;if(empty($submissions)):?><tr><td colspan="7" class="empty-state">No submissions found</td></tr><?php endif;?>
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

async function processSubmission(id, action) {
    let note = '';
    if(action === 'approve') {
        if(!confirm("Approve this submission and credit the user's wallet with the payout amount?")) return;
    } else {
        note = prompt("Reason for rejection (optional, shown to the user):") || '';
        if(note === null) return;
    }

    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('action', action);
    fd.append('id', id);
    fd.append('note', note);

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
