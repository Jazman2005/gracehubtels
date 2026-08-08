<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_admin();
if(isset($_GET['export']) && $_GET['export']==='csv'){
    $search=trim($_GET['q']??'');$filter=$_GET['filter']??'';
    $data=get_users_list(1, 999999, $search, $filter);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=users_export_'.date('YmdHis').'.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Full Name', 'Username', 'Email', 'Phone', 'Wallet Balance', 'Cashback', 'KYC Level', 'User Type', 'Status', 'Joined Date']);
    foreach($data['data'] as $u) {
        fputcsv($out, [
            $u['id'], $u['full_name'], $u['username'], $u['email'], $u['phone'],
            $u['wallet_balance'], $u['cashback_balance'], $u['user_type'],
            $u['is_suspended'] ? 'Suspended' : 'Active', $u['created_at']
        ]);
    }
    fclose($out);
    exit;
}
if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    $action=$_POST['action']??'';$uid=(int)($_POST['uid']??0);
    // Money-moving, credential-reset, impersonation and deletion actions require the
    // explicit "manage_users" permission — a bare admin session is not enough.
    $sensitive_actions=['credit','debit','reset_pin','change_password','delete_user','login_as','bulk_action'];
    if(in_array($action,$sensitive_actions,true))require_permission('manage_users');
    if($action==='suspend'){db()->prepare("UPDATE users SET is_suspended=1,suspension_reason=? WHERE id=?")->execute([sanitize($_POST['reason']??'Policy violation',255),$uid]);json_response(['success'=>true,'message'=>'User suspended']);}
    if($action==='unsuspend'){db()->prepare("UPDATE users SET is_suspended=0,suspension_reason=NULL WHERE id=?")->execute([$uid]);json_response(['success'=>true,'message'=>'User reinstated']);}
    // claim_purchase_lock() here guards against an admin double-clicking
    // Credit/Debit (or a duplicated AJAX submit) firing the same amount
    // twice in quick succession — credit_wallet()/debit_wallet() have no
    // idempotency key of their own and this is a brand-new transaction each
    // call, not a resolution of an existing pending one, so nothing else
    // would have caught a duplicate here.
    if($action==='credit'){$amt=(float)($_POST['amount']??0);if($amt<=0)json_response(['success'=>false,'message'=>'Invalid amount']);if(!claim_purchase_lock($uid,'admin_credit',[$amt,sanitize($_POST['note']??'',200)]))json_response(['success'=>false,'message'=>'Duplicate request detected. Please wait a moment and try again.']);$r=credit_wallet($uid,$amt,'admin_credit','Admin credit: '.sanitize($_POST['note']??'',200));json_response($r);}
    if($action==='debit'){$amt=(float)($_POST['amount']??0);if($amt<=0)json_response(['success'=>false,'message'=>'Invalid amount']);if(!claim_purchase_lock($uid,'admin_debit',[$amt,sanitize($_POST['note']??'',200)]))json_response(['success'=>false,'message'=>'Duplicate request detected. Please wait a moment and try again.']);$r=debit_wallet($uid,$amt,'admin_debit','Admin debit: '.sanitize($_POST['note']??'',200));if($r['success'])update_tx_status($r['ref'],'success');json_response($r);}
    if($action==='reset_pin'){db()->prepare("UPDATE users SET transaction_pin=NULL,pin_attempts=0,pin_locked_until=NULL WHERE id=?")->execute([$uid]);notify_user($uid,'PIN Reset','Your transaction PIN has been reset by admin. Set a new PIN.','info');json_response(['success'=>true,'message'=>'PIN reset']);}

    if($action==='change_password'){
        $admin_pw=$_POST['admin_password']??'';$new_pw=$_POST['new_password']??'';
        if(strlen($new_pw)<6)json_response(['success'=>false,'message'=>'Password must be at least 6 characters']);
        $s=db()->prepare("SELECT password FROM admin WHERE id=?");$s->execute([(int)$_SESSION['admin_id']]);$admin=$s->fetch();
        if(!$admin||!password_verify($admin_pw,$admin['password']))json_response(['success'=>false,'message'=>'Admin password is incorrect']);
        db()->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($new_pw,PASSWORD_DEFAULT),$uid]);
        json_response(['success'=>true,'message'=>'Password changed']);
    }

    if($action==='set_user_type'){
        $type=$_POST['user_type']??'';
        if(!in_array($type,['regular','agent','vendor']))json_response(['success'=>false,'message'=>'Invalid type']);
        db()->prepare("UPDATE users SET user_type=? WHERE id=?")->execute([$type,$uid]);
        json_response(['success'=>true,'message'=>'User type updated to '.$type]);
    }

    if($action==='set_custom_limits'){
        $daily = $_POST['custom_daily'] !== '' ? (float)$_POST['custom_daily'] : null;
        $airtime = $_POST['custom_airtime'] !== '' ? (float)$_POST['custom_airtime'] : null;
        $transfer = $_POST['custom_transfer'] !== '' ? (float)$_POST['custom_transfer'] : null;
        db()->prepare("UPDATE users SET custom_daily_limit=?, custom_airtime_limit=?, custom_transfer_limit=? WHERE id=?")->execute([$daily, $airtime, $transfer, $uid]);
        json_response(['success'=>true,'message'=>'Custom limits updated successfully']);
    }

    if($action==='delete_user'){
        $admin_pw=$_POST['admin_password']??'';
        $s=db()->prepare("SELECT password FROM admin WHERE id=?");$s->execute([(int)$_SESSION['admin_id']]);$admin=$s->fetch();
        if(!$admin||!password_verify($admin_pw,$admin['password']))json_response(['success'=>false,'message'=>'Admin password is incorrect']);
        
        try {
            db()->beginTransaction();
            // Delete dependent records not automatically cascaded or where we want to ensure deletion
            db()->prepare("DELETE FROM virtual_accounts WHERE user_id=?")->execute([$uid]);
            db()->prepare("DELETE FROM transactions WHERE user_id=?")->execute([$uid]);
            db()->prepare("DELETE FROM notifications WHERE user_id=?")->execute([$uid]);
            db()->prepare("DELETE FROM kyc_requests WHERE user_id=?")->execute([$uid]);
            db()->prepare("DELETE FROM beneficiaries WHERE user_id=?")->execute([$uid]);
            db()->prepare("DELETE FROM promo_claims WHERE user_id=?")->execute([$uid]);
            db()->prepare("DELETE FROM promo_usage WHERE user_id=?")->execute([$uid]);
            db()->prepare("DELETE FROM tickets WHERE user_id=?")->execute([$uid]);
            db()->prepare("DELETE FROM manual_funding_requests WHERE user_id=?")->execute([$uid]);
            db()->prepare("DELETE FROM login_tracker WHERE user_id=?")->execute([$uid]);
            // Delete the user (cascades where configured)
            db()->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
            db()->commit();
            json_response(['success'=>true,'message'=>'Customer account permanently deleted']);
        } catch(Exception $e) {
            db()->rollBack();
            error_log('Delete user failed: '.$e->getMessage()); json_response(['success'=>false,'message'=>'Failed to delete user']);
        }
    }

    if($action==='get_user_detail'){
        $s=db()->prepare("SELECT * FROM users WHERE id=?");$s->execute([$uid]);$user=$s->fetch(PDO::FETCH_ASSOC);
        if(!$user)json_response(['success'=>false,'message'=>'User not found'],404);
        $ts=db()->prepare("SELECT * FROM transactions WHERE user_id=? ORDER BY id DESC LIMIT 10");$ts->execute([$uid]);$txns=$ts->fetchAll(PDO::FETCH_ASSOC);
        $ls=db()->prepare("SELECT * FROM login_tracker WHERE user_id=? ORDER BY id DESC LIMIT 5");$ls->execute([$uid]);$logins=$ls->fetchAll(PDO::FETCH_ASSOC);
        // ensure is_dynamic column exists, then exclude one-time dynamic accounts
        try { db()->exec("ALTER TABLE virtual_accounts ADD COLUMN is_dynamic TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active"); } catch (\Throwable $e) {}
        $vs=db()->prepare("SELECT bank_name, account_number, account_name FROM virtual_accounts WHERE user_id=? AND (is_dynamic IS NULL OR is_dynamic=0)");$vs->execute([$uid]);$v_accs=$vs->fetchAll(PDO::FETCH_ASSOC);
        $vc=db()->prepare("SELECT * FROM virtual_cards WHERE user_id=? ORDER BY created_at DESC");$vc->execute([$uid]);$v_cards=$vc->fetchAll(PDO::FETCH_ASSOC);
        json_response(['success'=>true,'user'=>$user,'transactions'=>$txns,'logins'=>$logins,'virtual_accounts'=>$v_accs,'virtual_cards'=>$v_cards]);
    }

    if($action==='login_as'){
        $admin_id = $_SESSION['admin_id'];
        session_write_close();
        
        session_name('gracehubtels_user');
        session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>isset($_SERVER['HTTPS']),'httponly'=>true,'samesite'=>'Strict']);
        session_start();
        $_SESSION['user_id'] = $uid;
        $_SESSION['admin_impersonating'] = $admin_id;
        session_write_close();
        
        session_name('gracehubtels_admin');
        session_start();
        json_response(['success'=>true,'message'=>'Impersonating user...']);
    }

    if($action==='bulk_action'){
        $uids = json_decode($_POST['uids'] ?? '[]', true);
        $b_action = $_POST['bulk_action'] ?? '';
        if(empty($uids) || !is_array($uids)) json_response(['success'=>false,'message'=>'No users selected']);

        // Verify admin password for destructive actions
        if(in_array($b_action,['delete'])) {
            $admin_pw = $_POST['admin_password'] ?? '';
            $s=db()->prepare("SELECT password FROM admin WHERE id=?");$s->execute([(int)$_SESSION['admin_id']]);$admin=$s->fetch();
            if(!$admin||!password_verify($admin_pw,$admin['password'])) json_response(['success'=>false,'message'=>'Admin password is incorrect']);
        }
        
        $count = 0;
        foreach($uids as $id) {
            $id = (int)$id;
            if($b_action==='suspend') { db()->prepare("UPDATE users SET is_suspended=1, suspension_reason='Bulk suspension' WHERE id=?")->execute([$id]); $count++; }
            if($b_action==='unsuspend') { db()->prepare("UPDATE users SET is_suspended=0, suspension_reason=NULL WHERE id=?")->execute([$id]); $count++; }
            if($b_action==='credit') { if(claim_purchase_lock($id,'admin_credit_bulk',[(float)($_POST['amount']??0)])){ credit_wallet($id, (float)($_POST['amount']??0), 'admin_credit', 'Bulk admin credit'); $count++; } }
            if($b_action==='debit') { if(claim_purchase_lock($id,'admin_debit_bulk',[(float)($_POST['amount']??0)])){ debit_wallet($id, (float)($_POST['amount']??0), 'admin_debit', 'Bulk admin debit'); $count++; } }
            if($b_action==='upgrade_vendor') { db()->prepare("UPDATE users SET user_type='vendor' WHERE id=?")->execute([$id]); $count++; }
            if($b_action==='downgrade_regular') { db()->prepare("UPDATE users SET user_type='regular' WHERE id=?")->execute([$id]); $count++; }
            if($b_action==='delete') {
                try {
                    db()->prepare("DELETE FROM virtual_accounts WHERE user_id=?")->execute([$id]);
                    db()->prepare("DELETE FROM transactions WHERE user_id=?")->execute([$id]);
                    db()->prepare("DELETE FROM notifications WHERE user_id=?")->execute([$id]);
                    db()->prepare("DELETE FROM kyc_requests WHERE user_id=?")->execute([$id]);
                    db()->prepare("DELETE FROM beneficiaries WHERE user_id=?")->execute([$id]);
                    db()->prepare("DELETE FROM promo_claims WHERE user_id=?")->execute([$id]);
                    db()->prepare("DELETE FROM promo_usage WHERE user_id=?")->execute([$id]);
                    db()->prepare("DELETE FROM tickets WHERE user_id=?")->execute([$id]);
                    db()->prepare("DELETE FROM manual_funding_requests WHERE user_id=?")->execute([$id]);
                    db()->prepare("DELETE FROM login_tracker WHERE user_id=?")->execute([$id]);
                    db()->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
                    $count++;
                } catch(Exception $e) {
                    // skip individual failures
                }
            }
        }
        json_response(['success'=>true,'message'=>"Bulk action completed on $count users"]);
    }

    json_response(['success'=>false],400);
}
$csrf=admin_csrf();$page=max(1,(int)($_GET['page']??1));$search=trim($_GET['q']??'');$filter=$_GET['filter']??'';
$data=get_users_list($page,20,$search,$filter);

