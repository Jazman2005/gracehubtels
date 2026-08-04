<?php
require_once __DIR__ . '/../../core/api_auth.php';

$user = authenticate_api();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed. Use GET.']);
    exit;
}

$db = db();

// Helper to calculate price based on user role
function calculate_price($base_price, $agent_price, $vendor_price, $role) {
    if ($role === 'vendor' && $vendor_price > 0) return $vendor_price;
    if ($role === 'agent' && $agent_price > 0) return $agent_price;
    return $base_price;
}

$role = $user['user_type'] ?? 'regular';

// Data Plans
$data_plans = [];
$stmt = $db->query("SELECT id, network, plan_id, display_name, data_type, validity, selling_price, agent_price, vendor_price FROM data_plans WHERE is_active = 1");
while ($row = $stmt->fetch()) {
    $price = calculate_price((float)$row['selling_price'], (float)$row['agent_price'], (float)$row['vendor_price'], $role);
    $data_plans[] = [
        'id' => (int)$row['id'],
        'network' => $row['network'],
        'plan_id' => $row['plan_id'],
        'name' => $row['display_name'],
        'type' => $row['data_type'],
        'validity' => $row['validity'],
        'price' => number_format($price, 2, '.', '')
    ];
}

// Cable Plans
$cable_plans = [];
$stmt = $db->query("SELECT id, provider, cable_id, plan_id, display_name, selling_price, agent_price, vendor_price FROM cable_plans WHERE is_active = 1");
while ($row = $stmt->fetch()) {
    $price = calculate_price((float)$row['selling_price'], (float)$row['agent_price'], (float)$row['vendor_price'], $role);
    $cable_plans[] = [
        'id' => (int)$row['id'],
        'provider' => $row['provider'],
        'cable_id' => $row['cable_id'],
        'plan_id' => $row['plan_id'],
        'name' => $row['display_name'],
        'price' => number_format($price, 2, '.', '')
    ];
}

// Electricity Discos
$discos = [];
$stmt = $db->query("SELECT id, disco_id, disco_name, charge, agent_charge, vendor_charge FROM electricity_discos WHERE is_active = 1");
while ($row = $stmt->fetch()) {
    $charge = calculate_price((float)$row['charge'], (float)$row['agent_charge'], (float)$row['vendor_charge'], $role);
    $discos[] = [
        'id' => (int)$row['id'],
        'disco_id' => $row['disco_id'],
        'name' => $row['disco_name'],
        'fee' => number_format($charge, 2, '.', '')
    ];
}

echo json_encode([
    'status' => 'success',
    'data' => [
        'data_plans' => $data_plans,
        'cable_plans' => $cable_plans,
        'electricity_discos' => $discos
    ]
]);
