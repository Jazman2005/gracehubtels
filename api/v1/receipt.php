<?php
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Method Not Allowed. Use GET.']);
    exit;
}

$user = api_auth();
$uid = (int)$user['id'];

$ref = $_GET['reference'] ?? $_GET['ref'] ?? '';
$tx_id = $_GET['transaction_id'] ?? $_GET['id'] ?? 0;

if (!empty($ref)) {
    $stmt = db()->prepare("SELECT * FROM transactions WHERE user_id = ? AND (reference = ? OR transaction_ref = ?) LIMIT 1");
    $stmt->execute([$uid, $ref, $ref]);
} elseif (!empty($tx_id)) {
    $stmt = db()->prepare("SELECT * FROM transactions WHERE user_id = ? AND id = ? LIMIT 1");
    $stmt->execute([$uid, (int)$tx_id]);
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Missing reference or transaction_id.']);
    exit;
}

$tx = $stmt->fetch();

if (!$tx) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Transaction not found.']);
    exit;
}

$ref = $tx['transaction_ref'] ?? $tx['reference'] ?? '';
$meta = json_decode($tx['meta'] ?? '{}', true) ?: [];
$pins = [];
$pin_stmt = db()->prepare("SELECT pins FROM data_cards WHERE transaction_ref = ? LIMIT 1");
$pin_stmt->execute([$ref]);
$pin_row = $pin_stmt->fetch();
if ($pin_row) {
    $pins = json_decode($pin_row['pins'], true) ?? [];
}
if (empty($pins)) {
    $pin_stmt2 = db()->prepare("SELECT pins_data FROM recharge_cards WHERE transaction_ref = ? LIMIT 1");
    $pin_stmt2->execute([$ref]);
    $pin_row2 = $pin_stmt2->fetch();
    if ($pin_row2) {
        $pins = json_decode($pin_row2['pins_data'], true) ?? [];
    }
}

echo json_encode([
    'status' => 'success',
    'success' => true,
    'data' => [
        'id' => (int)$tx['id'],
        'reference' => $tx['reference'] ?? $tx['transaction_ref'] ?? '',
        'transaction_ref' => $tx['transaction_ref'] ?? $tx['reference'] ?? '',
        'category' => $tx['category'] ?? $tx['type'] ?? '',
        'type' => $tx['type'] ?? 'debit',
        'amount' => (float)$tx['amount'],
        'status' => $tx['status'] ?? 'pending',
        'description' => $tx['description'] ?? '',
        'phone' => $tx['phone'] ?? '',
        'balance_before' => (float)($tx['balance_before'] ?? 0),
        'balance_after' => (float)($tx['balance_after'] ?? 0),
        'created_at' => $tx['created_at'] ?? '',
        'pins' => $pins,
        'token' => $meta['token'] ?? null,
        'meta' => $meta
    ]
]);
