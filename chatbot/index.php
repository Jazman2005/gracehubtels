<?php
// Whapi.Cloud webhook entry point — called when incoming messages arrive
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/bot-error.log');

try {
    require_once __DIR__ . '/handler.php';
} catch (Throwable $e) {
    http_response_code(500);
    file_put_contents(__DIR__ . '/bot-error.log', date('Y-m-d H:i:s') . ' require failed: ' . $e->getMessage() . "\n", FILE_APPEND);
    exit;
}

try {
    $raw = file_get_contents('php://input');
    if (!$raw) { http_response_code(200); exit; }
    $input = json_decode($raw, true);
    if (!$input) { _log("invalid json: " . substr($raw, 0, 500)); http_response_code(200); exit; }

    // Whapi.Cloud wraps messages in a "messages" array
    $messages = $input['messages'] ?? [];
    if (empty($messages)) { http_response_code(200); exit; }

    foreach ($messages as $msg) {
        // Skip messages sent FROM the bot itself
        if (!empty($msg['from_me'])) continue;

        $phone = $msg['from'] ?? '';
        $type = $msg['type'] ?? '';

        // Log full message for debugging button clicks
        _log("RAW type={$type} from={$phone} json=" . json_encode($msg));

        // Skip if session was updated within the last 2 seconds (dedup)
        if ($phone) {
            $dup_check = db()->prepare("SELECT updated_at FROM chatbot_sessions WHERE phone = ?");
            $dup_check->execute([$phone]);
            $last_upd = $dup_check->fetchColumn();
            if ($last_upd && (time() - strtotime($last_upd)) < 2) continue;
        }

        $chat_id = $phone; // Whapi.Cloud accepts just the phone number

        // Helper to normalize Whapi.Cloud button IDs (strips prefixes like "ButtonsV3:")
        $normalize_id = function(string $id): string {
            $parts = explode(':', $id, 2);
            return trim(end($parts) ?: $id);
        };

        // Extract message text based on type
        $text = '';

        if ($type === 'text') {
            $text = trim($msg['text']['body'] ?? '');
        } elseif ($type === 'reply') {
            $reply = $msg['reply'] ?? [];
            $reply_type = $reply['type'] ?? '';
            if ($reply_type === 'buttons_reply') {
                $br = $reply['buttons_reply'] ?? [];
                $text = $normalize_id($br['id'] ?? $br['title'] ?? '');
            } elseif ($reply_type === 'list_reply') {
                $lr = $reply['list_reply'] ?? [];
                $text = $normalize_id($lr['id'] ?? $lr['title'] ?? '');
            }
        } elseif ($type === 'interactive') {
            $interactive = $msg['interactive'] ?? [];
            $interactive_type = $interactive['type'] ?? '';
            if ($interactive_type === 'button_reply') {
                $btn = $interactive['button_reply'] ?? [];
                $text = $normalize_id($btn['id'] ?? $btn['title'] ?? '');
            } elseif ($interactive_type === 'list_reply') {
                $lst = $interactive['list_reply'] ?? [];
                $text = $normalize_id($lst['id'] ?? $lst['title'] ?? '');
            }
        }

        _log("incoming: type={$type} from={$phone} msg=" . substr($text, 0, 50));

        if ($phone && $text !== '') {
            handle_message($phone, $chat_id, $text);
        }
    }
} catch (Throwable $e) {
    _log("FATAL: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
}
http_response_code(200);
