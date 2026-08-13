<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
require_feature_admin('admin_cashback');
if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    require_permission('manage_cashback');
    $action=$_POST['action']??'';
    if($action==='save'){
        $id=(int)($_POST['id']??0);
        $service=sanitize($_POST['service']??'',50);$network=sanitize($_POST['network']??'',20)?:null;
        $type=in_array($_POST['cashback_type']??'',['percent','fixed'])?$_POST['cashback_type']:'percent';
        $value=(float)($_POST['cashback_value']??0);$min=(float)($_POST['min_amount']??0);$max=(float)($_POST['max_cashback']??9999);
        $label=sanitize($_POST['label']??'',100);$active=isset($_POST['is_active'])?1:0;
        // cashback_balance is real, withdrawable money (process_cashback_withdrawal()
        // moves it straight into wallet_balance) — apply_cashback() computes
        // earned = min(amount * value/100, max_cashback) with no ceiling of
        // its own, so an unbounded percent/max_cashback here turns ordinary
        // purchases into a direct cash-out channel. A negative value/min/max
        // is also nonsensical and would produce a negative "earned" credit.
        if($value<0||$min<0||$max<0)json_response(['success'=>false,'message'=>'Value, minimum amount, and max cashback must not be negative']);
        if($type==='percent'&&$value>100)json_response(['success'=>false,'message'=>'Percentage cashback cannot exceed 100%']);
        if($id){db()->prepare("UPDATE cashback_config SET service=?,network=?,cashback_type=?,cashback_value=?,min_amount=?,max_cashback=?,label=?,is_active=? WHERE id=?")->execute([$service,$network,$type,$value,$min,$max,$label,$active,$id]);}
        else{
            $chk=db()->prepare("SELECT id FROM cashback_config WHERE service=? AND (network=? OR (network IS NULL AND ? IS NULL)) LIMIT 1");$chk->execute([$service,$network,$network]);
            if($chk->fetch())json_response(['success'=>false,'message'=>'Config already exists for this service+network. Edit it instead.']);
            db()->prepare("INSERT INTO cashback_config (service,network,cashback_type,cashback_value,min_amount,max_cashback,label,is_active) VALUES (?,?,?,?,?,?,?,?)")->execute([$service,$network,$type,$value,$min,$max,$label,$active]);
        }
        json_response(['success'=>true,'message'=>'Cashback config saved']);
    }
    if($action==='delete'){db()->prepare("DELETE FROM cashback_config WHERE id=?")->execute([(int)$_POST['id']]);json_response(['success'=>true,'message'=>'Deleted']);}
    if($action==='toggle'){db()->prepare("UPDATE cashback_config SET is_active=NOT is_active WHERE id=?")->execute([(int)$_POST['id']]);json_response(['success'=>true]);}
    json_response(['success'=>false],400);
}
$csrf=admin_csrf();
$configs=db()->query("SELECT * FROM cashback_config ORDER BY service,network")->fetchAll();
$services=['airtime','data','electricity','cabletv','bank_transfer','exam_pin','recharge_card','data_card','airtime_to_cash','betting'];
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Cashback – <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title><link rel="icon" type="image/png" href="/assets/images/favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header"><div><h2>Cashback Configuration</h2><p>Set cashback rewards per service and network — percent or fixed amount.</p></div><button class="btn-primary" onclick="openModal()"><i class="fas fa-plus"></i> Add Cashback Rule</button></div>

<div class="data-panel">
  <div class="responsive-grid-wrapper">
    <table class="data-table">
      <thead><tr><th>Service</th><th>Network</th><th>Type</th><th>Value</th><th>Min Amount</th><th>Max Cashback</th><th>Label</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($configs as $c):?>
      <tr>
        <td><strong><?=ucwords(str_replace('_',' ',$c['service']))?></strong></td>
        <td><?=$c['network']?:'<span class="text-muted">All</span>'?></td>
        <td><span class="badge badge-info"><?=ucfirst($c['cashback_type'])?></span></td>
        <td><strong><?=$c['cashback_type']==='percent'?$c['cashback_value'].'%':'₦'.number_format((float)$c['cashback_value'],2)?></strong></td>
        <td>₦<?=number_format((float)$c['min_amount'],0)?></td>
        <td>₦<?=number_format((float)$c['max_cashback'],0)?></td>
        <td class="text-sm text-muted"><?=htmlspecialchars($c['label']??'')?></td>
        <td><label class="toggle"><input type="checkbox" <?=$c['is_active']?'checked':''?> onchange="toggleCfg(<?=$c['id']?>)"><span class="slider"></span></label></td>
        <td style="white-space:nowrap"><button class="btn-ghost btn-sm" onclick='editCfg(<?=htmlspecialchars(json_encode($c),ENT_QUOTES)?>)'><i class="fas fa-edit"></i> Edit</button> <button class="btn-ghost btn-sm" style="color:var(--danger)" onclick="deleteCfg(<?=$c['id']?>)"><i class="fas fa-trash"></i> Delete</button></td>
      </tr>
      <?php endforeach;if(empty($configs)):?><tr><td colspan="9" class="empty-state">No cashback rules configured yet</td></tr><?php endif;?>
      </tbody>
    </table>
  </div>
