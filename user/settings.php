<?php
require_once __DIR__ . '/core/auth.php';
auth_user();
$uid = (int)$_SESSION['user_id'];
$user = get_user($uid);

if ($_SERVER['REQUEST_METHOD']==='POST' && is_ajax()) { 
    if (!verify_csrf($_POST['csrf_token']??'')) json_response(['success'=>false,'message'=>'Invalid token'],403); 
    $action=$_POST['action']??''; 
    if ($action==='update_phone') { 
        $phone=preg_replace('/\D/','',($_POST['phone']??'')); 
        if (!preg_match('/^0[7-9][0-1]\d{8}$/',$phone)) json_response(['success'=>false,'message'=>'Invalid phone number']); 
        $chk=db()->prepare("SELECT id FROM users WHERE phone=? AND id!=? LIMIT 1"); 
        $chk->execute([$phone,$uid]); 
        if ($chk->fetch()) json_response(['success'=>false,'message'=>'Phone already registered']); 
        db()->prepare("UPDATE users SET phone=? WHERE id=?")->execute([$phone,$uid]); 
        json_response(['success'=>true,'message'=>'Phone updated successfully']); 
    } 
    json_response(['success'=>false,'message'=>'Unknown action'],400);
}

$csrf=csrf_token();
$page_title = 'Settings'; 
include __DIR__ . '/includes/header.php'; 
include __DIR__ . '/includes/sidebar.php'; 
?>

