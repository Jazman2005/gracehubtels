<?php
require_once __DIR__.'/db.php'; require_once __DIR__.'/auth.php'; require_once __DIR__.'/api_router.php';
try{db()->query("SELECT 1 FROM airtime_to_cash LIMIT 1");}catch(PDOException$e){
    db()->exec("CREATE TABLE IF NOT EXISTS airtime_to_cash (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        transaction_ref VARCHAR(60) NOT NULL UNIQUE,
        network VARCHAR(20) NOT NULL,
        airtime_amount DECIMAL(12,2) NOT NULL,
        cash_amount DECIMAL(12,2) NOT NULL,
        discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        sender_phone VARCHAR(20) NOT NULL,
        our_number VARCHAR(20) DEFAULT NULL,
        status ENUM('pending','processing','success','failed') NOT NULL DEFAULT 'pending',
        admin_note VARCHAR(255) DEFAULT NULL,
        provider_response TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (user_id), INDEX (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach(['MTN'=>65,'GLO'=>60,'AIRTEL'=>62,'9MOBILE'=>58] as $net=>$rate){
        $c=db()->prepare("SELECT COUNT(*) FROM service_pricing WHERE service='airtime_to_cash' AND network=? AND is_active=1");$c->execute([$net]);
        if(!$c->fetchColumn())db()->prepare("INSERT INTO service_pricing (service,network,cost_price,selling_price,discount_percent,is_active) VALUES ('airtime_to_cash',?,0,0,?,1)")->execute([$net,$rate]);
    }
}
class AirtimeToCashService {
    public function get_our_number(string $network): string {$map=['MTN'=>'autopilot_mtn_number','GLO'=>'autopilot_glo_number','AIRTEL'=>'autopilot_airtel_number','9MOBILE'=>'autopilot_9mobile_number'];return setting($map[$network]??'','');}
    private function get_provider(string $network): ?array {$s=db()->prepare("SELECT * FROM api_providers WHERE service='airtime_to_cash' AND network=? AND is_active=1 ORDER BY priority LIMIT 1");$s->execute([$network]);return $s->fetch()?:null;}
    private function api_call(array $prov,string $endpoint,array $payload,bool $use_url_ref=false,string $url_ref=''): ?array {
        $base=rtrim($prov['api_base_url'],'/').'/api/v1/airtime/cash';
        $url=$use_url_ref?$base.'/'.$url_ref.$endpoint:$base.$endpoint;
        $headers=['Content-Type: application/json'];if(!empty($prov['api_key']))$headers[]='Authorization: Token '.$prov['api_key'];
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);$res=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
        $d=$res?json_decode($res,true):null;if($res===false)$d=['_http_code'=>500,'message'=>"cURL Error: ".$err];elseif($d===null&&!empty($res))$d=['_http_code'=>$code,'message'=>"HTTP ".$code.": ".substr(strip_tags($res),0,200)];
        if($d)$d['_http_code']=$code;log_api($prov['provider_name']??'airtime_to_cash_api','airtime_to_cash',$payload,$d,in_array($code, [200, 201]) && !is_api_response_failure($d));return $d;
    }
    private function is_autosyncng(array $prov): bool {return stripos($prov['provider_name']??'','autosyncng')!==false;}
    public function submit(int $uid,array $input): array {
        $network=strtoupper(trim($input['network']??''));$phone=sanitize_phone($input['sender_phone']??'');$amount=(float)($input['airtime_amount']??0);
        if(!in_array($network,['MTN','GLO','AIRTEL','9MOBILE']))return['success'=>false,'message'=>'Invalid network'];
        if(!validate_phone($phone))return['success'=>false,'message'=>'Invalid sender phone number'];
        // Every other money-adjacent action in this app (airtime, data,
        // cabletv, electricity, exam_pin, recharge_card, data_card,
        // bank_transfer, internal_transfer, bulk_sms, virtual card
        // create/fund/withdraw, cashback withdrawal) requires the
        // transaction PIN before proceeding — this one didn't, so anyone
        // holding a valid bearer token (without the PIN) could submit A2C
        // requests as the victim.
        if(!verify_pin($uid,trim($input['pin']??'')))return['success'=>false,'message'=>'Incorrect transaction PIN'];
        // Nothing stopped rapid duplicate submissions creating multiple
        // pending rows for one real airtime transfer, which risked a
        // double-credit if an admin later approved more than one of them.
        if(!claim_purchase_lock($uid,'airtime_to_cash',[$network,$phone,$amount]))return['success'=>false,'message'=>'Duplicate request detected. Please wait a moment and try again.'];
        $min=(float)setting('min_a2c_amount','500');$max=(float)setting('max_a2c_amount','50000');
        if($amount<$min||$amount>$max)return['success'=>false,'message'=>"Amount must be ₦{$min}–₦{$max}"];
        if(detect_tamper($input))return['success'=>false,'message'=>'Invalid input detected'];
        $pr=db()->prepare("SELECT * FROM service_pricing WHERE service='airtime_to_cash' AND network=? AND is_active=1 LIMIT 1");$pr->execute([$network]);$p=$pr->fetch();
        if(!$p)return['success'=>false,'message'=>"A2C not available for {$network}"];
        $user=get_user($uid);$ut=$user['user_type']??'subscriber';
        $rate=(float)$p['discount_percent'];
        if($ut==='agent'&&isset($p['agent_discount'])&&$p['agent_discount']>0)$rate=(float)$p['agent_discount'];
        if($ut==='vendor'&&isset($p['vendor_discount'])&&$p['vendor_discount']>0)$rate=(float)$p['vendor_discount'];
        $cash=$amount*($rate/100);
        $ref=generate_ref('A2C');$our_num=$this->get_our_number($network);
        $prov=$this->get_provider($network);$uses_api=$prov&&$this->is_autosyncng($prov);
        if($uses_api){
            $sharePin=trim($input['share_pin']??'');
            if(empty($sharePin))return['success'=>false,'message'=>"Enter the {$network} airtime share PIN to proceed"];
            $productId='mtn';
            $api_payload=['request_ref'=>$ref,'phone'=>$phone,'product_id'=>$productId,'amount'=>$amount,'sharePin'=>$sharePin];
            $res=$this->api_call($prov,'',$api_payload);
            if($res&&in_array($res['_http_code']??0,[200,201])&&($res['status']??'')==='ok'&&!empty($res['data']['transaction']['reference'])){
                $api_ref=$res['data']['transaction']['reference'];
                db()->prepare("INSERT INTO airtime_to_cash (user_id,transaction_ref,network,airtime_amount,cash_amount,discount_percent,sender_phone,our_number,status,provider_response) VALUES (?,?,?,?,?,?,?,?,?,?)")->execute([$uid,$ref,$network,$amount,$cash,$rate,$phone,$our_num,'processing',json_encode(['api_ref'=>$api_ref,'response'=>$res,'product_id'=>$productId])]);
                notify_user($uid,'A2C OTP Sent',"OTP sent to {$phone}. Enter OTP to complete {$amount} {$network} A2C (".number_format($cash,2).")",'info');
                return['success'=>true,'message'=>"OTP sent to {$phone}. Enter OTP to complete.",'ref'=>$ref,'cash_amount'=>$cash,'our_number'=>'','rate'=>$rate,'requires_otp'=>true,'status'=>'processing'];
            }
            $err='A2C initiation failed.';if(!empty($res['message']))$err=$res['message'];elseif(!empty($res['error']))$err=is_array($res['error'])?json_encode($res['error']):$res['error'];
            return['success'=>false,'message'=>$err];
        }
        db()->prepare("INSERT INTO airtime_to_cash (user_id,transaction_ref,network,airtime_amount,cash_amount,discount_percent,sender_phone,our_number,status) VALUES (?,?,?,?,?,?,?,?,?)")->execute([$uid,$ref,$network,$amount,$cash,$rate,$phone,$our_num,'pending']);
        notify_user($uid,'A2C Request Received',"{$amount} {$network} airtime → ".number_format($cash,2)." cash. Pending admin approval",'info');
        send_whatsapp_alert("New A2C Request!\nUser: ".($user['full_name']??'Unknown')." (".($user['email']??$user['phone']??'').")\nNetwork: {$network}\nAirtime: N".number_format($amount,0)."\nCash Due: N".number_format($cash,2)."\nSender: {$phone}\nRef: {$ref}\nStatus: Pending");
        try{notify_admin('Airtime-to-Cash Request',"
<div style='text-align:center;padding:10px 0 16px;'>
<span style='font-size:28px;line-height:1;font-weight:800;color:#e65100;'>A2C</span>
<h3 style='color:#4F46E5;font-size:18px;margin:8px 0 4px;'>New A2C Request (Manual)</h3>
<p style='color:#475569;font-size:13px;margin:0;'>A user has submitted an airtime-to-cash request for admin approval.</p>
</div>
<table style='width:100%;border-collapse:collapse;'>
<tr><td style='padding:8px 12px;background:#f8fafc;border-radius:8px;'>
<table width='100%'>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>User</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>".htmlspecialchars($user['full_name']??'Unknown',ENT_QUOTES)."</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Network</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>".htmlspecialchars($network,ENT_QUOTES)."</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Airtime</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>₦".number_format($amount,0)."</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Cash Due</td><td style='padding:4px 0;text-align:right;font-weight:700;color:#16a34a;font-size:14px;'>₦".number_format($cash,2)."</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Sender</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>".htmlspecialchars($phone,ENT_QUOTES)."</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Ref</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>".htmlspecialchars($ref,ENT_QUOTES)."</td></tr>
</table>
</td></tr>
</table>");}catch(\Throwable$e){}
        $msg="Send N{$amount} {$network} airtime to {$our_num}. You'll receive N".number_format($cash,2)." after admin approval.";
        return['success'=>true,'message'=>$msg,'ref'=>$ref,'cash_amount'=>$cash,'our_number'=>$our_num,'rate'=>$rate,'status'=>'pending'];
    }
    public function complete_with_otp(string $ref,string $otp,int $expected_uid=0): array {
        $s=db()->prepare("SELECT * FROM airtime_to_cash WHERE transaction_ref=? AND status='processing' LIMIT 1");$s->execute([$ref]);$req=$s->fetch();
        if(!$req)return['success'=>false,'message'=>'Transaction not found or already completed'];
        if($expected_uid>0 && (int)$req['user_id']!==$expected_uid)return['success'=>false,'message'=>'Transaction not found or already completed'];
        $prov=$this->get_provider($req['network']);if(!$prov||!$this->is_autosyncng($prov))return['success'=>false,'message'=>'Provider not configured'];
        $pd=json_decode($req['provider_response'],true);$api_ref=$pd['api_ref']??'';
        if(empty($api_ref))return['success'=>false,'message'=>'Invalid provider reference'];
        $base=rtrim($prov['api_base_url'],'/').'/api/v1/airtime/cash';
        $url=$base.'/'.$api_ref;
        $headers=['Content-Type: application/json'];if(!empty($prov['api_key']))$headers[]='Authorization: Token '.$prov['api_key'];
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['otp'=>$otp]),CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
        $r2=curl_exec($ch);$c2=curl_getinfo($ch,CURLINFO_HTTP_CODE);$e2=curl_error($ch);curl_close($ch);
        $res=$r2?json_decode($r2,true):null;if($r2===false)$res=['_http_code'=>500,'message'=>"cURL: ".$e2];elseif($res)$res['_http_code']=$c2;
        if($res&&in_array($res['_http_code']??0,[200,201])&&($res['status']??'')==='ok'&&($res['data']['transaction']['status']??'')==='successful'){
            $actual=(float)$req['cash_amount'];
            $db=db();$db->beginTransaction();
            try{
                $lk=db()->prepare("SELECT id,status FROM airtime_to_cash WHERE transaction_ref=? AND status='processing' LIMIT 1 FOR UPDATE");$lk->execute([$ref]);$locked=$lk->fetch();
                if(!$locked){$db->rollback();return['success'=>false,'message'=>'Transaction already completed'];}
                $cr=credit_wallet((int)$req['user_id'],$actual,'airtime_to_cash',"A2C: ₦{$req['airtime_amount']} {$req['network']} → ₦{$actual}",['a2c_ref'=>$ref,'network'=>$req['network'],'api_ref'=>$api_ref,'response'=>provider_response_text($res)]);
                if($cr['success']){
                    $pd['complete_response']=$res;db()->prepare("UPDATE airtime_to_cash SET status='success',cash_amount=?,provider_response=?,updated_at=NOW() WHERE transaction_ref=?")->execute([$actual,json_encode($pd),$ref]);
                    $db->commit();
                    notify_user((int)$req['user_id'],'A2C Credited',number_format($actual,2)." credited for your {$req['network']} airtime",'success',null,$cr['balance_before'],$cr['balance_after']);
                    try{
                        $a2cUser = get_user((int)$req['user_id']);
                        notify_admin('A2C Auto-Credited',"
<div style='text-align:center;padding:10px 0 16px;'>
<span style='font-size:28px;line-height:1;font-weight:800;color:#16a34a;'>OK</span>
<h3 style='color:#4F46E5;font-size:18px;margin:8px 0 4px;'>A2C Auto-Credited</h3>
<p style='color:#475569;font-size:13px;margin:0;'>User was auto-credited via A2C OTP flow.</p>
</div>
<table style='width:100%;border-collapse:collapse;'>
<tr><td style='padding:8px 12px;background:#f8fafc;border-radius:8px;'>
<table width='100%'>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>User</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>".htmlspecialchars(($a2cUser['full_name']??$a2cUser['phone']??'Unknown'),ENT_QUOTES)."</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Amount</td><td style='padding:4px 0;text-align:right;font-weight:700;color:#16a34a;font-size:14px;'>₦".number_format($actual,2)."</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Network</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>".htmlspecialchars($req['network'],ENT_QUOTES)."</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Ref</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>".htmlspecialchars($ref,ENT_QUOTES)."</td></tr>
</table>
</td></tr>
</table>");
                    }catch(\Throwable$e){}
                    return['success'=>true,'message'=>"₦".number_format($actual,2)." credited to your wallet"];
                }
                $db->rollback();
                return['success'=>false,'message'=>'Wallet credit failed: '.($cr['message']??'unknown')];
            }catch(\Throwable$e){$db->rollback();log_error('airtime_to_cash','OTP confirm exception: '.$e->getMessage());return['success'=>false,'message'=>'Transaction error. Please try again or contact support.'];}
        }
        $err='Invalid OTP. Try again.';if(!empty($res['message']))$err=$res['message'];elseif(!empty($res['error']))$err=is_array($res['error'])?json_encode($res['error']):$res['error'];
        return['success'=>false,'message'=>$err];
    }
    public function resend_otp(string $ref,int $expected_uid=0): array {
        $s=db()->prepare("SELECT * FROM airtime_to_cash WHERE transaction_ref=? AND status='processing' LIMIT 1");$s->execute([$ref]);$req=$s->fetch();
        if(!$req)return['success'=>false,'message'=>'Transaction not found or already completed'];
        if($expected_uid>0 && (int)$req['user_id']!==$expected_uid)return['success'=>false,'message'=>'Transaction not found or already completed'];
        $prov=$this->get_provider($req['network']);if(!$prov||!$this->is_autosyncng($prov))return['success'=>false,'message'=>'Provider not configured'];
        $pd=json_decode($req['provider_response'],true);$api_ref=$pd['api_ref']??'';
        if(empty($api_ref))return['success'=>false,'message'=>'Invalid provider reference'];
        $res=$this->api_call($prov,'/resend-otp',[],true,$api_ref);
        if($res&&in_array($res['_http_code']??0,[200,201])&&($res['status']??'')==='ok'){
            return['success'=>true,'message'=>'OTP resent successfully'];
        }
        $err='Failed to resend OTP. Try again.';if(!empty($res['message']))$err=$res['message'];
        return['success'=>false,'message'=>$err];
    }
    public function credit_confirmed(string $ref,float $actual_cash): bool {
        $db=db();$db->beginTransaction();
        try{
            $lk=db()->prepare("SELECT * FROM airtime_to_cash WHERE transaction_ref=? AND status IN ('pending','processing') LIMIT 1 FOR UPDATE");$lk->execute([$ref]);$req=$lk->fetch();
            if(!$req){$db->rollback();return false;}
            $r=credit_wallet((int)$req['user_id'],$actual_cash,'airtime_to_cash',"A2C: N{$req['airtime_amount']} {$req['network']} → N{$actual_cash}",['a2c_ref'=>$ref,'network'=>$req['network']]);
            if($r['success']){db()->prepare("UPDATE airtime_to_cash SET status='success',cash_amount=?,updated_at=NOW() WHERE transaction_ref=?")->execute([$actual_cash,$ref]);$db->commit();notify_user((int)$req['user_id'],'A2C Credited',number_format($actual_cash,2)." credited for your {$req['network']} airtime",'success',null,$r['balance_before'],$r['balance_after']);return true;}
            $db->rollback();return false;
        }catch(\Throwable$e){$db->rollback();return false;}
    }
    public function get_user_requests(int $uid): array {$s=db()->prepare("SELECT * FROM airtime_to_cash WHERE user_id=? ORDER BY created_at DESC LIMIT 10");$s->execute([$uid]);return $s->fetchAll();}
}
function submit_airtime_to_cash(int $uid,array $input):array{return(new AirtimeToCashService())->submit($uid,$input);}
function complete_a2c_with_otp(string $ref,string $otp,int $expected_uid=0):array{return(new AirtimeToCashService())->complete_with_otp($ref,$otp,$expected_uid);}
function resend_a2c_otp(string $ref,int $expected_uid=0):array{return(new AirtimeToCashService())->resend_otp($ref,$expected_uid);}
