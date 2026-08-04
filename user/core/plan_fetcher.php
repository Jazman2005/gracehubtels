<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/api_router.php';

function fetch_plans_from_api(string $service, int $registry_id, ?string $fetch_url_override = null, bool $dry_run = false): array {
    $result = ['success' => false, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [], 'plans' => [], 'diagnostic' => ''];

    $stmt = db()->prepare("SELECT * FROM api_registry WHERE id=? AND is_active=1 LIMIT 1");
    $stmt->execute([$registry_id]);
    $registry = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$registry) { $result['errors'][] = 'Registry entry not found or inactive'; return $result; }

    $method = strtoupper($registry['plan_fetch_method'] ?? 'GET');
    $fetch_url = $fetch_url_override ?: ($registry['plan_fetch_url'] ?? '');

    $candidates = discover_fetch_candidates($service, $registry, $method, $fetch_url);
    if (empty($candidates)) {
        $result['errors'][] = 'No fetch URL configured and auto-discovery failed. Set a Plan Fetch URL in the registry entry.';
        return $result;
    }

    $plans = null;
    $last_err = '';
    $err_msg = '';
    $attempts = 0;
    $max_attempts = 6;
    foreach ($candidates as $c) {
        if ($attempts >= $max_attempts) {
            if (!$last_err) $last_err = 'Gave up after ' . $max_attempts . ' attempts';
            break;
        }
        $attempts++;
        $raw = http_call_json($c['url'], $registry, $c['method'], $err_msg);
        if ($raw === null) {
            $last_err = "$c[method] $c[url] — $err_msg";
            $result['diagnostic'] = $last_err;
            continue;
        }
        $plans = parse_plan_response($raw, $service);
        if ($plans !== null && count($plans) > 0) {
            break;
        }
        $last_err = "$c[method] $c[url] — no plans in response";
    }

    if ($plans === null || count($plans) === 0) {
        $result['errors'][] = "Could not fetch plans from any endpoint. Last attempt: $last_err";
        $result['diagnostic'] = $last_err;
        log_api($registry['name'] ?? 'plan_fetcher', "fetch/$service/fail", [
            'registry' => $registry['name'], 'service' => $service, 'error' => $last_err
        ], $result, false);
        return $result;
    }

    switch ($service) {
        case 'data':         $result = process_data_plans($plans, $registry, $result, $dry_run); break;
        case 'cabletv':      $result = process_cable_plans($plans, $registry, $result, $dry_run); break;
        case 'recharge_card':
        case 'exam_pin':
        case 'data_card':
        case 'electricity':
        case 'airtime':
        case 'bulk_sms':    $result = process_pricing_plans($service, $plans, $registry, $result, $dry_run); break;
        default:            $result['errors'][] = "Unknown service: $service"; return $result;
    }

    $result['success'] = count($result['errors']) === 0;
    log_api($registry['name'] ?? 'plan_fetcher', "fetch/$service", [
        'url' => $candidates[0]['url'] ?? '', 'registry_id' => $registry_id, 'service' => $service,
        'fetched' => count($plans), 'created' => $result['created'], 'updated' => $result['updated'], 'dry_run' => $dry_run
    ], $result, $result['success']);

    return $result;
}

