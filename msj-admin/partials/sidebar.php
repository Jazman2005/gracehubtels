<?php
$cur=basename($_SERVER['PHP_SELF']);
$pending_a2c=(int)db()->query("SELECT COUNT(*) FROM airtime_to_cash WHERE status IN ('pending','processing')")->fetchColumn();
$kyc_pending=(int)db()->query("SELECT COUNT(*) FROM kyc_requests WHERE status='pending'")->fetchColumn();
$pending_withdrawals=(int)db()->query("SELECT COUNT(*) FROM transactions WHERE category='bank_transfer' AND status='pending'")->fetchColumn();
$pending_deposits=(int)db()->query("SELECT COUNT(*) FROM manual_funding_requests WHERE status='pending'")->fetchColumn();
?>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <div class="s-logo">M</div>
    <span class="brand-text" style="color:var(--primary); font-weight:900; font-size:22px; letter-spacing:-1px;">MsjPay</span>
    <span class="admin-badge">Admin</span>
  </div>
  <nav class="sidebar-nav">
    <a href="/msjpay/msj-admin/dashboard.php" class="nav-link <?=$cur==='dashboard.php'?'active':''?>">
        <i class="fas fa-home" style="width:20px;text-align:center;"></i><span>Dashboard</span>
    </a>

    <div class="nav-section">SERVICES</div>
    <a href="/msjpay/msj-admin/services-hub.php" class="nav-link <?=$cur==='services-hub.php'||$cur==='services-control.php'?'active':''?>">
        <i class="fas fa-cubes" style="width:20px;text-align:center;"></i><span>All Services</span>
    </a>

    <div class="nav-section">TRANSACTION</div>
    <a href="/msjpay/msj-admin/transactions-management.php" class="nav-link <?=$cur==='transactions-management.php'||$cur==='transaction-details.php'?'active':''?>">
        <i class="fas fa-exchange-alt" style="width:20px;text-align:center;"></i><span>Transactions</span><?php if($pending_a2c>0):?><span class="nav-badge warn"><?=$pending_a2c?></span><?php endif;?>
    </a>
    <a href="/msjpay/msj-admin/withdrawals.php" class="nav-link <?=$cur==='withdrawals.php'?'active':''?>">
        <i class="fas fa-money-bill-wave" style="width:20px;text-align:center;"></i><span>Withdrawals</span><?php if($pending_withdrawals>0):?><span class="nav-badge warn"><?=$pending_withdrawals?></span><?php endif;?>
    </a>
    <a href="/msjpay/msj-admin/manual-deposits.php" class="nav-link <?=$cur==='manual-deposits.php'?'active':''?>">
        <i class="fas fa-hand-holding-dollar" style="width:20px;text-align:center;"></i><span>Manual Deposits</span><?php if($pending_deposits>0):?><span class="nav-badge warn"><?=$pending_deposits?></span><?php endif;?>
    </a>

    <div class="nav-section">USERS</div>
    <a href="/msjpay/msj-admin/users-management.php" class="nav-link <?=$cur==='users-management.php'?'active':''?>">
        <i class="fas fa-users" style="width:20px;text-align:center;"></i><span>Customers</span><?php if($kyc_pending>0):?><span class="nav-badge warn"><?=$kyc_pending?></span><?php endif;?>
    </a>
    <a href="/msjpay/msj-admin/kyc-management.php" class="nav-link <?=$cur==='kyc-management.php'?'active':''?>">
        <i class="fas fa-id-card" style="width:20px;text-align:center;"></i><span>KYC Review</span>
    </a>
    <a href="/msjpay/msj-admin/referrals.php" class="nav-link <?=$cur==='referrals.php'?'active':''?>">
        <i class="fas fa-people-arrows" style="width:20px;text-align:center;"></i><span>Referrals</span>
    </a>

    <div class="nav-section">MESSAGING</div>
    <a href="/msjpay/msj-admin/messaging-hub.php" class="nav-link <?=$cur==='messaging-hub.php'||$cur==='notifications.php'||$cur==='marketing.php'||$cur==='tickets.php'?'active':''?>">
        <i class="fas fa-envelope-open-text" style="width:20px;text-align:center;"></i><span>Messaging Hub</span>
    </a>

    <div class="nav-section">CONFIGURATION</div>
    <a href="/msjpay/msj-admin/configuration-hub.php" class="nav-link <?=$cur==='configuration-hub.php'||$cur==='api-providers.php'||$cur==='api-settings.php'?'active':''?>">
        <i class="fas fa-cogs" style="width:20px;text-align:center;"></i><span>API Setup</span>
    </a>
    <a href="/msjpay/msj-admin/payment-gateways.php" class="nav-link <?=$cur==='payment-gateways.php'||$cur==='manual-funding.php'?'active':''?>">
        <i class="fas fa-credit-card" style="width:20px;text-align:center;"></i><span>Payment Gateways</span>
    </a>
    
    <div class="nav-section">FINANCE & OTHERS</div>
    <a href="/msjpay/msj-admin/profit-analysis.php" class="nav-link <?=$cur==='profit-analysis.php'?'active':''?>"><i class="fas fa-chart-line" style="width:20px;text-align:center;"></i><span>Profit Analysis</span></a>
    <a href="/msjpay/msj-admin/virtual-cards.php" class="nav-link <?=$cur==='virtual-cards.php'?'active':''?>"><i class="fas fa-vr-cardboard" style="width:20px;text-align:center;"></i><span>Virtual Cards</span></a>
    
    <div class="nav-section">SECURITY UNIT</div>
    <?php if(($_SESSION['admin_role']??'')==='superadmin'):?>
    <a href="/msjpay/msj-admin/staff-management.php" class="nav-link <?=$cur==='staff-management.php'?'active':''?>">
        <i class="fas fa-user-shield" style="width:20px;text-align:center;"></i><span>Staff Management</span>
    </a>
    <a href="/msjpay/msj-admin/security-unit.php" class="nav-link <?=$cur==='security-unit.php'||$cur==='audit-logs.php'?'active':''?>">
        <i class="fas fa-shield-alt" style="width:20px;text-align:center;"></i><span>Security & Logs</span>
    </a>
    <?php endif;?>
    
    <div class="nav-section">ACCOUNT</div>
    <a href="/msjpay/msj-admin/manage-account.php" class="nav-link <?=$cur==='manage-account.php'?'active':''?>">
        <i class="fas fa-user-cog" style="width:20px;text-align:center;"></i><span>Manage Account</span>
    </a>
    <a href="/msjpay/msj-admin/logout.php" class="nav-link danger">
        <i class="fas fa-sign-out-alt" style="width:20px;text-align:center;"></i><span>Logout</span>
    </a>
  </nav>
</aside>