</div>

</div></div>

<div class="modal-overlay" id="modalOvl" style="display:none">
  <div class="modal">
    <div class="modal-header"><h3 id="modalTitle">Add Cashback Rule</h3><button class="modal-close" onclick="closeModal()">✕</button></div>
    <div class="modal-body">
      <input type="hidden" id="cid" value="">
      <div style="display:flex;flex-direction:column;gap:14px">
        <div class="form-grid">
          <div class="form-group"><label>Service</label><select class="form-control" id="service"><?php foreach($services as $s):?><option value="<?=$s?>"><?=ucwords(str_replace('_',' ',$s))?></option><?php endforeach;?></select></div>
          <div class="form-group"><label>Network <span class="text-muted" style="font-weight:400">(blank=all)</span></label><select class="form-control" id="network"><option value="">All</option><option value="MTN">MTN</option><option value="GLO">GLO</option><option value="AIRTEL">AIRTEL</option><option value="9MOBILE">9MOBILE</option></select></div>
        </div>
        <div class="form-grid">
          <div class="form-group"><label>Cashback Type</label><select class="form-control" id="cashback_type"><option value="percent">Percentage (%)</option><option value="fixed">Fixed Amount (₦)</option></select></div>
          <div class="form-group"><label>Value</label><input type="number" class="form-control" id="cashback_value" step="0.01" placeholder="e.g. 1.5 or 50"></div>
        </div>
        <div class="form-grid">
          <div class="form-group"><label>Minimum Amount (₦)</label><input type="number" class="form-control" id="min_amount" step="0.01" placeholder="Min transaction for cashback"></div>
          <div class="form-group"><label>Max Cashback (₦)</label><input type="number" class="form-control" id="max_cashback" step="0.01" placeholder="Cap per transaction"></div>
        </div>
        <div class="form-group"><label>Label <span class="text-muted" style="font-weight:400">(shown to admin)</span></label><input type="text" class="form-control" id="label" placeholder="e.g. 1.5% on MTN Data"></div>
        <div class="flex items-center justify-between"><label class="font-semibold text-sm" style="color:var(--navy-dark)">Active</label><label class="toggle"><input type="checkbox" id="is_active" checked><span class="slider"></span></label></div>
        <button class="btn-primary" style="width:100%;height:44px;justify-content:center" onclick="saveCfg()"><i class="fas fa-save"></i> Save Cashback Rule</button>
      </div>
    </div>
  </div>
</div>
<div class="save-toast" id="toast"></div>
<script>
const CSRF='<?=$csrf?>';
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
function openModal(){document.getElementById('modalTitle').textContent='Add Cashback Rule';['cid','network','cashback_value','min_amount','max_cashback','label'].forEach(id=>document.getElementById(id).value='');document.getElementById('is_active').checked=true;document.getElementById('modalOvl').style.display='flex';}
function editCfg(c){document.getElementById('modalTitle').textContent='Edit Cashback Rule';document.getElementById('cid').value=c.id;document.getElementById('service').value=c.service;document.getElementById('network').value=c.network||'';document.getElementById('cashback_type').value=c.cashback_type;document.getElementById('cashback_value').value=c.cashback_value;document.getElementById('min_amount').value=c.min_amount;document.getElementById('max_cashback').value=c.max_cashback;document.getElementById('label').value=c.label||'';document.getElementById('is_active').checked=c.is_active==1;document.getElementById('modalOvl').style.display='flex';}
function closeModal(){document.getElementById('modalOvl').style.display='none';}
async function saveCfg(){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','save');fd.append('id',document.getElementById('cid').value);['service','network','cashback_type','cashback_value','min_amount','max_cashback','label'].forEach(id=>fd.append(id,document.getElementById(id).value));if(document.getElementById('is_active').checked)fd.append('is_active','1');const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);}
async function deleteCfg(id){if(!confirm('Delete this rule?'))return;const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','delete');fd.append('id',id);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),700);}
async function toggleCfg(id){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','toggle');fd.append('id',id);await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});}
function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),2500);}
document.getElementById('modalOvl').addEventListener('click',e=>{if(e.target===e.currentTarget)closeModal();});
</script>
</body></html>
