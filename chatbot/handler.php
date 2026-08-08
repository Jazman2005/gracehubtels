<?php
declare(strict_types=1);
require_once __DIR__ . '/../user/core/db.php';

if (!function_exists('_log')) {
function _log(string $msg): void {
    file_put_contents(__DIR__ . '/bot-error.log', date('Y-m-d H:i:s') . " {$msg}\n", FILE_APPEND);
}
}

// ── cURL helper for Whapi.Cloud API ────────────────────────
if (!function_exists('whapi_curl')) {
function whapi_curl(string $endpoint, array $payload): ?int {
    try {
        $token = setting('whapi_token', '');
        if (!$token) { _log("whapi_curl: missing token"); return null; }
        $url = "https://gate.whapi.cloud{$endpoint}";
        $ch = curl_init($url);
        if (!$ch) { _log("whapi_curl: curl_init failed"); return null; }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
        ]);
        $res = @curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false) { _log("whapi_curl: curl error={$err}"); return null; }
        if ($http !== 200 && $http !== 201) {
            _log("whapi_curl: HTTP {$http} on {$endpoint}: " . substr($res, 0, 500));
        }
        return $http;
    } catch (Throwable $e) {
        _log("whapi_curl exception: " . $e->getMessage());
        return null;
    }
}
}

// ── Send plain text message ────────────────────────────────
if (!function_exists('bot_send')) {
function bot_send(string $chat_id, string $text): void {
    $http = whapi_curl('/messages/text', [
        'to' => $chat_id,
        'body' => $text,
        'typing_time' => 0,
        'no_link_preview' => true,
    ]);
    if ($http === null) _log("bot_send: failed");
    elseif ($http !== 200 && $http !== 201) _log("bot_send: http={$http}");
}
}

// ── Fallback: render buttons as text with IDs ──────────────
if (!function_exists('bot_send_buttons_text')) {
function bot_send_buttons_text(string $chat_id, string $text, array $buttons): void {
    $lines = [$text, ''];
    foreach ($buttons as $b) {
        $id = $b['id'] ?? '';
        $label = $b['text'] ?? $b;
        $lines[] = "{$id}. {$label}";
    }
    $lines[] = '';
    $lines[] = "Type the option or type *menu*.";
    bot_send($chat_id, implode("\n", $lines));
}
}

// ── Fallback: render list as text with IDs ────────────────
if (!function_exists('bot_send_list_text')) {
function bot_send_list_text(string $chat_id, string $text, array $sections): void {
    $lines = [$text, ''];
    foreach ($sections as $section) {
        if (count($sections) > 1) {
            $lines[] = "▫️ {$section['title']}";
        }
        foreach ($section['rows'] as $row) {
            $id = $row['id'] ?? '';
            $label = $row['title'] ?? $row;
            $desc = !empty($row['description']) ? " — {$row['description']}" : '';
            $lines[] = "{$id}. {$label}{$desc}";
        }
    }
    $lines[] = '';
    $lines[] = "Type the option or type *menu*.";
    bot_send($chat_id, implode("\n", $lines));
}
}

// ── Send interactive buttons (max 3) ───────────────────────
if (!function_exists('bot_send_buttons')) {
function bot_send_buttons(string $chat_id, string $text, array $buttons): void {
    if (setting('bot_text_only', '0') === '1') {
        bot_send_buttons_text($chat_id, $text, $buttons);
        return;
    }
    $whapi_buttons = [];
    foreach (array_slice($buttons, 0, 3) as $b) {
        $whapi_buttons[] = [
            'type' => 'quick_reply',
            'title' => $b['text'] ?? $b,
            'id' => $b['id'] ?? '0',
        ];
    }
    $http = whapi_curl('/messages/interactive', [
        'to' => $chat_id,
        'type' => 'button',
        'body' => ['text' => $text],
        'action' => ['buttons' => $whapi_buttons],
    ]);
    if ($http === null || ($http !== 200 && $http !== 201)) {
        _log("bot_send_buttons: http=" . ($http ?? 'null') . ", falling back to text");
        bot_send_buttons_text($chat_id, $text, $buttons);
    }
}
}

// ── Send interactive list message ──────────────────────────
if (!function_exists('bot_send_list')) {
function bot_send_list(string $chat_id, string $text, string $button_text, array $sections, string $title = ''): void {
    if (setting('bot_text_only', '0') === '1') {
        bot_send_list_text($chat_id, $text, $sections);
        return;
    }
    $whapi_sections = [];
    foreach ($sections as $s) {
        $section = ['title' => $s['title'] ?? 'Options', 'rows' => []];
        foreach ($s['rows'] as $r) {
            $row = ['id' => $r['id'] ?? '0', 'title' => $r['title'] ?? $r];
            if (!empty($r['description'])) $row['description'] = $r['description'];
            $section['rows'][] = $row;
        }
        $whapi_sections[] = $section;
    }
    $http = whapi_curl('/messages/interactive', [
        'to' => $chat_id,
        'type' => 'list',
        'body' => ['text' => $text],
        'action' => [
            'button' => $button_text,
            'sections' => $whapi_sections,
        ],
    ]);
    if ($http === null || ($http !== 200 && $http !== 201)) {
        _log("bot_send_list: http=" . ($http ?? 'null') . ", falling back to text");
        bot_send_list_text($chat_id, $text, $sections);
    }
}
}