function discover_fetch_candidates(string $service, array $registry, string $method, string $override_url): array {
    $base = rtrim($registry['base_url'], '/');
    $pname = strtolower($registry['name'] ?? '');
    $candidates = [];

    // 1. Override URL (highest priority)
    if ($override_url) {
        $candidates[] = ['url' => $override_url, 'method' => $method];
    }

    // 2. Provider-specific URLs
    $is_autosyncng = strpos($pname, 'autosyncng') !== false;

    if ($is_autosyncng) {
        $auto_paths = auto_discover_urls($service, $base);
        foreach ($auto_paths as $p) $candidates[] = $p;
    } else {
        // Generic provider patterns
        $svc_paths = [
            'data' => [
                'api/services/data', 'api/v1/services?type=data',
                'api/data/plans', 'api/v1/data',
                'api/services'
            ],
            'cabletv' => [
                'api/services/cable', 'api/v1/services?type=cable',
                'api/cable/plans', 'api/v1/cable',
                'api/services'
            ],
            'electricity' => ['api/services/electricity', 'api/v1/services?type=electricity', 'api/services'],
            'recharge_card' => ['api/services/recharge', 'api/v1/services?type=recharge', 'api/services'],
            'exam_pin' => ['api/services/exam', 'api/v1/services?type=exam', 'api/services'],
            'airtime' => ['api/services/airtime', 'api/v1/services?type=airtime', 'api/services'],
            'data_card' => ['api/services/data', 'api/services'],
        ];
        foreach ($svc_paths[$service] ?? ['api/services'] as $p) {
            $candidates[] = ['url' => "$base/$p", 'method' => 'GET'];
        }
        // Also try as POST
        foreach ($svc_paths[$service] ?? ['api/services'] as $p) {
            $candidates[] = ['url' => "$base/$p", 'method' => 'POST'];
        }
    }

    // 3. Generic catch-all (only if under 6 candidates already)
    if (count($candidates) < 6) {
        $candidates[] = ['url' => "$base/api/services", 'method' => 'GET'];
        $candidates[] = ['url' => "$base/api/v1/services", 'method' => 'GET'];
        $candidates[] = ['url' => "$base/api/services", 'method' => 'POST'];
        $candidates[] = ['url' => "$base/api/v1/services", 'method' => 'POST'];
    }

    // Deduplicate (keep first occurrence)
    $seen = [];
    $unique = [];
    foreach ($candidates as $c) {
        $key = $c['method'] . ':' . $c['url'];
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $unique[] = $c;
    }
    return $unique;
}

function auto_discover_urls(string $service, string $base): array {
    // AutoSyncNG v1 purchase endpoints (GET may return category/products)
    $v1 = [];
    $svc_endpoints = [
        'data'    => ['api/v1/data', 'api/v2/data-sme', 'api/v2/data-gifting', 'api/v2/data-corporate', 'api/v2/data-transfer'],
        'cabletv' => ['api/v1/cable'],
        'electricity' => ['api/v1/electricity'],
        'recharge_card' => ['api/v1/data-pin'],
        'exam_pin' => ['api/v1/data-pin'],
        'airtime' => ['api/v1/airtime', 'api/v1/airtime-share'],
        'data_card' => ['api/v1/data-pin'],
    ];
    foreach ($svc_endpoints[$service] ?? [] as $ep) {
        $v1[] = ['url' => "$base/$ep", 'method' => 'GET'];
        $v1[] = ['url' => "$base/$ep", 'method' => 'POST'];
    }
    // Also try variations endpoint (used by some VTU platforms)
    $v1[] = ['url' => "$base/api/v1/variations", 'method' => 'POST'];
    $v1[] = ['url' => "$base/api/v1/variations", 'method' => 'GET'];
    $v1[] = ['url' => "$base/api/v2/variations", 'method' => 'POST'];
    $v1[] = ['url' => "$base/api/v2/variations", 'method' => 'GET'];
    return $v1;
}

function http_call_json(string $url, array $registry, string $method = 'GET', string &$error_msg = ''): ?array {
    $headers = ['Content-Type: application/json'];
    if (!empty($registry['api_key'])) $headers[] = 'Authorization: Token ' . $registry['api_key'];
    elseif (!empty($registry['api_token'])) $headers[] = 'Authorization: Bearer ' . $registry['api_token'];

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => true,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = '{}';
    }
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($res === false || $res === '') {
        $error_msg = $err ?: "Empty response (HTTP $code)";
        log_api('plan_fetcher', 'http_fail', ['url' => $url, 'method' => $method, 'error' => $error_msg, 'http_code' => $code], null, false);
        return null;
    }

    $d = json_decode($res, true);
    if (!is_array($d)) {
        $error_msg = "Invalid JSON (HTTP $code): " . substr($res, 0, 200);
        log_api('plan_fetcher', 'http_parse_fail', ['url' => $url, 'method' => $method, 'http_code' => $code], ['raw' => substr($res, 0, 500)], false);
        return null;
    }

    $d['_http_code'] = $code;
    return $d;
}

