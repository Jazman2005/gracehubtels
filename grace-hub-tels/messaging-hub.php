<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
require_once __DIR__.'/../user/core/onesignal.php';

auth_admin();
require_feature_admin('admin_messaging_hub');
$csrf = admin_csrf();



if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_admin_csrf($_POST['csrf'] ?? '')) {
        json_response(['success' => false, 'message' => 'Invalid CSRF token'], 403);
    }
    // Mass WhatsApp/Email/SMS broadcasts to the whole user base — same
    // permission Staff Management already defines for the Messaging Hub.
    if (!has_permission('manage_messaging')) {
        json_response(['success' => false, 'message' => 'Missing permission: manage_messaging'], 403);
    }

    $action = $_POST['action'];

    if ($action === 'send_broadcast') {
        try {
            $title = trim($_POST['title'] ?? '');
            $message = trim($_POST['message'] ?? '');
            $segment = trim($_POST['segment'] ?? 'all');
            $specific_user = trim($_POST['specific_user'] ?? '');
            
            $channels = $_POST['channels'] ?? []; // array like ['push', 'whatsapp', 'email', 'sms']
            
            if (empty($title) || empty($message)) {
                json_response(['success' => false, 'message' => 'Title and Message body are required.']);
            }
            
            if (empty($channels) || !is_array($channels)) {
                json_response(['success' => false, 'message' => 'Please select at least one communication channel.']);
            }

            // Fetch target users
            $users = [];
            if ($segment === 'all') {
                $stmt = db()->query("SELECT id, full_name, email, phone FROM users WHERE is_active = 1");
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } elseif ($segment === 'active') {
                $stmt = db()->query("
                    SELECT u.id, u.full_name, u.email, u.phone 
                    FROM users u 
                    WHERE u.is_active = 1 
                      AND u.id IN (SELECT DISTINCT user_id FROM transactions WHERE created_at >= NOW() - INTERVAL 30 DAY)
                ");
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } elseif ($segment === 'inactive') {
                $stmt = db()->query("
                    SELECT u.id, u.full_name, u.email, u.phone 
                    FROM users u 
                    WHERE u.is_active = 1 
                      AND u.id NOT IN (SELECT DISTINCT user_id FROM transactions WHERE created_at >= NOW() - INTERVAL 30 DAY)
                ");
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } elseif ($segment === 'specific') {
                if (empty($specific_user)) {
                    json_response(['success' => false, 'message' => 'Please enter an email or phone for the specific user.']);
                }
                $stmt = db()->prepare("SELECT id, full_name, email, phone FROM users WHERE email = ? OR phone = ? LIMIT 1");
                $stmt->execute([$specific_user, $specific_user]);
                $u = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($u) {
                    $users[] = $u;
                } else {
                    json_response(['success' => false, 'message' => 'Specific user not found.']);
                }
            }

            if (empty($users)) {
                json_response(['success' => false, 'message' => 'No users found in the selected segment.']);
            }

            // Setup API Providers
            $onesignal = null;
            if (in_array('push', $channels)) {
                $onesignal = new OneSignalAPI();
            }

            // Bulk SMS provider setup
            $sms_prov = null;
            if (in_array('sms', $channels)) {
                $prov_stmt = db()->prepare("SELECT * FROM api_providers WHERE service='bulk_sms' AND is_active=1 LIMIT 1");
                $prov_stmt->execute();
                $sms_prov = $prov_stmt->fetch(PDO::FETCH_ASSOC);
            }

            $stats = [
                'total_users' => count($users),
                'push_sent' => 0,
                'whatsapp_sent' => 0,
                'email_sent' => 0,
                'sms_sent' => 0
            ];

            // Prepare SMS numbers if sending SMS
            $sms_numbers = [];

            foreach ($users as $u) {
                $firstName = strtok($u['full_name'], ' ');
                $personalized_msg = str_replace(['{name}', '{full_name}'], [$firstName, $u['full_name']], $message);

                // 1. WhatsApp
                if (in_array('whatsapp', $channels) && function_exists('send_whatsapp_alert') && !empty($u['phone'])) {
                    $wa_msg = "*{$title}*\n\n{$personalized_msg}";
                    send_whatsapp_alert($wa_msg, $u['phone']);
                    $stats['whatsapp_sent']++;
                }

                // 2. Email
                if (in_array('email', $channels) && function_exists('send_email') && !empty($u['email'])) {
                    $html = "<div style='font-family:Arial;max-width:480px;margin:0 auto;padding:24px;border:1px solid #E2E8F0;border-radius:12px;'>
                        <h2 style='color:#0A2A7A;'>{$title}</h2>
                        <p>" . nl2br(htmlspecialchars($personalized_msg)) . "</p>
                    </div>";
                    send_email($u['email'], $title, $html);
                    $stats['email_sent']++;
                }

                // 3. Push Notifications (Send individually for personalization, or could be bulked without names)
                // For now, OneSignal supports single user targeting via External ID
                if ($onesignal) {
                    $res = $onesignal->send_push($title, $personalized_msg, $u['id']);
                    if ($res['success']) $stats['push_sent']++;
                }

                // 4. SMS Aggregation
                if (in_array('sms', $channels) && !empty($u['phone'])) {
                    $sms_numbers[] = $u['phone'];
                }
            }

            // Send Bulk SMS if requested
            if (in_array('sms', $channels) && !empty($sms_numbers) && $sms_prov) {
                $phone_list = implode(',', $sms_numbers);
                $clean_msg = str_replace(['{name}', '{full_name}'], ['Customer', 'Customer'], $message); // Default fallback since bulk
                
                $payload = [
                    'sender' => 'GRACEHUBTELS',
                    'message' => $clean_msg,
                    'recipients' => $phone_list
                ];

                $ch = curl_init($sms_prov['api_base_url']);
                $headers = ['Content-Type: application/json'];
                if (!empty($sms_prov['api_token'])) {
                    $headers[] = 'Authorization: Bearer ' . $sms_prov['api_token'];
                } elseif (!empty($sms_prov['api_key'])) {
                    $headers[] = 'Authorization: Token ' . $sms_prov['api_key'];
                    $payload['api_key'] = $sms_prov['api_key'];
                }

                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($payload),
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_SSL_VERIFYPEER => false
                ]);
                
                $sms_res = curl_exec($ch);
                curl_close($ch);
                $stats['sms_sent'] = count($sms_numbers); // Assumes accepted by gateway
            }

            audit_log($_SESSION['admin_id'], 'MESSAGING_HUB_BROADCAST', "Sent broadcast '{$title}' to {$stats['total_users']} users.");

            $res_msg = "Broadcast processed for {$stats['total_users']} users.<br>";
            if (in_array('whatsapp', $channels)) $res_msg .= "✅ WhatsApp: {$stats['whatsapp_sent']}<br>";
            if (in_array('push', $channels)) $res_msg .= "✅ Push: {$stats['push_sent']}<br>";
            if (in_array('email', $channels)) $res_msg .= "✅ Email: {$stats['email_sent']}<br>";
            if (in_array('sms', $channels)) $res_msg .= "✅ SMS: {$stats['sms_sent']}<br>";

            json_response(['success' => true, 'message' => $res_msg]);
        } catch (\Throwable $e) {
            json_response(['success' => false, 'message' => 'System Error: ' . $e->getMessage() . ' on line ' . $e->getLine()]);
        }
    }

    json_response(['success' => false, 'message' => 'Unknown action']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Messaging Hub – <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title>
    <link rel="icon" type="image/png" href="/assets/images/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .channel-checkbox { margin-right: 15px; display: inline-flex; align-items: center; gap: 8px; font-weight: 600; color: var(--navy); }
        .channel-checkbox input { width: 18px; height: 18px; accent-color: var(--primary); }
    </style>
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header">
    <div>
        <h2>Unified Messaging Hub</h2>
        <p>Broadcast messages across WhatsApp, SMS, Email, and Push Notifications.</p>
    </div>
</div>

<div class="data-panel" style="max-width: 800px; margin: 0 auto;">
    <div class="panel-title-bar" style="background:var(--navy);color:#fff">
        <i class="fas fa-bullhorn"></i>
        <h3 style="color:#fff;font-size:15px;font-weight:700">Compose Broadcast</h3>
    </div>
    <div style="padding:24px">
        <form id="broadcastForm" onsubmit="sendBroadcast(event)">
            
            <div class="form-group">
                <label style="font-size:13px;font-weight:600;color:var(--navy-dark);display:block;margin-bottom:6px">Target Segment <span style="color:var(--danger)">*</span></label>
                <select id="segment" class="form-control" onchange="toggleSpecificUser()" required>
                    <option value="all">All Registered Users</option>
                    <option value="active">Active Users (Transacted in last 30 days)</option>
                    <option value="inactive">Inactive Users (No transaction in last 30 days)</option>
                    <option value="specific">Specific User Only</option>
                </select>
            </div>

            <div class="form-group" id="specificUserGroup" style="display:none;">
                <label style="font-size:13px;font-weight:600;color:var(--navy-dark);display:block;margin-bottom:6px">User Email or Phone Number</label>
                <input type="text" id="specificUser" class="form-control" placeholder="e.g. 08012345678 or test@email.com">
            </div>

            <div class="form-group">
                <label style="font-size:13px;font-weight:600;color:var(--navy-dark);display:block;margin-bottom:12px">Communication Channels <span style="color:var(--danger)">*</span></label>
                
                <label class="channel-checkbox">
                    <input type="checkbox" name="channels" value="whatsapp" checked> 
                    <i class="fab fa-whatsapp" style="color: #25D366; font-size: 16px;"></i> WhatsApp
                </label>
                <label class="channel-checkbox">
                    <input type="checkbox" name="channels" value="push" checked> 
                    <i class="fas fa-mobile-alt" style="color: var(--primary); font-size: 16px;"></i> Push App
                </label>
                <label class="channel-checkbox">
                    <input type="checkbox" name="channels" value="email"> 
                    <i class="fas fa-envelope" style="color: #EA4335; font-size: 16px;"></i> Email
                </label>
                <label class="channel-checkbox">
                    <input type="checkbox" name="channels" value="sms"> 
                    <i class="fas fa-comment-sms" style="color: #F59E0B; font-size: 16px;"></i> SMS
                </label>
            </div>

            <div class="form-group">
                <label style="font-size:13px;font-weight:600;color:var(--navy-dark);display:block;margin-bottom:6px">Message Title <span style="color:var(--danger)">*</span></label>
                <input type="text" id="title" class="form-control" placeholder="e.g. Special Weekend Promo!" required>
            </div>
            
            <div class="form-group">
                <label style="font-size:13px;font-weight:600;color:var(--navy-dark);display:block;margin-bottom:6px">Message Body <span style="color:var(--danger)">*</span></label>
                <textarea id="message" class="form-control" rows="5" placeholder="Enter your message here... You can use {name} to personalize it." required></textarea>
                <p style="font-size: 12px; color: #64748b; margin-top: 4px;">Tip: Use <b>{name}</b> to automatically insert the user's first name.</p>
            </div>

            <div style="text-align: right; margin-top: 20px;">
                <button type="submit" class="btn-primary" id="btnSend">
                    <i class="fas fa-paper-plane" style="margin-right:6px"></i> Send Broadcast
                </button>
            </div>
        </form>
    </div>
</div>

</div>
</div>
<div class="save-toast" id="toast"></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
const csrfToken = '<?= $csrf ?>';

function toggleSpecificUser() {
    if ($('#segment').val() === 'specific') {
        $('#specificUserGroup').show();
        $('#specificUser').prop('required', true);
    } else {
        $('#specificUserGroup').hide();
        $('#specificUser').prop('required', false);
    }
}

function sendBroadcast(e) {
    e.preventDefault();
    
    let selectedChannels = [];
    $('input[name="channels"]:checked').each(function() {
        selectedChannels.push($(this).val());
    });

    if (selectedChannels.length === 0) {
        alert("Please select at least one communication channel (WhatsApp, Push, Email, or SMS).");
        return;
    }

    const btn = $('#btnSend');

    if(!confirm("Are you sure you want to broadcast this message to the selected segment? This action cannot be undone.")) return;

    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Processing...');

    $.ajax({
        url: 'messaging-hub.php',
        method: 'POST',
        data: {
            action: 'send_broadcast',
            csrf: csrfToken,
            segment: $('#segment').val(),
            specific_user: $('#specificUser').val(),
            channels: selectedChannels,
            title: $('#title').val(),
            message: $('#message').val()
        },
        dataType: 'json',
        success: function(res) {
            showToast(res.message ? res.message.replace(/<br>/g, ' — ') : (res.success ? 'Broadcast sent' : 'Error processing request'), res.success);
            if(res.success) {
                $('#broadcastForm')[0].reset();
                toggleSpecificUser();
            }
        },
        error: function(xhr, status, error) {
            let errMsg = xhr.responseJSON ? xhr.responseJSON.message : xhr.responseText;
            showToast('System Error (' + xhr.status + '): ' + errMsg, false);
        },
        complete: function() {
            btn.prop('disabled', false).html('<i class="fas fa-paper-plane" style="margin-right:6px"></i> Send Broadcast');
        }
    });
}
function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),3000);}
</script>
</body>
</html>
