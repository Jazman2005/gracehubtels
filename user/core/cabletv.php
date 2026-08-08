<?php
require_once __DIR__.'/db.php'; require_once __DIR__.'/auth.php'; require_once __DIR__.'/api_router.php';
class CableTVService {
    private function api_get(array $prov, string $url): ?array {
        $headers = ['Content-Type: application/json'];
        if (!empty($prov['api_key'])) {
            $headers[] = (strpos(strtolower($prov['provider_name'] ?? ''), 'autopilot') !== false) ? 'Authorization: Bearer ' . $prov['api_key'] : 'Authorization: Token ' . $prov['api_key'];
        } elseif (!empty($prov['api_token'])) {
            $headers[] = 'Authorization: Bearer ' . $prov['api_token'];
        }
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);$res=curl_exec($ch);curl_close($ch);return $res?json_decode($res,true):null;
    }
    private function api_post(array $prov, string $url, array $data): ?array {
        $headers = ['Content-Type: application/json'];
        if (!empty($prov['api_key'])) {
            $headers[] = (strpos(strtolower($prov['provider_name'] ?? ''), 'autopilot') !== false) ? 'Authorization: Bearer ' . $prov['api_key'] : 'Authorization: Token ' . $prov['api_key'];
        } elseif (!empty($prov['api_token'])) {
            $headers[] = 'Authorization: Bearer ' . $prov['api_token'];
        }
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($data),CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);$res=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
        // A cURL-level failure means we genuinely don't know whether the
        // provider received/processed this request — not the same as an
        // explicit decline. Flagged so the final purchase decision can avoid
        // treating an uncertain outcome the same as a confirmed failure.
        $d=$res?json_decode($res,true):($res===false?['_network_error'=>true,'message'=>"cURL Error: ".$err]:null);
        if($d)$d['_http_code']=$code;log_api($prov['provider_name'] ?? 'cabletv_api','cabletv',$data,$d,in_array($code,[200,201]));return $d;
    }
    public function validate_smartcard(int $cid,string $sc): array {
        $all = get_api_providers('cabletv'); $prov = $all[0] ?? null;
        if(!$prov||empty($prov['api_base_url']))return['success'=>false,'message'=>'Service not configured'];
        if (stripos($prov['provider_name'] ?? '', 'autosyncng') !== false || stripos($prov['provider_name'] ?? '', 'autopilot') !== false) {
            $pc=db()->prepare("SELECT product_code FROM cable_plans WHERE cable_id=? AND product_code IS NOT NULL LIMIT 1");$pc->execute([$cid]);$pcode=$pc->fetchColumn();
            $pid=$pcode?:$cid;
            $base=rtrim(str_ireplace('/api/live', '', $prov['api_base_url']), '/');
            if (stripos($prov['provider_name'] ?? '', 'autopilot') !== false) {
                $cname_q = db()->prepare("SELECT name FROM cables WHERE id=? LIMIT 1"); $cname_q->execute([$cid]); $cname = strtoupper($cname_q->fetchColumn() ?: '');
                $url = $base . '/api/live/v1/validate/smartcard-no';
                $res=$this->api_post($prov,$url,['cableType'=>$cname, 'smartCardNo'=>$sc]);
                if ($res && !empty($res['data']['validate']['customerName'])) return ['success'=>true,'name'=>$res['data']['validate']['customerName']];
                return ['success'=>false,'message'=>$res['message']??$res['error']??'Invalid smartcard number'];
            } else {
                $url=$base."/api/v1/validate/cable";
                $res=$this->api_post($prov,$url,['iuc_number'=>$sc, 'product_id'=>$pid]);
                if ($res && !empty($res['data']['is_valid'])) return ['success'=>true,'name'=>$res['data']['customer_name'] ?? $res['data']['customer'] ?? ''];
                return ['success'=>false,'message'=>$res['data']['customer_name']??$res['message']??$res['error']??'Invalid smartcard number'];
            }
        }
        if (stripos($prov['provider_name'] ?? '', 'msjdatasubs') !== false) {
            $url=rtrim($prov['api_base_url'],'/')."/api/cable/verify/";
            $res=$this->api_post($prov,$url,['provider'=>$cid, 'iucnumber'=>$sc]);
            if ($res && (isset($res['Customer_Name']) || isset($res['msg']))) return ['success'=>true,'name'=>$res['Customer_Name'] ?? $res['msg'] ?? ''];
            return ['success'=>false,'message'=>$res['message']??$res['error']??'Invalid smartcard number'];
        }
        if (stripos($prov['provider_name'] ?? '', 'telecomabode') !== false) {
            $url=rtrim($prov['api_base_url'],'/')."/api/cable/cable-validation?iuc={$sc}&cable={$cid}";
            $res=$this->api_get($prov,$url);
            if ($res && ($res['status'] === 'success' || (isset($res['Status']) && $res['Status'] === 'successful'))) return ['success'=>true,'name'=>$res['name'] ?? $res['msg'] ?? ''];
            return ['success'=>false,'message'=>$res['message']??$res['error']??'Invalid smartcard number'];
        }
        $url=rtrim($prov['api_base_url'],'/')."/api/validateiuc/?cable_id={$cid}&smart_card_number={$sc}";
        $res=$this->api_get($prov,$url);
        if($res&&!($res['invalid']??true)&&empty($res['message'])&&empty($res['error']))return['success'=>true,'name'=>$res['name']];
        return['success'=>false,'message'=>$res['message']??$res['error']??'Invalid smartcard number'];
    }
    public function process(int $uid,array $input): array {
        $plan_id=(int)($input['plan_id']??0);$sc=preg_replace('/\D/','',$input['smartcard']??'');$pin=(string)($input['pin']??'');
        if($plan_id<1)return['success'=>false,'message'=>'Select a plan'];if(strlen($sc)<5)return['success'=>false,'message'=>'Invalid smartcard'];if(detect_tamper($input))return['success'=>false,'message'=>'Invalid input'];if(!verify_pin($uid,$pin))return['success'=>false,'message'=>'Invalid PIN'];
        $pl=db()->prepare("SELECT * FROM cable_plans WHERE id=? AND is_active=1 LIMIT 1");$pl->execute([$plan_id]);$plan=$pl->fetch();if(!$plan)return['success'=>false,'message'=>'Plan not available'];
        $u=db()->prepare("SELECT user_type FROM users WHERE id=?");$u->execute([$uid]);$user=$u->fetch();$ut=$user['user_type']??'subscriber';
        $price=(float)$plan['selling_price'];
        if($ut==='agent'&&$plan['agent_price']>0)$price=(float)$plan['agent_price'];
        if($ut==='vendor'&&$plan['vendor_price']>0)$price=(float)$plan['vendor_price'];
        $limit_err = check_daily_limit($uid, $price, 'cabletv');
        if($limit_err) return['success'=>false,'message'=>$limit_err];
        if(!claim_purchase_lock($uid,'cabletv',[$plan_id,$sc]))return['success'=>false,'message'=>'Duplicate request detected. Please wait a moment and try again.'];
        $debit=debit_wallet($uid,$price,'cabletv',"{$plan['plan_name']} for {$sc}",['plan_id'=>$plan_id,'provider'=>$plan['provider'],'smartcard'=>$sc]);if(!$debit['success'])return $debit;
        
        $providers = get_api_providers('cabletv');
        $plan_bindings = get_plan_bindings('cabletv', $plan['id']);
        $providers = array_merge($plan_bindings, $providers);
        $success = false; $has_pending = false; $last_res = null; $last_prov = null;
        foreach ($providers as $prov) {
            if (empty($prov['api_base_url'])) continue;
            $last_prov = $prov;
            $cable_id_override = (int)provider_network_id($prov, 'cable_'.$plan['cable_id'], $plan['cable_id']);
            $url=rtrim($prov['api_base_url'],'/').'/api/cablesub/';
            $api_payload = ['cablename'=>$cable_id_override,'cableplan'=>(int)$plan['plan_id'],'smart_card_number'=>$sc];
            if (stripos($prov['provider_name'] ?? '', 'autosyncng') !== false) {
                $pid=$plan['product_code']??'';if(!$pid){$pq=db()->prepare("SELECT product_code FROM cable_plans WHERE cable_id=? AND product_code IS NOT NULL LIMIT 1");$pq->execute([$plan['cable_id']]);$pid=$pq->fetchColumn()?:$plan['cable_id'];}
                $base=rtrim(str_ireplace('/api/live', '', $prov['api_base_url']), '/');
                $url=$base.'/api/v1/cable';
                $api_payload = ['iuc_number' => $sc, 'product_id' => (string)$pid, 'variation_code' => (string)$plan['plan_id'], 'request_ref' => $debit['ref'], 'phone' => $input['phone'] ?? '08000000000'];
            } elseif (stripos($prov['provider_name'] ?? '', 'autopilot') !== false) {
                $base=rtrim(str_ireplace('/api/live', '', $prov['api_base_url']), '/');
                $url=$base.'/api/live/v1/cable';
                $cname_q = db()->prepare("SELECT name FROM cables WHERE id=? LIMIT 1"); $cname_q->execute([$plan['cable_id']]); $cname = strtoupper($cname_q->fetchColumn() ?: '');
                $api_payload = [
                    'cableType' => (string)$cname,
                    'planId' => (string)$plan['plan_id'],
                    'paymentTypes' => 'FULL_PAYMENT',
                    'customerName' => 'Customer',
                    'smartCardNo' => (string)$sc,
                    'reference' => (string)$debit['ref']
                ];
                if ($cname === 'SHOWMAX') $api_payload['phoneNo'] = (string)($input['phone'] ?? '08000000000');

            } elseif (stripos($prov['provider_name'] ?? '', 'msjdatasubs') !== false) {
                $url=rtrim($prov['api_base_url'],'/').'/api/cabletv/';
                $api_payload = ['provider' => (string)$plan['cable_id'], 'plan' => (string)$plan['plan_id'], 'iucnumber' => $sc, 'subtype' => 'renew', 'phone' => $input['phone'] ?? '08000000000', 'ref' => $debit['ref']];
            } elseif (stripos($prov['provider_name'] ?? '', 'telecomabode') !== false) {
                $url=rtrim($prov['api_base_url'],'/').'/api/cable/';
                $api_payload = ['cable' => (string)$plan['cable_id'], 'cable_plan' => (string)$plan['plan_id'], 'iuc' => $sc, 'ref' => $debit['ref']];
            }
            if (!empty($prov['api_pin'])) { $api_payload['pin'] = $prov['api_pin']; if (stripos($prov['provider_name'] ?? '', 'autosyncng') === false && stripos($prov['provider_name'] ?? '', 'autopilot') === false) $api_payload['account_pin'] = $prov['api_pin']; }
            $res=$this->api_post($prov,$url,$api_payload);
            if($res&&in_array($res['_http_code']??0,[200,201])&&!is_api_response_failure($res)&&!is_api_response_pending($res)){ $success=true; break; }
            if($res&&in_array($res['_http_code']??0,[200,201])&&is_api_response_pending($res)&&!$success){ $has_pending=true; $last_res=$res; continue; }
            $last_res = $res;
        }
        
        if ($success && $last_prov) {
            $prov = $last_prov; $res = $last_res;
            if (update_tx_status($debit['ref'],'success',(string)($res['id']??''))) {
                $resp_msg = $res['message'] ?? $res['msg'] ?? '';
                if ($resp_msg) db()->prepare("UPDATE transactions SET meta = JSON_SET(COALESCE(meta, '{}'), '$.response', ?) WHERE transaction_ref=?")->execute([$resp_msg, $debit['ref']]);
                db()->prepare("UPDATE transactions SET cost_price=?,profit=?,provider=? WHERE transaction_ref=?")->execute([$plan['cost_price'],$price-$plan['cost_price'],$prov['provider_name'],$debit['ref']]);
                $cb=apply_cashback($uid,$price,'cabletv',null);
                notify_user($uid,'✅ Cable TV Subscribed',"{$plan['plan_name']} activated on {$sc}",'success',null,$debit['balance_before'],$debit['balance_after']);
                return['success'=>true,'message'=>"{$plan['plan_name']} subscription successful",'ref'=>$debit['ref'],'cashback'=>$cb];
            }
            return['success'=>true,'message'=>"{$plan['plan_name']} subscription successful",'ref'=>$debit['ref']];
        }

        if ($last_prov) db()->prepare("UPDATE transactions SET provider=? WHERE transaction_ref=?")->execute([$last_prov['provider_name'],$debit['ref']]);
        if ($has_pending) {
            update_tx_status($debit['ref'],'pending',(string)($last_res['id']??''));
            notify_user($uid,'Cable TV Processing',"Your {$plan['plan_name']} subscription is being processed.",'info',null,$debit['balance_before'],$debit['balance_after']);
            return['success'=>true,'message'=>'Transaction is being processed. Check receipt for updates.','ref'=>$debit['ref'],'pending'=>true];
        }

        // A network-level failure on the final attempt means we don't
        // actually know whether that provider processed the request —
        // refunding here risks giving the money back on top of a
        // subscription the provider still activates. Leave it 'processing'
        // for cron_check_vtu.php/cron_refund.php to resolve once the
        // provider's real status can be confirmed.
        if (!empty($last_res['_network_error'])) {
            update_tx_status($debit['ref'],'processing');
            notify_user($uid,'Cable TV Processing',"We're confirming your {$plan['plan_name']} subscription with the provider. This can take a few minutes.",'info',null,$debit['balance_before'],$debit['balance_after']);
            return['success'=>true,'message'=>'We could not immediately confirm this subscription due to a network issue. It is being verified — check your receipt shortly.','ref'=>$debit['ref'],'pending'=>true];
        }

        $err_msg = 'Subscription failed. Wallet reversed.';
        if (!empty($last_res['message'])) { $err_msg = $last_res['message']; }
        refund_failed_transaction($debit['ref'], $uid, $price, $err_msg);
        return['success'=>true,'message'=>$err_msg,'ref'=>$debit['ref'],'refunded'=>true];
    }
}
function process_cabletv(int $uid,array $input):array{return(new CableTVService())->process($uid,$input);}
function validate_smartcard(int $cid,string $sc):array{return(new CableTVService())->validate_smartcard($cid,$sc);}
