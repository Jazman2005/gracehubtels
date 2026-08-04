<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once dirname(__DIR__, 2) . '/user/core/db.php';

$raw = setting('faqs_data', '[]');
$faqs = json_decode($raw, true);

if (!is_array($faqs) || empty($faqs)) {
    echo json_encode([
        'success' => true,
        'status' => 'success',
        'message' => 'No FAQs available',
        'data' => []
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'status' => 'success',
    'message' => 'FAQs retrieved successfully',
    'data' => $faqs
]);
