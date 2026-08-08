<?php
require_once __DIR__ . '/auth.php';

$user = api_auth();
$uid = (int)$user['id'];

// notifications.is_read is a single column shared by every recipient of a
// broadcast row (user_id IS NULL) — updating it directly (as this endpoint
// used to) marked a broadcast announcement "read" for the ENTIRE user base
// the instant any one user opened/dismissed it. Per-user read state for
// broadcasts is tracked separately here instead of mutating the shared row.
try {
    db()->exec("CREATE TABLE IF NOT EXISTS notification_reads (
        user_id INT NOT NULL,
        notification_id INT NOT NULL,
        read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, notification_id)
    )");
} catch (\Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';
    if ($action === 'mark_read') {
        $nid = (int)($input['id'] ?? 0);
        if ($nid) {
            // A per-user notification can be updated directly (it's already
            // private to this user); a broadcast one gets a per-user
            // read-marker instead of touching the shared row.
            $owned = db()->prepare("SELECT user_id FROM notifications WHERE id=? AND (user_id=? OR user_id IS NULL)");
            $owned->execute([$nid, $uid]);
            $row = $owned->fetch();
            if ($row && $row['user_id'] === null) {
                db()->prepare("INSERT IGNORE INTO notification_reads (user_id, notification_id) VALUES (?, ?)")->execute([$uid, $nid]);
            } elseif ($row) {
                db()->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([$nid, $uid]);
            }
        } else {
            db()->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0")->execute([$uid]);
            db()->prepare("INSERT IGNORE INTO notification_reads (user_id, notification_id) SELECT ?, id FROM notifications WHERE user_id IS NULL")->execute([$uid]);
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

$stmt = db()->prepare("SELECT n.id, n.title, n.message, n.type, n.action_url, n.created_at,
    CASE WHEN n.user_id IS NULL THEN (nr.notification_id IS NOT NULL) ELSE n.is_read END AS is_read
    FROM notifications n
    LEFT JOIN notification_reads nr ON nr.notification_id = n.id AND nr.user_id = ?
    WHERE (n.user_id IS NULL OR n.user_id = ?)
    ORDER BY n.created_at DESC
    LIMIT ? OFFSET ?");
$stmt->execute([$uid, $uid, $per_page, $offset]);
$notifications = $stmt->fetchAll();
foreach ($notifications as &$n) { $n['is_read'] = (int)$n['is_read']; }
unset($n);

$cnt = db()->prepare("SELECT COUNT(*) FROM notifications n
    LEFT JOIN notification_reads nr ON nr.notification_id = n.id AND nr.user_id = ?
    WHERE (n.user_id IS NULL OR n.user_id = ?)
    AND (CASE WHEN n.user_id IS NULL THEN nr.notification_id IS NULL ELSE n.is_read = 0 END)");
$cnt->execute([$uid, $uid]);
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
