<?php
require_once __DIR__.'/db.php';
define('SESSION_NAME','msjpay_user'); define('MAX_LOGIN',5); define('LOCKOUT',900);



function start_session():void{if(session_status()===PHP_SESSION_NONE){session_name(SESSION_NAME);session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>isset($_SERVER['HTTPS']),'httponly'=>true,'samesite'=>'Strict']);session_start();}}
function auth_user():void{
    start_session();
    if(empty($_SESSION['user_id'])){
        if(is_ajax())json_response(['success'=>false,'message'=>'Session expired'],401);
        header('Location: /user/login?next='.urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
    
    $s = db()->prepare("SELECT is_active, is_suspended FROM users WHERE id = ? LIMIT 1");
    $s->execute([$_SESSION['user_id']]);
    $u = $s->fetch();
    
    if (!$u || empty($u['is_active']) || !empty($u['is_suspended'])) {
        $_SESSION = [];
        if(ini_get('session.use_cookies')) {
            $p=session_get_cookie_params();
            setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);
        }
        session_destroy();
        if(is_ajax()) json_response(['success'=>false,'message'=>'Account suspended or deleted.'], 401);
        header('Location: /user/login?error=account_inactive');
        exit;
    }
}
// Per-install feature gate for the customer-facing portal (see
// grace-hub-tels/vendor-access.php for how the vendor toggles these). Call
// right after auth_user() — blocks both the page render AND the POST/AJAX
// action dispatch below it, since every user/*.php file handles both in one
// script.
function require_feature_user(string $key): void {
    if (feature_enabled($key)) return;
    if (is_ajax()) {
        json_response(['success' => false, 'message' => "This feature isn't included in your plan.", 'feature_locked' => true], 403);
    }
    $page_title = 'Premium Feature';
    include __DIR__.'/../includes/header.php';
    include __DIR__.'/../includes/sidebar.php';
    echo feature_locked_card_html($key, 'user');
    include __DIR__.'/../includes/footer.php';
    exit;
}
function login_user(string $identifier, string $password): array {
    check_blocked_ip();
    if (!rate_limit('login', MAX_LOGIN, LOCKOUT)) return ['success' => false, 'message' => 'Too many attempts. Try in 15 min.'];
    $identifier = trim($identifier);
    if (empty($identifier) || empty($password)) return ['success' => false, 'message' => 'Enter your username/email and password.'];
    $ip = get_ip();
    $s = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE identifier=? AND ip_address=? AND attempted_at>DATE_SUB(NOW(),INTERVAL " . LOCKOUT . " SECOND)");
    $s->execute([$identifier, $ip]);
    if ((int)$s->fetchColumn() >= MAX_LOGIN) return ['success' => false, 'message' => 'Too many failed attempts. Try in 15 min.'];
    
    // Ensure columns exist
    try{db()->exec("ALTER TABLE users ADD COLUMN login_attempts INT DEFAULT 0");}catch(\Throwable$e){}
    try{db()->exec("ALTER TABLE users ADD COLUMN login_locked_until DATETIME DEFAULT NULL");}catch(\Throwable$e){}
    
    // Detect if identifier is phone, email, or username
    $phone = '';
    $username = '';
    $email = '';
    if (preg_match('/^\d/', $identifier)) {
        $phone = sanitize_phone($identifier);
        // Accept common alternate formats so a number typed with a +234
        // country code, or missing its leading 0, still matches — phone
        // numbers are stored normalized to the 0XXXXXXXXXX form.
        if (strlen($phone) === 13 && str_starts_with($phone, '234')) {
            $phone = '0' . substr($phone, 3);
        } elseif (strlen($phone) === 10 && preg_match('/^[7-9]/', $phone)) {
            $phone = '0' . $phone;
        }
    } elseif (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
        $email = $identifier;
    } else {
        $username = $identifier;
    }

    $s = db()->prepare("SELECT * FROM users WHERE (phone=? OR username=? OR email=?) AND is_active=1 LIMIT 1");
    $s->execute([$phone, $username, $email]);
    $user = $s->fetch();
    
    if ($user && $user['login_locked_until'] && strtotime($user['login_locked_until']) > time()) {
        return ['success' => false, 'message' => 'Account locked. Try again after 10 minutes.'];
    }
    
    // Always run password_verify() even when no account matched — see
    // DUMMY_PASSWORD_HASH in db.php for why this closes a timing side-channel.
    $password_ok = password_verify($password, $user['password'] ?? DUMMY_PASSWORD_HASH);
    if (!$user || !$password_ok) {
        if ($user) {
            $att = (int)$user['login_attempts'] + 1;
            if ($att >= 3) {
                $lock = date('Y-m-d H:i:s', time() + 600);
                db()->prepare("UPDATE users SET login_attempts=?, login_locked_until=? WHERE id=?")->execute([$att, $lock, $user['id']]);
            } else {
                db()->prepare("UPDATE users SET login_attempts=? WHERE id=?")->execute([$att, $user['id']]);
            }
        }
        db()->prepare("INSERT INTO login_attempts (identifier,ip_address,user_agent) VALUES (?,?,?)")->execute([$identifier, $ip, get_ua()]);
        log_security('LOGIN_FAIL', ['identifier' => $identifier]);
        return ['success' => false, 'message' => 'Invalid username/email or password.'];
    }
    
    if ($user['is_suspended']) return ['success' => false, 'message' => 'Account suspended. Contact support.'];
    db()->prepare("DELETE FROM login_attempts WHERE identifier=?")->execute([$identifier]);
    db()->prepare("UPDATE users SET login_attempts=0,login_locked_until=NULL,last_login=NOW(),last_login_ip=? WHERE id=?")->execute([$ip, $user['id']]);
    start_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_phone'] = $user['phone'];
    $_SESSION['login_ts'] = time();
    // Log to login_tracker
    try {
        db()->prepare("INSERT INTO login_tracker (user_id,ip_address,user_agent,success) VALUES (?,?,?,1)")->execute([$user['id'], $ip, get_ua()]);
    } catch (PDOException $e) {
        // Table may not exist yet
    }
    log_info('auth', 'LOGIN', ['uid' => $user['id'], 'identifier' => $identifier]);
    try{notify_user($user['id'],'New Login','You logged into your '.setting('site_name','Gracehubtels').' account.','info');}catch(\Throwable$e){}
    return ['success' => true, 'user' => $user];
}
function register_user(array $data):array{
    foreach(['surname','first_name','phone','password','confirm_password','email','username'] as $f){if(empty($data[$f]))return['success'=>false,'message'=>ucfirst(str_replace('_',' ',$f)).' is required.'];}
    $surname=trim($data['surname']);$first=trim($data['first_name']);$middle=trim($data['middle_name']??'');
    $name=trim("$surname $first $middle");$phone=sanitize_phone($data['phone']??'');$pwd=$data['password'];$confirm=$data['confirm_password'];
    $email=trim($data['email']);$username=trim($data['username']);
    if(strlen($name)<3||strlen($name)>100)return['success'=>false,'message'=>'Full name must be 3-100 characters.'];
    if(!filter_var($email,FILTER_VALIDATE_EMAIL))return['success'=>false,'message'=>'Invalid email address.'];
    if(!preg_match('/^[a-zA-Z0-9_]{3,20}$/',$username))return['success'=>false,'message'=>'Username must be 3-20 characters (letters, numbers, underscore).'];
    if(!validate_phone($phone))return['success'=>false,'message'=>'Invalid phone (e.g. 08012345678).'];
    if(strlen($pwd)<8)return['success'=>false,'message'=>'Password must be at least 8 characters.'];
    if($pwd!==$confirm)return['success'=>false,'message'=>'Passwords do not match.'];
    if(detect_tamper($data))return['success'=>false,'message'=>'Invalid input detected.'];
    $chk=db()->prepare("SELECT id FROM users WHERE phone=? LIMIT 1");$chk->execute([$phone]);if($chk->fetch())return['success'=>false,'message'=>'Phone already registered.'];
    $chk=db()->prepare("SELECT id FROM users WHERE email=? LIMIT 1");$chk->execute([$email]);if($chk->fetch())return['success'=>false,'message'=>'Email already registered.'];
    $chk=db()->prepare("SELECT id FROM users WHERE username=? LIMIT 1");$chk->execute([$username]);if($chk->fetch())return['success'=>false,'message'=>'Username already taken.'];
    $ref_code=$phone;$referred=null;
    if(!empty($data['referral_code'])){$ref_input=trim($data['referral_code']);$rs=db()->prepare("SELECT id FROM users WHERE referral_code=? OR phone=? LIMIT 1");$rs->execute([$ref_input,$ref_input]);$rf=$rs->fetch();if($rf)$referred=$rf['id'];}
    $hash=password_hash($pwd,PASSWORD_BCRYPT,['cost'=>12]);
    db()->prepare("INSERT INTO users (full_name,username,phone,email,password,referral_code,referred_by) VALUES (?,?,?,?,?,?,?)")->execute([$name,$username,$phone,$email,$hash,$ref_code,$referred]);
    $uid=(int)db()->lastInsertId();log_info('auth','REGISTER',['uid'=>$uid,'phone'=>$phone]);
    // full_name/username/phone/email are stored as plain text (every other render
    // site in the app htmlspecialchars()'s them itself) — escape locally here since
    // these two blocks build raw HTML email bodies, unlike normal page rendering.
    $name_h=htmlspecialchars($name,ENT_QUOTES);$username_h=htmlspecialchars($username,ENT_QUOTES);
    $phone_h=htmlspecialchars($phone,ENT_QUOTES);$email_h=htmlspecialchars($email,ENT_QUOTES);
    try{
        notify_user($uid,'Welcome to '.setting('site_name','Gracehubtels').'!',"
<div style='text-align:center;padding:10px 0 20px;'>
<div style='width:64px;height:64px;background:#e8f5e9;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;'><span style='font-size:24px;font-weight:800;color:#16a34a;'>OK</span></div>
<h3 style='color:#4F46E5;font-size:20px;margin:0 0 4px;'>Welcome, {$name_h}!</h3>
<p style='color:#475569;font-size:14px;margin:0 0 20px;'>Your account has been created successfully.</p>
</div>
<table style='width:100%;border-collapse:collapse;margin-bottom:20px;'>
<tr><td style='padding:10px 14px;background:#f8fafc;border-radius:8px;'>
<table width='100%'>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Account</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>{$username_h}</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Phone</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>{$phone_h}</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Email</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>{$email_h}</td></tr>
</table>
</td></tr>
</table>
<p style='color:#475569;font-size:14px;line-height:1.6;'>You now have access to all our services:</p>
<table style='width:100%;border-collapse:collapse;margin:12px 0;'>
<tr><td style='padding:6px 10px;border-bottom:1px solid #f1f5f9;'><span style='display:inline-block;width:22px;height:22px;background:#e8f5e9;border-radius:4px;text-align:center;line-height:22px;font-size:11px;font-weight:700;color:#16a34a;margin-right:8px;'>A</span><strong>Airtime</strong></td><td style='padding:6px 10px;border-bottom:1px solid #f1f5f9;color:#64748b;font-size:13px;'>Instant top-ups for MTN, Glo, Airtel &amp; 9mobile</td></tr>
<tr><td style='padding:6px 10px;border-bottom:1px solid #f1f5f9;'><span style='display:inline-block;width:22px;height:22px;background:#e3f2fd;border-radius:4px;text-align:center;line-height:22px;font-size:11px;font-weight:700;color:#1565c0;margin-right:8px;'>D</span><strong>Data Plans</strong></td><td style='padding:6px 10px;border-bottom:1px solid #f1f5f9;color:#64748b;font-size:13px;'>All network data bundles at the best rates</td></tr>
<tr><td style='padding:6px 10px;border-bottom:1px solid #f1f5f9;'><span style='display:inline-block;width:22px;height:22px;background:#fff3e0;border-radius:4px;text-align:center;line-height:22px;font-size:11px;font-weight:700;color:#e65100;margin-right:8px;'>$</span><strong>Airtime to Cash</strong></td><td style='padding:6px 10px;border-bottom:1px solid #f1f5f9;color:#64748b;font-size:13px;'>Convert your airtime to real cash instantly</td></tr>
<tr><td style='padding:6px 10px;border-bottom:1px solid #f1f5f9;'><span style='display:inline-block;width:22px;height:22px;background:#fce4ec;border-radius:4px;text-align:center;line-height:22px;font-size:11px;font-weight:700;color:#c62828;margin-right:8px;'>E</span><strong>Electricity</strong></td><td style='padding:6px 10px;border-bottom:1px solid #f1f5f9;color:#64748b;font-size:13px;'>Prepaid &amp; postpaid for IKEDC, EKEDC, AEDC &amp; more</td></tr>
<tr><td style='padding:6px 10px;border-bottom:1px solid #f1f5f9;'><span style='display:inline-block;width:22px;height:22px;background:#f3e5f5;border-radius:4px;text-align:center;line-height:22px;font-size:11px;font-weight:700;color:#7b1fa2;margin-right:8px;'>TV</span><strong>Cable TV</strong></td><td style='padding:6px 10px;border-bottom:1px solid #f1f5f9;color:#64748b;font-size:13px;'>DSTV, GOtv &amp; Startimes subscriptions</td></tr>
<tr><td style='padding:6px 10px;border-bottom:1px solid #f1f5f9;'><span style='display:inline-block;width:22px;height:22px;background:#e0f7fa;border-radius:4px;text-align:center;line-height:22px;font-size:11px;font-weight:700;color:#00838f;margin-right:8px;'>P</span><strong>Exam PINs</strong></td><td style='padding:6px 10px;border-bottom:1px solid #f1f5f9;color:#64748b;font-size:13px;'>WAEC, NECO, JAMB &amp; other exam scratch cards</td></tr>
<tr><td style='padding:6px 10px;border-bottom:1px solid #f1f5f9;'><span style='display:inline-block;width:22px;height:22px;background:#fff8e1;border-radius:4px;text-align:center;line-height:22px;font-size:11px;font-weight:700;color:#f9a825;margin-right:8px;'>R</span><strong>Recharge Cards</strong></td><td style='padding:6px 10px;border-bottom:1px solid #f1f5f9;color:#64748b;font-size:13px;'>Print physical recharge cards for resale</td></tr>
<tr><td style='padding:6px 10px;border-bottom:1px solid #f1f5f9;'><span style='display:inline-block;width:22px;height:22px;background:#e8eaf6;border-radius:4px;text-align:center;line-height:22px;font-size:11px;font-weight:700;color:#283593;margin-right:8px;'>VC</span><strong>Virtual Cards</strong></td><td style='padding:6px 10px;border-bottom:1px solid #f1f5f9;color:#64748b;font-size:13px;'>Create virtual cards for online payments</td></tr>
<tr><td style='padding:6px 10px;border-bottom:1px solid #f1f5f9;'><span style='display:inline-block;width:22px;height:22px;background:#e0f2f1;border-radius:4px;text-align:center;line-height:22px;font-size:11px;font-weight:700;color:#00695c;margin-right:8px;'>B</span><strong>Bank Transfers</strong></td><td style='padding:6px 10px;border-bottom:1px solid #f1f5f9;color:#64748b;font-size:13px;'>Withdraw your earnings to any Nigerian bank</td></tr>
<tr><td style='padding:6px 10px;'><span style='display:inline-block;width:22px;height:22px;background:#efebe9;border-radius:4px;text-align:center;line-height:22px;font-size:11px;font-weight:700;color:#4e342e;margin-right:8px;'>W</span><strong>Wallet Transfer</strong></td><td style='padding:6px 10px;color:#64748b;font-size:13px;'>Send funds to other users instantly</td></tr>
</table>
<div style='text-align:center;margin-top:20px;padding:16px;background:#fff8e1;border-radius:10px;border:1px solid #ffe082;'>
<p style='margin:0;color:#e65100;font-size:13px;font-weight:600;'>Fund your wallet and start transacting right away!</p>
</div>",'success');
    }catch(\Throwable$e){}
    try{
        $site_name = setting('site_name', 'Gracehubtels');
        notify_admin('New User Registration', "
<div style='text-align:center;padding:10px 0 16px;'>
<span style='font-size:36px;line-height:1;font-weight:800;color:#4F46E5;'>+1</span>
<h3 style='color:#4F46E5;font-size:18px;margin:8px 0 4px;'>New User Registered</h3>
<p style='color:#475569;font-size:13px;margin:0;'>A new account has been created on {$site_name}.</p>
</div>
<table style='width:100%;border-collapse:collapse;'>
<tr><td style='padding:8px 12px;background:#f8fafc;border-radius:8px;'>
<table width='100%'>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Name</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>{$name_h}</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Username</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>{$username_h}</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Phone</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>{$phone_h}</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Email</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>{$email_h}</td></tr>
</table>
</td></tr>
</table>");
    }catch(\Throwable$e){}
    return['success'=>true,'user_id'=>$uid,'message'=>'Account created successfully!'];
}
function verify_pin(int $uid,string $pin):bool{
    if(!preg_match('/^\d{4}$/',$pin))return false;
    try{$s=db()->prepare("SELECT transaction_pin,pin_attempts,pin_locked_until FROM users WHERE id=? LIMIT 1");$s->execute([$uid]);$r=$s->fetch();
    if(!$r||!$r['transaction_pin'])return false;
    if($r['pin_locked_until']&&strtotime($r['pin_locked_until'])>time())return false;
    if(password_verify($pin,$r['transaction_pin'])){db()->prepare("UPDATE users SET pin_attempts=0,pin_locked_until=NULL WHERE id=?")->execute([$uid]);return true;}
    $att=(int)$r['pin_attempts']+1;$lock=null;
    if($att>=3){
        $lock=date('Y-m-d H:i:s',time()+600);
        db()->prepare("UPDATE users SET pin_attempts=?,pin_locked_until=? WHERE id=?")->execute([$att,$lock,$uid]);
        log_security('PIN_LOCKED',['uid'=>$uid,'attempts'=>$att]);
        return false;
    }
    db()->prepare("UPDATE users SET pin_attempts=?,pin_locked_until=? WHERE id=?")->execute([$att,$lock,$uid]);
    log_security('WRONG_PIN',['uid'=>$uid,'attempts'=>$att]);return false;}
    catch(PDOException $e){log_error('auth','verify_pin: '.$e->getMessage());return false;}
}
function logout_user():void{start_session();$_SESSION=[];if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);}session_destroy();header('Location: /user/login');exit;}
function csrf_token():string{start_session();if(empty($_SESSION['csrf_token']))$_SESSION['csrf_token']=bin2hex(random_bytes(32));return $_SESSION['csrf_token'];}
function verify_csrf(string $t):bool{start_session();return isset($_SESSION['csrf_token'])&&hash_equals($_SESSION['csrf_token'],$t);}
