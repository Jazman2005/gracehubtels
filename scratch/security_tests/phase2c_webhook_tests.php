<?php
/**
 * Phase 2C webhook security regression tests — standalone CLI script.
 * Run via: php scratch/security_tests/phase2c_webhook_tests.php
 *
 * Exercises every webhook endpoint (webhook/<provider>/index.php) over HTTP against the local
 * test vhost (http://localhost:8099), backed by the local msjdkjef_api test
 * database. Creates disposable sectest2c_<runid>_-prefixed fixtures and
 * deletes everything it created at the end regardless of pass/fail.
 * Never point BASE_URL at a real production host.
 */

declare(strict_types=1);

define('BASE_URL', 'http://localhost:8099');
require_once __DIR__ . '/../../user/core/db.php';

$RUNID = bin2hex(random_bytes(4));
$PASS = 0;
$FAIL = 0;
$FAILURES = [];
$cleanup = ['user_ids' => [], 'tx_refs' => [], 'card_ids' => [], 'va_ids' => [], 'a2c_refs' => []];

function check(string $name, bool $cond, string $detail = ''): void {
    global $PASS, $FAIL, $FAILURES;
    if ($cond) { $PASS++; echo "  [PASS] $name\n"; }
    else { $FAIL++; $FAILURES[] = $name . ($detail ? " ($detail)" : ''); echo "  [FAIL] $name" . ($detail ? " -- $detail" : '') . "\n"; }
}

function http(string $method, string $path, $data = '', array $headers = []): array {
    $ch = curl_init(BASE_URL . $path);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 15];
    if ($method === 'POST') $opts[CURLOPT_POSTFIELDS] = $data;
    if ($headers) $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'body' => substr((string)$raw, $hs)];
}

