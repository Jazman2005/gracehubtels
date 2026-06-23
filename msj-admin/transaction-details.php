<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_admin();
$csrf=admin_csrf();

$ref = $_GET['ref'] ?? '';
if(!$ref) {
    header('Location: /msjpay/msj-admin/transactions-management.php');
    exit;
}

$msg = '';

// Handle Status Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_tx_status') {
    if (!verify_admin_csrf($_POST['csrf'] ?? '')) {
        $msg = '<div class="save-toast err show">Invalid CSRF token</div>';
    } else {
        $status_action = $_POST['status_action'] ?? '';
        
        if ($status_action) {
            $db = db();
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("SELECT * FROM transactions WHERE transaction_ref = ? FOR UPDATE");
                $stmt->execute([$ref]);
                $tx = $stmt->fetch();
                
                if (!$tx) throw new RuntimeException("Transaction not found.");
                
                $uid = (int)$tx['user_id'];
                $amt = (float)$tx['amount'];
                
                if ($status_action === 'fail_only') {
                    $db->prepare("UPDATE transactions SET status = 'failed' WHERE transaction_ref = ?")->execute([$ref]);
                    audit_log($_SESSION['admin_id'], 'UPDATE_TX', "Marked TX $ref as Failed.");
                } elseif ($status_action === 'fail_refund') {
                    $db->prepare("UPDATE transactions SET status = 'failed' WHERE transaction_ref = ?")->execute([$ref]);
                    $res = credit_wallet($uid, $amt, 'refund', 'Refund for failed transaction: ' . $ref);
                    if (!$res['success']) throw new RuntimeException("Refund failed: " . $res['message']);
                    audit_log($_SESSION['admin_id'], 'UPDATE_TX', "Marked TX $ref as Failed and Refunded N$amt.");
                } elseif ($status_action === 'success_only') {
                    $db->prepare("UPDATE transactions SET status = 'success' WHERE transaction_ref = ?")->execute([$ref]);
                    audit_log($_SESSION['admin_id'], 'UPDATE_TX', "Marked TX $ref as Success.");
                } elseif ($status_action === 'success_debit') {
                    $db->prepare("UPDATE transactions SET status = 'success' WHERE transaction_ref = ?")->execute([$ref]);
                    $res = debit_wallet($uid, $amt, 'admin_debit', 'Debit for successful transaction: ' . $ref);
                    if (!$res['success']) throw new RuntimeException("Debit failed: " . $res['message']);
                    audit_log($_SESSION['admin_id'], 'UPDATE_TX', "Marked TX $ref as Success and Debited N$amt.");
                }
                
                $db->commit();
                $msg = '<div class="save-toast ok show">Transaction updated successfully</div>';
            } catch (Exception $e) {
                $db->rollBack();
                $msg = '<div class="save-toast err show">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
    }
}

