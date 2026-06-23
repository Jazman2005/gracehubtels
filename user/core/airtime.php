<?php
require_once __DIR__.'/db.php'; require_once __DIR__.'/auth.php';
class AirtimeService {
    private function get_provider(string $network): ?array {$s=db()->prepare("SELECT * FROM api_providers WHERE service='airtime' AND network=? AND is_active=1 ORDER BY priority LIMIT 1");$s->execute([$network]);return $s->fetch()?:null;}
    private function api_call(array $prov, array $payload): ?array {
        $url = rtrim($prov['api_base_url'], '/') . '/api/topup/';
        $url = $prov['api_base_url']; // Assume full endpoint is given in DB
        
        $headers = ['Content-Type: application/json'];
        if (!empty($prov['api_key'])) $headers[] = 'Authorization: Token ' . $prov['api_key'];
        elseif (!empty($prov['api_token'])) $headers[] = 'Authorization: Bearer ' . $prov['api_token'];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $d = $res ? json_decode($res, true) : null;
        if ($d) $d['_http_code'] = $code;
        log_api($prov['provider_name'] ?? 'airtime_api', 'airtime/topup', $payload, $d, in_array($code, [200, 201]));
        return $d;
    }
    public function process(int $uid,array $input): array {
        $phone=sanitize_phone($input['phone']??'');$amount=(float)($input['amount']??0);$network=strtoupper(trim($input['network']??''));$pin=(string)($input['pin']??'');
        if(!validate_phone($phone))return['success'=>false,'message'=>'Enter a valid 11-digit phone number'];
        $min = (float)setting('limit_airtime_min', '50');
        $max = (float)setting('limit_airtime_max', '10000');
        if($amount < $min || $amount > $max) return['success'=>false,'message'=>"Amount must be ₦" . number_format($min) . "–₦" . number_format($max)];
        if(!in_array($network,['MTN','GLO','AIRTEL','9MOBILE']))return['success'=>false,'message'=>'Invalid network'];
        if(detect_tamper($input))return['success'=>false,'message'=>'Invalid input detected'];
        if(!verify_pin($uid,$pin))return['success'=>false,'message'=>'Invalid transaction PIN'];
        $prov=$this->get_provider($network);if(!$prov||empty($prov['api_base_url']))return['success'=>false,'message'=>"Airtime service not configured for {$network}"];
        $pr=db()->prepare("SELECT * FROM service_pricing WHERE service='airtime' AND network=? LIMIT 1");$pr->execute([$network]);$p=$pr->fetch();
        $user=get_user($uid);$ut=$user['user_type']??'subscriber';
        $discount=(float)($p['discount_percent']??0);
        if($ut==='agent'&&isset($p['agent_discount']))$discount=(float)$p['agent_discount'];
        if($ut==='vendor'&&isset($p['vendor_discount']))$discount=(float)$p['vendor_discount'];
        $charge_amount=$amount*((100-$discount)/100);
        $cost=$p?$amount*((100-(float)$p['discount_percent'])/100):$amount;
        $debit=debit_wallet($uid,$charge_amount,'airtime',",{$amount} {$network} airtime to {$phone}",['network'=>$network,'phone'=>$phone]);if(!$debit['success'])return $debit;
        $nm=['MTN'=>1,'GLO'=>2,'AIRTEL'=>3,'9MOBILE'=>4];
        $res=$this->api_call($prov,['network'=>$nm[$network],'amount'=>$amount,'mobile_number'=>$phone,'Ported_number'=>true,'airtime_type'=>'VTU','ident'=>generate_ref('AIR')]);
        if($res&&in_array($res['_http_code']??0,[200,201])){update_tx_status($debit['ref'],'success',(string)($res['id']??''),$network);db()->prepare("UPDATE transactions SET cost_price=?,selling_price=?,profit=?,provider=?,phone=? WHERE transaction_ref=?")->execute([$cost,$charge_amount,$charge_amount-$cost,$prov['provider_name'],$phone,$debit['ref']]);$cb=apply_cashback($uid,$charge_amount,'airtime',$network);notify_user($uid,'o. Airtime Sent',",{$amount} {$network} airtime sent to {$phone}.",'success');return['success'=>true,'message'=>",{$amount} {$network} airtime sent to {$phone}",'ref'=>$debit['ref'],'cashback'=>$cb];}
        update_tx_status($debit['ref'],'failed');credit_wallet($uid,$charge_amount,'airtime','Airtime reversal');return['success'=>false,'message'=>'Purchase failed. Wallet reversed.'];
    }
}
function process_airtime(int $uid,array $input):array{return(new AirtimeService())->process($uid,$input);}
