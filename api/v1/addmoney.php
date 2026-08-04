<?php
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Method Not Allowed. Use GET.']);
    exit;
}

$user = api_auth();
$uid = (int)$user['id'];

// Check Payment Gateway settings (same as user/fund.php on website)
$provider_active = [
    'moniepoint'    => setting('gw_moniepoint_active', '0') === '1',
    'monnify'       => setting('gw_moniepoint_active', '0') === '1',
    'palmpay'       => setting('palmpay_enabled', '0') === '1',
    'paymentpoint'  => setting('paymentpoint_enabled', '0') === '1',
    'payvessel'     => setting('gw_payvessel_active', '0') === '1',
];

$stmt = db()->prepare("SELECT bank_name, account_number, account_name, provider FROM virtual_accounts WHERE user_id = ? AND is_active = 1 ORDER BY provider");
$stmt->execute([$uid]);
$accounts = $stmt->fetchAll();

$virtual_accounts = [];
$pending_accounts = [];

// Fee lookup per provider (matches website's available_banks logic)
function get_fee_for_provider(string $provider, string $bank_name): array {
    $p = strtolower($provider);
    $bn = strtolower($bank_name);
    if ($p === 'moniepoint' || $p === 'monnify') {
        return ['fee_type' => setting('gw_moniepoint_fee_type', 'percent'), 'fee_value' => (float)setting('gw_moniepoint_fee_value', '1')];
    }
    if ($p === 'payvessel') {
        return ['fee_type' => setting('gw_payvessel_fee_type', 'percent'), 'fee_value' => (float)setting('gw_payvessel_fee_value', '1')];
    }
    if ($p === 'palmpay') {
        return ['fee_type' => setting('palmpay_fee_type', 'percent'), 'fee_value' => (float)setting('palmpay_fee_value', '1')];
    }
    if ($p === 'paymentpoint') {
        if (str_contains($bn, 'palmpay') || str_contains($bn, 'opalmpay')) {
            $ft = setting('paymentpoint_palmpay_fee_type', '');
            $fv = (float)setting('paymentpoint_palmpay_fee_value', '0');
            if ($ft) return ['fee_type' => $ft, 'fee_value' => $fv];
        } elseif (str_contains($bn, 'opay') || str_contains($bn, '9payment')) {
            $ft = setting('paymentpoint_opay_fee_type', '');
            $fv = (float)setting('paymentpoint_opay_fee_value', '0');
            if ($ft) return ['fee_type' => $ft, 'fee_value' => $fv];
        }
        // Global PaymentPoint fallback
        $pct = (float)setting('paymentpoint_charge_pct', '1');
        $cap = (float)setting('paymentpoint_charge_cap', '50');
        return ['fee_type' => 'percent_capped', 'fee_value' => $pct, 'fee_cap' => $cap];
    }
    return ['fee_type' => 'percent', 'fee_value' => 0];
}

foreach ($accounts as $acc) {
    $p = strtolower($acc['provider']);
    if (isset($provider_active[$p]) && !$provider_active[$p]) {
        continue;
    }
    $fee = get_fee_for_provider($acc['provider'], $acc['bank_name']);
    $virtual_accounts[] = [
        'bank_name' => $acc['bank_name'],
        'account_number' => $acc['account_number'],
        'account_name' => $acc['account_name'],
        'provider' => $acc['provider'],
        'fee_type' => $fee['fee_type'],
        'fee_value' => $fee['fee_value'],
        'fee_cap' => $fee['fee_cap'] ?? 0
    ];
}

// Build available banks from settings (matching website logic)
$pp_enabled = setting('paymentpoint_enabled', '0') === '1';
$pp_palmpay = $pp_enabled && setting('paymentpoint_palmpay_enabled', '0') === '1';
$pp_opay    = $pp_enabled && setting('paymentpoint_opay_enabled', '0') === '1';
$pv_enabled = setting('gw_payvessel_active', '0') === '1';
$pv_palmpay = $pv_enabled && setting('gw_payvessel_9psb', '1') === '1';
$pv_9payment = $pv_enabled && setting('gw_payvessel_wema', '1') === '1';
$pv_rubies  = $pv_enabled && setting('gw_payvessel_rubies', '1') === '1';
$mp_enabled = setting('gw_moniepoint_active', '0') === '1';

