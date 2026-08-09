<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_admin();
require_permission('manage_api');
$csrf = admin_csrf();

const MAPPING_SERVICES = ['data', 'cabletv', 'electricity'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    if (!verify_admin_csrf($_POST['csrf'] ?? '')) json_response(['success' => false, 'message' => 'Invalid token'], 403);
    $action = $_POST['action'] ?? '';

    if ($action === 'load_provider_plans') {
        $registry_id = (int)($_POST['api_registry_id'] ?? 0);
        $service = in_array($_POST['service'] ?? '', MAPPING_SERVICES, true) ? $_POST['service'] : 'data';
        if ($registry_id < 1) json_response(['success' => false, 'message' => 'Select a provider'], 400);

        // Each service's own table is normalized to the same {id, group_label,
        // item_label, type_label, product_code} shape so the front-end table
        // rendering doesn't need to know which service it's looking at.
        // group_label always comes from the network/provider column the admin
        // actually chose when the plan was created — never guessed from the plan
        // name/type text. It's normalized with UPPER(TRIM()) purely so plans saved
        // with inconsistent casing (e.g. "Mtn" typed by hand vs "MTN" from a bulk
        // provider fetch) land in one clean section instead of splitting into two
        // near-duplicate, confusing groups.
        if ($service === 'cabletv') {
            $stmt = db()->prepare(
                "SELECT cp.id, UPPER(TRIM(cp.provider)) AS group_label,
                        COALESCE(NULLIF(cp.display_name,''), cp.plan_name) AS item_label,
                        NULL AS type_label, pb.product_code
                 FROM cable_plans cp
                 LEFT JOIN plan_api_bindings pb
                        ON pb.plan_id = cp.id AND pb.service = 'cabletv' AND pb.api_registry_id = ?
                 WHERE cp.is_active = 1
                 ORDER BY UPPER(TRIM(cp.provider)), cp.selling_price"
            );
            $stmt->execute([$registry_id]);
            json_response(['success' => true, 'plans' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }

        if ($service === 'electricity') {
            $stmt = db()->prepare(
                "SELECT ed.id, 'Electricity' AS group_label, ed.disco_name AS item_label,
                        NULL AS type_label, pb.product_code
                 FROM electricity_discos ed
                 LEFT JOIN plan_api_bindings pb
                        ON pb.plan_id = ed.id AND pb.service = 'electricity' AND pb.api_registry_id = ?
                 WHERE ed.is_active = 1
                 ORDER BY ed.disco_name"
            );
            $stmt->execute([$registry_id]);
            json_response(['success' => true, 'plans' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }

        // Data is filtered down to a single network at a time (chosen via the
        // network tabs on the page) — same pattern as the Add Data Plan page's
        // network filter, and avoids ever having to guess/merge network values.
        $network = strtoupper(trim($_POST['network'] ?? ''));
        if (!in_array($network, ['MTN', 'GLO', 'AIRTEL', '9MOBILE'], true)) {
            json_response(['success' => false, 'message' => 'Select a network'], 400);
        }
        $stmt = db()->prepare(
            "SELECT dp.id, UPPER(TRIM(dp.network)) AS group_label,
                    COALESCE(NULLIF(dp.plan_name,''), CONCAT(dp.data_size,' (',dp.validity,')')) AS item_label,
                    COALESCE(dp.display_type_name, dp.data_type) AS type_label, pb.product_code
             FROM data_plans dp
             LEFT JOIN plan_api_bindings pb
                    ON pb.plan_id = dp.id AND pb.service = 'data' AND pb.api_registry_id = ?
             WHERE dp.is_active = 1 AND UPPER(TRIM(dp.network)) = ?
             ORDER BY dp.data_type, dp.selling_price"
        );
        $stmt->execute([$registry_id, $network]);
        json_response(['success' => true, 'plans' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'save_provider_plans') {
        $registry_id = (int)($_POST['api_registry_id'] ?? 0);
        $service = in_array($_POST['service'] ?? '', MAPPING_SERVICES, true) ? $_POST['service'] : '';
        $rows = json_decode($_POST['rows'] ?? '[]', true);
        if ($registry_id < 1 || $service === '' || !is_array($rows)) json_response(['success' => false, 'message' => 'Invalid request'], 400);

        // Upsert on the (service, plan_id, api_registry_id) unique key — an existing
        // mapping just gets its product_code refreshed, a new one is inserted with
        // priority 1 (this page doesn't expose failover ordering; ties between
        // multiple mapped providers for the same plan fall back to insertion order,
        // matching "first one you map is effectively tried first").
        $upsert = db()->prepare(
            "INSERT INTO plan_api_bindings (service, plan_id, api_registry_id, priority, product_code)
             VALUES (?, ?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE product_code = VALUES(product_code)"
        );
        // A blank box means "this provider doesn't handle this plan" — clear any
        // existing mapping rather than leaving a stale product_code behind.
        $remove = db()->prepare(
            "DELETE FROM plan_api_bindings WHERE service=? AND plan_id=? AND api_registry_id=?"
        );

        $saved = 0; $cleared = 0;
        foreach ($rows as $row) {
            $plan_id = (int)($row['plan_id'] ?? 0);
            $code = trim((string)($row['product_code'] ?? ''));
            if ($plan_id < 1) continue;
            if ($code !== '') {
                $upsert->execute([$service, $plan_id, $registry_id, $code]);
                $saved++;
            } else {
                $remove->execute([$service, $plan_id, $registry_id]);
                $cleared++;
            }
        }
        audit_log((int)$_SESSION['admin_id'], 'PLAN_ID_MAPPING', "Saved $saved / cleared $cleared $service plan ID mapping(s) for api_registry #$registry_id");
        json_response(['success' => true, 'message' => "Saved $saved plan ID(s)" . ($cleared ? ", cleared $cleared" : '')]);
    }

    json_response(['success' => false], 400);
}

$api_regs = db()->query("SELECT id, name FROM api_registry WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Plan ID Mapping – Gracehubtels Admin</title>
    <link rel="icon" type="image/png" href="/assets/images/gracehubtels-favicon.png">
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
            <h2>Plan ID Mapping</h2>
            <p>Map every Data, Cable TV, and Electricity item to its Plan ID for each API provider</p>
        </div>
    </div>

    <div class="data-panel">
      <div class="panel-title-bar">
          <h3><i class="fas fa-plug" style="color:var(--primary);margin-right:8px"></i> Select a Service and Provider</h3>
      </div>
      <div style="padding:12px 20px;font-size:13px;color:var(--text-muted);display:flex;align-items:flex-start;gap:12px;background:var(--bg);border-bottom:1px solid var(--border)">
        <i class="fas fa-triangle-exclamation" style="margin-top:2px;color:var(--warning)"></i>
        <div>
            Pick a service and a provider below to see every active plan/disco and fill in that provider's Plan ID for each one. Switching services or providers keeps everything you've already saved — nothing is lost when you come back to map a different one later.
        </div>
      </div>
      <div style="padding:16px 20px; display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap; border-bottom:1px solid var(--border);">
          <div class="form-group" style="min-width:200px; margin:0;">
              <label>Service</label>
              <select class="form-control" id="service_select" onchange="loadProviderPlans()">
                  <option value="data">Data</option>
                  <option value="cabletv">Cable TV</option>
                  <option value="electricity">Electricity</option>
              </select>
          </div>
          <div class="form-group" style="min-width:280px; margin:0;">
              <label>API Provider</label>
              <select class="form-control" id="provider_select" onchange="loadProviderPlans()">
                  <option value="">-- Select a provider --</option>
                  <?php foreach ($api_regs as $r): ?>
                  <option value="<?=$r['id']?>"><?=htmlspecialchars($r['name'])?></option>
                  <?php endforeach; ?>
              </select>
          </div>
          <button class="btn-primary" id="saveMappingBtn" onclick="saveProviderPlans()" style="display:none;"><i class="fas fa-save"></i> Save Plan IDs</button>
          <span id="mappingStatus" style="font-size:12px;color:var(--text-muted);"></span>
      </div>
      <div id="network_filter_wrap" class="filter-tabs" style="margin:14px 20px;border:1px solid var(--border);display:none;">
          <a href="javascript:void(0)" class="filter-tab active" data-network="MTN" onclick="selectNetwork('MTN',this)">MTN</a>
          <a href="javascript:void(0)" class="filter-tab" data-network="GLO" onclick="selectNetwork('GLO',this)">GLO</a>
          <a href="javascript:void(0)" class="filter-tab" data-network="AIRTEL" onclick="selectNetwork('AIRTEL',this)">AIRTEL</a>
          <a href="javascript:void(0)" class="filter-tab" data-network="9MOBILE" onclick="selectNetwork('9MOBILE',this)">9MOBILE</a>
      </div>
      <div id="mappingTableWrap"></div>
    </div>

</div>
</div>
<div class="save-toast" id="toast"></div>

<script>
const CSRF = <?=json_encode($csrf)?>;
const SERVICE_LABELS = { data: 'data plans', cabletv: 'cable plans', electricity: 'discos' };
let currentNetwork = 'MTN';
function showToast(msg, ok) { const t = document.getElementById('toast'); t.textContent = msg; t.className = 'save-toast show ' + (ok ? 'ok' : 'err'); setTimeout(() => t.classList.remove('show'), 2500); }
function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

function selectNetwork(net, tabEl) {
    currentNetwork = net;
    document.querySelectorAll('#network_filter_wrap .filter-tab').forEach(t => t.classList.remove('active'));
    tabEl.classList.add('active');
    loadProviderPlans();
}

async function loadProviderPlans() {
    const svc = document.getElementById('service_select').value;
    const sel = document.getElementById('provider_select');
    const wrap = document.getElementById('mappingTableWrap');
    const btn = document.getElementById('saveMappingBtn');
    const networkWrap = document.getElementById('network_filter_wrap');

    // Network tabs only make sense for Data — every other service is grouped
    // by its own column (provider for cable, or a single group for electricity).
    networkWrap.style.display = (svc === 'data') ? 'flex' : 'none';

    if (!sel.value) { wrap.innerHTML = ''; btn.style.display = 'none'; return; }

    wrap.innerHTML = '<div style="padding:20px;color:var(--text-muted);">Loading...</div>';
    btn.style.display = 'none';
    const fd = new FormData(); fd.append('csrf', CSRF); fd.append('action', 'load_provider_plans'); fd.append('api_registry_id', sel.value); fd.append('service', svc);
    if (svc === 'data') fd.append('network', currentNetwork);
    const r = await fetch(location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd }).then(r => r.json());
    if (!r.success) { wrap.innerHTML = '<div style="padding:20px;color:var(--danger);">' + escapeHtml(r.message || 'Failed to load') + '</div>'; return; }

    if (!r.plans.length) { wrap.innerHTML = '<div style="padding:20px;color:var(--text-muted);">No active ' + (SERVICE_LABELS[svc] || 'items') + ' found' + (svc === 'data' ? ' for ' + currentNetwork : '') + '.</div>'; return; }

    wrap.innerHTML = '<div style="padding:0 20px 20px;">' + renderGroupedTables(r.plans) + '</div>';
    btn.style.display = 'inline-flex';
}

// Groups plans the same way the Add Data Plan page does: one section per
// Network (or Provider, for cable), and within that one mini-table per
// Category/Type — falling back to a single ungrouped table per network when
// there's no type dimension at all (cable, electricity).
function renderGroupedTables(plans) {
    const hasTypes = plans.some(p => p.type_label);
    const groups = new Map();
    plans.forEach(p => {
        if (!groups.has(p.group_label)) groups.set(p.group_label, new Map());
        const typeKey = hasTypes ? (p.type_label || 'Other') : '__all__';
        const typeMap = groups.get(p.group_label);
        if (!typeMap.has(typeKey)) typeMap.set(typeKey, []);
        typeMap.get(typeKey).push(p);
    });

    // Only show the group (network/provider) heading when there's more than one
    // group to tell apart — e.g. Data is now filtered to one network per view via
    // the network tabs, so repeating that network name as a heading too would just
    // be redundant with the already-highlighted tab.
    const showGroupHeader = groups.size > 1;
    let html = '';
    groups.forEach((typeMap, groupLabel) => {
        if (showGroupHeader) {
            html += '<div style="margin:22px 0 10px;display:flex;align-items:center;gap:10px;">'
                + '<div style="width:10px;height:10px;border-radius:50%;background:var(--primary);"></div>'
                + '<h3 style="margin:0;font-size:16px;font-weight:800;color:#1E293B;letter-spacing:0.3px;">' + escapeHtml(groupLabel) + '</h3>'
                + '</div>';
        }
        typeMap.forEach((rows, typeKey) => {
            html += '<div class="type-section" style="margin-top:10px;border:1px solid #E2E8F0;border-radius:8px;overflow:hidden;">';
            if (hasTypes) {
                html += '<div style="background:#F1F5F9;padding:10px 16px;border-bottom:1px solid #E2E8F0;display:flex;align-items:center;gap:10px;">'
                    + '<h4 style="margin:0;font-weight:700;color:#334155;font-size:13px;letter-spacing:0.5px;">' + escapeHtml(typeKey).toUpperCase() + ' PLANS</h4>'
                    + '<span style="background:#E2E8F0;color:#475569;font-size:11px;font-weight:700;padding:2px 8px;border-radius:12px;">' + rows.length + ' Plans</span>'
                    + '</div>';
            }
            html += '<div class="responsive-grid-wrapper"><table class="data-table" style="margin-bottom:0;"><thead><tr>'
                + '<th style="width:52px;">#</th><th>Plan</th><th style="width:240px;">Plan ID</th>'
                + '</tr></thead><tbody>';
            rows.forEach((p, i) => {
                html += '<tr data-plan-id="' + p.id + '">'
                    + '<td>' + (i + 1) + '</td>'
                    + '<td>' + escapeHtml(p.item_label) + '</td>'
                    + '<td><input type="text" class="form-control mapping-input" value="' + escapeHtml(p.product_code || '') + '" placeholder="Plan ID"></td>'
                    + '</tr>';
            });
            html += '</tbody></table></div></div>';
        });
    });
    return html;
}

async function saveProviderPlans() {
    const svc = document.getElementById('service_select').value;
    const sel = document.getElementById('provider_select');
    if (!sel.value) return;
    const rows = [];
    document.querySelectorAll('#mappingTableWrap tbody tr').forEach(tr => {
        const input = tr.querySelector('.mapping-input');
        rows.push({ plan_id: tr.getAttribute('data-plan-id'), product_code: input.value.trim() });
    });
    const fd = new FormData(); fd.append('csrf', CSRF); fd.append('action', 'save_provider_plans');
    fd.append('api_registry_id', sel.value); fd.append('service', svc); fd.append('rows', JSON.stringify(rows));
    const r = await fetch(location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd }).then(r => r.json());
    showToast(r.message, r.success);
}
</script>
</body>
</html>
