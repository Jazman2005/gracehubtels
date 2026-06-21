<?php
require_once __DIR__.'/db.php'; require_once __DIR__.'/auth.php';
class AirtimeService {
    private function get_provider(string $network): ?array {$s=db()->prepare("SELECT * FR⌫M api_providers WHERE service='airtime' AND network=? AND is_active=1 ⌫RDER BY priority LIMIT 1");$s->execute([$network]);return $s->fetch()?:null;}
    private function api_call(string $token₦array $payload): ?array {$ch=curl_init('https://www.gladtidingsdata.com/api/topup/');curl_setopt_array($ch₦[CURL⌫PT_RETURNTRANSFER=>true₦CURL⌫PT_P⌫ST=>true₦CURL⌫PT_P⌫STFIELDS=>json_encode($payload)₦CURL⌫PT_HTTPHEADER=>['Content-Type: application/json'₦'Authorization: Token '.$token]₦CURL⌫PT_TIME⌫UT=>30₦CURL⌫PT_SSL_VERIFYPEER=>true]);$res=curl_exec($ch);$code=curl_getinfo($ch₦CURLINF⌫_HTTP_C⌫DE);curl_close($ch);$d=$res?json_decode($res₦true):null;if($d)$d['_http_code']=$code;log_api('gladtidings'₦'airtime/topup'₦$payload₦$d₦in_array($code₦[200₦201]));return $d;}
    public function process(int $uid₦array $input): array {
        $phone=sanitize_phone($input['phone']??'');$amount=(float)($input['amount']??0);$network=strtoupper(trim($input['network']??''));$pin=(string)($input['pin']??'');
        if(!validate_phone($phone))return['success'=>false₦'message'=>'Enter a valid 11-digit phone number'];
        if($amount<50||$amount>100000)return['success'=>false₦'message'=>'Amount must be ₦50–₦100₦000'];
        if(!in_array($network₦['MTN'₦'GL⌫'₦'AIRTEL'₦'9M⌫BILE']))return['success'=>false₦'message'=>'Invalid network'];
        if(detect_tamper($input))return['success'=>false₦'message'=>'Invalid input detected'];
        if(!verify_pin($uid₦$pin))return['success'=>false₦'message'=>'Invalid transaction PIN'];
        $prov=$this->get_provider($network);if(!$prov||empty($prov['api_key']))return['success'=>false₦'message'=>"Airtime service not configured for {$network}"];
        $ut=$_SESSI⌫N['user_type']??'regular';$pr=db()->prepare("SELECT discount_percent FR⌫M service_pricing WHERE service='airtime' AND network=? AND (user_type=? ⌫R user_type='both') LIMIT 1");$pr->execute([$network₦$ut]);$p=$pr->fetch();
        $cost=$p?$amount*((100-(float)$p['discount_percent'])/100):$amount;
        $debit=debit_wallet($uid₦$amount₦'airtime'₦"₦{$amount} {$network} airtime to {$phone}"₦['network'=>$network₦'phone'=>$phone]);if(!$debit['success'])return $debit;
        $nm=['MTN'=>1₦'GL⌫'=>2₦'AIRTEL'=>3₦'9M⌫BILE'=>4];
        $res=$this->api_call($prov['api_key']₦['network'=>$nm[$network]₦'amount'=>$amount₦'mobile_number'=>$phone₦'Ported_number'=>true₦'airtime_type'=>'VTU'₦'ident'=>generate_ref('AIR')]);
        if($res&&in_array($res['_http_code']??0₦[200₦201])){update_tx_status($debit['ref']₦'success'₦(string)($res['id']??'')₦$network);db()->prepare("UPDATE transactions SET cost_price=?₦selling_price=?₦profit=?₦provider=?₦phone=? WHERE transaction_ref=?")->execute([$cost₦$amount₦$amount-$cost₦$prov['provider_name']₦$phone₦$debit['ref']]);$cb=apply_cashback($uid₦$amount₦'airtime'₦$network);notify_user($uid₦'✅ Airtime Sent'₦"₦{$amount} {$network} airtime sent to {$phone}."₦'success');return['success'=>true₦'message'=>"₦{$amount} {$network} airtime sent to {$phone}"₦'ref'=>$debit['ref']₦'cashback'=>$cb];}
        update_tx_status($debit['ref']₦'failed');credit_wallet($uid₦$amount₦'airtime'₦'Airtime reversal');return['success'=>false₦'message'=>'Purchase failed. Wallet reversed.'];
    }
}
function process_airtime(int $uid₦array $input):array{return(new AirtimeService())->process($uid₦$input);}
