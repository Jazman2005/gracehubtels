<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/beneficiaries.php'; 
auth_user(); 
$uid = (int)$_SESSION['user_id'];
$user = get_user($uid); 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) { 
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { 
        json_response(['success' => false, 'message' => 'Invalid token'], 403); 
    } 
    $action = $_POST['action'] ?? ''; 
    if ($action === 'delete') { 
        json_response(delete_beneficiary($uid, (int)($_POST['id'] ?? 0))); 
    } 
    if ($action === 'toggle_fav') { 
        json_response(toggle_fav($uid, (int)($_POST['id'] ?? 0))); 
    } 
    json_response(['success' => false], 400);
} 

$csrf = csrf_token();
$benefs = get_beneficiaries($uid); 

$svc_icons = [ 
    'airtime' => ['icon' => 'fas fa-mobile-alt', 'color' => 'text-blue-500', 'bg' => 'bg-blue-100'], 
    'data' => ['icon' => 'fas fa-wifi', 'color' => 'text-indigo-500', 'bg' => 'bg-indigo-100'], 
    'electricity' => ['icon' => 'fas fa-bolt', 'color' => 'text-yellow-500', 'bg' => 'bg-yellow-100'], 
    'cabletv' => ['icon' => 'fas fa-tv', 'color' => 'text-purple-500', 'bg' => 'bg-purple-100'], 
    'bank_transfer' => ['icon' => 'fas fa-university', 'color' => 'text-slate-700', 'bg' => 'bg-slate-200'],
]; 

$svc_pages = [ 
    'airtime' => 'airtime.php', 
    'data' => 'data.php', 
    'electricity' => 'electricity.php', 
    'cabletv' => 'cabletv.php', 
    'bank_transfer' => 'bank-transfer.php',
];

$page_title = 'Beneficiaries'; 
include __DIR__ . '/includes/header.php'; 
include __DIR__ . '/includes/sidebar.php'; 
?>

<div class="max-w-3xl mx-auto">
    <!-- Header -->
    <div class="flex items-center justify-between mb-4 sm:mb-6">
        <div class="flex items-center gap-4">
            <a href="/user/profile" class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-white border border-slate-200 flex items-center justify-center text-slate-600 hover:bg-slate-50 transition-colors">
                <i class="fas fa-arrow-left text-xs sm:text-sm"></i>
            </a>
            <div>
                <h1 class="text-lg sm:text-xl font-bold text-slate-800">Beneficiaries</h1>
                <p class="text-xs sm:text-sm text-slate-500">Manage your saved accounts and numbers</p>
            </div>
        </div>
        <div class="bg-slate-100 text-slate-600 px-2.5 sm:px-4 py-1.5 sm:py-2 rounded-xl font-bold border border-slate-200 shadow-sm hidden sm:flex items-center gap-2 text-sm">
            <span class="text-[10px] sm:text-xs font-semibold uppercase tracking-wider opacity-80">Saved:</span>
            <span><?=count($benefs)?>/20</span>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 card-shadow overflow-hidden mb-6">
        <?php if (empty($benefs)): ?>
            <div class="p-12 text-center">
                <div class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-4 text-slate-300">
                    <i class="fas fa-users text-3xl"></i>
                </div>
                <h3 class="text-lg font-bold text-slate-800 mb-1">No beneficiaries saved</h3>
                <p class="text-slate-500 text-sm max-w-xs mx-auto">Save numbers and account details while making payments for quick access next time.</p>
            </div>
        <?php else: ?>
            <div class="divide-y divide-slate-100">
                <?php foreach ($benefs as $b): 
                    $svc = $b['service']; 
                    $conf = $svc_icons[$svc] ?? ['icon' => 'fas fa-star', 'color' => 'text-slate-500', 'bg' => 'bg-slate-100']; 
                    $page = $svc_pages[$svc] ?? 'dashboard.php'; 
                ?>
                <div class="p-4 hover:bg-slate-50 transition-colors flex items-center gap-4 group" id="b-row-<?=$b['id']?>">
                    <div class="w-12 h-12 rounded-xl <?=$conf['bg']?> <?=$conf['color']?> flex items-center justify-center shrink-0 border border-white shadow-sm group-hover:scale-105 transition-transform">
                        <i class="<?=$conf['icon']?> text-xl"></i>
                    </div>
                    
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-1">
                            <h4 class="text-sm font-bold text-slate-800 truncate">
                                <?=htmlspecialchars($b['label'])?>
                            </h4>
                            <?php if ($b['is_favourite']): ?>
                                <i class="fas fa-star text-amber-400 text-xs fav-icon" id="fav-icon-<?=$b['id']?>"></i>
                            <?php else: ?>
                                <i class="far fa-star text-amber-400 text-xs fav-icon hidden" id="fav-icon-<?=$b['id']?>"></i>
                            <?php endif; ?>
                        </div>
                        <div class="text-xs text-slate-500 truncate flex items-center gap-2">
                            <span class="font-mono bg-slate-100 px-1.5 py-0.5 rounded text-slate-600 border border-slate-200"><?=htmlspecialchars($b['value'])?></span>
                            <span class="text-slate-400">&bull;</span>
                            <span><?=ucwords(str_replace('_', ' ', $svc))?><?=$b['network'] ? ' ('.$b['network'].')' : ''?></span>
                        </div>
                    </div>
                    
                    <div class="flex items-center gap-2 shrink-0 opacity-100 md:opacity-0 md:group-hover:opacity-100 transition-opacity">
                        <a href="/user/<?=$page?>?benef=<?=$b['id']?>" class="w-9 h-9 rounded-lg bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white flex items-center justify-center transition-colors" title="Use Beneficiary">
                            <i class="fas fa-paper-plane text-sm"></i>
                        </a>
                        <button onclick="toggleFav(<?=$b['id']?>, this)" class="w-9 h-9 rounded-lg bg-amber-50 text-amber-500 hover:bg-amber-500 hover:text-white flex items-center justify-center transition-colors" title="Toggle Favorite">
                            <?php if ($b['is_favourite']): ?>
                                <i class="fas fa-star text-sm"></i>
                            <?php else: ?>
                                <i class="far fa-star text-sm"></i>
                            <?php endif; ?>
                        </button>
                        <button onclick="deleteBenef(<?=$b['id']?>, this)" class="w-9 h-9 rounded-lg bg-rose-50 text-rose-500 hover:bg-rose-500 hover:text-white flex items-center justify-center transition-colors" title="Delete">
                            <i class="fas fa-trash-alt text-sm"></i>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <input type="hidden" id="csrfF" value="<?=$csrf?>">
    
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 flex gap-3 text-sm text-blue-800">
        <i class="fas fa-info-circle text-blue-500 mt-0.5 shrink-0"></i>
        <p>You can save up to 20 beneficiaries. Favorite beneficiaries will appear first on the payment pages.</p>
    </div>
