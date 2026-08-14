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
        } elseif (strpos($pname, 'gladtidings') !== false) {
            // Confirmed via Gladtidings' own Postman docs (BuyAirtime).
            $url = rtrim($prov['api_base_url'], '/') . '/api/topup/';
        } elseif (strpos($pname, 'reteller') !== false) {
            // Reteller's docs: one shared /purchase endpoint for every local
            // service (airtime/data/cable/electricity/betting) — no per-service path.
            $url = rtrim($prov['api_base_url'], '/') . '/purchase';
        } elseif (strpos($pname, 'payvessel') !== false) {
            $url = rtrim($prov['api_base_url'], '/') . '/vaas/api/v1/biller-reseller/orders';
        } else {
            $url = $prov['api_base_url'];
        }

        $headers = ['Content-Type: application/json'];
        if (strpos($pname, 'reteller') !== false && !empty($prov['public_key']) && !empty($prov['secret_key'])) {
            $headers[] = 'Authorization: Bearer ' . $prov['public_key'] . ':' . $prov['secret_key'];
        } elseif (strpos($pname, 'payvessel') !== false) {
            if (!empty($prov['api_key'])) $headers[] = 'api-key: ' . $prov['api_key'];
            if (!empty($prov['secret_key'])) $headers[] = 'api-secret: ' . $prov['secret_key'];
        } elseif (!empty($prov['api_key'])) {
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
            // A cURL-level failure (timeout, connection reset, DNS, etc.) means
            // we genuinely don't know whether the provider received/processed
            // this request — it is NOT the same thing as the provider
            // explicitly declining. Flagged so the caller can avoid treating
            // an uncertain outcome the same as a confirmed failure.
            $d = ['_http_code' => 500, '_network_error' => true, 'message' => "cURL Error: " . $err];
        } elseif ($d === null && !empty($res)) {
            $d = ['_http_code' => $code, 'message' => "HTTP " . $code . ": " . substr(strip_tags($res), 0, 200)];
        }
        if ($d) $d['_http_code'] = $code;
        log_api($prov['provider_name'] ?? 'airtime_api', 'airtime/topup', $payload, $d, in_array($code, [200, 201]));
        return $d;
    }
    public function process(int $uid,array $input): array {
      $debit = null; $charge_amount = 0;
      try {
        $phone=sanitize_phone($input['phone']??'');$amount=(float)($input['amount']??0);$network=strtoupper(trim($input['network']??''));$pin=(string)($input['pin']??'');
        if(!validate_phone($phone))return['success'=>false,'message'=>'Enter a valid 11-digit phone number'];
        $min = (float)setting('limit_airtime_min', '50');
        $max = (float)setting('limit_airtime_max', '10000');
        if($amount < $min || $amount > $max) return['success'=>false,'message'=>"Amount must be ₦" . number_format($min) . "–₦" . number_format($max)];
        if(!in_array($network,['MTN','GLO','AIRTEL','9MOBILE']))return['success'=>false,'message'=>'Invalid network'];
        if(detect_tamper($input))return['success'=>false,'message'=>'Invalid input detected'];
        if(!verify_pin($uid,$pin))return['success'=>false,'message'=>'Invalid transaction PIN'];
        if(!claim_purchase_lock($uid,'airtime',[$phone,$network,$amount]))return['success'=>false,'message'=>'Duplicate request detected. Please wait a moment and try again.'];
        $sig=purchase_signature('airtime',[$phone,$network,$amount]);
        $pr=db()->prepare("SELECT * FROM service_pricing WHERE service='airtime' AND network=? LIMIT 1");$pr->execute([$network]);$p=$pr->fetch();
        $user=get_user($uid);$ut=$user['user_type']??'subscriber';
        $discount=(float)($p['discount_percent']??0);
        if($ut==='agent'&&isset($p['agent_discount']))$discount=(float)$p['agent_discount'];
        if($ut==='vendor'&&isset($p['vendor_discount']))$discount=(float)$p['vendor_discount'];
        $charge_amount=$amount*((100-$discount)/100);
        $cost=$p?$amount*((100-(float)$p['discount_percent'])/100):$amount;
        $debit=debit_wallet($uid,$charge_amount,'airtime',"₦{$amount} {$network} airtime to {$phone}",['network'=>$network,'phone'=>$phone],$sig);if(!$debit['success'])return $debit;
        
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
            } elseif (strpos($pname, 'reteller') !== false) {
                // Reteller's variation_code is an opaque per-account catalog
                // token (their own docs: "send it back exactly as received;
                // do not parse or construct it") — there is no numeric
                // default to fall back to like every other provider here.
                // Deliberately `?? null`, not `?? $network`: sending Reteller
                // the generic default would guarantee a decline; skipping to
                // the next provider is strictly better than wasting the call.
                $variation_code = $net_map[$network] ?? null;
                if (!$variation_code) { $last_res = ['message' => "No Reteller variation_code configured for {$network} — set it in this route's Network ID Map."]; continue; }
                $api_payload = ['variation_code' => (string)$variation_code, 'recipient' => (string)$phone, 'amount' => $amount];
            } elseif (strpos($pname, 'payvessel') !== false) {
                // Same "opaque code per network, no numeric default" situation
                // Reteller is in — reuses the same network_id_map extensibility
                // point with a biller_id|item_id encoding (set in this route's
                // Network ID Map), rather than a separate mechanism.
                $pvcode = $net_map[$network] ?? null;
                if (!$pvcode || strpos($pvcode, '|') === false) { $last_res = ['message' => "No PayVessel biller_id|item_id configured for {$network} — set it in this route's Network ID Map."]; continue; }
                [$billerId, $itemId] = explode('|', $pvcode, 2);
                $api_payload = ['biller_id' => $billerId, 'item_id' => $itemId, 'recharge_account' => (string)$phone, 'amount' => (int)$amount, 'reference' => (string)$debit['ref']];
            } else {
                $api_payload = ['network' => $net_map[$network]??$network, 'amount' => $amount, 'mobile_number' => $phone, 'Ported_number' => true, 'airtime_type' => 'VTU', 'ref' => $debit['ref']];
                // Confirmed via Gladtidings' own Postman docs that its real
                // BuyAirtime endpoint wants exactly this base shape
                // (network/amount/mobile_number/Ported_number/airtime_type)
                // — it does NOT belong in the msjdatasubs/telecomabode
                // rename below, which was a guess that turned out wrong for
                // the sibling data endpoint too.
                if (strpos($pname, 'msjdatasubs') !== false || strpos($pname, 'telecomabode') !== false) {
                    $api_payload['phone'] = $phone; unset($api_payload['mobile_number']); $api_payload['ported_number'] = true; unset($api_payload['Ported_number']);
                }
                if (!empty($prov['api_pin'])) { $api_payload['pin'] = $prov['api_pin']; $api_payload['account_pin'] = $prov['api_pin']; }
            }
            $res=$this->api_call($prov, $api_payload);
            if($res&&in_array($res['_http_code']??0,[200,201])&&!is_api_response_failure($res)&&!is_api_response_pending($res)){ $success=true; $last_res=$res; break; }
            if($res&&in_array($res['_http_code']??0,[200,201])&&is_api_response_pending($res)&&!$success){ $has_pending=true; $last_res=$res; continue; }
            $last_res = $res;
        }
        
        if ($success && $last_prov) {
            $prov = $last_prov; $res = $last_res;
            save_tx_response($debit['ref'], provider_response_text($res));
            // Guard against a duplicate/concurrent resolution of this same
            // ref — only proceed to award cashback and notify success if we
            // actually won the pending/processing -> success transition.
            if (update_tx_status($debit['ref'],'success',(string)($res['id']??$res['data']['reference']??''),$network)) {
                db()->prepare("UPDATE transactions SET cost_price=?,profit=?,provider=? WHERE transaction_ref=?")->execute([$cost,$charge_amount-$cost,$prov['provider_name'],$debit['ref']]);
                $cb=apply_cashback($uid,$charge_amount,'airtime',$network);
                notify_user($uid,'✅ Airtime Sent',"₦{$amount} {$network} airtime sent to {$phone}",'success',null,$debit['balance_before'],$debit['balance_after']);
                return['success'=>true,'message'=>"₦{$amount} {$network} airtime sent to {$phone}",'ref'=>$debit['ref'],'cashback'=>$cb];
            }
            return['success'=>true,'message'=>"₦{$amount} {$network} airtime sent to {$phone}",'ref'=>$debit['ref']];
        }

        if ($last_prov) db()->prepare("UPDATE transactions SET provider=? WHERE transaction_ref=?")->execute([$last_prov['provider_name'],$debit['ref']]);
        $provider_resp = provider_response_text($last_res);
        if ($has_pending) {
            update_tx_status($debit['ref'],'pending',(string)($last_res['id']??$last_res['data']['reference']??''),$network);
            save_tx_response($debit['ref'], $provider_resp);
            notify_user($uid,'Airtime Processing',"Your {$amount} {$network} airtime purchase is being processed.",'info',null,$debit['balance_before'],$debit['balance_after']);
            return['success'=>true,'message'=>'Transaction is being processed. Check receipt for updates.','ref'=>$debit['ref'],'pending'=>true];
        }
        // A network-level failure (timeout/connection error) on the final
        // attempt means we don't actually know whether that provider
        // received/processed the request — it is NOT the same as every
        // provider explicitly declining. Refunding here would risk giving the
        // money back on top of airtime the provider still delivers. Leave it
        // 'processing' for cron_check_vtu.php to resolve once the provider's
        // real status can be confirmed, instead of refunding blindly.
        // A "duplicate reference" reply is treated the same as a network
        // error — it means the provider already has a record of this
        // reference from somewhere, so its real outcome is unknown, not a
        // clean decline (see data.php for the confirmed-live scenario this
        // covers: a retry/re-check reusing the same ref against a provider
        // that's simply slow, not down).
        if (!empty($last_res['_network_error']) || is_duplicate_reference_response($last_res)) {
            update_tx_status($debit['ref'],'processing');
            save_tx_response($debit['ref'], $provider_resp);
            if ($last_prov) log_error('airtime', 'Network error/duplicate-reference on final provider attempt — left processing pending verification', ['ref'=>$debit['ref'],'provider'=>$last_prov['provider_name']]);
            notify_user($uid,'Airtime Processing',"We're confirming your {$amount} {$network} airtime purchase with the network. This can take a few minutes.",'info',null,$debit['balance_before'],$debit['balance_after']);
            return['success'=>true,'message'=>'We could not immediately confirm this purchase due to a network issue. It is being verified — check your receipt shortly.','ref'=>$debit['ref'],'pending'=>true];
        }
        $err_msg = 'Purchase failed. Wallet reversed.';
        if (!empty($last_res['message'])) { $err_msg = $last_res['message']; }
        elseif (!empty($last_res['error'])) { $err_msg = is_array($last_res['error']) ? json_encode($last_res['error']) : $last_res['error']; }
        elseif (!empty($last_res['detail'])) { $err_msg = $last_res['detail']; }
        refund_failed_transaction($debit['ref'], $uid, $charge_amount, $err_msg);
        // See data.php for why this runs after refund_failed_transaction():
        // overwrites the generic/shortened failure reason with the full raw
        // provider reply so the receipt always shows the real response.
        save_tx_response($debit['ref'], $provider_resp ?: $err_msg);
        return['success'=>true,'message'=>$err_msg,'ref'=>$debit['ref'],'refunded'=>true];
      } catch (\Throwable $e) {
        // refund_failed_transaction() atomically claims the transaction
        // (WHERE status IN ('pending','processing')) before crediting
        // anything, so calling it here is a safe no-op if the purchase had
        // already reached a terminal status (success/failed/processing)
        // before this exception was thrown — every branch above writes its
        // terminal status before any statement that could realistically
        // throw, so this only ever fires for a genuinely still-pending debit.
        if ($debit !== null && ($debit['success'] ?? false)) {
            refund_failed_transaction($debit['ref'], $uid, $charge_amount, 'System error');
        }
        log_error('airtime', 'Purchase exception: ' . $e->getMessage(), ['line' => $e->getLine(), 'uid' => $uid]);
        return ['success' => false, 'message' => 'Purchase failed. Please try again.'];
      }
    }
}
function process_airtime(int $uid,array $input):array{return(new AirtimeService())->process($uid,$input);}
