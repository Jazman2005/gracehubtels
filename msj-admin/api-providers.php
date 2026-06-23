<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_admin();
require_permission('manage_api'); // Optional: check if they have permission to manage API

$csrf=admin_csrf();
$db = db();
$msg = '';

if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??''))json_response(['success'=>false,'message'=>'Invalid token']);
    $action=$_POST['action']??'';
    
    if($action==='save_api'){
        $id = (int)($_POST['id']??0);
        $service = sanitize($_POST['service']??'', 50);
        $network = sanitize($_POST['network']??'', 20) ?: null;
        $data_type = sanitize($_POST['data_type']??'', 50) ?: null;
        $provider_name = sanitize($_POST['provider_name']??'', 100);
        $api_base_url = sanitize($_POST['api_base_url']??'', 300);
        $api_key = sanitize($_POST['api_key']??'', 255) ?: null;
        $api_secret = sanitize($_POST['api_secret']??'', 255) ?: null;
        $api_token = sanitize($_POST['api_token']??'', 255) ?: null;
        $priority = (int)($_POST['priority']??1);
        $is_active = isset($_POST['is_active'])?1:0;
        
        if($id){
            $s = $db->prepare("UPDATE api_providers SET service=?,network=?,data_type=?,provider_name=?,api_base_url=?,api_key=?,api_secret=?,api_token=?,priority=?,is_active=? WHERE id=?");
            $s->execute([$service,$network,$data_type,$provider_name,$api_base_url,$api_key,$api_secret,$api_token,$priority,$is_active,$id]);
        }else{
            $s = $db->prepare("INSERT INTO api_providers (service,network,data_type,provider_name,api_base_url,api_key,api_secret,api_token,priority,is_active) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $s->execute([$service,$network,$data_type,$provider_name,$api_base_url,$api_key,$api_secret,$api_token,$priority,$is_active]);
        }
        json_response(['success'=>true,'message'=>'API Provider saved successfully']);
    }
    if($action==='delete_api'){
        $db->prepare("DELETE FROM api_providers WHERE id=?")->execute([(int)$_POST['id']]);
        json_response(['success'=>true,'message'=>'Provider deleted']);
    }
}

$providers = $db->query("SELECT * FROM api_providers ORDER BY service, priority")->fetchAll();

$page_title = 'Services API Setup';
include __DIR__.'/includes/header.php';
include __DIR__.'/includes/sidebar.php';
?>

<div class="main-content">
    <div class="top-bar">
        <div class="page-title">
            <i class="fas fa-plug text-primary" style="margin-right:12px;"></i>
            Services API Providers
        </div>
        <div class="user-info">
            <div class="user-avatar"><?=substr($_SESSION['admin_name']??'A',0,1)?></div>
            <span><?=$_SESSION['admin_name']??'Admin'?></span>
        </div>
    </div>

    <div class="card fade-in">
        <div class="card-header" style="border-bottom:1px solid #E2E8F0; padding-bottom:16px;">
            <h3><i class="fas fa-network-wired" style="color:var(--primary);margin-right:8px"></i> Configured VTU Providers</h3>
            <button class="btn-primary" onclick="openApiModal()" style="font-size:13px; padding:8px 16px; border-radius:8px;">
                <i class="fas fa-plus"></i> Add Provider
            </button>
        </div>
        
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Service</th>
                        <th>Network/Type</th>
                        <th>Provider Name</th>
                        <th>Base URL</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($providers as $p): ?>
                <tr>
                    <td><span class="badge badge-neutral"><?=ucwords(str_replace('_',' ',$p['service']))?></span></td>
                    <td style="font-size:13px; color:#475569;">
                        <?=htmlspecialchars($p['network']?:'ALL')?> 
                        <?=$p['data_type']?"<br><code style='font-size:11px'>{$p['data_type']}</code>":''?>
                    </td>
                    <td style="font-weight:600; font-size:13px; color:var(--navy-dark);"><?=htmlspecialchars($p['provider_name'])?></td>
                    <td style="font-size:12px; color:#64748B;"><?=htmlspecialchars(substr($p['api_base_url'],0,30))?>...</td>
                    <td style="font-weight:600; font-size:13px; color:#475569;"><?=$p['priority']?></td>
                    <td>
                        <label class="toggle"><input type="checkbox" <?=$p['is_active']?'checked':''?> disabled><span class="slider"></span></label>
                    </td>
                    <td>
                        <button class="btn-primary" style="background:#F97316; padding:6px 12px; border-radius:4px; font-size:12px;" onclick='editApi(<?=htmlspecialchars(json_encode($p),ENT_QUOTES)?>)'><i class="fas fa-edit"></i></button>
                        <button class="btn-primary" style="background:#EF4444; padding:6px 12px; border-radius:4px; font-size:12px;" onclick="deleteApi(<?=$p['id']?>)"><i class="fas fa-trash"></i></button>
                    </td>
                </tr>
                <?php endforeach; if(empty($providers)): ?>
                <tr><td colspan="7" class="empty-state">No API Providers configured yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- API MODAL -->
