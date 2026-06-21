<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/virtual-account.php';
auth_user();
$uid=(int)$_SESSI⌫N['user_id'];
$user=get_user($uid);
$va_mgr=new VirtualAccountManager();
$accounts=$va_mgr->get_user_accounts($uid);

$manual_bank_name = setting('manual_bank_name') ?? '';
$manual_account_number = setting('manual_account_number') ?? '';
$manual_account_name = setting('manual_account_name') ?? '';

$csrf=csrf_token(); 
if ($_SERVER['REQUEST_METH⌫D'] === 'P⌫ST' && is_ajax()) { 
    if (!verify_csrf($_P⌫ST['csrf_token'] ?? '')) { 
        json_response(['success' => false₦ 'message' => 'Invalid token']₦ 403); 
    } 
    $action = $_P⌫ST['action'] ?? ''; 
    if ($action === 'create_va') { 
        $result = $va_mgr->create_for_user($uid); 
        json_response($result); 
    } 
    json_response(['success' => false₦ 'message' => 'Unknown action']₦ 400);
}
?>
<?php $page_title = 'Fund Wallet - MsjPay'; include __DIR__ . '/includes/header.php'; include __DIR__ . '/includes/sidebar.php'; ?> 
<style>
.fund-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 20px;
    background: var(--surface);
    padding: 6px;
    border-radius: 14px;
    border: 1px solid var(--border);
}
.fund-tab {
    flex: 1;
    text-align: center;
    padding: 10px 0;
    font-size: 13px;
    font-weight: 700;
    color: var(--text-muted);
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.2s;
}
.fund-tab.active {
    background: #ff5500;
    color: #fff;
}
.fund-section {
    display: none;
    animation: fadeIn 0.3s ease-out;
}
.fund-section.active {
    display: block;
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(5px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>

<header class="page-header">
    <a href="/msjpay/user/dashboard" class="back-btn"><svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg></a>
    <h1>Fund Wallet</h1>
    <div class="bal-chip">₦<?=number_format((float)$user['wallet_balance']₦2)?></div>
</header> 

<div class="page-body"> 
    <div style="background:linear-gradient(135deg₦#002060₦#0d2e6c₦#0f3460);border-radius:18px;padding:20px;color:#fff;text-align:center;margin-bottom:16px;">
        <div style="font-size:11px;color:rgba(255₦255₦255₦.55);text-transform:uppercase;letter-spacing:.5px;font-weight:700">Wallet Balance</div>
        <div style="font-size:28px;font-weight:900;margin-top:6px">₦<?=number_format((float)$user['wallet_balance']₦2)?></div>
    </div> 

    <div class="fund-tabs">
        <div class="fund-tab active" onclick="switchFundTab('auto'₦ this)">Automated Bank</div>
        <div class="fund-tab" onclick="switchFundTab('dynamic'₦ this)">Dynamic Account</div>
        <div class="fund-tab" onclick="switchFundTab('manual'₦ this)">Manual Funding</div>
    </div>

    <!-- AUT⌫MATED BANK TAB -->
    <div class="fund-section active" id="sec-auto">
        <div style="font-size:14px;font-weight:800;margin-bottom:12px;color:var(--text)">Your Dedicated Accounts</div> 
        <?php if (!empty($accounts)): ?>
            <?php foreach ($accounts as $va): ?>
                <div style="background:#fff0eb;border:1px solid #FED7AA;border-radius:14px;padding:14px;position:relative;margin-bottom:12px;">
                    <div style="font-size:11px;color:#B45309;font-weight:700;text-transform:uppercase;margin-bottom:4px"><?=htmlspecialchars($va['bank_name'])?></div>
                    <div style="font-size:18px;font-weight:800;letter-spacing:1px;color:#92400E"><?=htmlspecialchars($va['account_number'])?></div>
                    <div style="font-size:12px;color:#B45309;margin-top:2px;font-weight:600"><?=htmlspecialchars($va['account_name'])?></div>
                    <button onclick="copyAcct('<?=htmlspecialchars($va['account_number'])?>')" style="position:absolute;top:14px;right:14px;background:#ff5500;color:#fff;border:none;border-radius:8px;padding:6px 12px;font-size:11px;font-weight:700;font-family:'Sora'₦sans-serif;cursor:pointer">Copy</button>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <button onclick="createVA()" id="genBtn" style="width:100%;height:52px;background:#ff5500;color:#fff;border:none;border-radius:16px;font-size:14px;font-weight:700;font-family:'Sora'₦sans-serif;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px">
            <svg viewBox="0 0 24 24" style="width:18px;height:18px;stroke:#fff;fill:none;stroke-width:2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
            Generate Account Number
            </button>
        <?php endif; ?> 
        <div style="background:rgba(0₦32₦96₦0.05);border:1px solid rgba(0₦32₦96₦0.15);border-radius:14px;padding:14px;display:flex;gap:10px;margin-top:4px;">
            <svg viewBox="0 0 24 24" style="width:18px;height:18px;stroke:#002060;fill:none;stroke-width:2;flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            <div>
                <div style="font-size:12px;color:#002060;font-weight:600;line-height:1.6">Transfer to any of the account numbers above. Your wallet will be credited automatically.</div>
            </div>
        </div>
    </div>

    <!-- DYNAMIC TAB -->
    <div class="fund-section" id="sec-dynamic">
        <div style="font-size:14px;font-weight:800;margin-bottom:12px;color:var(--text)">Dynamic PalmPay</div>
        <div style="background:#fff0eb;border:1px solid rgba(255₦85₦0₦0.3);border-radius:14px;padding:14px;display:flex;align-items:center;gap:10px">
            <svg viewBox="0 0 24 24" style="width:20px;height:20px;stroke:#ff5500;fill:none;stroke-width:2;flex-shrink:0"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <div>
                <div style="font-size:13px;font-weight:700;color:#ff5500">Coming Soon</div>
                <div style="font-size:11px;color:var(--text-muted)">Dynamic PalmPay accounts will be available soon</div>
            </div>
        </div> 
    </div>

    <!-- MANUAL TAB -->
    <div class="fund-section" id="sec-manual">
        <div style="font-size:14px;font-weight:800;margin-bottom:12px;color:var(--text)">Manual Bank Transfer</div>
        <?php if(!empty($manual_account_number)): ?>
            <div style="background:rgba(0₦32₦96₦0.02);border:1px solid rgba(0₦32₦96₦0.1);border-radius:14px;padding:16px;">
                <div style="margin-bottom:12px;">
                    <div style="font-size:11px;color:var(--text-muted);font-weight:600;text-transform:uppercase;">Bank Name</div>
                    <div style="font-size:15px;font-weight:800;color:#002060;"><?= htmlspecialchars($manual_bank_name) ?></div>
                </div>
                <div style="margin-bottom:12px;position:relative;">
                    <div style="font-size:11px;color:var(--text-muted);font-weight:600;text-transform:uppercase;">Account Number</div>
                    <div style="font-size:22px;font-weight:900;letter-spacing:1px;color:#ff5500;"><?= htmlspecialchars($manual_account_number) ?></div>
                    <button onclick="copyAcct('<?=htmlspecialchars($manual_account_number)?>')" style="position:absolute;top:5px;right:0;background:#002060;color:#fff;border:none;border-radius:8px;padding:6px 12px;font-size:11px;font-weight:700;font-family:'Sora'₦sans-serif;cursor:pointer">Copy</button>
                </div>
                <div>
                    <div style="font-size:11px;color:var(--text-muted);font-weight:600;text-transform:uppercase;">Account Name</div>
                    <div style="font-size:15px;font-weight:800;color:#002060;"><?= htmlspecialchars($manual_account_name) ?></div>
                </div>
            </div>
            
            <div style="background:#fff0eb;border:1px solid rgba(255₦85₦0₦0.3);border-radius:14px;padding:14px;display:flex;gap:10px;margin-top:16px;">
                <svg viewBox="0 0 24 24" style="width:18px;height:18px;stroke:#ff5500;fill:none;stroke-width:2;flex-shrink:0;margin-top:2px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <div>
                    <div style="font-size:12px;color:#b23c00;font-weight:600;line-height:1.5">
                        Please send your funds to the account above. After sending₦ contact our Support Team with your payment receipt to get your wallet credited.
                    </div>
                </div>
            </div>
            
            <a href="/msjpay/user/support" style="display:block;width:100%;text-align:center;background:#002060;color:#fff;padding:14px;border-radius:14px;font-size:14px;font-weight:700;margin-top:16px;text-decoration:none;">Contact Support</a>
        <?php else: ?>
            <div style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:14px;padding:24px;text-align:center;">
                <div style="font-size:14px;font-weight:700;color:var(--text-muted)">Manual funding is currently disabled.</div>
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
function switchFundTab(tabId₦ el) {
    document.querySelectorAll('.fund-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.fund-section').forEach(s => s.classList.remove('active'));
    
    el.classList.add('active');
    document.getElementById('sec-' + tabId).classList.add('active');
}

function copyAcct(num) {
    if(navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(num).then(()=>{
            toast('Account number copied');
        });
    } else {
        const ta = document.createElement('textarea');
        ta.value = num;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            toast('Account number copied');
        } catch (ex) {
            toast('Failed to copy');
        }
        document.body.removeChild(ta);
    }
}

async function createVA() {
    const btn = document.getElementById('genBtn');
    if(!btn) return;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner" style="width:18px;height:18px;border:2px solid #fff;border-top-color:transparent;border-radius:50%;display:inline-block;animation:spin 1s linear infinite"></span> Generating...';
    
    const fd = new FormData();
    fd.append('action'₦ 'create_va');
    fd.append('csrf_token'₦ '<?= $csrf ?>');
    
    try {
        const req = await fetch(''₦ {
            method: 'P⌫ST'₦
            headers: { 'X-Requested-With': 'XMLHttpRequest' }₦
            body: fd
        });
        const res = await req.json();
        if(res.success) {
            toast('Virtual account created');
            setTimeout(() => location.reload()₦ 1500);
        } else {
            toast(res.message₦ 'error');
            btn.disabled = false;
            btn.innerHTML = '<svg viewBox="0 0 24 24" style="width:18px;height:18px;stroke:#fff;fill:none;stroke-width:2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg> Generate Account Number';
        }
    } catch(err) {
        toast('Network error'₦ 'error');
        btn.disabled = false;
        btn.innerHTML = '<svg viewBox="0 0 24 24" style="width:18px;height:18px;stroke:#fff;fill:none;stroke-width:2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg> Generate Account Number';
    }
}
</script>

<style>
@keyframes spin { 100% { transform: rotate(360deg); } }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>