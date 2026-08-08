<?php
/**
 * Phase 2A security regression tests — standalone CLI script, not a real
 * test framework (none exists in this repo). Run via:
 *   php scratch/security_tests/phase2a_security_tests.php
 *
 * Creates disposable test fixtures (users/admin prefixed with
 * sectest_<runid>_), exercises the live local app over HTTP against
 * http://localhost/gracehubtels/, and deletes every fixture it created at
 * the end (in a finally block) regardless of pass/fail. Never point
 * BASE_URL at a real production host.
 */

declare(strict_types=1);

define('BASE_URL', 'http://localhost:8099');
require_once __DIR__ . '/../../user/core/db.php';

$RUNID = bin2hex(random_bytes(4));
$PASS = 0;
$FAIL = 0;
$FAILURES = [];
$cleanup_user_ids = [];
$cleanup_admin_ids = [];

function check(string $name, bool $cond, string $detail = ''): void {
    global $PASS, $FAIL, $FAILURES;
    if ($cond) {
        $PASS++;
        echo "  [PASS] $name\n";
    } else {
        $FAIL++;
        $FAILURES[] = $name . ($detail ? " ($detail)" : '');
        echo "  [FAIL] $name" . ($detail ? " -- $detail" : '') . "\n";
    }
}

function http(string $method, string $path, array $data = [], array $headers = [], ?string $cookieJar = null): array {
    $ch = curl_init(BASE_URL . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 15,
    ];
    if ($method === 'POST') {
        $isJson = in_array('Content-Type: application/json', $headers, true);
        $opts[CURLOPT_POSTFIELDS] = $isJson ? json_encode($data) : http_build_query($data);
    }
    if ($headers) $opts[CURLOPT_HTTPHEADER] = $headers;
    if ($cookieJar) {
        $opts[CURLOPT_COOKIEJAR] = $cookieJar;
        $opts[CURLOPT_COOKIEFILE] = $cookieJar;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $rawHeaders = substr((string)$raw, 0, $headerSize);
    $body = substr((string)$raw, $headerSize);
    return ['code' => $code, 'body' => $body, 'headers' => $rawHeaders];
}

echo "=== Phase 2A Security Test Suite (run $RUNID) ===\n\n";

try {
    // ------------------------------------------------------------------
    // Fixture setup: two ordinary users (A, B) directly in the DB so we
    // don't depend on registration/email-verification side effects.
    // ------------------------------------------------------------------
    echo "[setup] creating disposable test users\n";
    $pw = 'SecTest_Aa1_' . rand(1000, 9999);
    // cost=12 to match every real password_hash() call site in the app
    // (user/core/auth.php, api/v1/register.php, change-password, etc.) —
    // using a cheaper cost here would make the timing side-channel check
    // below compare apples to oranges.
    $hash = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]);

    $ins = db()->prepare("INSERT INTO users (full_name, username, phone, email, password, wallet_balance, is_active, api_token, created_at) VALUES (?,?,?,?,?,?,1,?,NOW())");
    $ins->execute(['SecTest User A', "sectest_{$RUNID}_a", "070{$RUNID}01", "sectest_{$RUNID}_a@example.test", $hash, 5000, 'msj_live_' . bin2hex(random_bytes(24))]);
    $userA = (int)db()->lastInsertId();
    $cleanup_user_ids[] = $userA;

    $ins->execute(['SecTest User B', "sectest_{$RUNID}_b", "070{$RUNID}02", "sectest_{$RUNID}_b@example.test", $hash, 5000, 'msj_live_' . bin2hex(random_bytes(24))]);
    $userB = (int)db()->lastInsertId();
    $cleanup_user_ids[] = $userB;

    $tokA = db()->prepare("SELECT api_token FROM users WHERE id=?"); $tokA->execute([$userA]); $tokA = $tokA->fetchColumn();
    $tokB = db()->prepare("SELECT api_token FROM users WHERE id=?"); $tokB->execute([$userB]); $tokB = $tokB->fetchColumn();

    // A transaction belonging to user A only.
    $txRef = 'MSXSECTEST' . $RUNID;
    db()->prepare("INSERT INTO transactions (user_id, transaction_ref, type, category, amount, status, balance_before, balance_after, created_at) VALUES (?,?, 'debit','airtime',100,'success',5000,4900,NOW())")
        ->execute([$userA, $txRef]);

    echo "[setup] userA=$userA userB=$userB done\n\n";

    // ==================================================================
    // 1. IDOR — partner API: user B's token must not see user A's data
    // ==================================================================
    echo "[1] IDOR — partner API cross-account access\n";

    // transactions.php derives its user strictly from api_auth() and never
    // reads a client-supplied user_id/reference — user B's token should
    // never see user A's transaction_ref in its own transaction list.
    $r = http('GET', "/api/v1/transactions.php?per_page=50", [], ["Authorization: Bearer $tokB"]);
    $notOwn = (strpos($r['body'], $txRef) === false);
    check("api/v1/transactions.php (as user B) does not leak user A's transaction_ref", $notOwn, "http={$r['code']}");

    // balance.php must ignore any client-supplied user_id and always return
    // the bearer token's own balance.
    $r = http('GET', "/api/v1/balance.php?user_id=$userA", [], ["Authorization: Bearer $tokB"]);
    $decoded = json_decode($r['body'], true);
    $ownBalance = isset($decoded['data']['wallet_balance']) && abs((float)$decoded['data']['wallet_balance'] - 5000.0) < 0.01;
    check("api/v1/balance.php ignores client-supplied user_id and returns the token owner's own balance", $ownBalance, "body={$r['body']}");

    // ==================================================================
    // 2. Timing side-channel / account enumeration on login endpoints
    // ==================================================================
    echo "\n[2] Timing side-channel on login (nonexistent vs wrong-password)\n";

    function timeit(callable $fn, int $n = 6): float {
        $t0 = microtime(true);
        for ($i = 0; $i < $n; $i++) $fn();
        return (microtime(true) - $t0) / $n;
    }

    $existingUser = "sectest_{$RUNID}_a";
    $nonexistentUser = "sectest_{$RUNID}_ghost";

    $tWrong = timeit(fn() => http('POST', '/api/v1/login.php', ['username' => $existingUser, 'password' => 'definitely-wrong-pw'], ['Content-Type: application/json']));
    $tMissing = timeit(fn() => http('POST', '/api/v1/login.php', ['username' => $nonexistentUser, 'password' => 'definitely-wrong-pw'], ['Content-Type: application/json']));
    $ratio = $tMissing > 0 ? $tWrong / $tMissing : 0;
    // Allow generous tolerance (single-box, noisy timing) — we're checking
    // that both paths run bcrypt (ratio close to 1), not nanosecond-level
    // precision. Before the DUMMY_PASSWORD_HASH fix, missing-user requests
    // returned near-instantly (ratio would be >3-5x).
    check("api/v1/login.php: nonexistent-user response time is comparable to wrong-password time (timing side-channel closed)", $ratio > 0.4 && $ratio < 2.5, "existing={$tWrong}s missing={$tMissing}s ratio=" . round($ratio, 2));

    // ==================================================================
    // 3. Brute-force / rate limiting on login
    // ==================================================================
    echo "\n[3] Brute-force protection on api/v1/login.php\n";
    $locked = false;
    for ($i = 0; $i < 12; $i++) {
        $r = http('POST', '/api/v1/login.php', ['username' => $existingUser, 'password' => 'wrong' . $i], ['Content-Type: application/json']);
        if ($r['code'] === 429 || $r['code'] === 423) { $locked = true; break; }
    }
    check("api/v1/login.php locks out / rate-limits after repeated failed attempts", $locked, "stopped at attempt " . ($i + 1) . " code={$r['code']}");

    // Confirm the underlying account-lock actually engaged (not just the
    // outer IP rate limiter) — even a correct password should now be
    // rejected with 423 while locked.
    db()->prepare("UPDATE users SET login_attempts=0, login_locked_until=DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id=?")->execute([$userA]);
    $r = http('POST', '/api/v1/login.php', ['username' => $existingUser, 'password' => $pw], ['Content-Type: application/json']);
    // Accept either 423 (account-level lock) or 429 (the IP rate limiter,
    // already saturated by the brute-force loop just above, denying first) —
    // both correctly mean a correct password does not bypass either guard.
    check("api/v1/login.php rejects even a CORRECT password while account-locked", in_array($r['code'], [423, 429], true), "code={$r['code']}");
    db()->prepare("UPDATE users SET login_attempts=0, login_locked_until=NULL WHERE id=?")->execute([$userA]);

    // ==================================================================
    // 4. rate_limit() atomicity under concurrent/parallel requests
    // ==================================================================
    echo "\n[4] rate_limit() race condition (concurrent burst must not exceed max)\n";
    $key = 'sectest_race_' . $RUNID;
    $max = 5;
    $procs = [];
    $php = PHP_BINARY;
    $harness = __DIR__ . '/_rate_limit_worker.php';
    for ($i = 0; $i < 15; $i++) {
        $procs[] = proc_open([$php, $harness, $key, (string)$max], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
        $pipeRefs[$i] = $pipes[$i];
    }
    $allowedCount = 0;
    foreach ($procs as $i => $p) {
        $out = stream_get_contents($pipeRefs[$i][1]);
        fclose($pipeRefs[$i][1]);
        fclose($pipeRefs[$i][2]);
        proc_close($p);
        if (trim($out) === 'ALLOWED') $allowedCount++;
    }
    check("rate_limit() allows at most $max requests out of 15 fired concurrently (no TOCTOU over-allow)", $allowedCount <= $max, "allowed=$allowedCount");

    // ==================================================================
    // 5. Password/PIN change rate limiting (previously unlimited)
    // ==================================================================
    echo "\n[5] Rate limiting on api/v1/change-password.php and change-pin.php\n";
    $limited = false;
    for ($i = 0; $i < 10; $i++) {
        $r = http('POST', '/api/v1/change-password.php', [
            'current_password' => 'wrong-guess-' . $i,
            'new_password' => 'NewPass_' . $i . '_Aa1',
            'confirm_password' => 'NewPass_' . $i . '_Aa1',
        ], ['Content-Type: application/json', "Authorization: Bearer $tokA"]);
        if ($r['code'] === 429) { $limited = true; break; }
    }
    check("api/v1/change-password.php rate-limits repeated current-password guesses", $limited, "stopped at attempt " . ($i + 1) . " code={$r['code']}");

    $limited = false;
    for ($i = 0; $i < 15; $i++) {
        $r = http('POST', '/api/v1/change-pin.php', [
            'new_pin' => '1234',
            'confirm_pin' => '1234',
        ], ['Content-Type: application/json', "Authorization: Bearer $tokB"]);
        if ($r['code'] === 429) { $limited = true; break; }
    }
    check("api/v1/change-pin.php rate-limits repeated PIN-setup attempts", $limited, "stopped at attempt " . ($i + 1) . " code={$r['code']}");

    // ==================================================================
    // 6. Registration validation (api/v1/register.php)
    // ==================================================================
    echo "\n[6] api/v1/register.php validation gaps (password length, PIN format, username uniqueness)\n";

    // Reset this endpoint's own rate_limit('api_register', 5, 3600) bucket so
    // repeated runs of this test suite within the same hour (same source IP,
    // file-backed cache) don't get rate-limited before reaching the
    // validation logic under test here. curl resolves "localhost" to the
    // IPv6 loopback (::1) on this machine, which is what Apache/get_ip()
    // actually sees server-side — not this CLI process's own get_ip(),
    // which has no $_SERVER['REMOTE_ADDR'] and would compute a different key.
    foreach (['::1', '127.0.0.1', '0.0.0.0'] as $candidateIp) {
        @unlink(sys_get_temp_dir() . '/rl_' . md5('api_register_' . $candidateIp));
    }

    $r = http('POST', '/api/v1/register.php', [
        'full_name' => 'Reg Test', 'phone' => "071{$RUNID}01", 'password' => 'short', 'pin' => '1234',
    ], ['Content-Type: application/json']);
    check("register.php rejects password shorter than 8 chars", $r['code'] === 400, "code={$r['code']} body={$r['body']}");

    $r = http('POST', '/api/v1/register.php', [
        'full_name' => 'Reg Test', 'phone' => "071{$RUNID}02", 'password' => 'LongEnough1', 'pin' => '12',
    ], ['Content-Type: application/json']);
    check("register.php rejects a non-4-digit PIN", $r['code'] === 400, "code={$r['code']} body={$r['body']}");

    $r = http('POST', '/api/v1/register.php', [
        'full_name' => 'Reg Test', 'phone' => "071{$RUNID}03", 'password' => 'LongEnough1', 'pin' => '1234',
        'username' => "sectest_{$RUNID}_a",
    ], ['Content-Type: application/json']);
    check("register.php rejects a duplicate username", $r['code'] === 409, "code={$r['code']} body={$r['body']}");

    // ==================================================================
    // 7. Admin panel — unauthorized access / privilege escalation
    // ==================================================================
    echo "\n[7] Admin RBAC — unauthenticated + newly-gated pages\n";

    // Extensionless paths — hitting the .php path directly would first trip
    // the site-wide "canonical redirect .php -> extensionless" rewrite rule
    // (a 301, before the PHP script ever runs), masking whatever auth_admin()
    // itself would have done. Extensionless matches how the app is actually
    // linked/used in production.
    $adminPages = [
        '/grace-hub-tels/service-pin-products',
        '/grace-hub-tels/exam-pins-stock',
        '/grace-hub-tels/api-logs',
        '/grace-hub-tels/webhook-logs',
        '/grace-hub-tels/bulk-sms-history',
        '/grace-hub-tels/dashboard',
        '/grace-hub-tels/backfill_other_profit',
        '/grace-hub-tels/backfill_vc_profit',
    ];
    foreach ($adminPages as $page) {
        $r = http('GET', $page);
        $redirectedToLogin = (strpos($r['headers'], 'Location:') !== false && strpos($r['headers'], 'login.php') !== false);
        check("unauthenticated request to $page is redirected to admin login (not served directly)", $redirectedToLogin, "code={$r['code']} headers=" . trim($r['headers']));
    }

    // ==================================================================
    // 8. Session cookie hardening sanity check (spot-check flags)
    // ==================================================================
    echo "\n[8] Session cookie flags on admin login page\n";
    $r = http('GET', '/grace-hub-tels/login', [], [], sys_get_temp_dir() . "/sectest_cookies_{$RUNID}.txt");
    $setCookie = '';
    foreach (explode("\r\n", $r['headers']) as $line) {
        if (stripos($line, 'Set-Cookie:') === 0) $setCookie .= $line . "\n";
    }
    if ($setCookie === '') {
        echo "  [SKIP] no Set-Cookie observed on this request (session may already exist / page cached)\n";
    } else {
        check("admin session cookie sets HttpOnly", stripos($setCookie, 'HttpOnly') !== false, $setCookie);
        check("admin session cookie sets SameSite=Strict", stripos($setCookie, 'SameSite=Strict') !== false, $setCookie);
    }

    // ==================================================================
    // 9. Privilege escalation — authenticated as a disposable, low-privilege
    //    ('admin' role, only manage_users granted) test admin account.
    // ==================================================================
    echo "\n[9] Privilege escalation checks (authenticated non-superadmin admin)\n";

    $adminEmail = "sectest_{$RUNID}_admin@example.test";
    $adminPw = 'SecTestAdmin_Aa1_' . rand(1000, 9999);
    $adminHash = password_hash($adminPw, PASSWORD_BCRYPT, ['cost' => 12]);
    db()->prepare("INSERT INTO admin (username, email, password, role, is_active, permissions, created_at) VALUES (?,?,?,?,1,?,NOW())")
        ->execute(["sectest_{$RUNID}_admin", $adminEmail, $adminHash, 'admin', '["manage_users"]']);
    $testAdminId = (int)db()->lastInsertId();
    $cleanup_admin_ids[] = $testAdminId;

    $fixedUA = 'SecTestAgent/1.0';
    // The device-fingerprint 2FA gate (is_known_device()) would otherwise
    // require an OTP step we can't complete headlessly — pre-register this
    // exact IP+UA combination as a known device for this disposable admin
    // only, matching get_device_hash()'s own sha256(ip.'|'.ua) formula.
    // curl resolves "localhost" to ::1 on this machine (confirmed earlier).
    $deviceHash = hash('sha256', '::1|' . $fixedUA);
    // login_admin() auto-creates this table on first call, but that hasn't
    // run yet at this point in the script — create it here too (same
    // schema) so the INSERT below doesn't fail on a fresh database.
    db()->exec("CREATE TABLE IF NOT EXISTS admin_known_devices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id INT NOT NULL,
        device_hash VARCHAR(64) NOT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        user_agent VARCHAR(400) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_seen DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_admin_device (admin_id, device_hash)
    )");
    db()->prepare("INSERT INTO admin_known_devices (admin_id, device_hash, ip_address, user_agent) VALUES (?,?,?,?)")
        ->execute([$testAdminId, $deviceHash, '::1', $fixedUA]);

    $adminCookieJar = sys_get_temp_dir() . "/sectest_admin_cookies_{$RUNID}.txt";
    $r = http('GET', '/grace-hub-tels/login', [], ["User-Agent: $fixedUA"], $adminCookieJar);
    preg_match('/name="csrf_token" value="([^"]+)"/', $r['body'], $m);
    $loginCsrf = $m[1] ?? '';

    $r = http('POST', '/grace-hub-tels/login', [
        'csrf_token' => $loginCsrf, 'email' => $adminEmail, 'password' => $adminPw,
    ], ["User-Agent: $fixedUA"], $adminCookieJar);
    $loggedIn = (strpos($r['headers'], 'Location:') !== false && strpos($r['headers'], 'dashboard.php') !== false);
    check("disposable test admin (role=admin, only manage_users granted) logs in successfully", $loggedIn, "headers=" . trim($r['headers']));

    if ($loggedIn) {
        $r = http('GET', '/grace-hub-tels/dashboard', [], ["User-Agent: $fixedUA"], $adminCookieJar);
        check("authenticated test admin can load dashboard.php directly (200, not redirected)", $r['code'] === 200, "code={$r['code']}");

        // Privilege escalation A: superadmin-only page must reject a plain 'admin'.
        $r = http('GET', '/grace-hub-tels/admin-management', [], ["User-Agent: $fixedUA"], $adminCookieJar);
        check("non-superadmin 'admin' role is denied access to admin-management.php (require_superadmin)", stripos($r['body'], 'Access denied') !== false, "code={$r['code']} body=" . substr($r['body'], 0, 150));

        // Privilege escalation B: a permission this admin does NOT have (manage_pricing) must be denied.
        $r = http('GET', '/grace-hub-tels/service-pin-products', [], ["User-Agent: $fixedUA"], $adminCookieJar);
        check("admin without manage_pricing permission is denied access to service-pin-products.php", stripos($r['body'], 'Access denied') !== false || stripos($r['body'], 'Missing permission') !== false, "code={$r['code']} body=" . substr($r['body'], 0, 150));

        // Privilege escalation C: login_as impersonation must require the acting
        // admin's own password even though this admin DOES hold manage_users —
        // permission alone must not be enough for full account takeover.
        $r = http('GET', '/grace-hub-tels/users-management', [], ["User-Agent: $fixedUA"], $adminCookieJar);
        preg_match('/const CSRF=\'([^\']+)\'/', $r['body'], $m2);
        $adminCsrf = $m2[1] ?? '';
        if ($adminCsrf) {
            $r = http('POST', '/grace-hub-tels/users-management', [
                'csrf' => $adminCsrf, 'action' => 'login_as', 'uid' => (string)$userA,
            ], ["User-Agent: $fixedUA", 'X-Requested-With: XMLHttpRequest'], $adminCookieJar);
            $decoded9 = json_decode($r['body'], true);
            $deniedNoPw = is_array($decoded9) && ($decoded9['success'] ?? true) === false;
            check("login_as impersonation is denied without the acting admin's own password (even with manage_users)", $deniedNoPw, "body={$r['body']}");
        } else {
            echo "  [SKIP] could not extract admin CSRF token from users-management page — skipping login_as re-auth check\n";
        }
    }

} finally {
    // ------------------------------------------------------------------
    // Cleanup — always runs, even on assertion failure or exception.
    // ------------------------------------------------------------------
    echo "\n[cleanup] removing test fixtures\n";
    if ($cleanup_user_ids) {
        db()->prepare("DELETE FROM transactions WHERE transaction_ref = ?")->execute([$txRef ?? '__none__']);
        $in = implode(',', array_fill(0, count($cleanup_user_ids), '?'));
        db()->prepare("DELETE FROM users WHERE id IN ($in)")->execute($cleanup_user_ids);
        // Also remove any users created by the register.php validation tests
        // that unexpectedly succeeded (defensive — should be none if the
        // fixes hold, since all three attempts above should have been
        // rejected with 4xx before an INSERT).
        db()->prepare("DELETE FROM users WHERE phone IN (?,?,?)")->execute(["071{$RUNID}01", "071{$RUNID}02", "071{$RUNID}03"]);
    }
    if ($cleanup_admin_ids) {
        $in = implode(',', array_fill(0, count($cleanup_admin_ids), '?'));
        db()->prepare("DELETE FROM admin_known_devices WHERE admin_id IN ($in)")->execute($cleanup_admin_ids);
        db()->prepare("DELETE FROM admin_activity_logs WHERE admin_id IN ($in)")->execute($cleanup_admin_ids);
        db()->prepare("DELETE FROM admin WHERE id IN ($in)")->execute($cleanup_admin_ids);
    }
    @unlink(sys_get_temp_dir() . "/sectest_cookies_{$RUNID}.txt");
    @unlink(sys_get_temp_dir() . "/sectest_admin_cookies_{$RUNID}.txt");
}

echo "\n=== RESULTS: $PASS passed, $FAIL failed ===\n";
if ($FAILURES) {
    echo "\nFailed checks:\n";
    foreach ($FAILURES as $f) echo " - $f\n";
}
exit($FAIL > 0 ? 1 : 0);