<div class="modal-overlay" id="apiModal" style="display:none; align-items:center; justify-content:center;">
    <div class="modal-card" style="background:transparent; width:100%; max-width:650px; border-radius:8px; box-shadow:0 10px 25px rgba(0,0,0,0.15); overflow:hidden;">
        <div style="background:#0CA4A5; color:#fff; padding:16px 20px; font-weight:600; font-size:16px;">
            <span id="apiModalTitle">Add Provider</span>
        </div>
        <div class="modal-body" style="padding:24px; max-height:80vh; overflow-y:auto; background:#fff; display:flex; flex-direction:column; gap:20px;">
            <input type="hidden" id="a_id">
            
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="form-group">
                    <label style="font-size:13px; color:#475569; font-weight:500; margin-bottom:6px; display:block;">Service Target</label>
                    <select class="form-control" id="a_service" style="border-radius:4px; border-color:#E2E8F0;">
                        <option value="data">Data</option>
                        <option value="airtime">Airtime</option>
                        <option value="cabletv">Cable TV</option>
                        <option value="electricity">Electricity</option>
                        <option value="exam_pin">Exam Pins</option>
                        <option value="bulk_sms">Bulk SMS</option>
                        <option value="airtime_to_cash">Airtime To Cash</option>
                    </select>
                </div>
                <div class="form-group">
                    <label style="font-size:13px; color:#475569; font-weight:500; margin-bottom:6px; display:block;">Provider Name</label>
                    <input type="text" class="form-control" id="a_provider_name" placeholder="e.g. VTPass, Husmodata" style="border-radius:4px; border-color:#E2E8F0;">
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="form-group">
                    <label style="font-size:13px; color:#475569; font-weight:500; margin-bottom:6px; display:block;">Network (Optional filter)</label>
                    <input type="text" class="form-control" id="a_network" placeholder="e.g. MTN, GLO" style="border-radius:4px; border-color:#E2E8F0;">
                </div>
                <div class="form-group">
                    <label style="font-size:13px; color:#475569; font-weight:500; margin-bottom:6px; display:block;">Data Type (Optional filter)</label>
                    <input type="text" class="form-control" id="a_data_type" placeholder="e.g. SME, CG" style="border-radius:4px; border-color:#E2E8F0;">
                </div>
            </div>

            <div class="form-group">
                <label style="font-size:13px; color:#475569; font-weight:500; margin-bottom:6px; display:block;">API Base URL</label>
                <input type="text" class="form-control" id="a_api_base_url" placeholder="https://..." style="border-radius:4px; border-color:#E2E8F0;">
            </div>

            <div class="form-group">
                <label style="font-size:13px; color:#475569; font-weight:500; margin-bottom:6px; display:block;">API Key / Auth Header</label>
                <input type="text" class="form-control" id="a_api_key" placeholder="API Key" style="border-radius:4px; border-color:#E2E8F0;">
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="form-group">
                    <label style="font-size:13px; color:#475569; font-weight:500; margin-bottom:6px; display:block;">API Secret (Optional)</label>
                    <input type="text" class="form-control" id="a_api_secret" placeholder="API Secret" style="border-radius:4px; border-color:#E2E8F0;">
                </div>
                <div class="form-group">
                    <label style="font-size:13px; color:#475569; font-weight:500; margin-bottom:6px; display:block;">API Token (Optional)</label>
                    <input type="text" class="form-control" id="a_api_token" placeholder="Bearer Token" style="border-radius:4px; border-color:#E2E8F0;">
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="form-group">
                    <label style="font-size:13px; color:#475569; font-weight:500; margin-bottom:6px; display:block;">Priority Route (1 = Highest)</label>
                    <input type="number" class="form-control" id="a_priority" value="1" style="border-radius:4px; border-color:#E2E8F0;">
                </div>
                <div style="display:flex; align-items:center; margin-top:20px;">
                    <label style="font-size:14px; font-weight:600; color:var(--navy-dark); margin-right:16px;">Active</label>
                    <label class="toggle"><input type="checkbox" id="a_is_active" checked><span class="slider"></span></label>
                </div>
            </div>

            <div style="display:flex; justify-content:space-between; margin-top:8px;">
                <button style="background:#0CA4A5; color:#fff; border:none; padding:10px 24px; font-weight:600; font-size:14px; border-radius:4px; cursor:pointer;" onclick="saveApi()"><i class="fas fa-save" style="margin-right:6px;"></i> Save Provider</button>
                <button style="background:#E2E8F0; color:#475569; border:none; padding:10px 24px; font-weight:600; font-size:14px; border-radius:4px; cursor:pointer;" onclick="document.getElementById('apiModal').style.display='none'">CLOSE</button>
            </div>
        </div>
    </div>
