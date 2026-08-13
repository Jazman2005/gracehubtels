<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_admin();
require_permission('manage_api');

// One-off diagnostic: fetches SageCloud's CURRENT live electricity biller
// list using the real stored credentials, rather than trusting their
// Postman docs' sample list — confirmed live that at least one biller ID
// from those docs ("kedco_electric_prepaid") is no longer valid
// ("No biller found with id: ..."), so the docs' snapshot has drifted from
// what SageCloud's account actually has configured today.

$db = db();
$reg = $db->query("SELECT id, name, base_url, api_key, api_token FROM api_registry WHERE name LIKE '%sagecloud%' LIMIT 1")->fetch();

$billers = null; $error = null; $raw = null;
if ($reg) {
    $base = rtrim($reg['base_url'], '/');
    if (substr($base, -4) !== '/api') $base .= '/api';
    $url = $base . '/v2/electricity/fetch-billers';
    $token = $reg['api_token'] ?: $reg['api_key'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $d = $raw ? json_decode($raw, true) : null;
    if ($code === 200 && $d && !empty($d['billers'])) {
        $billers = $d['billers'];
    } else {
        $error = "HTTP $code: " . ($raw ?: 'no response');
    }
} else {
    $error = 'No SageCloud entry found in API Registry.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>SageCloud Live Billers — <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title>
<link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css">
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">
<div class="section-header"><h2>SageCloud Live Electricity Billers</h2></div>

<?php if ($error): ?>
  <div style="padding:14px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;margin-bottom:16px;color:#991b1b;font-weight:600">
    Could not fetch live billers: <?=htmlspecialchars($error)?>
  </div>
<?php else: ?>
  <p style="color:#666;font-size:13px;margin-bottom:16px">This is SageCloud's real, current biller list — fetched live just now, not from documentation. Match each "type" value below against your discos, then tell me the correct ones so I can update the code's disco-to-slug map.</p>
  <table style="width:100%;border-collapse:collapse;font-size:13px">
    <tr style="background:#f3f4f6"><th style="text-align:left;padding:6px">type (what the code should send)</th><th style="text-align:left;padding:6px">narration</th><th style="text-align:left;padding:6px">product_id</th></tr>
    <?php foreach ($billers as $b): ?>
    <tr style="border-bottom:1px solid #e5e7eb">
      <td style="padding:6px;font-family:monospace;font-weight:600"><?=htmlspecialchars($b['type'] ?? '')?></td>
      <td style="padding:6px"><?=htmlspecialchars($b['narration'] ?? '')?></td>
      <td style="padding:6px"><?=htmlspecialchars((string)($b['product_id'] ?? ''))?></td>
    </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>

</div></div>
</body>
</html>
