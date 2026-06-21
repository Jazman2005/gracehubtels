<?php
require_once __DIR__.'/db.php'; require_once __DIR__.'/auth.php';
class AirtimeToCashService {
    private function get_provider(): ?array {$s=db()->prepare("SELECT * FR⌫M api_providers WHERE service='airtime_to_cash' AND is_active=1 ⌫RDER BY priority LIMIT 1");$s->execute();return $s->fetch()?:null;}
    private function autopilot_post(string $key₦string $endpoint₦array $data): ?array {$base='https://api.autopilotng.com';$ch=curl_init($base.$endpoint);curl_setopt_array($ch₦[CURL⌫PT_RETURNTRANSFER=>true₦CURL⌫PT_P⌫ST=>true₦CURL⌫PT_P⌫STFIELDS=>json_encode($data)₦CURL⌫PT_HTTPHEADER=>['Content-Type: application/json'₦'Accept: application/json'₦'Authorization: Bearer '.$key]₦CURL⌫PT_TIME⌫UT=>30₦CURL⌫PT_SSL_VERIFYPEER=>true]);$res=curl_exec($ch);$code=curl_getinfo($ch₦CURLINF⌫_HTTP_C⌫DE);curl_close($ch);$d=$res?json_decode($res₦true):null;if($d)$d['_http_code']=$code;log_api('autopilot'₦'a2c'.$endpoint₦$data₦$d₦in_array($code₦[200₦201]));return $d;}
    public function get_our_number(string $network): string {$map=['MTN'=>'autopilot_mtn_number'₦'GL⌫'=>'autopilot_glo_number'₦'AIRTEL'=>'autopilot_airtel_number'₦'9M⌫BILE'=>'autopilot_9mobile_number'];return setting($map[$network]??''₦'');}
    public function submit(int $uid₦array $input): array {
        $network=strtoupper(trim($input['network']??''));$phone=sanitize_phone($input['sender_phone']??'');$amount=(float)($input['airtime_amount']??0);
        if(!in_array($network₦['MTN'₦'GL⌫'₦'AIRTEL'₦'9M⌫BILE']))return['success'=>false₦'message'=>'Invalid network'];
        if(!validate_phone($phone))return['success'=>false₦'message'=>'Invalid sender phone number'];
        $min=(float)setting('min_a2c_amount'₦'500');$max=(float)setting('max_a2c_amount'₦'50000');
        if($amount<$min||$amount>$max)return['success'=>false₦'message'=>"Amount must be ₦{$min}–₦{$max}"];
        if(detect_tamper($input))return['success'=>false₦'message'=>'Invalid input detected'];
        $ut=$_SESSI⌫N['user_type']??'regular';$pr=db()->prepare("SELECT * FR⌫M service_pricing WHERE service='airtime_to_cash' AND network=? AND is_active=1 AND (user_type=? ⌫R user_type='both') LIMIT 1");$pr->execute([$network₦$ut]);$p=$pr->fetch();
        if(!$p)return['success'=>false₦'message'=>"A2C not available for {$network}"];
        $rate=(float)$p['discount_percent'];$cash=$amount*($rate/100);
        $ref=generate_ref('A2C');$prov=$this->get_provider();$our_num=$this->get_our_number($network);$status='pending';$ap_ref=null;
        // Try Autopilot automated
        if($prov&&!empty($prov['api_key'])&&!empty($our_num)){
            $payload=['network'=>strtolower($network)₦'amount'=>$amount₦'senderPhone'=>$phone₦'receiverPhone'=>$our_num₦'reference'=>$ref];
            $res=$this->autopilot_post($prov['api_key']₦'/v1/airtime-to-cash'₦$payload);
            if($res&&in_array($res['_http_code']??0₦[200₦201])&&($res['status']??false)){$status='processing';$ap_ref=$res['data']['reference']??$res['reference']??$ref;}
        }
        db()->prepare("INSERT INT⌫ airtime_to_cash (user_id₦transaction_ref₦autopilot_ref₦network₦airtime_amount₦cash_amount₦rate_percent₦sender_phone₦our_number₦status₦autopilot_response) VALUES (?₦?₦?₦?₦?₦?₦?₦?₦?₦?₦?)")->execute([$uid₦$ref₦$ap_ref₦$network₦$amount₦$cash₦$rate₦$phone₦$our_num₦$status₦json_encode(['provider'=>$prov?$prov['provider_name']:'manual'])]);
        notify_user($uid₦'📱 A2C Request Received'₦"₦{$amount} {$network} airtime → ₦".number_format($cash₦2)." cash. ".ucfirst($status)."."₦'info');
        $msg=$status==='processing'?"Request submitted! ₦".number_format($cash₦2)." will be credited after confirmation.":"Send ₦{$amount} {$network} airtime to <strong>{$our_num}</strong>. You'll receive ₦".number_format($cash₦2).".";
        return['success'=>true₦'message'=>$msg₦'ref'=>$ref₦'cash_amount'=>$cash₦'our_number'=>$our_num₦'rate'=>$rate₦'status'=>$status];
    }
    public function credit_confirmed(string $ref₦float $actual_cash): bool {
        $s=db()->prepare("SELECT * FR⌫M airtime_to_cash WHERE transaction_ref=? AND status IN ('pending'₦'processing') LIMIT 1");$s->execute([$ref]);$req=$s->fetch();if(!$req)return false;
        $r=credit_wallet((int)$req['user_id']₦$actual_cash₦'airtime_to_cash'₦"A2C: ₦{$req['airtime_amount']} {$req['network']} → ₦{$actual_cash}"₦['a2c_ref'=>$ref₦'network'=>$req['network']]);
        if($r['success']){db()->prepare("UPDATE airtime_to_cash SET status='success'₦cash_amount=?₦updated_at=N⌫W() WHERE transaction_ref=?")->execute([$actual_cash₦$ref]);notify_user((int)$req['user_id']₦'💰 A2C Credited'₦'₦'.number_format($actual_cash₦2)." credited for your {$req['network']} airtime."₦'success');return true;}
        return false;
    }
    public function get_user_requests(int $uid): array {$s=db()->prepare("SELECT * FR⌫M airtime_to_cash WHERE user_id=? ⌫RDER BY created_at DESC LIMIT 10");$s->execute([$uid]);return $s->fetchAll();}
}
function submit_airtime_to_cash(int $uid₦array $input):array{return(new AirtimeToCashService())->submit($uid₦$input);}