function parse_plan_response(?array $res, string $service): ?array {
    if (!$res) return null;

    // Format: flat list array
    if (array_is_list($res) && count($res) > 0 && is_array($res[0])) return $res;

    // Format: { "data": [...] }
    if (isset($res['data']) && is_array($res['data'])) {
        $d = $res['data'];
        if (array_is_list($d) && count($d) > 0 && is_array($d[0])) return $d;
        foreach (['variations', 'plans', 'products', 'services', 'items', 'list', 'categories'] as $k) {
            if (isset($d[$k]) && is_array($d[$k]) && array_is_list($d[$k]) && count($d[$k]) > 0) return $d[$k];
        }
        $merged = [];
        foreach ($d as $v) { if (is_array($v) && array_is_list($v)) $merged = array_merge($merged, $v); }
        if (!empty($merged)) return $merged;
    }

    foreach (['variations', 'plans', 'services', 'products', 'result', 'items', 'list', 'categories'] as $k) {
        if (isset($res[$k]) && is_array($res[$k]) && array_is_list($res[$k]) && count($res[$k]) > 0) return $res[$k];
    }

    // AutoSyncNG style: { "status": "ok", "data": { "categories": [...] } } inside data
    if (isset($res['status']) && in_array(strtolower((string)$res['status']), ['ok', 'success'], true) && isset($res['data'])) {
        $d = $res['data'];
        if (is_array($d)) {
            foreach (['categories', 'transactions', 'bundles', 'products', 'plans', 'variations', 'items', 'list'] as $k) {
                if (isset($d[$k]) && is_array($d[$k]) && array_is_list($d[$k]) && count($d[$k]) > 0) return $d[$k];
            }
        }
    }

    // Last resort: numeric keys with arrays of arrays
    foreach ($res as $k => $v) {
        if (is_int($k) && is_array($v) && array_is_list($v) && count($v) > 0 && is_array($v[0])) return $v;
    }

    return null;
}

