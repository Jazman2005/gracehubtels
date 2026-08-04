<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
require_once __DIR__.'/../user/core/onesignal.php';

auth_admin();
$csrf = admin_csrf();

// Ensure log table exists
db()->exec("CREATE TABLE IF NOT EXISTS push_notification_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  message TEXT NOT NULL,
  target VARCHAR(50) NOT NULL,
  recipients_count INT DEFAULT 0,
  tracking_id VARCHAR(255) DEFAULT NULL,
  recipient_ids TEXT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Capture recipient user IDs for a given target
function get_recipient_ids(string $target): array {
  if ($target === 'Total Subscriptions') {
    return db()->query("SELECT id FROM users WHERE push_token IS NOT NULL AND push_token != '' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
  }
  if (in_array($target, ['regular', 'agent', 'vendor'])) {
    $s = db()->prepare("SELECT id FROM users WHERE user_type=? AND push_token IS NOT NULL AND push_token != '' AND is_active = 1");
    $s->execute([$target]);
    return $s->fetchAll(PDO::FETCH_COLUMN);
  }
  if ($target === 'Test Devices') {
    return db()->query("SELECT id FROM users WHERE push_token IS NOT NULL AND push_token != '' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
  }
  if (is_numeric($target)) {
    return [(int)$target];
  }
  return [];
}

// Handle AJAX Post
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_admin_csrf($_POST['csrf'] ?? '')) {
        json_response(['success' => false, 'message' => 'Invalid CSRF token'], 403);
    }
    
    $action = $_POST['action'];

    if ($action === 'save_settings') {
        setting_set('onesignal_app_id', trim($_POST['onesignal_app_id'] ?? ''));
        setting_set('onesignal_rest_api_key', trim($_POST['onesignal_rest_api_key'] ?? ''));
        json_response(['success' => true, 'message' => 'OneSignal settings saved successfully!']);
    }

    if ($action === 'send_push') {
        $title = trim($_POST['title'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $target = trim($_POST['target'] ?? 'Total Subscriptions');
        $raw_data = trim($_POST['deep_link_data'] ?? '');

        if (empty($title) || empty($message)) {
            json_response(['success' => false, 'message' => 'Title and Message body are required.']);
        }

        $custom_data = [];
        if (!empty($raw_data)) {
            $parsed = json_decode($raw_data, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($parsed)) {
                json_response(['success' => false, 'message' => 'Invalid JSON payload for deep-link data.']);
            }
            $custom_data = $parsed;
        }

        // Capture recipient IDs before sending
        $recipient_ids = get_recipient_ids($target);

        $api = new OneSignalAPI();
        $response = $api->send_push($title, $message, $target, $custom_data);
        
        if ($response['success']) {
            audit_log($_SESSION['admin_id'], 'ONESIGNAL_PUSH', "Sent push notification: $title");

            // Log to history
            $log = db()->prepare("INSERT INTO push_notification_log (admin_id, title, message, target, recipients_count, tracking_id, recipient_ids) VALUES (?,?,?,?,?,?,?)");
            $log->execute([
                $_SESSION['admin_id'],
                $title,
                $message,
                $target,
                $response['recipients'] ?? 0,
                $response['tracking_id'] ?? '',
                implode(',', $recipient_ids)
            ]);
            $response['log_id'] = db()->lastInsertId();
        }
        
        json_response($response);
    }

    if ($action === 'view_recipients') {
        $log_id = (int)($_POST['log_id'] ?? 0);
        $row = db()->query("SELECT recipient_ids FROM push_notification_log WHERE id=$log_id")->fetch();
        if (!$row || empty($row['recipient_ids'])) {
            json_response(['success' => false, 'message' => 'No recipient data available']);
        }
        $ids = array_filter(array_map('trim', explode(',', $row['recipient_ids'])));
        if (empty($ids)) {
            json_response(['success' => false, 'message' => 'No recipient data available']);
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("SELECT id, full_name, email, phone, user_type FROM users WHERE id IN ($placeholders) ORDER BY full_name");
        $stmt->execute(array_values($ids));
        json_response(['success' => true, 'recipients' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'delete_log') {
        $log_id = (int)($_POST['log_id'] ?? 0);
        db()->prepare("DELETE FROM push_notification_log WHERE id=?")->execute([$log_id]);
        json_response(['success' => true]);
    }
    
    json_response(['success' => false, 'message' => 'Unknown action']);
}

function k(string $key): string { return htmlspecialchars(setting($key)); }

$app_id = k('onesignal_app_id');
$rest_key = k('onesignal_rest_api_key');
$is_configured = !empty($app_id) && !empty($rest_key);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Push Notifications – Gracehubtels Admin</title>
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
        <h2>Push Notifications</h2>
        <p>Broadcast alerts instantly to Expo Mobile App users via OneSignal</p>
    </div>
</div>

<?php if (!$is_configured): ?>
    <div style="padding:12px 16px;border-radius:10px;font-size:13px;font-weight:600;background:var(--warning-bg, #fffbeb);color:var(--warning-text, #92400e)">
        <i class="fas fa-exclamation-triangle"></i> <strong>Configuration Required:</strong> Please enter your OneSignal App ID and REST API Key in the settings panel below before sending notifications.
    </div>
<?php endif; ?>

<div class="dash-row-2col">
    
    <!-- Compose Notification -->
    <div class="data-panel">
        <div class="panel-title-bar" style="background:var(--navy);color:#fff">
            <i class="fas fa-paper-plane"></i>
            <h3 style="color:#fff;font-size:15px;font-weight:700">Compose Push Notification</h3>
        </div>
        <div style="padding:24px">
            <div id="pushAlert" style="padding:12px 16px;border-radius:10px;font-size:13px;font-weight:600;display:none"></div>
            
            <form id="pushForm" onsubmit="sendPush(event)">
                <div class="form-group">
                    <label style="font-size:13px;font-weight:600;color:var(--navy-dark);display:block;margin-bottom:6px">Notification Title <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="pushTitle" class="form-control" placeholder="e.g. Special Weekend Promo!" required>
                </div>
                
                <div class="form-group">
                    <label style="font-size:13px;font-weight:600;color:var(--navy-dark);display:block;margin-bottom:6px">Notification Body <span style="color:var(--danger)">*</span></label>
                    <textarea id="pushMessage" class="form-control" rows="4" placeholder="Enter the main message text here..." required></textarea>
                </div>

                <div class="form-group">
                    <label style="font-size:13px;font-weight:600;color:var(--navy-dark);display:block;margin-bottom:6px">Target Audience</label>
                    <select id="pushTarget" class="form-control">
                        <option value="Total Subscriptions">All Users (Total Subscriptions)</option>
                        <option value="regular">Subscribers Only (Regular Users)</option>
                        <option value="agent">Agents Only</option>
                        <option value="vendor">Vendors Only</option>
                        <option value="Test Devices">Test Devices Only</option>
                    </select>
                    <p style="font-size: 12px; color: #64748b; margin-top: 4px;">Select a user type to target only users with registered push tokens on their devices.</p>
                </div>

                <div class="form-group">
                    <label style="font-size:13px;font-weight:600;color:var(--navy-dark);display:block;margin-bottom:6px">Deep-Link Data Payload (Optional JSON)</label>
                    <textarea id="pushData" class="form-control" rows="3" placeholder='{"screen": "Dashboard", "promo_id": "123"}' style="font-family: monospace; font-size: 13px;"></textarea>
                    <p style="font-size: 12px; color: #64748b; margin-top: -10px;">Pass custom routing variables to your Expo app when a user taps the notification.</p>
                </div>

                <div style="text-align: right; margin-top: 10px;">
                    <button type="submit" class="btn-primary" id="btnSend">
                        <i class="fas fa-satellite-dish"></i> Send Notification
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- API Settings -->
    <div class="data-panel">
        <div class="panel-title-bar" style="background:var(--navy);color:#fff">
            <i class="fas fa-cog"></i>
            <h3 style="color:#fff;font-size:15px;font-weight:700">OneSignal Settings</h3>
        </div>
        <div style="padding:24px">
            <div id="settingsAlert" style="padding:12px 16px;border-radius:10px;font-size:13px;font-weight:600;display:none"></div>
            <form id="settingsForm" onsubmit="saveSettings(event)">
                <div class="form-group">
                    <label style="font-size:13px;font-weight:600;color:var(--navy-dark);display:block;margin-bottom:6px">App ID</label>
                    <input type="text" id="osAppId" class="form-control" value="<?= $app_id ?>" placeholder="xxxx-xxxx-xxxx-xxxx" required>
                </div>
                <div class="form-group">
                    <label style="font-size:13px;font-weight:600;color:var(--navy-dark);display:block;margin-bottom:6px">REST API Key</label>
                    <input type="password" id="osRestKey" class="form-control" value="<?= $rest_key ?>" placeholder="Enter REST API Key" required>
                </div>
                <button type="submit" class="btn-secondary w-full" id="btnSettings" style="width:100%; justify-content:center;">
                    Save Settings
                </button>
            </form>
            
            <div style="margin-top: 30px; padding-top: 20px; border-top: 1px dashed #cbd5e1;">
                <h4 style="font-size: 14px; font-weight: 700; color: #334155; margin-bottom: 8px;">Need Help?</h4>
                <p style="font-size: 13px; color: #64748b; line-height: 1.5;">
                    Find your App ID and REST API Key in your <a href="https://onesignal.com" target="_blank" style="color:#4F46E5; font-weight:600;">OneSignal Dashboard</a> under <strong>Settings &gt; Keys &amp; IDs</strong>.
                </p>
            </div>
        </div>
    </div>

</div>

<!-- History -->
<?php $logs = db()->query("SELECT l.*, a.username AS admin_name FROM push_notification_log l LEFT JOIN admin a ON l.admin_id=a.id ORDER BY l.created_at DESC LIMIT 50")->fetchAll();?>
<div class="data-panel" style="margin-top:24px">
  <div class="panel-title-bar"><h3><i class="fas fa-history"></i> Notification History</h3></div>
  <?php if(empty($logs)):?>
  <div style="padding:32px;text-align:center;color:var(--text-muted);font-size:14px">No push notifications sent yet</div>
  <?php else:?>
  <div class="responsive-grid-wrapper"><table class="data-table">
    <thead><tr><th>Date</th><th>Title</th><th>Target</th><th>Recipients</th><th>Sent By</th><th>Tracking ID</th><th>Action</th></tr></thead>
    <tbody><?php foreach($logs as $log):?>
    <tr>
      <td style="white-space:nowrap;font-size:12px"><?=date('M j, g:ia',strtotime($log['created_at']))?></td>
      <td><strong><?=htmlspecialchars($log['title'])?></strong></td>
      <td><?=htmlspecialchars($log['target'])?></td>
      <td><?=$log['recipients_count']?></td>
      <td><?=htmlspecialchars($log['admin_name']??'System')?></td>
      <td style="font-size:11px;color:var(--text-muted)"><?=htmlspecialchars(substr($log['tracking_id'],0,12))?>...</td>
      <td>
        <button class="btn-ghost" style="padding:4px 10px;font-size:11px" onclick="viewRecipients(<?=$log['id']?>)"><i class="fas fa-eye"></i> View</button>
        <button class="btn-ghost" style="padding:4px 10px;font-size:11px;color:var(--danger)" onclick="deleteLog(<?=$log['id']?>)"><i class="fas fa-trash"></i></button>
      </td>
    </tr><?php endforeach;?></tbody>
  </table></div>
  <?php endif;?>
</div>

<!-- Recipient Modal -->
<div class="modal-overlay" id="recipientModal" style="display:none" onclick="if(event.target===this)closeRecipients()">
  <div class="modal" style="max-width:600px">
    <div class="modal-header"><h3><i class="fas fa-users"></i> Push Recipients</h3><button class="modal-close" onclick="closeRecipients()">&times;</button></div>
    <div class="modal-body" id="recipientBody" style="max-height:400px;overflow-y:auto">
      <p style="text-align:center;color:var(--text-muted);padding:20px">Loading...</p>
    </div>
  </div>
</div>

</div></div>
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
function esc(s){if(!s)return '';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

async function saveSettings(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSettings');
    const alertBox = document.getElementById('settingsAlert');
    btn.innerHTML = 'Saving...';
    btn.disabled = true;

    const fd = new FormData();
    fd.append('csrf', '<?= $csrf ?>');
    fd.append('action', 'save_settings');
    fd.append('onesignal_app_id', document.getElementById('osAppId').value);
    fd.append('onesignal_rest_api_key', document.getElementById('osRestKey').value);

    try {
        const res = await fetch(location.href, { method: 'POST', body: fd }).then(r => r.json());
        alertBox.style.display = 'block';
        alertBox.style.background = res.success ? 'var(--success-bg, #ecfdf5)' : 'var(--danger-bg, #fef2f2)';
        alertBox.style.color = res.success ? 'var(--success-text, #065f46)' : 'var(--danger-text, #991b1b)';
        alertBox.innerHTML = esc(res.message);
        if(res.success) setTimeout(()=>location.reload(), 1500);
    } catch (err) {
        alertBox.style.display = 'block';
        alertBox.style.background = 'var(--danger-bg, #fef2f2)';
        alertBox.style.color = 'var(--danger-text, #991b1b)';
        alertBox.innerHTML = 'Network error saving settings.';
    } finally {
        btn.innerHTML = 'Save Settings';
        btn.disabled = false;
    }
}

async function sendPush(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSend');
    const alertBox = document.getElementById('pushAlert');
    
    const title = document.getElementById('pushTitle').value.trim();
    const message = document.getElementById('pushMessage').value.trim();
    const target = document.getElementById('pushTarget').value;
    const data = document.getElementById('pushData').value.trim();

    if(!title || !message) {
        alertBox.style.display = 'block';
        alertBox.style.background = 'var(--danger-bg, #fef2f2)';
        alertBox.style.color = 'var(--danger-text, #991b1b)';
        alertBox.innerHTML = 'Title and Message are required.';
        return;
    }

    if(data) {
        try { JSON.parse(data); } catch(e) {
            alertBox.style.display = 'block';
            alertBox.style.background = 'var(--danger-bg, #fef2f2)';
            alertBox.style.color = 'var(--danger-text, #991b1b)';
            alertBox.innerHTML = 'Deep-Link Data must be valid JSON format.';
            return;
        }
    }

    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
    btn.disabled = true;
    alertBox.style.display = 'none';

    const fd = new FormData();
    fd.append('csrf', '<?= $csrf ?>');
    fd.append('action', 'send_push');
    fd.append('title', title);
    fd.append('message', message);
    fd.append('target', target);
    fd.append('deep_link_data', data);

    try {
        const res = await fetch(location.href, { method: 'POST', body: fd }).then(r => r.json());
        if(res.success) {
            alertBox.style.display = 'block';
            alertBox.style.background = 'var(--success-bg, #ecfdf5)';
            alertBox.style.color = 'var(--success-text, #065f46)';
            alertBox.innerHTML = `<strong><i class="fas fa-check-circle"></i> ${esc(res.message)}</strong><br><small>Recipients: ${parseInt(res.recipients)||0} | Tracking ID: ${esc(res.tracking_id)}</small>`;
            document.getElementById('pushTitle').value = '';
            document.getElementById('pushMessage').value = '';
        } else {
            alertBox.style.display = 'block';
            alertBox.style.background = 'var(--danger-bg, #fef2f2)';
            alertBox.style.color = 'var(--danger-text, #991b1b)';
            alertBox.innerHTML = esc(res.message);
        }
    } catch (err) {
        alertBox.style.display = 'block';
        alertBox.style.background = 'var(--danger-bg, #fef2f2)';
        alertBox.style.color = 'var(--danger-text, #991b1b)';
        alertBox.innerHTML = 'Network error occurred while connecting to server.';
    } finally {
        btn.innerHTML = '<i class="fas fa-satellite-dish"></i> Send Notification';
        btn.disabled = false;
    }
}

async function viewRecipients(logId) {
    document.getElementById('recipientBody').innerHTML = '<p style="text-align:center;color:var(--text-muted);padding:20px"><i class="fas fa-spinner fa-spin"></i> Loading...</p>';
    document.getElementById('recipientModal').style.display = 'flex';
    const fd = new FormData();
    fd.append('csrf', '<?=$csrf?>');
    fd.append('action', 'view_recipients');
    fd.append('log_id', logId);
    try {
        const res = await fetch(location.href, { method: 'POST', body: fd }).then(r=>r.json());
        const body = document.getElementById('recipientBody');
        if(!res.success || !res.recipients.length) {
            body.innerHTML = '<p style="text-align:center;color:var(--text-muted);padding:20px">No recipient data available</p>';
            return;
        }
        let html = '<table class="data-table"><thead><tr><th>#</th><th>Name</th><th>Email</th><th>Phone</th><th>Type</th></tr></thead><tbody>';
        res.recipients.forEach((u,i) => {
            html += `<tr><td>${i+1}</td><td><strong>${esc(u.full_name||'—')}</strong></td><td>${esc(u.email||'—')}</td><td>${esc(u.phone||'—')}</td><td>${esc(u.user_type||'—')}</td></tr>`;
        });
        html += '</tbody></table>';
        body.innerHTML = html;
    } catch(e) {
        document.getElementById('recipientBody').innerHTML = '<p style="text-align:center;color:var(--danger);padding:20px">Failed to load recipients</p>';
    }
}

function closeRecipients() {
    document.getElementById('recipientModal').style.display = 'none';
}

async function deleteLog(logId) {
    if(!confirm('Delete this notification log?')) return;
    const fd = new FormData();
    fd.append('csrf', '<?=$csrf?>');
    fd.append('action', 'delete_log');
    fd.append('log_id', logId);
    try {
        const res = await fetch(location.href, { method: 'POST', body: fd }).then(r=>r.json());
        if(res.success) location.reload();
    } catch(e) {}
}
</script>
</body>
</html>
