<?php
$dir = 'c:/xampp/htdocs/gracehubtels/user/core/';
$files = ['data.php', 'airtime.php', 'cabletv.php', 'electricity.php', 'bulk_sms.php', 'betting.php', 'exam_pin.php', 'data_card.php', 'recharge_card.php'];

foreach ($files as $file) {
    $path = $dir . $file;
    if (!file_exists($path)) continue;
    $content = file_get_contents($path);
    
    // For electricity.php, fix the missing refund
    if ($file === 'electricity.php') {
        $content = str_replace(
            "save_tx_response(\$debit['ref'], \$err);",
            "refund_failed_transaction(\$debit['ref'], \$uid, \$amount, \$err);",
            $content
        );
    }
    
    // Replace the return statement
    // We are looking for something like: return['success'=>false,'message'=>$err,'ref'=>$debit['ref']];
    // Or: return ['success'=>false,'message'=>$err_msg, 'ref'=>$debit['ref']];
    $pattern = "/return\s*\[\s*'success'\s*=>\s*false\s*,\s*'message'\s*=>\s*([^,]+)\s*,\s*'ref'\s*=>\s*\\\$debit\['ref'\]\s*\]\s*;/";
    $replacement = "return['success'=>true,'message'=>$1,'ref'=>\$debit['ref'],'refunded'=>true];";
    
    $new_content = preg_replace($pattern, $replacement, $content);
    
    if ($new_content !== $content) {
        file_put_contents($path, $new_content);
        echo "Patched $file\n";
    } else {
        echo "No changes made to $file\n";
    }
}
