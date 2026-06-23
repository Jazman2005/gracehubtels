<?php
require_once __DIR__.'/db.php'; 
require_once __DIR__.'/auth.php';

class BulkSMSService {
    private function get_provider(): ?array {
        $s = db()->prepare("SELECT * FROM api_providers WHERE service='bulk_sms' AND is_active=1 ORDER BY priority LIMIT 1");
        $s->execute();
        return $s->fetch() ?: null;
    }

    private function api_call(array $prov, array $payload): ?array {
        if (empty($prov['api_base_url'])) return ['success' => true, 'mocked' => true, '_http_code' => 200]; // Mock if no URL
        
        $ch = curl_init($prov['api_base_url']);
        
        // Add auth token/key if provided
        $headers = ['Content-Type: application/json'];
        if (!empty($prov['api_token'])) {
            $headers[] = 'Authorization: Bearer ' . $prov['api_token'];
        } elseif (!empty($prov['api_key'])) {
            $headers[] = 'Authorization: Token ' . $prov['api_key'];
            // Sometimes APIs want key in payload
            $payload['api_key'] = $prov['api_key'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $d = $res ? json_decode($res, true) : null;
        if ($d) $d['_http_code'] = $code;
        
        log_api($prov['provider_name'] ?? 'bulk_sms', 'send', $payload, $d, in_array($code, [200, 201]));
        return $d;
    }

    public function process(int $uid, array $input): array {
        $sender = sanitize($input['sender_id'] ?? '', 11);
        $message = trim($input['message'] ?? '');
        $recipients_raw = trim($input['recipients'] ?? '');
        $pin = (string)($input['pin'] ?? '');

        // Validation
        if (empty($sender) || strlen($sender) > 11) return ['success' => false, 'message' => 'Sender ID must be 1-11 characters'];
        if (empty($message)) return ['success' => false, 'message' => 'Message cannot be empty'];
        if (empty($recipients_raw)) return ['success' => false, 'message' => 'Please provide at least one recipient'];
        
        if (detect_tamper($input)) return ['success' => false, 'message' => 'Invalid input detected'];
        if (!verify_pin($uid, $pin)) return ['success' => false, 'message' => 'Invalid transaction PIN'];

        // Process recipients (split by comma or newline, remove empty and non-numeric)
        $recipients_array = preg_split('/[\s,]+/', $recipients_raw, -1, PREG_SPLIT_NO_EMPTY);
        $valid_recipients = [];
        foreach ($recipients_array as $num) {
            $clean = preg_replace('/[^0-9\+]/', '', $num);
            if (strlen($clean) >= 10 && strlen($clean) <= 15) {
                $valid_recipients[] = $clean;
            }
        }
        $valid_recipients = array_unique($valid_recipients);
        $rec_count = count($valid_recipients);
        
        if ($rec_count === 0) return ['success' => false, 'message' => 'No valid phone numbers found'];

        // Calculate pages
        $msg_len = mb_strlen($message);
        $pages = 1;
        if ($msg_len > 160) {
            $pages = ceil($msg_len / 153);
        }

        // Pricing
        $prov = $this->get_provider();
        $pr = db()->prepare("SELECT * FROM service_pricing WHERE service='bulk_sms' AND is_active=1 LIMIT 1");
        $pr->execute();
        $p = $pr->fetch();
        
        $user=get_user($uid);$ut=$user['user_type']??'subscriber';
        $base_selling = $p ? (float)$p['selling_price'] : 3.00;
        if($ut==='agent'&&isset($p['agent_price'])&&$p['agent_price']>0)$base_selling=(float)$p['agent_price'];
        if($ut==='vendor'&&isset($p['vendor_price'])&&$p['vendor_price']>0)$base_selling=(float)$p['vendor_price'];
        
        $base_cost = $p ? (float)$p['cost_price'] : 2.50;
        $discount = $p ? (float)$p['discount_percent'] : 0;
        if($ut==='agent'&&isset($p['agent_discount']))$discount=(float)$p['agent_discount'];
        if($ut==='vendor'&&isset($p['vendor_discount']))$discount=(float)$p['vendor_discount'];
        
        $price_per_page = $base_selling * ((100 - $discount) / 100);
        $cost_per_page = $base_cost;

        $total_amount = $price_per_page * $pages * $rec_count;
        $total_cost = $cost_per_page * $pages * $rec_count;

        if ($total_amount < 1) return ['success' => false, 'message' => 'Total amount is too low'];

        // Debit Wallet
        $desc = "Bulk SMS: {$pages} pages to {$rec_count} recipients (Sender: {$sender})";
        $debit = debit_wallet($uid, $total_amount, 'bulk_sms', $desc, [
            'sender' => $sender,
            'pages' => $pages,
            'recipients' => $rec_count
        ]);
        
        if (!$debit['success']) return $debit;

        // Log to bulk_sms_history
        $b_sql = "INSERT INTO bulk_sms_history (user_id, sender_id, message, recipients, recipient_count, page_count, total_cost) 
                  VALUES (?, ?, ?, ?, ?, ?, ?)";
        db()->prepare($b_sql)->execute([
            $uid, $sender, $message, implode(',', $valid_recipients), $rec_count, $pages, $total_amount
        ]);

        // API Call
        $api_payload = [
            'sender' => $sender,
            'message' => $message,
            'recipients' => implode(',', $valid_recipients),
            'ref' => $debit['ref']
        ];
        
        $res = $prov ? $this->api_call($prov, $api_payload) : ['success' => true, '_http_code' => 200, 'mocked' => true];

        if ($res && in_array($res['_http_code'] ?? 0, [200, 201])) {
            update_tx_status($debit['ref'], 'success', (string)($res['id'] ?? 'mocked'), 'SMS');
            
            // Update profit
            db()->prepare("UPDATE transactions SET cost_price=?, selling_price=?, profit=?, provider=? WHERE transaction_ref=?")
                ->execute([$total_cost, $total_amount, $total_amount - $total_cost, $prov['provider_name'] ?? 'System', $debit['ref']]);
            
            // Log history success
            db()->prepare("UPDATE bulk_sms_history SET status='success', provider_response=? WHERE user_id=? AND total_cost=? ORDER BY id DESC LIMIT 1")
                ->execute([json_encode($res), $uid, $total_amount]);

            notify_user($uid, 'o. Bulk SMS Sent', "Campaign sent to {$rec_count} recipients.", 'success');
            
            return [
                'success' => true, 
                'message' => "Bulk SMS scheduled successfully to {$rec_count} recipients.", 
                'ref' => $debit['ref']
            ];
        }

        // Refund on failure
        update_tx_status($debit['ref'], 'failed');
        db()->prepare("UPDATE bulk_sms_history SET status='failed', provider_response=? WHERE user_id=? AND total_cost=? ORDER BY id DESC LIMIT 1")
            ->execute([json_encode($res), $uid, $total_amount]);
            
        credit_wallet($uid, $total_amount, 'bulk_sms', 'Bulk SMS reversal - API failure');
        
        return [
            'success' => false, 
            'message' => 'Campaign delivery failed. Wallet has been reversed.'
        ];
    }
}

function process_bulk_sms(int $uid, array $input): array {
    return (new BulkSMSService())->process($uid, $input);
}
