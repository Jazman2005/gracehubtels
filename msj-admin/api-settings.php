<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
function k(string $key):string{return htmlspecialchars(setting($key));}
if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    $allowed=['vendor_upgrade_fee','paymentpoint_bearer','paymentpoint_api_key','paymentpoint_webhook_secret','palmpay_bearer','palmpay_secret','palmpay_merchant_id','palmpay_sandbox','palmpay_enabled','palmpay_dynamic_enabled','palmpay_payout_enabled','easeid_bearer','easeid_secret','autopilot_api_key','autopilot_mtn_number','autopilot_glo_number','autopilot_airtel_number','autopilot_9mobile_number','cashwyre_api_key','cashwyre_secret_key','cashwyre_base_url','smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from_name','smtp_from_email'];
    foreach($_POST as $key=>$val){if(in_array($key,$allowed)){setting_set($key,trim((string)$val));}}
    json_response(['success'=>true,'message'=>'Settings saved successfully']);
}
$csrf=admin_csrf();
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>API Settings – MsjPay Admin</title><link rel="icon" type="image/png" href="/msjpay/assets/images/msjpay-favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="/msjpay/msj-admin/css/admin.css">
<style>.api-input-wrap{position:relative}.eye-toggle{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:14px}.setting-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0}.field-hint{font-size:11px;color:#9CA3AF;margin-top:3px;display:block}</style>
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="page-content">
<div class="page-head"><div><h2>API Settings</h2><p>Global API credentials for payment & verification providers</p></div></div>

<div class="row-2col">
  <!-- GENERAL SYSTEM SETTINGS -->
  <div class="card"><div class="card-header"><h3>⚙️ General Settings</h3></div>
    <div class="modal-body" style="display:flex;flex-direction:column;gap:14px">
      <div class="form-group"><label>Vendor Upgrade Fee (₦)</label><input type="number" step="0.01" class="form-control" id="vendor_upgrade_fee" value="<?=k('vendor_upgrade_fee')?>" placeholder="e.g. 1000.00"></div>
      <button class="btn-primary" onclick="saveMultiple(['vendor_upgrade_fee'])">Save</button>
    </div>
  </div>

  <!-- PAYMENTPOINT -->
  <div class="card"><div class="card-header"><h3>💰 PaymentPoint (Virtual Accounts)</h3></div>
    <div class="modal-body" style="display:flex;flex-direction:column;gap:14px">
      <div class="form-group"><label>Bearer Token</label><div class="api-input-wrap"><input type="password" class="form-control" id="paymentpoint_bearer" value="<?=k('paymentpoint_bearer')?>"><button type="button" class="eye-toggle" onclick="toggleVis('paymentpoint_bearer')">👁</button></div></div>
      <div class="form-group"><label>API Key</label><div class="api-input-wrap"><input type="password" class="form-control" id="paymentpoint_api_key" value="<?=k('paymentpoint_api_key')?>"><button type="button" class="eye-toggle" onclick="toggleVis('paymentpoint_api_key')">👁</button></div></div>
      <div class="form-group"><label>Webhook Secret</label><div class="api-input-wrap"><input type="password" class="form-control" id="paymentpoint_webhook_secret" value="<?=k('paymentpoint_webhook_secret')?>"><button type="button" class="eye-toggle" onclick="toggleVis('paymentpoint_webhook_secret')">👁</button></div><span class="field-hint">Webhook: /webhook/paymentpoint/</span></div>
      <button class="btn-primary" onclick="saveMultiple(['paymentpoint_bearer','paymentpoint_api_key','paymentpoint_webhook_secret'])">Save</button>
    </div>
  </div>


  <!-- EASEID -->
  <div class="card"><div class="card-header"><h3>🪪 EaseID (NIN/BVN KYC)</h3></div>
    <div class="modal-body" style="display:flex;flex-direction:column;gap:14px">
      <div class="form-group"><label>Bearer Token</label><div class="api-input-wrap"><input type="password" class="form-control" id="easeid_bearer" value="<?=k('easeid_bearer')?>"><button type="button" class="eye-toggle" onclick="toggleVis('easeid_bearer')">👁</button></div></div>
      <div class="form-group"><label>Secret (HMAC Signature)</label><div class="api-input-wrap"><input type="password" class="form-control" id="easeid_secret" value="<?=k('easeid_secret')?>"><button type="button" class="eye-toggle" onclick="toggleVis('easeid_secret')">👁</button></div></div>
      <button class="btn-primary" onclick="saveMultiple(['easeid_bearer','easeid_secret'])">Save</button>
    </div>
  </div>

  <!-- AUTOPILOT -->
  <div class="card"><div class="card-header"><h3>🔄 Autopilot NG (Airtime to Cash)</h3></div>
    <div class="modal-body" style="display:flex;flex-direction:column;gap:14px">
      <div class="form-group"><label>API Key</label><div class="api-input-wrap"><input type="password" class="form-control" id="autopilot_api_key" value="<?=k('autopilot_api_key')?>"><button type="button" class="eye-toggle" onclick="toggleVis('autopilot_api_key')">👁</button></div></div>
      <div class="form-grid">
        <div class="form-group"><label>MTN Receiving Number</label><input type="text" class="form-control" id="autopilot_mtn_number" value="<?=k('autopilot_mtn_number')?>" placeholder="0803..."></div>
        <div class="form-group"><label>GLO Receiving Number</label><input type="text" class="form-control" id="autopilot_glo_number" value="<?=k('autopilot_glo_number')?>" placeholder="0805..."></div>
        <div class="form-group"><label>AIRTEL Receiving Number</label><input type="text" class="form-control" id="autopilot_airtel_number" value="<?=k('autopilot_airtel_number')?>" placeholder="0802..."></div>
        <div class="form-group"><label>9MOBILE Receiving Number</label><input type="text" class="form-control" id="autopilot_9mobile_number" value="<?=k('autopilot_9mobile_number')?>" placeholder="0809..."></div>
      </div>
      <button class="btn-primary" onclick="saveMultiple(['autopilot_api_key','autopilot_mtn_number','autopilot_glo_number','autopilot_airtel_number','autopilot_9mobile_number'])">Save</button>
    </div>
  </div>

  <!-- CASHWYRE -->
  <div class="card"><div class="card-header"><h3>💳 Cashwyre (Virtual Cards)</h3></div>
    <div class="modal-body" style="display:flex;flex-direction:column;gap:14px">
      <div class="form-group"><label>API Key</label><div class="api-input-wrap"><input type="password" class="form-control" id="cashwyre_api_key" value="<?=k('cashwyre_api_key')?>"><button type="button" class="eye-toggle" onclick="toggleVis('cashwyre_api_key')">👁</button></div></div>
      <div class="form-group"><label>Secret Key</label><div class="api-input-wrap"><input type="password" class="form-control" id="cashwyre_secret_key" value="<?=k('cashwyre_secret_key')?>"><button type="button" class="eye-toggle" onclick="toggleVis('cashwyre_secret_key')">👁</button></div></div>
      <div class="form-group"><label>Base URL</label><input type="text" class="form-control" id="cashwyre_base_url" value="<?=k('cashwyre_base_url')?>"></div>
      <button class="btn-primary" onclick="saveMultiple(['cashwyre_api_key','cashwyre_secret_key','cashwyre_base_url'])">Save</button>
    </div>
  </div>

  <!-- SMTP -->
  <div class="card"><div class="card-header"><h3>📧 SMTP (Email)</h3></div>
    <div class="modal-body" style="display:flex;flex-direction:column;gap:14px">
      <div class="form-grid"><div class="form-group"><label>SMTP Host</label><input type="text" class="form-control" id="smtp_host" value="<?=k('smtp_host')?>"></div><div class="form-group"><label>Port</label><input type="text" class="form-control" id="smtp_port" value="<?=k('smtp_port')?>"></div></div>
      <div class="form-group"><label>SMTP Username</label><input type="text" class="form-control" id="smtp_user" value="<?=k('smtp_user')?>"></div>
      <div class="form-group"><label>SMTP Password</label><div class="api-input-wrap"><input type="password" class="form-control" id="smtp_pass" value="<?=k('smtp_pass')?>"><button type="button" class="eye-toggle" onclick="toggleVis('smtp_pass')">👁</button></div></div>
      <div class="form-grid"><div class="form-group"><label>From Name</label><input type="text" class="form-control" id="smtp_from_name" value="<?=k('smtp_from_name')?>"></div><div class="form-group"><label>From Email</label><input type="text" class="form-control" id="smtp_from_email" value="<?=k('smtp_from_email')?>"></div></div>
      <button class="btn-primary" onclick="saveMultiple(['smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from_name','smtp_from_email'])">Save</button>
    </div>
  </div>
</div>

</div></div>
<div class="save-toast" id="toast"></div>
<script>
const CSRF='<?=$csrf?>';
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
function toggleVis(id){const e=document.getElementById(id);e.type=e.type==='password'?'text':'password';}
async function saveMultiple(ids){const fd=new FormData();fd.append('csrf',CSRF);ids.forEach(id=>fd.append(id,document.getElementById(id).value));const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);}

function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),2500);}
</script>
</body></html>
