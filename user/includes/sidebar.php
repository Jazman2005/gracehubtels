<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<aside class="desktop-sidebar">
    <div class="sidebar-brand">
        <div class="logo-box">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
        </div>
        <span class="brand-name">MsjPay</span>
    </div>
    
    <div class="sidebar-menu">
        <div class="menu-label">Main Menu</div>
        <a href="/msjpay/user/dashboard" class="sidebar-link <?= $current_page == 'dashboard.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-house"></i>
            <span>Dashboard</span>
        </a>
        <a href="/msjpay/user/virtual-card" class="sidebar-link <?= $current_page == 'virtual-card.php' ? 'active' : '' ?>">
            <i class="fa-regular fa-credit-card"></i>
            <span>Virtual Cards</span>
        </a>
        <a href="/msjpay/user/transactions" class="sidebar-link <?= $current_page == 'transactions.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-clock-rotate-left"></i>
            <span>Transactions</span>
        </a>
        <a href="/msjpay/user/beneficiaries" class="sidebar-link <?= $current_page == 'beneficiaries.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-users"></i>
            <span>Beneficiaries</span>
        </a>

        <div class="menu-label">Services</div>
        <a href="/msjpay/user/airtime" class="sidebar-link <?= $current_page == 'airtime.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-mobile-screen-button"></i>
            <span>Airtime</span>
        </a>
        <a href="/msjpay/user/data" class="sidebar-link <?= $current_page == 'data.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-wifi"></i>
            <span>Data Bundle</span>
        </a>
        <a href="/msjpay/user/bank-transfer" class="sidebar-link <?= $current_page == 'bank-transfer.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-building-columns"></i>
            <span>Bank Transfer</span>
        </a>
        <a href="/msjpay/user/electricity" class="sidebar-link <?= $current_page == 'electricity.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-bolt"></i>
            <span>Electricity</span>
        </a>
        <a href="/msjpay/user/cabletv" class="sidebar-link <?= $current_page == 'cabletv.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-tv"></i>
            <span>Cable TV</span>
        </a>

        <div class="menu-label">Account</div>
        <a href="/msjpay/user/profile" class="sidebar-link <?= $current_page == 'profile.php' ? 'active' : '' ?>">
            <i class="fa-regular fa-user"></i>
            <span>My Profile</span>
        </a>
        <a href="/msjpay/user/support" class="sidebar-link <?= $current_page == 'support.php' ? 'active' : '' ?>">
            <i class="fa-regular fa-life-ring"></i>
            <span>Support</span>
        </a>
        <a href="/msjpay/user/logout" class="sidebar-link logout">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
            <span>Logout</span>
        </a>
    </div>
</aside>
<main class="dashboard-main">
    <div class="app-shell">
