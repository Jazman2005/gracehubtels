<?php
require_once __DIR__.'/db.php'; require_once __DIR__.'/auth.php';
require_once __DIR__.'/palmpay.php';
class BankTransferService {
    public function get_banks():array{return get_palmpay_banks();}
    public function validate_account(string $bank_code,string $acct):array{$api=new PalmPayAPI();if(!$api->is_configured())return['success'=>false,'message'=>'Bank transfer not configured'];$res=$api->validate_account($bank_code,$acct);if($res&&($res['respCode']??'')===('00000000')){$name=$res['data']['accountName']??$res['data']['account_name']??'';if($name)return['success'=>true,'name'=>$name];}return['success'=>false,'message'=>'Could not verify account. Check details.'];}
    public function process(int $uid,array $input):array{
      $debit = null;
      try {
        $bank=trim($input['bank_code']??'');$acct=sanitize_phone($input['account_number']??'');$amount=(float)($input['amount']??0);$payee=sanitize($input['payee_name']??'',200);$phone=sanitize_phone($input['phone']??'');$nar=sanitize($input['narration']??'',50);$pin=(string)($input['pin']??'');
        if(setting('palmpay_payout_enabled') !== '1') return ['success'=>false,'message'=>'Transfers are currently disabled by the administrator'];
        if(!$bank)return['success'=>false,'message'=>'Select a bank'];if(strlen($acct)!==10)return['success'=>false,'message'=>'Account number must be 10 digits'];
        $limit_err = check_daily_limit($uid, $amount, 'bank_transfer');
        if($limit_err) return['success'=>false,'message'=>$limit_err];
        if(detect_tamper($input))return['success'=>false,'message'=>'Invalid input'];if(!verify_pin($uid,$pin))return['success'=>false,'message'=>'Invalid transaction PIN'];
        if(!claim_purchase_lock($uid,'bank_transfer',[$bank,$acct,$amount]))return['success'=>false,'message'=>'Duplicate request detected. Please wait a moment and try again.'];
        $sig=purchase_signature('bank_transfer',[$bank,$acct,$amount]);
        $charge=(float)setting('transfer_charge','25');$total=$amount+$charge;$user=get_user($uid);
        $debit=debit_wallet($uid,$total,'bank_transfer',"₦{$amount} to {$payee} ({$acct})",['bank_code'=>$bank,'account_number'=>$acct,'payee_name'=>$payee,'charge'=>$charge,'narration'=>$nar,'pending_approval'=>1],$sig);if(!$debit['success'])return $debit;
        notify_user($uid,'Withdrawal Submitted',"Your withdrawal of ₦{$amount} to {$payee} ({$acct}) has been submitted for admin approval",'info',null,$debit['balance_before'],$debit['balance_after']);
        send_whatsapp_alert("New Withdrawal Request!\nUser: " . ($user['full_name'] ?? 'Unknown') . " (" . ($user['email'] ?? $user['phone'] ?? '') . ")\nAmount: ₦" . number_format($amount, 2) . "\nBank: {$bank}\nAccount: {$acct}\nPayee: {$payee}\nRef: " . ($debit['ref'] ?? 'N/A'));
        try{notify_admin('Withdrawal Request',"
<div style='text-align:center;padding:10px 0 16px;'>
<span style='font-size:28px;line-height:1;font-weight:800;color:#dc2626;'>$</span>
<h3 style='color:#4F46E5;font-size:18px;margin:8px 0 4px;'>New Withdrawal Request</h3>
<p style='color:#475569;font-size:13px;margin:0;'>A user has submitted a withdrawal request.</p>
</div>
<table style='width:100%;border-collapse:collapse;'>
<tr><td style='padding:8px 12px;background:#f8fafc;border-radius:8px;'>
<table width='100%'>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>User</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>".htmlspecialchars($user['full_name']??'Unknown',ENT_QUOTES)."</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Phone</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>".htmlspecialchars($user['phone']??'',ENT_QUOTES)."</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Amount</td><td style='padding:4px 0;text-align:right;font-weight:700;color:#dc2626;font-size:14px;'>₦".number_format($amount,2)."</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Charge</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>₦".number_format($charge,2)."</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Bank</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>".htmlspecialchars($bank,ENT_QUOTES)."</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Account</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>".htmlspecialchars($acct,ENT_QUOTES)."</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Payee</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>".htmlspecialchars($payee,ENT_QUOTES)."</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Ref</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>".htmlspecialchars($debit['ref']??'N/A',ENT_QUOTES)."</td></tr>
</table>
</td></tr>
</table>");}catch(\Throwable$e){}
        return['success'=>true,'message'=>"Withdrawal of ₦{$amount} submitted for approval.",'ref'=>$debit['ref'],'pending'=>true];
      } catch (\Throwable $e) {
        // Unlike the VTU-style services, this file never calls a provider
        // API and never refunds — a debited amount here is always correctly
        // queued 'pending' for manual admin approval (see withdrawals.php),
        // so an exception after a successful debit_wallet() call (e.g. a
        // notification send failing) does NOT leave the transaction in a
        // wrong state; it just means the user sees an error even though
        // their request was actually submitted correctly. Never refund here
        // — that would incorrectly cancel a legitimate pending withdrawal.
        log_error('bank_transfer', 'Purchase exception: ' . $e->getMessage(), ['line' => $e->getLine(), 'uid' => $uid]);
        if ($debit !== null && ($debit['success'] ?? false)) {
            return ['success' => true, 'message' => 'Your withdrawal request was submitted and is pending admin approval. Check your transaction history for updates.', 'ref' => $debit['ref'], 'pending' => true];
        }
        return ['success' => false, 'message' => 'Withdrawal request failed. Please try again.'];
      }
    }
}
function process_bank_transfer(int $uid,array $input):array{return(new BankTransferService())->process($uid,$input);}
function validate_bank_account(string $bank_code,string $acct):array{return(new BankTransferService())->validate_account($bank_code,$acct);}
function get_banks_list():array{return(new BankTransferService())->get_banks();}
