<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/profile.php';
auth_user(); 
$uid = (int) $_SESSION['user_id'];
$user = get_user($uid); 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) { 
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { 
        json_response(['success' => false, 'message' => 'Invalid token'], 403); 
    } 
    $action = $_POST['action'] ?? ''; 
    if ($action === 'update_profile') { 
        json_response(update_profile($uid, $_POST)); 
    } elseif ($action === 'change_password') { 
        json_response(change_password($uid, $_POST)); 
    } elseif ($action === 'set_pin') { 
        json_response(set_transaction_pin($uid, $_POST)); 
    } 
    json_response(['success' => false, 'message' => 'Unknown action'], 400);
} 

$has_pin = !empty($user['transaction_pin']); 

// Stats
$tc = db()->prepare("SELECT COUNT(*) FROM transactions WHERE user_id=? AND status='success'");
$tc->execute([$uid]);
$tx_count = (int) $tc->fetchColumn(); 

$total_vol_s = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE user_id=? AND type='debit' AND status='success'");
$total_vol_s->execute([$uid]);
$total_spent = (float) $total_vol_s->fetchColumn();

$page_title = 'Profile'; 
include __DIR__ . '/includes/header.php'; 
include __DIR__ . '/includes/sidebar.php'; 
?>

<div class="max-w-3xl mx-auto pb-6 sm:pb-10">
    <!-- Header -->
    <div class="flex items-center justify-between mb-4 sm:mb-6">
        <div class="flex items-center gap-4">
            <a href="/user/dashboard" class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-white border border-slate-200 flex items-center justify-center text-slate-600 hover:bg-slate-50 transition-colors">
                <i class="fas fa-arrow-left text-xs sm:text-sm"></i>
            </a>
            <div>
                <h1 class="text-lg sm:text-xl font-bold text-slate-800">My Profile</h1>
            </div>
        </div>
        <div class="bg-brand-orangeLight text-brand-orangeHover px-2.5 sm:px-4 py-1.5 sm:py-2 rounded-xl font-bold border border-brand-orange/20 shadow-sm text-sm">
            ₦<?=number_format((float)$user['wallet_balance'],2)?>
        </div>
    </div>

    <!-- PROFILE HERO -->
    <div class="bg-white rounded-2xl sm:rounded-3xl border border-slate-200 card-shadow overflow-hidden mb-4 sm:mb-6">
        <div class="h-20 sm:h-28 bg-gradient-to-r from-brand-navy to-brand-navyLight relative">
            <div class="absolute top-0 right-0 w-20 h-20 bg-white/5 rounded-full -mr-10 -mt-10 pointer-events-none"></div>
            <div class="absolute bottom-0 left-0 w-16 h-16 bg-white/5 rounded-full -ml-8 -mb-8 pointer-events-none"></div>
        </div>
        
        <div class="px-4 sm:px-6 pb-4 sm:pb-6 relative">
            <div class="flex justify-between items-end -mt-10 sm:-mt-12 mb-3 sm:mb-4">
                <div class="w-16 h-16 sm:w-24 sm:h-24 rounded-xl sm:rounded-2xl bg-white p-1 sm:p-1.5 shadow-md">
                    <div class="w-full h-full rounded-lg sm:rounded-xl bg-gradient-to-br from-brand-orange to-orange-500 flex items-center justify-center text-2xl sm:text-4xl font-black text-white">
                        <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                    </div>
                </div>
                <button onclick="openSection('editProfile')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-3 sm:px-4 py-1.5 sm:py-2 rounded-xl font-bold transition-colors text-xs sm:text-sm flex items-center gap-1.5 sm:gap-2">
                    <i class="fas fa-pen text-xs"></i> Edit Profile
                </button>
            </div>
            
            <div class="mb-4 sm:mb-6">
                <h2 class="text-lg sm:text-2xl font-black text-slate-800"><?= htmlspecialchars($user['full_name']) ?></h2>
                <div class="flex items-center gap-1.5 sm:gap-2 text-xs sm:text-sm text-slate-500 font-medium">
                    <span>@<?= htmlspecialchars($user['username']) ?></span>
                    <span class="w-1 h-1 sm:w-1.5 sm:h-1.5 rounded-full bg-slate-300"></span>
                    <span>Joined <?= date('M Y', strtotime($user['created_at'])) ?></span>
                </div>
            </div>
            
            <div class="flex border border-slate-100 rounded-xl sm:rounded-2xl overflow-hidden divide-x divide-slate-100 bg-slate-50">
                <div class="flex-1 p-2.5 sm:p-4 text-center">
                    <p class="text-[10px] sm:text-sm font-semibold text-slate-500 uppercase tracking-wider mb-0.5 sm:mb-1">Transactions</p>
                    <p class="text-lg sm:text-2xl font-black text-brand-navy"><?= number_format($tx_count) ?></p>
                </div>
                <div class="flex-1 p-2.5 sm:p-4 text-center">
                    <p class="text-[10px] sm:text-sm font-semibold text-slate-500 uppercase tracking-wider mb-0.5 sm:mb-1">Total Spent</p>
                    <p class="text-lg sm:text-2xl font-black text-brand-orangeHover">₦<?= number_format($total_spent, 0) ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
        <div class="space-y-4 sm:space-y-6">
            <!-- PERSONAL INFO -->
            <div class="bg-white rounded-xl sm:rounded-2xl border border-slate-200 card-shadow p-4 sm:p-6">
                <h3 class="font-bold text-slate-800 text-sm sm:text-base mb-3 sm:mb-4 flex items-center gap-2">
                    <i class="fas fa-user text-brand-orange text-xs sm:text-sm"></i> Personal Information
                </h3>
                
                <div class="space-y-2 sm:space-y-4">
                    <div class="flex justify-between items-center py-2 sm:py-3 border-b border-slate-100">
                        <span class="text-xs sm:text-sm font-semibold text-slate-500">Email Address</span>
                        <span class="text-xs sm:text-sm font-bold text-slate-800"><?= htmlspecialchars($user['email']) ?></span>
                    </div>
                    <div class="flex justify-between items-center py-2 sm:py-3 border-b border-slate-100">
                        <span class="text-xs sm:text-sm font-semibold text-slate-500">Phone Number</span>
                        <span class="text-xs sm:text-sm font-bold text-slate-800"><?= htmlspecialchars($user['phone']) ?></span>
                    </div>
                    <?php $ref_display = $user['referral_code'] ?: $user['phone']; ?>
                    <div class="flex justify-between items-center py-2 sm:py-3 border-b border-slate-100">
                        <span class="text-xs sm:text-sm font-semibold text-slate-500">Referral Code</span>
                        <button onclick="copyNum('<?= htmlspecialchars($ref_display) ?>')" class="flex items-center gap-1.5 sm:gap-2 text-xs sm:text-sm font-bold text-brand-orange bg-brand-orangeLight px-2 sm:px-3 py-0.5 sm:py-1 rounded-lg hover:bg-brand-orange hover:text-white transition-colors">
                            <?= htmlspecialchars($ref_display) ?> <i class="fas fa-copy text-xs"></i>
                        </button>
                    </div>
                    <div class="py-2 sm:py-3">
                        <span class="text-xs sm:text-sm font-semibold text-slate-500 block mb-1.5">Referral Link</span>
                        <div class="flex items-center gap-2">
                            <input type="text" value="<?= htmlspecialchars(site_url('/user/signup?ref=' . urlencode($ref_display))) ?>" class="flex-1 text-xs sm:text-sm bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-slate-700 font-medium outline-none" readonly onclick="this.select()">
                            <button onclick="copyNum('<?= site_url('/user/signup?ref=' . urlencode($ref_display)) ?>')" class="flex items-center gap-1.5 text-xs sm:text-sm font-bold text-brand-orange bg-brand-orangeLight px-3 py-2 rounded-lg hover:bg-brand-orange hover:text-white transition-colors whitespace-nowrap">
                                <i class="fas fa-copy text-xs"></i> Copy
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SECURITY -->
            <div class="bg-white rounded-xl sm:rounded-2xl border border-slate-200 card-shadow p-4 sm:p-6">
                <h3 class="font-bold text-slate-800 text-sm sm:text-base mb-3 sm:mb-4 flex items-center gap-2">
                    <i class="fas fa-lock text-emerald-500 text-xs sm:text-sm"></i> Security Settings
                </h3>
                
                <div class="space-y-2 sm:space-y-3">
                    <button onclick="openSection('pinSection')" class="w-full flex items-center justify-between p-3 sm:p-4 rounded-xl border border-slate-100 bg-slate-50 hover:border-slate-300 hover:bg-slate-100 transition-colors text-left group">
                        <div class="flex items-center gap-2 sm:gap-3">
                            <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg bg-white border border-slate-200 flex items-center justify-center text-slate-600 group-hover:text-emerald-500 transition-colors">
                                <i class="fas fa-key text-xs sm:text-sm"></i>
                            </div>
                            <div>
                                <h4 class="text-xs sm:text-sm font-bold text-slate-800">Change Transaction PIN</h4>
                                <p class="text-[10px] sm:text-xs text-slate-500">Update your current PIN</p>
                            </div>
                        </div>
                        <i class="fas fa-chevron-right text-slate-300 group-hover:text-slate-400 text-xs sm:text-sm"></i>
                    </button>
                    
                    <button onclick="openSection('pinSection')" class="w-full flex items-center justify-between p-3 sm:p-4 rounded-xl border border-slate-100 bg-slate-50 hover:border-slate-300 hover:bg-slate-100 transition-colors text-left group">
                        <div class="flex items-center gap-2 sm:gap-3">
                            <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg bg-white border border-slate-200 flex items-center justify-center text-slate-600 group-hover:text-amber-500 transition-colors">
                                <i class="fas fa-unlock-alt text-xs sm:text-sm"></i>
                            </div>
                            <div>
                                <h4 class="text-xs sm:text-sm font-bold text-slate-800">Reset Transaction PIN</h4>
                                <p class="text-[10px] sm:text-xs text-slate-500">If you forgot your PIN</p>
                            </div>
                        </div>
                        <i class="fas fa-chevron-right text-slate-300 group-hover:text-slate-400 text-xs sm:text-sm"></i>
                    </button>
                    
                    <button onclick="openSection('pwdSection')" class="w-full flex items-center justify-between p-3 sm:p-4 rounded-xl border border-slate-100 bg-slate-50 hover:border-slate-300 hover:bg-slate-100 transition-colors text-left group">
                        <div class="flex items-center gap-2 sm:gap-3">
                            <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg bg-white border border-slate-200 flex items-center justify-center text-slate-600 group-hover:text-blue-500 transition-colors">
                                <i class="fas fa-shield-alt text-xs sm:text-sm"></i>
                            </div>
                            <div>
                                <h4 class="text-xs sm:text-sm font-bold text-slate-800">Change Password</h4>
                                <p class="text-[10px] sm:text-xs text-slate-500">Update your login password</p>
                            </div>
                        </div>
                        <i class="fas fa-chevron-right text-slate-300 group-hover:text-slate-400 text-xs sm:text-sm"></i>
                    </button>
                    
                    <a href="/user/delete-account" class="w-full flex items-center justify-between p-3 sm:p-4 rounded-xl border border-rose-100 bg-rose-50 hover:border-rose-300 hover:bg-rose-100 transition-colors text-left group">
                        <div class="flex items-center gap-2 sm:gap-3">
                            <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg bg-white border border-rose-200 flex items-center justify-center text-rose-500 transition-colors">
                                <i class="fas fa-user-minus text-xs sm:text-sm"></i>
                            </div>
                            <div>
                                <h4 class="text-xs sm:text-sm font-bold text-rose-600">Delete Account</h4>
                                <p class="text-[10px] sm:text-xs text-rose-400">Permanently close your account</p>
                            </div>
                        </div>
                        <i class="fas fa-chevron-right text-rose-300 group-hover:text-rose-400 text-xs sm:text-sm"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODALS -->
