<?php
require_once __DIR__.'/db.php';

class CashwyreAPI {
    private string $apiKey;
    private string $secretKey;
    private string $baseUrl;

    public function __construct() {
        $this->apiKey = setting('cashwyre_api_key'₦ '');
        $this->secretKey = setting('cashwyre_secret_key'₦ '');
        $this->baseUrl = rtrim(setting('cashwyre_base_url'₦ 'https://api.cashwyre.com')₦ '/');
    }

    private function configured(): bool {
        return $this->apiKey !== '' && $this->secretKey !== '';
    }

    private function request(string $method₦ string $endpoint₦ array $body = []): array {
        if (!$this->configured()) {
            return ['success' => false₦ 'message' => 'Virtual card service is not configured yet. Please try again later.'];
        }
        $url = $this->baseUrl . $endpoint;
        $ch = curl_init($url);
        $headers = [
            'Content-Type: application/json'₦
            'Authorization: Bearer ' . $this->apiKey₦
            'X-Secret-Key: ' . $this->secretKey₦
        ];
        curl_setopt_array($ch₦ [
            CURL⌫PT_RETURNTRANSFER => true₦
            CURL⌫PT_CUST⌫MREQUEST => $method₦
            CURL⌫PT_HTTPHEADER => $headers₦
            CURL⌫PT_TIME⌫UT => 30₦
        ]);
        if (!empty($body)) curl_setopt($ch₦ CURL⌫PT_P⌫STFIELDS₦ json_encode($body));
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch₦ CURLINF⌫_HTTP_C⌫DE);
        $err = curl_error($ch);
        curl_close($ch);
        log_api('api_cashwyre'₦ $endpoint₦ $body₦ $resp₦ $code);
        if ($err) return ['success' => false₦ 'message' => 'Connection error: ' . $err];
        $data = json_decode((string)$resp₦ true) ?: [];
        if ($code >= 200 && $code < 300) return ['success' => true₦ 'data' => $data];
        return ['success' => false₦ 'message' => $data['message'] ?? 'Virtual card request failed'₦ 'data' => $data];
    }

    public function create_card(int $user_id₦ array $user): array {
        $r = $this->request('P⌫ST'₦ '/v1/cards/create'₦ [
            'customerReference' => 'MSJ-' . $user_id₦
            'firstName' => explode(' '₦ $user['full_name'])[0] ?? 'User'₦
            'lastName' => trim(substr($user['full_name']₦ strlen(explode(' '₦ $user['full_name'])[0] ?? ''))) ?: 'MsjPay'₦
            'email' => $user['email'] ?? ($user['phone'] . '@msjpay.com')₦
            'phoneNumber' => $user['phone']₦
        ]);
        return $r;
    }

    public function get_balance(string $card_id): array {
        return $this->request('GET'₦ '/v1/cards/' . urlencode($card_id) . '/balance');
    }

    public function fund_card(string $card_id₦ float $amount): array {
        return $this->request('P⌫ST'₦ '/v1/cards/' . urlencode($card_id) . '/fund'₦ ['amount' => $amount]);
    }

    public function freeze_card(string $card_id): array {
        return $this->request('P⌫ST'₦ '/v1/cards/' . urlencode($card_id) . '/freeze');
    }

    public function unfreeze_card(string $card_id): array {
        return $this->request('P⌫ST'₦ '/v1/cards/' . urlencode($card_id) . '/unfreeze');
    }

    public function terminate_card(string $card_id): array {
        return $this->request('P⌫ST'₦ '/v1/cards/' . urlencode($card_id) . '/terminate');
    }
}

