<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
$csrf=admin_csrf();

// Load data for each tab
$recharge_cards=db()->query("SELECT r.*,u.full_name,u.phone FROM recharge_cards r LEFT JOIN users u ON r.user_id=u.id ORDER BY r.created_at DESC LIMIT 200")->fetchAll();
$data_cards=db()->query("SELECT d.*,u.full_name,u.phone FROM data_cards d LEFT JOIN users u ON d.user_id=u.id ORDER BY d.created_at DESC LIMIT 200")->fetchAll();
$exam_pins=db()->query("SELECT e.*,u.full_name,u.phone FROM exam_pins e LEFT JOIN users u ON e.user_id=u.id ORDER BY e.created_at DESC LIMIT 200")->fetchAll();
$bulk_sms=db()->query("SELECT b.*,u.full_name,u.phone FROM bulk_sms_history b LEFT JOIN users u ON b.user_id=u.id ORDER BY b.created_at DESC LIMIT 200")->fetchAll();
$tab=$_GET['tab']??'recharge';
$allowed=['recharge','data_card','exam_pin','bulk_sms'];
if(!in_array($tab,$allowed))$tab='recharge';
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>PIN Products – Gracehubtels Admin</title><link rel="icon" type="image/png" href="/assets/images/gracehubtels-favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"><link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css"></head><body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header"><div><h2><i class="fas fa-ticket" style="color:var(--primary);margin-right:12px;"></i> PIN Products</h2><p>Recharge Cards, Data Cards, Exam PINs &amp; Bulk SMS History</p></div></div>

<div class="data-panel" style="display:block;">
  <div class="filter-tabs" style="margin-bottom:16px;background:transparent;border:none;padding:0;">
    <a href="?tab=recharge" class="filter-tab <?=$tab==='recharge'?'active':''?>" style="text-decoration:none;">Recharge Cards (<?=count($recharge_cards)?>)</a>
    <a href="?tab=data_card" class="filter-tab <?=$tab==='data_card'?'active':''?>" style="text-decoration:none;">Data Cards (<?=count($data_cards)?>)</a>
    <a href="?tab=exam_pin" class="filter-tab <?=$tab==='exam_pin'?'active':''?>" style="text-decoration:none;">Exam PINs (<?=count($exam_pins)?>)</a>
    <a href="?tab=bulk_sms" class="filter-tab <?=$tab==='bulk_sms'?'active':''?>" style="text-decoration:none;">Bulk SMS (<?=count($bulk_sms)?>)</a>
  </div>

<?php if($tab==='recharge'):?>
  <div class="responsive-grid-wrapper">
    <table class="data-table">
      <thead><tr><th>User</th><th>Network</th><th>Denom</th><th>Qty</th><th>Amount</th><th>Name on Card</th><th>PINs</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach($recharge_cards as $r):$pins=json_decode($r['pins_data']??'[]',true);?>
      <tr>
        <td><div class="user-cell"><div class="u-avatar"><?=strtoupper(substr($r['full_name']??'?',0,1))?></div><div><span class="u-name"><?=htmlspecialchars($r['full_name']??'—')?></span><span class="u-phone"><?=htmlspecialchars($r['phone']??'')?></span></div></div></td>
        <td><strong><?=htmlspecialchars($r['network']??'')?></strong></td>
        <td>₦<?=number_format((float)$r['denomination'],0)?></td>
        <td><?=(int)$r['quantity']?></td>
        <td>₦<?=number_format((float)$r['amount'],2)?></td>
        <td style="font-size:12px;"><?=htmlspecialchars($r['name_on_card']??'—')?></td>
        <td style="max-width:200px;font-size:11px;word-break:break-all;"><?=is_array($pins)?htmlspecialchars(implode(', ',$pins)):'—'?></td>
        <td><span class="badge <?=$r['status']==='success'?'badge-success':'badge-danger'?>"><?=ucfirst($r['status'])?></span></td>
        <td style="font-size:12px;color:#6B7280;white-space:nowrap;"><?=date('d M, H:i',strtotime($r['created_at']))?></td>
      </tr>
      <?php endforeach;if(empty($recharge_cards)):?><tr><td colspan="9" class="empty-state">No recharge card purchases yet</td></tr><?php endif;?>
      </tbody>
    </table>
  </div>
