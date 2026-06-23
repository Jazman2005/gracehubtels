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
            $u['wallet_balance'], $u['cashback_balance'], $u['kyc_level'], $u['user_type'],
            $u['is_suspended'] ? 'Suspended' : 'Active', $u['created_at']
        ]);
    }
    fclose($out);
    exit;
}
if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    $action=$_POST['action']??'';$uid=(int)($_POST['uid']??0);
    if($action==='suspend'){db()->prepare("UPDATE users SET is_suspended=1,suspension_reason=? WHERE id=?")->execute([sanitize($_POST['reason']??'Policy violation',255),$uid]);json_response(['success'=>true,'message'=>'User suspended']);}
    if($action==='unsuspend'){db()->prepare("UPDATE users SET is_suspended=0,suspension_reason=NULL WHERE id=?")->execute([$uid]);json_response(['success'=>true,'message'=>'User reinstated']);}
    if($action==='credit'){$amt=(float)($_POST['amount']??0);if($amt<=0)json_response(['success'=>false,'message'=>'Invalid amount']);$r=credit_wallet($uid,$amt,'admin_credit','Admin credit: '.sanitize($_POST['note']??'',200));json_response($r);}
    if($action==='debit'){$amt=(float)($_POST['amount']??0);if($amt<=0)json_response(['success'=>false,'message'=>'Invalid amount']);$r=debit_wallet($uid,$amt,'admin_debit','Admin debit: '.sanitize($_POST['note']??'',200));if($r['success'])update_tx_status($r['ref'],'success');json_response($r);}
    if($action==='reset_pin'){db()->prepare("UPDATE users SET transaction_pin=NULL,pin_attempts=0,pin_locked_until=NULL WHERE id=?")->execute([$uid]);notify_user($uid,'🔓 PIN Reset','Your transaction PIN has been reset by admin. Set a new PIN.','info');json_response(['success'=>true,'message'=>'PIN reset']);}

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
            db()->prepare("DELETE FROM api_credentials WHERE user_id=?")->execute([$uid]);
            db()->prepare("DELETE FROM beneficiaries WHERE user_id=?")->execute([$uid]);
            // Delete the user (cascades where configured)
            db()->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
            db()->commit();
            json_response(['success'=>true,'message'=>'Customer account permanently deleted']);
        } catch(Exception $e) {
            db()->rollBack();
            json_response(['success'=>false,'message'=>'Failed to delete user: '.$e->getMessage()]);
        }
    }

    if($action==='get_user_detail'){
        $s=db()->prepare("SELECT * FROM users WHERE id=?");$s->execute([$uid]);$user=$s->fetch(PDO::FETCH_ASSOC);
        if(!$user)json_response(['success'=>false,'message'=>'User not found'],404);
        $ts=db()->prepare("SELECT * FROM transactions WHERE user_id=? ORDER BY id DESC LIMIT 10");$ts->execute([$uid]);$txns=$ts->fetchAll(PDO::FETCH_ASSOC);
        $ls=db()->prepare("SELECT * FROM login_tracker WHERE user_id=? ORDER BY id DESC LIMIT 5");$ls->execute([$uid]);$logins=$ls->fetchAll(PDO::FETCH_ASSOC);
        $vs=db()->prepare("SELECT bank_name, account_number, account_name FROM virtual_accounts WHERE user_id=?");$vs->execute([$uid]);$v_accs=$vs->fetchAll(PDO::FETCH_ASSOC);
        json_response(['success'=>true,'user'=>$user,'transactions'=>$txns,'logins'=>$logins,'virtual_accounts'=>$v_accs]);
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
        SUM(CASE WHEN kyc_level > 0 THEN 1 ELSE 0 END) as verified
    FROM users
")->fetch(PDO::FETCH_ASSOC);

?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Customers – MsjPay Admin</title><link rel="icon" type="image/png" href="/msjpay/assets/images/msjpay-favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="/msjpay/msj-admin/css/admin.css"><link rel="stylesheet" href="/msjpay/msj-admin/css/users-management.css"><style>.clickable-row{cursor:pointer}.clickable-row:hover{background:var(--bg)}.badge-vendor{background:#8B5CF6;color:#fff}.badge-regular{background:#6B7280;color:#fff}.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}.info-grid .field{padding:6px 0;border-bottom:1px solid var(--border)}.info-grid .field label{font-size:11px;color:var(--text-muted);display:block}.info-grid .field strong{font-size:13px}.detail-section{margin-bottom:20px}.detail-section h4{font-size:14px;font-weight:700;margin-bottom:10px;color:var(--text)}</style></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="page-content">
<div class="page-head" style="margin-bottom:24px;">
  <div>
    <h2 style="font-size:24px; font-weight:800; color:var(--navy-dark); letter-spacing:-0.5px;">Customer Management</h2>
    <p style="color:var(--text-muted); font-size:14px; margin-top:4px;">Manage your platform's customers, their wallets, and security.</p>
  </div>
  <a href="?export=csv&q=<?=urlencode($search)?>&filter=<?=$filter?>" class="btn-primary" style="background:#10B981;text-decoration:none;border-radius:10px;padding:10px 20px;"><svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2" style="margin-right:6px"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Export CSV</a>
</div>

<div class="stats-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:24px;">
  <div class="stat-card" style="background:#fff; border-radius:12px; padding:20px; border:1px solid var(--border); box-shadow:0 2px 10px rgba(0,0,0,0.02); display:flex; flex-direction:column; gap:8px;">
    <div style="font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Total Customers</div>
    <div style="font-size:28px; font-weight:900; color:var(--navy-dark);"><?=number_format((float)$stats['total'])?></div>
  </div>
  <div class="stat-card" style="background:#fff; border-radius:12px; padding:20px; border:1px solid var(--border); box-shadow:0 2px 10px rgba(0,0,0,0.02); display:flex; flex-direction:column; gap:8px;">
    <div style="font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Active</div>
    <div style="font-size:28px; font-weight:900; color:#10B981;"><?=number_format((float)$stats['active'])?></div>
  </div>
  <div class="stat-card" style="background:#fff; border-radius:12px; padding:20px; border:1px solid var(--border); box-shadow:0 2px 10px rgba(0,0,0,0.02); display:flex; flex-direction:column; gap:8px;">
    <div style="font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Suspended</div>
    <div style="font-size:28px; font-weight:900; color:#EF4444;"><?=number_format((float)$stats['suspended'])?></div>
  </div>
  <div class="stat-card" style="background:#fff; border-radius:12px; padding:20px; border:1px solid var(--border); box-shadow:0 2px 10px rgba(0,0,0,0.02); display:flex; flex-direction:column; gap:8px;">
    <div style="font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">KYC Verified</div>
    <div style="font-size:28px; font-weight:900; color:#3B82F6;"><?=number_format((float)$stats['verified'])?></div>
  </div>
</div>

<div class="card" style="padding:0; overflow:hidden; border-radius:16px;">
  <form class="search-bar" method="GET" style="padding:20px; border-bottom:1px solid var(--border); display:flex; flex-direction:column; gap:16px;">
    <div class="search-input-wrap" style="position:relative; width:100%;">
        <svg class="search-icon" viewBox="0 0 24 24" style="position:absolute; left:16px; top:50%; transform:translateY(-50%); width:20px; height:20px; stroke:var(--text-muted); fill:none; stroke-width:2;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" name="q" placeholder="Search by name, phone, email or username..." value="<?=htmlspecialchars($search)?>" style="width:100%; height:48px; padding-left:48px; border-radius:12px; border:1px solid var(--border); font-size:14px; font-family:'Sora',sans-serif; outline:none; transition:all 0.2s;">
    </div>
    <div class="filter-tabs" style="display:flex; gap:8px; flex-wrap:wrap;">
      <a href="?filter=" class="filter-tab <?=$filter===''?'active':''?>" style="padding:8px 16px; border-radius:20px; font-size:13px; font-weight:600; text-decoration:none; border:1px solid var(--border); color:<?=$filter===''?'#fff':'var(--text-muted)'?>; background:<?=$filter===''?'var(--primary)':'#fff'?>; transition:all 0.2s;">All Customers</a>
      <a href="?filter=active" class="filter-tab <?=$filter==='active'?'active':''?>" style="padding:8px 16px; border-radius:20px; font-size:13px; font-weight:600; text-decoration:none; border:1px solid var(--border); color:<?=$filter==='active'?'#fff':'var(--text-muted)'?>; background:<?=$filter==='active'?'#10B981':'#fff'?>; border-color:<?=$filter==='active'?'#10B981':'var(--border)'?>; transition:all 0.2s;">Active</a>
      <a href="?filter=suspended" class="filter-tab <?=$filter==='suspended'?'active':''?>" style="padding:8px 16px; border-radius:20px; font-size:13px; font-weight:600; text-decoration:none; border:1px solid var(--border); color:<?=$filter==='suspended'?'#fff':'var(--text-muted)'?>; background:<?=$filter==='suspended'?'#EF4444':'#fff'?>; border-color:<?=$filter==='suspended'?'#EF4444':'var(--border)'?>; transition:all 0.2s;">Suspended</a>
      <a href="?filter=kyc1" class="filter-tab <?=$filter==='kyc1'?'active':''?>" style="padding:8px 16px; border-radius:20px; font-size:13px; font-weight:600; text-decoration:none; border:1px solid var(--border); color:<?=$filter==='kyc1'?'#fff':'var(--text-muted)'?>; background:<?=$filter==='kyc1'?'#3B82F6':'#fff'?>; border-color:<?=$filter==='kyc1'?'#3B82F6':'var(--border)'?>; transition:all 0.2s;">KYC Verified</a>
      <a href="?filter=no_kyc" class="filter-tab <?=$filter==='no_kyc'?'active':''?>" style="padding:8px 16px; border-radius:20px; font-size:13px; font-weight:600; text-decoration:none; border:1px solid var(--border); color:<?=$filter==='no_kyc'?'#fff':'var(--text-muted)'?>; background:<?=$filter==='no_kyc'?'#F59E0B':'#fff'?>; border-color:<?=$filter==='no_kyc'?'#F59E0B':'var(--border)'?>; transition:all 0.2s;">No KYC</a>
    </div>
  </form>
  <div class="table-wrap">
    <table class="data-table" style="width:100%; border-collapse:collapse; margin:0;">
      <thead><tr style="background:#F8FAFC; border-bottom:1px solid var(--border);">
          <th style="padding:16px 20px; font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Customer</th>
          <th style="padding:16px 20px; font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Balances</th>
          <th style="padding:16px 20px; font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Identity</th>
          <th style="padding:16px 20px; font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Status</th>
          <th style="padding:16px 20px; font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Joined</th>
          <th style="padding:16px 20px; text-align:right;"></th>
      </tr></thead>
      <tbody>
      <?php foreach($data['data'] as $u):?>
      <tr class="clickable-row" onclick="openDetailModal(<?=$u['id']?>)" style="border-bottom:1px solid var(--border); transition:all 0.2s;">
        <td style="padding:16px 20px;">
            <div class="user-cell" style="display:flex; align-items:center; gap:12px;">
                <div class="u-avatar" style="width:40px; height:40px; border-radius:10px; background:linear-gradient(135deg, var(--primary) 0%, #4F46E5 100%); color:#fff; display:flex; align-items:center; justify-content:center; font-size:16px; font-weight:800; box-shadow:0 4px 10px rgba(99,102,241,0.2);"><?=strtoupper(substr($u['full_name'],0,1))?></div>
                <div>
                    <div style="font-weight:700; color:var(--navy-dark); font-size:14px;"><?=htmlspecialchars($u['full_name'])?></div>
                    <div style="font-size:12px; color:var(--text-muted); margin-top:2px;"><?=htmlspecialchars($u['phone'])?> • <?=htmlspecialchars($u['username'] ?: 'No username')?></div>
                </div>
            </div>
        </td>
        <td style="padding:16px 20px;">
            <div style="font-weight:800; color:var(--navy-dark); font-size:14px;">₦<?=number_format((float)$u['wallet_balance'],2)?></div>
            <div style="font-size:12px; color:var(--success); font-weight:600; margin-top:2px;">+ ₦<?=number_format((float)$u['cashback_balance'],2)?> CB</div>
        </td>
        <td style="padding:16px 20px;">
            <div style="display:flex; flex-direction:column; gap:6px; align-items:flex-start;">
                <span class="badge" style="padding:4px 8px; border-radius:6px; font-size:11px; font-weight:700; background:<?=$u['kyc_level']>0?'#DBEAFE':'#F3F4F6'?>; color:<?=$u['kyc_level']>0?'#2563EB':'#6B7280'?>;">Tier <?=$u['kyc_level']?></span>
                <span class="badge" style="padding:4px 8px; border-radius:6px; font-size:11px; font-weight:700; background:<?=$u['user_type']==='vendor'?'#F3E8FF':'#F3F4F6'?>; color:<?=$u['user_type']==='vendor'?'#9333EA':'#6B7280'?>; text-transform:uppercase;"><?=htmlspecialchars($u['user_type']??'regular')?></span>
            </div>
        </td>
        <td style="padding:16px 20px;">
            <span class="badge" style="padding:6px 12px; border-radius:20px; font-size:12px; font-weight:700; background:<?=$u['is_suspended']?'#FEE2E2':'#DCFCE7'?>; color:<?=$u['is_suspended']?'#EF4444':'#10B981'?>;">
                <i class="fas <?=$u['is_suspended']?'fa-ban':'fa-check-circle'?>" style="margin-right:4px;"></i><?=$u['is_suspended']?'Suspended':'Active'?>
            </span>
        </td>
        <td style="padding:16px 20px; font-size:13px; color:var(--text-muted); font-weight:500;">
            <?=date('d M Y',strtotime($u['created_at']))?><br>
            <span style="font-size:11px;"><?=date('h:i A',strtotime($u['created_at']))?></span>
        </td>
        <td style="padding:16px 20px; text-align:right;">
            <button class="btn-sm btn-primary" style="background:var(--bg); color:var(--navy-dark); border:1px solid var(--border); box-shadow:0 2px 4px rgba(0,0,0,0.02); transition:all 0.2s;" onclick="event.stopPropagation();openDetailModal(<?=$u['id']?>)" onmouseover="this.style.background='var(--primary)'; this.style.color='#fff';" onmouseout="this.style.background='var(--bg)'; this.style.color='var(--navy-dark)';">Manage</button>
        </td>
      </tr>
      <?php endforeach;if(empty($data['data'])):?><tr><td colspan="6" class="empty-state" style="padding:40px; text-align:center; color:var(--text-muted);">No customers found matching your criteria.</td></tr><?php endif;?>
      </tbody>
    </table>
  </div>
  <?php if($data['pages']>1):?>
  <div class="pagination"><span class="page-info">Page <?=$page?> of <?=$data['pages']?></span>
  <?php for($i=max(1,$page-2);$i<=min($data['pages'],$page+2);$i++):?><a href="?page=<?=$i?>&q=<?=urlencode($search)?>&filter=<?=$filter?>" class="page-btn <?=$i===$page?'active':''?>"><?=$i?></a><?php endfor;?>
  </div>
  <?php endif;?>
</div>
</div></div>

<!-- Detail Modal -->
<div class="modal-overlay" id="detailModalOvl" style="display:none; backdrop-filter:blur(4px);">
  <div class="modal" style="max-width:850px; border-radius:24px; overflow:hidden; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
    <div class="modal-header" style="background:#F8FAFC; border-bottom:1px solid #E2E8F0; padding:24px 32px; display:flex; justify-content:space-between; align-items:center;">
        <h3 id="detailTitle" style="font-size:20px; font-weight:800; color:var(--navy-dark); margin:0;">Customer Details</h3>
        <button class="modal-close" onclick="closeDetailModal()" style="background:#fff; border:1px solid #E2E8F0; border-radius:50%; width:36px; height:36px; cursor:pointer; font-size:16px; color:var(--text-muted); transition:all 0.2s; display:flex; align-items:center; justify-content:center; box-shadow:0 2px 4px rgba(0,0,0,0.02);" onmouseover="this.style.background='#FEE2E2'; this.style.color='#EF4444'; this.style.borderColor='#FEE2E2';" onmouseout="this.style.background='#fff'; this.style.color='var(--text-muted)'; this.style.borderColor='#E2E8F0';"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div id="detailLoading" style="text-align:center;padding:40px 0;color:var(--text-muted);font-size:14px">Loading user details...</div>
      <div id="detailContent" style="display:none">
        <div id="detailInfo" style="margin-bottom: 20px;"></div>
        <div class="detail-section">
          <h4>Recent Transactions (Last 10)</h4>
          <div class="table-wrap"><table class="data-table"><thead><tr><th>Ref</th><th>Category</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead><tbody id="txnBody"></tbody></table></div>
        </div>
        <div class="detail-section">
          <h4>Login History (Last 5)</h4>
          <div class="table-wrap"><table class="data-table"><thead><tr><th>IP Address</th><th>User Agent</th><th>Date</th></tr></thead><tbody id="loginBody"></tbody></table></div>
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
    <div style="display:flex; align-items:center; gap:16px;">
        <div style="width:48px; height:48px; border-radius:14px; background:linear-gradient(135deg, var(--primary) 0%, #4F46E5 100%); color:#fff; display:flex; align-items:center; justify-content:center; font-size:20px; font-weight:800; box-shadow:0 4px 12px rgba(99,102,241,0.3);">${strToUpper(u.full_name).substring(0,1)}</div>
        <div>
            <div style="font-size:22px; font-weight:800; color:var(--navy-dark);">${esc(u.full_name)}</div>
            <div style="font-size:13px; color:var(--text-muted); font-weight:600; margin-top:2px;">ID: #${u.id} • ${esc(u.username||'No Username')}</div>
        </div>
    </div>
  `;
  
  let html = `
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:16px;margin-bottom:24px;">
      <div style="background:#fff;padding:20px;border-radius:16px;border:1px solid #E2E8F0;box-shadow:0 4px 6px -1px rgba(0,0,0,0.02);">
        <div style="display:flex; justify-content:space-between; align-items:flex-start;">
            <div>
                <div style="font-size:12px;color:var(--text-muted);text-transform:uppercase;font-weight:700;letter-spacing:0.5px;">Wallet Balance</div>
                <div style="font-size:28px;font-weight:900;color:var(--navy-dark);margin-top:8px;">${fmtMoney(u.wallet_balance)}</div>
            </div>
            <div style="width:40px;height:40px;border-radius:10px;background:#DBEAFE;color:#2563EB;display:flex;align-items:center;justify-content:center;font-size:18px;"><i class="fas fa-wallet"></i></div>
        </div>
      </div>
      <div style="background:#fff;padding:20px;border-radius:16px;border:1px solid #E2E8F0;box-shadow:0 4px 6px -1px rgba(0,0,0,0.02);">
        <div style="display:flex; justify-content:space-between; align-items:flex-start;">
            <div>
                <div style="font-size:12px;color:var(--text-muted);text-transform:uppercase;font-weight:700;letter-spacing:0.5px;">Cashback</div>
                <div style="font-size:28px;font-weight:900;color:#10B981;margin-top:8px;">${fmtMoney(u.cashback_balance)}</div>
            </div>
            <div style="width:40px;height:40px;border-radius:10px;background:#D1FAE5;color:#059669;display:flex;align-items:center;justify-content:center;font-size:18px;"><i class="fas fa-gift"></i></div>
        </div>
      </div>
    </div>
    
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-bottom:24px;">
        <div class="detail-section" style="background:#F8FAFC; padding:24px; border-radius:16px; border:1px solid #E2E8F0;">
          <h4 style="font-size:15px; font-weight:800; color:var(--navy-dark); margin-bottom:16px; display:flex; align-items:center; gap:8px;"><i class="fas fa-id-card" style="color:var(--primary)"></i> Personal Profile</h4>
          <div class="info-grid" style="gap:16px;">
            <div class="field" style="border:none; padding:0;"><label style="font-size:12px; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Phone Number</label><strong style="font-size:14px; color:var(--navy-dark);">${esc(u.phone)}</strong></div>
            <div class="field" style="border:none; padding:0;"><label style="font-size:12px; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Email Address</label><strong style="font-size:14px; color:var(--navy-dark);">${esc(u.email||'-')} ${u.email_verified==1?'<span style="color:#10B981;font-size:12px;margin-left:4px;"><i class="fas fa-check-circle"></i></span>':''}</strong></div>
            <div class="field" style="border:none; padding:0;"><label style="font-size:12px; color:var(--text-muted); font-weight:600; margin-bottom:4px;">KYC Level</label><strong style="font-size:14px; color:var(--navy-dark);">Tier ${u.kyc_level||0}</strong></div>
            <div class="field" style="border:none; padding:0;"><label style="font-size:12px; color:var(--text-muted); font-weight:600; margin-bottom:4px;">BVN / NIN</label><strong style="font-size:14px; color:var(--navy-dark);">${esc(u.bvn||u.nin||'-')}</strong></div>
          </div>
        </div>
        
        <div class="detail-section" style="background:#F8FAFC; padding:24px; border-radius:16px; border:1px solid #E2E8F0;">
          <h4 style="font-size:15px; font-weight:800; color:var(--navy-dark); margin-bottom:16px; display:flex; align-items:center; gap:8px;"><i class="fas fa-shield-alt" style="color:var(--primary)"></i> Account Status</h4>
          <div class="info-grid" style="gap:16px;">
            <div class="field" style="border:none; padding:0;"><label style="font-size:12px; color:var(--text-muted); font-weight:600; margin-bottom:4px;">User Type</label><strong><span class="badge" style="background:${u.user_type==='vendor'?'#F3E8FF':u.user_type==='agent'?'#FEF3C7':'#E0E7FF'};color:${u.user_type==='vendor'?'#9333EA':u.user_type==='agent'?'#D97706':'#4F46E5'};padding:4px 10px;border-radius:6px;font-size:12px;text-transform:capitalize;">${esc(u.user_type)}</span></strong></div>
            <div class="field" style="border:none; padding:0;"><label style="font-size:12px; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Status</label><strong><span class="badge" style="background:${u.is_suspended?'#FEE2E2':'#DCFCE7'};color:${u.is_suspended?'#EF4444':'#10B981'};padding:4px 10px;border-radius:6px;font-size:12px;">${u.is_suspended?'Suspended':'Active'}</span></strong></div>
            <div class="field" style="border:none; padding:0;"><label style="font-size:12px; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Joined On</label><strong style="font-size:14px; color:var(--navy-dark);">${fmtDate(u.created_at)}</strong></div>
            <div class="field" style="border:none; padding:0;"><label style="font-size:12px; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Last Login</label><strong style="font-size:14px; color:var(--navy-dark);">${fmtDate(u.last_login)}</strong></div>
          </div>
        </div>
    </div>
  `;
  
  if (r.virtual_accounts && r.virtual_accounts.length > 0) {
    html += `<div class="detail-section" style="margin-bottom:32px;">
      <h4 style="font-size:16px; font-weight:800; color:var(--navy-dark); margin-bottom:16px;">Assigned Virtual Accounts</h4>
      <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:16px;">`;
    r.virtual_accounts.forEach(va => {
      html += `<div style="background:#fff; padding:20px; border-radius:16px; border:1px solid #E2E8F0; box-shadow:0 4px 6px -1px rgba(0,0,0,0.02); position:relative; overflow:hidden;">
        <div style="position:absolute; top:0; left:0; width:4px; height:100%; background:var(--primary);"></div>
        <div style="font-size:13px; color:var(--text-muted); font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px;">${esc(va.bank_name)}</div>
        <div style="font-size:22px; font-weight:900; color:var(--navy-dark); letter-spacing:1px; margin-bottom:6px;">${esc(va.account_number)}</div>
        <div style="font-size:12px; color:var(--text-muted); font-weight:600;">${esc(va.account_name)}</div>
      </div>`;
    });
    html += `</div></div>`;
  }
  
  html += `
    <div class="detail-section" style="background:#fff; border:1px solid #E2E8F0; border-radius:16px; padding:24px; box-shadow:0 4px 6px -1px rgba(0,0,0,0.02);">
      <h4 style="font-size:16px; font-weight:800; color:var(--navy-dark); margin-bottom:20px;">Quick Actions</h4>
      <div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:16px;">
        <button class="btn-primary" style="background:#10B981; border:none; padding:10px 16px; border-radius:10px; font-weight:600; box-shadow:0 4px 10px rgba(16,185,129,0.2);" onclick="showSub('credit')"><i class="fas fa-plus-circle" style="margin-right:6px;"></i>Credit Wallet</button>
        <button class="btn-primary" style="background:#EF4444; border:none; padding:10px 16px; border-radius:10px; font-weight:600; box-shadow:0 4px 10px rgba(239,68,68,0.2);" onclick="showSub('debit')"><i class="fas fa-minus-circle" style="margin-right:6px;"></i>Debit Wallet</button>
        <button class="btn-primary" style="background:#F59E0B; border:none; padding:10px 16px; border-radius:10px; font-weight:600; box-shadow:0 4px 10px rgba(245,158,11,0.2);" onclick="resetPin()"><i class="fas fa-key" style="margin-right:6px;"></i>Reset PIN</button>
        <button class="btn-primary" style="background:var(--bg); color:var(--navy-dark); border:1px solid var(--border); padding:10px 16px; border-radius:10px; font-weight:600;" onclick="togglePasswordForm()"><i class="fas fa-lock" style="margin-right:6px;"></i>Change Password</button>
        <button class="btn-primary" style="background:#F3E8FF; color:#9333EA; border:none; padding:10px 16px; border-radius:10px; font-weight:600;" onclick="toggleUserTypeForm()"><i class="fas fa-user-tag" style="margin-right:6px;"></i>Manage Role</button>
        <button class="btn-primary" style="background:#E0E7FF; color:#4F46E5; border:none; padding:10px 16px; border-radius:10px; font-weight:600;" onclick="toggleLimitsForm()"><i class="fas fa-sliders-h" style="margin-right:6px;"></i>Custom Limits</button>
      </div>
      
      <div id="subForm" style="display:none;background:#F8FAFC;padding:20px;border-radius:12px;border:1px solid #E2E8F0;margin-bottom:16px;">
        <h5 id="subFormTitle" style="margin-bottom:16px;font-size:15px;font-weight:800;color:var(--navy-dark);">Action</h5>
        <div style="display:grid; grid-template-columns:1fr 2fr; gap:16px;">
            <div class="form-group"><label style="font-weight:600;color:var(--text-muted);font-size:12px;">Amount (₦)</label><input type="number" class="form-control" id="amt" step="0.01" style="border-radius:10px;height:44px;"></div>
            <div class="form-group"><label style="font-weight:600;color:var(--text-muted);font-size:12px;">Note</label><input type="text" class="form-control" id="note" placeholder="Reason for transaction" style="border-radius:10px;height:44px;"></div>
        </div>
        <button class="btn-primary" style="width:100%;justify-content:center;margin-top:16px;border-radius:10px;height:44px;" id="subBtn" onclick="submitAction()">Submit</button>
      </div>

      <div id="passwordForm" style="display:none;background:#F8FAFC;padding:20px;border-radius:12px;border:1px solid #E2E8F0;margin-bottom:16px;">
        <h5 style="margin-bottom:16px;font-size:15px;font-weight:800;color:var(--navy-dark);">Change Password</h5>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
            <div class="form-group"><label style="font-weight:600;color:var(--text-muted);font-size:12px;">Your Admin Password</label><input type="password" class="form-control" id="adminPw" style="border-radius:10px;height:44px;"></div>
            <div class="form-group"><label style="font-weight:600;color:var(--text-muted);font-size:12px;">New User Password</label><input type="password" class="form-control" id="newPw" style="border-radius:10px;height:44px;"></div>
        </div>
        <button class="btn-primary" style="width:100%;justify-content:center;margin-top:16px;border-radius:10px;height:44px;" onclick="changePassword()">Update Password</button>
      </div>
      
      <div id="userTypeForm" style="display:none;background:#F8FAFC;padding:20px;border-radius:12px;border:1px solid #E2E8F0;margin-bottom:16px;">
        <h5 style="margin-bottom:16px;font-size:15px;font-weight:800;color:var(--navy-dark);">Manage Customer Role</h5>
        <div style="display:flex; gap:16px;">
            <select class="form-control" id="newUserType" style="border-radius:10px;height:44px;flex:1;">
                <option value="regular" ${u.user_type==='regular'?'selected':''}>Regular User</option>
                <option value="agent" ${u.user_type==='agent'?'selected':''}>Agent</option>
                <option value="vendor" ${u.user_type==='vendor'?'selected':''}>Vendor</option>
            </select>
            <button class="btn-primary" style="justify-content:center;border-radius:10px;height:44px;padding:0 24px;" onclick="changeUserType()">Update Role</button>
        </div>
      </div>

      <div id="limitsForm" style="display:none;background:#F8FAFC;padding:20px;border-radius:12px;border:1px solid #E2E8F0;margin-bottom:16px;">
        <h5 style="margin-bottom:8px;font-size:15px;font-weight:800;color:var(--navy-dark);">Custom Override Limits</h5>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:16px;">Leave blank to use global settings.</p>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px;">
            <div class="form-group"><label style="font-weight:600;color:var(--text-muted);font-size:12px;">Total Daily Limit (₦)</label><input type="number" class="form-control" id="customDaily" value="${u.custom_daily_limit||''}" placeholder="e.g. 50000" style="border-radius:10px;height:44px;"></div>
            <div class="form-group"><label style="font-weight:600;color:var(--text-muted);font-size:12px;">Airtime Daily Limit (₦)</label><input type="number" class="form-control" id="customAirtime" value="${u.custom_airtime_limit||''}" placeholder="e.g. 10000" style="border-radius:10px;height:44px;"></div>
            <div class="form-group"><label style="font-weight:600;color:var(--text-muted);font-size:12px;">Transfer Daily Limit (₦)</label><input type="number" class="form-control" id="customTransfer" value="${u.custom_transfer_limit||''}" placeholder="e.g. 200000" style="border-radius:10px;height:44px;"></div>
        </div>
        <button class="btn-primary" style="width:100%;justify-content:center;margin-top:16px;border-radius:10px;height:44px;" onclick="saveCustomLimits()">Save Limits</button>
      </div>
      
      <div id="deleteForm" style="display:none;background:#FEF2F2;padding:20px;border-radius:12px;border:1px solid #FECACA;margin-bottom:16px;">
        <h5 style="margin-bottom:8px;font-size:15px;font-weight:800;color:#991B1B;"><i class="fas fa-exclamation-triangle" style="margin-right:6px;"></i>Danger Zone: Delete Customer</h5>
        <p style="font-size:13px;color:#B91C1C;margin-bottom:16px;">This action is permanent and cannot be undone. All associated data (transactions, wallets, virtual accounts) will be permanently destroyed.</p>
        <div class="form-group" style="margin-bottom:16px;">
            <label style="font-weight:600;color:#991B1B;font-size:12px;">Verify Your Admin Password to Confirm</label>
            <input type="password" class="form-control" id="deleteAdminPw" style="border-radius:10px;height:44px;border-color:#FCA5A5;">
        </div>
        <button class="btn-primary" style="width:100%;justify-content:center;border-radius:10px;height:44px;background:#DC2626;box-shadow:0 4px 10px rgba(220,38,38,0.2);" onclick="deleteUser()">Permanently Delete Customer</button>
      </div>
      
      <div id="suspendBtn" style="margin-top:8px; display:flex; gap:12px;">
        ${u.is_suspended==1 
          ? `<button class="btn-primary" style="flex:1;justify-content:center;background:#10B981;border:none;border-radius:10px;height:44px;font-weight:600;" onclick="unsuspend()"><i class="fas fa-check-circle" style="margin-right:8px;"></i>Reinstate Customer Account</button>` 
          : `<button class="btn-primary" style="flex:1;justify-content:center;background:#F97316;border:none;border-radius:10px;height:44px;font-weight:600;" onclick="suspendPrompt()"><i class="fas fa-ban" style="margin-right:8px;"></i>Suspend Customer</button>`}
          
        <button class="btn-primary" style="flex:1;justify-content:center;background:#DC2626;border:none;border-radius:10px;height:44px;font-weight:600;" onclick="toggleDeleteForm()"><i class="fas fa-trash-alt" style="margin-right:8px;"></i>Delete Customer</button>
      </div>
    </div>
  `;
  
  document.getElementById('detailInfo').innerHTML = html;

  const tbody=document.getElementById('txnBody');
  if(r.transactions&&r.transactions.length){tbody.innerHTML=r.transactions.map(t=>'<tr><td style="font-size:11px">'+esc(t.reference||'-')+'</td><td>'+esc(t.category||'-')+'</td><td>'+fmtMoney(t.amount)+'</td><td><span class="badge '+(t.status==='success'?'badge-success':t.status==='pending'?'badge-warning':'badge-danger')+'">'+esc(t.status||'-')+'</span></td><td style="font-size:12px;color:#6B7280">'+fmtDate(t.created_at)+'</td></tr>').join('')}else{tbody.innerHTML='<tr><td colspan="5" class="empty-state">No transactions</td></tr>'}
  const lbody=document.getElementById('loginBody');
  if(r.logins&&r.logins.length){lbody.innerHTML=r.logins.map(l=>'<tr><td style="font-size:12px">'+esc(l.ip_address||'-')+'</td><td style="font-size:11px;color:#6B7280;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="'+esc(l.user_agent||'')+'">'+esc(l.user_agent||'-')+'</td><td style="font-size:12px;color:#6B7280">'+fmtDate(l.login_at)+'</td></tr>').join('')}else{lbody.innerHTML='<tr><td colspan="3" class="empty-state">No login records</td></tr>'}
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
</script>
</body></html>
