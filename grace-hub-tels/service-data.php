<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/../user/core/api_router.php';
auth_admin();
require_feature_admin('data');
if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    require_permission('manage_pricing');
    $action=$_POST['action']??'';

    if($action==='set_type_provider'){
        // This is an API-routing change, not a pricing change — gate it with
        // manage_api on top of the page's existing manage_pricing gate, so an
        // admin who can edit prices but not API config gets a clean 403
        // instead of silently being able to redirect where money-moving
        // requests go.
        require_permission('manage_api');
        $network = sanitize($_POST['network']??'',20);
        $data_type = sanitize($_POST['data_type']??'',50);
        $registry_id = (int)($_POST['api_registry_id']??0);
        if($network===''||$data_type==='')json_response(['success'=>false,'message'=>'Missing network/data type'],400);

        $existing = db()->prepare("SELECT id FROM api_providers WHERE service='data' AND network=? AND data_type=?");
        $existing->execute([$network,$data_type]);
        $existing_id = (int)$existing->fetchColumn();

        if(!$registry_id){
            if($existing_id) db()->prepare("DELETE FROM api_providers WHERE id=?")->execute([$existing_id]);
            audit_log((int)$_SESSION['admin_id'], 'DATA_TYPE_PROVIDER', "Reverted $network/$data_type to universal API");
            json_response(['success'=>true,'message'=>'Reverted to the network\'s universal API']);
        }

        $reg = db()->prepare("SELECT name, base_url FROM api_registry WHERE id=? AND is_active=1");
        $reg->execute([$registry_id]);
        $r = $reg->fetch();
        if(!$r)json_response(['success'=>false,'message'=>'Provider not found or inactive'],404);

        if($existing_id){
            // Clear any leftover network_id_map/value_map from a previous
            // provider — it belonged to that provider's ID scheme and would
            // otherwise silently override the new provider's own correct
            // defaults (the exact bug found earlier with AutosyncNG).
            db()->prepare("UPDATE api_providers SET api_registry_id=?, provider_name=?, api_base_url=?, network_id_map=NULL, value_map=NULL, is_active=1 WHERE id=?")
                ->execute([$registry_id, $r['name'], $r['base_url'], $existing_id]);
        } else {
            db()->prepare("INSERT INTO api_providers (service,network,data_type,api_registry_id,provider_name,api_base_url,priority,is_active) VALUES ('data',?,?,?,?,?,1,1)")
                ->execute([$network, $data_type, $registry_id, $r['name'], $r['base_url']]);
        }
        audit_log((int)$_SESSION['admin_id'], 'DATA_TYPE_PROVIDER', "Set $network/$data_type to use {$r['name']} (registry #$registry_id)");
        json_response(['success'=>true,'message'=>"$data_type on $network will now use {$r['name']}"]);
    }
    if($action==='save_data_plan'){
        $id=(int)($_POST['id']??0);
        $cashback_type = in_array($_POST['cashback_type']??'', ['percent','fixed']) ? $_POST['cashback_type'] : null;
        $cashback_value = $cashback_type ? (float)($_POST['cashback_value']??0) : null;
        // Plan IDs (per API provider) are managed separately on the Data Plan IDs
        // page via plan_api_bindings — this form no longer collects or touches them,
        // so an edit here can't wipe out mappings set up over there. New plans get
        // plan_id=0, a harmless placeholder never actually used once at least one
        // provider binding exists (see plan_code fallback in DataService::process()).
        foreach(['cost_price'=>(float)($_POST['cost_price']??0),'selling_price'=>(float)($_POST['selling_price']??0),'agent_price'=>(float)($_POST['agent_price']??0),'vendor_price'=>(float)($_POST['vendor_price']??0)] as $label=>$val){
            if($val<0)json_response(['success'=>false,'message'=>ucwords(str_replace('_',' ',$label)).' cannot be negative'],400);
        }
        if($cashback_type==='percent'&&$cashback_value>100)json_response(['success'=>false,'message'=>'Cashback percent cannot exceed 100'],400);
        if($cashback_value!==null&&$cashback_value<0)json_response(['success'=>false,'message'=>'Cashback value cannot be negative'],400);
        $f=['network'=>sanitize($_POST['network']??'',20),'network_id'=>(int)($_POST['network_id']??1),'plan_name'=>sanitize($_POST['plan_name']??'',200),'display_name'=>sanitize($_POST['display_name']??'',200)?:null,'data_type'=>sanitize($_POST['data_type']??'SME',50),'display_type_name'=>sanitize($_POST['display_type_name']??'',100)?:null,'data_size'=>sanitize($_POST['data_size']??'',50),'validity'=>sanitize($_POST['validity']??'',50),'cost_price'=>(float)($_POST['cost_price']??0),'selling_price'=>(float)($_POST['selling_price']??0),'agent_price'=>(float)($_POST['agent_price']??0),'vendor_price'=>(float)($_POST['vendor_price']??0),'cashback_type'=>$cashback_type,'cashback_value'=>$cashback_value,'is_active'=>isset($_POST['is_active'])?1:0];
        if($id){db()->prepare("UPDATE data_plans SET network=?,network_id=?,plan_name=?,display_name=?,data_type=?,display_type_name=?,data_size=?,validity=?,cost_price=?,selling_price=?,agent_price=?,vendor_price=?,cashback_type=?,cashback_value=?,is_active=? WHERE id=?")->execute([...array_values($f),$id]);}
        else{
            // New plans always join the bottom of their network+data_type group
            // (highest display_order + 1), never disturbing the manually-arranged
            // order of the plans already there — admins only move it from there
            // by dragging it, same as any other row.
            $ord_stmt=db()->prepare("SELECT COALESCE(MAX(display_order),-1)+1 FROM data_plans WHERE network=? AND data_type=?");
            $ord_stmt->execute([$f['network'],$f['data_type']]);
            $next_order=(int)$ord_stmt->fetchColumn();
            db()->prepare("INSERT INTO data_plans (network,network_id,plan_id,plan_name,display_name,data_type,display_type_name,data_size,validity,cost_price,selling_price,agent_price,vendor_price,cashback_type,cashback_value,is_active,display_order) VALUES (?,?,0,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([...array_values($f),$next_order]);
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
        // A blank field means "leave this tier alone" — only tiers the admin
        // actually typed a value for get touched, so filling in just one
        // tier's percentage no longer resets the other tiers' prices back to
        // bare cost price.
        $markup_user = (isset($_POST['markup_user']) && $_POST['markup_user']!=='') ? (float)$_POST['markup_user'] : null;
        $markup_agent = (isset($_POST['markup_agent']) && $_POST['markup_agent']!=='') ? (float)$_POST['markup_agent'] : null;
        $markup_vendor = (isset($_POST['markup_vendor']) && $_POST['markup_vendor']!=='') ? (float)$_POST['markup_vendor'] : null;

        // Percentage is a discount OFF cost price, not a markup on top of it —
        // e.g. 5% on a ₦230 cost gives a ₦218.50 selling price.
        $sets = []; $params = [];
        if ($markup_user !== null) { $sets[] = "selling_price = CEILING(GREATEST(0, cost_price - (cost_price * (? / 100))))"; $params[] = $markup_user; }
        if ($markup_agent !== null) { $sets[] = "agent_price = CEILING(GREATEST(0, cost_price - (cost_price * (? / 100))))"; $params[] = $markup_agent; }
        if ($markup_vendor !== null) { $sets[] = "vendor_price = CEILING(GREATEST(0, cost_price - (cost_price * (? / 100))))"; $params[] = $markup_vendor; }
        if (!empty($sets)) {
            $params[] = $b_network; $params[] = $b_data_type;
            db()->prepare("UPDATE data_plans SET ".implode(', ', $sets)." WHERE network=? AND data_type=?")->execute($params);
        }

        // Remember whichever percentages were submitted this time, keeping the
        // previously-saved percentage for any tier left blank, so reopening
        // the modal later prefills all three tiers correctly.
        db()->prepare("INSERT INTO bulk_pricing_presets (service, scope_key, markup_user, markup_agent, markup_vendor) VALUES ('data', ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    markup_user = COALESCE(VALUES(markup_user), markup_user),
                    markup_agent = COALESCE(VALUES(markup_agent), markup_agent),
                    markup_vendor = COALESCE(VALUES(markup_vendor), markup_vendor)")
            ->execute([$b_network.'|'.$b_data_type, $markup_user, $markup_agent, $markup_vendor]);

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
// Last percentage applied per Bulk Pricing scope (network|data_type), keyed
// for instant client-side lookup so the modal can prefill previously-used
// percentages instead of resetting to blank.
$bulk_presets = ['data'=>[]];
foreach (db()->query("SELECT scope_key, markup_user, markup_agent, markup_vendor FROM bulk_pricing_presets WHERE service='data'")->fetchAll() as $bp) {
    $bulk_presets['data'][$bp['scope_key']] = [
        'markup_user' => $bp['markup_user'],
        'markup_agent' => $bp['markup_agent'],
        'markup_vendor' => $bp['markup_vendor'],
    ];
}
// For the per-type "API Provider" control: which registry is actually in
// effect right now for each (network, data_type) group on this page, and
// whether that's a type-specific override or the network's inherited
// universal route — computed with the exact same function try_api_providers()
// calls at purchase time, so this can never disagree with real behavior.
$type_provider_state = [];
if ($network_filter) {
    $types_seen = [];
    foreach ($data_plans as $p) { $types_seen[$p['data_type']] = true; }
    foreach (array_keys($types_seen) as $dt) {
        $matches = get_api_providers('data', $network_filter, $dt);
        $top = $matches[0] ?? null;
        $type_provider_state[$dt] = [
            'registry_id' => $top['api_registry_id'] ?? null,
            'name' => $top['registry_name'] ?? $top['provider_name'] ?? null,
            'is_override' => $top && strtoupper((string)($top['data_type'] ?? '')) === strtoupper($dt) && $top['data_type'] !== '',
        ];
    }
}
$api_regs = db()->query("SELECT id, name FROM api_registry WHERE is_active=1 ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Data Plans – <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title>
    <link rel="icon" type="image/png" href="/assets/images/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js"></script>
    <style>
        .drag-handle{cursor:grab;color:#94A3B8;padding:2px 8px;}
        .drag-handle:active{cursor:grabbing;}
        tr.sortable-ghost{opacity:0.4;background:#EFF6FF !important;}
        tr.sortable-drag{background:#fff;box-shadow:0 4px 12px rgba(0,0,0,0.15);}
    </style>
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
      <div style="background:#F1F5F9; padding:12px 20px; border-bottom:1px solid #E2E8F0;">
      <div style="display:flex; align-items:center; gap:10px;">
          <div style="width:12px; height:12px; background:var(--primary); border-radius:50%;"></div>
          <h4 style="margin:0; font-weight:700; color:#334155; font-size:15px; letter-spacing:0.5px;"><?=htmlspecialchars($type)?> PLANS</h4>
          <span style="background:#E2E8F0; color:#475569; font-size:11px; font-weight:700; padding:2px 8px; border-radius:12px;"><?=count($plans)?> Plans</span>
          <?php if($network_filter):?>
          <button class="btn-toggle-type" onclick="openBulkPricing('<?=$network_filter?>','<?=htmlspecialchars($type,ENT_QUOTES)?>')" style="margin-left:auto; background:none; border:1px solid var(--primary); border-radius:6px; padding:4px 10px; cursor:pointer; font-size:12px; color:var(--primary); font-weight:600;" title="Bulk set pricing for <?=htmlspecialchars($type)?> plans"><i class="fas fa-percent"></i> Bulk Price</button>
          <button class="btn-toggle-type" onclick="toggleDataType('<?=$network_filter?>','<?=htmlspecialchars($type,ENT_QUOTES)?>')" style="margin-left:8px; background:none; border:1px solid #CBD5E1; border-radius:6px; padding:4px 10px; cursor:pointer; font-size:12px; color:#475569;" title="Enable/disable all <?=htmlspecialchars($type)?> plans"><i class="fas fa-power-off"></i> Toggle All</button>
          <?php endif;?>
      </div>
      <?php if($network_filter): $tstate = $type_provider_state[$type] ?? ['registry_id'=>null,'name'=>null,'is_override'=>false]; ?>
      <div style="display:flex; align-items:center; gap:8px; margin-top:10px; padding-top:10px; border-top:1px dashed #E2E8F0;">
          <span style="font-size:11px; font-weight:600; color:#64748B; min-width:90px;"><i class="fas fa-plug" style="margin-right:4px"></i> API Provider</span>
          <select class="form-control type-provider-select" data-network="<?=htmlspecialchars($network_filter,ENT_QUOTES)?>" data-type="<?=htmlspecialchars($type,ENT_QUOTES)?>" style="max-width:220px; font-size:12px; padding:5px 8px;">
              <option value="">— Use Network's Universal API —</option>
              <?php foreach($api_regs as $r):?>
              <option value="<?=$r['id']?>" <?=($tstate['is_override']&&$tstate['registry_id']==$r['id'])?'selected':''?>><?=htmlspecialchars($r['name'])?></option>
              <?php endforeach;?>
          </select>
          <button class="btn-toggle-type" onclick="saveTypeProvider(this)" style="background:none; border:1px solid #CBD5E1; border-radius:6px; padding:4px 10px; cursor:pointer; font-size:12px; color:#475569;"><i class="fas fa-save"></i> Save</button>
          <span style="font-size:11px; color:#94A3B8;">
              <?php if($tstate['name']):?>
                Currently serving via <strong><?=htmlspecialchars($tstate['name'])?></strong> (<?=$tstate['is_override']?'type override':'network default'?>)
              <?php else:?>
                <span style="color:var(--danger)"><i class="fas fa-triangle-exclamation"></i> No provider configured for this network/type</span>
              <?php endif;?>
          </span>
      </div>
      <?php endif;?>
      </div>
      <div class="responsive-grid-wrapper">
        <table class="data-table" style="margin-bottom:0;">
          <thead>
            <tr>
                <th style="width:70px; text-align:center; font-size:11px;">Reorder</th>
                <th>#</th>
                <th>Plan</th>
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
              <i class="fas fa-grip-vertical drag-handle" title="Drag to reorder"></i>
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
            <td style="color:#64748B; font-size:13px;">₦ <?=(float)$p['cost_price']?></td>
            <td style="color:#64748B; font-size:13px;">₦ <?=(float)$p['selling_price']?></td>
            <td style="color:#64748B; font-size:13px;">₦ <?=(float)($p['agent_price']??$p['selling_price'])?></td>
            <td style="color:#64748B; font-size:13px;">₦ <?=(float)($p['vendor_price']??$p['selling_price'])?></td>
            <td><label class="toggle"><input type="checkbox" <?=$p['is_active']?'checked':''?> onchange="toggleDataPlan(<?=$p['id']?>)"><span class="slider"></span></label></td>
            <td style="width:40px;">
                <button class="btn-primary" style="background:var(--navy-light); padding:6px 12px; border-radius:4px; font-size:12px;" onclick='editDataPlan(<?=htmlspecialchars(json_encode($p),ENT_QUOTES)?>)'><i class="fas fa-edit"></i></button>
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




<div class="modal-overlay" id="dataModal" style="display:none; align-items:center; justify-content:center;">
    <div class="modal-card" style="background:transparent; width:100%; max-width:600px; border-radius:8px; box-shadow:0 10px 25px rgba(0,0,0,0.15); overflow:hidden;">
        <div style="background:var(--primary); color:#fff; padding:16px 20px; font-weight:600; font-size:16px;">
            <span id="dataModalTitle">Add New Plan</span>
        </div>
        <div class="modal-body" style="padding:24px; max-height:80vh; overflow-y:auto; background:var(--surface); display:flex; flex-direction:column; gap:16px;">
            <input type="hidden" id="d_id">
            <div class="form-group"><label>Network</label><input type="text" class="form-control" id="d_network" placeholder="MTN"></div>
            <div style="padding:10px 14px;background:rgba(37,99,235,0.08);border:1px solid rgba(37,99,235,0.2);border-radius:6px;font-size:12px;color:var(--info);">
                <i class="fas fa-circle-info" style="margin-right:6px;"></i> Provider Plan IDs are configured separately on the <a href="<?= ADMIN_URL ?>/data-plan-mapping" style="color:var(--info);font-weight:600;text-decoration:underline;">Plan ID Mapping</a> page after saving this plan.
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
            <div style="display:flex; justify-content:space-between; margin-top:8px;">
                <button class="btn-primary" onclick="saveDataPlan()"><i class="fas fa-save" style="margin-right:6px;"></i> Save</button>
                <button class="btn-ghost" onclick="closeDataModal()">CLOSE</button>
            </div>
        </div>
    </div>
</div>
<div class="save-toast" id="toast"></div>
    <!-- Bulk Pricing Modal -->
    <div class="modal-overlay" id="bulkPricingModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,.5); z-index:999; align-items:center; justify-content:center;">
      <div class="modal-card" style="background:var(--surface); width:95%; max-width:500px; border-radius:8px; box-shadow:0 10px 25px rgba(0,0,0,0.15); overflow:hidden; position:relative;">
        <div style="background:var(--primary); color:#fff; padding:16px 20px; display:flex; justify-content:space-between; align-items:center; font-weight:600; font-size:16px;">
          <span style="margin:0;"><i class="fas fa-tags" style="margin-right:8px"></i> Bulk Set Prices</span>
          <button onclick="document.getElementById('bulkPricingModal').style.display='none'" style="background:none; border:none; color:#fff; cursor:pointer; font-size:16px; padding:4px;"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" style="padding:20px; max-height:80vh; overflow-y:auto; display:flex; flex-direction:column; gap:16px;">
          <input type="hidden" id="bp_network">
          <input type="hidden" id="bp_data_type">
          
          <div style="font-size:13px; color:var(--text-muted); line-height:1.5;">
              Enter the <b>percentage discount</b> you want to apply off the <b>Cost Price</b> for each tier. (e.g. 10 for 10%, 5 for 5%). Leave blank or 0 to set price equal to cost price.
          </div>

          <div class="form-group">
            <label>User Tier Discount (%)</label>
            <input type="number" step="any" class="form-control" id="bp_markup_user" placeholder="e.g. 10">
          </div>
          <div class="form-group">
            <label>Agent Tier Discount (%)</label>
            <input type="number" step="any" class="form-control" id="bp_markup_agent" placeholder="e.g. 5">
          </div>
          <div class="form-group">
            <label>Vendor Tier Discount (%)</label>
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

// DATA PLANS
function openDataModal(){
    document.getElementById('dataModalTitle').innerHTML='Add New Plan';
    ['d_id','d_plan_name','d_data_type','d_validity','d_cost_price','d_selling_price','d_agent_price','d_vendor_price','d_network','d_cashback_value'].forEach(i=>{if(document.getElementById(i))document.getElementById(i).value=''});
    if(document.getElementById('d_cashback_type')) document.getElementById('d_cashback_type').value='';
    document.getElementById('dataModal').style.display='flex';
}
function editDataPlan(p){
    document.getElementById('dataModalTitle').innerHTML='Edit Data Plan';
    document.getElementById('d_id').value=p.id;
    document.getElementById('d_network').value=p.network;
    document.getElementById('d_plan_name').value=p.plan_name;
    document.getElementById('d_data_type').value=p.data_type;
    document.getElementById('d_validity').value=p.validity||'';
    document.getElementById('d_cost_price').value=p.cost_price;
    document.getElementById('d_selling_price').value=p.selling_price;
    document.getElementById('d_agent_price').value=p.agent_price||'0';
    document.getElementById('d_vendor_price').value=p.vendor_price||'0';
    document.getElementById('d_cashback_type').value=p.cashback_type||'';
    document.getElementById('d_cashback_value').value=p.cashback_value||'';
    document.getElementById('dataModal').style.display='flex';
}
let dataPlansChanged=false;
async function saveDataPlan(){
    const wasNew=!document.getElementById('d_id').value;
    const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','save_data_plan');
    ['id','network','network_id','plan_name','display_name','data_type','display_type_name','data_size','validity','cost_price','selling_price','agent_price','vendor_price','cashback_type','cashback_value'].forEach(k=>{
        if(document.getElementById('d_'+k)) fd.append(k,document.getElementById('d_'+k).value);
    });
    fd.append('is_active','1');
    const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    showToast(r.message,r.success);
    if(r.success){
        if(wasNew){
            // Keep the modal open and cleared for the next entry instead of
            // reloading — lets an admin add a whole batch of plans back to
            // back without reopening "Add Plan" each time. The underlying
            // table is stale until the modal is closed, at which point
            // closeDataModal() reloads to catch it up.
            dataPlansChanged=true;
            openDataModal();
        } else {
            setTimeout(()=>location.reload(),900);
        }
    }
}
function closeDataModal(){
    document.getElementById('dataModal').style.display='none';
    if(dataPlansChanged) location.reload();
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

async function saveTypeProvider(btn){
    const row=btn.closest('div').querySelector('.type-provider-select');
    const network=row.dataset.network, dataType=row.dataset.type, registryId=row.value;
    const label=registryId?row.options[row.selectedIndex].text:'the network\'s universal API';
    if(!confirm('Switch '+dataType+' on '+network+' to use '+label+'? Purchases will automatically fall back to the universal API if this one fails.')) return;
    const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','set_type_provider');fd.append('network',network);fd.append('data_type',dataType);fd.append('api_registry_id',registryId);
    const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    showToast(r.message,r.success);
    if(r.success)setTimeout(()=>location.reload(),900);
}

const BULK_PRESETS = <?= json_encode($bulk_presets, JSON_UNESCAPED_SLASHES) ?>;

function openBulkPricing(network, dataType) {
    document.getElementById('bp_network').value = network;
    document.getElementById('bp_data_type').value = dataType;
    const preset = BULK_PRESETS.data[network + '|' + dataType] || {};
    document.getElementById('bp_markup_user').value = preset.markup_user ?? '';
    document.getElementById('bp_markup_agent').value = preset.markup_agent ?? '';
    document.getElementById('bp_markup_vendor').value = preset.markup_vendor ?? '';
    document.getElementById('bulkPricingModal').style.display = 'flex';
}

async function applyBulkPricing() {
    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('action', 'bulk_pricing');
    fd.append('network', document.getElementById('bp_network').value);
    fd.append('data_type', document.getElementById('bp_data_type').value);
    // Blank fields are sent as blank (not 0) — the backend leaves untouched
    // tiers alone instead of resetting their prices to bare cost price.
    fd.append('markup_user', document.getElementById('bp_markup_user').value);
    fd.append('markup_agent', document.getElementById('bp_markup_agent').value);
    fd.append('markup_vendor', document.getElementById('bp_markup_vendor').value);

    if(!confirm('Are you sure you want to apply this percentage discount to the ' + document.getElementById('bp_data_type').value + ' plans for ' + document.getElementById('bp_network').value + ' you entered a value for? This will overwrite their current selling prices based on their Cost Price — tiers left blank are not changed.')) return;

    const r = await fetch(location.href, {method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: fd}).then(r => r.json());
    showToast(r.message, r.success);
    if(r.success) setTimeout(() => location.reload(), 900);
}

function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),2500);}

function moveRow(btn,dir){
    var tr=btn.closest('tr'),tbody=tr.closest('tbody'),target=dir<0?tr.previousElementSibling:tr.nextElementSibling;
    if(!target||target.tagName!=='TR')return;
    tbody.insertBefore(tr,dir<0?target:target.nextSibling);
    saveOrder(tbody);
}

function saveOrder(tbody){
    var rows=tbody.querySelectorAll('tr[data-id]'),orders=[];
    rows.forEach(function(r,i){
        orders.push({id:r.getAttribute('data-id'),order:i});
        var numCell=r.children[1];
        if(numCell) numCell.textContent=i+1;
    });
    var fd=new FormData();
    fd.append('csrf',CSRF);
    fd.append('action','reorder_data_plans');
    fd.append('orders',JSON.stringify(orders));
    fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(function(r){return r.json();})
    .then(function(r){showToast(r.success?'Order saved':'Failed to save order',r.success);});
}

// Real drag-and-drop reordering (the grip handle), on top of the existing
// up/down buttons which stay as a keyboard/precision-friendly fallback.
document.querySelectorAll('tbody.sortable-group').forEach(function(tbody){
    Sortable.create(tbody, {
        handle: '.drag-handle',
        animation: 150,
        ghostClass: 'sortable-ghost',
        dragClass: 'sortable-drag',
        onEnd: function(){ saveOrder(tbody); }
    });
});
</script>

</body>
</html>