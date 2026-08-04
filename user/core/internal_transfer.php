<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/auth.php';

function resolve_recipient(string $identifier, int $sender_id): array {
    $identifier = trim($identifier);
    if(empty($identifier)) return ['success'=>false, 'message'=>'Please enter username, email or phone'];
    
    // Normalize phone if starts with 0
    $phone_norm = $identifier;
    if(str_starts_with($phone_norm, '0')) $phone_norm = '234' . substr($phone_norm, 1);
    
    $stmt = db()->prepare("SELECT id, full_name, username FROM users WHERE (username=? OR email=? OR phone=? OR phone=?) AND is_active=1 LIMIT 1");
    $stmt->execute([$identifier, $identifier, $identifier, $phone_norm]);
    $u = $stmt->fetch();
    
    if(!$u) return ['success'=>false, 'message'=>'Recipient not found or account is inactive'];
    if($u['id'] == $sender_id) return ['success'=>false, 'message'=>'You cannot transfer to yourself'];
    
    return [
        'success' => true,
        'user_id' => $u['id'],
        'full_name' => $u['full_name'],
        'username' => $u['username']
    ];
}

function process_internal_transfer(int $uid, array $post): array {
    $identifier = trim($post['recipient'] ?? '');
    $amount = (float)($post['amount'] ?? 0);
    $pin = $post['pin'] ?? '';
    
    if($amount < 50) return ['success'=>false, 'message'=>'Minimum transfer is ₦50'];
    if(empty($pin)) return ['success'=>false, 'message'=>'Transaction PIN is required'];
    $limit_err = check_daily_limit($uid, $amount, 'internal_transfer');
    if($limit_err) return['success'=>false,'message'=>$limit_err];
    
    $rec_res = resolve_recipient($identifier, $uid);
    if(!$rec_res['success']) return $rec_res;
    
    $rec_id = $rec_res['user_id'];
    $rec_name = $rec_res['full_name'];
    
    $sender = get_user($uid);
    if(!$sender) return ['success'=>false, 'message'=>'User not found'];
    
    if(!verify_pin($uid, $pin)) return ['success'=>false, 'message'=>'Invalid transaction PIN'];
    if($sender['wallet_balance'] < $amount) return ['success'=>false, 'message'=>'Insufficient wallet balance'];
    
    // Check for fee setting
    $fee = 0.0;
    if (setting('p2p_transfer_charge_enabled') === '1') {
        $fee = (float)setting('p2p_transfer_charge', '0');
    }
    $total_deduction = $amount + $fee;
    
    if($sender['wallet_balance'] < $total_deduction) return ['success'=>false, 'message'=>"Insufficient balance to cover transfer and fee (₦$fee)"];
    
    try {
        db()->beginTransaction();
        
        $desc_sender = "Transfer to $rec_name";
        if($fee > 0) $desc_sender .= " + ₦$fee fee";
        
        $debit = debit_wallet($uid, $total_deduction, 'wallet_transfer', $desc_sender, [
            'recipient_id' => $rec_id,
            'amount_sent' => $amount,
            'fee' => $fee
        ]);
        
        if(!$debit['success']) {
            db()->rollBack();
            return $debit;
        }
        
        $desc_receiver = "Transfer from " . ($sender['username'] ?: $sender['full_name']);
        $credit = credit_wallet($rec_id, $amount, 'wallet_transfer', $desc_receiver, [
            'sender_id' => $uid,
            'external_ref' => $debit['ref']
        ]);
        
        if(!$credit['success']) {
            update_tx_status($debit['ref'], 'failed');
            db()->rollBack();
            return ['success'=>false, 'message'=>'Failed to credit recipient'];
        }
        
        update_tx_status($debit['ref'], 'success');
        db()->commit();
        
        // Notify recipient
        db()->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,?)")
           ->execute([$rec_id, 'Transfer Received', "You have received ₦".number_format($amount,2)." from ".($sender['full_name']).".", 'success']);
           
        return [
            'success' => true,
            'message' => "Successfully transferred ₦".number_format($amount,2)." to $rec_name",
            'ref' => $debit['ref'] ?? null,
            'balance_before' => $debit['balance_before'] ?? null,
            'balance_after' => $debit['balance_after'] ?? null,
            'recipient_name' => $rec_name
        ];
        
    } catch(Exception $e) {
        db()->rollBack();
        log_error('transfer', 'INTERNAL_TRANSFER_EXCEPTION', ['uid'=>$uid, 'error'=>$e->getMessage()]);
        return ['success'=>false, 'message'=>'System error processing transfer'];
    }
}
