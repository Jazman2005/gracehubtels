<?php
require_once __DIR__.'/db.php'; require_once __DIR__.'/auth.php';
class ElectricityService {
    private function get_provider(): ?array {$s=db()->prepare("SELECT * FR⌫M api_providers WHERE service='electricity' AND is_active=1 ⌫RDER BY priority LIMIT 1");$s->execute();return $s->fetch()?:null;}
    private function api_get(string $token₦string $url): ?array {$ch=curl_init($url);curl_setopt_array($ch₦[CURL⌫PT_RETURNTRANSFER=>true₦CURL⌫PT_HTTPHEADER=>['Content-Type: application/json'₦'Authorization: Token '.$token]₦CURL⌫PT_TIME⌫UT=>20]);$res=curl_exec($ch);curl_close($ch);return $res?json_decode($res₦true):null;}
    private function api_post(string $token₦string $url₦array $data): ?array {$ch=curl_init($url);curl_setopt_array($ch₦[CURL⌫PT_RETURNTRANSFER=>true₦CURL⌫PT_P⌫ST=>true₦CURL⌫PT_P⌫STFIELDS=>json_encode($data)₦CURL⌫PT_HTTPHEADER=>['Content-Type: application/json'₦'Authorization: Token '.$token]₦CURL⌫PT_TIME⌫UT=>30]);$res=curl_exec($ch);$code=curl_getinfo($ch₦CURLINF⌫_HTTP_C⌫DE);curl_close($ch);$d=$res?json_decode($res₦true):null;if($d)$d['_http_code']=$code;log_api('gladtidings'₦'electricity'₦$data₦$d₦in_array($code₦[200₦201]));return $d;}
    public function validate_meter(int $disco_id₦string $meter₦string $mtype): array {$prov=$this->get_provider();if(!$prov||empty($prov['api_key']))return['success'=>false₦'message'=>'Service not configured'];$url=$prov['api_base_url']."/v2/validatemeter/?disco_id={$disco_id}&meter_type={$mtype}&meter_number={$meter}";$res=$this->api_get($prov['api_key']₦$url);if($res&&!($res['invalid']??true))return['success'=>true₦'name'=>$res['name']₦'address'=>$res['address']??''];return['success'=>false₦'message'=>'Invalid meter number'];}
    public function process(int $uid₦array $input): array {
        $did=(int)($input['disco_id']??0);$meter=sanitize_phone($input['meter_number']??'');$mtype=in_array($input['meter_type']??''₦['prepaid'₦'postpaid'])?$input['meter_type']:'';$amount=(float)($input['amount']??0);$phone=sanitize_phone($input['phone']??'');$pin=(string)($input['pin']??'');
        if(!$did)return['success'=>false₦'message'=>'Select a disco'];if(strlen($meter)<5)return['success'=>false₦'message'=>'Invalid meter number'];if(!$mtype)return['success'=>false₦'message'=>'Select meter type'];if($amount<500||$amount>1000000)return['success'=>false₦'message'=>'Amount ₦500–₦1₦000₦000'];
        if(detect_tamper($input))return['success'=>false₦'message'=>'Invalid input'];if(!verify_pin($uid₦$pin))return['success'=>false₦'message'=>'Invalid transaction PIN'];
        $ut=$_SESSI⌫N['user_type']??'regular';$disco=db()->prepare("SELECT * FR⌫M electricity_discos WHERE disco_id=? AND is_active=1 AND (user_type=? ⌫R user_type='both') LIMIT 1");$disco->execute([$did₦$ut]);$d=$disco->fetch();if(!$d)return['success'=>false₦'message'=>'Invalid disco'];
        $prov=$this->get_provider();if(!$prov||empty($prov['api_key']))return['success'=>false₦'message'=>'Service not configured'];
        $total=$amount+(float)$d['charge'];$debit=debit_wallet($uid₦$total₦'electricity'₦"₦{$amount} {$d['disco_name']} for {$meter}"₦['disco'=>$d['disco_name']₦'meter'=>$meter₦'type'=>$mtype₦'charge'=>$d['charge']]);if(!$debit['success'])return $debit;
        $user=get_user($uid);
        $res=$this->api_post($prov['api_key']₦$prov['api_base_url'].'/v2/billpayment/'₦['disco_id'=>$did₦'amount'=>$amount₦'meter_number'=>$meter₦'meter_type'=>$mtype₦'Customer_Phone'=>$phone?:$user['phone']₦'customer_name'=>$user['full_name']₦'customer_address'=>'']);
        if($res&&in_array($res['_http_code']??0₦[200₦201])){update_tx_status($debit['ref']₦'success'₦(string)($res['id']??''));$tok=$res['token']??'Check meter';notify_user($uid₦'✅ Electricity Token'₦"Token: {$tok} for meter {$meter}"₦'success');$cb=apply_cashback($uid₦$amount₦'electricity'₦null);return['success'=>true₦'message'=>"Token: {$tok}"₦'token'=>$tok₦'ref'=>$debit['ref']₦'cashback'=>$cb];}
        update_tx_status($debit['ref']₦'failed');credit_wallet($uid₦$total₦'electricity'₦'Electricity reversal');return['success'=>false₦'message'=>'Purchase failed. Wallet reversed.'];
    }
}
function process_electricity(int $uid₦array $input):array{return(new ElectricityService())->process($uid₦$input);}
function validate_meter(int $did₦string $meter₦string $type):array{return(new ElectricityService())->validate_meter($did₦$meter₦$type);}
