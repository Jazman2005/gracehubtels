<?php
// webhook/autopilot/index.php
// Receives automatic confirmation from Autopilot NG when airtime is verified
require_once __DIR__ . '/../../user/core/db.php';
require_once __DIR__ . '/../../user/core/airtime_to_cash.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$ip = get_ip();

$sig = $_SERVER['HTTP_X_AUTOPILOT_SIGNATURE'] ?? $_SERVER['HTTP_X_SIGNATURE'] ?? '';
$secret = setting('autopilot_api_key');

$valid = false;
if (!empty($secret) && !empty($sig)) {
    $expected = hash_hmac('sha256', $raw, $secret);
    $valid = hash_equals($expected, $sig);
}

$payload = json_decode($raw, true);
$event = $payload['event'] ?? $payload['status'] ?? '';
$tx_ref = $payload['reference'] ?? $payload['transaction_ref'] ?? $payload['ref'] ?? $payload['request_ref'] ?? $payload['customerReference'] ?? $payload['tx_ref'] ?? '';
$amount = (float)($payload['amount'] ?? $payload['cashAmount'] ?? 0);

db()->prepare(
    "INSERT INTO webhook_logs (provider,event_type,transaction_ref,amount,payload,signature_valid,ip_address)
     VALUES ('autopilot',?,?,?,?,?,?)"
)->execute([$event, $tx_ref, $amount, $raw, (int)$valid, $ip]);

if (!$valid) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

if (!$payload || !$tx_ref) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

// Only process confirmed/verified events
$confirmed_events = ['airtime_verified', 'confirmed', 'success', 'completed'];
if (!in_array(strtolower($event), $confirmed_events)) {
    http_response_code(200);
    echo json_encode(['status' => 'ignored', 'event' => $event]);
    exit;
}

// Look up the pending A2C request
$req = db()->prepare("SELECT * FROM airtime_to_cash WHERE transaction_ref=? AND status IN ('pending','processing') LIMIT 1");
$req->execute([$tx_ref]);
$row = $req->fetch();

if (!$row) {
    // Try to find by api_ref stored in provider_response. $tx_ref here can be
    // Autopilot's own external reference rather than our internal one, so
    // the match is fuzzy (LIKE) -- but credit_confirmed() below does an
    // EXACT match on transaction_ref, so the lookup must be re-pointed at
    // the matched row's real internal ref, otherwise this whole fallback
    // path silently can never succeed (it previously still passed the
    // original, non-matching $tx_ref through).
    $fallback = db()->prepare("SELECT * FROM airtime_to_cash WHERE provider_response LIKE ? AND status IN ('pending','processing') LIMIT 1");
    $fallback->execute(['%' . $tx_ref . '%']);
    $row = $fallback->fetch();
    if ($row) {
        $tx_ref = $row['transaction_ref'];
    }
}
if (!$row) {
    $err = 'No matching pending A2C request. ref=' . $tx_ref;
    db()->prepare("UPDATE webhook_logs SET error_message=? WHERE transaction_ref=? ORDER BY id DESC LIMIT 1")
        ->execute([$err, $tx_ref]);
    http_response_code(200);
    echo json_encode(['status' => 'not_found', 'ref' => $tx_ref]);
    exit;
}

// Use the cash amount confirmed by Autopilot only if it's reasonably close to
// what the platform itself calculated when the request was submitted —
// previously an inflated/forged cashAmount in the payload was credited
// verbatim with no ceiling at all, so a compromised/leaked signing secret
// (or an Autopilot-side bug) could have paid out an arbitrary amount for a
// single small pending request. A modest tolerance still allows for minor
// legitimate rate drift between submission and confirmation.
$expected_cash = (float)$row['cash_amount'];
$final_amount = $expected_cash;
if ($amount > 0) {
    $tolerance = max($expected_cash * 0.10, 5);
    if (abs($amount - $expected_cash) <= $tolerance) {
        $final_amount = $amount;
    } else {
        log_error('webhook_autopilot', 'Confirmed cashAmount deviates too far from the platform-calculated amount — using the platform value instead', ['ref' => $tx_ref, 'expected' => $expected_cash, 'received' => $amount]);
    }
}

$svc = new AirtimeToCashService();
$ok = $svc->credit_confirmed($tx_ref, $final_amount);

if ($ok) {
    db()->prepare("UPDATE webhook_logs SET processed=1 WHERE transaction_ref=? ORDER BY id DESC LIMIT 1")->execute([$tx_ref]);
}

http_response_code(200);
echo json_encode(['status' => $ok ? 'processed' : 'failed']);
