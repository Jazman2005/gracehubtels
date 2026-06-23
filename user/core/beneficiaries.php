<?php
require_once __DIR__.'/db.php';
function get_beneficiaries(int $uid,string $service=''):array{$w='WHERE user_id=?';$p=[$uid];if($service){$w.=' AND service=?';$p[]=$service;}$s=db()->prepare("SELECT * FROM beneficiaries $w ORDER BY is_favourite DESC,last_used DESC LIMIT 20");$s->execute($p);return $s->fetchAll();}
function save_beneficiary(int $uid,array $data):array{
    $svc=$data['service']??'';$label=sanitize($data['label']??'',100);$value=sanitize($data['value']??'',100);
    $network=sanitize($data['network']??'',20);$bank_code=sanitize($data['bank_code']??'',20);$bank_name=sanitize($data['bank_name']??'',100);$acct_name=sanitize($data['account_name']??'',200);
    if(!in_array($svc,['airtime','data','electricity','cabletv','bank_transfer']))return['success'=>false,'message'=>'Invalid service'];
    if(!$label||!$value)return['success'=>false,'message'=>'Label and value required'];
    $chk=db()->prepare("SELECT id FROM beneficiaries WHERE user_id=? AND service=? AND value=? LIMIT 1");$chk->execute([$uid,$svc,$value]);if($chk->fetch())return['success'=>false,'message'=>'Already saved'];
    $cnt=db()->prepare("SELECT COUNT(*) FROM beneficiaries WHERE user_id=?");$cnt->execute([$uid]);if((int)$cnt->fetchColumn()>=20)return['success'=>false,'message'=>'Maximum 20 beneficiaries'];
    db()->prepare("INSERT INTO beneficiaries (user_id,service,label,value,network,bank_code,bank_name,account_name) VALUES (?,?,?,?,?,?,?,?)")->execute([$uid,$svc,$label,$value,$network,$bank_code,$bank_name,$acct_name]);
    return['success'=>true,'message'=>'Beneficiary saved'];
}
function delete_beneficiary(int $uid,int $id):array{$s=db()->prepare("DELETE FROM beneficiaries WHERE id=? AND user_id=?");$s->execute([$id,$uid]);return$s->rowCount()?['success'=>true,'message'=>'Removed']:['success'=>false,'message'=>'Not found'];}
function toggle_fav(int $uid,int $id):array{db()->prepare("UPDATE beneficiaries SET is_favourite=NOT is_favourite WHERE id=? AND user_id=?")->execute([$id,$uid]);return['success'=>true];}
function touch_beneficiary(int $uid,int $id):void{db()->prepare("UPDATE beneficiaries SET last_used=NOW() WHERE id=? AND user_id=?")->execute([$id,$uid]);}
