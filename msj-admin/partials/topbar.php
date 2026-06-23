<?php
$admin=get_admin();
$pending_a2c=(int)db()->query("SELECT COUNT(*) FROM airtime_to_cash WHERE status IN ('pending','processing')")->fetchColumn();
$page_titles=['dashboard.php'=>'Dashboard','users-management.php'=>'Users','transactions-management.php'=>'Transactions','services-control.php'=>'Services & Pricing','api-providers.php'=>'API Providers','profit-analysis.php'=>'Profit Analysis','cashback.php'=>'Cashback','webhook-logs.php'=>'Webhook Logs','kyc-management.php'=>'KYC Review','a2c-management.php'=>'A2C Requests','notifications.php'=>'Notifications','email.php'=>'Email','api-settings.php'=>'API Settings','settings.php'=>'Settings'];
$title=$page_titles[basename($_SERVER['PHP_SELF'])]??'Admin Panel';
?>
<header class="admin-topbar">
  <button class="hamburger" onclick="toggleSidebar()"><svg viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
  <div class="topbar-title"><h1><?=$title?></h1><span><?=date('D, d M Y')?></span></div>
  <div class="topbar-right">
    <?php if($pending_a2c>0):?><a href="/msjpay/msj-admin/a2c-management.php" class="topbar-alert"><svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg><?=$pending_a2c?> A2C pending</a><?php endif;?>
    <div class="admin-avatar"><?=strtoupper(substr($admin['username']??'A',0,1))?></div>
  </div>
</header>
<script>
function toggleSidebar() {
  const sb = document.getElementById('sidebar');
  const main = document.querySelector('.main-wrap');
  if(sb) sb.classList.toggle('collapsed');
  if(main) main.classList.toggle('expanded');
}
// For mobile responsive toggle
function toggleMobileSidebar() {
  const sb = document.getElementById('sidebar');
  if(sb) sb.classList.toggle('open');
}
</script>
