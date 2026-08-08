<?php
require_once __DIR__.'/db.php'; require_once __DIR__.'/auth.php'; require_once __DIR__.'/api_router.php';
class DataCardService {
    private function api_post(array $prov,array $data): ?array {
        $pname = strtolower($prov['provider_name'] ?? '');
        if (strpos($pname, 'autosyncng') !== false) {
            $url=rtrim($prov['api_base_url'],'/').'/api/v1/data/pin';
        } else {
            $url=rtrim($prov['api_base_url'],'/').'/api/rechargepin/';
        }
        $token=$prov['api_key']??$prov['api_token'];$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($data),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Token '.$token],CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);$res=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
        // A cURL-level failure means we genuinely don't know whether the
        // provider received/processed this request — not the same as an
        // explicit decline. Flagged so the final purchase decision can avoid
        // treating an uncertain outcome the same as a confirmed failure.
        $d=$res?json_decode($res,true):($res===false?['_network_error'=>true,'message'=>"cURL Error: ".$err]:null);
        if($d)$d['_http_code']=$code;log_api($prov['provider_name']??'data_card_api','data_card',$data,$d,in_array($code,[200,201]));return $d;}
    public function process(int $uid,array $input): array {
        $plan_id=(int)($input['plan_id']??0);$qty=(int)($input['quantity']??1);$pin=(string)($input['pin']??'');
        if($plan_id<1)return['success'=>false,'message'=>'Select a plan'];if($qty<1||$qty>5)return['success'=>false,'message'=>'Quantity 1–5'];if(detect_tamper($input))return['success'=>false,'message'=>'Invalid input'];if(!verify_pin($uid,$pin))return['success'=>false,'message'=>'Invalid PIN'];
        $pl=db()->prepare("SELECT * FROM data_plans WHERE id=? AND is_active=1 LIMIT 1");$pl->execute([$plan_id]);$plan=$pl->fetch();if(!$plan)return['success'=>false,'message'=>'Plan not available'];
        $total=(float)$plan['selling_price']*$qty;
        if(!claim_purchase_lock($uid,'data_card',[$plan_id,$qty]))return['success'=>false,'message'=>'Duplicate request detected. Please wait a moment and try again.'];
        $debit=debit_wallet($uid,$total,'data_card',"{$qty}x {$plan['data_size']} {$plan['network']} data card");if(!$debit['success'])return $debit;
        
        $providers = get_api_providers('data_card');
        $plan_bindings = get_plan_bindings('data_card', $plan['id']);
        $providers = array_merge($plan_bindings, $providers);
        $success = false; $has_pending = false; $last_res = null; $last_prov = null;
        foreach ($providers as $prov) {
            if (empty($prov['api_key'])) continue;
            $last_prov = $prov;
            $net_map = build_network_map($prov, 'data_card');
            $nid = $net_map[$plan['network']]??1;
            $pname = strtolower($prov['provider_name'] ?? '');
            if (strpos($pname, 'autosyncng') !== false) {
                $payload = ['product_id' => $nid, 'variation_code' => (string)$plan['plan_id'], 'quantity' => $qty, 'request_ref' => $debit['ref'], 'type' => 'data', 'days' => '30'];
                if (!empty($prov['api_pin'])) $payload['pin'] = $prov['api_pin'];
            } else {
                $payload = ['network'=>$nid,'network_amount'=>1,'quantity'=>$qty,'name_on_card'=>"DataCard_{$plan['data_size']}_{$plan['network']}"];
            }
            $res=$this->api_post($prov,$payload);
            if($res&&in_array($res['_http_code']??0,[200,201])&&!is_api_response_failure($res)&&!is_api_response_pending($res)){ $success=true; break; }
            if($res&&in_array($res['_http_code']??0,[200,201])&&is_api_response_pending($res)&&!$success){ $has_pending=true; $last_res=$res; continue; }
            $last_res = $res;
        }
        
        if ($success && $last_prov) {
            $prov = $last_prov; $res = $last_res;
            if (update_tx_status($debit['ref'],'success')) {
                $raw_pins=$res['data_pin']??$res['data']['pins']??[];$pins=[];foreach($raw_pins as $rp){if(is_array($rp)){$pins[]=['pin'=>$rp['pin']??json_encode($rp),'memo'=>$rp['memo']??''];}else{$pins[]=['pin'=>$rp,'memo'=>''];}}$costTotal=(float)$plan['cost_price']*$qty;db()->prepare("UPDATE transactions SET cost_price=?, profit=?, provider=? WHERE transaction_ref=?")->execute([$costTotal,$total-$costTotal,$last_prov['provider_name']??'System',$debit['ref']]);db()->prepare("INSERT INTO data_cards (user_id,transaction_ref,network,plan_name,quantity,amount,pins,status) VALUES (?,?,?,?,?,?,?,'success')")->execute([$uid,$debit['ref'],$plan['network'],$plan['plan_name'],$qty,$total,json_encode($pins)]);notify_user($uid,'Data Cards Ready',"{$qty}x {$plan['data_size']} {$plan['network']} data card(s) purchased",'success',null,$debit['balance_before'],$debit['balance_after']);return['success'=>true,'message'=>"{$qty} data card(s) ready",'pins'=>$pins,'ref'=>$debit['ref']];
            }
            return['success'=>true,'message'=>"{$qty} data card(s) ready",'ref'=>$debit['ref']];
        }
        if ($last_prov) db()->prepare("UPDATE transactions SET provider=? WHERE transaction_ref=?")->execute([$last_prov['provider_name']??'System',$debit['ref']]);
        if($has_pending){ update_tx_status($debit['ref'],'pending',(string)($last_res['id']??'')); notify_user($uid,'Data Card Processing',"Your data card purchase is being processed.",'info',null,$debit['balance_before'],$debit['balance_after']); return['success'=>true,'message'=>'Transaction is being processed. Check receipt for updates.','ref'=>$debit['ref'],'pending'=>true]; }

        // A network-level failure on the final attempt means we don't
        // actually know whether that provider processed the request — leave
        // it 'processing' for the verification crons instead of refunding
        // blindly.
        if (!empty($last_res['_network_error'])) {
            update_tx_status($debit['ref'],'processing');
            notify_user($uid,'Data Card Processing',"We're confirming your data card purchase with the provider. This can take a few minutes.",'info',null,$debit['balance_before'],$debit['balance_after']);
            return['success'=>true,'message'=>'We could not immediately confirm this purchase due to a network issue. It is being verified — check your receipt shortly.','ref'=>$debit['ref'],'pending'=>true];
        }

        $err='Purchase failed. Wallet reversed.';
        if(!empty($last_res['message'])) $err=$last_res['message'];
        elseif(!empty($last_res['error'])) $err=is_array($last_res['error'])?json_encode($last_res['error']):$last_res['error'];
        refund_failed_transaction($debit['ref'], $uid, $total, $err);
        return['success'=>true,'message'=>$err,'ref'=>$debit['ref'],'refunded'=>true];
    }
}
function process_data_card(int $uid,array $input):array{return(new DataCardService())->process($uid,$input);}
