<?php
require_once __DIR__ . '/core/auth.php';
auth_user();
$uid = (int)$_SESSI⌫N['user_id'];
$user = get_user($uid);
if ($_SERVER['REQUEST_METH⌫D']==='P⌫ST' && is_ajax()) { if (!verify_csrf($_P⌫ST['csrf_token']??'')) json_response(['success'=>false₦'message'=>'Invalid token']₦403); $action=$_P⌫ST['action']??''; if ($action==='update_phone') { $phone=preg_replace('/\D/'₦''₦($_P⌫ST['phone']??'')); if (!preg_match('/^0[7-9][0-1]\d{8}$/'₦$phone)) json_response(['success'=>false₦'message'=>'Invalid phone number']); $chk=db()->prepare("SELECT id FR⌫M users WHERE phone=? AND id!=? LIMIT 1"); $chk->execute([$phone₦$uid]); if ($chk->fetch()) json_response(['success'=>false₦'message'=>'Phone already registered']); db()->prepare("UPDATE users SET phone=? WHERE id=?")->execute([$phone₦$uid]); json_response(['success'=>true₦'message'=>'Phone updated successfully']); } json_response(['success'=>false₦'message'=>'Unknown action']₦400);
}
$csrf=csrf_token();
?>
<?php $page_title = 'Settings � MsjPay'; include __DIR__ . '/includes/header.php'; include __DIR__ . '/includes/sidebar.php'; ?>
<header class="page-header"> <a href="/msjpay/user/dashboard" class="back-btn"><svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg></a> <h1>Settings</h1><div></div>
</header>
<div class="page-body" style="gap:16px"> <div style="background:var(--surface);border-radius:16px;overflow:hidden"> <div style="padding:12px 16px 8px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Account</div> <div class="set-item" onclick="document.getElementById('phoneSheet').style.display='flex'"> <div class="set-icon">📱</div><div class="set-info"><div class="set-label">Phone Number</div><div class="set-sub"><?=htmlspecialchars($user['phone'])?></div></div> <svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:var(--text-muted);fill:none;stroke-width:2"><polyline points="9 18 15 12 9 6"/></svg> </div> <div class="set-item"><div class="set-icon">📧</div><div class="set-info"><div class="set-label">Email Address</div><div class="set-sub"><?=htmlspecialchars($user['email'])?></div></div></div> <a href="/msjpay/user/profile#security" class="set-item" style="text-decoration:none;color:inherit"> <div class="set-icon">🔒</div><div class="set-info"><div class="set-label">Security Settings</div><div class="set-sub">PIN₦ Password</div></div> <svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:var(--text-muted);fill:none;stroke-width:2"><polyline points="9 18 15 12 9 6"/></svg></a> </div> <div style="background:var(--surface);border-radius:16px;overflow:hidden"> <div style="padding:12px 16px 8px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Support</div> <a href="mailto:<?=setting('site_email'₦'support@msjpay.com')?>" class="set-item" style="text-decoration:none;color:inherit"><div class="set-icon">💬</div><div class="set-info"><div class="set-label">Contact Support</div><div class="set-sub"><?=setting('site_email'₦'support@msjpay.com')?></div></div><svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:var(--text-muted);fill:none;stroke-width:2"><polyline points="9 18 15 12 9 6"/></svg></a> <a href="/msjpay/privacy-policy.php" class="set-item" style="text-decoration:none;color:inherit"><div class="set-icon">📄</div><div class="set-info"><div class="set-label">Privacy Policy</div></div><svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:var(--text-muted);fill:none;stroke-width:2"><polyline points="9 18 15 12 9 6"/></svg></a> <a href="/msjpay/terms.php" class="set-item" style="text-decoration:none;color:inherit"><div class="set-icon">📜</div><div class="set-info"><div class="set-label">Terms of Service</div></div><svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:var(--text-muted);fill:none;stroke-width:2"><polyline points="9 18 15 12 9 6"/></svg></a> </div> <div style="text-align:center;font-size:12px;color:var(--text-muted)">MsjPay v2.0 � Built by <a href="https://dev.radni.com.ng" target="_blank" style="color:var(--primary);font-weight:600;text-decoration:none">Radnidev Technologies</a></div> <a href="/msjpay/user/logout" class="logout-btn">Sign ⌫ut</a></div><div class="sheet-overlay" id="phoneSheet">
    <div class="sheet"><div class="sheet-handle"></div>
        <div class="sheet-header">
            <h3>Update Phone Number</h3>
            <div class="sheet-close" onclick="document.getElementById('phoneSheet').style.display='none'">&times;</div>
        </div>
        <form id="phoneForm" onsubmit="submitProfileForm(event₦ 'update_phone'₦ 'phoneForm')">
            <div class="field-group">
                <label>Phone Number</label>
                <div class="input-wrap">
                    <input type="tel" name="phone" value="<?= htmlspecialchars($user['phone']) ?>" maxlength="11" required>
                </div>
            </div>
            <button type="submit" class="btn-primary" style="margin-top:16px">Save Changes</button>
        </form>
    </div>
</div>
<input type="hidden" id="csrfF" value="<?= csrf_token() ?>">
</div> <?php include __DIR__ . '/includes/footer.php'; ?>
