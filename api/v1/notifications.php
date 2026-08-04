<?php
require_once __DIR__ . '/auth.php';

$user = api_auth();
$uid = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';
    if ($action === 'mark_read') {
        $nid = (int)($input['id'] ?? 0);
        if ($nid) {
            db()->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND (user_id=? OR user_id IS NULL)")->execute([$nid, $uid]);
        } else {
            db()->prepare("UPDATE notifications SET is_read=1 WHERE (user_id=? OR user_id IS NULL) AND is_read=0")->execute([$uid]);
        }
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($action === 'clear_all') {
        db()->prepare("DELETE FROM notifications WHERE user_id=?")->execute([$uid]);
        echo json_encode(['status' => 'success']);
        exit;
    }
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = min(50, max(10, (int)($_GET['per_page'] ?? 20)));
$offset = ($page - 1) * $per_page;

$stmt = db()->prepare("SELECT id, title, message, type, action_url, is_read, created_at 
    FROM notifications 
    WHERE (user_id IS NULL OR user_id = ?) 
    ORDER BY created_at DESC 
    LIMIT ? OFFSET ?");
$stmt->execute([$uid, $per_page, $offset]);
$notifications = $stmt->fetchAll();

$cnt = db()->prepare("SELECT COUNT(*) FROM notifications WHERE (user_id IS NULL OR user_id = ?) AND is_read = 0");
$cnt->execute([$uid]);
$unread = (int)$cnt->fetchColumn();

$total = db()->prepare("SELECT COUNT(*) FROM notifications WHERE (user_id IS NULL OR user_id = ?)");
$total->execute([$uid]);
$total_count = (int)$total->fetchColumn();

echo json_encode([
    'status' => 'success',
    'data' => [
        'notifications' => $notifications,
        'unread_count' => $unread,
        'page' => $page,
        'per_page' => $per_page,
        'total' => $total_count,
        'total_pages' => (int)ceil($total_count / $per_page)
    ]
]);
