<?php
require_once __DIR__.'/core/auth.php';
admin_session();

// AJAX: Resend OTP
if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    $act=$_POST['action']??'';
    if($act==='resend_otp'){
        if(empty($_POST['csrf'])||empty($_SESSION['otp_resend_csrf'])||!hash_equals($_SESSION['otp_resend_csrf'],$_POST['csrf'])){http_response_code(403);echo json_encode(['success'=>false,'message'=>'Invalid request']);exit;}
        $aid=(int)($_SESSION['pending_otp_admin_id']??0);
        $adm=null;
        if($aid&&isset($_SESSION['pending_otp_admin_data']))$adm=json_decode($_SESSION['pending_otp_admin_data'],true);
        if(!$adm||empty($adm['email'])){echo json_encode(['success'=>false,'message'=>'Session expired']);exit;}
        if(!rate_limit('admin_otp_resend',3,300)){echo json_encode(['success'=>false,'message'=>'Too many requests']);exit;}
        $otp=generate_admin_otp($aid);
        $name=preg_replace('/@.*/','',$adm['email']);
        $html="
<div style='text-align:center;padding:10px 0 16px;'>
<span style='font-size:28px;line-height:1;font-weight:800;color:#FF5A1F;'>OTP</span>
<h3 style='color:#FF5A1F;font-size:18px;margin:8px 0 4px;'>OTP Verification</h3>
<p style='color:#475569;font-size:13px;margin:0;'>Hi <strong>".htmlspecialchars($name,ENT_QUOTES)."</strong>, here is your new OTP.</p>
</div>
<div style='text-align:center;padding:20px;background:#f3f4f6;border-radius:10px;margin:16px 0;border:2px dashed #FF5A1F;'>
<span style='font-size:36px;font-weight:800;letter-spacing:10px;color:#FF5A1F;font-family:monospace;'>".$otp."</span>
</div>
<div style='margin-top:4px;padding:12px;background:#fff8e1;border-radius:8px;border:1px solid #ffe082;text-align:center;'>
<p style='margin:0;color:#e65100;font-size:12px;font-weight:600;'>Expires in 10 minutes</p>
</div>";
        send_email($adm['email'],'🔑 OTP Verification - '.setting('site_name','Gracehubtels'),$html);
        echo json_encode(['success'=>true,'message'=>'OTP resent']);
        exit;
    }
    http_response_code(400);echo json_encode(['success'=>false]);exit;
}

$error='';
$admin=null;

if(isset($_SESSION['pending_otp_admin_id'])&&isset($_SESSION['pending_otp_admin_data'])){
    $admin=json_decode($_SESSION['pending_otp_admin_data'],true);
}

// Already fully logged in
if(!empty($_SESSION['admin_id'])){header('Location: ' . ADMIN_URL . '/dashboard.php');exit;}

// No pending OTP session
if(!$admin||empty($admin['id'])){header('Location: ' . ADMIN_URL . '/login.php');exit;}

$email=$admin['email']??'';
$masked=substr($email,0,3).'****@'.(preg_replace('/.*@/','',$email));

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_admin_csrf($_POST['csrf_token']??'')){
        $error='Security error';
    }elseif(!rate_limit('admin_otp',5,300)){
        $error='Too many attempts. Try again later.';
    }else{
        $otc=preg_replace('/[^0-9]/','',$_POST['otp']??'');
        if(strlen($otc)!==6){
            $error='Enter the 6-digit code sent to your email';
        }elseif(verify_admin_otp((int)$admin['id'],$otc)){
            mark_known_device((int)$admin['id'],$_SESSION['pending_otp_device_hash']??'');
            db()->prepare("UPDATE admin SET last_login=NOW(),last_login_ip=? WHERE id=?")->execute([get_ip(),$admin['id']]);
            complete_admin_login($admin);
            unset($_SESSION['pending_otp_admin_id'],$_SESSION['pending_otp_device_hash'],$_SESSION['pending_otp_admin_data']);
            header('Location: ' . ADMIN_URL . '/dashboard.php');
            exit;
        }else{
            $error='Invalid or expired OTP. Request a new one.';
        }
    }
}

