<?php
$url = 'https://autopilotng.com/api/live/v1/airtime';
$headers = ['Content-Type: application/json', 'Authorization: Bearer live_DUMMY_TOKEN'];

// Payload 1: Autosyncng style
$p1 = ['phone' => '08104595198', 'amount' => 100, 'product_id' => 1, 'request_ref' => 'REF123', 'ported_no' => true];
$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($p1), CURLOPT_HTTPHEADER => $headers, CURLOPT_SSL_VERIFYPEER => false]);
$res1 = curl_exec($ch); $c1 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "Payload 1 (Autosyncng): $c1 => $res1\n\n";

// Payload 2: Generic style
$p2 = ['network' => 1, 'amount' => 100, 'mobile_number' => '08104595198', 'Ported_number' => true, 'airtime_type' => 'VTU', 'ref' => 'REF123'];
$ch2 = curl_init($url);
curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($p2), CURLOPT_HTTPHEADER => $headers, CURLOPT_SSL_VERIFYPEER => false]);
$res2 = curl_exec($ch2); $c2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);
echo "Payload 2 (Generic): $c2 => $res2\n\n";