// Fetch Transaction Data joined with User
$stmt = db()->prepare("
    SELECT t.*, u.full_name, u.email, u.phone 
    FROM transactions t 
    JOIN users u ON t.user_id = u.id 
    WHERE t.transaction_ref = ?
");
$stmt->execute([$ref]);
$tx = $stmt->fetch();

if(!$tx) {
    echo "Transaction not found.";
    exit;
}

$scls=['success'=>'badge-success','pending'=>'badge-pending','failed'=>'badge-danger','reversed'=>'badge-warning'][$tx['status']]??'badge-pending';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Transaction Details – MsjPay Admin</title>
    <link rel="icon" type="image/png" href="/msjpay/assets/images/msjpay-favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/msjpay/msj-admin/css/admin.css">
    <style>
        .detail-row {
            display: flex;
            padding: 16px 0;
            border-bottom: 1px solid var(--border-light);
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            width: 200px;
            font-weight: 600;
            color: var(--text-muted);
            flex-shrink: 0;
        }
        .detail-value {
            flex: 1;
            font-weight: 500;
            color: var(--navy-dark);
            word-break: break-word;
        }
        .json-block {
            background: #f8fafc;
            padding: 16px;
            border-radius: 8px;
            font-family: monospace;
            font-size: 13px;
            overflow-x: auto;
            border: 1px solid var(--border-light);
            white-space: pre-wrap;
            color: #334155;
        }
    </style>
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="page-content">

<div class="page-head" style="display:flex; justify-content:space-between; align-items:center;">
  <div>
      <h2>Transaction Details</h2>
      <p>Reference: <?=htmlspecialchars($tx['transaction_ref'])?></p>
  </div>
  <a href="/msjpay/msj-admin/transactions-management.php" class="btn-primary" style="background:var(--navy-light);color:var(--text);text-decoration:none;">&larr; Back to Transactions</a>
</div>

<?=$msg?>

<div class="card" style="margin-bottom: 24px;">
  <div class="card-header">
    <h3>Overview</h3>
  </div>
  <div style="padding: 0 24px;">
      <div class="detail-row">
          <div class="detail-label">By User:</div>
          <div class="detail-value">
              <strong><?=htmlspecialchars($tx['full_name'])?></strong> 
              <span style="color:var(--text-muted)">(<?=htmlspecialchars($tx['email'])?>)</span>
          </div>
      </div>
      <div class="detail-row">
          <div class="detail-label">Transaction No:</div>
          <div class="detail-value"><code><?=htmlspecialchars($tx['transaction_ref'])?></code></div>
      </div>
      <div class="detail-row">
          <div class="detail-label">Service Category:</div>
          <div class="detail-value"><?=ucwords(str_replace('_',' ',$tx['category']))?></div>
      </div>
      <div class="detail-row">
          <div class="detail-label">Description:</div>
          <div class="detail-value"><?=htmlspecialchars($tx['description']??'—')?></div>
      </div>
      <div class="detail-row">
          <div class="detail-label">Amount:</div>
          <div class="detail-value" style="font-weight:700; font-size:16px;">₦<?=number_format((float)$tx['amount'],2)?></div>
      </div>
      <div class="detail-row">
          <div class="detail-label">Old Balance:</div>
          <div class="detail-value">₦<?=number_format((float)$tx['balance_before'],2)?></div>
      </div>
      <div class="detail-row">
          <div class="detail-label">New Balance:</div>
          <div class="detail-value">₦<?=number_format((float)$tx['balance_after'],2)?></div>
      </div>
      <div class="detail-row">
          <div class="detail-label">Status:</div>
          <div class="detail-value"><span class="badge <?=$scls?>"><?=ucfirst($tx['status'])?></span></div>
      </div>
      <div class="detail-row">
          <div class="detail-label">Date:</div>
          <div class="detail-value"><?=date('d M Y h:i A',strtotime($tx['created_at']))?></div>
      </div>
      <div class="detail-row" style="flex-direction:column; align-items:flex-start;">
          <div class="detail-label" style="margin-bottom:8px; width:100%;">API Logs & Meta Data:</div>
          <div class="detail-value" style="width:100%;">
              <div style="margin-bottom:8px;font-size:13px;color:var(--text-muted)">
                  Request Was Done From IP Address: <strong><?=htmlspecialchars($tx['ip_address']??'System')?></strong>
              </div>
              <div class="json-block"><?=htmlspecialchars($tx['meta']??'No API logs recorded.')?></div>
          </div>
      </div>
  </div>
</div>

<div class="card" style="border: 1px solid var(--primary); box-shadow: 0 4px 12px rgba(255, 90, 31, 0.1);">
  <div class="card-header" style="background:rgba(255, 90, 31, 0.05); border-bottom:1px solid rgba(255, 90, 31, 0.2);">
    <h3 style="color:var(--primary);">Change Transaction Status</h3>
  </div>
  <div style="padding: 24px;">
      <form method="POST">
          <input type="hidden" name="csrf" value="<?=$csrf?>">
          <input type="hidden" name="action" value="edit_tx_status">
          
          <div class="form-group" style="margin-bottom:20px; max-width: 400px;">
              <select name="status_action" class="form-control" required>
                  <option value="">-- Select Action --</option>
                  <option value="fail_only">Mark as Failed (No Wallet Change)</option>
                  <option value="fail_refund">Mark as Failed & Refund User Wallet</option>
                  <option value="success_only">Mark as Success (No Wallet Change)</option>
                  <option value="success_debit">Mark as Success & Debit User Wallet</option>
              </select>
          </div>
          
          <button type="submit" class="btn-primary" style="background:var(--primary); padding: 12px 24px; font-weight:700;">Update Status</button>
      </form>
  </div>
</div>

</div>
</div>
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
</script>
</body>
</html>
