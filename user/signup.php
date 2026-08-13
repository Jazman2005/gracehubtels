<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/virtual-account.php';
start_session();
if(!empty($_SESSION['user_id'])){header('Location: /user/dashboard');exit;}
if(setting('registration_enabled','1')==='0'){echo'<div style="text-align:center;padding:60px;font-family:Inter,sans-serif"><h2>Registration is currently closed.</h2><a href="/user/login">Sign In</a></div>';exit;}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){ 
    if(!verify_csrf($_POST['csrf_token']??''))$error='Security error.';
    elseif(detect_tamper($_POST))$error='Invalid input detected.';
    elseif(!rate_limit('web_register',5,3600))$error='Too many registration attempts. Please try again later.';
    else{
        $r=register_user($_POST);
        if($r['success']){
            login_user($_POST['username']??'',$_POST['password']??'');
            (new VirtualAccountManager())->create_for_user($r['user_id']);
            header('Location: /user/dashboard?new=1');
            exit;
        }
        $error=$r['message'];
    }
}
$csrf=csrf_token();$ref=htmlspecialchars($_GET['ref']??'');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account - <?=htmlspecialchars(setting('site_name','Gracehubtels'))?></title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'inter': ['Inter', 'sans-serif'],
                        'outfit': ['Outfit', 'sans-serif'],
                    },
                                        colors: {
                        brand: {
                            navy: '#2563EB',
                            navyLight: '#3B82F6',
                            navyDark: '#1D4ED8',
                            orange: '#F59E0B',
                            orangeHover: '#D97706',
                            orangeLight: '#FEF3C7'
                        }
                    }
                }
            }
        }
    </script>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        * {
            font-family: 'Inter', sans-serif;
        }
        h1, h2, h3, h4, h5, h6 {
            font-family: 'Outfit', sans-serif;
        }
        
        .honeycomb-bg {
            background-color: #f8fafc;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='28' height='49' viewBox='0 0 28 49'%3E%3Cg fill-rule='evenodd'%3E%3Cg id='hexagons' fill='%23002060' fill-opacity='0.05'%3E%3Cpath d='M13.99 9.25l13 7.5v15l-13 7.5L1 31.75v-15l12.99-7.5zM3 17.9v12.7l10.99 6.34 11-6.35V17.9l-11-6.34L3 17.9zM0 15l12.98-7.5V0h-2v6.35L0 12.69v2.3zm0 18.5L12.98 41v8h-2v-6.85L0 35.81v-2.3zM15 0v7.5L27.99 15H28v-2.31h-.01L17 6.35V0h-2zm0 49v-8l12.99-7.5H28v2.31h-.01L17 42.15V49h-2z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
        }
        
        .hero-gradient {
            background: linear-gradient(135deg, #002060 0%, #001238 50%, #0f172a 100%);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #ff5500 0%, #e04b00 100%);
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(255, 85, 0, 0.3);
        }
        
        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #9ca3af;
            transition: color 0.2s;
        }
        
        .password-toggle:hover {
            color: #ff5500;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-15px); }
        }
        
        .float-animation {
            animation: float 6s ease-in-out infinite;
        }
        
        .custom-checkbox {
            accent-color: #ff5500;
        }
        
        .input-field {
            transition: all 0.2s ease;
        }
        
        .input-field:focus {
            border-color: #ff5500;
            box-shadow: 0 0 0 3px rgba(255, 85, 0, 0.1);
        }
    </style>
</head>

