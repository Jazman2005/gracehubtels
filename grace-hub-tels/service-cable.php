<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
require_feature_admin('cabletv');
if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){

    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token'],403);
    require_permission('manage_pricing');
    $action=$_POST['action']??'';

    if($action==='save_cable_plan'){
        // Plan IDs (per API provider) are managed separately on the Data Plan IDs
        // page via plan_api_bindings — this form no longer collects or touches them,
        // so an edit here can't wipe out mappings set up over there.
        $id=(int)($_POST['id']??0);
        foreach(['cost_price'=>(float)($_POST['cost_price']??0),'selling_price'=>(float)($_POST['selling_price']??0),'agent_price'=>(float)($_POST['agent_price']??0),'vendor_price'=>(float)($_POST['vendor_price']??0)] as $label=>$val){
            if($val<0)json_response(['success'=>false,'message'=>ucwords(str_replace('_',' ',$label)).' cannot be negative'],400);
        }
        $f=[sanitize($_POST['provider']??'',50),(int)($_POST['cable_id']??0),sanitize($_POST['plan_name']??'',200),sanitize($_POST['display_name']??'',200)?:null,(float)($_POST['cost_price']??0),(float)($_POST['selling_price']??0),(float)($_POST['agent_price']??0),(float)($_POST['vendor_price']??0),isset($_POST['is_active'])?1:0];
        if($id){db()->prepare("UPDATE cable_plans SET provider=?,cable_id=?,plan_name=?,display_name=?,cost_price=?,selling_price=?,agent_price=?,vendor_price=?,is_active=? WHERE id=?")->execute([...$f,$id]);}
        else{db()->prepare("INSERT INTO cable_plans (provider,cable_id,plan_id,plan_name,display_name,cost_price,selling_price,agent_price,vendor_price,is_active) VALUES (?,?,0,?,?,?,?,?,?,?)")->execute($f);}
        json_response(['success'=>true,'message'=>'Cable plan saved']);
    }

    if($action==='delete_cable_plan'){
        db()->prepare("DELETE FROM cable_plans WHERE id=?")->execute([(int)$_POST['id']]);
        json_response(['success'=>true,'message'=>'Deleted']);
    }

    if($action==='bulk_pricing_cable'){
        $b_provider = sanitize($_POST['provider']??'',50);
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
            $params[] = $b_provider;
            db()->prepare("UPDATE cable_plans SET ".implode(', ', $sets)." WHERE provider=?")->execute($params);
        }

        // Remember whichever percentages were submitted this time, keeping the
        // previously-saved percentage for any tier left blank, so reopening
        // the modal later prefills all three tiers correctly.
        db()->prepare("INSERT INTO bulk_pricing_presets (service, scope_key, markup_user, markup_agent, markup_vendor) VALUES ('cable', ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    markup_user = COALESCE(VALUES(markup_user), markup_user),
                    markup_agent = COALESCE(VALUES(markup_agent), markup_agent),
                    markup_vendor = COALESCE(VALUES(markup_vendor), markup_vendor)")
            ->execute([$b_provider, $markup_user, $markup_agent, $markup_vendor]);

        json_response(['success'=>true, 'message'=>"Bulk pricing applied successfully for $b_provider"]);
    }


    json_response(['success'=>false],400);
}
$csrf=admin_csrf();
$cable_plans=db()->query("SELECT * FROM cable_plans ORDER BY provider,selling_price")->fetchAll();
// Last percentage applied per provider, keyed for instant client-side lookup
// so the Bulk Pricing modal can prefill previously-used percentages instead
// of resetting to blank.
$bulk_presets = [];
$bp_stmt = db()->prepare("SELECT scope_key, markup_user, markup_agent, markup_vendor FROM bulk_pricing_presets WHERE service='cable'");
$bp_stmt->execute();
foreach ($bp_stmt->fetchAll() as $bp) {
    $bulk_presets[$bp['scope_key']] = [
        'markup_user' => $bp['markup_user'],
        'markup_agent' => $bp['markup_agent'],
        'markup_vendor' => $bp['markup_vendor'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Cable TV – <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title>
    <link rel="icon" type="image/png" href="/assets/images/favicon.png">
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
            <h2>Cable TV</h2>
            <p>Manage Cable TV Packages</p>
        </div>
    </div>

    <div class="data-panel">
  <div class="panel-title-bar">
      <h3><i class="fas fa-tv" style="color:var(--primary);margin-right:8px"></i> Cable TV Plans</h3>
      <div style="margin-left:auto; display:flex; gap:8px;">
          <button class="btn-primary" onclick="openBulkPricingCable()" style="background:var(--surface); color:var(--primary); border:1px solid var(--primary); font-size:13px; padding:8px 16px; border-radius:8px;">
              <i class="fas fa-percent"></i> Bulk Pricing
          </button>
          <button class="btn-primary" onclick="openCableModal()" style="font-size:13px; padding:8px 16px; border-radius:8px;">
              <i class="fas fa-plus"></i> Add Plan
          </button>
      </div>
  </div>
  <div class="responsive-grid-wrapper">
    <table class="data-table">
      <thead><tr><th>Provider</th><th>Plan Name</th><th>Display Name</th><th>Cost</th><th>Selling</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($cable_plans as $p):?>
      <tr>
        <td><strong style="color:var(--navy-dark)"><?=$p['provider']?></strong></td>
        <td style="font-weight:600; font-size:13px;"><?=htmlspecialchars($p['plan_name'])?></td>
        <td style="color:var(--primary); font-weight:600; font-size:13px;"><?=htmlspecialchars($p['display_name']??'—')?></td>
        <td style="font-size:13px; color:var(--danger);">₦<?=number_format((float)$p['cost_price'],2)?></td>
        <td style="font-size:14px; color:var(--success); font-weight:bold;">₦<?=number_format((float)$p['selling_price'],2)?></td>
        <td><span class="badge <?=$p['is_active']?'badge-success':'badge-neutral'?>"><?=$p['is_active']?'Active':'Inactive'?></span></td>
        <td style="white-space:nowrap">
            <button class="btn-primary" style="background:var(--navy-light); padding:6px 12px; font-size:12px; border-radius:6px; margin-right:4px;" onclick='editCablePlan(<?=htmlspecialchars(json_encode($p),ENT_QUOTES)?>)'><i class="fas fa-edit"></i></button>
            <button class="btn-primary" style="background:var(--danger); padding:6px 12px; font-size:12px; border-radius:6px;" onclick="deleteCablePlan(<?=$p['id']?>)"><i class="fas fa-trash"></i></button>
        </td>
      </tr>
      <?php endforeach;?>
      </tbody>
    </table>
  </div>
</div>


</div>

</div>
</div>




<div class="modal-overlay" id="cableModal" style="display:none; align-items:center; justify-content:center;">
    <div class="modal-card" style="background:transparent; width:100%; max-width:600px; border-radius:8px; box-shadow:0 10px 25px rgba(0,0,0,0.15); overflow:hidden;">
        <div style="background:var(--primary); color:#fff; padding:16px 20px; font-weight:600; font-size:16px;">
            <span id="cableModalTitle">Add Cable Plan</span>
        </div>
        <div class="modal-body" style="padding:24px; max-height:80vh; overflow-y:auto; background:var(--surface); display:flex; flex-direction:column; gap:16px;">
            <input type="hidden" id="c_id">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="form-group"><label>Provider</label><input type="text" class="form-control" id="c_provider" placeholder="DSTV"></div>
                <div class="form-group"><label>Cable ID</label><input type="text" class="form-control" id="c_cable_id"></div>
            </div>
            <div class="form-group"><label>Plan Name</label><input type="text" class="form-control" id="c_plan_name"></div>
            <div style="padding:10px 14px;background:rgba(37,99,235,0.08);border:1px solid rgba(37,99,235,0.2);border-radius:6px;font-size:12px;color:var(--info);">
                <i class="fas fa-circle-info" style="margin-right:6px;"></i> Provider Plan IDs are configured separately on the <a href="<?= ADMIN_URL ?>/data-plan-mapping" style="color:var(--info);font-weight:600;text-decoration:underline;">Plan ID Mapping</a> page after saving this plan.
            </div>
            <div class="form-group"><label>Display Name</label><input type="text" class="form-control" id="c_display_name"></div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="form-group"><label>Cost Price</label><input type="number" class="form-control" id="c_cost_price"></div>
                <div class="form-group"><label>Selling Price</label><input type="number" class="form-control" id="c_selling_price"></div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="form-group"><label>Agent Price</label><input type="number" class="form-control" id="c_agent_price"></div>
                <div class="form-group"><label>Vendor Price</label><input type="number" class="form-control" id="c_vendor_price"></div>
            </div>
            <div style="display:flex; align-items:center;">
                <label style="font-size:14px; font-weight:600; color:var(--navy-dark); margin-right:16px;">Active</label>
                <label class="toggle"><input type="checkbox" id="c_is_active" checked><span class="slider"></span></label>
            </div>
            <div style="display:flex; justify-content:space-between; margin-top:8px;">
                <button class="btn-primary" onclick="saveCablePlan()"><i class="fas fa-save" style="margin-right:6px;"></i> Save</button>
                <button class="btn-ghost" onclick="closeCableModal()">CLOSE</button>
            </div>
        </div>
    </div>
</div>

<div class="modal-overlay" id="bulkPricingCableModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,.5); z-index:999; align-items:center; justify-content:center;">
  <div class="modal-card" style="background:var(--surface); width:95%; max-width:500px; border-radius:8px; box-shadow:0 10px 25px rgba(0,0,0,0.15); overflow:hidden; position:relative;">
    <div style="background:var(--primary); color:#fff; padding:16px 20px; display:flex; justify-content:space-between; align-items:center; font-weight:600; font-size:16px;">
      <span style="margin:0;"><i class="fas fa-tags" style="margin-right:8px"></i> Bulk Set Cable Prices</span>
      <button onclick="document.getElementById('bulkPricingCableModal').style.display='none'" style="background:none; border:none; color:#fff; cursor:pointer; font-size:16px; padding:4px;"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" style="padding:20px; max-height:80vh; overflow-y:auto; display:flex; flex-direction:column; gap:16px;">
      
      <div style="font-size:13px; color:var(--text-muted); line-height:1.5;">
          Select a provider, then enter the <b>percentage discount</b> you want to apply off the <b>Cost Price</b>.
      </div>
      
      <?php
      $providers = [];
      foreach($cable_plans as $p) {
          if(!in_array($p['provider'], $providers)) $providers[] = $p['provider'];
      }
      ?>
      <div class="form-group">
        <label>Select Provider</label>
        <select class="form-control" id="bpc_provider" onchange="loadBulkPricingCablePreset()">
            <option value="">-- Choose --</option>
            <?php foreach($providers as $prov):?>
            <option value="<?=htmlspecialchars($prov)?>"><?=htmlspecialchars($prov)?></option>
            <?php endforeach;?>
        </select>
      </div>

      <div class="form-group">
        <label>User Tier Discount (%)</label>
        <input type="number" step="any" class="form-control" id="bpc_markup_user" placeholder="e.g. 10">
      </div>
      <div class="form-group">
        <label>Agent Tier Discount (%)</label>
        <input type="number" step="any" class="form-control" id="bpc_markup_agent" placeholder="e.g. 5">
      </div>
      <div class="form-group">
        <label>Vendor Tier Discount (%)</label>
        <input type="number" step="any" class="form-control" id="bpc_markup_vendor" placeholder="e.g. 2">
      </div>
      
      <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:8px;">
        <button class="btn-ghost" onclick="document.getElementById('bulkPricingCableModal').style.display='none'" style="border:1px solid var(--border); padding:8px 16px;">Cancel</button>
        <button class="btn-primary" onclick="applyBulkPricingCable()" style="padding:8px 16px;"><i class="fas fa-check"></i> Apply Pricing</button>
      </div>
    </div>
  </div>
</div>

<div class="save-toast" id="toast"></div>
<script>
const CSRF='<?=$csrf?>';
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}

// CABLE PLANS
function openCableModal(){
    document.getElementById('cableModalTitle').innerHTML='<i class="fas fa-plus"></i> Add Cable Plan';
    ['c_id','c_cable_id','c_plan_name','c_display_name','c_cost_price','c_selling_price','c_agent_price','c_vendor_price'].forEach(i=>document.getElementById(i).value='');
    document.getElementById('c_is_active').checked=true;
    document.getElementById('cableModal').style.display='flex';
}
function editCablePlan(p){
    document.getElementById('cableModalTitle').innerHTML='<i class="fas fa-edit"></i> Edit Cable Plan';
    document.getElementById('c_id').value=p.id;
    document.getElementById('c_provider').value=p.provider;
    document.getElementById('c_cable_id').value=p.cable_id;
    document.getElementById('c_plan_name').value=p.plan_name;
    document.getElementById('c_display_name').value=p.display_name||'';
    document.getElementById('c_cost_price').value=p.cost_price;
    document.getElementById('c_selling_price').value=p.selling_price;
    document.getElementById('c_agent_price').value=p.agent_price||'0';
    document.getElementById('c_vendor_price').value=p.vendor_price||'0';
    document.getElementById('c_is_active').checked=p.is_active==1;
    document.getElementById('cableModal').style.display='flex';
}
let cablePlansChanged=false;
async function saveCablePlan(){
    const wasNew=!document.getElementById('c_id').value;
    const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','save_cable_plan');
    ['id','provider','cable_id','plan_name','display_name','cost_price','selling_price','agent_price','vendor_price'].forEach(k=>fd.append(k,document.getElementById('c_'+k).value));
    if(document.getElementById('c_is_active').checked)fd.append('is_active','1');
    const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    showToast(r.message,r.success);
    if(r.success){
        if(wasNew){
            // Keep the modal open and cleared for the next entry instead of
            // reloading — lets an admin add a whole batch of plans back to
            // back without reopening "Add Plan" each time. The underlying
            // table is stale until the modal is closed, at which point
            // closeCableModal() reloads to catch it up.
            cablePlansChanged=true;
            openCableModal();
        } else {
            setTimeout(()=>location.reload(),900);
        }
    }
}
function closeCableModal(){
    document.getElementById('cableModal').style.display='none';
    if(cablePlansChanged) location.reload();
}
async function deleteCablePlan(id){if(!confirm('Delete?'))return;const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','delete_cable_plan');fd.append('id',id);const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),700);}

const BULK_PRESETS_CABLE = <?= json_encode($bulk_presets, JSON_UNESCAPED_SLASHES) ?>;

function openBulkPricingCable() {
    document.getElementById('bpc_provider').value = '';
    document.getElementById('bpc_markup_user').value = '';
    document.getElementById('bpc_markup_agent').value = '';
    document.getElementById('bpc_markup_vendor').value = '';
    document.getElementById('bulkPricingCableModal').style.display = 'flex';
}

function loadBulkPricingCablePreset() {
    const prov = document.getElementById('bpc_provider').value;
    const preset = BULK_PRESETS_CABLE[prov] || {};
    document.getElementById('bpc_markup_user').value = preset.markup_user ?? '';
    document.getElementById('bpc_markup_agent').value = preset.markup_agent ?? '';
    document.getElementById('bpc_markup_vendor').value = preset.markup_vendor ?? '';
}

async function applyBulkPricingCable() {
    const prov = document.getElementById('bpc_provider').value;
    if(!prov) return alert('Please select a provider first.');
    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('action', 'bulk_pricing_cable');
    fd.append('provider', prov);
    // Blank fields are sent as blank (not 0) — the backend leaves untouched
    // tiers alone instead of resetting their prices to bare cost price.
    fd.append('markup_user', document.getElementById('bpc_markup_user').value);
    fd.append('markup_agent', document.getElementById('bpc_markup_agent').value);
    fd.append('markup_vendor', document.getElementById('bpc_markup_vendor').value);

    if(!confirm('Are you sure you want to apply this percentage discount to the ' + prov + ' plans you entered a value for? This will overwrite their current selling prices based on their Cost Price — tiers left blank are not changed.')) return;

    const r = await fetch(location.href, {method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: fd}).then(r => r.json());
    showToast(r.message, r.success);
    if(r.success) setTimeout(() => location.reload(), 900);
}

function showToast(msg,ok){const t=document.getElementById('toast');t.textContent=msg;t.className='save-toast show '+(ok?'ok':'err');setTimeout(()=>t.classList.remove('show'),2500);}
</script>

</body>
</html>