<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
// Need to include the API class from user side to execute commands
require_once __DIR__.'/../user/core/virtual_card.php';

auth_admin();
$csrf = admin_csrf();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_admin_csrf($_POST['csrf'] ?? '')) {
        $msg = '<div class="save-toast err show">Invalid CSRF token</div>';
    } else {
        $action = $_POST['action'];
        $card_id = trim($_POST['card_id'] ?? '');
        
        if ($card_id) {
            $cw = new CashwyreAPI();
            try {
                if ($action === 'freeze') {
                    $r = $cw->freeze_card($card_id);
                    if ($r['success']) {
                        db()->prepare("UPDATE virtual_cards SET status='frozen' WHERE card_id=?")->execute([$card_id]);
                        $msg = '<div class="save-toast ok show">Card successfully frozen</div>';
                        audit_log($_SESSION['admin_id'], 'VIRTUAL_CARD', "Froze card $card_id");
                    } else throw new Exception($r['message'] ?? 'Action failed');
                } elseif ($action === 'unfreeze') {
                    $r = $cw->unfreeze_card($card_id);
                    if ($r['success']) {
                        db()->prepare("UPDATE virtual_cards SET status='active' WHERE card_id=?")->execute([$card_id]);
                        $msg = '<div class="save-toast ok show">Card successfully unfrozen</div>';
                        audit_log($_SESSION['admin_id'], 'VIRTUAL_CARD', "Unfroze card $card_id");
                    } else throw new Exception($r['message'] ?? 'Action failed');
                } elseif ($action === 'terminate') {
                    $r = $cw->terminate_card($card_id);
                    if ($r['success']) {
                        db()->prepare("UPDATE virtual_cards SET status='terminated' WHERE card_id=?")->execute([$card_id]);
                        $msg = '<div class="save-toast ok show">Card successfully terminated</div>';
                        audit_log($_SESSION['admin_id'], 'VIRTUAL_CARD', "Terminated card $card_id");
                    } else throw new Exception($r['message'] ?? 'Action failed');
                }
            } catch (Exception $e) {
                $msg = '<div class="save-toast err show">API Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
    }
}

// Fetch Cards
$cards = db()->query("
    SELECT v.*, u.full_name, u.email 
    FROM virtual_cards v 
    JOIN users u ON v.user_id = u.id 
    ORDER BY v.created_at DESC
")->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Virtual Cards Management – MsjPay Admin</title>
    <link rel="icon" type="image/png" href="/msjpay/assets/images/msjpay-favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/msjpay/msj-admin/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="page-content">

<div class="page-head">
    <div>
        <h2>Virtual Cards</h2>
        <p><?= count($cards) ?> total cards issued</p>
    </div>
</div>

<?=$msg?>

<div class="card">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Card Number</th>
                    <th>Brand</th>
                    <th>Expiry</th>
                    <th>Balance (USD)</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($cards as $c): 
                    $scls = ['active'=>'badge-success','frozen'=>'badge-warning','terminated'=>'badge-danger'][$c['status']] ?? 'badge-pending';
                ?>
                <tr>
                    <td>
                        <div style="font-weight:600;color:var(--navy-dark)"><?=htmlspecialchars($c['full_name'])?></div>
                        <div style="font-size:12px;color:var(--text-muted)"><?=htmlspecialchars($c['email'])?></div>
                    </td>
                    <td><code style="background:#f1f5f9;padding:4px 8px;border-radius:4px;color:#334155;font-weight:600;letter-spacing:1px"><?=htmlspecialchars($c['masked_number'])?></code></td>
                    <td><?=htmlspecialchars($c['brand'])?></td>
                    <td><?=htmlspecialchars($c['expiry'] ?: '--/--')?></td>
                    <td style="font-weight:700;color:var(--primary)">$<?=number_format((float)$c['balance'], 2)?></td>
                    <td><span class="badge <?=$scls?>"><?=ucfirst($c['status'])?></span></td>
                    <td style="font-size:12px;color:#6B7280"><?=date('d M, Y', strtotime($c['created_at']))?></td>
                    <td>
                        <?php if($c['status'] !== 'terminated'): ?>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Change status for this card?')">
                                <input type="hidden" name="csrf" value="<?=$csrf?>">
                                <input type="hidden" name="card_id" value="<?=htmlspecialchars($c['card_id'])?>">
                                <?php if($c['status'] === 'active'): ?>
                                    <input type="hidden" name="action" value="freeze">
                                    <button type="submit" class="btn-primary" style="padding:4px 8px;font-size:12px;border-radius:6px;background:#F59E0B">Freeze</button>
                                <?php else: ?>
                                    <input type="hidden" name="action" value="unfreeze">
                                    <button type="submit" class="btn-primary" style="padding:4px 8px;font-size:12px;border-radius:6px;background:#10B981">Unfreeze</button>
                                <?php endif; ?>
                            </form>
                            
                            <form method="POST" style="display:inline" onsubmit="return confirm('WARNING: Terminating a card cannot be undone and remaining funds might be lost. Proceed?')">
                                <input type="hidden" name="csrf" value="<?=$csrf?>">
                                <input type="hidden" name="card_id" value="<?=htmlspecialchars($c['card_id'])?>">
                                <input type="hidden" name="action" value="terminate">
                                <button type="submit" class="btn-primary" style="padding:4px 8px;font-size:12px;border-radius:6px;background:#EF4444;margin-left:4px;">Terminate</button>
                            </form>
                        <?php else: ?>
                            <span style="font-size:12px;color:#9CA3AF">Closed</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; if(empty($cards)): ?>
                <tr><td colspan="8" class="empty-state">No virtual cards found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div></div>
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
</script>
</body>
</html>