// Fires two identical POST requests as close to simultaneously as libcurl
// allows, to exercise a true race rather than two sequential calls.
function http_concurrent(string $path, string $body, array $headers): array {
    $mh = curl_multi_init();
    $handles = [];
    for ($i = 0; $i < 2; $i++) {
        $ch = curl_init(BASE_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 15,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    $running = null;
    do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running > 0);
    $results = [];
    foreach ($handles as $ch) {
        $results[] = ['code' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => curl_multi_getcontent($ch)];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $results;
}

function set_secret(string $key, string $value): void {
    db()->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?")
        ->execute([$key, $value, $value]);
}

function balance_of(int $uid): float {
    $s = db()->prepare("SELECT wallet_balance FROM users WHERE id=?"); $s->execute([$uid]); return (float)$s->fetchColumn();
}

echo "=== Phase 2C Webhook Security Test Suite (run $RUNID) ===\n\n";

try {
    $pw = 'SecTest_Aa1_' . rand(1000, 9999);
    $hash = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]);
    $ins = db()->prepare("INSERT INTO users (full_name, username, phone, email, password, transaction_pin, wallet_balance, is_active, api_token, created_at) VALUES (?,?,?,?,?,?,?,1,?,NOW())");
    $ins->execute(['SecTest2C User', "sectest2c_{$RUNID}", "081{$RUNID}01", "sectest2c_{$RUNID}@example.test", $hash, password_hash('1234', PASSWORD_BCRYPT, ['cost' => 12]), 5000, 'msj_live_' . bin2hex(random_bytes(24))]);
    $uid = (int)db()->lastInsertId();
    $cleanup['user_ids'][] = $uid;
    echo "[setup] user=$uid\n\n";

    // ==================================================================
    // 1. VTU webhook
    // ==================================================================
    echo "[1] webhook/vtu/index.php\n";
    $vtuKey = 'sectest2c_vtu_key_' . $RUNID;
    set_secret('vtu_webhook_secret', $vtuKey);
    db()->exec("DELETE FROM site_settings WHERE setting_key='vtu_webhook_hmac_secret'"); // start with HMAC not configured

    // 1a. Missing/wrong key -> rejected
    $r = http('POST', '/webhook/vtu/index.php?key=wrong', json_encode(['reference' => 'X', 'status' => 'success']));
    check("VTU: wrong static key is rejected (403)", $r['code'] === 403, "code={$r['code']}");

    $r = http('POST', '/webhook/vtu/index.php', json_encode(['reference' => 'X', 'status' => 'success']));
    check("VTU: missing key is rejected (403)", $r['code'] === 403, "code={$r['code']}");

    // 1b. Wrong transaction reference -> gracefully ignored, no crash
    $r = http('POST', "/webhook/vtu/index.php?key=$vtuKey", json_encode(['reference' => 'NOSUCHREF_' . $RUNID, 'status' => 'success']));
    $d = json_decode($r['body'], true);
    check("VTU: unknown reference returns not_found (200, no crash)", $r['code'] === 200 && ($d['status'] ?? '') === 'not_found', "code={$r['code']} body={$r['body']}");

    // 1c. Duplicate + replay + already-resolved: fund a pending airtime debit, confirm success once
    $vtuRef = 'SECT2CVTU' . $RUNID;
    db()->prepare("INSERT INTO transactions (user_id, transaction_ref, type, category, amount, balance_before, balance_after, status, created_at) VALUES (?,?, 'debit','airtime',100,5000,4900,'pending',NOW())")
        ->execute([$uid, $vtuRef]);
    $cleanup['tx_refs'][] = $vtuRef;

    $successPayload = json_encode(['reference' => $vtuRef, 'status' => 'success', 'amount' => 100]);
    $r = http('POST', "/webhook/vtu/index.php?key=$vtuKey", $successPayload);
    $d = json_decode($r['body'], true);
    check("VTU: first success webhook processed", $r['code'] === 200 && ($d['outcome'] ?? '') === 'success', "body={$r['body']}");

    $status1 = db()->prepare("SELECT status FROM transactions WHERE transaction_ref=?"); $status1->execute([$vtuRef]); $status1 = $status1->fetchColumn();
    check("VTU: transaction resolved to success", $status1 === 'success', "status={$status1}");

    // Replay the exact same success webhook again (already-resolved transaction)
    $balBeforeReplay = balance_of($uid);
    $r = http('POST', "/webhook/vtu/index.php?key=$vtuKey", $successPayload);
    check("VTU: replayed success webhook still returns 200 (idempotent, not an error)", $r['code'] === 200, "code={$r['code']}");
    $balAfterReplay = balance_of($uid);
    check("VTU: replaying a resolved success webhook does not move any money", abs($balAfterReplay - $balBeforeReplay) < 0.01, "before={$balBeforeReplay} after={$balAfterReplay}");

    // A conflicting FAILED webhook arriving after the transaction already succeeded
    // must NOT trigger a refund on top of a purchase that already went through.
    $failedPayload = json_encode(['reference' => $vtuRef, 'status' => 'failed', 'amount' => 100]);
    $r = http('POST', "/webhook/vtu/index.php?key=$vtuKey", $failedPayload);
    $balAfterConflict = balance_of($uid);
    check("VTU: stale 'failed' webhook after success does NOT refund (no double payout)", abs($balAfterConflict - $balAfterReplay) < 0.01, "before={$balAfterReplay} after={$balAfterConflict}");
    $statusFinal = db()->prepare("SELECT status FROM transactions WHERE transaction_ref=?"); $statusFinal->execute([$vtuRef]); $statusFinal = $statusFinal->fetchColumn();
    check("VTU: transaction status remains success (not overwritten to failed)", $statusFinal === 'success', "status={$statusFinal}");

    // 1d. Wrong amount ignored on refund path: provider-claimed amount must not
    // influence the refund value, which must come from OUR OWN transaction record.
    $vtuRef2 = 'SECT2CVTU2' . $RUNID;
    db()->prepare("INSERT INTO transactions (user_id, transaction_ref, type, category, amount, balance_before, balance_after, status, created_at) VALUES (?,?, 'debit','airtime',200,4900,4700,'pending',NOW())")
        ->execute([$uid, $vtuRef2]);
    $cleanup['tx_refs'][] = $vtuRef2;
    $balBeforeFail = balance_of($uid);
    $forgedFail = json_encode(['reference' => $vtuRef2, 'status' => 'failed', 'amount' => 999999]);
    $r = http('POST', "/webhook/vtu/index.php?key=$vtuKey", $forgedFail);
    $balAfterFail = balance_of($uid);
    check("VTU: refund amount uses OUR recorded amount (200), ignoring forged payload amount (999999)", abs(($balAfterFail - $balBeforeFail) - 200.0) < 0.01, "delta=" . ($balAfterFail - $balBeforeFail));

    // 1e. Concurrent webhook delivery for the same success event must only pay out once.
    $vtuRef3 = 'SECT2CVTU3' . $RUNID;
    db()->prepare("INSERT INTO transactions (user_id, transaction_ref, type, category, amount, balance_before, balance_after, status, created_at) VALUES (?,?, 'debit','airtime',150,4700,4550,'pending',NOW())")
        ->execute([$uid, $vtuRef3]);
    $cleanup['tx_refs'][] = $vtuRef3;
    $concFailPayload = json_encode(['reference' => $vtuRef3, 'status' => 'failed', 'amount' => 150]);
    $balBeforeConc = balance_of($uid);
    http_concurrent("/webhook/vtu/index.php?key=$vtuKey", $concFailPayload, ['Content-Type: application/json']);
    usleep(300000);
    $balAfterConc = balance_of($uid);
    check("VTU: two concurrent webhook deliveries for the same failed event refund exactly once", abs(($balAfterConc - $balBeforeConc) - 150.0) < 0.01, "delta=" . ($balAfterConc - $balBeforeConc));

    // 1f. Optional HMAC upgrade: once configured, a bad signature is rejected
    // even with a correct static key; a valid signature is accepted.
    $hmacSecret = 'sectest2c_vtu_hmac_' . $RUNID;
    set_secret('vtu_webhook_hmac_secret', $hmacSecret);
    $vtuRef4 = 'SECT2CVTU4' . $RUNID;
    db()->prepare("INSERT INTO transactions (user_id, transaction_ref, type, category, amount, balance_before, balance_after, status, created_at) VALUES (?,?, 'debit','airtime',50,4550,4500,'pending',NOW())")
        ->execute([$uid, $vtuRef4]);
    $cleanup['tx_refs'][] = $vtuRef4;
    $hmacPayload = json_encode(['reference' => $vtuRef4, 'status' => 'success', 'amount' => 50]);
    $r = http('POST', "/webhook/vtu/index.php?key=$vtuKey", $hmacPayload, ['X-Webhook-Signature: deadbeefdeadbeef']);
    check("VTU: correct static key but INVALID HMAC signature is rejected once HMAC is configured", $r['code'] === 403, "code={$r['code']} body={$r['body']}");
    $status4 = db()->prepare("SELECT status FROM transactions WHERE transaction_ref=?"); $status4->execute([$vtuRef4]); $status4 = $status4->fetchColumn();
    check("VTU: transaction NOT resolved by the invalid-HMAC attempt", $status4 === 'pending', "status={$status4}");

    $validSig = hash_hmac('sha256', $hmacPayload, $hmacSecret);
    $r = http('POST', "/webhook/vtu/index.php?key=$vtuKey", $hmacPayload, ['X-Webhook-Signature: ' . $validSig]);
    $d = json_decode($r['body'], true);
    check("VTU: valid HMAC signature + correct key is accepted", $r['code'] === 200 && ($d['outcome'] ?? '') === 'success', "body={$r['body']}");
    db()->exec("DELETE FROM site_settings WHERE setting_key='vtu_webhook_hmac_secret'");

    // ==================================================================
    // 2. Cashwyre webhook
    // ==================================================================
    echo "\n[2] webhook/cashwyre/index.php\n";
    $cwSecret = 'sectest2c_cashwyre_' . $RUNID;
    set_secret('cashwyre_webhook_secret', $cwSecret);

    // 2a. Invalid signature outright
    $r = http('POST', '/webhook/cashwyre/index.php', json_encode(['event' => 'card.funded', 'cardId' => 'nope', 'amount' => 10]), ['X-Cashwyre-Signature: garbage']);
    check("Cashwyre: invalid signature rejected (400)", $r['code'] === 400, "code={$r['code']}");

    // 2b. Modified payload (amount tampered after signing) -> signature no longer matches
    $cardId = 'sectest2c_card_' . $RUNID;
    db()->prepare("INSERT INTO virtual_cards (user_id, card_id, balance, status, created_at) VALUES (?,?,0,'active',NOW())")->execute([$uid, $cardId]);
    $cleanup['card_ids'][] = $cardId;
    $cwRef = 'SECT2CCW' . $RUNID;
    $desc = 'Fund virtual card ' . substr($cardId, -6) . ' with $10';
    db()->prepare("INSERT INTO transactions (user_id, transaction_ref, type, category, amount, status, balance_before, balance_after, description, created_at) VALUES (?,?, 'debit','virtual_card',1500,'pending',5000,3500,?,NOW())")
        ->execute([$uid, $cwRef, $desc]);
    $cleanup['tx_refs'][] = $cwRef;

    $origPayload = json_encode(['event' => 'card.funded', 'cardId' => $cardId, 'amount' => 10, 'id' => 'evt_tamper_' . $RUNID]);
    $sigForOriginal = hash_hmac('sha256', $origPayload, $cwSecret);
    $tamperedPayload = json_encode(['event' => 'card.funded', 'cardId' => $cardId, 'amount' => 9999, 'id' => 'evt_tamper_' . $RUNID]);
    $r = http('POST', '/webhook/cashwyre/index.php', $tamperedPayload, ['X-Cashwyre-Signature: ' . $sigForOriginal]);
    check("Cashwyre: payload modified after signing is rejected (signature mismatch)", $r['code'] === 400, "code={$r['code']}");
    $balTamper = db()->prepare("SELECT balance FROM virtual_cards WHERE card_id=?"); $balTamper->execute([$cardId]); $balTamper = (float)$balTamper->fetchColumn();
    check("Cashwyre: tampered payload did not credit the card", abs($balTamper - 0.0) < 0.01, "balance={$balTamper}");

    // 2c. Duplicate delivery of the same legitimate funding event
    $legitPayload = json_encode(['event' => 'card.funded', 'cardId' => $cardId, 'amount' => 10, 'id' => 'evt_legit_' . $RUNID]);
    $legitSig = hash_hmac('sha256', $legitPayload, $cwSecret);
    $r1 = http('POST', '/webhook/cashwyre/index.php', $legitPayload, ['X-Cashwyre-Signature: ' . $legitSig]);
    $r2 = http('POST', '/webhook/cashwyre/index.php', $legitPayload, ['X-Cashwyre-Signature: ' . $legitSig]);
    check("Cashwyre: first delivery accepted", $r1['code'] === 200);
    check("Cashwyre: exact duplicate delivery (same event id) also 200 but no extra credit", $r2['code'] === 200);
    $balDup = db()->prepare("SELECT balance FROM virtual_cards WHERE card_id=?"); $balDup->execute([$cardId]); $balDup = (float)$balDup->fetchColumn();
    check("Cashwyre: duplicate delivery did NOT double-credit (balance still 10)", abs($balDup - 10.0) < 0.01, "balance={$balDup}");

    // 2d. Status-event replay protection (new Phase 2C fix): freeze, then replay
    // an old "unfreeze" event captured earlier — a stale replay must not silently
    // re-apply once a newer legitimate event for that same id already landed, and
    // the exact same event id must not be reprocessed twice.
    $freezeEvt = json_encode(['event' => 'card.freeze', 'cardId' => $cardId, 'id' => 'evt_freeze_' . $RUNID]);
    $freezeSig = hash_hmac('sha256', $freezeEvt, $cwSecret);
    http('POST', '/webhook/cashwyre/index.php', $freezeEvt, ['X-Cashwyre-Signature: ' . $freezeSig]);
    $st1 = db()->prepare("SELECT status FROM virtual_cards WHERE card_id=?"); $st1->execute([$cardId]); $st1 = $st1->fetchColumn();
    check("Cashwyre: freeze event applied", $st1 === 'frozen', "status={$st1}");

    $unfreezeEvt = json_encode(['event' => 'card.unfreeze', 'cardId' => $cardId, 'id' => 'evt_unfreeze_' . $RUNID]);
    $unfreezeSig = hash_hmac('sha256', $unfreezeEvt, $cwSecret);
    http('POST', '/webhook/cashwyre/index.php', $unfreezeEvt, ['X-Cashwyre-Signature: ' . $unfreezeSig]);
    $st2 = db()->prepare("SELECT status FROM virtual_cards WHERE card_id=?"); $st2->execute([$cardId]); $st2 = $st2->fetchColumn();
    check("Cashwyre: 'unfrozen' event correctly sets status=active (not misparsed as 'frozen')", $st2 === 'active', "status={$st2}");

    // Replay the earlier freeze event (captured/duplicate delivery) — must be
    // ignored now that a fresh idempotency key already exists for it, leaving
    // the card's current (unfrozen) state untouched.
    http('POST', '/webhook/cashwyre/index.php', $freezeEvt, ['X-Cashwyre-Signature: ' . $freezeSig]);
    $st3 = db()->prepare("SELECT status FROM virtual_cards WHERE card_id=?"); $st3->execute([$cardId]); $st3 = $st3->fetchColumn();
    check("Cashwyre: replaying the earlier freeze event does not override the current active state", $st3 === 'active', "status={$st3}");

    // ==================================================================
    // 3. Moniepoint webhook
    // ==================================================================
    echo "\n[3] webhook/moniepoint/index.php\n";
    $mpSecret = 'sectest2c_moniepoint_' . $RUNID;
    set_secret('gw_moniepoint_secret', $mpSecret);
    set_secret('gw_moniepoint_fee_type', 'percent');
    set_secret('gw_moniepoint_fee_value', '0');

    $r = http('POST', '/webhook/moniepoint/index.php', json_encode(['eventType' => 'SUCCESSFUL_TRANSACTION', 'eventData' => ['transactionReference' => 'X', 'settlementAmount' => 100]]), ['X-Moniepoint-Signature: garbage']);
    check("Moniepoint: invalid signature rejected (400)", $r['code'] === 400, "code={$r['code']}");

    $mpAccNum = '999' . substr($RUNID, 0, 8);
    db()->prepare("INSERT INTO virtual_accounts (user_id, provider, account_number, account_name, bank_name, is_active, created_at) VALUES (?,?,?,?,?,1,NOW())")
        ->execute([$uid, 'moniepoint', $mpAccNum, 'Test User', 'Moniepoint MFB']);
    $mpVaId = (int)db()->lastInsertId(); $cleanup['va_ids'][] = $mpVaId;

    $mpTxRef = 'sectest2c_mp_tx_' . $RUNID;
    $mpPayload = json_encode(['eventType' => 'SUCCESSFUL_TRANSACTION', 'eventData' => ['transactionReference' => $mpTxRef, 'settlementAmount' => 500, 'amountPaid' => 500, 'destinationAccountInformation' => ['accountNumber' => $mpAccNum]]]);
    $mpSig = hash_hmac('sha512', $mpPayload, $mpSecret);

    $balBeforeMp = balance_of($uid);
    // Concurrent delivery of the SAME funding notification twice — this is the
    // scenario that exposes the non-atomic "SELECT then credit" pre-check race;
    // the real guard is credit_wallet()'s UNIQUE idempotency_key constraint.
    http_concurrent('/webhook/moniepoint/index.php', $mpPayload, ['Content-Type: application/json', 'X-Moniepoint-Signature: ' . $mpSig]);
    usleep(300000);
    $balAfterMp = balance_of($uid);
    check("Moniepoint: concurrent duplicate funding notifications credit exactly once", abs(($balAfterMp - $balBeforeMp) - 500.0) < 0.01, "delta=" . ($balAfterMp - $balBeforeMp));

    // Replay after already processed
    $r = http('POST', '/webhook/moniepoint/index.php', $mpPayload, ['Content-Type: application/json', 'X-Moniepoint-Signature: ' . $mpSig]);
    $balAfterReplayMp = balance_of($uid);
    check("Moniepoint: replaying the same event after processing does not credit again", abs($balAfterReplayMp - $balAfterMp) < 0.01, "delta=" . ($balAfterReplayMp - $balAfterMp));

    // ==================================================================
    // 4. PalmPay webhook
    // ==================================================================
    echo "\n[4] webhook/palmpay/index.php\n";
    $ppSecret = 'sectest2c_palmpay_' . $RUNID;
    set_secret('palmpay_notify_secret', $ppSecret);
    set_secret('palmpay_fee_type', 'percent');
    set_secret('palmpay_fee_value', '0');

    $pmOrderNo = 'pp' . $RUNID;
    db()->prepare("INSERT INTO virtual_accounts (user_id, provider, bank_code, account_number, account_name, bank_name, is_active, created_at) VALUES (?,?,?,?,?,?,1,NOW())")
        ->execute([$uid, 'palmpay', $pmOrderNo, '8880002222', 'Test User', 'PalmPay']);
    $ppVaId = (int)db()->lastInsertId(); $cleanup['va_ids'][] = $ppVaId;

    function palmpay_sign(array $data, string $secret): string {
        $d = $data; unset($d['sign']); ksort($d);
        $str = '';
        foreach ($d as $k => $v) { if ($v !== '' && $v !== null && !is_array($v)) $str .= "{$k}={$v}&"; }
        return strtoupper(hash_hmac('sha256', rtrim($str, '&'), $secret));
    }
    $ppData = ['orderStatus' => 2, 'orderNo' => $pmOrderNo, 'amount' => 100000];
    $ppData['sign'] = palmpay_sign($ppData, $ppSecret);
    $balBeforePp = balance_of($uid);
    $r1 = http('POST', '/webhook/palmpay/index.php', json_encode($ppData));
    $r2 = http('POST', '/webhook/palmpay/index.php', json_encode($ppData));
    check("PalmPay: first delivery accepted", $r1['code'] === 200);
    $balAfterPp = balance_of($uid);
    check("PalmPay: exact replay of the same order does not double-credit", abs(($balAfterPp - $balBeforePp) - 1000.0) < 0.01, "delta=" . ($balAfterPp - $balBeforePp));

    // Modified payload: tamper amount after signing. PalmPay always replies
    // 200/'success' regardless of outcome (so it doesn't retry-storm us), so
    // the response code can't prove rejection — check that no wallet credit
    // was actually recorded for this order instead.
    $ppOrderTamper = $pmOrderNo . '_tamper';
    db()->prepare("INSERT INTO virtual_accounts (user_id, provider, bank_code, account_number, account_name, bank_name, is_active, created_at) VALUES (?,?,?,?,?,?,1,NOW())")
        ->execute([$uid, 'palmpay', $ppOrderTamper, '8880002223', 'Test User', 'PalmPay']);
    $cleanup['va_ids'][] = (int)db()->lastInsertId();
    $ppData2 = ['orderStatus' => 2, 'orderNo' => $ppOrderTamper, 'amount' => 100000];
    $sigFor100k = palmpay_sign($ppData2, $ppSecret);
    $ppData2['amount'] = 900000; // tamper without re-signing
    $ppData2['sign'] = $sigFor100k;
    http('POST', '/webhook/palmpay/index.php', json_encode($ppData2));
    $tamperCredited = db()->prepare("SELECT COUNT(*) FROM transactions WHERE idempotency_key = ?");
    $tamperCredited->execute(['palmpay:' . $ppOrderTamper]);
    check("PalmPay: tampered amount (signed for a different value) was never credited", (int)$tamperCredited->fetchColumn() === 0);

    // ==================================================================
    // 5. PaymentPoint webhook
    // ==================================================================
    echo "\n[5] webhook/paymentpoint/index.php\n";
    $ptSecret = 'sectest2c_paymentpoint_' . $RUNID;
    set_secret('paymentpoint_webhook_secret', $ptSecret);
    set_secret('paymentpoint_charge_pct', '0');
    set_secret('paymentpoint_charge_cap', '0');

    $ptAccNum = 'pt' . $RUNID;
    db()->prepare("INSERT INTO virtual_accounts (user_id, provider, account_number, account_name, bank_name, is_active, created_at) VALUES (?,?,?,?,?,1,NOW())")
        ->execute([$uid, 'paymentpoint', $ptAccNum, 'Test User', '9PSB']);
    $ptVaId = (int)db()->lastInsertId(); $cleanup['va_ids'][] = $ptVaId;

    $r = http('POST', '/webhook/paymentpoint/index.php', json_encode(['notification_status' => 'payment_successful', 'transaction_status' => 'success', 'transaction_id' => 'x', 'amount_paid' => 100, 'receiver' => ['account_number' => $ptAccNum]]), ['PaymentPoint-Signature: garbage']);
    check("PaymentPoint: invalid signature rejected (400)", $r['code'] === 400, "code={$r['code']}");

    $ptTxId = 'sectest2c_pt_tx_' . $RUNID;
    $ptPayload = json_encode(['notification_status' => 'payment_successful', 'transaction_status' => 'success', 'transaction_id' => $ptTxId, 'amount_paid' => 300, 'settlement_amount' => 300, 'receiver' => ['account_number' => $ptAccNum]]);
    $ptSig = hash_hmac('sha256', $ptPayload, $ptSecret);
    $balBeforePt = balance_of($uid);
    http_concurrent('/webhook/paymentpoint/index.php', $ptPayload, ['Content-Type: application/json', 'PaymentPoint-Signature: ' . $ptSig]);
    usleep(300000);
    $balAfterPt = balance_of($uid);
    check("PaymentPoint: concurrent duplicate deliveries credit exactly once", abs(($balAfterPt - $balBeforePt) - 300.0) < 0.01, "delta=" . ($balAfterPt - $balBeforePt));

    // ==================================================================
    // 6. PayVessel webhook
    // ==================================================================
    echo "\n[6] webhook/payvessel/index.php\n";
    $pvSecret = 'sectest2c_payvessel_' . $RUNID;
    set_secret('gw_payvessel_secret', $pvSecret);
    set_secret('gw_payvessel_fee_type', 'percent');
    set_secret('gw_payvessel_fee_value', '0');

    $pvAccNum = 'pv' . $RUNID;
    db()->prepare("INSERT INTO virtual_accounts (user_id, provider, account_number, account_name, bank_name, is_active, created_at) VALUES (?,?,?,?,?,1,NOW())")
        ->execute([$uid, 'payvessel', $pvAccNum, 'Test User', 'Wema']);
    $pvVaId = (int)db()->lastInsertId(); $cleanup['va_ids'][] = $pvVaId;

    $pvRef = 'sectest2c_pv_ref_' . $RUNID;
    $pvPayload = json_encode(['order' => ['amount' => 400, 'settlement_amount' => 400], 'transaction' => ['reference' => $pvRef], 'virtualAccount' => ['virtualAccountNumber' => $pvAccNum]]);
    $pvSig = hash_hmac('sha512', $pvPayload, $pvSecret);

    $r = http('POST', '/webhook/payvessel/index.php', $pvPayload, ['Signature: garbage']);
    check("PayVessel: invalid signature rejected (400)", $r['code'] === 400, "code={$r['code']}");

    $balBeforePv = balance_of($uid);
    $r1 = http('POST', '/webhook/payvessel/index.php', $pvPayload, ['X-PayVessel-Signature: ' . $pvSig]);
    $r2 = http('POST', '/webhook/payvessel/index.php', $pvPayload, ['X-PayVessel-Signature: ' . $pvSig]);
    check("PayVessel: first delivery accepted", $r1['code'] === 200, "code={$r1['code']}");
    $balAfterPv = balance_of($uid);
    check("PayVessel: replayed delivery of the same reference does not double-credit", abs(($balAfterPv - $balBeforePv) - 400.0) < 0.01, "delta=" . ($balAfterPv - $balBeforePv));

    // ==================================================================
    // 7. Autopilot webhook
    // ==================================================================
    echo "\n[7] webhook/autopilot/index.php\n";
    $apSecret = 'sectest2c_autopilot_' . $RUNID;
    set_secret('autopilot_api_key', $apSecret);

    $r = http('POST', '/webhook/autopilot/index.php', json_encode(['event' => 'confirmed', 'reference' => 'x', 'cashAmount' => 100]), ['X-Autopilot-Signature: garbage']);
    check("Autopilot: invalid signature rejected (400)", $r['code'] === 400, "code={$r['code']}");

    $a2cRef = 'sectest2c_a2c_' . $RUNID;
    db()->prepare("INSERT INTO airtime_to_cash (user_id,transaction_ref,network,airtime_amount,cash_amount,discount_percent,sender_phone,our_number,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())")
        ->execute([$uid, $a2cRef, 'MTN', 100, 65, 65, '08099998888', '08077776666', 'pending']);
    $cleanup['a2c_refs'][] = $a2cRef;

    $apPayloadOrig = json_encode(['event' => 'confirmed', 'reference' => $a2cRef, 'cashAmount' => 65]);
    $apSigOrig = hash_hmac('sha256', $apPayloadOrig, $apSecret);
    $apPayloadTampered = json_encode(['event' => 'confirmed', 'reference' => $a2cRef, 'cashAmount' => 99999]);
    $r = http('POST', '/webhook/autopilot/index.php', $apPayloadTampered, ['X-Autopilot-Signature: ' . $apSigOrig]);
    check("Autopilot: tampered payload (signed for a different amount) is rejected", $r['code'] === 400, "code={$r['code']}");
    $st = db()->prepare("SELECT status FROM airtime_to_cash WHERE transaction_ref=?"); $st->execute([$a2cRef]); $st = $st->fetchColumn();
    check("Autopilot: tampered payload did not resolve the request", $st === 'pending', "status={$st}");

    $balBeforeAp = balance_of($uid);
    $r = http('POST', '/webhook/autopilot/index.php', $apPayloadOrig, ['X-Autopilot-Signature: ' . $apSigOrig]);
    check("Autopilot: correctly signed confirmation accepted", $r['code'] === 200, "code={$r['code']} body={$r['body']}");
    $balAfterAp = balance_of($uid);
    check("Autopilot: wallet credited the expected cash amount once", abs(($balAfterAp - $balBeforeAp) - 65.0) < 0.01, "delta=" . ($balAfterAp - $balBeforeAp));

    // Already-resolved: replay the same confirmation again
    $r = http('POST', '/webhook/autopilot/index.php', $apPayloadOrig, ['X-Autopilot-Signature: ' . $apSigOrig]);
    $balAfterReplayAp = balance_of($uid);
    check("Autopilot: replaying an already-resolved confirmation does not credit again", abs($balAfterReplayAp - $balAfterAp) < 0.01, "delta=" . ($balAfterReplayAp - $balAfterAp));

} finally {
    echo "\n[cleanup] removing test fixtures\n";
    if ($cleanup['card_ids']) {
        $in = implode(',', array_fill(0, count($cleanup['card_ids']), '?'));
        db()->prepare("DELETE FROM virtual_cards WHERE card_id IN ($in)")->execute($cleanup['card_ids']);
    }
    if ($cleanup['tx_refs']) {
        $in = implode(',', array_fill(0, count($cleanup['tx_refs']), '?'));
        db()->prepare("DELETE FROM transactions WHERE transaction_ref IN ($in)")->execute($cleanup['tx_refs']);
    }
    db()->exec("DELETE FROM transactions WHERE description LIKE '%{$RUNID}%' OR idempotency_key LIKE '%{$RUNID}%'");
    if ($cleanup['a2c_refs']) {
        $in = implode(',', array_fill(0, count($cleanup['a2c_refs']), '?'));
        db()->prepare("DELETE FROM airtime_to_cash WHERE transaction_ref IN ($in)")->execute($cleanup['a2c_refs']);
    }
    if ($cleanup['va_ids']) {
        $in = implode(',', array_fill(0, count($cleanup['va_ids']), '?'));
        db()->prepare("DELETE FROM virtual_accounts WHERE id IN ($in)")->execute($cleanup['va_ids']);
    }
    if ($cleanup['user_ids']) {
        $in = implode(',', array_fill(0, count($cleanup['user_ids']), '?'));
        db()->prepare("DELETE FROM users WHERE id IN ($in)")->execute($cleanup['user_ids']);
    }
    db()->exec("DELETE FROM webhook_idempotency WHERE idem_key LIKE '%{$RUNID}%'");
    db()->exec("DELETE FROM webhook_logs WHERE transaction_ref LIKE 'SECT2C%{$RUNID}%' OR payload LIKE '%{$RUNID}%'");
    db()->exec("DELETE FROM site_settings WHERE setting_key IN ('vtu_webhook_secret','vtu_webhook_hmac_secret','cashwyre_webhook_secret','gw_moniepoint_secret','gw_moniepoint_fee_type','gw_moniepoint_fee_value','palmpay_notify_secret','palmpay_fee_type','palmpay_fee_value','paymentpoint_webhook_secret','paymentpoint_charge_pct','paymentpoint_charge_cap','gw_payvessel_secret','gw_payvessel_fee_type','gw_payvessel_fee_value','autopilot_api_key') AND setting_value LIKE 'sectest2c_%'");
}

echo "\n=== RESULTS: $PASS passed, $FAIL failed ===\n";
if ($FAILURES) {
    echo "\nFailed checks:\n";
    foreach ($FAILURES as $f) echo " - $f\n";
}
exit($FAIL > 0 ? 1 : 0);
