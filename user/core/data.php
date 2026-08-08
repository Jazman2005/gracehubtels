<?php
require_once __DIR__.'/db.php'; require_once __DIR__.'/auth.php'; require_once __DIR__.'/api_router.php';
class DataService {
    private function api_call(array $prov, array $payload): ?array {
        $pname = strtolower($prov['provider_name'] ?? '');
        if (strpos($pname, 'autosyncng') !== false) {
            $url = rtrim($prov['api_base_url'], '/') . '/api/v1/data';
        } elseif (strpos($pname, 'autopilot') !== false) {
            $base = rtrim(str_ireplace('/api/live', '', $prov['api_base_url']), '/');
            $url = $base . '/api/live/v1/data';
        } else {
            $url = rtrim($prov['api_base_url'], '/') . '/api/data/';
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
            CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        $res = curl_exec($ch);
        $err_no = curl_errno($ch);
        $err_msg = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $d = $res ? json_decode($res, true) : null;
        if ($d) $d['_http_code'] = $code;
        // A cURL-level failure means we genuinely don't know whether the
        // provider received/processed this request — not the same as an
        // explicit decline. Flagged so the timeout-handling below (this file
        // already distinguishes $is_timeout from a confirmed decline) has a
        // consistent marker matching the other services.
        if (!$res) $d = ['_http_code' => $code, '_curl_errno' => $err_no, '_curl_error' => $err_msg, '_network_error' => true];
        log_api($prov['provider_name'] ?? 'data_api', 'data/buy', $payload, $d, in_array($code, [200, 201]));
        return $d;
    }
    public function get_plans(string $net='',string $type=''):array{$where='WHERE is_active=1';$p=[];if($net){$where.=' AND network=?';$p[]=$net;}if($type){$where.=' AND data_type=?';$p[]=$type;}$s=db()->prepare("SELECT * FROM data_plans $where ORDER BY data_type,display_order,selling_price");$s->execute($p);return $s->fetchAll();}
    public function get_types(string $net=''):array{$where='WHERE is_active=1';$p=[];if($net){$where.=' AND network=?';$p[]=$net;}$s=db()->prepare("SELECT DISTINCT data_type,COALESCE(display_type_name,data_type) as label FROM data_plans $where ORDER BY data_type");$s->execute($p);return $s->fetchAll();}
    public function process(int $uid,array $input): array {
        $phone=sanitize_phone($input['phone']??'');$plan_id=(int)($input['plan_id']??0);$pin=trim((string)($input['pin']??''));
        if(!validate_phone($phone))return['success'=>false,'message'=>'Enter a valid phone number'];
        if($plan_id<1)return['success'=>false,'message'=>'Select a data plan'];
        if(detect_tamper($input))return['success'=>false,'message'=>'Invalid input'];
        if(!verify_pin($uid,$pin))return['success'=>false,'message'=>'Invalid transaction PIN'];
        $pl=db()->prepare("SELECT * FROM data_plans WHERE id=? AND is_active=1 LIMIT 1");$pl->execute([$plan_id]);$plan=$pl->fetch();if(!$plan)return['success'=>false,'message'=>'Plan not available'];
        $u=db()->prepare("SELECT user_type FROM users WHERE id=?");$u->execute([$uid]);$user=$u->fetch();$ut=$user['user_type']??'subscriber';
        $price=(float)$plan['selling_price'];
        if($ut==='agent'&&$plan['agent_price']>0)$price=(float)$plan['agent_price'];
        if($ut==='vendor'&&$plan['vendor_price']>0)$price=(float)$plan['vendor_price'];
        
        $promo_code = sanitize($input['promo_code']??'', 50);
        $discount = 0;
        $promo_id = 0;
        
        if(!empty($promo_code)) {
            $ps = db()->prepare("SELECT * FROM promo_codes WHERE code=? AND is_active=1 AND (valid_until IS NULL OR valid_until > NOW())");
            $ps->execute([$promo_code]);
            $promo = $ps->fetch();
            if($promo && ($promo['usage_limit'] == 0 || $promo['used_count'] < $promo['usage_limit'])) {
                if($promo['discount_type'] === 'fixed') {
                    $discount = (float)$promo['discount_value'];
                } else {
                    $discount = $price * ((float)$promo['discount_value']/100);
                }
                $promo_id = $promo['id'];
                $price = max(0, $price - $discount);
            }
        }

        if(!claim_purchase_lock($uid,'data',[$plan_id,$phone]))return['success'=>false,'message'=>'Duplicate request detected. Please wait a moment and try again.'];
        $desc_label = strtoupper($plan['network']) . ' ' . ($plan['plan_name'] ?: $plan['data_size']);$debit=debit_wallet($uid,$price,'data',"{$desc_label} for {$phone}",['network'=>$plan['network'],'phone'=>$phone,'plan_id'=>$plan_id,'data_size'=>$plan['data_size'],'plan_name'=>$plan['plan_name']]);if(!$debit['success'])return $debit;
        
        $providers = get_api_providers('data', $plan['network'], $plan['data_type']);
        $plan_bindings = get_plan_bindings('data', $plan_id);
        $providers = array_merge($plan_bindings, $providers);
        $success = false; $has_pending = false; $last_res = null; $last_prov = null;
        foreach ($providers as $prov) {
            if (empty($prov['api_base_url'])) continue;
            $last_prov = $prov;
            $plan_code = !empty($prov['product_code']) ? $prov['product_code'] : $plan['plan_id'];
            $api_payload = ['network' => $plan['network_id'], 'plan' => $plan_code, 'mobile_number' => $phone, 'Ported_number' => true, 'ref' => $debit['ref']];
            $pname = strtolower($prov['provider_name'] ?? '');
            if (strpos($pname, 'autosyncng') !== false) {
                $api_payload = ['phone' => $phone, 'product_id' => $plan['network_id'], 'variation_code' => (string)$plan_code, 'request_ref' => $debit['ref'], 'ported_no' => true];
            } elseif (strpos($pname, 'autopilot') !== false) {
                $dt = strtoupper($plan['data_type'] ?? '');
                if ($dt === 'CG' || $dt === 'CORPORATE') $dt = 'CORPORATE GIFTING';
                if ($dt === 'DG' || $dt === 'GIFTING') $dt = 'DIRECT GIFTING';
                
                $api_payload = [
                    'networkId' => (string)$plan['network_id'],
                    'dataType' => (string)$dt,
                    'planId' => (string)$plan_code,
                    'phone' => (string)$phone,
                    'reference' => (string)$debit['ref']
                ];

            } elseif (strpos($pname, 'msjdatasubs') !== false || strpos($pname, 'telecomabode') !== false) {
                $api_payload['phone'] = $phone; $api_payload['data_plan'] = $plan_code; unset($api_payload['mobile_number']); unset($api_payload['plan']); $api_payload['ported_number'] = true; unset($api_payload['Ported_number']);
            }
            if (!empty($prov['api_pin'])) { $api_payload['pin'] = $prov['api_pin']; if (strpos($pname, 'autosyncng') === false && strpos($pname, 'autopilot') === false) $api_payload['account_pin'] = $prov['api_pin']; }
            
            $res=$this->api_call($prov, $api_payload);
            $is_timeout = !$res || !empty($res['_curl_errno']);
            if($is_timeout){ usleep(3000000); $res=$this->api_call($prov, $api_payload); $is_timeout = !$res || !empty($res['_curl_errno']); }
            
            if($res&&in_array($res['_http_code']??0,[200,201])&&!is_api_response_failure($res)&&!is_api_response_pending($res)){ $success=true; break; }
            if($res&&in_array($res['_http_code']??0,[200,201])&&is_api_response_pending($res)&&!$success){ $has_pending=true; $last_res=$res; continue; }
            if($is_timeout){ $last_res = $res; break; } // timeout = stop trying, mark processing
            $last_res = $res;
        }
        
        if ($success && $last_prov) {
            $prov = $last_prov; $res = $last_res;
            // Guard against a duplicate/concurrent resolution of this same
            // ref (e.g. cron_check_vtu.php already resolved it while this
            // request was mid-flight) — only award cashback/promo usage and
            // notify success if we actually won the transition here.
            if (update_tx_status($debit['ref'],'success',(string)($res['id']??''),$plan['network'])) {
                $resp_msg = $res['message'] ?? $res['msg'] ?? '';
                if ($resp_msg) db()->prepare("UPDATE transactions SET meta = JSON_SET(COALESCE(meta, '{}'), '$.response', ?) WHERE transaction_ref=?")->execute([$resp_msg, $debit['ref']]);
                db()->prepare("UPDATE transactions SET cost_price=?,profit=?,provider=? WHERE transaction_ref=?")->execute([$plan['cost_price'],$price-$plan['cost_price'],$prov['provider_name'],$debit['ref']]);
                $cb=apply_cashback($uid,$price,'data',$plan['network'],(int)$plan['id']);
                if($promo_id > 0) { db()->prepare("INSERT INTO promo_usage (user_id, promo_id, transaction_ref, discount_applied) VALUES (?, ?, ?, ?)")->execute([$uid, $promo_id, $debit['ref'], $discount]); db()->prepare("UPDATE promo_codes SET used_count = used_count + 1 WHERE id=?")->execute([$promo_id]); }
                notify_user($uid,'✅ Data Purchased',"{$plan['data_size']} {$plan['network']} data sent to {$phone}",'success',null,$debit['balance_before'],$debit['balance_after']);
                return['success'=>true,'message'=>"{$plan['data_size']} {$plan['network']} data sent to {$phone}",'ref'=>$debit['ref'],'cashback'=>$cb];
            }
            return['success'=>true,'message'=>"{$plan['data_size']} {$plan['network']} data sent to {$phone}",'ref'=>$debit['ref']];
        }
        
        $is_timeout = $last_res && !empty($last_res['_curl_errno']);
        if ($last_prov) db()->prepare("UPDATE transactions SET provider=? WHERE transaction_ref=?")->execute([$last_prov['provider_name'],$debit['ref']]);
        if($is_timeout){ update_tx_status($debit['ref'],'processing'); return['success'=>true,'message'=>"Transaction is being processed. Check status shortly.",'ref'=>$debit['ref'],'processing'=>true]; }
        if($has_pending){ update_tx_status($debit['ref'],'pending',(string)($last_res['id']??'')); notify_user($uid,'Data Processing',"Your data purchase is being processed.",'info',null,$debit['balance_before'],$debit['balance_after']); return['success'=>true,'message'=>'Transaction is being processed. Check receipt for updates.','ref'=>$debit['ref'],'pending'=>true]; }
        
        $err_msg = 'Purchase failed. Wallet reversed.';
        if (!empty($last_res['message'])) { $err_msg = $last_res['message']; }
        refund_failed_transaction($debit['ref'], $uid, $price, $err_msg);
        return['success'=>true,'message'=>$err_msg,'ref'=>$debit['ref'],'refunded'=>true];
    }
}
function process_data(int $uid,array $input):array{return(new DataService())->process($uid,$input);}
function get_data_plans(string $net='',string $type=''):array{return(new DataService())->get_plans($net,$type);}
function get_data_types(string $net=''):array{return(new DataService())->get_types($net);}
