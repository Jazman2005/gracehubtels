<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
require_once __DIR__.'/../user/core/plan_fetcher.php';
auth_admin();
require_permission('manage_api');

// Auto-migrate: create api_registry table
try {
  db()->exec("CREATE TABLE IF NOT EXISTS api_registry (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    base_url VARCHAR(300) NOT NULL,
    api_key VARCHAR(255) DEFAULT NULL,
    api_pin VARCHAR(20) DEFAULT NULL,
    network_id_map TEXT DEFAULT NULL,
    value_map TEXT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
  )");
  try { db()->exec("ALTER TABLE api_registry ADD COLUMN plan_fetch_url VARCHAR(500) DEFAULT NULL AFTER notes"); } catch (\Throwable $e) {}
  try { db()->exec("ALTER TABLE api_registry ADD COLUMN plan_fetch_method VARCHAR(10) DEFAULT 'GET' AFTER plan_fetch_url"); } catch (\Throwable $e) {}
  // Also add api_registry_id to api_providers if not exists
  try { db()->exec("ALTER TABLE api_providers ADD COLUMN api_registry_id INT DEFAULT NULL AFTER data_type"); } catch (\Throwable $e) {}
  // Create plan_api_bindings table for per-plan API binding
  db()->exec("CREATE TABLE IF NOT EXISTS plan_api_bindings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service VARCHAR(50) NOT NULL,
    plan_id INT NOT NULL,
    api_registry_id INT NOT NULL,
    priority TINYINT(1) NOT NULL DEFAULT 1,
    product_code VARCHAR(100) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY service_plan_api (service, plan_id, api_registry_id),
    KEY service_plan (service, plan_id),
    KEY api_registry_id (api_registry_id)
  )");
} catch (\Throwable $e) {}

// Auto-seed: populate api_registry from existing api_providers if empty
try {
  $count = (int)db()->query("SELECT COUNT(*) FROM api_registry")->fetchColumn();
  if ($count === 0) {
    $existing = db()->query("SELECT DISTINCT provider_name, api_base_url, api_key, api_token, api_pin FROM api_providers WHERE provider_name IS NOT NULL AND provider_name != '' AND api_base_url IS NOT NULL AND api_base_url != ''")->fetchAll();
    foreach ($existing as $e) {
      $chk = db()->prepare("SELECT COUNT(*) FROM api_registry WHERE name=? AND base_url=?");
      $chk->execute([$e['provider_name'], rtrim($e['api_base_url'], '/')]);
      if ((int)$chk->fetchColumn() === 0) {
        $ins = db()->prepare("INSERT IGNORE INTO api_registry (name, base_url, api_key, api_token, api_pin) VALUES (?,?,?,?,?)");
        $ins->execute([$e['provider_name'], rtrim($e['api_base_url'], '/'), $e['api_key'], $e['api_token'], $e['api_pin']]);
      }
    }
    // Link existing api_providers to new registry entries
    db()->exec("UPDATE api_providers p JOIN api_registry r ON r.name = p.provider_name AND r.base_url = p.api_base_url SET p.api_registry_id = r.id WHERE p.api_registry_id IS NULL AND p.provider_name IS NOT NULL AND p.api_base_url IS NOT NULL");
  }
} catch (\Throwable $e) {}

$csrf = admin_csrf();
$db = db();

// Shows enough of a saved secret to visually confirm which key is on file
// (e.g. "uhuiw****************89jl") without exposing the whole value to the
// browser. Short values (<=9 chars) are fully masked — not enough length to
// safely reveal both ends without leaking most of the secret.
function mask_secret(string $s): string {
    $len = strlen($s);
    if ($len <= 9) return str_repeat('*', $len);
    $head = substr($s, 0, 5);
    $tail = substr($s, -4);
    return $head . str_repeat('*', max(6, $len - 9)) . $tail;
}

