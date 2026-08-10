<?php
require_once __DIR__.'/db.php';

// Auto-migrate plan_api_bindings table
try { db()->exec("CREATE TABLE IF NOT EXISTS plan_api_bindings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  service VARCHAR(50) NOT NULL,
  plan_id INT NOT NULL,
  api_registry_id INT NOT NULL,
  priority TINYINT(1) NOT NULL DEFAULT 1,
  product_code VARCHAR(100) DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY service_plan_api (service, plan_id, api_registry_id),
  KEY service_plan (service, plan_id),
  KEY api_registry_id (api_registry_id)
)"); } catch (\Throwable $e) {}

function get_api_providers(string $service, ?string $network = null, ?string $data_type = null): array {
  $params = [$service];
  $select = "SELECT p.*, 
    COALESCE(NULLIF(p.api_base_url,''), NULLIF(r.base_url,'')) AS api_base_url,
    COALESCE(NULLIF(p.api_key,''), NULLIF(r.api_key,'')) AS api_key,
    COALESCE(NULLIF(p.api_token,''), NULLIF(r.api_token,'')) AS api_token,
    COALESCE(NULLIF(p.api_pin,''), NULLIF(r.api_pin,'')) AS api_pin,
    COALESCE(NULLIF(p.contract_code,''), NULLIF(r.contract_code,'')) AS contract_code,
    COALESCE(NULLIF(p.public_key,''), NULLIF(r.public_key,'')) AS public_key,
    COALESCE(NULLIF(p.secret_key,''), NULLIF(r.secret_key,'')) AS secret_key,
    COALESCE(NULLIF(p.network_id_map,''), NULLIF(r.network_id_map,'')) AS network_id_map,
    COALESCE(NULLIF(p.value_map,''), NULLIF(r.value_map,'')) AS value_map,
    r.name AS registry_name";
  $from = " FROM api_providers p LEFT JOIN api_registry r ON p.api_registry_id = r.id";
  if ($data_type !== null && $network !== null) {
    $sql = "$select$from WHERE p.service=? AND p.network=? AND (p.data_type=? OR p.data_type IS NULL OR p.data_type='') AND p.is_active=1 ORDER BY CASE WHEN p.data_type=? THEN 0 ELSE 1 END, p.priority";
    $params = [$service, $network, $data_type, $data_type];
  } elseif ($network !== null) {
    $sql = "$select$from WHERE p.service=? AND (p.network=? OR p.network IS NULL) AND p.is_active=1 ORDER BY p.priority";
    $params[] = $network;
  } else {
    $sql = "$select$from WHERE p.service=? AND p.is_active=1 ORDER BY p.priority";
  }
  $s = db()->prepare($sql); $s->execute($params);
  return $s->fetchAll();
}

function try_api_providers(string $service, callable $call_fn, ?string $network = null, ?string $data_type = null): array {
  $providers = get_api_providers($service, $network, $data_type);
  if (empty($providers)) {
    return ['success' => false, 'message' => 'No active provider available for this service', 'providers_tried' => []];
  }
  $last_error = null;
  $tried = [];
  foreach ($providers as $prov) {
    if (empty($prov['api_base_url'])) continue;
    $tried[] = $prov['provider_name'] ?? 'Unknown';
    try {
      $res = $call_fn($prov);
      if ($res && !empty($res['_http_code']) && in_array($res['_http_code'], [200, 201]) && !is_api_response_failure($res)) {
        $res['_provider'] = $prov;
        return $res;
      }
      $last_error = $res;
    } catch (\Throwable $e) {
      $last_error = ['success' => false, 'message' => $e->getMessage()];
    }
  }
  return [
    'success' => false,
    'message' => $last_error['message'] ?? 'All providers failed',
    'providers_tried' => $tried,
    'last_response' => $last_error
  ];
}

// Read a key from provider's network_id_map JSON
function provider_network_id(array $prov, string $key, $default = null) {
  $map = json_decode($prov['network_id_map'] ?? '{}', true);
  return is_array($map) && isset($map[$key]) ? $map[$key] : $default;
}

// Read a key from provider's value_map (key=value per line)
function provider_value(array $prov, string $key, $default = null) {
  $lines = array_filter(explode("\n", $prov['value_map'] ?? ''));
  foreach ($lines as $line) {
    $parts = explode('=', trim($line), 2);
    if (count($parts) === 2 && trim($parts[0]) === $key) return trim($parts[1]);
  }
  return $default;
}

// Default network ID maps by service & provider type
function default_network_id_map(string $service, string $type = 'generic'): array {
  $maps = [
    'airtime' => [
      'generic' => ['MTN'=>1,'GLO'=>2,'AIRTEL'=>3,'9MOBILE'=>4],
      'autosyncng' => ['MTN'=>1,'GLO'=>15,'AIRTEL'=>16,'9MOBILE'=>17],
    ],
    'recharge_card' => [
      'generic' => ['MTN'=>1,'GLO'=>2,'AIRTEL'=>3,'9MOBILE'=>4],
      'autosyncng' => ['MTN'=>1,'GLO'=>15,'AIRTEL'=>16,'9MOBILE'=>17],
    ],
    'data_card' => [
      'generic' => ['MTN'=>1,'GLO'=>2,'AIRTEL'=>3,'9MOBILE'=>4],
      'autosyncng' => ['MTN'=>2,'GLO'=>8,'AIRTEL'=>3,'9MOBILE'=>7],
    ],
    'data' => [
      'generic' => ['MTN'=>1,'GLO'=>2,'AIRTEL'=>3,'9MOBILE'=>4],
      'autosyncng' => ['MTN'=>2,'GLO'=>8,'AIRTEL'=>3,'9MOBILE'=>7],
    ],
    'cabletv' => [
      'generic' => ['DSTV'=>1,'GOTV'=>2,'STARTIMES'=>3,'SHOWMAX'=>4],
      'autosyncng' => ['DSTV'=>1,'GOTV'=>2,'STARTIMES'=>3,'SHOWMAX'=>4],
    ],
    'electricity' => [
      'generic' => ['IKEDC'=>1,'EKEDC'=>2,'AEDC'=>3,'PHEDC'=>4,'IBEDC'=>5,'KEDCO'=>6,'EEDC'=>7,'KAEDCO'=>8,'JED'=>9],
      'autosyncng' => ['IKEDC'=>'ikeja-electric','EKEDC'=>'eko-electric','AEDC'=>'abuja-electric','PHEDC'=>'phedc','IBEDC'=>'ibadan-electric'],
    ],
    'exam_pin' => [
      'generic' => ['WAEC'=>1,'NECO'=>2,'NABTEB'=>3],
    ],
    'bulk_sms' => [
      'generic' => [],
    ],
    'airtime_to_cash' => [
      'generic' => ['MTN'=>1,'GLO'=>2,'AIRTEL'=>3,'9MOBILE'=>4],
    ],
  ];
  return $maps[$service][$type] ?? $maps[$service]['generic'] ?? [];
}

// Build the effective network_id_map for a provider (provider map overrides defaults)
function build_network_map(array $prov, string $service): array {
  $pname = strtolower($prov['provider_name'] ?? '');
  $type = strpos($pname, 'autosyncng') !== false ? 'autosyncng' : 'generic';
  $defaults = default_network_id_map($service, $type);
  $override = json_decode($prov['network_id_map'] ?? '{}', true);
  if (!is_array($override)) $override = [];
  return array_merge($defaults, $override);
}

// Build a key-value map from provider's value_map (with defaults)
function build_value_map(array $prov, string $service): array {
  $pname = strtolower($prov['provider_name'] ?? '');
  $type = strpos($pname, 'autosyncng') !== false ? 'autosyncng' : 'generic';
  $defaults = default_network_id_map($service, $type);
  $result = $defaults;
  // Parse value_map lines
  $lines = array_filter(explode("\n", $prov['value_map'] ?? ''));
  foreach ($lines as $line) {
    $parts = explode('=', trim($line), 2);
    if (count($parts) === 2) $result[trim($parts[0])] = trim($parts[1]);
  }
  return $result;
}

// Check if an API response explicitly indicates failure (e.g. insufficient balance)
// Returns true if the response body indicates failure, false if success or indeterminate
function is_api_response_failure(?array $res): bool {
  if (!$res) return true;
  if (isset($res['success']) && $res['success'] === false) return true;
  if (isset($res['status']) && strtolower((string)$res['status']) === 'failed') return true;
  if (isset($res['status']) && strtolower((string)$res['status']) === 'error') return true;
  
  // Nested status checks (e.g. AutoSyncNG data.transaction.status)
  $txStatus = $res['data']['transaction']['status'] ?? $res['data']['status'] ?? '';
  if ($txStatus && in_array(strtolower((string)$txStatus), ['failed', 'error', 'fail', 'reversed'])) return true;

  // AutoSyncNG-style insufficient balance code
  if (isset($res['code']) && (string)$res['code'] === '101') return true;
  // Message-based insufficiency detection
  if (isset($res['message']) && is_string($res['message']) && stripos($res['message'], 'insufficient') !== false) return true;
  if (isset($res['error']) && is_string($res['error']) && stripos($res['error'], 'insufficient') !== false) return true;
  return false;
}

// Check if an API response indicates pending/processing (neither success nor failure)
function is_api_response_pending(?array $res): bool {
  if (!$res) return false;
  if (isset($res['status']) && strtolower((string)$res['status']) === 'pending') return true;
  if (isset($res['status']) && strtolower((string)$res['status']) === 'processing') return true;
  $txStatus = $res['data']['transaction']['status'] ?? $res['data']['status'] ?? '';
  if ($txStatus && strtolower((string)$txStatus) === 'pending') return true;
  if ($txStatus && strtolower((string)$txStatus) === 'processing') return true;
  return false;
}

// Providers return wildly different response shapes. This pulls out whatever
// human-readable text is actually in there — falling back to the full
// (redacted) JSON body when no recognizable message field exists — so the
// REAL provider reply ends up on the receipt regardless of whether the
// purchase landed as successful, failed, pending, or still processing,
// instead of just our own generic internal message. Call this with the raw
// $res from api_call()/api_post() and feed the result into save_tx_response().
function provider_response_text(?array $res): string {
    if (!$res) return '';
    if (!empty($res['_network_error'])) {
        return 'No response from provider (network/timeout error)' . (!empty($res['_curl_error']) ? ': ' . $res['_curl_error'] : '');
    }
    $candidates = [
        $res['message'] ?? null,
        $res['msg'] ?? null,
        $res['error'] ?? null,
        $res['description'] ?? null,
        $res['respMsg'] ?? null,
        $res['data']['transaction']['message'] ?? null,
        $res['data']['message'] ?? null,
    ];
    foreach ($candidates as $c) {
        if (is_string($c) && trim($c) !== '') return $c;
        if (is_array($c) && !empty($c)) { $j = json_encode($c); if ($j) return $j; }
    }
    $clean = $res;
    unset($clean['_http_code'], $clean['_curl_errno'], $clean['_curl_error'], $clean['_network_error']);
    if (empty($clean)) return '';
    $j = json_encode(redact_sensitive($clean), JSON_UNESCAPED_SLASHES);
    return $j ?: '';
}

function get_plan_bindings(string $service, int $plan_id): array {
  try {
    // api_registry's URL column is named `base_url`, not `api_base_url` — this
    // query used to select the latter, which doesn't exist on the table, so
    // every call threw a PDOException that the catch below silently swallowed
    // into an empty array. Every plan-specific provider binding configured via
    // data-plan-mapping.php (data/cabletv/electricity) was therefore always
    // ignored at purchase time, with no error surfaced anywhere.
    $s = db()->prepare("SELECT b.product_code, r.name as provider_name, r.base_url AS api_base_url, r.api_key, r.api_token, r.api_pin, r.contract_code, r.public_key, r.secret_key, r.network_id_map, r.value_map FROM plan_api_bindings b JOIN api_registry r ON r.id=b.api_registry_id WHERE b.service=? AND b.plan_id=? AND r.is_active=1 ORDER BY b.priority");
    $s->execute([$service, $plan_id]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
  } catch (\Throwable $e) {
    log_error('api_router', 'get_plan_bindings query failed: ' . $e->getMessage(), ['service' => $service, 'plan_id' => $plan_id]);
    return [];
  }
}

// Providers whose actual delivery status can be independently confirmed via
// a real status-check API call (used below in query_provider_status()).
// Shared between cron_check_vtu.php (frequent polling of recent
// pending/processing transactions) and cron_refund.php (which must NOT
// blindly refund a merely-slow transaction without provider confirmation —
// only providers listed here can ever be resolved automatically; everything
// else requires a human to check and resolve manually via
// transaction-details.php).
const VERIFIABLE_PROVIDERS = ['autosyncng', 'msjdatasubs', 'telecomabode'];

function provider_is_verifiable(string $provider_name): bool {
    $pname = strtolower($provider_name);
    foreach (VERIFIABLE_PROVIDERS as $vp) {
        if (str_contains($pname, $vp)) return true;
    }
    return false;
}

// Queries a provider directly for the real current status of a specific
// transaction. Returns null when the status is still genuinely unknown
// (still processing at the provider, or the check itself failed/timed out)
// — callers must treat null as "don't touch this transaction yet", never as
// a confirmed outcome either way. Returns ['success'=>bool,'failed'=>bool,...]
// only when the provider gave an explicit, confident answer.
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
