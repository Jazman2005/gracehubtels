<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_admin();
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
        if(!in_array($type,['regular','vendor']))json_response(['success'=>false,'message'=>'Invalid type']);
        db()->prepare("UPDATE users SET user_type=? WHERE id=?")->execute([$type,$uid]);
        json_response(['success'=>true,'message'=>'User type updated to '.$type]);
    }

    if($action==='get_user_detail'){
        $s=db()->prepare("SELECT * FROM users WHERE id=?");$s->execute([$uid]);$user=$s->fetch(PDO::FETCH_ASSOC);
        if(!$user)json_response(['success'=>false,'message'=>'User not found'],404);
        $ts=db()->prepare("SELECT * FROM transactions WHERE user_id=? ORDER BY id DESC LIMIT 10");$ts->execute([$uid]);$txns=$ts->fetchAll(PDO::FETCH_ASSOC);
        $ls=db()->prepare("SELECT * FROM login_tracker WHERE user_id=? ORDER BY id DESC LIMIT 5");$ls->execute([$uid]);$logins=$ls->fetchAll(PDO::FETCH_ASSOC);
        json_response(['success'=>true,'user'=>$user,'transactions'=>$txns,'logins'=>$logins]);
    }

    json_response(['success'=>false],400);
}
$csrf=admin_csrf();$page=max(1,(int)($_GET['page']??1));$search=trim($_GET['q']??'');$filter=$_GET['filter']??'';
$data=get_users_list($page,20,$search,$filter);
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Users – MsjPay Admin</title><link rel="icon" type="image/png" href="/msjpay/assets/images/msjpay-favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="/msjpay/msj-admin/css/admin.css"><link rel="stylesheet" href="/msjpay/msj-admin/css/users-management.css"><style>.clickable-row{cursor:pointer}.clickable-row:hover{background:var(--bg)}.badge-vendor{background:#8B5CF6;color:#fff}.badge-regular{background:#6B7280;color:#fff}.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}.info-grid .field{padding:6px 0;border-bottom:1px solid var(--border)}.info-grid .field label{font-size:11px;color:var(--text-muted);display:block}.info-grid .field strong{font-size:13px}.detail-section{margin-bottom:20px}.detail-section h4{font-size:14px;font-weight:700;margin-bottom:10px;color:var(--text)}</style></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="page-content">
<div class="page-head"><div><h2>Users</h2><p><?=number_format($data['total'])?> total registered users</p></div></div>

<div class="card">
  <form class="search-bar" method="GET">
    <div class="search-input-wrap"><svg class="search-icon" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg><input type="text" name="q" placeholder="Search name, phone, username..." value="<?=htmlspecialchars($search)?>"></div>
    <div class="filter-tabs">
      <a href="?filter=" class="filter-tab <?=$filter===''?'active':''?>">All</a>
      <a href="?filter=active" class="filter-tab <?=$filter==='active'?'active':''?>">Active</a>
      <a href="?filter=suspended" class="filter-tab <?=$filter==='suspended'?'active':''?>">Suspended</a>
      <a href="?filter=kyc1" class="filter-tab <?=$filter==='kyc1'?'active':''?>">KYC Verified</a>
      <a href="?filter=no_kyc" class="filter-tab <?=$filter==='no_kyc'?'active':''?>">No KYC</a>
    </div>
  </form>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>User</th><th>Wallet</th><th>Cashback</th><th>KYC</th><th>Type</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($data['data'] as $u):?>
      <tr class="clickable-row" onclick="openDetailModal(<?=$u['id']?>)">
        <td><div class="user-cell"><div class="u-avatar"><?=strtoupper(substr($u['full_name'],0,1))?></div><div><span class="u-name"><?=htmlspecialchars($u['full_name'])?></span><span class="u-phone"><?=htmlspecialchars($u['phone'])?></span></div></div></td>
        <td><strong>₦<?=number_format((float)$u['wallet_balance'],2)?></strong></td>
        <td>₦<?=number_format((float)$u['cashback_balance'],2)?></td>
        <td><span class="badge <?=$u['kyc_level']>0?'badge-success':'badge-neutral'?>">Tier <?=$u['kyc_level']?></span></td>
        <td><span class="badge <?=$u['user_type']==='vendor'?'badge-vendor':'badge-regular'?>"><?=htmlspecialchars($u['user_type']??'regular')?></span></td>
        <td><span class="badge <?=$u['is_suspended']?'badge-danger':'badge-success'?>"><?=$u['is_suspended']?'Suspended':'Active'?></span></td>
        <td style="font-size:12px;color:#6B7280"><?=date('d M Y',strtotime($u['created_at']))?></td>
        <td style="white-space:nowrap"><button class="btn-sm btn-ghost" onclick="event.stopPropagation();openUserModal(<?=htmlspecialchars(json_encode($u),ENT_QUOTES)?>)">Manage</button></td>
      </tr>
      <?php endforeach;if(empty($data['data'])):?><tr><td colspan="8" class="empty-state">No users found</td></tr><?php endif;?>
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

