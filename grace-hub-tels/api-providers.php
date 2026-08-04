<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_admin();
require_permission('manage_api');

// Auto-migrate: add new columns and tables
foreach([
  'contract_code varchar(100) DEFAULT NULL AFTER is_active',
  'public_key varchar(255) DEFAULT NULL AFTER contract_code',
  'secret_key varchar(255) DEFAULT NULL AFTER public_key',
  'network_id_map text DEFAULT NULL AFTER secret_key',
  'value_map text DEFAULT NULL AFTER network_id_map',
  'api_registry_id INT DEFAULT NULL AFTER data_type'
] as $col){ try{ db()->exec("ALTER TABLE api_providers ADD COLUMN $col"); }catch(\Throwable $e){} }
try { db()->exec("CREATE TABLE IF NOT EXISTS plan_api_bindings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  service VARCHAR(50) NOT NULL,
  plan_id INT NOT NULL,
  api_registry_id INT NOT NULL,
  priority TINYINT(1) NOT NULL DEFAULT 1,
  product_code VARCHAR(100) DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY service_plan_api (service, plan_id, api_registry_id),
  KEY service_plan (service, plan_id),
  KEY api_registry_id (api_registry_id)
)"); } catch (\Throwable $e) {}

$csrf=admin_csrf();
$db=db();

if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token']);
    $action=$_POST['action']??'';

    if($action==='save_route'){
        $id=(int)($_POST['id']??0);
        $service=sanitize($_POST['service']??'',50);
        $network=$_POST['network']??'';
        $data_type=$_POST['data_type']??'';
        $api_registry_id = (int)($_POST['api_registry_id'] ?? 0) ?: null;
        $provider_name=sanitize($_POST['provider_name']??'',100);
        $api_base_url=rtrim(sanitize($_POST['api_base_url']??'',300),'/');
        $api_key=sanitize($_POST['api_key']??'',255);
        $api_token=sanitize($_POST['api_token']??'',255);
        $api_pin=sanitize($_POST['api_pin']??'',20);
        $contract_code=sanitize($_POST['contract_code']??'',100);
        $public_key=sanitize($_POST['public_key']??'',255);
        $secret_key=sanitize($_POST['secret_key']??'',255);
        $network_id_map=$_POST['network_id_map']??'';
        $value_map=$_POST['value_map']??'';
        $slot=sanitize($_POST['slot']??'primary',20);
        $is_active=isset($_POST['is_active'])?1:0;

        // Map slot to priority
        $prio_map=['primary'=>1,'backup_1'=>2,'backup_2'=>3,'backup_3'=>4];
        $priority=$prio_map[$slot]??1;

        // Auto-populate sensitive fields from registry (frontend can't access them)
        if (!$id && $api_registry_id) {
            $reg = $db->prepare("SELECT base_url, api_key, api_token, api_pin, contract_code, public_key, secret_key FROM api_registry WHERE id=?");
            $reg->execute([$api_registry_id]);
            $reg_data = $reg->fetch();
            if ($reg_data) {
                if (empty($api_base_url)) $api_base_url = $reg_data['base_url'] ?? '';
                if (empty($api_key)) $api_key = $reg_data['api_key'] ?? '';
                if (empty($api_token)) $api_token = $reg_data['api_token'] ?? '';
                if (empty($api_pin)) $api_pin = $reg_data['api_pin'] ?? '';
                if (empty($contract_code)) $contract_code = $reg_data['contract_code'] ?? '';
                if (empty($public_key)) $public_key = $reg_data['public_key'] ?? '';
                if (empty($secret_key)) $secret_key = $reg_data['secret_key'] ?? '';
            }
        }

        if($id){
            $sets=['service=?','network=?','data_type=?','api_registry_id=?','provider_name=?','priority=?','is_active=?','network_id_map=?','value_map=?'];
            $params=[$service,$network,$data_type,$api_registry_id,$provider_name,$priority,$is_active,$network_id_map,$value_map];
            // Only update optional/sensitive fields when non-empty
            foreach (['api_base_url','api_key','api_token','api_pin','contract_code','public_key','secret_key'] as $f) {
                if (!empty($$f)) {
                    $sets[] = "$f=?";
                    $params[] = $$f;
                }
            }
            $params[] = $id;
            $s=$db->prepare("UPDATE api_providers SET " . implode(',', $sets) . " WHERE id=?");
            $s->execute($params);
        }else{
            $ins_cols=['service','network','data_type','api_registry_id','provider_name','api_base_url','api_key','api_token','api_pin','priority','is_active','contract_code','public_key','secret_key','network_id_map','value_map'];
            $ins_vals=[$service,$network,$data_type,$api_registry_id,$provider_name,$api_base_url?:null,$api_key?:null,$api_token?:null,$api_pin?:null,$priority,$is_active,$contract_code?:null,$public_key?:null,$secret_key?:null,$network_id_map,$value_map];
            $s=$db->prepare("INSERT INTO api_providers (" . implode(',', $ins_cols) . ") VALUES (" . implode(',', array_fill(0, count($ins_cols), '?')) . ")");
            $s->execute($ins_vals);
        }
        json_response(['success'=>true,'message'=>'API route saved successfully']);
    }

    if($action==='delete_route'){
        $db->prepare("DELETE FROM api_providers WHERE id=?")->execute([(int)$_POST['id']]);
        json_response(['success'=>true]);
    }

    if($action==='toggle_route'){
        $db->prepare("UPDATE api_providers SET is_active=NOT is_active WHERE id=?")->execute([(int)$_POST['id']]);
        json_response(['success'=>true]);
    }

    if($action==='get_route'){
        $s=$db->prepare("SELECT p.*, r.name AS registry_name FROM api_providers p LEFT JOIN api_registry r ON p.api_registry_id = r.id WHERE p.id=?");
        $s->execute([(int)$_POST['id']]);
        $row=$s->fetch() ?: [];
        // Do not expose sensitive field values
        foreach (['api_key','api_token','api_pin','public_key','secret_key'] as $f) {
            if (isset($row[$f]) && $row[$f] !== null && $row[$f] !== '') {
                $row[$f] = '';
            }
        }
        json_response($row);
    }

    json_response(['success'=>false,'message'=>'Unknown action']);
}

