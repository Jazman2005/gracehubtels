<?php
require_once __DIR__.'/db.php'; 
require_once __DIR__.'/auth.php';
require_once __DIR__.'/api_router.php';

class BulkSMSService {
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
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        // A cURL-level failure means we genuinely don't know whether the
        // provider received/processed this request — not the same as an
        // explicit decline. Flagged so the final decision can avoid treating
        // an uncertain outcome the same as a confirmed failure.
        $d = $res ? json_decode($res, true) : ($res === false ? ['_network_error' => true, 'message' => "cURL Error: " . $err] : null);
        if ($d) $d['_http_code'] = $code;

        log_api($prov['provider_name'] ?? 'bulk_sms', 'send', $payload, $d, in_array($code, [200, 201]) && !is_api_response_failure($d));
        return $d;
    }

    public function process(int $uid, array $input): array {
        $sender = sanitize(trim($input['sender'] ?? $input['sender_id'] ?? ''), 11);
        $message = sanitize(trim($input['message'] ?? ''), 1000);
        $recipients = trim($input['recipients'] ?? '');
        $pin = (string)($input['pin'] ?? '');

        if (empty($sender)) return ['success' => false, 'message' => 'Sender ID is required (Max 11 chars)'];
        if (empty($message)) return ['success' => false, 'message' => 'Message body is empty'];
        if (empty($recipients)) return ['success' => false, 'message' => 'Recipients are required'];
        if (detect_tamper($input)) return ['success' => false, 'message' => 'Invalid input'];
        if (!verify_pin($uid, $pin)) return ['success' => false, 'message' => 'Invalid transaction PIN'];

        // Clean recipients
        $recs = explode(',', str_replace(["\r", "\n", " "], ",", $recipients));
        $valid_recipients = [];
        foreach ($recs as $r) {
            $r = sanitize_phone($r);
            if (validate_phone($r)) $valid_recipients[] = $r;
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
        
        $limit_err = check_daily_limit($uid, $total_amount, 'bulk_sms');
        if($limit_err) return['success'=>false,'message'=>$limit_err];

        if (!claim_purchase_lock($uid, 'bulk_sms', [$sender, $message, $valid_recipients])) return ['success' => false, 'message' => 'Duplicate request detected. Please wait a moment and try again.'];

        $debit = debit_wallet($uid, $total_amount, 'bulk_sms', "Bulk SMS ({$pages} pages to {$rec_count} numbers)", [
            'sender' => $sender,
            'pages' => $pages,
            'recipients' => $rec_count
        ]);

        if (!$debit['success']) return $debit;

        // Log to bulk_sms_history, keyed by transaction_ref — previously the
        // later success/failure UPDATEs matched by "user_id + total_cost
        // ORDER BY id DESC LIMIT 1" instead of the actual transaction_ref, so
        // two campaigns from the same user with an identical computed cost
        // racing close together could have their history rows
        // cross-attributed (the wrong campaign gets marked success/failed) —
        // a real audit-trail corruption risk, even though it never moved
        // money incorrectly on its own.
        try { db()->exec("ALTER TABLE bulk_sms_history ADD COLUMN transaction_ref VARCHAR(60) DEFAULT NULL"); } catch (\Throwable $e) {}
        $b_sql = "INSERT INTO bulk_sms_history (user_id, transaction_ref, sender_id, message, recipients, recipient_count, page_count, total_cost)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        db()->prepare($b_sql)->execute([
            $uid, $debit['ref'], $sender, $message, implode(',', $valid_recipients), $rec_count, $pages, $total_amount
        ]);

        // API Call with failover
        $providers = get_api_providers('bulk_sms');
        $success = false; $has_pending = false; $last_res = null; $last_prov = null;
        foreach ($providers as $prov) {
            $last_prov = $prov;
            $pname = strtolower($prov['provider_name'] ?? '');
            if (strpos($pname, 'autosyncng') !== false) {
                $api_payload = ['sender' => $sender, 'sender_id' => $sender, 'message' => $message, 'recipients' => implode(',', $valid_recipients), 'phone' => implode(',', $valid_recipients), 'request_ref' => $debit['ref'], 'ref' => $debit['ref']];
                if (!empty($prov['api_pin'])) $api_payload['pin'] = trim($prov['api_pin']);
            } elseif (strpos($pname, 'sagecloud') !== false) {
                $api_payload = ['reference' => $debit['ref'], 'recipients' => implode(', ', $valid_recipients), 'message' => $message, 'sender_ids' => $sender];
            } else {
                $api_payload = ['sender' => $sender, 'sender_id' => $sender, 'message' => $message, 'recipients' => implode(',', $valid_recipients), 'numbers' => implode(',', $valid_recipients), 'phones' => implode(',', $valid_recipients), 'ref' => $debit['ref']];
                if (!empty($prov['api_pin'])) { $api_payload['pin'] = trim($prov['api_pin']); if (strpos($pname, 'autopilot') === false) $api_payload['account_pin'] = trim($prov['api_pin']); }
            }
            
            $url = rtrim($prov['api_base_url'], '/') . '/api/sms/';
            if (strpos($pname, 'autosyncng') !== false) {
                if (strpos(strtolower($prov['api_base_url']), '/api/v1') !== false) {
                    $url = rtrim($prov['api_base_url'], '/') . '/sms/';
                }
            }
            if (strpos($pname, 'telecomabode') !== false) {
                $url = rtrim($prov['api_base_url'], '/') . '/api/bulk-sms/';
                $api_payload = ['subject' => $sender, 'message' => $message, 'bulkPhones' => implode(',', $valid_recipients), 'ref' => $debit['ref']];
            }
            if (strpos($pname, 'sagecloud') !== false) {
                $url = rtrim($prov['api_base_url'], '/') . '/v2/sms/bulk-sms/';
            }
            
            if (!empty($prov['api_base_url'])) {
                $prov['api_base_url'] = $url;
                $res = $this->api_call($prov, $api_payload);
                if ($res && in_array($res['_http_code'] ?? 0, [200, 201]) && !is_api_response_failure($res) && !is_api_response_pending($res)) { $success = true; break; }
                if ($res && in_array($res['_http_code'] ?? 0, [200, 201]) && is_api_response_pending($res) && !$success) { $has_pending = true; $last_res = $res; continue; }
                $last_res = $res;
            }
        }
        
        // If no providers, fallback to mock
        if (empty($providers)) {
            $last_res = ['success' => true, '_http_code' => 200, 'mocked' => true];
            $success = true;
        }

        if ($success) {
            $prov = $last_prov; $res = $last_res;
            save_tx_response($debit['ref'], provider_response_text($res));
            if (update_tx_status($debit['ref'], 'success', (string)($res['id'] ?? 'mocked'), 'SMS')) {
                db()->prepare("UPDATE transactions SET cost_price=?, profit=?, provider=? WHERE transaction_ref=?")
                    ->execute([$total_cost, $total_amount - $total_cost, $prov['provider_name'] ?? 'System', $debit['ref']]);
                db()->prepare("UPDATE bulk_sms_history SET status='success', provider_response=? WHERE transaction_ref=?")
                    ->execute([json_encode($res), $debit['ref']]);
                notify_user($uid, '✅ Bulk SMS Sent', "Campaign sent to {$rec_count} recipients", 'success', null, $debit['balance_before'], $debit['balance_after']);
            }
            return ['success' => true, 'message' => "Bulk SMS scheduled successfully to {$rec_count} recipients.", 'ref' => $debit['ref']];
        }

        // Pending on async response
        if ($last_prov) db()->prepare("UPDATE transactions SET provider=? WHERE transaction_ref=?")->execute([$last_prov['provider_name']??'System',$debit['ref']]);
        $provider_resp = provider_response_text($last_res);
        if ($has_pending) {
            update_tx_status($debit['ref'], 'pending', (string)($last_res['id'] ?? ''));
            save_tx_response($debit['ref'], $provider_resp);
            notify_user($uid, 'Bulk SMS Processing', 'Your bulk SMS campaign is being processed.', 'info', null, $debit['balance_before'], $debit['balance_after']);
            return ['success' => true, 'message' => 'Transaction is being processed. Check receipt for updates.', 'ref' => $debit['ref'], 'pending' => true];
        }

        // A network-level failure on the final attempt means we don't
        // actually know whether that provider processed the request — leave
        // it 'processing' for the verification crons instead of refunding
        // blindly.
        if (!empty($last_res['_network_error'])) {
            update_tx_status($debit['ref'], 'processing');
            save_tx_response($debit['ref'], $provider_resp);
            db()->prepare("UPDATE bulk_sms_history SET status='pending', provider_response=? WHERE transaction_ref=?")->execute([json_encode($last_res), $debit['ref']]);
            notify_user($uid, 'Bulk SMS Processing', "We're confirming your bulk SMS campaign with the provider. This can take a few minutes.", 'info', null, $debit['balance_before'], $debit['balance_after']);
            return ['success' => true, 'message' => 'We could not immediately confirm this campaign due to a network issue. It is being verified — check your receipt shortly.', 'ref' => $debit['ref'], 'pending' => true];
        }

        // Refund on failure
        if ($last_prov) db()->prepare("UPDATE transactions SET provider=? WHERE transaction_ref=?")->execute([$last_prov['provider_name'] ?? 'System', $debit['ref']]);
        db()->prepare("UPDATE bulk_sms_history SET status='failed', provider_response=? WHERE transaction_ref=?")
            ->execute([json_encode($last_res), $debit['ref']]);
        $err_msg = 'Campaign delivery failed. Wallet reversed.';
        if (!empty($last_res['message'])) { $err_msg = $last_res['message']; }
        refund_failed_transaction($debit['ref'], $uid, $total_amount, $err_msg);
        save_tx_response($debit['ref'], $provider_resp ?: $err_msg);
        return ['success' => true, 'message' => $err_msg, 'ref' => $debit['ref'], 'refunded' => true];
    }
}

function process_bulk_sms(int $uid, array $input): array {
    return (new BulkSMSService())->process($uid, $input);
}
