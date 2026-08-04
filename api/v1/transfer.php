<?php
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 2) . '/user/core/bank_transfer.php';
require_once dirname(__DIR__, 2) . '/user/core/internal_transfer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Method Not Allowed. Use POST.']);
    exit;
}

$user = api_auth();
$uid = (int)$user['id'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

// ============ GET BANKS ============
if ($action === 'get_banks') {
    $exclude_codes = ['000011', '000017']; // Moniepoint, Wema Bank
    $banks = [];
    $raw = get_palmpay_banks();
    foreach ($raw as $b) {
        $name = $b['bankName'] ?? '';
        $code = $b['bankCode'] ?? '';
        if ($name && $code && !in_array($code, $exclude_codes)) {
            $banks[] = ['code' => $code, 'name' => $name, 'short_name' => $name];
        }
    }

    // Fallback: hardcoded list if PalmPay API fails
    if (empty($banks)) {
        $banks = [
            ['code' => '000014', 'name' => 'Access Bank', 'short_name' => 'Access'],
            ['code' => '000010', 'name' => 'Ecobank', 'short_name' => 'Ecobank'],
            ['code' => '000003', 'name' => 'First Bank', 'short_name' => 'FirstBank'],
            ['code' => '000013', 'name' => 'GTBank', 'short_name' => 'GTCO'],
            ['code' => '000002', 'name' => 'Keystone Bank', 'short_name' => 'Keystone'],
            ['code' => '000023', 'name' => 'Providus Bank', 'short_name' => 'Providus'],
            ['code' => '000001', 'name' => 'Sterling Bank', 'short_name' => 'Sterling'],
            ['code' => '000004', 'name' => 'UBA', 'short_name' => 'UBA'],
            ['code' => '000005', 'name' => 'Unity Bank', 'short_name' => 'Unity'],
            ['code' => '000015', 'name' => 'Zenith Bank', 'short_name' => 'Zenith'],
            ['code' => '100033', 'name' => 'PalmPay', 'short_name' => 'PalmPay'],
            ['code' => '100004', 'name' => 'Opay', 'short_name' => 'Opay'],
            ['code' => '090267', 'name' => 'Kuda Bank', 'short_name' => 'Kuda'],
            ['code' => '000008', 'name' => 'Fidelity Bank', 'short_name' => 'Fidelity'],
            ['code' => '000019', 'name' => 'Polaris Bank', 'short_name' => 'Polaris'],
            ['code' => '000018', 'name' => 'JAIZ Bank', 'short_name' => 'Jaiz'],
            ['code' => '000007', 'name' => 'Stanbic IBTC', 'short_name' => 'Stanbic'],
            ['code' => '000016', 'name' => 'FCMB', 'short_name' => 'FCMB'],
            ['code' => '000022', 'name' => 'Heritage Bank', 'short_name' => 'Heritage'],
        ];
    }

    echo json_encode([
        'status' => 'success',
        'success' => true,
        'banks' => $banks,
        'data' => ['banks' => $banks]
    ]);
    exit;
}

// ============ VALIDATE ACCOUNT ============
if ($action === 'validate_account') {
    $bank_code = $input['bank_code'] ?? '';
    $account_number = $input['account_number'] ?? '';

    if (empty($bank_code) || empty($account_number)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Missing bank_code or account_number.']);
        exit;
    }

    $name = '';

    // Try PalmPay validation (same as website)
    $palmpay_result = validate_bank_account($bank_code, $account_number);
    if ($palmpay_result['success']) {
        $name = $palmpay_result['name'];
    }

    // Try other API providers as fallback
    if (!$name) {
        $stmt = db()->prepare("SELECT * FROM api_providers WHERE service='bank_transfer' AND is_active=1 ORDER BY priority LIMIT 1");
        $stmt->execute();
        $provider = $stmt->fetch();

        if ($provider) {
            $pname = strtolower($provider['provider_name'] ?? '');
            $url = rtrim($provider['api_base_url'], '/');
            if (strpos($pname, 'autosyncng') !== false) $url .= '/api/v1/merchant-verify';
            else $url .= '/api/merchant-verify';

            $headers = ['Content-Type: application/json'];
            if (!empty($provider['api_key'])) $headers[] = 'Authorization: Token ' . $provider['api_key'];
            elseif (!empty($provider['api_token'])) $headers[] = 'Authorization: Bearer ' . $provider['api_token'];

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'bank_code' => $bank_code,
                    'account_number' => $account_number,
                    'service' => 'bank_transfer'
                ]),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($resp && $code === 200) {
                $data = json_decode($resp, true);
                $name = $data['data']['name'] ?? $data['name'] ?? $data['account_name'] ?? '';
            }
        }
    }

    if ($name) {
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'account_name' => $name,
            'data' => [
                'name' => $name,
                'full_name' => $name,
                'account_number' => $account_number,
                'bank_code' => $bank_code
            ]
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'success' => false,
            'message' => 'Could not verify account. Check details.'
        ]);
    }
    exit;
}

