<?php
define('IS_API', true);
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 2) . '/user/core/virtual_card.php';

$user = api_auth();
$uid = (int)$user['id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $cards = get_user_virtual_cards($uid);
    $cw = new CashwyreAPI();
    foreach ($cards as &$card) {
        if (!$card['card_id'] || $card['status'] === 'terminated') continue;
        if (!empty($card['card_number']) || ($card['masked_number'] && $card['masked_number'] !== '•••• •••• •••• ••••')) continue;
        $cd = $cw->get_card($card['card_id']);
        if (!$cd['success'] && !empty($user['cashwyre_customer_code'])) {
            $email = $user['email'] ?? '';
            if ($email) {
                $cl = $cw->get_cards($user['cashwyre_customer_code'], $email);
                if ($cl['success'] && !empty($cl['data'])) {
                    $all = $cl['data'];
                    if (isset($all['cards']) && is_array($all['cards'])) $all = $all['cards'];
                    if (isset($all['card']) && is_array($all['card'])) $all = $all['card'];
                    if (isset($all['data']) && is_array($all['data'])) $all = $all['data'];
                    if (isset($all[0])) {
                        $realId = $all[0]['cardCode'] ?? $all[0]['CardCode'] ?? $all[0]['code'] ?? '';
                        if ($realId) {
                            db()->prepare("UPDATE virtual_cards SET card_id=? WHERE card_id=?")->execute([$realId, $card['card_id']]);
                            $card['card_id'] = $realId;
                            $cd = $cw->get_card($realId);
                        }
                    }
                }
            }
        }
        $cardNum = '';
        $masked = '';
        $expiry = $card['expiry'];
        $cvv = '';
        if ($cd['success']) {
            $d = $cd['data'] ?? [];
            if (isset($d['card']) && is_array($d['card'])) $d = $d['card'];
            $cardNum = $d['cardNumber'] ?? $d['CardNumber'] ?? '';
            $masked = $d['cardNumberMaxked'] ?? $d['CardNumberMaxked'] ?? $d['maskedNumber'] ?? '';
            $expiry = format_expiry($d['validMonthYear'] ?? $d['ValidMonthYear'] ?? $d['expiryOnInfo'] ?? $d['expiryOn'] ?? $d['ExpiryOn'] ?? $d['expiry'] ?? $d['expiryDate'] ?? $d['expirationDate'] ?? $d['expireDate'] ?? $d['exp_date'] ?? $d['expMonth'] ?? $card['expiry']);
            $cvv = $d['cvV2'] ?? $d['CvV2'] ?? '';
        }
        if (!$masked && $cardNum && strlen($cardNum) >= 16) {
            $masked = substr($cardNum, 0, 4) . ' •••• •••• ' . substr($cardNum, -4);
        } elseif (!$masked) {
            $masked = $card['masked_number'] ?: '•••• •••• •••• ••••';
        }
        if ($cardNum || ($masked && $masked !== '•••• •••• •••• ••••')) {
            $s = db()->prepare("UPDATE virtual_cards SET card_number=?, masked_number=?, expiry=?, cvv=? WHERE card_id=?");
            $s->execute([$cardNum, $masked, $expiry, $cvv, $card['card_id']]);
            $card['card_number'] = $cardNum;
            $card['masked_number'] = $masked;
            $card['expiry'] = $expiry;
            $card['cvv'] = $cvv;
        }
    }
    unset($card);
    $rate = get_exchange_rate();
    $liveRate = get_live_ngn_rate();
    echo json_encode([
        'status' => 'success',
        'success' => true,
        'data' => [
            'cards' => $cards,
            'wallet_balance' => (float)($user['wallet_balance'] ?? 0),
            'exchange_rate' => $rate,
            'live_rate' => $liveRate,
            'creation_fee' => get_creation_fee(),
            'setup_fee_usd' => get_setup_fee_usd(),
            'setup_fee_base' => 2.50,
            'min_topup_usd' => 5,
            'rate_markup_type' => setting('virtual_card_rate_markup_type', 'none'),
            'rate_markup_value' => (float)setting('virtual_card_rate_markup_value', '0'),
            'full_name' => $user['full_name'] ?? 'USER',
            'funding_fee_type' => setting('virtual_card_funding_fee_type', 'none'),
            'funding_fee_value' => (float)setting('virtual_card_funding_fee_value', '0')
        ]
    ]);
    exit;
}

