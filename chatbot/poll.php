<?php
/**
 * Message poller for Whapi.Cloud (Cloud API is webhook-only).
 *
 * Fetches recent messages when webhooks aren't available.
 * Run via cron every 1-5 minutes:
 *   * * * * * php /path/to/chatbot/poll.php
 * Or via web: GET /chatbot/poll.php?key=YOUR_SECRET_KEY (on this deployment's own domain)
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/bot-error.log');

try {
    require_once __DIR__ . '/handler.php';
} catch (Throwable $e) {
    file_put_contents(__DIR__ . '/bot-error.log', date('Y-m-d H:i:s') . ' poll require failed: ' . $e->getMessage() . "\n", FILE_APPEND);
    exit;
}

$secret = setting('bot_poll_key');
if (php_sapi_name() !== 'cli') {
    if (empty($secret) || !isset($_GET['key']) || !hash_equals($secret, $_GET['key'])) {
        http_response_code(403); echo "Forbidden"; exit;
    }
}

$token = setting('whapi_token', '');
if (!$token) exit;

$processed = 0;

$url = "https://gate.whapi.cloud/messages?count=10";
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ],
]);
$res = @curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http === 200 && $res) {
    $data = json_decode($res, true);
    $messages = $data['messages'] ?? [];
    foreach ($messages as $msg) {
        if (!empty($msg['from_me'])) continue;

        $phone = $msg['from'] ?? '';
        $type = $msg['type'] ?? '';
        $chat_id = $phone;

        $normalize_id = function(string $id): string {
            $parts = explode(':', $id, 2);
            return trim(end($parts) ?: $id);
        };

        $text = '';
        if ($type === 'text') {
            $text = trim($msg['text']['body'] ?? '');
        } elseif ($type === 'reply') {
            $reply = $msg['reply'] ?? [];
            $reply_type = $reply['type'] ?? '';
            if ($reply_type === 'buttons_reply') {
                $text = $normalize_id($reply['buttons_reply']['id'] ?? $reply['buttons_reply']['title'] ?? '');
            } elseif ($reply_type === 'list_reply') {
                $text = $normalize_id($reply['list_reply']['id'] ?? $reply['list_reply']['title'] ?? '');
            }
        } elseif ($type === 'interactive') {
            $interactive = $msg['interactive'] ?? [];
            $itype = $interactive['type'] ?? '';
            if ($itype === 'button_reply') {
                $text = $normalize_id($interactive['button_reply']['id'] ?? $interactive['button_reply']['title'] ?? '');
            } elseif ($itype === 'list_reply') {
                $text = $normalize_id($interactive['list_reply']['id'] ?? $interactive['list_reply']['title'] ?? '');
            }
        }

        if ($phone && $text !== '') {
            $s = db()->prepare("SELECT updated_at FROM chatbot_sessions WHERE phone = ? ORDER BY updated_at DESC LIMIT 1");
            $s->execute([$phone]);
            $last = $s->fetchColumn();
            if (!$last || (time() - strtotime($last)) > 30) {
                handle_message($phone, $chat_id, $text);
                $processed++;
            }
        }
    }
}

header('Content-Type: text/plain');
echo "Poll run: " . date('Y-m-d H:i:s') . "\n";
echo "Processed {$processed} messages.\n";
echo "Log: " . (file_exists(__DIR__ . '/bot-error.log') ? 'exists' : 'not found') . "\n";
