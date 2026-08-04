<?php
// user/core/palmpay.php
require_once __DIR__ . '/db.php';

class PalmPayAPI {
    private string $base;
    private string $app_id;
    private string $private_key;
    private string $country_code = 'NG';

    public function __construct() {
        $sandbox = setting('palmpay_sandbox', '1') === '1';
        $this->base   = $sandbox
            ? 'https://open-gw-daily.palmpay-inc.com'
            : 'https://open-gw-prod.palmpay-inc.com';
        $this->app_id      = setting('palmpay_bearer');
        $this->private_key = setting('palmpay_secret');
    }

    private function nonce(): string {
        return bin2hex(random_bytes(16));
    }

    private function sign(array $payload): string {
        ksort($payload);
        $str = '';
        foreach ($payload as $k => $v) {
            if ($v !== '' && $v !== null && !is_array($v)) {
                $str .= "{$k}={$v}&";
            }
        }
        $str    = rtrim($str, '&');
        $md5str = strtoupper(md5($str));

        if (!empty($this->private_key)) {
            if (!function_exists('openssl_pkey_get_private')) {
                log_write('palmpay_api', 'ERROR', 'openssl_pkey_get_private not available');
            } else {
                $pkey = @openssl_pkey_get_private($this->private_key);
                if ($pkey) {
                    $signed = '';
                    if (@openssl_sign($md5str, $signed, $pkey, OPENSSL_ALGO_SHA1)) {
                        openssl_free_key($pkey);
                        return base64_encode($signed);
                    }
                    openssl_free_key($pkey);
                    log_write('palmpay_api', 'ERROR', 'openssl_sign failed: ' . openssl_error_string());
                } else {
                    log_write('palmpay_api', 'ERROR', 'Failed to load private key');
                }
            }
        }

        return base64_encode(hash_hmac('sha256', $md5str, $this->private_key ?: $this->app_id, true));
    }

    private function request(string $endpoint, array $payload = []): ?array {
        if (empty($this->app_id)) {
            log_write('palmpay_api', 'ERROR', 'App ID (Bearer) not configured');
            return null;
        }

        $payload['requestTime'] = (int)(microtime(true) * 1000);
        $payload['nonceStr']    = $this->nonce();
        $payload['version']     = 'V2';

        $signature = $this->sign($payload);
        $body_json = json_encode($payload);
        $url       = $this->base . $endpoint;

        log_write('palmpay_api', 'DEBUG', 'Request', [
            'url'    => $url,
            'method' => 'POST',
            'body'   => $body_json,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body_json,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->app_id,
                'CountryCode: ' . $this->country_code,
                'Signature: ' . $signature,
                'Content-Type: application/json;charset=UTF-8',
                'Accept: application/json',
            ],
        ]);

        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if (!$res) {
            log_write('palmpay_api', 'ERROR', 'cURL failed', [
                'url'   => $url,
                'error' => $err ?: 'Empty response',
                'http_code' => $code,
            ]);
            return null;
        }

        log_write('palmpay_api', 'DEBUG', 'Response', [
            'http_code' => $code,
            'body'      => $res,
        ]);

