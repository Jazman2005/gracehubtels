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

    // No throttle here previously let an attacker script brute-force
    // enumeration of valid promo codes against a single account.
    if (!rate_limit('promo_claim_' . $user['id'], 10, 600)) {
        http_response_code(429);
        echo json_encode(['status' => 'error', 'message' => 'Too many attempts. Please try again later.']);
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
        // Many different accounts racing the same popular promo code all
        // contend for the same promo_codes row (the UPDATE below) and the
        // same FK-checked parent row (the promo_claims INSERT) -- proven via
        // load testing to genuinely trigger InnoDB deadlocks (error 1213),
        // which previously surfaced as a raw "an error occurred" failure
        // even for a claim that should have cleanly succeeded or been told
        // the limit was reached. A deadlock aborts the whole transaction
        // atomically (nothing partially applies), so it's safe to just
        // retry the same logic a few times before giving up.
        $maxAttempts = 3;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
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

            // Claim it. The used_count>=usage_limit check above ran before
            // this transaction started, so it's stale the moment two claims
            // for the same code race each other — gate the increment itself
            // on the row still being under its limit so only as many
            // concurrent claims as remaining slots can ever win, regardless
            // of how many different accounts race this at once.
            $db->prepare("INSERT INTO promo_claims (user_id, promo_id) VALUES (?, ?)")->execute([$user['id'], $promo['id']]);
            $claim = $db->prepare("UPDATE promo_codes SET used_count = used_count + 1 WHERE id=? AND used_count < usage_limit");
            $claim->execute([$promo['id']]);
            if ($claim->rowCount() === 0) {
                $db->rollBack();
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'This promo code has reached its usage limit']);
                exit;
            }

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
            $isDeadlock = $e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1213;
            if ($isDeadlock && $attempt < $maxAttempts) {
                usleep(random_int(20000, 80000)); // 20-80ms jitter before retrying
                continue;
            }
            log_error('promo', 'claim failed: ' . $e->getMessage(), ['user_id' => $user['id'], 'code' => $code, 'attempt' => $attempt]);
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'An error occurred. Please try again.']);
            exit;
        }
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
