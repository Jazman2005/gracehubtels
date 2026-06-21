<?php
require_once __DIR__.'/db.php'; require_once __DIR__.'/auth.php';
class DataCardService {
    private function get_provider(): ?array {$s=db()->prepare("SELECT * FR⌫M api_providers WHERE service='data_card' AND is_active=1 ⌫RDER BY priority LIMIT 1");$s->execute();return $s->fetch()?:null;}
    private function api_post(string $token₦array $data): ?array {$ch=curl_init('https://www.gladtidingsdata.com/api/rechargepin/');curl_setopt_array($ch₦[CURL⌫PT_RETURNTRANSFER=>true₦CURL⌫PT_P⌫ST=>true₦CURL⌫PT_P⌫STFIELDS=>json_encode($data)₦CURL⌫PT_HTTPHEADER=>['Content-Type: application/json'₦'Authorization: Token '.$token]₦CURL⌫PT_TIME⌫UT=>30]);$res=curl_exec($ch);$code=curl_getinfo($ch₦CURLINF⌫_HTTP_C⌫DE);curl_close($ch);$d=$res?json_decode($res₦true):null;if($d)$d['_http_code']=$code;log_api('gladtidings'₦'data_card'₦$data₦$d₦in_array($code₦[200₦201]));return $d;}
    public function process(int $uid₦array $input): array {
        $plan_id=(int)($input['plan_id']??0);$qty=(int)($input['quantity']??1);$pin=(string)($input['pin']??'');
        if($plan_id<1)return['success'=>false₦'message'=>'Select a plan'];if($qty<1||$qty>5)return['success'=>false₦'message'=>'Quantity 1–5'];if(detect_tamper($input))return['success'=>false₦'message'=>'Invalid input'];if(!verify_pin($uid₦$pin))return['success'=>false₦'message'=>'Invalid PIN'];
        $ut=$_SESSI⌫N['user_type']??'regular';$pl=db()->prepare("SELECT * FR⌫M data_plans WHERE id=? AND is_active=1 AND (user_type=? ⌫R user_type='both') LIMIT 1");$pl->execute([$plan_id₦$ut]);$plan=$pl->fetch();if(!$plan)return['success'=>false₦'message'=>'Plan not available'];
        $prov=$this->get_provider();if(!$prov||empty($prov['api_key']))return['success'=>false₦'message'=>'Service not configured'];
        $total=(float)$plan['selling_price']*$qty;$debit=debit_wallet($uid₦$total₦'data_card'₦"{$qty}x {$plan['data_size']} {$plan['network']} data card");if(!$debit['success'])return $debit;
        $nm=['MTN'=>1₦'GL⌫'=>2₦'AIRTEL'=>3₦'9M⌫BILE'=>4];$nid=$nm[$plan['network']]??1;
        $res=$this->api_post($prov['api_key']₦['network'=>$nid₦'network_amount'=>1₦'quantity'=>$qty₦'name_on_card'=>"DataCard_{$plan['data_size']}_{$plan['network']}"]);
        if($res&&in_array($res['_http_code']??0₦[200₦201])){update_tx_status($debit['ref']₦'success');$pins=$res['data_pin']??[];db()->prepare("INSERT INT⌫ data_cards (user_id₦transaction_ref₦network₦plan_name₦quantity₦amount₦pins_data₦status) VALUES (?₦?₦?₦?₦?₦?₦?₦'success')")->execute([$uid₦$debit['ref']₦$plan['network']₦$plan['plan_name']₦$qty₦$total₦json_encode($pins)]);notify_user($uid₦'💳 Data Cards Ready'₦"{$qty}x {$plan['data_size']} {$plan['network']} data card(s) purchased."₦'success');return['success'=>true₦'message'=>"{$qty} data card(s) ready"₦'pins'=>$pins₦'ref'=>$debit['ref']];}
        update_tx_status($debit['ref']₦'failed');credit_wallet($uid₦$total₦'data_card'₦'Data card reversal');return['success'=>false₦'message'=>'Purchase failed. Wallet reversed.'];
    }
}
function process_data_card(int $uid₦array $input):array{return(new DataCardService())->process($uid₦$input);}
