<?php
define('IS_API', true);
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 2) . '/user/core/recharge_card.php';

$user = api_auth();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'status' => 'success',
        'data' => [
            'networks' => [
                ['id' => 1, 'name' => 'MTN'],
                ['id' => 2, 'name' => 'GLO'],
                ['id' => 3, 'name' => 'AIRTEL'],
                ['id' => 4, 'name' => '9MOBILE']
            ],
            'denominations' => [100, 200, 500, 1000]
        ]
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed. Use POST or GET.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if (empty($input['network_id']) || empty($input['denomination']) || empty($input['pin'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields: network_id, denomination, pin']);
    exit;
}

$input['quantity'] = (int)($input['quantity'] ?? 1);

// Ensure table exists and migrate old column names
try { db()->exec("ALTER TABLE recharge_cards CHANGE COLUMN business_name name_on_card VARCHAR(100) DEFAULT NULL"); } catch (\Throwable $e) {}
try { db()->exec("ALTER TABLE recharge_cards CHANGE COLUMN pins pins_data TEXT DEFAULT NULL"); } catch (\Throwable $e) {}
db()->exec("CREATE TABLE IF NOT EXISTS recharge_cards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    transaction_ref VARCHAR(60) NOT NULL,
    network VARCHAR(20) NOT NULL,
    denomination DECIMAL(10,2) NOT NULL,
    quantity TINYINT(2) NOT NULL DEFAULT 1,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    name_on_card VARCHAR(100) DEFAULT NULL,
    pins_data TEXT DEFAULT NULL,
    status ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    KEY transaction_ref (transaction_ref)
)");

$res = process_recharge_card((int)$user['id'], $input);

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
