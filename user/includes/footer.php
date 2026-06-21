<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>
        <nav class="bottom-nav">
            <a href="/msjpay/user/dashboard" class="nav-item <?= $current_page == 'dashboard.php' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <span>Home</span>
            </a>
            <a href="/msjpay/user/virtual-card" class="nav-item <?= $current_page == 'virtual-card.php' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                <span>Card</span>
            </a>
            <a href="/msjpay/user/support" class="nav-item <?= $current_page == 'support.php' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <span>Support</span>
            </a>
            <a href="/msjpay/user/transactions" class="nav-item <?= $current_page == 'transactions.php' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <span>Activity</span>
            </a>
            <a href="/msjpay/user/profile" class="nav-item <?= $current_page == 'profile.php' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <span>Profile</span>
            </a>
        </nav>
    </div> <!-- end .app-shell -->
</main> <!-- end .dashboard-main -->
</div> <!-- end .dashboard-layout -->
<script>
    var userHasPin = <?= !empty($user['transaction_pin']) ? 'true' : 'false' ?>;
</script>
<script src="/msjpay/assets/js/app.js?v=<?=time()?>"></script>
<div class="sheet-overlay" id="setupPinModal">
    <div class="sheet"><div class="sheet-handle"></div>
        <div class="sheet-header">
            <h3>Setup Transaction PIN</h3>
            <div class="sheet-close" onclick="closeSection('setupPinModal')">&times;</div>
        </div>
        <form id="setupPinForm" onsubmit="submitSetupPin(event)">
            <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px;">You must set up a 4-digit PIN before you can complete transactions.</p>
            <div class="field-group">
                <label>Account Password</label>
                <div class="input-wrap">
                    <input type="password" name="current_password" required>
                </div>
            </div>
            <div class="field-row">
                <div class="field-group">
                    <label>New PIN</label>
                    <div class="input-wrap">
                        <input type="password" name="pin" maxlength="4" required inputmode="numeric">
                    </div>
                </div>
                <div class="field-group">
                    <label>Confirm PIN</label>
                    <div class="input-wrap">
                        <input type="password" name="confirm_pin" maxlength="4" required inputmode="numeric">
                    </div>
                </div>
            </div>
            <input type="hidden" name="action" value="set_pin">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <button type="submit" class="btn-primary" style="margin-top:16px">Save PIN & Continue</button>
        </form>
    </div>
</div>
</body>
</html>
