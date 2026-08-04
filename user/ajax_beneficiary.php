<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/db.php';

auth_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_ajax()) {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
    exit;
}

$uid = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? '';

if ($action === 'save_beneficiary') {
    $service = sanitize($_POST['service'] ?? '', 50);
    $value = sanitize($_POST['value'] ?? '', 100);
    $label = sanitize($_POST['label'] ?? '', 100);
    $network = sanitize($_POST['network'] ?? '', 20) ?: null;
    
    if (!$service || !$value) {
        echo json_encode(['success' => false, 'message' => 'Service and value are required.']);
        exit;
    }
    
    if (!$label) {
        $label = $value; // fallback label
    }
    
    // Check if it already exists to prevent exact duplicates
    $chk = db()->prepare("SELECT id FROM beneficiaries WHERE user_id=? AND service=? AND value=? AND network=?");
    $chk->execute([$uid, $service, $value, $network]);
    if ($chk->fetch()) {
        echo json_encode(['success' => false, 'message' => 'This contact is already saved.']);
        exit;
    }
    
    // Insert new beneficiary
    $stmt = db()->prepare("INSERT INTO beneficiaries (user_id, service, label, value, network, is_favourite, last_used) VALUES (?, ?, ?, ?, ?, 1, NOW())");
    $stmt->execute([$uid, $service, $label, $value, $network]);
    
    echo json_encode(['success' => true, 'message' => 'Contact saved successfully!']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
