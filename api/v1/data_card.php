<?php
define('IS_API', true);
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 2) . '/user/core/data_card.php';
require_once dirname(__DIR__, 2) . '/user/core/data.php';

$user = api_auth();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $net = strtoupper($_GET['net'] ?? 'MTN');
    if (!in_array($net, ['MTN', 'GLO', 'AIRTEL', '9MOBILE'])) $net = 'MTN';
    $type = $_GET['type'] ?? '';
    $plans = get_data_plans($net, $type);
    $types = get_data_types($net);
    echo json_encode([
        'status' => 'success',
        'data' => ['plans' => $plans, 'types' => $types]
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed. Use POST or GET.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if (empty($input['plan_id']) || empty($input['pin'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields: plan_id, pin']);
    exit;
}

$input['quantity'] = (int)($input['quantity'] ?? 1);

// Ensure table exists
db()->exec("CREATE TABLE IF NOT EXISTS data_cards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    transaction_ref VARCHAR(60) NOT NULL,
    network VARCHAR(20) DEFAULT NULL,
    plan_name VARCHAR(100) DEFAULT NULL,
    quantity TINYINT(2) NOT NULL DEFAULT 1,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    pins TEXT DEFAULT NULL,
    status ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    KEY transaction_ref (transaction_ref)
)");

$res = process_data_card((int)$user['id'], $input);

if ($res['success']) {
    $u_bal = db()->prepare("SELECT wallet_balance FROM users WHERE id=?");
    $u_bal->execute([$user['id']]);
    $updated = $u_bal->fetch();
    echo json_encode([
        'status' => 'success',
        'message' => $res['message'],
        'data' => [
            'reference' => $res['ref'] ?? null,
            'pins' => $res['pins'] ?? [],
            'balance_before' => (float)$user['wallet_balance'],
            'balance_after' => (float)($updated['wallet_balance'] ?? 0)
        ]
    ]);
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $res['message']]);
}
