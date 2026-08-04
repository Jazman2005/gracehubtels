<?php
$ch = curl_init('https://autopilotng.com/api/topup/');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "topup/: Code $code, Res: $res\n";

$ch2 = curl_init('https://autopilotng.com/api/data/');
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
$res2 = curl_exec($ch2);
$code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
echo "data/: Code $code2, Res: $res2\n";