<div class="max-w-2xl mx-auto pb-10">
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-4">
            <a href="/user/dashboard" class="w-10 h-10 rounded-full bg-white border border-slate-200 flex items-center justify-center text-slate-600 hover:bg-slate-50 transition-colors">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-slate-800">Settings</h1>
                <p class="text-sm text-slate-500">Manage your account preferences</p>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <!-- Account Settings -->
        <div class="bg-white rounded-2xl border border-slate-200 card-shadow overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 bg-slate-50">
                <h3 class="font-bold text-slate-800 flex items-center gap-2 text-sm uppercase tracking-wider">
                    <i class="fas fa-user-circle text-brand-orange"></i> Account
                </h3>
            </div>
            
            <div class="divide-y divide-slate-100">
                <div class="flex items-center justify-between p-4">
                    <div class="flex items-center gap-4">
                        <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-lg">
                            <i class="fas fa-phone"></i>
                        </div>
                        <div>
                            <p class="text-sm font-bold text-slate-800 mb-0.5">Phone Number</p>
                            <p class="text-xs font-semibold text-slate-500"><?=htmlspecialchars($user['phone'])?></p>
                        </div>
                    </div>
                    <i class="fas fa-lock text-slate-300 text-sm" title="Cannot be changed"></i>
                </div>
                
                <div class="flex items-center justify-between p-4">
                    <div class="flex items-center gap-4">
                        <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-lg">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <div>
                            <p class="text-sm font-bold text-slate-800 mb-0.5">Email Address</p>
                            <p class="text-xs font-semibold text-slate-500"><?=htmlspecialchars($user['email'])?></p>
                        </div>
                    </div>
                    <i class="fas fa-lock text-slate-300 text-sm" title="Cannot be changed"></i>
                </div>
                
                <a href="/user/profile" class="flex items-center justify-between p-4 hover:bg-slate-50 transition-colors group">
                    <div class="flex items-center gap-4">
                        <div class="w-10 h-10 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center text-lg group-hover:scale-110 transition-transform">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <div>
                            <p class="text-sm font-bold text-slate-800 mb-0.5">Security Settings</p>
                            <p class="text-xs font-semibold text-slate-500">Transaction PIN, Login Password</p>
                        </div>
                    </div>
                    <i class="fas fa-chevron-right text-slate-300 group-hover:text-brand-orange transition-colors"></i>
                </a>
                
                <a href="/user/developer-api" class="flex items-center justify-between p-4 hover:bg-slate-50 transition-colors group">
                    <div class="flex items-center gap-4">
                        <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-lg group-hover:scale-110 transition-transform">
                            <i class="fas fa-code"></i>
                        </div>
                        <div>
                            <p class="text-sm font-bold text-slate-800 mb-0.5">Developer API</p>
                            <p class="text-xs font-semibold text-slate-500">Access API docs and keys</p>
                        </div>
                    </div>
                    <i class="fas fa-chevron-right text-slate-300 group-hover:text-brand-orange transition-colors"></i>
                </a>
            </div>
        </div>
        
        <!-- Appearance & Notifications -->
        <div class="bg-white rounded-2xl border border-slate-200 card-shadow overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 bg-slate-50">
                <h3 class="font-bold text-slate-800 flex items-center gap-2 text-sm uppercase tracking-wider">
                    <i class="fas fa-palette text-purple-500"></i> Appearance & Notifications
                </h3>
            </div>
            
            <div class="divide-y divide-slate-100">
                <div class="flex items-center justify-between p-4">
                    <div class="flex items-center gap-4">
                        <div class="w-10 h-10 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center text-lg">
                            <i class="fas fa-moon"></i>
                        </div>
                        <div>
                            <p class="text-sm font-bold text-slate-800 mb-0.5">Dark Mode</p>
                            <p class="text-xs font-semibold text-slate-500">Toggle dark theme appearance</p>
                        </div>
                    </div>
                    <button id="darkModeToggleSettings" class="relative w-11 h-6 rounded-full transition-colors focus:outline-none" style="background:#cbd5e1" onclick="toggleTheme()">
                        <span id="dmThumb" class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow-sm transition-transform"></span>
                    </button>
                </div>
                
                <a href="/user/notifications" class="flex items-center justify-between p-4 hover:bg-slate-50 transition-colors group">
                    <div class="flex items-center gap-4">
                        <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-500 flex items-center justify-center text-lg group-hover:scale-110 transition-transform">
                            <i class="fas fa-bell"></i>
                        </div>
                        <div>
                            <p class="text-sm font-bold text-slate-800 mb-0.5">Notifications</p>
                            <p class="text-xs font-semibold text-slate-500">View all your alerts and updates</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <?php if(isset($nc) && $nc > 0): ?>
                        <span class="bg-red-500 text-white text-[10px] font-bold px-2 py-0.5 rounded-full"><?= min($nc, 99) ?></span>
                        <?php endif; ?>
                        <i class="fas fa-chevron-right text-slate-300 group-hover:text-brand-orange transition-colors"></i>
                    </div>
                </a>
                
                <a href="/user/profile" class="flex items-center justify-between p-4 hover:bg-slate-50 transition-colors group">
                    <div class="flex items-center gap-4">
                        <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-lg group-hover:scale-110 transition-transform">
                            <i class="fas fa-user-circle"></i>
                        </div>
                        <div>
                            <p class="text-sm font-bold text-slate-800 mb-0.5">My Profile</p>
                            <p class="text-xs font-semibold text-slate-500">Manage your personal information</p>
                        </div>
                    </div>
                    <i class="fas fa-chevron-right text-slate-300 group-hover:text-brand-orange transition-colors"></i>
                </a>
            </div>
        </div>

        <!-- Support & Legal -->
        <div class="bg-white rounded-2xl border border-slate-200 card-shadow overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 bg-slate-50">
                <h3 class="font-bold text-slate-800 flex items-center gap-2 text-sm uppercase tracking-wider">
                    <i class="fas fa-life-ring text-teal-500"></i> Support & Legal
                </h3>
            </div>
            
            <div class="divide-y divide-slate-100">
                <a href="mailto:<?=setting('site_email','support@gracehubtels.com')?>" class="flex items-center justify-between p-4 hover:bg-slate-50 transition-colors group">
                    <div class="flex items-center gap-4">
                        <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-500 flex items-center justify-center text-lg group-hover:scale-110 transition-transform">
                            <i class="fas fa-headset"></i>
                        </div>
                        <div>
                            <p class="text-sm font-bold text-slate-800 mb-0.5">Contact Support</p>
                            <p class="text-xs font-semibold text-slate-500"><?=setting('site_email','support@gracehubtels.com')?></p>
                        </div>
                    </div>
                    <i class="fas fa-chevron-right text-slate-300 group-hover:text-brand-orange transition-colors"></i>
                </a>
                
                <a href="/privacy-policy.php" class="flex items-center justify-between p-4 hover:bg-slate-50 transition-colors group">
                    <div class="flex items-center gap-4">
                        <div class="w-10 h-10 rounded-xl bg-slate-100 text-slate-600 flex items-center justify-center text-lg group-hover:scale-110 transition-transform">
                            <i class="fas fa-user-secret"></i>
                        </div>
                        <div>
                            <p class="text-sm font-bold text-slate-800">Privacy Policy</p>
                        </div>
                    </div>
                    <i class="fas fa-chevron-right text-slate-300 group-hover:text-brand-orange transition-colors"></i>
                </a>
                
                <a href="/terms.php" class="flex items-center justify-between p-4 hover:bg-slate-50 transition-colors group">
                    <div class="flex items-center gap-4">
                        <div class="w-10 h-10 rounded-xl bg-slate-100 text-slate-600 flex items-center justify-center text-lg group-hover:scale-110 transition-transform">
                            <i class="fas fa-file-contract"></i>
                        </div>
                        <div>
                            <p class="text-sm font-bold text-slate-800">Terms of Service</p>
                        </div>
                    </div>
                    <i class="fas fa-chevron-right text-slate-300 group-hover:text-brand-orange transition-colors"></i>
                </a>
            </div>
        </div>
        
        <a href="/user/logout" class="w-full bg-slate-100 hover:bg-rose-50 text-slate-700 hover:text-rose-600 font-bold text-center py-4 rounded-xl transition-colors inline-flex items-center justify-center gap-2 border border-slate-200 hover:border-rose-200">
            <i class="fas fa-sign-out-alt"></i> Sign Out
        </a>
    </div>
