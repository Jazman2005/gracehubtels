<?php
/**
 * Twilio WhatsApp Sandbox webhook — free alternative to Green API webhooks.
 * 
 * Since Twilio Sandbox doesn't support interactive buttons/lists, all
 * interactive elements are converted to text-based options.
 * Users type the option ID (name) shown, not a sequential number.
 *
 * Setup:
 * 1. Sign up at https://twilio.com (free trial gives ~$15 credit)
 * 2. Go to Console → WhatsApp → Sandbox
 * 3. Set "When a message comes in" → /chatbot/twilio-webhook.php (on this deployment's own domain)
 * 4. Users join by texting the sandbox join code to the sandbox number
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/twilio-error.log');

require_once __DIR__ . '/../user/core/db.php';

// Verify this request genuinely came from Twilio before trusting From/Body —
// otherwise anyone can POST a forged incoming WhatsApp message. Twilio signs
// every webhook request with HMAC-SHA1 over the exact callback URL + sorted
// POST params, using your Auth Token as the key (see twilio.com/docs/usage/security).
$twilio_auth_token = setting('twilio_auth_token', '');
if (empty($twilio_auth_token)) {
    log_write('chatbot', 'ERROR', 'twilio_auth_token not configured — rejecting webhook');
    http_response_code(500);
    exit;
}
$signature = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';
$url = 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
$signed_data = $url;
$sorted_params = $_POST;
ksort($sorted_params);
foreach ($sorted_params as $key => $value) {
    $signed_data .= $key . $value;
}
$expected_signature = base64_encode(hash_hmac('sha1', $signed_data, $twilio_auth_token, true));
if (empty($signature) || !hash_equals($expected_signature, $signature)) {
    log_write('chatbot', 'ERROR', 'Invalid Twilio signature — rejecting webhook', ['ip' => get_ip()]);
    http_response_code(403);
    exit;
}

$from = $_POST['From'] ?? '';
$body = trim($_POST['Body'] ?? '');

if (!$from || !$body) {
    header('Content-Type: text/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
    exit;
}

$phone = str_replace(['whatsapp:', '+'], '', $from);
$phone = preg_replace('/[^0-9]/', '', $phone);

$replies = [];

// ── Define Twilio-compatible send functions BEFORE loading handler ──
// These convert interactive messages to text-based options using IDs.

if (!function_exists('bot_send')) {
function bot_send(string $chat_id, string $text): void {
    global $replies;
    $replies[] = $text;
}
}

if (!function_exists('bot_send_buttons')) {
function bot_send_buttons(string $chat_id, string $text, array $buttons): void {
    global $replies;
    $lines = [$text, ''];
    foreach ($buttons as $b) {
        $id = $b['id'] ?? '';
        $label = $b['text'] ?? $b;
        $lines[] = "{$id}. {$label}";
    }
    $lines[] = '';
    $lines[] = "Type the option or type *menu*.";
    $replies[] = implode("\n", $lines);
}
}

if (!function_exists('bot_send_list')) {
function bot_send_list(string $chat_id, string $text, string $button_text, array $sections, string $title = ''): void {
    global $replies;
    $lines = [$text, ''];
    foreach ($sections as $section) {
        if (count($sections) > 1) {
            $lines[] = "▫️ {$section['title']}";
        }
        foreach ($section['rows'] as $row) {
            $id = $row['id'] ?? '';
            $label = $row['title'] ?? $row;
            $desc = !empty($row['description']) ? " — {$row['description']}" : '';
            $lines[] = "{$id}. {$label}{$desc}";
        }
    }
    $lines[] = '';
    $lines[] = "Type the option or type *menu*.";
    $replies[] = implode("\n", $lines);
}
}

// Now load handler — our Twilio send functions are already defined,
// so handler.php's function_exists checks skip defining Green API versions.
// The handler logic (handle_message, session, show_menu, network_list, etc.)
// is loaded and calls our bot_send/bot_send_buttons/bot_send_list above.
try {
    require_once __DIR__ . '/handler.php';
} catch (Throwable $e) {
    file_put_contents(__DIR__ . '/twilio-error.log', date('Y-m-d H:i:s') . ' require failed: ' . $e->getMessage() . "\n", FILE_APPEND);
    header('Content-Type: text/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
    exit;
}

try {
    handle_message($phone, $phone . '@c.us', $body);
} catch (Throwable $e) {
    file_put_contents(__DIR__ . '/twilio-error.log', date('Y-m-d H:i:s') . ' handler error: ' . $e->getMessage() . "\n", FILE_APPEND);
}

// Build TwiML response
header('Content-Type: text/xml');
echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<Response>';
foreach ($replies as $reply) {
    echo '<Message>' . xml_escape($reply) . '</Message>';
}
echo '</Response>';

function xml_escape(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
