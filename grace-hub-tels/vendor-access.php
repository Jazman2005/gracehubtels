<?php
// Hidden vendor-only control panel — enables/disables premium services,
// admin tooling, and specific upstream provider integrations for THIS
// install. Not linked from anywhere in the client's own admin nav, and
// deliberately does NOT touch admin_session()/auth_admin() — a client's own
// superadmin login must never grant access to this page. The only gate is
// VENDOR_ACCESS_KEY (defined per-install in user/core/db_config.php, never
// committed, never the same value across two clients). Wrong/missing key
// returns a plain 404, same as if the page didn't exist at all.
require_once dirname(__DIR__) . '/user/core/db.php';

$key = $_POST['key'] ?? $_GET['key'] ?? '';
if (!defined('VENDOR_ACCESS_KEY') || VENDOR_ACCESS_KEY === '' || !hash_equals(VENDOR_ACCESS_KEY, (string)$key)) {
    http_response_code(404);
    exit;
}

// Call once each for their self-healing side effect (CREATE TABLE IF NOT
// EXISTS) — on a brand-new install this page can be the very first thing
// that ever touches either table, before any client page has run
// feature_enabled()/provider_locked().
feature_enabled('__init__');
provider_locked('__init__');

$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_merge(FEATURE_REGISTRY, ADMIN_FEATURE_REGISTRY) as $fkey => $flabel) {
        $enabled = isset($_POST['feature'][$fkey]) ? 1 : 0;
        db()->prepare("INSERT INTO feature_flags (feature_key, is_enabled, label) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE is_enabled = ?, label = ?")
            ->execute([$fkey, $enabled, $flabel, $enabled, $flabel]);
    }
    foreach (array_keys(PROVIDER_REGISTRY) as $pkey) {
        // Checkbox semantics stay "checked = available" everywhere on this
        // page for consistency — internally that's is_locked = 0.
        $allowed = isset($_POST['provider'][$pkey]) ? 1 : 0;
        $locked = $allowed ? 0 : 1;
        db()->prepare("INSERT INTO provider_locks (provider_key, is_locked) VALUES (?, ?) ON DUPLICATE KEY UPDATE is_locked = ?")
            ->execute([$pkey, $locked, $locked]);
    }
    $saved = true;
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Vendor Access</title>
<style>
    body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:40px 20px;}
    .wrap{max-width:640px;margin:0 auto;}
    h1{font-size:20px;margin:0 0 4px;}
    .sub{color:#94a3b8;font-size:13px;margin:0 0 28px;}
    .group-title{font-size:13px;font-weight:700;color:#e2e8f0;text-transform:uppercase;letter-spacing:0.5px;margin:28px 0 4px;}
    .group-sub{color:#64748b;font-size:12px;margin:0 0 12px;}
    .row{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;background:#1e293b;border:1px solid #334155;border-radius:10px;margin-bottom:10px;}
    .row .name{font-weight:600;font-size:14px;}
    .row .key{color:#64748b;font-size:11px;font-family:monospace;margin-top:2px;}
    .toggle{position:relative;display:inline-block;width:44px;height:24px;flex-shrink:0;}
    .toggle input{opacity:0;width:0;height:0;}
    .toggle .slider{position:absolute;cursor:pointer;inset:0;background:#475569;border-radius:24px;transition:.2s;}
    .toggle .slider:before{content:"";position:absolute;height:18px;width:18px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s;}
    .toggle input:checked + .slider{background:#16a34a;}
    .toggle input:checked + .slider:before{transform:translateX(20px);}
    .save-btn{margin-top:24px;width:100%;padding:14px;background:#2563eb;color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;}
    .save-btn:hover{background:#1d4ed8;}
    .banner{background:#14532d;border:1px solid #16a34a;color:#bbf7d0;padding:12px 16px;border-radius:8px;font-size:13px;margin-bottom:20px;}
</style>
</head>
<body>
<div class="wrap">
    <h1><?=htmlspecialchars(setting('site_name','Gracehubtels'))?> — Vendor Access</h1>
    <p class="sub">Toggle what's available on this install. Off = client (or their customer) sees a "Premium Feature" message instead. Absence of a row in the underlying table defaults to ON/available, so nothing here needs to be touched unless you're deliberately locking something.</p>
    <?php if ($saved): ?><div class="banner">Saved. Changes take effect immediately, no redeploy needed.</div><?php endif; ?>
    <form method="POST">
        <input type="hidden" name="key" value="<?=htmlspecialchars($key, ENT_QUOTES)?>">

        <div class="group-title">Customer Services</div>
        <p class="group-sub">Purchase features shown to this install's own customers.</p>
        <?php foreach (FEATURE_REGISTRY as $fkey => $flabel): $on = feature_enabled($fkey); ?>
        <div class="row">
            <div>
                <div class="name"><?=htmlspecialchars($flabel)?></div>
                <div class="key"><?=htmlspecialchars($fkey)?></div>
            </div>
            <label class="toggle">
                <input type="checkbox" name="feature[<?=htmlspecialchars($fkey)?>]" <?=$on?'checked':''?>>
                <span class="slider"></span>
            </label>
        </div>
        <?php endforeach; ?>

        <div class="group-title">Admin Tools</div>
        <p class="group-sub">Extra back-office pages for THIS install's own staff — never locks anything operationally essential (withdrawals, KYC, settings, staff/security, API setup always stay available).</p>
        <?php foreach (ADMIN_FEATURE_REGISTRY as $fkey => $flabel): $on = feature_enabled($fkey); ?>
        <div class="row">
            <div>
                <div class="name"><?=htmlspecialchars($flabel)?></div>
                <div class="key"><?=htmlspecialchars($fkey)?></div>
            </div>
            <label class="toggle">
                <input type="checkbox" name="feature[<?=htmlspecialchars($fkey)?>]" <?=$on?'checked':''?>>
                <span class="slider"></span>
            </label>
        </div>
        <?php endforeach; ?>

        <div class="group-title">Provider Access</div>
        <p class="group-sub">Which upstream VTU/service integrations this install may route purchases through — enforced centrally regardless of what the client's own admin configures in their API Registry/API Routes, since that's their own database. Off = never selected as a provider, even if the client adds real credentials for it themselves.</p>
        <?php foreach (PROVIDER_REGISTRY as $pkey => $plabel): $allowed = !provider_locked($pkey); ?>
        <div class="row">
            <div>
                <div class="name"><?=htmlspecialchars($plabel)?></div>
                <div class="key"><?=htmlspecialchars($pkey)?></div>
            </div>
            <label class="toggle">
                <input type="checkbox" name="provider[<?=htmlspecialchars($pkey)?>]" <?=$allowed?'checked':''?>>
                <span class="slider"></span>
            </label>
        </div>
        <?php endforeach; ?>

        <button type="submit" class="save-btn">Save Changes</button>
    </form>
</div>
</body>
</html>