<!-- Management Modal -->
<div class="modal-overlay" id="modalOvl" style="display:none">
  <div class="modal">
    <div class="modal-header"><h3 id="mTitle">Manage User</h3><button class="modal-close" onclick="document.getElementById('modalOvl').style.display='none'">✕</button></div>
    <div class="modal-body">
      <input type="hidden" id="muid" value="">
      <div class="action-grid">
        <button class="action-tile green" onclick="showSub('credit')"><svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Credit</button>
        <button class="action-tile red" onclick="showSub('debit')"><svg viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"/></svg>Debit</button>
        <button class="action-tile gray" onclick="resetPin()"><svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>Reset PIN</button>
      </div>
      <div id="suspendBtn"></div>
      <div id="subForm" style="display:none;margin-top:14px;border-top:1px solid #E5E7EB;padding-top:14px">
        <div class="form-group" style="margin-bottom:10px"><label>Amount (₦)</label><input type="number" class="form-control" id="amt" step="0.01"></div>
        <div class="form-group" style="margin-bottom:10px"><label>Note</label><input type="text" class="form-control" id="note" placeholder="Reason"></div>
        <button class="btn-primary" style="width:100%;justify-content:center" id="subBtn" onclick="submitAction()">Submit</button>
      </div>
    </div>
  </div>
</div>

<!-- Detail Modal -->
<div class="modal-overlay" id="detailModalOvl" style="display:none">
  <div class="modal" style="max-width:780px">
    <div class="modal-header"><h3 id="detailTitle">User Details</h3><button class="modal-close" onclick="closeDetailModal()">✕</button></div>
    <div class="modal-body">
      <div id="detailLoading" style="text-align:center;padding:40px 0;color:var(--text-muted);font-size:14px">Loading user details...</div>
      <div id="detailContent" style="display:none">
        <div class="info-grid" id="detailInfo"></div>
        <div class="detail-section">
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button class="btn-primary btn-sm" onclick="togglePasswordForm()">Change Password</button>
            <button class="btn-primary btn-sm" id="userTypeBtn" onclick="toggleUserType()" style="background:#8B5CF6"></button>
          </div>
          <div id="passwordForm" style="display:none;margin-top:12px;padding:14px;background:var(--bg);border-radius:12px">
            <div class="form-group" style="margin-bottom:8px"><input type="password" class="form-control" id="adminPw" placeholder="Your admin password"></div>
            <div class="form-group" style="margin-bottom:8px"><input type="password" class="form-control" id="newPw" placeholder="New password for user (min 6 chars)"></div>
            <button class="btn-primary" style="width:100%;justify-content:center" onclick="changePassword()">Update Password</button>
          </div>
        </div>
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
function openUserModal(u){curUser=u;document.getElementById('mTitle').textContent=u.full_name;document.getElementById('muid').value=u.id;document.getElementById('subForm').style.display='none';
  document.getElementById('suspendBtn').innerHTML=u.is_suspended==1?'<button class="btn-primary" style="width:100%;justify-content:center;background:#22C55E;margin-top:10px" onclick="unsuspend()">Reinstate Account</button>':'<button class="btn-primary" style="width:100%;justify-content:center;background:#EF4444;margin-top:10px" onclick="suspendPrompt()">Suspend Account</button>';
  document.getElementById('modalOvl').style.display='flex';}
function showSub(action){curAction=action;document.getElementById('subForm').style.display='block';document.getElementById('subBtn').textContent=action==='credit'?'Credit Wallet':'Debit Wallet';}
async function resetPin(){if(!confirm("Reset this user's transaction PIN?"))return;const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','reset_pin');fd.append('uid',curUser.id);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);}
function suspendPrompt(){const reason=prompt('Reason for suspension:');if(!reason)return;suspend(reason);}
async function suspend(reason){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','suspend');fd.append('uid',curUser.id);fd.append('reason',reason);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);}
async function unsuspend(){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','unsuspend');fd.append('uid',curUser.id);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);}
async function submitAction(){const amt=document.getElementById('amt').value;const note=document.getElementById('note').value;if(!amt||parseFloat(amt)<=0)return showToast('Enter valid amount',false);const fd=new FormData();fd.append('csrf',CSRF);fd.append('action',curAction);fd.append('uid',curUser.id);fd.append('amount',amt);fd.append('note',note);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);}
function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),2500);}
document.getElementById('modalOvl').addEventListener('click',e=>{if(e.target===e.currentTarget)e.currentTarget.style.display='none';});
document.getElementById('detailModalOvl').addEventListener('click',e=>{if(e.target===e.currentTarget)closeDetailModal();});

