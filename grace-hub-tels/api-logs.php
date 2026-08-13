<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';

auth_admin();
// The staff-management.php permission checkbox for this page (view_apilogs)
// existed but was never actually enforced anywhere.
require_permission('view_apilogs');
$csrf = admin_csrf();

$log_dir = realpath(__DIR__ . '/../logs/');
$error_log_path = realpath(__DIR__ . '/../user/error_log');

// Discover available log files
$log_files = [];
if ($log_dir && is_dir($log_dir)) {
    $files = glob($log_dir . '/*.log');
    if ($files) {
        foreach ($files as $f) {
            $log_files[basename($f)] = $f;
        }
    }
}
if ($error_log_path && file_exists($error_log_path)) {
    $log_files['PHP Error Log (user/error_log)'] = $error_log_path;
}

$avail_keys = array_keys($log_files);
$selected_log_key = $_GET['log'] ?? ($avail_keys[0] ?? '');
if (!isset($log_files[$selected_log_key])) {
    reset($log_files);
    $selected_log_key = key($log_files) ?: '';
}

$selected_path = $log_files[$selected_log_key] ?? null;
$search_query = trim($_GET['q'] ?? '');
$filter_status = strtoupper(trim($_GET['status'] ?? ''));

// Handle clear log
if (isset($_POST['clear_log']) && verify_admin_csrf($_POST['csrf'] ?? '')) {
    if (($_SESSION['admin_role'] ?? '') === 'superadmin' && $selected_path && file_exists($selected_path)) {
        file_put_contents($selected_path, '');
        header("Location: api-logs.php?log=" . urlencode($selected_log_key) . "&msg=cleared");
        exit;
    }
}

$entries = [];
$total_fails = 0;
$total_ok = 0;
$file_size_formatted = '0 B';

