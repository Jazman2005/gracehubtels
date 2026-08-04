<?php
// POST /api/v1/promo.php — Claim a promo code
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed. Use POST.']);
    exit;
}

$user = api_auth();
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

if ($action === 'claim') {
    $code = strtoupper(sanitize($input['code'] ?? '', 50));
    if (!$code) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Please enter a promo code']);
        exit;
    }

    try {
        $promo = db()->prepare("SELECT * FROM promo_codes WHERE code=?");
        $promo->execute([$code]);
        $promo = $promo->fetch();

        if (!$promo) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid promo code']);
            exit;
        }
        if ($promo['expires_at'] && strtotime($promo['expires_at']) < time()) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'This promo code has expired']);
            exit;
        }
        if ($promo['used_count'] >= $promo['usage_limit']) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'This promo code has reached its usage limit']);
            exit;
        }

        $db = db();
        $db->beginTransaction();
        try {
            // Check if already claimed
            $chk = $db->prepare("SELECT id FROM promo_claims WHERE user_id=? AND promo_id=?");
            $chk->execute([$user['id'], $promo['id']]);
            if ($chk->fetchColumn()) {
                $db->rollBack();
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'You have already claimed this promo code']);
                exit;
            }

            // Claim it
            $db->prepare("INSERT INTO promo_claims (user_id, promo_id) VALUES (?, ?)")->execute([$user['id'], $promo['id']]);
            $db->prepare("UPDATE promo_codes SET used_count = used_count + 1 WHERE id=?")->execute([$promo['id']]);

            // Credit wallet
            $credit = credit_wallet($user['id'], (float)$promo['amount'], 'promo_claim', "Claimed Promo Code: $code");
            if (!$credit['success']) {
                throw new Exception($credit['message']);
            }

            $db->commit();
            echo json_encode([
                'status' => 'success',
                'message' => "Promo code claimed! \u20A6" . number_format($promo['amount'], 2) . " has been added to your wallet.",
                'amount' => (float)$promo['amount'],
                'code' => $code
            ]);
            exit;
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'An error occurred: ' . $e->getMessage()]);
            exit;
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database error.']);
        exit;
    }
} elseif ($action === 'history') {
    $claims = db()->prepare(
        "SELECT c.created_at, p.code, p.amount FROM promo_claims c JOIN promo_codes p ON c.promo_id=p.id WHERE c.user_id=? ORDER BY c.id DESC LIMIT 20"
    );
    $claims->execute([$user['id']]);
    $rows = $claims->fetchAll();
    echo json_encode(['status' => 'success', 'data' => $rows]);
    exit;
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Unknown action. Use claim or history.']);
    exit;
}
