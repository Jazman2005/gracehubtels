<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/api_router.php';

// Auto-migrate giftcard_buy_orders table
try { db()->exec("CREATE TABLE IF NOT EXISTS giftcard_buy_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    transaction_ref VARCHAR(64) NOT NULL UNIQUE,
    api_registry_id INT NOT NULL,
    provider_name VARCHAR(100) NOT NULL,
    provider_ref VARCHAR(150) DEFAULT NULL,
    country VARCHAR(5) NOT NULL,
    product_id VARCHAR(100) NOT NULL,
    brand VARCHAR(100) DEFAULT NULL,
    recipient_email VARCHAR(150) NOT NULL,
    amount_ngn DECIMAL(12,2) NOT NULL,
    card_code TEXT DEFAULT NULL,
    status ENUM('pending','processing','success','failed') NOT NULL DEFAULT 'pending',
    raw_response TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY user_id (user_id), KEY status (status)
)"); } catch (\Throwable $e) {}

// Same curated ISO alpha-2 list used by international_airtime.php — standard
// public data, not a provider catalog, safe to hardcode.
const GIFTCARD_BUY_COUNTRIES = [
    'US' => 'United States', 'GB' => 'United Kingdom', 'CA' => 'Canada', 'GH' => 'Ghana',
    'KE' => 'Kenya', 'ZA' => 'South Africa', 'IN' => 'India', 'AE' => 'United Arab Emirates',
    'DE' => 'Germany', 'FR' => 'France', 'AU' => 'Australia',
];

function get_giftcard_buy_countries(): array {
    return GIFTCARD_BUY_COUNTRIES;
}

// --- Reteller-specific catalog fetch/purchase. Add a second provider by
// adding an elseif branch here, same convention as every other provider
// integration in this codebase.
function reteller_fetch_giftcards(array $prov, string $iso2): array {
    $pname = strtolower($prov['provider_name'] ?? '');
    if (strpos($pname, 'reteller') === false) return [];
    $headers = ['Content-Type: application/json'];
    if (!empty($prov['public_key']) && !empty($prov['secret_key'])) {
        $headers[] = 'Authorization: Bearer ' . $prov['public_key'] . ':' . $prov['secret_key'];
    }
    $url = rtrim($prov['api_base_url'], '/') . '/giftcards?country=' . urlencode($iso2);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$headers, CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $data = $res ? json_decode($res, true) : null;
    log_api($prov['provider_name'] ?? 'api_reteller', 'giftcards', ['country'=>$iso2], $data, in_array($code,[200,201]));
    if (!$data || !in_array($code, [200,201]) || empty($data['status'])) return [];

    $out = [];
    foreach ($data['data']['products'] ?? [] as $p) {
        foreach ($p['options'] ?? [] as $opt) {
            $out[] = [
                'product_id' => (string)$p['id'],
                'brand' => $p['brand'] ?? ($p['name'] ?? ''),
                'name' => $p['name'] ?? '',
                'sender_amount' => (float)($opt['sender'] ?? 0),
                'ngn_price' => (float)($opt['ngn'] ?? 0),
            ];
        }
    }
    return $out;
}

function reteller_purchase_giftcard(array $prov, string $product_id, float $sender_amount, string $recipient_email): ?array {
    $headers = ['Content-Type: application/json'];
    if (!empty($prov['public_key']) && !empty($prov['secret_key'])) {
        $headers[] = 'Authorization: Bearer ' . $prov['public_key'] . ':' . $prov['secret_key'];
    }
    $url = rtrim($prov['api_base_url'], '/') . '/giftcards/purchase';
    $payload = ['product_id'=>$product_id, 'amount'=>$sender_amount, 'recipient_email'=>$recipient_email];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($payload), CURLOPT_HTTPHEADER=>$headers, CURLOPT_TIMEOUT=>30, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    $d = $res ? json_decode($res, true) : null;
    if ($res === false) $d = ['_http_code'=>500, '_network_error'=>true, 'message'=>"cURL Error: $err"];
    if ($d) $d['_http_code'] = $code;
    log_api($prov['provider_name'] ?? 'api_reteller', 'giftcards/purchase', $payload, $d, in_array($code,[200,201]));
    return $d;
}

function fetch_giftcard_buy_catalog(string $iso2): array {
    return aggregate_provider_catalog('giftcard_buy', $iso2, function(array $prov, string $region) {
        return reteller_fetch_giftcards($prov, $region);
    });
}

function process_giftcard_buy_purchase(int $uid, array $post): array {
    $debit = null; $total = 0;
    try {
        if (detect_tamper($post)) return ['success'=>false,'message'=>'Invalid input detected'];
        $country = strtoupper(trim($post['country'] ?? ''));
        $api_registry_id = (int)($post['api_registry_id'] ?? 0);
        $product_id = (string)($post['product_id'] ?? '');
        $email = trim($post['recipient_email'] ?? '');
        $pin = (string)($post['pin'] ?? '');
        if (strlen($country) !== 2) return ['success'=>false,'message'=>'Select a country'];
        if ($api_registry_id < 1 || $product_id === '') return ['success'=>false,'message'=>'Select a gift card'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['success'=>false,'message'=>'Enter a valid recipient email'];
        if (!verify_pin($uid, $pin)) return ['success'=>false,'message'=>'Invalid transaction PIN'];
        if (!claim_purchase_lock($uid, 'giftcard_buy', [$country, $product_id, $email])) {
            return ['success'=>false,'message'=>'Duplicate request detected. Please wait a moment and try again.'];
        }
        $sig = purchase_signature('giftcard_buy', [$country, $product_id, $email]);

        $item = relocate_catalog_item('giftcard_buy', $country, $api_registry_id, 'product_id', $product_id,
            fn($prov, $region) => reteller_fetch_giftcards($prov, $region));
        if (!$item) return ['success'=>false,'message'=>'This gift card is no longer available. Please refresh and try again.'];

        $markup = (float)setting('giftcard_buy_markup_percent', '0');
        $total = round((float)$item['ngn_price'] * (1 + $markup/100), 2);
        if ($total <= 0) return ['success'=>false,'message'=>'Could not price this item. Please try again.'];

        $debit = debit_wallet($uid, $total, 'giftcard_buy', "{$item['brand']} gift card to {$email}", ['country'=>$country,'product_id'=>$product_id], $sig);
        if (!$debit['success']) return $debit;

        $prov = $item['_prov'];
        db()->prepare("INSERT INTO giftcard_buy_orders (user_id,transaction_ref,api_registry_id,provider_name,country,product_id,brand,recipient_email,amount_ngn,status) VALUES (?,?,?,?,?,?,?,?,?,'pending')")
            ->execute([$uid, $debit['ref'], $api_registry_id, $prov['provider_name'], $country, $product_id, $item['brand'], $email, $total]);

        $res = reteller_purchase_giftcard($prov, $product_id, (float)$item['sender_amount'], $email);
        db()->prepare("UPDATE transactions SET provider=? WHERE transaction_ref=?")->execute([$prov['provider_name'], $debit['ref']]);

        if ($res && in_array($res['_http_code']??0,[200,201]) && !is_api_response_failure($res) && !is_api_response_pending($res)) {
            update_tx_status($debit['ref'], 'success');
            db()->prepare("UPDATE giftcard_buy_orders SET status='success', provider_ref=?, card_code=? WHERE transaction_ref=?")
                ->execute([$res['data']['reference'] ?? '', $res['data']['card_code'] ?? null, $debit['ref']]);
            save_tx_response($debit['ref'], provider_response_text($res));
            notify_user($uid, 'Gift Card Purchased', "{$item['brand']} gift card sent to {$email}", 'success', null, $debit['balance_before'], $debit['balance_after']);
            return ['success'=>true,'message'=>'Gift card purchased successfully — check your email','ref'=>$debit['ref']];
        }

        if ($res && (is_api_response_pending($res) || !empty($res['_network_error']) || is_duplicate_reference_response($res))) {
            update_tx_status($debit['ref'], 'processing');
            db()->prepare("UPDATE giftcard_buy_orders SET status='processing', provider_ref=? WHERE transaction_ref=?")
                ->execute([$res['data']['reference'] ?? '', $debit['ref']]);
            save_tx_response($debit['ref'], provider_response_text($res));
            notify_user($uid, 'Gift Card Processing', 'Your purchase is being confirmed with the provider.', 'info', null, $debit['balance_before'], $debit['balance_after']);
            return ['success'=>true,'message'=>'We could not immediately confirm this purchase. It is being verified — check your receipt shortly.','ref'=>$debit['ref'],'pending'=>true];
        }

        $err = $res['message'] ?? 'Purchase failed.';
        refund_failed_transaction($debit['ref'], $uid, $total, $err);
        db()->prepare("UPDATE giftcard_buy_orders SET status='failed' WHERE transaction_ref=?")->execute([$debit['ref']]);
        save_tx_response($debit['ref'], provider_response_text($res) ?: $err);
        return ['success'=>true,'message'=>$err,'ref'=>$debit['ref'],'refunded'=>true];
    } catch (\Throwable $e) {
        if ($debit !== null && ($debit['success'] ?? false)) {
            refund_failed_transaction($debit['ref'], $uid, $total, 'System error');
        }
        log_error('giftcard_buy', 'Purchase exception: ' . $e->getMessage(), ['line'=>$e->getLine(), 'uid'=>$uid]);
        return ['success' => false, 'message' => 'Purchase failed. Please try again.'];
    }
}

// Sell side: user submits a card (brand, value, code) to be redeemed for
// cash. Rate/brand config reuses service_pricing the same way
// airtime_to_cash already does (network=<brand>, discount_percent=payout
// rate %, is_active=bool) — no cost/selling price needed here.
// process_giftcard_sell_submission() doesn't touch the wallet either;
// nothing is credited until a real review/payout flow replaces it.
function get_giftcard_sell_brands(): array {
    $stmt = db()->query("SELECT network, discount_percent FROM service_pricing WHERE service='giftcard_sell' AND is_active=1 ORDER BY network");
    return $stmt->fetchAll();
}
function process_giftcard_sell_submission(int $uid, array $post): array {
    return service_not_live_yet('Gift Card selling');
}
