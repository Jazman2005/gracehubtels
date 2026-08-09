<?php
/**
 * Phase 4 adversarial financial security testing — standalone CLI script.
 * Run via: php scratch/security_tests/phase4_adversarial_tests.php
 *
 * Plays a malicious customer (and, where relevant, a careless admin)
 * against the live local test vhost (localhost:8099), backed by the local
 * msjdkjef_api test database. Every "attack" here is a real HTTP request
 * against real endpoints acting on disposable sectest4_<runid>_-prefixed
 * fixtures, cleaned up regardless of pass/fail. Never point BASE_URL at a
 * real production host.
 */
declare(strict_types=1);
define('BASE_URL', 'http://localhost:8099');
require_once __DIR__ . '/../../user/core/db.php';

$RUNID = bin2hex(random_bytes(4));
$PASS = 0; $FAIL = 0; $FAILURES = [];
$cleanup = ['user_ids' => [], 'admin_ids' => [], 'promo_ids' => []];

function check(string $name, bool $cond, string $detail = ''): void {
    global $PASS, $FAIL, $FAILURES;
    if ($cond) { $PASS++; echo "  [PASS] $name\n"; }
    else { $FAIL++; $FAILURES[] = $name . ($detail ? " ($detail)" : ''); echo "  [FAIL] $name" . ($detail ? " -- $detail" : '') . "\n"; }
}
function http(string $method, string $path, $data = [], array $headers = [], ?string $cookieJar = null, bool $rawBody = false): array {
    $ch = curl_init(BASE_URL . $path);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20];
    if ($method === 'POST') {
        $isJson = in_array('Content-Type: application/json', $headers, true);
        $opts[CURLOPT_POSTFIELDS] = $rawBody ? $data : ($isJson ? json_encode($data) : http_build_query($data));
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
// Fires N identical (or per-index-customized) requests as close to
// simultaneously as libcurl allows, for genuine concurrency rather than
// fast sequential calls.
function http_concurrent(int $n, callable $buildRequest): array {
    $mh = curl_multi_init();
    $handles = [];
    for ($i = 0; $i < $n; $i++) {
        [$path, $body, $headers] = $buildRequest($i);
        $ch = curl_init(BASE_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 20,
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
function clear_rl(string $key): void {
    foreach (['::1', '127.0.0.1', '0.0.0.0'] as $ip) @unlink(sys_get_temp_dir() . '/rl_' . md5($key . '_' . $ip));
}
function make_user(string $runid, string $tag, float $wallet = 0): array {
    static $seq = 0; $seq++;
    $pw = 'SecTest_Aa1_' . rand(1000, 9999);
    $hash = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 4]);
    $pin_hash = password_hash('1234', PASSWORD_BCRYPT, ['cost' => 4]);
    $tok = 'msj_live_' . bin2hex(random_bytes(24));
    db()->prepare("INSERT INTO users (full_name, username, phone, email, password, transaction_pin, wallet_balance, is_active, api_token, created_at) VALUES (?,?,?,?,?,?,?,1,?,NOW())")
        ->execute(["SecTest4 $tag", "sectest4_{$runid}_{$tag}", "071{$runid}" . str_pad((string)$seq, 2, '0', STR_PAD_LEFT), "sectest4_{$runid}_{$tag}@example.test", $hash, $pin_hash, $wallet, $tok]);
    return ['id' => (int)db()->lastInsertId(), 'token' => $tok];
}
function balance_of(int $uid): float {
    $s = db()->prepare("SELECT wallet_balance FROM users WHERE id=?"); $s->execute([$uid]); return (float)$s->fetchColumn();
}

echo "=== Phase 4 Adversarial Financial Security Testing (run $RUNID) ===\n\n";

try {
    // ==================================================================
    // 1. Double-click / N-concurrent identical purchase requests
    // ==================================================================
    echo "[1] Concurrent identical purchase requests (claim_purchase_lock + debit_wallet race)\n";
    $u1 = make_user($RUNID, 'conc1', 1000);
    clear_rl('api_purchase_' . $u1['id']);
    $phone = '08011112222';
    $results = http_concurrent(20, fn($i) => [
        '/api/v1/airtime.php',
        json_encode(['network' => 'MTN', 'phone' => $phone, 'amount' => 100, 'pin' => '1234']),
        ['Content-Type: application/json', 'Authorization: Bearer ' . $u1['token']]
    ]);
    // No real provider is configured in this test environment, so every
    // attempt that gets past claim_purchase_lock() debits the wallet and is
    // then immediately refunded (net balance unchanged either way) — the
    // response's "success" field is true for both a genuine sale and a
    // refunded one, so it can't distinguish "the lock held" from "it didn't".
    // What claim_purchase_lock() actually controls is how many times
    // debit_wallet() ever ran for this exact request signature — measured
    // directly by counting the debit rows it created, independent of
    // whatever happened to them afterward.
    $debitRows = (int)db()->query("SELECT COUNT(*) FROM transactions WHERE user_id={$u1['id']} AND category='airtime' AND description LIKE '%$phone%'")->fetchColumn();
    check("20 concurrent identical airtime purchases: debit_wallet() ran exactly once", $debitRows === 1, "debit_rows=$debitRows");
    $balAfter = balance_of($u1['id']);
    check("Wallet back at 1000 either way (debited-then-refunded net zero, or single real debit — never a multi-debit net loss)", $balAfter >= 900.0, "balance=$balAfter debit_rows=$debitRows");

    // ==================================================================
    // 2. Negative / zero / huge amounts
    // ==================================================================
    echo "\n[2] Negative, zero, and huge amounts\n";
    $u2 = make_user($RUNID, 'amt', 1000);
    clear_rl('api_purchase_' . $u2['id']);
    foreach ([-500, 0, 999999999] as $badAmt) {
        $r = http('POST', '/api/v1/airtime.php', ['network' => 'MTN', 'phone' => '08033334444', 'amount' => $badAmt, 'pin' => '1234'], ['Content-Type: application/json', 'Authorization: Bearer ' . $u2['token']]);
        $d = json_decode($r['body'], true);
        check("Airtime amount=$badAmt rejected", ($d['status'] ?? '') === 'error', "body={$r['body']}");
    }
    $balAfter2 = balance_of($u2['id']);
    check("Wallet untouched by rejected bad-amount attempts", abs($balAfter2 - 1000.0) < 0.01, "balance=$balAfter2");

    // Huge internal transfer exceeding balance
    $u2b = make_user($RUNID, 'amtb', 100);
    clear_rl('api_purchase_' . $u2b['id']);
    $r = http('POST', '/api/v1/internal_transfer.php', ['recipient' => "sectest4_{$RUNID}_amt", 'amount' => 999999, 'pin' => '1234'], ['Content-Type: application/json', 'Authorization: Bearer ' . $u2b['token']]);
    $d = json_decode($r['body'], true);
    check("Huge internal transfer (999999) with insufficient balance is rejected", ($d['status'] ?? '') === 'error', "body={$r['body']}");

    // ==================================================================
    // 3. Manipulated user_id / uid in POST body
    // ==================================================================
    echo "\n[3] Manipulated user_id/uid field in an authenticated request body\n";
    $u3 = make_user($RUNID, 'idspoof', 500);
    $victim = make_user($RUNID, 'victim', 500);
    clear_rl('api_purchase_' . $u3['id']);
    $spoofPhone = '08055556666';
    $r = http('POST', '/api/v1/airtime.php', ['network' => 'MTN', 'phone' => $spoofPhone, 'amount' => 100, 'pin' => '1234', 'user_id' => $victim['id'], 'uid' => $victim['id']], ['Content-Type: application/json', 'Authorization: Bearer ' . $u3['token']]);
    $d = json_decode($r['body'], true);
    $victimBal = balance_of($victim['id']);
    $victimTxCount = (int)db()->query("SELECT COUNT(*) FROM transactions WHERE user_id={$victim['id']} AND category='airtime' AND description LIKE '%$spoofPhone%'")->fetchColumn();
    $attackerTxCount = (int)db()->query("SELECT COUNT(*) FROM transactions WHERE user_id={$u3['id']} AND category='airtime' AND description LIKE '%$spoofPhone%'")->fetchColumn();
    check("Purchase succeeded (or failed) against the TOKEN owner's wallet, not the spoofed user_id", abs($victimBal - 500.0) < 0.01 && $victimTxCount === 0, "victim_balance=$victimBal victim_tx_count=$victimTxCount");
    check("Attacker's own wallet was the one actually debited (a transaction row was created under the attacker's uid)", $attackerTxCount === 1, "attacker_tx_count=$attackerTxCount body={$r['body']}");

    // ==================================================================
    // 4. Price / fee manipulation
    // ==================================================================
    echo "\n[4] Client-supplied price/fee override attempt\n";
    $u4 = make_user($RUNID, 'price', 1000);
    clear_rl('api_purchase_' . $u4['id']);
    // Attacker tries to pay 100 for a service while claiming a huge discount/fee override field
    $r = http('POST', '/api/v1/airtime.php', ['network' => 'MTN', 'phone' => '08077778888', 'amount' => 1000, 'discount' => 100, 'discount_percent' => 100, 'price' => 1, 'charge_amount' => 1, 'pin' => '1234'], ['Content-Type: application/json', 'Authorization: Bearer ' . $u4['token']]);
    $d = json_decode($r['body'], true);
    $balAfter4 = balance_of($u4['id']);
    // Airtime here has 0% discount for MTN by default in this fixture set —
    // regardless, the debit must equal server-computed charge, never the
    // attacker-supplied override fields.
    check("Client-supplied discount/price/charge override fields had no effect on the actual debit", ($balAfter4 <= 1000.0) && (1000.0 - $balAfter4 == 0 || 1000.0 - $balAfter4 >= 995), "balance=$balAfter4 body={$r['body']}");

    // ==================================================================
    // 5. Concurrent internal transfers exceeding balance (transfer race)
    // ==================================================================
    echo "\n[5] Concurrent internal transfers exceeding sender's balance\n";
    $sender = make_user($RUNID, 'txsend', 1000);
    $recip = make_user($RUNID, 'txrecv', 0);
    clear_rl('api_purchase_' . $sender['id']);
    $recipUsername = "sectest4_{$RUNID}_txrecv";
    $results = http_concurrent(10, fn($i) => [
        '/api/v1/internal_transfer.php',
        json_encode(['recipient' => $recipUsername, 'amount' => 300, 'pin' => '1234']),
        ['Content-Type: application/json', 'Authorization: Bearer ' . $sender['token']]
    ]);
    $txSuccesses = 0;
    foreach ($results as $r) { $d = json_decode($r['body'], true); if (($d['status'] ?? '') === 'success') $txSuccesses++; }
    $senderBal = balance_of($sender['id']);
    $recipBal = balance_of($recip['id']);
    check("At most 3 of 10 concurrent ₦300 transfers succeeded (1000 balance / 300 = 3.33)", $txSuccesses <= 3, "successes=$txSuccesses");
    check("Sender's balance never went negative", $senderBal >= -0.01, "balance=$senderBal");
    check("Sender debited exactly (successes * 300)", abs($senderBal - (1000 - $txSuccesses * 300)) < 0.01, "balance=$senderBal successes=$txSuccesses");
    check("Recipient credited exactly (successes * 300) — no money created or lost", abs($recipBal - $txSuccesses * 300) < 0.01, "recip_balance=$recipBal successes=$txSuccesses");

    // ==================================================================
    // 6. Promo abuse: same user racing the same code, and a usage-limited
    // code raced by many different users.
    // ==================================================================
    echo "\n[6] Promo code race conditions\n";
    $promoCode1 = 'SECT4SOLO' . strtoupper(substr($RUNID, 0, 6));
    db()->prepare("INSERT INTO promo_codes (code, amount, usage_limit, used_count, created_at) VALUES (?, 200, 5, 0, NOW())")->execute([$promoCode1]);
    $promoId1 = (int)db()->lastInsertId(); $cleanup['promo_ids'][] = $promoId1;
    $soloUser = make_user($RUNID, 'promosolo', 0);
    $results = http_concurrent(8, fn($i) => [
        '/api/v1/promo.php',
        json_encode(['action' => 'claim', 'code' => $promoCode1]),
        ['Content-Type: application/json', 'Authorization: Bearer ' . $soloUser['token']]
    ]);
    $soloSuccesses = 0;
    foreach ($results as $r) { $d = json_decode($r['body'], true); if (($d['status'] ?? '') === 'success') $soloSuccesses++; }
    $soloBal = balance_of($soloUser['id']);
    check("Same user racing the same promo code 8x concurrently: claimed at most once", $soloSuccesses <= 1, "successes=$soloSuccesses");
    check("Wallet credited at most once (₦200, not ₦1600)", $soloBal <= 200.01, "balance=$soloBal");

    $promoCode2 = 'SECT4MULTI' . strtoupper(substr($RUNID, 0, 6));
    db()->prepare("INSERT INTO promo_codes (code, amount, usage_limit, used_count, created_at) VALUES (?, 150, 3, 0, NOW())")->execute([$promoCode2]);
    $promoId2 = (int)db()->lastInsertId(); $cleanup['promo_ids'][] = $promoId2;
    $multiUsers = [];
    for ($i = 0; $i < 10; $i++) { $multiUsers[] = make_user($RUNID, 'promomulti' . $i, 0); }
    $results = http_concurrent(10, fn($i) => [
        '/api/v1/promo.php',
        json_encode(['action' => 'claim', 'code' => $promoCode2]),
        ['Content-Type: application/json', 'Authorization: Bearer ' . $multiUsers[$i]['token']]
    ]);
    $multiSuccesses = 0;
    foreach ($results as $r) { $d = json_decode($r['body'], true); if (($d['status'] ?? '') === 'success') $multiSuccesses++; }
    $usedCount = db()->prepare("SELECT used_count FROM promo_codes WHERE id=?"); $usedCount->execute([$promoId2]); $usedCount = (int)$usedCount->fetchColumn();
    check("10 different users racing a usage_limit=3 promo: exactly 3 succeeded", $multiSuccesses === 3, "successes=$multiSuccesses");
    check("used_count correctly reflects 3, never exceeds usage_limit", $usedCount === 3, "used_count=$usedCount");

    // ==================================================================
    // 7. Concurrent admin A2C credit (double-click by admin)
    // ==================================================================
    echo "\n[7] Concurrent admin A2C credit double-click\n";
    $adminEmail = "sectest4adm_{$RUNID}@example.test";
    $adminPw = 'SecTestAdmin_Aa1_' . rand(1000, 9999);
    $adminHash = password_hash($adminPw, PASSWORD_BCRYPT, ['cost' => 12]);
    db()->prepare("INSERT INTO admin (username, email, password, role, is_active, permissions, created_at) VALUES (?,?,?,?,1,?,NOW())")
        ->execute(["sectest4adm_{$RUNID}", $adminEmail, $adminHash, 'admin', '["manage_pricing","manage_users","manage_withdrawals","manage_deposits"]']);
    $testAdminId = (int)db()->lastInsertId(); $cleanup['admin_ids'][] = $testAdminId;
    $fixedUA = 'SecTest4AdminAgent/1.0';
    $deviceHash = hash('sha256', '::1|' . $fixedUA);
    db()->exec("CREATE TABLE IF NOT EXISTS admin_known_devices (id INT AUTO_INCREMENT PRIMARY KEY, admin_id INT NOT NULL, device_hash VARCHAR(64) NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(400) DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, last_seen DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uniq_admin_device (admin_id, device_hash))");
    db()->prepare("INSERT INTO admin_known_devices (admin_id, device_hash, ip_address, user_agent) VALUES (?,?,?,?)")->execute([$testAdminId, $deviceHash, '::1', $fixedUA]);
    $jar = sys_get_temp_dir() . "/sectest4_admin_cookies_{$RUNID}.txt";
    $r = http('GET', '/grace-hub-tels/login', [], ["User-Agent: $fixedUA"], $jar);
    preg_match('/name="csrf_token" value="([^"]+)"/', $r['body'], $m);
    $r = http('POST', '/grace-hub-tels/login', ['csrf_token' => $m[1] ?? '', 'email' => $adminEmail, 'password' => $adminPw], ["User-Agent: $fixedUA"], $jar);
    $loggedIn = strpos($r['headers'], 'dashboard.php') !== false;
    check("Disposable test admin logs in", $loggedIn);

    if ($loggedIn) {
        $r = http('GET', '/grace-hub-tels/service-pricing', [], ["User-Agent: $fixedUA"], $jar);
        preg_match("/const CSRF='([^']+)'/", $r['body'], $m2);
        $adminCsrf = $m2[1] ?? '';
        if ($adminCsrf) {
            $a2cUser = make_user($RUNID, 'a2c', 0);
            $a2cRef = 'sectest4_a2c_' . $RUNID;
            db()->prepare("INSERT INTO airtime_to_cash (user_id,transaction_ref,network,airtime_amount,cash_amount,discount_percent,sender_phone,our_number,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())")
                ->execute([$a2cUser['id'], $a2cRef, 'MTN', 1000, 650, 65, '08099990000', '08088881111', 'pending']);

            // curl_multi doesn't share the cookie jar automatically across
            // handles the way the sequential http() helper does — reissue
            // using explicit Cookie headers extracted from the jar file.
            // libcurl marks HttpOnly cookies (which this app's session
            // cookie is) with a literal "#HttpOnly_" prefix on the domain
            // field, so a naive "skip lines starting with #" filter throws
            // away every real cookie and silently leaves every concurrent
            // request unauthenticated.
            $cookieHeader = '';
            if (file_exists($jar)) {
                foreach (file($jar) as $line) {
                    $line = str_replace('#HttpOnly_', '', $line);
                    if (strpos($line, "\t") !== false && strpos(trim($line), '#') !== 0) {
                        $parts = preg_split('/\t/', trim($line));
                        if (count($parts) >= 7) $cookieHeader .= $parts[5] . '=' . $parts[6] . '; ';
                    }
                }
            }
            check("Extracted a non-empty session cookie for the concurrent requests", $cookieHeader !== '', "jar=" . (file_exists($jar) ? file_get_contents($jar) : 'MISSING'));
            $results = http_concurrent(10, fn($i) => [
                '/grace-hub-tels/service-pricing',
                http_build_query(['csrf' => $adminCsrf, 'action' => 'credit', 'ref' => $a2cRef, 'amount' => '650']),
                ["User-Agent: $fixedUA", "Cookie: $cookieHeader", 'X-Requested-With: XMLHttpRequest']
            ]);
            $a2cSuccesses = 0;
            foreach ($results as $r) { $d = json_decode($r['body'], true); if (($d['success'] ?? false) === true) $a2cSuccesses++; }
            $a2cBal = balance_of($a2cUser['id']);
            check("10 concurrent admin A2C credit clicks: exactly 1 succeeded (not 0 — proves the requests were genuinely authenticated — and not >1)", $a2cSuccesses === 1, "successes=$a2cSuccesses");
            check("A2C user credited exactly once (₦650, not ₦6500 or ₦0)", abs($a2cBal - 650.0) < 0.01, "balance=$a2cBal");
            db()->prepare("DELETE FROM airtime_to_cash WHERE transaction_ref=?")->execute([$a2cRef]);

            // ==============================================================
            // 8. Withdrawal double-approve race (admin)
            // ==============================================================
            echo "\n[8] Concurrent withdrawal approve race\n";
            $wdUser = make_user($RUNID, 'wd', 5000);
            $wdRef = 'sectest4_wd_' . $RUNID;
            db()->prepare("INSERT INTO transactions (user_id, transaction_ref, type, category, amount, charge, balance_before, balance_after, description, meta, status, created_at) VALUES (?,?, 'debit','bank_transfer',2000,50,5000,2950,?,?,'pending',NOW())")
                ->execute([$wdUser['id'], $wdRef, 'Withdrawal test', json_encode(['charge' => 50, 'bank_code' => '000', 'account_number' => '0000000000', 'payee_name' => 'Test'])]);

            $results = http_concurrent(8, fn($i) => [
                '/grace-hub-tels/withdrawals',
                http_build_query(['csrf' => $adminCsrf, 'action' => 'approve', 'ref' => $wdRef]),
                ["User-Agent: $fixedUA", "Cookie: $cookieHeader", 'X-Requested-With: XMLHttpRequest']
            ]);
            $turnedAway = 0; $pastLock = 0;
            foreach ($results as $r) {
                $d = json_decode($r['body'], true);
                $msg = $d['message'] ?? '';
                if (stripos($msg, 'already processed') !== false || stripos($msg, 'wait a moment') !== false) $turnedAway++;
                else $pastLock++;
            }
            check("8 concurrent withdrawal-approve clicks: only 1 got past the claim lock", $pastLock === 1, "past_lock=$pastLock turned_away=$turnedAway");
            db()->prepare("DELETE FROM transactions WHERE transaction_ref=?")->execute([$wdRef]);
        } else {
            echo "  [SKIP] could not extract admin CSRF token — skipping checks 7-8\n";
        }
    }

    // ==================================================================
    // 9. Cashback not double-applied under concurrent purchase race
    // ==================================================================
    echo "\n[9] Cashback not double-applied under concurrent purchase race\n";
    // Use whatever cashback config already exists for airtime, if any; the
    // point is to prove AT MOST one successful purchase (and therefore at
    // most one apply_cashback() call) occurs regardless of concurrency —
    // already implied by check [1] above (successes<=1), verified directly
    // here via the cashback_balance delta.
    $u9 = make_user($RUNID, 'cbrace', 1000);
    clear_rl('api_purchase_' . $u9['id']);
    db()->prepare("INSERT INTO cashback_config (service, network, cashback_type, cashback_value, min_amount, max_cashback, is_active, created_at) VALUES ('airtime', NULL, 'percent', 10, 0, 1000, 1, NOW())")->execute();
    $cbConfigId = (int)db()->lastInsertId();
    $cbPhone = '08022223333';
    $cbBefore = db()->query("SELECT cashback_balance FROM users WHERE id={$u9['id']}")->fetchColumn();
    $results = http_concurrent(15, fn($i) => [
        '/api/v1/airtime.php',
        json_encode(['network' => 'MTN', 'phone' => $cbPhone, 'amount' => 100, 'pin' => '1234']),
        ['Content-Type: application/json', 'Authorization: Bearer ' . $u9['token']]
    ]);
    $cbAfter = db()->query("SELECT cashback_balance FROM users WHERE id={$u9['id']}")->fetchColumn();
    $cbDebitRows = (int)db()->query("SELECT COUNT(*) FROM transactions WHERE user_id={$u9['id']} AND category='airtime' AND description LIKE '%$cbPhone%'")->fetchColumn();
    // apply_cashback() only ever fires from inside the single winning
    // update_tx_status() pending->success claim, so proving debit_wallet()
    // itself only ran once (no real provider is configured here, so that
    // claim never actually fires) is the reachable proxy for "cashback
    // cannot have been applied more than the number of real debits allows".
    check("At most 1 debit_wallet() call occurred under 15-way concurrency (cashback can only ever piggyback on a single resolution)", $cbDebitRows === 1, "debit_rows=$cbDebitRows");
    check("Cashback applied at most once (delta <= 10, not 150)", ((float)$cbAfter - (float)$cbBefore) <= 10.01, "delta=" . ((float)$cbAfter - (float)$cbBefore));
    db()->prepare("DELETE FROM cashback_config WHERE id=?")->execute([$cbConfigId]);

    // ==================================================================
    // 10. Already-successful / expired / cancelled transaction re-trigger
    // ==================================================================
    echo "\n[10] Already-successful / expired / cancelled transaction re-triggers\n";
    // Expired promo
    $expiredCode = 'SECT4EXP' . strtoupper(substr($RUNID, 0, 6));
    db()->prepare("INSERT INTO promo_codes (code, amount, usage_limit, used_count, expires_at, created_at) VALUES (?, 500, 10, 0, DATE_SUB(NOW(), INTERVAL 1 DAY), NOW())")->execute([$expiredCode]);
    $expPromoId = (int)db()->lastInsertId(); $cleanup['promo_ids'][] = $expPromoId;
    $expUser = make_user($RUNID, 'exp', 0);
    $r = http('POST', '/api/v1/promo.php', ['action' => 'claim', 'code' => $expiredCode], ['Content-Type: application/json', 'Authorization: Bearer ' . $expUser['token']]);
    $d = json_decode($r['body'], true);
    check("Expired promo code claim is rejected", ($d['status'] ?? '') === 'error' && stripos($d['message'] ?? '', 'expired') !== false, "body={$r['body']}");
    check("No wallet credit from expired promo attempt", abs(balance_of($expUser['id'])) < 0.01);

    // Already-completed A2C OTP re-completion
    $a2cUser2 = make_user($RUNID, 'a2cotp', 0);
    $a2cRef2 = 'sectest4_a2cotp_' . $RUNID;
    db()->prepare("INSERT INTO airtime_to_cash (user_id,transaction_ref,network,airtime_amount,cash_amount,discount_percent,sender_phone,our_number,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())")
        ->execute([$a2cUser2['id'], $a2cRef2, 'MTN', 100, 65, 65, '08011119999', '08022228888', 'success']);
    $r = http('POST', '/api/v1/a2c.php', ['action' => 'complete_otp', 'ref' => $a2cRef2, 'otp' => '0000'], ['Content-Type: application/json', 'Authorization: Bearer ' . $a2cUser2['token']]);
    $d = json_decode($r['body'], true);
    check("OTP-complete on an already-successful A2C request is rejected, not re-processed", ($d['status'] ?? '') === 'error', "body={$r['body']}");
    db()->prepare("DELETE FROM airtime_to_cash WHERE transaction_ref=?")->execute([$a2cRef2]);

    // ==================================================================
    // 11. Provider-success-after-local-timeout: a transaction left
    // 'processing' by a network timeout must resolve exactly once when the
    // provider's real outcome later arrives via webhook.
    // ==================================================================
    echo "\n[11] Provider success arriving after a local timeout resolves exactly once\n";
    $u11 = make_user($RUNID, 'timeout', 1000);
    $ref11 = 'SECT4TO' . $RUNID;
    // Simulate debit_wallet() having already run (money genuinely left the
    // wallet) before the provider call timed out locally, leaving the
    // transaction row 'processing' — this is the real pre-state a timeout
    // leaves behind, not just a transaction row with no matching balance
    // change.
    db()->prepare("UPDATE users SET wallet_balance=700 WHERE id=?")->execute([$u11['id']]);
    db()->prepare("INSERT INTO transactions (user_id, transaction_ref, type, category, amount, balance_before, balance_after, status, created_at) VALUES (?,?, 'debit','airtime',300,1000,700,'processing',NOW())")
        ->execute([$u11['id'], $ref11]);
    $vtuKey = 'sectest4_vtu_key_' . $RUNID;
    db()->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('vtu_webhook_secret', ?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$vtuKey, $vtuKey]);
    $payload = json_encode(['reference' => $ref11, 'status' => 'success', 'amount' => 300]);
    // Two "providers" both eventually confirming success for the same
    // timed-out request (a realistic double-notification scenario).
    $results = http_concurrent(5, fn($i) => ["/webhook/vtu/index.php?key=$vtuKey", $payload, ['Content-Type: application/json']]);
    $balAfter11 = balance_of($u11['id']);
    $status11 = db()->prepare("SELECT status FROM transactions WHERE transaction_ref=?"); $status11->execute([$ref11]); $status11 = $status11->fetchColumn();
    check("Transaction resolved to success exactly once", $status11 === 'success', "status=$status11");
    check("No money was created or refunded twice — balance stays at the post-debit value (700)", abs($balAfter11 - 700.0) < 0.01, "balance=$balAfter11");
    db()->prepare("DELETE FROM transactions WHERE transaction_ref=?")->execute([$ref11]);
    db()->prepare("DELETE FROM site_settings WHERE setting_key='vtu_webhook_secret' AND setting_value=?")->execute([$vtuKey]);

} finally {
    echo "\n[cleanup] removing test fixtures\n";
    db()->exec("DELETE FROM transactions WHERE description LIKE '%{$RUNID}%' OR transaction_ref LIKE '%{$RUNID}%'");
    db()->exec("DELETE FROM airtime_to_cash WHERE transaction_ref LIKE '%{$RUNID}%'");
    db()->exec("DELETE FROM promo_claims WHERE promo_id IN (SELECT id FROM (SELECT id FROM promo_codes WHERE code LIKE 'SECT4%') x)");
    if ($cleanup['promo_ids']) {
        $in = implode(',', array_fill(0, count($cleanup['promo_ids']), '?'));
        db()->prepare("DELETE FROM promo_codes WHERE id IN ($in)")->execute($cleanup['promo_ids']);
    }
    db()->exec("DELETE FROM cashback_config WHERE service='airtime' AND max_cashback=1000 AND cashback_value=10");
    db()->exec("DELETE FROM users WHERE username LIKE 'sectest4\\_{$RUNID}\\_%' ESCAPE '\\\\'");
    if ($cleanup['admin_ids']) {
        $in = implode(',', array_fill(0, count($cleanup['admin_ids']), '?'));
        db()->prepare("DELETE FROM admin_known_devices WHERE admin_id IN ($in)")->execute($cleanup['admin_ids']);
        db()->prepare("DELETE FROM admin_activity_logs WHERE admin_id IN ($in)")->execute($cleanup['admin_ids']);
        db()->prepare("DELETE FROM admin WHERE id IN ($in)")->execute($cleanup['admin_ids']);
    }
    @unlink(sys_get_temp_dir() . "/sectest4_admin_cookies_{$RUNID}.txt");
}

echo "\n=== RESULTS: $PASS passed, $FAIL failed ===\n";
if ($FAILURES) { echo "\nFailed checks:\n"; foreach ($FAILURES as $f) echo " - $f\n"; }
exit($FAIL > 0 ? 1 : 0);
