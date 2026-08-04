<?php
require_once dirname(__DIR__, 2) . '/user/core/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

try {
    $stmt = db()->prepare("SELECT id, image_url, link, sort_order FROM banners WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
    $stmt->execute();
    $banners = $stmt->fetchAll();

    $base = rtrim((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'], '/');

    foreach ($banners as &$b) {
        if (!empty($b['image_url']) && strpos($b['image_url'], '://') === false) {
            $b['image_url'] = $base . '/' . ltrim($b['image_url'], '/');
        }
    }
    unset($b);

    echo json_encode([
        'status' => 'success',
        'success' => true,
        'data' => $banners
    ]);
} catch (Exception $e) {
    error_log("Banners fetch failed: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Failed to load banners']);
}
