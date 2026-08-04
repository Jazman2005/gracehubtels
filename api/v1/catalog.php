<?php
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed. Use GET.']);
    exit;
}

$user = api_auth();

$db = db();

$role = $user['user_type'] ?? 'regular';

function cat_price($base, $agent, $vendor, $role) {
    if ($role === 'vendor' && $vendor > 0) return $vendor;
    if ($role === 'agent' && $agent > 0) return $agent;
    return $base;
}

$data_plans = [];
$stmt = $db->query("SELECT id, network, plan_id, plan_name, display_name, data_type, data_size, validity, selling_price, agent_price, vendor_price, network_id FROM data_plans WHERE is_active = 1 ORDER BY data_type, selling_price");
while ($row = $stmt->fetch()) {
    $price = cat_price((float)$row['selling_price'], (float)$row['agent_price'], (float)$row['vendor_price'], $role);
    $data_plans[] = [
        'id' => (int)$row['id'],
        'network' => $row['network'],
        'plan_id' => $row['plan_id'],
        'plan_name' => $row['plan_name'],
        'display_name' => $row['display_name'],
        'data_type' => $row['data_type'],
        'data_size' => $row['data_size'],
        'validity' => $row['validity'],
        'selling_price' => number_format($price, 2, '.', ''),
        'network_id' => $row['network_id']
    ];
}

$cable_plans = [];
$stmt = $db->query("SELECT id, provider, cable_id, plan_id, plan_name, display_name, selling_price, agent_price, vendor_price FROM cable_plans WHERE is_active = 1");
while ($row = $stmt->fetch()) {
    $price = cat_price((float)$row['selling_price'], (float)$row['agent_price'], (float)$row['vendor_price'], $role);
    $cable_plans[] = [
        'id' => (int)$row['id'],
        'provider' => $row['provider'],
        'cable_id' => $row['cable_id'],
        'plan_id' => $row['plan_id'],
        'plan_name' => $row['plan_name'],
        'display_name' => $row['display_name'],
        'selling_price' => number_format($price, 2, '.', '')
    ];
}

$discos = [];
$stmt = $db->query("SELECT disco_id, disco_name, charge, agent_charge, vendor_charge FROM electricity_discos WHERE is_active = 1 ORDER BY disco_name");
while ($row = $stmt->fetch()) {
    $charge = cat_price((float)$row['charge'], (float)$row['agent_charge'], (float)$row['vendor_charge'], $role);
    $discos[] = [
        'disco_id' => $row['disco_id'],
        'disco_name' => $row['disco_name'],
        'charge' => number_format($charge, 2, '.', '')
    ];
}

$exam_prices = [];
$stmt = $db->query("SELECT plan_name, selling_price, agent_price, vendor_price FROM service_pricing WHERE service = 'exam_pin' AND is_active = 1");
while ($row = $stmt->fetch()) {
    $price = cat_price((float)$row['selling_price'], (float)$row['agent_price'], (float)$row['vendor_price'], $role);
    $exam_prices[$row['plan_name']] = (float)number_format($price, 2, '.', '');
}

$bulk_sms_pricing = null;
$stmt = $db->query("SELECT selling_price, agent_price, vendor_price, discount_percent, agent_discount, vendor_discount, cost_price FROM service_pricing WHERE service = 'bulk_sms' AND is_active = 1 LIMIT 1");
$bs = $stmt->fetch();
if ($bs) {
    $price = cat_price((float)$bs['selling_price'], (float)$bs['agent_price'], (float)$bs['vendor_price'], $role);
    $discount = $bs['discount_percent'];
    if ($role === 'agent' && (float)$bs['agent_discount'] > 0) $discount = (float)$bs['agent_discount'];
    if ($role === 'vendor' && (float)$bs['vendor_discount'] > 0) $discount = (float)$bs['vendor_discount'];
    $bulk_sms_pricing = [
        'price_per_page' => (float)number_format($price, 2, '.', ''),
        'discount_percent' => (float)$discount,
        'cost_price' => (float)$bs['cost_price']
    ];
}

echo json_encode([
    'status' => 'success',
    'data' => [
        'data_plans' => $data_plans,
        'cable_plans' => $cable_plans,
        'discos' => $discos,
        'exam_prices' => $exam_prices,
        'bulk_sms_pricing' => $bulk_sms_pricing
    ]
]);
