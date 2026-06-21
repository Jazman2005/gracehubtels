<?php
function fix_file($path) {
    if (!file_exists($path)) return;
    $content = file_get_contents($path);
    $orig = $content;

    $replaces = [
        ',' => '₦',
        'dY?' => '🏦',
        'dY"<' => '📋',
        'dY>,?' => '🛡️',
        'dY`' => '👥',
        'dY\'3' => '💳',
        'sT,?' => '⚙️',
        'dY"`' => '🔑',
        'dY"\'' => '🔒',
        'dY-`,?' => '🗑️',
        'dY`' => '👤',
        'dY>' => '💰',
        'sT' => '⚙️',
        'dY\'' => '💡',
        'O' => '⌫',
        'o.' => '✅',
        '<p class="tx-note">?' => '<p class="tx-note">⚡',
        'Tier <?= $user[\'kyc_level\'] ?> ? <?= ucfirst($user[\'kyc_status\']) ?> ?' => 'Tier <?= $user[\'kyc_level\'] ?> - <?= ucfirst($user[\'kyc_status\']) ?>',
        'Tier <?= $user[\'kyc_level\'] ?> - <?= ucfirst($user[\'kyc_status\']) ?> ?' => 'Tier <?= $user[\'kyc_level\'] ?> - <?= ucfirst($user[\'kyc_status\']) ?>',
        'type="number" id="amtF"' => 'type="text" id="amtF"',
    ];

    foreach ($replaces as $search => $replace) {
        $content = str_replace($search, $replace, $content);
    }
    
    // Also catch some slightly different garbled texts
    $content = str_replace('dY?', '🏦', $content);
    $content = str_replace('dY`', '👥', $content);
    $content = preg_replace('/dY\?,?/', '🛡️', $content); // Any similar garbled KYC shield
    
    // Replace standalone $ where it means money
    $content = preg_replace('/\$([0-9]+)/', '₦$1', $content);

    if ($content !== $orig) {
        file_put_contents($path, $content);
        echo "Fixed encoding & inputs in $path\n";
    }
}

$dir = new RecursiveDirectoryIterator('c:/xampp/htdocs/msjpay/user', RecursiveDirectoryIterator::SKIP_DOTS);
$iterator = new RecursiveIteratorIterator($dir);

foreach ($iterator as $file) {
    if ($file->getExtension() === 'php') {
        fix_file($file->getPathname());
    }
}
?>
