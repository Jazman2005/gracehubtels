<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';

auth_admin();
// Exposes recipient phone numbers and message content — same permission
// used to gate the messaging-hub itself.
require_permission('manage_messaging');

$history = db()->query("
    SELECT h.*, u.full_name, u.email 
    FROM bulk_sms_history h 
    JOIN users u ON h.user_id = u.id 
    ORDER BY h.created_at DESC
")->fetchAll();

$total_spent = 0;
$total_msgs = 0;
foreach($history as $h) {
    if ($h['status'] === 'success') {
        $total_spent += (float)$h['total_cost'];
        $total_msgs += (int)$h['recipient_count'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Bulk SMS History — Gracehubtels Admin</title>
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
        <h2>Bulk SMS History</h2>
        <p>Audit all outbound SMS campaigns</p>
    </div>
</div>

<div class="kpi-grid">
    <div class="data-panel kpi-card">
        <div class="kpi-icon orange"><i class="fas fa-comment-sms"></i></div>
        <div class="kpi-info">
            <div class="kpi-label">Total SMS Sent</div>
            <div class="kpi-value"><?=number_format($total_msgs)?></div>
        </div>
    </div>
    <div class="data-panel kpi-card">
        <div class="kpi-icon green"><i class="fas fa-sack-dollar"></i></div>
        <div class="kpi-info">
            <div class="kpi-label">Total Campaign Value</div>
            <div class="kpi-value">₦<?=number_format($total_spent, 2)?></div>
        </div>
    </div>
    <div class="data-panel kpi-card">
        <div class="kpi-icon gray"><i class="fas fa-list"></i></div>
        <div class="kpi-info">
            <div class="kpi-label">Total Campaigns</div>
            <div class="kpi-value"><?=number_format(count($history))?></div>
        </div>
    </div>
</div>

<div class="data-panel">
    <div class="responsive-grid-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Sender ID</th>
                    <th>Recipients</th>
                    <th>Pages</th>
                    <th>Total Cost</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($history as $h): 
                    $scls = ['success'=>'badge-success','failed'=>'badge-danger'][$h['status']] ?? 'badge-pending';
                    $msg_preview = htmlspecialchars(mb_substr($h['message'], 0, 50)) . (mb_strlen($h['message']) > 50 ? '...' : '');
                ?>
                <tr>
                    <td>
                        <div style="font-weight:600;color:var(--navy-dark)"><?=htmlspecialchars($h['full_name'])?></div>
                        <div style="font-size:12px;color:var(--text-muted)"><?=htmlspecialchars($h['email'])?></div>
                    </td>
                    <td><code style="background:#f1f5f9;padding:4px 8px;border-radius:4px;color:#334155;font-size:12px;font-weight:bold;"><?=htmlspecialchars($h['sender_id'])?></code></td>
                    <td style="font-weight:600;color:var(--navy-dark)"><?=number_format($h['recipient_count'])?></td>
                    <td><?=number_format($h['page_count'])?></td>
                    <td style="font-weight:700;color:var(--navy-dark)">₦<?=number_format((float)$h['total_cost'], 2)?></td>
                    <td><span class="badge <?=$scls?>"><?=ucfirst($h['status'])?></span></td>
                    <td style="font-size:12px;color:#6B7280"><?=date('d M, Y H:i', strtotime($h['created_at']))?></td>
                    <td>
                        <button class="btn-primary" style="padding:4px 8px;font-size:12px;border-radius:6px;background:var(--navy-light)" onclick="viewMsg(this)" data-msg="<?=htmlspecialchars($h['message'])?>" data-phones="<?=htmlspecialchars($h['recipients'])?>">View</button>
                    </td>
                </tr>
                <?php endforeach; if(empty($history)): ?>
                <tr><td colspan="8" class="empty-state">No bulk SMS history found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- View Message Modal -->
<div id="msgModalOverlay" class="modal-overlay" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:999;align-items:center;justify-content:center;">
  <div class="modal-card" style="background:#fff;padding:24px;border-radius:12px;width:90%;max-width:500px;box-shadow:0 10px 25px rgba(0,0,0,0.2);">
    <h3 style="margin-bottom:16px;color:var(--navy-dark)">Campaign Details</h3>
    <div style="margin-bottom:16px;">
        <label style="font-size:12px;font-weight:bold;color:var(--text-muted)">Message:</label>
        <div id="modalMsg" style="background:#f8fafc;padding:12px;border-radius:8px;font-size:13px;color:#334155;border:1px solid #e2e8f0;white-space:pre-wrap;"></div>
    </div>
    <div style="margin-bottom:20px;">
        <label style="font-size:12px;font-weight:bold;color:var(--text-muted)">Recipients:</label>
        <div id="modalPhones" style="background:#f8fafc;padding:12px;border-radius:8px;font-size:13px;color:#334155;border:1px solid #e2e8f0;max-height:100px;overflow-y:auto;word-break:break-all;"></div>
    </div>
    <div style="display:flex;justify-content:flex-end;">
      <button type="button" class="btn-primary" style="background:var(--navy-light)" onclick="document.getElementById('msgModalOverlay').style.display='none'">Close</button>
    </div>
  </div>
</div>

</div></div>
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
function viewMsg(btn) {
    document.getElementById('modalMsg').textContent = btn.getAttribute('data-msg');
    document.getElementById('modalPhones').textContent = btn.getAttribute('data-phones');
    document.getElementById('msgModalOverlay').style.display = 'flex';
}
</script>
</body>
</html>