$csrf=admin_csrf();
$resend_csrf=bin2hex(random_bytes(32));
$_SESSION['otp_resend_csrf']=$resend_csrf;
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>OTP Verification – <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title><link rel="icon" type="image/png" href="/assets/images/favicon.png">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={theme:{extend:{fontFamily:{inter:['Inter','sans-serif'],outfit:['Outfit','sans-serif']},colors:{brand:{navy:'#051A52',navyLight:'#1640A6',navyDark:'#030F30',indigo:'#FF5A1F',indigoHover:'#E04815',indigoLight:'#FFF0EB',emerald:'#14B8A6',emeraldLight:'#CCFBF1'}}}}}</script>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>*{font-family:'Inter',sans-serif}h1,h2,h3,h4{font-family:'Outfit',sans-serif}.honeycomb-bg{background-color:#f3f4f6;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='28' height='49' viewBox='0 0 28 49'%3E%3Cg fill-rule='evenodd'%3E%3Cg id='hexagons' fill='%23FF5A1F' fill-opacity='0.04'%3E%3Cpath d='M13.99 9.25l13 7.5v15l-13 7.5L1 31.75v-15l12.99-7.5zM3 17.9v12.7l10.99 6.34 11-6.35V17.9l-11-6.34L3 17.9zM0 15l12.98-7.5V0h-2v6.35L0 12.69v2.3zm0 18.5L12.98 41v8h-2v-6.85L0 35.81v-2.3zM15 0v7.5L27.99 15H28v-2.31h-.01L17 6.35V0h-2zm0 49v-8l12.99-7.5H28v2.31h-.01L17 42.15V49h-2z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E")}.hero-gradient{background:linear-gradient(135deg,#051A52,#0B2472,#1640A6)}.btn-primary{background:linear-gradient(135deg,#FF5A1F,#E04815);transition:all .3s ease}.btn-primary:hover{transform:translateY(-2px);box-shadow:0 10px 25px rgba(255,90,31,.35)}.btn-primary:active{transform:translateY(0)}.input-field{transition:all .2s ease}.input-field:focus{border-color:#FF5A1F!important;box-shadow:0 0 0 3px rgba(255,90,31,.12)!important}.otp-input{width:48px;height:54px;text-align:center;font-size:24px;font-weight:700;border:2px solid #e2e8f0;border-radius:10px;outline:none;transition:all .2s;color:#0F172A;caret-color:#FF5A1F}.otp-input:focus{border-color:#FF5A1F;box-shadow:0 0 0 3px rgba(255,90,31,.15)}.otp-input:not(:placeholder-shown){border-color:#FF5A1F;background:#FFF0EB}.shake{animation:shake .4s ease-in-out}@keyframes shake{0%,100%{transform:translateX(0)}20%{transform:translateX(-6px)}40%{transform:translateX(6px)}60%{transform:translateX(-4px)}80%{transform:translateX(4px)}}</style>
</head><body class="font-inter bg-gray-50">
<div class="min-h-screen flex items-center justify-center p-4">
<div class="w-full max-w-md">
<div class="bg-white rounded-2xl shadow-lg border border-gray-100 p-8">
<div class="text-center mb-6">
<div class="w-16 h-16 mx-auto bg-brand-indigoLight rounded-full flex items-center justify-center mb-4"><i class="fas fa-shield-halved text-2xl text-brand-indigo"></i></div>
<h1 class="text-xl font-bold text-gray-900 mb-1">Verify Your Login</h1>
<p class="text-gray-500 text-sm">We sent a 6-digit code to <strong class="text-gray-700"><?=htmlspecialchars($masked)?></strong></p>
</div>

<?php if($error):?>
<div class="bg-red-50 border-l-4 border-red-500 p-3 mb-5 rounded-r-lg shadow-sm shake"><div class="flex items-center gap-2"><i class="fas fa-exclamation-circle text-red-500 text-sm"></i><p class="text-xs text-red-700 font-medium"><?=htmlspecialchars($error)?></p></div></div>
<?php endif;?>

<form method="POST" id="otpForm" class="space-y-5" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?=$csrf?>">
<div class="flex justify-center gap-2.5" id="otpBoxes">
<?php for($i=0;$i<6;$i++):?>
<input type="text" maxlength="1" class="otp-input" inputmode="numeric" pattern="[0-9]" autofocus="<?=$i===0?'autofocus':''?>" data-idx="<?=$i?>" oninput="otpDigit(this)" onkeydown="otpBack(this,e)">
<?php endfor;?>
</div>
<input type="hidden" name="otp" id="otpHidden">

<button type="submit" id="submitBtn" class="btn-primary w-full py-3 text-white text-sm font-semibold rounded-lg shadow flex items-center justify-center gap-2"><span id="sbtxt">Verify Code</span><i class="fas fa-arrow-right text-xs" id="sbicn"></i><svg viewBox="0 0 24 24" class="spin" id="sbl" style="display:none;width:16px;height:16px;stroke:#fff;fill:none;stroke-width=2"><style>@keyframes spin{100%{transform:rotate(360deg)}}</style><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg></button>
</form>

<div class="text-center mt-5"><p class="text-xs text-gray-400 mb-2">Didn't receive the code?</p><button onclick="resendOtp()" id="resendBtn" class="text-brand-indigo text-sm font-semibold hover:underline">Resend OTP</button><span id="resendTimer" class="text-gray-400 text-xs hidden ml-1"></span></div>
<div class="text-center mt-4"><a href="<?= ADMIN_URL ?>/logout.php" class="text-gray-400 text-xs hover:text-gray-600"><i class="fas fa-arrow-left mr-1"></i> Back to login</a></div>
</div></div>

<script>
function otpDigit(inp){
    var val=inp.value.replace(/\D/,'');inp.value=val;
    if(val&&inp.nextElementSibling&&inp.nextElementSibling.tagName==='INPUT')inp.nextElementSibling.focus();
    updateOtp();
}
function otpBack(inp,e){
    if(e.key==='Backspace'&&!inp.value&&inp.previousElementSibling&&inp.previousElementSibling.tagName==='INPUT')inp.previousElementSibling.focus();
    updateOtp();
}
function updateOtp(){
    var boxes=document.querySelectorAll('.otp-input'),code='';
    boxes.forEach(function(b){code+=b.value;});
    document.getElementById('otpHidden').value=code;
}
document.getElementById('otpForm').addEventListener('submit',function(){
    document.getElementById('sbtxt').style.display='none';
    document.getElementById('sbicn').style.display='none';
    document.getElementById('sbl').style.display='inline';
    updateOtp();
});

function resendOtp(){
    var btn=document.getElementById('resendBtn'),timer=document.getElementById('resendTimer');
    btn.disabled=true;btn.classList.add('opacity-50');btn.textContent='Sending...';
    var fd=new FormData();
    fd.append('csrf','<?=$resend_csrf?>');
    fd.append('action','resend_otp');
    fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(function(r){return r.json();})
    .then(function(r){
        if(r.success){
            timer.classList.remove('hidden');timer.textContent='Code resent';
            setTimeout(function(){timer.textContent='';},3000);
        }else{
            alert(r.message||'Failed to resend');
        }
        btn.disabled=false;btn.classList.remove('opacity-50');btn.textContent='Resend OTP';
    });
}
</script>
</body></html>
