<?php
// cron_check_vtu.php
// Periodically checks pending VTU transactions with the provider API.
// If provider confirms success → updates DB with token/message.
// If provider confirms failure → marks failed, refunds wallet.
// Run every 2-5 minutes via cron.
// If a webhook is implemented, this is a fallback for missed callbacks.
require_once __DIR__ . '/user/core/db.php';
require_once __DIR__ . '/user/core/api_router.php';
if (php_sapi_name() !== 'cli' && !defined('STDIN')) {
    $cron_key = $_GET['key'] ?? '';
    $expected = setting('cron_secret', '');
    if (empty($expected) || !hash_equals($expected, $cron_key)) {
        http_response_code(403);
        exit('Access denied');
    }
}

$lockFile = __DIR__ . '/cron_check_vtu.lock';
if (file_exists($lockFile) && (time() - filemtime($lockFile) < 120)) {
    exit("Already running or ran recently.\n");
}
touch($lockFile);

$service_categories = ['electricity', 'airtime', 'data', 'cabletv', 'exam_pin', 'recharge_card', 'bulk_sms', 'data_card'];

try {
    // Find pending/processing transactions older than 2 minutes, younger than 30 minutes (so refund cron handles the very old ones)
    $stmt = db()->prepare("
        SELECT * FROM transactions 
        WHERE status IN ('pending', 'processing') 
        AND category IN ('electricity', 'airtime', 'data', 'cabletv', 'exam_pin', 'recharge_card', 'bulk_sms', 'data_card')
        AND created_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)
        AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        ORDER BY created_at ASC
        LIMIT 20
    ");
    $stmt->execute();
    $txs = $stmt->fetchAll();

    $count = 0;
    foreach ($txs as $tx) {
        $ref = $tx['transaction_ref'];
        $provider_name = $tx['provider'] ?? '';
        $category = $tx['category'];

        if (!$provider_name) continue;

        // Look up the provider config
        $prov = db()->prepare("SELECT * FROM api_providers WHERE provider_name=? AND service=? AND is_active=1 LIMIT 1");
        $prov->execute([$provider_name, $category]);
        $provider_config = $prov->fetch();

        if (!$provider_config || empty($provider_config['api_base_url'])) continue;

        $result = query_provider_status($provider_config, $tx);
        if (!$result) continue;

        $count++;

        // update_tx_status() only reports true if THIS call actually moved the row
        // out of pending/processing — false means a webhook or cron_refund.php
        // already resolved this same transaction_ref in the meantime. Skipping the
        // side effects below in that case prevents double notifications and,
        // critically, a double refund racing a webhook that just marked it success.
        if ($result['success'] && !empty($result['token'])) {
            // Success with token
            if (update_tx_status($ref, 'success', $result['provider_ref'] ?? '')) {
                $meta_updates = [];
                $meta_updates[] = "'$.token', " . db()->quote($result['token']);
                if (!empty($result['message'])) $meta_updates[] = "'$.response', " . db()->quote($result['message']);
                if (!empty($result['provider_ref'])) $meta_updates[] = "'$.provider_ref', " . db()->quote($result['provider_ref']);
                $sql = "UPDATE transactions SET meta = JSON_SET(COALESCE(meta, '{}'), " . implode(', ', $meta_updates) . ") WHERE transaction_ref=?";
                db()->prepare($sql)->execute([$ref]);
                $uid = (int)$tx['user_id'];
                notify_user($uid, ucwords(str_replace('_', ' ', $category)) . ' Completed', "Token: {$result['token']} - {$result['message']}", 'success', null, $tx['balance_before'], $tx['balance_after']);
                log_write('cron_check_vtu', 'INFO', "Updated $ref as success via status check");
            }
        } elseif ($result['success']) {
            // Success without token (e.g., airtime)
            if (update_tx_status($ref, 'success', $result['provider_ref'] ?? '')) {
                if (!empty($result['message'])) {
                    db()->prepare("UPDATE transactions SET meta = JSON_SET(COALESCE(meta, '{}'), '$.response', ?) WHERE transaction_ref=?")->execute([$result['message'], $ref]);
                }
                $uid = (int)$tx['user_id'];
                notify_user($uid, ucwords(str_replace('_', ' ', $category)) . ' Completed', $result['message'] ?: "Your $category purchase was successful.", 'success', null, $tx['balance_before'], $tx['balance_after']);
                log_write('cron_check_vtu', 'INFO', "Updated $ref as success via status check");
            }
        } elseif ($result['failed']) {
            if (update_tx_status($ref, 'failed')) {
                $uid = (int)$tx['user_id'];
                $amount = (float)$tx['amount'];
                credit_wallet($uid, $amount, $category, ucwords(str_replace('_', ' ', $category)) . ' reversal - ' . ($result['message'] ?: 'Provider reported failure'), [], 'vtu_refund:' . $ref);
                notify_user($uid, ucwords(str_replace('_', ' ', $category)) . ' Failed', ($result['message'] ?: "Your $category transaction failed. Wallet refunded."), 'error', null, $tx['balance_before'], $tx['balance_after']);
                log_write('cron_check_vtu', 'INFO', "Updated $ref as failed via status check");
            }
        }
        // If status is still pending, do nothing - check again next run
    }

    echo "Checked $count pending transactions.\n";

} catch (Exception $e) {
    log_write('cron_check_vtu', 'ERROR', "Cron error: " . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
}

if (file_exists($lockFile)) unlink($lockFile);

// ============================================================
// Helper: Query a provider for transaction status
// Returns array with keys: success (bool), failed (bool), token, message, provider_ref
// ============================================================
function query_provider_status(array $provider_config, array $tx): ?array {
    $pname = strtolower($provider_config['provider_name'] ?? '');
    $base = rtrim($provider_config['api_base_url'], '/');
    $ref = $tx['transaction_ref'];
    $category = $tx['category'];
    $meta = json_decode($tx['meta'] ?? '{}', true);

    $headers = ['Content-Type: application/json'];
    if (!empty($provider_config['api_key'])) $headers[] = 'Authorization: Token ' . $provider_config['api_key'];
    elseif (!empty($provider_config['api_token'])) $headers[] = 'Authorization: Bearer ' . $provider_config['api_token'];

    // AutoSyncNG: GET /api/v1/status/{request_ref}
    if (stripos($pname, 'autosyncng') !== false) {
        $url = "$base/api/v1/status/$ref";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = $res ? json_decode($res, true) : null;
        if (!$data || !in_array($code, [200, 201])) return null;

        $s = strtolower((string)($data['status'] ?? ''));
        $txStatus = $s;
        $txStatusNested = $data['data']['transaction']['status'] ?? $data['data']['status'] ?? '';
        if ($txStatusNested && in_array(strtolower((string)$txStatusNested), ['pending', 'processing', 'queued'])) $txStatus = strtolower((string)$txStatusNested);
        elseif ($txStatusNested && in_array(strtolower((string)$txStatusNested), ['success', 'fulfilled', 'completed', 'delivered'])) $txStatus = strtolower((string)$txStatusNested);
        elseif ($txStatusNested && in_array(strtolower((string)$txStatusNested), ['failed', 'error', 'declined', 'reversed'])) $txStatus = strtolower((string)$txStatusNested);
        if ($txStatus === 'success' || $txStatus === 'fulfilled' || $txStatus === 'completed' || $txStatus === 'delivered') {
            $d2 = $data['data'] ?? [];
            $token = $data['token'] ?? $data['pin'] ?? $data['meter_token'] ?? ''
                ?? $d2['token'] ?? $d2['pin'] ?? $d2['meter_token'] ?? $d2['vtoken']
                ?? $d2['transaction']['token'] ?? $d2['transaction']['pin'] ?? $d2['transaction']['main_token'];
            return ['success' => true, 'failed' => false, 'token' => $token, 'message' => $data['message'] ?? $data['msg'] ?? $d2['message'] ?? '', 'provider_ref' => $data['id'] ?? $d2['id'] ?? $d2['transaction']['reference'] ?? ''];
        }
        if ($txStatus === 'failed' || $txStatus === 'error' || $txStatus === 'declined' || $txStatus === 'reversed') {
            return ['success' => false, 'failed' => true, 'token' => '', 'message' => $data['message'] ?? $data['error'] ?? $data['data']['transaction']['details'] ?? $data['data']['message'] ?? 'Provider declined', 'provider_ref' => ''];
        }
        return null; // Still pending
    }

    // MsjDataSubs: POST /api/status/ with ref
    if (stripos($pname, 'msjdatasubs') !== false) {
        $url = "$base/api/status/";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['ref' => $ref, 'service' => $category]),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = $res ? json_decode($res, true) : null;
        if (!$data || !in_array($code, [200, 201])) return null;

        $s = strtolower((string)($data['status'] ?? $data['success'] ?? ''));
        if (in_array($s, ['success', 'true', '1', 'completed', 'fulfilled'])) {
            $token = $data['token'] ?? $data['pin'] ?? $data['meter_token'] ?? '';
            return ['success' => true, 'failed' => false, 'token' => $token, 'message' => $data['message'] ?? $data['msg'] ?? '', 'provider_ref' => $data['id'] ?? $data['ref'] ?? ''];
        }
        if (in_array($s, ['failed', 'false', '0', 'error', 'declined'])) {
            return ['success' => false, 'failed' => true, 'token' => '', 'message' => $data['message'] ?? $data['error'] ?? 'Provider declined', 'provider_ref' => ''];
        }
        return null;
    }

    // TelecomAbode: POST /api/status/ with ref
    if (stripos($pname, 'telecomabode') !== false) {
        $url = "$base/api/status/";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['ref' => $ref, 'transaction_ref' => $ref]),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = $res ? json_decode($res, true) : null;
        if (!$data || !in_array($code, [200, 201])) return null;

        $s = strtolower((string)($data['status'] ?? $data['success'] ?? $data['message'] ?? ''));
        if (in_array($s, ['success', 'true', '1', 'completed', 'fulfilled', 'delivered'])) {
            $token = $data['token'] ?? $data['pin'] ?? $data['meter_token'] ?? '';
            return ['success' => true, 'failed' => false, 'token' => $token, 'message' => $data['message'] ?? $data['msg'] ?? '', 'provider_ref' => $data['id'] ?? $data['ref'] ?? ''];
        }
        if (in_array($s, ['failed', 'false', '0', 'error', 'declined'])) {
            return ['success' => false, 'failed' => true, 'token' => '', 'message' => $data['message'] ?? $data['error'] ?? 'Provider declined', 'provider_ref' => ''];
        }
        return null;
    }

    // Unknown provider - cannot check status
    return null;
}
