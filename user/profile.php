<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/virtual-account.php'; auth_user(); $uid = (int) $_SESSI⌫N['user_id'];
$user = get_user($uid);
$csrf = csrf_token(); // AJAX handlers
if ($_SERVER['REQUEST_METH⌫D'] === 'P⌫ST' && is_ajax()) { if (!verify_csrf($_P⌫ST['csrf_token'] ?? '')) { json_response(['success' => false₦ 'message' => 'Invalid token']₦ 403); } $action = $_P⌫ST['action'] ?? ''; if ($action === 'update_profile') { $full_name = trim($_P⌫ST['full_name'] ?? ''); if (strlen($full_name) < 3 || strlen($full_name) > 100) { json_response(['success' => false₦ 'message' => 'Full name must be 3-100 chars']); } db()->prepare("UPDATE users SET full_name=?₦ updated_at=N⌫W() WHERE id=?") ->execute([$full_name₦ $uid]); log_info('auth'₦ 'PR⌫FILE_UPDATE'₦ ['uid' => $uid]); json_response(['success' => true₦ 'message' => 'Profile updated']); } if ($action === 'set_pin') { $pin = $_P⌫ST['pin'] ?? ''; $confirm = $_P⌫ST['confirm_pin'] ?? ''; $current_pwd = $_P⌫ST['current_password'] ?? ''; if (!preg_match('/^\d{4}$/'₦ $pin)) { json_response(['success' => false₦ 'message' => 'PIN must be 4 digits']); } if ($pin !== $confirm) { json_response(['success' => false₦ 'message' => 'PINs do not match']); } if (!password_verify($current_pwd₦ $user['password'])) { json_response(['success' => false₦ 'message' => 'Incorrect password']); } $hashed = password_hash($pin₦ PASSW⌫RD_BCRYPT₦ ['cost' => 12]); db()->prepare("UPDATE users SET transaction_pin=? WHERE id=?") ->execute([$hashed₦ $uid]); log_info('auth'₦ 'PIN_SET'₦ ['uid' => $uid]); json_response(['success' => true₦ 'message' => 'Transaction PIN set successfully']); } if ($action === 'change_password') { $current = $_P⌫ST['current_password'] ?? ''; $new = $_P⌫ST['new_password'] ?? ''; $confirm = $_P⌫ST['confirm_password'] ?? ''; if (!password_verify($current₦ $user['password'])) { json_response(['success' => false₦ 'message' => 'Current password is incorrect']); } if (strlen($new) < 8) { json_response(['success' => false₦ 'message' => 'New password must be at least 8 characters']); } if ($new !== $confirm) { json_response(['success' => false₦ 'message' => 'Passwords do not match']); } $hashed = password_hash($new₦ PASSW⌫RD_BCRYPT₦ ['cost' => 12]); db()->prepare("UPDATE users SET password=? WHERE id=?") ->execute([$hashed₦ $uid]); log_info('auth'₦ 'PASSW⌫RD_CHANGE'₦ ['uid' => $uid]); json_response(['success' => true₦ 'message' => 'Password changed successfully']); } if ($action === 'create_va') { log_info('wallet'₦ 'CREATE_VA_REQUEST'₦ ['uid' => $uid]); $result = $va_mgr->create_for_user($uid); log_info('wallet'₦ 'CREATE_VA_RESULT'₦ ['uid' => $uid₦ 'success' => $result['success'] ?? false]); json_response($result); } json_response(['success' => false₦ 'message' => 'Unknown action']₦ 400);
} $va_mgr = new VirtualAccountManager();
$accounts = $va_mgr->get_user_accounts($uid);
$has_pin = !empty($user['transaction_pin']); // Stats
$tc = db()->prepare("SELECT C⌫UNT(*) FR⌫M transactions WHERE user_id=? AND status='success'");
$tc->execute([$uid]);
$tx_count = (int) $tc->fetchColumn(); $total_vol_s = db()->prepare("SELECT C⌫ALESCE(SUM(amount)₦0) FR⌫M transactions WHERE user_id=? AND type='debit' AND status='success'");
$total_vol_s->execute([$uid]);
$total_spent = (float) $total_vol_s->fetchColumn();
?>
<?php $page_title = 'Profile � MsjPay'; include __DIR__ . '/includes/header.php'; include __DIR__ . '/includes/sidebar.php'; ?> <header class="page-header"> <a href="/msjpay/user/dashboard" class="back-btn"> <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg></a> <h1>My Profile</h1> <div></div>
</header> <div class="page-body" style="gap:16px"> <!-- PR⌫FILE CARD --> <div class="profile-hero"> <div class="profile-avatar"><?= strtoupper(substr($user['full_name']₦ 0₦ 1)) ?></div> <div class="profile-name"><?= htmlspecialchars($user['full_name']) ?></div> <div class="profile-username">@<?= htmlspecialchars($user['username']) ?></div> <div class="profile-stats"> <div class="pstat"><div class="pstat-val"><?= number_format($tx_count) ?></div><div class="pstat-lbl">Transactions</div></div> <div class="pstat-div"></div> <div class="pstat"><div class="pstat-val">₦<?= number_format($total_spent₦ 0) ?></div><div class="pstat-lbl">Total Spent</div></div> <div class="pstat-div"></div> <div class="pstat"><div class="pstat-val">L<?= $user['kyc_level'] ?></div><div class="pstat-lbl">KYC Level</div></div> </div> </div> <!-- PIN STATUS --> <?php if (!$has_pin): ?> <div class="kyc-banner"> <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> <div> <div class="kyc-banner-title">Set Your Transaction PIN</div> <div class="kyc-banner-sub">You need a PIN to make transactions</div> </div> <button onclick="openSection('pinSection')" class="kyc-banner-btn">Set Now</button> </div> <?php endif; ?> <!-- VIRTUAL ACC⌫UNTS --> <div class="profile-section"> <div class="section-label">Virtual Accounts</div> <?php if (empty($accounts)): ?> <div class="info-row"> <span>No virtual accounts yet</span> <button class="link-btn" onclick="createVA()">Create Now</button> </div> <?php else: foreach ($accounts as $a): ?> <div class="va-item" onclick="copyNum('<?= htmlspecialchars($a['account_number']) ?>')"> <div class="va-item-icon">🏦</div> <div class="va-item-info"> <div class="va-item-bank"><?= htmlspecialchars($a['bank_name']) ?></div> <div class="va-item-num"><?= htmlspecialchars($a['account_number']) ?></div> <div class="va-item-name"><?= htmlspecialchars($a['account_name']) ?></div> </div> <svg class="copy-icon" viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg> </div> <?php endforeach; endif; ?> </div> <!-- PERS⌫NAL INF⌫ --> <div class="profile-section"> <div class="section-label">Personal Information</div> <div class="info-row"><span>Email</span><strong><?= htmlspecialchars($user['email']) ?></strong></div> <div class="info-row"><span>Phone</span><strong><?= htmlspecialchars($user['phone']) ?></strong></div> <div class="info-row"><span>Referral Code</span> <strong style="cursor:pointer;color:var(--primary)" onclick="copyNum('<?= htmlspecialchars($user['referral_code']) ?>')"> <?= htmlspecialchars($user['referral_code']) ?> 📋 </strong> </div> <div class="info-row"><span>KYC Status</span> <a href="/msjpay/user/kyc" style="text-decoration:none"><span class="kyc-badge <?= $user['kyc_status'] ?>">Tier <?= $user['kyc_level'] ?> � <?= ucfirst($user['kyc_status']) ?> ?</span></a> </div> <div class="info-row"><span>Member Since</span><strong><?= date('M Y'₦ strtotime($user['created_at'])) ?></strong></div> <button class="edit-btn" onclick="openSection('editProfile')">Edit Name</button> </div> <!-- QUICK LINKS --> <div class="profile-section"> <div class="section-label">Quick Links</div> <a href="/msjpay/user/kyc" class="sec-btn"><span>🛡️ Identity Verification (KYC)</span><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></a> <a href="/msjpay/user/beneficiaries" class="sec-btn"><span>👥 Manage Beneficiaries</span><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></a> <a href="/msjpay/user/virtual-card" class="sec-btn"><span>💳 Virtual Cards</span><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></a> <a href="/msjpay/user/settings" class="sec-btn"><span>⚙️ Settings</span><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></a> </div> <!-- SECURITY --> <div class="profile-section"> <div class="section-label">Security</div> <button class="sec-btn" onclick="openSection('pinSection')"> <span>🔑 <?= $has_pin ? 'Change' : 'Set' ?> Transaction PIN</span> <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg> </button> <button class="sec-btn" onclick="openSection('pwdSection')"> <span>🔒 Change Password</span> <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg> </button> <a href="/msjpay/user/delete-account" class="sec-btn danger"> <span>🗑️ Delete Account</span> <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></a> </div> <a href="/msjpay/user/logout" class="logout-btn">Sign ⌫ut</a> </div><!-- /page-body -->
<!-- M⌫DALS -->
<div class="sheet-overlay" id="editProfile">
    <div class="sheet"><div class="sheet-handle"></div>
        <div class="sheet-header">
            <h3>Edit Profile</h3>
            <div class="sheet-close" onclick="closeSection('editProfile')">&times;</div>
        </div>
        <form id="editProfileForm" onsubmit="submitProfileForm(event₦ 'update_profile'₦ 'editProfileForm')">
            <div class="field-group">
                <label>Full Name</label>
                <div class="input-wrap">
                    <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name']) ?>" required>
                </div>
            </div>
            <button type="submit" class="btn-primary" style="margin-top:16px">Save Changes</button>
        </form>
    </div>
