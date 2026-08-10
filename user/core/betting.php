<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/api_router.php';

// Auto-migrate betting tables
try { 
    $db = db();
    $db->exec("CREATE TABLE IF NOT EXISTS betting_plans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        platform_name VARCHAR(100) NOT NULL,
        provider_code VARCHAR(100) NOT NULL,
        charge DECIMAL(10,2) DEFAULT 0.00,
        agent_charge DECIMAL(10,2) DEFAULT 0.00,
        vendor_charge DECIMAL(10,2) DEFAULT 0.00,
        is_active TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    
    $db->exec("INSERT IGNORE INTO service_pricing (service, plan_name, selling_price, cost_price, agent_price, vendor_price, is_active) 
               VALUES ('betting', 'Default Betting Charge', 0, 0, 0, 0, 1)");
               
    // Insert defaults if empty
    $chk = $db->query("SELECT COUNT(*) FROM betting_plans")->fetchColumn();
    if ($chk == 0) {
        $platforms = [
            ['Bet9ja', 'Bet9ja'],
            ['SportyBet', 'SportyBet'],
            ['1xBet', '1xBet'],
            ['BetKing', 'BetKing']
        ];
        $stmt = $db->prepare("INSERT INTO betting_plans (platform_name, provider_code) VALUES (?, ?)");
        foreach ($platforms as $p) {
            $stmt->execute($p);
        }
    }
} catch (\Throwable $e) {}

class BettingService {
    private function api_post(array $prov, string $url, array $data): ?array {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if (!empty($prov['api_token'])) $headers[] = 'Authorization: Bearer ' . $prov['api_token'];
        if (!empty($prov['api_key'])) {
            $pname = strtolower($prov['provider_name'] ?? '');
            if (stripos($pname, 'sagecloud') !== false) {
                $headers[] = 'publicKey: ' . $prov['api_key'];
            } else {
                $headers[] = 'Authorization: Token ' . $prov['api_key'];
            }
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $d = $res ? json_decode($res, true) : null;
        if ($d) $d['_http_code'] = $code;
        // A cURL-level failure means we genuinely don't know whether the
        // provider received/processed this request — not the same as an
        // explicit decline. Flagged (fixing a pre-existing bug where
        // curl_error() was called *after* curl_close(), always returning an
        // empty string) so the final purchase decision can avoid treating an
        // uncertain outcome the same as a confirmed failure.
        if (!$res) $d = ['_http_code' => $code, '_curl_error' => $err, '_network_error' => true];
        elseif (!$d) $d = ['_http_code' => $code, '_raw_response' => $res];
        
        log_api($prov['provider_name'] ?? 'betting_api', 'betting', $data, $d, in_array($code, [200, 201]) && !is_api_response_failure($d));
        return $d;
    }

    public function validate_customer(int $plan_id, string $customerId): array {
        $pl = db()->prepare("SELECT * FROM betting_plans WHERE id=? AND is_active=1 LIMIT 1");
        $pl->execute([$plan_id]);
        $plan = $pl->fetch();
        if (!$plan) return ['success'=>false,'message'=>'Platform not available'];

        $providers = get_api_providers('betting');
        if (empty($providers)) return ['success'=>false,'message'=>'Service not configured'];
        
        foreach ($providers as $prov) {
            $pname = strtolower($prov['provider_name'] ?? '');
            if (stripos($pname, 'sagecloud') !== false) {
                $base = rtrim($prov['api_base_url'], '/');
                if (substr($base, -4) !== '/api') $base .= '/api';
                $url = $base . '/v2/betting/validate';
                
                $res = $this->api_post($prov, $url, ['type' => $plan['provider_code'], 'customerId' => $customerId]);
                if ($res && in_array($res['_http_code'] ?? 0, [200, 201]) && !empty($res['data']['name'])) {
                    return ['success' => true, 'name' => $res['data']['name']];
                }
                return ['success' => false, 'message' => $res['message'] ?? $res['error'] ?? 'Invalid Customer ID'];
            }
        }
        
        return ['success' => false, 'message' => 'Validation not supported by current provider'];
    }

    public function process(int $uid, array $input): array {
        $plan_id = (int)($input['plan_id'] ?? 0);
        $customerId = sanitize(trim($input['customer_id'] ?? ''), 50);
        $amount = (float)($input['amount'] ?? 0);
        $pin = (string)($input['pin'] ?? '');

        if ($plan_id < 1) return ['success'=>false,'message'=>'Select a platform'];
        if (empty($customerId)) return ['success'=>false,'message'=>'Invalid Customer ID'];
        
        $min = (float)setting('limit_betting_min', '100');
        $max = (float)setting('limit_betting_max', '500000');
        if ($amount < $min || $amount > $max) return ['success'=>false,'message'=>"Amount must be between ₦" . number_format($min) . " and ₦" . number_format($max)];
        
        if (detect_tamper($input)) return ['success'=>false,'message'=>'Invalid input'];
        if (!verify_pin($uid, $pin)) return ['success'=>false,'message'=>'Invalid transaction PIN'];

        $pl = db()->prepare("SELECT * FROM betting_plans WHERE id=? AND is_active=1 LIMIT 1");
        $pl->execute([$plan_id]);
        $plan = $pl->fetch();
        if (!$plan) return ['success'=>false,'message'=>'Platform not available'];

        $user = get_user($uid);
        $ut = $user['user_type'] ?? 'subscriber';

        $charge = (float)$plan['charge'];
        if ($ut === 'agent' && $plan['agent_charge'] > 0) $charge = (float)$plan['agent_charge'];
        if ($ut === 'vendor' && $plan['vendor_charge'] > 0) $charge = (float)$plan['vendor_charge'];

        $total = $amount + $charge;
        
        $limit_err = check_daily_limit($uid, $total, 'betting');
        if($limit_err) return['success'=>false,'message'=>$limit_err];

        if (!claim_purchase_lock($uid, 'betting', [$plan_id, $customerId, $amount])) return ['success'=>false,'message'=>'Duplicate request detected. Please wait a moment and try again.'];
        $desc = "₦{$amount} {$plan['platform_name']} funding for ID: {$customerId}";
        $debit = debit_wallet($uid, $total, 'betting', $desc, ['platform' => $plan['platform_name'], 'customer_id' => $customerId, 'charge' => $charge]);
        if (!$debit['success']) return $debit;

        $providers = get_api_providers('betting');
        $success = false; $has_pending = false; $last_res = null; $last_prov = null;
        
        // Name validation prior to posting (some APIs need name in payload)
        $customerName = $input['customer_name'] ?? 'Unknown Customer';
        if (empty($input['customer_name'])) {
            $val = $this->validate_customer($plan_id, $customerId);
            if ($val['success']) $customerName = $val['name'];
        }

        foreach ($providers as $prov) {
            if (empty($prov['api_base_url'])) continue;
            $last_prov = $prov;
            $pname = strtolower($prov['provider_name'] ?? '');
            
            if (stripos($pname, 'sagecloud') !== false) {
                $base = rtrim($prov['api_base_url'], '/');
                if (substr($base, -4) !== '/api') $base .= '/api';
                $url = $base . '/v2/betting/payment';
                $api_payload = [
                    'reference' => $debit['ref'],
                    'type' => $plan['provider_code'],
                    'customerId' => $customerId,
                    'name' => $customerName,
                    'amount' => $amount
                ];
            } else {
                // Generic fallback structure
                $url = rtrim($prov['api_base_url'], '/') . '/api/betting/';
                $api_payload = ['reference' => $debit['ref'], 'provider' => $plan['provider_code'], 'customer_id' => $customerId, 'amount' => $amount];
                if (!empty($prov['api_pin'])) $api_payload['pin'] = $prov['api_pin'];
            }

            $res = $this->api_post($prov, $url, $api_payload);
            $last_res = $res;
            if ($res && in_array($res['_http_code'] ?? 0, [200, 201]) && !is_api_response_failure($res) && !is_api_response_pending($res)) { $success = true; break; }
            if ($res && in_array($res['_http_code'] ?? 0, [200, 201]) && is_api_response_pending($res) && !$success) { $has_pending = true; continue; }
        }

        if ($success && $last_prov) {
            $prov = $last_prov; $res = $last_res;
            save_tx_response($debit['ref'], provider_response_text($res));
            if (update_tx_status($debit['ref'], 'success', (string)($res['id'] ?? ''))) {
                notify_user($uid, 'Betting Funded', "{$plan['platform_name']} account ({$customerId}) funded with ₦{$amount}.", 'success', null, $debit['balance_before'], $debit['balance_after']);
                $cb = apply_cashback($uid, $amount, 'betting', null);
                return ['success'=>true, 'message'=>"Betting wallet funded successfully.", 'ref'=>$debit['ref'], 'cashback'=>$cb];
            }
            return ['success'=>true, 'message'=>"Betting wallet funded successfully.", 'ref'=>$debit['ref']];
        }

        if ($last_prov) db()->prepare("UPDATE transactions SET provider=? WHERE transaction_ref=?")->execute([$last_prov['provider_name'], $debit['ref']]);
        $provider_resp = provider_response_text($last_res);
        if ($has_pending) {
            $prov_ref = $last_res['id'] ?? $last_res['data']['transaction']['reference'] ?? $last_res['reference'] ?? '';
            update_tx_status($debit['ref'], 'pending', $prov_ref);
            save_tx_response($debit['ref'], $provider_resp);
            notify_user($uid, 'Betting Processing', "Funding for {$plan['platform_name']} ({$customerId}) is processing.", 'info', null, $debit['balance_before'], $debit['balance_after']);
            return ['success'=>true, 'message'=>'Transaction is being processed. Check receipt for updates.', 'ref'=>$debit['ref'], 'pending'=>true];
        }

        // A network-level failure on the final attempt means we don't
        // actually know whether that provider processed the request — leave
        // it 'processing' pending verification instead of refunding blindly
        // (betting platforms have no automated status-check integrated here,
        // so this will need cron_refund.php's manual-review escalation or an
        // admin to resolve it via Transaction Details).
        if (!empty($last_res['_network_error'])) {
            update_tx_status($debit['ref'], 'processing');
            save_tx_response($debit['ref'], $provider_resp);
            notify_user($uid, 'Betting Processing', "We're confirming your {$plan['platform_name']} funding with the provider. This can take a few minutes.", 'info', null, $debit['balance_before'], $debit['balance_after']);
            return ['success'=>true, 'message'=>'We could not immediately confirm this funding due to a network issue. It is being verified — check your receipt shortly.', 'ref'=>$debit['ref'], 'pending'=>true];
        }

        $err = 'Funding failed. Wallet reversed.';
        if (!empty($last_res['message'])) $err = $last_res['message'];
        elseif (!empty($last_res['error'])) $err = is_array($last_res['error']) ? json_encode($last_res['error']) : $last_res['error'];
        refund_failed_transaction($debit['ref'], $uid, $total, $err);
        save_tx_response($debit['ref'], $provider_resp ?: $err);
        return['success'=>true,'message'=>$err,'ref'=>$debit['ref'],'refunded'=>true];
    }
}

function process_betting(int $uid, array $input): array {
    return (new BettingService())->process($uid, $input);
}
function validate_betting_customer(int $plan_id, string $customerId): array {
    return (new BettingService())->validate_customer($plan_id, $customerId);
}
