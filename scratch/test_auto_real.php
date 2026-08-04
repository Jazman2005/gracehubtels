<?php
require_once __DIR__.'/../user/core/db.php';
$pr = db()->query("SELECT api_key, api_token FROM api_providers WHERE provider_name LIKE '%autopilot%' LIMIT 1")->fetch();
$key = $pr['api_token'] ?: $pr['api_key'];

$url = 'https://autopilotng.com/api/live/v1/airtime';
$headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $key];

// Payload 1: Autosyncng
$p1 = ['phone' => '08104595198', 'amount' => 50, 'product_id' => 1, 'request_ref' => 'REF1234567890', 'ported_no' => true];
$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($p1), CURLOPT_HTTPHEADER => $headers, CURLOPT_SSL_VERIFYPEER => false]);
$res1 = curl_exec($ch); $c1 = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
echo "Payload 1 (Autosyncng): $c1 => $res1\n\n";

// Payload 2: Generic
$p2 = ['network' => 1, 'amount' => 50, 'mobile_number' => '08104595198', 'Ported_number' => true, 'airtime_type' => 'VTU', 'ref' => 'REF1234567891'];
$ch2 = curl_init($url);
curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($p2), CURLOPT_HTTPHEADER => $headers, CURLOPT_SSL_VERIFYPEER => false]);
$res2 = curl_exec($ch2); $c2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE); curl_close($ch2);
echo "Payload 2 (Generic): $c2 => $res2\n\n";

// Payload 3: Autosyncng with 'network' instead of 'product_id'
$p3 = ['phone' => '08104595198', 'amount' => 50, 'network' => 1, 'request_ref' => 'REF1234567892', 'ported_no' => true];
$ch3 = curl_init($url);
curl_setopt_array($ch3, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($p3), CURLOPT_HTTPHEADER => $headers, CURLOPT_SSL_VERIFYPEER => false]);
$res3 = curl_exec($ch3); $c3 = curl_getinfo($ch3, CURLINFO_HTTP_CODE); curl_close($ch3);
echo "Payload 3 (Mixed 1): $c3 => $res3\n\n";

// Payload 4: Generic but network_id instead of network
$p4 = ['network_id' => 1, 'amount' => 50, 'phone' => '08104595198', 'request_ref' => 'REF1234567893'];
$ch4 = curl_init($url);
curl_setopt_array($ch4, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($p4), CURLOPT_HTTPHEADER => $headers, CURLOPT_SSL_VERIFYPEER => false]);
$res4 = curl_exec($ch4); $c4 = curl_getinfo($ch4, CURLINFO_HTTP_CODE); curl_close($ch4);
echo "Payload 4 (Mixed 2): $c4 => $res4\n\n";
