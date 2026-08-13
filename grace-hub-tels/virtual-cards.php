<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
// Need to include the API class from user side to execute commands
require_once __DIR__.'/../user/core/virtual_card.php';

auth_admin();
require_feature_admin('virtual_card');
$csrf = admin_csrf();
$msg = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        if (!verify_admin_csrf($_POST['csrf'] ?? '')) {
            $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
            if ($is_ajax) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Invalid CSRF token']); exit; }
            $msg = '<div class="save-toast err show">Invalid CSRF token</div>';
        } elseif (!has_permission('manage_cards')) {
            // Freezing/unfreezing/terminating a live card and rewriting the
            // Cashwyre API keys both need the "Virtual Cards" permission —
            // matches the checkbox Staff Management already offers for this.
            $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
            if ($is_ajax) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Missing permission: manage_cards']); exit; }
            $msg = '<div class="save-toast err show">Missing permission: manage_cards</div>';
        } else {
            $action = $_POST['action'];
            $card_id = trim($_POST['card_id'] ?? '');
            $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
            
            if ($action === 'save_api') {
                $allowed = ['cashwyre_api_key', 'cashwyre_secret_key', 'cashwyre_base_url', 'cashwyre_webhook_secret', 'virtual_card_exchange_rate', 'cashwyre_enabled', 'virtual_card_setup_fee_markup', 'virtual_card_funding_fee_type', 'virtual_card_funding_fee_value', 'virtual_card_rate_markup_type', 'virtual_card_rate_markup_value'];
                foreach ($_POST as $key => $val) {
                    if (in_array($key, $allowed)) {
                        setting_set($key, trim((string)$val));
                    }
                }
                if ($is_ajax) { echo json_encode(['success'=>true,'message'=>'Settings saved successfully']); exit; }
                $msg = '<div class="save-toast ok show">Settings saved successfully</div>';
            } elseif ($card_id) {
                if ($action === 'view_card') {
                    try {
                        $cardRow = db()->prepare("SELECT v.*, u.full_name, u.email FROM virtual_cards v JOIN users u ON v.user_id = u.id WHERE v.card_id = ? LIMIT 1");
                        $cardRow->execute([$card_id]);
                        $cardDetail = $cardRow->fetch(PDO::FETCH_ASSOC);
                        if (!$cardDetail) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Card not found']); exit; }
                        $uid = (int)$cardDetail['user_id'];
                        $txRows = db()->prepare("SELECT * FROM transactions WHERE user_id=? AND category='virtual_card' ORDER BY id DESC LIMIT 50");
                        $txRows->execute([$uid]);
                        $txns = $txRows->fetchAll(PDO::FETCH_ASSOC);
                        echo json_encode(['success'=>true,'card'=>$cardDetail,'transactions'=>$txns]);
                    } catch (\Throwable $e) {
                        error_log('view_card error: '.$e->getMessage());
                        http_response_code(500);
                        echo json_encode(['success'=>false,'message'=>'Server error: '.$e->getMessage()]);
                    }
                    exit;
                }
                $cw = new CashwyreAPI();
                try {
                    if ($action === 'freeze') {
                        $r = $cw->freeze_card($card_id);
                        if ($r['success']) {
                            db()->prepare("UPDATE virtual_cards SET status='frozen' WHERE card_id=?")->execute([$card_id]);
                            if ($is_ajax) { echo json_encode(['success'=>true,'message'=>'Card frozen']); exit; }
                            $msg = '<div class="save-toast ok show">Card successfully frozen</div>';
                            audit_log($_SESSION['admin_id'], 'VIRTUAL_CARD', "Froze card $card_id");
                        } else throw new Exception($r['message'] ?? 'Action failed');
                    } elseif ($action === 'unfreeze') {
                        $r = $cw->unfreeze_card($card_id);
                        if ($r['success']) {
                            db()->prepare("UPDATE virtual_cards SET status='active' WHERE card_id=?")->execute([$card_id]);
                            if ($is_ajax) { echo json_encode(['success'=>true,'message'=>'Card unfrozen']); exit; }
                            $msg = '<div class="save-toast ok show">Card successfully unfrozen</div>';
                            audit_log($_SESSION['admin_id'], 'VIRTUAL_CARD', "Unfroze card $card_id");
                        } else throw new Exception($r['message'] ?? 'Action failed');
                    } elseif ($action === 'terminate') {
                        $r = $cw->terminate_card($card_id);
                        if ($r['success']) {
                            db()->prepare("UPDATE virtual_cards SET status='terminated' WHERE card_id=?")->execute([$card_id]);
                            if ($is_ajax) { echo json_encode(['success'=>true,'message'=>'Card terminated']); exit; }
                            $msg = '<div class="save-toast ok show">Card successfully terminated</div>';
                            audit_log($_SESSION['admin_id'], 'VIRTUAL_CARD', "Terminated card $card_id");
                        } else throw new Exception($r['message'] ?? 'Action failed');
                    }
                } catch (Exception $e) {
                    error_log('Virtual card error: '.$e->getMessage());
                    if ($is_ajax) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'API Error: '.$e->getMessage()]); exit; }
                    $msg = '<div class="save-toast err show">API Error: Action could not be completed</div>';
                }
            }
        }
    }

