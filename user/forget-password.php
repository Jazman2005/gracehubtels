<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/db.php';
start_session();

if (!empty($_SESSION['user_id'])) { header('Location: /msjpay/user/dashboard'); exit; } 

$step = $_GET['step'] ?? 'request'; // request | reset
$token = $_GET['token'] ?? '';
$message = $error = ''; 

// -- STEP 1: Request reset --------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'request') { 
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { 
        $error = 'Security error. Try again.'; 
    } else { 
        $identifier = strtolower(trim($_POST['identifier'] ?? '')); 
        $stmt = db()->prepare("SELECT id, email, full_name FROM users WHERE (email=? OR phone=?) AND is_active=1 LIMIT 1"); 
        $stmt->execute([$identifier, $identifier]); 
        $user = $stmt->fetch(); 
        
        // Always show success message (prevent email enumeration) 
        if ($user) { 
            $reset_token = bin2hex(random_bytes(32)); 
            $expiry = date('Y-m-d H:i:s', strtotime('+1 hour')); 
            db()->prepare("UPDATE users SET reset_token=?, reset_token_expiry=? WHERE id=?")->execute([$reset_token, $expiry, $user['id']]); 
            
            // Send reset email (using simple mail; replace with PHPMailer for production) 
            $reset_url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/msjpay/user/forget-password.php?step=reset&token=' . $reset_token; 
            $subject = 'MsjPay Password Reset'; 
            $body = "Hello {$user['full_name']},\n\nClick the link below to reset your MsjPay password:\n\n{$reset_url}\n\nThis link expires in 1 hour. If you didn't request this, ignore this email.\n\nMsjPay Team"; 
            @mail($user['email'], $subject, $body, 'From: noreply@msjpay.com'); 
        } 
        $message = 'If an account with that email/phone exists, a reset link has been sent.'; 
    }
} 