</div>

<script>
const CSRF='<?=$csrf?>';
function openApiModal(){
    document.getElementById('apiModalTitle').innerText='Add New API Provider';
    ['a_id','a_network','a_data_type','a_provider_name','a_api_base_url','a_api_key','a_api_secret','a_api_token'].forEach(id=>{document.getElementById(id).value=''});
    document.getElementById('a_priority').value=1;
    document.getElementById('a_is_active').checked=true;
    document.getElementById('apiModal').style.display='flex';
}
function editApi(p){
    document.getElementById('apiModalTitle').innerText='Edit API Provider';
    document.getElementById('a_id').value=p.id;
    document.getElementById('a_service').value=p.service;
    document.getElementById('a_network').value=p.network||'';
    document.getElementById('a_data_type').value=p.data_type||'';
    document.getElementById('a_provider_name').value=p.provider_name;
    document.getElementById('a_api_base_url').value=p.api_base_url;
    document.getElementById('a_api_key').value=p.api_key||'';
    document.getElementById('a_api_secret').value=p.api_secret||'';
    document.getElementById('a_api_token').value=p.api_token||'';
    document.getElementById('a_priority').value=p.priority;
    document.getElementById('a_is_active').checked=p.is_active==1;
    document.getElementById('apiModal').style.display='flex';
}
async function saveApi(){
    const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','save_api');
    ['id','service','network','data_type','provider_name','api_base_url','api_key','api_secret','api_token','priority'].forEach(k=>{
        fd.append(k,document.getElementById('a_'+k).value);
    });
    if(document.getElementById('a_is_active').checked)fd.append('is_active','1');
    const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    showToast(r.message,r.success);
    if(r.success)setTimeout(()=>location.reload(),1000);
}
function deleteApi(id){
    if(!confirm('Delete this API provider?'))return;
    const fd=new FormData();fd.append('csrf',CSRF);fd.append('action','delete_api');fd.append('id',id);
    fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json()).then(r=>{
        showToast(r.message,r.success);if(r.success)setTimeout(()=>location.reload(),1000);
    });
}
</script>
<?php include __DIR__.'/includes/footer.php'; ?>
