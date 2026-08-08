<?php
require_once __DIR__ . '/../../user/core/db.php';
require_once __DIR__ . '/../../user/core/api_router.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$ip = get_ip();

// Shared-secret verification (same pattern as cron_secret in cron_check_vtu.php).
// Configure the secret once via site_settings and append ?key=<secret> to the
// webhook URL registered on each VTU provider's dashboard.
$webhook_secret = setting('vtu_webhook_secret', '');
$provided_key = $_GET['key'] ?? ($_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '');
if (empty($webhook_secret)) {
    log_write('webhook_vtu', 'ERROR', 'Webhook secret not configured — rejecting', ['ip' => $ip]);
    http_response_code(500);
    echo json_encode(['error' => 'Webhook secret not configured']);
    exit;
}
if (empty($provided_key) || !hash_equals($webhook_secret, (string)$provided_key)) {
    log_write('webhook_vtu', 'ERROR', 'Invalid or missing webhook key', ['ip' => $ip]);
    http_response_code(403);
    echo json_encode(['error' => 'Invalid or missing webhook key']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!$payload) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

$t = $payload['transaction'] ?? [];

$ref = $payload['reference'] ?? $payload['transaction_ref'] ?? $payload['ref'] ?? $payload['request_ref'] ?? $payload['customerReference'] ?? $payload['tx_ref'] ?? $payload['order_id'] ?? $t['request_ref'] ?? $t['reference'] ?? $t['ref'] ?? '';
$provider_ref = $payload['id'] ?? $payload['transaction_id'] ?? $payload['order_id'] ?? $payload['provider_ref'] ?? $t['reference'] ?? $t['id'] ?? '';

$status_raw = strtolower((string)($t['status'] ?? $payload['status'] ?? $t['event'] ?? $payload['event'] ?? $payload['event_type'] ?? $payload['state'] ?? ''));
$success_statuses = ['success', 'successful', 'completed', 'fulfilled', 'confirmed', 'delivered', 'processed', 'paid', 'sent', 'active'];
$failed_statuses = ['failed', 'error', 'declined', 'reversed', 'cancelled', 'refunded', 'expired', 'rejected', 'timed_out'];

$token = $t['token'] ?? $t['pin'] ?? $t['meter_token'] ?? $t['vtoken'] ?? $t['units'] ?? $payload['token'] ?? $payload['pin'] ?? $payload['vtoken'] ?? $payload['meter_token'] ?? $payload['tokens'] ?? $payload['data_pin'] ?? '';
$msg = $t['details'] ?? $t['message'] ?? $t['msg'] ?? $t['response'] ?? $payload['message'] ?? $payload['msg'] ?? $payload['response'] ?? $payload['note'] ?? '';
$amount = (float)($t['amount'] ?? $payload['amount'] ?? $payload['charged_amount'] ?? $payload['paid_amount'] ?? 0);

$service_type = $payload['service'] ?? $payload['type'] ?? $payload['category'] ?? $t['type'] ?? '';

$log = db()->prepare(
    "INSERT INTO webhook_logs (provider,event_type,transaction_ref,amount,payload,signature_valid,ip_address)
     VALUES ('vtu_webhook',?,?,?,?,1,?)"
);
$log->execute([$status_raw, $ref, $amount, $raw, $ip]);
$log_id = (int)db()->lastInsertId();

if (!$ref) {
    http_response_code(200);
    echo json_encode(['status' => 'ignored', 'reason' => 'no_reference']);
    exit;
}

$tx = db()->prepare("SELECT * FROM transactions WHERE transaction_ref=? AND status IN ('pending','processing') LIMIT 1");
$tx->execute([$ref]);
$tx_row = $tx->fetch();

if (!$tx_row && $provider_ref) {
    $tx = db()->prepare("SELECT * FROM transactions WHERE provider_ref=? AND status IN ('pending','processing') LIMIT 1");
    $tx->execute([$provider_ref]);
    $tx_row = $tx->fetch();
}

if (!$tx_row && $provider_ref) {
    $tx = db()->prepare("SELECT * FROM transactions WHERE provider_ref=? ORDER BY created_at DESC LIMIT 1");
    $tx->execute([$provider_ref]);
    $tx_row = $tx->fetch();
}

if (!$tx_row) {
    db()->prepare("UPDATE webhook_logs SET error_message='No matching pending transaction' WHERE id=?")->execute([$log_id]);
    http_response_code(200);
    echo json_encode(['status' => 'not_found']);
    exit;
}

$ref = $tx_row['transaction_ref'];

$is_success = in_array($status_raw, $success_statuses);
$is_failed = in_array($status_raw, $failed_statuses);

if ($is_success) {
    // update_tx_status() only reports true if THIS call actually moved the row out
    // of pending/processing — false means cron_check_vtu.php, cron_refund.php, or a
    // duplicate delivery of this same webhook already resolved it. Skip re-crediting
    // meta/notifications in that case so a replay can't double-notify or, worse,
    // race a concurrent refund into paying out on a transaction that just succeeded.
    $claimed = update_tx_status($ref, 'success', $provider_ref);
    if ($claimed) {
        if ($token || $msg) {
            $paths = []; $vals = [];
            if ($token) { $paths[] = "'$.token', ?"; $vals[] = $token; }
            if ($msg) { $paths[] = "'$.response', ?"; $vals[] = $msg; }
            if ($provider_ref) { $paths[] = "'$.provider_ref', ?"; $vals[] = $provider_ref; }
            if ($paths) {
                $sql = "UPDATE transactions SET meta = JSON_SET(COALESCE(meta, '{}'), " . implode(', ', $paths) . ") WHERE transaction_ref=?";
                db()->prepare($sql)->execute(array_merge($vals, [$ref]));
            }
        }
        $cat = $tx_row['category'];
        $uid = (int)$tx_row['user_id'];
        notify_user($uid, ucwords(str_replace('_', ' ', $cat)) . ' Completed', $msg ?: "Your $cat purchase was successful." . ($token ? " Token: $token" : ''), 'success', null, $tx_row['balance_before'], $tx_row['balance_after']);
    }
    db()->prepare("UPDATE webhook_logs SET processed=1 WHERE id=?")->execute([$log_id]);
    http_response_code(200);
    echo json_encode(['status' => 'processed', 'outcome' => 'success']);
    exit;
}

if ($is_failed) {
    $claimed = update_tx_status($ref, 'failed');
    if ($claimed) {
        $uid = (int)$tx_row['user_id'];
        $charge = (float)($tx_row['charge'] ?? 0);
        $amount_to_refund = (float)$tx_row['amount'] + $charge;
        $cat = $tx_row['category'];
        credit_wallet($uid, $amount_to_refund, $cat, ucwords(str_replace('_', ' ', $cat)) . ' reversal - ' . ($msg ?: 'Provider declined'), [], 'vtu_refund:' . $ref);
        notify_user($uid, ucwords(str_replace('_', ' ', $cat)) . ' Failed', $msg ?: "Your $cat transaction was not completed. Wallet refunded.", 'error', null, $tx_row['balance_before'], $tx_row['balance_after']);
    }
    db()->prepare("UPDATE webhook_logs SET processed=1 WHERE id=?")->execute([$log_id]);
    http_response_code(200);
    echo json_encode(['status' => 'processed', 'outcome' => 'failed']);
    exit;
}

db()->prepare("UPDATE webhook_logs SET error_message=? WHERE id=?")->execute(["Unknown status: $status_raw", $log_id]);
http_response_code(200);
echo json_encode(['status' => 'ignored', 'reason' => 'unknown_status', 'status_value' => $status_raw]);
