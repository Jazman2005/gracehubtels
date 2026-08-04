<?php
$dashboard_file = 'c:\xampp\htdocs\gracehubtels\user\core\dashboard.php';
$b64_file = 'c:\xampp\htdocs\gracehubtels\scratch\b64_betting.txt';

$b64 = trim(file_get_contents($b64_file));
$dashboard = file_get_contents($dashboard_file);

// Check if betting already exists
if (strpos($dashboard, "'betting' =>") === false) {
    $insert_str = "\n        'betting' => 'data:image/jpeg;base64," . $b64 . "',";
    // Insert right after $map = [
    $dashboard = preg_replace('/\$map\s*=\s*\[/', "\$map = [" . $insert_str, $dashboard, 1);
    
    file_put_contents($dashboard_file, $dashboard);
    echo "Injected betting logo successfully.\n";
} else {
    echo "Betting logo already exists in map.\n";
}
