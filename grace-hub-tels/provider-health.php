<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_admin();
require_permission('view_apilogs');
require_feature_admin('admin_provider_health');

// Parses log_api()'s actual line format — "[timestamp] [OK|FAIL] endpoint
// REQ:{...} RES:{...}" — scanning from the end of the file backwards and
// stopping once a line falls outside the time window, since the file is
// strictly chronological (append-only). A hard line cap protects against a
// pathologically large file from ever making this page hang.
function scan_provider_log(string $path, int $hours = 24): array {
    $ok = 0; $fail = 0; $last_time = null; $last_status = null;
    if (!file_exists($path) || filesize($path) === 0) {
        return ['ok' => 0, 'fail' => 0, 'last_time' => null, 'last_status' => null];
    }
    $cutoff = time() - $hours * 3600;
    $file = new SplFileObject($path);
    $file->seek(PHP_INT_MAX);
    $total_lines = $file->key();
    $max_scan = 5000;
    $scanned = 0;
    for ($i = $total_lines - 1; $i >= 0 && $scanned < $max_scan; $i--) {
        $file->seek($i);
        $line = trim((string)$file->current());
        if ($line === '') continue;
        if (!preg_match('/^\[([\d\-: ]+)\]\s*\[(OK|FAIL)\]/', $line, $m)) continue;
        $scanned++;
        if ($last_time === null) { $last_time = $m[1]; $last_status = $m[2]; }
        $ts = strtotime($m[1]);
        if ($ts !== false && $ts < $cutoff) break;
        if ($m[2] === 'OK') $ok++; else $fail++;
    }
    return ['ok' => $ok, 'fail' => $fail, 'last_time' => $last_time, 'last_status' => $last_status];
}

function read_heartbeat(string $file, int $stale_after_minutes): array {
    $path = LOG_DIR . '/' . $file;
    if (!file_exists($path)) {
        return ['ran_at' => null, 'status' => 'never', 'detail' => '', 'stale' => true, 'minutes_ago' => null];
    }
    $data = json_decode(file_get_contents($path), true) ?: [];
    $ran_at = $data['ran_at'] ?? null;
    $minutes_ago = $ran_at ? (int)round((time() - strtotime($ran_at)) / 60) : null;
    return [
        'ran_at' => $ran_at,
        'status' => $data['status'] ?? 'unknown',
        'detail' => $data['detail'] ?? '',
        'stale' => $minutes_ago === null || $minutes_ago > $stale_after_minutes,
        'minutes_ago' => $minutes_ago,
    ];
}

// cron_check_vtu.php's own header comment says "every 2-5 minutes"; 15
// minutes is 3x the upper bound. cron_refund.php says "every 5-10 minutes";
// 30 minutes is the same 3x buffer.
$hb_check_vtu = read_heartbeat('cron_heartbeat_check_vtu.json', 15);
$hb_refund = read_heartbeat('cron_heartbeat_refund.json', 30);

$providers = [];
foreach (glob(LOG_DIR . '/api_*.log') ?: [] as $path) {
    $channel = basename($path, '.log');
    $name = ucwords(str_replace(['api_', '_'], ['', ' '], $channel));
    $stats = scan_provider_log($path, 24);
    $total = $stats['ok'] + $stats['fail'];
    $rate = $total > 0 ? round($stats['ok'] / $total * 100, 1) : null;
    $providers[] = [
        'name' => $name,
        'channel' => $channel,
        'ok' => $stats['ok'],
        'fail' => $stats['fail'],
        'total' => $total,
        'rate' => $rate,
        'last_time' => $stats['last_time'],
        'last_status' => $stats['last_status'],
    ];
}
// Busiest first, so an admin's eye lands on the providers actually carrying
// traffic rather than an alphabetical list dominated by idle/unused ones.
usort($providers, fn($a, $b) => $b['total'] <=> $a['total']);

