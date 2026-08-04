<?php
require_once __DIR__ . '/../user/core/db.php';
if (!isset($_GET['key']) || !hash_equals(setting('bot_diag_key') ?: 'gracehubtels_diag_2024', $_GET['key'])) {
    http_response_code(403); die("Access denied");
}
header('Content-Type: text/plain');
echo "Node.js check:\n";
$out = null; $rc = null;
exec('node --version 2>&1', $out, $rc);
echo "node --version: " . ($out ? implode("\n", $out) : "not found") . " (exit: $rc)\n\n";

echo "npm check:\n";
$out = null; $rc = null;
exec('npm --version 2>&1', $out, $rc);
echo "npm --version: " . ($out ? implode("\n", $out) : "not found") . " (exit: $rc)\n\n";

echo "Free alternatives:\n";
echo "1. Node.js available → Use Baileys (free, unlimited WhatsApp bot)\n";
echo "2. Node.js not available → Use Twilio WhatsApp Sandbox (free for dev)\n";
echo "3. Keep Green API for sending + alternative receive method\n";
