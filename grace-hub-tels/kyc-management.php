<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    require_permission('manage_users');
    $action=$_POST['action']??'';$id=(int)($_POST['id']??0);
    $req=db()->prepare("SELECT * FROM kyc_requests WHERE id=? LIMIT 1");$req->execute([$id]);$r=$req->fetch();
    if(!$r)json_response(['success'=>false,'message'=>'Request not found']);
    if($action==='approve'){
        db()->prepare("UPDATE kyc_requests SET status='approved',reviewed_by=?,reviewed_at=NOW() WHERE id=?")->execute([$_SESSION['admin_id'],$id]);
        if((int)$r['tier']===3)db()->prepare("UPDATE users SET kyc_level=3,kyc_status='approved' WHERE id=?")->execute([$r['user_id']]);
        notify_user((int)$r['user_id'],'KYC Approved',"Your Tier {$r['tier']} verification has been approved!",'success');
        json_response(['success'=>true,'message'=>'Approved']);
    }
    if($action==='reject'){
        $reason=sanitize($_POST['reason']??'Documents unclear',300);
        db()->prepare("UPDATE kyc_requests SET status='rejected',rejection_reason=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?")->execute([$reason,$_SESSION['admin_id'],$id]);
        if((int)$r['tier']===3)db()->prepare("UPDATE users SET kyc_status='rejected' WHERE id=?")->execute([$r['user_id']]);
        notify_user((int)$r['user_id'],'KYC Rejected',"Your Tier {$r['tier']} verification was rejected: {$reason}",'danger');
        json_response(['success'=>true,'message'=>'Rejected']);
    }
    if($action==='save_api'){
        $allowed=['easeid_bearer','easeid_secret'];
        foreach($_POST as $key=>$val){if(in_array($key,$allowed)){setting_set($key,trim((string)$val));}}
        json_response(['success'=>true,'message'=>'Settings saved']);
    }
    json_response(['success'=>false],400);
}
function k(string $key):string{return htmlspecialchars(setting($key));}
// Secret fields (API credentials) must not render for viewers who can't act on
// them — auth_admin() alone (no role/permission) lets any admin, including
// 'support', load this page; only the manage_users-gated POST above can change these.
function k_secret(string $key):string{return has_permission('manage_users')?htmlspecialchars(setting($key)):'';}
$csrf=admin_csrf();
$filter_raw=$_GET['status']??'pending';
$allowed_filters=['pending','auto_approved','approved','rejected','all'];
$filter=in_array($filter_raw,$allowed_filters)?$filter_raw:'pending';
$where=$filter==='all'?'':'WHERE k.status=?';
$stmt=db()->prepare("SELECT k.*,u.full_name,u.phone,u.kyc_level FROM kyc_requests k LEFT JOIN users u ON k.user_id=u.id $where ORDER BY k.created_at DESC LIMIT 100");
$stmt->execute($filter==='all'?[]:[$filter]);
$requests=$stmt->fetchAll();
$counts=db()->query("SELECT status,COUNT(*) c FROM kyc_requests GROUP BY status")->fetchAll();
$cmap=[];foreach($counts as $c)$cmap[$c['status']]=$c['c'];
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>KYC Review — <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title><link rel="icon" type="image/png" href="/assets/images/favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header"><div><h2>KYC Review</h2><p>Auto-approved Tier 1/2 shown for audit. Tier 3 requires manual approval.</p></div></div>

<div class="filter-tabs" style="margin-bottom:16px">
  <a href="?status=pending" class="filter-tab <?=$filter==='pending'?'active':''?>">Pending (<?=$cmap['pending']??0?>)</a>
  <a href="?status=auto_approved" class="filter-tab <?=$filter==='auto_approved'?'active':''?>">Auto-Approved (<?=$cmap['auto_approved']??0?>)</a>
  <a href="?status=approved" class="filter-tab <?=$filter==='approved'?'active':''?>">Approved (<?=$cmap['approved']??0?>)</a>
  <a href="?status=rejected" class="filter-tab <?=$filter==='rejected'?'active':''?>">Rejected (<?=$cmap['rejected']??0?>)</a>
  <a href="?status=all" class="filter-tab <?=$filter==='all'?'active':''?>">All</a>
</div>

<div class="data-panel" style="margin-bottom: 24px;">
  <div class="panel-title-bar"><h3>EaseID (NIN/BVN KYC) API Configuration</h3></div>
  <div class="modal-body" style="display:flex;flex-direction:column;gap:14px">
    <div class="form-group">
      <label>Bearer Token</label>
      <input type="password" class="form-control" id="easeid_bearer" value="<?=k_secret('easeid_bearer')?>">
    </div>
    <div class="form-group">
      <label>Secret (HMAC Signature)</label>
      <input type="password" class="form-control" id="easeid_secret" value="<?=k_secret('easeid_secret')?>">
    </div>
    <button class="btn-primary" onclick="saveEaseID()">Save API Keys</button>
  </div>
</div>

