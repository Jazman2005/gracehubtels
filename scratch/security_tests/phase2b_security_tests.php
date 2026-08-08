<?php
/**
 * Phase 2B security regression tests — standalone CLI script (no test
 * framework exists in this repo). Run via:
 *   php scratch/security_tests/phase2b_security_tests.php
 *
 * Creates disposable test fixtures (users/admin prefixed with
 * sectest2b_<runid>_), exercises the live local app over HTTP against
 * http://localhost:8099/ (a temporary root-level vhost matching production
 * URL structure), and deletes every fixture it created at the end
 * regardless of pass/fail. Never point BASE_URL at a real production host.
 */

declare(strict_types=1);

define('BASE_URL', 'http://localhost:8099');
require_once __DIR__ . '/../../user/core/db.php';

$RUNID = bin2hex(random_bytes(4));
$PASS = 0;
$FAIL = 0;
$FAILURES = [];
$cleanup = ['user_ids' => [], 'admin_ids' => [], 'tx_refs' => [], 'card_ids' => [], 'promo_ids' => [], 'cashback_ids' => []];

function check(string $name, bool $cond, string $detail = ''): void {
    global $PASS, $FAIL, $FAILURES;
    if ($cond) { $PASS++; echo "  [PASS] $name\n"; }
    else { $FAIL++; $FAILURES[] = $name . ($detail ? " ($detail)" : ''); echo "  [FAIL] $name" . ($detail ? " -- $detail" : '') . "\n"; }
}

function http(string $method, string $path, $data = [], array $headers = [], ?string $cookieJar = null, bool $rawBody = false): array {
    $ch = curl_init(BASE_URL . $path);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 15];
    if ($method === 'POST') {
        $isJson = in_array('Content-Type: application/json', $headers, true);
        if ($rawBody) { $opts[CURLOPT_POSTFIELDS] = $data; }
        else { $opts[CURLOPT_POSTFIELDS] = $isJson ? json_encode($data) : http_build_query($data); }
    }
    if ($headers) $opts[CURLOPT_HTTPHEADER] = $headers;
    if ($cookieJar) { $opts[CURLOPT_COOKIEJAR] = $cookieJar; $opts[CURLOPT_COOKIEFILE] = $cookieJar; }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'body' => substr((string)$raw, $hs), 'headers' => substr((string)$raw, 0, $hs)];
}

function clear_rl(string $key): void {
    foreach (['::1', '127.0.0.1', '0.0.0.0'] as $ip) {
        @unlink(sys_get_temp_dir() . '/rl_' . md5($key . '_' . $ip));
    }
}

echo "=== Phase 2B Security Test Suite (run $RUNID) ===\n\n";