function normalize_plan_entry(array $entry, string $service): ?array {
    $id   = $entry['variation_code'] ?? $entry['plan_id'] ?? $entry['product_id'] ?? $entry['service_id'] ?? $entry['id'] ?? $entry['code'] ?? $entry['key'] ?? null;
    $name = $entry['variation_name'] ?? $entry['plan_name'] ?? $entry['name'] ?? $entry['product_name'] ?? $entry['service_name'] ?? $entry['label'] ?? $entry['title'] ?? $entry['description'] ?? null;
    $price = $entry['amount'] ?? $entry['price'] ?? $entry['cost'] ?? $entry['selling_price'] ?? $entry['total_amount'] ?? $entry['value'] ?? null;
    $net  = $entry['network'] ?? $entry['provider'] ?? $entry['service'] ??
            $entry['network_name'] ?? $entry['provider_name'] ?? $entry['short_name'] ?? $entry['category'] ?? '';

    if ($id === null || $name === null) return null;

    $p = [
        'plan_id' => (string)$id,
        'plan_name' => trim((string)$name),
        'cost_price' => (float)($price ?: 0),
        'network' => strtoupper(trim((string)$net)),
        'data_size' => $entry['data_size'] ?? $entry['size'] ?? $entry['volume'] ?? $entry['data_volume'] ?? '',
        'validity' => $entry['validity'] ?? $entry['duration'] ?? $entry['plan'] ?? $entry['plan_type'] ?? $entry['period'] ?? '',
        'data_type' => $entry['data_type'] ?? $entry['type'] ?? $entry['category'] ?? $entry['group'] ?? '',
    ];
    // If data_type is just the network name, reset to SME
    if (in_array(strtoupper($p['data_type']), ['MTN', 'GLO', 'AIRTEL', '9MOBILE', '']) || is_numeric($p['data_type'])) {
        $p['data_type'] = 'SME';
    }

    // Derive network from name if missing
    if (!$p['network']) {
        $nu = strtoupper($p['plan_name']);
        $lookups = ['MTN', 'GLO' => 'GLO', 'AIRTEL', '9MOBILE',
                    'GW:MTN' => 'MTN', 'GW:GLO' => 'GLO', 'GW:AIRTEL' => 'AIRTEL', 'GW:9MOBILE' => '9MOBILE'];
        if ($service === 'exam_pin') $lookups += ['WAEC' => 'WAEC', 'NECO' => 'NECO', 'NABTEB' => 'NABTEB', 'GCE' => 'WAEC'];
        if ($service === 'cabletv') $lookups += ['DSTV' => 'DSTV', 'GOTV' => 'GOTV', 'STARTIMES' => 'STARTIMES', 'SHOWMAX' => 'SHOWMAX'];
        foreach ($lookups as $search => $map) {
            $search = is_int($search) ? $map : $search;
            if (strpos($nu, strtoupper($search)) !== false) { $p['network'] = $map; break; }
        }
    }

    // If still no network and service is cabletv, try harder from plan_id
    if ($service === 'cabletv' && !$p['network']) {
        $upper_id = strtoupper($p['plan_name'] . ' ' . $p['plan_id']);
        foreach (['DSTV' => 'DSTV', 'GOTV' => 'GOTV', 'STARTIMES' => 'STARTIMES', 'SHOWMAX' => 'SHOWMAX'] as $s => $m) {
            if (strpos($upper_id, $s) !== false) { $p['network'] = $m; break; }
        }
    }

    return $p;
}

function guess_data_size(string $name): string {
    if (preg_match('/(\d+(?:\.\d+)?)\s*(GB|MB|TB)/i', $name, $m)) return $m[1] . strtoupper($m[2]);
    return '';
}

function guess_validity(string $name): string {
    if (preg_match('/(\d+)\s*(DAY|DAYS|MONTH|MONTHS|YEAR|YEARS|HOUR|HOURS|WEEK|WEEKS)/i', $name, $m)) return strtoupper($m[1] . $m[2]);
    return '';
}

function process_data_plans(array $plans, array $registry, array $result, bool $dry_run): array {
    $db = db();
    $net_map = array_merge(
        default_network_id_map('data', 'generic'),
        json_decode($registry['network_id_map'] ?? '{}', true) ?: []
    );

    foreach ($plans as $entry) {
        if (!is_array($entry)) { $result['skipped']++; continue; }
        $n = normalize_plan_entry($entry, 'data');
        if (!$n || !$n['network']) { $result['skipped']++; continue; }

        $net = $n['network'];
        $net_id = $net_map[$net] ?? 1;
        $pid = $n['plan_id'];

        $existing = $db->prepare("SELECT id, cost_price FROM data_plans WHERE plan_id=? AND network=? LIMIT 1");
        $existing->execute([$pid, $net]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            if (!$dry_run) {
                $db->prepare("UPDATE data_plans SET cost_price=? WHERE id=?")->execute([$n['cost_price'], $row['id']]);
            }
            $result['updated']++;
            $result['plans'][] = ['action' => 'updated', 'plan' => $n['plan_name'], 'net' => $net, 'cost' => $n['cost_price']];
        } else {
            if (!$dry_run) {
                $data_size = $n['data_size'] ?: guess_data_size($n['plan_name']);
                $validity = $n['validity'] ?: guess_validity($n['plan_name']);
                $data_type = $n['data_type'] ?: 'SME';
                $selling = $n['cost_price'] > 0 ? round($n['cost_price'] * 1.1, 2) : 0;
                $db->prepare("INSERT IGNORE INTO data_plans (network, network_id, plan_id, plan_name, display_name, data_type, data_size, validity, cost_price, selling_price, is_active) VALUES (?,?,?,?,NULL,?,?,?,?,?,1)")
                    ->execute([$net, $net_id, $pid, $n['plan_name'], $data_type, $data_size, $validity, $n['cost_price'], $selling]);
            }
            $result['created']++;
            $result['plans'][] = ['action' => 'created', 'plan' => $n['plan_name'], 'net' => $net, 'cost' => $n['cost_price']];
        }
    }
    return $result;
}

