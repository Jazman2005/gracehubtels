<?php
require_once __DIR__.'/db.php'; require_once __DIR__.'/auth.php';
class KYCService {
    private string $base='https://open-api.easeid.ai';
    private function sign(array $payload):string{$secret=setting('easeid_secret');if(!$secret)return '';ksort($payload);$str='';foreach($payload as $k=>$v){if($v!==''&&$v!==null&&!is_array($v))$str.="{$k}={$v}&";}return base64_encode(hash_hmac('sha256',rtrim($str,'&'),$secret,true));}
    private function api_post(string $ep,array $payload):?array{$bearer=setting('easeid_bearer');if(!$bearer)return null;$payload['requestTime']=(int)(microtime(true)*1000);$payload['nonceStr']=bin2hex(random_bytes(16));$payload['version']='V1.1';$sig=$this->sign($payload);$ch=curl_init($this->base.$ep);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_HTTPHEADER=>['CountryCode: NG','Authorization: Bearer '.$bearer,'Signature: '.$sig,'Content-Type: application/json'],CURLOPT_TIMEOUT=>30]);$res=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$d=$res?json_decode($res,true):null;if($d)$d['_http_code']=$code;log_api('easeid',$ep,$payload,$d,in_array($code,[200,201]));return $d;}
    public function verify_nin(int $uid,array $input):array{
        $nin=preg_replace('/\D/','',$input['nin']??'');$first=sanitize($input['first_name']??'',50);$last=sanitize($input['last_name']??'',50);$mid=sanitize($input['middle_name']??'',50);$gender=strtolower($input['gender']??'');$dob=$input['dob']??'';$phone=sanitize_phone($input['phone']??'');
        if(strlen($nin)!==11)return['success'=>false,'message'=>'NIN must be 11 digits'];if(strlen($first)<2||strlen($last)<2)return['success'=>false,'message'=>'First and last name required'];
        $chk=db()->prepare("SELECT id FROM users WHERE kyc_nin=? AND id!=? LIMIT 1");$chk->execute([$nin,$uid]);if($chk->fetch())return['success'=>false,'message'=>'NIN already linked to another account'];
        $payload=['nin'=>$nin,'firstName'=>$first,'lastName'=>$last,'middleName'=>$mid];if(in_array($gender,['male','female']))$payload['gender']=$gender;if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$dob))$payload['birthday']=$dob;if($phone)$payload['phoneNumber']=$phone;
        $res=$this->api_post('/api/validator-service/open/nin/verify',$payload);
        if(!$res)return['success'=>false,'message'=>'Verification service unavailable. Try again.'];
        $data=$res['data']??[];$match=$data['nameMatchRlt']??'No Match';$pct=(string)($data['namesMatchPercentage']??'0');
        $ok=in_array($match,['Exactly Match','Partial Match'])&&(float)$pct>=70;$status=$ok?'auto_approved':'rejected';
        db()->prepare("INSERT INTO kyc_requests (user_id,tier,nin,first_name,middle_name,last_name,gender,dob,easeid_response,name_match,match_percent,status) VALUES (?,1,?,?,?,?,?,?,?,?,?,?)")->execute([$uid,$nin,$first,$mid,$last,$gender,$dob,json_encode($res),$match,$pct,$status]);
        if($ok){db()->prepare("UPDATE users SET kyc_level=GREATEST(kyc_level,1),kyc_status='approved',kyc_nin=? WHERE id=?")->execute([$nin,$uid]);notify_user($uid,'o. KYC Tier 1 Approved','NIN verified! Daily limit: ,'.number_format((float)setting('kyc_tier1_limit_daily','50000'),0),'success');return['success'=>true,'message'=>"NIN verified! Match: {$match} ({$pct}%)",'kyc_level'=>1];}
        return['success'=>false,'message'=>"NIN verification failed. Name match: {$match} ({$pct}%). Ensure your names exactly match your NIN records."];
    }
    public function verify_bvn(int $uid,array $input):array{
        $bvn=preg_replace('/\D/','',$input['bvn']??'');$first=sanitize($input['first_name']??'',50);$last=sanitize($input['last_name']??'',50);$mid=sanitize($input['middle_name']??'',50);$gender=strtolower($input['gender']??'');$dob=$input['dob']??'';$phone=sanitize_phone($input['phone']??'');
        if(strlen($bvn)!==11)return['success'=>false,'message'=>'BVN must be 11 digits'];if(strlen($first)<2||strlen($last)<2)return['success'=>false,'message'=>'First and last name required'];
        $user=get_user($uid);if((int)$user['kyc_level']<1)return['success'=>false,'message'=>'Complete Tier 1 (NIN) first'];
        $chk=db()->prepare("SELECT id FROM users WHERE kyc_bvn=? AND id!=? LIMIT 1");$chk->execute([$bvn,$uid]);if($chk->fetch())return['success'=>false,'message'=>'BVN already linked to another account'];
        $payload=['bvn'=>$bvn,'firstName'=>$first,'lastName'=>$last,'middleName'=>$mid];if(in_array($gender,['male','female']))$payload['gender']=$gender;if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$dob))$payload['birthday']=$dob;if($phone)$payload['phoneNumber']=$phone;
        $res=$this->api_post('/api/validator-service/open/bvn/verify',$payload);if(!$res)return['success'=>false,'message'=>'Service unavailable'];
        $data=$res['data']??[];$match=$data['nameMatchRlt']??'No Match';$pct=(string)($data['namesMatchPercentage']??'0');
        $ok=in_array($match,['Exactly Match','Partial Match'])&&(float)$pct>=70;$status=$ok?'auto_approved':'rejected';
        db()->prepare("INSERT INTO kyc_requests (user_id,tier,bvn,first_name,middle_name,last_name,gender,dob,easeid_response,name_match,match_percent,status) VALUES (?,2,?,?,?,?,?,?,?,?,?,?)")->execute([$uid,$bvn,$first,$mid,$last,$gender,$dob,json_encode($res),$match,$pct,$status]);
        if($ok){db()->prepare("UPDATE users SET kyc_level=GREATEST(kyc_level,2),kyc_status='approved',kyc_bvn=? WHERE id=?")->execute([$bvn,$uid]);notify_user($uid,'o. KYC Tier 2 Approved','BVN verified! Higher limits unlocked.','success');return['success'=>true,'message'=>"BVN verified! Match: {$match} ({$pct}%)",'kyc_level'=>2];}
        return['success'=>false,'message'=>"BVN verification failed. Match: {$match} ({$pct}%)."];
    }
    public function submit_tier3(int $uid,array $input):array{$user=get_user($uid);if((int)$user['kyc_level']<2)return['success'=>false,'message'=>'Complete Tier 1 & 2 first'];$addr=sanitize($input['address']??'',300);if(strlen($addr)<10)return['success'=>false,'message'=>'Enter a valid address'];db()->prepare("INSERT INTO kyc_requests (user_id,tier,address,status) VALUES (?,3,?,'pending')")->execute([$uid,$addr]);db()->prepare("UPDATE users SET kyc_status='under_review' WHERE id=?")->execute([$uid]);notify_user($uid,'o. Tier 3 Submitted','Documents under review. We\'ll notify within 24 hours.','info');return['success'=>true,'message'=>'Documents submitted. You\'ll be notified within 24 hours.'];}
    public function get_limits(int $level):array{static $l=[0=>['daily'=>0,'single'=>0,'withdraw'=>false,'note'=>'Fund & receive only'],1=>['daily'=>50000,'single'=>20000,'withdraw'=>true,'note'=>'NIN Verified'],2=>['daily'=>200000,'single'=>100000,'withdraw'=>true,'note'=>'BVN Verified'],3=>['daily'=>5000000,'single'=>1000000,'withdraw'=>true,'note'=>'Fully Verified']];$lv=$level>3?3:max(0,$level);$l[$lv]['daily']=(float)setting('kyc_tier'.($lv>0?$lv:'0').'_limit_daily',(string)($l[$lv]['daily']));$l[$lv]['single']=(float)setting('kyc_tier'.($lv>0?$lv:'0').'_limit_single',(string)($l[$lv]['single']));return $l[$lv];}
}
function verify_nin(int $uid,array $input):array{return(new KYCService())->verify_nin($uid,$input);}
function verify_bvn(int $uid,array $input):array{return(new KYCService())->verify_bvn($uid,$input);}
function submit_tier3_kyc(int $uid,array $input):array{return(new KYCService())->submit_tier3($uid,$input);}
function get_kyc_limits(int $level):array{return(new KYCService())->get_limits($level);}
