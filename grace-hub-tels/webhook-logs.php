<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
$page=max(1,(int)($_GET['page']??1));$per=30;$offset=($page-1)*$per;
$total=(int)db()->query("SELECT COUNT(*) FROM webhook_logs")->fetchColumn();
$logs=db()->prepare("SELECT * FROM webhook_logs ORDER BY created_at DESC LIMIT ? OFFSET ?");$logs->execute([$per,$offset]);$logs=$logs->fetchAll();
$pages=(int)ceil($total/$per);
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Webhook Logs — Gracehubtels Admin</title><link rel="icon" type="image/png" href="/assets/images/gracehubtels-favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">
<div class="section-header"><div><h2>Webhook Logs</h2><p><?=number_format($total)?> webhook events received</p></div></div>
<div class="data-panel"><div class="responsive-grid-wrapper"><table class="data-table">
<thead><tr><th>Provider</th><th>Event</th><th>Account/Ref</th><th>Amount</th><th>Signature</th><th>Processed</th><th>Date</th></tr></thead><tbody>
<?php foreach($logs as $l):?>
<tr><td><strong><?=ucfirst($l['provider'])?></strong></td><td style="font-size:12px"><?=htmlspecialchars($l['event_type']??'—')?></td><td style="font-size:11px"><?=htmlspecialchars(substr($l['account_number']??$l['transaction_ref']??'',0,20))?></td><td><?=$l['amount']?'₦'.number_format((float)$l['amount'],2):'—'?></td><td><span class="badge <?=$l['signature_valid']?'badge-success':'badge-danger'?>"><?=$l['signature_valid']?'Valid':'Invalid'?></span></td><td><span class="badge <?=$l['processed']?'badge-success':'badge-pending'?>"><?=$l['processed']?'Yes':'No'?></span></td><td style="font-size:12px;color:#6B7280"><?=date('d M, H:i',strtotime($l['created_at']))?></td></tr>
<?php endforeach;if(empty($logs)):?><tr><td colspan="7" class="empty-state">No webhook events yet</td></tr><?php endif;?>
</tbody></table></div>
<?php if($pages>1):?><div class="pagination"><span class="page-info">Page <?=$page?> of <?=$pages?></span><?php for($i=1;$i<=$pages;$i++):?><a href="?page=<?=$i?>" class="page-btn <?=$i===$page?'active':''?>"><?=$i?></a><?php endfor;?></div><?php endif;?>
</div>
</div></div>
<script>function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}</script>
</body></html>
