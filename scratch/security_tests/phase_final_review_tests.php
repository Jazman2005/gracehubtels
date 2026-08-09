<?php
/**
 * Final-review regression tests — standalone CLI script.
 * Run via: php scratch/security_tests/phase_final_review_tests.php
 *
 * Covers the two gaps found during the final cross-phase review that no
 * earlier phase's test suite caught: withdrawals.php and service-pricing.php's
 * A2C actions moved real money with no audit_log() entry, and A2C reject had
 * no atomic status guard (could overwrite an already-credited request's
 * status to 'failed'). Live HTTP against the local test vhost (localhost:8099)
 * / local msjdkjef_api test database. Disposable sectestfr_<runid>_-prefixed
 * fixtures, cleaned up regardless of pass/fail.
 */
declare(strict_types=1);
define('BASE_URL', 'http://localhost:8099');
require_once __DIR__ . '/../../user/core/db.php';

$RUNID = bin2hex(random_bytes(4));
$PASS = 0; $FAIL = 0; $FAILURES = [];
$cleanup = ['user_ids' => [], 'admin_ids' => []];

function check(string $name, bool $cond, string $detail = ''): void {
    global $PASS, $FAIL, $FAILURES;
    if ($cond) { $PASS++; echo "  [PASS] $name\n"; }
    else { $FAIL++; $FAILURES[] = $name . ($detail ? " ($detail)" : ''); echo "  [FAIL] $name" . ($detail ? " -- $detail" : '') . "\n"; }
}
function http(string $method, string $path, $data = [], array $headers = [], ?string $cookieJar = null): array {
    $ch = curl_init(BASE_URL . $path);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20];
    if ($method === 'POST') $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
    if ($headers) $opts[CURLOPT_HTTPHEADER] = $headers;
    if ($cookieJar) { $opts[CURLOPT_COOKIEJAR] = $cookieJar; $opts[CURLOPT_COOKIEFILE] = $cookieJar; }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'body' => substr((string)$raw, $hs), 'headers' => substr((string)$raw, 0, $hs)];
}

