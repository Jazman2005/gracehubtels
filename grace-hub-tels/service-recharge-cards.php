<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
require_feature_admin('recharge_card');
if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    require_permission('manage_pricing');
    $action=$_POST['action']??'';
    if($action==='save_pricing'){
        $id=(int)($_POST['id']??0);
        if($id){
            $cost=(float)$_POST['cost_price'];$sell=(float)$_POST['selling_price'];$agentP=(float)($_POST['agent_price']??0);$vendorP=(float)($_POST['vendor_price']??0);
            $disc=(float)$_POST['discount_percent'];$agentD=(float)($_POST['agent_discount']??0);$vendorD=(float)($_POST['vendor_discount']??0);
            foreach(['cost_price'=>$cost,'selling_price'=>$sell,'agent_price'=>$agentP,'vendor_price'=>$vendorP] as $label=>$val){
                if($val<0)json_response(['success'=>false,'message'=>ucwords(str_replace('_',' ',$label)).' cannot be negative'],400);
            }
            foreach(['discount_percent'=>$disc,'agent_discount'=>$agentD,'vendor_discount'=>$vendorD] as $label=>$val){
                if($val<0||$val>100)json_response(['success'=>false,'message'=>ucwords(str_replace('_',' ',$label)).' must be between 0 and 100'],400);
            }
            db()->prepare("UPDATE service_pricing SET cost_price=?,selling_price=?,agent_price=?,vendor_price=?,discount_percent=?,agent_discount=?,vendor_discount=?,is_active=? WHERE id=?")->execute([
                $cost,$sell,$agentP,$vendorP,$disc,$agentD,$vendorD,
                isset($_POST['is_active'])?1:0,$id
            ]);
            // Save plan API bindings
            db()->prepare("DELETE FROM plan_api_bindings WHERE service='recharge_card' AND plan_id=?")->execute([$id]);
            foreach(['backup_1'=>2,'backup_2'=>3,'backup_3'=>4,'backup_4'=>5] as $key=>$prio){
                $reg_id=(int)($_POST['api_registry_'.$key]??0);
                $code=sanitize($_POST['api_code_'.$key]??'',100);
                if($reg_id){
                    db()->prepare("INSERT INTO plan_api_bindings (service,plan_id,api_registry_id,priority,product_code) VALUES ('recharge_card',?,?,?,?)")->execute([$id,$reg_id,$prio,$code?:null]);
                }
            }
            json_response(['success'=>true,'message'=>'Pricing updated']);
        }
        json_response(['success'=>false,'message'=>'Missing ID']);
    }
    if($action==='get_plan_bindings'){
        $pid=(int)($_POST['plan_id']??0); $svc=sanitize($_POST['service']??'recharge_card',50);
        $b_stmt=db()->prepare("SELECT b.*,r.name as api_name FROM plan_api_bindings b LEFT JOIN api_registry r ON r.id=b.api_registry_id WHERE b.service=? AND b.plan_id=? ORDER BY b.priority");
        $b_stmt->execute([$svc,$pid]);
        json_response(['success'=>true,'bindings'=>$b_stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if($action==='toggle_pricing'){
        db()->prepare("UPDATE service_pricing SET is_active=NOT is_active WHERE id=?")->execute([(int)$_POST['id']]);
        json_response(['success'=>true]);
    }
    json_response(['success'=>false],400);
}
$csrf=admin_csrf();

// Ensure default rows exist for all networks and denominations
$networks=['MTN','GLO','AIRTEL','9MOBILE'];
$denoms=[100,200,500,1000];
foreach($networks as $net){
    foreach($denoms as $den){
        $chk=db()->prepare("SELECT id FROM service_pricing WHERE service='recharge_card' AND network=? AND plan_name=?");
        $chk->execute([$net,(string)$den]);
        if(!$chk->fetch()){
            db()->prepare("INSERT INTO service_pricing (service,network,plan_name,selling_price,cost_price,is_active) VALUES ('recharge_card',?,?,?,?,1)")->execute([$net,(string)$den,(float)$den,(float)$den]);
        }
    }
}
$pricing=db()->query("SELECT * FROM service_pricing WHERE service='recharge_card' ORDER BY FIELD(network,'MTN','GLO','AIRTEL','9MOBILE'),CAST(plan_name AS UNSIGNED)")->fetchAll();
$api_regs = db()->query("SELECT id, name FROM api_registry WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Recharge Cards – <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title><link rel="icon" type="image/png" href="/assets/images/favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">
<div class="section-header"><div><h2>Recharge Cards</h2><p>Set pricing for recharge PIN cards by network and denomination</p></div></div>

<div class="data-panel">
  <div class="panel-title-bar">
    <h3><i class="fas fa-credit-card" style="color:var(--primary);margin-right:8px"></i> Recharge Card Pricing</h3>
  </div>
  <div style="background:#FFF7ED;border-bottom:1px solid #FDE68A;padding:12px 20px;font-size:13px;color:#92400E;display:flex;align-items:flex-start;gap:12px;">
    <i class="fas fa-info-circle" style="margin-top:2px;"></i>
    <div>Set the <strong>cost price</strong> (what you pay the provider) and <strong>selling price</strong> (what users pay). Agent/Vendor discount % auto-calculates their tier price. Toggle a row off to disable that card type.</div>
  </div>
  <div class="responsive-grid-wrapper">
    <table class="data-table">
      <thead><tr>
        <th>Network</th><th>Denomination</th><th>Cost Price (₦)</th><th>Selling Price (₦)</th><th>Agent Price (₦)</th><th>Agent Discount %</th><th>Vendor Price (₦)</th><th>Vendor Discount %</th><th>Status</th><th style="width:60px"></th>
      </tr></thead>
      <tbody>
      <?php $cur_net='';foreach($pricing as $p):
        $is_new_net=$p['network']!==$cur_net;
        if($is_new_net)$cur_net=$p['network'];
      ?>
      <tr style="<?=$p['is_active']?'':'opacity:0.5'?>">
        <?php if($is_new_net):?>
        <td rowspan="4" style="vertical-align:middle;font-weight:800;font-size:16px;color:var(--navy-dark);background:#F8FAFC;border-right:2px solid var(--border);"><?=htmlspecialchars($p['network'])?></td>
        <?php endif;?>
        <td style="font-weight:700;">₦<?=htmlspecialchars($p['plan_name'])?></td>
        <td>₦<?=number_format((float)$p['cost_price'],0)?></td>
        <td style="font-weight:700;color:var(--navy-dark);">₦<?=number_format((float)$p['selling_price'],0)?></td>
        <td>₦<?=number_format((float)$p['agent_price']>0?$p['agent_price']:$p['selling_price'],0)?></td>
        <td><?=$p['agent_discount']?>%</td>
        <td>₦<?=number_format((float)$p['vendor_price']>0?$p['vendor_price']:$p['selling_price'],0)?></td>
        <td><?=$p['vendor_discount']?>%</td>
        <td><label class="toggle"><input type="checkbox" <?=$p['is_active']?'checked':''?> onchange="togglePricing(<?=$p['id']?>)"><span class="slider"></span></label></td>
        <td><button class="btn-primary" style="background:#F97316;padding:6px 12px;border-radius:4px;font-size:12px;" onclick='openModal(<?=htmlspecialchars(json_encode($p),ENT_QUOTES)?>)'><i class="fas fa-edit"></i></button></td>
      </tr>
      <?php endforeach;?>
      </tbody>
    </table>
  </div>
</div>

</div></div>

<!-- Edit Modal -->
<div class="modal-overlay" id="modalOvl" style="display:none;">
  <div class="modal modal-card">
    <div class="modal-header">
      <h3 id="modalTitle">Edit Recharge Card Pricing</h3>
      <button class="modal-close" onclick="closeModal()">&times;</button>
    </div>
    <div class="modal-body">
      <form id="pricingForm" onsubmit="savePricing(event)">
        <input type="hidden" id="editId">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
          <div class="form-group">
            <label>Network</label>
            <input type="text" id="editNetwork" class="form-control" readonly style="background:#F1F5F9;">
          </div>
          <div class="form-group">
            <label>Denomination (₦)</label>
            <input type="text" id="editDenom" class="form-control" readonly style="background:#F1F5F9;">
          </div>
        </div>
        <div style="font-size:13px;font-weight:700;color:var(--danger);margin-bottom:8px;">COST PRICE</div>
        <div class="form-group">
          <input type="number" id="editCost" class="form-control" step="0.01" placeholder="Cost price" style="border:2px solid #FECACA;">
        </div>
        <div style="font-size:13px;font-weight:700;color:var(--navy-dark);margin-bottom:8px;margin-top:16px;">SELLING PRICE</div>
        <div class="form-group">
          <input type="number" id="editSelling" class="form-control" step="0.01" placeholder="Selling price" required>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:16px;">
          <div class="form-group">
            <label>Agent Price (₦)</label>
            <input type="number" id="editAgentPrice" class="form-control" step="0.01" oninput="calcAgentPrice()">
          </div>
          <div class="form-group">
            <label>Agent Discount %</label>
            <input type="number" id="editAgentDisc" class="form-control" step="0.01" oninput="calcAgentDisc()">
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="form-group">
            <label>Vendor Price (₦)</label>
            <input type="number" id="editVendorPrice" class="form-control" step="0.01" oninput="calcVendorPrice()">
          </div>
          <div class="form-group">
            <label>Vendor Discount %</label>
            <input type="number" id="editVendorDisc" class="form-control" step="0.01" oninput="calcVendorDisc()">
          </div>
        </div>
        <div class="form-group" style="margin-top:8px;">
          <label class="flex items-center gap-2" style="cursor:pointer">
            <input type="checkbox" id="editActive" checked> <span style="font-weight:600">Active</span>
          </label>
        </div>
        <div style="border-top:1px solid #E2E8F0; padding-top:12px;margin-top:12px;">
          <h5 style="margin:0 0 10px; font-size:13px; color:#334155;"><i class="fas fa-plug" style="color:var(--primary);margin-right:6px;"></i> Backup APIs <span style="font-weight:400;font-size:10px;color:#94A3B8;">(failover)</span></h5>
          <?php $api_keys=['backup_1'=>'B1','backup_2'=>'B2','backup_3'=>'B3','backup_4'=>'B4']; foreach($api_keys as $key=>$label): ?>
          <div style="display:flex;gap:8px;margin-bottom:6px;align-items:center;">
            <select class="form-control" id="rc_api_registry_<?=$key?>" style="flex:2;font-size:12px;padding:6px 8px;">
              <option value="">-- <?=$label?> --</option>
              <?php foreach($api_regs as $reg):?>
              <option value="<?=$reg['id']?>"><?=htmlspecialchars($reg['name'])?></option>
              <?php endforeach;?>
            </select>
            <input type="text" class="form-control" id="rc_api_code_<?=$key?>" placeholder="Plan ID" style="flex:1;font-size:12px;padding:6px 8px;">
          </div>
          <?php endforeach; ?>
        </div>
        <button type="submit" class="btn-primary" style="width:100%;justify-content:center;margin-top:12px;height:46px;">
          <i class="fas fa-save"></i> Save Pricing
        </button>
      </form>
    </div>
  </div>
</div>

<div class="save-toast" id="toast"></div>
<script>
const CSRF='<?=$csrf?>';
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');document.getElementById('sidebarOverlay').classList.toggle('show');}

async function loadRCBindings(pid){
  const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','get_plan_bindings');fd.append('service','recharge_card');fd.append('plan_id',pid);
  try {
    const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    if(!r.bindings) return;
    r.bindings.forEach(function(b){
      if(b.priority<2) return;
      const key=['','','backup_1','backup_2','backup_3','backup_4'][b.priority]; if(!key) return;
      const sel=document.getElementById('rc_api_registry_'+key); if(sel) sel.value=b.api_registry_id||'';
      const inp=document.getElementById('rc_api_code_'+key); if(inp) inp.value=b.product_code||'';
    });
  } catch(e){}
}
function openModal(p){
  document.getElementById('editId').value=p.id;
  document.getElementById('editNetwork').value=p.network;
  document.getElementById('editDenom').value='₦'+p.plan_name;
  document.getElementById('editCost').value=p.cost_price;
  document.getElementById('editSelling').value=p.selling_price;
  document.getElementById('editAgentPrice').value=p.agent_price||'';
  document.getElementById('editAgentDisc').value=p.agent_discount||'';
  document.getElementById('editVendorPrice').value=p.vendor_price||'';
  document.getElementById('editVendorDisc').value=p.vendor_discount||'';
  document.getElementById('editActive').checked=!!p.is_active;
  ['backup_1','backup_2','backup_3','backup_4'].forEach(function(k){
    const sel=document.getElementById('rc_api_registry_'+k); if(sel) sel.value='';
    const inp=document.getElementById('rc_api_code_'+k); if(inp) inp.value='';
  });
  document.getElementById('modalOvl').style.display='flex';
  loadRCBindings(p.id);
}
function closeModal(){document.getElementById('modalOvl').style.display='none';}
document.getElementById('modalOvl').addEventListener('click',e=>{if(e.target===e.currentTarget)closeModal();});

function calcAgentDisc(){const s=parseFloat(document.getElementById('editSelling').value)||0;const d=parseFloat(document.getElementById('editAgentDisc').value)||0;if(d>0)document.getElementById('editAgentPrice').value=(s*(1-d/100)).toFixed(2);}
function calcAgentPrice(){const s=parseFloat(document.getElementById('editSelling').value)||0;const a=parseFloat(document.getElementById('editAgentPrice').value)||0;if(a>0&&s>0)document.getElementById('editAgentDisc').value=((1-a/s)*100).toFixed(1);}
function calcVendorDisc(){const s=parseFloat(document.getElementById('editSelling').value)||0;const d=parseFloat(document.getElementById('editVendorDisc').value)||0;if(d>0)document.getElementById('editVendorPrice').value=(s*(1-d/100)).toFixed(2);}
function calcVendorPrice(){const s=parseFloat(document.getElementById('editSelling').value)||0;const v=parseFloat(document.getElementById('editVendorPrice').value)||0;if(v>0&&s>0)document.getElementById('editVendorDisc').value=((1-v/s)*100).toFixed(1);}

async function savePricing(e){
  e.preventDefault();
  const fd=new FormData();
  fd.append('csrf',CSRF);fd.append('action','save_pricing');
  fd.append('id',document.getElementById('editId').value);
  fd.append('cost_price',document.getElementById('editCost').value);
  fd.append('selling_price',document.getElementById('editSelling').value);
  fd.append('agent_price',document.getElementById('editAgentPrice').value||0);
  fd.append('vendor_price',document.getElementById('editVendorPrice').value||0);
  fd.append('discount_percent',0);
  fd.append('agent_discount',document.getElementById('editAgentDisc').value||0);
  fd.append('vendor_discount',document.getElementById('editVendorDisc').value||0);
  fd.append('is_active',document.getElementById('editActive').checked?'1':'0');
  ['backup_1','backup_2','backup_3','backup_4'].forEach(function(k){
    const sel=document.getElementById('rc_api_registry_'+k); if(sel) fd.append('api_registry_'+k, sel.value);
    const inp=document.getElementById('rc_api_code_'+k); if(inp) fd.append('api_code_'+k, inp.value);
  });
  const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
  showToast(r.message,r.success);
  if(r.success) setTimeout(()=>location.reload(),900);
}

async function togglePricing(id){
  const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','toggle_pricing');fd.append('id',id);
  await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});
}

function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),2500);}
</script>
</body>
</html>