</div>

<!-- Modals -->
<!-- Phone Update Modal -->
<div class="fixed inset-0 z-[100] flex items-center justify-center hidden" id="phoneSheet">
    <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity" onclick="closePhoneModal()"></div>
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm m-4 relative z-10 transform transition-all p-6">
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-xl font-bold text-slate-800">Update Phone</h3>
            <button onclick="closePhoneModal()" class="w-8 h-8 rounded-full bg-slate-100 text-slate-500 hover:bg-slate-200 flex items-center justify-center transition-colors">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="phoneForm" onsubmit="submitPhoneForm(event)">
            <div class="mb-5">
                <label class="block text-sm font-semibold text-slate-700 mb-2">New Phone Number</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <i class="fas fa-phone text-slate-400"></i>
                    </div>
                    <input type="tel" name="phone" value="<?= htmlspecialchars($user['phone']) ?>" maxlength="11" class="block w-full pl-11 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium tracking-wider" required>
                </div>
            </div>
            <button type="submit" class="w-full bg-brand-orange hover:bg-brand-orangeHover text-white font-bold py-3.5 rounded-xl shadow-sm transition-colors">
                Save Changes
            </button>
        </form>
    </div>
</div>

<input type="hidden" id="csrfF" value="<?= csrf_token() ?>">

<script>
document.addEventListener('DOMContentLoaded', () => {
    const btn = document.getElementById('darkModeToggleSettings');
    const thumb = document.getElementById('dmThumb');
    if (btn && document.documentElement.classList.contains('dark-mode')) {
        btn.style.background = '#FF5A1F';
        thumb.style.transform = 'translateX(20px)';
    }
});
function toggleTheme() {
    const html = document.documentElement;
    const icon = document.getElementById('themeIcon');
    const btn = document.getElementById('darkModeToggleSettings');
    const thumb = document.getElementById('dmThumb');
    if (html.classList.contains('dark-mode')) {
        html.classList.remove('dark-mode');
        localStorage.setItem('theme', 'light');
        if(icon) { icon.className = 'fa-solid fa-moon text-xl'; }
        if(btn) { btn.style.background = '#cbd5e1'; }
        if(thumb) { thumb.style.transform = 'translateX(0)'; }
    } else {
        html.classList.add('dark-mode');
        localStorage.setItem('theme', 'dark');
        if(icon) { icon.className = 'fa-solid fa-sun text-xl'; }
        if(btn) { btn.style.background = '#FF5A1F'; }
        if(thumb) { thumb.style.transform = 'translateX(20px)'; }
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