<!-- Edit Profile Modal -->
<div class="fixed inset-0 z-[100] flex items-center justify-center hidden" id="editProfile">
    <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity" onclick="closeSection('editProfile')"></div>
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md m-4 relative z-10 transform transition-all p-6">
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-xl font-bold text-slate-800">Edit Profile</h3>
            <button onclick="closeSection('editProfile')" class="w-8 h-8 rounded-full bg-slate-100 text-slate-500 hover:bg-slate-200 flex items-center justify-center transition-colors">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="editProfileForm" onsubmit="submitProfileForm(event, 'update_profile', 'editProfileForm')">
            <div class="mb-5">
                <label class="block text-sm font-semibold text-slate-700 mb-2">Full Name</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <i class="fas fa-user text-slate-400"></i>
                    </div>
                    <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name']) ?>" class="block w-full pl-11 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium" required>
                </div>
            </div>
            <button type="submit" class="w-full bg-brand-orange hover:bg-brand-orangeHover text-white font-bold py-3.5 rounded-xl shadow-sm transition-colors">
                Save Changes
            </button>
        </form>
    </div>
</div>

<!-- PIN Modal -->
<div class="fixed inset-0 z-[100] flex items-center justify-center hidden" id="pinSection">
    <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity" onclick="closeSection('pinSection')"></div>
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md m-4 relative z-10 transform transition-all p-6">
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-xl font-bold text-slate-800">Change PIN</h3>
            <button onclick="closeSection('pinSection')" class="w-8 h-8 rounded-full bg-slate-100 text-slate-500 hover:bg-slate-200 flex items-center justify-center transition-colors">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="pinForm" onsubmit="submitProfileForm(event, 'set_pin', 'pinForm')">
            <div class="mb-5">
                <label class="block text-sm font-semibold text-slate-700 mb-2">Current Transaction PIN</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <i class="fas fa-lock text-slate-400"></i>
                    </div>
                    <input type="password" name="current_pin" maxlength="4" class="block w-full pl-11 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium" required inputmode="numeric">
                </div>
            </div>
            
            <div class="grid grid-cols-2 gap-4 mb-6">
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2">New PIN</label>
                    <input type="password" name="pin" maxlength="4" placeholder="----" class="block w-full text-center py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-black text-2xl tracking-[0.5em]" required inputmode="numeric">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2">Confirm PIN</label>
                    <input type="password" name="confirm_pin" maxlength="4" placeholder="----" class="block w-full text-center py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-black text-2xl tracking-[0.5em]" required inputmode="numeric">
                </div>
            </div>
            <button type="submit" class="w-full bg-emerald-500 hover:bg-emerald-600 text-white font-bold py-3.5 rounded-xl shadow-sm transition-colors">
                Save PIN
            </button>
        </form>
    </div>
