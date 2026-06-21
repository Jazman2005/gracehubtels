<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
function k(string $key,string $d=''):string{return htmlspecialchars(setting($key,$d));}
if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    $allowed=['site_name','site_tagline','site_email','site_phone','site_address','maintenance_mode','registration_enabled','min_wallet_funding','max_wallet_funding','min_transfer_amount','max_transfer_amount','transfer_charge','min_a2c_amount','max_a2c_amount','virtual_card_creation_fee','kyc_tier1_limit_daily','kyc_tier1_limit_single','kyc_tier2_limit_daily','kyc_tier2_limit_single','kyc_tier3_limit_daily','kyc_tier3_limit_single'];
    foreach($_POST as $key=>$val){if(in_array($key,$allowed))setting_set($key,trim((string)$val));}
    json_response(['success'=>true,'message'=>'Settings saved']);
}
$csrf=admin_csrf();
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Settings – MsjPay Admin</title><link rel="icon" type="image/png" href="/msjpay/assets/images/msjpay-favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="/msjpay/msj-admin/css/admin.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="page-content">
<div class="page-head"><div><h2>Site Settings</h2><p>General platform configuration</p></div></div>

<div class="row-2col">
  <div class="card"><div class="card-header"><h3>General</h3></div><div class="modal-body" style="display:flex;flex-direction:column;gap:14px">
    <div class="form-group"><label>Site Name</label><input type="text" class="form-control" id="site_name" value="<?=k('site_name')?>"></div>
    <div class="form-group"><label>Tagline</label><input type="text" class="form-control" id="site_tagline" value="<?=k('site_tagline')?>"></div>
    <div class="form-group"><label>Support Email</label><input type="text" class="form-control" id="site_email" value="<?=k('site_email')?>"></div>
    <div class="form-group"><label>Support Phone</label><input type="text" class="form-control" id="site_phone" value="<?=k('site_phone')?>"></div>
    <div class="form-group"><label>Address</label><input type="text" class="form-control" id="site_address" value="<?=k('site_address')?>"></div>
    <div class="setting-row" style="display:flex;justify-content:space-between"><label style="font-size:13px;font-weight:600">Maintenance Mode</label><label class="toggle"><input type="checkbox" id="maintenance_mode" <?=setting('maintenance_mode')==='1'?'checked':''?>><span class="slider"></span></label></div>
    <div class="setting-row" style="display:flex;justify-content:space-between"><label style="font-size:13px;font-weight:600">Registration Enabled</label><label class="toggle"><input type="checkbox" id="registration_enabled" <?=setting('registration_enabled','1')==='1'?'checked':''?>><span class="slider"></span></label></div>
    <button class="btn-primary" onclick="saveGeneral()">Save</button>
  </div></div>

  <div class="card"><div class="card-header"><h3>Transaction Limits</h3></div><div class="modal-body" style="display:flex;flex-direction:column;gap:14px">
    <div class="form-grid"><div class="form-group"><label>Min Wallet Funding</label><input type="number" class="form-control" id="min_wallet_funding" value="<?=k('min_wallet_funding')?>"></div><div class="form-group"><label>Max Wallet Funding</label><input type="number" class="form-control" id="max_wallet_funding" value="<?=k('max_wallet_funding')?>"></div></div>
    <div class="form-grid"><div class="form-group"><label>Min Transfer</label><input type="number" class="form-control" id="min_transfer_amount" value="<?=k('min_transfer_amount')?>"></div><div class="form-group"><label>Max Transfer</label><input type="number" class="form-control" id="max_transfer_amount" value="<?=k('max_transfer_amount')?>"></div></div>
    <div class="form-group"><label>Transfer Charge (₦)</label><input type="number" class="form-control" id="transfer_charge" value="<?=k('transfer_charge')?>"></div>
    <div class="form-grid"><div class="form-group"><label>Min A2C</label><input type="number" class="form-control" id="min_a2c_amount" value="<?=k('min_a2c_amount')?>"></div><div class="form-group"><label>Max A2C</label><input type="number" class="form-control" id="max_a2c_amount" value="<?=k('max_a2c_amount')?>"></div></div>
    <button class="btn-primary" onclick="saveLimits()">Save</button>
  </div></div>

  <div class="card"><div class="card-header"><h3>KYC Tier Limits</h3></div><div class="modal-body" style="display:flex;flex-direction:column;gap:14px">
    <div class="form-grid"><div class="form-group"><label>Tier 1 Single (₦)</label><input type="number" class="form-control" id="kyc_tier1_limit_single" value="<?=k('kyc_tier1_limit_single')?>"></div><div class="form-group"><label>Tier 1 Daily (₦)</label><input type="number" class="form-control" id="kyc_tier1_limit_daily" value="<?=k('kyc_tier1_limit_daily')?>"></div></div>
    <div class="form-grid"><div class="form-group"><label>Tier 2 Single (₦)</label><input type="number" class="form-control" id="kyc_tier2_limit_single" value="<?=k('kyc_tier2_limit_single')?>"></div><div class="form-group"><label>Tier 2 Daily (₦)</label><input type="number" class="form-control" id="kyc_tier2_limit_daily" value="<?=k('kyc_tier2_limit_daily')?>"></div></div>
    <div class="form-grid"><div class="form-group"><label>Tier 3 Single (₦)</label><input type="number" class="form-control" id="kyc_tier3_limit_single" value="<?=k('kyc_tier3_limit_single')?>"></div><div class="form-group"><label>Tier 3 Daily (₦)</label><input type="number" class="form-control" id="kyc_tier3_limit_daily" value="<?=k('kyc_tier3_limit_daily')?>"></div></div>
    <button class="btn-primary" onclick="saveKyc()">Save</button>
  </div></div>
</div>
</div></div>
<div class="save-toast" id="toast"></div>
<script>
const CSRF='<?=$csrf?>';
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
async function postSave(ids,toggles=[]){const fd=new FormData();fd.append('csrf',CSRF);ids.forEach(id=>fd.append(id,document.getElementById(id).value));toggles.forEach(id=>fd.append(id,document.getElementById(id).checked?'1':'0'));const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);}
function saveGeneral(){postSave(['site_name','site_tagline','site_email','site_phone','site_address'],['maintenance_mode','registration_enabled']);}
function saveLimits(){postSave(['min_wallet_funding','max_wallet_funding','min_transfer_amount','max_transfer_amount','transfer_charge','min_a2c_amount','max_a2c_amount']);}
function saveKyc(){postSave(['kyc_tier1_limit_single','kyc_tier1_limit_daily','kyc_tier2_limit_single','kyc_tier2_limit_daily','kyc_tier3_limit_single','kyc_tier3_limit_daily']);}
function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),2500);}
</script>
</body></html>
