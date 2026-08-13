<?php
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed. Use GET.']);
    exit;
}

$user = api_auth();
$uid = (int)$user['id'];

$ref_code = $user['referral_code'] ?: $user['phone'];
$ref_link = site_url('/user/signup?ref=' . urlencode($ref_code));

$tr = db()->prepare("SELECT COUNT(*) FROM users WHERE referred_by = ?");
$tr->execute([$uid]);
$total_referred = (int)$tr->fetchColumn();

$te = db()->prepare("SELECT COALESCE(SUM(reward_amount),0) FROM referral_rewards WHERE referrer_id = ? AND status = 'paid'");
$te->execute([$uid]);
$total_earned = (float)$te->fetchColumn();

$pe = db()->prepare("SELECT COALESCE(SUM(reward_amount),0) FROM referral_rewards WHERE referrer_id = ? AND status = 'pending'");
$pe->execute([$uid]);
$pending_earned = (float)$pe->fetchColumn();

$bonus_amt = (float)(db()->query("SELECT setting_value FROM site_settings WHERE setting_key='referral_bonus'")->fetchColumn() ?: 0);

$ru = db()->prepare("
    SELECT u.full_name, u.created_at, 
           (SELECT status FROM referral_rewards WHERE referred_id = u.id AND referrer_id = ? LIMIT 1) as bonus_status
    FROM users u 
    WHERE u.referred_by = ? 
    ORDER BY u.id DESC 
    LIMIT 50
");
$ru->execute([$uid, $uid]);
$referred_users = [];
while ($row = $ru->fetch()) {
    $referred_users[] = [
        'full_name' => $row['full_name'],
        'joined_date' => $row['created_at'],
        'bonus_status' => $row['bonus_status'] ?? 'awaiting_funding',
    ];
}

echo json_encode([
    'status' => 'success',
    'success' => true,
    'data' => [
        'referral_code' => $ref_code,
        'referral_link' => $ref_link,
        'bonus_amount' => $bonus_amt,
        'stats' => [
            'total_referred' => $total_referred,
            'total_earned' => $total_earned,
            'pending_earned' => $pending_earned,
        ],
        'referred_users' => $referred_users,
    ]
]);
