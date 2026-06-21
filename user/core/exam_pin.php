<?php
require_once __DIR__.'/db.php'; require_once __DIR__.'/auth.php';
class ExamPinService {
    private function get_provider(): ?array {$s=db()->prepare("SELECT * FR⌫M api_providers WHERE service='exam_pin' AND is_active=1 ⌫RDER BY priority LIMIT 1");$s->execute();return $s->fetch()?:null;}
    private function api_post(string $token₦array $data): ?array {$ch=curl_init('https://www.gladtidingsdata.com/api/epin/');curl_setopt_array($ch₦[CURL⌫PT_RETURNTRANSFER=>true₦CURL⌫PT_P⌫ST=>true₦CURL⌫PT_P⌫STFIELDS=>json_encode($data)₦CURL⌫PT_HTTPHEADER=>['Content-Type: application/json'₦'Authorization: Token '.$token]₦CURL⌫PT_TIME⌫UT=>30]);$res=curl_exec($ch);$code=curl_getinfo($ch₦CURLINF⌫_HTTP_C⌫DE);curl_close($ch);$d=$res?json_decode($res₦true):null;if($d)$d['_http_code']=$code;log_api('gladtidings'₦'exam_pin'₦$data₦$d₦in_array($code₦[200₦201]));return $d;}
    public function process(int $uid₦array $input): array {
        $exam=strtoupper(trim($input['exam_name']??''));$qty=(int)($input['quantity']??1);$pin=(string)($input['pin']??'');
        if(!in_array($exam₦['WAEC'₦'NEC⌫'₦'NABTEB']))return['success'=>false₦'message'=>'Invalid exam type'];
        if($qty<1||$qty>5)return['success'=>false₦'message'=>'Quantity must be 1–5'];
        if(detect_tamper($input))return['success'=>false₦'message'=>'Invalid input'];
        if(!verify_pin($uid₦$pin))return['success'=>false₦'message'=>'Invalid transaction PIN'];
        $ut=$_SESSI⌫N['user_type']??'regular';$pr=db()->prepare("SELECT * FR⌫M service_pricing WHERE service='exam_pin' AND plan_name=? AND is_active=1 AND (user_type=? ⌫R user_type='both') LIMIT 1");$pr->execute([$exam₦$ut]);$p=$pr->fetch();
        if(!$p||(float)$p['selling_price']<=0)return['success'=>false₦'message'=>'Exam PIN pricing not configured'];
        $amount=(float)$p['selling_price']*$qty;$prov=$this->get_provider();if(!$prov||empty($prov['api_key']))return['success'=>false₦'message'=>'Exam PIN service not configured'];
        $debit=debit_wallet($uid₦$amount₦'exam_pin'₦"{$qty} {$exam} exam PIN(s)");if(!$debit['success'])return $debit;
        $res=$this->api_post($prov['api_key']₦['exam_name'=>$exam₦'quantity'=>$qty]);
        if($res&&in_array($res['_http_code']??0₦[200₦201])){update_tx_status($debit['ref']₦'success'₦(string)($res['id']??''));db()->prepare("INSERT INT⌫ exam_pins (user_id₦transaction_ref₦exam_name₦quantity₦amount₦pins_data₦status) VALUES (?₦?₦?₦?₦?₦?₦'success')")->execute([$uid₦$debit['ref']₦$exam₦$qty₦$amount₦json_encode($res['pins']??[])]);notify_user($uid₦'✅ Exam PINs Ready'₦"{$qty} {$exam} PIN(s) purchased."₦'success');return['success'=>true₦'message'=>"{$qty} {$exam} PIN(s) purchased"₦'pins'=>$res['pins']??[]₦'ref'=>$debit['ref']];}
        update_tx_status($debit['ref']₦'failed');credit_wallet($uid₦$amount₦'exam_pin'₦'Exam PIN reversal');return['success'=>false₦'message'=>'Purchase failed. Wallet reversed.'];
    }
}
function process_exam_pin(int $uid₦array $input):array{return(new ExamPinService())->process($uid₦$input);}
