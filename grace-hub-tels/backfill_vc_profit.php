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
        . '<h2>Backfill Virtual Card Profit</h2>'
        . '<p>This will bulk-rewrite cost_price/profit for virtual card transactions missing that data.</p>'
        . '<input type="hidden" name="csrf" value="' . htmlspecialchars($csrf) . '">'
        . '<button type="submit">Run Backfill</button>'
        . '</form>';
    exit;
}
if (!verify_admin_csrf($_POST['csrf'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }

$updated = 0; $skipped = 0; $errors = [];
$rows = db()->prepare("SELECT transaction_ref, amount, description, charge FROM transactions WHERE category='virtual_card' AND type='debit' AND cost_price IS NULL");
$rows->execute();
foreach ($rows as $r) {
    $ref = $r['transaction_ref'];
    $amount = (float)$r['amount'];
    $desc = $r['description'] ?? '';
    $charge = (float)($r['charge'] ?? 0);
    $costPrice = null;
    try {
        if (preg_match('/Virtual card creation: \$([\d.]+) setup(?: \+ \$([\d.]+) topup)? @ ₦([\d,]+\.?\d*)\/\$/', $desc, $m)) {
            $setupUsd = (float)$m[1];
            $topupUsd = isset($m[2]) ? (float)$m[2] : 0;
            $rate = (float)str_replace(',', '', $m[3]);
            $costPrice = (2.50 * $rate) + ($topupUsd * $rate);
            $profit = $amount - $costPrice;
        } elseif (preg_match('/Fund virtual card \S+ with \$([\d.]+)/', $desc, $m)) {
            $usdAmount = (float)$m[1];
            $fee = $charge;
            $ngnExcludingFee = $amount - $fee;
            if ($ngnExcludingFee > 0 && $usdAmount > 0) {
                $rate = $ngnExcludingFee / $usdAmount;
                $costPrice = $usdAmount * $rate;
                $profit = $amount - $costPrice;
            }
        } elseif (preg_match('/Withdraw \$([\d.]+)/', $desc)) {
            continue;
        }
        if ($costPrice !== null) {
            db()->prepare("UPDATE transactions SET cost_price=?, profit=?, provider=COALESCE(provider,'System') WHERE transaction_ref=?")
                ->execute([round($costPrice, 2), round($profit, 2), $ref]);
            $updated++;
        } else {
            $skipped++;
        }
    } catch (\Throwable $e) {
        $errors[] = "$ref: " . $e->getMessage();
        $skipped++;
    }
}
echo "<h2>Virtual Card Profit Backfill Complete</h2>";
echo "<p>Updated: $updated | Skipped: $skipped</p>";
if ($errors) { echo "<h3>Errors:</h3><pre>" . implode("\n", $errors) . "</pre>"; }
