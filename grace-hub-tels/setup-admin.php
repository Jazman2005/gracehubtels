<?php
// One-time first-superadmin bootstrap — the ONLY way to create an admin
// account on a fresh install, since msjpay.sql ships with a genuinely empty
// `admin` table and there is deliberately no public admin-signup page.
// Gated two ways: (1) VENDOR_ACCESS_KEY, same per-install secret used by
// vendor-access.php, so only whoever ran the install (and has
// db_config.php) can reach this even during the brief bootstrap window;
// (2) the admin table must be genuinely empty — the moment one admin
// account exists, this page 404s forever, same as if it never existed.
// Deliberately does NOT touch admin_session()/auth_admin() — there is no
// session to check yet.
require_once dirname(__DIR__) . '/user/core/db.php';

$key = $_POST['key'] ?? $_GET['key'] ?? '';
if (!defined('VENDOR_ACCESS_KEY') || VENDOR_ACCESS_KEY === '' || !hash_equals(VENDOR_ACCESS_KEY, (string)$key)) {
    http_response_code(404);
    exit;
}

$existing = (int)db()->query("SELECT COUNT(*) FROM admin")->fetchColumn();
if ($existing > 0) {
    http_response_code(404);
    exit;
}

$error = '';
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
        $error = 'Username must be 3-50 characters (letters, numbers, underscore only).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        // Re-check under the same race the settings page itself is careful
        // about — two people opening this page at once must not both
        // succeed. A UNIQUE constraint on the table isn't guaranteed here
        // (msjpay.sql may or may not have one on username/email), so the
        // count-check is re-verified immediately before the insert too.
        $existing2 = (int)db()->query("SELECT COUNT(*) FROM admin")->fetchColumn();
        if ($existing2 > 0) {
            http_response_code(404);
            exit;
        }
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        db()->prepare("INSERT INTO admin (username, email, password, role, is_active, created_at) VALUES (?, ?, ?, 'superadmin', 1, NOW())")
            ->execute([$username, $email, $hash]);
        $done = true;
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>First Admin Setup</title>
<style>
    body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:40px 20px;}
    .wrap{max-width:420px;margin:0 auto;}
    h1{font-size:20px;margin:0 0 4px;}
    .sub{color:#94a3b8;font-size:13px;margin:0 0 24px;}
    label{display:block;font-size:12px;font-weight:600;color:#94a3b8;margin-bottom:6px;margin-top:16px;}
    input{width:100%;box-sizing:border-box;padding:12px 14px;background:#1e293b;border:1px solid #334155;border-radius:8px;color:#e2e8f0;font-size:14px;}
    button{margin-top:24px;width:100%;padding:14px;background:#2563eb;color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;}
    button:hover{background:#1d4ed8;}
    .error{background:#7f1d1d;border:1px solid #dc2626;color:#fecaca;padding:12px 16px;border-radius:8px;font-size:13px;margin-bottom:8px;}
    .success{background:#14532d;border:1px solid #16a34a;color:#bbf7d0;padding:16px;border-radius:8px;font-size:13px;}
</style>
</head>
<body>
<div class="wrap">
    <h1>Create the First Admin Account</h1>
    <p class="sub">This page only works once, while no admin account exists yet — it disables itself permanently the moment this succeeds.</p>

    <?php if ($done): ?>
        <div class="success">Superadmin account created. This page is now permanently disabled — <a href="login.php" style="color:#bbf7d0;font-weight:700;">log in here</a>.</div>
    <?php else: ?>
        <?php if ($error): ?><div class="error"><?=htmlspecialchars($error)?></div><?php endif; ?>
        <form method="POST">
            <input type="hidden" name="key" value="<?=htmlspecialchars($key, ENT_QUOTES)?>">
            <label>Username</label>
            <input type="text" name="username" value="<?=htmlspecialchars($_POST['username'] ?? '')?>" required>
            <label>Email</label>
            <input type="email" name="email" value="<?=htmlspecialchars($_POST['email'] ?? '')?>" required>
            <label>Password (min 8 characters)</label>
            <input type="password" name="password" required>
            <label>Confirm Password</label>
            <input type="password" name="confirm_password" required>
            <button type="submit">Create Superadmin Account</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