$total_ok = array_sum(array_column($providers, 'ok'));
$total_fail = array_sum(array_column($providers, 'fail'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>System Health — <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title>
<link rel="icon" type="image/png" href="/assets/images/favicon.png">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css">
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header">
  <div>
    <h2><i class="fas fa-heart-pulse" style="color:var(--primary);margin-right:10px"></i> System Health</h2>
    <p>Provider call success rates (last 24h) and background cron liveness — catch a silently failing provider or a dead cron before customers do</p>
  </div>
</div>

<div class="kpi-grid" style="margin-bottom:20px;">
  <div class="data-panel kpi-card">
    <div class="kpi-icon <?=$hb_check_vtu['stale']?'red':'green'?>"><i class="fas fa-<?=$hb_check_vtu['stale']?'triangle-exclamation':'check'?>"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">cron_check_vtu.php</div>
      <div class="kpi-value" style="font-size:16px;"><?=$hb_check_vtu['ran_at']?($hb_check_vtu['minutes_ago'].'m ago'):'Never run'?></div>
      <div style="font-size:11px;color:var(--text-muted);margin-top:2px;"><?=htmlspecialchars($hb_check_vtu['detail']?:'Expected every 2-5 min')?></div>
    </div>
  </div>
  <div class="data-panel kpi-card">
    <div class="kpi-icon <?=$hb_refund['stale']?'red':'green'?>"><i class="fas fa-<?=$hb_refund['stale']?'triangle-exclamation':'check'?>"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">cron_refund.php</div>
      <div class="kpi-value" style="font-size:16px;"><?=$hb_refund['ran_at']?($hb_refund['minutes_ago'].'m ago'):'Never run'?></div>
      <div style="font-size:11px;color:var(--text-muted);margin-top:2px;"><?=htmlspecialchars($hb_refund['detail']?:'Expected every 5-10 min')?></div>
    </div>
  </div>
  <div class="data-panel kpi-card">
    <div class="kpi-icon green"><i class="fas fa-check"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">Successful Calls (24h)</div>
      <div class="kpi-value"><?=number_format($total_ok)?></div>
    </div>
  </div>
  <div class="data-panel kpi-card">
    <div class="kpi-icon red"><i class="fas fa-exclamation-triangle"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">Failed Calls (24h)</div>
      <div class="kpi-value"><?=number_format($total_fail)?></div>
    </div>
  </div>
</div>

<?php if($hb_check_vtu['stale']||$hb_refund['stale']):?>
<div style="background:#FEF2F2;border:1px solid #DC2626;border-radius:10px;padding:12px 20px;font-size:13px;color:#991B1B;display:flex;align-items:flex-start;gap:12px;margin-bottom:20px;">
  <i class="fas fa-triangle-exclamation" style="margin-top:2px;"></i>
  <div>
    <?php if($hb_check_vtu['stale']):?>cron_check_vtu.php hasn't reported a run <?=$hb_check_vtu['ran_at']?'in over 15 minutes':'ever'?> — pending transactions may be stuck without auto-verification. Check the server's cron/task scheduler.<br><?php endif;?>
    <?php if($hb_refund['stale']):?>cron_refund.php hasn't reported a run <?=$hb_refund['ran_at']?'in over 30 minutes':'ever'?> — stuck transactions won't be auto-resolved or refunded. Check the server's cron/task scheduler.<?php endif;?>
  </div>
</div>
<?php endif;?>

<div class="data-panel">
  <div class="panel-title-bar"><h3><i class="fas fa-plug" style="color:var(--primary);margin-right:8px"></i> Provider Call Success Rates (Last 24h)</h3></div>
  <div class="responsive-grid-wrapper">
    <table class="data-table">
      <thead><tr><th>Provider</th><th>Calls (24h)</th><th>Success</th><th>Failures</th><th>Success Rate</th><th>Last Activity</th><th>Last Status</th></tr></thead>
      <tbody>
      <?php foreach($providers as $p):
        if($p['rate']===null)$rcls='badge-neutral';
        elseif($p['rate']>=95)$rcls='badge-success';
        elseif($p['rate']>=80)$rcls='badge-warning';
        else $rcls='badge-danger';
      ?>
      <tr>
        <td><strong><?=htmlspecialchars($p['name'])?></strong></td>
        <td><?=number_format($p['total'])?></td>
        <td style="color:var(--success)"><?=number_format($p['ok'])?></td>
        <td style="color:var(--danger)"><?=number_format($p['fail'])?></td>
        <td><span class="badge <?=$rcls?>"><?=$p['rate']!==null?$p['rate'].'%':'No calls'?></span></td>
        <td style="font-size:12px;color:var(--text-muted)"><?=htmlspecialchars($p['last_time']??'—')?></td>
        <td><?php if($p['last_status']):?><span class="badge <?=$p['last_status']==='OK'?'badge-success':'badge-danger'?>"><?=$p['last_status']?></span><?php else:?>—<?php endif;?></td>
      </tr>
      <?php endforeach; if(empty($providers)):?><tr><td colspan="7" class="empty-state">No provider call logs found yet</td></tr><?php endif;?>
      </tbody>
    </table>
  </div>
</div>

<p style="margin-top:16px;font-size:12px;color:var(--text-muted);">Full request/response detail for any of these is available in <a href="<?= ADMIN_URL ?>/api-logs.php" style="color:var(--primary);font-weight:600;">API Logs &amp; Errors</a>.</p>

</div></div>
</body>
</html>
