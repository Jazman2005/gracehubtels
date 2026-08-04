<?php
declare(strict_types=1);

/**
 * Standalone helper to send WhatsApp messages via Whapi.Cloud.
 * Include this file and call chatbot_send($phone, $message).
 * Also supports chatbot_send_buttons() and chatbot_send_list().
 * Interactive features fall back to text if the API returns an error.
 */

function whapi_curl(string $endpoint, array $payload): array {
    if (!function_exists('setting')) {
        require_once __DIR__ . '/../user/core/db.php';
    }
    $token = setting('whapi_token', '');
    if (!$token) return ['success' => false, 'message' => 'Whapi.Cloud not configured'];

    $url = "https://gate.whapi.cloud{$endpoint}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
    ]);
    $res = @curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($res === false) return ['success' => false, 'message' => "cURL error: $err"];
    $data = json_decode($res, true);
    if ($http === 200 || $http === 201) {
        $id = $data['messages'][0]['id'] ?? $data['message_id'] ?? '';
        return ['success' => true, 'message' => 'Sent', 'id' => $id];
    }
    $apiErr = $data['message'] ?? $data['error'] ?? $res;
    return ['success' => false, 'message' => "API error (HTTP $http): " . (is_string($apiErr) ? $apiErr : json_encode($apiErr))];
}

function chatbot_send(string $phone, string $message): array {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) < 10) return ['success' => false, 'message' => 'Invalid phone number'];
    return whapi_curl('/messages/text', [
        'to' => $phone,
        'body' => $message,
        'typing_time' => 0,
        'no_link_preview' => true,
    ]);
}

function chatbot_send_buttons(string $phone, string $message, array $buttons): array {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) < 10) return ['success' => false, 'message' => 'Invalid phone number'];

    if (!function_exists('setting')) {
        require_once __DIR__ . '/../user/core/db.php';
    }

    // Check if text-only mode is forced
    if (setting('bot_text_only', '0') === '1') {
        return chatbot_send($phone, render_buttons_text($message, $buttons));
    }

    $whapi_buttons = [];
    foreach (array_slice($buttons, 0, 3) as $b) {
        $whapi_buttons[] = [
            'type' => 'quick_reply',
            'title' => $b['text'] ?? $b,
            'id' => $b['id'] ?? '0',
        ];
    }
    $result = whapi_curl('/messages/interactive', [
        'to' => $phone,
        'type' => 'button',
        'body' => ['text' => $message],
        'action' => ['buttons' => $whapi_buttons],
    ]);
    if (!$result['success']) {
        return chatbot_send($phone, render_buttons_text($message, $buttons));
    }
    return $result;
}

function chatbot_send_list(string $phone, string $message, string $button_text, array $sections, string $title = ''): array {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) < 10) return ['success' => false, 'message' => 'Invalid phone number'];

    if (!function_exists('setting')) {
        require_once __DIR__ . '/../user/core/db.php';
    }

    // Check if text-only mode is forced
    if (setting('bot_text_only', '0') === '1') {
        return chatbot_send($phone, render_list_text($message, $sections));
    }

    $whapi_sections = [];
    foreach ($sections as $s) {
        $section = ['title' => $s['title'] ?? 'Options', 'rows' => []];
        foreach ($s['rows'] as $r) {
            $row = ['id' => $r['id'] ?? '0', 'title' => $r['title'] ?? $r];
            if (!empty($r['description'])) $row['description'] = $r['description'];
            $section['rows'][] = $row;
        }
        $whapi_sections[] = $section;
    }
    $result = whapi_curl('/messages/interactive', [
        'to' => $phone,
        'type' => 'list',
        'body' => ['text' => $message],
        'action' => [
            'button' => $button_text,
            'sections' => $whapi_sections,
        ],
    ]);
    if (!$result['success']) {
        return chatbot_send($phone, render_list_text($message, $sections));
    }
    return $result;
}

// ── Render helpers for text fallback ──────────────────────

function render_buttons_text(string $message, array $buttons): string {
    $lines = [$message, ''];
    foreach ($buttons as $b) {
        $id = $b['id'] ?? '';
        $label = $b['text'] ?? $b;
        $lines[] = "{$id}. {$label}";
    }
    $lines[] = '';
    $lines[] = "Type the option or type *menu*.";
    return implode("\n", $lines);
}

function render_list_text(string $message, array $sections): string {
    $lines = [$message, ''];
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
    return implode("\n", $lines);
}