$stats = db()->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_suspended = 0 THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN is_suspended = 1 THEN 1 ELSE 0 END) as suspended,
        0 as verified
    FROM users
")->fetch(PDO::FETCH_ASSOC);

?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Customers — Gracehubtels Admin</title><link rel="icon" type="image/png" href="/assets/images/gracehubtels-favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">
<div class="section-header">
  <div>
    <h2>Customer Management</h2>
    <p>Manage your platform's customers, their wallets, and security.</p>
  </div>
  <div class="flex items-center gap-2">
    <select id="bulkActionSelect" class="form-control" style="width:auto;">
      <option value="">Bulk Actions...</option>
      <option value="suspend">Suspend Selected</option>
      <option value="unsuspend">Unsuspend Selected</option>
      <option value="credit">Credit Selected</option>
      <option value="debit">Debit Selected</option>
      <option value="upgrade_vendor">Upgrade to Vendor</option>
      <option value="downgrade_regular">Downgrade to Regular</option>
      <option value="delete">Delete Permanently</option>
    </select>
    <button class="btn-secondary" onclick="applyBulkAction()">Apply</button>
  </div>
  <a href="?export=csv&q=<?=urlencode($search)?>&filter=<?=urlencode($filter)?>" class="btn-primary"><svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2" style="margin-right:6px"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Export CSV</a>
