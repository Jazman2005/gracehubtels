<?php
// Helper process spawned by phase2a_security_tests.php to fire concurrent
// rate_limit() calls from separate PHP processes (a single process can't
// exercise the flock-based race condition against itself the same way).
declare(strict_types=1);
require_once __DIR__ . '/../../user/core/db.php';

$key = $argv[1] ?? 'sectest_race';
$max = (int)($argv[2] ?? 5);

echo rate_limit($key, $max, 60) ? 'ALLOWED' : 'DENIED';
