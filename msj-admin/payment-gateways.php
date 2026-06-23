<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
require_permission('manage_api'); // Superadmin or explicit permission

if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    
    $action = $_POST['action'] ?? '';
    
    if($action === 'save_gateways'){
        $keys = [
            // Moniepoint
            'gw_moniepoint_active','gw_moniepoint_api_key','gw_moniepoint_secret','gw_moniepoint_contract',
            'gw_moniepoint_fee_type', 'gw_moniepoint_fee_value',
            
            // PayVessel
            'gw_payvessel_active','gw_payvessel_secret','gw_payvessel_merchant', 'gw_payvessel_business_id',
            'gw_payvessel_fee_type', 'gw_payvessel_fee_value',
            
            // PalmPay Direct
            'palmpay_bearer','palmpay_secret','palmpay_merchant_id',
            'palmpay_sandbox','palmpay_enabled','palmpay_dynamic_enabled','palmpay_payout_enabled',
            'palmpay_fee_type', 'palmpay_fee_value',
            
            // PaymentPoint
            'paymentpoint_bearer','paymentpoint_api_key','paymentpoint_business_id','paymentpoint_webhook_secret',
            'paymentpoint_palmpay_enabled', 'paymentpoint_palmpay_fee_type', 'paymentpoint_palmpay_fee_value',
            'paymentpoint_opay_enabled', 'paymentpoint_opay_fee_type', 'paymentpoint_opay_fee_value'
        ];
        
        foreach($keys as $k){
            $v = isset($_POST[$k]) ? $_POST[$k] : '';
            if(str_contains($k, '_active') || str_contains($k, '_enabled') || str_contains($k, '_sandbox')){
                $v = empty($v) ? '0' : '1';
            }
            
            $chk = db()->query("SELECT id FROM site_settings WHERE setting_key='$k'")->fetchColumn();
            if($chk){
                db()->prepare("UPDATE site_settings SET setting_value=? WHERE setting_key=?")->execute([$v, $k]);
            } else {
                db()->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $v]);
            }
        }
        
        audit_log($_SESSION['admin_id'], 'UPDATE_GATEWAYS', 'Updated payment gateway configurations');
        json_response(['success'=>true,'message'=>'Payment gateways configuration saved successfully']);
    }
}
$csrf=admin_csrf();

function get_gw_set($key){
    return db()->query("SELECT setting_value FROM site_settings WHERE setting_key='$key'")->fetchColumn() ?: '';
}

// Variables for toggles
$moniepoint_active = get_gw_set('gw_moniepoint_active') == '1';
$payvessel_active = get_gw_set('gw_payvessel_active') == '1';
$palmpay_enabled = get_gw_set('palmpay_enabled') == '1';
$paymentpoint_palmpay_enabled = get_gw_set('paymentpoint_palmpay_enabled') == '1';
$paymentpoint_opay_enabled = get_gw_set('paymentpoint_opay_enabled') == '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Payment Gateways – MsjPay Admin</title>
    <link rel="icon" type="image/png" href="/msjpay/assets/images/msjpay-favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/msjpay/msj-admin/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .fee-group { background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 16px; margin-top: 16px; display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .fee-title { font-weight: 600; font-size: 13px; color: var(--navy-dark); grid-column: span 2; margin: 0; display:flex; align-items:center; gap:6px; }
    </style>
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="page-content">

<div class="page-head" style="margin-bottom:24px;">
    <div>
        <h2>Payment Gateways</h2>
        <p>Configure API credentials and set individual funding fees per gateway.</p>
    </div>
    <button class="btn-primary" id="saveBtnTop" onclick="saveGateways()" style="padding:10px 20px; font-size:14px; border-radius:8px;">
        <i class="fas fa-save"></i> Save All Changes
    </button>
</div>