if ($method === 'POST') {
    if (!rate_limit('api_purchase_' . $uid, 30, 60)) {
        http_response_code(429);
        echo json_encode(['status' => 'error', 'message' => 'Too many requests. Please slow down and try again shortly.']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? 'create';

    if ($action === 'create') {
        if (empty($input['pin'])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing required field: pin']);
            exit;
        }
        $res = process_create_virtual_card($uid, $input);
        if ($res['success']) {
            echo json_encode([
                'status' => 'success',
                'success' => true,
                'message' => $res['message'],
                'data' => ['card' => $res['card'] ?? null, 'reference' => $res['ref'] ?? null]
            ]);
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'success' => false, 'message' => $res['message']]);
        }
        exit;
    }

    if ($action === 'reveal') {
        if (empty($input['card_id']) || empty($input['pin'])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing required fields: card_id, pin']);
            exit;
        }
        if (!verify_pin($uid, $input['pin'])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Incorrect transaction PIN']);
            exit;
        }
        $s = db()->prepare("SELECT * FROM virtual_cards WHERE card_id=? AND user_id=? LIMIT 1");
        $s->execute([$input['card_id'], $uid]);
        $card = $s->fetch();
        if (!$card) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Card not found']);
            exit;
        }
        $cw = new CashwyreAPI();
        $cd = $cw->get_card($input['card_id']);
        if (!$cd['success']) {
            $cc = $user['cashwyre_customer_code'] ?? '';
            $em = $user['email'] ?? '';
            if ($cc && $em) {
                $cl = $cw->get_cards($cc, $em);
                if ($cl['success'] && !empty($cl['data'])) {
                    $all = $cl['data'];
                    if (isset($all['cards']) && is_array($all['cards'])) $all = $all['cards'];
                    if (isset($all['card']) && is_array($all['card'])) $all = $all['card'];
                    if (isset($all['data']) && is_array($all['data'])) $all = $all['data'];
                    if (isset($all[0])) {
                        $realId = $all[0]['cardCode'] ?? $all[0]['CardCode'] ?? $all[0]['code'] ?? '';
                        if ($realId) {
                            db()->prepare("UPDATE virtual_cards SET card_id=? WHERE card_id=?")->execute([$realId, $input['card_id']]);
                            $cd = $cw->get_card($realId);
                        }
                    }
                }
            }
            if (!$cd['success']) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'success' => false, 'message' => $cd['message'] ?? 'Failed to fetch card details']);
                exit;
            }
        }
        $d = $cd['data'] ?? [];
        if (isset($d['card']) && is_array($d['card'])) $d = $d['card'];
        $expiryRaw = $d['validMonthYear'] ?? $d['ValidMonthYear'] ?? $d['expiryOnInfo'] ?? $d['expiryOn'] ?? $d['ExpiryOn'] ?? $d['expiry'] ?? $d['expiryDate'] ?? $d['expirationDate'] ?? $d['expireDate'] ?? $d['exp_date'] ?? $d['expMonth'] ?? '';
        if (!$expiryRaw) {
            log_error('api_cashwyre', 'EXPIRY_KEYS', ['keys' => array_keys($d), 'sample' => json_encode($d)]);
        }
        $cardNum = $d['cardNumber'] ?? $d['CardNumber'] ?? '';
        $cvv = $d['cvV2'] ?? $d['CvV2'] ?? '';
        $expiry = format_expiry($expiryRaw);
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'data' => [
                'card_number' => $cardNum,
                'cvv' => $cvv,
                'expiry' => $expiry,
            ]
        ]);
        exit;
    }

    if (in_array($action, ['freeze', 'unfreeze', 'terminate', 'fund', 'withdraw'])) {
        if (empty($input['card_id']) || empty($input['pin'])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing required fields: card_id, pin']);
            exit;
        }
        $res = process_card_action($uid, $action, $input['card_id'], $input);
        if ($res['success']) {
            echo json_encode([
                'status' => 'success',
                'success' => true,
                'message' => $res['message'],
                'data' => ['reference' => $res['ref'] ?? null]
            ]);
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'success' => false, 'message' => $res['message']]);
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Unknown action. Supported: create, freeze, unfreeze, terminate, fund, withdraw']);
    exit;
}

http_response_code(405);
echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed. Use GET or POST.']);