        $decoded = json_decode($res, true);
        if ($decoded) $decoded['_http_code'] = $code;
        return $decoded;
    }

    public function is_configured(): bool {
        return !empty($this->app_id);
    }

    /**
     * Create a payment order — generates a VA for bank-transfer deposit
     * POST /api/v2/payment/merchant/createorder
     */
    public function create_order(array $user, float $amount, string $order_id, string $notify_url, string $callback_url = ''): ?array {
        if (empty($callback_url)) {
            $base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
            $callback_url = 'https://www.gracehubtels.com' . $base . '/user/dashboard';
        }
        $payload = [
            'orderId'       => $order_id,
            'title'         => 'Wallet Top-up',
            'description'   => 'Deposit to Gracehubtels wallet',
            'amount'        => (int)round($amount * 100),
            'currency'      => 'NGN',
            'notifyUrl'     => $notify_url,
            'callBackUrl'   => $callback_url,
            'productType'   => 'bank_transfer',
            'goodsDetails'  => '[{"goodsId":"-1"}]',
            'userId'        => (string)$user['id'],
            'userMobileNo'  => $user['phone'],
            'remark'        => 'Deposit ref: ' . $order_id,
        ];
        return $this->request('/api/v2/payment/merchant/createorder', $payload);
    }

    /**
     * Query order status
     * POST /api/v2/payment/merchant/order/queryStatus
     */
    public function query_order(string $order_id, string $order_no = ''): ?array {
        $params = ['orderId' => $order_id];
        if (!empty($order_no)) {
            $params['orderNo'] = $order_no;
        }
        $res = $this->request('/api/v2/payment/merchant/order/queryStatus', $params);
        // If no data returned, try the other identifier format
        if (empty($res) || empty($res['data']['orderStatus'])) {
            $alt = !empty($order_no) ? ['orderId' => $order_no] : ['orderNo' => $order_id];
            $alt_res = $this->request('/api/v2/payment/merchant/order/queryStatus', $alt);
            if (!empty($alt_res) && !empty($alt_res['data']['orderStatus'])) {
                return $alt_res;
            }
        }
        return $res;
    }

    /**
     * Get list of banks
     */
    public function get_banks(): ?array {
        return $this->request('/api/v2/general/merchant/queryBankList', [
            'businessType' => 0,
        ]);
    }

    /**
     * Validate a bank account (name enquiry)
     */
    public function validate_account(string $bank_code, string $account_number): ?array {
        return $this->request('/api/v2/payment/merchant/payout/queryBankAccount', [
            'bankCode'  => $bank_code,
            'bankAccNo' => $account_number,
        ]);
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
            'orderId'        => $order_id,
            'payeeName'      => $payee_name,
            'payeeBankCode'  => $bank_code,
            'payeeBankAccNo' => $account_number,
            'payeePhoneNo'   => $phone,
            'amount'         => (int)round($amount * 100), // in kobo
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
        return $this->request('/api/v2/merchant/payment/payout/query', [
            'orderId' => $order_id,
        ]);
    }

    public function save_virtual_account(int $user_id, array $data, int $is_active = 1, bool $is_dynamic = false): void {
        // ensure is_dynamic column exists
        try { db()->exec("ALTER TABLE virtual_accounts ADD COLUMN is_dynamic TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active"); } catch (\Throwable $e) {}
        $acct_no = $data['virtualAccountNo'] ?? $data['payerVirtualAccNo'] ?? '';
        if (!$acct_no) return;

        $chk = db()->prepare("SELECT id FROM virtual_accounts WHERE account_number=? LIMIT 1");
        $chk->execute([$acct_no]);
        if ($chk->fetch()) return;

        $bank_name = $data['bankName'] ?? $data['payerBankName'] ?? 'PalmPay';
        $raw_name = $data['accountName'] ?? $data['payerAccountName'] ?? $data['customerName'] ?? 'Gracehubtels';
        // Strip palmPay's "(Pay NGN X.XX)" suffix for permanent accounts
        $acct_name = preg_replace('/\s*\(Pay\s+NGN[\d,.]*\)\s*$/i', '', $raw_name);
        $ref = $data['orderNo'] ?? $data['virtualAccountNo'] ?? $acct_no;

        db()->prepare(
            "INSERT INTO virtual_accounts (user_id,provider,bank_name,account_number,account_name,bank_code,is_active,is_dynamic)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([
            $user_id, 'palmpay', $bank_name, $acct_no, $acct_name, $ref, $is_active, (int)$is_dynamic,
        ]);
    }
}

// ── Cached bank list from DB ───────────────────────────────────────────
function get_palmpay_banks(): array {
    $cached = setting('palmpay_banks_cache');
    if ($cached) {
        $banks = json_decode($cached, true);
        if (is_array($banks) && count($banks) > 0) return $banks;
    }

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
        ['bankCode'=>'000015','bankName'=>'Zenith Bank'],
        ['bankCode'=>'100033','bankName'=>'PalmPay'],
        ['bankCode'=>'100004','bankName'=>'Opay'],
        ['bankCode'=>'090267','bankName'=>'Kuda Bank'],
        ['bankCode'=>'000008','bankName'=>'Fidelity Bank'],
        ['bankCode'=>'000019','bankName'=>'Polaris Bank'],
        ['bankCode'=>'000018','bankName'=>'JAIZ Bank'],
        ['bankCode'=>'000007','bankName'=>'Stanbic IBTC'],
        ['bankCode'=>'000016','bankName'=>'FCMB'],
        ['bankCode'=>'000022','bankName'=>'Heritage Bank'],
    ];

    // Try PalmPay API for live bank list (deduped against fallback)
    $api_banks = [];
    $api = new PalmPayAPI();
    if ($api->is_configured()) {
        $res = $api->get_banks();
        $api_banks = $res['data'] ?? [];
        if (!is_array($api_banks)) $api_banks = [$api_banks];
    }

    $all = array_merge($api_banks, $fallback);
    $seen = []; $unique = [];
    foreach ($all as $b) {
        $code = $b['bankCode'] ?? '';
        $name = $b['bankName'] ?? '';
        if ($code && $name && !isset($seen[$code])) { $seen[$code]=1; $unique[]=$b; }
    }
    usort($unique, fn($a,$b) => strcmp($a['bankName']??'', $b['bankName']??''));

    setting_set('palmpay_banks_cache', json_encode($unique));
    return $unique;
}