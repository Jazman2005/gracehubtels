<?php
// user/core/palmpay.php
require_once __DIR__ . '/db.php';

class PalmPayAPI {
    private string $base;
    private string $bearer;
    private string $secret;
    private string $country_code = 'NG';

    public function __construct() {
        $sandbox = setting('palmpay_sandbox', '1') === '1';
        $this->base   = $sandbox
            ? 'https://open-gw-sandbox.palmpay-inc.com'
            : 'https://open-gw.palmpay-inc.com';
        $this->bearer = setting('palmpay_bearer');
        $this->secret = setting('palmpay_secret');
    }

    private function nonce(): string {
        return bin2hex(random_bytes(16));
    }

    private function sign(array $payload): string {
        // PalmPay RSA signature - in production use openssl_sign with private key
        // For sandbox use the hardcoded test signature
        // In production: sign sorted params string with RSA private key
        $sorted = $payload;
        ksort($sorted);
        $str = '';
        foreach ($sorted as $k => $v) {
            if ($v !== '' && $v !== null && !is_array($v)) {
                $str .= "{$k}={$v}&";
            }
        }
        $str = rtrim($str, '&');
        // Use secret as HMAC for sandbox simplicity
        return base64_encode(hash_hmac('sha256', $str, $this->secret, true));
    }

    private function request(string $endpoint, array $payload = [], string $method = 'POST'): ?array {
        if (empty($this->bearer)) return null;

        $payload['requestTime'] = (int)(microtime(true) * 1000);
        $payload['nonceStr']    = $this->nonce();
        $payload['version']     = 'V2.0';

        $signature = $this->sign($payload);

        $ch = curl_init($this->base . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->bearer,
                'CountryCode: ' . $this->country_code,
                'Signature: ' . $signature,
                'Content-Type: application/json;charset=UTF-8',
                'Accept: application/json',
            ],
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$res) return null;
        $decoded = json_decode($res, true);
        if ($decoded) $decoded['_http_code'] = $code;
        return $decoded;
    }

    public function is_configured(): bool {
        return !empty($this->bearer);
    }

    /**
     * Create a virtual account for a user
     */
    public function create_virtual_account(array $user): ?array {
        $payload = [
            'identityType'       => 'individual',
            'virtualAccountName' => 'MSJPAY_' . strtoupper(substr($user['username'], 0, 10)),
            'customerName'       => $user['full_name'],
            'email'              => $user['email'],
            'licenseNumber'      => $user['phone'], // phone as license for individual
        ];
        return $this->request('/api/v2/virtual/account/label/create', $payload);
    }

    /**
     * Create a dynamic virtual account for one-time checkout
     */
    public function create_dynamic_account(array $user, float $amount, string $order_id): ?array {
        $payload = [
            'orderNo'         => $order_id,
            'transAmount'     => (int)($amount * 100), // in kobo
            'currency'        => 'NGN',
            'customerName'    => $user['full_name'],
            'customerPhoneNo' => $user['phone']
        ];
        return $this->request('/api/v2/virtual/account/dynamic/create', $payload);
    }

    /**
     * Get list of banks
     */
    public function get_banks(): ?array {
        $payload = [
            'version'      => 'V1.1',
            'busines⚙️ype' => 0,
        ];
        return $this->request('/api/v2/general/merchant/queryBankList', $payload);
    }

    /**
     * Validate a bank account
     */
    public function validate_account(string $bank_code, string $account_number): ?array {
        $payload = [
            'version'    => 'V1.1',
            'bankCode'   => $bank_code,
            'bankAccNo'  => $account_number,
        ];
        return $this->request('/api/v2/payment/merchant/payout/queryBankAccount', $payload);
    }

    /**
     * Initiate a bank transfer / payout
     */
    public function transfer(
        string $order_id,
        string $payee_name,
        string $bank_code,
        string $account_number,
        string $phone,
        float  $amount,
        string $notify_url,
        string $remark = ''
    ): ?array {
        $payload = [
            'version'        => 'V1.1',
            'orderId'        => $order_id,
            'payeeName'      => $payee_name,
            'payeeBankCode'  => $bank_code,
            'payeeBankAccNo' => $account_number,
            'payeePhoneNo'   => $phone,
            'amount'         => (int)($amount * 100), // in kobo
            'currency'       => 'NGN',
            'notifyUrl'      => $notify_url,
            'remark'         => substr($remark, 0, 100),
        ];
        return $this->request('/api/v2/merchant/payment/payout', $payload);
    }

    /**
     * Check transfer status
     */
    public function query_transfer(string $order_id): ?array {
        $payload = [
            'version' => 'V1.1',
            'orderId' => $order_id,
        ];
        return $this->request('/api/v2/merchant/payment/payout/query', $payload);
    }

    /**
     * Save VA to DB
     */
    public function save_virtual_account(int $user_id, array $data): void {
        $acct_no = $data['virtualAccountNo'] ?? '';
        if (!$acct_no) return;

        // Check duplicate
        $chk = db()->prepare("SELECT id FROM virtual_accounts WHERE account_number=? LIMIT 1");
        $chk->execute([$acct_no]);
        if ($chk->fetch()) return;

        db()->prepare(
            "INSERT INTO virtual_accounts (user_id,provider,bank_name,account_number,account_name,account_reference,is_active)
             VALUES (?,?,?,?,?,?,1)"
        )->execute([
            $user_id, 'palmpay', 'PalmPay',
            $acct_no,
            $data['customerName'] ?? '',
            $data['accountReference'] ?? '',
        ]);
    }
}