</div>

<!-- Password Modal -->
<div class="fixed inset-0 z-[100] flex items-center justify-center hidden" id="pwdSection">
    <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity" onclick="closeSection('pwdSection')"></div>
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md m-4 relative z-10 transform transition-all p-6">
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-xl font-bold text-slate-800">Change Password</h3>
            <button onclick="closeSection('pwdSection')" class="w-8 h-8 rounded-full bg-slate-100 text-slate-500 hover:bg-slate-200 flex items-center justify-center transition-colors">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="pwdForm" onsubmit="submitProfileForm(event, 'change_password', 'pwdForm')">
            <div class="mb-4">
                <label class="block text-sm font-semibold text-slate-700 mb-2">Current Password</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <i class="fas fa-lock text-slate-400"></i>
                    </div>
                    <input type="password" name="current_password" class="block w-full pl-11 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium" required>
                </div>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-semibold text-slate-700 mb-2">New Password</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <i class="fas fa-key text-slate-400"></i>
                    </div>
                    <input type="password" name="new_password" class="block w-full pl-11 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium" required>
                </div>
            </div>
            <div class="mb-6">
                <label class="block text-sm font-semibold text-slate-700 mb-2">Confirm Password</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <i class="fas fa-key text-slate-400"></i>
                    </div>
                    <input type="password" name="confirm_password" class="block w-full pl-11 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium" required>
                </div>
            </div>
            <button type="submit" class="w-full bg-brand-navy hover:bg-brand-navyLight text-white font-bold py-3.5 rounded-xl shadow-sm transition-colors">
                Change Password
            </button>
        </form>
    </div>
