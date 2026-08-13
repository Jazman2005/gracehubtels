<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
require_once __DIR__.'/../user/core/api_router.php';
auth_admin();
require_permission('manage_api');

// One-time production data fix: routes added through the "Add Route" admin
// form (before it was fixed to fetch defaults live from build_network_map())
// got the same generic network_id_map baked in
// ({"MTN":1,"GLO":2,"AIRTEL":3,"9MOBILE":4}) regardless of which provider
// was picked — wrong for any provider with its own real ID scheme (confirmed
// against each provider's own developer docs: AutosyncNG's Gifting/SME
// categories use different IDs entirely, and AutoPilot uses
// MTN=1/AIRTEL=2/GLO=3/9MOBILE=4 — note GLO and AIRTEL swapped vs the
// generic default). Since a route's own saved network_id_map always
// overrides the code's built-in per-provider default, this wrong value has
// been permanently silencing the correct default on every affected route.
// This page clears that bad override so the code's own (now-corrected)
// default takes over. Safe to run more than once — a second run will find
// nothing left to fix. Runs across ALL providers, not just one, so any
// provider hit by this same stale-prefill bug gets caught.

$db = db();
$csrf = admin_csrf();
$generic_map = ['MTN'=>1,'GLO'=>2,'AIRTEL'=>3,'9MOBILE'=>4];

// Only flag a row as "wrong" if the generic map actually differs from what
// the code would apply for that specific provider+service+data_type — e.g.
// a provider whose real scheme IS the generic map is left alone.
function is_stale_generic_override(array $row, array $generic_map): bool {
    $map = $row['network_id_map'] ? json_decode($row['network_id_map'], true) : null;
    if ($map !== $generic_map) return false;
    $correct = build_network_map(['provider_name' => $row['provider_name'], 'data_type' => $row['data_type'], 'network_id_map' => ''], $row['service']);
    return $correct !== $generic_map;
}

$applied = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_admin_csrf($_POST['csrf'] ?? '')) { die('Invalid token'); }
    $rows = $db->query("SELECT id, service, network, data_type, provider_name, network_id_map FROM api_providers WHERE network_id_map IS NOT NULL AND network_id_map != ''")->fetchAll();
    $applied = [];
    foreach ($rows as $r) {
        if (is_stale_generic_override($r, $generic_map)) {
            $db->prepare("UPDATE api_providers SET network_id_map=NULL WHERE id=?")->execute([$r['id']]);
            $applied[] = $r;
        }
    }
    audit_log((int)($_SESSION['admin_id'] ?? 0), 'fix_network_ids', 'Cleared stale generic network_id_map overrides on ' . count($applied) . ' route(s)');
}

// Preview (also shown after applying, to confirm nothing's left)
$rows = $db->query("SELECT id, service, network, data_type, provider_name, network_id_map, is_active FROM api_providers ORDER BY provider_name, service, network")->fetchAll();
$to_fix = [];
$other = [];
foreach ($rows as $r) {
    if (is_stale_generic_override($r, $generic_map)) $to_fix[] = $r;
    elseif (!empty($r['network_id_map']) || stripos($r['provider_name'] ?? '', 'autosync') !== false || stripos($r['provider_name'] ?? '', 'pilot') !== false) $other[] = $r;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Fix Network ID Overrides — <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title>
<link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css">
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">
<div class="section-header"><h2>Fix Network ID Overrides</h2></div>

<?php if ($applied !== null): ?>
  <div style="padding:14px;background:#f0fdf4;border:1px solid #16a34a;border-radius:8px;margin-bottom:16px;color:#166534;font-weight:600">
    Cleared <?=count($applied)?> route(s). They will now use each provider's correct built-in defaults.
  </div>
<?php endif; ?>

<h3>Routes that need fixing (stale generic 1/2/3/4 map, wrong for this provider — will be cleared)</h3>
<?php if (empty($to_fix)): ?>
  <p style="color:#166534;font-weight:600">None found — nothing left to fix.</p>
<?php else: ?>
  <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px">
    <tr style="background:#f3f4f6"><th style="text-align:left;padding:6px">ID</th><th style="text-align:left;padding:6px">Provider</th><th style="text-align:left;padding:6px">Service</th><th style="text-align:left;padding:6px">Network</th><th style="text-align:left;padding:6px">Data Type</th><th style="text-align:left;padding:6px">Current Map</th></tr>
    <?php foreach ($to_fix as $r): ?>
    <tr style="border-bottom:1px solid #e5e7eb">
      <td style="padding:6px"><?=$r['id']?></td>
      <td style="padding:6px"><?=htmlspecialchars($r['provider_name'])?></td>
      <td style="padding:6px"><?=htmlspecialchars($r['service'])?></td>
      <td style="padding:6px"><?=htmlspecialchars($r['network'] ?: 'ALL')?></td>
      <td style="padding:6px"><?=htmlspecialchars($r['data_type'] ?: '-')?></td>
      <td style="padding:6px;font-family:monospace;font-size:11px"><?=htmlspecialchars($r['network_id_map'])?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <form method="POST" onsubmit="return confirm('Clear the network_id_map override on ' + <?=count($to_fix)?> + ' route(s)? This lets the code apply each provider\'s correct built-in defaults instead.');">
    <input type="hidden" name="csrf" value="<?=$csrf?>">
    <button type="submit" style="padding:10px 20px;background:#dc2626;color:#fff;border:none;border-radius:6px;font-weight:600;cursor:pointer">Apply Fix to <?=count($to_fix)?> Route(s)</button>
  </form>
<?php endif; ?>

<h3 style="margin-top:24px">Other AutosyncNG / AutoPilot routes with an override (left untouched — review manually)</h3>
<p style="color:#666;font-size:13px">These have a non-generic override already, which is assumed intentional. Routes with data_type AWOOF, AWOOF 2, BROADBAND, or NIGHT don't correspond to any category name in AutosyncNG's own API docs (Gifting/SME/Corporate/Transfer) — confirm with AutosyncNG which real category each one should use, and set/clear network_id_map accordingly via <a href="<?= ADMIN_URL ?>/api-providers">API Routes</a>.</p>
<table style="width:100%;border-collapse:collapse;font-size:13px">
  <tr style="background:#f3f4f6"><th style="text-align:left;padding:6px">ID</th><th style="text-align:left;padding:6px">Provider</th><th style="text-align:left;padding:6px">Service</th><th style="text-align:left;padding:6px">Network</th><th style="text-align:left;padding:6px">Data Type</th><th style="text-align:left;padding:6px">Current Map</th></tr>
  <?php foreach ($other as $r): ?>
  <tr style="border-bottom:1px solid #e5e7eb">
    <td style="padding:6px"><?=$r['id']?></td>
    <td style="padding:6px"><?=htmlspecialchars($r['provider_name'])?></td>
    <td style="padding:6px"><?=htmlspecialchars($r['service'])?></td>
    <td style="padding:6px"><?=htmlspecialchars($r['network'] ?: 'ALL')?></td>
    <td style="padding:6px"><?=htmlspecialchars($r['data_type'] ?: '-')?></td>
    <td style="padding:6px;font-family:monospace;font-size:11px"><?=htmlspecialchars($r['network_id_map'] ?: '(none)')?></td>
  </tr>
  <?php endforeach; ?>
</table>

</div></div>
</body>
</html>
