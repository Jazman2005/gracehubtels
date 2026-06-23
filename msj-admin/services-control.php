<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    $action=$_POST['action']??'';
    if($action==='save_data_plan'){
        $id=(int)($_POST['id']??0);
        $f=['network'=>sanitize($_POST['network']??'',20),'network_id'=>(int)($_POST['network_id']??1),'plan_id'=>(int)($_POST['plan_id']??0),'plan_name'=>sanitize($_POST['plan_name']??'',200),'display_name'=>sanitize($_POST['display_name']??'',200)?:null,'data_type'=>sanitize($_POST['data_type']??'SME',50),'display_type_name'=>sanitize($_POST['display_type_name']??'',100)?:null,'data_size'=>sanitize($_POST['data_size']??'',50),'validity'=>sanitize($_POST['validity']??'',50),'cost_price'=>(float)($_POST['cost_price']??0),'selling_price'=>(float)($_POST['selling_price']??0),'agent_price'=>(float)($_POST['agent_price']??0),'vendor_price'=>(float)($_POST['vendor_price']??0),'is_active'=>isset($_POST['is_active'])?1:0];
        if($id){db()->prepare("UPDATE data_plans SET network=?,network_id=?,plan_id=?,plan_name=?,display_name=?,data_type=?,display_type_name=?,data_size=?,validity=?,cost_price=?,selling_price=?,agent_price=?,vendor_price=?,is_active=? WHERE id=?")->execute([...array_values($f),$id]);}
        else{db()->prepare("INSERT INTO data_plans (network,network_id,plan_id,plan_name,display_name,data_type,display_type_name,data_size,validity,cost_price,selling_price,agent_price,vendor_price,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute(array_values($f));}
        json_response(['success'=>true,'message'=>'Data plan saved']);
    }
    if($action==='delete_data_plan'){db()->prepare("DELETE FROM data_plans WHERE id=?")->execute([(int)$_POST['id']]);json_response(['success'=>true,'message'=>'Deleted']);}
    if($action==='toggle_data_plan'){db()->prepare("UPDATE data_plans SET is_active=NOT is_active WHERE id=?")->execute([(int)$_POST['id']]);json_response(['success'=>true]);}

    if($action==='save_cable_plan'){
        $id=(int)($_POST['id']??0);
        $f=[sanitize($_POST['provider']??'',50),(int)($_POST['cable_id']??0),(int)($_POST['plan_id']??0),sanitize($_POST['plan_name']??'',200),sanitize($_POST['display_name']??'',200)?:null,(float)($_POST['cost_price']??0),(float)($_POST['selling_price']??0),(float)($_POST['agent_price']??0),(float)($_POST['vendor_price']??0),isset($_POST['is_active'])?1:0];
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
$data_plans=db()->query("SELECT * FROM data_plans ORDER BY network,data_type,selling_price")->fetchAll();
$cable_plans=db()->query("SELECT * FROM cable_plans ORDER BY provider,selling_price")->fetchAll();
$discos=db()->query("SELECT * FROM electricity_discos ORDER BY disco_name")->fetchAll();
$pricing=db()->query("SELECT * FROM service_pricing ORDER BY service,network")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Services & Pricing – MsjPay Admin</title>
    <link rel="icon" type="image/png" href="/msjpay/assets/images/msjpay-favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/msjpay/msj-admin/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="page-content">

<div class="page-head">
    <div>
        <h2>Services & Pricing</h2>
        <p>Manage data plans, cable bouquets, electricity discos, and base pricing structures.</p>
    </div>
</div>

<div class="filter-tabs" style="margin-bottom:24px; display:inline-flex; border-radius:12px; background:#fff; border:1px solid #E2E8F0; padding:4px;">
  <button class="filter-tab active" onclick="showTab('data',this)" style="border-radius:8px; display:flex; align-items:center; gap:6px; padding:10px 16px;">
    <i class="fas fa-wifi"></i> Data Plans
  </button>
  <button class="filter-tab" onclick="showTab('cable',this)" style="border-radius:8px; display:flex; align-items:center; gap:6px; padding:10px 16px;">
    <i class="fas fa-tv"></i> Cable Plans
  </button>
  <button class="filter-tab" onclick="showTab('disco',this)" style="border-radius:8px; display:flex; align-items:center; gap:6px; padding:10px 16px;">
    <i class="fas fa-lightbulb"></i> Electricity Discos
  </button>
  <button class="filter-tab" onclick="showTab('pricing',this)" style="border-radius:8px; display:flex; align-items:center; gap:6px; padding:10px 16px;">
    <i class="fas fa-tags"></i> Other Pricing
  </button>
</div>

<!-- DATA PLANS -->
<div id="tab-data" class="card fade-in">
  <div class="card-header" style="border-bottom:1px solid #E2E8F0; padding-bottom:16px;">
      <h3><i class="fas fa-wifi" style="color:var(--primary);margin-right:8px"></i> Data Plans Configuration</h3>
      <button class="btn-primary" onclick="openDataModal()" style="font-size:13px; padding:8px 16px; border-radius:8px;">
          <i class="fas fa-plus"></i> Add Plan
      </button>
  </div>
  <div style="background:#FFF7ED;border-bottom:1px solid #FDE68A;padding:12px 20px;font-size:13px;color:#92400E;display:flex;align-items:flex-start;gap:12px;">
    <i class="fas fa-triangle-exclamation" style="margin-top:2px;"></i>
    <div>
        <strong>Internal Type</strong> (e.g. "SME") is used for strict backend provider validation. <strong>Display Type Name</strong> is a cosmetic label shown to end-users (e.g. rename "SME" to "Jaz-Prime").
    </div>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
            <th>#</th>
            <th>Plan</th>
            <th>Plan Id</th>
            <th>Price</th>
            <th>User</th>
            <th>Agent</th>
            <th>Vendor</th>
            <th colspan="2" style="text-align:center;">Action</th>
        </tr>
      </thead>
      <tbody>
      <?php $i=1; foreach($data_plans as $p):?>
      <tr>
        <td style="color:#64748B; font-weight:500;"><?=$i++?></td>
        <td>
            <div style="font-weight:600; color:#475569; font-size:13px;"><?=htmlspecialchars($p['plan_name'] ?: ($p['data_size'].' ('.$p['network'].' '.$p['data_type'].') ('.$p['validity'].')'))?></div>
            <div style="font-size:12px; color:<?=$p['is_active']?'#10B981':'#EF4444'?>; margin-top:4px;">Status: <?=$p['is_active']?'Active':'Inactive'?></div>
        </td>
        <td style="color:#64748B; font-size:13px;"><?=$p['plan_id']?></td>
        <td style="color:#64748B; font-size:13px;">₦ <?=(float)$p['cost_price']?></td>
        <td style="color:#64748B; font-size:13px;">₦ <?=(float)$p['selling_price']?></td>
        <td style="color:#64748B; font-size:13px;">₦ <?=(float)($p['agent_price']??$p['selling_price'])?></td>
        <td style="color:#64748B; font-size:13px;">₦ <?=(float)($p['vendor_price']??$p['selling_price'])?></td>
        <td style="width:40px;">
            <button class="btn-primary" style="background:#F97316; padding:6px 12px; border-radius:4px; font-size:12px;" onclick='editDataPlan(<?=htmlspecialchars(json_encode($p),ENT_QUOTES)?>)'><i class="fas fa-edit"></i></button>
        </td>
        <td style="width:40px;">
            <button class="btn-primary" style="background:#EF4444; padding:6px 12px; border-radius:4px; font-size:12px;" onclick="deleteDataPlan(<?=$p['id']?>)"><i class="fas fa-trash"></i></button>
        </td>
      </tr>
      <?php endforeach;?>
      </tbody>
    </table>
  </div>
</div>

<!-- CABLE PLANS -->
<div id="tab-cable" class="card fade-in" style="display:none">
  <div class="card-header" style="border-bottom:1px solid #E2E8F0; padding-bottom:16px;">
      <h3><i class="fas fa-tv" style="color:var(--primary);margin-right:8px"></i> Cable TV Plans</h3>
      <button class="btn-primary" onclick="openCableModal()" style="font-size:13px; padding:8px 16px; border-radius:8px;">
          <i class="fas fa-plus"></i> Add Plan
      </button>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Provider</th><th>Plan Name</th><th>Display Name</th><th>Cost</th><th>Selling</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($cable_plans as $p):?>
      <tr>
        <td><strong style="color:var(--navy-dark)"><?=$p['provider']?></strong></td>
        <td style="font-weight:600; font-size:13px;"><?=htmlspecialchars($p['plan_name'])?></td>
        <td style="color:var(--primary); font-weight:600; font-size:13px;"><?=htmlspecialchars($p['display_name']??'—')?></td>
        <td style="font-size:13px; color:#EF4444;">₦<?=number_format((float)$p['cost_price'],2)?></td>
        <td style="font-size:14px; color:var(--success); font-weight:bold;">₦<?=number_format((float)$p['selling_price'],2)?></td>
        <td><span class="badge <?=$p['is_active']?'badge-success':'badge-neutral'?>"><?=$p['is_active']?'Active':'Inactive'?></span></td>
        <td style="white-space:nowrap">
            <button class="btn-primary" style="background:var(--navy-light); padding:6px 12px; font-size:12px; border-radius:6px; margin-right:4px;" onclick='editCablePlan(<?=htmlspecialchars(json_encode($p),ENT_QUOTES)?>)'><i class="fas fa-edit"></i></button>
            <button class="btn-primary" style="background:#EF4444; padding:6px 12px; font-size:12px; border-radius:6px;" onclick="deleteCablePlan(<?=$p['id']?>)"><i class="fas fa-trash"></i></button>
        </td>
      </tr>
      <?php endforeach;?>
      </tbody>
    </table>
  </div>
</div>

<!-- DISCOS -->
<div id="tab-disco" class="card fade-in" style="display:none">
  <div class="card-header" style="border-bottom:1px solid #E2E8F0; padding-bottom:16px;">
      <h3><i class="fas fa-lightbulb" style="color:var(--primary);margin-right:8px"></i> Electricity Discos</h3>
      <button class="btn-primary" onclick="openDiscoModal()" style="font-size:13px; padding:8px 16px; border-radius:8px;">
          <i class="fas fa-plus"></i> Add Disco
      </button>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Disco Name</th><th>Disco ID</th><th>Logo Key</th><th>Charge (₦)</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($discos as $d):?>
      <tr>
          <td><strong style="color:var(--navy-dark)"><?=htmlspecialchars($d['disco_name'])?></strong></td>
          <td><code style="background:#F1F5F9;padding:4px 6px;border-radius:4px;color:#475569;font-size:12px;font-weight:600;"><?=$d['disco_id']?></code></td>
          <td style="font-size:13px;color:var(--text-muted);"><?=htmlspecialchars($d['logo_key']??'')?></td>
          <td style="font-size:14px; color:var(--success); font-weight:bold;">₦<?=number_format((float)$d['charge'],2)?></td>
          <td><span class="badge <?=$d['is_active']?'badge-success':'badge-neutral'?>"><?=$d['is_active']?'Active':'Inactive'?></span></td>
          <td>
            <button class="btn-primary" style="background:var(--navy-light); padding:6px 12px; font-size:12px; border-radius:6px;" onclick='editDisco(<?=htmlspecialchars(json_encode($d),ENT_QUOTES)?>)'><i class="fas fa-edit"></i> Edit</button>
          </td>
      </tr>
      <?php endforeach;?>
      </tbody>
    </table>
  </div>
</div>

<!-- OTHER PRICING -->
<div id="tab-pricing" class="card fade-in" style="display:none">
  <div class="card-header" style="border-bottom:1px solid #E2E8F0; padding-bottom:16px;">
      <h3><i class="fas fa-tags" style="color:var(--primary);margin-right:8px"></i> Service Pricing (Airtime, A2C, Exam PIN, etc.)</h3>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Service</th><th>Network/Plan</th><th>Cost</th><th>Selling</th><th>Discount %</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($pricing as $p):?>
      <tr>
          <td style="font-weight:600; color:var(--navy-dark);"><i class="fas fa-cube" style="color:#CBD5E1; margin-right:6px;"></i> <?=ucwords(str_replace('_',' ',$p['service']))?></td>
          <td style="font-weight:600; font-size:13px;"><?=htmlspecialchars($p['network']??$p['plan_name']??'')?></td>
          <td style="font-size:13px; color:#EF4444;">₦<?=number_format((float)$p['cost_price'],2)?></td>
          <td style="font-size:14px; color:var(--success); font-weight:bold;">₦<?=number_format((float)$p['selling_price'],2)?></td>
          <td><span class="badge badge-info" style="font-weight:bold"><?=$p['discount_percent']?>%</span></td>
          <td><span class="badge <?=$p['is_active']?'badge-success':'badge-neutral'?>"><?=$p['is_active']?'Active':'Inactive'?></span></td>
          <td>
            <button class="btn-primary" style="background:var(--navy-light); padding:6px 12px; font-size:12px; border-radius:6px;" onclick='editPricing(<?=htmlspecialchars(json_encode($p),ENT_QUOTES)?>)'><i class="fas fa-edit"></i> Edit</button>
          </td>
      </tr>
      <?php endforeach;?>
      </tbody>
    </table>
  </div>
</div>

</div></div>

<!-- DATA PLAN MODAL -->
<div class="modal-overlay" id="dataModal" style="display:none; align-items:center; justify-content:center;">
    <div class="modal-card" style="background:transparent; width:100%; max-width:650px; border-radius:8px; box-shadow:0 10px 25px rgba(0,0,0,0.15); overflow:hidden;">
        
        <div style="background:#0CA4A5; color:#fff; padding:16px 20px; font-weight:600; font-size:16px;">
            <span id="dataModalTitle">Add New Plan</span>
        </div>
        
        <div class="modal-body" style="padding:24px; max-height:80vh; overflow-y:auto; background:#fff; display:flex; flex-direction:column; gap:20px;">
            <input type="hidden" id="d_id">
            
            <div class="form-group">
                <label style="font-size:13px; color:#475569; font-weight:500; margin-bottom:6px; display:block;">Network</label>
                <select class="form-control" id="d_network" style="border-radius:4px; border-color:#E2E8F0;">
                    <option value="">Select Network</option>
                    <option>MTN</option>
                    <option>GLO</option>
                    <option>AIRTEL</option>
                    <option>9MOBILE</option>
                </select>
            </div>
            
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="form-group">
                    <label style="font-size:13px; color:#475569; font-weight:500; margin-bottom:6px; display:block;">Name</label>
                    <input type="text" class="form-control" id="d_plan_name" placeholder="Name" style="border-radius:4px; border-color:#E2E8F0;">
                </div>
                <div class="form-group">
                    <label style="font-size:13px; color:#475569; font-weight:500; margin-bottom:6px; display:block;">Data Type</label>
                    <input type="text" class="form-control" id="d_data_type" placeholder="Select Data Type" style="border-radius:4px; border-color:#E2E8F0;">
                </div>
            </div>
            
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="form-group">
                    <label style="font-size:13px; color:#475569; font-weight:500; margin-bottom:6px; display:block;">Plan Id</label>
                    <input type="number" class="form-control" id="d_plan_id" placeholder="Plan Id" style="border-radius:4px; border-color:#E2E8F0;">
                </div>
                <div class="form-group">
                    <label style="font-size:13px; color:#475569; font-weight:500; margin-bottom:6px; display:block;">Duration In Days</label>
                    <input type="text" class="form-control" id="d_validity" placeholder="Days" style="border-radius:4px; border-color:#E2E8F0;">
                </div>
            </div>
            
            <div class="form-group">
                <label style="font-size:13px; color:#475569; font-weight:500; margin-bottom:6px; display:block;">Buying Price</label>
                <input type="number" class="form-control" id="d_cost_price" step="0.01" placeholder="Price" style="border-radius:4px; border-color:#E2E8F0;">
            </div>
            
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px;">
                <div class="form-group">
                    <label style="font-size:13px; color:#475569; font-weight:500; margin-bottom:6px; display:block;">User Price</label>
                    <input type="number" class="form-control" id="d_selling_price" step="0.01" placeholder="User Price" style="border-radius:4px; border-color:#E2E8F0;">
                </div>
                <div class="form-group">
                    <label style="font-size:13px; color:#475569; font-weight:500; margin-bottom:6px; display:block;">Agent Price</label>
                    <input type="number" class="form-control" id="d_agent_price" step="0.01" placeholder="Agent Price" style="border-radius:4px; border-color:#E2E8F0;">
                </div>
                <div class="form-group">
                    <label style="font-size:13px; color:#475569; font-weight:500; margin-bottom:6px; display:block;">Vendor Price</label>
                    <input type="number" class="form-control" id="d_vendor_price" step="0.01" placeholder="Vendor Price" style="border-radius:4px; border-color:#E2E8F0;">
                </div>
            </div>
            
            <!-- Hidden required fields -->
            <input type="hidden" id="d_network_id" value="1">
            <input type="hidden" id="d_display_name">
            <input type="hidden" id="d_display_type_name">
            <input type="hidden" id="d_data_size">
            <input type="hidden" id="d_is_active" value="1">
            
            <div style="display:flex; justify-content:space-between; margin-top:8px;">
                <button style="background:#0CA4A5; color:#fff; border:none; padding:10px 24px; font-weight:600; font-size:14px; border-radius:4px; cursor:pointer;" onclick="saveDataPlan()"><i class="fas fa-plus" style="margin-right:6px;"></i> Add Plan</button>
                <button style="background:#E2E8F0; color:#475569; border:none; padding:10px 24px; font-weight:600; font-size:14px; border-radius:4px; cursor:pointer;" onclick="document.getElementById('dataModal').style.display='none'">CLOSE</button>
            </div>
        </div>
    </div>
</div>

<!-- CABLE MODAL -->
<div class="modal-overlay" id="cableModal" style="display:none; align-items:center; justify-content:center;">
    <div class="modal-card" style="background:#fff; width:100%; max-width:500px; border-radius:16px; box-shadow:0 10px 25px rgba(0,0,0,0.15); overflow:hidden;">
        <div class="modal-header" style="padding:20px 24px; border-bottom:1px solid #E2E8F0; display:flex; justify-content:space-between; align-items:center;">
            <h3 id="cableModalTitle" style="color:var(--navy-dark); font-size:18px;">Add Cable Plan</h3>
            <button onclick="document.getElementById('cableModal').style.display='none'" style="background:none; border:none; font-size:20px; color:#9CA3AF; cursor:pointer;">&times;</button>
        </div>
        <div class="modal-body" style="padding:24px; max-height:80vh; overflow-y:auto;">
            <input type="hidden" id="c_id">
            <div style="display:flex;flex-direction:column;gap:16px">
                <div class="form-group"><label>Provider</label><select class="form-control" id="c_provider"><option>GOTV</option><option>DSTV</option><option>STARTIMES</option></select></div>
                <div class="form-grid">
                    <div class="form-group"><label>Cable ID</label><input type="number" class="form-control" id="c_cable_id"></div>
                    <div class="form-group"><label>Plan ID</label><input type="number" class="form-control" id="c_plan_id"></div>
                </div>
                <div class="form-group"><label>Plan Name</label><input type="text" class="form-control" id="c_plan_name"></div>
                <div class="form-group"><label>Display Name</label><input type="text" class="form-control" id="c_display_name"></div>
                <div class="form-grid">
                    <div class="form-group"><label>Cost Price (₦)</label><input type="number" class="form-control" id="c_cost_price" step="0.01"></div>
                    <div class="form-group"><label>Selling Price (₦)</label><input type="number" class="form-control" id="c_selling_price" step="0.01"></div>
                </div>
                <div class="form-grid">
                    <div class="form-group"><label>Agent Price (₦)</label><input type="number" class="form-control" id="c_agent_price" step="0.01"></div>
                    <div class="form-group"><label>Vendor Price (₦)</label><input type="number" class="form-control" id="c_vendor_price" step="0.01"></div>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-top:1px solid #E2E8F0;margin-top:8px;">
                    <label style="font-size:14px;font-weight:600;color:var(--navy-dark);">Active Status</label>
                    <label class="toggle"><input type="checkbox" id="c_is_active" checked><span class="slider"></span></label>
                </div>
                <button class="btn-primary" style="width:100%;justify-content:center;padding:14px;font-size:15px;" onclick="saveCablePlan()"><i class="fas fa-save" style="margin-right:8px;"></i> Save Plan</button>
            </div>
        </div>
    </div>
</div>

<!-- DISCO MODAL -->
<div class="modal-overlay" id="discoModal" style="display:none; align-items:center; justify-content:center;">
    <div class="modal-card" style="background:#fff; width:100%; max-width:500px; border-radius:16px; box-shadow:0 10px 25px rgba(0,0,0,0.15); overflow:hidden;">
        <div class="modal-header" style="padding:20px 24px; border-bottom:1px solid #E2E8F0; display:flex; justify-content:space-between; align-items:center;">
            <h3 id="discoModalTitle" style="color:var(--navy-dark); font-size:18px;">Add Disco</h3>
            <button onclick="document.getElementById('discoModal').style.display='none'" style="background:none; border:none; font-size:20px; color:#9CA3AF; cursor:pointer;">&times;</button>
        </div>
        <div class="modal-body" style="padding:24px; max-height:80vh; overflow-y:auto;">
            <input type="hidden" id="x_id">
            <div style="display:flex;flex-direction:column;gap:16px">
                <div class="form-group"><label>Disco Name</label><input type="text" class="form-control" id="x_disco_name" placeholder="e.g. Ikeja Electric"></div>
                <div class="form-grid">
                    <div class="form-group"><label>Disco ID</label><input type="number" class="form-control" id="x_disco_id"></div>
                    <div class="form-group"><label>Logo Key</label><input type="text" class="form-control" id="x_logo_key" placeholder="ikeja"></div>
                </div>
                <div class="form-grid">
                    <div class="form-group"><label>Service Charge (₦)</label><input type="number" class="form-control" id="x_charge" step="0.01"></div>
                    <div class="form-group"><label>Agent Charge (₦)</label><input type="number" class="form-control" id="x_agent_charge" step="0.01"></div>
                    <div class="form-group"><label>Vendor Charge (₦)</label><input type="number" class="form-control" id="x_vendor_charge" step="0.01"></div>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-top:1px solid #E2E8F0;margin-top:8px;">
                    <label style="font-size:14px;font-weight:600;color:var(--navy-dark);">Active Status</label>
                    <label class="toggle"><input type="checkbox" id="x_is_active" checked><span class="slider"></span></label>
                </div>
                <button class="btn-primary" style="width:100%;justify-content:center;padding:14px;font-size:15px;" onclick="saveDisco()"><i class="fas fa-save" style="margin-right:8px;"></i> Save Disco</button>
            </div>
        </div>
    </div>
</div>

<!-- PRICING MODAL -->
<div class="modal-overlay" id="pricingModal" style="display:none; align-items:center; justify-content:center;">
    <div class="modal-card" style="background:#fff; width:100%; max-width:400px; border-radius:16px; box-shadow:0 10px 25px rgba(0,0,0,0.15); overflow:hidden;">
        <div class="modal-header" style="padding:20px 24px; border-bottom:1px solid #E2E8F0; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="color:var(--navy-dark); font-size:18px;">Edit Pricing</h3>
            <button onclick="document.getElementById('pricingModal').style.display='none'" style="background:none; border:none; font-size:20px; color:#9CA3AF; cursor:pointer;">&times;</button>
        </div>
        <div class="modal-body" style="padding:24px;">
            <input type="hidden" id="pr_id">
            <div style="display:flex;flex-direction:column;gap:16px">
                <div class="form-grid">
                    <div class="form-group"><label>Selling Price (₦)</label><input type="number" class="form-control" id="pr_selling_price" step="0.01"></div>
                    <div class="form-group"><label>Discount % <span style="font-weight:400;color:#64748B">(A2C)</span></label><input type="number" class="form-control" id="pr_discount_percent" step="0.01"></div>
                </div>
                <div class="form-grid">
                    <div class="form-group"><label>Agent Price (₦)</label><input type="number" class="form-control" id="pr_agent_price" step="0.01"></div>
                    <div class="form-group"><label>Agent Discount %</label><input type="number" class="form-control" id="pr_agent_discount" step="0.01"></div>
                </div>
                <div class="form-grid">
                    <div class="form-group"><label>Vendor Price (₦)</label><input type="number" class="form-control" id="pr_vendor_price" step="0.01"></div>
                    <div class="form-group"><label>Vendor Discount %</label><input type="number" class="form-control" id="pr_vendor_discount" step="0.01"></div>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-top:1px solid #E2E8F0;margin-top:8px;">
                    <label style="font-size:14px;font-weight:600;color:var(--navy-dark);">Active Status</label>
                    <label class="toggle"><input type="checkbox" id="pr_is_active" checked><span class="slider"></span></label>
                </div>
                <button class="btn-primary" style="width:100%;justify-content:center;padding:14px;font-size:15px;" onclick="savePricing()"><i class="fas fa-save" style="margin-right:8px;"></i> Save</button>
            </div>
        </div>
    </div>
</div>

<div class="save-toast" id="toast"></div>
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
    ['d_id','d_plan_id','d_plan_name','d_data_type','d_validity','d_cost_price','d_selling_price','d_agent_price','d_vendor_price','d_network'].forEach(i=>{if(document.getElementById(i))document.getElementById(i).value=''});
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
    document.getElementById('dataModal').style.display='flex';
}
async function saveDataPlan(){
    const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','save_data_plan');
    ['id','network','network_id','plan_id','plan_name','display_name','data_type','display_type_name','data_size','validity','cost_price','selling_price','agent_price','vendor_price'].forEach(k=>{
        if(document.getElementById('d_'+k)) fd.append(k,document.getElementById('d_'+k).value);
    });
    fd.append('is_active','1');
    const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);
}
async function deleteDataPlan(id){if(!confirm('Delete this plan?'))return;const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','delete_data_plan');fd.append('id',id);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),700);}
async function toggleDataPlan(id){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','toggle_data_plan');fd.append('id',id);await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});}

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
document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',e=>{if(e.target===e.currentTarget)e.currentTarget.style.display='none';}));
</script>
<style>
.fade-in { animation: fadeIn 0.3s ease-in-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
.filter-tab.active { background:var(--navy); color:#fff; }
</style>
</body></html>
