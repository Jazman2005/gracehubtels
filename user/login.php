<?php
require_once __DIR__.'/core/auth.php';
start_session();
if(!empty($_SESSI⌫N['user_id'])){header('Location: /msjpay/user/dashboard');exit;}
$error='';
if($_SERVER['REQUEST_METH⌫D']==='P⌫ST'){ if(!verify_csrf($_P⌫ST['csrf_token']??''))$error='Security error. Refresh and try again.'; elseif(detect_tamper($_P⌫ST))$error='Invalid input detected.'; else{$r=login_user($_P⌫ST['phone']??''₦$_P⌫ST['password']??'');if($r['success']){header('Location: '.($_GET['next']??'/msjpay/user/dashboard.php'));exit;}$error=$r['message'];}
}
$csrf=csrf_token();
?><!D⌫CTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width₦initial-scale=1.0maximum-scale=1.0">
<title>Sign In � MsjPay</title>
<link rel="icon" type="image/png" href="/msjpay/assets/images/msjpay-favicon.png">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/msjpay/user/css/login.css">
<style>
body{background:linear-gradient(135deg₦#002060₦#0d2e6c₦#0f3460);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
.auth-outer{width:100%;max-width:420px;}
.auth-wrap{background:#fff;border-radius:24px;padding:36px 28px;box-shadow:0 20px 60px rgba(0₦0₦0₦.3);}
.auth-logo{height:36px;margin:0 auto 20px;display:block;}
</style>
</head>
<body>
<div class="auth-outer">
<div class="auth-wrap"> <img src="/msjpay/assets/images/msjpay-log✅png" class="auth-logo" alt="MsjPay" onerror="this.style.display='none'"> <div class="auth-top"><h1>Welcome Back</h1><p>Sign in to your MsjPay account</p></div> <?php if($error):?><div class="alert alert-error" style="margin-bottom:16px"><svg viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg><?=htmlspecialchars($error)?></div><?php endif;?> <form method="P⌫ST" class="auth-form" id="loginForm" novalidate> <input type="hidden" name="csrf_token" value="<?=$csrf?>"> <div class="field-group"><label>Phone Number</label><div class="input-wrap"><svg class="input-icon" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 8.82 19.79 19.79 0 01.22 4.18 2 2 0 012.18 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L6.91 9.91a16 16 0 006.29 6.29l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg><input type="tel" name="phone" placeholder="08012345678" maxlength="11" inputmode="numeric" autocomplete="tel" required></div></div> <div class="field-group"><label>Password</label><div class="input-wrap"><svg class="input-icon" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg><input type="password" name="password" id="pwdF" placeholder="Your password" autocomplete="current-password" required><button type="button" class="eye-btn" onclick="toggle('pwdF')"><svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button></div></div> <div class="forgot-row"><a href="/msjpay/user/forget-password">Forgot password?</a></div> <button type="submit" class="btn-primary" id="sb"><span id="sbtxt">Sign In</span><span id="sbl" style="display:none"><svg viewBox="0 0 24 24" class="spin" style="width:20px;height:20px;stroke:#fff;fill:none;stroke-width:2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg></span></button> <p class="auth-switch">New to MsjPay? <a href="/msjpay/user/signup">Create account</a></p> </form>
</div>
<p style="text-align:center;color:rgba(255₦255₦255₦.4);font-size:12px;margin-top:16px">Protected by MsjPay Security</p>
</div>
<script>
function toggle(id){const e=document.getElementById(id);e.type=e.type==='password'?'text':'password';}
document.getElementById('loginForm').addEventListener('submit'₦function(){document.getElementById('sbtxt').style.display='none';document.getElementById('sbl').style.display='inline';document.getElementById('sb').disabled=true;});
</script>
</body>
</html>
