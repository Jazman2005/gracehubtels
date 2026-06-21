<?php
require_once __DIR__.'/core/auth.php';
admin_session();
if(!empty($_SESSION['admin_id'])){header('Location: /msjpay/msj-admin/dashboard.php');exit;}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_admin_csrf($_POST['csrf_token']??''))$error='Security error.';
    elseif(!rate_limit('admin_login',5,900))$error='Too many attempts. Try in 15 min.';
    else{$r=login_admin($_POST['email']??'',$_POST['password']??'');if($r['success']){header('Location: /msjpay/msj-admin/dashboard.php');exit;}$error=$r['message'];}
}
$csrf=admin_csrf();
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Admin Login – MsjPay</title><link rel="icon" type="image/png" href="/msjpay/assets/images/msjpay-favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}body{font-family:'Sora',sans-serif;background:linear-gradient(135deg,#1a1a2e,#16213e,#0f3460);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}.wrap{width:100%;max-width:400px;background:#fff;border-radius:20px;padding:36px 28px;box-shadow:0 20px 60px rgba(0,0,0,.3)}.logo{width:52px;height:52px;background:#F97316;border-radius:14px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:24px;font-weight:800;margin:0 auto 16px}h1{font-size:20px;font-weight:800;text-align:center;margin-bottom:4px}p{font-size:13px;color:#6B7280;text-align:center;margin-bottom:24px}.fg{margin-bottom:16px}.fg label{font-size:12px;font-weight:700;display:block;margin-bottom:6px}.fg input{width:100%;height:46px;padding:0 14px;border:1.5px solid #E5E7EB;border-radius:10px;font-family:'Sora',sans-serif;font-size:14px;outline:none}.fg input:focus{border-color:#F97316}.err{background:#FEE2E2;color:#EF4444;padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:16px}.btn{width:100%;height:46px;background:#F97316;color:#fff;border:none;border-radius:10px;font-family:'Sora',sans-serif;font-size:14px;font-weight:700;cursor:pointer}.btn:hover{background:#EA580C}</style>
</head><body>
<div class="wrap">
<div class="logo">M</div>
<h1>MsjPay Admin</h1><p>Sign in to access the admin panel</p>
<?php if($error):?><div class="err"><?=htmlspecialchars($error)?></div><?php endif;?>
<form method="POST">
<input type="hidden" name="csrf_token" value="<?=$csrf?>">
<div class="fg"><label>Email</label><input type="email" name="email" required autocomplete="email"></div>
<div class="fg"><label>Password</label><input type="password" name="password" required autocomplete="current-password"></div>
<button class="btn" type="submit">Sign In</button>
</form>
</div>
</body></html>