<form id="gatewayForm" onsubmit="event.preventDefault(); saveGateways();" style="display:grid; grid-template-columns:1fr 1fr; gap:24px; align-items:start;">
    
    <!-- PALMPAY DIRECT -->
    <div class="card" style="padding:0; overflow:hidden;">
        <div class="card-header" style="border-bottom:1px solid #E2E8F0; padding:20px 24px; background:#fff; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0; font-size:16px; color:var(--navy-dark); display:flex; align-items:center; gap:8px;">
                <div style="width:28px;height:28px;border-radius:6px;background:#E0E7FF;color:#4F46E5;display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-wallet"></i>
                </div>
                PalmPay Direct
            </h3>
            <label class="toggle"><input type="checkbox" id="palmpay_enabled" <?= $palmpay_enabled ? 'checked' : '' ?>><span class="slider"></span></label>
        </div>
        <div style="padding:24px;">
            <div class="form-group" style="margin-bottom:16px;">
                <label style="font-weight:600; font-size:13px; color:var(--navy-dark); margin-bottom:6px; display:block;">Bearer Token</label>
                <input type="password" class="form-control" id="palmpay_bearer" value="<?=htmlspecialchars(get_gw_set('palmpay_bearer'))?>" placeholder="Enter Bearer Token">
            </div>
            <div class="form-group" style="margin-bottom:16px;">
                <label style="font-weight:600; font-size:13px; color:var(--navy-dark); margin-bottom:6px; display:block;">Secret Key (Webhook Signature)</label>
                <input type="password" class="form-control" id="palmpay_secret" value="<?=htmlspecialchars(get_gw_set('palmpay_secret'))?>" placeholder="Enter Secret Key">
            </div>
            <div class="form-group" style="margin-bottom:24px;">
                <label style="font-weight:600; font-size:13px; color:var(--navy-dark); margin-bottom:6px; display:block;">Merchant ID</label>
                <input type="text" class="form-control" id="palmpay_merchant_id" value="<?=htmlspecialchars(get_gw_set('palmpay_merchant_id'))?>" placeholder="Enter Merchant ID">
            </div>
            
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; padding-bottom:16px; border-bottom:1px solid #E2E8F0;">
                <div>
                    <h4 style="margin:0; font-size:14px; font-weight:600; color:var(--navy-dark);">Sandbox Mode</h4>
                    <p style="margin:4px 0 0; font-size:12px; color:#64748B;">Use test API endpoints</p>
                </div>
                <label class="toggle"><input type="checkbox" id="palmpay_sandbox" <?= get_gw_set('palmpay_sandbox')=='1' ? 'checked' : '' ?>><span class="slider"></span></label>
            </div>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; padding-bottom:16px; border-bottom:1px solid #E2E8F0;">
                <div>
                    <h4 style="margin:0; font-size:14px; font-weight:600; color:var(--navy-dark);">Enable Dynamic VA</h4>
                    <p style="margin:4px 0 0; font-size:12px; color:#64748B;">One-time checkout accounts</p>
                </div>
                <label class="toggle"><input type="checkbox" id="palmpay_dynamic_enabled" <?= get_gw_set('palmpay_dynamic_enabled')=='1' ? 'checked' : '' ?>><span class="slider"></span></label>
            </div>
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <h4 style="margin:0; font-size:14px; font-weight:600; color:var(--navy-dark);">Enable Payouts</h4>
                    <p style="margin:4px 0 0; font-size:12px; color:#64748B;">Bank transfers to any bank</p>
                </div>
                <label class="toggle"><input type="checkbox" id="palmpay_payout_enabled" <?= get_gw_set('palmpay_payout_enabled')=='1' ? 'checked' : '' ?>><span class="slider"></span></label>
            </div>

            <!-- FEE SETTINGS -->
            <div class="fee-group">
                <p class="fee-title"><i class="fas fa-percent" style="color:#64748B"></i> Funding Fee Setup</p>
                <div class="form-group">
                    <label style="font-size:12px; font-weight:600;">Fee Type</label>
                    <select class="form-control" id="palmpay_fee_type">
                        <option value="flat" <?=get_gw_set('palmpay_fee_type')=='flat'?'selected':''?>>Flat Rate (NGN)</option>
                        <option value="percent" <?=get_gw_set('palmpay_fee_type')=='percent'?'selected':''?>>Percentage (%)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label style="font-size:12px; font-weight:600;">Fee Value</label>
                    <input type="number" step="0.01" class="form-control" id="palmpay_fee_value" value="<?=htmlspecialchars(get_gw_set('palmpay_fee_value'))?>" placeholder="0.00">
                </div>
            </div>
        </div>
    </div>

    <!-- PAYMENTPOINT -->
    <div class="card" style="padding:0; overflow:hidden;">
        <div class="card-header" style="border-bottom:1px solid #E2E8F0; padding:20px 24px; background:#fff; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0; font-size:16px; color:var(--navy-dark); display:flex; align-items:center; gap:8px;">
                <div style="width:28px;height:28px;border-radius:6px;background:#FCE7F3;color:#DB2777;display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-coins"></i>
                </div>
                PaymentPoint Integration
            </h3>
        </div>
        <div style="padding:24px;">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px;">
                <div class="form-group">
                    <label style="font-weight:600; font-size:13px; color:var(--navy-dark); margin-bottom:6px; display:block;">Bearer Token</label>
                    <input type="password" class="form-control" id="paymentpoint_bearer" value="<?=htmlspecialchars(get_gw_set('paymentpoint_bearer'))?>">
                </div>
                <div class="form-group">
                    <label style="font-weight:600; font-size:13px; color:var(--navy-dark); margin-bottom:6px; display:block;">API Key</label>
                    <input type="password" class="form-control" id="paymentpoint_api_key" value="<?=htmlspecialchars(get_gw_set('paymentpoint_api_key'))?>">
                </div>
                <div class="form-group">
                    <label style="font-weight:600; font-size:13px; color:var(--navy-dark); margin-bottom:6px; display:block;">Business ID</label>
                    <input type="text" class="form-control" id="paymentpoint_business_id" value="<?=htmlspecialchars(get_gw_set('paymentpoint_business_id'))?>">
                </div>
                <div class="form-group">
                    <label style="font-weight:600; font-size:13px; color:var(--navy-dark); margin-bottom:6px; display:block;">Webhook Secret</label>
                    <input type="password" class="form-control" id="paymentpoint_webhook_secret" value="<?=htmlspecialchars(get_gw_set('paymentpoint_webhook_secret'))?>">
                </div>
            </div>

            <!-- PAYMENTPOINT PALMPAY -->
            <div style="border:1px solid #E2E8F0; border-radius:8px; padding:16px; margin-bottom:16px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <h4 style="margin:0; font-size:14px; font-weight:600; color:var(--navy-dark);">PalmPay (via PaymentPoint)</h4>
                    <label class="toggle"><input type="checkbox" id="paymentpoint_palmpay_enabled" <?= $paymentpoint_palmpay_enabled ? 'checked' : '' ?>><span class="slider"></span></label>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div class="form-group">
                        <label style="font-size:12px; font-weight:600;">Fee Type</label>
                        <select class="form-control" id="paymentpoint_palmpay_fee_type" style="padding:6px; font-size:13px;">
                            <option value="flat" <?=get_gw_set('paymentpoint_palmpay_fee_type')=='flat'?'selected':''?>>Flat</option>
                            <option value="percent" <?=get_gw_set('paymentpoint_palmpay_fee_type')=='percent'?'selected':''?>>Percent</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label style="font-size:12px; font-weight:600;">Fee Value</label>
                        <input type="number" step="0.01" class="form-control" id="paymentpoint_palmpay_fee_value" value="<?=htmlspecialchars(get_gw_set('paymentpoint_palmpay_fee_value'))?>" style="padding:6px; font-size:13px;">
                    </div>
                </div>
            </div>

            <!-- PAYMENTPOINT OPAY -->
            <div style="border:1px solid #E2E8F0; border-radius:8px; padding:16px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <h4 style="margin:0; font-size:14px; font-weight:600; color:var(--navy-dark);">OPay (via PaymentPoint)</h4>
                    <label class="toggle"><input type="checkbox" id="paymentpoint_opay_enabled" <?= $paymentpoint_opay_enabled ? 'checked' : '' ?>><span class="slider"></span></label>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div class="form-group">
                        <label style="font-size:12px; font-weight:600;">Fee Type</label>
                        <select class="form-control" id="paymentpoint_opay_fee_type" style="padding:6px; font-size:13px;">
                            <option value="flat" <?=get_gw_set('paymentpoint_opay_fee_type')=='flat'?'selected':''?>>Flat</option>
                            <option value="percent" <?=get_gw_set('paymentpoint_opay_fee_type')=='percent'?'selected':''?>>Percent</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label style="font-size:12px; font-weight:600;">Fee Value</label>
                        <input type="number" step="0.01" class="form-control" id="paymentpoint_opay_fee_value" value="<?=htmlspecialchars(get_gw_set('paymentpoint_opay_fee_value'))?>" style="padding:6px; font-size:13px;">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- PAYVESSEL -->
    <div class="card" style="padding:0; overflow:hidden;">
        <div class="card-header" style="border-bottom:1px solid #E2E8F0; padding:20px 24px; background:#fff; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0; font-size:16px; color:var(--navy-dark); display:flex; align-items:center; gap:8px;">
                <div style="width:28px;height:28px;border-radius:6px;background:#DCFCE7;color:#16A34A;display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-ship"></i>
                </div>
                PayVessel
            </h3>
            <label class="toggle"><input type="checkbox" id="gw_payvessel_active" <?= $payvessel_active ? 'checked' : '' ?>><span class="slider"></span></label>
        </div>
        <div style="padding:24px;">
            <div class="form-group" style="margin-bottom:16px;">
                <label style="font-weight:600; font-size:13px; color:var(--navy-dark); margin-bottom:6px; display:block;">API Key</label>
                <input type="password" class="form-control" id="gw_payvessel_merchant" value="<?=htmlspecialchars(get_gw_set('gw_payvessel_merchant'))?>">
            </div>
            <div class="form-group" style="margin-bottom:16px;">
                <label style="font-weight:600; font-size:13px; color:var(--navy-dark); margin-bottom:6px; display:block;">API Secret</label>
                <input type="password" class="form-control" id="gw_payvessel_secret" value="<?=htmlspecialchars(get_gw_set('gw_payvessel_secret'))?>">
            </div>
            <div class="form-group" style="margin-bottom:16px;">
                <label style="font-weight:600; font-size:13px; color:var(--navy-dark); margin-bottom:6px; display:block;">Business ID</label>
                <input type="text" class="form-control" id="gw_payvessel_business_id" value="<?=htmlspecialchars(get_gw_set('gw_payvessel_business_id'))?>">
            </div>
            
            <div class="fee-group">
                <p class="fee-title"><i class="fas fa-percent" style="color:#64748B"></i> Funding Fee Setup</p>
                <div class="form-group">
                    <label style="font-size:12px; font-weight:600;">Fee Type</label>
                    <select class="form-control" id="gw_payvessel_fee_type">
                        <option value="flat" <?=get_gw_set('gw_payvessel_fee_type')=='flat'?'selected':''?>>Flat Rate (NGN)</option>
                        <option value="percent" <?=get_gw_set('gw_payvessel_fee_type')=='percent'?'selected':''?>>Percentage (%)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label style="font-size:12px; font-weight:600;">Fee Value</label>
                    <input type="number" step="0.01" class="form-control" id="gw_payvessel_fee_value" value="<?=htmlspecialchars(get_gw_set('gw_payvessel_fee_value'))?>" placeholder="0.00">
                </div>
            </div>
        </div>
    </div>
    
    <!-- MONIEPOINT -->
    <div class="card" style="padding:0; overflow:hidden;">
        <div class="card-header" style="border-bottom:1px solid #E2E8F0; padding:20px 24px; background:#fff; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0; font-size:16px; color:var(--navy-dark); display:flex; align-items:center; gap:8px;">
                <div style="width:28px;height:28px;border-radius:6px;background:#DBEAFE;color:#2563EB;display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-building"></i>
                </div>
                Moniepoint Gateway
            </h3>
            <label class="toggle"><input type="checkbox" id="gw_moniepoint_active" <?= $moniepoint_active ? 'checked' : '' ?>><span class="slider"></span></label>
        </div>
        <div style="padding:24px;">
            <div class="form-group" style="margin-bottom:16px;">
                <label style="font-weight:600; font-size:13px; color:var(--navy-dark); margin-bottom:6px; display:block;">Contract / Account Code</label>
                <input type="text" class="form-control" id="gw_moniepoint_contract" value="<?=htmlspecialchars(get_gw_set('gw_moniepoint_contract'))?>">
            </div>
            <div class="form-group" style="margin-bottom:16px;">
                <label style="font-weight:600; font-size:13px; color:var(--navy-dark); margin-bottom:6px; display:block;">API Key</label>
                <input type="password" class="form-control" id="gw_moniepoint_api_key" value="<?=htmlspecialchars(get_gw_set('gw_moniepoint_api_key'))?>">
            </div>
            <div class="form-group" style="margin-bottom:16px;">
                <label style="font-weight:600; font-size:13px; color:var(--navy-dark); margin-bottom:6px; display:block;">API Secret</label>
                <input type="password" class="form-control" id="gw_moniepoint_secret" value="<?=htmlspecialchars(get_gw_set('gw_moniepoint_secret'))?>">
            </div>
            
            <div class="fee-group">
                <p class="fee-title"><i class="fas fa-percent" style="color:#64748B"></i> Funding Fee Setup</p>
                <div class="form-group">
                    <label style="font-size:12px; font-weight:600;">Fee Type</label>
                    <select class="form-control" id="gw_moniepoint_fee_type">
                        <option value="flat" <?=get_gw_set('gw_moniepoint_fee_type')=='flat'?'selected':''?>>Flat Rate (NGN)</option>
                        <option value="percent" <?=get_gw_set('gw_moniepoint_fee_type')=='percent'?'selected':''?>>Percentage (%)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label style="font-size:12px; font-weight:600;">Fee Value</label>
                    <input type="number" step="0.01" class="form-control" id="gw_moniepoint_fee_value" value="<?=htmlspecialchars(get_gw_set('gw_moniepoint_fee_value'))?>" placeholder="0.00">
                </div>
            </div>
        </div>
    </div>

