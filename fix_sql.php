<?php
function fixElec() {
    $file = 'c:\\xampp\\htdocs\\msjpay\\user\\electricity.php';
    $content = file_get_contents($file);
    if ($content) {
        $content = preg_replace(
            '/\\$ut=\\$_SESSION\\[\'user_type\'\\]\?\?\'regular\';\\$discos=db\(\)->prepare\("SELECT \* FROM electricity_discos WHERE is_active=1 AND \(user_type=\? OR user_type=\'both\'\) ORDER BY disco_name"\);\\$discos->execute\(\\[\\$ut\\]\);\\$discos=\\$discos->fetchAll\(\);/',
            '$discos=db()->prepare("SELECT * FROM electricity_discos WHERE is_active=1 ORDER BY disco_name");$discos->execute();$discos=$discos->fetchAll();',
            $content
        );
        file_put_contents($file, $content);
        echo "Fixed electricity.php\n";
    }
}

function fixCable() {
    $file = 'c:\\xampp\\htdocs\\msjpay\\user\\cabletv.php';
    $content = file_get_contents($file);
    if ($content) {
        $content = preg_replace(
            '/\\$plans = \\$ut=\\$_SESSION\\[\'user_type\'\\]\?\?\'regular\';\\$s=db\(\)->prepare\("SELECT \* FROM cable_plans WHERE provider=\? AND is_active=1 AND \(user_type=\? OR user_type=\'both\'\) ORDER BY selling_price"\);\\$s->execute\(\\[\\$sel_prov,\\$ut\\]\);\\$s->fetchAll\(\);/',
            '$s=db()->prepare("SELECT * FROM cable_plans WHERE provider=? AND is_active=1 ORDER BY selling_price");$s->execute([$sel_prov]);$plans = $s->fetchAll();',
            $content
        );
        file_put_contents($file, $content);
        echo "Fixed cabletv.php\n";
    }
}

fixElec();
fixCable();
?>
