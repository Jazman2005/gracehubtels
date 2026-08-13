<?php
require_once __DIR__ . '/core/auth.php';
auth_user();
$uid = (int)$_SESSION['user_id'];
$user = get_user($uid); 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) { 
    if (!verify_csrf($_POST['csrf_token'] ?? '')) json_response(['success' => false, 'message' => 'Invalid token'], 403); 
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    // Unlimited attempts to guess the current password were previously
    // possible via this endpoint (e.g. from a hijacked session) with no
    // lockout at all.
    if (!rate_limit('delete_account_' . $uid, 5, 900)) json_response(['success' => false, 'message' => 'Too many attempts. Please try again in 15 minutes.']);

    if (!password_verify($password, $user['password'])) json_response(['success' => false, 'message' => 'Incorrect password']);
    if ($confirm !== 'DELETE') json_response(['success' => false, 'message' => 'Type DELETE to confirm']); 
    
    $anon_email = 'deleted_' . $uid . '@gracehubtels.deleted'; 
    $anon_username = 'deleted_' . $uid;
    db()->prepare( "UPDATE users SET full_name='Deleted User', username=?, email=?, phone='00000000000', password='deleted', transaction_pin=NULL, is_active=0, updated_at=NOW() WHERE id=?" )->execute([$anon_username, $anon_email, $uid]); 
    logout_user();
}

$csrf = csrf_token();

$page_title = 'Delete Account'; 
include __DIR__ . '/includes/header.php'; 
include __DIR__ . '/includes/sidebar.php'; 
?>

<div class="max-w-md mx-auto min-h-[70vh] flex flex-col pt-4">
    <!-- Header -->
    <div class="flex items-center justify-between mb-8">
        <div class="flex items-center gap-4">
            <a href="/user/profile" class="w-10 h-10 rounded-full bg-white border border-slate-200 flex items-center justify-center text-slate-600 hover:bg-slate-50 transition-colors">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-slate-800">Delete Account</h1>
            </div>
        </div>
    </div>

    <!-- Content Area -->
    <div class="flex-1 flex flex-col items-center justify-center">
        <div class="text-center mb-6">
            <div class="w-20 h-20 bg-rose-50 text-rose-500 rounded-full flex items-center justify-center text-4xl mx-auto mb-4 border-4 border-rose-100">
                <i class="fas fa-user-minus"></i>
            </div>
            <h2 class="text-xl font-bold text-slate-800 mb-2">We're sad to see you go</h2>
            <p class="text-sm text-slate-500 max-w-xs mx-auto">This action cannot be undone. All your data will be permanently removed.</p>
        </div>
        
        <div class="bg-white p-6 rounded-3xl border border-slate-200 w-full card-shadow mb-6">
            <div class="bg-rose-50 border border-rose-200 rounded-xl p-4 flex gap-3 text-sm text-rose-800 mb-6">
                <i class="fas fa-exclamation-triangle text-rose-500 mt-0.5 shrink-0"></i>
                <p><strong>This action is permanent.</strong> Deleting your account will remove all your data. Wallet balances above ₦100 will be refunded to your linked bank account within 5 business days.</p>
            </div>
            
            <form onsubmit="deleteAccount(event)">
                <div class="space-y-4 mb-6">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-2">Confirm Password</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <i class="fas fa-lock text-slate-400"></i>
                            </div>
                            <input type="password" id="delPwd" placeholder="Your current login password" class="block w-full pl-11 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:border-rose-500 transition-colors font-medium">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-2">Type <strong class="text-rose-600">DELETE</strong> to confirm</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <i class="fas fa-trash-alt text-slate-400"></i>
                            </div>
                            <input type="text" id="delConfirm" placeholder="DELETE" class="block w-full pl-11 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:border-rose-500 transition-colors font-medium tracking-widest text-rose-600 uppercase" autocomplete="off">
                        </div>
                    </div>
                </div>
                
                <input type="hidden" id="csrfField" value="<?= $csrf ?>">
                
                <button type="submit" id="delBtn" class="w-full bg-rose-500 hover:bg-rose-600 text-white font-bold py-3.5 rounded-xl shadow-sm transition-colors mb-4 flex items-center justify-center gap-2">
                    <i class="fas fa-trash-alt"></i> Permanently Delete Account
                </button>
                
                <a href="/user/profile" class="block w-full text-center py-3 text-sm font-bold text-slate-500 hover:text-slate-800 transition-colors">
                    Cancel, keep my account
                </a>
            </form>
            
            <div id="delResult" class="mt-4 hidden"></div>
        </div>
    </div>
</div>

<script>
const SITE_NAME = <?=json_encode(setting('site_name','Gracehubtels'))?>;
async function deleteAccount(e) {
    if(e) e.preventDefault();
    
    const pwd = document.getElementById('delPwd').value;
    const confirmVal = document.getElementById('delConfirm').value.toUpperCase();
    const btn = document.getElementById('delBtn');
    const resDiv = document.getElementById('delResult');
    
    if(!pwd) return Swal.fire('Notice', 'Please enter your password', 'info');
    if(confirmVal !== 'DELETE') return Swal.fire('Notice', 'Please type DELETE to confirm', 'info');
    
    if(!await showConfirm(`FINAL WARNING: Are you absolutely sure you want to permanently delete your ${SITE_NAME} account? This cannot be undone.`, 'Confirm Deletion')) return;
    
    const ogHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
    btn.disabled = true;
    
    const fd = new FormData();
    fd.append('password', pwd);
    fd.append('confirm', confirmVal);
    fd.append('csrf_token', document.getElementById('csrfField').value);
    
    fetch('', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(async r => { const txt = await r.text(); try { return JSON.parse(txt); } catch(e) { console.error('Raw response:', txt); throw new Error(txt); } })
    .then(res => {
        resDiv.classList.remove('hidden');
        if(res.success !== false) {
            // Success might be redirection from logout, handle any response not explicitly false
            resDiv.innerHTML = `<div class="p-3 bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm font-semibold rounded-xl flex items-center gap-2"><i class="fas fa-check-circle"></i> Account deleted successfully. Redirecting...</div>`;
            setTimeout(() => { location.href = '/user/login'; }, 1500);
        } else {
            resDiv.innerHTML = `<div class="p-3 bg-rose-50 border border-rose-200 text-rose-700 text-sm font-semibold rounded-xl flex items-center gap-2"><i class="fas fa-exclamation-circle"></i> ${res.message}</div>`;
            btn.innerHTML = ogHtml;
            btn.disabled = false;
        }
    })
    .catch(err => {
        // If logout redirects, it might throw a fetch error
        setTimeout(() => { location.href = '/user/login'; }, 1500);
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
