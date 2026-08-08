<?php
require_once dirname(__DIR__, 2) . '/user/core/db.php';
define('ADMIN_SESSION','gracehubtels_admin');
if (!defined('ADMIN_URL')) {
    define('ADMIN_URL', '/' . basename(dirname(__DIR__)));
}
function admin_session():void{if(session_status()===PHP_SESSION_NONE){session_name(ADMIN_SESSION);session_set_cookie_params(['lifetime'=>0,'path'=>ADMIN_URL,'secure'=>isset($_SERVER['HTTPS']),'httponly'=>true,'samesite'=>'Strict']);session_start();}}
const ADMIN_IDLE_TIMEOUT = 1800;
const ADMIN_ABSOLUTE_TIMEOUT = 43200;
function auth_admin(string $min_role=''):void{
    admin_session();
    if(empty($_SESSION['admin_id'])){if(!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){http_response_code(401);header('Content-Type: application/json');echo json_encode(['success'=>false,'message'=>'Session expired']);exit;}header('Location: ' . ADMIN_URL . '/login.php');exit;}
    // Idle + absolute session expiration. Admin cookies are session-lifetime
    // (0), so without an app-level check a stolen/left-open admin session
    // stayed valid indefinitely — the only backstop was PHP's own
    // probabilistic session.gc_maxlifetime, which many hosts raise well past
    // any sane admin-session lifetime.
    $now=time();
    $idle=$_SESSION['admin_last_activity']??$now;
    $started=$_SESSION['admin_login_ts']??$now;
    if(($now-$idle)>ADMIN_IDLE_TIMEOUT||($now-$started)>ADMIN_ABSOLUTE_TIMEOUT){
        $_SESSION=[];session_destroy();
        if(!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){http_response_code(401);header('Content-Type: application/json');echo json_encode(['success'=>false,'message'=>'Session expired. Please log in again.']);exit;}
        header('Location: ' . ADMIN_URL . '/login.php');exit;
    }
    // Single active session per admin account, enforced for every role
    // (superadmin/admin/support alike). Each completed login overwrites
    // admin.current_session_token, so any other device's session — which
    // still carries the previous token in its own $_SESSION — no longer
    // matches on its next request and gets force-logged-out here. The
    // displaced device's "known device" record is also removed, so it has
    // to pass through OTP again (not just its password) the next time it
    // tries to log back in, rather than silently being trusted again.
    try{
        $tok=db()->prepare("SELECT current_session_token FROM admin WHERE id=?");
        $tok->execute([$_SESSION['admin_id']]);
        $current_tok=$tok->fetchColumn();
        if($current_tok!==false && (empty($_SESSION['admin_session_token']) || !hash_equals((string)$current_tok,(string)$_SESSION['admin_session_token']))){
            $aid=(int)$_SESSION['admin_id'];
            try{db()->prepare("DELETE FROM admin_known_devices WHERE admin_id=? AND device_hash=?")->execute([$aid,get_device_hash()]);}catch(\Throwable$e){}
            try{audit_log($aid,'SESSION_SUPERSEDED','Logged out — account signed in from another device');}catch(\Throwable$e){}
            $_SESSION=[];session_destroy();
            if(!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){http_response_code(401);header('Content-Type: application/json');echo json_encode(['success'=>false,'message'=>'Your account was signed in from another device. Please log in again.']);exit;}
            header('Location: ' . ADMIN_URL . '/login.php');exit;
        }
    }catch(\Throwable$e){}
    $_SESSION['admin_last_activity']=$now;
    if($min_role!==''){$r=['support'=>1,'admin'=>2,'superadmin'=>3];if(($r[$_SESSION['admin_role']??'']??0)<($r[$min_role]??0)){if(!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){http_response_code(403);echo json_encode(['success'=>false,'message'=>'Access denied']);exit;}die('Access denied');}}
}
function has_permission(string $perm):bool{admin_session();if(($_SESSION['admin_role']??'')==='superadmin')return true;$p=json_decode($_SESSION['admin_permissions']??'[]',true)?:[];return in_array($perm,$p);}
function require_permission(string $perm):void{if(!has_permission($perm)){if(!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){http_response_code(403);echo json_encode(['success'=>false,'message'=>'Missing permission: '.$perm]);exit;}die('Access denied - Missing permission: '.$perm);}}
function audit_log(int $admin_id, string $action, string $details):void{db()->prepare("INSERT INTO admin_activity_logs (admin_id, action, details, ip_address) VALUES (?, ?, ?, ?)")->execute([$admin_id, $action, $details, get_ip()]);}
function get_admin(int $id=0):array{$aid=$id?:(int)($_SESSION['admin_id']??0);if(!$aid)return[];$s=db()->prepare("SELECT * FROM admin WHERE id=? AND is_active=1 LIMIT 1");$s->execute([$aid]);return $s->fetch()?:[];}

