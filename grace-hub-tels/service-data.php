<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    $action=$_POST['action']??'';
    if($action==='save_data_plan'){
        $id=(int)($_POST['id']??0);
        $cashback_type = in_array($_POST['cashback_type']??'', ['percent','fixed']) ? $_POST['cashback_type'] : null;
        $cashback_value = $cashback_type ? (float)($_POST['cashback_value']??0) : null;
        $f=['network'=>sanitize($_POST['network']??'',20),'network_id'=>(int)($_POST['network_id']??1),'plan_id'=>sanitize($_POST['plan_id']??'',100),'plan_name'=>sanitize($_POST['plan_name']??'',200),'display_name'=>sanitize($_POST['display_name']??'',200)?:null,'data_type'=>sanitize($_POST['data_type']??'SME',50),'display_type_name'=>sanitize($_POST['display_type_name']??'',100)?:null,'data_size'=>sanitize($_POST['data_size']??'',50),'validity'=>sanitize($_POST['validity']??'',50),'cost_price'=>(float)($_POST['cost_price']??0),'selling_price'=>(float)($_POST['selling_price']??0),'agent_price'=>(float)($_POST['agent_price']??0),'vendor_price'=>(float)($_POST['vendor_price']??0),'cashback_type'=>$cashback_type,'cashback_value'=>$cashback_value,'is_active'=>isset($_POST['is_active'])?1:0];
        if($id){db()->prepare("UPDATE data_plans SET network=?,network_id=?,plan_id=?,plan_name=?,display_name=?,data_type=?,display_type_name=?,data_size=?,validity=?,cost_price=?,selling_price=?,agent_price=?,vendor_price=?,cashback_type=?,cashback_value=?,is_active=? WHERE id=?")->execute([...array_values($f),$id]);}
        else{db()->prepare("INSERT INTO data_plans (network,network_id,plan_id,plan_name,display_name,data_type,display_type_name,data_size,validity,cost_price,selling_price,agent_price,vendor_price,cashback_type,cashback_value,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute(array_values($f));}
        // Save plan API bindings
        if(!$id) $id=(int)db()->lastInsertId();
        db()->prepare("DELETE FROM plan_api_bindings WHERE service='data' AND plan_id=?")->execute([$id]);
        foreach(['backup_1'=>2,'backup_2'=>3,'backup_3'=>4,'backup_4'=>5] as $key=>$prio){
            $reg_id=(int)($_POST['api_registry_'.$key]??0);
            $code=sanitize($_POST['api_code_'.$key]??'',100);
            if($reg_id){
                db()->prepare("INSERT INTO plan_api_bindings (service,plan_id,api_registry_id,priority,product_code) VALUES ('data',?,?,?,?)")->execute([$id,$reg_id,$prio,$code?:null]);
            }
        }
        json_response(['success'=>true,'message'=>'Data plan saved']);
    }
    if($action==='delete_data_plan'){db()->prepare("DELETE FROM data_plans WHERE id=?")->execute([(int)$_POST['id']]);json_response(['success'=>true,'message'=>'Deleted']);}
    if($action==='toggle_data_plan'){db()->prepare("UPDATE data_plans SET is_active=NOT is_active WHERE id=?")->execute([(int)$_POST['id']]);json_response(['success'=>true]);}
    if($action==='toggle_data_type'){
        $t_network = sanitize($_POST['network']??'',20);
        $t_data_type = sanitize($_POST['data_type']??'',50);
        $t_stmt = db()->prepare("SELECT COUNT(*) FROM data_plans WHERE network=? AND data_type=? AND is_active=1");
        $t_stmt->execute([$t_network,$t_data_type]);
        $t_current = $t_stmt->fetchColumn();
        $t_new = ((int)$t_current > 0) ? 0 : 1;
        $t_upd = db()->prepare("UPDATE data_plans SET is_active=? WHERE network=? AND data_type=?");
        $t_upd->execute([$t_new,$t_network,$t_data_type]);
        json_response(['success'=>true,'new_state'=>$t_new]);
    }
    
    if($action==='bulk_pricing'){
        $b_network = sanitize($_POST['network']??'',20);
        $b_data_type = sanitize($_POST['data_type']??'',50);
        $markup_user = (float)($_POST['markup_user']??0);
        $markup_agent = (float)($_POST['markup_agent']??0);
        $markup_vendor = (float)($_POST['markup_vendor']??0);
        
        $sql = "UPDATE data_plans SET 
                selling_price = CEILING(cost_price + (cost_price * (? / 100))),
                agent_price = CEILING(cost_price + (cost_price * (? / 100))),
                vendor_price = CEILING(cost_price + (cost_price * (? / 100)))
                WHERE network=? AND data_type=?";
        db()->prepare($sql)->execute([$markup_user, $markup_agent, $markup_vendor, $b_network, $b_data_type]);
        json_response(['success'=>true, 'message'=>'Bulk pricing applied successfully']);
    }
    
    if($action==='reorder_data_plans'){
        $orders = json_decode($_POST['orders']??'[]', true);
        if(is_array($orders)){
            $stmt = db()->prepare("UPDATE data_plans SET display_order=? WHERE id=?");
            foreach($orders as $o){
                $stmt->execute([(int)$o['order'], (int)$o['id']]);
            }
        }
        json_response(['success'=>true]);
    }

    if($action==='get_plan_bindings'){
        $pid=(int)($_POST['plan_id']??0);
        $b_stmt=db()->prepare("SELECT b.*,r.name as api_name FROM plan_api_bindings b LEFT JOIN api_registry r ON r.id=b.api_registry_id WHERE b.service='data' AND b.plan_id=? ORDER BY b.priority");
        $b_stmt->execute([$pid]);
        $bindings=$b_stmt->fetchAll(PDO::FETCH_ASSOC);
        json_response(['success'=>true,'bindings'=>$bindings]);
    }

    if($action==='save_cable_plan'){
        $id=(int)($_POST['id']??0);
        $f=[sanitize($_POST['provider']??'',50),(int)($_POST['cable_id']??0),sanitize($_POST['plan_id']??'',100),sanitize($_POST['plan_name']??'',200),sanitize($_POST['display_name']??'',200)?:null,(float)($_POST['cost_price']??0),(float)($_POST['selling_price']??0),(float)($_POST['agent_price']??0),(float)($_POST['vendor_price']??0),isset($_POST['is_active'])?1:0];
        if($id){db()->prepare("UPDATE cable_plans SET provider=?,cable_id=?,plan_id=?,plan_name=?,display_name=?,cost_price=?,selling_price=?,agent_price=?,vendor_price=?,is_active=? WHERE id=?")->execute([...$f,$id]);}
        else{db()->prepare("INSERT INTO cable_plans (provider,cable_id,plan_id,plan_name,display_name,cost_price,selling_price,agent_price,vendor_price,is_active) VALUES (?,?,?,?,?,?,?,?,?,?)")->execute($f);}
        json_response(['success'=>true,'message'=>'Cable plan saved']);
    }
    if($action==='delete_cable_plan'){db()->prepare("DELETE FROM cable_plans WHERE id=?")->execute([(int)$_POST['id']]);json_response(['success'=>true,'message'=>'Deleted']);}

    if($action==='save_disco'){
        $id=(int)($_POST['id']??0);
        $f=[sanitize($_POST['disco_name']??'',100),(int)($_POST['disco_id']??0),sanitize($_POST['logo_key']??'',50),(float)($_POST['charge']??100),(float)($_POST['agent_charge']??0),(float)($_POST['vendor_charge']??0),isset($_POST['is_active'])?1:0];
        if($id){db()->prepare("UPDATE electricity_discos SET disco_name=?,disco_id=?,logo_key=?,charge=?,agent_charge=?,vendor_charge=?,is_active=? WHERE id=?")->execute([...$f,$id]);}
        else{db()->prepare("INSERT INTO electricity_discos (disco_name,disco_id,logo_key,charge,agent_charge,vendor_charge,is_active) VALUES (?,?,?,?,?,?,?)")->execute($f);}
        json_response(['success'=>true,'message'=>'Disco saved']);
    }
    if($action==='save_pricing'){
        $id=(int)($_POST['id']??0);
        if($id){db()->prepare("UPDATE service_pricing SET selling_price=?,agent_price=?,vendor_price=?,discount_percent=?,agent_discount=?,vendor_discount=?,is_active=? WHERE id=?")->execute([(float)$_POST['selling_price'],(float)($_POST['agent_price']??0),(float)($_POST['vendor_price']??0),(float)$_POST['discount_percent'],(float)($_POST['agent_discount']??0),(float)($_POST['vendor_discount']??0),isset($_POST['is_active'])?1:0,$id]);json_response(['success'=>true,'message'=>'Pricing updated']);}
        json_response(['success'=>false,'message'=>'Missing ID']);
    }

    json_response(['success'=>false],400);
}
$csrf=admin_csrf();
$network_filter = sanitize($_GET['network'] ?? '', 20);
if ($network_filter) {
    $stmt = db()->prepare("SELECT * FROM data_plans WHERE network=? ORDER BY data_type,display_order,selling_price");
    $stmt->execute([$network_filter]);
    $data_plans = $stmt->fetchAll();
} else {
    $data_plans = db()->query("SELECT * FROM data_plans ORDER BY network,data_type,display_order,selling_price")->fetchAll();
}
$cable_plans=db()->query("SELECT * FROM cable_plans ORDER BY provider,selling_price")->fetchAll();
$discos=db()->query("SELECT * FROM electricity_discos ORDER BY disco_name")->fetchAll();
$pricing=db()->query("SELECT * FROM service_pricing ORDER BY service,network")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Data Plans – Gracehubtels Admin</title>
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

    <div class="section-header">
        <div>
            <h2>Data Plans</h2>
            <p>Manage Data Plans</p>
        </div>
    </div>
    <div class="data-panel">
  <div class="panel-title-bar">
      <h3><i class="fas fa-wifi" style="color:var(--primary);margin-right:8px"></i> Data Plans Configuration</h3>
      <button class="btn-primary" onclick="openDataModal()"><i class="fas fa-plus"></i> Add Plan</button>
  </div>
  <div style="padding:12px 20px;font-size:13px;color:var(--text-muted);display:flex;align-items:flex-start;gap:12px;background:var(--bg);border-bottom:1px solid var(--border)">
    <i class="fas fa-triangle-exclamation" style="margin-top:2px;color:var(--warning)"></i>
    <div>
        <strong>Internal Type</strong> (e.g. "SME") is used for strict backend provider validation. <strong>Display Type Name</strong> is a cosmetic label shown to end-users (e.g. rename "SME" to "Jaz-Prime").<br>
        <strong>Tip:</strong> Drag and drop rows using the <i class="fas fa-grip-vertical"></i> icon to reorder plans.
    </div>
  </div>

  <div class="filter-tabs" style="margin:14px 20px;border:1px solid var(--border)">
    <a href="<?= ADMIN_URL ?>/service-data.php" class="filter-tab <?=empty($network_filter)?'active':''?>">All</a>
    <?php foreach(['MTN','GLO','AIRTEL','9MOBILE'] as $net):?>
    <a href="<?= ADMIN_URL ?>/service-data.php?network=<?=$net?>" class="filter-tab <?=$network_filter===$net?'active':''?>"><?=$net?></a>
    <?php endforeach;?>
    <?php if($network_filter):?>
    <span style="margin-left:auto;font-size:12px;color:var(--text-muted);align-self:center;padding:0 8px"><i class="fas fa-filter"></i> Showing: <strong><?=htmlspecialchars($network_filter)?></strong></span>
    <?php endif;?>
  </div>

  <?php
  $grouped_plans = [];
  foreach($data_plans as $p) {
      $grouped_plans[$p['data_type']][] = $p;
  }
  foreach($grouped_plans as $type => $plans): 
  ?>
  <div class="type-section" style="margin-top:20px; border:1px solid #E2E8F0; border-radius:8px; overflow:hidden;">
      <div style="background:#F1F5F9; padding:12px 20px; border-bottom:1px solid #E2E8F0; display:flex; align-items:center; gap:10px;">
          <div style="width:12px; height:12px; background:var(--primary); border-radius:50%;"></div>
          <h4 style="margin:0; font-weight:700; color:#334155; font-size:15px; letter-spacing:0.5px;"><?=htmlspecialchars($type)?> PLANS</h4>
          <span style="background:#E2E8F0; color:#475569; font-size:11px; font-weight:700; padding:2px 8px; border-radius:12px;"><?=count($plans)?> Plans</span>
          <?php if($network_filter):?>
          <button class="btn-toggle-type" onclick="openBulkPricing('<?=$network_filter?>','<?=htmlspecialchars($type,ENT_QUOTES)?>')" style="margin-left:auto; background:none; border:1px solid var(--primary); border-radius:6px; padding:4px 10px; cursor:pointer; font-size:12px; color:var(--primary); font-weight:600;" title="Bulk set pricing for <?=htmlspecialchars($type)?> plans"><i class="fas fa-percent"></i> Bulk Price</button>
          <button class="btn-toggle-type" onclick="toggleDataType('<?=$network_filter?>','<?=htmlspecialchars($type,ENT_QUOTES)?>')" style="margin-left:8px; background:none; border:1px solid #CBD5E1; border-radius:6px; padding:4px 10px; cursor:pointer; font-size:12px; color:#475569;" title="Enable/disable all <?=htmlspecialchars($type)?> plans"><i class="fas fa-power-off"></i> Toggle All</button>
          <?php endif;?>
      </div>
      <div class="responsive-grid-wrapper">
        <table class="data-table" style="margin-bottom:0;">
          <thead>
            <tr>
                <th style="width:52px; text-align:center; font-size:11px;">Reorder</th>
                <th>#</th>
                <th>Plan</th>
                <th>Plan Id</th>
                <th>Price</th>
                <th>User</th>
                <th>Agent</th>
                <th>Vendor</th>
                <th>Status</th>
                <th style="width:40px;">Edit</th>
                <th style="width:40px;">Del</th>
            </tr>
          </thead>
          <tbody class="sortable-group">
          <?php $i=1; foreach($plans as $p):?>
          <tr data-id="<?=$p['id']?>" style="background:#fff;">
            <td style="text-align:center; white-space:nowrap; font-size:13px;">
              <button class="btn-sort-up" onclick="moveRow(this,-1)" title="Move up" style="cursor:pointer;border:none;background:none;color:#64748B;padding:2px 4px;"><i class="fas fa-chevron-up"></i></button>
              <button class="btn-sort-dn" onclick="moveRow(this,1)" title="Move down" style="cursor:pointer;border:none;background:none;color:#64748B;padding:2px 4px;"><i class="fas fa-chevron-down"></i></button>
            </td>
            <td style="color:#64748B; font-weight:500;"><?=$i++?></td>
            <td>
                <div style="font-weight:600; color:#475569; font-size:13px;"><?=htmlspecialchars($p['plan_name'] ?: ($p['data_size'].' ('.$p['network'].' '.$p['data_type'].') ('.$p['validity'].')'))?></div>
                <?php if($p['cashback_type'] && $p['cashback_value']): ?>
                <div style="font-size:11px; color:var(--primary); margin-top:2px;"><i class="fas fa-coins"></i> Cashback: <?=$p['cashback_type']==='percent'?$p['cashback_value'].'%':'₦'.number_format((float)$p['cashback_value'],2)?></div>
                <?php endif; ?>
            </td>
            <td style="color:#64748B; font-size:13px;"><?=$p['plan_id']?></td>
            <td style="color:#64748B; font-size:13px;">₦ <?=(float)$p['cost_price']?></td>
            <td style="color:#64748B; font-size:13px;">₦ <?=(float)$p['selling_price']?></td>
            <td style="color:#64748B; font-size:13px;">₦ <?=(float)($p['agent_price']??$p['selling_price'])?></td>
            <td style="color:#64748B; font-size:13px;">₦ <?=(float)($p['vendor_price']??$p['selling_price'])?></td>
            <td><label class="toggle"><input type="checkbox" <?=$p['is_active']?'checked':''?> onchange="toggleDataPlan(<?=$p['id']?>)"><span class="slider"></span></label></td>
            <td style="width:40px;">
                <button class="btn-primary" style="background:#F97316; padding:6px 12px; border-radius:4px; font-size:12px;" onclick='editDataPlan(<?=htmlspecialchars(json_encode($p),ENT_QUOTES)?>)'><i class="fas fa-edit"></i></button>
            </td>
            <td style="width:40px;">
                <button class="btn-primary" style="background:var(--danger); padding:6px 12px; border-radius:4px; font-size:12px;" onclick="deleteDataPlan(<?=$p['id']?>)"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
          <?php endforeach;?>
          </tbody>
        </table>
      </div>
  </div>
  <?php endforeach; ?>
</div>

</div>
</div>




<?php $api_regs = db()->query("SELECT id, name FROM api_registry WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); ?>
<div class="modal-overlay" id="dataModal" style="display:none; align-items:center; justify-content:center;">
    <div class="modal-card" style="background:transparent; width:100%; max-width:600px; border-radius:8px; box-shadow:0 10px 25px rgba(0,0,0,0.15); overflow:hidden;">
        <div style="background:var(--primary); color:#fff; padding:16px 20px; font-weight:600; font-size:16px;">
            <span id="dataModalTitle">Add New Plan</span>
        </div>
        <div class="modal-body" style="padding:24px; max-height:80vh; overflow-y:auto; background:#fff; display:flex; flex-direction:column; gap:16px;">
            <input type="hidden" id="d_id">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="form-group"><label>Network</label><input type="text" class="form-control" id="d_network" placeholder="MTN"></div>
                <div class="form-group"><label>Plan ID</label><input type="text" class="form-control" id="d_plan_id"></div>
            </div>
            <div class="form-group"><label>Plan Name / Size</label><input type="text" class="form-control" id="d_plan_name"></div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="form-group"><label>Data Type</label><input type="text" class="form-control" id="d_data_type" placeholder="SME"></div>
                <div class="form-group"><label>Validity</label><input type="text" class="form-control" id="d_validity" placeholder="30 Days"></div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="form-group"><label>Cost Price</label><input type="number" class="form-control" id="d_cost_price"></div>
                <div class="form-group"><label>Selling Price</label><input type="number" class="form-control" id="d_selling_price"></div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="form-group"><label>Agent Price</label><input type="number" class="form-control" id="d_agent_price"></div>
                <div class="form-group"><label>Vendor Price</label><input type="number" class="form-control" id="d_vendor_price"></div>
            </div>
            <div style="border-top:1px solid #E2E8F0; padding-top:16px;">
                <h5 style="margin:0 0 12px; font-size:14px; color:#334155;"><i class="fas fa-coins" style="color:var(--primary);margin-right:6px;"></i> Per-Plan Cashback <span style="font-weight:400;font-size:11px;color:#94A3B8;">(leave blank to use global setting)</span></h5>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                    <div class="form-group"><label>Cashback Type</label>
                        <select class="form-control" id="d_cashback_type">
                            <option value="">-- None (Use Global) --</option>
                            <option value="percent">Percentage (%)</option>
                            <option value="fixed">Flat Amount (₦)</option>
                        </select>
                    </div>
                    <div class="form-group"><label>Cashback Value</label><input type="number" class="form-control" id="d_cashback_value" step="0.01" placeholder="e.g. 1.5 or 50"></div>
                </div>
            </div>
            <div style="border-top:1px solid #E2E8F0; padding-top:16px;">
                <h5 style="margin:0 0 12px; font-size:14px; color:#334155;"><i class="fas fa-plug" style="color:var(--primary);margin-right:6px;"></i> Backup APIs (failover) <span style="font-weight:400;font-size:11px;color:#94A3B8;">(optional — tried in order if primary fails)</span> <span style="font-weight:400;font-size:11px;color:#64748B;display:block;margin-top:4px;">The system plan ID at the top is used as primary. These backups are tried when the primary API returns insufficient balance.</span></h5>
                <div style="display:grid; grid-template-columns:2fr 1fr; gap:12px; margin-bottom:8px;">
                    <div class="form-group"><label>Backup 1 API</label>
                        <select class="form-control" id="d_api_registry_backup_1">
                            <option value="">-- None --</option>
                            <?php foreach($api_regs as $r):?>
                            <option value="<?=$r['id']?>"><?=htmlspecialchars($r['name'])?></option>
                            <?php endforeach;?>
                        </select>
                    </div>
                    <div class="form-group"><label>Plan ID</label><input type="text" class="form-control" id="d_api_code_backup_1"></div>
                </div>
                <div style="display:grid; grid-template-columns:2fr 1fr; gap:12px; margin-bottom:8px;">
                    <div class="form-group"><label>Backup 2 API</label>
                        <select class="form-control" id="d_api_registry_backup_2">
                            <option value="">-- None --</option>
                            <?php foreach($api_regs as $r):?>
                            <option value="<?=$r['id']?>"><?=htmlspecialchars($r['name'])?></option>
                            <?php endforeach;?>
                        </select>
                    </div>
                    <div class="form-group"><label>Plan ID</label><input type="text" class="form-control" id="d_api_code_backup_2"></div>
                </div>
                <div style="display:grid; grid-template-columns:2fr 1fr; gap:12px; margin-bottom:8px;">
                    <div class="form-group"><label>Backup 3 API</label>
                        <select class="form-control" id="d_api_registry_backup_3">
                            <option value="">-- None --</option>
                            <?php foreach($api_regs as $r):?>
                            <option value="<?=$r['id']?>"><?=htmlspecialchars($r['name'])?></option>
                            <?php endforeach;?>
                        </select>
                    </div>
                    <div class="form-group"><label>Plan ID</label><input type="text" class="form-control" id="d_api_code_backup_3"></div>
                </div>
                <div style="display:grid; grid-template-columns:2fr 1fr; gap:12px; margin-bottom:8px;">
                    <div class="form-group"><label>Backup 4 API</label>
                        <select class="form-control" id="d_api_registry_backup_4">
                            <option value="">-- None --</option>
                            <?php foreach($api_regs as $r):?>
                            <option value="<?=$r['id']?>"><?=htmlspecialchars($r['name'])?></option>
                            <?php endforeach;?>
                        </select>
                    </div>
                    <div class="form-group"><label>Plan ID</label><input type="text" class="form-control" id="d_api_code_backup_4"></div>
                </div>
            </div>
            <div style="display:flex; justify-content:space-between; margin-top:8px;">
                <button class="btn-primary" onclick="saveDataPlan()"><i class="fas fa-save" style="margin-right:6px;"></i> Save</button>
                <button class="btn-ghost" onclick="document.getElementById('dataModal').style.display='none'">CLOSE</button>
            </div>
        </div>
    </div>
</div>
<div class="save-toast" id="toast"></div>
    <!-- Bulk Pricing Modal -->
    <div class="modal-overlay" id="bulkPricingModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,.5); z-index:999; align-items:center; justify-content:center;">
      <div class="modal-card" style="background:#fff; width:95%; max-width:500px; border-radius:8px; box-shadow:0 10px 25px rgba(0,0,0,0.15); overflow:hidden; position:relative;">
        <div style="background:var(--primary); color:#fff; padding:16px 20px; display:flex; justify-content:space-between; align-items:center; font-weight:600; font-size:16px;">
          <span style="margin:0;"><i class="fas fa-tags" style="margin-right:8px"></i> Bulk Set Prices</span>
          <button onclick="document.getElementById('bulkPricingModal').style.display='none'" style="background:none; border:none; color:#fff; cursor:pointer; font-size:16px; padding:4px;"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" style="padding:20px; max-height:80vh; overflow-y:auto; display:flex; flex-direction:column; gap:16px;">
          <input type="hidden" id="bp_network">
          <input type="hidden" id="bp_data_type">
          
          <div style="font-size:13px; color:var(--text-muted); line-height:1.5;">
              Enter the <b>percentage markup</b> you want to apply on top of the <b>Cost Price</b> for each tier. (e.g. 10 for 10%, 5 for 5%). Leave blank or 0 to set price equal to cost price.
          </div>
          
          <div class="form-group">
            <label>User Tier Markup (%)</label>
            <input type="number" step="any" class="form-control" id="bp_markup_user" placeholder="e.g. 10">
          </div>
          <div class="form-group">
            <label>Agent Tier Markup (%)</label>
            <input type="number" step="any" class="form-control" id="bp_markup_agent" placeholder="e.g. 5">
          </div>
          <div class="form-group">
            <label>Vendor Tier Markup (%)</label>
            <input type="number" step="any" class="form-control" id="bp_markup_vendor" placeholder="e.g. 2">
          </div>
          
          <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:8px;">
            <button class="btn-ghost" onclick="document.getElementById('bulkPricingModal').style.display='none'" style="border:1px solid var(--border); padding:8px 16px;">Cancel</button>
            <button class="btn-primary" onclick="applyBulkPricing()" style="padding:8px 16px;"><i class="fas fa-check"></i> Apply Pricing</button>
          </div>
        </div>
      </div>
    </div>
<script>
const CSRF='<?=$csrf?>';
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
function showTab(t,btn){
    document.querySelectorAll('[id^="tab-"]').forEach(e=>e.style.display='none');
    document.getElementById('tab-'+t).style.display='block';
    document.querySelectorAll('.filter-tab').forEach(b=>b.classList.remove('active'));
    if(btn) btn.classList.add('active');
    else {
        // If btn not passed, find the tab and activate it
        const tabs = document.querySelectorAll('.filter-tab');
        if(t==='data') tabs[0].classList.add('active');
        if(t==='cable') tabs[1].classList.add('active');
        if(t==='disco') tabs[2].classList.add('active');
        if(t==='pricing') tabs[3].classList.add('active');
    }
}

document.addEventListener("DOMContentLoaded", () => {
    const activeTab = sessionStorage.getItem('serviceTab');
    if (activeTab) {
        showTab(activeTab, null);
        sessionStorage.removeItem('serviceTab'); // Clear it so it doesn't stick forever
    }
});

// DATA PLANS
function openDataModal(){
    document.getElementById('dataModalTitle').innerHTML='Add New Plan';
    ['d_id','d_plan_id','d_plan_name','d_data_type','d_validity','d_cost_price','d_selling_price','d_agent_price','d_vendor_price','d_network','d_cashback_value'].forEach(i=>{if(document.getElementById(i))document.getElementById(i).value=''});
    if(document.getElementById('d_cashback_type')) document.getElementById('d_cashback_type').value='';
    ['d_api_registry_backup_1','d_api_registry_backup_2','d_api_registry_backup_3','d_api_registry_backup_4','d_api_code_backup_1','d_api_code_backup_2','d_api_code_backup_3','d_api_code_backup_4'].forEach(i=>{if(document.getElementById(i))document.getElementById(i).value=''});
    document.getElementById('dataModal').style.display='flex';
}
function editDataPlan(p){
    document.getElementById('dataModalTitle').innerHTML='Edit Data Plan';
    document.getElementById('d_id').value=p.id;
    document.getElementById('d_network').value=p.network;
    document.getElementById('d_plan_id').value=p.plan_id;
    document.getElementById('d_plan_name').value=p.plan_name;
    document.getElementById('d_data_type').value=p.data_type;
    document.getElementById('d_validity').value=p.validity||'';
    document.getElementById('d_cost_price').value=p.cost_price;
    document.getElementById('d_selling_price').value=p.selling_price;
    document.getElementById('d_agent_price').value=p.agent_price||'0';
    document.getElementById('d_vendor_price').value=p.vendor_price||'0';
    document.getElementById('d_cashback_type').value=p.cashback_type||'';
    document.getElementById('d_cashback_value').value=p.cashback_value||'';
    ['d_api_registry_backup_1','d_api_registry_backup_2','d_api_registry_backup_3','d_api_registry_backup_4','d_api_code_backup_1','d_api_code_backup_2','d_api_code_backup_3','d_api_code_backup_4'].forEach(i=>{if(document.getElementById(i))document.getElementById(i).value=''});
    document.getElementById('dataModal').style.display='flex';
    const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','get_plan_bindings');fd.append('plan_id',p.id);
    fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json()).then(d=>{
        if(d.success&&d.bindings) d.bindings.forEach(function(b){
            if(b.priority<2) return;
            const key=['','','backup_1','backup_2','backup_3','backup_4'][b.priority];
            const sel=document.getElementById('d_api_registry_'+key);
            if(sel)sel.value=b.api_registry_id;
            const inp=document.getElementById('d_api_code_'+key);
            if(inp)inp.value=b.product_code||'';
        });
    });
}
async function saveDataPlan(){
    const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','save_data_plan');
    ['id','network','network_id','plan_id','plan_name','display_name','data_type','display_type_name','data_size','validity','cost_price','selling_price','agent_price','vendor_price','cashback_type','cashback_value'].forEach(k=>{
        if(document.getElementById('d_'+k)) fd.append(k,document.getElementById('d_'+k).value);
    });
    fd.append('is_active','1');
    ['backup_1','backup_2','backup_3','backup_4'].forEach(k=>{
        const sel=document.getElementById('d_api_registry_'+k);if(sel)fd.append('api_registry_'+k,sel.value);
        const inp=document.getElementById('d_api_code_'+k);if(inp)fd.append('api_code_'+k,inp.value);
    });
    const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);
}
async function deleteDataPlan(id){if(!confirm('Delete this plan?'))return;const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','delete_data_plan');fd.append('id',id);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),700);}
async function toggleDataPlan(id){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','toggle_data_plan');fd.append('id',id);await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});setTimeout(()=>location.reload(),300);}
async function toggleDataType(network, dataType){
    if(!confirm('Toggle all '+dataType+' plans for '+network+'?')) return;
    const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','toggle_data_type');fd.append('network',network);fd.append('data_type',dataType);
    const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    showToast('All '+dataType+' plans '+(r.new_state?'enabled':'disabled'),r.success);
    if(r.success)setTimeout(()=>location.reload(),700);
}

function openBulkPricing(network, dataType) {
    document.getElementById('bp_network').value = network;
    document.getElementById('bp_data_type').value = dataType;
    document.getElementById('bp_markup_user').value = '';
    document.getElementById('bp_markup_agent').value = '';
    document.getElementById('bp_markup_vendor').value = '';
    document.getElementById('bulkPricingModal').style.display = 'flex';
}

async function applyBulkPricing() {
    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('action', 'bulk_pricing');
    fd.append('network', document.getElementById('bp_network').value);
    fd.append('data_type', document.getElementById('bp_data_type').value);
    fd.append('markup_user', document.getElementById('bp_markup_user').value || 0);
    fd.append('markup_agent', document.getElementById('bp_markup_agent').value || 0);
    fd.append('markup_vendor', document.getElementById('bp_markup_vendor').value || 0);
    
    if(!confirm('Are you sure you want to apply this percentage markup to ALL ' + document.getElementById('bp_data_type').value + ' plans for ' + document.getElementById('bp_network').value + '? This will overwrite their current selling prices based on their Cost Price.')) return;
    
    const r = await fetch(location.href, {method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: fd}).then(r => r.json());
    showToast(r.message, r.success);
    if(r.success) setTimeout(() => location.reload(), 900);
}

// CABLE PLANS
function openCableModal(){
    document.getElementById('cableModalTitle').innerHTML='<i class="fas fa-plus"></i> Add Cable Plan';
    ['c_id','c_cable_id','c_plan_id','c_plan_name','c_display_name','c_cost_price','c_selling_price','c_agent_price','c_vendor_price'].forEach(i=>document.getElementById(i).value='');
    document.getElementById('c_is_active').checked=true;
    document.getElementById('cableModal').style.display='flex';
}
function editCablePlan(p){
    document.getElementById('cableModalTitle').innerHTML='<i class="fas fa-edit"></i> Edit Cable Plan';
    document.getElementById('c_id').value=p.id;
    document.getElementById('c_provider').value=p.provider;
    document.getElementById('c_cable_id').value=p.cable_id;
    document.getElementById('c_plan_id').value=p.plan_id;
    document.getElementById('c_plan_name').value=p.plan_name;
    document.getElementById('c_display_name').value=p.display_name||'';
    document.getElementById('c_cost_price').value=p.cost_price;
    document.getElementById('c_selling_price').value=p.selling_price;
    document.getElementById('c_agent_price').value=p.agent_price||'0';
    document.getElementById('c_vendor_price').value=p.vendor_price||'0';
    document.getElementById('c_is_active').checked=p.is_active==1;
    document.getElementById('cableModal').style.display='flex';
}
async function saveCablePlan(){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','save_cable_plan');['id','provider','cable_id','plan_id','plan_name','display_name','cost_price','selling_price','agent_price','vendor_price'].forEach(k=>fd.append(k,document.getElementById('c_'+k).value));if(document.getElementById('c_is_active').checked)fd.append('is_active','1');const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);}
async function deleteCablePlan(id){if(!confirm('Delete?'))return;const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','delete_cable_plan');fd.append('id',id);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),700);}

// DISCOS
function openDiscoModal(){document.getElementById('discoModalTitle').innerHTML='<i class="fas fa-plus"></i> Add Disco';['x_id','x_disco_id','x_disco_name','x_logo_key','x_charge','x_agent_charge','x_vendor_charge'].forEach(i=>document.getElementById(i).value='');document.getElementById('x_is_active').checked=true;document.getElementById('discoModal').style.display='flex';}
function editDisco(d){document.getElementById('discoModalTitle').innerHTML='<i class="fas fa-edit"></i> Edit Disco';document.getElementById('x_id').value=d.id;document.getElementById('x_disco_name').value=d.disco_name;document.getElementById('x_disco_id').value=d.disco_id;document.getElementById('x_logo_key').value=d.logo_key||'';document.getElementById('x_charge').value=d.charge;document.getElementById('x_agent_charge').value=d.agent_charge||'0';document.getElementById('x_vendor_charge').value=d.vendor_charge||'0';document.getElementById('x_is_active').checked=d.is_active==1;document.getElementById('discoModal').style.display='flex';}
async function saveDisco(){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','save_disco');['id','disco_name','disco_id','logo_key','charge','agent_charge','vendor_charge'].forEach(k=>fd.append(k,document.getElementById('x_'+k).value));if(document.getElementById('x_is_active').checked)fd.append('is_active','1');const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);}

// PRICING
function editPricing(p){document.getElementById('pr_id').value=p.id;document.getElementById('pr_selling_price').value=p.selling_price;document.getElementById('pr_discount_percent').value=p.discount_percent;document.getElementById('pr_agent_price').value=p.agent_price||'0';document.getElementById('pr_vendor_price').value=p.vendor_price||'0';document.getElementById('pr_agent_discount').value=p.agent_discount||'0';document.getElementById('pr_vendor_discount').value=p.vendor_discount||'0';document.getElementById('pr_is_active').checked=p.is_active==1;document.getElementById('pricingModal').style.display='flex';}
async function savePricing(){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','save_pricing');fd.append('id',document.getElementById('pr_id').value);fd.append('selling_price',document.getElementById('pr_selling_price').value);fd.append('discount_percent',document.getElementById('pr_discount_percent').value);fd.append('agent_price',document.getElementById('pr_agent_price').value);fd.append('vendor_price',document.getElementById('pr_vendor_price').value);fd.append('agent_discount',document.getElementById('pr_agent_discount').value);fd.append('vendor_discount',document.getElementById('pr_vendor_discount').value);if(document.getElementById('pr_is_active').checked)fd.append('is_active','1');const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);}

function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),2500);}

function moveRow(btn,dir){
    var tr=btn.closest('tr'),tbody=tr.closest('tbody'),target=dir<0?tr.previousElementSibling:tr.nextElementSibling;
    if(!target||target.tagName!=='TR')return;
    tbody.insertBefore(tr,dir<0?target:target.nextSibling);
    saveOrder(tbody);
}

function saveOrder(tbody){
    var rows=tbody.querySelectorAll('tr[data-id]'),orders=[];
    rows.forEach(function(r,i){orders.push({id:r.getAttribute('data-id'),order:i});});
    var fd=new FormData();
    fd.append('csrf',CSRF);
    fd.append('action','reorder_data_plans');
    fd.append('orders',JSON.stringify(orders));
    fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(function(r){return r.json();})
    .then(function(r){showToast(r.success?'Order saved':'Failed to save order',r.success);});
}
</script>

</body>
</html>