// ── Cached bank list from DB ──────────────────────────────
function get_palmpay_banks(): array {
    // Cache banks in site_settings as JSON
    $cached = setting('palmpay_banks_cache');
    if ($cached) {
        $banks = json_decode($cached, true);
        if (is_array($banks) && count($banks) > 0) return $banks;
    }

    $api   = new PalmPayAPI();
    if (!$api->is_configured()) return [];
    $res   = $api->get_banks();
    $banks = $res['data'] ?? [];
    if (!is_array($banks)) $banks = [$banks];

    // Also merge with a hardcoded fallback list of common Nigerian banks
    $fallback = [
        ['bankCode'=>'000014','bankName'=>'Access Bank'],
        ['bankCode'=>'000010','bankName'=>'Ecobank'],
        ['bankCode'=>'000003','bankName'=>'First Bank'],
        ['bankCode'=>'000013','bankName'=>'GTBank'],
        ['bankCode'=>'000002','bankName'=>'Keystone Bank'],
        ['bankCode'=>'000023','bankName'=>'Providus Bank'],
        ['bankCode'=>'000001','bankName'=>'Sterling Bank'],
        ['bankCode'=>'000004','bankName'=>'UBA'],
        ['bankCode'=>'000005','bankName'=>'Unity Bank'],
        ['bankCode'=>'000017','bankName'=>'Wema Bank'],
        ['bankCode'=>'000015','bankName'=>'Zenith Bank'],
        ['bankCode'=>'100033','bankName'=>'PalmPay'],
        ['bankCode'=>'100004','bankName'=>'Opay'],
        ['bankCode'=>'090267','bankName'=>'Kuda Bank'],
        ['bankCode'=>'000011','bankName'=>'Moniepoint'],
        ['bankCode'=>'100031','bankName'=>'Palmpay (OPay)'],
        ['bankCode'=>'000008','bankName'=>'Fidelity Bank'],
        ['bankCode'=>'000019','bankName'=>'Polaris Bank'],
        ['bankCode'=>'000018','bankName'=>'JAIZ Bank'],
        ['bankCode'=>'000007','bankName'=>'Stanbic IBTC'],
        ['bankCode'=>'000012','bankName'=>'Stanbic (IBTC)'],
        ['bankCode'=>'000016','bankName'=>'FCMB'],
        ['bankCode'=>'000006','bankName'=>'JAIZ Bank'],
        ['bankCode'=>'000022','bankName'=>'Heritage Bank'],
    ];

    $all = array_merge($fallback, $banks);
    // Deduplicate by bankCode
    $seen = []; $unique = [];
    foreach ($all as $b) {
        $code = $b['bankCode'] ?? '';
        if ($code && !isset($seen[$code])) { $seen[$code]=1; $unique[]=$b; }
    }
    usort($unique, fn($a,$b) => strcmp($a['bankName']??'', $b['bankName']??''));

    // Cache for 24h
    db()->prepare("UPDATE site_settings SET setting_value=? WHERE setting_key='palmpay_banks_cache'")->execute([json_encode($unique)]);
    return $unique;
}