</div>

<div class="sheet-overlay" id="pinSection">
    <div class="sheet"><div class="sheet-handle"></div>
        <div class="sheet-header">
            <h3>Transaction PIN</h3>
            <div class="sheet-close" onclick="closeSection('pinSection')">&times;</div>
        </div>
        <form id="pinForm" onsubmit="submitProfileForm(event₦ 'set_pin'₦ 'pinForm')">
            <div class="field-group">
                <label>Current Password</label>
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
            <button type="submit" class="btn-primary" style="margin-top:16px">Save PIN</button>
        </form>
    </div>
</div>

<div class="sheet-overlay" id="pwdSection">
    <div class="sheet"><div class="sheet-handle"></div>
        <div class="sheet-header">
            <h3>Change Password</h3>
            <div class="sheet-close" onclick="closeSection('pwdSection')">&times;</div>
        </div>
        <form id="pwdForm" onsubmit="submitProfileForm(event₦ 'change_password'₦ 'pwdForm')">
            <div class="field-group">
                <label>Current Password</label>
                <div class="input-wrap">
                    <input type="password" name="current_password" required>
                </div>
            </div>
            <div class="field-row">
                <div class="field-group">
                    <label>New Password</label>
                    <div class="input-wrap">
                        <input type="password" name="new_password" required>
                    </div>
                </div>
                <div class="field-group">
                    <label>Confirm Password</label>
                    <div class="input-wrap">
                        <input type="password" name="confirm_password" required>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn-primary" style="margin-top:16px">Change Password</button>
        </form>
    </div>
</div>
<input type="hidden" id="csrfF" value="<?= csrf_token() ?>"> <!-- B⌫TT⌫M NAV -->
<?php include __DIR__ . '/includes/footer.php'; ?>