<body class="font-inter">
    <div class="min-h-screen flex flex-col lg:flex-row">
        
        <!-- Left Side - Hero Image (order-1 on lg) -->
        <div class="hidden lg:flex w-1/2 hero-gradient relative overflow-hidden items-center justify-center lg:order-1">
            <!-- Content -->
            <div class="relative z-10 text-center px-6">
                <div class="mb-4">
                    <div class="w-36 h-36 mx-auto bg-white/10 backdrop-blur-md rounded-full flex items-center justify-center p-6 border border-white/20 shadow-2xl">
                        <i class="fas fa-rocket text-white" style="font-size: 4rem;"></i>
                    </div>
                </div>
                <h2 class="text-xl font-bold text-white mb-2">Start Your Journey With Us Today</h2>
                <p class="text-blue-100 text-sm max-w-xs mx-auto opacity-90">
                    Join thousands of users enjoying instant data, airtime, and bill payments.
                </p>
                <div class="flex flex-wrap justify-center gap-2 mt-6">
                    <span class="px-3 py-1.5 bg-white/10 rounded-full text-white text-xs backdrop-blur-sm"><i class="fas fa-gift mr-1.5"></i>Welcome Bonus</span>
                    <span class="px-3 py-1.5 bg-white/10 rounded-full text-white text-xs backdrop-blur-sm"><i class="fas fa-users mr-1.5"></i>Referral Rewards</span>
                    <span class="px-3 py-1.5 bg-white/10 rounded-full text-white text-xs backdrop-blur-sm"><i class="fas fa-clock mr-1.5"></i>24/7 Support</span>
                </div>
            </div>
        </div>

        <!-- Right Side - Signup Form (order-2 on lg) -->
        <div class="w-full lg:w-1/2 honeycomb-bg flex items-center justify-center p-4 sm:p-6 min-h-screen lg:min-h-0 lg:order-2">
            <div class="w-full max-w-sm">
                
                <!-- Logo -->
                <div class="mb-4">
                    <a href="/index.php" class="inline-flex items-center gap-2 group">
                        <div class="w-9 h-9 bg-gradient-to-br from-brand-navy to-brand-navyDark rounded-lg flex items-center justify-center shadow group-hover:scale-105 transition-transform">
                            <img src="/img/icon.png" alt="<?=htmlspecialchars(setting('site_name','Gracehubtels'))?>" class="w-6 h-6 object-contain" onerror="this.style.display='none'; this.parentElement.innerHTML='<i class=\'fas fa-bolt text-sm text-white\'></i>'">
                        </div>
                        <span class="text-lg font-bold text-gray-800"><?=htmlspecialchars(setting('site_name','Gracehubtels'))?></span>
                    </a>
                </div>

                <!-- Welcome Text -->
                <div class="mb-4">
                    <h1 class="text-xl font-bold text-gray-900 mb-1">Create Account</h1>
                    <p class="text-gray-500 text-sm">Join thousands of users on <?=htmlspecialchars(setting('site_name','Gracehubtels'))?></p>
                </div>
                
                <!-- Error Messages -->
                <?php if($error): ?>
                <div class="bg-red-50 border-l-4 border-red-500 p-3 mb-4 rounded-r-lg shadow-sm">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-circle text-red-500"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-xs text-red-700 font-medium">
                                <?=htmlspecialchars($error)?>
                            </p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Signup Form -->
                <form method="POST" class="space-y-3" id="signupForm">
                    <input type="hidden" name="csrf_token" value="<?=$csrf?>">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <!-- Surname Field -->
                        <div>
                            <label for="id_surname" class="block text-xs font-semibold text-gray-700 mb-1.5">Surname</label>
                            <input type="text" name="surname" id="id_surname" 
                                   class="input-field w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg bg-white focus:bg-white transition-all outline-none text-gray-800 text-sm"
                                   placeholder="Surname" value="<?=htmlspecialchars($_POST['surname']??'')?>" required autofocus>
                        </div>
                        
                        <!-- First Name Field -->
                        <div>
                            <label for="id_first_name" class="block text-xs font-semibold text-gray-700 mb-1.5">First Name</label>
                            <input type="text" name="first_name" id="id_first_name" 
                                   class="input-field w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg bg-white focus:bg-white transition-all outline-none text-gray-800 text-sm"
                                   placeholder="First Name" value="<?=htmlspecialchars($_POST['first_name']??'')?>" required>
                        </div>
                    </div>
                    
                    <!-- Middle Name Field -->
                    <div>
                        <label for="id_middle_name" class="block text-xs font-semibold text-gray-700 mb-1.5">Middle Name <span class="text-gray-400 font-normal">(Optional)</span></label>
                        <input type="text" name="middle_name" id="id_middle_name" 
                               class="input-field w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg bg-white focus:bg-white transition-all outline-none text-gray-800 text-sm"
                               placeholder="Middle Name" value="<?=htmlspecialchars($_POST['middle_name']??'')?>">
                    </div>

                    <!-- Username Field -->
                    <div>
                        <label for="id_username" class="block text-xs font-semibold text-gray-700 mb-1.5">Username</label>
                        <input type="text" name="username" id="id_username" 
                               class="input-field w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg bg-white focus:bg-white transition-all outline-none text-gray-800 text-sm"
                               placeholder="Choose a username" pattern="[a-zA-Z0-9_]{3,20}" value="<?=htmlspecialchars($_POST['username']??'')?>" required>
                        <p class="text-xs text-gray-500 mt-0.5">3-20 chars, letters, numbers & underscore</p>
                    </div>
                    
                    <!-- Email Field -->
                    <div>
                        <label for="id_email" class="block text-xs font-semibold text-gray-700 mb-1.5">Email</label>
                        <input type="email" name="email" id="id_email" 
                               class="input-field w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg bg-white focus:bg-white transition-all outline-none text-gray-800 text-sm"
                               placeholder="your@email.com" value="<?=htmlspecialchars($_POST['email']??'')?>" required>
                    </div>
                    
                    <!-- Phone Field -->
                    <div>
                        <label for="id_phone" class="block text-xs font-semibold text-gray-700 mb-1.5">Phone</label>
                        <input type="tel" name="phone" id="id_phone" 
                               class="input-field w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg bg-white focus:bg-white transition-all outline-none text-gray-800 text-sm"
                               placeholder="08012345678" maxlength="11" inputmode="numeric" value="<?=htmlspecialchars($_POST['phone']??'')?>" required>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <!-- Password Field -->
                        <div>
                            <label for="id_password" class="block text-xs font-semibold text-gray-700 mb-1.5">Password</label>
                            <div class="relative">
                                <input type="password" name="password" id="id_password" 
                                       class="input-field w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg bg-white focus:bg-white transition-all outline-none pr-10 text-gray-800 text-sm"
                                       placeholder="Min 8 chars" required minlength="8">
                                <button type="button" onclick="togglePassword('id_password', 'icon_password')" class="password-toggle">
                                    <i class="fas fa-eye text-sm" id="icon_password"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Confirm Password Field -->
                        <div>
                            <label for="id_confirm_password" class="block text-xs font-semibold text-gray-700 mb-1.5">Confirm</label>
                            <div class="relative">
                                <input type="password" name="confirm_password" id="id_confirm_password" 
                                       class="input-field w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg bg-white focus:bg-white transition-all outline-none pr-10 text-gray-800 text-sm"
                                       placeholder="Repeat password" required>
                                <button type="button" onclick="togglePassword('id_confirm_password', 'icon_confirm_password')" class="password-toggle">
                                    <i class="fas fa-eye text-sm" id="icon_confirm_password"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Referral Code Field -->
                    <div>
                        <label for="id_referral_code" class="block text-xs font-semibold text-gray-700 mb-1.5">Referral Code [optional]</label>
                        <input type="text" name="referral_code" id="id_referral_code" 
                               class="input-field w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg bg-white focus:bg-white transition-all outline-none text-gray-800 text-sm"
                               placeholder="Enter referrer phone or code" value="<?=$ref?>">
                        <p class="text-xs text-gray-500 mt-0.5">Enter the referrer's phone number or referral code</p>
                    </div>

                    <!-- Terms Checkbox -->
                    <div>
                        <label class="flex items-center gap-1.5 cursor-pointer group">
                            <input type="checkbox" name="agree" id="agreeChk" class="custom-checkbox w-3.5 h-3.5 rounded border-gray-300 cursor-pointer" required>
                            <span class="text-xs text-gray-600">I agree to the <a href="/terms.php" class="text-brand-orange hover:underline" target="_blank">Terms of Service</a> and <a href="/privacy-policy.php" class="text-brand-orange hover:underline" target="_blank">Privacy Policy</a></span>
                        </label>
                    </div>
                    
                    <!-- Submit Button -->
                    <button type="submit" id="submitBtn"
                            class="btn-primary w-full py-2.5 text-white text-sm font-semibold rounded-lg shadow flex items-center justify-center gap-1.5">
                        <span id="sbtxt">Create Account</span>
                        <i class="fas fa-arrow-right text-xs" id="sbicn"></i>
                        <svg viewBox="0 0 24 24" class="spin" id="sbl" style="display:none; width:16px;height:16px;stroke:#fff;fill:none;stroke-width:2; animation: spin 1s linear infinite;">
                            <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
                        </svg>
                        <style>@keyframes spin { 100% { transform: rotate(360deg); } }</style>
                    </button>
                </form>
                
                <!-- Sign In Link -->
                <div class="mt-6 text-center">
                    <p class="text-gray-500 text-xs">
                        Have an account? 
                        <a href="/user/login" class="font-semibold text-brand-orange hover:text-brand-orangeHover transition hover:underline">
                            Sign In
                        </a>
                    </p>
                </div>
                
                <!-- Footer -->
                <p class="text-center text-gray-400 text-xs mt-6">
                    &copy; 2026 <?=htmlspecialchars(setting('site_name','Gracehubtels'))?>. All rights reserved.
                </p>
            </div>
        </div>
        
    </div>

    <script>
        function togglePassword(inputId, iconId) {
            const pwdInput = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            
            if (pwdInput.type === 'password') {
                pwdInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                pwdInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        document.getElementById('signupForm').addEventListener('submit', function(e) {
            if (!document.getElementById('agreeChk').checked) {
                e.preventDefault();
                Swal.fire('Notice', 'Please accept the Terms of Service', 'info');
                return;
            }
            document.getElementById('sbtxt').style.display = 'none';
            document.getElementById('sbicn').style.display = 'none';
            document.getElementById('sbl').style.display = 'inline';
            document.getElementById('submitBtn').disabled = true;
            document.getElementById('submitBtn').classList.add('opacity-75', 'cursor-not-allowed');
        });
    </script>
</body>
</html>
