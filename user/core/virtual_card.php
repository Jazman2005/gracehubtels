<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/api_router.php';

class CashwyreAPI {
    private string $secretKey;
    private string $baseUrl;
    private string $businessCode;

    public function __construct() {
        $this->secretKey = setting('cashwyre_secret_key', '');
        $this->baseUrl = rtrim(setting('cashwyre_base_url', 'https://businessapi.cashwyre.com/api/v1.0'), '/');
        $this->businessCode = setting('cashwyre_api_key', '');
    }

    private function configured(): bool {
        return $this->secretKey !== '' && $this->businessCode !== '' && setting('cashwyre_enabled', '0') === '1';
    }

    private function request(string $endpoint, array $body = []): array {
        if (!$this->configured()) {
            return ['success' => false, 'message' => 'Virtual card service is not configured yet. Please try again later.'];
        }
        $url = $this->baseUrl . '/' . $endpoint;
        $ch = curl_init($url);
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->secretKey,
        ];
        $payload = array_merge([
            'appId' => $this->businessCode,
            'requestId' => uniqid('req_', true),
            'businessCode' => $this->businessCode,
        ], $body);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        log_api('api_cashwyre', $endpoint, $payload, $resp, $code);
        if ($err) return ['success' => false, 'message' => 'Connection error: ' . $err];
        $data = json_decode((string)$resp, true) ?: [];
        if ($code >= 200 && $code < 300 && ($data['success'] ?? false)) {
            return ['success' => true, 'data' => $data['data'] ?? [], 'raw' => $data];
        }
        $msg = $data['message'] ?? 'Virtual card request failed';
        $msg = str_ireplace(['Cashwyre', 'cashwyre', 'top-up your cashwyre', 'top-up your system'], '', $msg);
        $msg = preg_replace('/\s+/', ' ', trim($msg));
        if (stripos($msg, 'insufficient wallet balance') !== false) {
            $msg = 'Funding declined. Please try again later or contact support.';
        }
        return ['success' => false, 'message' => $msg, 'data' => $data];
    }

    public function get_fx_rates(): array {
        return $this->request('businessRate/getFxRates');
    }

    public function get_ngn_rate(): float {
        $r = $this->get_fx_rates();
        if (!$r['success']) return 0;
        foreach ($r['data'] as $item) {
            $currency = $item['targetCurrency'] ?? $item['currency'] ?? '';
            if ($currency === 'NGN' && ($item['sourceCurrency'] ?? 'USD') === 'USD') {
                if (empty($item['rate']) && empty($item['buyRate']) && empty($item['sellRate'])) {
                    error_log("CASHWYRE_DEBUG matched NGN item keys: " . implode(',', array_keys($item)) . " json=" . json_encode($item));
                }
                return (float)($item['rate'] ?? $item['buyRate'] ?? $item['sellRate'] ?? 0);
            }
        }
        return 0;
    }

    public function get_wallet_balance(string $currency = 'NGN'): array {
        return $this->request('Wallet/getFundwalletAccountInfo', ['currency' => $currency]);
    }

    public function create_customer(string $firstName, string $lastName, string $email, string $phone): array {
        return $this->request('Customer/createCustomer', [
            'firstName' => $firstName,
            'lastName' => $lastName,
            'email' => $email,
            'phoneNumber' => $phone,
        ]);
    }

    public function create_card(array $params): array {
        return $this->request('CustomerCard/createCard', $params);
    }

    public function get_cards(string $customerCode, string $email): array {
        return $this->request('CustomerCard/getCards', [
            'customerCode' => $customerCode,
            'customerEmail' => $email,
        ]);
    }

    public function get_card(string $cardCode): array {
        return $this->request('CustomerCard/getCard', ['cardCode' => $cardCode]);
    }

    public function fund_card(string $cardCode, float $amountInUSD): array {
        return $this->request('CustomerCard/topup', [
            'cardCode' => $cardCode,
            'amountInUSD' => $amountInUSD,
        ]);
    }

    public function withdraw_card(string $cardCode, float $amountInUSD, string $reason = 'Withdrawal'): array {
        return $this->request('CustomerCard/cardwithdrawal', [
            'cardCode' => $cardCode,
            'amountInUSD' => $amountInUSD,
            'reason' => $reason,
        ]);
    }

    public function freeze_card(string $cardCode): array {
        return $this->request('CustomerCard/freezeCard', ['cardCode' => $cardCode]);
    }

    public function unfreeze_card(string $cardCode): array {
        return $this->request('CustomerCard/unfreezeCard', ['cardCode' => $cardCode]);
    }

    public function terminate_card(string $cardCode): array {
        return $this->request('customerCard/terminateCard', ['cardCode' => $cardCode]);
    }
}

