<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/profile.php';
require_once __DIR__.'/core/virtual-account.php';
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

$va_mgr = new VirtualAccountManager();
$accounts = $va_mgr->get_user_accounts($uid);
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

<div class="max-w-3xl mx-auto pb-10">
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-4">
            <a href="/msjpay/user/dashboard" class="w-10 h-10 rounded-full bg-white border border-slate-200 flex items-center justify-center text-slate-600 hover:bg-slate-50 transition-colors">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-slate-800">My Profile</h1>
            </div>
        </div>
    </div>

    <!-- PROFILE HERO -->
    <div class="bg-white rounded-3xl border border-slate-200 card-shadow overflow-hidden mb-6">
        <div class="h-32 bg-gradient-to-r from-brand-navy to-brand-navyLight relative">
            <!-- Decorative Elements -->
            <div class="absolute top-0 right-0 w-32 h-32 bg-white/5 rounded-full -mr-16 -mt-16 pointer-events-none"></div>
            <div class="absolute bottom-0 left-0 w-24 h-24 bg-white/5 rounded-full -ml-12 -mb-12 pointer-events-none"></div>
            <div class="absolute inset-0 bg-[url('https://www.transparenttextures.com/patterns/cubes.png')] opacity-10 pointer-events-none"></div>
        </div>
        
        <div class="px-6 pb-6 relative">
            <div class="flex justify-between items-end -mt-12 mb-4">
                <div class="w-24 h-24 rounded-2xl bg-white p-1.5 shadow-md">
                    <div class="w-full h-full rounded-xl bg-gradient-to-br from-brand-orange to-orange-500 flex items-center justify-center text-4xl font-black text-white">
                        <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                    </div>
                </div>
                <button onclick="openSection('editProfile')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-4 py-2 rounded-xl font-bold transition-colors text-sm flex items-center gap-2">
                    <i class="fas fa-pen"></i> Edit Profile
                </button>
            </div>
            
            <div class="mb-6">
                <h2 class="text-2xl font-black text-slate-800"><?= htmlspecialchars($user['full_name']) ?></h2>
                <div class="flex items-center gap-2 text-slate-500 font-medium">
                    <span>@<?= htmlspecialchars($user['username']) ?></span>
                    <span class="w-1.5 h-1.5 rounded-full bg-slate-300"></span>
                    <span>Joined <?= date('M Y', strtotime($user['created_at'])) ?></span>
                </div>
            </div>
            
            <div class="flex border border-slate-100 rounded-2xl overflow-hidden divide-x divide-slate-100 bg-slate-50">
                <div class="flex-1 p-4 text-center">
                    <p class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-1">Transactions</p>
                    <p class="text-2xl font-black text-brand-navy"><?= number_format($tx_count) ?></p>
                </div>
                <div class="flex-1 p-4 text-center">
                    <p class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-1">Total Spent</p>
                    <p class="text-2xl font-black text-brand-orangeHover">₦<?= number_format($total_spent, 0) ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- PIN STATUS -->
    <?php if (!$has_pin): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-2xl p-5 mb-6 flex flex-col sm:flex-row items-center gap-4 shadow-sm">
        <div class="w-12 h-12 rounded-full bg-amber-100 flex items-center justify-center text-amber-500 shrink-0">
            <i class="fas fa-shield-alt text-xl"></i>
        </div>
        <div class="flex-1 text-center sm:text-left">
            <h3 class="font-bold text-amber-900 mb-1">Set Your Transaction PIN</h3>
            <p class="text-sm text-amber-700">You need a PIN to secure your wallet and authorize payments.</p>
        </div>
        <button onclick="openSection('pinSection')" class="bg-amber-500 hover:bg-amber-600 text-white px-5 py-2.5 rounded-xl font-bold transition-colors shadow-sm whitespace-nowrap w-full sm:w-auto">
            Set PIN Now
        </button>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="space-y-6">
            <!-- PERSONAL INFO -->
            <div class="bg-white rounded-2xl border border-slate-200 card-shadow p-6">
                <h3 class="font-bold text-slate-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-user text-brand-orange"></i> Personal Information
                </h3>
                
                <div class="space-y-4">
                    <div class="flex justify-between items-center py-3 border-b border-slate-100">
                        <span class="text-sm font-semibold text-slate-500">Email Address</span>
                        <span class="text-sm font-bold text-slate-800"><?= htmlspecialchars($user['email']) ?></span>
                    </div>
                    <div class="flex justify-between items-center py-3 border-b border-slate-100">
                        <span class="text-sm font-semibold text-slate-500">Phone Number</span>
                        <span class="text-sm font-bold text-slate-800"><?= htmlspecialchars($user['phone']) ?></span>
                    </div>
                    <div class="flex justify-between items-center py-3 border-b border-slate-100">
                        <span class="text-sm font-semibold text-slate-500">Referral Code</span>
                        <button onclick="copyNum('<?= htmlspecialchars($user['referral_code']) ?>')" class="flex items-center gap-2 text-sm font-bold text-brand-orange bg-brand-orangeLight px-3 py-1 rounded-lg hover:bg-brand-orange hover:text-white transition-colors">
                            <?= htmlspecialchars($user['referral_code']) ?> <i class="fas fa-copy"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- SECURITY -->
            <div class="bg-white rounded-2xl border border-slate-200 card-shadow p-6">
                <h3 class="font-bold text-slate-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-lock text-emerald-500"></i> Security Settings
                </h3>
                
                <div class="space-y-3">
                    <button onclick="openSection('pinSection')" class="w-full flex items-center justify-between p-4 rounded-xl border border-slate-100 bg-slate-50 hover:border-slate-300 hover:bg-slate-100 transition-colors text-left group">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg bg-white border border-slate-200 flex items-center justify-center text-slate-600 group-hover:text-emerald-500 transition-colors">
                                <i class="fas fa-key"></i>
                            </div>
                            <div>
                                <h4 class="text-sm font-bold text-slate-800"><?= $has_pin ? 'Change' : 'Set' ?> Transaction PIN</h4>
                                <p class="text-xs text-slate-500">Secure your payments</p>
                            </div>
                        </div>
                        <i class="fas fa-chevron-right text-slate-300 group-hover:text-slate-400"></i>
                    </button>
                    
                    <button onclick="openSection('pwdSection')" class="w-full flex items-center justify-between p-4 rounded-xl border border-slate-100 bg-slate-50 hover:border-slate-300 hover:bg-slate-100 transition-colors text-left group">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg bg-white border border-slate-200 flex items-center justify-center text-slate-600 group-hover:text-blue-500 transition-colors">
                                <i class="fas fa-shield-alt"></i>
                            </div>
                            <div>
                                <h4 class="text-sm font-bold text-slate-800">Change Password</h4>
                                <p class="text-xs text-slate-500">Update your login password</p>
                            </div>
                        </div>
                        <i class="fas fa-chevron-right text-slate-300 group-hover:text-slate-400"></i>
                    </button>
                    
                    <a href="/msjpay/user/delete-account" class="w-full flex items-center justify-between p-4 rounded-xl border border-rose-100 bg-rose-50 hover:border-rose-300 hover:bg-rose-100 transition-colors text-left group">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg bg-white border border-rose-200 flex items-center justify-center text-rose-500 transition-colors">
                                <i class="fas fa-user-minus"></i>
                            </div>
                            <div>
                                <h4 class="text-sm font-bold text-rose-600">Delete Account</h4>
                                <p class="text-xs text-rose-400">Permanently close your account</p>
                            </div>
                        </div>
                        <i class="fas fa-chevron-right text-rose-300 group-hover:text-rose-400"></i>
                    </a>
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <!-- VIRTUAL ACCOUNTS -->
            <div class="bg-white rounded-2xl border border-slate-200 card-shadow p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-bold text-slate-800 flex items-center gap-2">
                        <i class="fas fa-university text-blue-500"></i> Virtual Accounts
                    </h3>
                </div>
                
                <?php if (empty($accounts)): ?>
                    <div class="bg-slate-50 border border-slate-100 rounded-xl p-6 text-center">
                        <p class="text-sm text-slate-500 mb-4">You don't have any virtual accounts for automated funding yet.</p>
                        <a href="/msjpay/user/fund" class="bg-brand-navy hover:bg-brand-navyLight text-white px-5 py-2.5 rounded-xl font-bold transition-colors text-sm shadow-sm">
                            Create Account
                        </a>
                    </div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($accounts as $a): ?>
                        <div onclick="copyNum('<?= htmlspecialchars($a['account_number']) ?>')" class="bg-gradient-to-r from-slate-50 to-white border border-slate-200 p-4 rounded-xl flex justify-between items-center cursor-pointer hover:border-brand-orange hover:shadow-md transition-all group relative overflow-hidden">
                            <div class="absolute left-0 top-0 bottom-0 w-1 bg-brand-orange"></div>
                            <div>
                                <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1"><?= htmlspecialchars($a['bank_name']) ?></p>
                                <h4 class="text-lg font-black text-brand-navy font-mono tracking-wider"><?= htmlspecialchars($a['account_number']) ?></h4>
                                <p class="text-xs font-medium text-slate-600 mt-0.5"><?= htmlspecialchars($a['account_name']) ?></p>
                            </div>
                            <div class="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 group-hover:bg-brand-orange group-hover:text-white transition-colors">
                                <i class="fas fa-copy"></i>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- QUICK LINKS -->
            <div class="bg-white rounded-2xl border border-slate-200 card-shadow p-6">
                <h3 class="font-bold text-slate-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-bolt text-amber-500"></i> Quick Actions
                </h3>
                
                <div class="grid grid-cols-2 gap-3">
                    <a href="/msjpay/user/beneficiaries" class="bg-slate-50 hover:bg-slate-100 border border-slate-100 hover:border-slate-300 p-4 rounded-xl text-center transition-colors">
                        <div class="w-10 h-10 bg-white border border-slate-200 rounded-full flex items-center justify-center mx-auto mb-2 text-slate-600">
                            <i class="fas fa-users"></i>
                        </div>
                        <h4 class="text-sm font-bold text-slate-800">Beneficiaries</h4>
                    </a>
                    
                    <a href="/msjpay/user/virtual-card" class="bg-slate-50 hover:bg-slate-100 border border-slate-100 hover:border-slate-300 p-4 rounded-xl text-center transition-colors">
                        <div class="w-10 h-10 bg-white border border-slate-200 rounded-full flex items-center justify-center mx-auto mb-2 text-slate-600">
                            <i class="fas fa-credit-card"></i>
                        </div>
                        <h4 class="text-sm font-bold text-slate-800">Virtual Cards</h4>
                    </a>
                    
                    <a href="/msjpay/user/settings" class="bg-slate-50 hover:bg-slate-100 border border-slate-100 hover:border-slate-300 p-4 rounded-xl text-center transition-colors">
                        <div class="w-10 h-10 bg-white border border-slate-200 rounded-full flex items-center justify-center mx-auto mb-2 text-slate-600">
                            <i class="fas fa-cog"></i>
                        </div>
                        <h4 class="text-sm font-bold text-slate-800">Settings</h4>
                    </a>
                    
                    <a href="/msjpay/user/support" class="bg-slate-50 hover:bg-slate-100 border border-slate-100 hover:border-slate-300 p-4 rounded-xl text-center transition-colors">
                        <div class="w-10 h-10 bg-white border border-slate-200 rounded-full flex items-center justify-center mx-auto mb-2 text-slate-600">
                            <i class="fas fa-headset"></i>
                        </div>
                        <h4 class="text-sm font-bold text-slate-800">Support</h4>
                    </a>
                </div>
            </div>
            
            <a href="/msjpay/user/logout" class="w-full bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-center py-4 rounded-xl shadow-sm transition-colors inline-flex items-center justify-center gap-2">
                <i class="fas fa-sign-out-alt"></i> Sign Out
            </a>
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
            <h3 class="text-xl font-bold text-slate-800">Transaction PIN</h3>
            <button onclick="closeSection('pinSection')" class="w-8 h-8 rounded-full bg-slate-100 text-slate-500 hover:bg-slate-200 flex items-center justify-center transition-colors">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="pinForm" onsubmit="submitProfileForm(event, 'set_pin', 'pinForm')">
            <div class="mb-5">
                <label class="block text-sm font-semibold text-slate-700 mb-2">Current Login Password</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <i class="fas fa-lock text-slate-400"></i>
                    </div>
                    <input type="password" name="current_password" class="block w-full pl-11 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-medium" required>
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
        navigator.clipboard.writeText(txt).then(() => alert('Copied to clipboard!'));
    } else {
        const ta = document.createElement('textarea');
        ta.value = txt;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); alert('Copied to clipboard!'); } 
        catch (ex) { alert('Failed to copy'); }
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
            alert(`Success! ${res.message}`);
            location.reload();
        } else {
            alert(`Error: ${res.message}`);
            btn.innerHTML = ogHtml;
            btn.disabled = false;
        }
    })
    .catch(err => {
        alert('Network error occurred.');
        btn.innerHTML = ogHtml;
        btn.disabled = false;
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>