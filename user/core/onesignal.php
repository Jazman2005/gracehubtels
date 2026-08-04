<?php
require_once __DIR__ . '/db.php';

class OneSignalAPI {
    private string $appId;
    private string $restApiKey;

    public function __construct() {
        $this->appId = setting('onesignal_app_id', '');
        $this->restApiKey = setting('onesignal_rest_api_key', '');
    }

    public function is_configured(): bool {
        return !empty($this->appId) && !empty($this->restApiKey);
    }

    public function send_push(string $title, string $message, string $target = 'Total Subscriptions', array $custom_data = []): array {
        if (!$this->is_configured()) {
            return ['success' => false, 'message' => 'OneSignal is not configured.'];
        }

        $url = "https://onesignal.com/api/v1/notifications";
        
        $fields = [
            'app_id' => $this->appId,
            'headings' => ["en" => $title],
            'contents' => ["en" => $message],
            'target_channel' => "push",
            'isIos' => true,
            'isAndroid' => true,
            'isAnyWeb' => false
        ];

        // Handle different target types
        if ($target === 'Test Devices') {
            $fields['included_segments'] = ["Test Users"];
        } elseif (in_array($target, ['regular', 'agent', 'vendor'])) {
            // Target by user type — fetch player IDs from DB
            $playerIds = $this->get_player_ids_by_type($target);
            if (empty($playerIds)) {
                return ['success' => false, 'message' => "No registered devices found for user type: $target"];
            }
            $fields['include_player_ids'] = $playerIds;
        } elseif (is_numeric($target)) {
            // Target a single user by their External ID
            // OneSignal will automatically send the push to ALL devices (Android & iOS) owned by this user!
            $fields['include_aliases'] = [
                'external_id' => [strval($target)]
            ];
            $fields['target_channel'] = "push";
        } else {
            $fields['included_segments'] = ["Total Subscriptions"];
        }

        if (!empty($custom_data)) {
            $fields['data'] = $custom_data;
        }

        $fields = json_encode($fields);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Basic ' . $this->restApiKey
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $logData = [
            'endpoint' => $url,
            'payload' => json_decode($fields, true),
            'response' => json_decode((string)$response, true)
        ];
        log_api('onesignal_api', 'send_push', $logData['payload'], $logData['response'], $httpcode);

        if ($err) {
            log_error('onesignal', 'cURL Error', ['error' => $err]);
            return ['success' => false, 'message' => 'Connection error: ' . $err];
        }

        $res = json_decode($response, true);
        
        if ($httpcode >= 200 && $httpcode < 300 && isset($res['id']) && empty($res['errors'])) {
            return [
                'success' => true, 
                'message' => 'Notification sent successfully!', 
                'tracking_id' => $res['id'],
                'recipients' => $res['recipients'] ?? 0
            ];
        }

        $errorMsg = 'Unknown error';
        if (isset($res['errors'])) {
            $errorMsg = is_array($res['errors']) ? implode(", ", $res['errors']) : $res['errors'];
        }

        log_error('onesignal', 'Push failed', ['http_code' => $httpcode, 'response' => $res]);
        return ['success' => false, 'message' => 'Push failed: ' . $errorMsg];
    }

    private function get_player_ids_by_type(string $user_type): array {
        $stmt = db()->prepare("SELECT push_token FROM users WHERE user_type = ? AND push_token IS NOT NULL AND push_token != '' AND is_active = 1");
        $stmt->execute([$user_type]);
        return array_column($stmt->fetchAll(), 'push_token');
    }

    private function get_player_id(int $user_id): ?string {
        $stmt = db()->prepare("SELECT push_token FROM users WHERE id = ? AND push_token IS NOT NULL AND push_token != ''");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch();
        return $row ? $row['push_token'] : null;
    }
}
