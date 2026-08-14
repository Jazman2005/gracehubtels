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
    // Match this exact network OR a network-agnostic ("ALL networks") route —
    // the network-only branch below already does this OR-NULL fallback, but
    // this branch previously required an exact p.network=? match with no
    // fallback, so an admin-configured "ALL networks" route for a specific
    // data_type (e.g. network=NULL, data_type=NIGHT) could never be selected
    // once a specific network was known, making that route silently dead.
    // Exact network/data_type matches are still preferred via the ORDER BY.
    $sql = "$select$from WHERE p.service=? AND (p.network=? OR p.network IS NULL OR p.network='') AND (p.data_type=? OR p.data_type IS NULL OR p.data_type='') AND p.is_active=1 ORDER BY CASE WHEN p.network=? THEN 0 ELSE 1 END, CASE WHEN p.data_type=? THEN 0 ELSE 1 END, p.priority";
    $params = [$service, $network, $data_type, $network, $data_type];
  } elseif ($network !== null) {
    // An exact-network route must always outrank a universal ("ALL networks")
    // route, regardless of their priority values — otherwise a tie leaves
    // which one actually gets tried first up to arbitrary row order. Mirrors
    // the same exact-match-first fix applied to the data_type branch above.
    $sql = "$select$from WHERE p.service=? AND (p.network=? OR p.network IS NULL OR p.network='') AND p.is_active=1 ORDER BY CASE WHEN p.network=? THEN 0 ELSE 1 END, p.priority";
    $params[] = $network; $params[] = $network;
  } else {
    $sql = "$select$from WHERE p.service=? AND p.is_active=1 ORDER BY p.priority";
  }
  $s = db()->prepare($sql); $s->execute($params);
  // Filtered in PHP rather than SQL — provider_locked() uses the same
  // stripos($pname,'x') substring convention every service file's own
  // provider loop already uses, so this stays in lockstep with however a
  // row's provider_name is actually matched at purchase time, and reuses the
  // one function rather than re-deriving the same logic in SQL.
  return array_values(array_filter($s->fetchAll(), fn($p) => !provider_locked($p['provider_name'] ?? null)));
}

// For "browse a live catalog" services (esim, international_airtime,
// giftcard_buy) where each provider's inventory is disjoint (not
// interchangeable the way "MTN airtime" is across multiple VTU providers) —
// unlike try_api_providers()'s first-success-wins failover, this aggregates
// every active provider's catalog so a second registered provider's
// products actually show up to the customer, not just in admin config. One
// provider erroring/timing out only shrinks the list, never blanks it.
// $fetch_fn(array $prov, string $region): array — raw catalog items, or []
// on failure. Each returned item is stamped with which provider it came
// from so the client can round-trip that identity back at purchase time.
function aggregate_provider_catalog(string $service, string $region, callable $fetch_fn): array {
  $providers = get_api_providers($service, $region);
  $out = [];
  foreach ($providers as $prov) {
    if (empty($prov['api_base_url'])) continue;
    try {
      foreach ($fetch_fn($prov, $region) as $item) {
        $item['api_registry_id'] = (int)$prov['api_registry_id'];
        $item['provider_name'] = $prov['provider_name'];
        $out[] = $item;
      }
    } catch (\Throwable $e) {
      log_error('api_router', "catalog fetch failed for {$prov['provider_name']}/$service", ['err' => $e->getMessage()]);
    }
  }
  return $out;
}

