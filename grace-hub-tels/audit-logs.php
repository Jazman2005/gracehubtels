<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_admin('superadmin'); // Only superadmin can view logs

$page=max(1,(int)($_GET['page']??1));
$limit=50;
$offset=($page-1)*$limit;

$total=db()->query("SELECT COUNT(*) FROM admin_activity_logs")->fetchColumn();
$pages=ceil($total/$limit);

$logs=db()->query("SELECT l.*, a.username FROM admin_activity_logs l JOIN admin a ON l.admin_id = a.id ORDER BY l.id DESC LIMIT $limit OFFSET $offset")->fetchAll();
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Audit Logs — Gracehubtels Admin</title><link rel="icon" type="image/png" href="/assets/images/gracehubtels-favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header"><div><h2>Activity Audit Logs</h2><p>Un-editable ledger of all administrative actions</p></div></div>

<div class="data-panel">
  <div class="responsive-grid-wrapper">
    <table class="data-table">
      <thead><tr><th>Time</th><th>Admin</th><th>Action</th><th>Details</th><th>IP Address</th></tr></thead>
      <tbody>
      <?php foreach($logs as $l):?>
      <tr>
        <td style="font-size:12px;color:var(--text-muted);white-space:nowrap"><?=date('d M Y, H:i:s',strtotime($l['created_at']))?></td>
        <td><strong><?=htmlspecialchars($l['username'])?></strong></td>
        <td><span class="badge badge-neutral" style="font-family:monospace"><?=htmlspecialchars($l['action'])?></span></td>
        <td style="font-size:13px"><?=htmlspecialchars($l['details'])?></td>
        <td style="font-size:12px;color:var(--text-muted)"><?=htmlspecialchars($l['ip_address']??'-')?></td>
      </tr>
      <?php endforeach;if(empty($logs)):?><tr><td colspan="5" class="empty-state">No activity logs found</td></tr><?php endif;?>
      </tbody>
    </table>
  </div>
  <?php if($pages>1):?>
  <div class="pagination"><span class="page-info">Page <?=$page?> of <?=$pages?></span>
  <?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++):?><a href="?page=<?=$i?>" class="page-btn <?=$i===$page?'active':''?>"><?=$i?></a><?php endfor;?>
  </div>
  <?php endif;?>
</div>

</div></div>
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
</script>
</body></html>
