<?php
$f = 'c:\xampp\htdocs\gracehubtels\user\core\virtual_card.php';
$content = file_get_contents($f);

// 1. customer creation failed
$content = str_replace(
    "credit_wallet(\$user_id, \$total, 'virtual_card', 'Refund: customer creation failed');\n        update_tx_status(\$debit['ref'], 'failed');",
    "refund_failed_transaction(\$debit['ref'], \$user_id, \$total, 'Could not create customer profile on card provider.');",
    $content
);

// 2. virtual card creation failed
$content = preg_replace(
    "/credit_wallet\(\\\$user_id, \\\$total, 'virtual_card', 'Refund: virtual card creation failed'\);\s*update_tx_status\(\\\$debit\['ref'\], 'failed'\);/",
    "refund_failed_transaction(\$debit['ref'], \$user_id, \$total, \$result['message'] ?? 'Failed to create virtual card.');",
    $content
);

// 3. retrieve card code failed
$content = preg_replace(
    "/credit_wallet\(\\\$user_id, \\\$total, 'virtual_card', 'Refund: could not retrieve card code'\);\s*update_tx_status\(\\\$debit\['ref'\], 'failed'\);/",
    "refund_failed_transaction(\$debit['ref'], \$user_id, \$total, 'Could not retrieve card code.');",
    $content
);

// 4. card funding failed
$content = preg_replace(
    "/credit_wallet\(\\\$user_id, \\\$total_ngn, 'virtual_card', 'Refund: card funding failed'\);\s*update_tx_status\(\\\$debit\['ref'\], 'failed'\);/",
    "refund_failed_transaction(\$debit['ref'], \$user_id, \$total_ngn, \$res\['message'\] ?? 'Failed to fund virtual card.');",
    $content
);

file_put_contents($f, $content);
echo "Done\n";