function process_cable_plans(array $plans, array $registry, array $result, bool $dry_run): array {
    $db = db();
    $pname = strtolower($registry['name'] ?? '');
    $net_map = array_merge(
        default_network_id_map('cabletv', strpos($pname, 'autosyncng') !== false ? 'autosyncng' : 'generic'),
        json_decode($registry['network_id_map'] ?? '{}', true) ?: []
    );

    foreach ($plans as $entry) {
        if (!is_array($entry)) { $result['skipped']++; continue; }
        $n = normalize_plan_entry($entry, 'cabletv');
        if (!$n || !$n['network']) { $result['skipped']++; continue; }

        $prov = $n['network'];
        $cid = $net_map[$prov] ?? 1;
        $pid = $n['plan_id'];

        $existing = $db->prepare("SELECT id, cost_price FROM cable_plans WHERE plan_id=? AND provider=? LIMIT 1");
        $existing->execute([$pid, $prov]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            if (!$dry_run) {
                $db->prepare("UPDATE cable_plans SET cost_price=? WHERE id=?")->execute([$n['cost_price'], $row['id']]);
            }
            $result['updated']++;
            $result['plans'][] = ['action' => 'updated', 'plan' => $n['plan_name'], 'net' => $prov, 'cost' => $n['cost_price']];
        } else {
            if (!$dry_run) {
                $selling = $n['cost_price'] > 0 ? round($n['cost_price'] * 1.1, 2) : 0;
                $db->prepare("INSERT IGNORE INTO cable_plans (provider, cable_id, plan_id, plan_name, display_name, cost_price, selling_price, is_active) VALUES (?,?,?,?,NULL,?,?,1)")
                    ->execute([$prov, $cid, $pid, $n['plan_name'], $n['cost_price'], $selling]);
            }
            $result['created']++;
            $result['plans'][] = ['action' => 'created', 'plan' => $n['plan_name'], 'net' => $prov, 'cost' => $n['cost_price']];
        }
    }
    return $result;
}

function process_pricing_plans(string $service, array $plans, array $registry, array $result, bool $dry_run): array {
    $db = db();
    foreach ($plans as $entry) {
        if (!is_array($entry)) { $result['skipped']++; continue; }
        $n = normalize_plan_entry($entry, $service);
        if (!$n) { $result['skipped']++; continue; }

        $network = $n['network'];

        $existing = $db->prepare("SELECT id, cost_price FROM service_pricing WHERE service=? AND network=? AND plan_name=? LIMIT 1");
        $existing->execute([$service, $network, $n['plan_name']]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            if (!$dry_run) {
                $db->prepare("UPDATE service_pricing SET cost_price=? WHERE id=?")->execute([$n['cost_price'], $row['id']]);
            }
            $result['updated']++;
            $result['plans'][] = ['action' => 'updated', 'plan' => $n['plan_name'], 'net' => $network, 'cost' => $n['cost_price']];
        } else {
            if (!$dry_run) {
                $selling = $n['cost_price'] > 0 ? round($n['cost_price'] * 1.1, 2) : 0;
                $db->prepare("INSERT IGNORE INTO service_pricing (service, network, plan_name, cost_price, selling_price, is_active) VALUES (?,?,?,?,?,1)")
                    ->execute([$service, $network, $n['plan_name'], $n['cost_price'], $selling]);
            }
            $result['created']++;
            $result['plans'][] = ['action' => 'created', 'plan' => $n['plan_name'], 'net' => $network, 'cost' => $n['cost_price']];
        }
    }
    return $result;
}