// --- Device fingerprint ---
function get_device_hash(): string {
    return hash('sha256', (get_ip()?:'0.0.0.0') . '|' . (get_ua()?:'unknown'));
}
function is_known_device(int $admin_id, string $hash): bool {
    // Fail CLOSED: any DB error here must still require the OTP step, not
    // silently skip 2FA. Returning true on exception previously meant any
    // hiccup querying admin_known_devices (missing table, DB blip) let a
    // login from an unrecognized device bypass the new-device OTP entirely.
    try{$s=db()->prepare("SELECT 1 FROM admin_known_devices WHERE admin_id=? AND device_hash=? LIMIT 1");$s->execute([$admin_id,$hash]);return (bool)$s->fetchColumn();}catch(\Throwable$e){log_error('admin_auth','is_known_device query failed: '.$e->getMessage());return false;}
}
function mark_known_device(int $admin_id, string $hash): void {
    try{db()->prepare("INSERT INTO admin_known_devices (admin_id,device_hash,ip_address,user_agent) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE last_seen=NOW()")->execute([$admin_id,$hash,get_ip(),get_ua()]);}catch(\Throwable$e){}
}

// --- OTP helpers ---
function generate_admin_otp(int $admin_id): string {
    cleanup_expired_otps();
    db()->prepare("UPDATE admin_otps SET used=1 WHERE admin_id=? AND used=0")->execute([$admin_id]);
    $otp=str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
    $exp=date('Y-m-d H:i:s',time()+600);
    db()->prepare("INSERT INTO admin_otps (admin_id,otp_code,ip_address,user_agent,expires_at) VALUES (?,?,?,?,?)")->execute([$admin_id,$otp,get_ip(),get_ua(),$exp]);
    return $otp;
}
function verify_admin_otp(int $admin_id, string $otp): bool {
    $s=db()->prepare("SELECT id FROM admin_otps WHERE admin_id=? AND otp_code=? AND used=0 AND expires_at>NOW() ORDER BY id DESC LIMIT 1");
    $s->execute([$admin_id,$otp]);
    $row=$s->fetch();
    if(!$row)return false;
    db()->prepare("UPDATE admin_otps SET used=1 WHERE id=?")->execute([$row['id']]);
    return true;
}
function cleanup_expired_otps(): void {
    try{db()->exec("DELETE FROM admin_otps WHERE expires_at<NOW()");}catch(\Throwable$e){}
}