// Get all providers grouped by service (join with registry)
$all_providers=$db->query("SELECT p.*, r.name AS registry_name FROM api_providers p LEFT JOIN api_registry r ON p.api_registry_id=r.id ORDER BY FIELD(p.service,'airtime','data','cabletv','electricity','exam_pin','recharge_card','data_card','bulk_sms','airtime_to_cash','bank_transfer','betting'),p.network,p.priority")->fetchAll();
// Get all registries for the dropdown
$registries=$db->query("SELECT id,name FROM api_registry WHERE is_active=1 ORDER BY name")->fetchAll();
$services=['airtime','data','cabletv','electricity','exam_pin','recharge_card','data_card','bulk_sms','airtime_to_cash','betting'];
$service_labels=[
  'airtime'=>'Airtime','data'=>'Data','cabletv'=>'Cable TV','electricity'=>'Electricity',
  'exam_pin'=>'Exam PINs','recharge_card'=>'Airtime PINs','data_card'=>'Data Cards',
  'bulk_sms'=>'Bulk SMS','airtime_to_cash'=>'Airtime to Cash','betting'=>'Betting'
];
$slot_labels=['primary'=>'Primary','backup_1'=>'Backup 1','backup_2'=>'Backup 2','backup_3'=>'Backup 3'];
$slot_colors=['primary'=>'var(--success)','backup_1'=>'var(--primary)','backup_2'=>'#3B82F6','backup_3'=>'#8B5CF6'];

// Group providers by service
$by_service=[];
foreach($all_providers as $p){
    $by_service[$p['service']][]=$p;
}

