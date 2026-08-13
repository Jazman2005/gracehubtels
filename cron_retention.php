<?php
/**
 * Automated Customer Retention & CRM Script
 * Runs via CRON once daily (or hourly).
 * Implements: Inactive User Follow-ups, Low Balance Reminders, First Transaction Follow-ups, and Month-in-Review.
 */
require_once __DIR__ . '/user/core/db.php';
require_once __DIR__ . '/user/core/onesignal.php';
$site_name = setting('site_name', 'Gracehubtels');

// Try to include auth for email function if it exists there
if (file_exists(__DIR__ . '/user/core/auth.php')) {
    require_once __DIR__ . '/user/core/auth.php';
}

// Same cron_secret convention as cron_check_vtu.php/cron_refund.php — this
// was previously callable by anyone (it only sends marketing pushes/emails/
// WhatsApp, not money, but still shouldn't be a public, unauthenticated
// trigger for bulk outbound messaging).
if (php_sapi_name() !== 'cli' && !defined('STDIN')) {
    $cron_key = $_GET['key'] ?? '';
    $expected = setting('cron_secret', '');
    if (empty($expected) || !hash_equals($expected, $cron_key)) {
        http_response_code(403);
        exit('Access denied');
    }
}

$lockFile = __DIR__ . '/cron_retention.lock';
if (file_exists($lockFile) && (time() - filemtime($lockFile) < 300)) {
    exit("Cron is already running or ran too recently.\n");
}
touch($lockFile);
register_shutdown_function(function() use ($lockFile) {
    if (file_exists($lockFile)) unlink($lockFile);
});

// 1. Ensure CRM tracking table exists
try {
    db()->exec("CREATE TABLE IF NOT EXISTS crm_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        campaign_type VARCHAR(50) NOT NULL,
        campaign_reference VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id),
        INDEX (campaign_type),
        INDEX (campaign_reference)
    )");
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

$onesignal = new OneSignalAPI();

// Helper to check if a campaign was already sent
function crm_already_sent($user_id, $campaign_type, $campaign_reference = '') {
    $stmt = db()->prepare("SELECT id FROM crm_logs WHERE user_id = ? AND campaign_type = ? AND campaign_reference = ? LIMIT 1");
    $stmt->execute([$user_id, $campaign_type, $campaign_reference]);
    return $stmt->fetch() !== false;
}

// Helper to log sent campaign
function crm_log_sent($user_id, $campaign_type, $campaign_reference = '') {
    $stmt = db()->prepare("INSERT INTO crm_logs (user_id, campaign_type, campaign_reference) VALUES (?, ?, ?)");
    $stmt->execute([$user_id, $campaign_type, $campaign_reference]);
}

echo "Starting CRM Automations...\n<br>";

