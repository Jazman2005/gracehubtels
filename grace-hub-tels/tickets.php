<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_admin('support');

$page=max(1,(int)($_GET['page']??1));
$limit=20;
$offset=($page-1)*$limit;

$filter=$_GET['filter']??'all';
$q=$_GET['q']??'';

$where="1=1";$params=[];
if($filter==='open'){$where.=" AND t.status='open'";}
elseif($filter==='pending'){$where.=" AND t.status='pending'";}
elseif($filter==='resolved'){$where.=" AND t.status='resolved'";}

if($q){$where.=" AND (t.id LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR t.subject LIKE ?)";$l="%$q%";$params=[$l,$l,$l,$l];}

$total=db()->prepare("SELECT COUNT(*) FROM tickets t JOIN users u ON t.user_id=u.id WHERE $where");
$total->execute($params);
$total=$total->fetchColumn();
$pages=ceil($total/$limit);

$s=db()->prepare("SELECT t.*, u.full_name, u.email FROM tickets t JOIN users u ON t.user_id=u.id WHERE $where ORDER BY t.updated_at DESC LIMIT $limit OFFSET $offset");
$s->execute($params);
$tickets=$s->fetchAll();

$counts = db()->query("SELECT status, COUNT(*) as c FROM tickets GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$c_open = $counts['open']??0;
$c_pending = $counts['pending']??0;
$c_resolved = $counts['resolved']??0;
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Support Tickets — <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title><link rel="icon" type="image/png" href="/assets/images/favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header"><div><h2>Support Tickets</h2><p>Manage and respond to user inquiries</p></div></div>

<div class="metrics-grid" style="margin-bottom:20px;grid-template-columns:repeat(3,1fr)">
  <a href="?filter=open" class="metric-card" style="border-left:4px solid var(--danger);text-decoration:none">
    <div class="m-info"><span class="m-label">Open / New Tickets</span><strong class="m-val"><?=$c_open?></strong></div>
  </a>
  <a href="?filter=pending" class="metric-card" style="border-left:4px solid var(--warning);text-decoration:none">
    <div class="m-info"><span class="m-label">Pending / Waiting on User</span><strong class="m-val"><?=$c_pending?></strong></div>
  </a>
  <a href="?filter=resolved" class="metric-card" style="border-left:4px solid var(--success);text-decoration:none">
    <div class="m-info"><span class="m-label">Resolved Tickets</span><strong class="m-val"><?=$c_resolved?></strong></div>
  </a>
</div>

<div class="data-panel">
  <form class="filters">
    <input type="text" name="q" class="form-control" placeholder="Search ticket ID, user, or subject" value="<?=htmlspecialchars($q)?>">
    <button class="btn-primary" style="height:40px">Search</button>
  </form>
  
  <div class="responsive-grid-wrapper">
    <table class="data-table">
      <thead><tr><th>ID</th><th>User</th><th>Subject</th><th>Priority</th><th>Status</th><th>Last Update</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($tickets as $t):?>
      <tr class="clickable-row" onclick="location.href='ticket-view.php?id=<?=$t['id']?>'">
        <td style="font-weight:700">#<?=$t['id']?></td>
        <td>
          <div class="user-cell">
            <div class="u-avatar" style="background:var(--navy);color:#fff"><?=strtoupper(substr($t['full_name'],0,1))?></div>
            <div><span class="u-name"><?=htmlspecialchars($t['full_name'])?></span><span class="u-phone"><?=htmlspecialchars($t['email']??'-')?></span></div>
          </div>
        </td>
        <td style="max-width:250px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><strong><?=htmlspecialchars($t['subject'])?></strong></td>
        <td><span class="badge badge-<?=strtolower($t['priority'])==='high'?'danger':(strtolower($t['priority'])==='low'?'neutral':'warning')?>"><?=ucfirst($t['priority'])?></span></td>
        <td><span class="badge badge-<?=$t['status']==='open'?'danger':($t['status']==='pending'?'warning':'success')?>"><?=strtoupper($t['status'])?></span></td>
        <td style="font-size:12px;color:var(--text-muted)"><?=date('d M Y, H:i',strtotime($t['updated_at']))?></td>
        <td><button class="btn-sm btn-ghost">View Ticket</button></td>
      </tr>
      <?php endforeach;if(empty($tickets)):?><tr><td colspan="7" class="empty-state">No tickets found</td></tr><?php endif;?>
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
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
</script>
</body></html>
