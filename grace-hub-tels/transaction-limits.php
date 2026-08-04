<?php
require_once __DIR__.'/core/auth.php';
auth_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_limits'])) {
    if (!verify_admin_csrf($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token');
    }
    
    $keys = [
        'limit_airtime_min', 'limit_airtime_max',
        'limit_betting_min', 'limit_betting_max',
        'limit_electricity_min', 'limit_electricity_max',
        'limit_transfer_min', 'limit_transfer_max',
        'limit_daily_user', 'limit_daily_agent', 'limit_daily_vendor',
        'p2p_transfer_charge_enabled', 'p2p_transfer_charge'
    ];
    
    foreach ($keys as $k) {
        if (isset($_POST[$k])) {
            $val = trim($_POST[$k]);
            setting_set($k, $val);
        } else {
            // For checkboxes
            if ($k === 'p2p_transfer_charge_enabled') {
                setting_set($k, '0');
            }
        }
    }
    
    $success_msg = "Transaction limits updated successfully!";
}

$csrf = admin_csrf();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Transaction Limits & Controls — Gracehubtels Admin</title>
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
        <h2>Transaction Limits & Controls</h2>
        <p>Set daily transaction limits, transfer limits, and internal P2P charges.</p>
    </div>
</div>

<?php if (isset($success_msg)): ?>
<div style="background:var(--success);color:#fff;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-weight:600;">
    <i class="fas fa-check-circle" style="margin-right:8px;"></i> <?= htmlspecialchars($success_msg) ?>
</div>
<?php endif; ?>