if ($selected_path && file_exists($selected_path)) {
    $bytes = filesize($selected_path);
    if ($bytes >= 1048576) $file_size_formatted = number_format($bytes / 1048576, 2) . ' MB';
    elseif ($bytes >= 1024) $file_size_formatted = number_format($bytes / 1024, 2) . ' KB';
    else $file_size_formatted = $bytes . ' B';

    // Read lines from bottom up (latest first)
    $file = new SplFileObject($selected_path);
    $file->seek(PHP_INT_MAX);
    $total_lines = $file->key();

    $max_read = 500; // Read last 500 lines for speed
    $start_line = max(0, $total_lines - $max_read);

    for ($i = $total_lines - 1; $i >= $start_line; $i--) {
        $file->seek($i);
        $line = trim($file->current());
        if (empty($line)) continue;

        $is_json = false;
        $parsed = json_decode($line, true);
        
        $entry_time = '';
        $entry_level = 'INFO';
        $entry_title = '';
        $entry_payload = '';
        $entry_resp = '';

        if (is_array($parsed)) {
            $is_json = true;
            $entry_time = $parsed['time'] ?? '';
            
            // Check status or level
            if (isset($parsed['status'])) {
                $entry_level = strtoupper($parsed['status']);
            } elseif (isset($parsed['level'])) {
                $entry_level = strtoupper($parsed['level']);
            }
            
            $provider = $parsed['provider'] ?? '';
            $endpoint = $parsed['endpoint'] ?? $parsed['action'] ?? '';
            $entry_title = trim($provider . ' ' . $endpoint);

            $entry_payload = isset($parsed['payload']) ? json_encode($parsed['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '';
            $entry_resp = isset($parsed['response']) ? json_encode($parsed['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '';
            
            if (isset($parsed['response']['message'])) {
                $entry_resp = "Message: " . $parsed['response']['message'] . "\n\nFull:\n" . $entry_resp;
            }
        } elseif (preg_match('/^\[([\d\-: ]+)\]\s*\[(OK|FAIL)\]\s*(\S+)\s*REQ:(.*?)\s*RES:(.*)$/s', $line, $m)) {
            // log_api()'s actual format — "[timestamp] [OK|FAIL] endpoint
            // REQ:{...} RES:{...}" — plain text, not JSON, so the branch
            // above always missed it and every one of these lines fell into
            // the generic guess below, which doesn't recognize the literal
            // "[FAIL]"/"[OK]" markers (it only pattern-matches the words
            // "error"/"exception"), so every provider call's real
            // success/fail status was silently misreported in the KPI
            // totals above and showed as an opaque "System Log Entry".
            $entry_time = $m[1];
            $entry_level = $m[2] === 'FAIL' ? 'FAIL' : 'OK';
            $entry_title = $m[3];
            $entry_payload = $m[4];
            $entry_resp = $m[5];
        } else {
            // Raw string line (like PHP error log)
            $entry_time = substr($line, 1, 20); // guess timestamp
            $entry_level = (stripos($line, 'error') !== false || stripos($line, 'exception') !== false) ? 'ERROR' : 'INFO';
            $entry_title = 'System Log Entry';
            $entry_resp = $line;
        }

        if (in_array($entry_level, ['FAIL', 'ERROR', 'FAILED'])) {
            $total_fails++;
        } elseif (in_array($entry_level, ['SUCCESS', 'OK', 'INFO'])) {
            $total_ok++;
        }

        // Apply Search Filter
        if (!empty($search_query) && stripos($line, $search_query) === false) {
            continue;
        }

        // Apply Status Filter
        if ($filter_status === 'FAIL' && !in_array($entry_level, ['FAIL', 'ERROR', 'FAILED'])) {
            continue;
        } elseif ($filter_status === 'SUCCESS' && !in_array($entry_level, ['SUCCESS', 'OK'])) {
            continue;
        }

        $entries[] = [
            'time' => $entry_time,
            'level' => $entry_level,
            'title' => $entry_title,
            'payload' => $entry_payload,
            'response' => $entry_resp,
            'raw' => $line
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Backend API & Error Diagnostics – <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title>
    <link rel="icon" type="image/png" href="/assets/images/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .log-filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            background: var(--surface);
            padding: 16px 20px;
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
            margin-bottom: 24px;
        }
        .log-filter-bar select, .log-filter-bar input {
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            color: var(--text);
            background: var(--bg);
        }
        .log-filter-bar select:focus, .log-filter-bar input:focus {
            outline: none;
            border-color: var(--primary);
            background: var(--surface);
        }
        .log-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        .log-badge.fail, .log-badge.error { background: rgba(220,38,38,0.1); color: var(--danger); }
        .log-badge.success, .log-badge.ok { background: rgba(5,150,105,0.1); color: var(--success); }
        .log-badge.info { background: rgba(37,99,235,0.1); color: var(--info); }
        .code-block {
            background: #0F172A;
            color: #F8FAFC;
            padding: 12px;
            border-radius: 8px;
            font-family: monospace;
            font-size: 12px;
            overflow-x: auto;
            max-height: 250px;
            white-space: pre-wrap;
            word-break: break-all;
        }
    </style>
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
    <div>
        <h2>Backend API & Error Diagnostics</h2>
        <p>Inspect live API requests, provider responses, and PHP system errors</p>
    </div>
    <?php if (($_SESSION['admin_role'] ?? '') === 'superadmin'): ?>
    <form method="POST" onsubmit="return confirm('Clear all recorded entries in this log file?');">
        <input type="hidden" name="csrf" value="<?=$csrf?>">
        <button type="submit" name="clear_log" class="btn btn-danger" style="background:#EF4444; color:#fff; padding:10px 16px; border:none; border-radius:8px; font-weight:600; cursor:pointer;">
            <i class="fas fa-trash-alt"></i> Clear Selected Log
        </button>
    </form>
    <?php endif; ?>
</div>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'cleared'): ?>
<div style="background:#D1FAE5; color:#065F46; padding:12px 16px; border-radius:8px; margin-bottom:20px; font-weight:600;">
    <i class="fas fa-check-circle"></i> Log file cleared successfully.
</div>
<?php endif; ?>

<div class="kpi-grid">
    <div class="data-panel kpi-card">
        <div class="kpi-icon orange"><i class="fas fa-file-alt"></i></div>
        <div class="kpi-info">
            <div class="kpi-label">Log File Size</div>
            <div class="kpi-value"><?=$file_size_formatted?></div>
        </div>
    </div>
    <div class="data-panel kpi-card">
        <div class="kpi-icon red"><i class="fas fa-exclamation-triangle"></i></div>
        <div class="kpi-info">
            <div class="kpi-label">Recorded Errors / Fails</div>
            <div class="kpi-value"><?=number_format($total_fails)?></div>
        </div>
    </div>
    <div class="data-panel kpi-card">
        <div class="kpi-icon green"><i class="fas fa-check"></i></div>
        <div class="kpi-info">
            <div class="kpi-label">Successful Events</div>
            <div class="kpi-value"><?=number_format($total_ok)?></div>
        </div>
    </div>
    <div class="data-panel kpi-card">
        <div class="kpi-icon gray"><i class="fas fa-list"></i></div>
        <div class="kpi-info">
            <div class="kpi-label">Displayed Entries</div>
            <div class="kpi-value"><?=number_format(count($entries))?></div>
        </div>
    </div>
</div>

<form method="GET" class="log-filter-bar">
    <div>
        <label style="font-weight:600; font-size:13px; margin-right:6px; color:var(--text-muted);">Select Log File:</label>
        <select name="log" onchange="this.form.submit()">
            <?php if (empty($log_files)): ?>
            <option value="" disabled selected>No log files found</option>
            <?php else: ?>
            <?php foreach ($log_files as $label => $path): ?>
            <option value="<?=htmlspecialchars($label)?>" <?=$selected_log_key === $label ? 'selected' : ''?>><?=htmlspecialchars($label)?></option>
            <?php endforeach; ?>
            <?php endif; ?>
        </select>
    </div>
    <div>
        <label style="font-weight:600; font-size:13px; margin-right:6px; color:var(--text-muted);">Filter Level:</label>
        <select name="status" onchange="this.form.submit()">
            <option value="" <?=$filter_status === '' ? 'selected' : ''?>>All Statuses</option>
            <option value="FAIL" <?=$filter_status === 'FAIL' ? 'selected' : ''?>>Failures / Errors Only</option>
            <option value="SUCCESS" <?=$filter_status === 'SUCCESS' ? 'selected' : ''?>>Success Only</option>
        </select>
    </div>
    <div style="flex:1; min-width:200px; display:flex; gap:8px;">
        <input type="text" name="q" value="<?=htmlspecialchars($search_query)?>" placeholder="Search phone, ref, error code..." style="flex:1;">
        <button type="submit" style="background:var(--primary); color:#fff; border:none; padding:10px 18px; border-radius:8px; font-weight:600; cursor:pointer;">
            <i class="fas fa-search"></i>
        </button>
        <?php if ($search_query !== '' || $filter_status !== ''): ?>
        <a href="api-logs.php?log=<?=urlencode($selected_log_key)?>" class="btn-ghost" style="display:flex; align-items:center;">
            <i class="fas fa-times"></i>
        </a>
        <?php endif; ?>
    </div>
</form>

<div class="data-panel">
    <div class="panel-title-bar"><h3>Showing Latest Entries (Max 500)</h3></div>
    <div class="responsive-grid-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:160px;">Timestamp</th>
                    <th style="width:100px;">Level</th>
                    <th style="width:220px;">Service / Action</th>
                    <th>Payload / Request Data</th>
                    <th>Response / Error Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($entries)): ?>
                <tr>
                    <td colspan="5" class="empty-state">
                        <i class="fas fa-folder-open empty-state-icon"></i>
                        No matching log entries found in this file.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($entries as $row): ?>
                <tr style="vertical-align:top;">
                    <td class="text-muted" style="font-size:13px;">
                        <i class="far fa-clock" style="margin-right:4px; opacity:0.7;"></i> <?=htmlspecialchars($row['time'])?>
                    </td>
                    <td>
                        <span class="log-badge <?=strtolower($row['level'])?>"><?=htmlspecialchars($row['level'])?></span>
                    </td>
                    <td style="font-weight:600; font-size:14px;">
                        <?=htmlspecialchars($row['title'])?>
                    </td>
                    <td style="width:30%;">
                        <?php if (!empty($row['payload'])): ?>
                        <div class="code-block"><?=htmlspecialchars($row['payload'])?></div>
                        <?php else: ?>
                        <span class="text-muted" style="font-style:italic;">None</span>
                        <?php endif; ?>
                    </td>
                    <td style="width:35%;">
                        <?php if (!empty($row['response'])): ?>
                        <div class="code-block" style="<?=in_array($row['level'], ['FAIL','ERROR']) ? 'border-left:3px solid var(--danger);' : ''?>"><?=htmlspecialchars($row['response'])?></div>
                        <?php else: ?>
                        <span class="text-muted" style="font-style:italic;">None</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div>
</div>
</body>
</html>