// ---------------------------------------------------------
// OPTION 1: "We Miss You" Campaigns (Inactive Users > 14 days)
// ---------------------------------------------------------
echo "Processing Inactive Users...\n<br>";
// Assuming users table has a created_at or last login. If no last_login, we check transactions.
// Find users who haven't made a transaction in 14 days, but made one before.
$stmt = db()->query("
    SELECT u.id, u.full_name, u.email, u.phone 
    FROM users u 
    WHERE u.is_active = 1 
      AND u.id NOT IN (
          SELECT DISTINCT user_id FROM transactions WHERE created_at >= NOW() - INTERVAL 14 DAY
      )
      AND u.id IN (
          SELECT DISTINCT user_id FROM transactions
      )
");
$inactive_users = $stmt->fetchAll();

foreach ($inactive_users as $u) {
    $ref = date('Y-m'); // Send once per month
    if (!crm_already_sent($u['id'], 'we_miss_you', $ref)) {
        $title = "We Miss You, " . strtok($u['full_name'], ' ') . "!";
        $message = "It's been a while! Come back and enjoy seamless Data and Airtime top-ups today.";
        
        $onesignal->send_push($title, $message, $u['id']);
        
        if (function_exists('send_email')) {
            $html = "<h3>Hi {$u['full_name']},</h3><p>{$message}</p><p>Open {$site_name} now to see our latest offers.</p>";
            send_email($u['email'], 'We Miss You! - ' . $site_name, $html);
        }
        
        if (function_exists('send_whatsapp_alert') && !empty($u['phone'])) {
            $wa_msg = "Hi " . strtok($u['full_name'], ' ') . " 👋\n\nIt's been a while! Come back and enjoy seamless Data and Airtime top-ups today at {$site_name}.";
            send_whatsapp_alert($wa_msg, $u['phone']);
        }
        
        crm_log_sent($u['id'], 'we_miss_you', $ref);
        echo "Sent We Miss You to User ID: {$u['id']}\n<br>";
    }
}

// ---------------------------------------------------------
// OPTION 2: Low Balance Reminders (Active Users < 50)
// ---------------------------------------------------------
echo "Processing Low Balance Reminders...\n<br>";
// Find active users (transacted in last 30 days) with wallet_balance < 50
$stmt = db()->query("
    SELECT u.id, u.full_name, u.email, u.wallet_balance as balance 
    FROM users u 
    WHERE u.is_active = 1 
      AND u.wallet_balance < 50
      AND u.id IN (
          SELECT DISTINCT user_id FROM transactions WHERE created_at >= NOW() - INTERVAL 30 DAY
      )
");
$low_balance_users = $stmt->fetchAll();

foreach ($low_balance_users as $u) {
    $ref = date('Y-m-d'); // Send once per day max, but ideally maybe weekly. Let's use Week number.
    $ref = date('Y-W'); 
    
    if (!crm_already_sent($u['id'], 'low_balance', $ref)) {
        $title = "Low Wallet Balance 📉";
        $message = "Your wallet is running low! Fund your {$site_name} wallet now to avoid being stranded when you need Data urgently.";
        
        $onesignal->send_push($title, $message, $u['id']);
        
        crm_log_sent($u['id'], 'low_balance', $ref);
        echo "Sent Low Balance to User ID: {$u['id']}\n<br>";
    }
}


// ---------------------------------------------------------
// OPTION 4: Transaction Follow-Ups (First transaction > 2 hrs ago)
// ---------------------------------------------------------
echo "Processing First Transaction Follow-ups...\n<br>";
$stmt = db()->query("
    SELECT t.user_id, u.full_name, u.phone, MIN(t.created_at) as first_tx_time 
    FROM transactions t
    JOIN users u ON t.user_id = u.id
    WHERE t.status = 'success' 
    GROUP BY t.user_id, u.full_name, u.phone
    HAVING first_tx_time <= NOW() - INTERVAL 2 HOUR 
       AND first_tx_time >= NOW() - INTERVAL 14 DAY
");
$first_tx_users = $stmt->fetchAll();

foreach ($first_tx_users as $tx) {
    $uid = $tx['user_id'];
    if (!crm_already_sent($uid, 'first_tx_followup', '1')) {
        $title = "How was your first transaction? ⭐";
        $message = "We hope you enjoyed the speed! Let us know if you need any help by contacting support.";
        
        $onesignal->send_push($title, $message, $uid);
        
        if (function_exists('send_whatsapp_alert') && !empty($tx['phone'])) {
            $wa_msg = "Hi " . strtok($tx['full_name'], ' ') . " ⭐\n\nHow was your first transaction on {$site_name}?\n\nReply to this message if you need any help or have feedback for us!";
            send_whatsapp_alert($wa_msg, $tx['phone']);
        }
        
        crm_log_sent($uid, 'first_tx_followup', '1');
        echo "Sent First TX Followup to User ID: {$uid}\n<br>";
    }
}


// ---------------------------------------------------------
// OPTION 5: "Month in Review" Summaries (Run on 1st of month)
// ---------------------------------------------------------
echo "Processing Month in Review...\n<br>";
if (date('d') == '01') {
    $last_month = date('Y-m', strtotime('-1 month'));
    $start_date = date('Y-m-01 00:00:00', strtotime('-1 month'));
    $end_date = date('Y-m-t 23:59:59', strtotime('-1 month'));

    $stmt = db()->prepare("
        SELECT u.id, u.full_name, u.email, 
               SUM(t.amount) as total_spent, 
               COUNT(t.id) as total_txs
        FROM users u
        JOIN transactions t ON u.id = t.user_id
        WHERE t.status = 'success' AND t.created_at BETWEEN ? AND ?
        GROUP BY u.id
        HAVING total_txs > 0
    ");
    $stmt->execute([$start_date, $end_date]);
    $monthly_users = $stmt->fetchAll();

    foreach ($monthly_users as $u) {
        if (!crm_already_sent($u['id'], 'month_review', $last_month)) {
            $month_name = date('F', strtotime('-1 month'));
            $title = "Your $month_name with {$site_name} 📊";
            $message = "You made {$u['total_txs']} transactions last month. Check your email for your full monthly summary!";
            
            $onesignal->send_push($title, $message, $u['id']);
            
            if (function_exists('send_email')) {
                $html = "<div style='font-family:Arial;max-width:480px;margin:0 auto;padding:24px;border:1px solid #E2E8F0;border-radius:12px;'>
                    <h2 style='color:#0A2A7A;'>Your {$month_name} Summary</h2>
                    <p>Hi <strong>{$u['full_name']}</strong>,</p>
                    <p>Thank you for using {$site_name}! Here is a quick summary of your spending for {$month_name}:</p>
                    <div style='background:#F1F5F9;padding:16px;border-radius:8px;margin:20px 0;'>
                        <p style='margin:0;color:#64748B;'>Total Successful Transactions:</p>
                        <h3 style='margin:5px 0 15px 0;color:#0F172A;'>{$u['total_txs']}</h3>
                        <p style='margin:0;color:#64748B;'>Total Amount Spent:</p>
                        <h3 style='margin:5px 0 0 0;color:#0A2A7A;'>₦" . number_format($u['total_spent'], 2) . "</h3>
                    </div>
                    <p>We look forward to serving you even better this month!</p>
                </div>";
                send_email($u['email'], "Your $month_name with {$site_name}", $html);
            }
            
            crm_log_sent($u['id'], 'month_review', $last_month);
            echo "Sent Month Review to User ID: {$u['id']}\n<br>";
        }
    }
}

echo "CRM Automations Completed.\n";