function get_live_ngn_rate(): float {
    static $cached = null;
    if ($cached !== null) return $cached;
    $cache_file = sys_get_temp_dir() . '/gracehubtels_rate.cache';
    $ttl = 300;
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $ttl) {
        $cached = (float)file_get_contents($cache_file);
        if ($cached > 0) return $cached;
    }
    try {
        $cw = new CashwyreAPI();
        $rate = $cw->get_ngn_rate();
        if ($rate > 0) {
            $cached = $rate;
            @file_put_contents($cache_file, $rate);
        }
    } catch (\Throwable $e) {}
    return $cached ?: 0;
}

function get_exchange_rate(): float {
    $base = get_live_ngn_rate();
    if ($base <= 0) $base = 1500;
    $type = setting('virtual_card_rate_markup_type', 'none');
    $value = (float)setting('virtual_card_rate_markup_value', '0');
    if ($type === 'fixed') return $base + $value;
    if ($type === 'percentage') return $base * (1 + $value / 100);
    return $base;
}

function get_setup_fee_usd(): float {
    return 2.50 + (float)setting('virtual_card_setup_fee_markup', '0.5');
}

function get_creation_fee(): float {
    return get_setup_fee_usd() * get_exchange_rate();
}

function get_funding_fee(float $usd_amount, float $rate): float {
    $type = setting('virtual_card_funding_fee_type', 'none');
    $value = (float)setting('virtual_card_funding_fee_value', '0');
    if ($type === 'flat') return $value;
    if ($type === 'percentage') return $usd_amount * ($value / 100) * $rate;
    return 0;
}

function create_cashwyre_customer_if_needed(int $user_id): ?string {
    $s = db()->prepare("SELECT cashwyre_customer_code FROM users WHERE id=?");
    $s->execute([$user_id]);
    $existing = $s->fetchColumn();
    if ($existing) return $existing;

    $user = get_user($user_id);
    if (!$user) return null;

    $cw = new CashwyreAPI();
    $parts = explode(' ', $user['full_name'] ?? 'User Gracehubtels');
    $firstName = $parts[0] ?? 'User';
    $lastName = count($parts) > 1 ? trim(substr($user['full_name'], strlen($firstName))) : 'Gracehubtels';
    $email = $user['email'] ?? ($user['phone'] . '@gracehubtels.com');

    $r = $cw->create_customer($firstName, $lastName, $email, $user['phone']);
    if (!$r['success']) return null;

    $code = $r['data']['code'] ?? $r['data']['customerId'] ?? null;
    if ($code) {
        db()->prepare("UPDATE users SET cashwyre_customer_code=? WHERE id=?")->execute([$code, $user_id]);
    }
    return $code;
}

