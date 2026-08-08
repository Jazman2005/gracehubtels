<?php
require_once __DIR__ . '/auth.php';

$user = api_auth();
$uid = (int)$user['id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $ticket_id = (int)($_GET['id'] ?? 0);

    if ($ticket_id) {
        $ticket = db()->prepare("SELECT * FROM tickets WHERE id=? AND user_id=?");
        $ticket->execute([$ticket_id, $uid]);
        $ticket = $ticket->fetch();
        if (!$ticket) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Ticket not found']);
            exit;
        }
        $messages = db()->prepare("SELECT * FROM ticket_messages WHERE ticket_id=? ORDER BY id ASC");
        $messages->execute([$ticket_id]);
        echo json_encode([
            'status' => 'success',
            'success' => true,
            'data' => [
                'ticket' => $ticket,
                'messages' => $messages->fetchAll(),
            ]
        ]);
        exit;
    }

    $tickets = db()->prepare("SELECT * FROM tickets WHERE user_id=? ORDER BY updated_at DESC");
    $tickets->execute([$uid]);
    echo json_encode([
        'status' => 'success',
        'success' => true,
        'data' => $tickets->fetchAll(),
    ]);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action = $input['action'] ?? '';

    if ($action === 'create') {
        // Each ticket creation fires an outbound WhatsApp alert to the
        // support channel — with no throttle, a single account could script
        // hundreds of tickets per minute and flood/spam that channel.
        if (!rate_limit('ticket_create_' . $uid, 10, 600)) {
            http_response_code(429);
            echo json_encode(['status' => 'error', 'message' => 'Too many tickets created. Please try again later.']);
            exit;
        }
        $subject = trim($input['subject'] ?? '');
        $message = trim($input['message'] ?? '');
        $priority = in_array($input['priority'] ?? '', ['low', 'medium', 'high']) ? $input['priority'] : 'medium';

        if (!$subject || !$message) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Subject and message are required']);
            exit;
        }

        try {
            db()->prepare("INSERT INTO tickets (user_id, subject, priority) VALUES (?, ?, ?)")->execute([$uid, $subject, $priority]);
            $ticket_id = db()->lastInsertId();
            db()->prepare("INSERT INTO ticket_messages (ticket_id, sender_type, sender_id, message) VALUES (?, 'user', ?, ?)")->execute([$ticket_id, $uid, $message]);
            $name = htmlspecialchars($user['full_name'] ?? $user['username'] ?? $user['phone'] ?? 'User');
            $phone = htmlspecialchars($user['phone'] ?? 'N/A');
            $truncated = strlen($message) > 300 ? substr($message, 0, 300) . '...' : $message;
            $msg = "🎫 *New Support Ticket!*\nUser: {$name}\nPhone: {$phone}\nSubject: " . htmlspecialchars($subject) . "\nPriority: {$priority}\nMessage: " . htmlspecialchars($truncated) . "\nTime: " . date('d M Y, H:i');
            @send_whatsapp_alert($msg);
            echo json_encode(['status' => 'success', 'success' => true, 'message' => 'Ticket created', 'data' => ['ticket_id' => $ticket_id]]);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to create ticket']);
            exit;
        }
    }

    if ($action === 'reply') {
        $ticket_id = (int)($input['ticket_id'] ?? 0);
        $message = trim($input['message'] ?? '');

        if (!$ticket_id || !$message) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Ticket ID and message are required']);
            exit;
        }

        $ticket = db()->prepare("SELECT * FROM tickets WHERE id=? AND user_id=?");
        $ticket->execute([$ticket_id, $uid]);
        if (!$ticket->fetch()) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Ticket not found']);
            exit;
        }

        try {
            db()->prepare("INSERT INTO ticket_messages (ticket_id, sender_type, sender_id, message) VALUES (?, 'user', ?, ?)")->execute([$ticket_id, $uid, $message]);
            db()->prepare("UPDATE tickets SET updated_at=NOW() WHERE id=?")->execute([$ticket_id]);
            echo json_encode(['status' => 'success', 'success' => true, 'message' => 'Reply sent']);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to send reply']);
            exit;
        }
    }

    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    exit;
}

http_response_code(405);
echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