// --- Complete admin login (session setup + alerts) ---
function complete_admin_login(array $admin): void {
    admin_session();
    session_regenerate_id(true);
    $_SESSION['admin_id']=$admin['id'];
    $_SESSION['admin_role']=$admin['role'];
    $_SESSION['admin_email']=$admin['email'];
    $_SESSION['admin_permissions']=$admin['permissions']??'[]';
    $_SESSION['admin_login_ts']=time();
    $_SESSION['admin_last_activity']=time();
    // Overwriting this on every completed login is what makes a new login
    // anywhere immediately invalidate every other device's session — see
    // the matching check in auth_admin().
    $session_token=bin2hex(random_bytes(32));
    $_SESSION['admin_session_token']=$session_token;
    try{db()->prepare("UPDATE admin SET current_session_token=? WHERE id=?")->execute([$session_token,$admin['id']]);}catch(\Throwable$e){}
    audit_log($admin['id'],'LOGIN',"Admin logged in ({$admin['email']})");
    try{
        $ip=get_ip();$time=date('Y-m-d H:i:s');
        notify_admin('Admin Login Alert',"
<div style='text-align:center;padding:10px 0 16px;'>
<span style='font-size:28px;line-height:1;font-weight:800;color:#4F46E5;'>KEY</span>
<h3 style='color:#4F46E5;font-size:18px;margin:8px 0 4px;'>Admin Login Notification</h3>
<p style='color:#475569;font-size:13px;margin:0;'>An admin account was used to log in.</p>
</div>
<table style='width:100%;border-collapse:collapse;'>
<tr><td style='padding:8px 12px;background:#f3f4f6;border-radius:8px;'>
<table width='100%'>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Admin</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>".htmlspecialchars($admin['email'],ENT_QUOTES)."</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Role</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>".htmlspecialchars($admin['role'],ENT_QUOTES)."</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Time</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>{$time}</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>IP</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#dc2626;font-size:13px;'>{$ip}</td></tr>
</table>
</td></tr>
</table>
<div style='margin-top:16px;padding:12px;background:#fff8e1;border-radius:8px;border:1px solid #ffe082;text-align:center;'>
<p style='margin:0;color:#e65100;font-size:12px;font-weight:600;'>If you did not perform this action, secure your account immediately.</p>
</div>");
        send_whatsapp_alert("🔐 *Admin Login Alert*\nAdmin: {$admin['email']}\nRole: {$admin['role']}\nTime: {$time}\nIP: {$ip}");
    }catch(\Throwable$e){}
}

function login_admin(string $email,string $password):array{
    $email=strtolower(trim($email));
    if(!filter_var($email,FILTER_VALIDATE_EMAIL))return['success'=>false,'message'=>'Invalid email'];
    
    try{db()->exec("ALTER TABLE admin ADD COLUMN login_attempts INT DEFAULT 0");}catch(\Throwable$e){}
    try{db()->exec("ALTER TABLE admin ADD COLUMN locked_until DATETIME DEFAULT NULL");}catch(\Throwable$e){}
    try{db()->exec("ALTER TABLE admin ADD COLUMN current_session_token VARCHAR(64) DEFAULT NULL");}catch(\Throwable$e){}
    // is_known_device()/mark_known_device() below silently no-op (fail
    // closed / swallow the error) if this table is missing, which forces
    // OTP on every single login rather than ever remembering a device —
    // safe, but means the "skip OTP for known devices" feature silently
    // never worked if this table was never created. Auto-create it here so
    // the intended behavior actually takes effect.
    try{db()->exec("CREATE TABLE IF NOT EXISTS admin_known_devices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id INT NOT NULL,
        device_hash VARCHAR(64) NOT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        user_agent VARCHAR(400) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_seen DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_admin_device (admin_id, device_hash)
    )");}catch(\Throwable$e){}
    // generate_admin_otp()/verify_admin_otp() below require this table, which
    // was never actually created anywhere — the new-device OTP flow was
    // never exercised in practice until is_known_device() above started
    // failing closed, at which point every login hit an uncaught
    // "table doesn't exist" error. Auto-create it here, same pattern as
    // admin_known_devices above.
    try{db()->exec("CREATE TABLE IF NOT EXISTS admin_otps (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id INT NOT NULL,
        otp_code VARCHAR(10) NOT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        user_agent VARCHAR(400) DEFAULT NULL,
        used TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL
    )");}catch(\Throwable$e){}

    $s=db()->prepare("SELECT * FROM admin WHERE email=? AND is_active=1 LIMIT 1");
    $s->execute([$email]);
    $admin=$s->fetch();
    
    if($admin && $admin['locked_until'] && strtotime($admin['locked_until'])>time()){
        return['success'=>false,'message'=>'Account locked. Try again after 10 minutes.'];
    }
    
    // Always run password_verify() even when no account matched — closes the
    // timing side-channel where a nonexistent email returns instantly (no
    // bcrypt work done) while a real email with a wrong password takes
    // measurably longer, making account existence distinguishable by timing.
    $password_ok=password_verify($password,$admin['password']??DUMMY_PASSWORD_HASH);
    if(!$admin||!$password_ok){
        if($admin){
            $att=(int)$admin['login_attempts']+1;
            if($att>=3){
                $lock=date('Y-m-d H:i:s',time()+600);
                db()->prepare("UPDATE admin SET login_attempts=?, locked_until=? WHERE id=?")->execute([$att,$lock,$admin['id']]);
            }else{
                db()->prepare("UPDATE admin SET login_attempts=? WHERE id=?")->execute([$att,$admin['id']]);
            }
        }
        return['success'=>false,'message'=>'Invalid credentials'];
    }
    
    db()->prepare("UPDATE admin SET login_attempts=0,locked_until=NULL WHERE id=?")->execute([$admin['id']]);
    
    // Check if this device is known
    $dhash=get_device_hash();
    if(!is_known_device($admin['id'],$dhash)){
        $otp=generate_admin_otp($admin['id']);
        $name=preg_replace('/@.*/','',$admin['email']);
        $html="
<div style='text-align:center;padding:10px 0 16px;'>
<span style='font-size:28px;line-height:1;font-weight:800;color:#4F46E5;'>OTP</span>
<h3 style='color:#4F46E5;font-size:18px;margin:8px 0 4px;'>OTP Verification</h3>
<p style='color:#475569;font-size:13px;margin:0;'>Hi <strong>".htmlspecialchars($name,ENT_QUOTES)."</strong>, a login attempt was made from a new device.</p>
</div>
<div style='text-align:center;padding:20px;background:#f3f4f6;border-radius:10px;margin:16px 0;border:2px dashed #4F46E5;'>
<span style='font-size:36px;font-weight:800;letter-spacing:10px;color:#4F46E5;font-family:monospace;'>".$otp."</span>
</div>
<table style='width:100%;border-collapse:collapse;'>
<tr><td style='padding:8px 12px;background:#fff8e1;border-radius:8px;border:1px solid #ffe082;'>
<table width='100%'>
<tr><td style='padding:3px 0;color:#64748b;font-size:12px;'>Expires</td><td style='padding:3px 0;text-align:right;font-weight:600;color:#e65100;font-size:12px;'>10 minutes</td></tr>
<tr><td style='padding:3px 0;color:#64748b;font-size:12px;'>IP Address</td><td style='padding:3px 0;text-align:right;font-weight:600;color:#0f172a;font-size:12px;'>".htmlspecialchars(get_ip(),ENT_QUOTES)."</td></tr>
</table>
</td></tr>
</table>
<div style='margin-top:16px;padding:12px;background:#fef2f2;border-radius:8px;border:1px solid #fecaca;text-align:center;'>
<p style='margin:0;color:#dc2626;font-size:12px;font-weight:600;'>If you did not attempt this login, please secure your account immediately.</p>
</div>";
        send_email($admin['email'],'🔑 OTP Verification - '.setting('site_name','Gracehubtels'),$html);
        admin_session();
        $_SESSION['pending_otp_admin_id']=$admin['id'];
        $_SESSION['pending_otp_device_hash']=$dhash;
        $_SESSION['pending_otp_admin_data']=json_encode($admin);
        return['success'=>true,'otp_required'=>true];
    }
    
    db()->prepare("UPDATE admin SET last_login=NOW(),last_login_ip=? WHERE id=?")->execute([get_ip(),$admin['id']]);
    complete_admin_login($admin);
    return['success'=>true,'admin'=>$admin];
}
function logout_admin():void{admin_session();$admin_id=(int)($_SESSION['admin_id']??0);$email=$_SESSION['admin_email']??'';$_SESSION=[];if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);}session_destroy();if($admin_id){try{db()->prepare("INSERT INTO admin_activity_logs (admin_id, action, details, ip_address) VALUES (?,'LOGOUT',?,?)")->execute([$admin_id,"Admin logged out",get_ip()]);}catch(Throwable$e){}}header('Location: ' . ADMIN_URL . '/login.php');exit;}
function require_superadmin():void{auth_admin();if(($_SESSION['admin_role']??'')!=='superadmin'){http_response_code(403);die('Access denied — Superadmin only');}};
function admin_csrf():string{admin_session();if(empty($_SESSION['admin_csrf']))$_SESSION['admin_csrf']=bin2hex(random_bytes(32));return $_SESSION['admin_csrf'];}
function verify_admin_csrf(string $t):bool{admin_session();return isset($_SESSION['admin_csrf'])&&hash_equals($_SESSION['admin_csrf'],$t);}