</div>

<script>
function toggleFav(id, btn) {
    const icon = btn.querySelector('i');
    icon.className = 'fas fa-spinner fa-spin text-sm';
    
    const fd = new FormData();
    fd.append('action', 'toggle_fav');
    fd.append('id', id);
    fd.append('csrf_token', document.getElementById('csrfF').value);
    
    fetch('', { method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: fd })
    .then(async r => { const txt = await r.text(); try { return JSON.parse(txt); } catch(e) { console.error('Raw response:', txt); throw new Error(txt); } })
    .then(res => {
        if(res.success) {
            const isFav = res.is_favourite;
            icon.className = isFav ? 'fas fa-star text-sm' : 'far fa-star text-sm';
            
            const listIcon = document.getElementById('fav-icon-' + id);
            if(listIcon) {
                if(isFav) {
                    listIcon.classList.remove('hidden', 'far');
                    listIcon.classList.add('fas');
                } else {
                    listIcon.classList.remove('fas');
                    listIcon.classList.add('hidden', 'far');
                }
            }
        } else {
            Swal.fire('Notice', 'Error toggling favorite', 'info');
            // reset icon - assuming it was what it wasn't
            icon.className = 'far fa-star text-sm';
        }
    })
    .catch(err => {
        Swal.fire('Notice', 'Network error', 'info');
        icon.className = 'far fa-star text-sm';
    });
}

async function deleteBenef(id, btn) {
    if(!await showConfirm('Delete this beneficiary?', 'Confirm Deletion')) return;
    
    const icon = btn.querySelector('i');
    icon.className = 'fas fa-spinner fa-spin text-sm';
    
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', id);
    fd.append('csrf_token', document.getElementById('csrfF').value);
    
    fetch('', { method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: fd })
    .then(async r => { const txt = await r.text(); try { return JSON.parse(txt); } catch(e) { console.error('Raw response:', txt); throw new Error(txt); } })
    .then(res => {
        if(res.success) {
            const row = document.getElementById('b-row-' + id);
            row.style.opacity = '0';
            setTimeout(() => {
                row.remove();
                // Check if empty
                if(document.querySelectorAll('[id^="b-row-"]').length === 0) {
                    location.reload(); // Reload to show empty state
                }
            }, 300);
        } else {
            Swal.fire('Notice', 'Error deleting beneficiary', 'info');
            icon.className = 'fas fa-trash-alt text-sm';
        }
    })
    .catch(err => {
        Swal.fire('Notice', 'Network error', 'info');
        icon.className = 'fas fa-trash-alt text-sm';
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
