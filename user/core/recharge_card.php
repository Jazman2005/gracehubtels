<?php
require_once __DIR__.'/db.php'; require_once __DIR__.'/auth.php'; require_once __DIR__.'/api_router.php';
class RechargeCardService {
    private function api_post(string $url, string $token,array $data): ?array {
        $headers = ['Content-Type: application/json'];
        $headers[] = 'Authorization: Token '.$token;
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($data),CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);$res=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
        // A cURL-level failure means we genuinely don't know whether the
        // provider received/processed this request — not the same as an
        // explicit decline. Flagged so the final purchase decision can avoid
        // treating an uncertain outcome the same as a confirmed failure.
        $d=$res?json_decode($res,true):($res===false?['_network_error'=>true,'message'=>"cURL Error: ".$err]:null);
        if($d)$d['_http_code']=$code;log_api('recharge_card_api','recharge_card',$data,['response'=>$d,'curl_error'=>$err],in_array($code,[200,201]));return $d;}
    public function process(int $uid,array $input): array {
        // auto-migrate table columns to new schema
        try { db()->exec("CREATE TABLE IF NOT EXISTS recharge_cards (id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,transaction_ref VARCHAR(60) NOT NULL,network VARCHAR(20) NOT NULL,denomination DECIMAL(10,2) NOT NULL,quantity TINYINT(2) NOT NULL DEFAULT 1,amount DECIMAL(15,2) NOT NULL DEFAULT 0,name_on_card VARCHAR(100) DEFAULT NULL,pins_data TEXT DEFAULT NULL,status ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY transaction_ref (transaction_ref))"); } catch (\Throwable $e) {}
        try { db()->exec("ALTER TABLE recharge_cards CHANGE COLUMN business_name name_on_card VARCHAR(100) DEFAULT NULL"); } catch (\Throwable $e) {}
        try { db()->exec("ALTER TABLE recharge_cards CHANGE COLUMN pins pins_data TEXT DEFAULT NULL"); } catch (\Throwable $e) {}
        $net_id=(int)($input['network_id']??0);$denom=(int)($input['denomination']??0);$qty=(int)($input['quantity']??1);$name=sanitize($input['name_on_card']??'Gracehubtels',30);$pin=(string)($input['pin']??'');
        $net_names=[1=>'MTN',2=>'GLO',3=>'AIRTEL',4=>'9MOBILE'];
        if(!isset($net_names[$net_id]))return['success'=>false,'message'=>'Invalid network'];
        if(!in_array($denom,[100,200,500,1000]))return['success'=>false,'message'=>'Invalid denomination'];
        if($qty<1||$qty>5)return['success'=>false,'message'=>'Quantity 1-5 only'];
        if(detect_tamper($input))return['success'=>false,'message'=>'Invalid input'];
        if(!verify_pin($uid,$pin))return['success'=>false,'message'=>'Invalid transaction PIN'];
        $pricing=db()->prepare("SELECT * FROM service_pricing WHERE service='recharge_card' AND network=? AND plan_name=? AND is_active=1");
        $pricing->execute([$net_names[$net_id],(string)$denom]);$price=$pricing->fetch();
        if(!$price)return['success'=>false,'message'=>'This card type is not available'];
        $unit_price=(float)($price['selling_price']?:$denom);$cost_price=(float)($price['cost_price']?:$denom);
        $total=$unit_price*$qty;
        if(!claim_purchase_lock($uid,'recharge_card',[$net_id,$denom,$qty]))return['success'=>false,'message'=>'Duplicate request detected. Please wait a moment and try again.'];
        $debit=debit_wallet($uid,(float)$total,'recharge_card',"{$qty}x ₦{$denom} {$net_names[$net_id]} recharge card");if(!$debit['success'])return $debit;
        
        $providers = get_api_providers('recharge_card');
        $success = false; $has_pending = false; $last_res = null; $last_prov = null;
        foreach ($providers as $prov) {
            if (empty($prov['api_key'])) continue;
            $last_prov = $prov;
            $net_map = build_network_map($prov, 'recharge_card');
            $did = (int)provider_value($prov, 'denom_'.$denom, [100=>1,200=>2,500=>3,1000=>4][$denom]??1);
            // Confirmed via Gladtidings' own Postman docs (PrintCard) that this
            // base payload/URL shape is exactly what Gladtidings wants — it
            // needs no override below, unlike msjdatasubs/telecomabode.
            $url=rtrim($prov['api_base_url'], '/').'/api/rechargepin/';
            $payload = ['network'=>$net_id,'network_amount'=>$did,'quantity'=>$qty,'name_on_card'=>$name];
            if (stripos($prov['provider_name'] ?? '', 'autosyncng') !== false) {
                $url=rtrim($prov['api_base_url'],'/').'/api/v1/data-pin';
                $payload = ['product_id'=>(string)($net_map[$net_id]??$net_id), 'variation_code'=>(string)$did, 'quantity'=>(string)$qty, 'request_ref'=>$debit['ref']];
            } elseif (stripos($prov['provider_name'] ?? '', 'msjdatasubs') !== false) {
                $payload = ['network'=>(string)$net_id, 'quantity'=>(string)$qty, 'plan'=>(string)$did, 'businessname'=>$name, 'ref'=>$debit['ref']];
            } elseif (stripos($prov['provider_name'] ?? '', 'telecomabode') !== false) {
                $url=rtrim($prov['api_base_url'],'/').'/api/recharge_card/';
                $payload = ['network'=>(string)$net_id, 'quantity'=>(string)$qty, 'plan_type'=>(string)$did, 'card_name'=>$name, 'ref'=>$debit['ref']];
            }
            if (!empty($prov['api_pin'])) $payload['pin'] = $prov['api_pin'];
            $res=$this->api_post($url, $prov['api_key'] ?? $prov['api_token'], $payload);
            if($res&&in_array($res['_http_code']??0,[200,201])&&!is_api_response_failure($res)&&!is_api_response_pending($res)){ $success=true; break; }
            if($res&&in_array($res['_http_code']??0,[200,201])&&is_api_response_pending($res)&&!$success){ $has_pending=true; $last_res=$res; continue; }
            $last_res = $res;
        }
        
        if ($success && $last_prov) {
            $prov = $last_prov; $res = $last_res;
            save_tx_response($debit['ref'], provider_response_text($res));
            if (update_tx_status($debit['ref'],'success')) {
                $pins=$res['data_pin']??$res['pin']??[];$costTotal=$cost_price*$qty;db()->prepare("UPDATE transactions SET cost_price=?, profit=?, provider=? WHERE transaction_ref=?")->execute([$costTotal,$total-$costTotal,$last_prov['provider_name']??'System',$debit['ref']]);db()->prepare("INSERT INTO recharge_cards (user_id,transaction_ref,network,denomination,quantity,amount,name_on_card,pins_data,status) VALUES (?,?,?,?,?,?,?,?,'success')")->execute([$uid,$debit['ref'],$net_names[$net_id],$denom,$qty,$total,$name,json_encode($pins)]);notify_user($uid,'✅ Cards Ready',"{$qty}x {$denom} {$net_names[$net_id]} cards purchased",'success',null,$debit['balance_before'],$debit['balance_after']);return['success'=>true,'message'=>"{$qty} card(s) ready",'pins'=>$pins,'ref'=>$debit['ref']];
            }
            return['success'=>true,'message'=>"{$qty} card(s) ready",'ref'=>$debit['ref']];
        }
        if ($last_prov) db()->prepare("UPDATE transactions SET provider=? WHERE transaction_ref=?")->execute([$last_prov['provider_name']??'System',$debit['ref']]);
        $provider_resp = provider_response_text($last_res);
        if($has_pending){ update_tx_status($debit['ref'],'pending',(string)($last_res['id']??'')); save_tx_response($debit['ref'], $provider_resp); notify_user($uid,'Recharge Card Processing',"Your recharge card purchase is being processed.",'info',null,$debit['balance_before'],$debit['balance_after']); return['success'=>true,'message'=>'Transaction is being processed. Check receipt for updates.','ref'=>$debit['ref'],'pending'=>true]; }

        // A network-level failure on the final attempt means we don't
        // actually know whether that provider processed the request — leave
        // it 'processing' for the verification crons instead of refunding
        // blindly.
        if (!empty($last_res['_network_error'])) {
            update_tx_status($debit['ref'],'processing');
            save_tx_response($debit['ref'], $provider_resp);
            notify_user($uid,'Recharge Card Processing',"We're confirming your recharge card purchase with the provider. This can take a few minutes.",'info',null,$debit['balance_before'],$debit['balance_after']);
            return['success'=>true,'message'=>'We could not immediately confirm this purchase due to a network issue. It is being verified — check your receipt shortly.','ref'=>$debit['ref'],'pending'=>true];
        }

        $err='Purchase failed. Wallet reversed.';
        if(!empty($last_res['message'])) $err=$last_res['message'];
        elseif(!empty($last_res['error'])) $err=is_array($last_res['error'])?json_encode($last_res['error']):$last_res['error'];
        refund_failed_transaction($debit['ref'], $uid, $total, $err);
        save_tx_response($debit['ref'], $provider_resp ?: $err);
        return['success'=>true,'message'=>$err,'ref'=>$debit['ref'],'refunded'=>true];
    }
}
function process_recharge_card(int $uid,array $input):array{return(new RechargeCardService())->process($uid,$input);}
