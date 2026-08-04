<?php
require_once __DIR__.'/db.php'; require_once __DIR__.'/auth.php'; require_once __DIR__.'/api_router.php';
class AirtimeService {
    private function api_call(array $prov, array $payload): ?array {
        $pname = strtolower($prov['provider_name'] ?? '');
        if (strpos($pname, 'autosyncng') !== false) {
            $url = rtrim($prov['api_base_url'], '/') . '/api/v1/airtime';
        } elseif (strpos($pname, 'autopilot') !== false) {
            $base = rtrim(str_ireplace('/api/live', '', $prov['api_base_url']), '/');
            $url = $base . '/api/live/v1/airtime';
        } elseif (strpos($pname, 'msjdatasubs') !== false) {
            $url = rtrim($prov['api_base_url'], '/') . '/api/airtime/';
        } elseif (strpos($pname, 'telecomabode') !== false) {
            $url = rtrim($prov['api_base_url'], '/') . '/api/topup/';
        } else {
            $url = $prov['api_base_url'];
        }
        
        $headers = ['Content-Type: application/json'];
        if (!empty($prov['api_key'])) {
            $headers[] = (strpos(strtolower($prov['provider_name'] ?? ''), 'autopilot') !== false) ? 'Authorization: Bearer ' . $prov['api_key'] : 'Authorization: Token ' . $prov['api_key'];
        } elseif (!empty($prov['api_token'])) {
            $headers[] = 'Authorization: Bearer ' . $prov['api_token'];
        }
        
        $ch = curl_init($url);
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
        
        $d = $res ? json_decode($res, true) : null;
        if ($res === false) {
            $d = ['_http_code' => 500, 'message' => "cURL Error: " . $err];
        } elseif ($d === null && !empty($res)) {
            $d = ['_http_code' => $code, 'message' => "HTTP " . $code . ": " . substr(strip_tags($res), 0, 200)];
        }
        if ($d) $d['_http_code'] = $code;
        log_api($prov['provider_name'] ?? 'airtime_api', 'airtime/topup', $payload, $d, in_array($code, [200, 201]));
        return $d;
    }
    public function process(int $uid,array $input): array {
        $phone=sanitize_phone($input['phone']??'');$amount=(float)($input['amount']??0);$network=strtoupper(trim($input['network']??''));$pin=(string)($input['pin']??'');
        if(!validate_phone($phone))return['success'=>false,'message'=>'Enter a valid 11-digit phone number'];
        $min = (float)setting('limit_airtime_min', '50');
        $max = (float)setting('limit_airtime_max', '10000');
        if($amount < $min || $amount > $max) return['success'=>false,'message'=>"Amount must be ₦" . number_format($min) . "–₦" . number_format($max)];
        if(!in_array($network,['MTN','GLO','AIRTEL','9MOBILE']))return['success'=>false,'message'=>'Invalid network'];
        if(detect_tamper($input))return['success'=>false,'message'=>'Invalid input detected'];
        if(!verify_pin($uid,$pin))return['success'=>false,'message'=>'Invalid transaction PIN'];
        $pr=db()->prepare("SELECT * FROM service_pricing WHERE service='airtime' AND network=? LIMIT 1");$pr->execute([$network]);$p=$pr->fetch();
        $user=get_user($uid);$ut=$user['user_type']??'subscriber';
        $discount=(float)($p['discount_percent']??0);
        if($ut==='agent'&&isset($p['agent_discount']))$discount=(float)$p['agent_discount'];
        if($ut==='vendor'&&isset($p['vendor_discount']))$discount=(float)$p['vendor_discount'];
        $charge_amount=$amount*((100-$discount)/100);
        $cost=$p?$amount*((100-(float)$p['discount_percent'])/100):$amount;
        $debit=debit_wallet($uid,$charge_amount,'airtime',"₦{$amount} {$network} airtime to {$phone}",['network'=>$network,'phone'=>$phone]);if(!$debit['success'])return $debit;
        
        $providers = get_api_providers('airtime', $network);
        $success = false; $has_pending = false; $last_res = null; $last_prov = null;
        foreach ($providers as $prov) {
            if (empty($prov['api_base_url'])) continue;
            $last_prov = $prov;
            $net_map = build_network_map($prov, 'airtime');
            $pname = strtolower($prov['provider_name'] ?? '');
            if (strpos($pname, 'autosyncng') !== false) {
                $api_payload = ['phone' => $phone, 'amount' => $amount, 'product_id' => $net_map[$network]??$network, 'request_ref' => $debit['ref'], 'ported_no' => true];
                if (!empty($prov['api_pin'])) $api_payload['pin'] = $prov['api_pin'];
            } elseif (strpos($pname, 'autopilot') !== false) {
                $api_payload = [
                    'networkId' => (string)($net_map[$network] ?? $network),
                    'airtimeType' => 'VTU',
                    'amount' => (string)$amount,
                    'phone' => (string)$phone,
                    'reference' => (string)$debit['ref']
                ];
                if (!empty($prov['api_pin'])) $api_payload['pin'] = (string)$prov['api_pin'];
            } else {
                $api_payload = ['network' => $net_map[$network]??$network, 'amount' => $amount, 'mobile_number' => $phone, 'Ported_number' => true, 'airtime_type' => 'VTU', 'ref' => $debit['ref']];
                if (strpos($pname, 'msjdatasubs') !== false || strpos($pname, 'telecomabode') !== false) {
                    $api_payload['phone'] = $phone; unset($api_payload['mobile_number']); $api_payload['ported_number'] = true; unset($api_payload['Ported_number']);
                }
                if (!empty($prov['api_pin'])) { $api_payload['pin'] = $prov['api_pin']; $api_payload['account_pin'] = $prov['api_pin']; }
            }
            $res=$this->api_call($prov, $api_payload);
            if($res&&in_array($res['_http_code']??0,[200,201])&&!is_api_response_failure($res)&&!is_api_response_pending($res)){ $success=true; break; }
            if($res&&in_array($res['_http_code']??0,[200,201])&&is_api_response_pending($res)&&!$success){ $has_pending=true; $last_res=$res; continue; }
            $last_res = $res;
        }
        
        if ($success && $last_prov) {
            $prov = $last_prov; $res = $last_res;
            update_tx_status($debit['ref'],'success',(string)($res['id']??''),$network);
            $resp_msg = $res['message'] ?? $res['msg'] ?? '';
            if ($resp_msg) db()->prepare("UPDATE transactions SET meta = JSON_SET(COALESCE(meta, '{}'), '$.response', ?) WHERE transaction_ref=?")->execute([$resp_msg, $debit['ref']]);
            db()->prepare("UPDATE transactions SET cost_price=?,profit=?,provider=? WHERE transaction_ref=?")->execute([$cost,$charge_amount-$cost,$prov['provider_name'],$debit['ref']]);
            $cb=apply_cashback($uid,$charge_amount,'airtime',$network);
            notify_user($uid,'o. Airtime Sent',",{$amount} {$network} airtime sent to {$phone}",'success',null,$debit['balance_before'],$debit['balance_after']);
            return['success'=>true,'message'=>",{$amount} {$network} airtime sent to {$phone}",'ref'=>$debit['ref'],'cashback'=>$cb];
        }
        
        if ($last_prov) db()->prepare("UPDATE transactions SET provider=? WHERE transaction_ref=?")->execute([$last_prov['provider_name'],$debit['ref']]);
        if ($has_pending) {
            update_tx_status($debit['ref'],'pending',(string)($last_res['id']??''),$network);
            notify_user($uid,'Airtime Processing',"Your {$amount} {$network} airtime purchase is being processed.",'info',null,$debit['balance_before'],$debit['balance_after']);
            return['success'=>true,'message'=>'Transaction is being processed. Check receipt for updates.','ref'=>$debit['ref'],'pending'=>true];
        }
        $err_msg = 'Purchase failed. Wallet reversed.';
        if (!empty($last_res['message'])) { $err_msg = $last_res['message']; }
        elseif (!empty($last_res['error'])) { $err_msg = is_array($last_res['error']) ? json_encode($last_res['error']) : $last_res['error']; }
        elseif (!empty($last_res['detail'])) { $err_msg = $last_res['detail']; }
        refund_failed_transaction($debit['ref'], $uid, $charge_amount, $err_msg);
        return['success'=>true,'message'=>$err_msg,'ref'=>$debit['ref'],'refunded'=>true];
    }
}
function process_airtime(int $uid,array $input):array{return(new AirtimeService())->process($uid,$input);}
