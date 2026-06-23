<?php
// user/core/virtual-account.php — Virtual Account Management
// Handles PaymentPoint and PalmPay virtual account creation

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/palmpay.php';

class VirtualAccountManager
{
    private PDO $db;

    public function __construct()
    {
        $this->db = db();
    }

    /**
     * Create virtual accounts for a user via all configured providers.
     */
    public function create_for_user(int $user_id): array
    {
        log_info('wallet', 'VA_CREATE_START', ['uid' => $user_id]);

        $stmt = $this->db->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
        $stmt->execute([$user_id]);
        $u = $stmt->fetch();

        if (!$u) {
            log_error('wallet', 'VA_CREATE_USER_NOT_FOUND', ['uid' => $user_id]);
            return ['success' => false, 'message' => 'User not found'];
        }

        $results = [];

        // PaymentPoint
        $pp_palm = setting('paymentpoint_palmpay_enabled', '0') === '1';
        $pp_opay = setting('paymentpoint_opay_enabled', '0') === '1';
        if ($pp_palm || $pp_opay) {
            $pp = $this->create_paymentpoint($u, $pp_palm, $pp_opay);
            if ($pp['success']) {
                $results[] = $pp;
            } else {
                log_error('wallet', 'VA_PAYMENTPOINT_FAILED', [
                    'uid' => $user_id,
                    'message' => $pp['message'] ?? 'Unknown error'
                ]);
            }
        }
        
        // PayVessel
        if (setting('gw_payvessel_active', '0') === '1') {
            $pv = $this->create_payvessel($u);
            if ($pv['success']) {
                $results[] = $pv;
            } else {
                log_error('wallet', 'VA_PAYVESSEL_FAILED', [
                    'uid' => $user_id,
                    'message' => $pv['message'] ?? 'Unknown error'
                ]);
            }
        }

        // Monnify (Moniepoint)
        if (setting('gw_moniepoint_active', '0') === '1') {
            $monnify = $this->create_monnify($u);
            if ($monnify['success']) {
                $results[] = $monnify;
            } else {
                log_error('wallet', 'VA_MONNIFY_FAILED', [
                    'uid' => $user_id,
                    'message' => $monnify['message'] ?? 'Unknown error'
                ]);
            }
        }

        // PalmPay (if enabled)
        if (setting('palmpay_enabled', '0') === '1') {
            $palm = $this->create_palmpay_direct($u);
            if ($palm['success']) {
                $results[] = $palm;
            } else {
                log_error('wallet', 'VA_PALMPAY_FAILED', [
                    'uid' => $user_id,
                    'message' => $palm['message'] ?? 'Unknown error'
                ]);
            }
        }

        $success = !empty($results);
        log_info('wallet', 'VA_CREATE_END', [
            'uid' => $user_id,
            'success' => $success,
            'accounts_created' => count($results)
        ]);

        return ['success' => $success, 'accounts' => $results];
    }

    /**
     * Create virtual account via PaymentPoint API.
     */
    private function create_paymentpoint(array $user, bool $palm, bool $opay): array
    {
        $bearer  = setting('paymentpoint_bearer');
        $api_key = setting('paymentpoint_api_key');
        $biz_id  = setting('paymentpoint_business_id');

        if (!$bearer || !$api_key || !$biz_id) {
            log_error('wallet', 'VA_PAYMENTPOINT_NOT_CONFIGURED');
            return ['success' => false, 'message' => 'PaymentPoint not configured completely'];
        }

        $bankCodesArr = [];
        if ($palm) $bankCodesArr[] = '20946'; // PalmPay
        if ($opay) $bankCodesArr[] = '20897'; // OPay
        
        if (empty($bankCodesArr)) return ['success' => false, 'message' => 'No banks enabled for PaymentPoint'];

        $payload = [
            'email'       => $user['email'],
            'name'        => $user['full_name'],
            'phoneNumber' => $user['phone'],
            'bankCode'    => array_values($bankCodesArr),
            'businessId'  => $biz_id,
        ];

        log_info('wallet', 'VA_PAYMENTPOINT_REQUEST', [
            'uid' => $user['id'],
            'email' => $user['email']
        ]);

        $ch = curl_init('https://api.paymentpoint.co/api/v1/createVirtualAccount');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $bearer,
                'api-key: ' . $api_key,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT       => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $res = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false) {
            log_error('wallet', 'VA_PAYMENTPOINT_CURL_ERROR', [
                'uid' => $user['id'],
                'error' => $err
            ]);
            return ['success' => false, 'message' => 'Network error connecting to PaymentPoint'];
        }

        $res = json_decode($res, true);

        log_info('wallet', 'VA_PAYMENTPOINT_RESPONSE', [
            'uid' => $user['id'],
            'http_code' => $code,
            'status' => $res['status'] ?? 'unknown'
        ]);