// Handle AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
  if (!verify_admin_csrf($_POST['csrf'] ?? '')) json_response(['success' => false, 'message' => 'Invalid token']);
  $action = $_POST['action'] ?? '';

  if ($action === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $data = [
      'name' => sanitize($_POST['name'] ?? '', 100),
      'base_url' => rtrim(sanitize($_POST['base_url'] ?? '', 300), '/'),
      'network_id_map' => $_POST['network_id_map'] ?? null,
      'value_map' => $_POST['value_map'] ?? null,
      'plan_fetch_url' => sanitize($_POST['plan_fetch_url'] ?? '', 500) ?: null,
      'plan_fetch_method' => in_array($_POST['plan_fetch_method'] ?? '', ['GET', 'POST']) ? $_POST['plan_fetch_method'] : 'GET',
      'notes' => sanitize($_POST['notes'] ?? '', 500) ?: null,
      'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];
    // Sensitive fields — only update if non-empty (keeps existing on edit).
    // The edit form pre-fills these with a masked preview (see mask_secret())
    // rather than leaving them blank, so an admin who opens Edit and saves
    // without touching a field would otherwise resubmit the mask string
    // itself as if it were the real secret — clobbering the actual saved
    // value with literal asterisks. Load the current row first and treat a
    // resubmitted mask exactly like blank (keep existing).
    $current = [];
    if ($id) {
      $cur_stmt = $db->prepare("SELECT api_key, api_token, api_pin, public_key, secret_key FROM api_registry WHERE id=?");
      $cur_stmt->execute([$id]);
      $current = $cur_stmt->fetch() ?: [];
    }
    $sensitive = ['api_key','api_token','api_pin','public_key','secret_key'];
    foreach ($sensitive as $f) {
      $v = sanitize($_POST[$f] ?? '', 255);
      if ($v === '') { $data[$f] = '__SKIP__'; continue; }
      if ($id && !empty($current[$f]) && $v === mask_secret($current[$f])) { $data[$f] = '__SKIP__'; continue; }
      $data[$f] = $v;
    }
    if ($id) {
      $sets = []; $params = [];
      foreach ($data as $f => $v) {
        if ($v === '__SKIP__') continue;
        $sets[] = "$f=?";
        $params[] = $v;
      }
      $params[] = $id;
      $s = $db->prepare("UPDATE api_registry SET " . implode(',', $sets) . " WHERE id=?");
      $s->execute($params);
      
      // Sync down to api_providers to ensure services get the new token and pin
      $sync = $db->prepare("UPDATE api_providers p JOIN api_registry r ON p.api_registry_id = r.id SET p.api_base_url = r.base_url, p.api_token = r.api_token, p.api_pin = r.api_pin, p.api_key = r.api_key, p.public_key = r.public_key, p.secret_key = r.secret_key, p.provider_name = r.name WHERE r.id = ?");
      $sync->execute([$id]);
    } else {
      // For insert, skip fields that are empty
      $sets = []; $params = [];
      foreach ($data as $f => $v) {
        if ($v === '__SKIP__' || $v === null) continue;
        $sets[] = $f;
        $params[] = $v;
      }
      $s = $db->prepare("INSERT INTO api_registry (" . implode(',', $sets) . ") VALUES (" . implode(',', array_fill(0, count($sets), '?')) . ")");
      $s->execute($params);
    }
    json_response(['success' => true, 'message' => $id ? 'Updated' : 'Created']);
  }

  if ($action === 'delete') {
    $db->prepare("DELETE FROM api_registry WHERE id=?")->execute([(int)$_POST['id']]);
    json_response(['success' => true]);
  }

  if ($action === 'toggle') {
    $db->prepare("UPDATE api_registry SET is_active=NOT is_active WHERE id=?")->execute([(int)$_POST['id']]);
    json_response(['success' => true]);
  }

  if ($action === 'get') {
    $s = $db->prepare("SELECT * FROM api_registry WHERE id=?");
    $s->execute([(int)$_POST['id']]);
    $row = $s->fetch() ?: [];
    // Never expose the real secret to the browser — show a masked preview
    // (e.g. "uhuiw****************89jl") instead, so the admin can visually
    // confirm which key is on file without it being fully blank. The save
    // handler above recognizes a resubmitted mask as "unchanged" and won't
    // overwrite the real value with it.
    foreach (['api_key','api_token','api_pin','public_key','secret_key'] as $f) {
      if (!empty($row[$f])) {
        $row[$f] = mask_secret($row[$f]);
      }
    }
    json_response($row);
  }

  if ($action === 'fetch_plans') {
    @set_time_limit(120);
    try {
      $id = (int)($_POST['id'] ?? 0);
      $service = sanitize($_POST['service'] ?? '', 50);
      $fetch_url = $_POST['fetch_url'] ?? null;
      $dry_run = !empty($_POST['dry_run']);
      if (!$id || !$service) json_response(['success' => false, 'message' => 'Missing registry ID or service']);
      $result = fetch_plans_from_api($service, $id, $fetch_url ?: null, $dry_run);
      json_response($result);
    } catch (\Throwable $e) {
      json_response(['success' => false, 'errors' => ['Server error: ' . $e->getMessage()], 'diagnostic' => $e->getFile() . ':' . $e->getLine()]);
    }
  }

  json_response(['success' => false, 'message' => 'Unknown action']);
}