// Default ID maps & value maps for each service (pre-filled in Add Route form)
function _default_network_id_map(string $svc): array {
  $m = [
    'airtime'=>['MTN'=>1,'GLO'=>2,'AIRTEL'=>3,'9MOBILE'=>4],
    'data'=>['MTN'=>1,'GLO'=>2,'AIRTEL'=>3,'9MOBILE'=>4],
    'cabletv'=>['DSTV'=>1,'GOTV'=>2,'STARTIMES'=>3,'SHOWMAX'=>4],
    'electricity'=>['IKEDC'=>1,'EKEDC'=>2,'AEDC'=>3,'PHEDC'=>4,'IBEDC'=>5,'KEDCO'=>6,'EEDC'=>7,'KAEDCO'=>8,'JED'=>9],
    'exam_pin'=>['WAEC'=>1,'NECO'=>2,'NABTEB'=>3],
    'recharge_card'=>['MTN'=>1,'GLO'=>2,'AIRTEL'=>3,'9MOBILE'=>4],
    'data_card'=>['MTN'=>1,'GLO'=>2,'AIRTEL'=>3,'9MOBILE'=>4],
    'bulk_sms'=>[],
    'airtime_to_cash'=>['MTN'=>1,'GLO'=>2,'AIRTEL'=>3,'9MOBILE'=>4],
  ];
  return $m[$svc] ?? [];
}
$default_network_maps = [];
$default_value_maps = [];
foreach ($services as $svc) {
  $vm = _default_network_id_map($svc);
  $default_network_maps[$svc] = json_encode($vm);
  $lines = [];
  foreach ($vm as $k => $v) $lines[] = "$k=$v";
  $default_value_maps[$svc] = implode("\n", $lines);
}
// Additional defaults not covered by network_id_map
$default_value_maps['recharge_card'] = "denom_100=1\ndenom_200=2\ndenom_500=3\ndenom_1000=4";
$default_value_maps['electricity'] = "disco_1=1\ndisco_2=2\ndisco_3=3\ndisco_4=4\ndisco_5=5";
$default_value_maps['cabletv'] = "cable_1=1\ncable_2=2\ncable_3=3\ncable_4=4";
$default_value_maps['airtime'] = "";
$default_value_maps['data'] = "";
$default_value_maps['bulk_sms'] = "";
$default_value_maps['airtime_to_cash'] = "";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>API Routes — Gracehubtels Admin</title>
<link rel="icon" type="image/png" href="/assets/images/gracehubtels-favicon.png">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css">
<style>
.api-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px;margin-top:16px}
.api-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;transition:all .2s;box-shadow:var(--shadow-sm)}
.api-card:hover{box-shadow:var(--shadow-md);border-color:var(--primary)}
.api-card-head{display:flex;align-items:center;gap:10px;padding:14px 16px;background:var(--bg);border-bottom:1px solid var(--border)}
.api-card-head .slot-badge{font-size:10px;font-weight:700;text-transform:uppercase;padding:3px 10px;border-radius:20px;color:#fff;letter-spacing:.3px}
.api-card-head .api-name{font-size:14px;font-weight:700;color:var(--navy-dark);flex:1}
.api-card-body{padding:14px 16px;display:flex;flex-direction:column;gap:10px}
.api-card-body .field-row{display:flex;align-items:center;gap:8px;font-size:12px}
.api-card-body .field-row .field-label{color:var(--text-muted);min-width:80px;font-weight:600}
.api-card-body .field-row .field-value{color:var(--navy-dark);font-family:monospace;font-size:11px;word-break:break-all;flex:1}
.api-card-body .field-row .field-value.json{background:var(--bg);padding:2px 8px;border-radius:4px;font-size:10px}
.api-card-body .map-lines{display:flex;flex-wrap:wrap;gap:3px}
.api-card-body .map-lines .kv{background:var(--bg);padding:2px 8px;border-radius:4px;font-size:10px;font-family:monospace;color:var(--navy-dark)}
.api-card-foot{display:flex;gap:6px;padding:10px 16px;border-top:1px solid var(--border);background:var(--bg)}
.page-tabs{display:flex;gap:12px;overflow-x:auto;padding-bottom:8px;margin-bottom:16px;-webkit-overflow-scrolling:touch}
.page-tabs::-webkit-scrollbar{height:4px}
.page-tabs::-webkit-scrollbar-thumb{background:var(--border);border-radius:4px}
.page-tab{display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border-radius:var(--radius);background:var(--surface);border:1px solid var(--border);color:var(--text-muted);font-weight:600;font-size:13px;cursor:pointer;white-space:nowrap;transition:all .2s}
.page-tab:hover{border-color:var(--primary);color:var(--primary)}
.page-tab.active{background:var(--primary);color:#fff;border-color:var(--primary)}
.page-tab .count-badge{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;border-radius:10px;font-size:10px;font-weight:700;padding:0 6px;margin-left:6px;background:rgba(79,70,229,.12);color:var(--primary)}
.page-tab.active .count-badge{background:rgba(255,255,255,.2);color:#fff}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-grid .colspan-2{grid-column:1/-1}
@media(max-width:640px){.api-grid{grid-template-columns:1fr}.form-grid{grid-template-columns:1fr}.form-grid .colspan-2{grid-column:1}}
</style>
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header">
  <div>
    <h2><i class="fas fa-plug" style="color:var(--primary);margin-right:10px"></i> API Route Manager</h2>
    <p>Configure per-service API providers with primary &amp; backup slots for automatic failover</p>
  </div>
  <button class="btn-primary" onclick="openRouteModal()"><i class="fas fa-plus"></i> Add Route</button>
</div>

<!-- Service Tabs -->
<div class="page-tabs" id="serviceTabs">
  <?php foreach($services as $idx=>$svc): $routes=$by_service[$svc]??[];?>
  <button class="page-tab <?=$idx===0?'active':''?>" data-service="<?=$svc?>" onclick="switchService('<?=$svc?>')">
    <i class="fas <?=['airtime'=>'fa-phone','data'=>'fa-wifi','cabletv'=>'fa-tv','electricity'=>'fa-bolt','exam_pin'=>'fa-graduation-cap','recharge_card'=>'fa-ticket','data_card'=>'fa-sd-card','bulk_sms'=>'fa-comment-sms','airtime_to_cash'=>'fa-arrows-rotate','betting'=>'fa-futbol'][$svc]?>"></i>
    <?=$service_labels[$svc]??ucfirst($svc)?>
    <span class="count-badge"><?=count($routes)?></span>
  </button>
  <?php endforeach;?>
</div>

<!-- Tab Content -->
<?php foreach($services as $svc): $routes=$by_service[$svc]??[]; $networks=[]; foreach($routes as $r){$n=$r['network']?:'ALL'; $networks[$n][]=$r;}?>
<div class="service-panel" id="panel-<?=$svc?>" style="display:<?=$svc==='airtime'?'block':'none'?>">
  <?php if(empty($routes)):?>
  <div style="padding:40px;text-align:center;color:var(--text-muted)">
    <i class="fas fa-plug" style="font-size:40px;display:block;margin-bottom:12px;opacity:.3"></i>
    <p style="font-weight:600">No routes configured for <?=$service_labels[$svc]?></p>
    <button class="btn-primary" style="margin-top:12px" onclick="openRouteModal('<?=$svc?>')"><i class="fas fa-plus"></i> Add First Route</button>
  </div>
  <?php else:?>
  <?php foreach($networks as $net=>$net_routes):?>
  <div style="margin-top:16px">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
      <span style="font-size:13px;font-weight:700;color:var(--navy-dark);text-transform:uppercase"><?=htmlspecialchars($net)?></span>
      <span style="font-size:11px;color:var(--text-muted)">— <?=count($net_routes)?> route<?=count($net_routes)>1?'s':''?></span>
    </div>
    <div class="api-grid">
    <?php foreach($net_routes as $p):
      $slot=$p['priority']<=1?'primary':($p['priority']<=2?'backup_1':($p['priority']<=3?'backup_2':'backup_3'));
      $slot_label=$slot_labels[$slot]??'Backup';
      $slot_color=$slot_colors[$slot]??'var(--text-muted)';
    ?>
      <div class="api-card" id="card-<?=$p['id']?>">
        <div class="api-card-head">
          <span class="slot-badge" style="background:<?=$slot_color?>"><?=$slot_label?></span>
          <span class="api-name"><?=htmlspecialchars($p['provider_name']?:'Unnamed')?></span>
          <?php if (!empty($p['registry_name'])):?><span style="font-size:10px;background:var(--primary);color:#fff;padding:2px 8px;border-radius:10px;font-weight:600"><?=htmlspecialchars($p['registry_name'])?></span><?php endif;?>
          <label class="toggle" style="margin-left:auto"><input type="checkbox" <?=$p['is_active']?'checked':''?> onchange="toggleRoute(<?=$p['id']?>)"><span class="slider"></span></label>
        </div>
        <div class="api-card-body">
          <div class="field-row">
            <span class="field-label">URL</span>
            <span class="field-value"><?=htmlspecialchars($p['api_base_url'])?></span>
          </div>
          <?php if($p['contract_code']):?>
          <div class="field-row"><span class="field-label">Contract</span><span class="field-value"><?=htmlspecialchars($p['contract_code'])?></span></div>
          <?php endif;?>
          <?php if($p['public_key']):?>
          <div class="field-row"><span class="field-label">Public Key</span><span class="field-value">••••••<?=substr($p['public_key'],-6)?></span></div>
          <?php endif;?>
          <?php if($p['secret_key']):?>
          <div class="field-row"><span class="field-label">Secret Key</span><span class="field-value">••••••<?=substr($p['secret_key'],-6)?></span></div>
          <?php endif;?>
          <?php if($p['network_id_map']):?>
          <div class="field-row">
            <span class="field-label">ID Map</span>
            <span class="field-value json"><?=htmlspecialchars(substr($p['network_id_map'],0,60))?><?=strlen($p['network_id_map'])>60?'...':''?></span>
          </div>
          <?php endif;?>
          <?php if($p['value_map']): $lines=array_filter(explode("\n",$p['value_map']));?>
          <div class="field-row">
            <span class="field-label">Value Map</span>
            <div class="map-lines"><?php foreach(array_slice($lines,0,4) as $ln):?><span class="kv"><?=htmlspecialchars(trim($ln))?></span><?php endforeach;?><?=count($lines)>4?'<span class="kv">+'.(count($lines)-4).' more</span>':''?></div>
          </div>
          <?php endif;?>
          <?php if($p['data_type']):?>
          <div class="field-row"><span class="field-label">Data Type</span><span class="field-value"><?=htmlspecialchars($p['data_type'])?></span></div>
          <?php endif;?>
        </div>
        <div class="api-card-foot">
          <button class="btn-ghost" style="padding:5px 12px;font-size:11px" onclick='editRoute(<?=$p['id']?>)'><i class="fas fa-pen"></i> Edit</button>
          <button class="btn-ghost" style="padding:5px 12px;font-size:11px;color:var(--danger)" onclick="deleteRoute(<?=$p['id']?>)"><i class="fas fa-trash"></i> Delete</button>
        </div>
      </div>
    <?php endforeach;?>
    </div>
  </div>
  <?php endforeach;?>
  <?php endif;?>
  <div style="margin-top:16px;text-align:right">
    <button class="btn-ghost" onclick="openRouteModal('<?=$svc?>')"><i class="fas fa-plus"></i> Add Route for <?=$service_labels[$svc]?></button>
  </div>
</div>
<?php endforeach;?>

</div></div>

<!-- Route Modal -->
<div class="modal-overlay" id="routeModal" style="display:none" onclick="if(event.target===this)closeModal()">
  <div class="modal" style="max-width:720px">
    <div class="modal-header">
      <h3 id="modalTitle"><i class="fas fa-plug"></i> Add API Route</h3>
      <button class="modal-close" onclick="closeModal()">&times;</button>
    </div>
    <div class="modal-body" style="max-height:70vh;overflow-y:auto;padding:20px 24px;">
      <input type="hidden" id="r_id">
      <form id="routeForm" onsubmit="saveRoute(event)">
        <div class="form-grid">
          <div class="form-group">
            <label>Service Category</label>
            <select class="form-control" id="r_service" required>
              <?php foreach($service_labels as $k=>$l):?>
              <option value="<?=$k?>"><?=$l?></option>
              <?php endforeach;?>
            </select>
          </div>
          <div class="form-group">
            <label>Network / Target</label>
            <select class="form-control" id="r_network">
              <option value="">ALL (Any Network)</option>
            </select>
          </div>
          <div class="form-group">
            <label>API Registry <span style="color:var(--text-muted);font-weight:400">(optional)</span></label>
            <select class="form-control" id="r_api_registry_id" onchange="onRegistryChange()">
              <option value="">— None (manual entry) —</option>
              <?php foreach($registries as $reg):?>
              <option value="<?=$reg['id']?>" data-name="<?=htmlspecialchars($reg['name'])?>"><?=htmlspecialchars($reg['name'])?></option>
              <?php endforeach;?>
            </select>
          </div>
          <div class="form-group">
            <label>Route Name / Label</label>
            <input type="text" class="form-control" id="r_provider_name" placeholder="e.g. GladTidings Primary" required>
          </div>
          <div class="form-group">
            <label>Slot (Failover Order)</label>
            <select class="form-control" id="r_slot">
              <option value="primary">Primary</option>
              <option value="backup_1">Backup 1</option>
              <option value="backup_2">Backup 2</option>
              <option value="backup_3">Backup 3</option>
            </select>
          </div>
          <div class="form-group">
            <label>API Base URL</label>
            <input type="text" class="form-control" id="r_api_base_url" placeholder="https://api.example.com">
          </div>
          <input type="hidden" id="r_api_token">
          <input type="hidden" id="r_api_key">
          <input type="hidden" id="r_api_pin">
          <div class="form-group">
            <label>Data Type <span style="color:var(--text-muted);font-weight:400">(for Data service)</span></label>
            <select class="form-control" id="r_data_type_select" onchange="onDataTypeChange()">
              <option value="">ALL (any data type)</option>
              <option value="__custom__">Specific type...</option>
            </select>
            <input type="text" class="form-control" id="r_data_type" style="display:none;margin-top:6px" placeholder="e.g. SME, Gifting">
          </div>
          <div class="form-group colspan-2">
            <label>Network ID Map (JSON) <span style="color:var(--text-muted);font-weight:400">(optional)</span></label>
            <input type="text" class="form-control" id="r_network_id_map" placeholder='{"MTN":1,"GLO":2,"AIRTEL":3,"9MOBILE":4}'>
          </div>
          <div class="form-group colspan-2">
            <label>Value Map (one per line: key=value) <span style="color:var(--text-muted);font-weight:400">(optional)</span></label>
            <textarea class="form-control" id="r_value_map" rows="3" placeholder="abuja=8&#10;ikeja=1&#10;dstv=Dstv"></textarea>
          </div>
          <div class="form-group" style="display:flex;align-items:center;gap:10px;padding-top:8px">
            <label class="toggle"><input type="checkbox" id="r_is_active" checked><span class="slider"></span></label>
            <span style="font-size:13px;font-weight:600;color:var(--navy-dark)">Active</span>
          </div>
        </div>
        <div style="display:flex;justify-content:space-between;margin-top:20px;padding-top:16px;border-top:1px solid var(--border)">
          <button type="submit" class="btn-primary" id="btnSaveRoute"><i class="fas fa-save"></i> Save Route</button>
          <button type="button" class="btn-ghost" onclick="closeModal()">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
const CSRF='<?=$csrf?>';
const defaultNetworkMaps=<?=json_encode($default_network_maps)?>;
const defaultValueMaps=<?=json_encode($default_value_maps)?>;
// Network options per service for the dropdown
const serviceNetworks={
  airtime:['MTN','GLO','AIRTEL','9MOBILE'],
  data:['MTN','GLO','AIRTEL','9MOBILE'],
  cabletv:['DSTV','GOTV','STARTIMES','SHOWMAX'],
  electricity:['IKEDC','EKEDC','AEDC','PHEDC','IBEDC','KEDCO','EEDC','KAEDCO','JED'],
  exam_pin:['WAEC','NECO','NABTEB'],
  recharge_card:['MTN','GLO','AIRTEL','9MOBILE'],
  data_card:['MTN','GLO','AIRTEL','9MOBILE'],
  bulk_sms:[],
  airtime_to_cash:['MTN','GLO','AIRTEL','9MOBILE'],
  betting:[]
};
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');document.getElementById('sidebarOverlay').classList.toggle('show');}

function switchService(svc){
  document.querySelectorAll('.page-tab').forEach(t=>t.classList.remove('active'));
  document.querySelector(`.page-tab[data-service="${svc}"]`).classList.add('active');
  document.querySelectorAll('.service-panel').forEach(p=>p.style.display='none');
  document.getElementById('panel-'+svc).style.display='block';
}

function prefillDefaults(svc){
  const idMap=document.getElementById('r_network_id_map');
  const valMap=document.getElementById('r_value_map');
  if(!document.getElementById('r_id').value){
    idMap.value=defaultNetworkMaps[svc]||'';
    valMap.value=defaultValueMaps[svc]||'';
  }
}

// Fetch registry entry JSON and auto-fill the form
async function onRegistryChange(){
  const sel=document.getElementById('r_api_registry_id');
  const id=sel.value;
  const nameOption=sel.options[sel.selectedIndex];
  if(!id) return;
  // Auto-set route name from registry name
  const regName=nameOption?.getAttribute('data-name')||'';
  if(!document.getElementById('r_id').value && regName){
    document.getElementById('r_provider_name').value=regName;
  }
  // Fetch full registry data
  const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','get');fd.append('id',id);
  try {
    const r=await fetch('<?= ADMIN_URL ?>/api-registry.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    if(!r.id) return;
    if(r.base_url && !document.getElementById('r_id').value) document.getElementById('r_api_base_url').value=r.base_url;
    if(r.network_id_map) document.getElementById('r_network_id_map').value=r.network_id_map||'';
    if(r.value_map) document.getElementById('r_value_map').value=r.value_map||'';
  } catch(e){}
}

function openRouteModal(svc){
  document.getElementById('modalTitle').innerHTML='<i class="fas fa-plug"></i> Add API Route';
  document.getElementById('r_id').value='';
  document.getElementById('routeForm').reset();
  document.getElementById('r_is_active').checked=true;
  document.getElementById('r_data_type_select').value='';
  document.getElementById('r_data_type').style.display='none';
  if(svc){ document.getElementById('r_service').value=svc; updateNetworkOptions(svc); }
  document.getElementById('routeModal').style.display='flex';
  if(svc) prefillDefaults(svc);
}

// Populate network options based on service selection
function updateNetworkOptions(svc){
  const sel=document.getElementById('r_network');
  const current=sel.value;
  sel.innerHTML='<option value="">ALL (Any Network)</option>';
  (serviceNetworks[svc]||[]).forEach(function(n){
    const o=document.createElement('option');
    o.value=n; o.textContent=n;
    if(n===current) o.selected=true;
    sel.appendChild(o);
  });
}
// Pre-fill defaults & update network options when service changes
document.addEventListener('DOMContentLoaded',function(){
  document.getElementById('r_service').addEventListener('change',function(){
    updateNetworkOptions(this.value);
    if(!document.getElementById('r_id').value) prefillDefaults(this.value);
  });
  // Init network options for current service
  var initSvc=document.getElementById('r_service').value;
  if(initSvc) updateNetworkOptions(initSvc);
});

async function editRoute(id){
  document.getElementById('modalTitle').innerHTML='<i class="fas fa-pen"></i> Edit API Route';
  const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','get_route');fd.append('id',id);
  try {
    const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    if(!r.id) return alert('Route not found');
    document.getElementById('r_id').value=r.id;
    document.getElementById('r_service').value=r.service;
    updateNetworkOptions(r.service);
    document.getElementById('r_network').value=r.network||'';
    document.getElementById('r_api_registry_id').value=r.api_registry_id||'';
    document.getElementById('r_provider_name').value=r.provider_name||'';
    const slotMap={'1':'primary','2':'backup_1','3':'backup_2','4':'backup_3'};
    document.getElementById('r_slot').value=slotMap[r.priority]||'primary';
    document.getElementById('r_api_base_url').value=r.api_base_url||'';
    if(r.data_type){ document.getElementById('r_data_type_select').value='__custom__'; document.getElementById('r_data_type').value=r.data_type; document.getElementById('r_data_type').style.display='block'; }
    else { document.getElementById('r_data_type_select').value=''; document.getElementById('r_data_type').value=''; document.getElementById('r_data_type').style.display='none'; }
    document.getElementById('r_network_id_map').value=r.network_id_map||'';
    document.getElementById('r_value_map').value=r.value_map||'';
    document.getElementById('r_is_active').checked=r.is_active==1;
    document.getElementById('routeModal').style.display='flex';
  } catch(e){alert('Failed to load route');}
}

function closeModal(){
  document.getElementById('routeModal').style.display='none';
}

function onDataTypeChange(){
  const sel=document.getElementById('r_data_type_select');
  const inp=document.getElementById('r_data_type');
  if(sel.value==='__custom__'){ inp.style.display='block'; inp.focus(); }
  else { inp.style.display='none'; inp.value=''; }
}
async function saveRoute(e){
  e.preventDefault();
  const fd=new FormData();
  fd.append('csrf',CSRF);fd.append('action','save_route');
  ['id','service','network','api_registry_id','provider_name','api_base_url','network_id_map','value_map','slot'].forEach(k=>{
    const el=document.getElementById('r_'+k);
    if(el){
      const v=el.value;
      if(v) fd.append(k,v);
    }
  });
  // data_type: use text input value if "Specific type" selected, otherwise empty (ALL)
  const dtSelect=document.getElementById('r_data_type_select');
  if(dtSelect.value==='__custom__'){ const v=document.getElementById('r_data_type').value; if(v) fd.append('data_type',v); }
  else if(dtSelect.value) fd.append('data_type',dtSelect.value);
  if(document.getElementById('r_is_active').checked) fd.append('is_active','1');
  if(!document.getElementById('r_provider_name').value || !document.getElementById('r_api_base_url').value) return alert('API Name and Base URL are required');
  const btn=document.getElementById('btnSaveRoute');btn.innerHTML='Saving...';btn.disabled=true;
  try {
    const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    if(r.success) location.reload(); else alert(r.message);
  } catch(e){alert('Network error');}
  finally{btn.innerHTML='<i class="fas fa-save"></i> Save Route';btn.disabled=false;}
}

function deleteRoute(id){
  if(!confirm('Delete this API route?')) return;
  const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','delete_route');fd.append('id',id);
  fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json()).then(r=>{if(r.success)location.reload();else alert(r.message)});
}

async function toggleRoute(id){
  const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','toggle_route');fd.append('id',id);
  const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
  if(!r.success) location.reload();
}
</script>
</body>
</html>