</div>

<div class="kpi-grid">
  <div class="kpi-card">
    <div class="kpi-icon navy"><i class="fas fa-users"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">Total Customers</div>
      <div class="kpi-value"><?=number_format((float)$stats['total'])?></div>
    </div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon green"><i class="fas fa-check-circle"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">Active</div>
      <div class="kpi-value"><?=number_format((float)$stats['active'])?></div>
    </div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon red"><i class="fas fa-ban"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">Suspended</div>
      <div class="kpi-value"><?=number_format((float)$stats['suspended'])?></div>
    </div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon blue"><i class="fas fa-id-card"></i></div>
    <div class="kpi-info">
      <div class="kpi-label">KYC Verified</div>
      <div class="kpi-value"><?=number_format((float)$stats['verified'])?></div>
    </div>
  </div>
</div>

<div class="data-panel">
  <form class="search-bar" method="GET">
    <div class="search-input-wrap" style="position:relative; flex:1; min-width:200px;">
        <svg class="search-icon" viewBox="0 0 24 24" style="position:absolute; left:16px; top:50%; transform:translateY(-50%); width:20px; height:20px; stroke:var(--text-muted); fill:none; stroke-width:2;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" name="q" placeholder="Search by name, phone, email or username..." value="<?=htmlspecialchars($search)?>" class="form-control" style="width:100%; padding-left:48px;">
    </div>
    <div class="filter-tabs">
      <a href="?filter=" class="filter-tab <?=$filter===''?'active':''?>">All Customers</a>
      <a href="?filter=active" class="filter-tab <?=$filter==='active'?'active':''?>">Active</a>
      <a href="?filter=suspended" class="filter-tab <?=$filter==='suspended'?'active':''?>">Suspended</a>
      <a href="?filter=kyc1" class="filter-tab <?=$filter==='kyc1'?'active':''?>">KYC Verified</a>
      <a href="?filter=no_kyc" class="filter-tab <?=$filter==='no_kyc'?'active':''?>">No KYC</a>
    </div>
  </form>
  <div class="responsive-grid-wrapper">
    <table class="data-table">
      <thead><tr>
          <th style="width:40px; text-align:center;"><input type="checkbox" id="selectAllCheckbox" onclick="toggleAllCheckboxes(this)"></th>
          <th>Customer</th>
          <th>Balances</th>
          <th>Identity</th>
          <th>Status</th>
          <th>Joined</th>
          <th>Last Login</th>
          <th style="text-align:right;"></th>
      </tr></thead>
      <tbody>
      <?php foreach($data['data'] as $u):?>
      <tr class="clickable-row" onclick="openDetailModal(<?=$u['id']?>)"">
        <td style="text-align:center;" onclick="event.stopPropagation()"><input type="checkbox" class="u-checkbox" value="<?=$u['id']?>"></td>
        <td>
            <div class="user-cell">
                <div class="u-avatar"><?=strtoupper(substr($u['full_name'],0,1))?></div>
                <div>
                    <div class="u-name"><?=htmlspecialchars($u['full_name'])?></div>
                    <div class="u-phone"><?=htmlspecialchars($u['phone'])?> &bull; <?=htmlspecialchars($u['username'] ?: 'No username')?></div>
                </div>
            </div>
        </td>
        <td>
            <div class="font-bold text-navy">₦<?=number_format((float)$u['wallet_balance'],2)?></div>
            <div class="text-success text-xs font-semibold">+ ₦<?=number_format((float)$u['cashback_balance'],2)?> CB</div>
        </td>
        <td>
            <div class="flex flex-col" style="gap:6px;">
                <span class="badge badge-neutral" style="text-transform:uppercase;"><?=htmlspecialchars($u['user_type']??'regular')?></span>
            </div>
        </td>
        <td>
            <span class="badge <?=$u['is_suspended']?'badge-danger':(!$u['is_active']?'badge-warning':'badge-success')?>">
                <i class="fas <?=$u['is_suspended']?'fa-ban':(!$u['is_active']?'fa-clock':'fa-check-circle')?>" style="margin-right:4px;"></i><?=$u['is_suspended']?'Suspended':(!$u['is_active']?'Inactive':'Active')?>
            </span>
        </td>
        <td class="text-sm text-muted font-semibold">
            <?=date('d M Y',strtotime($u['created_at']))?><br>
            <span class="text-xs"><?=date('h:i A',strtotime($u['created_at']))?></span>
        </td>
        <td class="text-sm font-semibold">
            <?php if(!empty($u['last_login'])):?>
                <span style="color:var(--success);"><?=date('d M, H:i',strtotime($u['last_login']))?></span><br>
                <span class="text-xs text-muted">IP: <?=htmlspecialchars($u['last_login_ip']??'-')?></span>
            <?php else:?>
                <span style="color:#9CA3AF;">Never logged in</span>
            <?php endif;?>
        </td>
        <td style="text-align:right;">
            <button class="btn-ghost btn-sm" onclick="event.stopPropagation();openDetailModal(<?=$u['id']?>)">Manage</button>
        </td>
      </tr>
      <?php endforeach;if(empty($data['data'])):?><tr><td colspan="7" class="empty-state" style="padding:40px; text-align:center; color:var(--text-muted);">No customers found matching your criteria.</td></tr><?php endif;?>
      </tbody>
    </table>
  </div>
  <?php if($data['pages']>1):?>
  <div class="pagination"><span class="page-info">Page <?=$page?> of <?=$data['pages']?></span>
  <?php for($i=max(1,$page-2);$i<=min($data['pages'],$page+2);$i++):?><a href="?page=<?=$i?>&q=<?=urlencode($search)?>&filter=<?=urlencode($filter)?>" class="page-btn <?=$i===$page?'active':''?>"><?=$i?></a><?php endfor;?>
  </div>
  <?php endif;?>