function process_create_virtual_card(int $user_id, array $post): array {
    if (detect_tamper($post)) return ['success' => false, 'message' => 'Invalid input detected'];
    $user = get_user($user_id);
    if (!$user) return ['success' => false, 'message' => 'User not found'];

    $pin = $post['pin'] ?? '';
    if (!verify_pin($user_id, $pin)) return ['success' => false, 'message' => 'Incorrect transaction PIN'];
    if (!claim_purchase_lock($user_id, 'virtual_card_create', [$post['topup'] ?? 0])) return ['success' => false, 'message' => 'Duplicate request detected. Please wait a moment and try again.'];

    $existing = db()->prepare("SELECT COUNT(*) FROM virtual_cards WHERE user_id=? AND status!='terminated'");
    $existing->execute([$user_id]);
    if ((int)$existing->fetchColumn() >= 3) {
        return ['success' => false, 'message' => 'Maximum of 3 active virtual cards allowed'];
    }

    $fee = get_creation_fee();
    $rate = get_exchange_rate();
    $setupUsd = get_setup_fee_usd();
    $minTopupUsd = 5;

    $topupUsd = (float)($post['topup'] ?? 0);
    if ($topupUsd > 0 && $topupUsd < $minTopupUsd) {
        return ['success' => false, 'message' => "Minimum topup amount is \$$minTopupUsd"];
    }
    $topupNgn = $topupUsd * $rate;
    $total = $fee + $topupNgn;

    if ((float)$user['wallet_balance'] < $total) {
        $msg = 'Insufficient balance. Card creation fee is ₦' . number_format($fee, 2);
        if ($topupUsd > 0) $msg .= ' + ₦' . number_format($topupNgn, 2) . ' topup = ₦' . number_format($total, 2);
        $msg .= ' ($' . number_format($setupUsd, 2) . ' setup @ ₦' . number_format($rate, 2) . '/$)';
        return ['success' => false, 'message' => $msg];
    }

    $debit = debit_wallet($user_id, $total, 'virtual_card', 'Virtual card creation: $' . number_format($setupUsd, 2) . ' setup' . ($topupUsd > 0 ? ' + $' . number_format($topupUsd, 2) . ' topup' : '') . ' @ ₦' . number_format($rate, 2) . '/$');
    if (!$debit['success']) return $debit;

    $cw = new CashwyreAPI();
    $customerCode = create_cashwyre_customer_if_needed($user_id);
    if (!$customerCode) {
        refund_failed_transaction($debit['ref'], $user_id, $total, 'Customer creation failed');
        return ['success' => false, 'message' => 'Could not create customer profile on card provider. You have been refunded.'];
    }

    $parts = explode(' ', $user['full_name'] ?? 'User');
    $firstName = $parts[0] ?? 'User';
    $lastName = count($parts) > 1 ? trim(substr($user['full_name'], strlen($firstName))) : 'Gracehubtels';
    $email = $user['email'] ?? ($user['phone'] . '@gracehubtels.com');

    $result = $cw->create_card([
        'firstName' => $firstName,
        'lastName' => $lastName,
        'email' => $email,
        'phoneCode' => '+234',
        'phoneNumber' => $user['phone'],
        'dateOfBirth' => $user['dob'] ?? '1990-01-01T00:00:00Z',
        'homeAddressNumber' => $user['house_number'] ?? '1',
        'homeAddress' => $user['address'] ?? $user['home_address'] ?? 'N/A',
        'cardName' => $user['full_name'] ?? 'User',
        'cardType' => 'virtual',
        'cardBrand' => 'Visa',
        'amountInUSD' => $topupUsd,
    ]);

    if (!$result['success']) {
        refund_failed_transaction($debit['ref'], $user_id, $total, $result['message'] ?? 'Failed to create virtual card.');
        save_tx_response($debit['ref'], provider_response_text($result));
        log_error('api_cashwyre', 'CARD_CREATE_FAILED', ['user_id' => $user_id, 'error' => $result['message'] ?? '']);
        $msg = $result['message'] ?? 'Failed to create virtual card. You have been refunded.';
        if (stripos($msg, 'retry again later') !== false) {
            $msg = 'Virtual card service is temporarily unavailable. Please try again later or contact support. You have been refunded.';
        }
        return ['success' => false, 'message' => $msg];
    }

    $data = $result['data'] ?? [];

    $customerId = $data['CustomerId'] ?? $data['customerId'] ?? $data['customerCode'] ?? $data['code'] ?? '';
    if ($customerId && $customerId !== $customerCode) {
        db()->prepare("UPDATE users SET cashwyre_customer_code=? WHERE id=?")->execute([$customerId, $user_id]);
        $customerCode = $customerId;
    }

    $card_number = '';
    $masked = '';
    $expiry = '';
    $cvv = '';
    $brand = 'Visa';
    $card_id = '';

    $cardsList = $cw->get_cards($customerCode, $email);
    if ($cardsList['success'] && !empty($cardsList['data'])) {
        $allCards = $cardsList['data'];
        if (isset($allCards['cards']) && is_array($allCards['cards'])) $allCards = $allCards['cards'];
        if (isset($allCards['card']) && is_array($allCards['card'])) $allCards = $allCards['card'];
        if (isset($allCards['data']) && is_array($allCards['data'])) $allCards = $allCards['data'];
        if (isset($allCards[0])) {
            $latest = $allCards[0];
            $card_id = $latest['cardCode'] ?? $latest['CardCode'] ?? $latest['code'] ?? '';
        }
        if (!$card_id) {
            foreach ((array)$allCards as $ac) {
                $cc = $ac['cardCode'] ?? $ac['CardCode'] ?? $ac['code'] ?? '';
                if ($cc) { $card_id = $cc; break; }
            }
        }
    } elseif (!$cardsList['success'] && stripos($cardsList['message'] ?? '', 'No record') !== false) {
        log_error('api_cashwyre', 'GETCARDS_NO_RECORD', ['customerCode' => $customerCode, 'email' => $email]);
    }

    if (!$card_id) {
        $card_id = $data['cardCode'] ?? $data['CardCode'] ?? $data['code'] ?? $data['reference'] ?? $data['id'] ?? $data['CustomerId'] ?? '';
    }

    if ($card_id) {
        $cardDetails = $cw->get_card($card_id);
        if ($cardDetails['success']) {
            $cd = $cardDetails['data'] ?? [];
            if (isset($cd['card']) && is_array($cd['card'])) $cd = $cd['card'];
            $card_number = $cd['cardNumber'] ?? $cd['CardNumber'] ?? '';
            $masked = $cd['cardNumberMaxked'] ?? $cd['CardNumberMaxked'] ?? $cd['maskedNumber'] ?? $cd['MaskedNumber'] ?? '';
            $expiry = format_expiry($cd['validMonthYear'] ?? $cd['ValidMonthYear'] ?? $cd['expiryOnInfo'] ?? $cd['expiryOn'] ?? $cd['ExpiryOn'] ?? $cd['expiry'] ?? $cd['expiryDate'] ?? $cd['expirationDate'] ?? $cd['expireDate'] ?? $cd['exp_date'] ?? $cd['expMonth'] ?? '');
            $cvv = $cd['cvV2'] ?? $cd['CvV2'] ?? $cd['cvv'] ?? '';
            $brand = $cd['cardBrand'] ?? $cd['CardBrand'] ?? $cd['brand'] ?? 'Visa';
        }
    }

    if (!$masked && $card_number && strlen($card_number) >= 16) {
        $masked = substr($card_number, 0, 4) . ' •••• •••• ' . substr($card_number, -4);
    } elseif (!$masked) {
        $masked = '•••• •••• •••• ••••';
    }

    if (!$card_id) {
        refund_failed_transaction($debit['ref'], $user_id, $total, 'Could not retrieve card code.');
        save_tx_response($debit['ref'], provider_response_text($result));
        return ['success' => false, 'message' => 'Could not retrieve card code. You have been refunded.'];
    }

    if (!$card_number) {
        log_error('api_cashwyre', 'CARD_DETAILS_MISSING', ['card_id' => $card_id, 'create_resp' => $data, 'getcards_resp' => $cardsList ?? []]);
    }

    db()->prepare("INSERT INTO virtual_cards (user_id, card_id, masked_number, card_number, expiry, cvv, brand, balance, status, raw_response) VALUES (?,?,?,?,?,?,?,?,'active',?)")
        ->execute([$user_id, $card_id, $masked, $card_number, $expiry, $cvv, $brand, $topupUsd, json_encode($data)]);

    update_tx_status($debit['ref'], 'success');
    save_tx_response($debit['ref'], provider_response_text($result));
    $baseRate = get_live_ngn_rate();
    if ($baseRate <= 0) $baseRate = $rate;
    $costPrice = (2.50 * $baseRate) + ($topupUsd * $baseRate);
    $profitVal = $total - $costPrice;
    db()->prepare("UPDATE transactions SET cost_price=?, profit=?, provider=? WHERE transaction_ref=?")
        ->execute([$costPrice, $profitVal, 'System', $debit['ref']]);
    notify_user($user_id, 'Virtual Card Created', 'Your virtual card is ready to use!' . ($topupUsd > 0 ? " \$$topupUsd has been added to your card." : ''), 'success', null, $debit['balance_before'], $debit['balance_after']);

    return ['success' => true, 'message' => 'Virtual card created successfully!' . ($topupUsd > 0 ? " \$$topupUsd topup added." : ''), 'card' => ['card_id' => $card_id, 'masked_number' => $masked], 'ref' => $debit['ref']];
}