$registries = $db->query("SELECT * FROM api_registry ORDER BY name")->fetchAll();
// A registry entry (API key/base URL) does nothing on its own — a service
// only ever calls it if a matching row exists in API Route Manager
// (api_providers) or a plan-specific override in Plan ID Mapping
// (plan_api_bindings). It's easy to add a registry entry, test one plan via
// a one-off binding, and never add the general routes — leaving every other
// plan/network for that provider silently unrouted with no error anywhere.
// Surface that gap here instead of leaving it invisible.
$route_counts = $db->query("SELECT api_registry_id, COUNT(*) c FROM api_providers WHERE api_registry_id IS NOT NULL GROUP BY api_registry_id")->fetchAll(PDO::FETCH_KEY_PAIR);
$binding_counts = $db->query("SELECT api_registry_id, COUNT(*) c FROM plan_api_bindings GROUP BY api_registry_id")->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>API Registry — <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title>
<link rel="icon" type="image/png" href="/assets/images/favicon.png">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css">
<style>
.reg-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:16px;margin-top:16px}
.reg-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow-sm);transition:all .2s}
.reg-card:hover{box-shadow:var(--shadow-md);border-color:var(--primary)}
.reg-card-head{display:flex;align-items:center;gap:10px;padding:14px 16px;background:var(--bg);border-bottom:1px solid var(--border)}
.reg-card-head .reg-name{font-size:15px;font-weight:700;color:var(--navy-dark);flex:1}
.reg-card-body{padding:14px 16px;display:flex;flex-direction:column;gap:8px;font-size:12px}
.reg-card-body .field-row{display:flex;align-items:center;gap:8px}
.reg-card-body .field-row .label{color:var(--text-muted);min-width:80px;font-weight:600}
.reg-card-body .field-row .value{color:var(--navy-dark);font-family:monospace;font-size:11px;word-break:break-all;flex:1}
.reg-card-foot{display:flex;gap:6px;padding:10px 16px;border-top:1px solid var(--border);background:var(--bg)}
.form-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-grid-2 .full{grid-column:1/-1}
@media(max-width:640px){.reg-grid{grid-template-columns:1fr}.form-grid-2{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header">
  <div>
    <h2><i class="fas fa-database" style="color:var(--primary);margin-right:10px"></i> API Registry</h2>
    <p>Manage API companies/endpoints — then assign them to services via <a href="<?= ADMIN_URL ?>/api-providers" style="color:var(--primary)">API Routes</a></p>
  </div>
  <button class="btn-primary" onclick="openModal()"><i class="fas fa-plus"></i> Add API</button>
</div>

<?php if (empty($registries)): ?>
<div style="padding:60px;text-align:center;color:var(--text-muted)">
  <i class="fas fa-plug" style="font-size:48px;display:block;margin-bottom:12px;opacity:.3"></i>
  <p style="font-weight:600">No API companies registered yet</p>
  <button class="btn-primary" style="margin-top:12px" onclick="openModal()"><i class="fas fa-plus"></i> Add Your First API</button>
</div>
<?php else: ?>
<div class="reg-grid">
<?php foreach ($registries as $r): ?>
  <div class="reg-card" id="card-<?=$r['id']?>">
    <div class="reg-card-head">
      <span class="reg-name"><?=htmlspecialchars($r['name'])?></span>
      <label class="toggle"><input type="checkbox" <?=$r['is_active']?'checked':''?> onchange="toggleApi(<?=$r['id']?>)"><span class="slider"></span></label>
    </div>
    <div class="reg-card-body">
      <?php $routes=(int)($route_counts[$r['id']]??0); $bindings=(int)($binding_counts[$r['id']]??0); ?>
      <?php if ($routes===0 && $bindings===0):?>
      <div style="display:flex;align-items:center;gap:8px;padding:8px 10px;background:var(--danger-bg,#fef2f2);border:1px solid var(--danger,#dc2626);border-radius:6px;font-size:11px;font-weight:600;color:var(--danger,#dc2626)">
        <i class="fas fa-triangle-exclamation"></i> No API Route configured for any service — this API will never be called. Add one via <a href="<?= ADMIN_URL ?>/api-providers" style="color:inherit;text-decoration:underline">API Routes</a>.
      </div>
      <?php elseif ($routes===0):?>
      <div style="display:flex;align-items:center;gap:8px;padding:8px 10px;background:var(--warning-bg,#fffbeb);border:1px solid var(--warning,#d97706);border-radius:6px;font-size:11px;font-weight:600;color:var(--warning,#d97706)">
        <i class="fas fa-triangle-exclamation"></i> Only bound to <?=$bindings?> specific plan<?=$bindings>1?'s':''?> (Plan ID Mapping) — no general <a href="<?= ADMIN_URL ?>/api-providers" style="color:inherit;text-decoration:underline">API Route</a>, so every other plan/network skips this API entirely.
      </div>
      <?php endif;?>
      <?php if (provider_locked($r['name'])):?>
      <div style="display:flex;align-items:center;gap:8px;padding:8px 10px;background:#F3F4F6;border:1px solid #9CA3AF;border-radius:6px;font-size:11px;font-weight:600;color:#374151">
        <i class="fas fa-lock"></i> This provider is locked for this installation and will never be selected for a purchase, regardless of routes/credentials configured here.
      </div>
      <?php endif;?>
      <div class="field-row"><span class="label">URL</span><span class="value"><?=htmlspecialchars($r['base_url']?:'-')?></span></div>
      <?php if ($r['api_key']):?><div class="field-row"><span class="label">Key</span><span class="value">••••<?=substr($r['api_key'],-3)?></span></div><?php endif;?>
      <?php if ($r['api_pin']):?><div class="field-row"><span class="label">PIN</span><span class="value">••••<?=substr($r['api_pin'],-3)?></span></div><?php endif;?>
      <?php if (!empty($r['public_key'])):?><div class="field-row"><span class="label">Public Key</span><span class="value">••••<?=substr($r['public_key'],-3)?></span></div><?php endif;?>
      <?php if (!empty($r['secret_key'])):?><div class="field-row"><span class="label">Secret Key</span><span class="value">••••<?=substr($r['secret_key'],-3)?></span></div><?php endif;?>
      <?php if ($r['notes']):?><div class="field-row"><span class="label">Notes</span><span class="value"><?=htmlspecialchars($r['notes'])?></span></div><?php endif;?>
      <div class="field-row"><span class="label">Routes</span><span class="value"><?=$routes?> service route<?=$routes!==1?'s':''?><?=$bindings?', '.$bindings.' plan binding'.($bindings>1?'s':''):''?></span></div>
    </div>
    <div class="reg-card-foot">
      <button class="btn-ghost" style="padding:5px 12px;font-size:11px" onclick='editApi(<?=$r['id']?>)'><i class="fas fa-pen"></i> Edit</button>
      <button class="btn-ghost" style="padding:5px 12px;font-size:11px;color:var(--success)" onclick='openFetchModal(<?=$r['id']?>,"<?=htmlspecialchars($r['name'],ENT_QUOTES)?>")'><i class="fas fa-cloud-download-alt"></i> Fetch Plans</button>
      <button class="btn-ghost" style="padding:5px 12px;font-size:11px;color:var(--danger)" onclick="deleteApi(<?=$r['id']?>)"><i class="fas fa-trash"></i> Delete</button>
    </div>
  </div>
<?php endforeach;?>
</div>
<?php endif;?>

</div></div>

<!-- Modal -->
<div class="modal-overlay" id="apiModal" style="display:none" onclick="if(event.target===this)closeModal()">
  <div class="modal" style="max-width:680px">
    <div class="modal-header">
      <h3 id="modalTitle"><i class="fas fa-plug"></i> Add API Company</h3>
      <button class="modal-close" onclick="closeModal()">&times;</button>
    </div>
    <div class="modal-body" style="max-height:70vh;overflow-y:auto;padding:20px 24px;">
      <form id="apiForm" onsubmit="saveApi(event)">
      <input type="hidden" id="r_id" name="id">
        <div class="form-grid-2">
          <div class="form-group full">
            <label>API Name <span style="color:var(--text-muted)">(e.g. GladTidings, AutoSyncNG)</span></label>
            <input type="text" class="form-control" id="r_name" name="name" required placeholder="e.g. GladTidings Primary">
          </div>
          <div class="form-group full">
            <label>Base URL</label>
            <input type="text" class="form-control" id="r_base_url" name="base_url" required placeholder="https://api.example.com/v1">
          </div>
              <div class="form-group">
                <label>API Key / Public Key (Auth Header)</label>
                <input type="text" class="form-control" id="r_api_key" name="api_key" placeholder="Optional. e.g. Public Key">
              </div>
              <div class="form-group">
                <label>API Token / Secret Key (Bearer)</label>
                <input type="text" class="form-control" id="r_api_token" name="api_token" placeholder="Required for Bearer auth (e.g. SageCloud Secret Key)">
              </div>
          <div class="form-group">
            <label>Account PIN</label>
            <input type="text" class="form-control" id="r_api_pin" name="api_pin" placeholder="e.g. 1234">
          </div>
          <div class="form-group">
            <label>Public Key <span style="color:var(--text-muted)">(public_key:secret_key Bearer scheme)</span></label>
            <input type="text" class="form-control" id="r_public_key" name="public_key" placeholder="Optional. e.g. Reteller Public Key">
          </div>
          <div class="form-group">
            <label>Secret Key</label>
            <input type="text" class="form-control" id="r_secret_key" name="secret_key" placeholder="Optional. e.g. Reteller Secret Key">
          </div>
          <div class="form-group full">
            <label>Network ID Map (JSON) <span style="color:var(--text-muted)">(optional)</span></label>
            <input type="text" class="form-control" id="r_network_id_map" name="network_id_map" placeholder='{"MTN":1,"GLO":2,"AIRTEL":3,"9MOBILE":4}'>
          </div>
          <div class="form-group full">
            <label>Value Map (key=value per line) <span style="color:var(--text-muted)">(optional)</span></label>
            <textarea class="form-control" id="r_value_map" name="value_map" rows="3" placeholder="denom_100=1&#10;denom_200=2"></textarea>
          </div>
          <div class="form-group full">
            <label>Plan Fetch URL <span style="color:var(--text-muted)">(optional — for auto-fetching plans)</span></label>
            <input type="text" class="form-control" id="r_plan_fetch_url" name="plan_fetch_url" placeholder="e.g. https://api.example.com/api/v1/variations?service_id=2">
          </div>
          <div class="form-group">
            <label>Fetch Method</label>
            <select class="form-control" id="r_plan_fetch_method" name="plan_fetch_method">
              <option value="GET">GET</option>
              <option value="POST">POST</option>
            </select>
          </div>
          <div class="form-group full">
            <label>Notes <span style="color:var(--text-muted)">(internal)</span></label>
            <input type="text" class="form-control" id="r_notes" name="notes" placeholder="e.g. Contact: support@provider.com">
          </div>
          <div class="form-group" style="display:flex;align-items:center;gap:10px">
            <label class="toggle"><input type="checkbox" id="r_is_active" name="is_active" checked><span class="slider"></span></label>
            <span style="font-size:13px;font-weight:600">Active</span>
          </div>
        </div>
        <div style="display:flex;justify-content:space-between;margin-top:20px;padding-top:16px;border-top:1px solid var(--border)">
          <button type="submit" class="btn-primary" id="btnSave"><i class="fas fa-save"></i> Save</button>
          <button type="button" class="btn-ghost" onclick="closeModal()">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Fetch Plans Modal -->
<div class="modal-overlay" id="fetchModal" style="display:none" onclick="if(event.target===this)closeFetchModal()">
  <div class="modal" style="max-width:740px">
    <div class="modal-header">
      <h3><i class="fas fa-cloud-download-alt"></i> <span id="fetchModalTitle">Fetch Plans</span></h3>
      <button class="modal-close" onclick="closeFetchModal()">&times;</button>
    </div>
    <div class="modal-body" style="max-height:75vh;overflow-y:auto;padding:20px 24px;">
      <input type="hidden" id="fetch_registry_id">
      <div class="form-grid-2">
        <div class="form-group">
          <label>Service</label>
          <select class="form-control" id="fetch_service">
            <option value="data">Data Plans</option>
            <option value="cabletv">Cable TV Plans</option>
            <option value="recharge_card">Recharge Cards</option>
            <option value="exam_pin">Exam PINs</option>
            <option value="electricity">Electricity</option>
            <option value="airtime">Airtime</option>
            <option value="data_card">Data Card</option>
          </select>
        </div>
        <div class="form-group" style="display:flex;align-items:flex-end;gap:12px">
          <label class="toggle" style="margin-bottom:4px"><input type="checkbox" id="fetch_dry_run" checked><span class="slider"></span></label>
          <span style="font-size:13px;font-weight:600">Dry run (preview only)</span>
        </div>
        <div class="form-group full">
          <label>Fetch URL <span style="color:var(--text-muted)">(leave blank to auto-discover)</span></label>
          <input type="text" class="form-control" id="fetch_url" placeholder="https://api.example.com/api/v1/variations?service_id=...">
        </div>
      </div>
      <div style="display:flex;gap:10px;margin:16px 0">
        <button class="btn-primary" id="btnFetch" onclick="runFetch()"><i class="fas fa-cloud-download-alt"></i> Fetch Plans</button>
        <button class="btn-ghost" onclick="closeFetchModal()">Cancel</button>
      </div>
      <div id="fetchResults" style="display:none;margin-top:12px;padding:14px;border-radius:8px;font-size:13px;line-height:1.6"></div>
      <div id="fetchLog" style="display:none;margin-top:8px;max-height:300px;overflow-y:auto;background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:10px;font-family:monospace;font-size:11px;white-space:pre-wrap"></div>
    </div>
  </div>
</div>
<script>
const CSRF='<?=$csrf?>';
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');document.getElementById('sidebarOverlay').classList.toggle('show');}
const SENSITIVE_FIELDS=['api_key','api_token','api_pin','public_key','secret_key'];
// Clicking into a field that's showing a masked preview clears it, so typing
// always starts fresh rather than editing into the middle of the mask
// string. Attached once at load — reads dataset.masked fresh each time, so
// it works correctly across repeated Add/Edit opens of the same modal.
SENSITIVE_FIELDS.forEach(k=>{
  const el=document.getElementById('r_'+k);
  if(el) el.addEventListener('focus',function(){
    if(this.dataset.masked && this.value===this.dataset.masked) this.value='';
  });
});
function openModal(){
  document.getElementById('modalTitle').innerHTML='<i class="fas fa-plug"></i> Add API Company';
  document.getElementById('r_id').value='';
  document.getElementById('apiForm').reset();
  SENSITIVE_FIELDS.forEach(k=>{
    const el=document.getElementById('r_'+k);
    if(el){ el.placeholder='Enter value'; el.dataset.masked=''; }
  });
  document.getElementById('r_is_active').checked=true;
  document.getElementById('apiModal').style.display='flex';
}
function closeModal(){document.getElementById('apiModal').style.display='none';}
async function editApi(id){
  document.getElementById('modalTitle').innerHTML='<i class="fas fa-pen"></i> Edit API Company';
  const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','get');fd.append('id',id);
  try {
    const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    if(!r.id) return alert('Not found');
    // Non-sensitive fields
    ['id','name','base_url','network_id_map','value_map','plan_fetch_url','plan_fetch_method','notes'].forEach(k=>{
      const el=document.getElementById('r_'+k);
      if(el) el.value=r[k]||'';
    });
    // Sensitive fields — pre-filled with a masked preview of the saved value
    // (e.g. "uhuiw****************89jl") so the admin can see a key is on
    // file without it being blank. Click into the field to clear it and type
    // a new value; leave it untouched to keep the current one.
    SENSITIVE_FIELDS.forEach(k=>{
      const el=document.getElementById('r_'+k);
      if(el){ el.value=r[k]||''; el.dataset.masked=r[k]||''; el.placeholder=r[k]?'':'Enter value'; }
    });
    document.getElementById('r_is_active').checked=r.is_active==1;
    document.getElementById('apiModal').style.display='flex';
  } catch(e){alert('Failed to load');}
}
async function saveApi(e){
  e.preventDefault();
  const fd=new FormData(document.getElementById('apiForm'));
  fd.append('csrf',CSRF);fd.append('action','save');
  if(!document.getElementById('r_name').value||!document.getElementById('r_base_url').value) return alert('Name and Base URL required');
  const btn=document.getElementById('btnSave');btn.innerHTML='Saving...';btn.disabled=true;
  try {
    const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    if(r.success) location.reload(); else alert(r.message);
  } catch(e){alert('Network error');}
  finally{btn.innerHTML='<i class="fas fa-save"></i> Save';btn.disabled=false;}
}
function deleteApi(id){
  if(!confirm('Delete this API registry entry?')) return;
  const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','delete');fd.append('id',id);
  fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json()).then(r=>{if(r.success)location.reload();else alert(r.message)});
}
async function toggleApi(id){
  const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','toggle');fd.append('id',id);
  await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});
}
function openFetchModal(id,name){
  document.getElementById('fetchModalTitle').textContent='Fetch Plans — '+name;
  document.getElementById('fetch_registry_id').value=id;
  document.getElementById('fetch_service').value='data';
  document.getElementById('fetch_url').value='';
  document.getElementById('fetch_dry_run').checked=true;
  document.getElementById('fetchResults').style.display='none';
  document.getElementById('fetchResults').innerHTML='';
  document.getElementById('fetchLog').style.display='none';
  document.getElementById('fetchLog').innerHTML='';
  document.getElementById('btnFetch').disabled=false;
  document.getElementById('btnFetch').innerHTML='<i class="fas fa-cloud-download-alt"></i> Fetch Plans';
  document.getElementById('fetchModal').style.display='flex';
}
function closeFetchModal(){document.getElementById('fetchModal').style.display='none';}
async function runFetch(){
  const id=document.getElementById('fetch_registry_id').value;
  const svc=document.getElementById('fetch_service').value;
  const url=document.getElementById('fetch_url').value;
  const dry=document.getElementById('fetch_dry_run').checked?1:0;
  if(!id||!svc) return alert('Missing parameters');
  const btn=document.getElementById('btnFetch');
  btn.innerHTML='Fetching...';btn.disabled=true;
  const resEl=document.getElementById('fetchResults');
  const logEl=document.getElementById('fetchLog');
  resEl.style.display='none';logEl.style.display='none';
  const svcLabels={'data':'Data Plans','cabletv':'Cable TV','recharge_card':'Recharge Cards','exam_pin':'Exam PINs','electricity':'Electricity','airtime':'Airtime','data_card':'Data Cards'};
  try{
    const fd=new FormData();
    fd.append('csrf',CSRF);fd.append('action','fetch_plans');fd.append('id',id);
    fd.append('service',svc);fd.append('fetch_url',url);fd.append('dry_run',dry);
    const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    if(r.errors&&r.errors.length){
      let html='<div style="color:var(--danger);font-weight:600">&#9888; Errors:</div><ul style="margin:6px 0 0 16px;color:var(--danger)">'+r.errors.map(e=>'<li>'+e+'</li>').join('')+'</ul>';
      if(r.diagnostic) html+='<div style="margin-top:8px;padding:8px;background:var(--bg);border-radius:6px;font-family:monospace;font-size:11px;word-break:break-all">Diagnostic: '+r.diagnostic+'</div>';
      resEl.innerHTML=html;
      resEl.style.display='block';
    }
    if(r.plans&&r.plans.length){
      let html='<div style="font-weight:600;margin-bottom:8px">'+r.plans.length+' plan'+(r.plans.length>1?'s':'')+' found for '+svcLabels[svc]+':</div>';
      html+='<table style="width:100%;border-collapse:collapse;font-size:12px"><thead><tr style="background:var(--bg)"><th style="padding:4px 8px;text-align:left;border-bottom:1px solid var(--border)">#</th><th style="padding:4px 8px;text-align:left;border-bottom:1px solid var(--border)">Action</th><th style="padding:4px 8px;text-align:left;border-bottom:1px solid var(--border)">Plan</th><th style="padding:4px 8px;text-align:left;border-bottom:1px solid var(--border)">Network</th><th style="padding:4px 8px;text-align:right;border-bottom:1px solid var(--border)">Cost</th></tr></thead><tbody>';
      r.plans.forEach((p,i)=>{
        const actClr=p.action==='created'?'var(--success)':'var(--warning)';
        html+='<tr><td style="padding:3px 8px;border-bottom:1px solid var(--border)">'+(i+1)+'</td><td style="padding:3px 8px;border-bottom:1px solid var(--border);color:'+actClr+';font-weight:600">'+p.action+'</td><td style="padding:3px 8px;border-bottom:1px solid var(--border)">'+p.plan+'</td><td style="padding:3px 8px;border-bottom:1px solid var(--border)">'+(p.net||'-')+'</td><td style="padding:3px 8px;border-bottom:1px solid var(--border);text-align:right">₦'+parseFloat(p.cost).toLocaleString()+'</td></tr>';
      });
      html+='</tbody></table>';
      html+='<div style="margin-top:8px;display:flex;gap:12px;font-size:12px">';
      html+='<span style="color:var(--success)">&#9679; Created: '+r.created+'</span>';
      html+='<span style="color:var(--warning)">&#9679; Updated: '+r.updated+'</span>';
      if(r.skipped) html+='<span style="color:var(--text-muted)">&#9679; Skipped: '+r.skipped+'</span>';
      html+='</div>';
      if(dry) html+='<div style="margin-top:8px;padding:8px;background:var(--warning-bg);border-radius:6px;font-size:12px;font-weight:600">&#128196; Dry run — no changes were saved. Uncheck "Dry run" and fetch again to apply.</div>';
      resEl.innerHTML=html;
      resEl.style.display='block';
    }
    if((!r.plans||!r.plans.length)&&(!r.errors||!r.errors.length)){
      let html='<div style="font-weight:600">No plan data returned from any endpoint.</div>';
      if(r.diagnostic) html+='<div style="margin-top:6px;padding:8px;background:var(--bg);border-radius:6px;font-family:monospace;font-size:11px;word-break:break-all">Last attempt: '+r.diagnostic+'</div>';
      html+='<div style="margin-top:8px;color:var(--text-muted);font-size:12px">Set a Plan Fetch URL in the registry if your provider has a plans/variations endpoint.</div>';
      resEl.innerHTML=html;
      resEl.style.display='block';
    }
  }catch(e){
    resEl.innerHTML='<div style="color:var(--danger)">Network error: '+e.message+'</div>';
    resEl.style.display='block';
  }finally{
    btn.innerHTML='<i class="fas fa-cloud-download-alt"></i> Fetch Plans';
    btn.disabled=false;
  }
}
</script>
</body>
</html>