</div>
</div></div>

<!-- Detail Modal -->
<div class="modal-overlay" id="detailModalOvl" style="display:none;">
  <div class="modal" style="max-width:850px;">
    <div class="modal-header">
        <h3 id="detailTitle">Customer Details</h3>
        <button class="modal-close" onclick="closeDetailModal()"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" style="max-height:75vh; overflow-y:auto;">
      <div id="detailLoading" class="empty-state">Loading user details...</div>
      <div id="detailContent" style="display:none">
        <div id="detailInfo" class="mb-4"></div>
        <div class="detail-section" style="margin-bottom:20px;">
          <h4>Recent Transactions (Last 10)</h4>
          <div class="responsive-grid-wrapper"><table class="data-table"><thead><tr><th>Ref</th><th>Category</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead><tbody id="txnBody"></tbody></table></div>
        </div>
        <div class="detail-section" style="margin-bottom:20px;">
          <h4>Login History (Last 5)</h4>
          <div class="responsive-grid-wrapper"><table class="data-table"><thead><tr><th>IP Address</th><th>User Agent</th><th>Date</th></tr></thead><tbody id="loginBody"></tbody></table></div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="save-toast" id="toast"></div>
<script>
const CSRF='<?=$csrf?>';let curUser=null,curAction='',detailUserId=0;
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
function showSub(action){curAction=action;document.getElementById('subForm').style.display='block';document.getElementById('subBtn').textContent=action==='credit'?'Credit Wallet':'Debit Wallet';}
async function resetPin(){if(!confirm("Reset this user's transaction PIN?"))return;const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','reset_pin');fd.append('uid',curUser.id);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);}
function suspendPrompt(){const reason=prompt('Reason for suspension:');if(!reason)return;suspend(reason);}
async function suspend(reason){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','suspend');fd.append('uid',curUser.id);fd.append('reason',reason);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);}
async function unsuspend(){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','unsuspend');fd.append('uid',curUser.id);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);}
async function submitAction(){const amt=document.getElementById('amt').value;const note=document.getElementById('note').value;if(!amt||parseFloat(amt)<=0)return showToast('Enter valid amount',false);const fd=new FormData();fd.append('csrf',CSRF);fd.append('action',curAction);fd.append('uid',curUser.id);fd.append('amount',amt);fd.append('note',note);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);}
function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),2500);}
document.getElementById('detailModalOvl').addEventListener('click',e=>{if(e.target===e.currentTarget)closeDetailModal();});

