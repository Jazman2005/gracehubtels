<?php
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 2) . '/user/core/dashboard.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed. Use GET.']);
    exit;
}

$user = api_auth();
$data = get_dashboard_data((int)$user['id']);

// Marquee & modal notifications
$announcements = [];
$modal_announcement = null;
$target_role = $data['user']['user_type'] ?? 'all';

try {
    $mq = db()->prepare("SELECT title, message FROM notifications WHERE user_id IS NULL AND is_active = 1 AND display_type = 'marquee' AND (target_role = 'all' OR target_role = ?) ORDER BY created_at DESC LIMIT 5");
    $mq->execute([$target_role]);
    $marquee_rows = $mq->fetchAll();
    $marquee = '';
    if (!empty($marquee_rows)) {
        $parts = [];
        foreach ($marquee_rows as $n) {
            $item = $n['title'] . ': ' . $n['message'];
            $parts[] = $item;
            $announcements[] = $item;
        }
        $marquee = implode('   |   ', $parts);
    }

    $md = db()->prepare("SELECT title, message, type FROM notifications WHERE user_id IS NULL AND is_active = 1 AND display_type = 'modal' AND (target_role = 'all' OR target_role = ?) ORDER BY created_at DESC LIMIT 1");
    $md->execute([$target_role]);
    $modal_row = $md->fetch();
    if ($modal_row) {
        $modal_announcement = [
            'title' => $modal_row['title'],
            'message' => $modal_row['message'],
            'type' => $modal_row['type'] ?? 'info',
        ];
    }
} catch (Exception $e) {
    error_log("Notification query failed (is_active missing?): " . $e->getMessage());
}

echo json_encode([
    'status' => 'success',
    'success' => true,
    'data' => [
        'user' => [
            'id' => (int)$data['user']['id'],
            'full_name' => $data['user']['full_name'],
            'email' => $data['user']['email'],
            'phone' => $data['user']['phone'],
            'wallet_balance' => (float)$data['user']['wallet_balance'],
            'usd_balance' => (float)($data['usd_balance'] ?? 0),
            'cashback_balance' => (float)$data['user']['cashback_balance'],
            'notif_count' => $data['notif_count'],
            'referral_code' => $data['user']['referral_code'] ?: $data['user']['phone'],
        ],
        'virtual_accounts' => $data['virtual_accounts'],
        'transactions' => $data['transactions'],
        'monthly_spend' => $data['monthly_spend'],
        'marquee_text' => $marquee ?? '',
        'announcements' => $announcements,
        'modal_announcement' => $modal_announcement,
    ]
]);
