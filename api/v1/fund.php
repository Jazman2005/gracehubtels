<?php
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 2) . '/user/core/virtual-account.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Method Not Allowed. Use POST.']);
    exit;
}

$user = api_auth();
$uid = (int)$user['id'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

$va_mgr = new VirtualAccountManager();

// ============ CREATE SPECIFIC BANK ACCOUNT ============
$bank_actions = [
    'create_pp_palmpay' => ['check' => 'paymentpoint_palmpay_enabled', 'parent' => 'paymentpoint_enabled', 'method' => 'create_paymentpoint_bank', 'args' => ['20946', 'PalmPay']],
    'create_pp_opay'    => ['check' => 'paymentpoint_opay_enabled', 'parent' => 'paymentpoint_enabled', 'method' => 'create_paymentpoint_bank', 'args' => ['20897', 'OPay']],
    'create_pv_palmpay' => ['check' => 'gw_payvessel_9psb', 'parent' => 'gw_payvessel_active', 'method' => 'create_payvessel_bank', 'args' => ['999991', 'PalmPay']],
    'create_pv_9payment' => ['check' => 'gw_payvessel_wema', 'parent' => 'gw_payvessel_active', 'method' => 'create_payvessel_bank', 'args' => ['120001', '9Payment Service Bank']],
    'create_pv_rubies'  => ['check' => 'gw_payvessel_rubies', 'parent' => 'gw_payvessel_active', 'method' => 'create_payvessel_bank', 'args' => ['090175', 'Rubies MFB']],
    'create_moniepoint' => ['check' => 'gw_moniepoint_active', 'parent' => null, 'method' => 'create_monnify_bank', 'args' => []],
];

if (isset($bank_actions[$action])) {
    $cfg = $bank_actions[$action];

    // Check parent gate is enabled
    if ($cfg['parent'] && setting($cfg['parent'], '0') !== '1') {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => 'This funding option is currently disabled by admin.']);
        exit;
    }

    // Check specific bank is enabled
    if (setting($cfg['check'], '0') !== '1') {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => 'This funding option is currently disabled by admin.']);
        exit;
    }

    $result = $va_mgr->{$cfg['method']}($uid, ...$cfg['args']);

    if ($result['success']) {
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'message' => $result['message'] ?? 'Account generated successfully.',
            'data' => $result['account'] ?? []
        ]);
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => $result['message'] ?? 'Failed to generate account.']);
    }
    exit;
}

// ============ CREATE DYNAMIC VA (via PalmPay API) ============
if ($action === 'create_dynamic_va') {
    if (setting('palmpay_dynamic_enabled', '0') !== '1') {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Dynamic funding is currently disabled by admin.']);
        exit;
    }

    $amount = (float)($input['amount'] ?? 0);

    if ($amount < 100) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Minimum amount is ₦100.']);
        exit;
    }

    try {
        require_once __DIR__ . '/../../user/core/palmpay.php';
        $api = new PalmPayAPI();
        if (!$api->is_configured()) {
            http_response_code(503);
            echo json_encode(['status' => 'error', 'success' => false, 'message' => 'PalmPay not configured.']);
            exit;
        }

        $order_id = 'DYN_' . time() . '_' . $uid;
        $notify_url = 'https://www.gracehubtels.com/webhook/palmpay/index.php';
        $res = $api->create_order($user, $amount, $order_id, $notify_url);

        if ($res && !empty($res['data']['payerVirtualAccNo'])) {
            $data = $res['data'];
            $api->save_virtual_account($uid, $data, 0, true);

            $fee_type = setting('palmpay_fee_type', 'percent');
            $fee_value = (float)setting('palmpay_fee_value', '1');

            echo json_encode([
                'status' => 'success',
                'success' => true,
                'message' => 'Dynamic account generated.',
                'account_number' => $data['payerVirtualAccNo'],
                'account_name' => $data['payerAccountName'] ?? $user['full_name'],
                'bank_name' => $data['payerBankName'] ?? 'PalmPay',
                'amount' => $amount,
                'fee_type' => $fee_type,
                'fee_value' => $fee_value,
                'orderId' => $data['orderNo'] ?? $order_id,
                'orderNo' => $data['orderNo'] ?? $order_id,
                'data' => [
                    'account_number' => $data['payerVirtualAccNo'],
                    'account_name' => $data['payerAccountName'] ?? $user['full_name'],
                    'bank_name' => $data['payerBankName'] ?? 'PalmPay',
                    'amount' => $amount,
                    'fee_type' => $fee_type,
                    'fee_value' => $fee_value,
                    'orderId' => $data['orderNo'] ?? $order_id,
                    'orderNo' => $data['orderNo'] ?? $order_id
                ]
            ]);
            exit;
        }

        http_response_code(502);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Failed to generate dynamic account: ' . ($res['respMsg'] ?? ($res['data']['respMsg'] ?? ($res['_http_code'] ?? 'Unknown error')))]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Server error generating dynamic account.']);
    }
    exit;
}