// Purchase-time re-validation for the same catalog-browsing services — never
// trust a client-submitted price/code. Re-fetches the ONE specific provider
// the user's selection came from (never re-picks a different provider) and
// re-locates the item by its opaque code, so the caller can use the fresh
// NGN price as the source of truth. Returns null if that provider is no
// longer active, or the code no longer exists in its current catalog (item
// removed / price changed since the user was browsing) — callers must fail
// the purchase closed on null, before any wallet debit.
function relocate_catalog_item(string $service, string $region, int $api_registry_id, string $code_field, string $code_value, callable $fetch_fn): ?array {
  foreach (get_api_providers($service, $region) as $prov) {
    if ((int)$prov['api_registry_id'] !== $api_registry_id) continue;
    if (empty($prov['api_base_url'])) return null;
    foreach ($fetch_fn($prov, $region) as $item) {
      if ((string)($item[$code_field] ?? '') === $code_value) { $item['_prov'] = $prov; return $item; }
    }
    return null;
  }
  return null;
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
// AutosyncNG's "product_id" is a per-category product code, not a flat
// per-network ID — confirmed against their own developer docs
// (autosyncng.com/user/developer/docs): the Data Gifting category uses
// MTN=2/GLO=8/AIRTEL=3/9MOBILE=7, while Data SME and Data Corporate are a
// completely different category with MTN=4/AIRTEL=5 (GLO/9MOBILE aren't
// offered under SME/Corporate at all per their product list). The
// 'autosyncng' key below is the Gifting-equivalent default (also used as-is
// for data_card, confirmed via a real historical successful data_card log
// entry using product_id 2 for MTN); 'autosyncng_sme' is the SME/Corporate
// variant, selected by build_network_map() when the route's data_type is
// SME or CORPORATE.
function default_network_id_map(string $service, string $type = 'generic'): array {
  // AutoPilot's real network IDs — confirmed against their own developer
  // docs (autopilotng.com/api/developer/v1/documentation/start, "Get
  // Networks" sample response): MTN=1, AIRTEL=2, GLO=3, 9MOBILE=4. This is
  // NOT the same as the generic default (which has GLO=2/AIRTEL=3 swapped
  // relative to AutoPilot's real scheme) — MTN happened to work by
  // coincidence (1=1 in both), which is what made AIRTEL/GLO/9MOBILE look
  // like a plan-ID problem when it was actually always the network ID.
  $autopilot_map = ['MTN'=>1,'AIRTEL'=>2,'GLO'=>3,'9MOBILE'=>4];
  $maps = [
    'airtime' => [
      'generic' => ['MTN'=>1,'GLO'=>2,'AIRTEL'=>3,'9MOBILE'=>4],
      'autosyncng' => ['MTN'=>1,'GLO'=>15,'AIRTEL'=>16,'9MOBILE'=>17],
      'autopilot' => $autopilot_map,
      // Deliberately all-null, not a guessed scheme — Reteller's
      // variation_code is an opaque per-account catalog token (their own
      // docs: "send it back exactly as received; do not parse or construct
      // it"), so there is no default to fall back to. Without this, an
      // unconfigured network would silently inherit the meaningless generic
      // numeric map above (array_merge only overrides keys present in the
      // route's own network_id_map override) and send Reteller a guaranteed-
      // wrong id instead of cleanly skipping to the next provider.
      'reteller' => ['MTN'=>null,'GLO'=>null,'AIRTEL'=>null,'9MOBILE'=>null],
    ],
    'recharge_card' => [
      'generic' => ['MTN'=>1,'GLO'=>2,'AIRTEL'=>3,'9MOBILE'=>4],
      'autosyncng' => ['MTN'=>1,'GLO'=>15,'AIRTEL'=>16,'9MOBILE'=>17],
      'autopilot' => $autopilot_map,
    ],
    'data_card' => [
      'generic' => ['MTN'=>1,'GLO'=>2,'AIRTEL'=>3,'9MOBILE'=>4],
      'autosyncng' => ['MTN'=>2,'GLO'=>8,'AIRTEL'=>3,'9MOBILE'=>7],
      'autosyncng_sme' => ['MTN'=>4,'AIRTEL'=>5],
      'autopilot' => $autopilot_map,
    ],
    'data' => [
      'generic' => ['MTN'=>1,'GLO'=>2,'AIRTEL'=>3,'9MOBILE'=>4],
      'autosyncng' => ['MTN'=>2,'GLO'=>8,'AIRTEL'=>3,'9MOBILE'=>7],
      'autosyncng_sme' => ['MTN'=>4,'AIRTEL'=>5],
      'autopilot' => $autopilot_map,
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
      // AutosyncNG's airtime-to-cash endpoint takes a lowercase network-name
      // string as product_id, not a numeric id — confirmed for 'mtn' (the
      // value this integration has actually run with). AIRTEL/GLO/9MOBILE
      // follow the same slug convention AutosyncNG uses elsewhere (see the
      // electricity map above) but are NOT independently confirmed against
      // AutosyncNG's own docs — verify with a small live A2C test before
      // trusting real customer volume on AIRTEL, and override here (or via
      // the route's network_id_map in API Routes admin) if AutosyncNG's
      // actual value differs.
      'autosyncng' => ['MTN'=>'mtn','GLO'=>'glo','AIRTEL'=>'airtel','9MOBILE'=>'9mobile'],
    ],
  ];
  return $maps[$service][$type] ?? $maps[$service]['generic'] ?? [];
}

// Build the effective network_id_map for a provider (provider map overrides defaults)
function build_network_map(array $prov, string $service): array {
  $pname = strtolower($prov['provider_name'] ?? '');
  $type = strpos($pname, 'autosyncng') !== false ? 'autosyncng' : (strpos($pname, 'autopilot') !== false ? 'autopilot' : (strpos($pname, 'reteller') !== false ? 'reteller' : 'generic'));
  // AutosyncNG's SME/Corporate data category uses different product IDs than
  // Gifting (see default_network_id_map() above) — pick the right variant
  // based on the route's own data_type before applying the row's override.
  // Only 'data'/'data_card' have an 'autosyncng_sme' variant defined; other
  // services ignore data_type entirely (e.g. a stray data_type value on an
  // airtime route must not accidentally divert it to a nonexistent variant).
  if ($type === 'autosyncng' && in_array($service, ['data', 'data_card']) && in_array(strtoupper($prov['data_type'] ?? ''), ['SME', 'CORPORATE'])) {
    $type = 'autosyncng_sme';
  }
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

// PHP array-key lookups are case-sensitive, but providers are not consistent
// about casing their status field — confirmed live: Gladtidings returns
// "Status" (capital S) on its BuyData endpoint, which every isset($res['status'])
// check below silently missed, letting a real decline
// ({"Status":"failed","api_response":"Please check entered number is not GLO
// user"}) fall through as a clean success and keep the customer's money.
// Finds the first case-insensitive match for $key among $res's own top-level
// keys, without needing to know which exact casing a given provider used.
function find_key_ci(array $res, string $key) {
  foreach ($res as $k => $v) {
    if (strcasecmp((string)$k, $key) === 0) return $v;
  }
  return null;
}

// Check if an API response explicitly indicates failure (e.g. insufficient balance)
// Returns true if the response body indicates failure, false if success or indeterminate
function is_api_response_failure(?array $res): bool {
  if (!$res) return true;
  if (isset($res['success']) && $res['success'] === false) return true;
  $status = find_key_ci($res, 'status');
  if ($status !== null && in_array(strtolower((string)$status), ['failed', 'error', 'fail'])) return true;
  // Reteller's top-level "status" is a literal JSON/PHP boolean, not a
  // string — strtolower((string)false) is "" and strtolower((string)true)
  // is "1", so neither matches the string check above and a genuine
  // boolean-false decline (data:null) would otherwise silently fall through
  // as "not a failure". Checked as strict === false (not a falsy check) so
  // this can't misfire against some other provider's unrelated falsy value.
  if ($status === false) return true;

  // Nested status checks (e.g. AutoSyncNG data.transaction.status).
  // 'cancelled' added for PayVessel, whose order lifecycle status sits at
  // data.status (its top-level "status" is a boolean call-succeeded flag,
  // already handled above) — without this, a cancelled PayVessel order
  // matches neither failure nor pending here and falls through to being
  // treated as a success by every calling loop's default branch.
  $txStatus = $res['data']['transaction']['status'] ?? $res['data']['status'] ?? '';
  if ($txStatus && in_array(strtolower((string)$txStatus), ['failed', 'error', 'fail', 'reversed', 'cancelled'])) return true;

  // AutoSyncNG-style insufficient balance code
  if (isset($res['code']) && (string)$res['code'] === '101') return true;
  // Message-based insufficiency detection (checked across every common
  // message-carrying field, including Gladtidings' own "api_response" key)
  foreach (['message', 'error', 'api_response', 'description'] as $mk) {
    $mv = find_key_ci($res, $mk);
    if (is_string($mv) && stripos($mv, 'insufficient') !== false) return true;
  }
  return false;
}

// Check if an API response indicates pending/processing (neither success nor failure)
function is_api_response_pending(?array $res): bool {
  if (!$res) return false;
  $status = find_key_ci($res, 'status');
  if ($status !== null && in_array(strtolower((string)$status), ['pending', 'processing'])) return true;
  $txStatus = $res['data']['transaction']['status'] ?? $res['data']['status'] ?? '';
  if ($txStatus && strtolower((string)$txStatus) === 'pending') return true;
  if ($txStatus && strtolower((string)$txStatus) === 'processing') return true;
  return false;
}

// True when a provider's response is telling us "this reference was already
// used" rather than genuinely declining the purchase. This only ever
// legitimately happens after OUR OWN timeout-retry re-sends the exact same
// reference — meaning the provider actually received and recorded the FIRST
// (unconfirmed) attempt, so we still don't know whether it succeeded or
// failed. Callers must treat this exactly like a network timeout (leave the
// transaction 'processing' for manual/cron verification), never as a clean
// decline — refunding here risks double-crediting a customer whose money
// may have already been spent on real delivery by that first attempt.
function is_duplicate_reference_response(?array $res): bool {
  if (!$res) return false;
  $text = strtolower((string)($res['message'] ?? $res['data']['message'] ?? $res['error'] ?? ''));
  if ($text === '') return false;
  return str_contains($text, 'duplicate') && (str_contains($text, 'reference') || str_contains($text, 'ref '));
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
        // Gladtidings' own field name for the human-readable outcome text
        // (e.g. "Please check entered number is not GLO user") — not any of
        // the generic names above, so it was silently skipped in favor of
        // the raw JSON dump further down even after the decline was
        // correctly detected.
        $res['api_response'] ?? null,
        $res['data']['transaction']['message'] ?? null,
        $res['data']['message'] ?? null,
    ];
    foreach ($candidates as $c) {
        // Capped defensively — some providers' success messages run long
        // (confirmed: AutoPilot's MTN success text ran ~186 chars, far past
        // any failure message seen from any provider so far) and this value
        // gets appended into transactions.meta JSON alongside other fields,
        // so an unbounded message is the one thing that scales with how
        // verbose a given provider happens to be, rather than anything under
        // our control.
        if (is_string($c) && trim($c) !== '') return substr(trim($c), 0, 500);
        if (is_array($c) && !empty($c)) { $j = json_encode($c); if ($j) return substr($j, 0, 500); }
    }
    $clean = $res;
    unset($clean['_http_code'], $clean['_curl_errno'], $clean['_curl_error'], $clean['_network_error']);
    if (empty($clean)) return '';
    $j = json_encode(redact_sensitive($clean), JSON_UNESCAPED_SLASHES);
    return $j ? substr($j, 0, 500) : '';
}

// $meter_type only matters for electricity, where a disco can have a
// different provider Plan ID for prepaid vs postpaid (e.g. SageCloud's own
// compound slugs already treat them as distinct products). Every other
// service always binds under the blank meter_type ('') and every existing
// caller that doesn't pass one keeps hitting exactly that row — unaffected.
function get_plan_bindings(string $service, int $plan_id, ?string $meter_type = null): array {
  try {
    // api_registry's URL column is named `base_url`, not `api_base_url` — this
    // query used to select the latter, which doesn't exist on the table, so
    // every call threw a PDOException that the catch below silently swallowed
    // into an empty array. Every plan-specific provider binding configured via
    // data-plan-mapping.php (data/cabletv/electricity) was therefore always
    // ignored at purchase time, with no error surfaced anywhere.
    $s = db()->prepare("SELECT b.product_code, r.name as provider_name, r.base_url AS api_base_url, r.api_key, r.api_token, r.api_pin, r.contract_code, r.public_key, r.secret_key, r.network_id_map, r.value_map FROM plan_api_bindings b JOIN api_registry r ON r.id=b.api_registry_id WHERE b.service=? AND b.plan_id=? AND b.meter_type=? AND r.is_active=1 ORDER BY b.priority");
    $s->execute([$service, $plan_id, $meter_type ?? '']);
    // Same vendor-side provider lock enforced in get_api_providers() — a
    // per-plan binding (Plan ID Mapping) is a separate query path and would
    // otherwise let a locked provider be routed through anyway.
    return array_values(array_filter($s->fetchAll(PDO::FETCH_ASSOC), fn($p) => !provider_locked($p['provider_name'] ?? null)));
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
const VERIFIABLE_PROVIDERS = ['autosyncng', 'msjdatasubs', 'telecomabode', 'reteller', 'payvessel'];

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

    // Reteller: GET /transactions/{reference} — its own dedicated Bearer
    // public_key:secret_key scheme, not the Token/Bearer-from-api_key/token
    // headers built above, so it needs its own header set here.
    if (stripos($pname, 'reteller') !== false) {
        $rt_headers = ['Content-Type: application/json'];
        if (!empty($provider_config['public_key']) && !empty($provider_config['secret_key'])) {
            $rt_headers[] = 'Authorization: Bearer ' . $provider_config['public_key'] . ':' . $provider_config['secret_key'];
        }
        $url = "$base/transactions/$ref";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $rt_headers,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = $res ? json_decode($res, true) : null;
        if (!$data || !in_array($code, [200, 201])) return null;

        $s = strtolower((string)($data['data']['status'] ?? ''));
        if ($s === 'successful') {
            return ['success' => true, 'failed' => false, 'token' => '', 'message' => $data['message'] ?? '', 'provider_ref' => $data['data']['reference'] ?? ''];
        }
        if ($s === 'reversed') {
            return ['success' => false, 'failed' => true, 'token' => '', 'message' => $data['message'] ?? 'Provider declined/reversed', 'provider_ref' => ''];
        }
        return null; // still pending
    }

    // PayVessel: verify-by-reference, but the path differs by product family
    // (gift-cards vs biller-reseller both expose /orders/verify/{reference};
    // there's no equivalent for other categories yet, so this only covers
    // what's actually wired up so far). Same known limitation as Reteller's
    // branch above: this generic shape has no slot for a gift card's
    // redeem_code, so an order that only resolves via this cron path (never
    // came back immediately-successful at purchase time) won't have its
    // code captured here — consistent with Reteller's existing behavior for
    // the same case, not a new gap introduced for PayVessel specifically.
    if (stripos($pname, 'payvessel') !== false) {
        $pv_headers = ['Content-Type: application/json'];
        if (!empty($provider_config['api_key'])) $pv_headers[] = 'api-key: ' . $provider_config['api_key'];
        if (!empty($provider_config['secret_key'])) $pv_headers[] = 'api-secret: ' . $provider_config['secret_key'];

        $verifyPath = $category === 'giftcard_buy' ? '/vaas/api/v1/gift-cards/orders/verify/' : '/vaas/api/v1/biller-reseller/orders/verify/';
        $url = $base . $verifyPath . urlencode($ref);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $pv_headers,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = $res ? json_decode($res, true) : null;
        if (!$data || !in_array($code, [200, 201])) return null;

        $s = strtolower((string)($data['data']['status'] ?? ''));
        if ($s === 'completed') {
            return ['success' => true, 'failed' => false, 'token' => '', 'message' => $data['message'] ?? '', 'provider_ref' => $data['data']['order_reference'] ?? $data['data']['id'] ?? ''];
        }
        if ($s === 'failed' || $s === 'cancelled') {
            return ['success' => false, 'failed' => true, 'token' => '', 'message' => $data['data']['error_message'] ?? $data['message'] ?? 'Provider declined', 'provider_ref' => ''];
        }
        return null; // still pending/processing
    }

    // Unknown provider - cannot check status
    return null;
}
