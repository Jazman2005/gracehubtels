<?php
echo "<h3>Server IP Information</h3>";

// Method 1: The server's internal address variable
$server_addr = $_SERVER['SERVER_ADDR'] ?? 'Unknown';
echo "<p>Internal Server IP: <strong>" . htmlspecialchars($server_addr) . "</strong></p>";

// Method 2: The exact outgoing IP address (this is what SageCloud actually sees!)
$ch = curl_init('https://api.ipify.org');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$outgoing_ip = curl_exec($ch);
curl_close($ch);

if ($outgoing_ip) {
    echo "<p style='color:blue; font-size:18px;'><strong>Exact Outgoing IP (Whitelist This One): " . htmlspecialchars($outgoing_ip) . "</strong></p>";
} else {
    echo "<p style='color:red;'>Could not fetch outgoing IP address automatically.</p>";
}
