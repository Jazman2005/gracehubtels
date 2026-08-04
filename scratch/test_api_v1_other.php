<?php
$urls = [
    'https://autopilotng.com/api/live/v1/data',
    'https://autopilotng.com/api/live/v1/cabletv',
    'https://autopilotng.com/api/live/v1/billpay'
];
foreach($urls as $u) {
    $ch = curl_init($u);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo "$u: Code $code\n";
}