function esc(str){if(!str)return '';return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function strToUpper(str){if(!str)return '';return String(str).toUpperCase();}
function fmtDate(d){if(!d)return '-';try{const dt=new Date(d);return dt.toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'})}catch(e){return d}}
function fmtMoney(n){return '\u20A6'+parseFloat(n||0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',')}

function openDetailModal(uid){detailUserId=uid;document.getElementById('detailLoading').style.display='block';document.getElementById('detailContent').style.display='none';document.getElementById('detailModalOvl').style.display='flex';document.getElementById('detailTitle').textContent='Loading...';loadUserDetail(uid);}
function closeDetailModal(){document.getElementById('detailModalOvl').style.display='none';document.getElementById('passwordForm').style.display='none';}

async function loadUserDetail(uid){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','get_user_detail');fd.append('uid',uid);try{const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());if(!r.success){showToast(r.message,false);closeDetailModal();return}renderDetail(r);}catch(e){showToast('Failed to load details',false);closeDetailModal();}}

function renderDetail(r){
  const u=r.user;curUser=u;
  document.getElementById('detailTitle').innerHTML = `
    <div class="flex items-center gap-3">
        <div class="u-avatar" style="width:48px;height:48px;border-radius:14px;background:linear-gradient(135deg,var(--primary) 0%,#4F46E5 100%);color:#fff;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:800;box-shadow:0 4px 12px rgba(99,102,241,0.3);">${strToUpper(u.full_name).substring(0,1)}</div>
        <div>
            <div style="font-size:22px;font-weight:800;color:var(--navy-dark);">${esc(u.full_name)}</div>
            <div class="text-sm text-muted font-semibold" style="margin-top:2px;">ID: #${u.id} &bull; ${esc(u.username||'No Username')}</div>
        </div>
    </div>
  `;
  
  let html = `
    <div class="kpi-grid">
      <div class="kpi-card">
        <div class="kpi-info">
          <div class="kpi-label">Wallet Balance</div>
          <div class="kpi-value">${fmtMoney(u.wallet_balance)}</div>
        </div>
        <div class="kpi-icon blue"><i class="fas fa-wallet"></i></div>
      </div>
      <div class="kpi-card">
        <div class="kpi-info">
          <div class="kpi-label">Cashback</div>
          <div class="kpi-value" style="color:var(--success);">${fmtMoney(u.cashback_balance)}</div>
        </div>
        <div class="kpi-icon green"><i class="fas fa-gift"></i></div>
      </div>
    </div>
    
    <div class="row-2col">
        <div class="data-panel" style="padding:24px;">
          <h4 class="font-bold text-navy flex items-center gap-2" style="font-size:15px;margin-bottom:16px;"><i class="fas fa-id-card" style="color:var(--primary)"></i> Personal Profile</h4>
          <div class="form-grid">
            <div class="form-group" style="margin-bottom:0;"><label class="text-xs text-muted font-semibold">Phone Number</label><strong>${esc(u.phone)}</strong></div>
            <div class="form-group" style="margin-bottom:0;"><label class="text-xs text-muted font-semibold">Email Address</label><strong>${esc(u.email||'-')} ${u.email_verified==1?'<span class="text-success text-xs" style="margin-left:4px;"><i class="fas fa-check-circle"></i></span>':''}</strong></div>
            <div class="form-group" style="margin-bottom:0;"><label class="text-xs text-muted font-semibold">BVN / NIN</label><strong>${esc(u.bvn||u.nin||'-')}</strong></div>
          </div>
        </div>
        
        <div class="data-panel" style="padding:24px;">
          <h4 class="font-bold text-navy flex items-center gap-2" style="font-size:15px;margin-bottom:16px;"><i class="fas fa-shield-alt" style="color:var(--primary)"></i> Account Status</h4>
          <div class="form-grid">
            <div class="form-group" style="margin-bottom:0;"><label class="text-xs text-muted font-semibold">User Type</label><strong><span class="badge badge-${u.user_type==='vendor'?'primary':u.user_type==='agent'?'warning':'neutral'}">${esc(u.user_type)}</span></strong></div>
            <div class="form-group" style="margin-bottom:0;"><label class="text-xs text-muted font-semibold">Status</label><strong><span class="badge ${parseInt(u.is_suspended)?'badge-danger':parseInt(u.is_active)==0?'badge-warning':'badge-success'}">${parseInt(u.is_suspended)?'Suspended':parseInt(u.is_active)==0?'Inactive':'Active'}</span></strong></div>
            <div class="form-group" style="margin-bottom:0;"><label class="text-xs text-muted font-semibold">Joined On</label><strong>${fmtDate(u.created_at)}</strong></div>
            <div class="form-group" style="margin-bottom:0;"><label class="text-xs text-muted font-semibold">Last Login</label><strong>${u.last_login?fmtDate(u.last_login):'<span style=color:#9CA3AF>Never</span>'}</strong></div>
          </div>
        </div>
    </div>
  `;
  
  if (r.virtual_cards && r.virtual_cards.length > 0) {
    html += `<div class="detail-section" style="margin-bottom:32px;">
      <h4 class="font-bold text-navy" style="font-size:16px;margin-bottom:16px;"><i class="fas fa-credit-card" style="color:var(--primary);margin-right:8px;"></i>Virtual Cards</h4>
      <div class="kpi-grid">`;
    r.virtual_cards.forEach(vc => {
      const statusBadge = vc.status === 'active' ? 'badge-success' : vc.status === 'frozen' ? 'badge-warning' : 'badge-danger';
      html += `<div class="kpi-card" style="padding:20px;">
        <div style="position:absolute;top:0;left:0;width:4px;height:100%;background:${vc.status==='active'?'var(--success)':vc.status==='frozen'?'var(--warning)':'var(--danger)'};"></div>
        <div class="kpi-info">
          <div class="kpi-label" style="margin-bottom:4px;">${esc(vc.brand || 'Visa')} Card</div>
          <div class="kpi-value" style="font-size:16px;letter-spacing:1px;margin-bottom:4px;font-family:monospace;">${esc(vc.masked_number || '•••• •••• •••• ••••')}</div>
          <div style="display:flex;justify-content:space-between;align-items:center;">
            <span class="kpi-sub">Bal: $${parseFloat(vc.balance||0).toFixed(2)}</span>
            <span class="badge ${statusBadge}" style="font-size:10px;">${esc(vc.status)}</span>
          </div>
          <div class="kpi-sub" style="margin-top:4px;font-size:11px;">Exp: ${esc(vc.expiry || '-')}</div>
        </div>
      </div>`;
    });
    html += `</div></div>`;
  }
  
  if (r.virtual_accounts && r.virtual_accounts.length > 0) {
    html += `<div class="detail-section" style="margin-bottom:32px;">
      <h4 class="font-bold text-navy" style="font-size:16px;margin-bottom:16px;">Assigned Virtual Accounts</h4>
      <div class="kpi-grid">`;
    r.virtual_accounts.forEach(va => {
      html += `<div class="kpi-card" style="padding:20px;">
        <div style="position:absolute;top:0;left:0;width:4px;height:100%;background:var(--primary);"></div>
        <div class="kpi-info">
          <div class="kpi-label" style="margin-bottom:8px;">${esc(va.bank_name)}</div>
          <div class="kpi-value" style="font-size:22px;letter-spacing:1px;margin-bottom:6px;">${esc(va.account_number)}</div>
          <div class="kpi-sub">${esc(va.account_name)}</div>
        </div>
      </div>`;
    });
    html += `</div></div>`;
  }
  
  html += `
    <div class="data-panel" style="padding:24px;">
      <h4 class="font-bold text-navy" style="font-size:16px;margin-bottom:20px;">Quick Actions</h4>
      <div class="flex gap-3" style="flex-wrap:wrap;margin-bottom:16px;">
        <button class="btn-primary btn-sm" onclick="showSub('credit')"><i class="fas fa-plus-circle"></i>Credit Wallet</button>
        <button class="btn-primary btn-sm" onclick="showSub('debit')"><i class="fas fa-minus-circle"></i>Debit Wallet</button>
        <button class="btn-primary btn-sm" onclick="resetPin()"><i class="fas fa-key"></i>Reset PIN</button>
        <button class="btn-ghost btn-sm" onclick="togglePasswordForm()"><i class="fas fa-lock"></i>Change Password</button>
        <button class="btn-ghost btn-sm" onclick="toggleUserTypeForm()"><i class="fas fa-user-tag"></i>Manage Role</button>
        <button class="btn-ghost btn-sm" onclick="toggleLimitsForm()"><i class="fas fa-sliders-h"></i>Custom Limits</button>
        <button class="btn-secondary btn-sm" onclick="loginAsUser()"><i class="fas fa-sign-in-alt"></i>Login As</button>
      </div>
      
      <div id="subForm" class="data-panel" style="display:none;padding:20px;margin-bottom:16px;">
        <h5 class="font-bold text-navy" style="font-size:15px;margin-bottom:16px;">Action</h5>
        <div class="form-grid">
            <div class="form-group"><label class="text-xs text-muted font-semibold">Amount (₦)</label><input type="number" class="form-control" id="amt" step="0.01"></div>
            <div class="form-group"><label class="text-xs text-muted font-semibold">Note</label><input type="text" class="form-control" id="note" placeholder="Reason for transaction"></div>
        </div>
        <button class="btn-primary" style="width:100%;justify-content:center;" id="subBtn" onclick="submitAction()">Submit</button>
      </div>

      <div id="passwordForm" class="data-panel" style="display:none;padding:20px;margin-bottom:16px;">
        <h5 class="font-bold text-navy" style="font-size:15px;margin-bottom:16px;">Change Password</h5>
        <div class="form-grid">
            <div class="form-group"><label class="text-xs text-muted font-semibold">Your Admin Password</label><input type="password" class="form-control" id="adminPw"></div>
            <div class="form-group"><label class="text-xs text-muted font-semibold">New User Password</label><input type="password" class="form-control" id="newPw"></div>
        </div>
        <button class="btn-primary" style="width:100%;justify-content:center;" onclick="changePassword()">Update Password</button>
      </div>
      
      <div id="userTypeForm" class="data-panel" style="display:none;padding:20px;margin-bottom:16px;">
        <h5 class="font-bold text-navy" style="font-size:15px;margin-bottom:16px;">Manage Customer Role</h5>
        <div class="flex gap-3">
            <select class="form-control" id="newUserType" style="flex:1;">
                <option value="regular" ${u.user_type==='regular'?'selected':''}>Regular User</option>
                <option value="agent" ${u.user_type==='agent'?'selected':''}>Agent</option>
                <option value="vendor" ${u.user_type==='vendor'?'selected':''}>Vendor</option>
            </select>
            <button class="btn-primary" onclick="changeUserType()">Update Role</button>
        </div>
      </div>

      <div id="limitsForm" class="data-panel" style="display:none;padding:20px;margin-bottom:16px;">
        <h5 class="font-bold text-navy" style="font-size:15px;margin-bottom:8px;">Custom Override Limits</h5>
        <p class="text-xs text-muted" style="margin-bottom:16px;">Leave blank to use global settings.</p>
        <div class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));">
            <div class="form-group"><label class="text-xs text-muted font-semibold">Total Daily Limit (₦)</label><input type="number" class="form-control" id="customDaily" value="${u.custom_daily_limit||''}" placeholder="e.g. 50000"></div>
            <div class="form-group"><label class="text-xs text-muted font-semibold">Airtime Daily Limit (₦)</label><input type="number" class="form-control" id="customAirtime" value="${u.custom_airtime_limit||''}" placeholder="e.g. 10000"></div>
            <div class="form-group"><label class="text-xs text-muted font-semibold">Transfer Daily Limit (₦)</label><input type="number" class="form-control" id="customTransfer" value="${u.custom_transfer_limit||''}" placeholder="e.g. 200000"></div>
        </div>
        <button class="btn-primary" style="width:100%;justify-content:center;" onclick="saveCustomLimits()">Save Limits</button>
      </div>
      
      <div id="deleteForm" style="display:none;background:rgba(239,68,68,0.06);padding:20px;border-radius:12px;border:1px solid rgba(239,68,68,0.2);margin-bottom:16px;">
        <h5 style="margin-bottom:8px;font-size:15px;font-weight:800;color:var(--danger);"><i class="fas fa-exclamation-triangle" style="margin-right:6px;"></i>Danger Zone: Delete Customer</h5>
        <p style="font-size:13px;color:var(--danger);margin-bottom:16px;">This action is permanent and cannot be undone. All associated data (transactions, wallets, virtual accounts) will be permanently destroyed.</p>
        <div class="form-group" style="margin-bottom:16px;">
            <label style="font-weight:600;color:var(--danger);font-size:12px;">Verify Your Admin Password to Confirm</label>
            <input type="password" class="form-control" id="deleteAdminPw" style="border-color:rgba(239,68,68,0.3);">
        </div>
        <button class="btn-primary" style="width:100%;justify-content:center;background:#DC2626;box-shadow:0 4px 10px rgba(220,38,38,0.2);" onclick="deleteUser()">Permanently Delete Customer</button>
      </div>
      
      <div id="suspendBtn" class="flex gap-3" style="margin-top:8px;">
        ${u.is_suspended==1 
          ? `<button class="btn-primary" style="flex:1;justify-content:center;background:var(--success);" onclick="unsuspend()"><i class="fas fa-check-circle" style="margin-right:8px;"></i>Reinstate Customer Account</button>` 
          : `<button class="btn-primary" style="flex:1;justify-content:center;background:var(--danger);" onclick="suspendPrompt()"><i class="fas fa-ban" style="margin-right:8px;"></i>Suspend Customer</button>`}
          
        <button class="btn-primary" style="flex:1;justify-content:center;background:#DC2626;" onclick="toggleDeleteForm()"><i class="fas fa-trash-alt" style="margin-right:8px;"></i>Delete Customer</button>
      </div>
    </div>
  `;
  
  document.getElementById('detailInfo').innerHTML = html;

  const tbody=document.getElementById('txnBody');
  if(r.transactions&&r.transactions.length){tbody.innerHTML=r.transactions.map(t=>'<tr><td>'+esc(t.reference||'-')+'</td><td>'+esc(t.category||'-')+'</td><td>'+fmtMoney(t.amount)+'</td><td><span class="badge '+(t.status==='success'?'badge-success':t.status==='pending'?'badge-warning':'badge-danger')+'">'+esc(t.status||'-')+'</span></td><td>'+fmtDate(t.created_at)+'</td></tr>').join('')}else{tbody.innerHTML='<tr><td colspan="5" class="empty-state">No transactions</td></tr>'}
  const lbody=document.getElementById('loginBody');
  if(r.logins&&r.logins.length){lbody.innerHTML=r.logins.map(l=>'<tr><td>'+esc(l.ip_address||'-')+'</td><td>'+esc(l.user_agent||'-')+'</td><td>'+fmtDate(l.login_at)+'</td></tr>').join('')}else{lbody.innerHTML='<tr><td colspan="3" class="empty-state">No login records</td></tr>'}
  document.getElementById('detailLoading').style.display='none';document.getElementById('detailContent').style.display='block';
}

function togglePasswordForm(){const f=document.getElementById('passwordForm');f.style.display=f.style.display==='none'?'block':'none';document.getElementById('deleteForm').style.display='none';document.getElementById('userTypeForm').style.display='none';document.getElementById('limitsForm').style.display='none';if(f.style.display==='block'){document.getElementById('adminPw').value='';document.getElementById('newPw').value='';document.getElementById('adminPw').focus();}}
function toggleDeleteForm(){const f=document.getElementById('deleteForm');f.style.display=f.style.display==='none'?'block':'none';document.getElementById('passwordForm').style.display='none';document.getElementById('userTypeForm').style.display='none';document.getElementById('limitsForm').style.display='none';if(f.style.display==='block'){document.getElementById('deleteAdminPw').value='';document.getElementById('deleteAdminPw').focus();}}
function toggleUserTypeForm(){const f=document.getElementById('userTypeForm');f.style.display=f.style.display==='none'?'block':'none';document.getElementById('passwordForm').style.display='none';document.getElementById('deleteForm').style.display='none';document.getElementById('limitsForm').style.display='none';}
function toggleLimitsForm(){const f=document.getElementById('limitsForm');f.style.display=f.style.display==='none'?'block':'none';document.getElementById('passwordForm').style.display='none';document.getElementById('deleteForm').style.display='none';document.getElementById('userTypeForm').style.display='none';}

async function changePassword(){const ap=document.getElementById('adminPw').value;const np=document.getElementById('newPw').value;if(!ap)return showToast('Enter your admin password',false);if(!np||np.length<6)return showToast('New password must be at least 6 characters',false);const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','change_password');fd.append('uid',detailUserId);fd.append('admin_password',ap);fd.append('new_password',np);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success){document.getElementById('passwordForm').style.display='none';document.getElementById('adminPw').value='';document.getElementById('newPw').value='';}}

async function deleteUser(){const ap=document.getElementById('deleteAdminPw').value;if(!ap)return showToast('Enter your admin password to confirm deletion',false);if(!confirm('CRITICAL WARNING: This will permanently delete the user and ALL related records. Are you absolutely sure?'))return;const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','delete_user');fd.append('uid',detailUserId);fd.append('admin_password',ap);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),1000);}

async function changeUserType(){const nt=document.getElementById('newUserType').value;if(nt===curUser.user_type)return showToast('Role is already set to '+nt,false);if(!confirm('Change user role to "'+nt+'"?'))return;const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','set_user_type');fd.append('uid',detailUserId);fd.append('user_type',nt);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);}

async function saveCustomLimits(){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','set_custom_limits');fd.append('uid',detailUserId);fd.append('custom_daily',document.getElementById('customDaily').value);fd.append('custom_airtime',document.getElementById('customAirtime').value);fd.append('custom_transfer',document.getElementById('customTransfer').value);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>loadUserDetail(detailUserId),900);}

function toggleAllCheckboxes(source) {
  const checkboxes = document.querySelectorAll('.u-checkbox');
  checkboxes.forEach(cb => cb.checked = source.checked);
}

async function loginAsUser() {
  if(!confirm('You are about to log in as ' + curUser.full_name + '. Continue?')) return;
  const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','login_as');fd.append('uid',detailUserId);
  const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
  showToast(r.message,r.success);
  if(r.success) setTimeout(()=>window.location.href='/user/dashboard.php',1000);
}

async function applyBulkAction() {
  const action = document.getElementById('bulkActionSelect').value;
  if(!action) return alert('Select an action first.');
  const checkboxes = document.querySelectorAll('.u-checkbox:checked');
  if(checkboxes.length === 0) return alert('Select at least one user.');
  
  let amount = 0;
  let adminPassword = '';
  
  if(action === 'credit' || action === 'debit') {
    amount = prompt('Enter the amount to ' + action + ' the selected users:');
    if(!amount || isNaN(amount) || amount <= 0) return alert('Invalid amount.');
  } else if(action === 'delete') {
    adminPassword = prompt('⚠️ PERMANENT DELETE — Enter your admin password to confirm:');
    if(!adminPassword) return alert('Admin password required for deletion.');
    if(!confirm('This will permanently delete ' + checkboxes.length + ' users and ALL their records. Are you absolutely sure?')) return;
  } else if(action === 'upgrade_vendor') {
    if(!confirm('Upgrade ' + checkboxes.length + ' user(s) to Vendor?')) return;
  } else if(action === 'downgrade_regular') {
    if(!confirm('Downgrade ' + checkboxes.length + ' user(s) to Regular?')) return;
  } else {
    if(!confirm('Are you sure you want to perform this bulk action?')) return;
  }
  
  const uids = Array.from(checkboxes).map(cb => cb.value);
  const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','bulk_action');
  fd.append('bulk_action',action);fd.append('uids',JSON.stringify(uids));
  if(amount) fd.append('amount',amount);
  if(adminPassword) fd.append('admin_password',adminPassword);
  
  const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
  showToast(r.message,r.success);
  if(r.success) setTimeout(()=>location.reload(),1000);
}
</script>
</body></html>

