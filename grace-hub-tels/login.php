<?php
require_once __DIR__.'/core/auth.php';
admin_session();
if(!empty($_SESSION['admin_id'])){header('Location: ' . ADMIN_URL . '/dashboard.php');exit;}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_admin_csrf($_POST['csrf_token']??''))$error='Security error.';
    elseif(!rate_limit('admin_login',10,900))$error='Too many attempts. Try later.';
    else{$r=login_admin($_POST['email']??'',$_POST['password']??'');if($r['success']){header('Location: '.(!empty($r['otp_required'])?ADMIN_URL . '/otp.php':ADMIN_URL . '/dashboard.php'));exit;}$error=$r['message'];}
}
$csrf=admin_csrf();
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Admin Login – <?=htmlspecialchars(setting('site_name','Gracehubtels'))?></title><link rel="icon" type="image/png" href="/assets/images/favicon.png">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={theme:{extend:{fontFamily:{inter:['Inter','sans-serif'],outfit:['Outfit','sans-serif']},colors:{brand:{navy:'#051A52',navyLight:'#1640A6',navyDark:'#030F30',indigo:'#FF5A1F',indigoHover:'#E04815',indigoLight:'#FFF0EB',emerald:'#14B8A6',emeraldLight:'#CCFBF1'}}}}}</script>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>*{font-family:'Inter',sans-serif}h1,h2,h3,h4{font-family:'Outfit',sans-serif}.honeycomb-bg{background-color:#f3f4f6;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='28' height='49' viewBox='0 0 28 49'%3E%3Cg fill-rule='evenodd'%3E%3Cg id='hexagons' fill='%23FF5A1F' fill-opacity='0.04'%3E%3Cpath d='M13.99 9.25l13 7.5v15l-13 7.5L1 31.75v-15l12.99-7.5zM3 17.9v12.7l10.99 6.34 11-6.35V17.9l-11-6.34L3 17.9zM0 15l12.98-7.5V0h-2v6.35L0 12.69v2.3zm0 18.5L12.98 41v8h-2v-6.85L0 35.81v-2.3zM15 0v7.5L27.99 15H28v-2.31h-.01L17 6.35V0h-2zm0 49v-8l12.99-7.5H28v2.31h-.01L17 42.15V49h-2z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E")}.hero-gradient{background:linear-gradient(135deg,#051A52,#0B2472,#1640A6)}.btn-primary{background:linear-gradient(135deg,#FF5A1F,#E04815);transition:all .3s ease}.btn-primary:hover{transform:translateY(-2px);box-shadow:0 10px 25px rgba(255,90,31,.35)}.btn-primary:active{transform:translateY(0)}.input-field{transition:all .2s ease}.input-field:focus{border-color:#FF5A1F!important;box-shadow:0 0 0 3px rgba(255,90,31,.12)!important}.password-toggle{position:absolute;right:16px;top:50%;transform:translateY(-50%);cursor:pointer;color:#9ca3af;transition:color .2s}.password-toggle:hover{color:#FF5A1F}@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-15px)}}.float-animation{animation:float 6s ease-in-out infinite}</style>
</head><body class="font-inter">
<div class="min-h-screen flex flex-col lg:flex-row">
<div class="w-full lg:w-1/2 honeycomb-bg flex items-center justify-center p-4 sm:p-6 min-h-screen lg:min-h-0">
<div class="w-full max-w-sm">
<div class="mb-4"><a href="/" class="inline-flex items-center gap-2 group"><div class="w-9 h-9 bg-gradient-to-br from-brand-indigo to-brand-navyDark rounded-lg flex items-center justify-center shadow group-hover:scale-105 transition-transform"><i class="fas fa-shield-halved text-sm text-white"></i></div><span class="text-lg font-bold text-gray-800"><?=htmlspecialchars(setting('site_name','Gracehubtels'))?> <span class="text-brand-indigo font-semibold text-sm">Admin</span></span></a></div>
<div class="mb-4"><h1 class="text-xl font-bold text-gray-900 mb-1">Admin Portal</h1><p class="text-gray-500 text-sm">Sign in to manage your platform</p></div>
<?php if($error):?>
<div class="bg-red-50 border-l-4 border-red-500 p-3 mb-4 rounded-r-lg shadow-sm"><div class="flex items-center"><div class="flex-shrink-0"><i class="fas fa-exclamation-circle text-red-500"></i></div><div class="ml-3"><p class="text-xs text-red-700 font-medium"><?=htmlspecialchars($error)?></p></div></div></div>
<?php endif;?>
<form method="POST" action="<?= ADMIN_URL ?>/login" class="space-y-3">
<input type="hidden" name="csrf_token" value="<?=$csrf?>">
<div><label for="id_email" class="block text-xs font-semibold text-gray-700 mb-1.5">Email</label><input type="email" name="email" id="id_email" class="input-field w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg bg-white focus:bg-white transition-all outline-none text-gray-800 text-sm" placeholder="admin@gracehubtels.com" required autofocus></div>
<div><label for="id_password" class="block text-xs font-semibold text-gray-700 mb-1.5">Password</label><div class="relative"><input type="password" name="password" id="id_password" class="input-field w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg bg-white focus:bg-white transition-all outline-none pr-10 text-gray-800 text-sm" placeholder="Enter admin password" required><button type="button" onclick="togglePassword()" class="password-toggle"><i class="fas fa-eye text-sm" id="toggleIcon"></i></button></div></div>
<button type="submit" id="submitBtn" class="btn-primary w-full py-2.5 text-white text-sm font-semibold rounded-lg shadow flex items-center justify-center gap-1.5"><span id="sbtxt">Sign In</span><i class="fas fa-arrow-right text-xs" id="sbicn"></i><svg viewBox="0 0 24 24" class="spin" id="sbl" style="display:none;width:16px;height:16px;stroke:#fff;fill:none;stroke-width:2"><style>@keyframes spin{100%{transform:rotate(360deg)}}</style><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg></button>
</form>
<p class="text-center text-gray-400 text-xs mt-6">&copy; 2026 <?=htmlspecialchars(setting('site_name','Gracehubtels'))?>. All rights reserved.</p>
</div></div>
<div class="hidden lg:flex w-1/2 hero-gradient relative overflow-hidden items-center justify-center">
<div class="relative z-10 text-center px-6"><div class="mb-4"><div class="w-36 h-36 mx-auto bg-white/10 backdrop-blur-md rounded-full flex items-center justify-center p-6 border border-white/20 shadow-2xl"><i class="fas fa-chart-line text-white" style="font-size:4rem"></i></div></div><h2 class="text-xl font-bold text-white mb-2">Manage with Ease</h2><p class="text-blue-100 text-sm max-w-xs mx-auto opacity-90">Monitor transactions, manage users, and control settings from a single dashboard.</p></div>
</div></div>
<script>
function togglePassword(){const p=document.getElementById('id_password'),i=document.getElementById('toggleIcon');if(p.type==='password'){p.type='text';i.classList.remove('fa-eye');i.classList.add('fa-eye-slash')}else{p.type='password';i.classList.remove('fa-eye-slash');i.classList.add('fa-eye')}}
document.getElementById('submitBtn')?.addEventListener('click',function(){document.getElementById('sbtxt').style.display='none';document.getElementById('sbicn').style.display='none';document.getElementById('sbl').style.display='inline'});
</script>
</body></html>