<div class="data-panel">
  <div class="responsive-grid-wrapper">
    <table class="data-table">
      <thead><tr><th>User</th><th>Tier</th><th>ID Number</th><th>Names</th><th>Match</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($requests as $r):
        $scls=['pending'=>'badge-pending','auto_approved'=>'badge-success','approved'=>'badge-success','rejected'=>'badge-danger','under_review'=>'badge-warning'][$r['status']]??'badge-pending';
        $idnum=$r['tier']==1?$r['nin']:($r['tier']==2?$r['bvn']:'—');
      ?>
      <tr>
        <td><div class="user-cell"><div class="u-avatar"><?=strtoupper(substr($r['full_name']??'?',0,1))?></div><div><span class="u-name"><?=htmlspecialchars($r['full_name']??'—')?></span><span class="u-phone"><?=htmlspecialchars($r['phone']??'')?></span></div></div></td>
        <td><span class="badge badge-info">Tier <?=$r['tier']?></span></td>
        <td><code><?=htmlspecialchars($idnum?:'—')?></code></td>
        <td style="font-size:12px"><?=htmlspecialchars(trim(($r['first_name']??'').' '.($r['last_name']??'')))?></td>
        <td style="font-size:12px"><?=htmlspecialchars($r['name_match']??'—')?> <?=$r['match_percent']?'('.$r['match_percent'].'%)':''?></td>
        <td><span class="badge <?=$scls?>"><?=ucwords(str_replace('_',' ',$r['status']))?></span></td>
        <td style="font-size:12px;color:#6B7280"><?=date('d M, H:i',strtotime($r['created_at']))?></td>
        <td style="white-space:nowrap">
          <?php if($r['status']==='pending'):?>
          <button class="btn-sm btn-success" onclick="approve(<?=$r['id']?>)">Approve</button>
          <button class="btn-sm btn-danger" onclick="rejectPrompt(<?=$r['id']?>)">Reject</button>
          <?php else:?>
          <button class="btn-sm btn-ghost" onclick='viewDetail(<?=htmlspecialchars(json_encode($r),ENT_QUOTES)?>)'>View</button>
          <?php endif;?>
        </td>
      </tr>
      <?php endforeach;if(empty($requests)):?><tr><td colspan="8" class="empty-state">No KYC requests found</td></tr><?php endif;?>
      </tbody>
    </table>
  </div>
</div>

</div></div>

<div class="modal-overlay" id="modalOvl" style="display:none">
  <div class="modal">
    <div class="modal-header"><h3>KYC Detail</h3><button class="modal-close" onclick="document.getElementById('modalOvl').style.display='none'">&times;</button></div>
    <div class="modal-body" id="modalBody"></div>
  </div>
</div>
<div class="save-toast" id="toast"></div>
<script>
const CSRF='<?=$csrf?>';
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
function esc(s){if(!s)return '';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
async function approve(id){if(!confirm('Approve this KYC request?'))return;const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','approve');fd.append('id',id);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);}
function rejectPrompt(id){const reason=prompt('Reason for rejection:');if(!reason)return;reject(id,reason);}
async function reject(id,reason){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','reject');fd.append('id',id);fd.append('reason',reason);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);}
function viewDetail(r){
  let resp={};try{resp=JSON.parse(r.easeid_response||'{}');}catch(e){}
  document.getElementById('modalBody').innerHTML=`
    <div style="display:flex;flex-direction:column;gap:10px;font-size:13px">
      <div><strong>User:</strong> ${esc(r.full_name||'—')} (${esc(r.phone||'')})</div>
      <div><strong>Tier:</strong> ${parseInt(r.tier)||0}</div>
      <div><strong>ID Number:</strong> ${r.tier==1?esc(r.nin||'—'):(r.tier==2?esc(r.bvn||'—'):'—')}</div>
      <div><strong>Name Match:</strong> ${esc(r.name_match||'—')} (${parseInt(r.match_percent)||0}%)</div>
      <div><strong>Status:</strong> ${esc(r.status)}</div>
      ${r.rejection_reason?`<div><strong>Rejection Reason:</strong> ${esc(r.rejection_reason)}</div>`:''}
      <div><strong>Submitted:</strong> ${esc(r.created_at)}</div>
      ${r.address?`<div><strong>Address:</strong> ${esc(r.address)}</div>`:''}
      <div style="background:#F9FAFB;border-radius:8px;padding:10px;font-size:11px;font-family:monospace;max-height:200px;overflow-y:auto;white-space:pre-wrap">${esc(JSON.stringify(resp,null,2))}</div>
    </div>`;
  document.getElementById('modalOvl').style.display='flex';
}
function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),2500);}

async function saveEaseID(){
    const fd=new FormData();
    fd.append('csrf',CSRF);
    fd.append('action','save_api');
    fd.append('easeid_bearer',document.getElementById('easeid_bearer').value);
    fd.append('easeid_secret',document.getElementById('easeid_secret').value);
    const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    showToast(r.message,r.success);
}
document.getElementById('modalOvl').addEventListener('click',e=>{if(e.target===e.currentTarget)e.currentTarget.style.display='none';});
</script>
</body></html>
