<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function get_dashboard_data(int $user_id): array {
    $db = db();

    $user = get_user($user_id);
    if (!$user) return [];

    // Virtual accounts
    $va = $db->prepare("SELECT * FROM virtual_accounts WHERE user_id = ? AND is_active = 1 ORDER BY provider");
    $va->execute([$user_id]);
    $virtual_accounts = $va->fetchAll();

    // Recent transactions (last 10)
    $tx = $db->prepare(
        "SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 10"
    );
    $tx->execute([$user_id]);
    $transactions = $tx->fetchAll();

    // Unread notifications count
    $nc = $db->prepare(
        "SELECT COUNT(*) FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0"
    );
    $nc->execute([$user_id]);
    $notif_count = (int)$nc->fetchColumn();

    // Monthly spending
    $ms = $db->prepare(
        "SELECT COALESCE(SUM(amount),0) FROM transactions 
         WHERE user_id=? AND type='debit' AND status='success' 
         AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())"
    );
    $ms->execute([$user_id]);
    $monthly_spend = (float)$ms->fetchColumn();

    return compact('user','virtual_accounts','transactions','notif_count','monthly_spend');
}

function get_user_transactions(int $user_id, int $page=1, int $per_page=20, string $filter=''): array {
    $db = db();
    $offset = ($page - 1) * $per_page;
    $where = "WHERE user_id = ?";
    $params = [$user_id];

    if ($filter && in_array($filter, ['airtime','data','electricity','cabletv','airtime_to_cash','exam_pin','recharge_card','data_card','bank_transfer','wallet_funding','send_money'])) {
        $where .= " AND category = ?";
        $params[] = $filter;
    }

    $count_stmt = $db->prepare("SELECT COUNT(*) FROM transactions $where");
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetchColumn();

    $params[] = $per_page;
    $params[] = $offset;
    $stmt = $db->prepare("SELECT * FROM transactions $where ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    return [
        'data'       => $rows,
        'total'      => $total,
        'page'       => $page,
        'per_page'   => $per_page,
        'total_pages'=> (int)ceil($total / $per_page),
    ];
}

?>