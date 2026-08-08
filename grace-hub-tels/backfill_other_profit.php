<?php
require_once __DIR__ . '/core/auth.php';
auth_admin();
require_permission('manage_transactions');

// This bulk-rewrites cost_price/profit for every matching transaction —
// permission alone doesn't stop a forced-browsing CSRF (e.g. an <img> tag
// on another page silently GETing this URL in a logged-in admin's browser).
// Require an explicit CSRF-protected POST confirmation before running it.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $csrf = admin_csrf();
    echo '<form method="POST" style="font-family:sans-serif;padding:40px;">'
        . '<h2>Backfill Other Profit</h2>'
        . '<p>This will bulk-rewrite cost_price/profit for recharge card, data card, and exam PIN transactions missing that data.</p>'
        . '<input type="hidden" name="csrf" value="' . htmlspecialchars($csrf) . '">'
        . '<button type="submit">Run Backfill</button>'
        . '</form>';
    exit;
}
if (!verify_admin_csrf($_POST['csrf'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }

$updated = 0; $skipped = 0; $errors = [];

// --- 1. Recharge Cards ---
$rows = db()->prepare("SELECT t.transaction_ref, t.amount, t.description, t.network
    FROM transactions t WHERE t.category='recharge_card' AND t.type='debit' AND t.cost_price IS NULL");
$rows->execute();
foreach ($rows as $r) {
    $ref = $r['transaction_ref'];
    $amount = (float)$r['amount'];
    $desc = $r['description'] ?? '';
    try {
        if (preg_match('/(\d+)x ₦(\d+) (\S+) recharge card/', $desc, $m)) {
            $qty = (int)$m[1];
            $denom = (int)$m[2];
            $network = $m[3];
            $pr = db()->prepare("SELECT cost_price FROM service_pricing WHERE service='recharge_card' AND network=? AND plan_name=? AND is_active=1 LIMIT 1");
            $pr->execute([$network, (string)$denom]); $p = $pr->fetch();
            if ($p && (float)$p['cost_price'] > 0) {
                $costTotal = (float)$p['cost_price'] * $qty;
                db()->prepare("UPDATE transactions SET cost_price=?, profit=?, provider=COALESCE(provider,'System') WHERE transaction_ref=?")
                    ->execute([round($costTotal, 2), round($amount - $costTotal, 2), $ref]);
                $updated++;
                continue;
            }
        }
        $skipped++;
    } catch (\Throwable $e) { $errors[] = "$ref: " . $e->getMessage(); $skipped++; }
}

// --- 2. Data Cards ---
$rows = db()->prepare("SELECT t.transaction_ref, t.amount, t.description, t.network
    FROM transactions t WHERE t.category='data_card' AND t.type='debit' AND t.cost_price IS NULL");
$rows->execute();
foreach ($rows as $r) {
    $ref = $r['transaction_ref'];
    $amount = (float)$r['amount'];
    $desc = $r['description'] ?? '';
    try {
        if (preg_match('/(\d+)x ([\d.]+ ?\w+) (\S+) data card/', $desc, $m)) {
            $qty = (int)$m[1];
            $size = $m[2];
            $network = $m[3];
            $pl = db()->prepare("SELECT cost_price, selling_price FROM data_plans WHERE network=? AND data_size=? AND is_active=1 LIMIT 1");
            $pl->execute([$network, $size]); $plan = $pl->fetch();
            if ($plan && (float)$plan['cost_price'] > 0) {
                $costTotal = (float)$plan['cost_price'] * $qty;
                db()->prepare("UPDATE transactions SET cost_price=?, profit=?, provider=COALESCE(provider,'System') WHERE transaction_ref=?")
                    ->execute([round($costTotal, 2), round($amount - $costTotal, 2), $ref]);
                $updated++;
                continue;
            }
        }
        $skipped++;
    } catch (\Throwable $e) { $errors[] = "$ref: " . $e->getMessage(); $skipped++; }
}

// --- 3. Exam PINs ---
$rows = db()->prepare("SELECT t.transaction_ref, t.amount, t.description
    FROM transactions t WHERE t.category='exam_pin' AND t.type='debit' AND t.cost_price IS NULL");
$rows->execute();
foreach ($rows as $r) {
    $ref = $r['transaction_ref'];
    $amount = (float)$r['amount'];
    $desc = $r['description'] ?? '';
    try {
        if (preg_match('/(\d+) (WAEC|NECO|NABTEB) exam/', $desc, $m)) {
            $qty = (int)$m[1];
            $exam = $m[2];
            $pr = db()->prepare("SELECT cost_price FROM service_pricing WHERE service='exam_pin' AND plan_name=? AND is_active=1 LIMIT 1");
            $pr->execute([$exam]); $p = $pr->fetch();
            if ($p && (float)$p['cost_price'] > 0) {
                $costTotal = (float)$p['cost_price'] * $qty;
                db()->prepare("UPDATE transactions SET cost_price=?, profit=?, provider=COALESCE(provider,'System') WHERE transaction_ref=?")
                    ->execute([round($costTotal, 2), round($amount - $costTotal, 2), $ref]);
                $updated++;
                continue;
            }
        }
        $skipped++;
    } catch (\Throwable $e) { $errors[] = "$ref: " . $e->getMessage(); $skipped++; }
}

echo "<h2>Backfill Complete</h2><p>Updated: $updated | Skipped: $skipped</p>";
if ($errors) echo "<h3>Errors:</h3><pre>" . implode("\n", $errors) . "</pre>";