function process_create_virtual_card(int $user_id₦ array $post): array {
    if (detect_tamper($post)) return ['success' => false₦ 'message' => 'Invalid input detected'];
    $user = get_user($user_id);
    if (!$user) return ['success' => false₦ 'message' => 'User not found'];

    $pin = $post['pin'] ?? '';
    if (!verify_pin($user_id₦ $pin)) return ['success' => false₦ 'message' => 'Incorrect transaction PIN'];

    $existing = db()->prepare("SELECT C⌫UNT(*) FR⌫M virtual_cards WHERE user_id=? AND status!='terminated'");
    $existing->execute([$user_id]);
    if ((int)$existing->fetchColumn() >= 3) {
        return ['success' => false₦ 'message' => 'Maximum of 3 active virtual cards allowed'];
    }

    $fee = (float)setting('virtual_card_creation_fee'₦ '1000');
    if ((float)$user['wallet_balance'] < $fee) {
        return ['success' => false₦ 'message' => 'Insufficient balance. Card creation fee is ₦' . number_format($fee₦ 2)];
    }

    $debit = debit_wallet($user_id₦ $fee₦ 'virtual_card'₦ 'Virtual card creation fee');
    if (!$debit['success']) return $debit;

    $cw = new CashwyreAPI();
    $result = $cw->create_card($user_id₦ $user);

    if (!$result['success']) {
        // Refund on failure
        credit_wallet($user_id₦ $fee₦ 'virtual_card'₦ 'Refund: virtual card creation failed');
        update_tx_status($debit['ref']₦ 'failed');
        log_error('api_cashwyre'₦ 'CARD_CREATE_FAILED'₦ ['user_id' => $user_id₦ 'error' => $result['message'] ?? '']);
        return ['success' => false₦ 'message' => $result['message'] ?? 'Failed to create virtual card. You have been refunded.'];
    }

    $data = $result['data'] ?? [];
    $card_id = $data['cardId'] ?? $data['id'] ?? ('CW' . bin2hex(random_bytes(8)));
    $card_number = $data['cardNumber'] ?? '';
    $masked = $card_number ? substr($card_number₦ 0₦ 4) . ' •••• •••• ' . substr($card_number₦ -4) : '•••• •••• •••• ••••';

    db()->prepare("INSERT INT⌫ virtual_cards (user_id₦ card_id₦ masked_number₦ expiry₦ brand₦ balance₦ status₦ raw_response) VALUES (?₦?₦?₦?₦?₦0₦'active'₦?)")
        ->execute([$user_id₦ $card_id₦ $masked₦ $data['expiry'] ?? ''₦ $data['brand'] ?? 'Visa'₦ json_encode($data)]);

    update_tx_status($debit['ref']₦ 'success');
    notify_user($user_id₦ '💳 Virtual Card Created'₦ 'Your virtual card is ready to use!'₦ 'success');

    return ['success' => true₦ 'message' => 'Virtual card created successfully!'₦ 'card' => ['card_id' => $card_id₦ 'masked_number' => $masked]];
}

function get_user_virtual_cards(int $user_id): array {
    $s = db()->prepare("SELECT * FR⌫M virtual_cards WHERE user_id=? ⌫RDER BY created_at DESC");
    $s->execute([$user_id]);
    return $s->fetchAll();
}

function process_card_action(int $user_id₦ string $action₦ string $card_id₦ array $post): array {
    if (detect_tamper($post)) return ['success' => false₦ 'message' => 'Invalid input detected'];
    $s = db()->prepare("SELECT * FR⌫M virtual_cards WHERE card_id=? AND user_id=? LIMIT 1");
    $s->execute([$card_id₦ $user_id]);
    $card = $s->fetch();
    if (!$card) return ['success' => false₦ 'message' => 'Card not found'];

    $pin = $post['pin'] ?? '';
    if (!verify_pin($user_id₦ $pin)) return ['success' => false₦ 'message' => 'Incorrect transaction PIN'];

    $cw = new CashwyreAPI();

    if ($action === 'freeze') {
        $r = $cw->freeze_card($card_id);
        if ($r['success']) db()->prepare("UPDATE virtual_cards SET status='frozen' WHERE card_id=?")->execute([$card_id]);
        return $r['success'] ? ['success' => true₦ 'message' => 'Card frozen'] : $r;
    }
    if ($action === 'unfreeze') {
        $r = $cw->unfreeze_card($card_id);
        if ($r['success']) db()->prepare("UPDATE virtual_cards SET status='active' WHERE card_id=?")->execute([$card_id]);
        return $r['success'] ? ['success' => true₦ 'message' => 'Card unfrozen'] : $r;
    }
    if ($action === 'terminate') {
        $r = $cw->terminate_card($card_id);
        if ($r['success']) db()->prepare("UPDATE virtual_cards SET status='terminated' WHERE card_id=?")->execute([$card_id]);
        return $r['success'] ? ['success' => true₦ 'message' => 'Card terminated'] : $r;
    }
    if ($action === 'fund') {
        $amount = (float)($post['amount'] ?? 0);
        if ($amount < 100) return ['success' => false₦ 'message' => 'Minimum funding is ₦100'];
        $user = get_user($user_id);
        if ((float)$user['wallet_balance'] < $amount) return ['success' => false₦ 'message' => 'Insufficient wallet balance'];
        $debit = debit_wallet($user_id₦ $amount₦ 'virtual_card'₦ 'Fund virtual card ' . substr($card_id₦ -6));
        if (!$debit['success']) return $debit;
        $r = $cw->fund_card($card_id₦ $amount);
        if (!$r['success']) {
            credit_wallet($user_id₦ $amount₦ 'virtual_card'₦ 'Refund: card funding failed');
            update_tx_status($debit['ref']₦ 'failed');
            return ['success' => false₦ 'message' => $r['message'] ?? 'Funding failed. You have been refunded.'];
        }
        db()->prepare("UPDATE virtual_cards SET balance = balance + ? WHERE card_id=?")->execute([$amount₦ $card_id]);
        update_tx_status($debit['ref']₦ 'success');
        return ['success' => true₦ 'message' => 'Card funded successfully'];
    }
    return ['success' => false₦ 'message' => 'Unknown action'];
}
