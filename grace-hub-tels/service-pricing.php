<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){

    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    require_permission('manage_pricing');
    $action=$_POST['action']??'';
    if($action==='save_pricing'){
        $id=(int)($_POST['id']??0);
        if($id){
            $cost=(float)($_POST['cost_price']??0);$sell=(float)$_POST['selling_price'];
            $agentP=(float)($_POST['agent_price']??0);$vendorP=(float)($_POST['vendor_price']??0);
            $disc=(float)$_POST['discount_percent'];$agentD=(float)($_POST['agent_discount']??0);$vendorD=(float)($_POST['vendor_discount']??0);
            // Negative prices/discounts are nonsensical; debit_wallet() itself
            // already rejects a zero/negative selling_price at purchase time
            // (making that specific case a self-inflicted "plan unusable" bug
            // rather than a fund-drain), but there was no validation at all
            // here, so an admin typo went live immediately with no floor.
            foreach(['cost_price'=>$cost,'selling_price'=>$sell,'agent_price'=>$agentP,'vendor_price'=>$vendorP] as $label=>$val){
                if($val<0)json_response(['success'=>false,'message'=>ucwords(str_replace('_',' ',$label)).' cannot be negative'],400);
            }
            foreach(['discount_percent'=>$disc,'agent_discount'=>$agentD,'vendor_discount'=>$vendorD] as $label=>$val){
                if($val<0||$val>100)json_response(['success'=>false,'message'=>ucwords(str_replace('_',' ',$label)).' must be between 0 and 100'],400);
            }
            db()->prepare("UPDATE service_pricing SET cost_price=?,selling_price=?,agent_price=?,vendor_price=?,discount_percent=?,agent_discount=?,vendor_discount=?,is_active=? WHERE id=?")->execute([$cost,$sell,$agentP,$vendorP,$disc,$agentD,$vendorD,isset($_POST['is_active'])?1:0,$id]);
        }
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
        // The admin-entered cash amount was previously trusted with no
        // ceiling at all — nothing tied it to the airtime actually claimed
        // in this request, so a typo (or a forged/replayed AJAX call) could
        // credit an amount wildly disproportionate to the submitted
        // airtime. Cash payout is always some discount OFF the airtime's
        // face value, so it can never legitimately exceed it.
        $pre=db()->prepare("SELECT airtime_amount FROM airtime_to_cash WHERE transaction_ref=? AND status IN ('pending','processing') LIMIT 1");
        $pre->execute([$ref]);
        $preRow=$pre->fetch();
        if(!$preRow)json_response(['success'=>false,'message'=>'Request not found or already processed'],400);
        if($amount>(float)$preRow['airtime_amount'])json_response(['success'=>false,'message'=>'Amount cannot exceed the submitted airtime value (₦'.number_format((float)$preRow['airtime_amount'],2).')'],400);
        // Atomically claim this A2C request BEFORE crediting anything.
        // Previously this was a plain unlocked SELECT ... WHERE status IN
        // ('pending','processing') — unlike AirtimeToCashService::credit_confirmed()
        // (the webhook-driven path), which correctly locks the row inside a
        // transaction, this admin action had no such guard: a double-click
        // here, or this action landing at the same moment the Autopilot
        // webhook resolves the very same ref, could both pass the check and
        // both credit the wallet. This UPDATE is the single atomic gate.
        $claim=db()->prepare("UPDATE airtime_to_cash SET status='success',cash_amount=?,updated_at=NOW() WHERE transaction_ref=? AND status IN ('pending','processing')");
        $claim->execute([$amount,$ref]);
        $ok=false;
        if($claim->rowCount()>0){
            $s=db()->prepare("SELECT * FROM airtime_to_cash WHERE transaction_ref=? LIMIT 1");$s->execute([$ref]);$req=$s->fetch();
            if($req){
                $r=credit_wallet((int)$req['user_id'],$amount,'airtime_to_cash',"A2C: N{$req['airtime_amount']} {$req['network']} - N{$amount}",['a2c_ref'=>$ref,'network'=>$req['network']],'a2c:'.$ref);
                if($r['success']){
                    notify_user((int)$req['user_id'],'A2C Credited',number_format($amount,2)." credited for your {$req['network']} airtime",'success',null,$r['balance_before'],$r['balance_after']);
                    audit_log($_SESSION['admin_id'],'CREDIT_A2C',"Credited A2C $ref for user #{$req['user_id']}, N$amount ({$req['network']})");
                    $ok=true;
                } else {
                    // Crediting failed (or the idempotency key was already
                    // used) after we already claimed the request — release
                    // the claim back to pending rather than leaving it stuck
                    // 'success' with no actual credit.
                    db()->prepare("UPDATE airtime_to_cash SET status='pending' WHERE transaction_ref=?")->execute([$ref]);
                }
            }
        }
        if($ok){$s=db()->prepare("SELECT a.*, u.full_name, u.email, u.phone FROM airtime_to_cash a JOIN users u ON a.user_id=u.id WHERE a.transaction_ref=? LIMIT 1");$s->execute([$ref]);$r=$s->fetch();if($r) send_whatsapp_alert("💰 *A2C Credited!*\nUser: ".($r['full_name']??'Unknown')." (".($r['email']??$r['phone']??'').")\nNetwork: {$r['network']}\nAmount Credited: ₦".number_format($amount,2)."\nRef: {$ref}");
        if($r)try{notify_admin('A2C Credited (Admin)',"
<div style='text-align:center;padding:10px 0 16px;'>
<span style='font-size:28px;line-height:1;font-weight:800;color:#16a34a;'>OK</span>
<h3 style='color:#FF5A1F;font-size:18px;margin:8px 0 4px;'>A2C Credited by Admin</h3>
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
        // Same atomic guard as the credit action above — without this, a
        // reject landing after the request was already credited (a race, or
        // simply an admin clicking Reject on a stale page) silently overwrote
        // status to 'failed' even though the wallet had genuinely been paid,
        // leaving the record permanently misleading with no way to tell the
        // money was actually sent.
        $rejClaim=db()->prepare("UPDATE airtime_to_cash SET status='failed',admin_note=? WHERE transaction_ref=? AND status IN ('pending','processing')");
        $rejClaim->execute([sanitize($_POST['reason']??'Rejected by admin',300),$ref]);
        if($rejClaim->rowCount()===0)json_response(['success'=>false,'message'=>'Request already resolved — cannot reject']);
        notify_user((int)$req['user_id'],'❌ A2C Rejected','Your airtime to cash request was rejected. Contact support if you sent the airtime.','danger');
        send_whatsapp_alert("❌ *A2C Rejected*\nUser: ".($req['full_name']??'Unknown')." (".($req['email']??$req['phone']??'').")\nNetwork: {$req['network']}\nAirtime: ₦".number_format((float)$req['airtime_amount'],0)."\nRef: {$ref}");
        try{notify_admin('A2C Rejected',"
<div style='text-align:center;padding:10px 0 16px;'>
<span style='font-size:28px;line-height:1;font-weight:800;color:#dc2626;'>X</span>
<h3 style='color:#FF5A1F;font-size:18px;margin:8px 0 4px;'>A2C Request Rejected</h3>
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
        audit_log($_SESSION['admin_id'],'REJECT_A2C',"Rejected A2C $ref for user #{$req['user_id']}");
        json_response(['success'=>true,'message'=>'Rejected']);
    }
    if($action==='save_rates'){
        foreach(['MTN','GLO','AIRTEL','9MOBILE'] as $net){$lk=strtolower($net);
            if(isset($_POST["rate_{$lk}"])){
                $rate=(float)$_POST["rate_{$lk}"];
                // This rate is the % cash payout for submitted airtime — a
                // value above 100 would mean paying out MORE cash than the
                // airtime is worth, and negative is nonsensical.
                if($rate<0||$rate>100)json_response(['success'=>false,'message'=>"Rate for {$net} must be between 0 and 100"],400);
                db()->prepare("UPDATE service_pricing SET discount_percent=? WHERE service='airtime_to_cash' AND network=?")->execute([$rate,$net]);
            }
            if(isset($_POST["num_{$lk}"]))setting_set("autopilot_{$lk}_number", sanitize_phone($_POST["num_{$lk}"]));
        }
        if(isset($_POST['autopilot_api_key']))setting_set('autopilot_api_key', trim($_POST['autopilot_api_key']));
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
// Deduplicate A2C rows — keep only the best row per network
try {
    $dup=db()->query("SELECT service,network,COUNT(*) c FROM service_pricing WHERE service='airtime_to_cash' GROUP BY service,network HAVING c>1")->fetchAll();
    foreach($dup as $d) {
        db()->prepare("DELETE FROM service_pricing WHERE service='airtime_to_cash' AND network=? AND id NOT IN (SELECT id FROM (SELECT id FROM service_pricing WHERE service='airtime_to_cash' AND network=? ORDER BY is_active DESC, id DESC LIMIT 1) t)")->execute([$d['network'],$d['network']]);
    }
} catch(\Throwable $e) {}
$pricing=db()->query("SELECT * FROM service_pricing ORDER BY service,network")->fetchAll();
$rc_pricing=db()->query("SELECT * FROM service_pricing WHERE service='recharge_card' ORDER BY network,plan_name")->fetchAll();
$api_regs = db()->query("SELECT id, name FROM api_registry WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
function k(string $key):string{return htmlspecialchars(setting($key));}
// Secret fields (API keys) must not render for viewers who can't act on them —
// auth_admin() alone (no role/permission) lets any admin, including 'support',
// load this page; only the manage_pricing-gated POST above can change these.
function k_secret(string $key):string{return has_permission('manage_pricing')?htmlspecialchars(setting($key)):'';}
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
    <div class="form-group" style="max-width:400px;margin-top:6px;">
        <label>Autopilot Webhook Signing Key</label>
        <input type="password" class="form-control" id="autopilot_api_key" value="<?=k_secret('autopilot_api_key')?>" placeholder="From your Autopilot NG dashboard">
        <small class="text-muted">Without this, Autopilot's confirmation webhook (webhook/autopilot/index.php) fails its signature check and A2C requests never auto-confirm — they'd need manual approval below.</small>
    </div>
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
        <div class="modal-body" style="padding:24px; max-height:80vh; overflow-y:auto; background:var(--surface); display:flex; flex-direction:column; gap:16px;">
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

document.addEventListener("DOMContentLoaded", () => {
    showA2CTab('pending');
});

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
async function saveRates(){const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','save_rates');['mtn','glo','airtel','9mobile'].forEach(lk=>{fd.append('rate_'+lk,document.getElementById('rate_'+lk).value);fd.append('num_'+lk,document.getElementById('num_'+lk).value);});fd.append('autopilot_api_key',document.getElementById('autopilot_api_key').value);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);}

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