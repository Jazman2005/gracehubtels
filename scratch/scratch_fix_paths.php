<?php
$files = glob(__DIR__ . '/../user/*.php');
foreach ($files as $f) {
    if (basename($f) === 'dashboard.php') continue; // Don't touch dashboard back button
    $c = file_get_contents($f);
    // Replace absolute assets to relative
    $c = str_replace("'/assets/logos/", "'../assets/logos/", $c);
    $c = str_replace('"/assets/logos/', '"../assets/logos/', $c);
    
    // Replace specific back button with history.back()
    $c = preg_replace('/href="\/user\/dashboard"([^>]+fa-arrow-left)/', 'href="javascript:history.back()"$1', $c);
    
    file_put_contents($f, $c);
}
echo "Done.\n";
