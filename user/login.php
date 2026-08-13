<?php
require_once __DIR__.'/core/auth.php';
start_session();
if(!empty($_SESSION['user_id'])){header('Location: /user/dashboard');exit;}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){ 
    if(!verify_csrf($_POST['csrf_token']??''))$error='Security error. Refresh and try again.'; 
    elseif(detect_tamper($_POST))$error='Invalid input detected.'; 
    else{
        $r=login_user($_POST['identifier']??'',$_POST['password']??'');
        if($r['success']){
            $next = $_GET['next'] ?? '';
            $allowed = ['/user/dashboard','/user/transactions','/user/airtime','/user/data','/user/cabletv','/user/electricity','/user/exam-pin','/user/recharge-card','/user/data-card','/user/bulk-sms','/user/profile','/user/settings','/user/support','/user/fund','/user/bank-transfer','/user/airtime-to-cash','/user/internal-transfer','/user/pricing','/user/notifications','/user/promo','/user/referrals','/user/beneficiaries','/user/virtual-card','/user/delete-account','/user/receipt','/user/api-docs','/user/developer-api'];
            $path = parse_url($next, PHP_URL_PATH);
            $next = ($path && in_array($path, $allowed, true)) ? $path : '/user/dashboard';
            header('Location: '.$next);
            exit;
        }
        $error=$r['message'];
    }
}
$csrf=csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - <?=htmlspecialchars(setting('site_name','Gracehubtels'))?></title>
    
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
        
        .btn-primary:active {
            transform: translateY(0);
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
        
        <!-- Left Side - Login Form -->
        <div class="w-full lg:w-1/2 honeycomb-bg flex items-center justify-center p-4 sm:p-6 min-h-screen lg:min-h-0">
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
                    <h1 class="text-xl font-bold text-gray-900 mb-1">Welcome Back!</h1>
                    <p class="text-gray-500 text-sm">Sign in to your account to continue</p>
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
                
                <!-- Login Form -->
                <form method="POST" class="space-y-3" id="loginForm">
                    <input type="hidden" name="csrf_token" value="<?=$csrf?>">
                    
                    <!-- Email / Username Field -->
                    <div>
                        <label for="id_identifier" class="block text-xs font-semibold text-gray-700 mb-1.5">
                            Username or Email
                        </label>
                        <input type="text" name="identifier" id="id_identifier" 
                               class="input-field w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg bg-white focus:bg-white transition-all outline-none text-gray-800 text-sm"
                               placeholder="Username or Email" required autofocus>
                    </div>
                    
                    <!-- Password Field -->
                    <div>
                        <label for="id_password" class="block text-xs font-semibold text-gray-700 mb-1.5">
                            Password
                        </label>
                        <div class="relative">
                            <input type="password" name="password" id="id_password" 
                                   class="input-field w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg bg-white focus:bg-white transition-all outline-none pr-10 text-gray-800 text-sm"
                                   placeholder="Enter your password" required>
                            <button type="button" onclick="togglePassword()" class="password-toggle">
                                <i class="fas fa-eye text-sm" id="toggleIcon"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Remember & Forgot -->
                    <div class="flex items-center justify-between">
                        <label class="flex items-center gap-1.5 cursor-pointer group">
                            <input type="checkbox" name="remember" class="custom-checkbox w-3.5 h-3.5 rounded border-gray-300 cursor-pointer">
                            <span class="text-xs text-gray-600 group-hover:text-gray-800 transition">Keep me logged in</span>
                        </label>
                        <a href="/user/forget-password" class="text-xs font-semibold text-brand-orange hover:text-brand-orangeHover transition hover:underline">
                            Forgot Password?
                        </a>
                    </div>
                    
                    <!-- Submit Button -->
                    <button type="submit" id="submitBtn"
                            class="btn-primary w-full py-2.5 text-white text-sm font-semibold rounded-lg shadow flex items-center justify-center gap-1.5">
                        <span id="sbtxt">Sign In</span>
                        <i class="fas fa-arrow-right text-xs" id="sbicn"></i>
                        <svg viewBox="0 0 24 24" class="spin" id="sbl" style="display:none; width:16px;height:16px;stroke:#fff;fill:none;stroke-width:2; animation: spin 1s linear infinite;">
                            <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
                        </svg>
                        <style>@keyframes spin { 100% { transform: rotate(360deg); } }</style>
                    </button>
                </form>
                
                <!-- Sign Up Link -->
                <div class="mt-6 text-center">
                    <p class="text-gray-500 text-xs">
                        Don't have an account? 
                        <a href="/user/signup" class="font-semibold text-brand-orange hover:text-brand-orangeHover transition hover:underline">
                            Sign Up Now
                        </a>
                    </p>
                </div>
                
                <!-- Footer -->
                <p class="text-center text-gray-400 text-xs mt-6">
                    &copy; 2026 <?=htmlspecialchars(setting('site_name','Gracehubtels'))?>. All rights reserved.
                </p>
            </div>
        </div>
        
        <!-- Right Side - Hero Image -->
        <div class="hidden lg:flex w-1/2 hero-gradient relative overflow-hidden items-center justify-center">
            <!-- Content -->
            <div class="relative z-10 text-center px-6">
                <div class="mb-4">
                    <div class="w-36 h-36 mx-auto bg-white/10 backdrop-blur-md rounded-full flex items-center justify-center p-6 border border-white/20 shadow-2xl">
                        <i class="fas fa-wallet text-white" style="font-size: 4rem;"></i>
                    </div>
                </div>
                <h2 class="text-xl font-bold text-white mb-2">Seamless Payments</h2>
                <p class="text-blue-100 text-sm max-w-xs mx-auto opacity-90">
                    Experience instant airtime, data top-ups, and bill payments with zero delays.
                </p>
            </div>
        </div>
    </div>

    <script>
        function togglePassword() {
            const pwdInput = document.getElementById('id_password');
            const icon = document.getElementById('toggleIcon');
            
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
        
        document.getElementById('loginForm').addEventListener('submit', function() {
            document.getElementById('sbtxt').style.display = 'none';
            document.getElementById('sbicn').style.display = 'none';
            document.getElementById('sbl').style.display = 'inline';
            document.getElementById('submitBtn').disabled = true;
            document.getElementById('submitBtn').classList.add('opacity-75', 'cursor-not-allowed');
        });
    </script>
</body>
</html>