        if (!$res || ($res['status'] ?? '') !== 'success') {
            $msg = $res['message'] ?? 'PaymentPoint API error';
            log_error('wallet', 'VA_PAYMENTPOINT_API_ERROR', [
                'uid' => $user['id'],
                'message' => $msg,
                'response' => $res
            ]);
            return ['success' => false, 'message' => $msg];
        }

        $count = 0;
        foreach ($res['bankAccounts'] ?? [] as $acct) {
            $this->save_account(
                $user['id'],
                'paymentpoint',
                [
                    'bank_name'          => $acct['bankName'],
                    'bank_code'          => $acct['bankCode'],
                    'account_number'     => $acct['accountNumber'],
                    'account_name'       => $acct['accountName'],
                    'reserved_account_id' => $acct['Reserved_Account_Id'] ?? null,
                    'customer_id'        => $res['customer']['customer_id'] ?? null,
                ]
            );
            $count++;
        }

        log_info('wallet', 'VA_PAYMENTPOINT_SUCCESS', [
            'uid' => $user['id'],
            'accounts_count' => $count
        ]);

        return ['success' => true, 'provider' => 'paymentpoint', 'data' => $res];
    }

    /**
     * Create virtual account via PalmPay Direct API.
     */
    private function create_palmpay_direct(array $user): array
    {
        $api = new PalmPayAPI();

        if (!$api->is_configured()) {
            log_error('wallet', 'VA_PALMPAY_NOT_CONFIGURED');
            return ['success' => false, 'message' => 'PalmPay not configured'];
        }

        log_info('wallet', 'VA_PALMPAY_REQUEST', ['uid' => $user['id']]);

        $res = $api->create_virtual_account($user);

        if (!$res || ($res['respCode'] ?? '') !== '00000000') {
            $msg = $res['respMsg'] ?? 'PalmPay API error';
            log_error('wallet', 'VA_PALMPAY_API_ERROR', [
                'uid' => $user['id'],
                'message' => $msg,
                'resp_code' => $res['respCode'] ?? 'unknown'
            ]);
            return ['success' => false, 'message' => $msg];
        }

        $data = $res['data'] ?? [];
        $api->save_virtual_account($user['id'], $data);

        log_info('wallet', 'VA_PALMPAY_SUCCESS', ['uid' => $user['id']]);

        return ['success' => true, 'provider' => 'palmpay_direct', 'data' => $data];
    }

    /**
     * Save a virtual account record to the database.
     */
    private function save_account(int $user_id, string $provider, array $data): void
    {
        $chk = $this->db->prepare(
            "SELECT id FROM virtual_accounts WHERE user_id=? AND account_number=? LIMIT 1"
        );
        $chk->execute([$user_id, $data['account_number']]);

        if ($chk->fetch()) {
            log_info('wallet', 'VA_ACCOUNT_EXISTS', [
                'uid' => $user_id,
                'account' => $data['account_number']
            ]);
            return;
        }

        $this->db->prepare(
            "INSERT INTO virtual_accounts 
             (user_id, provider, bank_name, bank_code, account_number, account_name, 
              account_reference, reserved_account_id, customer_id) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $user_id,
            $provider,
            $data['bank_name'],
            $data['bank_code'] ?? null,
            $data['account_number'],
            $data['account_name'],
            $data['account_reference'] ?? null,
            $data['reserved_account_id'] ?? null,
            $data['customer_id'] ?? null,
        ]);

        log_info('wallet', 'VA_ACCOUNT_SAVED', [
            'uid' => $user_id,
            'provider' => $provider,
            'account' => $data['account_number']
        ]);
    }

    /**
     * Create virtual account via PayVessel API.
     */
    private function create_payvessel(array $user): array
    {
        $api_key = setting('gw_payvessel_merchant');
        $api_secret = setting('gw_payvessel_secret');
        $biz_id = setting('gw_payvessel_business_id');

        if (!$api_key || !$api_secret || !$biz_id) {
            log_error('wallet', 'VA_PAYVESSEL_NOT_CONFIGURED');
            return ['success' => false, 'message' => 'PayVessel not configured completely'];
        }

        $payload = [
            'email'       => $user['email'] ?? ($user['phone'].'@msjpay.com'),
            'name'        => $user['full_name'],
            'phoneNumber' => $user['phone'],
            'bankcode'    => ['120001', '999991'], // Wema and 9PSB
            'account_type'=> 'STATIC',
            'businessid'  => $biz_id
        ];

        log_info('wallet', 'VA_PAYVESSEL_REQUEST', [
            'uid' => $user['id'],
            'email' => $payload['email']
        ]);

        $ch = curl_init('https://api.payvessel.com/pms/api/external/request/customerReservedAccount/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'api-key: ' . $api_key,
                'api-secret: Bearer ' . $api_secret,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT       => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $res = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false) {
            log_error('wallet', 'VA_PAYVESSEL_CURL_ERROR', [
                'uid' => $user['id'],
                'error' => $err
            ]);
            return ['success' => false, 'message' => 'Network error connecting to PayVessel'];
        }

        $res = json_decode($res, true);

        log_info('wallet', 'VA_PAYVESSEL_RESPONSE', [
            'uid' => $user['id'],
            'http_code' => $code,
            'status' => $res['status'] ?? false
        ]);

        if (!$res || ($res['status'] ?? false) !== true) {
            $msg = $res['message'] ?? 'PayVessel API error';
            log_error('wallet', 'VA_PAYVESSEL_API_ERROR', [
                'uid' => $user['id'],
                'message' => $msg,
                'response' => $res
            ]);
            return ['success' => false, 'message' => $msg];
        }

        $count = 0;
        $banks = $res['banks'] ?? [];

        foreach ($banks as $bank) {
            $this->save_account($user['id'], 'payvessel', [
                'bank_name'         => $bank['bankName'],
                'account_number'    => $bank['accountNumber'],
                'account_name'      => $bank['accountName'],
                'account_reference' => $bank['trackingReference'] ?? null
            ]);
            $count++;
        }

        log_info('wallet', 'VA_PAYVESSEL_SUCCESS', [
            'uid' => $user['id'],
            'accounts_created' => $count
        ]);

        return ['success' => true, 'provider' => 'payvessel', 'data' => $res];
    }

    /**
     * Create virtual account via Monnify API (For Moniepoint).
     */
    private function create_monnify(array $user): array
    {
        $api_key = setting('gw_moniepoint_api_key');
        $api_secret = setting('gw_moniepoint_secret');
        $contract = setting('gw_moniepoint_contract');

        if (!$api_key || !$api_secret || !$contract) {
            log_error('wallet', 'VA_MONNIFY_NOT_CONFIGURED');
            return ['success' => false, 'message' => 'Monnify not configured completely'];
        }

        if (empty($user['bvn']) && empty($user['nin'])) {
            log_error('wallet', 'VA_MONNIFY_NO_BVN_NIN');
            return ['success' => false, 'message' => 'User BVN or NIN is required for Monnify'];
        }

        $base_url = 'https://api.monnify.com';

        // 1. Authenticate to get Access Token
        $ch = curl_init($base_url . '/api/v1/auth/login');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . base64_encode($api_key . ':' . $api_secret)
            ],
            CURLOPT_TIMEOUT       => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $auth_res = curl_exec($ch);
        curl_close($ch);

        $auth_data = json_decode($auth_res, true);
        if (!$auth_data || !isset($auth_data['responseBody']['accessToken'])) {
            return ['success' => false, 'message' => 'Monnify authentication failed'];
        }
        $access_token = $auth_data['responseBody']['accessToken'];

        // 2. Create Reserved Account
        $account_ref = 'MSJ_' . $user['id'] . '_' . time();
        $payload = [
            'accountReference' => $account_ref,
            'accountName'      => $user['full_name'],
            'currencyCode'     => 'NGN',
            'contractCode'     => $contract,
            'customerEmail'    => $user['email'] ?? ($user['phone'] . '@msjpay.com'),
            'customerName'     => $user['full_name'],
            'getAllAvailableBanks' => false,
            'preferredBanks'   => ['50515'] // Moniepoint
        ];

        if (!empty($user['bvn'])) {
            $payload['bvn'] = $user['bvn'];
        } else {
            $payload['nin'] = $user['nin'];
        }

        $ch2 = curl_init($base_url . '/api/v2/bank-transfer/reserved-accounts');
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $access_token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT       => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $res = curl_exec($ch2);
        $err = curl_error($ch2);
        curl_close($ch2);

        if ($res === false) {
            return ['success' => false, 'message' => 'Network error connecting to Monnify'];
        }

        $res_data = json_decode($res, true);
        if (!$res_data || !isset($res_data['requestSuccessful']) || !$res_data['requestSuccessful']) {
            $msg = $res_data['responseMessage'] ?? 'Monnify API error';
            log_error('wallet', 'VA_MONNIFY_API_ERROR', [
                'uid' => $user['id'],
                'message' => $msg,
                'response' => $res_data
            ]);
            return ['success' => false, 'message' => $msg];
        }

        $body = $res_data['responseBody'];
        $accounts = $body['accounts'] ?? [];
        $count = 0;

        foreach ($accounts as $acc) {
            $this->save_account($user['id'], 'moniepoint', [
                'bank_name'         => $acc['bankName'],
                'account_number'    => $acc['accountNumber'],
                'account_name'      => $acc['accountName'],
                'account_reference' => $body['accountReference']
            ]);
            $count++;
        }

        log_info('wallet', 'VA_MONNIFY_SUCCESS', [
            'uid' => $user['id'],
            'accounts_created' => $count
        ]);

        return ['success' => true, 'provider' => 'moniepoint', 'data' => $res_data];
    }

    /**
     * Get all active virtual accounts for a user.
     */
    public function get_user_accounts(int $user_id): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM virtual_accounts WHERE user_id=? AND is_active=1 ORDER BY created_at"
        );
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    }
}