// ── Session helpers ────────────────────────────────────────
if (!function_exists('session_get')) {
function session_get(string $phone): array {
    $s = db()->prepare("SELECT * FROM chatbot_sessions WHERE phone = ? LIMIT 1");
    $s->execute([$phone]); $row = $s->fetch();
    if ($row) return $row;
    db()->prepare("INSERT INTO chatbot_sessions (phone, step, temp_data) VALUES (?, 'menu', '{}')")->execute([$phone]);
    return ['phone' => $phone, 'user_id' => null, 'step' => 'menu', 'temp_data' => '{}'];
}
}

if (!function_exists('session_set')) {
function session_set(string $phone, string $step, array $temp = []): void {
    db()->prepare("UPDATE chatbot_sessions SET step = ?, temp_data = ?, updated_at = NOW() WHERE phone = ?")
        ->execute([$step, json_encode($temp), $phone]);
}
}

if (!function_exists('session_set_user')) {
function session_set_user(string $phone, int $uid): void {
    db()->prepare("UPDATE chatbot_sessions SET user_id = ?, updated_at = NOW() WHERE phone = ?")->execute([$uid, $phone]);
}
}

// ── Show main menu ─────────────────────────────────────────
if (!function_exists('show_menu')) {
function show_menu(string $chat_id): void {
    // Check if user is logged in
    $phone = $chat_id;
    $uid = 0;
    if (preg_match('/^[0-9]+$/', $phone)) {
        $s = db()->prepare("SELECT user_id, temp_data FROM chatbot_sessions WHERE phone = ? LIMIT 1");
        $s->execute([$phone]);
        $row = $s->fetch();
        $uid = (int)($row['user_id'] ?? 0);
        $tmp = json_decode($row['temp_data'] ?? '{}', true) ?: [];
    } else {
        $tmp = [];
    }

    // Only send welcome text once per session
    $welcome_sent = !empty($tmp['welcome_sent']);
    if (!$welcome_sent) {
        $tmp['welcome_sent'] = true;
        // Persist the flag
        if ($phone) {
            @db()->prepare("UPDATE chatbot_sessions SET temp_data = ? WHERE phone = ?")
                ->execute([json_encode($tmp), $phone]);
        }
    }

    if ($uid) {
        // ── Authenticated: services menu ──────────────────
        $user = get_user($uid);
        $name = $user['full_name'] ?? 'User';
        $bal = (float)($user['wallet_balance'] ?? 0);
        if (!$welcome_sent) {
            bot_send($chat_id, "🤖 *Gracehubtels Bot*\n\nWelcome back, {$name}!\n💰 Balance: ₦" . number_format($bal, 2));
            usleep(300000);
        }
        bot_send_buttons($chat_id, "Choose a service:", [
            ['id' => 'data', 'text' => '📱 Buy Data'],
            ['id' => 'airtime', 'text' => '📞 Airtime'],
            ['id' => 'cable', 'text' => '📺 Cable TV'],
        ]);
        usleep(300000);
        bot_send_buttons($chat_id, "More:", [
            ['id' => 'electricity', 'text' => '⚡ Electricity'],
            ['id' => 'a2c', 'text' => '💱 A2C'],
            ['id' => 'balance', 'text' => '💰 Balance'],
        ]);
        usleep(300000);
        bot_send_buttons($chat_id, "Account:", [
            ['id' => 'fund_wallet', 'text' => '💳 Fund Wallet'],
            ['id' => 'logout', 'text' => '🚪 Logout'],
        ]);
    } else {
        // ── Unauthenticated: login / register ─────────────
        if (!$welcome_sent) {
            bot_send($chat_id, "🤖 *Gracehubtels Bot*\n\nWelcome! Please login or create an account:");
            usleep(300000);
        }
        bot_send_buttons($chat_id, "Get Started:", [
            ['id' => 'login', 'text' => '🔑 Login'],
            ['id' => 'register', 'text' => '📝 Register'],
        ]);
    }
}
}

// ── Network list (shared across services) ──────────────────
if (!function_exists('network_list')) {
function network_list(string $chat_id, string $step_name, string $title): void {
    bot_send($chat_id, "{$title}\n\nSelect network:");
    usleep(300000);
    bot_send_buttons($chat_id, "Choose network:", [
        ['id' => 'MTN', 'text' => 'MTN'],
        ['id' => 'GLO', 'text' => 'GLO'],
        ['id' => 'AIRTEL', 'text' => 'Airtel'],
    ]);
    usleep(300000);
    bot_send_buttons($chat_id, "Or pick:", [
        ['id' => '9MOBILE', 'text' => '9mobile'],
        ['id' => 'back', 'text' => '🔙 Back'],
    ]);
}
}