function esc(str){if(!str)return '';return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function fmtDate(d){if(!d)return '-';try{const dt=new Date(d);return dt.toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'})}catch(e){return d}}
function fmtMoney(n){return '\u20A6'+parseFloat(n||0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',')}

function openDetailModal(uid){detailUserId=uid;document.getElementById('detailLoading').style.display='block';document.getElementById('detailContent').style.display='none';document.getElementById('detailModalOvl').style.display='flex';document.getElementById('detailTitle').textContent='Loading...';loadUserDetail(uid);}
function closeDetailModal(){document.getElementById('detailModalOvl').style.display='none';document.getElementById('passwordForm').style.display='none';}

async function loadUserDetail(uid){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','get_user_detail');fd.append('uid',uid);try{const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());if(!r.success){showToast(r.message,false);closeDetailModal();return}renderDetail(r);}catch(e){showToast('Failed to load details',false);closeDetailModal();}}

function renderDetail(r){
  const u=r.user;document.getElementById('detailTitle').textContent=u.full_name||u.username||'User #'+u.id;
  const info=[{l:'Full Name',v:u.full_name},{l:'Username',v:u.username},{l:'Phone',v:u.phone},{l:'Email',v:u.email},{l:'KYC Level',v:'Tier '+(u.kyc_level||0)},{l:'Wallet Balance',v:fmtMoney(u.wallet_balance)},{l:'Cashback',v:fmtMoney(u.cashback_balance)},{l:'User Type',v:u.user_type||'regular'},{l:'Status',v:u.is_suspended?'Suspended':'Active'},{l:'Registered',v:u.created_at?fmtDate(u.created_at):'-'},{l:'Last Login',v:u.last_login||'-'},{l:'IP Address',v:u.last_login_ip||'-'}];
  document.getElementById('detailInfo').innerHTML=info.map(i=>'<div class="field"><label>'+i.l+'</label><strong>'+esc(i.v)+'</strong></div>').join('');
  const isV=u.user_type==='vendor';const btn=document.getElementById('userTypeBtn');btn.textContent=isV?'Downgrade to Regular':'Upgrade to Vendor';btn.style.background=isV?'#EF4444':'#8B5CF6';
  const tbody=document.getElementById('txnBody');
  if(r.transactions&&r.transactions.length){tbody.innerHTML=r.transactions.map(t=>'<tr><td style="font-size:11px">'+esc(t.reference||'-')+'</td><td>'+esc(t.category||'-')+'</td><td>'+fmtMoney(t.amount)+'</td><td><span class="badge '+(t.status==='success'?'badge-success':t.status==='pending'?'badge-warning':'badge-danger')+'">'+esc(t.status||'-')+'</span></td><td style="font-size:12px;color:#6B7280">'+fmtDate(t.created_at)+'</td></tr>').join('')}else{tbody.innerHTML='<tr><td colspan="5" class="empty-state">No transactions</td></tr>'}
  const lbody=document.getElementById('loginBody');
  if(r.logins&&r.logins.length){lbody.innerHTML=r.logins.map(l=>'<tr><td style="font-size:12px">'+esc(l.ip_address||'-')+'</td><td style="font-size:11px;color:#6B7280;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="'+esc(l.user_agent||'')+'">'+esc(l.user_agent||'-')+'</td><td style="font-size:12px;color:#6B7280">'+fmtDate(l.login_at)+'</td></tr>').join('')}else{lbody.innerHTML='<tr><td colspan="3" class="empty-state">No login records</td></tr>'}
  document.getElementById('detailLoading').style.display='none';document.getElementById('detailContent').style.display='block';
}

function togglePasswordForm(){const f=document.getElementById('passwordForm');f.style.display=f.style.display==='none'?'block':'none';if(f.style.display==='block'){document.getElementById('adminPw').value='';document.getElementById('newPw').value='';document.getElementById('adminPw').focus();}}
async function changePassword(){const ap=document.getElementById('adminPw').value;const np=document.getElementById('newPw').value;if(!ap)return showToast('Enter your admin password',false);if(!np||np.length<6)return showToast('New password must be at least 6 characters',false);const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','change_password');fd.append('uid',detailUserId);fd.append('admin_password',ap);fd.append('new_password',np);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success){document.getElementById('passwordForm').style.display='none';document.getElementById('adminPw').value='';document.getElementById('newPw').value='';}}
async function toggleUserType(){const ct=document.getElementById('detailInfo').querySelectorAll('.field')[7].querySelector('strong').textContent;const nt=ct==='vendor'?'regular':'vendor';if(!confirm('Change user type to "'+nt+'"?'))return;const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','set_user_type');fd.append('uid',detailUserId);fd.append('user_type',nt);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success){document.getElementById('detailInfo').querySelectorAll('.field')[7].querySelector('strong').textContent=nt;const btn=document.getElementById('userTypeBtn');btn.textContent=nt==='vendor'?'Downgrade to Regular':'Upgrade to Vendor';btn.style.background=nt==='vendor'?'#EF4444':'#8B5CF6';}}
</script>
</body></html>