echo "=== Final Review Regression Tests (run $RUNID) ===\n\n";
try {
    $adminEmail = "sectestfr_{$RUNID}@example.test";
    $adminPw = 'SecTestAdmin_Aa1_' . rand(1000, 9999);
    $adminHash = password_hash($adminPw, PASSWORD_BCRYPT, ['cost' => 12]);
    db()->prepare("INSERT INTO admin (username, email, password, role, is_active, permissions, created_at) VALUES (?,?,?,?,1,?,NOW())")
        ->execute(["sectestfr_{$RUNID}", $adminEmail, $adminHash, 'admin', '["manage_withdrawals","manage_pricing"]']);
    $testAdminId = (int)db()->lastInsertId(); $cleanup['admin_ids'][] = $testAdminId;
    $fixedUA = 'FinalReviewAgent/1.0';
    $deviceHash = hash('sha256', '::1|' . $fixedUA);
    db()->exec("CREATE TABLE IF NOT EXISTS admin_known_devices (id INT AUTO_INCREMENT PRIMARY KEY, admin_id INT NOT NULL, device_hash VARCHAR(64) NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(400) DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, last_seen DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uniq_admin_device (admin_id, device_hash))");
    db()->prepare("INSERT INTO admin_known_devices (admin_id, device_hash, ip_address, user_agent) VALUES (?,?,?,?)")->execute([$testAdminId, $deviceHash, '::1', $fixedUA]);
    $jar = sys_get_temp_dir() . "/sectestfr_cookies_{$RUNID}.txt";
    $r = http('GET', '/grace-hub-tels/login', [], ["User-Agent: $fixedUA"], $jar);
    preg_match('/name="csrf_token" value="([^"]+)"/', $r['body'], $m);
    $r = http('POST', '/grace-hub-tels/login', ['csrf_token' => $m[1] ?? '', 'email' => $adminEmail, 'password' => $adminPw], ["User-Agent: $fixedUA"], $jar);
    $loggedIn = strpos($r['headers'], 'dashboard.php') !== false;
    check("Disposable test admin logs in", $loggedIn);
    if (!$loggedIn) throw new RuntimeException('cannot continue without admin session');

    // ------------------------------------------------------------------
    // 1. Withdrawal reject writes an audit_log entry.
    // ------------------------------------------------------------------
    echo "\n[1] Withdrawal reject writes an audit trail entry\n";
    $wdUser = ['id' => 0];
    $pw = password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]);
    db()->prepare("INSERT INTO users (full_name, username, phone, email, password, wallet_balance, is_active, created_at) VALUES (?,?,?,?,?,0,1,NOW())")
        ->execute(["FinalReview WD", "sectestfr_{$RUNID}_wd", "073{$RUNID}01", "sectestfr_{$RUNID}_wd@example.test", $pw]);
    $wdUid = (int)db()->lastInsertId(); $cleanup['user_ids'][] = $wdUid;
    $wdRef = 'sectestfr_wd_' . $RUNID;
    db()->prepare("INSERT INTO transactions (user_id, transaction_ref, type, category, amount, charge, balance_before, balance_after, description, meta, status, created_at) VALUES (?,?, 'debit','bank_transfer',1000,50,1000,-50,?,?,'pending',NOW())")
        ->execute([$wdUid, $wdRef, 'Withdrawal test', json_encode(['charge' => 50, 'bank_code' => '000', 'account_number' => '0000000000', 'payee_name' => 'Test'])]);

    $r = http('GET', '/grace-hub-tels/withdrawals', [], ["User-Agent: $fixedUA"], $jar);
    preg_match("/fd\.append\('csrf',\s*'([^']+)'\)/", $r['body'], $m2);
    $adminCsrf = $m2[1] ?? '';
    check("Extracted admin CSRF token from withdrawals page", (bool)$adminCsrf, "body_snip=" . substr($r['body'], 0, 300));

    if ($adminCsrf) {
        $before = (int)db()->query("SELECT COUNT(*) FROM admin_activity_logs WHERE admin_id=$testAdminId AND action='REJECT_WITHDRAWAL'")->fetchColumn();
        $r = http('POST', '/grace-hub-tels/withdrawals', ['csrf' => $adminCsrf, 'action' => 'reject', 'ref' => $wdRef], ["User-Agent: $fixedUA", 'X-Requested-With: XMLHttpRequest'], $jar);
        $d = json_decode($r['body'], true);
        check("Withdrawal reject succeeds", ($d['success'] ?? false) === true, "body={$r['body']}");
        $after = (int)db()->query("SELECT COUNT(*) FROM admin_activity_logs WHERE admin_id=$testAdminId AND action='REJECT_WITHDRAWAL'")->fetchColumn();
        check("REJECT_WITHDRAWAL audit_log entry was written", $after === $before + 1, "before=$before after=$after");
        $balNow = (float)db()->query("SELECT wallet_balance FROM users WHERE id=$wdUid")->fetchColumn();
        check("Wallet refunded the full debited amount (1050) on reject", abs($balNow - 1050.0) < 0.01, "balance=$balNow");
    } else {
        echo "  [SKIP] could not extract admin CSRF token — skipping check 1\n";
    }

    // ------------------------------------------------------------------
    // 2. A2C credit writes audit_log; A2C reject writes audit_log AND is
    // blocked once the request is already resolved (new atomic guard).
    // ------------------------------------------------------------------
    echo "\n[2] A2C credit/reject audit trail + reject-after-credit guard\n";
    db()->prepare("INSERT INTO users (full_name, username, phone, email, password, wallet_balance, is_active, created_at) VALUES (?,?,?,?,?,0,1,NOW())")
        ->execute(["FinalReview A2C", "sectestfr_{$RUNID}_a2c", "073{$RUNID}02", "sectestfr_{$RUNID}_a2c@example.test", $pw]);
    $a2cUid = (int)db()->lastInsertId(); $cleanup['user_ids'][] = $a2cUid;
    $a2cRef = 'sectestfr_a2c_' . $RUNID;
    db()->prepare("INSERT INTO airtime_to_cash (user_id,transaction_ref,network,airtime_amount,cash_amount,discount_percent,sender_phone,our_number,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())")
        ->execute([$a2cUid, $a2cRef, 'MTN', 500, 325, 65, '08011112222', '08033334444', 'pending']);

    $r = http('GET', '/grace-hub-tels/service-pricing', [], ["User-Agent: $fixedUA"], $jar);
    preg_match("/const CSRF='([^']+)'/", $r['body'], $m3);
    $pricingCsrf = $m3[1] ?? '';
    check("Extracted admin CSRF token from service-pricing page", (bool)$pricingCsrf);

    if ($pricingCsrf) {
        $beforeCredit = (int)db()->query("SELECT COUNT(*) FROM admin_activity_logs WHERE admin_id=$testAdminId AND action='CREDIT_A2C'")->fetchColumn();
        $r = http('POST', '/grace-hub-tels/service-pricing', ['csrf' => $pricingCsrf, 'action' => 'credit', 'ref' => $a2cRef, 'amount' => '325'], ["User-Agent: $fixedUA", 'X-Requested-With: XMLHttpRequest'], $jar);
        $d = json_decode($r['body'], true);
        check("A2C credit succeeds", ($d['success'] ?? false) === true, "body={$r['body']}");
        $afterCredit = (int)db()->query("SELECT COUNT(*) FROM admin_activity_logs WHERE admin_id=$testAdminId AND action='CREDIT_A2C'")->fetchColumn();
        check("CREDIT_A2C audit_log entry was written", $afterCredit === $beforeCredit + 1, "before=$beforeCredit after=$afterCredit");

        // Now try to reject the SAME request after it was already credited —
        // must be blocked, not silently flip status to 'failed'.
        $r = http('POST', '/grace-hub-tels/service-pricing', ['csrf' => $pricingCsrf, 'action' => 'reject', 'ref' => $a2cRef, 'reason' => 'late reject attempt'], ["User-Agent: $fixedUA", 'X-Requested-With: XMLHttpRequest'], $jar);
        $d = json_decode($r['body'], true);
        check("Reject-after-credit is rejected, not silently applied", ($d['success'] ?? true) === false, "body={$r['body']}");
        $statusNow = db()->query("SELECT status FROM airtime_to_cash WHERE transaction_ref='$a2cRef'")->fetchColumn();
        check("A2C row status remains 'success' (not overwritten to 'failed')", $statusNow === 'success', "status=$statusNow");
    } else {
        echo "  [SKIP] could not extract admin CSRF token — skipping check 2\n";
    }

    // ------------------------------------------------------------------
    // 3. Fresh A2C request, reject it normally (not already credited) —
    // confirm the atomic guard doesn't break the legitimate path, and
    // REJECT_A2C audit_log fires.
    // ------------------------------------------------------------------
    echo "\n[3] Normal A2C reject (never credited) still works and is audited\n";
    $a2cRef2 = 'sectestfr_a2c2_' . $RUNID;
    db()->prepare("INSERT INTO airtime_to_cash (user_id,transaction_ref,network,airtime_amount,cash_amount,discount_percent,sender_phone,our_number,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())")
        ->execute([$a2cUid, $a2cRef2, 'MTN', 500, 325, 65, '08055556666', '08033334444', 'pending']);
    if ($pricingCsrf) {
        $beforeRej = (int)db()->query("SELECT COUNT(*) FROM admin_activity_logs WHERE admin_id=$testAdminId AND action='REJECT_A2C'")->fetchColumn();
        $r = http('POST', '/grace-hub-tels/service-pricing', ['csrf' => $pricingCsrf, 'action' => 'reject', 'ref' => $a2cRef2, 'reason' => 'test'], ["User-Agent: $fixedUA", 'X-Requested-With: XMLHttpRequest'], $jar);
        $d = json_decode($r['body'], true);
        check("Normal reject (still pending) succeeds", ($d['success'] ?? false) === true, "body={$r['body']}");
        $afterRej = (int)db()->query("SELECT COUNT(*) FROM admin_activity_logs WHERE admin_id=$testAdminId AND action='REJECT_A2C'")->fetchColumn();
        check("REJECT_A2C audit_log entry was written", $afterRej === $beforeRej + 1, "before=$beforeRej after=$afterRej");
        $status2 = db()->query("SELECT status FROM airtime_to_cash WHERE transaction_ref='$a2cRef2'")->fetchColumn();
        check("A2C row correctly resolved to 'failed'", $status2 === 'failed', "status=$status2");
    }

} finally {
    echo "\n[cleanup] removing test fixtures\n";
    db()->exec("DELETE FROM transactions WHERE transaction_ref LIKE 'sectestfr_%{$RUNID}%'");
    db()->exec("DELETE FROM airtime_to_cash WHERE transaction_ref LIKE 'sectestfr_%{$RUNID}%'");
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
    @unlink(sys_get_temp_dir() . "/sectestfr_cookies_{$RUNID}.txt");
}

echo "\n=== RESULTS: $PASS passed, $FAIL failed ===\n";
if ($FAILURES) { echo "\nFailed checks:\n"; foreach ($FAILURES as $f) echo " - $f\n"; }
exit($FAIL > 0 ? 1 : 0);
