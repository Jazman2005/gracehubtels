<?php
require_once __DIR__.'/core/auth.php';
auth_admin();

// Load current settings
$current_services_json = setting('dashboard_services', '[]');
$current_banners_json = setting('dashboard_banners', '[]');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    if (!verify_admin_csrf($_POST['csrf'] ?? '')) {
        json_response(['success' => false, 'message' => 'Invalid token'], 403);
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_services') {
        $services_json = $_POST['services'] ?? '[]';
        // Validate JSON
        if (json_decode($services_json) === null) {
            json_response(['success' => false, 'message' => 'Invalid JSON data']);
        }
        setting_set('dashboard_services', $services_json);
        json_response(['success' => true, 'message' => 'Services updated successfully']);
    }
    
    if ($action === 'save_banners') {
        $banners_json = $_POST['banners'] ?? '[]';
        // Validate JSON
        if (json_decode($banners_json) === null) {
            json_response(['success' => false, 'message' => 'Invalid JSON data']);
        }
        setting_set('dashboard_banners', $banners_json);
        json_response(['success' => true, 'message' => 'Banners updated successfully']);
    }
    
    json_response(['success' => false, 'message' => 'Invalid action']);
}

$csrf = admin_csrf();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Dashboard Settings – MsjPay Admin</title>
    <link rel="icon" type="image/png" href="/msjpay/assets/images/msjpay-favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/msjpay/msj-admin/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .setting-box { border: 1px solid #E2E8F0; border-radius: 8px; padding: 15px; margin-bottom: 15px; background: #f8fafc; }
        .setting-box.inactive { opacity: 0.6; }
        .flex-row { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
        .flex-row .form-group { flex: 1; min-width: 150px; margin-bottom: 0; }
        .action-btn { background: #EF4444; color: #fff; border: none; padding: 8px 12px; border-radius: 6px; cursor: pointer; height: 38px;}
        .add-btn { background: #10B981; color: #fff; border: none; padding: 8px 15px; border-radius: 6px; cursor: pointer; font-weight: 600; margin-bottom: 15px; }
        .icon-preview { width: 38px; height: 38px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 18px; }
    </style>
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php'; ?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php'; ?>
<div class="page-content">
    <div class="page-head">
        <div>
            <h2>Dashboard Settings</h2>
            <p>Customize the User Dashboard layout, services, and banners.</p>
        </div>
    </div>

    <div class="filter-tabs" style="margin-bottom:16px">
        <button class="filter-tab active" onclick="showTab('services', this)">Quick Services</button>
        <button class="filter-tab" onclick="showTab('banners', this)">Promo Banners</button>
    </div>

    <!-- QUICK SERVICES TAB -->
    <div id="tab-services" class="card">
        <div class="card-header">
            <h3>Quick Services Grid</h3>
        </div>
        <div class="modal-body">
            <p style="margin-bottom:15px; color:#64748B; font-size:14px;">Toggle visibility or edit details for the quick services shown on the user dashboard. Warning: Changing the Link may break navigation.</p>
            
            <div id="services-container"></div>
            
            <button class="add-btn" onclick="addService()">+ Add New Service</button>
            <br>
            <button class="btn-primary" onclick="saveServices()">Save Services Configuration</button>
        </div>
    </div>

    <!-- PROMO BANNERS TAB -->
    <div id="tab-banners" class="card" style="display:none">
        <div class="card-header">
            <h3>Promo Banners Carousel</h3>
        </div>
        <div class="modal-body">
            <p style="margin-bottom:15px; color:#64748B; font-size:14px;">Edit the rotating promotional banners shown to users.</p>
            
            <div id="banners-container"></div>
            
            <button class="add-btn" onclick="addBanner()">+ Add New Banner</button>
            <br>
            <button class="btn-primary" onclick="saveBanners()">Save Banners Configuration</button>
        </div>
    </div>

</div>
</div>

<div class="save-toast" id="toast"></div>

<script>
const CSRF = '<?=$csrf?>';
let servicesData = <?= $current_services_json ?: '[]' ?>;
let bannersData = <?= $current_banners_json ?: '[]' ?>;

function showTab(t, btn) {
    document.querySelectorAll('[id^="tab-"]').forEach(e => e.style.display = 'none');
    document.getElementById('tab-' + t).style.display = 'block';
    document.querySelectorAll('.filter-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}

function showToast(msg, ok) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'save-toast show ' + (ok ? 'ok' : 'err');
    setTimeout(() => t.classList.remove('show'), 2500);
}

// ---- SERVICES LOGIC ----
function renderServices() {
    const container = document.getElementById('services-container');
    container.innerHTML = '';
    servicesData.forEach((svc, index) => {
        const box = document.createElement('div');
        box.className = 'setting-box ' + (svc.active ? '' : 'inactive');
        
        let html = `
            <div style="display:flex; justify-content:space-between; margin-bottom:10px;">
                <strong>Service #${index+1}</strong>
                <label class="toggle"><input type="checkbox" onchange="toggleService(${index}, this.checked)" ${svc.active ? 'checked' : ''}><span class="slider"></span></label>
            </div>
            <div class="flex-row">
                <div class="form-group"><label>Label</label><input type="text" class="form-control" value="${svc.label}" onchange="updateService(${index}, 'label', this.value)"></div>
                <div class="form-group"><label>Link (URL)</label><input type="text" class="form-control" value="${svc.link}" onchange="updateService(${index}, 'link', this.value)"></div>
                <div class="form-group"><label>FontAwesome Icon</label><input type="text" class="form-control" value="${svc.icon}" onchange="updateService(${index}, 'icon', this.value)"></div>
                <div class="icon-preview ${svc.icon_bg} ${svc.icon_color}" style="background-color:#e2e8f0;"><i class="fa-solid ${svc.icon}"></i></div>
            </div>
            <div class="flex-row" style="margin-top:10px;">
                <div class="form-group"><label>Icon Color (Tailwind)</label><input type="text" class="form-control" value="${svc.icon_color}" onchange="updateService(${index}, 'icon_color', this.value)"></div>
                <div class="form-group"><label>Icon BG (Tailwind)</label><input type="text" class="form-control" value="${svc.icon_bg}" onchange="updateService(${index}, 'icon_bg', this.value)"></div>
                <div class="form-group"><label>Hover Border (Tailwind)</label><input type="text" class="form-control" value="${svc.hover_border}" onchange="updateService(${index}, 'hover_border', this.value)"></div>
                <button class="action-btn" onclick="removeService(${index})"><i class="fas fa-trash"></i></button>
            </div>
        `;
        box.innerHTML = html;
        container.appendChild(box);
    });
}

function updateService(index, field, value) { servicesData[index][field] = value; renderServices(); }
function toggleService(index, checked) { servicesData[index].active = checked; renderServices(); }
function removeService(index) { if(confirm('Remove this service?')) { servicesData.splice(index, 1); renderServices(); } }
function addService() {
    servicesData.push({link: '#', label: 'New Service', icon_color: 'text-slate-500', icon_bg: 'bg-slate-100', hover_border: 'hover:border-slate-300', icon: 'fa-star', active: true});
    renderServices();
}

async function saveServices() {
    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('action', 'save_services');
    fd.append('services', JSON.stringify(servicesData));
    const r = await fetch(location.href, {method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: fd}).then(r=>r.json());
    showToast(r.message, r.success);
}

// ---- BANNERS LOGIC ----
function renderBanners() {
    const container = document.getElementById('banners-container');
    container.innerHTML = '';
    bannersData.forEach((bn, index) => {
        const box = document.createElement('div');
        box.className = 'setting-box ' + (bn.active ? '' : 'inactive');
        
        let html = `
            <div style="display:flex; justify-content:space-between; margin-bottom:10px;">
                <strong>Banner #${index+1}</strong>
                <label class="toggle"><input type="checkbox" onchange="toggleBanner(${index}, this.checked)" ${bn.active ? 'checked' : ''}><span class="slider"></span></label>
            </div>
            <div class="flex-row">
                <div class="form-group" style="flex:2;"><label>Title</label><input type="text" class="form-control" value="${bn.title}" onchange="updateBanner(${index}, 'title', this.value)"></div>
                <div class="form-group"><label>Tag (e.g. SPECIAL OFFER)</label><input type="text" class="form-control" value="${bn.tag}" onchange="updateBanner(${index}, 'tag', this.value)"></div>
            </div>
            <div class="form-group" style="margin-top:10px;"><label>Subtitle/Description</label><input type="text" class="form-control" value="${bn.subtitle}" onchange="updateBanner(${index}, 'subtitle', this.value)"></div>
            <div class="flex-row" style="margin-top:10px;">
                <div class="form-group"><label>Background Gradient (Tailwind)</label><input type="text" class="form-control" value="${bn.bg_gradient}" onchange="updateBanner(${index}, 'bg_gradient', this.value)"></div>
                <div class="form-group"><label>FontAwesome Icon</label><input type="text" class="form-control" value="${bn.icon}" onchange="updateBanner(${index}, 'icon', this.value)"></div>
                <button class="action-btn" onclick="removeBanner(${index})"><i class="fas fa-trash"></i></button>
            </div>
        `;
        box.innerHTML = html;
        container.appendChild(box);
    });
}

function updateBanner(index, field, value) { bannersData[index][field] = value; }
function toggleBanner(index, checked) { bannersData[index].active = checked; renderBanners(); }
function removeBanner(index) { if(confirm('Remove this banner?')) { bannersData.splice(index, 1); renderBanners(); } }
function addBanner() {
    bannersData.push({title: 'New Banner', subtitle: 'Banner description here.', tag: 'PROMO', bg_gradient: 'from-slate-600 to-slate-800', icon: 'fas fa-star', active: true});
    renderBanners();
}

async function saveBanners() {
    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('action', 'save_banners');
    fd.append('banners', JSON.stringify(bannersData));
    const r = await fetch(location.href, {method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: fd}).then(r=>r.json());
    showToast(r.message, r.success);
}

// Init
document.addEventListener('DOMContentLoaded', () => {
    renderServices();
    renderBanners();
});
</script>
</body>
</html>
