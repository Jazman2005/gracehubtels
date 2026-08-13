<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
function k(string $key,string $d=''):string{return htmlspecialchars(setting($key,$d));}
// Secret fields (API tokens) must not render for viewers who can't act on them —
// auth_admin() alone (no role/permission) lets any admin, including 'support',
// load this page; only the manage_settings-gated POST below can change them.
function k_secret(string $key,string $d=''):string{return has_permission('manage_settings')?htmlspecialchars(setting($key,$d)):'';}
if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    require_permission('manage_settings');
    // NOTE: p2p_transfer_charge(_enabled) and limit_daily_user are the actual
    // keys read by the money-moving code (core/internal_transfer.php,
    // user/core/db.php's check_daily_limit()) — these fields used to save
    // under internal_transfer_charge(_enabled)/limit_daily_regular instead,
    // which meant editing them here had zero effect on real enforcement.
    $allowed=['site_name','site_tagline','site_email','site_phone','site_address','site_domain','maintenance_mode','registration_enabled','min_wallet_funding','max_wallet_funding','min_transfer_amount','max_transfer_amount','transfer_charge','min_a2c_amount','max_a2c_amount','virtual_card_creation_fee','vendor_upgrade_fee','kyc_tier1_limit_daily','kyc_tier1_limit_single','kyc_tier2_limit_daily','kyc_tier2_limit_single','kyc_tier3_limit_daily','kyc_tier3_limit_single','p2p_transfer_charge','p2p_transfer_charge_enabled', 'limit_daily_user', 'limit_daily_agent', 'limit_daily_vendor', 'limit_airtime_daily', 'limit_airtime_min', 'limit_airtime_max', 'limit_transfer_daily', 'green_api_id_instance', 'green_api_token_instance', 'green_api_recipient', 'wa_notif_withdrawals', 'wa_notif_manual_funding', 'wa_notif_a2c', 'wa_notif_admin_login', 'wa_notif_failed_tx', 'network_prefixes_mtn', 'network_prefixes_glo', 'network_prefixes_airtel', 'network_prefixes_9mobile'];
    foreach($_POST as $key=>$val){if(in_array($key,$allowed))setting_set($key,trim((string)$val));}
    json_response(['success'=>true,'message'=>'Settings saved']);
}
$csrf=admin_csrf();
?><!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Settings &ndash; <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title><link rel="icon" type="image/png" href="/assets/images/favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">
<div class="section-header"><div><h2><i class="fas fa-cog" style="color:var(--primary);margin-right:10px"></i>Site Settings</h2><p>General platform configuration</p></div></div>

<div class="row-2col">

