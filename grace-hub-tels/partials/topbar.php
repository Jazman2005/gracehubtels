<?php
$admin=get_admin();
$pending_a2c=(int)db()->query("SELECT COUNT(*) FROM airtime_to_cash WHERE status='pending'")->fetchColumn();
$page_titles=[
  'dashboard.php'=>'Dashboard',
  'users-management.php'=>'Users',
  'transactions-management.php'=>'Transactions',
  'transaction-details.php'=>'Transaction Details',
  'transaction-limits.php'=>'Transaction Limits',
  'services-control.php'=>'Services & Pricing',
  'service-data.php'=>'Data Plans',
  'service-cable.php'=>'Cable TV',
  'service-electricity.php'=>'Electricity',
  'service-airtime.php'=>'Airtime',
  'service-pricing.php'=>'Pricing & Margins',
  'service-pin-products.php'=>'PIN Products',
  'service-recharge-cards.php'=>'Recharge Cards',
  'withdrawals.php'=>'Withdrawals',
  'manual-deposits.php'=>'Manual Deposits',
  'api-registry.php'=>'API Registry',
  'api-registry'=>'API Registry',
  'api-providers.php'=>'API Routes',
  'api-providers'=>'API Routes',
  'payment-gateways.php'=>'Payment Gateways',
  'sales-analysis.php'=>'Sales Analysis',
  'profit-analysis.php'=>'Profit Analysis',
  'api-logs.php'=>'API Logs & Errors',
  'cashback.php'=>'Cashback',
  'webhook-logs.php'=>'Webhook Logs',
  'kyc-management.php'=>'KYC Review',
  'a2c-management.php'=>'A2C Requests',
  'notifications.php'=>'Broadcast',
  'marketing.php'=>'Send Emails',
  'tickets.php'=>'Site Messages',
  'email.php'=>'SMTP Settings',
  'push-notifications.php'=>'Push Notifications',
  'promo-codes.php'=>'Promo Codes',
  'settings.php'=>'Settings',
  'staff-management.php'=>'Staff Management',
  'security-unit.php'=>'Security & Logs',
  'audit-logs.php'=>'Audit Logs',
  'referrals.php'=>'Referrals',
  'virtual-cards.php'=>'Virtual Cards',
  'cms-settings.php'=>'Appearance / CMS',
  'faq-manager.php'=>'FAQ Manager',
  'manage-account.php'=>'Manage Account'
];
$title=$page_titles[basename($_SERVER['PHP_SELF'])]??'Admin Panel';
?>
<header class="admin-topbar">
  <div class="topbar-left">
      <button class="hamburger" id="hamburgerBtn"><i class="fas fa-bars"></i></button>
      <div class="topbar-context">
          <h1><?=$title?></h1>
          <span><?=date('l, F j, Y')?></span>
      </div>
  </div>
  <div class="topbar-right">
      <?php if($pending_a2c>0):?>
      <a href="<?= ADMIN_URL ?>/a2c-management.php" class="topbar-alert">
          <i class="fas fa-circle" style="font-size:8px;color:var(--warning);"></i>
          <?=$pending_a2c?> pending action
      </a>
      <?php endif;?>
      <div class="admin-avatar"><?=strtoupper(substr($admin['username']??'A',0,1))?></div>
  </div>
</header>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ham = document.getElementById('hamburgerBtn');
    if (ham) {
        ham.addEventListener('click', function() {
            const sb = document.getElementById('sidebar');
            const main = document.querySelector('.main-wrap');
            const ov = document.getElementById('sidebarOverlay');
            if(window.matchMedia('(max-width: 1024px)').matches) {
                if(sb) sb.classList.toggle('open');
                if(ov) ov.classList.toggle('show');
            } else {
                if(sb) sb.classList.toggle('collapsed');
                if(main) main.classList.toggle('expanded');
            }
        });
    }
    // Sidebar overlay click
    const overlay = document.getElementById('sidebarOverlay');
    if (overlay) {
        overlay.addEventListener('click', function() {
            const sb = document.getElementById('sidebar');
            if(sb) sb.classList.remove('open');
            overlay.classList.remove('show');
        });
    }
});
</script>
