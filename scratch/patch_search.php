<?php
$dashboard_file = 'c:\xampp\htdocs\gracehubtels\user\core\dashboard.php';
$content = file_get_contents($dashboard_file);

// Find the function get_user_transactions
$search_func = "function get_user_transactions(int \$user_id, int \$page=1, int \$per_page=20, string \$filter='', string \$start_date='', string \$end_date=''): array {";
$replace_func = "function get_user_transactions(int \$user_id, int \$page=1, int \$per_page=20, string \$filter='', string \$start_date='', string \$end_date='', string \$search=''): array {";

// Ensure we don't replace if already there
if (strpos($content, '$search=\'\': array') === false) {
    // There are some line breaks in the original file, so let's use regex
    $content = preg_replace('/function get_user_transactions\([^)]+\):\s*array\s*\{/', $replace_func, $content);
}

// Find the if ($end_date) block
$search_end_date = '    if ($end_date && preg_match(\'/^\d{4}-\d{2}-\d{2}$/\', $end_date)) {
        $where .= " AND DATE(created_at) <= ?";
        $params[] = $end_date;
    }';

$search_block = '    if ($end_date && preg_match(\'/^\d{4}-\d{2}-\d{2}$/\', $end_date)) {
        $where .= " AND DATE(created_at) <= ?";
        $params[] = $end_date;
    }
    
    if ($search) {
        $where .= " AND (description LIKE ? OR transaction_ref LIKE ? OR meta LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }';

if (strpos($content, 'if ($search) {') === false) {
    // Regex replace to handle whitespace differences
    $content = preg_replace('/if\s*\(\$end_date.*?\$params\[\]\s*=\s*\$end_date;\s*\}/s', $search_block, $content);
}

file_put_contents($dashboard_file, $content);
echo "Patched core/dashboard.php successfully.\n";
