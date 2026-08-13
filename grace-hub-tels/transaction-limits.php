<?php
require_once __DIR__.'/core/auth.php';
auth_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    if (!verify_admin_csrf($_POST['csrf'] ?? '')) json_response(['success'=>false,'message'=>'Invalid token'], 403);
    require_permission('manage_settings');

    // NOTE: limit_daily_user and p2p_transfer_charge(_enabled) are also
    // editable on settings.php — both pages share these same keys, which are
    // what the real enforcement code (user/core/db.php's check_daily_limit(),
    // core/internal_transfer.php) actually reads.
    $keys = [
        'limit_airtime_min', 'limit_airtime_max',
        'limit_betting_min', 'limit_betting_max',
        'limit_electricity_min', 'limit_electricity_max',
        'limit_transfer_min', 'limit_transfer_max',
        'limit_daily_user', 'limit_daily_agent', 'limit_daily_vendor',
        'p2p_transfer_charge'
    ];
    foreach ($keys as $k) {
        if (isset($_POST[$k])) setting_set($k, trim((string)$_POST[$k]));
    }
    setting_set('p2p_transfer_charge_enabled', isset($_POST['p2p_transfer_charge_enabled']) ? '1' : '0');

    json_response(['success'=>true, 'message'=>'Transaction limits updated successfully']);
}

$csrf = admin_csrf();
function tl(string $key, string $d = ''): string { return htmlspecialchars(setting($key, $d)); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Transaction Limits & Controls — <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title>
    <link rel="icon" type="image/png" href="/assets/images/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header">
    <div>
        <h2>Transaction Limits & Controls</h2>
        <p>Set daily transaction limits, transfer limits, and internal P2P charges.</p>
    </div>
    <button class="btn-primary" onclick="saveLimits()"><i class="fas fa-save"></i> Save Limits Configuration</button>
</div>

<div class="row-2col">

<div class="data-panel">
    <div class="panel-title-bar"><h3><i class="fas fa-calendar-day" style="color:var(--primary)"></i> Global Daily Limits</h3></div>
    <div class="modal-body" style="display:flex;flex-direction:column;gap:14px;padding:20px 24px">
        <div class="form-group"><label>User Tier Limit (&#8358;)</label><input type="number" class="form-control" id="limit_daily_user" value="<?= tl('limit_daily_user', '50000') ?>"></div>
        <div class="form-group"><label>Agent Tier Limit (&#8358;)</label><input type="number" class="form-control" id="limit_daily_agent" value="<?= tl('limit_daily_agent', '200000') ?>"></div>
        <div class="form-group"><label>Vendor Tier Limit (&#8358;)</label><input type="number" class="form-control" id="limit_daily_vendor" value="<?= tl('limit_daily_vendor', '1000000') ?>"></div>
    </div>
</div>

<div class="data-panel">
    <div class="panel-title-bar"><h3><i class="fas fa-exchange-alt" style="color:var(--info)"></i> Transfer & Internal P2P</h3></div>
    <div class="modal-body" style="display:flex;flex-direction:column;gap:14px;padding:20px 24px">
        <div class="form-grid">
            <div class="form-group"><label>Minimum Transfer (&#8358;)</label><input type="number" class="form-control" id="limit_transfer_min" value="<?= tl('limit_transfer_min', '1000') ?>"></div>
            <div class="form-group"><label>Maximum Transfer (&#8358;)</label><input type="number" class="form-control" id="limit_transfer_max" value="<?= tl('limit_transfer_max', '500000') ?>"></div>
        </div>
        <div class="flex items-center justify-between" style="padding:10px 0;border-top:1px solid var(--border-light);margin-top:6px">
            <label style="font-size:13px;font-weight:600">Enable P2P Charge</label>
            <label class="toggle"><input type="checkbox" id="p2p_transfer_charge_enabled" <?= setting('p2p_transfer_charge_enabled','1')==='1' ? 'checked' : '' ?>><span class="slider"></span></label>
        </div>
        <div class="form-group"><label>P2P Charge Amount (&#8358;)</label><input type="number" class="form-control" id="p2p_transfer_charge" value="<?= tl('p2p_transfer_charge', '50') ?>"></div>
    </div>
</div>

<div class="data-panel">
    <div class="panel-title-bar"><h3><i class="fas fa-mobile-alt" style="color:var(--warning)"></i> Airtime Limits</h3></div>
    <div class="modal-body" style="display:flex;flex-direction:column;gap:14px;padding:20px 24px">
        <div class="form-grid">
            <div class="form-group"><label>Minimum per Transaction (&#8358;)</label><input type="number" class="form-control" id="limit_airtime_min" value="<?= tl('limit_airtime_min', '50') ?>"></div>
            <div class="form-group"><label>Maximum per Transaction (&#8358;)</label><input type="number" class="form-control" id="limit_airtime_max" value="<?= tl('limit_airtime_max', '10000') ?>"></div>
        </div>
    </div>
</div>

<div class="data-panel">
    <div class="panel-title-bar"><h3><i class="fas fa-futbol" style="color:var(--success)"></i> Betting Funding Limits</h3></div>
    <div class="modal-body" style="display:flex;flex-direction:column;gap:14px;padding:20px 24px">
        <div class="form-grid">
            <div class="form-group"><label>Minimum per Transaction (&#8358;)</label><input type="number" class="form-control" id="limit_betting_min" value="<?= tl('limit_betting_min', '100') ?>"></div>
            <div class="form-group"><label>Maximum per Transaction (&#8358;)</label><input type="number" class="form-control" id="limit_betting_max" value="<?= tl('limit_betting_max', '500000') ?>"></div>
        </div>
    </div>
</div>

<div class="data-panel">
    <div class="panel-title-bar"><h3><i class="fas fa-bolt" style="color:var(--warning)"></i> Electricity Token Limits</h3></div>
    <div class="modal-body" style="display:flex;flex-direction:column;gap:14px;padding:20px 24px">
        <div class="form-grid">
            <div class="form-group"><label>Minimum per Transaction (&#8358;)</label><input type="number" class="form-control" id="limit_electricity_min" value="<?= tl('limit_electricity_min', '1000') ?>"></div>
            <div class="form-group"><label>Maximum per Transaction (&#8358;)</label><input type="number" class="form-control" id="limit_electricity_max" value="<?= tl('limit_electricity_max', '50000') ?>"></div>
        </div>
    </div>
</div>

</div>

</div></div>
<div class="save-toast" id="toast"></div>
<script>
const CSRF = '<?= $csrf ?>';
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),2500);}

async function saveLimits(){
    const ids=['limit_daily_user','limit_daily_agent','limit_daily_vendor','limit_transfer_min','limit_transfer_max',
        'p2p_transfer_charge','limit_airtime_min','limit_airtime_max','limit_betting_min','limit_betting_max',
        'limit_electricity_min','limit_electricity_max'];
    const fd=new FormData();
    fd.append('csrf',CSRF);
    ids.forEach(id=>fd.append(id,document.getElementById(id).value));
    fd.append('p2p_transfer_charge_enabled', document.getElementById('p2p_transfer_charge_enabled').checked?'1':'0');
    const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    showToast(r.message,r.success);
}
</script>
</body>
</html>
