<?php
require_once __DIR__.'/db.php'; require_once __DIR__.'/auth.php'; require_once __DIR__.'/api_router.php';
class ElectricityService {
    private function api_get(array $prov, string $url): ?array {
        $headers = ['Content-Type: application/json'];
        if (!empty($prov['api_key'])) {
            $headers[] = (strpos(strtolower($prov['provider_name'] ?? ''), 'autopilot') !== false) ? 'Authorization: Bearer ' . $prov['api_key'] : 'Authorization: Token ' . $prov['api_key'];
        } elseif (!empty($prov['api_token'])) {
            $headers[] = 'Authorization: Bearer ' . $prov['api_token'];
        }
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);$res=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$d=$res?json_decode($res,true):null;if($d===null&&!empty($res)){$d=['_http_code'=>$code,'message'=>"HTTP ".$code.": ".substr(strip_tags($res),0,200)];}if($d)$d['_http_code']=$code;log_api($prov['provider_name']??'electricity_api','validate_meter',['url'=>$url],$d,$code==200);return $d;
    }
    private function api_post(array $prov, string $url, array $data): ?array {
        $headers = ['Content-Type: application/json'];
        if (!empty($prov['api_key'])) {
            $headers[] = (strpos(strtolower($prov['provider_name'] ?? ''), 'autopilot') !== false) ? 'Authorization: Bearer ' . $prov['api_key'] : 'Authorization: Token ' . $prov['api_key'];
        } elseif (!empty($prov['api_token'])) {
            $headers[] = 'Authorization: Bearer ' . $prov['api_token'];
        }
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($data),CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);$res=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);$d=$res?json_decode($res,true):null;if($res===false){$d=['_http_code'=>500,'message'=>"cURL Error: ".$err];}elseif($d===null&&!empty($res)){$d=['_http_code'=>$code,'message'=>"HTTP ".$code.": ".substr(strip_tags($res),0,200)];}if($d)$d['_http_code']=$code;log_api($prov['provider_name'] ?? 'electricity_api','electricity',$data,$d,in_array($code,[200,201]));return $d;
    }
    public function validate_meter(int $disco_id,string $meter,string $mtype): array {
        $all = get_api_providers('electricity'); $prov = $all[0] ?? null;
        if(!$prov||empty($prov['api_base_url']))return['success'=>false,'message'=>'Service not configured'];
        $disco=db()->prepare("SELECT * FROM electricity_discos WHERE disco_id=? LIMIT 1");$disco->execute([$disco_id]);$d=$disco->fetch();
        $pname = strtolower($prov['provider_name'] ?? '');
        if (stripos($pname, 'autosyncng') !== false || stripos($pname, 'autopilot') !== false) {
            $pid=$d['product_code']??$disco_id;
            $base=rtrim(str_ireplace('/api/live', '', $prov['api_base_url']), '/');
            $url=$base.(stripos($pname, 'autopilot') !== false ? '/api/live/v1/validate/electricity' : '/api/v1/validate/electricity');
            $res=$this->api_post($prov,$url,['meter_number'=>$meter,'product_id'=>$pid,'type'=>$mtype]);
            if($res&&in_array($res['_http_code']??0,[200,201])&&!empty($res['data']['is_valid']))return['success'=>true,'name'=>$res['data']['customer_name']??'Valid Meter','address'=>$res['data']['customer_address']??''];
            return['success'=>false,'message'=>$res['data']['customer_name']??$res['message']??$res['error']??'Invalid meter number'];
        }
        if (stripos($pname, 'msjdatasubs') !== false) {
            $pid=$d['product_code']??$disco_id;
            $url=rtrim($prov['api_base_url'],'/').'/api/electricity/verify/';
            $res=$this->api_post($prov,$url,['provider'=>$pid,'meternumber'=>$meter,'metertype'=>$mtype]);
            if($res&&in_array($res['_http_code']??0,[200,201]))return['success'=>true,'name'=>$res['name']??$res['customer_name']??$res['Customer_Name']??$res['msg']??'Valid Meter','address'=>$res['address']??$res['customer_address']??$res['Customer_Address']??''];
            return['success'=>false,'message'=>$res['message']??$res['error']??'Invalid meter number'];
        }
        if (stripos($pname, 'telecomabode') !== false) {
            $url=rtrim($prov['api_base_url'],'/')."/api/electricity/validate";
            $res=$this->api_post($prov,$url,['disco_id'=>$disco_id,'meter_type'=>$mtype,'meter_number'=>$meter]);
            if($res&&in_array($res['_http_code']??0,[200,201]))return['success'=>true,'name'=>$res['name']??$res['customer_name']??$res['message']??'Valid Meter','address'=>$res['address']??$res['customer_address']??''];
            return['success'=>false,'message'=>$res['message']??$res['error']??'Invalid meter number'];
        }
        $url=rtrim($prov['api_base_url'],'/')."/api/validatemeter/?disco_id={$disco_id}&meter_type={$mtype}&meter_number={$meter}";
        $res=$this->api_get($prov,$url);
        if($res&&!($res['invalid']??true)&&empty($res['message'])&&empty($res['error']))return['success'=>true,'name'=>$res['name']??'Valid Meter','address'=>$res['address']??''];
        return['success'=>false,'message'=>$res['message']??$res['error']??'Invalid meter number'];
    }
    public function process(int $uid,array $input): array {
        $did=(int)($input['disco_id']??0);$meter=sanitize_phone($input['meter_number']??'');$mtype=in_array($input['meter_type']??'',['prepaid','postpaid'])?$input['meter_type']:'';$amount=(float)($input['amount']??0);$phone=sanitize_phone($input['phone']??'');$pin=(string)($input['pin']??'');
        if(!$did)return['success'=>false,'message'=>'Select a disco'];if(strlen($meter)<5)return['success'=>false,'message'=>'Invalid meter number'];if(!$mtype)return['success'=>false,'message'=>'Select meter type'];
        $min = (float)setting('limit_electricity_min', '1000'); $max = (float)setting('limit_electricity_max', '50000');
        if($amount<$min||$amount>$max)return['success'=>false,'message'=>"Amount must be between ₦".number_format($min)." and ₦".number_format($max)];
        if(detect_tamper($input))return['success'=>false,'message'=>'Invalid input'];if(!verify_pin($uid,$pin))return['success'=>false,'message'=>'Invalid transaction PIN'];
        $disco=db()->prepare("SELECT * FROM electricity_discos WHERE disco_id=? AND is_active=1 LIMIT 1");$disco->execute([$did]);$d=$disco->fetch();if(!$d)return['success'=>false,'message'=>'Invalid disco'];
        $user=get_user($uid);$ut=$user['user_type']??'subscriber';
        $charge=(float)$d['charge'];
        if($ut==='agent'&&$d['agent_charge']!==null)$charge=(float)$d['agent_charge'];
        if($ut==='vendor'&&$d['vendor_charge']!==null)$charge=(float)$d['vendor_charge'];
        $total=$amount+$charge;$debit=debit_wallet($uid,$total,'electricity',"₦{$amount} {$d['disco_name']} for {$meter}",['disco'=>$d['disco_name'],'meter'=>$meter,'type'=>$mtype,'charge'=>$charge]);if(!$debit['success'])return $debit;
        
        $providers = get_api_providers('electricity');
        $success = false; $has_pending = false; $last_res = null; $last_prov = null;
        foreach ($providers as $prov) {
            if (empty($prov['api_base_url'])) continue;
            $last_prov = $prov;
            $pname = strtolower($prov['provider_name'] ?? '');
            if (stripos($pname, 'autosyncng') !== false || stripos($pname, 'autopilot') !== false) {
                $pid=$d['product_code']??$did;
                $base=rtrim(str_ireplace('/api/live', '', $prov['api_base_url']), '/');
                $url=$base.(stripos($pname, 'autopilot') !== false ? '/api/live/v1/electricity' : '/api/v1/electricity');
                $api_payload = ['meter_number'=>$meter,'product_id'=>$pid,'type'=>$mtype,'amount'=>$amount,'request_ref'=>$debit['ref']];
                if (!empty($prov['api_pin'])) $api_payload['pin'] = $prov['api_pin'];
            } elseif (stripos($pname, 'msjdatasubs') !== false) {
                $pid=$d['product_code']??$did;
                $url=rtrim($prov['api_base_url'],'/').'/api/electricity/';
                $api_payload = ['provider'=>$pid,'meternumber'=>$meter,'metertype'=>$mtype,'amount'=>$amount,'phone'=>$phone?:$user['phone'],'ref'=>$debit['ref']];
                if (!empty($prov['api_pin'])) $api_payload['pin'] = $prov['api_pin'];
            } else {
                $url=rtrim($prov['api_base_url'],'/').'/api/billpayment/';
                $disco_id_override = (int)provider_network_id($prov, 'disco_'.$did, $did);
                $api_payload = ['disco_id'=>$disco_id_override,'amount'=>$amount,'meter_number'=>$meter,'meter_type'=>$mtype,'Customer_Phone'=>$phone?:$user['phone'],'customer_name'=>$user['full_name'],'customer_address'=>''];
            }
            $res=$this->api_post($prov,$url,$api_payload);
            $last_res = $res;
            if($res&&in_array($res['_http_code']??0,[200,201])&&!is_api_response_failure($res)&&!is_api_response_pending($res)){ $success=true; break; }
            if($res&&in_array($res['_http_code']??0,[200,201])&&is_api_response_pending($res)&&!$success){ $has_pending=true; continue; }
        }
        
        if ($success && $last_prov) {
            $prov = $last_prov; $res = $last_res;
            update_tx_status($debit['ref'],'success',(string)($res['id']??''));
            $tok = $res['data']['transaction']['token'] ?? $res['data']['transaction']['pin'] ?? $res['data']['transaction']['main_token'] ?? $res['data']['transaction']['units'] ?? $res['data']['transaction']['meter_token'] ?? $res['data']['token'] ?? $res['data']['pin'] ?? $res['data']['main_token'] ?? $res['data']['units'] ?? $res['token'] ?? $res['pin'] ?? $res['meter_token'] ?? $res['vtoken'] ?? $res['tokens'] ?? $res['data_pin'] ?? $res['tokenCode'] ?? $res['token_code'] ?? $res['main_token'] ?? $res['units'] ?? $res['unit'] ?? $res['card'] ?? (is_string($res['message'] ?? null) && preg_match('/[0-9]{4,}(?:-[0-9]{4,})+/', $res['message'], $m) ? $m[0] : '') ?? (is_string($res['data']['transaction']['details'] ?? null) && preg_match('/[0-9]{4,}(?:-[0-9]{4,})+/', $res['data']['transaction']['details'], $m) ? $m[0] : '') ?: 'Check meter';
            $resp_msg = $res['message'] ?? $res['msg'] ?? $d['message'] ?? $d['msg'] ?? $d['transaction']['details'] ?? '';
            if ($resp_msg || $tok) db()->prepare("UPDATE transactions SET meta = JSON_SET(COALESCE(meta, '{}'), '$.response', ?, '$.token', ?) WHERE transaction_ref=?")->execute([$resp_msg, $tok, $debit['ref']]);notify_user($uid,' ⚡ Electricity Token',"Token: {$tok} for meter {$meter}",'success',null,$debit['balance_before'],$debit['balance_after']);$cb=apply_cashback($uid,$amount,'electricity',null);return['success'=>true,'message'=>"Token: {$tok}",'token'=>$tok,'ref'=>$debit['ref'],'cashback'=>$cb];
        }
        
        if ($last_prov) db()->prepare("UPDATE transactions SET provider=? WHERE transaction_ref=?")->execute([$last_prov['provider_name'],$debit['ref']]);
        if ($has_pending) {
            $prov_ref = $last_res['id'] ?? $last_res['data']['transaction']['reference'] ?? $last_res['data']['id'] ?? $last_res['reference'] ?? '';update_tx_status($debit['ref'],'pending',$prov_ref);notify_user($uid,'Electricity Processing',"Your electricity purchase for meter {$meter} is being processed. Check back later.",'info',null,$debit['balance_before'],$debit['balance_after']);return['success'=>true,'message'=>'Transaction is being processed. Check receipt for updates.','ref'=>$debit['ref'],'pending'=>true];
        }
        
        $err='Purchase failed. Wallet reversed.';
        if(!empty($last_res['message'])) $err=$last_res['message'];
        elseif(!empty($last_res['error'])) $err=is_array($last_res['error'])?json_encode($last_res['error']):$last_res['error'];
        refund_failed_transaction($debit['ref'], $uid, $amount, $err);
        return['success'=>true,'message'=>$err,'ref'=>$debit['ref'],'refunded'=>true];
    }
}
function process_electricity(int $uid,array $input):array{return(new ElectricityService())->process($uid,$input);}
function validate_meter(int $did,string $meter,string $type):array{return(new ElectricityService())->validate_meter($did,$meter,$type);}
