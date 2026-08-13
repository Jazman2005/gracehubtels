<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/api_router.php';

// Auto-migrate international_airtime_orders table
try { db()->exec("CREATE TABLE IF NOT EXISTS international_airtime_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    transaction_ref VARCHAR(64) NOT NULL UNIQUE,
    api_registry_id INT NOT NULL,
    provider_name VARCHAR(100) NOT NULL,
    provider_ref VARCHAR(150) DEFAULT NULL,
    country VARCHAR(5) NOT NULL,
    operator_id VARCHAR(100) NOT NULL,
    operator_name VARCHAR(150) DEFAULT NULL,
    phone VARCHAR(30) NOT NULL,
    amount_ngn DECIMAL(12,2) NOT NULL,
    status ENUM('pending','processing','success','failed') NOT NULL DEFAULT 'pending',
    raw_response TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY user_id (user_id), KEY status (status)
)"); } catch (\Throwable $e) {}

// A small curated list of common destinations, not a provider catalog — ISO
// 3166-1 alpha-2 codes are standard public data, safe to hardcode (unlike
// any provider's opaque operator/product codes, which never are). Not
// exhaustive; the country picker also accepts a free-typed 2-letter code.
const INTERNATIONAL_AIRTIME_COUNTRIES = [
    'US' => 'United States', 'GB' => 'United Kingdom', 'CA' => 'Canada', 'GH' => 'Ghana',
    'KE' => 'Kenya', 'ZA' => 'South Africa', 'IN' => 'India', 'CN' => 'China',
    'AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia', 'DE' => 'Germany', 'FR' => 'France',
    'IT' => 'Italy', 'ES' => 'Spain', 'NL' => 'Netherlands', 'AU' => 'Australia',
    'PH' => 'Philippines', 'PK' => 'Pakistan', 'BD' => 'Bangladesh', 'EG' => 'Egypt',
];

function get_international_airtime_countries(): array {
    return INTERNATIONAL_AIRTIME_COUNTRIES;
}

// --- Reteller-specific catalog fetch/purchase. Add a second provider by
// adding an elseif branch here, keyed the same way as every other provider
// integration in this codebase (stripos($pname,'x')) — never rewrite the
// aggregate/relocate helpers themselves for a new provider's shape.
function reteller_fetch_intl_airtime_operators(array $prov, string $iso2): array {
    $pname = strtolower($prov['provider_name'] ?? '');
    if (strpos($pname, 'reteller') === false) return [];
    $headers = ['Content-Type: application/json'];
    if (!empty($prov['public_key']) && !empty($prov['secret_key'])) {
        $headers[] = 'Authorization: Bearer ' . $prov['public_key'] . ':' . $prov['secret_key'];
    }
    $url = rtrim($prov['api_base_url'], '/') . '/airtime/operators?country=' . urlencode($iso2);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$headers, CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $data = $res ? json_decode($res, true) : null;
    log_api($prov['provider_name'] ?? 'api_reteller', 'airtime/operators', ['country'=>$iso2], $data, in_array($code,[200,201]));
    if (!$data || !in_array($code, [200,201]) || empty($data['status'])) return [];

    $out = [];
    foreach ($data['data']['operators'] ?? [] as $op) {
        $type = strtoupper($op['denominationType'] ?? 'FIXED');
        if ($type === 'RANGE' && !empty($op['range'])) {
            $r = $op['range'];
            $out[] = [
                'operator_id' => (string)$op['id'],
                'operator_name' => $op['name'] ?? '',
                'type' => 'RANGE',
                'min' => (float)($r['min'] ?? 0),
                'max' => (float)($r['max'] ?? 0),
                'ngn_min' => (float)($r['ngnMin'] ?? 0),
                'ngn_max' => (float)($r['ngnMax'] ?? 0),
            ];
        } else {
            foreach ($op['options'] ?? [] as $opt) {
                $out[] = [
                    'operator_id' => (string)$op['id'],
                    'operator_name' => $op['name'] ?? '',
                    'type' => 'FIXED',
                    'sender_amount' => (float)($opt['sender'] ?? 0),
                    'ngn_price' => (float)($opt['ngn'] ?? 0),
                    'label' => $opt['label'] ?? '',
                ];
            }
        }
    }
    return $out;
}

function reteller_purchase_intl_airtime(array $prov, string $operator_id, float $sender_amount, string $phone, string $country): ?array {
    $headers = ['Content-Type: application/json'];
    if (!empty($prov['public_key']) && !empty($prov['secret_key'])) {
        $headers[] = 'Authorization: Bearer ' . $prov['public_key'] . ':' . $prov['secret_key'];
    }
    $url = rtrim($prov['api_base_url'], '/') . '/airtime/purchase';
    $payload = ['operator_id'=>$operator_id, 'amount'=>$sender_amount, 'phone'=>$phone, 'country'=>$country];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($payload), CURLOPT_HTTPHEADER=>$headers, CURLOPT_TIMEOUT=>30, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    $d = $res ? json_decode($res, true) : null;
    if ($res === false) $d = ['_http_code'=>500, '_network_error'=>true, 'message'=>"cURL Error: $err"];
    if ($d) $d['_http_code'] = $code;
    log_api($prov['provider_name'] ?? 'api_reteller', 'airtime/purchase', $payload, $d, in_array($code,[200,201]));
    return $d;
}

function fetch_international_airtime_catalog(string $iso2): array {
    return aggregate_provider_catalog('international_airtime', $iso2, function(array $prov, string $region) {
        return reteller_fetch_intl_airtime_operators($prov, $region);
    });
}

// Resolves the exact NGN price for a specific catalog item, given whatever
// amount the user actually chose (for RANGE items, any value between
// min/max; for FIXED items, the option's own fixed sender_amount). Never
// trusts a client-submitted NGN price — always derives it from the item's
// own live-fetched conversion fields.
function international_airtime_price_ngn(array $item, float $sender_amount): float {
    if (($item['type'] ?? '') === 'RANGE') {
        $min = (float)($item['min'] ?? 0);
        if ($min <= 0) return 0;
        $rate = (float)($item['ngn_min'] ?? 0) / $min; // linear NGN-per-unit rate
        return round($sender_amount * $rate, 2);
    }
    return (float)($item['ngn_price'] ?? 0);
}

function process_international_airtime_purchase(int $uid, array $post): array {
    $debit = null; $total = 0;
    try {
        if (detect_tamper($post)) return ['success'=>false,'message'=>'Invalid input detected'];
        $country = strtoupper(trim($post['country'] ?? ''));
        $api_registry_id = (int)($post['api_registry_id'] ?? 0);
        $operator_id = (string)($post['operator_id'] ?? '');
        $phone = trim($post['phone'] ?? '');
        $sender_amount = (float)($post['amount'] ?? 0);
        $pin = (string)($post['pin'] ?? '');
        if (strlen($country) !== 2) return ['success'=>false,'message'=>'Select a country'];
        if ($api_registry_id < 1 || $operator_id === '') return ['success'=>false,'message'=>'Select an operator'];
        if ($phone === '' || strlen($phone) < 6) return ['success'=>false,'message'=>'Enter a valid recipient number'];
        if ($sender_amount <= 0) return ['success'=>false,'message'=>'Enter a valid amount'];
        if (!verify_pin($uid, $pin)) return ['success'=>false,'message'=>'Invalid transaction PIN'];
        if (!claim_purchase_lock($uid, 'international_airtime', [$country, $operator_id, $phone, $sender_amount])) {
            return ['success'=>false,'message'=>'Duplicate request detected. Please wait a moment and try again.'];
        }
        $sig = purchase_signature('international_airtime', [$country, $operator_id, $phone, $sender_amount]);

        // Re-fetch the SAME provider's live catalog and re-locate this exact
        // operator — the server never trusts a client-submitted price, and
        // never falls back to a different provider than the one the user's
        // selection actually came from.
        $item = relocate_catalog_item('international_airtime', $country, $api_registry_id, 'operator_id', $operator_id,
            fn($prov, $region) => reteller_fetch_intl_airtime_operators($prov, $region));
        if (!$item) return ['success'=>false,'message'=>'This offer is no longer available. Please refresh and try again.'];

        if (($item['type'] ?? '') === 'RANGE') {
            if ($sender_amount < (float)$item['min'] || $sender_amount > (float)$item['max']) {
                return ['success'=>false,'message'=>'Amount must be between '.$item['min'].' and '.$item['max']];
            }
        } else {
            // FIXED — the submitted amount must match the option's own fixed
            // sender_amount exactly, not just any positive number.
            $sender_amount = (float)$item['sender_amount'];
        }
        $ngn_price = international_airtime_price_ngn($item, $sender_amount);
        if ($ngn_price <= 0) return ['success'=>false,'message'=>'Could not price this offer. Please try again.'];

        $markup = (float)setting('international_airtime_markup_percent', '0');
        $total = round($ngn_price * (1 + $markup/100), 2);

        $debit = debit_wallet($uid, $total, 'international_airtime', "International airtime ({$item['operator_name']}) to {$phone}", ['country'=>$country,'operator_id'=>$operator_id], $sig);
        if (!$debit['success']) return $debit;

        $prov = $item['_prov'];
        db()->prepare("INSERT INTO international_airtime_orders (user_id,transaction_ref,api_registry_id,provider_name,country,operator_id,operator_name,phone,amount_ngn,status) VALUES (?,?,?,?,?,?,?,?,?,'pending')")
            ->execute([$uid, $debit['ref'], $api_registry_id, $prov['provider_name'], $country, $operator_id, $item['operator_name'], $phone, $total]);

        $res = reteller_purchase_intl_airtime($prov, $operator_id, $sender_amount, $phone, $country);
        db()->prepare("UPDATE transactions SET provider=? WHERE transaction_ref=?")->execute([$prov['provider_name'], $debit['ref']]);

        if ($res && in_array($res['_http_code']??0,[200,201]) && !is_api_response_failure($res) && !is_api_response_pending($res)) {
            // Irrevocable — flip status before any other write that could throw.
            update_tx_status($debit['ref'], 'success');
            db()->prepare("UPDATE international_airtime_orders SET status='success', provider_ref=? WHERE transaction_ref=?")
                ->execute([$res['data']['reference'] ?? '', $debit['ref']]);
            save_tx_response($debit['ref'], provider_response_text($res));
            notify_user($uid, 'International Airtime Sent', "{$item['operator_name']} airtime sent to {$phone}", 'success', null, $debit['balance_before'], $debit['balance_after']);
            return ['success'=>true,'message'=>'International airtime sent successfully','ref'=>$debit['ref']];
        }

        if ($res && (is_api_response_pending($res) || !empty($res['_network_error']) || is_duplicate_reference_response($res))) {
            update_tx_status($debit['ref'], 'processing');
            db()->prepare("UPDATE international_airtime_orders SET status='processing', provider_ref=? WHERE transaction_ref=?")
                ->execute([$res['data']['reference'] ?? '', $debit['ref']]);
            save_tx_response($debit['ref'], provider_response_text($res));
            notify_user($uid, 'International Airtime Processing', 'Your purchase is being confirmed with the provider.', 'info', null, $debit['balance_before'], $debit['balance_after']);
            return ['success'=>true,'message'=>'We could not immediately confirm this purchase. It is being verified — check your receipt shortly.','ref'=>$debit['ref'],'pending'=>true];
        }

        $err = $res['message'] ?? 'Purchase failed.';
        refund_failed_transaction($debit['ref'], $uid, $total, $err);
        db()->prepare("UPDATE international_airtime_orders SET status='failed' WHERE transaction_ref=?")->execute([$debit['ref']]);
        save_tx_response($debit['ref'], provider_response_text($res) ?: $err);
        return ['success'=>true,'message'=>$err,'ref'=>$debit['ref'],'refunded'=>true];
    } catch (\Throwable $e) {
        if ($debit !== null && ($debit['success'] ?? false)) {
            refund_failed_transaction($debit['ref'], $uid, $total, 'System error');
        }
        log_error('international_airtime', 'Purchase exception: ' . $e->getMessage(), ['line'=>$e->getLine(), 'uid'=>$uid]);
        return ['success' => false, 'message' => 'Purchase failed. Please try again.'];
    }
}
