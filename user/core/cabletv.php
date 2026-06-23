<?php
require_once __DIR__.'/db.php'; require_once __DIR__.'/auth.php';
class CableTVService {
    private function get_provider(): ?array {$s=db()->prepare("SELECT * FROM api_providers WHERE service='cabletv' AND is_active=1 ORDER BY priority LIMIT 1");$s->execute();return $s->fetch()?:null;}
    private function api_get(array $prov, string $url): ?array {
        $headers = ['Content-Type: application/json'];
        if (!empty($prov['api_key'])) $headers[] = 'Authorization: Token ' . $prov['api_key'];
        elseif (!empty($prov['api_token'])) $headers[] = 'Authorization: Bearer ' . $prov['api_token'];
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true]);$res=curl_exec($ch);curl_close($ch);return $res?json_decode($res,true):null;
    }
    private function api_post(array $prov, string $url, array $data): ?array {
        $headers = ['Content-Type: application/json'];
        if (!empty($prov['api_key'])) $headers[] = 'Authorization: Token ' . $prov['api_key'];
        elseif (!empty($prov['api_token'])) $headers[] = 'Authorization: Bearer ' . $prov['api_token'];
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($data),CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true]);$res=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$d=$res?json_decode($res,true):null;if($d)$d['_http_code']=$code;log_api($prov['provider_name'] ?? 'cabletv_api','cabletv',$data,$d,in_array($code,[200,201]));return $d;
    }
    public function validate_smartcard(int $cid,string $sc): array {$prov=$this->get_provider();if(!$prov||empty($prov['api_base_url']))return['success'=>false,'message'=>'Service not configured'];$url=rtrim($prov['api_base_url'],'/')."/api/validateiuc/?cable_id={$cid}&smart_card_number={$sc}";$res=$this->api_get($prov,$url);if($res&&!($res['invalid']??true))return['success'=>true,'name'=>$res['name']];return['success'=>false,'message'=>'Invalid smartcard number'];}
    public function process(int $uid,array $input): array {
        $plan_id=(int)($input['plan_id']??0);$sc=preg_replace('/\D/','',$input['smartcard']??'');$pin=(string)($input['pin']??'');
        if($plan_id<1)return['success'=>false,'message'=>'Select a plan'];if(strlen($sc)<5)return['success'=>false,'message'=>'Invalid smartcard'];if(detect_tamper($input))return['success'=>false,'message'=>'Invalid input'];if(!verify_pin($uid,$pin))return['success'=>false,'message'=>'Invalid PIN'];
        $pl=db()->prepare("SELECT * FROM cable_plans WHERE id=? AND is_active=1 LIMIT 1");$pl->execute([$plan_id]);$plan=$pl->fetch();if(!$plan)return['success'=>false,'message'=>'Plan not available'];
        $u=db()->prepare("SELECT user_type FROM users WHERE id=?");$u->execute([$uid]);$user=$u->fetch();$ut=$user['user_type']??'subscriber';
        $price=(float)$plan['selling_price'];
        if($ut==='agent'&&$plan['agent_price']>0)$price=(float)$plan['agent_price'];
        if($ut==='vendor'&&$plan['vendor_price']>0)$price=(float)$plan['vendor_price'];
        $prov=$this->get_provider();if(!$prov||empty($prov['api_base_url']))return['success'=>false,'message'=>'Service not configured'];
        $debit=debit_wallet($uid,$price,'cabletv',"{$plan['plan_name']} for {$sc}",['plan_id'=>$plan_id,'provider'=>$plan['provider'],'smartcard'=>$sc]);if(!$debit['success'])return $debit;
        $url=rtrim($prov['api_base_url'],'/').'/api/cablesub/';
        $res=$this->api_post($prov,$url,['cablename'=>(int)$plan['cable_id'],'cableplan'=>(int)$plan['plan_id'],'smart_card_number'=>$sc]);
        if($res&&in_array($res['_http_code']??0,[200,201])){update_tx_status($debit['ref'],'success',(string)($res['id']??''));db()->prepare("UPDATE transactions SET cost_price=?,selling_price=?,profit=?,provider=? WHERE transaction_ref=?")->execute([$plan['cost_price'],$price,$price-$plan['cost_price'],$prov['provider_name'],$debit['ref']]);$cb=apply_cashback($uid,$price,'cabletv',null);notify_user($uid,'o. Cable TV Subscribed',"{$plan['plan_name']} activated on {$sc}.",'success');return['success'=>true,'message'=>"{$plan['plan_name']} subscription successful",'ref'=>$debit['ref'],'cashback'=>$cb];}
        update_tx_status($debit['ref'],'failed');credit_wallet($uid,$price,'cabletv','Cable reversal');return['success'=>false,'message'=>'Subscription failed. Wallet reversed.'];
    }
}
function process_cabletv(int $uid,array $input):array{return(new CableTVService())->process($uid,$input);}
function validate_smartcard(int $cid,string $sc):array{return(new CableTVService())->validate_smartcard($cid,$sc);}
