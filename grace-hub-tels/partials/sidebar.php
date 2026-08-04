<?php
$cur=basename($_SERVER['PHP_SELF']);
$admin=get_admin();
$pending_a2c=(int)db()->query("SELECT COUNT(*) FROM airtime_to_cash WHERE status='pending'")->fetchColumn();
$kyc_pending=(int)db()->query("SELECT COUNT(*) FROM kyc_requests WHERE status='pending'")->fetchColumn();
$pending_withdrawals=(int)db()->query("SELECT COUNT(*) FROM transactions WHERE category='bank_transfer' AND status='pending'")->fetchColumn();
$pending_deposits=(int)db()->query("SELECT COUNT(*) FROM manual_funding_requests WHERE status='pending'")->fetchColumn();
$_admin_perms=[];if(($_SESSION['admin_role']??'')!=='superadmin'){$_aps=db()->prepare("SELECT permissions FROM admin WHERE id=?");$_aps->execute([$_SESSION['admin_id']??0]);$_raw=$_aps->fetchColumn();if($_raw)$_admin_perms=json_decode($_raw,true)??[];}
function _has_perm(string $p):bool{global$_admin_perms;return($_SESSION['admin_role']??'')==='superadmin'||in_array($p,$_admin_perms);}
function _has_any(array $perms):bool{foreach($perms as$p){if(_has_perm($p))return true;}return false;}
$uname=htmlspecialchars($admin['username']??'Admin');
$urole=ucfirst($_SESSION['admin_role']??'admin');
$uinitial=strtoupper(substr($admin['username']??'A',0,1));
?>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <a href="<?= ADMIN_URL ?>/dashboard.php" class="brand-link">
      <span class="brand-icon">G</span>
      <span class="brand-info">
        <span class="brand-name">Gracehubtels</span>
        <span class="brand-label">Admin Panel</span>
      </span>
    </a>
  </div>

  <div class="sidebar-user">
    <span class="user-avatar"><?=$uinitial?></span>
    <span class="user-meta">
      <span class="user-name"><?=$uname?></span>
      <span class="user-role"><?=$urole?></span>
    </span>
  </div>

  <div class="sidebar-body">
    <?php if(_has_perm('view_dashboard')):?>
    <a href="<?= ADMIN_URL ?>/dashboard.php" class="nav-item <?=$cur==='dashboard.php'?'active':''?>">
      <span class="nav-icon"><i class="fas fa-home"></i></span>
      <span class="nav-text">Dashboard</span>
    </a>
    <?php endif;?>

    <?php if(_has_any(['view_transactions','manage_transactions','manage_withdrawals','manage_deposits'])):?>
    <div class="nav-label">Core Operations</div>
    <?php endif;?>
    <?php if(_has_any(['view_transactions','manage_transactions'])):?>
    <div class="nav-item nav-dropdown" onclick="toggleNavSub(this)">
      <span class="nav-icon"><i class="fas fa-arrow-right-arrow-left"></i></span>
      <span class="nav-text">Transactions</span>
      <span class="nav-chevron"><i class="fas fa-chevron-down"></i></span>
    </div>
    <div class="nav-sub<?=($cur==='transactions-management.php'||$cur==='transaction-details.php')?' open':''?>">
      <a href="<?= ADMIN_URL ?>/transactions-management.php" class="nav-sub-item <?=($cur==='transactions-management.php'&&(!isset($_GET['tab'])||$_GET['tab']==='all')&&!isset($_GET['ref']))?'active':''?>">All</a>
      <a href="<?= ADMIN_URL ?>/transactions-management.php?tab=pending" class="nav-sub-item <?=($cur==='transactions-management.php'&&isset($_GET['tab'])&&$_GET['tab']==='pending')?'active':''?>">Pending</a>
      <a href="<?= ADMIN_URL ?>/transactions-management.php?tab=success" class="nav-sub-item <?=($cur==='transactions-management.php'&&isset($_GET['tab'])&&$_GET['tab']==='success')?'active':''?>">Successful</a>
      <a href="<?= ADMIN_URL ?>/transactions-management.php?tab=failed" class="nav-sub-item <?=($cur==='transactions-management.php'&&isset($_GET['tab'])&&$_GET['tab']==='failed')?'active':''?>">Failed</a>
      <a href="<?= ADMIN_URL ?>/transactions-management.php?tab=sales" class="nav-sub-item <?=($cur==='transactions-management.php'&&isset($_GET['tab'])&&$_GET['tab']==='sales')?'active':''?>">Sales Analysis</a>
      <a href="<?= ADMIN_URL ?>/transactions-management.php?tab=users" class="nav-sub-item <?=($cur==='transactions-management.php'&&isset($_GET['tab'])&&$_GET['tab']==='users')?'active':''?>">Users Analysis</a>
    </div>
    <?php endif;?>
    <?php if(_has_perm('manage_withdrawals')):?>
    <a href="<?= ADMIN_URL ?>/withdrawals.php" class="nav-item <?=$cur==='withdrawals.php'?'active':''?>">
      <span class="nav-icon"><i class="fas fa-money-bill-transfer"></i></span>
      <span class="nav-text">Withdrawals</span>
      <?php if($pending_withdrawals>0):?><span class="nav-badge"><?=$pending_withdrawals?></span><?php endif;?>
    </a>
    <?php endif;?>
    <?php if(_has_perm('manage_deposits')):?>
    <a href="<?= ADMIN_URL ?>/manual-deposits.php" class="nav-item <?=$cur==='manual-deposits.php'?'active':''?>">
      <span class="nav-icon"><i class="fas fa-hand-holding-dollar"></i></span>
      <span class="nav-text">Manual Deposits</span>
      <?php if($pending_deposits>0):?><span class="nav-badge"><?=$pending_deposits?></span><?php endif;?>
    </a>
    <?php endif;?>

    <?php if(_has_any(['manage_airtime','manage_data','manage_electricity','manage_cabletv','manage_pricing','manage_cards','manage_transactions'])):?>
    <div class="nav-label">Services</div>
    <?php endif;?>
    <?php if(_has_perm('manage_data')):?>
    <div class="nav-item nav-dropdown" onclick="toggleNavSub(this)">
      <span class="nav-icon"><i class="fas fa-wifi"></i></span>
      <span class="nav-text">Data Plans</span>
      <span class="nav-chevron"><i class="fas fa-chevron-down"></i></span>
    </div>
    <div class="nav-sub<?=($cur==='service-data.php'&&!empty($_GET['network']))?' open':''?>">
      <a href="<?= ADMIN_URL ?>/service-data.php" class="nav-sub-item <?=$cur==='service-data.php'&&empty($_GET['network'])?'active':''?>">All Networks</a>
      <a href="<?= ADMIN_URL ?>/service-data.php?network=MTN" class="nav-sub-item <?=($cur==='service-data.php'&&($_GET['network']??'')==='MTN')?'active':''?>">MTN</a>
      <a href="<?= ADMIN_URL ?>/service-data.php?network=GLO" class="nav-sub-item <?=($cur==='service-data.php'&&($_GET['network']??'')==='GLO')?'active':''?>">GLO</a>
      <a href="<?= ADMIN_URL ?>/service-data.php?network=AIRTEL" class="nav-sub-item <?=($cur==='service-data.php'&&($_GET['network']??'')==='AIRTEL')?'active':''?>">AIRTEL</a>
      <a href="<?= ADMIN_URL ?>/service-data.php?network=9MOBILE" class="nav-sub-item <?=($cur==='service-data.php'&&($_GET['network']??'')==='9MOBILE')?'active':''?>">9MOBILE</a>
    </div>
    <?php endif;?>
    <?php if(_has_perm('manage_cabletv')):?>
    <a href="<?= ADMIN_URL ?>/service-cable.php" class="nav-item <?=$cur==='service-cable.php'?'active':''?>">
      <span class="nav-icon"><i class="fas fa-tv"></i></span>
      <span class="nav-text">Cable TV</span>
    </a>
    <?php endif;?>
    <?php if(_has_perm('manage_electricity')):?>
    <a href="<?= ADMIN_URL ?>/service-electricity.php" class="nav-item <?=$cur==='service-electricity.php'?'active':''?>">
      <span class="nav-icon"><i class="fas fa-bolt"></i></span>
      <span class="nav-text">Electricity</span>
    </a>
    <?php endif;?>
    <a href="<?= ADMIN_URL ?>/service-betting.php" class="nav-item <?=$cur==='service-betting.php'?'active':''?>">
      <span class="nav-icon"><i class="fas fa-futbol"></i></span>
      <span class="nav-text">Betting</span>
    </a>
    <?php if(_has_perm('manage_pricing')):?>
    <a href="<?= ADMIN_URL ?>/service-pricing.php" class="nav-item <?=$cur==='service-pricing.php'?'active':''?>">
      <span class="nav-icon"><i class="fas fa-tags"></i></span>
      <span class="nav-text">Pricing &amp; Margins</span>
    </a>
    <?php endif;?>
    <?php if(_has_perm('manage_cards')):?>
    <a href="<?= ADMIN_URL ?>/virtual-cards.php" class="nav-item <?=$cur==='virtual-cards.php'?'active':''?>">
      <span class="nav-icon"><i class="fas fa-credit-card"></i></span>
      <span class="nav-text">Virtual Cards</span>
    </a>
    <?php endif;?>
    <?php if(_has_perm('manage_pricing')):?>
    <div class="nav-item nav-dropdown" onclick="toggleNavSub(this)">
      <span class="nav-icon"><i class="fas fa-ticket"></i></span>
      <span class="nav-text">PIN Products</span>
      <span class="nav-chevron"><i class="fas fa-chevron-down"></i></span>
    </div>
    <div class="nav-sub<?=($cur==='service-pin-products.php'||$cur==='exam-pins-stock.php')?' open':''?>">
      <a href="<?= ADMIN_URL ?>/service-pin-products.php" class="nav-sub-item <?=($cur==='service-pin-products.php'&&(!isset($_GET['tab'])||$_GET['tab']==='recharge'))?'active':''?>">Recharge Cards</a>
      <a href="<?= ADMIN_URL ?>/service-pin-products.php?tab=data_card" class="nav-sub-item <?=($cur==='service-pin-products.php'&&isset($_GET['tab'])&&$_GET['tab']==='data_card')?'active':''?>">Data Cards</a>
      <a href="<?= ADMIN_URL ?>/service-pin-products.php?tab=exam_pin" class="nav-sub-item <?=($cur==='service-pin-products.php'&&isset($_GET['tab'])&&$_GET['tab']==='exam_pin')?'active':''?>">Exam PINs History</a>
      <a href="<?= ADMIN_URL ?>/exam-pins-stock.php" class="nav-sub-item <?=($cur==='exam-pins-stock.php'&&empty($_GET['exam']))?'active':''?>">Local Exam Stock (All)</a>
      <a href="<?= ADMIN_URL ?>/exam-pins-stock.php?exam=WAEC" class="nav-sub-item <?=($cur==='exam-pins-stock.php'&&($_GET['exam']??'')==='WAEC')?'active':''?>">- WAEC Stock</a>
      <a href="<?= ADMIN_URL ?>/exam-pins-stock.php?exam=NECO" class="nav-sub-item <?=($cur==='exam-pins-stock.php'&&($_GET['exam']??'')==='NECO')?'active':''?>">- NECO Stock</a>
      <a href="<?= ADMIN_URL ?>/exam-pins-stock.php?exam=NABTEB" class="nav-sub-item <?=($cur==='exam-pins-stock.php'&&($_GET['exam']??'')==='NABTEB')?'active':''?>">- NABTEB Stock</a>
      <a href="<?= ADMIN_URL ?>/service-pin-products.php?tab=bulk_sms" class="nav-sub-item <?=($cur==='service-pin-products.php'&&isset($_GET['tab'])&&$_GET['tab']==='bulk_sms')?'active':''?>">Bulk SMS</a>
    </div>
    <a href="<?= ADMIN_URL ?>/service-recharge-cards.php" class="nav-item <?=$cur==='service-recharge-cards.php'?'active':''?>">
      <span class="nav-icon"><i class="fas fa-credit-card"></i></span>
      <span class="nav-text">Recharge Cards</span>
    </a>
    <?php endif;?>

    <?php if(_has_any(['view_users','manage_users','manage_referrals'])):?>
    <div class="nav-label">Users</div>
    <?php endif;?>
    <?php if(_has_any(['view_users','manage_users'])):?>
    <a href="<?= ADMIN_URL ?>/users-management.php" class="nav-item <?=$cur==='users-management.php'?'active':''?>">
      <span class="nav-icon"><i class="fas fa-users"></i></span>
      <span class="nav-text">Customers</span>
      <?php if($kyc_pending>0):?><span class="nav-badge"><?=$kyc_pending?></span><?php endif;?>
    </a>
    <?php endif;?>
    <?php if(_has_perm('manage_users')):?>
    <a href="<?= ADMIN_URL ?>/kyc-management.php" class="nav-item <?=$cur==='kyc-management.php'?'active':''?>">
      <span class="nav-icon"><i class="fas fa-id-card"></i></span>
      <span class="nav-text">KYC Review</span>
    </a>
    <?php endif;?>
    <?php if(_has_perm('manage_referrals')):?>
    <a href="<?= ADMIN_URL ?>/referrals.php" class="nav-item <?=$cur==='referrals.php'?'active':''?>">
      <span class="nav-icon"><i class="fas fa-share-nodes"></i></span>
      <span class="nav-text">Referrals</span>
    </a>
    <?php endif;?>

    <?php if(_has_any(['manage_messaging','manage_promo'])):?>
    <div class="nav-label">Comms</div>
    <?php endif;?>
    <?php if(_has_perm('manage_messaging')):?>
    <div class="nav-item nav-dropdown" onclick="toggleNavSub(this)">
      <span class="nav-icon"><i class="fas fa-envelope-open-text"></i></span>
      <span class="nav-text">Messaging</span>
      <span class="nav-chevron"><i class="fas fa-chevron-down"></i></span>
    </div>
    <div class="nav-sub<?=($cur==='notifications.php'||$cur==='marketing.php'||$cur==='tickets.php')?' open':''?>">
      <a href="<?= ADMIN_URL ?>/notifications.php" class="nav-sub-item <?=$cur==='notifications.php'?'active':''?>">Dashboard Announcements</a>
      <a href="<?= ADMIN_URL ?>/marketing.php" class="nav-sub-item <?=$cur==='marketing.php'?'active':''?>">Send Emails</a>
      <a href="<?= ADMIN_URL ?>/tickets.php" class="nav-sub-item <?=$cur==='tickets.php'?'active':''?>">Site Messages</a>
    </div>
    <a href="<?= ADMIN_URL ?>/email.php" class="nav-item <?=$cur==='email.php'?'active':''?>">
      <span class="nav-icon"><i class="fas fa-at"></i></span>
      <span class="nav-text">SMTP Settings</span>
    </a>
    <a href="<?= ADMIN_URL ?>/push-notifications.php" class="nav-item <?=$cur==='push-notifications.php'?'active':''?>">
      <span class="nav-icon"><i class="fas fa-bell"></i></span>
      <span class="nav-text">Push Notifications</span>
    </a>
    <?php endif;?>
    <?php if(_has_perm('manage_promo')):?>
    <a href="<?= ADMIN_URL ?>/promo-codes.php" class="nav-item <?=$cur==='promo-codes.php'?'active':''?>">
      <span class="nav-icon"><i class="fas fa-ticket"></i></span>
      <span class="nav-text">Promo Codes</span>
    </a>
    <?php endif;?>

    <?php if(_has_any(['manage_api','manage_gateways','view_profit','view_apilogs'])):?>
    <div class="nav-label">System</div>
    <?php endif;?>
    <?php if(_has_perm('manage_api')):?>
    <div class="nav-item nav-dropdown" onclick="toggleNavSub(this)">
      <span class="nav-icon"><i class="fas fa-gear"></i></span>
      <span class="nav-text">API Setup</span>
      <span class="nav-chevron"><i class="fas fa-chevron-down"></i></span>
    </div>
    <div class="nav-sub<?=($cur==='api-registry'||$cur==='api-registry.php'||$cur==='api-providers'||$cur==='api-providers.php')?' open':''?>">
      <a href="<?= ADMIN_URL ?>/api-registry" class="nav-sub-item <?=$cur==='api-registry'||$cur==='api-registry.php'?'active':''?>">API Registry</a>
      <a href="<?= ADMIN_URL ?>/api-providers" class="nav-sub-item <?=$cur==='api-providers'||$cur==='api-providers.php'?'active':''?>">API Routes</a>
    </div>
    <?php endif;?>
    <?php if(_has_perm('manage_gateways')):?>
    <a href="<?= ADMIN_URL ?>/payment-gateways.php" class="nav-item <?=$cur==='payment-gateways.php'||$cur==='manual-funding.php'?'active':''?>">
      <span class="nav-icon"><i class="fas fa-credit-card"></i></span>
      <span class="nav-text">Payment Gateways</span>
    </a>
    <?php endif;?>
    <?php if(_has_perm('view_profit')):?>
    <a href="<?= ADMIN_URL ?>/sales-analysis" class="nav-item <?=$cur==='sales-analysis.php'?'active':''?>">
      <span class="nav-icon"><i class="fas fa-chart-simple"></i></span>
      <span class="nav-text">Sales Analysis</span>
    </a>
    <a href="<?= ADMIN_URL ?>/profit-analysis" class="nav-item <?=$cur==='profit-analysis.php'?'active':''?>">
      <span class="nav-icon"><i class="fas fa-chart-line"></i></span>
      <span class="nav-text">Profit Analysis</span>
    </a>
    <?php endif;?>
    <?php if(_has_perm('view_apilogs')):?>
    <a href="<?= ADMIN_URL ?>/api-logs.php" class="nav-item <?=$cur==='api-logs.php'?'active':''?>">
      <span class="nav-icon"><i class="fas fa-bug"></i></span>
      <span class="nav-text">API Logs &amp; Errors</span>
    </a>
    <?php endif;?>
    <?php if(_has_perm('manage_settings')):?>
    <a href="<?= ADMIN_URL ?>/transaction-limits.php" class="nav-item <?=$cur==='transaction-limits.php'?'active':''?>">
      <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
      <span class="nav-text">Transaction Limits</span>
    </a>
    <a href="<?= ADMIN_URL ?>/settings.php" class="nav-item <?=$cur==='settings.php'?'active':''?>">
      <span class="nav-icon"><i class="fas fa-sliders"></i></span>
      <span class="nav-text">Settings</span>
    </a>
    <a href="<?= ADMIN_URL ?>/settings.php#network-prefixes" class="nav-item">
      <span class="nav-icon"><i class="fas fa-network-wired"></i></span>
      <span class="nav-text">Network Prefixes</span>
    </a>
    <?php endif;?>
    <?php if(_has_any(['manage_security','manage_settings'])):?>
    <div class="nav-label">Security</div>
    <?php endif;?>
    <?php if(_has_perm('manage_settings')):?>
    <a href="<?= ADMIN_URL ?>/staff-management.php" class="nav-item <?=$cur==='staff-management.php'?'active':''?>">
      <span class="nav-icon"><i class="fas fa-user-shield"></i></span>
      <span class="nav-text">Staff Management</span>
    </a>
    <?php endif;?>
    <?php if(_has_perm('manage_security')):?>
    <a href="<?= ADMIN_URL ?>/security-unit.php" class="nav-item <?=$cur==='security-unit.php'||$cur==='audit-logs.php'?'active':''?>">
      <span class="nav-icon"><i class="fas fa-shield-halved"></i></span>
      <span class="nav-text">Security &amp; Logs</span>
    </a>
    <?php endif;?>

    <?php if(_has_perm('manage_settings')):?>
    <div class="nav-label">Appearance</div>
    <a href="<?= ADMIN_URL ?>/cms-settings.php" class="nav-item <?=$cur==='cms-settings.php'?'active':''?>">
      <span class="nav-icon"><i class="fas fa-palette"></i></span>
      <span class="nav-text">Appearance / CMS</span>
    </a>
    <a href="<?= ADMIN_URL ?>/faq-manager.php" class="nav-item <?=$cur==='faq-manager.php'?'active':''?>">
      <span class="nav-icon"><i class="fas fa-question-circle"></i></span>
      <span class="nav-text">FAQ Manager</span>
    </a>
    <?php endif;?>
  </div>

  <div class="sidebar-foot">
    <button class="nav-item dark-toggle" onclick="toggleDarkMode()" data-tooltip="Toggle theme">
      <span class="nav-icon"><i class="fas fa-moon"></i></span>
      <span class="nav-text">Dark Mode</span>
    </button>
    <a href="<?= ADMIN_URL ?>/manage-account.php" class="nav-item <?=$cur==='manage-account.php'?'active':''?>">
      <span class="nav-icon"><i class="fas fa-user-gear"></i></span>
      <span class="nav-text">Manage Account</span>
    </a>
    <a href="<?= ADMIN_URL ?>/logout.php" class="nav-item nav-logout">
      <span class="nav-icon"><i class="fas fa-right-from-bracket"></i></span>
      <span class="nav-text">Logout</span>
    </a>
  </div>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const sb = document.getElementById('sidebar');
    const body = sb.querySelector('.sidebar-body');
    const pos = sessionStorage.getItem('adminSidebarScroll');
    if (pos) body.scrollTop = parseInt(pos, 10);
    body.addEventListener('scroll', () => {
        sessionStorage.setItem('adminSidebarScroll', body.scrollTop);
    });
});
function toggleNavSub(el) {
    el.classList.toggle('open');
    const sub = el.nextElementSibling;
    if (sub && sub.classList.contains('nav-sub')) {
        sub.classList.toggle('open');
    }
}
function toggleDarkMode() {
    const html = document.documentElement;
    const isDark = html.getAttribute('data-theme') === 'dark';
    html.setAttribute('data-theme', isDark ? 'light' : 'dark');
    localStorage.setItem('adminTheme', isDark ? 'light' : 'dark');
}
(function(){
    const saved = localStorage.getItem('adminTheme');
    if (saved === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
    // Page enter animation
    const container = document.querySelector('.admin-fluid-container');
    if (container) container.classList.add('page-enter');
})();
</script>
