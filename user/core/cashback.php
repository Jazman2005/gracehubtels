<?php
require_once __DIR__.'/db.php';

function get_cashback_history(int $user_id, int $limit = 50): array {
    $stmt = db()->prepare(
        "SELECT * FROM transactions 
         WHERE user_id = ? AND category = 'cashback' 
         ORDER BY created_at DESC LIMIT ?"
    );
    $stmt->execute([$user_id, $limit]);
    return $stmt->fetchAll();
}

function get_cashback_withdrawals(int $user_id, int $limit = 50): array {
    $stmt = db()->prepare(
        "SELECT * FROM transactions 
         WHERE user_id = ? AND category = 'cashback_withdrawal' AND type = 'debit'
         ORDER BY created_at DESC LIMIT ?"
    );
    $stmt->execute([$user_id, $limit]);
    return $stmt->fetchAll();
}

function process_cashback_withdrawal(int $user_id, array $post): array {
    $amount = (float)($post['amount'] ?? 0);
    $pin = $post['pin'] ?? '';

    if ($amount <= 0) return ['success' => false, 'message' => 'Invalid amount'];
    if ($amount < 100) return ['success' => false, 'message' => 'Minimum withdrawal is ₦100'];
    if (empty($pin)) return ['success' => false, 'message' => 'Transaction PIN is required'];

    $user = get_user($user_id);
    if (!$user) return ['success' => false, 'message' => 'User not found'];
    if (!verify_pin($user_id, $pin)) return ['success' => false, 'message' => 'Invalid transaction PIN'];

    $cb_balance = (float)$user['cashback_balance'];
    if ($amount > $cb_balance) return ['success' => false, 'message' => 'Insufficient cashback balance'];

    try {
        db()->beginTransaction();

        // Lock user record
        $stmt = db()->prepare("SELECT cashback_balance FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$user_id]);
        $locked = $stmt->fetch();

        if (!$locked || (float)$locked['cashback_balance'] < $amount) {
            db()->rollBack();
            return ['success' => false, 'message' => 'Insufficient cashback balance'];
        }

        $cb_before = (float)$locked['cashback_balance'];
        $cb_after = $cb_before - $amount;

        // Debit cashback balance
        db()->prepare("UPDATE users SET cashback_balance = ? WHERE id = ?")
            ->execute([$cb_after, $user_id]);

        // Credit main wallet
        $wallet_stmt = db()->prepare("SELECT wallet_balance FROM users WHERE id = ? FOR UPDATE");
        $wallet_stmt->execute([$user_id]);
        $wallet = $wallet_stmt->fetch();
        $wallet_before = (float)$wallet['wallet_balance'];
        $wallet_after = $wallet_before + $amount;

        db()->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?")
            ->execute([$wallet_after, $user_id]);

        // Record cashback debit (from cashback balance)
        $cb_ref = generate_ref('CBW');
        db()->prepare(
            "INSERT INTO transactions (user_id, transaction_ref, type, category, amount, balance_before, balance_after, description, status, ip_address) 
             VALUES (?, ?, 'debit', 'cashback_withdrawal', ?, ?, ?, 'Cashback withdrawal to wallet', 'success', ?)"
        )->execute([$user_id, $cb_ref, $amount, $cb_before, $cb_after, get_ip()]);

        // Record wallet credit
        $wallet_ref = generate_ref('MSJ');
        db()->prepare(
            "INSERT INTO transactions (user_id, transaction_ref, type, category, amount, balance_before, balance_after, description, status, ip_address) 
             VALUES (?, ?, 'credit', 'cashback_withdrawal', ?, ?, ?, 'Cashback withdrawal credited to wallet', 'success', ?)"
        )->execute([$user_id, $wallet_ref, $amount, $wallet_before, $wallet_after, get_ip()]);

        db()->commit();

        notify_user($user_id, 'Cashback Withdrawn', '₦'.number_format($amount,2).' cashback has been moved to your wallet', 'success', null, $wallet_before, $wallet_after);

        return [
            'success' => true,
            'message' => '₦'.number_format($amount,2).' cashback withdrawn to wallet successfully!',
            'ref' => $cb_ref,
            'cashback_before' => $cb_before,
            'cashback_after' => $cb_after,
            'wallet_before' => $wallet_before,
            'wallet_after' => $wallet_after
        ];

    } catch (Exception $e) {
        db()->rollBack();
        log_error('cashback', 'WITHDRAWAL_ERROR: '.$e->getMessage());
        return ['success' => false, 'message' => 'System error processing withdrawal'];
    }
}
