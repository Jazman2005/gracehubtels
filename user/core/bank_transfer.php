<?php
require_once __DIR__.'/db.php'; require_once __DIR__.'/auth.php';
require_once __DIR__.'/palmpay.php';
class BankTransferService {
    public function get_banks():array{return get_palmpay_banks();}
    public function validate_account(string $bank_code,string $acct):array{$api=new PalmPayAPI();if(!$api->is_configured())return['success'=>false,'message'=>'Bank transfer not configured'];$res=$api->validate_account($bank_code,$acct);if($res&&($res['respCode']??'')===('00000000')&&($res['data']['Status']??'')===('Success'))return['success'=>true,'name'=>$res['data']['accountName']];return['success'=>false,'message'=>'Could not verify account. Check details.'];}
    public function process(int $uid,array $input):array{
        $bank=trim($input['bank_code']??'');$acct=sanitize_phone($input['account_number']??'');$amount=(float)($input['amount']??0);$payee=sanitize($input['payee_name']??'',200);$phone=sanitize_phone($input['phone']??'');$nar=sanitize($input['narration']??'',50);$pin=(string)($input['pin']??'');
        if(setting('palmpay_payout_enabled') !== '1') return ['success'=>false,'message'=>'Transfers are currently disabled by the administrator'];
        if(!$bank)return['success'=>false,'message'=>'Select a bank'];if(strlen($acct)!==10)return['success'=>false,'message'=>'Account number must be 10 digits'];
        $min=(float)setting('min_transfer_amount','100');$max=(float)setting('max_transfer_amount','1000000');if($amount<$min||$amount>$max)return['success'=>false,'message'=>"Amount ,".number_format($min,0)."–,".number_format($max,0)];
        if(detect_tamper($input))return['success'=>false,'message'=>'Invalid input'];if(!verify_pin($uid,$pin))return['success'=>false,'message'=>'Invalid transaction PIN'];
        $charge=(float)setting('transfer_charge','25');$total=$amount+$charge;$user=get_user($uid);
        $debit=debit_wallet($uid,$total,'bank_transfer',"Transfer ,{$amount} to {$payee} ({$acct})",['bank_code'=>$bank,'account_number'=>$acct,'charge'=>$charge,'narration'=>$nar]);if(!$debit['success'])return $debit;
        $api=new PalmPayAPI();$notify=(isset($_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'].'/webhook/palmpay/index.php';
        $res=$api->transfer($debit['ref'],$payee,$bank,$acct,$phone?:$user['phone'],$amount,$notify,$nar);
        if($res&&($res['respCode']??'')===('00000000')){update_tx_status($debit['ref'],'success',$res['data']['orderNo']??null);$cb=apply_cashback($uid,$amount,'bank_transfer',null);notify_user($uid,'o. Transfer Successful',",".number_format($amount,2)." sent to {$payee}.",'success');return['success'=>true,'message'=>",".number_format($amount,2)." transferred to {$payee}",'ref'=>$debit['ref'],'cashback'=>$cb];}
        update_tx_status($debit['ref'],'failed');credit_wallet($uid,$total,'bank_transfer','Transfer reversal');log_error('bank_transfer','Transfer failed',['response'=>$res,'uid'=>$uid]);return['success'=>false,'message'=>'Transfer failed: '.($res['respMsg']??'Try again').'. Wallet refunded.'];
    }
}
function process_bank_transfer(int $uid,array $input):array{return(new BankTransferService())->process($uid,$input);}
function validate_bank_account(string $bank_code,string $acct):array{return(new BankTransferService())->validate_account($bank_code,$acct);}
function get_banks_list():array{return(new BankTransferService())->get_banks();}