// ── Main message handler ───────────────────────────────────
if (!function_exists('handle_message')) {
function handle_message(string $phone, string $chat_id, string $msg): void {
    $s = session_get($phone);
    $step = $s['step'];
    $tmp = json_decode($s['temp_data'] ?? '{}', true) ?: [];
    $uid = (int)($s['user_id'] ?? 0);
    $user = $uid ? get_user($uid) : null;

    // Normalize button click texts to their IDs
    // Green API sends button TEXT on click, not the ID
    $button_map = [
        '✅ confirm' => 'yes', '✅ yes' => 'yes',
        '❌ cancel' => 'no', '❌ no' => 'no',
        '📡 dstv' => 'DSTV', '📡 gotv' => 'GOTV', '📡 startimes' => 'STARTIMES',
        '⚡ prepaid' => 'prepaid', '⚡ postpaid' => 'postpaid',
    ];
    $norm = strtolower(trim($msg));
    if (isset($button_map[$norm])) {
        $msg = $button_map[$norm];
    }

    // Global commands: menu, back, cancel
    $low = strtolower(trim($msg));
    if ($low === 'menu' || $low === '0' || $low === 'back' || $low === 'cancel') {
        session_set($phone, 'menu', []);
        show_menu($chat_id);
        return;
    }

    // ── LOGIN ───────────────────────────────────────────
    if ($step === 'menu' && $msg === 'login') {
        session_set($phone, 'login_email', []);
        bot_send($chat_id, "📧 *Login*\n\nEnter your registered email address:");
        return;
    }
    if ($step === 'login_email') {
        $email = trim($msg);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            bot_send($chat_id, "❌ Invalid email. Try again or type *menu* to go back.");
            return;
        }
        // Keyed by $phone (not IP, which is always the messaging provider's
        // webhook IP for every user) — without this, an attacker could script
        // conversation turns to mass-enumerate registered emails via the
        // "No account found" / found-account branching below with no limit.
        if (!rate_limit('chat_login_email_' . $phone, 8, 900)) {
            bot_send($chat_id, "❌ Too many attempts. Please try again later.");
            session_set($phone, 'menu', []);
            return;
        }
        $s2 = db()->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $s2->execute([$email]);
        $u = $s2->fetch();
        if (!$u) {
            bot_send($chat_id, "❌ No account found with that email. Type *menu* for options.");
            session_set($phone, 'menu', []);
            return;
        }
        session_set($phone, 'login_pass', ['email' => $email]);
        bot_send($chat_id, "🔑 Enter your password:");
        return;
    }
    if ($step === 'login_pass') {
        // Shares the same login_attempts/login_locked_until lockout the website
        // login already enforces on this account (user/core/auth.php login_user())
        // — a raw password_verify() here with no throttling would let an attacker
        // brute-force a known email's password over WhatsApp with zero rate limit.
        $email = $tmp['email'] ?? '';
        $s2 = db()->prepare("SELECT id, password, login_attempts, login_locked_until FROM users WHERE email = ? LIMIT 1");
        $s2->execute([$email]);
        $u = $s2->fetch();

        if ($u && !empty($u['login_locked_until']) && strtotime($u['login_locked_until']) > time()) {
            bot_send($chat_id, "🔒 This account is temporarily locked due to too many failed attempts. Try again later, or reset your password on the website.");
            session_set($phone, 'menu', []);
            return;
        }

        if ($u && password_verify($msg, $u['password'])) {
            db()->prepare("UPDATE users SET login_attempts=0, login_locked_until=NULL WHERE id=?")->execute([$u['id']]);
            session_set_user($phone, (int)$u['id']);
            session_set($phone, 'menu', []);
            bot_send($chat_id, "✅ *Login successful!*\n\nWelcome back! Tap *Menu* below to continue.");
            show_menu($chat_id);
        } else {
            if ($u) {
                $att = (int)$u['login_attempts'] + 1;
                if ($att >= 3) {
                    $lock = date('Y-m-d H:i:s', time() + 600);
                    db()->prepare("UPDATE users SET login_attempts=?, login_locked_until=? WHERE id=?")->execute([$att, $lock, $u['id']]);
                    bot_send($chat_id, "🔒 Too many failed attempts. This account is locked for 10 minutes.");
                    session_set($phone, 'menu', []);
                    return;
                }
                db()->prepare("UPDATE users SET login_attempts=? WHERE id=?")->execute([$att, $u['id']]);
            }
            bot_send($chat_id, "❌ Wrong password. Try again or type *menu* to go back.");
        }
        return;
    }

    // ── REGISTER ────────────────────────────────────────
    if ($step === 'menu' && $msg === 'register') {
        session_set($phone, 'reg_name', []);
        bot_send($chat_id, "📝 *Register*\n\nEnter your full name:");
        return;
    }
    if ($step === 'reg_name') {
        if (strlen(trim($msg)) < 2) { bot_send($chat_id, "❌ Name too short. Try again or type *menu*."); return; }
        session_set($phone, 'reg_email', ['name' => trim($msg)]);
        bot_send($chat_id, "📧 Enter your email address:");
        return;
    }
    if ($step === 'reg_email') {
        if (!filter_var(trim($msg), FILTER_VALIDATE_EMAIL)) { bot_send($chat_id, "❌ Invalid email. Try again or type *menu*."); return; }
        // Same enumeration-throttling rationale as login_email above.
        if (!rate_limit('chat_reg_email_' . $phone, 8, 900)) { bot_send($chat_id, "❌ Too many attempts. Please try again later."); session_set($phone, 'menu', []); return; }
        $s2 = db()->prepare("SELECT id FROM users WHERE email = ? LIMIT 1"); $s2->execute([trim($msg)]);
        if ($s2->fetch()) { bot_send($chat_id, "❌ Email already registered. Tap *Login* below or type *menu*."); show_menu($chat_id); return; }
        session_set($phone, 'reg_pass', ['name' => $tmp['name'], 'email' => trim($msg)]);
        bot_send($chat_id, "🔑 Create a password (min 6 characters):");
        return;
    }
    if ($step === 'reg_pass') {
        if (strlen(trim($msg)) < 6) { bot_send($chat_id, "❌ Password must be at least 6 characters. Try again or type *menu*."); return; }
        $name = $tmp['name']; $email = $tmp['email'];
        $hash = password_hash(trim($msg), PASSWORD_DEFAULT);
        $phone_clean = preg_replace('/[^0-9]/', '', $phone);
        $local_phone = '0' . substr($phone_clean, -10);
        try {
            $ref_code = $local_phone;
            db()->prepare("INSERT INTO users (full_name, email, phone, password, referral_code, is_active) VALUES (?, ?, ?, ?, ?, 1)")
                ->execute([$name, $email, $local_phone, $hash, $ref_code]);
            $uid = (int)db()->lastInsertId();
            session_set_user($phone, $uid);
            session_set($phone, 'menu', []);
            bot_send($chat_id, "✅ *Registration successful!*\n\nWelcome {$name}! You're now logged in.\nYour referral code: {$ref_code}");
            show_menu($chat_id);
        } catch (Exception $e) {
            bot_send($chat_id, "❌ Registration failed. Try again later or type *menu*.");
        }
        return;
    }

    // ── LOGOUT ──────────────────────────────────────────
    if ($step === 'menu' && $msg === 'logout') {
        if (!$uid) { bot_send($chat_id, "You're not logged in."); show_menu($chat_id); return; }
        session_set_user($phone, 0);
        bot_send($chat_id, "👋 Logged out successfully.");
        show_menu($chat_id);
        return;
    }

    // ── CHECK BALANCE ───────────────────────────────────
    if ($step === 'menu' && $msg === 'balance') {
        if (!$uid) { bot_send($chat_id, "❌ You need to login first. Tap *Login* below."); show_menu($chat_id); return; }
        $b = (float)($user['wallet_balance'] ?? 0);
        $cb = (float)($user['cashback_balance'] ?? 0);
        bot_send($chat_id, "💰 *Balance*\n\nWallet: ₦" . number_format($b, 2) . "\nCashback: ₦" . number_format($cb, 2));
        show_menu($chat_id);
        return;
    }

    // ── BUY DATA ────────────────────────────────────────
    if ($step === 'menu' && $msg === 'data') {
        if (!$uid) { bot_send($chat_id, "❌ Login first. Tap *Login* below."); show_menu($chat_id); return; }
        session_set($phone, 'data_network', []);
        network_list($chat_id, 'data_network', "📶 *Buy Data*");
        return;
    }
    if ($step === 'data_network') {
        $net = strtoupper(trim($msg));
        $valid = ['MTN', 'GLO', 'AIRTEL', '9MOBILE'];
        if (!in_array($net, $valid)) { bot_send($chat_id, "❌ Invalid network. Please tap a network from the list."); network_list($chat_id, 'data_network', "📶 *Buy Data*"); return; }
        $plans = db()->prepare("SELECT id, data_size as `size`, selling_price as amount, data_type FROM data_plans WHERE network = ? AND is_active = 1 ORDER BY data_type, display_order, selling_price");
        $plans->execute([$net]); $rows = $plans->fetchAll();
        if (!$rows) { bot_send($chat_id, "❌ No plans available for {$net}."); show_menu($chat_id); return; }
        $by_type = [];
        foreach ($rows as $p) { $by_type[$p['data_type']][] = $p; }
        $plan_sections = []; $plan_map = []; $i = 1;
        foreach ($by_type as $type => $plist) {
            $section = ['title' => $type, 'rows' => []];
            foreach ($plist as $p) {
                $pid = (string)$i;
                $section['rows'][] = ['id' => $pid, 'title' => $p['size'], 'description' => '₦' . number_format((float)$p['amount'], 0)];
                $plan_map[$pid] = $p; $i++;
            }
            $plan_sections[] = $section;
        }
        session_set($phone, 'data_phone', ['network' => $net, 'plans' => $plan_map]);
        bot_send_list($chat_id, "📶 *{$net} Data Plans*\n\nSelect a plan:", "Select Plan", $plan_sections, 'Data Plans');
        return;
    }
    if ($step === 'data_phone') {
        $idx = $msg; $plans = $tmp['plans'] ?? [];
        if (!isset($plans[$idx])) { bot_send($chat_id, "❌ Invalid plan. Please tap a plan from the list."); return; }
        session_set($phone, 'data_phone_num', ['network' => $tmp['network'], 'plan' => $plans[$idx]]);
        bot_send($chat_id, "📱 Enter the phone number to receive data:");
        return;
    }
    if ($step === 'data_phone_num') {
        $phone_in = preg_replace('/[^0-9]/', '', $msg);
        if (strlen($phone_in) < 10) { bot_send($chat_id, "❌ Invalid phone number. Try again or type *menu*."); return; }
        session_set($phone, 'data_pin', ['network' => $tmp['network'], 'plan' => $tmp['plan'], 'phone' => $phone_in]);
        bot_send($chat_id, "🔑 Enter your transaction PIN:");
        return;
    }
    if ($step === 'data_pin') {
        $pin = trim($msg);
        if (!preg_match('/^[0-9]{4,}$/', $pin)) { bot_send($chat_id, "❌ Invalid PIN. Enter your 4-digit transaction PIN or type *menu*."); return; }
        $p = $tmp['plan'];
        $txt = "⚡ *Confirm Purchase*\n\n"
             . "Network: {$tmp['network']}\n"
             . "Plan: {$p['size']} ({$p['data_type']})\n"
             . "Amount: ₦" . number_format((float)$p['amount'], 0) . "\n"
             . "Phone: {$tmp['phone']}\n"
             . "PIN: {$pin}";
        session_set($phone, 'data_confirm', ['network' => $tmp['network'], 'plan' => $p, 'phone' => $tmp['phone'], 'pin' => $pin]);
        bot_send_buttons($chat_id, $txt, [
            ['id' => 'yes', 'text' => '✅ Confirm'],
            ['id' => 'no', 'text' => '❌ Cancel'],
        ]);
        return;
    }
    if ($step === 'data_confirm') {
        if ($msg !== 'yes') {
            session_set($phone, 'menu', []); bot_send($chat_id, "❌ Cancelled."); show_menu($chat_id); return;
        }
        $p = $tmp['plan']; $phone_in = $tmp['phone']; $pin = $tmp['pin'] ?? '0000';
        $_POST['promo_code'] = '';
        require_once __DIR__ . '/../user/core/data.php';
        $res = process_data($uid, ['phone' => $phone_in, 'plan_id' => (int)$p['id'], 'pin' => $pin]);
        session_set($phone, 'menu', []);
        if (($res['success'] ?? false)) {
            bot_send($chat_id, "✅ *Data Purchase Successful!*\n\n₦" . number_format((float)$p['amount'], 0) . " {$tmp['network']} {$p['size']}\nSent to: {$phone_in}\nRef: " . ($res['ref'] ?? 'N/A'));
        } else {
            bot_send($chat_id, "❌ *Purchase Failed*\n\n" . ($res['message'] ?? 'Please try again.'));
        }
        show_menu($chat_id);
        return;
    }

    // ── BUY AIRTIME ─────────────────────────────────────
    if ($step === 'menu' && $msg === 'airtime') {
        if (!$uid) { bot_send($chat_id, "❌ Login first. Tap *Login* below."); show_menu($chat_id); return; }
        session_set($phone, 'airtime_network', []);
        network_list($chat_id, 'airtime_network', "📱 *Buy Airtime*");
        return;
    }
    if ($step === 'airtime_network') {
        $net = strtoupper(trim($msg));
        $valid = ['MTN', 'GLO', 'AIRTEL', '9MOBILE'];
        if (!in_array($net, $valid)) { bot_send($chat_id, "❌ Invalid network."); network_list($chat_id, 'airtime_network', "📱 *Buy Airtime*"); return; }
        session_set($phone, 'airtime_amount', ['network' => $net]);
        bot_send($chat_id, "💰 Enter amount (₦):");
        return;
    }
    if ($step === 'airtime_amount') {
        $amt = (float)$msg;
        if ($amt < 50 || $amt > 50000) { bot_send($chat_id, "❌ Amount must be between ₦50 and ₦50,000. Try again or type *menu*."); return; }
        session_set($phone, 'airtime_phone', ['network' => $tmp['network'], 'amount' => $amt]);
        bot_send($chat_id, "📱 Enter phone number to receive airtime:");
        return;
    }
    if ($step === 'airtime_phone') {
        $phone_in = preg_replace('/[^0-9]/', '', $msg);
        if (strlen($phone_in) < 10) { bot_send($chat_id, "❌ Invalid phone number. Try again or type *menu*."); return; }
        session_set($phone, 'airtime_pin', ['network' => $tmp['network'], 'amount' => $tmp['amount'], 'phone' => $phone_in]);
        bot_send($chat_id, "🔑 Enter your transaction PIN to proceed:");
        return;
    }
    if ($step === 'airtime_pin') {
        $pin = trim($msg);
        if (!preg_match('/^[0-9]{4,}$/', $pin)) { bot_send($chat_id, "❌ Invalid PIN. Enter your 4-digit transaction PIN or type *menu*."); return; }
        $txt = "⚡ *Confirm Purchase*\n\n"
             . "Network: {$tmp['network']}\n"
             . "Amount: ₦" . number_format($tmp['amount'], 0) . "\n"
             . "Phone: {$tmp['phone']}\n"
             . "PIN: {$pin}";
        session_set($phone, 'airtime_confirm', ['network' => $tmp['network'], 'amount' => $tmp['amount'], 'phone' => $tmp['phone'], 'pin' => $pin]);
        bot_send_buttons($chat_id, $txt, [
            ['id' => 'yes', 'text' => '✅ Confirm'],
            ['id' => 'no', 'text' => '❌ Cancel'],
        ]);
        return;
    }
    if ($step === 'airtime_confirm') {
        if ($msg !== 'yes') {
            session_set($phone, 'menu', []); bot_send($chat_id, "❌ Cancelled."); show_menu($chat_id); return;
        }
        require_once __DIR__ . '/../user/core/airtime.php';
        $net = $tmp['network']; $amt = $tmp['amount']; $phone_in = $tmp['phone']; $pin = $tmp['pin'] ?? '0000';
        $res = process_airtime($uid, ['network' => $net, 'amount' => $amt, 'phone' => $phone_in, 'pin' => $pin]);
        session_set($phone, 'menu', []);
        if (($res['success'] ?? false)) {
            bot_send($chat_id, "✅ *Airtime Purchase Successful!*\n\n₦" . number_format($amt, 0) . " {$net}\nSent to: {$phone_in}\nRef: " . ($res['ref'] ?? 'N/A'));
        } else {
            bot_send($chat_id, "❌ *Purchase Failed*\n\n" . ($res['message'] ?? 'Please try again.'));
        }
        show_menu($chat_id);
        return;
    }

    // ── A2C ─────────────────────────────────────────────
    if ($step === 'menu' && $msg === 'a2c') {
        if (!$uid) { bot_send($chat_id, "❌ Login first. Tap *Login* below."); show_menu($chat_id); return; }
        session_set($phone, 'a2c_network', []);
        network_list($chat_id, 'a2c_network', "💸 *Airtime to Cash*");
        return;
    }
    if ($step === 'a2c_network') {
        $net = strtoupper(trim($msg));
        $valid = ['MTN', 'GLO', 'AIRTEL', '9MOBILE'];
        if (!in_array($net, $valid)) { bot_send($chat_id, "❌ Invalid network."); network_list($chat_id, 'a2c_network', "💸 *Airtime to Cash*"); return; }
        session_set($phone, 'a2c_amount', ['network' => $net]);
        bot_send($chat_id, "💰 Enter the airtime amount to convert (₦):");
        return;
    }
    if ($step === 'a2c_amount') {
        $amt = (float)$msg;
        $min = (float)setting('min_a2c_amount', '500');
        $max = (float)setting('max_a2c_amount', '50000');
        if ($amt < $min || $amt > $max) { bot_send($chat_id, "❌ Amount must be between ₦" . number_format($min, 0) . " and ₦" . number_format($max, 0) . ". Try again or type *menu*."); return; }
        session_set($phone, 'a2c_phone', ['network' => $tmp['network'], 'amount' => $amt]);
        bot_send($chat_id, "📱 Enter the sender phone number (the number that will send the airtime):");
        return;
    }
    if ($step === 'a2c_phone') {
        $phone_in = preg_replace('/[^0-9]/', '', $msg);
        if (strlen($phone_in) < 10) { bot_send($chat_id, "❌ Invalid phone number. Try again or type *menu*."); return; }
        session_set($phone, 'a2c_pin', ['network' => $tmp['network'], 'amount' => $tmp['amount'], 'phone' => $phone_in]);
        bot_send($chat_id, "🔑 Enter your transaction PIN:");
        return;
    }
    if ($step === 'a2c_pin') {
        $pin = trim($msg);
        if (!preg_match('/^[0-9]{4,}$/', $pin)) { bot_send($chat_id, "❌ Invalid PIN. Enter your 4-digit transaction PIN or type *menu*."); return; }
        $txt = "⚡ *Confirm A2C*\n\n"
             . "Network: {$tmp['network']}\n"
             . "Airtime: ₦" . number_format($tmp['amount'], 0) . "\n"
             . "Sender: {$tmp['phone']}\n"
             . "PIN: {$pin}";
        session_set($phone, 'a2c_confirm', ['network' => $tmp['network'], 'amount' => $tmp['amount'], 'phone' => $tmp['phone']]);
        bot_send_buttons($chat_id, $txt, [
            ['id' => 'yes', 'text' => '✅ Confirm'],
            ['id' => 'no', 'text' => '❌ Cancel'],
        ]);
        return;
    }
    if ($step === 'a2c_confirm') {
        if ($msg !== 'yes') {
            session_set($phone, 'menu', []); bot_send($chat_id, "❌ Cancelled."); show_menu($chat_id); return;
        }
        require_once __DIR__ . '/../user/core/airtime_to_cash.php';
        $res = submit_airtime_to_cash($uid, ['network' => $tmp['network'], 'sender_phone' => $tmp['phone'], 'airtime_amount' => $tmp['amount']]);
        session_set($phone, 'menu', []);
        if (($res['success'] ?? false)) {
            bot_send($chat_id, "✅ *A2C Request Submitted!*\n\n{$tmp['network']} ₦" . number_format($tmp['amount'], 0) . "\nCash Due: ₦" . number_format((float)($res['cash_amount'] ?? 0), 2) . "\n" . (($res['our_number'] ?? '') ? "Send airtime to: {$res['our_number']}" : "Wait for confirmation.") . "\nRef: " . ($res['ref'] ?? 'N/A'));
        } else {
            bot_send($chat_id, "❌ *A2C Failed*\n\n" . ($res['message'] ?? 'Try again.'));
        }
        show_menu($chat_id);
        return;
    }

    // ── CABLE TV ────────────────────────────────────────
    if ($step === 'menu' && $msg === 'cable') {
        if (!$uid) { bot_send($chat_id, "❌ Login first. Tap *Login* below."); show_menu($chat_id); return; }
        session_set($phone, 'cable_provider', []);
        bot_send_buttons($chat_id, "📺 *Cable TV*\n\nSelect provider:", [
            ['id' => 'DSTV', 'text' => '📡 DSTV'],
            ['id' => 'GOTV', 'text' => '📡 GOtv'],
            ['id' => 'STARTIMES', 'text' => '📡 StarTimes'],
        ]);
        return;
    }
    if ($step === 'cable_provider') {
        $prov = strtoupper(trim($msg));
        $valid = ['DSTV', 'GOTV', 'STARTIMES'];
        if (!in_array($prov, $valid)) { bot_send($chat_id, "❌ Invalid provider. Tap a provider button."); return; }
        $plans = db()->prepare("SELECT id, plan_name, cable_id, selling_price as amount FROM cable_plans WHERE provider = ? AND is_active = 1 ORDER BY selling_price");
        $plans->execute([$prov]); $rows = $plans->fetchAll();
        if (!$rows) { bot_send($chat_id, "❌ No plans available for {$prov}."); show_menu($chat_id); return; }
        $plan_sections = [['title' => 'Plans', 'rows' => []]];
        $plan_map = [];
        foreach ($rows as $p) {
            $pid = (string)$p['id'];
            $plan_sections[0]['rows'][] = ['id' => $pid, 'title' => $p['plan_name'], 'description' => '₦' . number_format((float)$p['amount'], 0)];
            $plan_map[$pid] = $p;
        }
        session_set($phone, 'cable_iuc', ['provider' => $prov, 'plans' => $plan_map]);
        bot_send_list($chat_id, "📺 *{$prov} Plans*\n\nSelect a plan:", "Select Plan", $plan_sections, 'Cable Plans');
        return;
    }
    if ($step === 'cable_iuc') {
        $idx = $msg; $plans = $tmp['plans'] ?? [];
        if (!isset($plans[$idx])) { bot_send($chat_id, "❌ Invalid plan. Tap a plan from the list."); return; }
        session_set($phone, 'cable_iuc_confirm', ['provider' => $tmp['provider'], 'plan' => $plans[$idx]]);
        bot_send($chat_id, "🔢 Enter your IUC / smart card number:");
        return;
    }
    if ($step === 'cable_iuc_confirm') {
        $iuc = trim($msg);
        if (strlen($iuc) < 5) { bot_send($chat_id, "❌ Invalid IUC number. Try again or type *menu*."); return; }
        session_set($phone, 'cable_pin', ['provider' => $tmp['provider'], 'plan' => $tmp['plan'], 'iuc' => $iuc]);
        bot_send($chat_id, "🔑 Enter your transaction PIN:");
        return;
    }
    if ($step === 'cable_pin') {
        $pin = trim($msg);
        if (!preg_match('/^[0-9]{4,}$/', $pin)) { bot_send($chat_id, "❌ Invalid PIN. Enter your 4-digit transaction PIN or type *menu*."); return; }
        $p = $tmp['plan'];
        $txt = "⚡ *Confirm*\n\n"
             . "Provider: {$tmp['provider']}\n"
             . "Plan: {$p['plan_name']}\n"
             . "IUC: {$tmp['iuc']}\n"
             . "Amount: ₦" . number_format((float)$p['amount'], 0) . "\n"
             . "PIN: {$pin}";
        session_set($phone, 'cable_confirm', ['provider' => $tmp['provider'], 'plan' => $p, 'iuc' => $tmp['iuc'], 'pin' => $pin]);
        bot_send_buttons($chat_id, $txt, [
            ['id' => 'yes', 'text' => '✅ Confirm'],
            ['id' => 'no', 'text' => '❌ Cancel'],
        ]);
        return;
    }
    if ($step === 'cable_confirm') {
        if ($msg !== 'yes') {
            session_set($phone, 'menu', []); bot_send($chat_id, "❌ Cancelled."); show_menu($chat_id); return;
        }
        $pin = $tmp['pin'] ?? '0000';
        require_once __DIR__ . '/../user/core/cabletv.php';
        $res = process_cabletv($uid, [
            'iuc_number' => $tmp['iuc'],
            'plan_id' => (int)$tmp['plan']['id'],
            'amount' => (float)$tmp['plan']['amount'],
            'pin' => $pin,
        ]);
        session_set($phone, 'menu', []);
        if (($res['success'] ?? false)) {
            bot_send($chat_id, "✅ *Cable TV Purchase Successful!*\n\n{$tmp['provider']} - {$tmp['plan']['plan_name']}\nIUC: {$tmp['iuc']}\nRef: " . ($res['ref'] ?? 'N/A'));
        } else {
            bot_send($chat_id, "❌ *Purchase Failed*\n\n" . ($res['message'] ?? 'Try again.'));
        }
        show_menu($chat_id);
        return;
    }

    // ── ELECTRICITY ─────────────────────────────────────
    if ($step === 'menu' && $msg === 'electricity') {
        if (!$uid) { bot_send($chat_id, "❌ Login first. Tap *Login* below."); show_menu($chat_id); return; }
        $discos = db()->prepare("SELECT disco_id, disco_name FROM electricity_discos WHERE is_active = 1 ORDER BY disco_name");
        $discos->execute(); $rows = $discos->fetchAll();
        if (!$rows) { bot_send($chat_id, "❌ No electricity providers configured."); show_menu($chat_id); return; }
        $disco_sections = [['title' => 'Electricity Providers', 'rows' => []]];
        $disco_map = [];
        foreach ($rows as $d) {
            $did = (string)$d['disco_id'];
            $disco_sections[0]['rows'][] = ['id' => $did, 'title' => $d['disco_name']];
            $disco_map[$did] = $d;
        }
        session_set($phone, 'electric_meter', ['discos' => $disco_map]);
        bot_send_list($chat_id, "💡 *Electricity*\n\nSelect provider:", "Select Provider", $disco_sections, 'Choose DISCO');
        return;
    }
    if ($step === 'electric_meter') {
        $idx = $msg; $discos = $tmp['discos'] ?? [];
        if (!isset($discos[$idx])) { bot_send($chat_id, "❌ Invalid provider. Tap a provider from the list."); return; }
        session_set($phone, 'electric_type', ['disco' => $discos[$idx]]);
        bot_send($chat_id, "🔢 Enter your meter number:");
        return;
    }
    if ($step === 'electric_type') {
        $meter = trim($msg);
        if (strlen($meter) < 5) { bot_send($chat_id, "❌ Invalid meter number. Try again or type *menu*."); return; }
        session_set($phone, 'electric_meter_type', ['disco' => $tmp['disco'], 'meter' => $meter]);
        bot_send_buttons($chat_id, "🔌 Meter type:", [
            ['id' => 'prepaid', 'text' => '⚡ Prepaid'],
            ['id' => 'postpaid', 'text' => '⚡ Postpaid'],
        ]);
        return;
    }
    if ($step === 'electric_meter_type') {
        $mtypes = ['prepaid', 'postpaid'];
        if (!in_array($msg, $mtypes)) { bot_send($chat_id, "❌ Invalid. Tap a meter type button."); return; }
        session_set($phone, 'electric_amount', ['disco' => $tmp['disco'], 'meter' => $tmp['meter'], 'meter_type' => $msg]);
        bot_send($chat_id, "💰 Enter amount to purchase (₦):");
        return;
    }
    if ($step === 'electric_amount') {
        $amt = (float)$msg;
        if ($amt < 500) { bot_send($chat_id, "❌ Minimum amount is ₦500. Try again or type *menu*."); return; }
        session_set($phone, 'electric_pin', ['disco' => $tmp['disco'], 'meter' => $tmp['meter'], 'meter_type' => $tmp['meter_type'], 'amount' => $amt]);
        bot_send($chat_id, "🔑 Enter your transaction PIN:");
        return;
    }
    if ($step === 'electric_pin') {
        $pin = trim($msg);
        if (!preg_match('/^[0-9]{4,}$/', $pin)) { bot_send($chat_id, "❌ Invalid PIN. Enter your 4-digit transaction PIN or type *menu*."); return; }
        $d = $tmp['disco'];
        $txt = "⚡ *Confirm*\n\n"
             . "Provider: {$d['disco_name']}\n"
             . "Meter: {$tmp['meter']}\n"
             . "Type: {$tmp['meter_type']}\n"
             . "Amount: ₦" . number_format($tmp['amount'], 0) . "\n"
             . "PIN: {$pin}";
        session_set($phone, 'electric_confirm', ['disco' => $d, 'meter' => $tmp['meter'], 'meter_type' => $tmp['meter_type'], 'amount' => $tmp['amount'], 'pin' => $pin]);
        bot_send_buttons($chat_id, $txt, [
            ['id' => 'yes', 'text' => '✅ Confirm'],
            ['id' => 'no', 'text' => '❌ Cancel'],
        ]);
        return;
    }
    if ($step === 'electric_confirm') {
        if ($msg !== 'yes') {
            session_set($phone, 'menu', []); bot_send($chat_id, "❌ Cancelled."); show_menu($chat_id); return;
        }
        $pin = $tmp['pin'] ?? '0000';
        require_once __DIR__ . '/../user/core/electricity.php';
        $res = process_electricity($uid, [
            'disco_id' => (int)$tmp['disco']['disco_id'],
            'meter_number' => $tmp['meter'],
            'meter_type' => $tmp['meter_type'],
            'amount' => $tmp['amount'],
            'pin' => $pin,
        ]);
        session_set($phone, 'menu', []);
        if (($res['success'] ?? false)) {
            bot_send($chat_id, "✅ *Electricity Purchase Successful!*\n\n{$tmp['disco']['disco_name']}\nMeter: {$tmp['meter']}\nAmount: ₦" . number_format($tmp['amount'], 0) . "\nRef: " . ($res['ref'] ?? 'N/A') . "\nToken: " . ($res['token'] ?? 'N/A'));
        } else {
            bot_send($chat_id, "❌ *Purchase Failed*\n\n" . ($res['message'] ?? 'Try again.'));
        }
        show_menu($chat_id);
        return;
    }

    // ── FUND WALLET ────────────────────────────────────
    if ($step === 'menu' && $msg === 'fund_wallet') {
        if (!$uid) { bot_send($chat_id, "❌ Login first."); show_menu($chat_id); return; }
        bot_send($chat_id, "💳 *Fund Wallet*\n\nTo fund your wallet, use the bank transfer option on our website:\n\nhttps://gracehubtels.com/user/fund\n\nOr send to any of our payment account numbers and the wallet will be credited automatically.\n\nType *menu* to go back.");
        show_menu($chat_id);
        return;
    }

    // ── DEFAULT: show menu ──────────────────────────────
    show_menu($chat_id);
}
}