<form method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    
    <!-- Daily Global Limits -->
    <div class="data-panel" style="margin-bottom: 24px; padding: 24px; background: #fff; border-radius: 16px; border: 1px solid #e2e8f0;">
        <h3 style="margin-top: 0; margin-bottom: 20px; color: #1e293b;"><i class="fas fa-calendar-day" style="color:var(--primary);margin-right:8px;"></i> Global Daily Limits</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
            <div class="form-group">
                <label style="display:block;margin-bottom:8px;font-weight:600;font-size:14px;">User Tier Limit (&#8358;)</label>
                <input type="number" name="limit_daily_user" value="<?= htmlspecialchars(setting('limit_daily_user', '50000')) ?>" required style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px;">
            </div>
            <div class="form-group">
                <label style="display:block;margin-bottom:8px;font-weight:600;font-size:14px;">Agent Tier Limit (&#8358;)</label>
                <input type="number" name="limit_daily_agent" value="<?= htmlspecialchars(setting('limit_daily_agent', '200000')) ?>" required style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px;">
            </div>
            <div class="form-group">
                <label style="display:block;margin-bottom:8px;font-weight:600;font-size:14px;">Vendor Tier Limit (&#8358;)</label>
                <input type="number" name="limit_daily_vendor" value="<?= htmlspecialchars(setting('limit_daily_vendor', '1000000')) ?>" required style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px;">
            </div>
        </div>
    </div>

    <!-- Airtime specific limits -->
    <div class="data-panel" style="margin-bottom: 24px; padding: 24px; background: #fff; border-radius: 16px; border: 1px solid #e2e8f0;">
        <h3 style="margin-top: 0; margin-bottom: 20px; color: #1e293b;"><i class="fas fa-mobile-alt" style="color:var(--warning);margin-right:8px;"></i> Airtime Specific Limits</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
            <div class="form-group">
                <label style="display:block;margin-bottom:8px;font-weight:600;font-size:14px;">Minimum per Transaction (&#8358;)</label>
                <input type="number" name="limit_airtime_min" value="<?= htmlspecialchars(setting('limit_airtime_min', '50')) ?>" required style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px;">
            </div>
            <div class="form-group">
                <label style="display:block;margin-bottom:8px;font-weight:600;font-size:14px;">Maximum per Transaction (&#8358;)</label>
                <input type="number" name="limit_airtime_max" value="<?= htmlspecialchars(setting('limit_airtime_max', '10000')) ?>" required style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px;">
            </div>
        </div>
    </div>
    
    <!-- Betting specific limits -->
    <div class="data-panel" style="margin-bottom: 24px; padding: 24px; background: #fff; border-radius: 16px; border: 1px solid #e2e8f0;">
        <h3 style="margin-top: 0; margin-bottom: 20px; color: #1e293b;"><i class="fas fa-futbol" style="color:var(--success);margin-right:8px;"></i> Betting Funding Limits</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
            <div class="form-group">
                <label style="display:block;margin-bottom:8px;font-weight:600;font-size:14px;">Minimum per Transaction (&#8358;)</label>
                <input type="number" name="limit_betting_min" value="<?= htmlspecialchars(setting('limit_betting_min', '100')) ?>" required style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px;">
            </div>
            <div class="form-group">
                <label style="display:block;margin-bottom:8px;font-weight:600;font-size:14px;">Maximum per Transaction (&#8358;)</label>
                <input type="number" name="limit_betting_max" value="<?= htmlspecialchars(setting('limit_betting_max', '500000')) ?>" required style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px;">
            </div>
        </div>
    </div>
    
    <!-- Electricity specific limits -->
    <div class="data-panel" style="margin-bottom: 24px; padding: 24px; background: #fff; border-radius: 16px; border: 1px solid #e2e8f0;">
        <h3 style="margin-top: 0; margin-bottom: 20px; color: #1e293b;"><i class="fas fa-bolt" style="color:#eab308;margin-right:8px;"></i> Electricity Token Limits</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
            <div class="form-group">
                <label style="display:block;margin-bottom:8px;font-weight:600;font-size:14px;">Minimum per Transaction (&#8358;)</label>
                <input type="number" name="limit_electricity_min" value="<?= htmlspecialchars(setting('limit_electricity_min', '1000')) ?>" required style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px;">
            </div>
            <div class="form-group">
                <label style="display:block;margin-bottom:8px;font-weight:600;font-size:14px;">Maximum per Transaction (&#8358;)</label>
                <input type="number" name="limit_electricity_max" value="<?= htmlspecialchars(setting('limit_electricity_max', '50000')) ?>" required style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px;">
            </div>
        </div>
    </div>

    <!-- Transfer & Payout Limits -->
    <div class="data-panel" style="margin-bottom: 24px; padding: 24px; background: #fff; border-radius: 16px; border: 1px solid #e2e8f0;">
        <h3 style="margin-top: 0; margin-bottom: 20px; color: #1e293b;"><i class="fas fa-exchange-alt" style="color:#3b82f6;margin-right:8px;"></i> Transfer & Payout Limits</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
            <div class="form-group">
                <label style="display:block;margin-bottom:8px;font-weight:600;font-size:14px;">Minimum Transfer (&#8358;)</label>
                <input type="number" name="limit_transfer_min" value="<?= htmlspecialchars(setting('limit_transfer_min', '1000')) ?>" required style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px;">
            </div>
            <div class="form-group">
                <label style="display:block;margin-bottom:8px;font-weight:600;font-size:14px;">Maximum Transfer (&#8358;)</label>
                <input type="number" name="limit_transfer_max" value="<?= htmlspecialchars(setting('limit_transfer_max', '500000')) ?>" required style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px;">
            </div>
        </div>
        <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 20px 0;">
        <h4 style="margin-top: 0; margin-bottom: 15px; color: #1e293b;">Internal (P2P) Transfer Fee</h4>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
            <div class="form-group" style="display:flex;align-items:center;">
                <label style="display:flex;align-items:center;gap:10px;font-weight:600;font-size:14px;cursor:pointer;">
                    <input type="checkbox" name="p2p_transfer_charge_enabled" value="1" <?= setting('p2p_transfer_charge_enabled', '1') === '1' ? 'checked' : '' ?> style="width:20px;height:20px;accent-color:var(--primary);">
                    Enable P2P Charge
                </label>
            </div>
            <div class="form-group">
                <label style="display:block;margin-bottom:8px;font-weight:600;font-size:14px;">P2P Charge Amount (&#8358;)</label>
                <input type="number" name="p2p_transfer_charge" value="<?= htmlspecialchars(setting('p2p_transfer_charge', '50')) ?>" required style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px;">
            </div>
        </div>
    </div>

    <div>
        <button type="submit" name="save_limits" style="background:var(--primary);color:#fff;border:none;padding:12px 24px;border-radius:8px;font-weight:700;font-size:15px;cursor:pointer;box-shadow:0 4px 12px rgba(79,70,229,0.3);">
            <i class="fas fa-save" style="margin-right:8px;"></i> Save Limits Configuration
        </button>
    </div>
</form>

</div></div>
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
</script>
</body>
</html>

