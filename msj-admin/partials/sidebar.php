<?php
$cur=basename($_SERVER['PHP_SELF']);
$pending_a2c=(int)db()->query("SELECT COUNT(*) FROM airtime_to_cash WHERE status IN ('pending','processing')")->fetchColumn();
$kyc_pending=(int)db()->query("SELECT COUNT(*) FROM kyc_requests WHERE status='pending'")->fetchColumn();
?>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <div class="s-logo">M</div>
    <img src="/msjpay/assets/images/msjpay-logo.png" alt="MsjPay" onerror="this.style.display='none'">
    <span class="admin-badge">Admin</span>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section">MAIN</div>
    <a href="/msjpay/msj-admin/dashboard.php" class="nav-link <?=$cur==='dashboard.php'?'active':''?>"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Dashboard</a>
    <a href="/msjpay/msj-admin/users-management.php" class="nav-link <?=$cur==='users-management.php'?'active':''?>"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>Users<?php if($kyc_pending>0):?><span class="nav-badge warn"><?=$kyc_pending?></span><?php endif;?></a>
    <a href="/msjpay/msj-admin/transactions-management.php" class="nav-link <?=$cur==='transactions-management.php'?'active':''?>"><svg viewBox="0 0 24 24"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>Transactions<?php if($pending_a2c>0):?><span class="nav-badge warn"><?=$pending_a2c?></span><?php endif;?></a>
    <a href="/msjpay/msj-admin/services-control.php" class="nav-link <?=$cur==='services-control.php'?'active':''?>"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93l-1.41 1.41M4.93 19.07l1.41-1.41M21 12h-2M5 12H3M19.07 19.07l-1.41-1.41M4.93 4.93l1.41 1.41M12 3V1M12 23v-2"/></svg>Services & Pricing</a>
    <a href="/msjpay/msj-admin/api-providers.php" class="nav-link <?=$cur==='api-providers.php'?'active':''?>"><svg viewBox="0 0 24 24"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 11-7.778 7.778 5.5 5.5 0 017.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>API Providers</a>

    <div class="nav-section">FINANCE</div>
    <a href="/msjpay/msj-admin/profit-analysis.php" class="nav-link <?=$cur==='profit-analysis.php'?'active':''?>"><svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>Profit Analysis</a>
    <a href="/msjpay/msj-admin/cashback.php" class="nav-link <?=$cur==='cashback.php'?'active':''?>"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M16 8h-6a2 2 0 100 4h4a2 2 0 110 4H8M12 6v2m0 8v2"/></svg>Cashback Config</a>
    <a href="/msjpay/msj-admin/manual-funding.php" class="nav-link <?=$cur==='manual-funding.php'?'active':''?>"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>Manual Funding</a>
    <a href="/msjpay/msj-admin/webhook-logs.php" class="nav-link <?=$cur==='webhook-logs.php'?'active':''?>"><svg viewBox="0 0 24 24"><path d="M18 10h-1.26A8 8 0 109 20h9a5 5 0 000-10z"/></svg>Webhook Logs</a>

    <div class="nav-section">USERS & KYC</div>
    <a href="/msjpay/msj-admin/kyc-management.php" class="nav-link <?=$cur==='kyc-management.php'?'active':''?>"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>KYC Review<?php if($kyc_pending>0):?><span class="nav-badge"><?=$kyc_pending?></span><?php endif;?></a>
    <a href="/msjpay/msj-admin/a2c-management.php" class="nav-link <?=$cur==='a2c-management.php'?'active':''?>"><svg viewBox="0 0 24 24"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>A2C Requests<?php if($pending_a2c>0):?><span class="nav-badge"><?=$pending_a2c?></span><?php endif;?></a>

    <div class="nav-section">COMMUNICATION</div>
    <a href="/msjpay/msj-admin/notifications.php" class="nav-link <?=$cur==='notifications.php'?'active':''?>"><svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/></svg>Notifications</a>
    <a href="/msjpay/msj-admin/email.php" class="nav-link <?=$cur==='email.php'?'active':''?>"><svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>Email Blast</a>

    <div class="nav-section">SYSTEM</div>
    <a href="/msjpay/msj-admin/api-settings.php" class="nav-link <?=$cur==='api-settings.php'?'active':''?>"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/></svg>Settings</a>
    <a href="/msjpay/msj-admin/logout.php" class="nav-link danger"><svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>Logout</a>
  </nav>
</aside>