</form>

</div></div>
<div class="save-toast" id="toast"></div>

<script>
const CSRF='<?=$csrf?>';
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}

async function saveGateways(){
    const btn = document.getElementById('saveBtnTop');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:8px;"></i> Saving...';
    
    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('action', 'save_gateways');
    
    const fields = [
        'gw_moniepoint_contract', 'gw_moniepoint_api_key', 'gw_moniepoint_secret', 'gw_moniepoint_fee_type', 'gw_moniepoint_fee_value',
        'gw_payvessel_merchant', 'gw_payvessel_secret', 'gw_payvessel_business_id', 'gw_payvessel_fee_type', 'gw_payvessel_fee_value',
        'palmpay_bearer', 'palmpay_secret', 'palmpay_merchant_id', 'palmpay_fee_type', 'palmpay_fee_value',
        'paymentpoint_bearer', 'paymentpoint_api_key', 'paymentpoint_business_id', 'paymentpoint_webhook_secret',
        'paymentpoint_palmpay_fee_type', 'paymentpoint_palmpay_fee_value',
        'paymentpoint_opay_fee_type', 'paymentpoint_opay_fee_value'
    ];
    fields.forEach(f => fd.append(f, document.getElementById(f).value));
    
    // Checkboxes
    if(document.getElementById('gw_moniepoint_active').checked) fd.append('gw_moniepoint_active', '1');
    if(document.getElementById('gw_payvessel_active').checked) fd.append('gw_payvessel_active', '1');
    if(document.getElementById('palmpay_sandbox').checked) fd.append('palmpay_sandbox', '1');
    if(document.getElementById('palmpay_enabled').checked) fd.append('palmpay_enabled', '1');
    if(document.getElementById('palmpay_dynamic_enabled').checked) fd.append('palmpay_dynamic_enabled', '1');
    if(document.getElementById('palmpay_payout_enabled').checked) fd.append('palmpay_payout_enabled', '1');
    if(document.getElementById('paymentpoint_palmpay_enabled').checked) fd.append('paymentpoint_palmpay_enabled', '1');
    if(document.getElementById('paymentpoint_opay_enabled').checked) fd.append('paymentpoint_opay_enabled', '1');
    
    try {
        const r = await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
        showToast(r.message, r.success);
    } catch(e) {
        showToast('Network error occurred', false);
    }
    
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-save" style="margin-right:8px;"></i> Save All Changes';
}

function showToast(msg,ok){
    const t=document.getElementById('toast');
    t.textContent=msg;
    t.className='save-toast show '+(ok?'ok':'err');
    setTimeout(()=>t.classList.remove('show'),2500);
}
</script>
</body>
</html>
