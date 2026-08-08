<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_admin();
$csrf=admin_csrf();

$ref = $_GET['ref'] ?? '';
if(!$ref) {
    header('Location: ' . ADMIN_URL . '/transactions-management.php');
    exit;
}

$msg = '';

// Handle Status Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_tx_status') {
    // Overriding a transaction's status — and, for fail_refund/success_debit,
    // crediting or debiting the user's wallet directly — is real money movement
    // on an arbitrary transaction. Requires the same permission Staff Management
    // already defines for exactly this ("Edit/Refund Txns").
    if (!has_permission('manage_transactions')) {
        $msg = '<div class="save-toast err show">Missing permission: manage_transactions</div>';
    } elseif (!verify_admin_csrf($_POST['csrf'] ?? '')) {
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

                // The SELECT ... FOR UPDATE above does serialize truly
                // simultaneous submissions (the second blocks until the first
                // commits), but without this check it would then still
                // blindly re-apply the action once unblocked — e.g. two
                // admin clicks on "Refund", or a click landing right after a
                // webhook/cron already resolved this same ref, would credit
                // the wallet a second time on top of the first. Only allow
                // these transitions from a not-yet-final state.
                if (!in_array($tx['status'], ['pending', 'processing'], true)) {
                    throw new RuntimeException("Transaction already resolved (current status: {$tx['status']}) — refusing to re-apply.");
                }

                $uid = (int)$tx['user_id'];
                $amt = (float)$tx['amount'];

                if ($status_action === 'fail_only') {
                    $db->prepare("UPDATE transactions SET status = 'failed' WHERE transaction_ref = ?")->execute([$ref]);
                    audit_log($_SESSION['admin_id'], 'UPDATE_TX', "Marked TX $ref as Failed.");
                } elseif ($status_action === 'fail_refund') {
                    $db->prepare("UPDATE transactions SET status = 'failed' WHERE transaction_ref = ?")->execute([$ref]);
                    $res = credit_wallet($uid, $amt, 'refund', 'Refund for failed transaction: ' . $ref, [], 'admin_refund:'.$ref);
                    if (!$res['success']) throw new RuntimeException("Refund failed: " . $res['message']);
                    audit_log($_SESSION['admin_id'], 'UPDATE_TX', "Marked TX $ref as Failed and Refunded N$amt.");
                } elseif ($status_action === 'success_only') {
                    $db->prepare("UPDATE transactions SET status = 'success' WHERE transaction_ref = ?")->execute([$ref]);
                    audit_log($_SESSION['admin_id'], 'UPDATE_TX', "Marked TX $ref as Success.");
                } elseif ($status_action === 'success_debit') {
                    $db->prepare("UPDATE transactions SET status = 'success' WHERE transaction_ref = ?")->execute([$ref]);
                    $res = debit_wallet($uid, $amt, 'admin_debit', 'Debit for successful transaction: ' . $ref);
                    if (!$res['success']) throw new RuntimeException("Debit failed: " . $res['message']);
                    update_tx_status($res['ref'], 'success');
                    audit_log($_SESSION['admin_id'], 'UPDATE_TX', "Marked TX $ref as Success and Debited N$amt.");
                }
                
                $db->commit();
                $msg = '<div class="save-toast ok show">Transaction updated successfully</div>';
            } catch (Exception $e) {
                $db->rollBack();
                error_log('Transaction update error: '.$e->getMessage()); $msg = '<div class="save-toast err show">Error: Could not update transaction</div>';
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
    <title>Transaction Details — Gracehubtels Admin</title>
    <link rel="icon" type="image/png" href="/assets/images/gracehubtels-favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css">
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
<div class="admin-fluid-container">

<div class="section-header" style="display:flex; justify-content:space-between; align-items:center;">
  <div>
      <h2>Transaction Details</h2>
      <p>Reference: <?=htmlspecialchars($tx['transaction_ref'])?></p>
  </div>
  <a href="<?= ADMIN_URL ?>/transactions-management.php" class="btn-primary" style="background:var(--navy-light);color:var(--text);text-decoration:none;">&larr; Back to Transactions</a>
</div>

<?=$msg?>

<div class="data-panel" >
  <div class="panel-title-bar">
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
          <div class="detail-value"><?=htmlspecialchars($tx['description']??'&mdash;')?></div>
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
          <div class="detail-label">API Provider:</div>
          <div class="detail-value"><?=htmlspecialchars($tx['provider'] ?: 'None/Wallet')?></div>
      </div>
      <div class="detail-row">
          <div class="detail-label">Date:</div>
          <div class="detail-value"><?=date('d M Y h:i A',strtotime($tx['created_at']))?></div>
      </div>
<?php
// Find real API log for this transaction
$real_api_log = '';
$failure_reason = '';
$log_dir = dirname(__DIR__) . '/logs';
if (is_dir($log_dir)) {
    $files = glob($log_dir . '/api_*.log');
    if ($files) {
        // Search backwards to get the most recent logs first if needed, 
        // or just scan sequentially
        foreach ($files as $file) {
            if (is_readable($file)) {
                $content = file_get_contents($file);
                if (strpos($content, $tx['transaction_ref']) !== false) {
                    // Extract only lines containing the ref
                    $lines = explode("\n", $content);
                    foreach ($lines as $line) {
                        if (strpos($line, $tx['transaction_ref']) !== false) {
                            $real_api_log .= $line . "\n";
                            
                            // Extract failure reason if failed
                            if ($tx['status'] === 'failed' || $tx['status'] === 'reversed') {
                                preg_match('/RES:(\{.*?\})/', $line, $matches);
                                if (!empty($matches[1])) {
                                    $resData = json_decode($matches[1], true);
                                    if ($resData && isset($resData['message'])) {
                                        $failure_reason = is_string($resData['message']) ? $resData['message'] : json_encode($resData['message']);
                                    } elseif ($resData && isset($resData['error'])) {
                                        $failure_reason = is_string($resData['error']) ? $resData['error'] : json_encode($resData['error']);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}
?>
      <div class="detail-row" style="flex-direction:column; align-items:flex-start;">
          <div class="detail-label" style="margin-bottom:8px; width:100%;">API Logs & Meta Data:</div>
          <div class="detail-value" style="width:100%;">
              <div style="margin-bottom:8px;font-size:13px;color:var(--text-muted)">
                  Request Was Done From IP Address: <strong><?=htmlspecialchars($tx['ip_address']??'System')?></strong>
              </div>
              
              <?php if($failure_reason): ?>
              <div style="margin: 10px 0; padding: 12px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; color: #991b1b; font-size: 14px;">
                  <strong style="display:block; margin-bottom:4px; font-size:12px; text-transform:uppercase;">API Failure Reason:</strong>
                  <?=htmlspecialchars($failure_reason)?>
              </div>
              <?php endif; ?>
              
              <h4 style="font-size:12px; margin: 10px 0 4px; color:var(--navy-dark); text-transform:uppercase;">Meta Data</h4>
              <div class="json-block"><?=htmlspecialchars($tx['meta']??'No meta data recorded.')?></div>
              
              <h4 style="font-size:12px; margin: 16px 0 4px; color:var(--navy-dark); text-transform:uppercase;">Real Provider API Log</h4>
              <?php if($real_api_log): ?>
              <div class="json-block" style="background:#1e293b; color:#a5b4fc; border-color:#334155;"><?=htmlspecialchars(trim($real_api_log))?></div>
              <?php else: ?>
              <div class="json-block" style="background:#f1f5f9; color:#94a3b8; border-style:dashed;">No API requests logged for this transaction yet.</div>
              <?php endif; ?>
          </div>
      </div>
  </div>
</div>

<div class="data-panel" style="border: 1px solid var(--primary); box-shadow: 0 4px 12px rgba(255, 90, 31, 0.1);">
  <div class="panel-title-bar" style="background:rgba(255, 90, 31, 0.05); border-bottom:1px solid rgba(255, 90, 31, 0.2);">
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
