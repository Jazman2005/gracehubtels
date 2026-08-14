<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/api_router.php';
// get_live_ngn_rate() lives in virtual_card.php — needed for PayVessel's
// USD-denominated eSIM package pricing (see payvessel_fetch_esim_packages()).
require_once __DIR__.'/virtual_card.php';

// Auto-migrate esim_orders table
try { db()->exec("CREATE TABLE IF NOT EXISTS esim_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    transaction_ref VARCHAR(64) NOT NULL UNIQUE,
    api_registry_id INT NOT NULL,
    provider_name VARCHAR(100) NOT NULL,
    provider_ref VARCHAR(150) DEFAULT NULL,
    region VARCHAR(20) NOT NULL,
    package_code VARCHAR(150) NOT NULL,
    package_name VARCHAR(150) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    amount_ngn DECIMAL(12,2) NOT NULL,
    iccid VARCHAR(50) DEFAULT NULL,
    activation_code TEXT DEFAULT NULL,
    qr_image TEXT DEFAULT NULL,
    smdp VARCHAR(150) DEFAULT NULL,
    status ENUM('pending','processing','success','failed') NOT NULL DEFAULT 'pending',
    raw_response TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY user_id (user_id), KEY status (status)
)"); } catch (\Throwable $e) {}

// Same curated ISO alpha-2 list used by the other two Reteller-backed
// services, plus Reteller's own "!GL" global-package sentinel — standard
// public data, not a provider catalog, safe to hardcode. Replaces the old
// ESIM_PROVIDERS placeholder list (Alpha/Smile/Kirani), which was never a
// real, confirmed provider catalog — this app now browses live packages by
// region instead of a fixed named-provider list.
const ESIM_REGIONS = [
    '!GL' => 'Global', 'US' => 'United States', 'GB' => 'United Kingdom', 'CA' => 'Canada',
    'GH' => 'Ghana', 'KE' => 'Kenya', 'ZA' => 'South Africa', 'AE' => 'United Arab Emirates',
    'DE' => 'Germany', 'FR' => 'France', 'ES' => 'Spain', 'IT' => 'Italy', 'AU' => 'Australia',
];

function get_esim_regions(): array {
    return ESIM_REGIONS;
}

// --- Reteller-specific catalog fetch/purchase/status. Add a second provider
// by adding an elseif branch here, same convention as every other provider
// integration in this codebase.
function reteller_fetch_esim_packages(array $prov, string $region): array {
    $pname = strtolower($prov['provider_name'] ?? '');
    if (strpos($pname, 'reteller') === false) return [];
    $headers = ['Content-Type: application/json'];
    if (!empty($prov['public_key']) && !empty($prov['secret_key'])) {
        $headers[] = 'Authorization: Bearer ' . $prov['public_key'] . ':' . $prov['secret_key'];
    }
    $url = rtrim($prov['api_base_url'], '/') . '/esim/packages?region=' . urlencode($region);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$headers, CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $data = $res ? json_decode($res, true) : null;
    log_api($prov['provider_name'] ?? 'api_reteller', 'esim/packages', ['region'=>$region], $data, in_array($code,[200,201]));
    if (!$data || !in_array($code, [200,201]) || empty($data['status'])) return [];

    $out = [];
    foreach ($data['data']['packages'] ?? [] as $pkg) {
        $out[] = [
            'package_code' => (string)$pkg['code'],
            'package_name' => $pkg['name'] ?? '',
            'data_size' => $pkg['data'] ?? '',
            'days' => (int)($pkg['days'] ?? 0),
            'ngn_price' => (float)($pkg['ngn'] ?? 0),
        ];
    }
    return $out;
}

function reteller_purchase_esim(array $prov, string $package_code, string $email): ?array {
    $headers = ['Content-Type: application/json'];
    if (!empty($prov['public_key']) && !empty($prov['secret_key'])) {
        $headers[] = 'Authorization: Bearer ' . $prov['public_key'] . ':' . $prov['secret_key'];
    }
    $url = rtrim($prov['api_base_url'], '/') . '/esim/purchase';
    $payload = ['package_code'=>$package_code, 'email'=>$email];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($payload), CURLOPT_HTTPHEADER=>$headers, CURLOPT_TIMEOUT=>30, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    $d = $res ? json_decode($res, true) : null;
    if ($res === false) $d = ['_http_code'=>500, '_network_error'=>true, 'message'=>"cURL Error: $err"];
    if ($d) $d['_http_code'] = $code;
    log_api($prov['provider_name'] ?? 'api_reteller', 'esim/purchase', $payload, $d, in_array($code,[200,201]));
    return $d;
}

function reteller_esim_status(array $prov, string $reference): ?array {
    $headers = ['Content-Type: application/json'];
    if (!empty($prov['public_key']) && !empty($prov['secret_key'])) {
        $headers[] = 'Authorization: Bearer ' . $prov['public_key'] . ':' . $prov['secret_key'];
    }
    $url = rtrim($prov['api_base_url'], '/') . '/esim/status/' . urlencode($reference);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$headers, CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    $d = $res ? json_decode($res, true) : null;
    if ($res === false) $d = ['_http_code'=>500, '_network_error'=>true, 'message'=>"cURL Error: $err"];
    if ($d) $d['_http_code'] = $code;
    log_api($prov['provider_name'] ?? 'api_reteller', 'esim/status', ['reference'=>$reference], $d, in_array($code,[200,201]));
    return $d;
}

// PayVessel-specific catalog fetch/purchase/status. Its package prices are
// USD-only (no NGN field, unlike Reteller/gift-cards which give ngn
// directly) — converted here via the same live-rate helper virtual cards
// already use (get_live_ngn_rate(), NOT get_exchange_rate(), since that
// applies virtual-card-specific markup settings that have nothing to do
// with eSIM pricing; esim_markup_percent is applied uniformly afterward,
// same as every other provider here).
function payvessel_fetch_esim_packages(array $prov, string $region): array {
    $pname = strtolower($prov['provider_name'] ?? '');
    if (strpos($pname, 'payvessel') === false) return [];
    $headers = ['Content-Type: application/json'];
    if (!empty($prov['api_key'])) $headers[] = 'api-key: ' . $prov['api_key'];
    if (!empty($prov['secret_key'])) $headers[] = 'api-secret: ' . $prov['secret_key'];
    $url = rtrim($prov['api_base_url'], '/') . '/vaas/api/v1/esim/packages?location_code=' . urlencode($region);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$headers, CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $data = $res ? json_decode($res, true) : null;
    log_api($prov['provider_name'] ?? 'api_payvessel', 'esim/packages', ['location_code'=>$region], $data, in_array($code,[200,201]));
    if (!$data || !in_array($code, [200,201]) || empty($data['status'])) return [];

    $rate = get_live_ngn_rate();
    if ($rate <= 0) $rate = 1500;

    $out = [];
    foreach ($data['data'] ?? [] as $pkg) {
        $usd = (float)($pkg['retail_price_usd'] ?? $pkg['price_usd'] ?? 0);
        if ($usd <= 0) continue;
        $out[] = [
            'package_code' => (string)($pkg['package_code'] ?? ''),
            'package_name' => $pkg['name'] ?? '',
            'data_size' => isset($pkg['volume_bytes']) ? round(((float)$pkg['volume_bytes']) / 1073741824, 2) . 'GB' : '',
            'days' => (int)($pkg['duration'] ?? 0),
            'ngn_price' => round($usd * $rate, 2),
        ];
    }
    return $out;
}

function payvessel_purchase_esim(array $prov, string $reference, string $package_code): ?array {
    $headers = ['Content-Type: application/json'];
    if (!empty($prov['api_key'])) $headers[] = 'api-key: ' . $prov['api_key'];
    if (!empty($prov['secret_key'])) $headers[] = 'api-secret: ' . $prov['secret_key'];
    $url = rtrim($prov['api_base_url'], '/') . '/vaas/api/v1/esim/orders';
    $payload = ['reference'=>$reference, 'package_code'=>$package_code, 'quantity'=>1];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($payload), CURLOPT_HTTPHEADER=>$headers, CURLOPT_TIMEOUT=>30, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    $d = $res ? json_decode($res, true) : null;
    if ($res === false) $d = ['_http_code'=>500, '_network_error'=>true, 'message'=>"cURL Error: $err"];
    if ($d) $d['_http_code'] = $code;
    log_api($prov['provider_name'] ?? 'api_payvessel', 'esim/orders', $payload, $d, in_array($code,[200,201]));
    return $d;
}

// Status is checked by PayVessel's OWN order id (returned at purchase time),
// not by our merchant reference — unlike Reteller's /esim/status/{reference}
// endpoint. $order_id is what gets stored in esim_orders.provider_ref for a
// PayVessel-sourced order.
function payvessel_esim_status(array $prov, string $order_id): ?array {
    $headers = ['Content-Type: application/json'];
    if (!empty($prov['api_key'])) $headers[] = 'api-key: ' . $prov['api_key'];
    if (!empty($prov['secret_key'])) $headers[] = 'api-secret: ' . $prov['secret_key'];
    $url = rtrim($prov['api_base_url'], '/') . '/vaas/api/v1/esim/orders/' . urlencode($order_id);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$headers, CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    $d = $res ? json_decode($res, true) : null;
    if ($res === false) $d = ['_http_code'=>500, '_network_error'=>true, 'message'=>"cURL Error: $err"];
    if ($d) $d['_http_code'] = $code;
    log_api($prov['provider_name'] ?? 'api_payvessel', 'esim/orders/get', ['order_id'=>$order_id], $d, in_array($code,[200,201]));
    return $d;
}

function esim_fetch_dispatch(array $prov, string $region): array {
    $pname = strtolower($prov['provider_name'] ?? '');
    if (strpos($pname, 'reteller') !== false) return reteller_fetch_esim_packages($prov, $region);
    if (strpos($pname, 'payvessel') !== false) return payvessel_fetch_esim_packages($prov, $region);
    return [];
}

function fetch_esim_catalog(string $region): array {
    return aggregate_provider_catalog('esim', $region, function(array $prov, string $r) {
        return esim_fetch_dispatch($prov, $r);
    });
}

function process_esim_purchase(int $uid, array $post): array {
    $debit = null; $total = 0;
    try {
        if (detect_tamper($post)) return ['success'=>false,'message'=>'Invalid input detected'];
        $region = strtoupper(trim($post['region'] ?? ''));
        $api_registry_id = (int)($post['api_registry_id'] ?? 0);
        $package_code = (string)($post['package_code'] ?? '');
        $email = trim($post['email'] ?? '');
        $pin = (string)($post['pin'] ?? '');
        if ($region === '') return ['success'=>false,'message'=>'Select a region'];
        if ($api_registry_id < 1 || $package_code === '') return ['success'=>false,'message'=>'Select a package'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['success'=>false,'message'=>'Enter a valid email'];
        if (!verify_pin($uid, $pin)) return ['success'=>false,'message'=>'Invalid transaction PIN'];
        if (!claim_purchase_lock($uid, 'esim', [$region, $package_code, $email])) {
            return ['success'=>false,'message'=>'Duplicate request detected. Please wait a moment and try again.'];
        }
        $sig = purchase_signature('esim', [$region, $package_code, $email]);

        $item = relocate_catalog_item('esim', $region, $api_registry_id, 'package_code', $package_code,
            fn($prov, $r) => esim_fetch_dispatch($prov, $r));
        if (!$item) return ['success'=>false,'message'=>'This package is no longer available. Please refresh and try again.'];

        $markup = (float)setting('esim_markup_percent', '0');
        $total = round((float)$item['ngn_price'] * (1 + $markup/100), 2);
        if ($total <= 0) return ['success'=>false,'message'=>'Could not price this package. Please try again.'];

        $debit = debit_wallet($uid, $total, 'esim', "{$item['package_name']} eSIM", ['region'=>$region,'package_code'=>$package_code], $sig);
        if (!$debit['success']) return $debit;

        $prov = $item['_prov'];
        db()->prepare("INSERT INTO esim_orders (user_id,transaction_ref,api_registry_id,provider_name,region,package_code,package_name,email,amount_ngn,status) VALUES (?,?,?,?,?,?,?,?,?,'pending')")
            ->execute([$uid, $debit['ref'], $api_registry_id, $prov['provider_name'], $region, $package_code, $item['package_name'], $email, $total]);

        $pname = strtolower($prov['provider_name'] ?? '');
        $is_payvessel = strpos($pname, 'payvessel') !== false;
        $res = $is_payvessel
            ? payvessel_purchase_esim($prov, $debit['ref'], $package_code)
            : reteller_purchase_esim($prov, $package_code, $email);
        db()->prepare("UPDATE transactions SET provider=? WHERE transaction_ref=?")->execute([$prov['provider_name'], $debit['ref']]);

        // Both providers can accept and charge the purchase but still leave
        // the actual eSIM profile not-ready — a third, non-decline outcome
        // distinct from both "confirmed success" and "genuine network
        // uncertainty". Modeled directly on virtual_card.php's Cashwyre
        // create-card flow: never refund a charge the provider has already
        // confirmed, only escalate and let it be resolved later via
        // resolve_esim_order(). Response shapes differ enough between the
        // two that extraction is normalized here rather than shared.
        if ($is_payvessel) {
            $profile = $res['data']['profiles'][0] ?? [];
            $iccid = $profile['iccid'] ?? null;
            $activation_code = $profile['ac'] ?? null;
            $qr_image = $profile['qr_code_url'] ?? null;
            $smdp = $profile['apn'] ?? null;
            $provider_ref = $res['data']['id'] ?? '';
        } else {
            $e = $res['data']['esim'] ?? [];
            $iccid = $e['iccid'] ?? null;
            $activation_code = $e['activation_code'] ?? null;
            $qr_image = $e['qr_image'] ?? null;
            $smdp = $e['smdp'] ?? null;
            $provider_ref = $res['data']['reference'] ?? '';
        }
        $has_iccid = !empty($iccid);

        if ($res && in_array($res['_http_code']??0,[200,201]) && !is_api_response_failure($res)) {
            if ($has_iccid) {
                update_tx_status($debit['ref'], 'success');
                db()->prepare("UPDATE esim_orders SET status='success', provider_ref=?, iccid=?, activation_code=?, qr_image=?, smdp=? WHERE transaction_ref=?")
                    ->execute([$provider_ref, $iccid, $activation_code, $qr_image, $smdp, $debit['ref']]);
                save_tx_response($debit['ref'], provider_response_text($res));
                notify_user($uid, 'eSIM Ready', "Your {$item['package_name']} eSIM is ready — scan the QR code to install.", 'success', null, $debit['balance_before'], $debit['balance_after']);
                return ['success'=>true,'message'=>'eSIM purchased successfully','ref'=>$debit['ref']];
            }
            // Accepted/charged (not a decline) but the profile isn't ready
            // yet. Never refund a charge the provider has already
            // confirmed; escalate and let it be resolved later via
            // resolve_esim_order().
            update_tx_status($debit['ref'], 'processing');
            db()->prepare("UPDATE esim_orders SET status='processing', provider_ref=? WHERE transaction_ref=?")
                ->execute([$provider_ref, $debit['ref']]);
            save_tx_response($debit['ref'], provider_response_text($res));
            notify_admin('eSIM Provisioning', "eSIM order for user #$uid (ref: {$debit['ref']}) was charged and accepted by {$prov['provider_name']} but the profile isn't ready yet. Use \"Check Status\" to resolve once it is — do not refund.");
            notify_user($uid, 'eSIM Provisioning', 'Your eSIM is being provisioned — check back shortly or use Refresh Status.', 'info', null, $debit['balance_before'], $debit['balance_after']);
            return ['success'=>true,'message'=>'Your eSIM is being provisioned. Check back shortly.','ref'=>$debit['ref'],'pending'=>true];
        }

        if ($res && (!empty($res['_network_error']) || is_duplicate_reference_response($res))) {
            // Genuine network/timeout uncertainty — we don't know whether
            // Reteller actually charged/created this. Never refund; escalate
            // for manual verification, mirroring virtual_card.php's Cashwyre
            // "never refund on an unconfirmed outcome" rule exactly.
            update_tx_status($debit['ref'], 'processing');
            db()->prepare("UPDATE esim_orders SET status='processing' WHERE transaction_ref=?")->execute([$debit['ref']]);
            save_tx_response($debit['ref'], 'Uncertain outcome after esim/purchase (network error) — manual verification required.');
            log_error('esim', 'ESIM_PURCHASE_OUTCOME_UNKNOWN', ['uid'=>$uid, 'ref'=>$debit['ref']]);
            notify_admin('eSIM Purchase Outcome Unknown', "User #$uid's eSIM purchase (ref: {$debit['ref']}) timed out — check {$prov['provider_name']}'s dashboard for an orphaned charge before this is resolved. Do not refund until confirmed not created.");
            notify_user($uid, 'eSIM Under Review', 'We could not immediately confirm this purchase. It is being verified.', 'info', null, $debit['balance_before'], $debit['balance_after']);
            return ['success'=>true,'message'=>'We could not immediately confirm this purchase. It is being verified — check back shortly.','ref'=>$debit['ref'],'pending'=>true];
        }

        // Confirmed decline.
        $err = $res['message'] ?? 'Purchase failed.';
        refund_failed_transaction($debit['ref'], $uid, $total, $err);
        db()->prepare("UPDATE esim_orders SET status='failed' WHERE transaction_ref=?")->execute([$debit['ref']]);
        save_tx_response($debit['ref'], provider_response_text($res) ?: $err);
        return ['success'=>true,'message'=>$err,'ref'=>$debit['ref'],'refunded'=>true];
    } catch (\Throwable $e) {
        if ($debit !== null && ($debit['success'] ?? false)) {
            refund_failed_transaction($debit['ref'], $uid, $total, 'System error');
        }
        log_error('esim', 'Purchase exception: ' . $e->getMessage(), ['line'=>$e->getLine(), 'uid'=>$uid]);
        return ['success' => false, 'message' => 'Purchase failed. Please try again.'];
    }
}

// On-demand resolution for an order stuck 'processing' — never automated via
// cron (see the exclusion note in cron_check_vtu.php/cron_refund.php); this
// is triggered explicitly by an admin action or a rate-limited user
// "Refresh Status" button, both calling this same function. Reteller's
// /esim/status/{reference} is a read-only idempotent GET, safe to expose to
// both, unlike anything money-moving.
function resolve_esim_order(string $transaction_ref): array {
    $order = db()->prepare("SELECT * FROM esim_orders WHERE transaction_ref=? AND status='processing' LIMIT 1");
    $order->execute([$transaction_ref]);
    $order = $order->fetch();
    if (!$order) return ['success'=>false,'message'=>'No processing order found for this reference'];
    if (empty($order['provider_ref'])) return ['success'=>false,'message'=>'No provider reference recorded yet — try again shortly'];

    // Reuse get_api_providers() rather than re-deriving the route/registry
    // COALESCE (base_url/public_key/secret_key) by hand in a raw query.
    $prov = null;
    foreach (get_api_providers('esim') as $p) {
        if ((int)$p['api_registry_id'] === (int)$order['api_registry_id']) { $prov = $p; break; }
    }
    if (!$prov) return ['success'=>false,'message'=>'The provider for this order is no longer active'];

    $pname = strtolower($prov['provider_name'] ?? '');
    $is_payvessel = strpos($pname, 'payvessel') !== false;
    $res = $is_payvessel
        ? payvessel_esim_status($prov, $order['provider_ref'])
        : reteller_esim_status($prov, $order['provider_ref']);
    if (!$res || !in_array($res['_http_code']??0,[200,201])) return ['success'=>false,'message'=>'Could not reach the provider — try again shortly'];

    $status = strtolower((string)($res['data']['status'] ?? ''));
    if ($is_payvessel) {
        $profile = $res['data']['profiles'][0] ?? [];
        $iccid = $profile['iccid'] ?? null;
        $activation_code = $profile['ac'] ?? null;
        $qr_image = $profile['qr_code_url'] ?? null;
        $smdp = $profile['apn'] ?? null;
    } else {
        $esim = $res['data']['esim'] ?? [];
        $iccid = $esim['iccid'] ?? null;
        $activation_code = $esim['activation_code'] ?? null;
        $qr_image = $esim['qr_image'] ?? null;
        $smdp = $esim['smdp'] ?? null;
    }
    if (!empty($iccid)) {
        update_tx_status($order['transaction_ref'], 'success');
        db()->prepare("UPDATE esim_orders SET status='success', iccid=?, activation_code=?, qr_image=?, smdp=? WHERE transaction_ref=?")
            ->execute([$iccid, $activation_code, $qr_image, $smdp, $order['transaction_ref']]);
        save_tx_response($order['transaction_ref'], provider_response_text($res));
        notify_user((int)$order['user_id'], 'eSIM Ready', "Your {$order['package_name']} eSIM is ready — scan the QR code to install.", 'success');
        return ['success'=>true,'message'=>'eSIM is now ready','ready'=>true];
    }
    if (in_array($status, ['reversed', 'failed', 'cancelled'])) {
        refund_failed_transaction($order['transaction_ref'], (int)$order['user_id'], (float)$order['amount_ngn'], 'Provider confirmed not delivered');
        db()->prepare("UPDATE esim_orders SET status='failed' WHERE transaction_ref=?")->execute([$order['transaction_ref']]);
        return ['success'=>true,'message'=>'Provider confirmed this order failed — refunded'];
    }
    return ['success'=>true,'message'=>'Still provisioning — check back shortly','ready'=>false];
}