try {
    // ------------------------------------------------------------------
    // Fixture: one ordinary user + a second user (for cross-user checks)
    // ------------------------------------------------------------------
    $pw = 'SecTest_Aa1_' . rand(1000, 9999);
    $hash = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]);
    $pin_hash = password_hash('1234', PASSWORD_BCRYPT, ['cost' => 12]);

    $ins = db()->prepare("INSERT INTO users (full_name, username, phone, email, password, transaction_pin, wallet_balance, is_active, api_token, created_at) VALUES (?,?,?,?,?,?,?,1,?,NOW())");
    $ins->execute(['SecTest2B User A', "sectest2b_{$RUNID}_a", "080{$RUNID}01", "sectest2b_{$RUNID}_a@example.test", $hash, $pin_hash, 5000, 'msj_live_' . bin2hex(random_bytes(24))]);
    $userA = (int)db()->lastInsertId();
    $cleanup['user_ids'][] = $userA;

    $ins->execute(['SecTest2B User B', "sectest2b_{$RUNID}_b", "080{$RUNID}02", "sectest2b_{$RUNID}_b@example.test", $hash, $pin_hash, 5000, 'msj_live_' . bin2hex(random_bytes(24))]);
    $userB = (int)db()->lastInsertId();
    $cleanup['user_ids'][] = $userB;

    $tokA = db()->prepare("SELECT api_token FROM users WHERE id=?"); $tokA->execute([$userA]); $tokA = $tokA->fetchColumn();
    $tokB = db()->prepare("SELECT api_token FROM users WHERE id=?"); $tokB->execute([$userB]); $tokB = $tokB->fetchColumn();

    echo "[setup] userA=$userA userB=$userB\n\n";

    // ==================================================================
    // 1. Cashwyre webhook double-credit fix
    // ==================================================================
    echo "[1] Cashwyre webhook double-credit fix\n";

    // Create a virtual card + a matching pending debit transaction, exactly
    // as process_card_action('fund') would right before calling Cashwyre.
    $cardId = 'sectest2b_card_' . $RUNID;
    db()->prepare("INSERT INTO virtual_cards (user_id, card_id, balance, status, created_at) VALUES (?,?,0,'active',NOW())")->execute([$userA, $cardId]);
    $cleanup['card_ids'][] = $cardId;

    $ref1 = 'MSXSECT2B' . $RUNID . '1';
    $desc = 'Fund virtual card ' . substr($cardId, -6) . ' with $10';
    db()->prepare("INSERT INTO transactions (user_id, transaction_ref, type, category, amount, status, balance_before, balance_after, description, created_at) VALUES (?,?, 'debit','virtual_card',1500,'pending',5000,3500,?,NOW())")
        ->execute([$userA, $ref1, $desc]);
    $cleanup['tx_refs'][] = $ref1;

    // Simulate the Cashwyre webhook firing for this funding event (event id A).
    $secret = 'sectest2b_cashwyre_secret_' . $RUNID;
    db()->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('cashwyre_webhook_secret', ?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$secret, $secret]);

    $payload1 = json_encode(['event' => 'card.funded', 'cardId' => $cardId, 'amount' => 10, 'id' => 'evt_' . $RUNID . '_1']);
    $sig1 = hash_hmac('sha256', $payload1, $secret);
    $r = http('POST', '/webhook/cashwyre/index.php', $payload1, ['X-Cashwyre-Signature: ' . $sig1], null, true);
    check("Cashwyre webhook (1st event) accepted", $r['code'] === 200, "code={$r['code']} body={$r['body']}");

    $bal1 = db()->prepare("SELECT balance FROM virtual_cards WHERE card_id=?"); $bal1->execute([$cardId]); $bal1 = (float)$bal1->fetchColumn();
    check("Card credited once after first webhook event (balance=10)", abs($bal1 - 10.0) < 0.01, "balance={$bal1}");

    // Now simulate Cashwyre ALSO sending a second, independent webhook event
    // for the SAME real-world funding (normal delivery behavior, not an
    // attack) -- this is exactly the guaranteed-double-credit scenario.
    $payload2 = json_encode(['event' => 'card.funded', 'cardId' => $cardId, 'amount' => 10, 'id' => 'evt_' . $RUNID . '_2']);
    $sig2 = hash_hmac('sha256', $payload2, $secret);
    $r = http('POST', '/webhook/cashwyre/index.php', $payload2, ['X-Cashwyre-Signature: ' . $sig2], null, true);
    check("Cashwyre webhook (2nd, duplicate-funding event) accepted (200 either way)", $r['code'] === 200, "code={$r['code']}");

    $bal2 = db()->prepare("SELECT balance FROM virtual_cards WHERE card_id=?"); $bal2->execute([$cardId]); $bal2 = (float)$bal2->fetchColumn();
    check("Card NOT double-credited by the second webhook event (balance still 10, not 20)", abs($bal2 - 10.0) < 0.01, "balance={$bal2}");

    $txStatus = db()->prepare("SELECT status FROM transactions WHERE transaction_ref=?"); $txStatus->execute([$ref1]); $txStatus = $txStatus->fetchColumn();
    check("Underlying transaction correctly resolved to success (claimed once)", $txStatus === 'success', "status={$txStatus}");

    // Forged webhook with an inflated amount that does NOT match any real
    // pending transaction for this card -- should be rejected (not credited)
    // rather than trusted unconditionally.
    $payload3 = json_encode(['event' => 'card.funded', 'cardId' => $cardId, 'amount' => 5000, 'id' => 'evt_' . $RUNID . '_forged']);
    $sig3 = hash_hmac('sha256', $payload3, $secret);
    $r = http('POST', '/webhook/cashwyre/index.php', $payload3, ['X-Cashwyre-Signature: ' . $sig3], null, true);
    $bal3 = db()->prepare("SELECT balance FROM virtual_cards WHERE card_id=?"); $bal3->execute([$cardId]); $bal3 = (float)$bal3->fetchColumn();
    check("Forged webhook with no matching pending transaction does NOT credit an arbitrary amount", abs($bal3 - 10.0) < 0.01, "balance={$bal3}");

    // ==================================================================
    // 2. Autopilot webhook amount-cap fix
    // ==================================================================
    echo "\n[2] Autopilot webhook amount-cap fix\n";
    $a2cRef = 'A2CSECT2B' . $RUNID;
    db()->prepare("INSERT INTO airtime_to_cash (user_id,transaction_ref,network,airtime_amount,cash_amount,discount_percent,sender_phone,our_number,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())")
        ->execute([$userA, $a2cRef, 'MTN', 100, 65, 65, '08011111111', '08022222222', 'pending']);

    $apSecret = 'sectest2b_autopilot_secret_' . $RUNID;
    db()->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('autopilot_api_key', ?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$apSecret, $apSecret]);

    $apPayload = json_encode(['event' => 'confirmed', 'reference' => $a2cRef, 'cashAmount' => 500000]);
    $apSig = hash_hmac('sha256', $apPayload, $apSecret);
    $r = http('POST', '/webhook/autopilot/index.php', $apPayload, ['X-Autopilot-Signature: ' . $apSig], null, true);
    check("Autopilot webhook with inflated cashAmount accepted (200)", $r['code'] === 200, "code={$r['code']} body={$r['body']}");

    $creditedAmt = db()->prepare("SELECT cash_amount, status FROM airtime_to_cash WHERE transaction_ref=?");
    $creditedAmt->execute([$a2cRef]);
    $row = $creditedAmt->fetch();
    check("Inflated cashAmount (500000) was NOT credited verbatim — capped to the platform-calculated amount", $row && abs((float)$row['cash_amount'] - 65.0) < 10, "credited=" . ($row['cash_amount'] ?? 'n/a'));
    check("A2C request resolved to success", $row && $row['status'] === 'success', "status=" . ($row['status'] ?? 'n/a'));

    // ==================================================================
    // 3. Promo code usage_limit race condition fix
    // ==================================================================
    echo "\n[3] Promo code usage_limit atomicity\n";
    $promoCode = 'SECT2B' . strtoupper(substr($RUNID, 0, 6));
    db()->prepare("INSERT INTO promo_codes (code, amount, usage_limit, used_count, created_at) VALUES (?, 100, 1, 0, NOW())")->execute([$promoCode]);
    $promoId = (int)db()->lastInsertId();
    $cleanup['promo_ids'][] = $promoId;

    // usage_limit=1: userA claims first (should succeed), userB claims second
    // (should be rejected -- limit already reached), even though the
    // pre-transaction check alone would have let both through under the
    // old code if they raced. Sequential calls here (not truly concurrent)
    // still validate the atomic UPDATE...WHERE used_count<usage_limit guard
    // correctly rejects the second claim once the limit is hit.
    $r1 = http('POST', '/api/v1/promo.php', ['action' => 'claim', 'code' => $promoCode], ['Content-Type: application/json', "Authorization: Bearer $tokA"]);
    $r2 = http('POST', '/api/v1/promo.php', ['action' => 'claim', 'code' => $promoCode], ['Content-Type: application/json', "Authorization: Bearer $tokB"]);
    $d1 = json_decode($r1['body'], true);
    $d2 = json_decode($r2['body'], true);
    check("First claim (userA) succeeds", ($d1['status'] ?? '') === 'success', "body={$r1['body']}");
    check("Second claim (userB) rejected once usage_limit reached", ($d2['status'] ?? '') === 'error' && stripos($d2['message'] ?? '', 'limit') !== false, "body={$r2['body']}");

    $usedCount = db()->prepare("SELECT used_count FROM promo_codes WHERE id=?"); $usedCount->execute([$promoId]); $usedCount = (int)$usedCount->fetchColumn();
    check("used_count never exceeds usage_limit (used_count=1, limit=1)", $usedCount === 1, "used_count={$usedCount}");

    // ==================================================================
    // 4. Notifications shared is_read fix
    // ==================================================================
    echo "\n[4] Notifications per-user read state (broadcast rows)\n";
    db()->prepare("INSERT INTO notifications (user_id, title, message, type, is_read, created_at) VALUES (NULL, ?, 'Broadcast test', 'info', 0, NOW())")->execute(["SecTest2B Broadcast {$RUNID}"]);
    $broadcastId = (int)db()->lastInsertId();

    // userA marks it read
    $r = http('POST', '/api/v1/notifications.php', ['action' => 'mark_read', 'id' => $broadcastId], ['Content-Type: application/json', "Authorization: Bearer $tokA"]);
    check("userA mark_read on broadcast succeeds", (json_decode($r['body'], true)['status'] ?? '') === 'success', "body={$r['body']}");

    // userB's unread listing should STILL show it as unread
    $r = http('GET', '/api/v1/notifications.php?per_page=50', [], ["Authorization: Bearer $tokB"]);
    $listB = json_decode($r['body'], true);
    $foundUnreadForB = false;
    foreach (($listB['data']['notifications'] ?? []) as $n) {
        if ((int)$n['id'] === $broadcastId && (int)$n['is_read'] === 0) { $foundUnreadForB = true; break; }
    }
    check("userB still sees the broadcast as UNREAD after userA marked it read", $foundUnreadForB, "body=" . substr($r['body'], 0, 300));

    // The raw shared column itself must remain untouched (still 0)
    $sharedVal = db()->prepare("SELECT is_read FROM notifications WHERE id=?"); $sharedVal->execute([$broadcastId]); $sharedVal = (int)$sharedVal->fetchColumn();
    check("Underlying shared notifications.is_read column was NOT mutated", $sharedVal === 0, "is_read={$sharedVal}");

    db()->prepare("DELETE FROM notification_reads WHERE notification_id=?")->execute([$broadcastId]);
    db()->prepare("DELETE FROM notifications WHERE id=?")->execute([$broadcastId]);

    // ==================================================================
    // 5. a2c.php submit() PIN requirement
    // ==================================================================
    echo "\n[5] Airtime-to-cash submit() now requires the transaction PIN\n";
    clear_rl('api_purchase_' . $userA);
    $r = http('POST', '/api/v1/a2c.php', ['network' => 'MTN', 'sender_phone' => '08033333333', 'airtime_amount' => 500, 'pin' => '9999'], ['Content-Type: application/json', "Authorization: Bearer $tokA"]);
    $d = json_decode($r['body'], true);
    check("a2c submit with WRONG PIN is rejected", ($d['status'] ?? '') === 'error' && stripos($d['message'] ?? '', 'PIN') !== false, "body={$r['body']}");

    $countBefore = db()->prepare("SELECT COUNT(*) FROM airtime_to_cash WHERE user_id=? AND sender_phone='08033333333'"); $countBefore->execute([$userA]); $countBefore = (int)$countBefore->fetchColumn();
    check("No airtime_to_cash row was created for the rejected (wrong-PIN) submission", $countBefore === 0, "count={$countBefore}");

    // ==================================================================
    // 6. Purchase endpoint rate limiting
    // ==================================================================
    echo "\n[6] Rate limiting on purchase endpoints (api_purchase_<uid>)\n";
    clear_rl('api_purchase_' . $userB);
    $limited = false;
    for ($i = 0; $i < 35; $i++) {
        $r = http('POST', '/api/v1/airtime.php', ['network' => 'MTN', 'phone' => '08000000000', 'amount' => 100], ['Content-Type: application/json', "Authorization: Bearer $tokB"]);
        if ($r['code'] === 429) { $limited = true; break; }
    }
    check("api/v1/airtime.php rate-limits a burst of requests from one account", $limited, "stopped at attempt " . ($i + 1) . " last_code={$r['code']}");

    // ==================================================================
    // 7. Admin: cashback bound validation
    // ==================================================================
    echo "\n[7] Admin cashback.php bound validation\n";
    // Disposable admin fixture with manage_cashback permission, known device.
    $adminEmail = "sectest2b_{$RUNID}_admin@example.test";
    $adminPw = 'SecTestAdmin_Aa1_' . rand(1000, 9999);
    $adminHash = password_hash($adminPw, PASSWORD_BCRYPT, ['cost' => 12]);
    db()->prepare("INSERT INTO admin (username, email, password, role, is_active, permissions, created_at) VALUES (?,?,?,?,1,?,NOW())")
        ->execute(["sectest2b_{$RUNID}", $adminEmail, $adminHash, 'admin', '["manage_cashback","manage_pricing"]']);
    $testAdminId = (int)db()->lastInsertId();
    $cleanup['admin_ids'][] = $testAdminId;

    $fixedUA = 'SecTest2BAgent/1.0';
    $deviceHash = hash('sha256', '::1|' . $fixedUA);
    db()->exec("CREATE TABLE IF NOT EXISTS admin_known_devices (id INT AUTO_INCREMENT PRIMARY KEY, admin_id INT NOT NULL, device_hash VARCHAR(64) NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(400) DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, last_seen DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uniq_admin_device (admin_id, device_hash))");
    db()->prepare("INSERT INTO admin_known_devices (admin_id, device_hash, ip_address, user_agent) VALUES (?,?,?,?)")->execute([$testAdminId, $deviceHash, '::1', $fixedUA]);

    $adminCookieJar = sys_get_temp_dir() . "/sectest2b_admin_cookies_{$RUNID}.txt";
    $r = http('GET', '/grace-hub-tels/login', [], ["User-Agent: $fixedUA"], $adminCookieJar);
    preg_match('/name="csrf_token" value="([^"]+)"/', $r['body'], $m);
    $loginCsrf = $m[1] ?? '';
    $r = http('POST', '/grace-hub-tels/login', ['csrf_token' => $loginCsrf, 'email' => $adminEmail, 'password' => $adminPw], ["User-Agent: $fixedUA"], $adminCookieJar);
    $loggedIn = (strpos($r['headers'], 'dashboard.php') !== false);
    check("Disposable test admin logs in successfully", $loggedIn, "headers=" . trim($r['headers']));

    if ($loggedIn) {
        $r = http('GET', '/grace-hub-tels/cashback', [], ["User-Agent: $fixedUA"], $adminCookieJar);
        preg_match("/const CSRF='([^']+)'/", $r['body'], $m2);
        $adminCsrf = $m2[1] ?? '';
        if (!$adminCsrf) { preg_match('/name="csrf" value="([^"]+)"/', $r['body'], $m2); $adminCsrf = $m2[1] ?? ''; }

        if ($adminCsrf) {
            $r = http('POST', '/grace-hub-tels/cashback', [
                'csrf' => $adminCsrf, 'action' => 'save', 'service' => 'airtime', 'network' => '',
                'cashback_type' => 'percent', 'cashback_value' => '150', 'min_amount' => '0', 'max_cashback' => '9999', 'label' => 'sectest',
            ], ["User-Agent: $fixedUA", 'X-Requested-With: XMLHttpRequest'], $adminCookieJar);
            $d = json_decode($r['body'], true);
            check("cashback_value=150% (percent type) is rejected", ($d['success'] ?? true) === false, "body={$r['body']}");

            $r = http('POST', '/grace-hub-tels/cashback', [
                'csrf' => $adminCsrf, 'action' => 'save', 'service' => 'data', 'network' => '',
                'cashback_type' => 'fixed', 'cashback_value' => '-50', 'min_amount' => '0', 'max_cashback' => '9999', 'label' => 'sectest',
            ], ["User-Agent: $fixedUA", 'X-Requested-With: XMLHttpRequest'], $adminCookieJar);
            $d = json_decode($r['body'], true);
            check("negative cashback_value is rejected", ($d['success'] ?? true) === false, "body={$r['body']}");

            // ==============================================================
            // 8. Admin: A2C credit amount cross-check
            // ==============================================================
            echo "\n[8] Admin service-pricing.php A2C credit amount cross-check\n";
            $a2cRef2 = 'A2CSECT2B' . $RUNID . 'B';
            db()->prepare("INSERT INTO airtime_to_cash (user_id,transaction_ref,network,airtime_amount,cash_amount,discount_percent,sender_phone,our_number,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())")
                ->execute([$userA, $a2cRef2, 'MTN', 100, 65, 65, '08011111112', '08022222223', 'pending']);

            $r = http('POST', '/grace-hub-tels/service-pricing', ['csrf' => $adminCsrf, 'action' => 'credit', 'ref' => $a2cRef2, 'amount' => '999999'], ["User-Agent: $fixedUA", 'X-Requested-With: XMLHttpRequest'], $adminCookieJar);
            $d = json_decode($r['body'], true);
            check("Admin crediting far more than the submitted airtime value is rejected", ($d['success'] ?? true) === false, "body={$r['body']}");

            $stillPending = db()->prepare("SELECT status FROM airtime_to_cash WHERE transaction_ref=?"); $stillPending->execute([$a2cRef2]); $stillPending = $stillPending->fetchColumn();
            check("A2C request was NOT resolved/credited by the rejected over-amount attempt", $stillPending === 'pending', "status={$stillPending}");

            $r = http('POST', '/grace-hub-tels/service-pricing', ['csrf' => $adminCsrf, 'action' => 'credit', 'ref' => $a2cRef2, 'amount' => '65'], ["User-Agent: $fixedUA", 'X-Requested-With: XMLHttpRequest'], $adminCookieJar);
            $d = json_decode($r['body'], true);
            check("Admin crediting an amount within the airtime value succeeds", ($d['success'] ?? false) === true, "body={$r['body']}");

            db()->prepare("DELETE FROM transactions WHERE transaction_ref LIKE ?")->execute(['a2c:%' . $a2cRef2 . '%']);
        } else {
            echo "  [SKIP] could not extract admin CSRF token — skipping admin-panel checks 7-8\n";
        }
    }

} finally {
    echo "\n[cleanup] removing test fixtures\n";
    db()->prepare("DELETE FROM virtual_cards WHERE card_id IN (" . implode(',', array_fill(0, count($cleanup['card_ids']), '?')) . ")")->execute($cleanup['card_ids']) ;
    if ($cleanup['tx_refs']) {
        $in = implode(',', array_fill(0, count($cleanup['tx_refs']), '?'));
        db()->prepare("DELETE FROM transactions WHERE transaction_ref IN ($in)")->execute($cleanup['tx_refs']);
    }
    db()->prepare("DELETE FROM transactions WHERE description LIKE ?")->execute(['%' . $RUNID . '%']);
    db()->exec("DELETE FROM airtime_to_cash WHERE transaction_ref LIKE 'A2CSECT2B{$RUNID}%'");
    db()->exec("DELETE FROM promo_claims WHERE promo_id IN (SELECT id FROM promo_codes WHERE code LIKE 'SECT2B%')");
    db()->exec("DELETE FROM promo_codes WHERE code LIKE 'SECT2B%'");
    db()->exec("DELETE FROM site_settings WHERE setting_key IN ('cashwyre_webhook_secret','autopilot_api_key') AND setting_value LIKE 'sectest2b_%'");
    if ($cleanup['user_ids']) {
        $in = implode(',', array_fill(0, count($cleanup['user_ids']), '?'));
        db()->prepare("DELETE FROM users WHERE id IN ($in)")->execute($cleanup['user_ids']);
    }
    if ($cleanup['admin_ids']) {
        $in = implode(',', array_fill(0, count($cleanup['admin_ids']), '?'));
        db()->prepare("DELETE FROM admin_known_devices WHERE admin_id IN ($in)")->execute($cleanup['admin_ids']);
        db()->prepare("DELETE FROM admin_activity_logs WHERE admin_id IN ($in)")->execute($cleanup['admin_ids']);
        db()->prepare("DELETE FROM admin WHERE id IN ($in)")->execute($cleanup['admin_ids']);
    }
    @unlink(sys_get_temp_dir() . "/sectest2b_admin_cookies_{$RUNID}.txt");
}

echo "\n=== RESULTS: $PASS passed, $FAIL failed ===\n";
if ($FAILURES) {
    echo "\nFailed checks:\n";
    foreach ($FAILURES as $f) echo " - $f\n";
}
exit($FAIL > 0 ? 1 : 0);
