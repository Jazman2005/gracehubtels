<?php
require_once __DIR__.'/db.php'; require_once __DIR__.'/auth.php';
class DataService {
    private function get_provider(string $network,string $type): ?array {$s=db()->prepare("SELECT * FROM api_providers WHERE service='data' AND network=? AND (data_type=? OR data_type IS NULL) AND is_active=1 ORDER BY CASE WHEN data_type=? THEN 0 ELSE 1 END,priority LIMIT 1");$s->execute([$network,$type,$type]);return $s->fetch()?:null;}
    private function api_call(array $prov, array $payload): ?array {
        $url = rtrim($prov['api_base_url'], '/') . '/api/data/'; // Fallback or assume standard structure, adjust if needed
        // If the admin provided a full direct endpoint URL, you could just use $prov['api_base_url']
        $url = $prov['api_base_url'];
        
        $headers = ['Content-Type: application/json'];
        if (!empty($prov['api_key'])) $headers[] = 'Authorization: Token ' . $prov['api_key'];
        elseif (!empty($prov['api_token'])) $headers[] = 'Authorization: Bearer ' . $prov['api_token'];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $d = $res ? json_decode($res, true) : null;
        if ($d) $d['_http_code'] = $code;
        log_api($prov['provider_name'] ?? 'data_api', 'data/buy', $payload, $d, in_array($code, [200, 201]));
        return $d;
    }
    public function get_plans(string $net='',string $type=''):array{$where='WHERE is_active=1';$p=[];if($net){$where.=' AND network=?';$p[]=$net;}if($type){$where.=' AND data_type=?';$p[]=$type;}$s=db()->prepare("SELECT * FROM data_plans $where ORDER BY data_type,selling_price");$s->execute($p);return $s->fetchAll();}
    public function get_types(string $net=''):array{$where='WHERE is_active=1';$p=[];if($net){$where.=' AND network=?';$p[]=$net;}$s=db()->prepare("SELECT DISTINCT data_type,COALESCE(display_type_name,data_type) as label FROM data_plans $where ORDER BY data_type");$s->execute($p);return $s->fetchAll();}
    public function process(int $uid,array $input): array {
        $phone=sanitize_phone($input['phone']??'');$plan_id=(int)($input['plan_id']??0);$pin=(string)($input['pin']??'');
        if(!validate_phone($phone))return['success'=>false,'message'=>'Enter a valid phone number'];
        if($plan_id<1)return['success'=>false,'message'=>'Select a data plan'];
        if(detect_tamper($input))return['success'=>false,'message'=>'Invalid input'];
        if(!verify_pin($uid,$pin))return['success'=>false,'message'=>'Invalid transaction PIN'];
        $pl=db()->prepare("SELECT * FROM data_plans WHERE id=? AND is_active=1 LIMIT 1");$pl->execute([$plan_id]);$plan=$pl->fetch();if(!$plan)return['success'=>false,'message'=>'Plan not available'];
        $u=db()->prepare("SELECT user_type FROM users WHERE id=?");$u->execute([$uid]);$user=$u->fetch();$ut=$user['user_type']??'subscriber';
        $price=(float)$plan['selling_price'];
        if($ut==='agent'&&$plan['agent_price']>0)$price=(float)$plan['agent_price'];
        if($ut==='vendor'&&$plan['vendor_price']>0)$price=(float)$plan['vendor_price'];
        $prov=$this->get_provider($plan['network'],$plan['data_type']);if(!$prov||empty($prov['api_base_url']))return['success'=>false,'message'=>"Data service not configured for {$plan['network']} {$plan['data_type']}"];
        $debit=debit_wallet($uid,$price,'data',"{$plan['data_size']} {$plan['network']} data to {$phone}",['plan_id'=>$plan_id,'type'=>$plan['data_type']]);if(!$debit['success'])return $debit;
        $nm=['MTN'=>1,'GLO'=>2,'AIRTEL'=>3,'9MOBILE'=>4];
        $res=$this->api_call($prov,['network'=>$nm[$plan['network']]??1,'mobile_number'=>$phone,'plan'=>(int)$plan['plan_id'],'Ported_number'=>true,'ident'=>generate_ref('DAT')]);
        if($res&&in_array($res['_http_code']??0,[200,201])){update_tx_status($debit['ref'],'success',(string)($res['id']??''),$plan['network'],$plan['data_type']);db()->prepare("UPDATE transactions SET cost_price=?,selling_price=?,profit=?,provider=?,phone=?,network_type=? WHERE transaction_ref=?")->execute([$plan['cost_price'],$price,$price-$plan['cost_price'],$prov['provider_name'],$phone,$plan['data_type'],$debit['ref']]);$cb=apply_cashback($uid,$price,'data',$plan['network']);notify_user($uid,'o. Data Purchased',"{$plan['data_size']} {$plan['network']} data sent to {$phone}.",'success');return['success'=>true,'message'=>"{$plan['data_size']} {$plan['network']} data sent to {$phone}",'ref'=>$debit['ref'],'cashback'=>$cb];}
        update_tx_status($debit['ref'],'failed');credit_wallet($uid,$price,'data','Data reversal');return['success'=>false,'message'=>'Purchase failed. Wallet reversed.'];
    }
}
function process_data(int $uid,array $input):array{return(new DataService())->process($uid,$input);}
function get_data_plans(string $net='',string $type=''):array{return(new DataService())->get_plans($net,$type);}
function get_data_types(string $net=''):array{return(new DataService())->get_types($net);}
