<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!-- Desktop Sidebar -->
<aside class="hidden md:flex flex-col w-64 bg-white border-r border-slate-200 h-full">
    <div class="h-16 flex items-center px-6 border-b border-slate-200">
        <a href="/index.php" class="flex items-center gap-2">
            <img src="/assets/images/gracehubtels-logo.png" alt="Gracehubtels" class="h-8 w-auto">
            <span class="text-xl font-bold text-brand-navy">Gracehubtels</span>
        </a>
    </div>
    
    <div class="flex-1 overflow-y-auto py-4">
        <nav class="space-y-1 px-3">
            <p class="px-3 text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2 mt-4">Main Menu</p>
            <a href="/user/dashboard" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors <?= $current_page == 'dashboard.php' ? 'sidebar-active' : 'text-slate-600 hover:bg-slate-50 hover:text-brand-navy' ?>">
                <i class="fa-solid fa-house w-5 text-brand-orange"></i> Dashboard
            </a>
            <a href="/user/virtual-card" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors <?= $current_page == 'virtual-card.php' ? 'sidebar-active' : 'text-slate-600 hover:bg-slate-50 hover:text-brand-navy' ?>">
                <i class="fa-regular fa-credit-card w-5 text-emerald-500"></i> Virtual Cards
            </a>
            <a href="/user/transactions" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors <?= $current_page == 'transactions.php' ? 'sidebar-active' : 'text-slate-600 hover:bg-slate-50 hover:text-brand-navy' ?>">
                <i class="fa-solid fa-clock-rotate-left w-5 text-indigo-500"></i> Transactions
            </a>
            <a href="/user/internal-transfer" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors <?= $current_page == 'internal-transfer.php' ? 'sidebar-active' : 'text-slate-600 hover:bg-slate-50 hover:text-brand-navy' ?>">
                <i class="fa-solid fa-paper-plane w-5 text-brand-orange"></i> Send Money
            </a>
            <a href="/user/beneficiaries" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors <?= $current_page == 'beneficiaries.php' ? 'sidebar-active' : 'text-slate-600 hover:bg-slate-50 hover:text-brand-navy' ?>">
                <i class="fa-solid fa-users w-5 text-rose-500"></i> Beneficiaries
            </a>

            
            <p class="px-3 text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2 mt-6">Services</p>
            <a href="/user/airtime" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors <?= $current_page == 'airtime.php' ? 'sidebar-active' : 'text-slate-600 hover:bg-slate-50 hover:text-brand-navy' ?>">
                <i class="fa-solid fa-mobile-screen-button w-5 text-blue-500"></i> Airtime
            </a>
            <a href="/user/data" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors <?= $current_page == 'data.php' ? 'sidebar-active' : 'text-slate-600 hover:bg-slate-50 hover:text-brand-navy' ?>">
                <i class="fa-solid fa-wifi w-5 text-green-500"></i> Data Bundle
            </a>
            <a href="/user/electricity" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors <?= $current_page == 'electricity.php' ? 'sidebar-active' : 'text-slate-600 hover:bg-slate-50 hover:text-brand-navy' ?>">
                <i class="fa-solid fa-bolt w-5 text-yellow-500"></i> Electricity
            </a>
            <a href="/user/cabletv" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors <?= $current_page == 'cabletv.php' ? 'sidebar-active' : 'text-slate-600 hover:bg-slate-50 hover:text-brand-navy' ?>">
                <i class="fa-solid fa-tv w-5 text-purple-500"></i> Cable TV
            </a>
            
            <p class="px-3 text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2 mt-6">Account</p>
            <a href="/user/profile" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors <?= $current_page == 'profile.php' ? 'sidebar-active' : 'text-slate-600 hover:bg-slate-50 hover:text-brand-navy' ?>">
                <i class="fa-regular fa-user w-5 text-brand-navy"></i> My Profile
            </a>
            <a href="/user/developer-api" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors <?= $current_page == 'developer-api.php' ? 'sidebar-active' : 'text-slate-600 hover:bg-slate-50 hover:text-brand-navy' ?>">
                <i class="fa-solid fa-code w-5 text-indigo-600"></i> Developer API
            </a>
            <a href="/user/referrals" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors <?= $current_page == 'referrals.php' ? 'sidebar-active' : 'text-slate-600 hover:bg-slate-50 hover:text-brand-navy' ?>">
                <i class="fa-solid fa-handshake w-5 text-green-500"></i> Referrals
            </a>
            <a href="/user/pricing" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors <?= $current_page == 'pricing.php' ? 'sidebar-active' : 'text-slate-600 hover:bg-slate-50 hover:text-brand-navy' ?>">
                <i class="fa-solid fa-tags w-5 text-amber-500"></i> Pricing Catalog
            </a>
            <a href="/user/promo" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors <?= $current_page == 'promo.php' ? 'sidebar-active' : 'text-slate-600 hover:bg-slate-50 hover:text-brand-navy' ?>">
                <i class="fa-solid fa-gift w-5 text-brand-orange"></i> Promo Codes
            </a>
            <a href="/user/support" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors <?= $current_page == 'support.php' ? 'sidebar-active' : 'text-slate-600 hover:bg-slate-50 hover:text-brand-navy' ?>">
                <i class="fa-regular fa-life-ring w-5 text-teal-500"></i> Support
            </a>
            <a href="/user/logout" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-red-500 hover:bg-red-50 mt-4">
                <i class="fa-solid fa-arrow-right-from-bracket w-5"></i> Logout
            </a>
        </nav>
    </div>
</aside>


<!-- Main content wrapper -->
<div class="flex flex-1 flex-col overflow-hidden">
    <!-- Topbar -->
    <header class="h-12 hidden md:flex items-center justify-between px-4 sm:px-6 lg:px-8 bg-white border-b border-slate-200 shadow-sm z-10">
        
        <div class="flex-1 flex justify-end items-center gap-3">
            <button id="darkModeToggle" class="text-slate-500 hover:text-brand-orange transition-colors w-8 h-8 rounded-full flex items-center justify-center hover:bg-slate-100" onclick="toggleTheme()">
                <i class="fa-solid fa-moon text-lg" id="themeIcon"></i>
            </button>
            <a href="/user/notifications" class="text-slate-500 hover:text-brand-orange transition-colors relative w-8 h-8 rounded-full flex items-center justify-center hover:bg-slate-100">
                <i class="fa-regular fa-bell text-lg"></i>
                <?php if(isset($nc) && $nc > 0): ?>
                <span class="absolute top-0 right-0 bg-red-500 text-white text-[9px] font-bold px-1.5 py-0.5 rounded-full"><?= min($nc, 99) ?></span>
                <?php endif; ?>
            </a>
            
            <a href="/user/profile" class="flex items-center gap-2 pl-3 border-l border-slate-200">
                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-brand-navy to-brand-orange flex items-center justify-center text-white font-bold text-xs shadow-sm">
                    <?= isset($name) ? strtoupper(substr($name, 0, 1)) : 'U' ?>
                </div>
                <div class="hidden md:block text-xs font-medium text-slate-700">
                    <?= isset($name) ? htmlspecialchars($name) : 'User' ?>
                </div>
            </a>
        </div>
    </header>
    
    <!-- Page Content -->
    <main class="flex-1 overflow-y-auto bg-slate-50 p-4 sm:p-6 lg:p-8 pb-24 md:pb-8">
