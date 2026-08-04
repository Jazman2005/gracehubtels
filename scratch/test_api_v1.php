<?php
$urls = [
    'https://autopilotng.com/api/v1/airtime',
    'https://autopilotng.com/api/v1/data',
    'https://autopilotng.com/api/live/api/v1/airtime'
];
foreach($urls as $u) {
    $ch = curl_init($u);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo "$u: Code $code\n";
}
