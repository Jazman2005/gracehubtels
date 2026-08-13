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
        
        $existing = $this->get_user_accounts($user_id);
        $has_pp = false;
        $has_pv = false;
        $has_mp = false;
        foreach ($existing as $ex) {
            if ($ex['provider'] === 'paymentpoint') $has_pp = true;
            if ($ex['provider'] === 'payvessel') $has_pv = true;
            if ($ex['provider'] === 'moniepoint') $has_mp = true;
        }

        $results = [];

        // PaymentPoint
        if (!$has_pp && setting('paymentpoint_enabled', '0') === '1') {
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
        }
        
        // PayVessel
        if (!$has_pv && setting('gw_payvessel_active', '0') === '1') {
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
        if (!$has_mp && setting('gw_moniepoint_active', '0') === '1') {
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

        // PalmPay — via Dynamic tab only (no permanent VA endpoint available)

        log_info('wallet', 'VA_CREATE_END', [
            'uid' => $user_id,
            'success' => !empty($results),
            'accounts_created' => count($results)
        ]);

        return ['success' => !empty($results), 'accounts' => $results];
    }

    /**
     * Create virtual account via PaymentPoint API.
     */
    private function create_paymentpoint(array $user, bool $palm, bool $opay): array
    {
        // Deactivate old PaymentPoint VAs so only new ones remain active
        $this->db->prepare("UPDATE virtual_accounts SET is_active=0 WHERE user_id=? AND provider='paymentpoint'")->execute([$user['id']]);

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

        $bankCode = count($bankCodesArr) === 1 ? $bankCodesArr[0] : $bankCodesArr;

        $payload = [
            'email'       => $user['email'],
            'name'        => $user['full_name'],
            'phoneNumber' => $user['phone'],
            'bankCode'    => $bankCode,
            'businessId'  => $biz_id,
        ];

        // Pass BVN/NIN if available (PaymentPoint KYC requirement)
        if (!empty($user['bvn'])) {
            $payload['idType'] = 'bvn';
            $payload['idNumber'] = $user['bvn'];
        } elseif (!empty($user['nin'])) {
            $payload['idType'] = 'nin';
            $payload['idNumber'] = $user['nin'];
        }

        log_info('wallet', 'VA_PAYMENTPOINT_REQUEST', [
            'uid' => $user['id'],
            'email' => $user['email'],
            'bankCode' => $bankCode
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
            CURLOPT_SSL_VERIFYHOST => 2,
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

        $raw = $res;
        $res = json_decode($res, true);

        log_info('wallet', 'VA_PAYMENTPOINT_RESPONSE', [
            'uid' => $user['id'],
            'http_code' => $code,
            'status' => $res['status'] ?? 'unknown',
            'keys' => array_keys($res ?? []),
            'errors' => $res['errors'] ?? null,
            'bank_count' => count($res['bankAccounts'] ?? []),
            'sample_acct_keys' => !empty($res['bankAccounts'][0]) ? array_keys($res['bankAccounts'][0]) : null,
            'customer_keys' => !empty($res['customer']) ? array_keys($res['customer']) : null
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

        // Try to find bank accounts — may be nested under a data key
        // Check for bank-level errors first
        $errors_list = $res['errors'] ?? [];
        if (!empty($errors_list)) {
            log_error('wallet', 'VA_PAYMENTPOINT_BANK_ERRORS', [
                'uid' => $user['id'],
                'errors' => $errors_list
            ]);
        }

        $accounts = $res['bankAccounts'] ?? $res['data']['bankAccounts'] ?? $res['accounts'] ?? $res['data']['accounts'] ?? [];
        $customer_data = $res['customer'] ?? $res['data']['customer'] ?? [];

        $count = 0;
        foreach ($accounts as $acct) {
            $this->save_account(
                $user['id'],
                'paymentpoint',
                [
                    'bank_name'          => $acct['bankName'] ?? $acct['bank_name'] ?? '',
                    'bank_code'          => $acct['bankCode'] ?? $acct['bank_code'] ?? null,
                    'account_number'     => $acct['accountNumber'] ?? $acct['account_number'] ?? '',
                    'account_name'       => $acct['accountName'] ?? $acct['account_name'] ?? '',
                    'reserved_account_id' => $acct['Reserved_Account_Id'] ?? $acct['reservedAccountId'] ?? $acct['id'] ?? null,
                    'customer_id'        => $customer_data['customer_id'] ?? $customer_data['id'] ?? $res['customer_id'] ?? null,
                ]
            );
            $count++;
        }

        if ($count === 0) {
            $error_detail = !empty($errors_list) ? ' Bank errors: ' . json_encode($errors_list) : '';
            log_error('wallet', 'VA_PAYMENTPOINT_NO_ACCOUNTS', [
                'uid' => $user['id'],
                'response_sample' => substr($raw, 0, 3000)
            ]);
            return ['success' => false, 'message' => 'No bank accounts returned from PaymentPoint.' . $error_detail];
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
        try {
            $api = new PalmPayAPI();
            if (!$api->is_configured()) {
                log_error('wallet', 'VA_PALMPAY_NOT_CONFIGURED');
                return ['success' => false, 'message' => 'PalmPay not configured'];
            }

            log_info('wallet', 'VA_PALMPAY_REQUEST', ['uid' => $user['id']]);

            $order_id = 'VA_' . $user['id'] . '_' . time();
            $rel_path = dirname(dirname($_SERVER['SCRIPT_NAME']));
            $rel_path = $rel_path === '/' || $rel_path === '\\' ? '' : rtrim($rel_path, '/');
            $notify_url = site_url($rel_path . '/webhook/palmpay/index.php');
            $res = $api->create_order($user, 100, $order_id, $notify_url);

            if (!$res || empty($res['data']['payerVirtualAccNo'])) {
                $msg = $res['respMsg'] ?? ($res['data']['respMsg'] ?? 'PalmPay API error');
                log_error('wallet', 'VA_PALMPAY_API_ERROR', [
                    'uid' => $user['id'], 'message' => $msg,
                    'resp_code' => $res['respCode'] ?? ($res['data']['respCode'] ?? 'unknown')
                ]);
                return ['success' => false, 'message' => $msg];
            }

            $data = $res['data'] ?? [];
            $api->save_virtual_account($user['id'], $data);

            log_info('wallet', 'VA_PALMPAY_SUCCESS', ['uid' => $user['id']]);
            return ['success' => true, 'provider' => 'palmpay_direct', 'data' => $data];
        } catch (Throwable $e) {
            log_error('wallet', 'VA_PALMPAY_EXCEPTION', [
                'uid' => $user['id'], 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return ['success' => false, 'message' => 'Server error. Please try again or contact support.'];
        }
    }

    /**
     * Save a virtual account record to the database.
     */
    private function save_account(int $user_id, string $provider, array $data): void
    {
        // ensure is_dynamic column exists
        try { $this->db->exec("ALTER TABLE virtual_accounts ADD COLUMN is_dynamic TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active"); } catch (\Throwable $e) {}
        // If active record exists, skip
        $chk = $this->db->prepare("SELECT id FROM virtual_accounts WHERE user_id=? AND account_number=? AND is_active=1 LIMIT 1");
        $chk->execute([$user_id, $data['account_number']]);
        if ($chk->fetch()) {
            log_info('wallet', 'VA_ACCOUNT_EXISTS', ['uid' => $user_id, 'account' => $data['account_number']]);
            return;
        }

        // If inactive record exists, reactivate it
        $old = $this->db->prepare("SELECT id FROM virtual_accounts WHERE user_id=? AND account_number=? LIMIT 1");
        $old->execute([$user_id, $data['account_number']]);
        $old = $old->fetch();
        if ($old) {
            $this->db->prepare("UPDATE virtual_accounts SET is_active=1, bank_name=?, bank_code=?, account_name=?, provider=? WHERE id=?")
                ->execute([$data['bank_name'], $data['bank_code'], $data['account_name'], $provider, $old['id']]);
            log_info('wallet', 'VA_ACCOUNT_REACTIVATED', ['uid' => $user_id, 'account' => $data['account_number']]);
            return;
        }

        $this->db->prepare(
            "INSERT INTO virtual_accounts (user_id, provider, bank_name, bank_code, account_number, account_name, is_active) 
             VALUES (?, ?, ?, ?, ?, ?, 1)"
        )->execute([
            $user_id, $provider, $data['bank_name'], $data['bank_code'] ?? null,
            $data['account_number'], $data['account_name'],
        ]);

        log_info('wallet', 'VA_ACCOUNT_SAVED', [
            'uid' => $user_id, 'provider' => $provider, 'account' => $data['account_number']
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
        
        $nin = $user['nin'] ?? '';
        if (empty($user['bvn']) && empty($nin)) {
            return ['success' => false, 'message' => 'NIN is required to generate this account. Please verify your identity.'];
        }

        $banks_to_create = ['999991', '120001']; // PalmPay and 9PSB
        $total_created = 0;
        $last_error = '';
        $success = false;
        $all_data = [];

        foreach ($banks_to_create as $bcode) {
            $payload = [
                'email'       => $user['email'] ?? ($user['phone'].'@'.site_domain()),
                'name'        => $user['full_name'],
                'phoneNumber' => $user['phone'],
                'bankcode'    => [$bcode],
                'account_type'=> 'STATIC',
                'businessid'  => $biz_id
            ];
            
            if (!empty($user['bvn'])) {
                $payload['bvn'] = $user['bvn'];
            } else {
                $payload['nin'] = $nin;
            }

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
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $res = curl_exec($ch);
            $err = curl_error($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($res === false) {
                log_error('wallet', 'VA_PAYVESSEL_CURL_ERROR', ['uid' => $user['id'], 'error' => $err]);
                $last_error = 'Network error connecting to PayVessel';
                continue;
            }

            $res = json_decode($res, true);

            if (!$res || ($res['status'] ?? false) !== true) {
                $msg = $res['message'] ?? 'PayVessel API error';
                log_error('wallet', 'VA_PAYVESSEL_API_ERROR', ['uid' => $user['id'], 'message' => $msg, 'response' => $res]);
                $last_error = $msg;
                continue;
            }

            $banks = $res['banks'] ?? [];
            foreach ($banks as $bank) {
                $this->save_account($user['id'], 'payvessel', [
                    'bank_name'         => $bank['bankName'],
                    'account_number'    => $bank['accountNumber'],
                    'account_name'      => $bank['accountName'],
                    'account_reference' => $bank['trackingReference'] ?? null
                ]);
                $total_created++;
                $success = true;
            }
            $all_data[] = $res;
        }

        if ($total_created > 0) {
            log_info('wallet', 'VA_PAYVESSEL_SUCCESS', ['uid' => $user['id'], 'accounts_created' => $total_created]);
            return ['success' => true, 'provider' => 'payvessel', 'data' => $all_data];
        }

        return ['success' => false, 'message' => $last_error ?: 'Failed to create PayVessel accounts'];
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
            CURLOPT_SSL_VERIFYHOST => 2,
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
            'customerEmail'    => $user['email'] ?? ($user['phone'] . '@'.site_domain()),
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
            CURLOPT_SSL_VERIFYHOST => 2,
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
     * Create a single PaymentPoint bank account for a user.
     */
    public function create_paymentpoint_bank(int $user_id, string $bank_code, string $bank_label = ''): array
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
        $stmt->execute([$user_id]);
        $u = $stmt->fetch();
        if (!$u) return ['success' => false, 'message' => 'User not found'];

        $bearer  = setting('paymentpoint_bearer');
        $api_key = setting('paymentpoint_api_key');
        $biz_id  = setting('paymentpoint_business_id');

        if (!$bearer || !$api_key || !$biz_id) {
            return ['success' => false, 'message' => 'PaymentPoint not configured completely'];
        }

        $payload = [
            'email'       => $u['email'],
            'name'        => $u['full_name'],
            'phoneNumber' => $u['phone'],
            'bankCode'    => [$bank_code],
            'businessId'  => $biz_id,
        ];

        if (!empty($u['bvn'])) { $payload['idType'] = 'bvn'; $payload['idNumber'] = $u['bvn']; }
        elseif (!empty($u['nin'])) { $payload['idType'] = 'nin'; $payload['idNumber'] = $u['nin']; }

        log_info('wallet', 'VA_PAYMENTPOINT_SINGLE', [
            'uid' => $user_id, 'bankCode' => $bank_code
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
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $res = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false) {
            log_error('wallet', 'VA_PAYMENTPOINT_SINGLE_CURL_ERR', ['uid'=>$user_id,'error'=>$err]);
            return ['success' => false, 'message' => 'Network error connecting to PaymentPoint'];
        }

        $res = json_decode($res, true);

        if (!$res || ($res['status'] ?? '') !== 'success') {
            $msg = $res['message'] ?? 'PaymentPoint API error';
            $bank_err = $res['errors'][0] ?? '';
            if ($bank_err) $msg .= ' (HTTP ' . $code . ': ' . $bank_err . ')';
            log_error('wallet', 'VA_PAYMENTPOINT_SINGLE_FAIL', ['uid'=>$user_id,'message'=>$msg,'http_code'=>$code,'response'=>$res]);
            return ['success' => false, 'message' => $msg];
        }

        $accounts = $res['bankAccounts'] ?? $res['data']['bankAccounts'] ?? [];
        $errors_list = $res['errors'] ?? [];

        if (!empty($errors_list)) {
            log_error('wallet', 'VA_PAYMENTPOINT_SINGLE_BANK_ERR', ['uid'=>$user_id,'http_code'=>$code,'errors'=>$errors_list]);
        }

        $customer_data = $res['customer'] ?? $res['data']['customer'] ?? [];
        $count = 0;

        foreach ($accounts as $acct) {
            $this->save_account($user_id, 'paymentpoint', [
                'bank_name'          => $acct['bankName'] ?? $acct['bank_name'] ?? $bank_label,
                'bank_code'          => $acct['bankCode'] ?? $acct['bank_code'] ?? $bank_code,
                'account_number'     => $acct['accountNumber'] ?? $acct['account_number'] ?? '',
                'account_name'       => $acct['accountName'] ?? $acct['account_name'] ?? '',
                'reserved_account_id' => $acct['Reserved_Account_Id'] ?? $acct['reservedAccountId'] ?? null,
                'customer_id'        => $customer_data['customer_id'] ?? $customer_data['id'] ?? null,
            ]);
            $count++;
        }

        if ($count === 0) {
            $err_detail = !empty($errors_list) ? ' Error: ' . json_encode($errors_list) : '';
            return ['success' => false, 'message' => 'No bank account returned from PaymentPoint.' . $err_detail];
        }

        log_info('wallet', 'VA_PAYMENTPOINT_SINGLE_SUCCESS', ['uid'=>$user_id,'bank'=>$bank_code]);
        return ['success' => true, 'account' => $accounts[0], 'message' => $bank_label . ' account generated successfully'];
    }

    /**
     * Create a single PayVessel bank account for a user.
     */
    public function create_payvessel_bank(int $user_id, string $bank_code, string $bank_label = ''): array
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
        $stmt->execute([$user_id]);
        $u = $stmt->fetch();
        if (!$u) return ['success' => false, 'message' => 'User not found'];

        $api_key = setting('gw_payvessel_merchant');
        $api_secret = setting('gw_payvessel_secret');
        $biz_id = setting('gw_payvessel_business_id');

        if (!$api_key || !$api_secret || !$biz_id) {
            return ['success' => false, 'message' => 'PayVessel not configured completely'];
        }

        $account_type = $bank_code === '090175' ? 'DYNAMIC' : 'STATIC';
        $payload = [
            'email'       => $u['email'] ?? ($u['phone'].'@'.site_domain()),
            'name'        => $u['full_name'],
            'phoneNumber' => $u['phone'],
            'bankcode'    => [$bank_code],
            'account_type'=> $account_type,
            'businessid'  => $biz_id
        ];
        if ($account_type === 'STATIC') {
            $nin = $u['nin'] ?? '';
            if (!empty($u['bvn'])) {
                $payload['bvn'] = $u['bvn'];
            } elseif (!empty($nin)) {
                $payload['nin'] = $nin;
            } else {
                return ['success' => false, 'message' => 'NIN is required to generate this account. Please verify your identity.'];
            }
        }

        log_info('wallet', 'VA_PAYVESSEL_SINGLE', ['uid'=>$user_id,'bankCode'=>$bank_code]);

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
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $res = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false) {
            log_error('wallet', 'VA_PAYVESSEL_SINGLE_CURL_ERR', ['uid'=>$user_id,'error'=>$err]);
            return ['success' => false, 'message' => 'Network error connecting to PayVessel'];
        }

        $res = json_decode($res, true);

        if (!$res || ($res['status'] ?? false) !== true) {
            $msg = $res['message'] ?? 'PayVessel API error';
            log_error('wallet', 'VA_PAYVESSEL_SINGLE_FAIL', ['uid'=>$user_id,'message'=>$msg,'response'=>$res]);
            return ['success' => false, 'message' => $msg];
        }

        $banks = $res['banks'] ?? [];
        $count = 0;
        $saved = null;

        foreach ($banks as $bank) {
            $this->save_account($user_id, 'payvessel', [
                'bank_name'         => $bank['bankName'],
                'account_number'    => $bank['accountNumber'],
                'account_name'      => $bank['accountName'],
                'account_reference' => $bank['trackingReference'] ?? null
            ]);
            $count++;
            if (!$saved) $saved = $bank;
        }

        if ($count === 0) {
            return ['success' => false, 'message' => 'No bank account returned from PayVessel.'];
        }

        log_info('wallet', 'VA_PAYVESSEL_SINGLE_SUCCESS', ['uid'=>$user_id,'bank'=>$bank_code]);
        return ['success' => true, 'account' => $saved, 'message' => $bank_label . ' account generated successfully'];
    }

    /**
     * Create a Moniepoint (Monnify) virtual account for a user.
     */
    public function create_monnify_bank(int $user_id): array
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
        $stmt->execute([$user_id]);
        $u = $stmt->fetch();
        if (!$u) return ['success' => false, 'message' => 'User not found'];

        if (empty($u['bvn']) && empty($u['nin'])) {
            return ['success' => false, 'message' => 'BVN or NIN is required for Moniepoint'];
        }

        $api_key = setting('gw_moniepoint_api_key');
        $api_secret = setting('gw_moniepoint_secret');
        $contract = setting('gw_moniepoint_contract');

        if (!$api_key || !$api_secret || !$contract) {
            return ['success' => false, 'message' => 'Monnify not configured completely'];
        }

        $base_url = 'https://api.monnify.com';

        $ch = curl_init($base_url . '/api/v1/auth/login');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Authorization: Basic ' . base64_encode($api_key . ':' . $api_secret)],
            CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $auth_res = curl_exec($ch);
        curl_close($ch);
        $auth_data = json_decode($auth_res, true);
        if (!$auth_data || !isset($auth_data['responseBody']['accessToken'])) {
            return ['success' => false, 'message' => 'Monnify authentication failed'];
        }
        $access_token = $auth_data['responseBody']['accessToken'];

        $account_ref = 'MSJ_' . $user_id . '_' . time();
        $payload = [
            'accountReference' => $account_ref,
            'accountName'      => $u['full_name'],
            'currencyCode'     => 'NGN',
            'contractCode'     => $contract,
            'customerEmail'    => $u['email'] ?? ($u['phone'] . '@'.site_domain()),
            'customerName'     => $u['full_name'],
            'getAllAvailableBanks' => false,
            'preferredBanks'   => ['50515']
        ];
        if (!empty($u['bvn'])) $payload['bvn'] = $u['bvn'];
        else $payload['nin'] = $u['nin'];

        $ch2 = curl_init($base_url . '/api/v2/bank-transfer/reserved-accounts');
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $access_token, 'Content-Type: application/json'],
            CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $res = curl_exec($ch2);
        $err = curl_error($ch2);
        curl_close($ch2);

        if ($res === false) return ['success' => false, 'message' => 'Network error connecting to Monnify'];

        $res_data = json_decode($res, true);
        if (!$res_data || !isset($res_data['requestSuccessful']) || !$res_data['requestSuccessful']) {
            $msg = $res_data['responseMessage'] ?? 'Monnify API error';
            return ['success' => false, 'message' => $msg];
        }

        $body = $res_data['responseBody'];
        $accounts = $body['accounts'] ?? [];
        $count = 0;
        $saved = null;

        foreach ($accounts as $acc) {
            $this->save_account($user_id, 'moniepoint', [
                'bank_name'         => $acc['bankName'],
                'account_number'    => $acc['accountNumber'],
                'account_name'      => $acc['accountName'],
                'account_reference' => $body['accountReference']
            ]);
            $count++;
            if (!$saved) $saved = $acc;
        }

        if ($count === 0) return ['success' => false, 'message' => 'No account returned from Monnify'];
        return ['success' => true, 'account' => $saved, 'message' => 'Moniepoint account generated successfully'];
    }

    /**
     * Get all active virtual accounts for a user.
     */
    public function get_user_accounts(int $user_id): array
    {
        // ensure is_dynamic column exists
        try { $this->db->exec("ALTER TABLE virtual_accounts ADD COLUMN is_dynamic TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active"); } catch (\Throwable $e) {}
        $stmt = $this->db->prepare(
            "SELECT * FROM virtual_accounts WHERE user_id=? AND is_active=1 AND (is_dynamic IS NULL OR is_dynamic=0) ORDER BY created_at"
        );
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    }
}