// -- STEP 2: Reset password -------------------------------
if ($step === 'reset') { 
    if (empty($token)) { header('Location: /msjpay/user/forget-password'); exit; } 
    $stmt = db()->prepare("SELECT * FROM users WHERE reset_token=? AND reset_token_expiry > NOW() LIMIT 1"); 
    $stmt->execute([$token]); 
    $reset_user = $stmt->fetch(); 
    if (!$reset_user) { 
        $error = 'This reset link has expired or is invalid.'; 
        $step = 'expired'; 
    }
} 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'reset') { 
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { 
        $error = 'Security error.'; 
    } else { 
        $token = trim($_POST['token'] ?? ''); 
        $new_pwd = $_POST['new_password'] ?? ''; 
        $confirm = $_POST['confirm_password'] ?? ''; 
        
        $stmt = db()->prepare("SELECT * FROM users WHERE reset_token=? AND reset_token_expiry > NOW() LIMIT 1"); 
        $stmt->execute([$token]); 
        $reset_user = $stmt->fetch(); 
        
        if (!$reset_user) { 
            $error = 'Invalid or expired token.'; 
        } elseif (strlen($new_pwd) < 8) { 
            $error = 'Password must be at least 8 characters.'; 
        } elseif ($new_pwd !== $confirm) { 
            $error = 'Passwords do not match.'; 
        } else { 
            $hashed = password_hash($new_pwd, PASSWORD_BCRYPT, ['cost' => 12]); 
            db()->prepare("UPDATE users SET password=?, reset_token=NULL, reset_token_expiry=NULL WHERE id=?")->execute([$hashed, $reset_user['id']]); 
            $message = 'Password reset successfully! You can now log in.'; 
            $step = 'done'; 
        } 
    } 
}

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>Reset Password - MsjPay</title>
    <!-- Tailwind CSS (via CDN for migration, replace with compiled CSS in production) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts: Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                    colors: {
                        brand: {
                            navy: '#0f172a',
                            navyLight: '#1e293b',
                            orange: '#f97316',
                            orangeHover: '#ea580c',
                            orangeLight: '#fff7ed'
                        }
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        .glass-panel {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.05);
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 bg-slate-50 relative overflow-hidden">
    
    <!-- Decorative background elements -->
    <div class="absolute top-[-10%] left-[-10%] w-[40%] h-[40%] rounded-full bg-brand-orange/10 blur-[80px]"></div>
    <div class="absolute bottom-[-10%] right-[-10%] w-[40%] h-[40%] rounded-full bg-brand-navy/5 blur-[80px]"></div>

    <div class="w-full max-w-md glass-panel rounded-3xl p-8 sm:p-10 relative z-10">
        
        <div class="text-center mb-8">
            <a href="/msjpay/index.php" class="inline-flex items-center gap-2 mb-6">
                <img src="/msjpay/assets/images/msjpay-logo.png" alt="MsjPay" class="h-10 w-auto">
                <span class="text-2xl font-black text-brand-navy tracking-tight">MsjPay</span>
            </a>
            
            <?php if ($step === 'request'): ?>
                <h1 class="text-2xl font-bold text-slate-800 mb-2">Forgot Password</h1>
                <p class="text-slate-500 text-sm">Enter your email or phone and we'll send a reset link</p>
            <?php elseif ($step === 'reset'): ?>
                <h1 class="text-2xl font-bold text-slate-800 mb-2">Set New Password</h1>
                <p class="text-slate-500 text-sm">Choose a strong new password</p>
            <?php elseif ($step === 'done'): ?>
                <div class="w-16 h-16 bg-emerald-100 text-emerald-500 rounded-full flex items-center justify-center text-3xl mx-auto mb-4">
                    <i class="fas fa-check"></i>
                </div>
                <h1 class="text-2xl font-bold text-slate-800 mb-2">Password Reset!</h1>
                <p class="text-slate-500 text-sm">Your password has been changed successfully</p>
            <?php elseif ($step === 'expired'): ?>
                <div class="w-16 h-16 bg-rose-100 text-rose-500 rounded-full flex items-center justify-center text-3xl mx-auto mb-4">
                    <i class="fas fa-times"></i>
                </div>
                <h1 class="text-2xl font-bold text-slate-800 mb-2">Link Expired</h1>
                <p class="text-slate-500 text-sm">This reset link is no longer valid</p>
            <?php endif; ?>
        </div>

        <?php if ($error): ?>
        <div class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl mb-6 flex items-start gap-3 text-sm">
            <i class="fas fa-exclamation-circle mt-0.5"></i>
            <p><?=htmlspecialchars($error)?></p>
        </div>
        <?php endif; ?>

        <?php if ($message): ?>
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-xl mb-6 flex items-start gap-3 text-sm">
            <i class="fas fa-check-circle mt-0.5"></i>
            <p><?=htmlspecialchars($message)?></p>
        </div>
        <?php endif; ?>

        <?php if ($step === 'request'): ?>
        <form method="POST" action="?step=request" novalidate>
            <input type="hidden" name="csrf_token" value="<?=$csrf?>">
            
            <div class="mb-6">
                <label class="block text-sm font-semibold text-slate-700 mb-2">Email or Phone Number</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <i class="fas fa-user text-slate-400"></i>
                    </div>
                    <input type="text" name="identifier" placeholder="Enter your email or phone" class="block w-full pl-11 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium" required>
                </div>
            </div>
            
            <button type="submit" class="w-full bg-brand-orange hover:bg-brand-orangeHover text-white font-bold py-3.5 rounded-xl shadow-lg shadow-orange-500/30 transition-all hover:-translate-y-0.5 mb-6">
                Send Reset Link
            </button>
            
            <div class="text-center">
                <a href="/msjpay/user/login" class="text-sm font-bold text-slate-500 hover:text-brand-orange transition-colors">
                    <i class="fas fa-arrow-left mr-1"></i> Back to Login
                </a>
            </div>
        </form>

        <?php elseif ($step === 'reset' && !$error): ?>
        <form method="POST" action="?step=reset" novalidate>
            <input type="hidden" name="csrf_token" value="<?=$csrf?>">
            <input type="hidden" name="token" value="<?=htmlspecialchars($token)?>">
            
            <div class="mb-4">
                <label class="block text-sm font-semibold text-slate-700 mb-2">New Password</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <i class="fas fa-lock text-slate-400"></i>
                    </div>
                    <input type="password" name="new_password" id="np" placeholder="Min 8 characters" class="block w-full pl-11 pr-12 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium" required minlength="8">
                    <button type="button" class="absolute inset-y-0 right-0 pr-4 flex items-center text-slate-400 hover:text-slate-600" onclick="const p=document.getElementById('np'); const i=this.querySelector('i'); if(p.type==='password'){p.type='text';i.className='fas fa-eye-slash';}else{p.type='password';i.className='fas fa-eye';}">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
            
            <div class="mb-6">
                <label class="block text-sm font-semibold text-slate-700 mb-2">Confirm New Password</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <i class="fas fa-lock text-slate-400"></i>
                    </div>
                    <input type="password" name="confirm_password" placeholder="Repeat password" class="block w-full pl-11 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium" required>
                </div>
            </div>
            
            <button type="submit" class="w-full bg-brand-orange hover:bg-brand-orangeHover text-white font-bold py-3.5 rounded-xl shadow-lg shadow-orange-500/30 transition-all hover:-translate-y-0.5">
                Reset Password
            </button>
        </form>

        <?php elseif ($step === 'done'): ?>
        <a href="/msjpay/user/login" class="block w-full bg-brand-navy hover:bg-brand-navyLight text-white font-bold text-center py-3.5 rounded-xl shadow-sm transition-colors">
            Go to Login <i class="fas fa-arrow-right ml-1"></i>
        </a>
        
        <?php elseif ($step === 'expired'): ?>
        <a href="/msjpay/user/forget-password" class="block w-full bg-brand-orange hover:bg-brand-orangeHover text-white font-bold text-center py-3.5 rounded-xl shadow-sm transition-colors">
            Request New Link
        </a>
        <?php endif; ?>
    </div>
</body>
</html>