// ============ CHECK PAYMENT (with wallet credit) ============
if ($action === 'check_payment') {
    if (setting('palmpay_dynamic_enabled', '0') !== '1') {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Dynamic funding is currently disabled.']);
        exit;
    }

    $order_no = $input['order_no'] ?? $input['orderId'] ?? $input['reference'] ?? '';

    if (empty($order_no)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Missing order_no.']);
        exit;
    }

    // order_no comes straight from the request body. Without verifying it actually
    // belongs to the calling account, any authenticated user could poll another
    // user's dynamic-VA order number and have that deposit credited to their own
    // wallet instead of the rightful owner's.
    $va_owner = db()->prepare("SELECT user_id FROM virtual_accounts WHERE bank_code = ? AND provider = 'palmpay' LIMIT 1");
    $va_owner->execute([$order_no]);
    $va_owner_row = $va_owner->fetch();
    if (!$va_owner_row || (int)$va_owner_row['user_id'] !== $uid) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Order not found for this account.']);
        exit;
    }

    try {
        require_once __DIR__ . '/../../user/core/palmpay.php';
        $api = new PalmPayAPI();
        if (!$api->is_configured()) {
            http_response_code(503);
            echo json_encode(['status' => 'error', 'success' => false, 'message' => 'PalmPay not configured.']);
            exit;
        }

        $res = $api->query_order($order_no);
        $paid = $res && !empty($res['data']['orderStatus']) && (int)$res['data']['orderStatus'] === 2;

        if (!$paid) {
            echo json_encode([
                'status' => 'success', 'success' => true,
                'paid' => false, 'confirmed' => false,
                'data' => ['paid' => false, 'confirmed' => false]
            ]);
            exit;
        }

        // Payment confirmed — extract details and credit wallet
        $payment_data = $res['data'] ?? [];
        $palmpay_order_no = $payment_data['orderNo'] ?? $payment_data['orderId'] ?? $order_no;
        $amount_kobo = (int)($payment_data['orderAmount'] ?? $payment_data['amount'] ?? 0);
        $amount = $amount_kobo / 100;

        if ($amount <= 0) {
            echo json_encode([
                'status' => 'success', 'success' => true,
                'paid' => true, 'confirmed' => true, 'processed' => false,
                'message' => 'Amount invalid',
                'data' => ['paid' => true, 'confirmed' => true, 'processed' => false]
            ]);
            exit;
        }

        // Check if already processed. This SELECT is only a fast-path — the real
        // guard is the UNIQUE constraint on transactions.idempotency_key enforced
        // atomically inside credit_wallet() below. Shares the same key namespace
        // ("palmpay:<orderNo>") as webhook/palmpay/index.php so whichever of the
        // webhook or this polling endpoint fires first wins, and the other is
        // automatically treated as a duplicate rather than crediting twice.
        $idem_key = 'palmpay:' . $palmpay_order_no;
        $chk = db()->prepare("SELECT id FROM transactions WHERE idempotency_key = ? LIMIT 1");
        $chk->execute([$idem_key]);
        if ($chk->fetch()) {
            echo json_encode([
                'status' => 'success', 'success' => true,
                'paid' => true, 'confirmed' => true, 'processed' => true,
                'message' => 'Already credited',
                'data' => ['paid' => true, 'confirmed' => true, 'processed' => true]
            ]);
            exit;
        }

        // Calculate fee (same as website)
        $fee_type = setting('palmpay_fee_type', 'percent');
        $fee_value = (float)setting('palmpay_fee_value', '1');
        if ($fee_type === 'flat') {
            $fee = min($fee_value, $amount * 0.5);
        } else {
            $fee = ($amount * $fee_value) / 100;
        }
        $credit = $amount - $fee;

        if ($credit <= 0) {
            echo json_encode([
                'status' => 'success', 'success' => true,
                'paid' => true, 'confirmed' => true, 'processed' => false,
                'message' => 'Amount too small after fee',
                'data' => ['paid' => true, 'confirmed' => true, 'processed' => false]
            ]);
            exit;
        }

        // Credit wallet
        $result = credit_wallet($uid, $credit, 'wallet_funding', 'PalmPay Funding - ' . $palmpay_order_no, ['ref' => $palmpay_order_no], $idem_key);

        if (!empty($result['duplicate'])) {
            echo json_encode([
                'status' => 'success', 'success' => true,
                'paid' => true, 'confirmed' => true, 'processed' => true,
                'message' => 'Already credited',
                'data' => ['paid' => true, 'confirmed' => true, 'processed' => true]
            ]);
        } elseif ($result['success']) {
            // Deactivate the VA
            $vad = db()->prepare("SELECT id FROM virtual_accounts WHERE user_id=? AND bank_code=? AND provider='palmpay' LIMIT 1");
            $vad->execute([$uid, $palmpay_order_no]);
            $vaRow = $vad->fetch();
            if ($vaRow) {
                db()->prepare("UPDATE virtual_accounts SET is_active=0 WHERE id=?")->execute([$vaRow['id']]);
            }
            notify_user($uid, 'Wallet Funded', "Your wallet has been funded with \u20A6" . number_format($credit, 2) . " via PalmPay", 'info', null, $result['balance_before'], $result['balance_after']);

            echo json_encode([
                'status' => 'success', 'success' => true,
                'paid' => true, 'confirmed' => true, 'processed' => true,
                'message' => "Wallet credited \u20A6" . number_format($credit, 2),
                'data' => ['paid' => true, 'confirmed' => true, 'processed' => true]
            ]);
        } else {
            echo json_encode([
                'status' => 'success', 'success' => true,
                'paid' => true, 'confirmed' => true, 'processed' => false,
                'message' => 'Failed to credit wallet',
                'data' => ['paid' => true, 'confirmed' => true, 'processed' => false]
            ]);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Error checking payment status.']);
    }
    exit;
}

