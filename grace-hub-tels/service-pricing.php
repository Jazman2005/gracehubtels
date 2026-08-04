<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){

    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    $action=$_POST['action']??'';
    if($action==='save_data_plan'){
        $id=(int)($_POST['id']??0);
        $f=['network'=>sanitize($_POST['network']??'',20),'network_id'=>(int)($_POST['network_id']??1),'plan_id'=>sanitize($_POST['plan_id']??'',100),'plan_name'=>sanitize($_POST['plan_name']??'',200),'display_name'=>sanitize($_POST['display_name']??'',200)?:null,'data_type'=>sanitize($_POST['data_type']??'SME',50),'display_type_name'=>sanitize($_POST['display_type_name']??'',100)?:null,'data_size'=>sanitize($_POST['data_size']??'',50),'validity'=>sanitize($_POST['validity']??'',50),'cost_price'=>(float)($_POST['cost_price']??0),'selling_price'=>(float)($_POST['selling_price']??0),'agent_price'=>(float)($_POST['agent_price']??0),'vendor_price'=>(float)($_POST['vendor_price']??0),'is_active'=>isset($_POST['is_active'])?1:0];
        if($id){db()->prepare("UPDATE data_plans SET network=?,network_id=?,plan_id=?,plan_name=?,display_name=?,data_type=?,display_type_name=?,data_size=?,validity=?,cost_price=?,selling_price=?,agent_price=?,vendor_price=?,is_active=? WHERE id=?")->execute([...array_values($f),$id]);}
        else{db()->prepare("INSERT INTO data_plans (network,network_id,plan_id,plan_name,display_name,data_type,display_type_name,data_size,validity,cost_price,selling_price,agent_price,vendor_price,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute(array_values($f));}
        json_response(['success'=>true,'message'=>'Data plan saved']);
    }
    if($action==='delete_data_plan'){db()->prepare("DELETE FROM data_plans WHERE id=?")->execute([(int)$_POST['id']]);json_response(['success'=>true,'message'=>'Deleted']);}
    if($action==='toggle_data_plan'){db()->prepare("UPDATE data_plans SET is_active=NOT is_active WHERE id=?")->execute([(int)$_POST['id']]);json_response(['success'=>true]);}

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
        if($id){db()->prepare("UPDATE service_pricing SET cost_price=?,selling_price=?,agent_price=?,vendor_price=?,discount_percent=?,agent_discount=?,vendor_discount=?,is_active=? WHERE id=?")->execute([(float)($_POST['cost_price']??0),(float)$_POST['selling_price'],(float)($_POST['agent_price']??0),(float)($_POST['vendor_price']??0),(float)$_POST['discount_percent'],(float)($_POST['agent_discount']??0),(float)($_POST['vendor_discount']??0),isset($_POST['is_active'])?1:0,$id]);}
        else{json_response(['success'=>false,'message'=>'Missing ID']);}
        // Save plan API bindings
        $psvc=sanitize($_POST['service']??'',50);
        if($psvc){
            db()->prepare("DELETE FROM plan_api_bindings WHERE service=? AND plan_id=?")->execute([$psvc,$id]);
            foreach(['backup_1'=>2,'backup_2'=>3,'backup_3'=>4,'backup_4'=>5] as $key=>$prio){
                $reg_id=(int)($_POST['api_registry_'.$key]??0);
                $code=sanitize($_POST['api_code_'.$key]??'',100);
                if($reg_id){
                    db()->prepare("INSERT INTO plan_api_bindings (service,plan_id,api_registry_id,priority,product_code) VALUES (?,?,?,?,?)")->execute([$psvc,$id,$reg_id,$prio,$code?:null]);
                }
            }
        }
        json_response(['success'=>true,'message'=>'Pricing updated']);
    }
    if($action==='get_plan_bindings'){
        $pid=(int)($_POST['plan_id']??0); $svc=sanitize($_POST['service']??'',50);
        $b_stmt=db()->prepare("SELECT b.*,r.name as api_name FROM plan_api_bindings b LEFT JOIN api_registry r ON r.id=b.api_registry_id WHERE b.service=? AND b.plan_id=? ORDER BY b.priority");
        $b_stmt->execute([$svc,$pid]);
        json_response(['success'=>true,'bindings'=>$b_stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if($action==='credit'){
        $ref=$_POST['ref']??'';$amount=(float)($_POST['amount']??0);
        if(!$ref||$amount<=0)json_response(['success'=>false,'message'=>'Invalid request'],400);
        $s=db()->prepare("SELECT * FROM airtime_to_cash WHERE transaction_ref=? AND status IN ('pending','processing') LIMIT 1");$s->execute([$ref]);$req=$s->fetch();
        $ok=false;if($req){$r=credit_wallet((int)$req['user_id'],$amount,'airtime_to_cash',"A2C: N{$req['airtime_amount']} {$req['network']} - N{$amount}",['a2c_ref'=>$ref,'network'=>$req['network']]);if($r['success']){db()->prepare("UPDATE airtime_to_cash SET status='success',cash_amount=?,updated_at=NOW() WHERE transaction_ref=?")->execute([$amount,$ref]);notify_user((int)$req['user_id'],'A2C Credited',number_format($amount,2)." credited for your {$req['network']} airtime",'success',null,$r['balance_before'],$r['balance_after']);$ok=true;}}
        if($ok){$s=db()->prepare("SELECT a.*, u.full_name, u.email, u.phone FROM airtime_to_cash a JOIN users u ON a.user_id=u.id WHERE a.transaction_ref=? LIMIT 1");$s->execute([$ref]);$r=$s->fetch();if($r) send_whatsapp_alert("💰 *A2C Credited!*\nUser: ".($r['full_name']??'Unknown')." (".($r['email']??$r['phone']??'').")\nNetwork: {$r['network']}\nAmount Credited: ₦".number_format($amount,2)."\nRef: {$ref}");
        if($r)try{notify_admin('A2C Credited (Admin)',"
<div style='text-align:center;padding:10px 0 16px;'>
<span style='font-size:28px;line-height:1;font-weight:800;color:#16a34a;'>OK</span>
<h3 style='color:#4F46E5;font-size:18px;margin:8px 0 4px;'>A2C Credited by Admin</h3>
<p style='color:#475569;font-size:13px;margin:0;'>An A2C request was approved by admin.</p>
</div>
<table style='width:100%;border-collapse:collapse;'>
<tr><td style='padding:8px 12px;background:#f3f4f6;border-radius:8px;'>
<table width='100%'>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>User</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>".htmlspecialchars($r['full_name']??'Unknown',ENT_QUOTES)."</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Amount</td><td style='padding:4px 0;text-align:right;font-weight:700;color:#16a34a;font-size:14px;'>₦".number_format($amount,2)."</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Network</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>".htmlspecialchars($r['network']??'',ENT_QUOTES)."</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Ref</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>".htmlspecialchars($ref,ENT_QUOTES)."</td></tr>
</table>
</td></tr>
</table>");}catch(\Throwable$e){}}
        json_response($ok?['success'=>true,'message'=>'Credited successfully']:['success'=>false,'message'=>'Failed - request not found or already processed']);
    }
    if($action==='reject'){
        $ref=$_POST['ref']??'';if(!$ref)json_response(['success'=>false,'message'=>'Missing ref'],400);
        $s=db()->prepare("SELECT a.*, u.full_name, u.email, u.phone FROM airtime_to_cash a JOIN users u ON a.user_id=u.id WHERE a.transaction_ref=? LIMIT 1");$s->execute([$ref]);$req=$s->fetch();
        if(!$req)json_response(['success'=>false,'message'=>'Not found']);
        db()->prepare("UPDATE airtime_to_cash SET status='failed',admin_note=? WHERE transaction_ref=?")->execute([sanitize($_POST['reason']??'Rejected by admin',300),$ref]);
        notify_user((int)$req['user_id'],'❌ A2C Rejected','Your airtime to cash request was rejected. Contact support if you sent the airtime.','danger');
        send_whatsapp_alert("❌ *A2C Rejected*\nUser: ".($req['full_name']??'Unknown')." (".($req['email']??$req['phone']??'').")\nNetwork: {$req['network']}\nAirtime: ₦".number_format((float)$req['airtime_amount'],0)."\nRef: {$ref}");
        try{notify_admin('A2C Rejected',"
<div style='text-align:center;padding:10px 0 16px;'>
<span style='font-size:28px;line-height:1;font-weight:800;color:#dc2626;'>X</span>
<h3 style='color:#4F46E5;font-size:18px;margin:8px 0 4px;'>A2C Request Rejected</h3>
<p style='color:#475569;font-size:13px;margin:0;'>An A2C request was rejected by admin.</p>
</div>
<table style='width:100%;border-collapse:collapse;'>
<tr><td style='padding:8px 12px;background:#f3f4f6;border-radius:8px;'>
<table width='100%'>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>User</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>".htmlspecialchars($req['full_name']??'Unknown',ENT_QUOTES)."</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Network</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>".htmlspecialchars($req['network']??'',ENT_QUOTES)."</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Ref</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>".htmlspecialchars($ref,ENT_QUOTES)."</td></tr>
</table>
</td></tr>
</table>");}catch(\Throwable$e){}
        json_response(['success'=>true,'message'=>'Rejected']);
    }
    if($action==='save_rates'){
        foreach(['MTN','GLO','AIRTEL','9MOBILE'] as $net){$lk=strtolower($net);
            if(isset($_POST["rate_{$lk}"]))db()->prepare("UPDATE service_pricing SET discount_percent=? WHERE service='airtime_to_cash' AND network=?")->execute([(float)$_POST["rate_{$lk}"],$net]);
            if(isset($_POST["num_{$lk}"]))setting_set("autopilot_{$lk}_number", sanitize_phone($_POST["num_{$lk}"]));
        }
        json_response(['success'=>true,'message'=>'Rates & numbers saved']);
    }
    if($action==='toggle_a2c'){
        $id=(int)($_POST['id']??0);$net=$_POST['network']??'';
        if($id&&in_array($net,['MTN','GLO','AIRTEL','9MOBILE'])){db()->prepare("UPDATE service_pricing SET is_active=NOT is_active WHERE id=? AND service='airtime_to_cash'")->execute([$id]);json_response(['success'=>true]);}
        json_response(['success'=>false,'message'=>'Invalid request'],400);
    }
    json_response(['success'=>false],400);
}
$csrf=admin_csrf();
$data_plans=db()->query("SELECT * FROM data_plans ORDER BY network,data_type,selling_price")->fetchAll();
$cable_plans=db()->query("SELECT * FROM cable_plans ORDER BY provider,selling_price")->fetchAll();
$discos=db()->query("SELECT * FROM electricity_discos ORDER BY disco_name")->fetchAll();
// Deduplicate A2C rows — keep only the best row per network
try {
    $dup=db()->query("SELECT service,network,COUNT(*) c FROM service_pricing WHERE service='airtime_to_cash' GROUP BY service,network HAVING c>1")->fetchAll();
    foreach($dup as $d) {
        db()->prepare("DELETE FROM service_pricing WHERE service='airtime_to_cash' AND network=? AND id NOT IN (SELECT id FROM (SELECT id FROM service_pricing WHERE service='airtime_to_cash' AND network=? ORDER BY is_active DESC, id DESC LIMIT 1) t)")->execute([$d['network'],$d['network']]);
    }
} catch(\Throwable $e) {}
$pricing=db()->query("SELECT * FROM service_pricing ORDER BY service,network")->fetchAll();
$rc_pricing=db()->query("SELECT * FROM service_pricing WHERE service='recharge_card' ORDER BY network,plan_name")->fetchAll();
$rc_networks=array_unique(array_map(function($r){return $r['network'];},$rc_pricing));
$api_regs = db()->query("SELECT id, name FROM api_registry WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
function k(string $key):string{return htmlspecialchars(setting($key));}
try{
    $a2c_requests=db()->query("SELECT a.*,u.full_name,u.phone as user_phone FROM airtime_to_cash a LEFT JOIN users u ON a.user_id=u.id ORDER BY a.created_at DESC LIMIT 100")->fetchAll();
    $a2c_counts=db()->query("SELECT status,COUNT(*) c FROM airtime_to_cash GROUP BY status")->fetchAll();$a2c_cmap=[];foreach($a2c_counts as $c)$a2c_cmap[$c['status']]=$c['c'];
    $a2c_pricing_rows=db()->query("SELECT id,network,discount_percent,is_active FROM service_pricing WHERE service='airtime_to_cash' ORDER BY network")->fetchAll();$a2c_data=[];foreach($a2c_pricing_rows as $r)$a2c_data[$r['network']]=$r;
}catch(PDOException$e){$a2c_requests=[];$a2c_cmap=[];$a2c_data=[];}
$net_list=['MTN','GLO','AIRTEL','9MOBILE'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Service Pricing – Gracehubtels Admin</title>
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
            <h2>Service Pricing</h2>
            <p>Manage Pricing and Margins</p>
        </div>
    </div>

    <div class="data-panel">
  <div class="panel-title-bar" style="border-bottom:1px solid #E2E8F0; padding-bottom:16px;">
      <h3><i class="fas fa-tags" style="color:var(--primary);margin-right:8px"></i> Service Pricing (Airtime, A2C, Exam PIN, etc.)</h3>
  </div>
  <div class="responsive-grid-wrapper">
    <table class="data-table">
      <thead><tr><th>Service</th><th>Network/Plan</th><th>Cost</th><th>Selling</th><th>Discount %</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($pricing as $p):if(in_array($p['service'],['airtime_to_cash','recharge_card','betting']))continue;?>
      <tr>
          <td style="font-weight:600; color:var(--navy-dark);"><i class="fas fa-cube" style="color:#CBD5E1; margin-right:6px;"></i> <?=ucwords(str_replace('_',' ',$p['service']))?></td>
          <td style="font-weight:600; font-size:13px;"><?=htmlspecialchars($p['network']??$p['plan_name']??'')?></td>
          <td style="font-size:13px; color:var(--danger);">₦<?=number_format((float)$p['cost_price'],2)?></td>
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

<!-- Recharge Cards by Network -->
<div class="data-panel" style="margin-top:24px;">
  <div class="panel-title-bar" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
    <h3><i class="fas fa-ticket" style="color:var(--primary);margin-right:8px"></i> Recharge Card Pricing</h3>
    <div class="filter-tabs" style="margin-left:auto;">
      <span class="filter-tab rc-tab active" data-net="" onclick="showRCTab(this,'')">All</span>
      <?php foreach(['MTN','GLO','AIRTEL','9MOBILE'] as $net):?>
      <span class="filter-tab rc-tab" data-net="<?=$net?>" onclick="showRCTab(this,'<?=$net?>')"><?=$net?></span>
      <?php endforeach;?>
    </div>
  </div>
  <div class="responsive-grid-wrapper">
    <table class="data-table">
      <thead><tr><th>Network</th><th>Denomination</th><th>Cost</th><th>Selling</th><th>Discount %</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($rc_pricing as $p):?>
      <tr data-net="<?=htmlspecialchars($p['network'])?>">
        <td style="font-weight:600;font-size:13px"><?=htmlspecialchars($p['network'])?></td>
        <td style="font-weight:600">₦<?=htmlspecialchars($p['plan_name']??'')?></td>
        <td style="font-size:13px;color:var(--danger);">₦<?=number_format((float)$p['cost_price'],2)?></td>
        <td style="font-size:14px;color:var(--success);font-weight:bold;">₦<?=number_format((float)$p['selling_price'],2)?></td>
        <td><span class="badge badge-info" style="font-weight:bold"><?=$p['discount_percent']?>%</span></td>
        <td><span class="badge <?=$p['is_active']?'badge-success':'badge-neutral'?>"><?=$p['is_active']?'Active':'Inactive'?></span></td>
        <td>
          <button class="btn-primary" style="background:var(--navy-light);padding:6px 12px;font-size:12px;border-radius:6px;" onclick='editPricing(<?=htmlspecialchars(json_encode($p),ENT_QUOTES)?>)'><i class="fas fa-edit"></i> Edit</button>
        </td>
      </tr>
      <?php endforeach;?>
      </tbody>
    </table>
  </div>
</div>

<!-- A2C Management -->
<div class="data-panel" style="margin-top:24px;">
  <div class="panel-title-bar"><h3><i class="fas fa-exchange-alt" style="color:var(--primary);margin-right:8px"></i> Airtime to Cash – Rates & Receiving Numbers</h3></div>
  <div class="modal-body" style="display:flex;flex-direction:column;gap:14px">
    <div class="form-grid" style="display:grid;grid-template-columns:1fr 1fr 1fr 80px;gap:16px;font-weight:600;font-size:13px;color:#6B7280;padding:0 4px;">
      <div>Network</div><div>Rate (%)</div><div>Receiving Number</div><div style="text-align:center">Active</div>
    </div>
    <?php foreach($net_list as $net): $lk=strtolower($net); $net_logo=strtolower($net); $r=$a2c_data[$net]??null; ?>
    <div class="form-grid" style="display:grid;grid-template-columns:1fr 1fr 1fr 80px;gap:16px;align-items:center;">
      <div style="display:flex;align-items:center;gap:8px;font-weight:600;font-size:14px;">
        <img src="/assets/logos/<?=$net_logo?>.png" alt="<?=$net?>" style="width:28px;height:28px;border-radius:50%;object-fit:cover;border:1px solid #e5e7eb;" onerror="this.style.display='none'">
        <?=$net?>
      </div>
      <div>
        <input type="number" step="0.01" min="0" max="100" class="form-control" id="rate_<?=$lk?>" value="<?=htmlspecialchars((string)($r['discount_percent']??''))?>" placeholder="e.g. 65">
      </div>
      <div>
        <input type="text" class="form-control" id="num_<?=$lk?>" value="<?=k('autopilot_'.$lk.'_number')?>" placeholder="080...">
      </div>
      <div style="text-align:center">
        <?php if($r):?>
        <label class="toggle" style="margin:0"><input type="checkbox" <?=$r['is_active']?'checked':''?> onchange="toggleA2c(<?=$r['id']?>,'<?=$net?>')"><span class="slider"></span></label>
        <?php else:?>
        <span style="color:#9CA3AF;font-size:11px">N/A</span>
        <?php endif;?>
      </div>
    </div>
    <?php endforeach; ?>
    <button class="btn-primary" onclick="saveRates()" style="max-width:200px;">Save Rates & Numbers</button>
  </div>
</div>

<div class="data-panel">
  <div class="panel-title-bar"><h3><i class="fas fa-clock" style="color:var(--primary);margin-right:8px"></i> Airtime to Cash – Pending Requests</h3></div>
  <div style="padding:16px">
    <div class="filter-tabs" style="margin-bottom:16px">
      <span class="filter-tab a2c-tab active" data-status="pending" onclick="showA2CTab('pending')">Pending (<?=$a2c_cmap['pending']??0?>)</span>
      <span class="filter-tab a2c-tab" data-status="processing" onclick="showA2CTab('processing')">Processing (<?=$a2c_cmap['processing']??0?>)</span>
      <span class="filter-tab a2c-tab" data-status="success" onclick="showA2CTab('success')">Success (<?=$a2c_cmap['success']??0?>)</span>
      <span class="filter-tab a2c-tab" data-status="failed" onclick="showA2CTab('failed')">Failed (<?=$a2c_cmap['failed']??0?>)</span>
      <span class="filter-tab a2c-tab" data-status="all" onclick="showA2CTab('all')">All</span>
    </div>
    <div class="responsive-grid-wrapper">
      <table class="data-table">
        <thead><tr><th>User</th><th>Network</th><th>Airtime</th><th>Cash Due</th><th>Sender</th><th>Our Number</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
        <tbody id="a2c-body">
        <?php foreach($a2c_requests as $r):$scls=['pending'=>'badge-pending','processing'=>'badge-info','success'=>'badge-success','failed'=>'badge-danger'][$r['status']]??'badge-pending';?>
        <tr data-status="<?=$r['status']?>">
          <td><div class="user-cell"><div class="u-avatar"><?=strtoupper(substr($r['full_name']??'?',0,1))?></div><div><span class="u-name"><?=htmlspecialchars($r['full_name']??'—')?></span><span class="u-phone"><?=htmlspecialchars($r['user_phone']??'')?></span></div></div></td>
          <td><strong><?=$r['network']?></strong></td>
          <td>₦<?=number_format((float)$r['airtime_amount'],0)?></td>
          <td><strong class="text-success">₦<?=number_format((float)$r['cash_amount'],2)?></strong></td>
          <td><?=htmlspecialchars($r['sender_phone'])?></td>
          <td style="font-size:11px"><?=htmlspecialchars($r['our_number']??'—')?></td>
          <td><span class="badge <?=$scls?>"><?=ucfirst($r['status'])?></span></td>
          <td style="font-size:12px;color:#6B7280"><?=date('d M, H:i',strtotime($r['created_at']))?></td>
          <td style="white-space:nowrap">
            <?php if(in_array($r['status'],['pending','processing'])):?>
            <button class="btn-sm btn-success" onclick="creditPrompt('<?=$r['transaction_ref']?>',<?=$r['cash_amount']?>)">Credit</button>
            <button class="btn-sm btn-danger" onclick="rejectPrompt('<?=$r['transaction_ref']?>')">Reject</button>
            <?php else:?><span style="color:#9CA3AF;font-size:12px">—</span><?php endif;?>
          </td>
        </tr>
        <?php endforeach;if(empty($a2c_requests)):?><tr><td colspan="9" class="empty-state">No A2C requests found</td></tr><?php endif;?>
        </tbody>
      </table>
    </div>
  </div>
</div>

</div></div>

<div class="modal-overlay" id="pricingModal" style="display:none; align-items:center; justify-content:center;">
    <div class="modal-card" style="background:transparent; width:100%; max-width:600px; border-radius:8px; box-shadow:0 10px 25px rgba(0,0,0,0.15); overflow:hidden;">
        <div style="background:var(--primary); color:#fff; padding:16px 20px; font-weight:600; font-size:16px;">
            <span id="pricingModalTitle">Edit Pricing</span>
        </div>
        <div class="modal-body" style="padding:24px; max-height:80vh; overflow-y:auto; background:#fff; display:flex; flex-direction:column; gap:16px;">
            <input type="hidden" id="pr_id">
            <input type="hidden" id="pr_service">
            <div class="form-group">
                <label style="font-weight:700;color:var(--danger);">Cost Price</label>
                <input type="number" step="0.01" class="form-control" id="pr_cost_price">
            </div>
            <hr style="border:none;border-top:1px solid #E2E8F0;margin:0;">
            <div style="display:grid; grid-template-columns:2fr 1fr; gap:16px;">
                <div class="form-group"><label>Selling Price</label><input type="number" step="0.01" class="form-control" id="pr_selling_price" oninput="calcTier()"></div>
                <div class="form-group"><label>Discount %</label><input type="number" step="0.01" class="form-control" id="pr_discount_percent"></div>
            </div>
            <div style="display:grid; grid-template-columns:2fr 1fr; gap:16px;">
                <div class="form-group"><label>Agent Price</label><input type="number" step="0.01" class="form-control" id="pr_agent_price"></div>
                <div class="form-group"><label>Agent Discount %</label><input type="number" step="0.01" class="form-control" id="pr_agent_discount" oninput="calcTier()"></div>
            </div>
            <div style="display:grid; grid-template-columns:2fr 1fr; gap:16px;">
                <div class="form-group"><label>Vendor Price</label><input type="number" step="0.01" class="form-control" id="pr_vendor_price"></div>
                <div class="form-group"><label>Vendor Discount %</label><input type="number" step="0.01" class="form-control" id="pr_vendor_discount" oninput="calcTier()"></div>
            </div>
            <div style="display:flex; align-items:center;">
                <label style="font-size:14px; font-weight:600; color:var(--navy-dark); margin-right:16px;">Active</label>
                <label class="toggle"><input type="checkbox" id="pr_is_active" checked><span class="slider"></span></label>
            </div>
            <div style="border-top:1px solid #E2E8F0; padding-top:16px;">
                <h5 style="margin:0 0 12px; font-size:14px; color:#334155;"><i class="fas fa-plug" style="color:var(--primary);margin-right:6px;"></i> Backup APIs (failover) <span style="font-weight:400;font-size:11px;color:#94A3B8;">(optional — tried in order if primary fails)</span> <span style="font-weight:400;font-size:11px;color:#64748B;display:block;margin-top:4px;">The system plan ID at the top is used as primary. These backups are tried when the primary API returns insufficient balance.</span></h5>
                <?php $api_keys=['backup_1'=>['Backup 1',2],'backup_2'=>['Backup 2',3],'backup_3'=>['Backup 3',4],'backup_4'=>['Backup 4',5]]; foreach($api_keys as $key=>$label): ?>
                <div style="display:grid; grid-template-columns:2fr 1fr; gap:12px; margin-bottom:8px;">
                    <div class="form-group"><label><?=$label[0]?></label>
                        <select class="form-control" id="pr_api_registry_<?=$key?>">
                            <option value="">-- Default (provider loop) --</option>
                            <?php foreach($api_regs as $reg):?>
                            <option value="<?=$reg['id']?>"><?=htmlspecialchars($reg['name'])?></option>
                            <?php endforeach;?>
                        </select>
                    </div>
                    <div class="form-group"><label>Plan ID</label><input type="text" class="form-control" id="pr_api_code_<?=$key?>" placeholder="e.g. MTN-100"></div>
                </div>
                <?php endforeach; ?>
            </div>
            <div style="display:flex; justify-content:space-between; margin-top:8px;">
                <button class="btn-primary" onclick="savePricing()"><i class="fas fa-save" style="margin-right:6px;"></i> Save</button>
                <button class="btn-ghost" onclick="document.getElementById('pricingModal').style.display='none'">CLOSE</button>
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
        sessionStorage.removeItem('serviceTab');
    }
    showA2CTab('pending');
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
function editPricing(p){
    document.getElementById('pr_id').value=p.id;
    document.getElementById('pr_service').value=p.service||'';
    document.getElementById('pr_cost_price').value=p.cost_price;
    document.getElementById('pr_selling_price').value=p.selling_price;
    document.getElementById('pr_discount_percent').value=p.discount_percent;
    document.getElementById('pr_agent_price').value=p.agent_price||'0';
    document.getElementById('pr_vendor_price').value=p.vendor_price||'0';
    document.getElementById('pr_agent_discount').value=p.agent_discount||'0';
    document.getElementById('pr_vendor_discount').value=p.vendor_discount||'0';
    document.getElementById('pr_is_active').checked=p.is_active==1;
    ['backup_1','backup_2','backup_3','backup_4'].forEach(function(k){
        const sel=document.getElementById('pr_api_registry_'+k); if(sel) sel.value='';
        const inp=document.getElementById('pr_api_code_'+k); if(inp) inp.value='';
    });
    document.getElementById('pricingModal').style.display='flex';
    if(p.service && p.id) loadPricingPlanBindings(p.service, p.id);
}
async function loadPricingPlanBindings(svc, pid){
    const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','get_plan_bindings');fd.append('service',svc);fd.append('plan_id',pid);
    try {
        const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
        if(!r.bindings) return;
        r.bindings.forEach(function(b){
            if(b.priority<2) return;
            const key=['','','backup_1','backup_2','backup_3','backup_4'][b.priority]; if(!key) return;
            const sel=document.getElementById('pr_api_registry_'+key); if(sel) sel.value=b.api_registry_id||'';
            const inp=document.getElementById('pr_api_code_'+key); if(inp) inp.value=b.product_code||'';
        });
    } catch(e){}
}
async function savePricing(){
    const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','save_pricing');
    fd.append('id',document.getElementById('pr_id').value);
    fd.append('service',document.getElementById('pr_service').value);
    fd.append('cost_price',document.getElementById('pr_cost_price').value);
    fd.append('selling_price',document.getElementById('pr_selling_price').value);
    fd.append('discount_percent',document.getElementById('pr_discount_percent').value);
    fd.append('agent_price',document.getElementById('pr_agent_price').value);
    fd.append('vendor_price',document.getElementById('pr_vendor_price').value);
    fd.append('agent_discount',document.getElementById('pr_agent_discount').value);
    fd.append('vendor_discount',document.getElementById('pr_vendor_discount').value);
    if(document.getElementById('pr_is_active').checked)fd.append('is_active','1');
    ['backup_1','backup_2','backup_3','backup_4'].forEach(function(k){
        const sel=document.getElementById('pr_api_registry_'+k); if(sel) fd.append('api_registry_'+k, sel.value);
        const inp=document.getElementById('pr_api_code_'+k); if(inp) fd.append('api_code_'+k, inp.value);
    });
    const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);}
function calcTier(){var sp=parseFloat(document.getElementById('pr_selling_price').value)||0;var ad=parseFloat(document.getElementById('pr_agent_discount').value)||0;var vd=parseFloat(document.getElementById('pr_vendor_discount').value)||0;document.getElementById('pr_agent_price').value=(ad>0?(sp*(1-ad/100)).toFixed(2):sp.toFixed(2));document.getElementById('pr_vendor_price').value=(vd>0?(sp*(1-vd/100)).toFixed(2):sp.toFixed(2));}

// A2C
async function credit(ref,amount){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','credit');fd.append('ref',ref);fd.append('amount',amount);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);}
function creditPrompt(ref,suggested){const amt=prompt('Confirm cash amount to credit:',suggested);if(!amt||isNaN(amt))return;credit(ref,parseFloat(amt));}
function rejectPrompt(ref){const reason=prompt('Reason for rejection:');if(!reason)return;reject(ref,reason);}
async function reject(ref,reason){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','reject');fd.append('ref',ref);fd.append('reason',reason);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),900);}
async function saveRates(){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','save_rates');['mtn','glo','airtel','9mobile'].forEach(lk=>{fd.append('rate_'+lk,document.getElementById('rate_'+lk).value);fd.append('num_'+lk,document.getElementById('num_'+lk).value);});const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);}

async function toggleA2c(id,net){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','toggle_a2c');fd.append('id',id);fd.append('network',net);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());if(r.success)location.reload();}

function showRCTab(el,net){
    document.querySelectorAll('.rc-tab').forEach(function(t){t.classList.toggle('active',t.getAttribute('data-net')===net);});
    document.querySelectorAll('.data-table tbody tr[data-net]').forEach(function(r){
        r.style.display=!net||r.getAttribute('data-net')===net?'':'none';
    });
}
function showA2CTab(status){
    document.querySelectorAll('.a2c-tab').forEach(function(t){t.classList.toggle('active',t.getAttribute('data-status')===status);});
    document.querySelectorAll('#a2c-body tr').forEach(function(r){
        var s=r.getAttribute('data-status');
        if(status==='all'){r.style.display='';}
        else{r.style.display=s===status?'':'none';}
    });
}

function showToast(msg,ok){const t=document.getElementById('toast');if(!t)return;t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),2500);}
document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',e=>{if(e.target===e.currentTarget)e.currentTarget.style.display='none';}));
</script>

</body>
</html>