<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
// Same fields are also editable from payment-gateways.php, which requires
// manage_api — match that gate here so there's no back door to the same
// setting (the bank account shown to every user for manual deposits).
require_permission('manage_api');
function k(string $key):string{return htmlspecialchars(setting($key) ?? '');}

if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??'')) {
        json_response(['success'=>false,'message'=>'Invalid token'],403);
    }
    $allowed=['manual_bank_name','manual_account_number','manual_account_name'];
    foreach($_POST as $key=>$val){
        if(in_array($key,$allowed)){
            setting_set($key,trim((string)$val));
        }
    }
    json_response(['success'=>true,'message'=>'Manual Funding settings saved successfully']);
}
$csrf=admin_csrf();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Manual Funding Settings - <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title>
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
        <h2>Manual Funding Config</h2>
        <p>Set the official banking details users will deposit money into.</p>
    </div>
</div>

<div class="data-panel" style="max-width:600px">
    <div class="panel-title-bar" style="border-bottom:1px solid #E2E8F0; margin-bottom:16px;">
        <h3 style="display:flex; align-items:center; gap:8px;">
            <div style="width:32px;height:32px;border-radius:8px;background:rgba(255,90,31,0.1);color:var(--primary);display:flex;align-items:center;justify-content:center;">
                <i class="fas fa-building-columns"></i>
            </div>
            Official Bank Details
        </h3>
    </div>
    <div class="modal-body" style="display:flex;flex-direction:column;gap:16px">
        <form id="mfForm" onsubmit="saveMF(event)">
            <input type="hidden" id="csrf" value="<?= $csrf ?>">
            
            <div class="form-group" style="margin-bottom:16px;">
                <label style="font-weight:600; font-size:13px; color:var(--navy-dark); margin-bottom:6px; display:block;">Bank Name</label>
                <input type="text" id="manual_bank_name" class="form-control" value="<?= k('manual_bank_name') ?>" placeholder="e.g. Opay, Moniepoint, Zenith">
            </div>
            
            <div class="form-group" style="margin-bottom:16px;">
                <label style="font-weight:600; font-size:13px; color:var(--navy-dark); margin-bottom:6px; display:block;">Account Number</label>
                <input type="text" id="manual_account_number" class="form-control" value="<?= k('manual_account_number') ?>" placeholder="e.g. 1234567890">
            </div>
            
            <div class="form-group" style="margin-bottom:20px;">
                <label style="font-weight:600; font-size:13px; color:var(--navy-dark); margin-bottom:6px; display:block;">Account Name</label>
                <input type="text" id="manual_account_name" class="form-control" value="<?= k('manual_account_name') ?>" placeholder="e.g. Gracehubtels Services">
            </div>
            
            <button type="submit" class="btn-primary" id="mfBtn" style="width:100%; justify-content:center; padding:12px; font-size:14px;">
                <i class="fas fa-save" style="margin-right:8px;"></i> Save Details
            </button>
        </form>
    </div>
</div>

</div>
</div>
<div class="save-toast" id="toast"></div>

<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
function showToast(msg,ok){
    const t=document.getElementById('toast');
    t.textContent=msg;
    t.className='save-toast show '+(ok?'ok':'err');
    setTimeout(()=>t.classList.remove('show'),2500);
}

async function saveMF(e){
    e.preventDefault();
    const btn = document.getElementById('mfBtn');
    btn.disabled = true;
    const ogHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:8px;"></i> Saving...';
    
    const fd = new FormData();
    fd.append('csrf', document.getElementById('csrf').value);
    fd.append('manual_bank_name', document.getElementById('manual_bank_name').value);
    fd.append('manual_account_number', document.getElementById('manual_account_number').value);
    fd.append('manual_account_name', document.getElementById('manual_account_name').value);
    
    try {
        const req = await fetch('manual-funding.php', {
            method: 'POST',
            headers: {'X-Requested-With': 'XMLHttpRequest'},
            body: fd
        });
        const data = await req.json();
        showToast(data.message, data.success);
    } catch(err){
        showToast('Network error occurred', false);
    }
    
    btn.disabled = false;
    btn.innerHTML = ogHtml;
}
</script>
</body>
</html>
