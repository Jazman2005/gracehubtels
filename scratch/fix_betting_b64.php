<?php
$dashboard_file = 'c:\xampp\htdocs\gracehubtels\user\core\dashboard.php';
$img_file = 'C:\Users\user\Downloads\WhatsApp Image 2026-08-01 at 3.55.36 PM.jpeg';

$b64 = base64_encode(file_get_contents($img_file));
$dashboard = file_get_contents($dashboard_file);

// Replace whatever betting line is currently there
$dashboard = preg_replace("/'betting'\s*=>\s*'data:image\/jpeg;base64,[^']+',/", "'betting' => 'data:image/jpeg;base64," . $b64 . "',", $dashboard);

file_put_contents($dashboard_file, $dashboard);
echo "Fixed betting logo successfully.\n";