function k(string $key): string { return htmlspecialchars(setting($key)); }
// Secret fields (API keys) must not render for viewers who can't act on them —
// auth_admin() alone (no role/permission) lets any admin, including 'support',
// load this page; only the manage_cards-gated POST below can change these.
function k_secret(string $key): string { return has_permission('manage_cards') ? htmlspecialchars(setting($key)) : ''; }

// Fetch Cards
$cards = db()->query("
    SELECT v.*, u.full_name, u.email 
    FROM virtual_cards v 
    JOIN users u ON v.user_id = u.id 
    ORDER BY v.created_at DESC
")->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Virtual Cards Management – <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title>
    <link rel="icon" type="image/png" href="/assets/images/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="page-content">

<div class="page-head">
    <div>
        <h2>Virtual Cards</h2>
        <p><?= count($cards) ?> total cards issued</p>
    </div>
</div>

<?=$msg?>

<div class="card" style="margin-bottom: 24px;">
    <div class="panel-title-bar" style="padding:16px;border-bottom:1px solid var(--border-light);display:flex;align-items:center;gap:8px;">
        <i class="fas fa-cog text-slate-500"></i>
        <h3 style="margin:0;font-size:16px;font-weight:600;color:var(--navy-dark)">Cashwyre API Configuration</h3>
    </div>
    <div style="padding: 24px; display:flex; flex-direction:column; gap:16px;">
        <div class="form-group">
            <label>API Key</label>
            <input type="password" class="form-control" id="cashwyre_api_key" value="<?=k_secret('cashwyre_api_key')?>">
        </div>
        <div class="form-group">
            <label>Secret Key</label>
            <input type="password" class="form-control" id="cashwyre_secret_key" value="<?=k_secret('cashwyre_secret_key')?>">
        </div>
        <div class="form-group">
            <label>Base URL (e.g. https://businessapi.cashwyre.com/api/v1.0)</label>
            <input type="text" class="form-control" id="cashwyre_base_url" value="<?=k('cashwyre_base_url')?>">
        </div>
        <div class="form-group">
            <label>Webhook Secret</label>
            <input type="password" class="form-control" id="cashwyre_webhook_secret" value="<?=k_secret('cashwyre_webhook_secret')?>">
            <small class="text-muted">From your Cashwyre dashboard — without this, card funding/freeze/unfreeze confirmations from Cashwyre are silently rejected (webhook/cashwyre/index.php fails closed with no secret).</small>
        </div>

        <hr style="margin: 8px 0; border: none; border-top: 1px solid var(--border-light);">
        <div style="font-weight:600; color:var(--navy-dark); font-size:14px;">Pricing from Cashwyre API</div>

        <div class="form-group">
            <label>Live Cashwyre Rate (1 USD to NGN)</label>
            <input type="text" class="form-control" id="virtual_card_exchange_rate" value="<?=number_format(get_live_ngn_rate(), 2)?>" readonly style="background:#f1f5f9;font-weight:700">
            <small class="text-muted">Fetched live from Cashwyre API. Not editable.</small>
        </div>
        <div class="form-group">
            <label>Cashwyre Card Setup Fee</label>
            <input type="text" class="form-control" value="$2.50" readonly style="background:#f1f5f9">
            <small class="text-muted">Base setup fee charged by Cashwyre per card.</small>
        </div>

        <div style="font-weight:600; color:var(--navy-dark); font-size:14px; margin-top:16px;">Your Markup</div>

        <div class="form-group">
            <label>Rate Markup Type</label>
            <select class="form-control" id="virtual_card_rate_markup_type" onchange="toggleVis()">
                <option value="none" <?=setting('virtual_card_rate_markup_type','none')==='none'?'selected':''?>>None</option>
                <option value="fixed" <?=setting('virtual_card_rate_markup_type','none')==='fixed'?'selected':''?>>Fixed (₦ per $1)</option>
                <option value="percentage" <?=setting('virtual_card_rate_markup_type','none')==='percentage'?'selected':''?>>Percentage (%)</option>
            </select>
            <small class="text-muted">Extra charge on the exchange rate (e.g. ₦100 per $1 adds ₦100 to every dollar).</small>
        </div>
        <div class="form-group" id="rate_markup_value_group" style="display:<?=in_array(setting('virtual_card_rate_markup_type','none'),['fixed','percentage'])?'block':'none'?>">
            <label>Rate Markup Value</label>
            <input type="number" step="0.01" class="form-control" id="virtual_card_rate_markup_value" value="<?=k('virtual_card_rate_markup_value')?>">
            <small class="text-muted">If Fixed: added in ₦ to the rate (e.g. 100 = ₦100 per $1). If Percentage: % added to the rate.</small>
        </div>

        <div class="form-group">
            <label>Card Setup Fee Markup ($)</label>
            <input type="number" step="0.01" class="form-control" id="virtual_card_setup_fee_markup" value="<?=k('virtual_card_setup_fee_markup')?>" placeholder="0.50">
            <small class="text-muted">Extra $ amount on top of Cashwyre's $2.50 setup fee (e.g. 0.50 = total $3.00). Charged to user in NGN at the rate above.</small>
        </div>

        <hr style="margin: 8px 0; border: none; border-top: 1px solid var(--border-light);">
        <div style="font-weight:600; color:var(--navy-dark); font-size:14px;">Funding Fee (on top-up)</div>

        <div class="form-group">
            <label>Funding Fee Type</label>
            <select class="form-control" id="virtual_card_funding_fee_type" onchange="toggleVis()">
                <option value="none" <?=setting('virtual_card_funding_fee_type','none')==='none'?'selected':''?>>None</option>
                <option value="flat" <?=setting('virtual_card_funding_fee_type','none')==='flat'?'selected':''?>>Flat (NGN)</option>
                <option value="percentage" <?=setting('virtual_card_funding_fee_type','none')==='percentage'?'selected':''?>>Percentage (%)</option>
            </select>
            <small class="text-muted">Additional fee charged when a user funds a virtual card.</small>
        </div>
        <div class="form-group" id="funding_fee_value_group" style="display:<?=in_array(setting('virtual_card_funding_fee_type','none'),['flat','percentage'])?'block':'none'?>">
            <label>Funding Fee Value</label>
            <input type="number" step="0.01" class="form-control" id="virtual_card_funding_fee_value" value="<?=k('virtual_card_funding_fee_value')?>">
            <small class="text-muted">If Flat: charged in NGN per funding. If Percentage: % of the USD funding amount.</small>
        </div>

        <div class="form-check" style="display:flex;align-items:center;gap:12px;padding:12px 0">
            <input type="checkbox" id="cashwyre_enabled" style="width:18px;height:18px;accent-color:#FF5A1F;cursor:pointer" <?=setting('cashwyre_enabled','0')==='1'?'checked':''?>>
            <label for="cashwyre_enabled" style="font-weight:600;font-size:14px;cursor:pointer">Enable Virtual Card Service</label>
        </div>

        <button class="btn-primary" onclick="saveCashwyre()" style="max-width:200px; margin-top:8px;">Save Configuration</button>
    </div>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Card Number</th>
                    <th>Brand</th>
                    <th>Expiry</th>
                    <th>Balance (USD)</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($cards as $c): 
                    $scls = ['active'=>'badge-success','frozen'=>'badge-warning','terminated'=>'badge-danger'][$c['status']] ?? 'badge-pending';
                ?>
                <tr>
                    <td>
                        <div style="font-weight:600;color:var(--navy-dark)"><?=htmlspecialchars($c['full_name'])?></div>
                        <div style="font-size:12px;color:var(--text-muted)"><?=htmlspecialchars($c['email'])?></div>
                    </td>
                    <td><code style="background:#f1f5f9;padding:4px 8px;border-radius:4px;color:#334155;font-weight:600;letter-spacing:1px"><?=htmlspecialchars($c['masked_number'])?></code></td>
                    <td><?=htmlspecialchars($c['brand'])?></td>
                    <td><?=htmlspecialchars($c['expiry'] ?: '--/--')?></td>
                    <td style="font-weight:700;color:var(--primary)">$<?=number_format((float)$c['balance'], 2)?></td>
                    <td><span class="badge <?=$scls?>"><?=ucfirst($c['status'])?></span></td>
                    <td style="font-size:12px;color:#6B7280"><?=date('d M, Y', strtotime($c['created_at']))?></td>
                    <td>
                        <button type="button" class="btn-primary" style="padding:4px 8px;font-size:12px;border-radius:6px;background:var(--primary);margin-right:4px;" onclick="viewCard('<?=htmlspecialchars($c['card_id'])?>')"><i class="fas fa-eye" style="margin-right:4px;"></i>View</button>
                        <?php if($c['status'] !== 'terminated'): ?>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Change status for this card?')">
                                <input type="hidden" name="csrf" value="<?=$csrf?>">
                                <input type="hidden" name="card_id" value="<?=htmlspecialchars($c['card_id'])?>">
                                <?php if($c['status'] === 'active'): ?>
                                    <input type="hidden" name="action" value="freeze">
                                    <button type="submit" class="btn-primary" style="padding:4px 8px;font-size:12px;border-radius:6px;background:var(--warning)">Freeze</button>
                                <?php else: ?>
                                    <input type="hidden" name="action" value="unfreeze">
                                    <button type="submit" class="btn-primary" style="padding:4px 8px;font-size:12px;border-radius:6px;background:var(--success)">Unfreeze</button>
                                <?php endif; ?>
                            </form>
                            
                            <form method="POST" style="display:inline" onsubmit="return confirm('WARNING: Terminating a card cannot be undone and remaining funds might be lost. Proceed?')">
                                <input type="hidden" name="csrf" value="<?=$csrf?>">
                                <input type="hidden" name="card_id" value="<?=htmlspecialchars($c['card_id'])?>">
                                <input type="hidden" name="action" value="terminate">
                                <button type="submit" class="btn-primary" style="padding:4px 8px;font-size:12px;border-radius:6px;background:var(--danger);margin-left:4px;">Terminate</button>
                            </form>
                        <?php else: ?>
                            <span style="font-size:12px;color:#9CA3AF">Closed</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; if(empty($cards)): ?>
                <tr><td colspan="8" class="empty-state">No virtual cards found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div></div>

<!-- Card Detail Modal -->
<div id="cardModalOvl" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;backdrop-filter:blur(4px);" onclick="if(event.target===this)closeCardModal()">
  <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:16px;width:90%;max-width:700px;max-height:85vh;overflow-y:auto;box-shadow:0 25px 60px rgba(0,0,0,0.3);">
    <div style="padding:20px 24px;border-bottom:1px solid #E5E7EB;display:flex;justify-content:space-between;align-items:center;">
      <h3 id="cardModalTitle" style="margin:0;font-size:18px;font-weight:700;color:#0F172A;">Card Details</h3>
      <button onclick="closeCardModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#6B7280;padding:4px 8px;border-radius:6px;" onmouseover="this.style.background='#F3F4F6'" onmouseout="this.style.background='none'">&times;</button>
    </div>
    <div id="cardModalBody" style="padding:24px;">
      <div style="text-align:center;padding:40px;color:#6B7280;"><i class="fas fa-spinner fa-spin" style="font-size:24px;"></i><p style="margin-top:8px;">Loading...</p></div>
    </div>
  </div>
</div>

<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
function esc(str){if(!str)return '';return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

function toggleVis() {
    var rf = document.getElementById('funding_fee_value_group');
    rf.style.display = document.getElementById('virtual_card_funding_fee_type').value === 'none' ? 'none' : 'block';
    var rr = document.getElementById('rate_markup_value_group');
    rr.style.display = document.getElementById('virtual_card_rate_markup_type').value === 'none' ? 'none' : 'block';
}

async function saveCashwyre() {
    const btn = event.target;
    const oldText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    btn.disabled = true;

    const fd = new FormData();
    fd.append('action', 'save_api');
    fd.append('csrf', '<?=$csrf?>');
    fd.append('cashwyre_api_key', document.getElementById('cashwyre_api_key').value);
    fd.append('cashwyre_secret_key', document.getElementById('cashwyre_secret_key').value);
    fd.append('cashwyre_base_url', document.getElementById('cashwyre_base_url').value);
    fd.append('cashwyre_webhook_secret', document.getElementById('cashwyre_webhook_secret').value);
    fd.append('virtual_card_setup_fee_markup', document.getElementById('virtual_card_setup_fee_markup').value);
    fd.append('virtual_card_exchange_rate', document.getElementById('virtual_card_exchange_rate').value);
    fd.append('virtual_card_rate_markup_type', document.getElementById('virtual_card_rate_markup_type').value);
    fd.append('virtual_card_rate_markup_value', document.getElementById('virtual_card_rate_markup_value').value);
    fd.append('virtual_card_funding_fee_type', document.getElementById('virtual_card_funding_fee_type').value);
    fd.append('virtual_card_funding_fee_value', document.getElementById('virtual_card_funding_fee_value').value);
    fd.append('cashwyre_enabled', document.getElementById('cashwyre_enabled').checked ? '1' : '0');

    try {
        const res = await fetch(location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        });
        const data = await res.json();
        alert(data.message);
        if(data.success) location.reload();
    } catch (e) {
        alert('Network error saving settings');
    } finally {
        btn.innerHTML = oldText;
        btn.disabled = false;
    }
}

function fmtMoney(v){return '&#8358;'+parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}
function fmtDate(d){if(!d)return '-';return new Date(d).toLocaleString('en-NG',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'})}

async function viewCard(cardId){
    document.getElementById('cardModalOvl').style.display='flex';
    document.getElementById('cardModalTitle').textContent='Card Details';
    document.getElementById('cardModalBody').innerHTML='<div style="text-align:center;padding:40px;color:#6B7280;"><i class="fas fa-spinner fa-spin" style="font-size:24px;"></i><p style="margin-top:8px;">Loading...</p></div>';
    try{
        const fd=new FormData();fd.append('action','view_card');fd.append('card_id',cardId);fd.append('csrf','<?=$csrf?>');
        const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
        if(!r.success){document.getElementById('cardModalBody').innerHTML='<p style="color:var(--danger);">'+(r.message||'Failed to load')+'</p>';return}
        const c=r.card;
        const txns=r.transactions||[];
        let h=`<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px;">
            <div style="background:#F8FAFC;border-radius:12px;padding:16px;">
                <div style="font-size:12px;color:#6B7280;font-weight:600;margin-bottom:4px;">Owner</div>
                <div style="font-weight:700;color:#0F172A;">${esc(c.full_name)}</div>
                <div style="font-size:13px;color:#6B7280;">${esc(c.email||'-')}</div>
            </div>
            <div style="background:#F8FAFC;border-radius:12px;padding:16px;">
                <div style="font-size:12px;color:#6B7280;font-weight:600;margin-bottom:4px;">Card</div>
                <div style="font-weight:700;color:#0F172A;font-family:monospace;letter-spacing:1px;">${esc(c.masked_number||'---')}</div>
                <div style="font-size:13px;color:#6B7280;">${esc(c.brand||'Visa')} &bull; Exp: ${esc(c.expiry||'--/--')}</div>
            </div>
            <div style="background:#F8FAFC;border-radius:12px;padding:16px;">
                <div style="font-size:12px;color:#6B7280;font-weight:600;margin-bottom:4px;">Balance</div>
                <div style="font-weight:700;color:#FF5A1F;font-size:20px;">$${parseFloat(c.balance||0).toFixed(2)}</div>
            </div>
            <div style="background:#F8FAFC;border-radius:12px;padding:16px;">
                <div style="font-size:12px;color:#6B7280;font-weight:600;margin-bottom:4px;">Status</div>
                <div><span class="badge ${c.status==='active'?'badge-success':c.status==='frozen'?'badge-warning':'badge-danger'}" style="font-size:12px;">${esc(c.status)}</span></div>
                <div style="font-size:12px;color:#6B7280;margin-top:4px;">Created: ${fmtDate(c.created_at)}</div>
            </div>
        </div>`;
        h+=`<h4 style="font-size:15px;font-weight:700;color:#0F172A;margin-bottom:12px;"><i class="fas fa-receipt" style="color:var(--primary);margin-right:6px;"></i>Virtual Card Transactions (${txns.length})</h4>`;
        if(txns.length){
            h+=`<div style="overflow-x:auto;"><table class="data-table" style="width:100%;"><thead><tr><th>Ref</th><th>Type</th><th>Amount</th><th>Cost Price</th><th>Profit</th><th>Status</th><th>Date</th></tr></thead><tbody>`;
            txns.forEach(t=>{
                const typeLabel=t.type==='credit'?'<span style="color:var(--success);font-weight:700;">Credit</span>':'<span style="color:var(--danger);font-weight:700;">Debit</span>';
                const profitVal=parseFloat(t.profit||0);
                const profitStr=profitVal>0?'<span style="color:var(--success);font-weight:700;">&#8358;'+profitVal.toLocaleString('en-US',{minimumFractionDigits:2})+'</span>':profitVal<0?'<span style="color:var(--danger);font-weight:700;">&#8358;'+profitVal.toLocaleString('en-US',{minimumFractionDigits:2})+'</span>':'<span style="color:#9CA3AF;">-</span>';
                const cpStr=t.cost_price?'&#8358;'+parseFloat(t.cost_price).toLocaleString('en-US',{minimumFractionDigits:2}):'<span style="color:#9CA3AF;">-</span>';
                h+=`<tr><td style="font-family:monospace;font-size:12px;">${esc(t.transaction_ref||'-')}</td><td>${typeLabel}</td><td style="font-weight:600;">${fmtMoney(t.amount)}</td><td>${cpStr}</td><td>${profitStr}</td><td><span class="badge ${t.status==='success'?'badge-success':t.status==='pending'?'badge-warning':'badge-danger'}" style="font-size:11px;">${esc(t.status)}</span></td><td style="font-size:12px;">${fmtDate(t.created_at)}</td></tr>`;
            });
            h+=`</tbody></table></div>`;
        } else {
            h+=`<div style="text-align:center;padding:30px;color:#6B7280;background:#F8FAFC;border-radius:12px;"><i class="fas fa-receipt" style="font-size:24px;margin-bottom:8px;display:block;"></i>No virtual card transactions found</div>`;
        }
        document.getElementById('cardModalBody').innerHTML=h;
    }catch(e){console.error(e);document.getElementById('cardModalBody').innerHTML='<p style="color:var(--danger);">Error: '+(e.message||'Network error')+'</p>';}
}

function closeCardModal(){document.getElementById('cardModalOvl').style.display='none';}
</script>
</body>
</html>