<?php elseif($tab==='data_card'):?>
  <div class="responsive-grid-wrapper">
    <table class="data-table">
      <thead><tr><th>User</th><th>Network</th><th>Plan</th><th>Qty</th><th>Amount</th><th>PINs</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach($data_cards as $d):$pins=json_decode($d['pins']??'[]',true);?>
      <tr>
        <td><div class="user-cell"><div class="u-avatar"><?=strtoupper(substr($d['full_name']??'?',0,1))?></div><div><span class="u-name"><?=htmlspecialchars($d['full_name']??'—')?></span><span class="u-phone"><?=htmlspecialchars($d['phone']??'')?></span></div></div></td>
        <td><strong><?=htmlspecialchars($d['network']??'')?></strong></td>
        <td style="font-size:12px;"><?=htmlspecialchars($d['plan_name']??'')?></td>
        <td><?=(int)$d['quantity']?></td>
        <td>₦<?=number_format((float)$d['amount'],2)?></td>
        <td style="max-width:200px;font-size:11px;word-break:break-all;"><?=is_array($pins)?htmlspecialchars(implode(', ',$pins)):'—'?></td>
        <td><span class="badge <?=$d['status']==='success'?'badge-success':'badge-danger'?>"><?=ucfirst($d['status'])?></span></td>
        <td style="font-size:12px;color:#6B7280;white-space:nowrap;"><?=date('d M, H:i',strtotime($d['created_at']))?></td>
      </tr>
      <?php endforeach;if(empty($data_cards)):?><tr><td colspan="8" class="empty-state">No data card purchases yet</td></tr><?php endif;?>
      </tbody>
    </table>
  </div>
<?php elseif($tab==='exam_pin'):?>
  <div class="responsive-grid-wrapper">
    <table class="data-table">
      <thead><tr><th>User</th><th>Exam</th><th>Qty</th><th>Amount</th><th>PINs</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach($exam_pins as $e):$pins=json_decode($e['pins_data']??'[]',true);?>
      <tr>
        <td><div class="user-cell"><div class="u-avatar"><?=strtoupper(substr($e['full_name']??'?',0,1))?></div><div><span class="u-name"><?=htmlspecialchars($e['full_name']??'—')?></span><span class="u-phone"><?=htmlspecialchars($e['phone']??'')?></span></div></div></td>
        <td><strong><?=htmlspecialchars($e['exam_name']??'')?></strong></td>
        <td><?=(int)$e['quantity']?></td>
        <td>₦<?=number_format((float)$e['amount'],2)?></td>
        <td style="max-width:200px;font-size:11px;word-break:break-all;"><?=is_array($pins)?htmlspecialchars(implode(', ',$pins)):'—'?></td>
        <td><span class="badge <?=$e['status']==='success'?'badge-success':'badge-danger'?>"><?=ucfirst($e['status'])?></span></td>
        <td style="font-size:12px;color:#6B7280;white-space:nowrap;"><?=date('d M, H:i',strtotime($e['created_at']))?></td>
      </tr>
      <?php endforeach;if(empty($exam_pins)):?><tr><td colspan="7" class="empty-state">No exam PIN purchases yet</td></tr><?php endif;?>
      </tbody>
    </table>
  </div>
<?php elseif($tab==='bulk_sms'):?>
  <div class="responsive-grid-wrapper">
    <table class="data-table">
      <thead><tr><th>User</th><th>Sender ID</th><th>Message</th><th>Recipients</th><th>Pages</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach($bulk_sms as $b):?>
      <tr>
        <td><div class="user-cell"><div class="u-avatar"><?=strtoupper(substr($b['full_name']??'?',0,1))?></div><div><span class="u-name"><?=htmlspecialchars($b['full_name']??'—')?></span><span class="u-phone"><?=htmlspecialchars($b['phone']??'')?></span></div></div></td>
        <td style="font-size:12px;"><strong><?=htmlspecialchars($b['sender_id']??'')?></strong></td>
        <td style="max-width:250px;font-size:11px;word-break:break-word;"><?=htmlspecialchars(mb_substr($b['message']??'',0,120))?><?=mb_strlen($b['message']??'')>120?'...':''?></td>
        <td style="font-size:12px;"><?=(int)$b['recipient_count']?></td>
        <td><?=(int)$b['page_count']?></td>
        <td>₦<?=number_format((float)$b['total_cost'],2)?></td>
        <td><span class="badge <?=($b['status']??'pending')==='success'?'badge-success':(($b['status']??'')==='failed'?'badge-danger':'badge-pending')?>"><?=ucfirst($b['status']??'pending')?></span></td>
        <td style="font-size:12px;color:#6B7280;white-space:nowrap;"><?=date('d M, H:i',strtotime($b['created_at']))?></td>
      </tr>
      <?php endforeach;if(empty($bulk_sms)):?><tr><td colspan="8" class="empty-state">No bulk SMS campaigns yet</td></tr><?php endif;?>
      </tbody>
    </table>
  </div>
<?php endif;?>
</div>

</div></div>
<div class="save-toast" id="toast"></div>
</body></html>
