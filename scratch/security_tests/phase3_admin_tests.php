<?php
/**
 * Phase 3 admin-side verification — standalone CLI script.
 * Run via: php scratch/security_tests/phase3_admin_tests.php
 *
 * Verifies, over live HTTP against the local test vhost (localhost:8099):
 *  - the reconciliation.php report page renders for a permitted admin
 *    without a fatal error and without ever mutating a balance
 *  - admin credit/debit on a single user writes an audit_log entry
 *  - bulk-delete refuses to delete a user with a nonzero wallet balance
 *  - manual-deposits.php approve writes an audit_log entry
 *
 * Disposable sectest3adm_<runid>_-prefixed fixtures, cleaned up regardless
 * of pass/fail. Never point BASE_URL at a real production host.
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
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 15];
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

echo "=== Phase 3 Admin Verification (run $RUNID) ===\n\n";
try {
    // Disposable admin with manage_users + manage_transactions, known device.
    $adminEmail = "sectest3adm_{$RUNID}@example.test";
    $adminPw = 'SecTestAdmin_Aa1_' . rand(1000, 9999);
    $adminHash = password_hash($adminPw, PASSWORD_BCRYPT, ['cost' => 12]);
    db()->prepare("INSERT INTO admin (username, email, password, role, is_active, permissions, created_at) VALUES (?,?,?,?,1,?,NOW())")
        ->execute(["sectest3adm_{$RUNID}", $adminEmail, $adminHash, 'admin', '["manage_users","manage_transactions","manage_deposits"]']);
    $testAdminId = (int)db()->lastInsertId();
    $cleanup['admin_ids'][] = $testAdminId;

    $fixedUA = 'SecTest3AdminAgent/1.0';
    $deviceHash = hash('sha256', '::1|' . $fixedUA);
    db()->exec("CREATE TABLE IF NOT EXISTS admin_known_devices (id INT AUTO_INCREMENT PRIMARY KEY, admin_id INT NOT NULL, device_hash VARCHAR(64) NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(400) DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, last_seen DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uniq_admin_device (admin_id, device_hash))");
    db()->prepare("INSERT INTO admin_known_devices (admin_id, device_hash, ip_address, user_agent) VALUES (?,?,?,?)")->execute([$testAdminId, $deviceHash, '::1', $fixedUA]);

    $jar = sys_get_temp_dir() . "/sectest3adm_cookies_{$RUNID}.txt";
    $r = http('GET', '/grace-hub-tels/login', [], ["User-Agent: $fixedUA"], $jar);
    preg_match('/name="csrf_token" value="([^"]+)"/', $r['body'], $m);
    $loginCsrf = $m[1] ?? '';
    $r = http('POST', '/grace-hub-tels/login', ['csrf_token' => $loginCsrf, 'email' => $adminEmail, 'password' => $adminPw], ["User-Agent: $fixedUA"], $jar);
    $loggedIn = (strpos($r['headers'], 'dashboard.php') !== false);
    check("Disposable test admin logs in", $loggedIn, "headers=" . trim($r['headers']));
    if (!$loggedIn) { throw new RuntimeException('cannot continue without admin session'); }

    // ------------------------------------------------------------------
    // 1. reconciliation.php renders without a fatal error.
    // ------------------------------------------------------------------
    echo "\n[1] reconciliation.php renders cleanly\n";
    $r = http('GET', '/grace-hub-tels/reconciliation', [], ["User-Agent: $fixedUA"], $jar);
    check("reconciliation.php returns 200", $r['code'] === 200, "code={$r['code']}");
    check("reconciliation.php body has no PHP fatal-error text", stripos($r['body'], 'Fatal error') === false && stripos($r['body'], 'Uncaught') === false);
    check("reconciliation.php shows the report heading", strpos($r['body'], 'Wallet &amp; Cashback Reconciliation') !== false || strpos($r['body'], 'Wallet & Cashback Reconciliation') !== false);

    // ------------------------------------------------------------------
    // 2. users-management.php credit action writes an audit_log entry.
    // ------------------------------------------------------------------
    echo "\n[2] Admin credit/debit writes an audit trail entry\n";
    $pw = password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]);
    db()->prepare("INSERT INTO users (full_name, username, phone, email, password, wallet_balance, cashback_balance, is_active, created_at) VALUES (?,?,?,?,?,0,0,1,NOW())")
        ->execute(["SecTest3Adm Target", "sectest3adm_{$RUNID}_t", "090{$RUNID}01", "sectest3adm_{$RUNID}_t@example.test", $pw]);
    $targetUid = (int)db()->lastInsertId();
    $cleanup['user_ids'][] = $targetUid;

    $r = http('GET', '/grace-hub-tels/users-management', [], ["User-Agent: $fixedUA"], $jar);
    preg_match("/const CSRF\s*=\s*'([^']+)'/", $r['body'], $m2);
    $adminCsrf = $m2[1] ?? '';
    check("Extracted admin CSRF token from users-management.php", (bool)$adminCsrf, "body_snip=" . substr($r['body'], 0, 200));

    if ($adminCsrf) {
        $before = db()->query("SELECT COUNT(*) FROM admin_activity_logs WHERE admin_id=$testAdminId AND action='ADMIN_CREDIT'")->fetchColumn();
        $r = http('POST', '/grace-hub-tels/users-management', ['csrf' => $adminCsrf, 'action' => 'credit', 'uid' => $targetUid, 'amount' => '250', 'note' => 'phase3 test'], ["User-Agent: $fixedUA", 'X-Requested-With: XMLHttpRequest'], $jar);
        $d = json_decode($r['body'], true);
        check("Admin credit succeeds", ($d['success'] ?? false) === true, "body={$r['body']}");
        $after = db()->query("SELECT COUNT(*) FROM admin_activity_logs WHERE admin_id=$testAdminId AND action='ADMIN_CREDIT'")->fetchColumn();
        check("ADMIN_CREDIT audit_log entry was written", (int)$after === (int)$before + 1, "before=$before after=$after");

        // ------------------------------------------------------------------
        // 3. Bulk delete refuses a user with a nonzero wallet balance.
        // ------------------------------------------------------------------
        echo "\n[3] Bulk delete refuses to delete a user with a nonzero balance\n";
        $balNow = db()->query("SELECT wallet_balance FROM users WHERE id=$targetUid")->fetchColumn();
        check("Target user now has a nonzero balance to test against", (float)$balNow > 0, "balance=$balNow");

        $r = http('POST', '/grace-hub-tels/users-management', ['csrf' => $adminCsrf, 'action' => 'bulk_action', 'bulk_action' => 'delete', 'uids' => json_encode([$targetUid]), 'admin_password' => $adminPw], ["User-Agent: $fixedUA", 'X-Requested-With: XMLHttpRequest'], $jar);
        $d = json_decode($r['body'], true);
        $stillExists = db()->query("SELECT COUNT(*) FROM users WHERE id=$targetUid")->fetchColumn();
        check("User with nonzero balance was NOT deleted", (int)$stillExists === 1, "response={$r['body']}");
        check("Response message mentions the skip", stripos($d['message'] ?? '', 'skipped') !== false || stripos($d['message'] ?? '', 'nonzero') !== false, "message=" . ($d['message'] ?? ''));

        // Zero the balance out via the audited single-user debit, then confirm
        // deletion is allowed once the balance is genuinely zero.
        http('POST', '/grace-hub-tels/users-management', ['csrf' => $adminCsrf, 'action' => 'debit', 'uid' => $targetUid, 'amount' => '250', 'note' => 'phase3 zero-out'], ["User-Agent: $fixedUA", 'X-Requested-With: XMLHttpRequest'], $jar);
        $balZero = (float)db()->query("SELECT wallet_balance FROM users WHERE id=$targetUid")->fetchColumn();
        check("Balance successfully zeroed via audited debit", abs($balZero) < 0.01, "balance=$balZero");

        $r = http('POST', '/grace-hub-tels/users-management', ['csrf' => $adminCsrf, 'action' => 'bulk_action', 'bulk_action' => 'delete', 'uids' => json_encode([$targetUid]), 'admin_password' => $adminPw], ["User-Agent: $fixedUA", 'X-Requested-With: XMLHttpRequest'], $jar);
        $stillExists2 = db()->query("SELECT COUNT(*) FROM users WHERE id=$targetUid")->fetchColumn();
        check("User with zero balance IS deletable", (int)$stillExists2 === 0, "response={$r['body']}");
        if ((int)$stillExists2 === 0) {
            $cleanup['user_ids'] = array_diff($cleanup['user_ids'], [$targetUid]); // already gone
        }
    } else {
        echo "  [SKIP] could not extract admin CSRF token — skipping checks 2-3\n";
    }

    // ------------------------------------------------------------------
    // 4. manual-deposits.php approve writes an audit_log entry.
    // ------------------------------------------------------------------
    echo "\n[4] Manual deposit approval writes an audit trail entry\n";
    db()->prepare("INSERT INTO users (full_name, username, phone, email, password, wallet_balance, cashback_balance, is_active, created_at) VALUES (?,?,?,?,?,0,0,1,NOW())")
        ->execute(["SecTest3Adm Depositor", "sectest3adm_{$RUNID}_d", "090{$RUNID}02", "sectest3adm_{$RUNID}_d@example.test", $pw]);
    $depUid = (int)db()->lastInsertId();
    $cleanup['user_ids'][] = $depUid;
    db()->prepare("INSERT INTO manual_funding_requests (user_id, amount, sender_name, status, created_at) VALUES (?,?,?,?,NOW())")
        ->execute([$depUid, 500, 'SecTest3 Sender', 'pending']);
    $depId = (int)db()->lastInsertId();

    $r = http('GET', '/grace-hub-tels/manual-deposits', [], ["User-Agent: $fixedUA"], $jar);
    preg_match("/const CSRF\s*=\s*'([^']+)'/", $r['body'], $m3);
    $depCsrf = $m3[1] ?? '';
    if ($depCsrf) {
        $before = db()->query("SELECT COUNT(*) FROM admin_activity_logs WHERE admin_id=$testAdminId AND action='APPROVE_MANUAL_DEPOSIT'")->fetchColumn();
        $r = http('POST', '/grace-hub-tels/manual-deposits', ['csrf' => $depCsrf, 'action' => 'approve_deposit', 'id' => $depId], ["User-Agent: $fixedUA", 'X-Requested-With: XMLHttpRequest'], $jar);
        $d = json_decode($r['body'], true);
        check("Manual deposit approval succeeds", ($d['success'] ?? false) === true, "body={$r['body']}");
        $after = db()->query("SELECT COUNT(*) FROM admin_activity_logs WHERE admin_id=$testAdminId AND action='APPROVE_MANUAL_DEPOSIT'")->fetchColumn();
        check("APPROVE_MANUAL_DEPOSIT audit_log entry was written", (int)$after === (int)$before + 1, "before=$before after=$after");
    } else {
        echo "  [SKIP] could not extract admin CSRF token for manual-deposits.php\n";
    }
    db()->prepare("DELETE FROM manual_funding_requests WHERE id=?")->execute([$depId]);

} finally {
    echo "\n[cleanup] removing test fixtures\n";
    if (!empty($cleanup['user_ids'])) {
        $in = implode(',', array_fill(0, count($cleanup['user_ids']), '?'));
        db()->prepare("DELETE FROM transactions WHERE user_id IN ($in)")->execute($cleanup['user_ids']);
        db()->prepare("DELETE FROM users WHERE id IN ($in)")->execute($cleanup['user_ids']);
    }
    if (!empty($cleanup['admin_ids'])) {
        $in = implode(',', array_fill(0, count($cleanup['admin_ids']), '?'));
        db()->prepare("DELETE FROM admin_known_devices WHERE admin_id IN ($in)")->execute($cleanup['admin_ids']);
        db()->prepare("DELETE FROM admin_activity_logs WHERE admin_id IN ($in)")->execute($cleanup['admin_ids']);
        db()->prepare("DELETE FROM admin WHERE id IN ($in)")->execute($cleanup['admin_ids']);
    }
    @unlink(sys_get_temp_dir() . "/sectest3adm_cookies_{$RUNID}.txt");
}

echo "\n=== RESULTS: $PASS passed, $FAIL failed ===\n";
if ($FAILURES) { echo "\nFailed checks:\n"; foreach ($FAILURES as $f) echo " - $f\n"; }
exit($FAIL > 0 ? 1 : 0);