function get_user_virtual_cards(int $user_id): array {
    $s = db()->prepare("SELECT * FROM virtual_cards WHERE user_id=? ORDER BY created_at DESC");
    $s->execute([$user_id]);
    return $s->fetchAll();
}

function process_card_action(int $user_id, string $action, string $card_id, array $post): array {
    if (detect_tamper($post)) return ['success' => false, 'message' => 'Invalid input detected'];
    $s = db()->prepare("SELECT * FROM virtual_cards WHERE card_id=? AND user_id=? LIMIT 1");
    $s->execute([$card_id, $user_id]);
    $card = $s->fetch();
    if (!$card) return ['success' => false, 'message' => 'Card not found'];

    $pin = $post['pin'] ?? '';
    if (!verify_pin($user_id, $pin)) return ['success' => false, 'message' => 'Incorrect transaction PIN'];

    $cw = new CashwyreAPI();

    if ($action === 'freeze') {
        $r = $cw->freeze_card($card_id);
        $isOffline = !$r['success'] && (
            stripos($r['message'] ?? '', 'connection error') !== false
            || stripos($r['message'] ?? '', 'service unavailable') !== false
            || stripos($r['message'] ?? '', 'timed out') !== false
            || empty($r['message'])
        );
        if ($r['success'] || $isOffline) {
            db()->prepare("UPDATE virtual_cards SET status='frozen' WHERE card_id=?")->execute([$card_id]);
        }
        if ($r['success'] || $isOffline) return ['success' => true, 'message' => 'Card frozen'];
        return $r;
    }
    if ($action === 'unfreeze') {
        $r = $cw->unfreeze_card($card_id);
        $isOffline = !$r['success'] && (
            stripos($r['message'] ?? '', 'connection error') !== false
            || stripos($r['message'] ?? '', 'service unavailable') !== false
            || stripos($r['message'] ?? '', 'timed out') !== false
            || empty($r['message'])
        );
        if ($r['success'] || $isOffline) {
            db()->prepare("UPDATE virtual_cards SET status='active' WHERE card_id=?")->execute([$card_id]);
        }
        if ($r['success'] || $isOffline) return ['success' => true, 'message' => 'Card unfrozen'];
        return $r;
    }
    if ($action === 'terminate') {
        $r = $cw->terminate_card($card_id);
        if ($r['success']) db()->prepare("UPDATE virtual_cards SET status='terminated' WHERE card_id=?")->execute([$card_id]);
        return $r['success'] ? ['success' => true, 'message' => 'Card terminated'] : $r;
    }
    if ($action === 'withdraw') {
        $usd_amount = (float)($post['amount'] ?? 0);
        if ($usd_amount <= 0) return ['success' => false, 'message' => 'Invalid withdrawal amount'];
        if (!claim_purchase_lock($user_id, 'virtual_card_withdraw', [$card_id, $usd_amount])) return ['success' => false, 'message' => 'Duplicate request detected. Please wait a moment and try again.'];

        // Atomically claim the withdrawal amount off the card's local balance
        // ledger BEFORE calling Cashwyre's (external, irreversible) withdrawal
        // API. $card['balance'] above was read with no lock, so two concurrent
        // withdraw requests on the same card could both pass the sufficiency
        // check against the same stale balance and both succeed at the
        // provider — draining more than the card actually held. This row
        // lock serializes concurrent requests: the second one only proceeds
        // after the first commits, and then sees the already-reduced balance.
        $db = db();
        $db->beginTransaction();
        try {
            $lock = $db->prepare("SELECT balance FROM virtual_cards WHERE card_id=? AND user_id=? FOR UPDATE");
            $lock->execute([$card_id, $user_id]);
            $locked = $lock->fetch();
            if (!$locked) { $db->rollBack(); return ['success' => false, 'message' => 'Card not found']; }
            if ($usd_amount > (float)$locked['balance']) { $db->rollBack(); return ['success' => false, 'message' => 'Withdrawal amount exceeds card balance']; }
            $db->prepare("UPDATE virtual_cards SET balance = balance - ? WHERE card_id=?")->execute([$usd_amount, $card_id]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            log_error('virtual_card', 'Withdraw claim failed: ' . $e->getMessage(), ['card_id' => $card_id]);
            return ['success' => false, 'message' => 'Could not process withdrawal. Please try again.'];
        }

        $r = $cw->withdraw_card($card_id, $usd_amount, 'Withdrawal by user');
        if (!$r['success']) {
            // Provider declined/failed outright (not a network timeout — see
            // the 'fund' branch below for how that uncertain case is handled
            // differently) — release the local claim back onto the card.
            db()->prepare("UPDATE virtual_cards SET balance = balance + ? WHERE card_id=?")->execute([$usd_amount, $card_id]);
            return ['success' => false, 'message' => $r['message'] ?? 'Withdrawal from card failed'];
        }

        $rate = get_exchange_rate();
        $ngn_amount = $usd_amount * $rate;

        $credit = credit_wallet($user_id, $ngn_amount, 'virtual_card', 'Withdraw $' . number_format($usd_amount, 2) . ' from virtual card ' . substr($card_id, -6));
        if (!$credit['success']) {
            // The provider-side withdrawal already happened — the real money
            // left the card at Cashwyre. Re-adding it to our local balance
            // ledger here would desync it from the actual (now lower)
            // Cashwyre balance, so we don't silently revert; this needs a
            // human to reconcile and manually credit the user.
            log_error('virtual_card', 'Withdraw succeeded at provider but wallet credit failed — manual reconciliation required', ['user_id' => $user_id, 'card_id' => $card_id, 'usd_amount' => $usd_amount]);
            return ['success' => false, 'message' => 'Withdrawal was processed but crediting your wallet failed. Please contact support with card ref: ' . $card_id];
        }
        save_tx_response($credit['ref'], provider_response_text($r));
        notify_user($user_id, 'Virtual Card Withdrawal', '₦' . number_format($ngn_amount) . ' withdrawn from virtual card to wallet', 'success', null, $credit['balance_before'], $credit['balance_after']);
        $baseRate = get_live_ngn_rate();
        if ($baseRate <= 0) $baseRate = $rate;
        $costPrice = $usd_amount * $baseRate;
        $profitVal = $ngn_amount - $costPrice;
        db()->prepare("UPDATE transactions SET cost_price=?, profit=?, provider=? WHERE transaction_ref=?")
            ->execute([$costPrice, $profitVal, 'System', $credit['ref']]);
        return ['success' => true, 'message' => "Withdrew \${$usd_amount} from card. ₦" . number_format($ngn_amount) . ' added to wallet.', 'ref' => $credit['ref']];
    }

    if ($action === 'fund') {
        $usd_amount = (float)($post['amount'] ?? 0);
        if ($usd_amount < 5) return ['success' => false, 'message' => 'Minimum funding is $5'];
        if (!claim_purchase_lock($user_id, 'virtual_card_fund', [$card_id, $usd_amount])) return ['success' => false, 'message' => 'Duplicate request detected. Please wait a moment and try again.'];

        $rate = get_exchange_rate();
        $ngn_amount = $usd_amount * $rate;
        $funding_fee = get_funding_fee($usd_amount, $rate);
        $total_ngn = $ngn_amount + $funding_fee;
        
        $user = get_user($user_id);
        if ((float)$user['wallet_balance'] < $total_ngn) return ['success' => false, 'message' => 'Insufficient wallet balance. You need ₦' . number_format($total_ngn, 2)];
        
        $desc = 'Fund virtual card ' . substr($card_id, -6) . ' with $' . $usd_amount;
        if ($funding_fee > 0) $desc .= ' (+ ₦' . number_format($funding_fee, 2) . ' fee)';
        $debit = debit_wallet($user_id, $total_ngn, 'virtual_card', $desc);
        if (!$debit['success']) return $debit;
        
        $r = $cw->fund_card($card_id, $usd_amount);
        if (!$r['success']) {
            $isOffline = stripos($r['message'] ?? '', 'connection error') !== false
                      || stripos($r['message'] ?? '', 'service unavailable') !== false
                      || stripos($r['message'] ?? '', 'timed out') !== false
                      || empty($r['message']);
            if ($isOffline) {
                // Network-level uncertainty talking to Cashwyre — we do NOT
                // know whether the top-up actually landed on the card.
                // Previously this branch assumed success outright: it
                // credited the card balance and marked the wallet debit
                // 'success' with zero confirmation from the provider — a
                // direct free-money vector, since a user could force or
                // simply wait for a timeout and get the card credited
                // regardless of whether Cashwyre ever processed the top-up.
                // Mark 'processing' instead (not refunded, not success) and
                // require a human to confirm the real outcome with Cashwyre
                // before this is ever resolved either way.
                $check = $cw->get_card($card_id);
                save_tx_response($debit['ref'], 'Uncertain outcome after fund_card() (network error) — manual verification required. Provider card check: ' . json_encode($check));
                update_tx_status($debit['ref'], 'processing');
                log_error('api_cashwyre', 'FUND_CARD_OUTCOME_UNKNOWN', ['user_id' => $user_id, 'card_id' => $card_id, 'usd_amount' => $usd_amount, 'ref' => $debit['ref']]);
                notify_user($user_id, 'Card Funding Under Review', 'We could not confirm your card funding due to a network issue. Your request is being verified and will be resolved shortly — you have not been charged twice.', 'info');
                return ['success' => true, 'message' => 'Your card funding is being verified due to a network issue. This can take a few minutes — check back shortly.', 'ref' => $debit['ref'], 'processing' => true];
            }
            refund_failed_transaction($debit['ref'], $user_id, $total_ngn, $r['message'] ?? 'Failed to fund virtual card.');
            save_tx_response($debit['ref'], provider_response_text($r) ?: ($r['message'] ?? 'Failed to fund virtual card.'));
            return ['success' => false, 'message' => $r['message'] ?? 'Funding failed. You have been refunded.'];
        }
        save_tx_response($debit['ref'], provider_response_text($r));
        // Guard against a Cashwyre funding webhook (which independently
        // credits virtual_cards.balance for the same top-up event under its
        // own 'cashwyre:fund:<event_id>' idempotency key) resolving this same
        // transaction concurrently with this synchronous request. Only the
        // caller that actually wins the pending/processing -> success
        // transition here touches the card balance and cost/profit fields.
        if (!update_tx_status($debit['ref'], 'success')) {
            log_info('wallet', 'virtual_card fund: transaction already resolved elsewhere, skipping duplicate balance credit', ['ref' => $debit['ref']]);
            return ['success' => true, 'message' => "Card funding for $$usd_amount already processed.", 'ref' => $debit['ref'], 'fee' => $funding_fee];
        }
        db()->prepare("UPDATE virtual_cards SET balance = balance + ? WHERE card_id=?")->execute([$usd_amount, $card_id]);
        $baseRate = get_live_ngn_rate();
        if ($baseRate <= 0) $baseRate = $rate;
        $costPrice = $usd_amount * $baseRate;
        $profitVal = $total_ngn - $costPrice;
        db()->prepare("UPDATE transactions SET cost_price=?, profit=?, provider=? WHERE transaction_ref=?")
            ->execute([$costPrice, $profitVal, 'System', $debit['ref']]);
        return ['success' => true, 'message' => "Card funded successfully with $$usd_amount", 'ref' => $debit['ref'], 'fee' => $funding_fee];
    }
    return ['success' => false, 'message' => 'Unknown action'];
}

function format_expiry(?string $raw): string {
    if (!$raw) return '';
    $raw = trim($raw);
    if (preg_match('#^(\d{2})/(\d{4})$#', $raw, $m)) return $m[1] . '/' . substr($m[2], -2);
    if (preg_match('#^\d{2}/\d{2}$#', $raw)) return $raw;
    if (preg_match('#^\d{2}(\d{2})(\d{2})$#', $raw, $m)) return $m[1] . '/' . $m[2];
    $ts = strtotime($raw);
    if ($ts === false) return $raw;
    return date('m/y', $ts);
}

