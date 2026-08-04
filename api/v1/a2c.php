<?php
define('IS_API', true);
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 2) . '/user/core/airtime_to_cash.php';

$user = api_auth();

// GET — return rates, receiving numbers, autosyncng networks, and settings
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rates = [];
    $stmt = db()->prepare("SELECT network, discount_percent, is_active FROM service_pricing WHERE service='airtime_to_cash' ORDER BY FIELD(network,'MTN','GLO','AIRTEL','9MOBILE')");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rates[] = $row;
    }
    $svc = new AirtimeToCashService();
    $nets = ['MTN','GLO','AIRTEL','9MOBILE'];
    $receiving_numbers = [];
    $auto_nets = db()->prepare("SELECT network FROM api_providers WHERE service='airtime_to_cash' AND is_active=1");$auto_nets->execute();
    $autosyncng_nets = [];$an=[];while($ar=$auto_nets->fetch()){$an[$ar['network']]=true;}
    foreach ($nets as $n) {
        $num = $svc->get_our_number($n);
        if ($num) $receiving_numbers[$n] = $num;
        if (!empty($an[$n])) $autosyncng_nets[] = $n;
    }
    echo json_encode([
        'success' => true,
        'rates' => $rates,
        'receiving_numbers' => $receiving_numbers,
        'autosyncng_networks' => $autosyncng_nets,
        'min_amount' => (float)setting('min_a2c_amount', '500'),
        'max_amount' => (float)setting('max_a2c_amount', '50000'),
    ]);
    exit;
}

// POST handling
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed. Use GET or POST.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? 'submit';

// OTP Complete
if ($action === 'complete_otp') {
    $ref = trim($input['ref'] ?? '');
    $otp = trim($input['otp'] ?? '');
    if (empty($ref) || empty($otp)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing required fields: ref, otp']);
        exit;
    }
    $res = complete_a2c_with_otp($ref, $otp, (int)$user['id']);
    if ($res['success']) {
        echo json_encode(['status' => 'success', 'success' => true, 'message' => $res['message']]);
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => $res['message']]);
    }
    exit;
}

// OTP Resend
if ($action === 'resend_otp') {
    $ref = trim($input['ref'] ?? '');
    if (empty($ref)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing required field: ref']);
        exit;
    }
    $res = resend_a2c_otp($ref, (int)$user['id']);
    if ($res['success']) {
        echo json_encode(['status' => 'success', 'success' => true, 'message' => $res['message']]);
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => $res['message']]);
    }
    exit;
}

// Submit A2C (default action)
if (empty($input['network']) || empty($input['sender_phone']) || empty($input['airtime_amount'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields: network, sender_phone, airtime_amount']);
    exit;
}

$res = submit_airtime_to_cash((int)$user['id'], $input);

if ($res['success']) {
    $data = [
        'reference' => $res['ref'] ?? null,
        'cash_expected' => $res['cash_amount'] ?? 0,
        'a2c_status' => $res['status'] ?? 'pending',
    ];
    if (!empty($res['requires_otp'])) {
        $data['requires_otp'] = true;
    } else {
        $data['transfer_to'] = $res['our_number'] ?? '';
    }
    echo json_encode([
        'status' => 'success',
        'success' => true,
        'message' => $res['message'],
        'data' => $data
    ]);
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => $res['message']]);
}