</div>

<input type="hidden" id="csrfF" value="<?= csrf_token() ?>">

<script>
function copyNum(txt) {
    if(navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(txt).then(() => showAlert('Copied to clipboard!', 'Copied'));
    } else {
        const ta = document.createElement('textarea');
        ta.value = txt;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); showAlert('Copied to clipboard!', 'Copied'); } 
        catch (ex) { showAlert('Failed to copy', 'Error'); }
        document.body.removeChild(ta);
    }
}

function openSection(id) {
    document.getElementById(id).classList.remove('hidden');
    // Prevent body scrolling
    document.body.style.overflow = 'hidden';
}

function closeSection(id) {
    document.getElementById(id).classList.add('hidden');
    document.body.style.overflow = 'auto';
}

function submitProfileForm(e, action, formId) {
    e.preventDefault();
    const form = document.getElementById(formId);
    const btn = form.querySelector('button[type="submit"]');
    const ogHtml = btn.innerHTML;
    
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    btn.disabled = true;
    
    const fd = new FormData(form);
    fd.append('action', action);
    fd.append('csrf_token', document.getElementById('csrfF').value);
    
    fetch('', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if(res.success) {
            showAlert(res.message || 'Updated successfully!', 'Success', 'check-circle', 'text-emerald-500');
            location.reload();
        } else {
            showAlert(res.message || 'Error occurred', 'Error', 'exclamation-circle', 'text-red-500');
            btn.innerHTML = ogHtml;
            btn.disabled = false;
        }
    })
    .catch(err => {
        showAlert('Network error occurred. Please try again.', 'Error');
        btn.innerHTML = ogHtml;
        btn.disabled = false;
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