// ============ VALIDATE INTERNAL ============
if ($action === 'validate_internal') {
    $recipient = trim($input['recipient'] ?? '');

    if (empty($recipient)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Missing recipient.']);
        exit;
    }

    $phone_norm = $recipient;
    if (str_starts_with($phone_norm, '0')) $phone_norm = '234' . substr($phone_norm, 1);

    $stmt = db()->prepare("SELECT full_name, phone, email FROM users WHERE (phone = ? OR username = ? OR email = ? OR phone = ?) AND is_active = 1 LIMIT 1");
    $stmt->execute([$recipient, $recipient, $recipient, $phone_norm]);
    $found = $stmt->fetch();

    if ($found) {
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'account_name' => $found['full_name'],
            'data' => [
                'full_name' => $found['full_name'],
                'name' => $found['full_name'],
                'phone' => $found['phone']
            ]
        ]);
    } else {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => 'User not found.']);
    }
    exit;
}

// ============ BANK TRANSFER ============
if ($action === 'bank_transfer') {
    $bank_code = $input['bank_code'] ?? '';
    $account_number = $input['account_number'] ?? '';
    $amount = $input['amount'] ?? '';
    $payee_name = $input['payee_name'] ?? '';

    if (empty($bank_code) || empty($account_number) || empty($amount)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Missing required fields.']);
        exit;
    }

    $res = process_bank_transfer($uid, [
        'bank_code' => $bank_code,
        'account_number' => $account_number,
        'amount' => $amount,
        'payee_name' => $payee_name,
        'pin' => $input['pin'] ?? '',
        'narration' => $input['narration'] ?? ''
    ]);

    if ($res['success']) {
        $tx_status = 'pending';
        if (!empty($res['ref'])) {
            $st = db()->prepare("SELECT status FROM transactions WHERE transaction_ref = ? LIMIT 1");
            $st->execute([$res['ref']]);
            $tx_row = $st->fetch();
            if ($tx_row) $tx_status = $tx_row['status'];
        }
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'message' => $res['message'],
            'reference' => $res['ref'] ?? null,
            'ref' => $res['ref'] ?? null,
            'transaction_status' => $tx_status,
            'data' => [
                'reference' => $res['ref'] ?? null,
                'balance_before' => (float)($res['balance_before'] ?? $user['wallet_balance']),
                'balance_after' => (float)($res['balance_after'] ?? 0),
                'transaction_status' => $tx_status
            ]
        ]);
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => $res['message']]);
    }
    exit;
}

// ============ INTERNAL TRANSFER ============
if ($action === 'internal_transfer') {
    $recipient = $input['recipient'] ?? '';
    $amount = $input['amount'] ?? '';

    if (empty($recipient) || empty($amount)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Missing required fields: recipient, amount']);
        exit;
    }

    $res = process_internal_transfer($uid, [
        'recipient' => $recipient,
        'amount' => $amount,
        'pin' => $input['pin'] ?? '',
        'narration' => $input['narration'] ?? ''
    ]);

    if ($res['success']) {
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'message' => $res['message'],
            'reference' => $res['ref'] ?? null,
            'ref' => $res['ref'] ?? null,
            'data' => [
                'reference' => $res['ref'] ?? null,
                'balance_before' => (float)($res['balance_before'] ?? $user['wallet_balance']),
                'balance_after' => (float)($res['balance_after'] ?? 0),
                'recipient_name' => $res['recipient_name'] ?? ''
            ]
        ]);
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => $res['message']]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Unknown action. Valid: get_banks, validate_account, validate_internal, bank_transfer, internal_transfer']);
