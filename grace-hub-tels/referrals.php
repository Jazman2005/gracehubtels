<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_admin('admin');
$csrf = admin_csrf();

if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??'')){ echo json_encode(['success'=>false,'message'=>'Invalid token']); exit; }
    $action = $_POST['action']??'';
    if($action==='update_bonus'){
        $amt = (float)($_POST['amount']??0);
        
        setting_set('referral_bonus', (string)$amt);
        
        audit_log($_SESSION['admin_id'], 'UPDATE_REFERRAL_BONUS', "Updated referral bonus to $amt");
        echo json_encode(['success'=>true,'message'=>'Referral bonus updated to N'.$amt]);
        exit;
    }
}

$bonus = db()->query("SELECT setting_value FROM site_settings WHERE setting_key='referral_bonus'")->fetchColumn() ?: 0;

$top = db()->query("
    SELECT u.id, u.full_name, u.email, u.phone,
           COUNT(r.id) as total_referrals,
           SUM(r.reward_amount) as total_earned
    FROM referral_rewards r
    JOIN users u ON r.referrer_id = u.id
    GROUP BY u.id
    ORDER BY total_referrals DESC
    LIMIT 50
")->fetchAll();

$tot_paid = db()->query("SELECT SUM(reward_amount) FROM referral_rewards WHERE status='paid'")->fetchColumn() ?: 0;
$tot_pending = db()->query("SELECT SUM(reward_amount) FROM referral_rewards WHERE status='pending'")->fetchColumn() ?: 0;
$tot_ref = db()->query("SELECT COUNT(*) FROM referral_rewards")->fetchColumn() ?: 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Referrals — Gracehubtels Admin</title>
    <link rel="icon" type="image/png" href="/assets/images/gracehubtels-favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header" style="margin-bottom:24px">
  <div>
      <h2>Referral Program Manager</h2>
      <p>Configure signup bonuses and track your top performing agents across the platform.</p>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 2fr;gap:24px;align-items:start">
  
  <div class="data-panel">
    <div class="panel-title-bar" style="border-bottom:1px solid #E2E8F0; padding-bottom:16px; margin-bottom:16px;">
        <h3 style="display:flex; align-items:center; gap:8px;">
            <div style="width:32px;height:32px;border-radius:8px;background:rgba(79,70,229,0.1);color:var(--primary);display:flex;align-items:center;justify-content:center;">
                <i class="fas fa-gift"></i>
            </div>
            Referral Configuration
        </h3>
    </div>
    
    <div class="form-group" style="margin-bottom:12px">
      <label style="font-weight:600; font-size:13px; color:var(--navy-dark); margin-bottom:6px; display:block;">Registration Bonus Amount (₦)</label>
      <input type="number" class="form-control" id="bonus_amt" value="<?=htmlspecialchars($bonus)?>" step="0.01">
    </div>
    <p style="font-size:12px;color:var(--text-muted);line-height:1.6;margin-bottom:20px;background:#F8FAFC;padding:12px;border-radius:8px;border:1px solid #E2E8F0;">
        <i class="fas fa-info-circle" style="color:var(--primary);margin-right:6px;"></i> 
        This is the amount credited to the referrer's wallet when their invitee completes registration and meets the funding requirement.
    </p>
    
    <button class="btn-primary" style="width:100%;justify-content:center;padding:12px;font-size:14px;" id="saveBtn" onclick="updateBonus()">
        <i class="fas fa-save" style="margin-right:8px;"></i> Save Changes
    </button>
    
    <div style="margin-top:24px;padding-top:20px;border-top:1px solid #E2E8F0">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;padding:8px;background:#F8FAFC;border-radius:6px;">
        <span style="font-size:13px;color:var(--navy);font-weight:600;"><i class="fas fa-users" style="color:#9CA3AF;margin-right:6px;width:16px;"></i> Total Referrals</span>
        <strong style="font-size:15px;"><?=number_format($tot_ref)?></strong>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;padding:8px;background:#F0FDF4;border-radius:6px;border:1px solid #BBF7D0;">
        <span style="font-size:13px;color:var(--navy);font-weight:600;"><i class="fas fa-check-circle" style="color:var(--success);margin-right:6px;width:16px;"></i> Total Paid Out</span>
        <strong style="color:var(--success);font-size:15px;">₦<?=number_format($tot_paid,2)?></strong>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:8px;background:#FFFBEB;border-radius:6px;border:1px solid #FEF3C7;">
        <span style="font-size:13px;color:var(--navy);font-weight:600;"><i class="fas fa-clock" style="color:var(--warning);margin-right:6px;width:16px;"></i> Pending Bonus</span>
        <strong style="color:var(--warning);font-size:15px;">₦<?=number_format($tot_pending,2)?></strong>
      </div>
    </div>
  </div>
  
  <div class="data-panel" >
    <div class="panel-title-bar" style="border-bottom:1px solid #E2E8F0; padding:20px 24px; background:#fff;">
        <h3 style="display:flex; align-items:center; gap:8px; margin:0;">
            <i class="fas fa-trophy" style="color:var(--warning);"></i> Top Referrers (Agents)
        </h3>
    </div>
    <div class="responsive-grid-wrapper" style="border:none;">
      <table class="data-table">
        <thead>
            <tr>
                <th style="padding-left:24px;">User</th>
                <th>Total Referrals</th>
                <th>Total Earned</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach($top as $t):?>
        <tr class="clickable-row" onclick="location.href='users-management.php?q=<?=$t['id']?>'">
          <td style="padding-left:24px;">
            <div class="user-cell">
              <div class="u-avatar" style="background:var(--navy);color:#fff"><?=strtoupper(substr($t['full_name'],0,1))?></div>
              <div><span class="u-name"><?=htmlspecialchars($t['full_name'])?></span><span class="u-phone"><?=htmlspecialchars($t['phone'])?></span></div>
            </div>
          </td>
          <td>
              <span style="display:inline-flex;align-items:center;justify-content:center;background:#F1F5F9;color:var(--navy-dark);font-weight:800;font-size:14px;height:28px;padding:0 12px;border-radius:14px;">
                  <?=number_format($t['total_referrals'])?>
              </span>
          </td>
          <td><span class="badge badge-success" style="font-size:13px;padding:6px 10px;">₦<?=number_format($t['total_earned'],2)?></span></td>
        </tr>
        <?php endforeach;if(empty($top)):?>
        <tr>
            <td colspan="3" class="empty-state" style="padding:40px;">
                <i class="fas fa-users-slash" style="font-size:32px; color:#CBD5E1; margin-bottom:12px;"></i><br>
                No referrals found in the system.
            </td>
        </tr>
        <?php endif;?>
        </tbody>
      </table>
    </div>
  </div>
  
</div>

</div></div>
<div class="save-toast" id="toast"></div>

<script>
const CSRF_TOKEN = <?=json_encode($csrf)?>;
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
async function updateBonus(){
  const btn = document.getElementById('saveBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:8px;"></i> Saving...';

  const a=document.getElementById('bonus_amt').value;
  const fd=new FormData();fd.append('action','update_bonus');fd.append('amount',a);fd.append('csrf',CSRF_TOKEN);
  const r=await fetch('',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
  
  showToast(r.message,r.success);
  btn.disabled = false;
  btn.innerHTML = '<i class="fas fa-save" style="margin-right:8px;"></i> Save Changes';
}
function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),3000);}
</script>
</body>
</html>
