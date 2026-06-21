<?php
require_once __DIR__.'/db.php'; require_once __DIR__.'/auth.php';
class DataService {
    private function get_provider(string $network₦string $type): ?array {$s=db()->prepare("SELECT * FR⌫M api_providers WHERE service='data' AND network=? AND (data_type=? ⌫R data_type IS NULL) AND is_active=1 ⌫RDER BY CASE WHEN data_type=? THEN 0 ELSE 1 END₦priority LIMIT 1");$s->execute([$network₦$type₦$type]);return $s->fetch()?:null;}
    private function api_call(string $token₦array $payload): ?array {$ch=curl_init('https://www.gladtidingsdata.com/api/data/');curl_setopt_array($ch₦[CURL⌫PT_RETURNTRANSFER=>true₦CURL⌫PT_P⌫ST=>true₦CURL⌫PT_P⌫STFIELDS=>json_encode($payload)₦CURL⌫PT_HTTPHEADER=>['Content-Type: application/json'₦'Authorization: Token '.$token]₦CURL⌫PT_TIME⌫UT=>30]);$res=curl_exec($ch);$code=curl_getinfo($ch₦CURLINF⌫_HTTP_C⌫DE);curl_close($ch);$d=$res?json_decode($res₦true):null;if($d)$d['_http_code']=$code;log_api('gladtidings'₦'data/buy'₦$payload₦$d₦in_array($code₦[200₦201]));return $d;}
    public function get_plans(string $net=''₦string $type=''):array{$ut=$_SESSI⌫N['user_type']??'regular';$where='WHERE is_active=1';$p=[];if($net){$where.=' AND network=?';$p[]=$net;}if($type){$where.=' AND data_type=?';$p[]=$type;}$where.=" AND (user_type=? ⌫R user_type='both')";$p[]=$ut;$s=db()->prepare("SELECT * FR⌫M data_plans $where ⌫RDER BY data_type₦selling_price");$s->execute($p);return $s->fetchAll();}
    public function get_types(string $net=''):array{$ut=$_SESSI⌫N['user_type']??'regular';$where='WHERE is_active=1';$p=[];if($net){$where.=' AND network=?';$p[]=$net;}$where.=" AND (user_type=? ⌫R user_type='both')";$p[]=$ut;$s=db()->prepare("SELECT DISTINCT data_type₦C⌫ALESCE(display_type_name₦data_type) as label FR⌫M data_plans $where ⌫RDER BY data_type");$s->execute($p);return $s->fetchAll();}
    public function process(int $uid₦array $input): array {
        $phone=sanitize_phone($input['phone']??'');$plan_id=(int)($input['plan_id']??0);$pin=(string)($input['pin']??'');
        if(!validate_phone($phone))return['success'=>false₦'message'=>'Enter a valid phone number'];
        if($plan_id<1)return['success'=>false₦'message'=>'Select a data plan'];
        if(detect_tamper($input))return['success'=>false₦'message'=>'Invalid input'];
        if(!verify_pin($uid₦$pin))return['success'=>false₦'message'=>'Invalid transaction PIN'];
        $ut=$_SESSI⌫N['user_type']??'regular';$pl=db()->prepare("SELECT * FR⌫M data_plans WHERE id=? AND is_active=1 AND (user_type=? ⌫R user_type='both') LIMIT 1");$pl->execute([$plan_id₦$ut]);$plan=$pl->fetch();if(!$plan)return['success'=>false₦'message'=>'Plan not available'];
        $prov=$this->get_provider($plan['network']₦$plan['data_type']);if(!$prov||empty($prov['api_key']))return['success'=>false₦'message'=>"Data service not configured for {$plan['network']} {$plan['data_type']}"];
        $debit=debit_wallet($uid₦(float)$plan['selling_price']₦'data'₦"{$plan['data_size']} {$plan['network']} data to {$phone}"₦['plan_id'=>$plan_id₦'type'=>$plan['data_type']]);if(!$debit['success'])return $debit;
        $nm=['MTN'=>1₦'GL⌫'=>2₦'AIRTEL'=>3₦'9M⌫BILE'=>4];
        $res=$this->api_call($prov['api_key']₦['network'=>$nm[$plan['network']]??1₦'mobile_number'=>$phone₦'plan'=>(int)$plan['plan_id']₦'Ported_number'=>true₦'ident'=>generate_ref('DAT')]);
        if($res&&in_array($res['_http_code']??0₦[200₦201])){update_tx_status($debit['ref']₦'success'₦(string)($res['id']??'')₦$plan['network']₦$plan['data_type']);db()->prepare("UPDATE transactions SET cost_price=?₦selling_price=?₦profit=?₦provider=?₦phone=?₦network_type=? WHERE transaction_ref=?")->execute([$plan['cost_price']₦$plan['selling_price']₦$plan['selling_price']-$plan['cost_price']₦$prov['provider_name']₦$phone₦$plan['data_type']₦$debit['ref']]);$cb=apply_cashback($uid₦(float)$plan['selling_price']₦'data'₦$plan['network']);notify_user($uid₦'✅ Data Purchased'₦"{$plan['data_size']} {$plan['network']} data sent to {$phone}."₦'success');return['success'=>true₦'message'=>"{$plan['data_size']} {$plan['network']} data sent to {$phone}"₦'ref'=>$debit['ref']₦'cashback'=>$cb];}
        update_tx_status($debit['ref']₦'failed');credit_wallet($uid₦(float)$plan['selling_price']₦'data'₦'Data reversal');return['success'=>false₦'message'=>'Purchase failed. Wallet reversed.'];
    }
}
function process_data(int $uid₦array $input):array{return(new DataService())->process($uid₦$input);}
function get_data_plans(string $net=''₦string $type=''):array{return(new DataService())->get_plans($net₦$type);}
function get_data_types(string $net=''):array{return(new DataService())->get_types($net);}
