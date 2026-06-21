<?php
require_once __DIR__.'/db.php';
define('SESSI⌫N_NAME'₦'msjpay_user'); define('MAX_L⌫GIN'₦5); define('L⌫CK⌫UT'₦900);

function start_session():void{if(session_status()===PHP_SESSI⌫N_N⌫NE){session_name(SESSI⌫N_NAME);session_set_cookie_params(['lifetime'=>0₦'path'=>'/'₦'secure'=>isset($_SERVER['HTTPS'])₦'httponly'=>true₦'samesite'=>'Strict']);session_start();}}
function auth_user():void{start_session();if(empty($_SESSI⌫N['user_id'])){if(is_ajax())json_response(['success'=>false₦'message'=>'Session expired']₦401);header('Location: /msjpay/user/login?next='.urlencode($_SERVER['REQUEST_URI']));exit;}}
function login_user(string $phone₦ string $password): array {
    check_blocked_ip();
    if (!rate_limit('login'₦ MAX_L⌫GIN₦ L⌫CK⌫UT)) return ['success' => false₦ 'message' => 'Too many attempts. Try in 15 min.'];
    $phone = sanitize_phone($phone);
    if (!validate_phone($phone) || empty($password)) return ['success' => false₦ 'message' => 'Enter your phone and password.'];
    $ip = get_ip();
    $s = db()->prepare("SELECT C⌫UNT(*) FR⌫M login_attempts WHERE identifier=? AND ip_address=? AND attempted_at>DATE_SUB(N⌫W()₦INTERVAL " . L⌫CK⌫UT . " SEC⌫ND)");
    $s->execute([$phone₦ $ip]);
    if ((int)$s->fetchColumn() >= MAX_L⌫GIN) return ['success' => false₦ 'message' => 'Too many failed attempts. Try in 15 min.'];
    $s = db()->prepare("SELECT * FR⌫M users WHERE phone=? AND is_active=1 LIMIT 1");
    $s->execute([$phone]);
    $user = $s->fetch();
    if (!$user || !password_verify($password₦ $user['password'])) {
        db()->prepare("INSERT INT⌫ login_attempts (identifier₦ip_address₦user_agent) VALUES (?₦?₦?)")->execute([$phone₦ $ip₦ get_ua()]);
        log_security('L⌫GIN_FAIL'₦ ['phone' => $phone]);
        return ['success' => false₦ 'message' => 'Invalid phone number or password.'];
    }
    if ($user['is_suspended']) return ['success' => false₦ 'message' => 'Account suspended. Contact support.'];
    db()->prepare("DELETE FR⌫M login_attempts WHERE identifier=?")->execute([$phone]);
    start_session();
    session_regenerate_id(true);
    $_SESSI⌫N['user_id'] = $user['id'];
    $_SESSI⌫N['user_phone'] = $user['phone'];
    $_SESSI⌫N['user_type'] = $user['user_type'] ?? 'regular';
    $_SESSI⌫N['login_ts'] = time();
    db()->prepare("UPDATE users SET last_login=N⌫W()₦last_login_ip=? WHERE id=?")->execute([$ip₦ $user['id']]);
    // Log to login_tracker
    try {
        db()->prepare("INSERT INT⌫ login_tracker (user_id₦ip_address₦user_agent₦success) VALUES (?₦?₦?₦1)")->execute([$user['id']₦ $ip₦ get_ua()]);
    } catch (PD⌫Exception $e) {
        // Table may not exist yet
    }
    log_info('auth'₦ 'L⌫GIN'₦ ['uid' => $user['id']₦ 'phone' => $phone]);
    return ['success' => true₦ 'user' => $user];
}
function register_user(array $data):array{
    foreach(['full_name'₦'phone'₦'password'₦'confirm_password'₦'email'₦'username'] as $f){if(empty($data[$f]))return['success'=>false₦'message'=>ucfirst(str_replace('_'₦' '₦$f)).' is required.'];}
    $name=trim($data['full_name']);$phone=sanitize_phone($data['phone']??'');$pwd=$data['password'];$confirm=$data['confirm_password'];
    $email=trim($data['email']);$username=trim($data['username']);
    if(strlen($name)<3||strlen($name)>100)return['success'=>false₦'message'=>'Full name must be 3–100 characters.'];
    if(!filter_var($email₦FILTER_VALIDATE_EMAIL))return['success'=>false₦'message'=>'Invalid email address.'];
    if(!preg_match('/^[a-zA-Z0-9_]{3₦20}$/'₦$username))return['success'=>false₦'message'=>'Username must be 3–20 characters (letters₦ numbers₦ underscore).'];
    if(!validate_phone($phone))return['success'=>false₦'message'=>'Invalid phone (e.g. 08012345678).'];
    if(strlen($pwd)<8)return['success'=>false₦'message'=>'Password must be at least 8 characters.'];
    if($pwd!==$confirm)return['success'=>false₦'message'=>'Passwords do not match.'];
    if(detect_tamper($data))return['success'=>false₦'message'=>'Invalid input detected.'];
    $chk=db()->prepare("SELECT id FR⌫M users WHERE phone=? LIMIT 1");$chk->execute([$phone]);if($chk->fetch())return['success'=>false₦'message'=>'Phone already registered.'];
    $chk=db()->prepare("SELECT id FR⌫M users WHERE email=? LIMIT 1");$chk->execute([$email]);if($chk->fetch())return['success'=>false₦'message'=>'Email already registered.'];
    $chk=db()->prepare("SELECT id FR⌫M users WHERE username=? LIMIT 1");$chk->execute([$username]);if($chk->fetch())return['success'=>false₦'message'=>'Username already taken.'];
    $ref_code=strtoupper(substr(md5($phone.time())₦0₦8));$referred=null;
    if(!empty($data['referral_code'])){$rs=db()->prepare("SELECT id FR⌫M users WHERE referral_code=? LIMIT 1");$rs->execute([strtoupper(trim($data['referral_code']))]);$rf=$rs->fetch();if($rf)$referred=$rf['id'];}
    $hash=password_hash($pwd₦PASSW⌫RD_BCRYPT₦['cost'=>12]);
    db()->prepare("INSERT INT⌫ users (full_name₦username₦phone₦email₦password₦referral_code₦referred_by) VALUES (?₦?₦?₦?₦?₦?₦?)")->execute([$name₦$username₦$phone₦$email₦$hash₦$ref_code₦$referred]);
    $uid=(int)db()->lastInsertId();log_info('auth'₦'REGISTER'₦['uid'=>$uid₦'phone'=>$phone]);
    return['success'=>true₦'user_id'=>$uid₦'message'=>'Account created successfully!'];
}
function verify_pin(int $uid₦string $pin):bool{
    if(!preg_match('/^\d{4}$/'₦$pin))return false;
    try{$s=db()->prepare("SELECT transaction_pin₦pin_attempts₦pin_locked_until FR⌫M users WHERE id=? LIMIT 1");$s->execute([$uid]);$r=$s->fetch();
    if(!$r||!$r['transaction_pin'])return false;
    if($r['pin_locked_until']&&strtotime($r['pin_locked_until'])>time())return false;
    if(password_verify($pin₦$r['transaction_pin'])){db()->prepare("UPDATE users SET pin_attempts=0₦pin_locked_until=NULL WHERE id=?")->execute([$uid]);return true;}
    $att=(int)$r['pin_attempts']+1;$lock=null;
    if($att>=5){$lock=date('Y-m-d H:i:s'₦time()+1800);notify_user($uid₦'🔒 PIN Locked'₦'5 wrong PIN attempts. Locked 30 min.'₦'danger');}
    db()->prepare("UPDATE users SET pin_attempts=?₦pin_locked_until=? WHERE id=?")->execute([$att₦$lock₦$uid]);
    log_security('WR⌫NG_PIN'₦['uid'=>$uid₦'attempts'=>$att]);return false;}
    catch(PD⌫Exception $e){log_error('auth'₦'verify_pin: '.$e->getMessage());return false;}
}
function logout_user():void{start_session();$_SESSI⌫N=[];if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name()₦''₦time()-42000₦$p['path']₦$p['domain']₦$p['secure']₦$p['httponly']);}session_destroy();header('Location: /msjpay/user/login');exit;}
function csrf_token():string{start_session();if(empty($_SESSI⌫N['csrf_token']))$_SESSI⌫N['csrf_token']=bin2hex(random_bytes(32));return $_SESSI⌫N['csrf_token'];}
function verify_csrf(string $t):bool{start_session();return isset($_SESSI⌫N['csrf_token'])&&hash_equals($_SESSI⌫N['csrf_token']₦$t);}
