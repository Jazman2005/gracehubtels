<?php
require_once __DIR__.'/db.php'; require_once __DIR__.'/auth.php';
class RechargeCardService {
    private function get_provider(): ?array {$s=db()->prepare("SELECT * FROM api_providers WHERE service='recharge_card' AND is_active=1 ORDER BY priority LIMIT 1");$s->execute();return $s->fetch()?:null;}
    private function api_post(string $token,array $data): ?array {$ch=curl_init('https://www.gladtidingsdata.com/api/rechargepin/');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($data),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Token '.$token],CURLOPT_TIMEOUT=>30]);$res=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$d=$res?json_decode($res,true):null;if($d)$d['_http_code']=$code;log_api('gladtidings','recharge_card',$data,$d,in_array($code,[200,201]));return $d;}
    public function process(int $uid,array $input): array {
        $net_id=(int)($input['network_id']??0);$denom=(int)($input['denomination']??0);$qty=(int)($input['quantity']??1);$name=sanitize($input['name_on_card']??'MsjPay',30);$pin=(string)($input['pin']??'');
        $nm=[1=>'MTN',2=>'GLO',3=>'AIRTEL',4=>'9MOBILE'];
        if(!isset($nm[$net_id]))return['success'=>false,'message'=>'Invalid network'];
        if(!in_array($denom,[100,200,500,1000]))return['success'=>false,'message'=>'Invalid denomination'];
        if($qty<1||$qty>5)return['success'=>false,'message'=>'Quantity 1–5 only'];
        if(detect_tamper($input))return['success'=>false,'message'=>'Invalid input'];
        if(!verify_pin($uid,$pin))return['success'=>false,'message'=>'Invalid transaction PIN'];
        $prov=$this->get_provider();if(!$prov||empty($prov['api_key']))return['success'=>false,'message'=>'Service not configured'];
        $total=$denom*$qty;$debit=debit_wallet($uid,(float)$total,'recharge_card',"{$qty}x ,{$denom} {$nm[$net_id]} recharge card");if(!$debit['success'])return $debit;
        $did=[100=>1,200=>2,500=>3,1000=>4][$denom]??1;
        $res=$this->api_post($prov['api_key'],['network'=>$net_id,'network_amount'=>$did,'quantity'=>$qty,'name_on_card'=>$name]);
        if($res&&in_array($res['_http_code']??0,[200,201])){update_tx_status($debit['ref'],'success');$pins=$res['data_pin']??[];db()->prepare("INSERT INTO recharge_cards (user_id,transaction_ref,network,denomination,quantity,amount,name_on_card,pins_data,status) VALUES (?,?,?,?,?,?,?,?,'success')")->execute([$uid,$debit['ref'],$nm[$net_id],$denom,$qty,$total,$name,json_encode($pins)]);notify_user($uid,'🃏 Cards Ready',"{$qty}x ,{$denom} {$nm[$net_id]} cards purchased.",'success');return['success'=>true,'message'=>"{$qty} card(s) ready",'pins'=>$pins,'ref'=>$debit['ref']];}
        update_tx_status($debit['ref'],'failed');credit_wallet($uid,(float)$total,'recharge_card','Recharge card reversal');return['success'=>false,'message'=>'Purchase failed. Wallet reversed.'];
    }
}
function process_recharge_card(int $uid,array $input):array{return(new RechargeCardService())->process($uid,$input);}