<div class="data-panel">
<div class="panel-title-bar"><h3><i class="fas fa-globe" style="color:var(--primary)"></i> General</h3></div>
<div class="modal-body" style="display:flex;flex-direction:column;gap:14px;padding:20px 24px">
<div class="form-group"><label>Site Name</label><input type="text" class="form-control" id="site_name" value="<?=k('site_name')?>"></div>
<div class="form-group"><label>Tagline</label><input type="text" class="form-control" id="site_tagline" value="<?=k('site_tagline')?>"></div>
<div class="form-group"><label>Support Email</label><input type="text" class="form-control" id="site_email" value="<?=k('site_email')?>"></div>
<div class="form-group"><label>Support Phone</label><input type="text" class="form-control" id="site_phone" value="<?=k('site_phone')?>"></div>
<div class="form-group"><label>Address</label><input type="text" class="form-control" id="site_address" value="<?=k('site_address')?>"></div>
<div class="form-group"><label>Domain <span style="color:var(--text-muted);font-weight:400">(optional — leave blank to auto-detect from the current request; only set this to force a specific domain, e.g. for emails sent from a cron job)</span></label><input type="text" class="form-control" id="site_domain" placeholder="e.g. gracehubtels.com" value="<?=k('site_domain')?>"></div>
<div class="flex items-center justify-between" style="padding:12px 0"><label style="font-size:13px;font-weight:600">Maintenance Mode</label><label class="toggle"><input type="checkbox" id="maintenance_mode" <?=setting('maintenance_mode')==='1'?'checked':''?>><span class="slider"></span></label></div>
<div class="flex items-center justify-between" style="padding:12px 0;border-top:1px solid var(--border-light)"><label style="font-size:13px;font-weight:600">Registration Enabled</label><label class="toggle"><input type="checkbox" id="registration_enabled" <?=setting('registration_enabled','1')==='1'?'checked':''?>><span class="slider"></span></label></div>
<div class="form-group"><label>Vendor Upgrade Fee (&#8358;)</label><input type="number" class="form-control" id="vendor_upgrade_fee" value="<?=k('vendor_upgrade_fee','1000')?>"></div>
<button class="btn-primary" onclick="saveGeneral()"><i class="fas fa-save"></i> Save</button>
</div></div>

<div class="data-panel">
<div class="panel-title-bar"><h3><i class="fas fa-arrows-left-right" style="color:var(--warning)"></i> Transaction Limits</h3></div>
<div class="modal-body" style="display:flex;flex-direction:column;gap:14px;padding:20px 24px">
<div class="form-grid"><div class="form-group"><label>Min Wallet Funding</label><input type="number" class="form-control" id="min_wallet_funding" value="<?=k('min_wallet_funding')?>"></div><div class="form-group"><label>Max Wallet Funding</label><input type="number" class="form-control" id="max_wallet_funding" value="<?=k('max_wallet_funding')?>"></div></div>
<div class="form-grid"><div class="form-group"><label>Min Transfer</label><input type="number" class="form-control" id="min_transfer_amount" value="<?=k('min_transfer_amount')?>"></div><div class="form-group"><label>Max Transfer</label><input type="number" class="form-control" id="max_transfer_amount" value="<?=k('max_transfer_amount')?>"></div></div>
<div class="form-group"><label>Daily Transfer Limit (&#8358;)</label><input type="number" class="form-control" id="limit_transfer_daily" value="<?=k('limit_transfer_daily')?>"></div>
<div class="form-group"><label>Transfer Charge (&#8358;) &mdash; Bank</label><input type="number" class="form-control" id="transfer_charge" value="<?=k('transfer_charge')?>"></div>
<div class="form-grid">
<div class="flex items-center justify-between" style="padding:10px 0"><label style="font-size:13px;font-weight:600">P2P Charge Enabled</label><label class="toggle"><input type="checkbox" id="p2p_transfer_charge_enabled" <?=setting('p2p_transfer_charge_enabled')==='1'?'checked':''?>><span class="slider"></span></label></div>
<div class="form-group"><label>P2P Charge (&#8358;)</label><input type="number" class="form-control" id="p2p_transfer_charge" value="<?=k('p2p_transfer_charge', '0')?>"></div>
</div>
<div class="form-grid"><div class="form-group"><label>Min A2C</label><input type="number" class="form-control" id="min_a2c_amount" value="<?=k('min_a2c_amount')?>"></div><div class="form-group"><label>Max A2C</label><input type="number" class="form-control" id="max_a2c_amount" value="<?=k('max_a2c_amount')?>"></div></div>
<div class="form-group"><label>Virtual Card Creation Fee (&#8358;)</label><input type="number" class="form-control" id="virtual_card_creation_fee" value="<?=k('virtual_card_creation_fee','1000')?>"></div>
<button class="btn-primary" onclick="saveLimits()"><i class="fas fa-save"></i> Save</button>
</div></div>

<div class="data-panel">
<div class="panel-title-bar"><h3><i class="fas fa-user-tag" style="color:var(--info)"></i> Role Daily Limits</h3></div>
<div class="modal-body" style="display:flex;flex-direction:column;gap:14px;padding:20px 24px">
<div class="form-group"><label>Regular Daily Limit (&#8358;)</label><input type="number" class="form-control" id="limit_daily_user" value="<?=k('limit_daily_user', '50000')?>"></div>
<div class="form-group"><label>Agent Daily Limit (&#8358;)</label><input type="number" class="form-control" id="limit_daily_agent" value="<?=k('limit_daily_agent', '200000')?>"></div>
<div class="form-group"><label>Vendor Daily Limit (&#8358;)</label><input type="number" class="form-control" id="limit_daily_vendor" value="<?=k('limit_daily_vendor', '1000000')?>"></div>
<button class="btn-primary" onclick="saveRoleLimits()"><i class="fas fa-save"></i> Save</button>
</div></div>

<div class="data-panel">
<div class="panel-title-bar"><h3><i class="fas fa-phone" style="color:var(--success)"></i> Airtime Limits</h3></div>
<div class="modal-body" style="display:flex;flex-direction:column;gap:14px;padding:20px 24px">
<div class="form-group"><label>Airtime Daily Limit (&#8358;)</label><input type="number" class="form-control" id="limit_airtime_daily" value="<?=k('limit_airtime_daily', '20000')?>"></div>
<div class="form-grid"><div class="form-group"><label>Min Airtime Per TX</label><input type="number" class="form-control" id="limit_airtime_min" value="<?=k('limit_airtime_min', '50')?>"></div><div class="form-group"><label>Max Airtime Per TX</label><input type="number" class="form-control" id="limit_airtime_max" value="<?=k('limit_airtime_max', '10000')?>"></div></div>
<button class="btn-primary" onclick="saveAirtimeLimits()"><i class="fas fa-save"></i> Save</button>
</div></div>

<div class="data-panel">
<div class="panel-title-bar"><h3><i class="fas fa-shield" style="color:var(--danger)"></i> KYC Tier Limits</h3></div>
<div class="modal-body" style="display:flex;flex-direction:column;gap:14px;padding:20px 24px">
<div class="form-grid"><div class="form-group"><label>Tier 1 Single (&#8358;)</label><input type="number" class="form-control" id="kyc_tier1_limit_single" value="<?=k('kyc_tier1_limit_single')?>"></div><div class="form-group"><label>Tier 1 Daily (&#8358;)</label><input type="number" class="form-control" id="kyc_tier1_limit_daily" value="<?=k('kyc_tier1_limit_daily')?>"></div></div>
<div class="form-grid"><div class="form-group"><label>Tier 2 Single (&#8358;)</label><input type="number" class="form-control" id="kyc_tier2_limit_single" value="<?=k('kyc_tier2_limit_single')?>"></div><div class="form-group"><label>Tier 2 Daily (&#8358;)</label><input type="number" class="form-control" id="kyc_tier2_limit_daily" value="<?=k('kyc_tier2_limit_daily')?>"></div></div>
<div class="form-grid"><div class="form-group"><label>Tier 3 Single (&#8358;)</label><input type="number" class="form-control" id="kyc_tier3_limit_single" value="<?=k('kyc_tier3_limit_single')?>"></div><div class="form-group"><label>Tier 3 Daily (&#8358;)</label><input type="number" class="form-control" id="kyc_tier3_limit_daily" value="<?=k('kyc_tier3_limit_daily')?>"></div></div>
<button class="btn-primary" onclick="saveKyc()"><i class="fas fa-save"></i> Save</button>
</div></div>
<div class="data-panel" id="network-prefixes">
<div class="panel-title-bar"><h3><i class="fas fa-network-wired" style="color:var(--primary)"></i> Network Auto-Selector Prefixes</h3></div>
<div class="modal-body" style="display:flex;flex-direction:column;gap:14px;padding:20px 24px">
<p style="font-size:12px;color:var(--text-muted); margin:0;">Comma-separated prefixes used to auto-select networks for airtime/data (e.g., 0803,0703,0903).</p>
<div class="form-group"><label>MTN Prefixes</label><input type="text" class="form-control" id="network_prefixes_mtn" value="<?=k('network_prefixes_mtn', '0803,0806,0703,0903,0813,0816,0810,0814,0906,0706,0913,0916')?>"></div>
<div class="form-group"><label>GLO Prefixes</label><input type="text" class="form-control" id="network_prefixes_glo" value="<?=k('network_prefixes_glo', '0805,0705,0905,0807,0815,0811,0915')?>"></div>
<div class="form-group"><label>AIRTEL Prefixes</label><input type="text" class="form-control" id="network_prefixes_airtel" value="<?=k('network_prefixes_airtel', '0802,0902,0701,0808,0708,0812,0907,0901,0912,0904,0704')?>"></div>
<div class="form-group"><label>9MOBILE Prefixes</label><input type="text" class="form-control" id="network_prefixes_9mobile" value="<?=k('network_prefixes_9mobile', '0809,0909,0817,0818')?>"></div>
<button class="btn-primary" onclick="saveNetworkPrefixes()"><i class="fas fa-save"></i> Save</button>
</div></div>

<div class="data-panel">
<div class="panel-title-bar"><h3><i class="fab fa-whatsapp" style="color:#25D366"></i> Green API WhatsApp Settings</h3></div>
<div class="modal-body" style="display:flex;flex-direction:column;gap:14px;padding:20px 24px">
<p style="font-size:12px; color:var(--text-muted); margin:0;">These credentials are used to send automated WhatsApp alerts to the admin regarding withdrawals, funding, etc.</p>
<div class="form-group"><label>Id Instance</label><input type="text" class="form-control" id="green_api_id_instance" value="<?=k('green_api_id_instance')?>"></div>
<div class="form-group"><label>Token Instance</label><input type="password" class="form-control" id="green_api_token_instance" value="<?=k_secret('green_api_token_instance')?>"></div>
<div class="form-group"><label>Admin Receiving Phone Number</label><input type="text" class="form-control" id="green_api_recipient" value="<?=k('green_api_recipient')?>" placeholder="e.g. 2348012345678"></div>
<hr style="border: 0; border-top: 1px solid var(--border-light); margin: 8px 0;">
<label style="font-size:13px;font-weight:600;margin-bottom:4px;display:block">Notification Preferences</label>
<div class="form-grid">
    <div class="flex items-center justify-between"><label style="font-size:12px">Withdrawal Requests</label><label class="toggle"><input type="checkbox" id="wa_notif_withdrawals" <?=setting('wa_notif_withdrawals','1')==='1'?'checked':''?>><span class="slider"></span></label></div>
    <div class="flex items-center justify-between"><label style="font-size:12px">Manual Funding</label><label class="toggle"><input type="checkbox" id="wa_notif_manual_funding" <?=setting('wa_notif_manual_funding','1')==='1'?'checked':''?>><span class="slider"></span></label></div>
    <div class="flex items-center justify-between"><label style="font-size:12px">Airtime to Cash</label><label class="toggle"><input type="checkbox" id="wa_notif_a2c" <?=setting('wa_notif_a2c','1')==='1'?'checked':''?>><span class="slider"></span></label></div>
    <div class="flex items-center justify-between"><label style="font-size:12px">Failed Transactions</label><label class="toggle"><input type="checkbox" id="wa_notif_failed_tx" <?=setting('wa_notif_failed_tx','1')==='1'?'checked':''?>><span class="slider"></span></label></div>
    <div class="flex items-center justify-between"><label style="font-size:12px">Admin Login</label><label class="toggle"><input type="checkbox" id="wa_notif_admin_login" <?=setting('wa_notif_admin_login','1')==='1'?'checked':''?>><span class="slider"></span></label></div>
</div>
<button class="btn-primary" onclick="saveWhatsApp()"><i class="fas fa-save"></i> Save</button>
</div></div>
</div>
</div></div>
<div class="save-toast" id="toast"></div>
<script>
const CSRF='<?=$csrf?>';
async function postSave(ids,toggles=[]){const fd=new FormData();fd.append('csrf',CSRF);ids.forEach(id=>fd.append(id,document.getElementById(id).value));toggles.forEach(id=>fd.append(id,document.getElementById(id).checked?'1':'0'));const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);}
function saveGeneral(){postSave(['site_name','site_tagline','site_email','site_phone','site_address','site_domain','vendor_upgrade_fee'],['maintenance_mode','registration_enabled']);}
function saveLimits(){postSave(['min_wallet_funding','max_wallet_funding','min_transfer_amount','max_transfer_amount','limit_transfer_daily','transfer_charge','p2p_transfer_charge','min_a2c_amount','max_a2c_amount','virtual_card_creation_fee'],['p2p_transfer_charge_enabled']);}
function saveRoleLimits(){postSave(['limit_daily_user','limit_daily_agent','limit_daily_vendor']);}
function saveAirtimeLimits(){postSave(['limit_airtime_daily','limit_airtime_min','limit_airtime_max']);}
function saveKyc(){postSave(['kyc_tier1_limit_single','kyc_tier1_limit_daily','kyc_tier2_limit_single','kyc_tier2_limit_daily','kyc_tier3_limit_single','kyc_tier3_limit_daily']);}
function saveWhatsApp(){postSave(['green_api_id_instance','green_api_token_instance','green_api_recipient'], ['wa_notif_withdrawals','wa_notif_manual_funding','wa_notif_a2c','wa_notif_admin_login','wa_notif_failed_tx']);}
function saveNetworkPrefixes(){postSave(['network_prefixes_mtn','network_prefixes_glo','network_prefixes_airtel','network_prefixes_9mobile']);}
function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),2500);}
</script>
</body></html>
