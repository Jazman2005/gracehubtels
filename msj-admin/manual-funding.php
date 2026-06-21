<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
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
    <title>Manual Funding Settings - MsjPay Admin</title>
    <link rel="icon" type="image/png" href="/msjpay/assets/images/msjpay-favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/msjpay/msj-admin/css/admin.css">
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="page-content">
<div class="page-head">
    <div>
        <h2>Manual Funding Settings</h2>
        <p>Configure the official bank details users will send money to.</p>
    </div>
</div>

<div class="card" style="max-width:600px">
    <div class="card-header">
        <h3>🏦 Official Bank Details</h3>
    </div>
    <div class="card-body">
        <form id="mfForm" onsubmit="saveMF(event)">
            <input type="hidden" id="csrf" value="<?= $csrf ?>">
            <div class="field-group">
                <label>Bank Name</label>
                <input type="text" id="manual_bank_name" class="input" value="<?= k('manual_bank_name') ?>" placeholder="e.g. Opay, Moniepoint, Zenith">
            </div>
            <div class="field-group">
                <label>Account Number</label>
                <input type="text" id="manual_account_number" class="input" value="<?= k('manual_account_number') ?>" placeholder="e.g. 1234567890">
            </div>
            <div class="field-group">
                <label>Account Name</label>
                <input type="text" id="manual_account_name" class="input" value="<?= k('manual_account_name') ?>" placeholder="e.g. MsjPay Services">
            </div>
            
            <button type="submit" class="btn-primary" id="mfBtn" style="margin-top:16px">Save Details</button>
            <div id="mfRes" style="margin-top:12px"></div>
        </form>
    </div>
</div>

</div>
</div>

<script>
async function saveMF(e){
    e.preventDefault();
    const btn = document.getElementById('mfBtn');
    const res = document.getElementById('mfRes');
    btn.disabled = true;
    btn.textContent = 'Saving...';
    res.innerHTML = '';
    
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
        if(data.success){
            res.innerHTML = '<span style="color:#10B981;font-size:13px;font-weight:600">'+data.message+'</span>';
        } else {
            res.innerHTML = '<span style="color:#EF4444;font-size:13px;font-weight:600">'+data.message+'</span>';
        }
    } catch(err){
        res.innerHTML = '<span style="color:#EF4444;font-size:13px;font-weight:600">Network error</span>';
    }
    btn.disabled = false;
    btn.textContent = 'Save Details';
}
</script>
</body>
</html>