$bank_slug = [
    'pp_palmpay' => 'paymentpoint:palmpay',
    'pp_opay' => 'paymentpoint:opay',
    'pv_palmpay' => 'payvessel:palmpay',
    'pv_9payment' => 'payvessel:9psb',
    'pv_rubies' => 'payvessel:rubies',
    'mp' => 'moniepoint:moniepoint',
];

$all_available_banks = [];
if ($pp_palmpay) $all_available_banks['pp_palmpay'] = ['id'=>'pp_palmpay', 'action'=>'create_pp_palmpay', 'name'=>'PalmPay', 'provider'=>'PaymentPoint'];
if ($pp_opay)    $all_available_banks['pp_opay'] = ['id'=>'pp_opay', 'action'=>'create_pp_opay', 'name'=>'OPay', 'provider'=>'PaymentPoint'];
if ($pv_palmpay) $all_available_banks['pv_palmpay'] = ['id'=>'pv_palmpay', 'action'=>'create_pv_palmpay', 'name'=>'PalmPay', 'provider'=>'PayVessel'];
if ($pv_9payment) $all_available_banks['pv_9payment'] = ['id'=>'pv_9payment', 'action'=>'create_pv_9payment', 'name'=>'9Payment Service Bank', 'provider'=>'PayVessel'];
if ($pv_rubies)  $all_available_banks['pv_rubies'] = ['id'=>'pv_rubies', 'action'=>'create_pv_rubies', 'name'=>'Rubies MFB', 'provider'=>'PayVessel'];
if ($mp_enabled) $all_available_banks['mp'] = ['id'=>'mp', 'action'=>'create_moniepoint', 'name'=>'Moniepoint', 'provider'=>'Monnify'];

// Determine which banks user already has vs still needs
$user_va_keys = [];
foreach ($accounts as $va) {
    $n = strtolower(trim($va['bank_name']));
    $p = $va['provider'];
         if (strpos($n,'palmpay')!==false)                  $user_va_keys[$p.':palmpay'] = true;
    elseif (strpos($n,'opay')!==false)                      $user_va_keys[$p.':opay'] = true;
    elseif (strpos($n,'9payment')!==false)                  $user_va_keys[$p.':9psb'] = true;
    elseif (strpos($n,'rubies')!==false)                    $user_va_keys[$p.':rubies'] = true;
    elseif (strpos($n,'moniepoint')!==false||strpos($n,'monnify')!==false) $user_va_keys[$p.':moniepoint'] = true;
}

// Generate pending list: banks that are available but user hasn't generated yet
$pending_accounts = [];
foreach ($all_available_banks as $id => $bk) {
    $k = $bank_slug[$id] ?? null;
    if ($k && isset($user_va_keys[$k])) continue; // user already has this bank
    $fee = get_fee_for_provider($bk['provider'], $bk['name']);
    $pending_accounts[] = [
        'bank_name' => $bk['name'],
        'action' => $bk['action'],
        'provider' => $bk['provider'],
        'fee_type' => $fee['fee_type'],
        'fee_value' => $fee['fee_value'],
        'fee_cap' => $fee['fee_cap'] ?? 0
    ];
}

$fee_settings = [
    'palmpay' => [
        'fee_type' => setting('palmpay_fee_type', 'flat'),
        'fee_value' => (float)setting('palmpay_fee_value', '50'),
    ],
    'moniepoint' => [
        'fee_type' => setting('gw_moniepoint_fee_type', 'percent'),
        'fee_value' => (float)setting('gw_moniepoint_fee_value', '1'),
    ],
    'payvessel' => [
        'fee_type' => setting('gw_payvessel_fee_type', 'percent'),
        'fee_value' => (float)setting('gw_payvessel_fee_value', '1'),
    ],
];

$manual_bank_name = setting('manual_bank_name', '');
$manual_account_number = setting('manual_account_number', '');
$manual_account_name = setting('manual_account_name', '');

echo json_encode([
    'status' => 'success',
    'success' => true,
    'data' => [
        'user' => [
            'full_name' => $user['full_name'],
            'phone' => $user['phone'],
            'wallet_balance' => (float)$user['wallet_balance']
        ],
        'virtual_accounts' => $virtual_accounts,
        'pending_accounts' => $pending_accounts,
        'fee_settings' => $fee_settings,
        'manual_funding' => [
            'bank_name' => $manual_bank_name,
            'account_number' => $manual_account_number,
            'account_name' => $manual_account_name
        ]
    ]
]);
