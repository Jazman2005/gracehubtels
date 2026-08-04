<?php
$urls = [
    'https://autopilotng.com/api/live',
    'https://autopilotng.com/api/live/topup/',
    'https://autopilotng.com/api/live/data/',
    'https://autopilotng.com/api/live/v1/airtime',
    'https://autopilotng.com/api/live/api/topup/'
];
foreach($urls as $u) {
    $ch = curl_init($u);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo "$u: Code $code\n";
}