// ============ SUBMIT MANUAL FUNDING REQUEST ============
if ($action === 'submit_manual_funding') {
    $amount = (float)($input['amount'] ?? 0);
    $sender_name = sanitize($input['sender_name'] ?? '', 255);
    $sender_bank = sanitize($input['sender_bank'] ?? '', 100);

    if ($amount < 100) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Minimum amount is ₦100']);
        exit;
    }
    if (!$sender_name) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Sender name is required']);
        exit;
    }

    try {
        // Ensure table exists
        db()->exec("CREATE TABLE IF NOT EXISTS manual_funding_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            sender_name VARCHAR(255) NOT NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");

        db()->prepare("INSERT INTO manual_funding_requests (user_id, amount, sender_name, status) VALUES (?, ?, ?, 'pending')")
            ->execute([$uid, $amount, $sender_name . ($sender_bank ? " ($sender_bank)" : '')]);

        $u = get_user($uid);
        send_whatsapp_alert("💳 *New Manual Funding Request*\nUser: " . ($u['full_name'] ?? 'Unknown') . " (" . ($u['email'] ?? $u['phone'] ?? '') . ")\nAmount: ₦" . number_format($amount, 2) . "\nSender: " . htmlspecialchars($sender_name) . ($sender_bank ? "\nBank: " . htmlspecialchars($sender_bank) : ''));

        echo json_encode([
            'status' => 'success',
            'success' => true,
            'message' => 'Your funding request has been submitted! It will be approved shortly.',
            'data' => ['amount' => $amount, 'sender_name' => $sender_name]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => 'An error occurred. Please try again.']);
    }
    exit;
}

// ============ MANUAL FUNDING HISTORY ============
if ($action === 'manual_history') {
    db()->exec("CREATE TABLE IF NOT EXISTS manual_funding_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        amount DECIMAL(15,2) NOT NULL,
        sender_name VARCHAR(255) NOT NULL,
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    $reqs = db()->prepare(
        "SELECT id, amount, sender_name, status, created_at FROM manual_funding_requests WHERE user_id=? ORDER BY created_at DESC LIMIT 20"
    );
    $reqs->execute([$uid]);
    echo json_encode(['status' => 'success', 'success' => true, 'data' => $reqs->fetchAll()]);
    exit;
}

http_response_code(400);
echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Unknown action. Valid: create_pp_palmpay, create_pp_opay, create_pv_palmpay, create_pv_9payment, create_pv_rubies, create_moniepoint, create_dynamic_va, check_payment, submit_manual_funding, manual_history']);
