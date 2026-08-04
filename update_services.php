<?php
$files = [
    'user/core/cabletv.php' => '$price',
    'user/core/electricity.php' => '$total',
    'user/core/exam_pin.php' => '$amount',
    'user/core/data_card.php' => '$total',
    'user/core/recharge_card.php' => '(float)$total',
    'user/core/bulk_sms.php' => '$total_amount',
    'user/core/betting.php' => '$total'
];

foreach ($files as $file => $amt_var) {
    $f = 'c:\xampp\htdocs\gracehubtels\\' . $file;
    if (!file_exists($f)) {
        echo "File not found: $f\n";
        continue;
    }
    $content = file_get_contents($f);
    
    // Pattern to match update_tx_status + credit_wallet
    $pattern = '/update_tx_status\(\$debit\[\'ref\'\],\'failed\'\);\s+credit_wallet\(\$uid,' . preg_quote($amt_var, '/') . ',\'[^\']+\',\'[^\']+\'\);/';
    
    if (preg_match($pattern, $content)) {
        $content = preg_replace($pattern, "", $content);
        
        // Now replace save_tx_response with refund_failed_transaction
        $content = str_replace(
            "save_tx_response(\$debit['ref'], \$err_msg);",
            "refund_failed_transaction(\$debit['ref'], \$uid, $amt_var, \$err_msg);",
            $content
        );
        
        file_put_contents($f, $content);
        echo "Updated $file\n";
    } else {
        echo "Pattern not found in $file\n";
    }
}
