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
        if (json_decode($services_json) === null) {
            json_response(['success' => false, 'message' => 'Invalid JSON data']);
        }
        setting_set('dashboard_services', $services_json);
        json_response(['success' => true, 'message' => 'Services updated successfully']);
    }
    
    if ($action === 'save_banners') {
        $banners_json = $_POST['banners'] ?? '[]';
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
    <!-- Load Tailwind just for the preview to work visually exactly like the frontend -->
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --primary: #4F46E5;
            --bg: #F8FAFC;
        }
        body { font-family: 'Sora', sans-serif; background-color: var(--bg); margin: 0; }
        .main-wrap { display: flex; flex-direction: column; height: 100vh; overflow: hidden; }
        .page-content { flex: 1; padding: 24px; overflow-y: auto; }
        .builder-card { background: #fff; border-radius: 16px; border: 1px solid #e2e8f0; margin-bottom: 24px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); }
        .builder-card-header { background: #F8FAFC; padding: 16px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
        .builder-card-header h3 { font-size: 16px; font-weight: 800; color: #1e293b; margin: 0; }
        .builder-card-body { padding: 24px; }
        
        .item-box { display: flex; gap: 24px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 16px; transition: all 0.2s; position: relative; }
        .item-box:hover { border-color: #cbd5e1; box-shadow: 0 4px 12px rgba(0,0,0,0.03); }
        .item-box.inactive { opacity: 0.5; background: #f8fafc; }
        
        .form-grid-custom { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; flex: 1; }
        .form-group-custom label { display: block; font-size: 12px; font-weight: 700; color: #64748b; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
        .form-control-custom { width: 100%; height: 42px; padding: 0 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-family: inherit; font-size: 14px; background: #f8fafc; color: #1e293b; outline: none; transition: all 0.2s; }
        .form-control-custom:focus { border-color: var(--primary); background: #fff; box-shadow: 0 0 0 3px rgba(79,70,229,0.1); }
        
        .preview-area { width: 140px; display: flex; flex-direction: column; align-items: center; justify-content: center; background: #f8fafc; border-radius: 12px; padding: 16px; border: 1px dashed #cbd5e1; }
        .preview-area-banner { width: 300px; height: 120px; border-radius: 16px; overflow: hidden; position: relative; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        
        .controls-bar { display: flex; gap: 8px; position: absolute; top: 20px; right: 20px; }
        .ctrl-btn { width: 32px; height: 32px; border-radius: 8px; border: none; background: #f1f5f9; color: #64748b; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 14px; transition: all 0.2s; }
        .ctrl-btn:hover { background: #e2e8f0; color: #1e293b; }
        .ctrl-btn.danger:hover { background: #fee2e2; color: #ef4444; }
        
        .filter-tabs { display: flex; gap: 8px; margin-bottom: 24px; }
        .filter-tab { padding: 10px 20px; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; border: 1px solid #e2e8f0; background: #fff; color: #64748b; transition: all 0.2s; }
        .filter-tab.active { background: var(--primary); color: #fff; border-color: var(--primary); }
        
        /* Dashboard Styles for Preview */
        .card-shadow { box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); }
        
        .save-toast { position: fixed; bottom: -60px; left: 50%; transform: translateX(-50%); background: #1e293b; color: #fff; padding: 12px 24px; border-radius: 30px; font-weight: 600; font-size: 14px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.3); transition: bottom 0.3s cubic-bezier(0.68, -0.55, 0.26, 1.55); z-index: 9999; }
        .save-toast.show { bottom: 30px; }
        .save-toast.ok { background: #10B981; }
        .save-toast.err { background: #EF4444; }
    </style>
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php'; ?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php'; ?>
<div class="page-content">
    <div style="margin-bottom: 24px;">
        <h2 style="font-size: 24px; font-weight: 800; color: #1e293b; letter-spacing: -0.5px;">Dashboard Designer</h2>
        <p style="color: #64748b; font-size: 14px; margin-top: 4px;">Visually construct the user interface elements for the customer dashboard.</p>
    </div>

    <div class="filter-tabs">
        <button class="filter-tab active" onclick="showTab('services', this)"><i class="fas fa-th-large" style="margin-right:6px"></i> Quick Services</button>
        <button class="filter-tab" onclick="showTab('banners', this)"><i class="fas fa-images" style="margin-right:6px"></i> Promo Banners</button>
    </div>

    <!-- QUICK SERVICES BUILDER -->
    <div id="tab-services" class="builder-card">
        <div class="builder-card-header">
            <h3>Quick Services Grid</h3>
            <button class="btn-primary" style="background:#10B981; border:none; padding:8px 16px; border-radius:8px; font-weight:700; cursor:pointer;" onclick="addService()"><i class="fas fa-plus" style="margin-right:6px"></i>Add Service</button>
        </div>
        <div class="builder-card-body">
            <p style="margin-bottom:24px; color:#64748b; font-size:13px;">Reorder items using the Up/Down arrows. Changes are applied immediately to the preview but must be published to go live.</p>
            <div id="services-container"></div>
            <div style="margin-top: 24px; text-align: right; border-top: 1px solid #e2e8f0; padding-top: 24px;">
                <button class="btn-primary" style="background:var(--primary); border:none; padding:12px 24px; border-radius:10px; font-weight:700; font-size:15px; cursor:pointer; box-shadow:0 4px 12px rgba(79,70,229,0.3);" onclick="saveServices()"><i class="fas fa-save" style="margin-right:6px"></i> Publish Services</button>
            </div>
        </div>
    </div>

    <!-- PROMO BANNERS BUILDER -->
    <div id="tab-banners" class="builder-card" style="display:none">
        <div class="builder-card-header">
            <h3>Promo Banners Carousel</h3>
            <button class="btn-primary" style="background:#10B981; border:none; padding:8px 16px; border-radius:8px; font-weight:700; cursor:pointer;" onclick="addBanner()"><i class="fas fa-plus" style="margin-right:6px"></i>Add Banner</button>
        </div>
        <div class="builder-card-body">
            <p style="margin-bottom:24px; color:#64748b; font-size:13px;">Design promotional banners to highlight new features or discounts.</p>
            <div id="banners-container"></div>
            <div style="margin-top: 24px; text-align: right; border-top: 1px solid #e2e8f0; padding-top: 24px;">
                <button class="btn-primary" style="background:var(--primary); border:none; padding:12px 24px; border-radius:10px; font-weight:700; font-size:15px; cursor:pointer; box-shadow:0 4px 12px rgba(79,70,229,0.3);" onclick="saveBanners()"><i class="fas fa-save" style="margin-right:6px"></i> Publish Banners</button>
            </div>
        </div>
    </div>

</div>
</div>

<div class="save-toast" id="toast"></div>

<script>
const CSRF = '<?=$csrf?>';
let servicesData = <?= $current_services_json ?: '[]' ?>;
let bannersData = <?= $current_banners_json ?: '[]' ?>;

// Configuration Mappings (No-Code Translation)
const COLORS = {
    'blue': { text: 'text-blue-500', bg: 'bg-blue-100', border: 'hover:border-blue-300' },
    'green': { text: 'text-emerald-500', bg: 'bg-emerald-100', border: 'hover:border-emerald-300' },
    'red': { text: 'text-red-500', bg: 'bg-red-100', border: 'hover:border-red-300' },
    'orange': { text: 'text-orange-500', bg: 'bg-orange-100', border: 'hover:border-orange-300' },
    'purple': { text: 'text-purple-500', bg: 'bg-purple-100', border: 'hover:border-purple-300' },
    'pink': { text: 'text-pink-500', bg: 'bg-pink-100', border: 'hover:border-pink-300' },
    'indigo': { text: 'text-indigo-500', bg: 'bg-indigo-100', border: 'hover:border-indigo-300' },
    'yellow': { text: 'text-yellow-500', bg: 'bg-yellow-100', border: 'hover:border-yellow-300' }
};

const ICONS = {
    'fa-mobile-screen-button': 'Mobile / Airtime',
    'fa-wifi': 'Wifi / Data',
    'fa-tv': 'Television / Cable',
    'fa-bolt': 'Lightning / Electricity',
    'fa-wallet': 'Wallet / Funding',
    'fa-building-columns': 'Bank / Transfer',
    'fa-right-left': 'Exchange / Convert',
    'fa-graduation-cap': 'Graduation / Education',
    'fa-gift': 'Gift / Cashback',
    'fa-credit-card': 'Credit Card',
    'fa-chart-pie': 'Chart / Stats',
    'fa-headset': 'Headset / Support',
    'fa-users': 'Users / Referrals'
};

const LINKS = {
    'fund': 'Fund Wallet',
    'airtime': 'Buy Airtime',
    'data': 'Buy Data',
    'electricity': 'Pay Electricity',
    'cabletv': 'Cable TV',
    'airtime-to-cash': 'Airtime to Cash',
    'exam-pins': 'Exam Pins',
    'bank-transfer': 'Bank Transfer',
    'internal-transfer': 'Wallet to Wallet',
    'pricing': 'Pricing List',
    'referrals': 'Referrals',
    'support': 'Support',
    'settings': 'Account Settings'
};

const GRADIENTS = {
    'from-blue-600 to-blue-800': 'Blue Gradient',
    'from-emerald-600 to-emerald-800': 'Green Gradient',
    'from-orange-500 to-orange-700': 'Orange Gradient',
    'from-purple-600 to-purple-800': 'Purple Gradient',
    'from-pink-500 to-rose-600': 'Pink Gradient',
    'from-slate-700 to-slate-900': 'Dark Navy'
};

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

// Reorder Array Helper
function moveItem(arr, old_index, new_index) {
    if (new_index >= arr.length) { var k = new_index - arr.length + 1; while (k--) { arr.push(undefined); } }
    arr.splice(new_index, 0, arr.splice(old_index, 1)[0]);
}

// ---- SERVICES LOGIC ----
function getColorKeyFromTailwind(textColorClass) {
    for (const [key, val] of Object.entries(COLORS)) {
        if (val.text === textColorClass) return key;
    }
    return 'blue'; // fallback
}

function renderServices() {
    const container = document.getElementById('services-container');
    container.innerHTML = '';
    
    if (servicesData.length === 0) {
        container.innerHTML = '<div style="text-align:center; padding:40px; color:#94a3b8;">No services created yet. Click "Add Service" to begin.</div>';
        return;
    }

    servicesData.forEach((svc, index) => {
        const box = document.createElement('div');
        box.className = 'item-box ' + (svc.active ? '' : 'inactive');
        
        let colorKey = getColorKeyFromTailwind(svc.icon_color);
        
        let linkOptions = '';
        let isCustomLink = !Object.keys(LINKS).includes(svc.link);
        for (const [val, name] of Object.entries(LINKS)) {
            linkOptions += `<option value="${val}" ${svc.link === val ? 'selected' : ''}>${name}</option>`;
        }
        linkOptions += `<option value="CUSTOM" ${isCustomLink ? 'selected' : ''}>Custom URL...</option>`;

        let iconOptions = '';
        for (const [val, name] of Object.entries(ICONS)) {
            iconOptions += `<option value="${val}" ${svc.icon === val ? 'selected' : ''}>${name}</option>`;
        }
        // If icon is custom (not in list) add it
        if (!Object.keys(ICONS).includes(svc.icon)) {
            iconOptions += `<option value="${svc.icon}" selected>Custom (${svc.icon})</option>`;
        }

        let colorOptions = '';
        for (const [val, def] of Object.entries(COLORS)) {
            let labelName = val.charAt(0).toUpperCase() + val.slice(1);
            colorOptions += `<option value="${val}" ${colorKey === val ? 'selected' : ''}>${labelName}</option>`;
        }

        let html = `
            <div class="controls-bar">
                <label class="toggle" style="margin-right:16px; margin-top:4px;"><input type="checkbox" onchange="toggleService(${index}, this.checked)" ${svc.active ? 'checked' : ''}><span class="slider"></span></label>
                <button class="ctrl-btn" onclick="moveService(${index}, -1)" ${index === 0 ? 'disabled style="opacity:0.3;"' : ''}><i class="fas fa-chevron-up"></i></button>
                <button class="ctrl-btn" onclick="moveService(${index}, 1)" ${index === servicesData.length - 1 ? 'disabled style="opacity:0.3;"' : ''}><i class="fas fa-chevron-down"></i></button>
                <button class="ctrl-btn danger" onclick="removeService(${index})"><i class="fas fa-trash-alt"></i></button>
            </div>
            
            <div class="preview-area">
                <div class="flex flex-col items-center justify-center p-4 bg-white rounded-2xl border border-slate-100 card-shadow transition-all group" style="width:100%; aspect-ratio:1;">
                    <div class="w-12 h-12 rounded-full ${svc.icon_bg} ${svc.icon_color} flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                        <i class="fa-solid ${svc.icon} text-xl"></i>
                    </div>
                    <span class="text-xs font-semibold text-slate-700 text-center">${svc.label || 'Label'}</span>
                </div>
            </div>
            
            <div class="form-grid-custom">
                <div class="form-group-custom">
                    <label>Label</label>
                    <input type="text" class="form-control-custom" value="${svc.label}" onchange="updateService(${index}, 'label', this.value)" placeholder="e.g. Buy Airtime">
                </div>
                
                <div class="form-group-custom">
                    <label>Target Page Link</label>
                    <select class="form-control-custom" onchange="handleLinkChange(${index}, this.value)">
                        ${linkOptions}
                    </select>
                    ${isCustomLink ? `<input type="text" class="form-control-custom" style="margin-top:8px;" value="${svc.link}" onchange="updateService(${index}, 'link', this.value)" placeholder="Enter custom URL">` : ''}
                </div>
                
                <div class="form-group-custom">
                    <label>Visual Icon</label>
                    <select class="form-control-custom" onchange="updateService(${index}, 'icon', this.value)">
                        ${iconOptions}
                    </select>
                </div>
                
                <div class="form-group-custom">
                    <label>Color Theme</label>
                    <select class="form-control-custom" onchange="handleColorChange(${index}, this.value)">
                        ${colorOptions}
                    </select>
                </div>
            </div>
        `;
        box.innerHTML = html;
        container.appendChild(box);
    });
}

function handleColorChange(index, colorKey) {
    const t = COLORS[colorKey];
    servicesData[index]['icon_color'] = t.text;
    servicesData[index]['icon_bg'] = t.bg;
    servicesData[index]['hover_border'] = t.border;
    renderServices();
}

function handleLinkChange(index, val) {
    if (val === 'CUSTOM') {
        servicesData[index]['link'] = '#'; // clear it out
    } else {
        servicesData[index]['link'] = val;
    }
    renderServices();
}

function updateService(index, field, value) { servicesData[index][field] = value; renderServices(); }
function toggleService(index, checked) { servicesData[index].active = checked; renderServices(); }
function moveService(index, dir) { moveItem(servicesData, index, index + dir); renderServices(); }
function removeService(index) { if(confirm('Delete this service entirely?')) { servicesData.splice(index, 1); renderServices(); } }
function addService() {
    servicesData.push({link: 'fund', label: 'New Service', icon_color: 'text-blue-500', icon_bg: 'bg-blue-100', hover_border: 'hover:border-blue-300', icon: 'fa-star', active: true});
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
    
    if (bannersData.length === 0) {
        container.innerHTML = '<div style="text-align:center; padding:40px; color:#94a3b8;">No banners created yet. Click "Add Banner" to begin.</div>';
        return;
    }

    bannersData.forEach((bn, index) => {
        const box = document.createElement('div');
        box.className = 'item-box ' + (bn.active ? '' : 'inactive');
        
        let gradOptions = '';
        for (const [val, name] of Object.entries(GRADIENTS)) {
            gradOptions += `<option value="${val}" ${bn.bg_gradient === val ? 'selected' : ''}>${name}</option>`;
        }

        let iconOptions = '';
        for (const [val, name] of Object.entries(ICONS)) {
            iconOptions += `<option value="${val}" ${bn.icon === val ? 'selected' : ''}>${name}</option>`;
        }
        if (!Object.keys(ICONS).includes(bn.icon)) {
            iconOptions += `<option value="${bn.icon}" selected>Custom (${bn.icon})</option>`;
        }

        let html = `
            <div class="controls-bar">
                <label class="toggle" style="margin-right:16px; margin-top:4px;"><input type="checkbox" onchange="toggleBanner(${index}, this.checked)" ${bn.active ? 'checked' : ''}><span class="slider"></span></label>
                <button class="ctrl-btn" onclick="moveBanner(${index}, -1)" ${index === 0 ? 'disabled style="opacity:0.3;"' : ''}><i class="fas fa-chevron-up"></i></button>
                <button class="ctrl-btn" onclick="moveBanner(${index}, 1)" ${index === bannersData.length - 1 ? 'disabled style="opacity:0.3;"' : ''}><i class="fas fa-chevron-down"></i></button>
                <button class="ctrl-btn danger" onclick="removeBanner(${index})"><i class="fas fa-trash-alt"></i></button>
            </div>
            
            <div style="display:flex; flex-direction:column; gap:20px; width:100%;">
                
                <div class="preview-area-banner">
                    <div class="absolute inset-0 bg-gradient-to-r ${bn.bg_gradient} opacity-90"></div>
                    <div class="absolute right-0 bottom-0 opacity-10 transform translate-x-4 translate-y-4">
                        <i class="fa-solid ${bn.icon || 'fa-gift'} text-9xl text-white"></i>
                    </div>
                    <div class="relative z-10 p-5 sm:p-6 flex flex-col justify-center h-full">
                        <span class="inline-block px-2 py-1 bg-white/20 text-white text-[10px] font-bold rounded mb-2 w-max uppercase tracking-wider backdrop-blur-sm">
                            ${bn.tag || 'TAG'}
                        </span>
                        <h3 class="text-white text-xl font-bold mb-1 drop-shadow-md leading-tight">${bn.title || 'Title'}</h3>
                        <p class="text-white/90 text-sm drop-shadow-sm font-medium line-clamp-2" style="max-width:200px;">${bn.subtitle || 'Subtitle description'}</p>
                    </div>
                </div>
                
                <div class="form-grid-custom">
                    <div class="form-group-custom">
                        <label>Headline Title</label>
                        <input type="text" class="form-control-custom" value="${bn.title}" onchange="updateBanner(${index}, 'title', this.value)" placeholder="e.g. 50% Off Data">
                    </div>
                    
                    <div class="form-group-custom">
                        <label>Description Subtitle</label>
                        <input type="text" class="form-control-custom" value="${bn.subtitle}" onchange="updateBanner(${index}, 'subtitle', this.value)" placeholder="Short description text">
                    </div>
                    
                    <div class="form-group-custom">
                        <label>Small Tag Badge</label>
                        <input type="text" class="form-control-custom" value="${bn.tag}" onchange="updateBanner(${index}, 'tag', this.value)" placeholder="e.g. PROMO">
                    </div>
                    
                    <div class="form-group-custom">
                        <label>Color Gradient Theme</label>
                        <select class="form-control-custom" onchange="updateBanner(${index}, 'bg_gradient', this.value)">
                            ${gradOptions}
                        </select>
                    </div>
                    
                    <div class="form-group-custom">
                        <label>Background Visual Icon</label>
                        <select class="form-control-custom" onchange="updateBanner(${index}, 'icon', this.value)">
                            ${iconOptions}
                        </select>
                    </div>
                </div>
            </div>
        `;
        box.innerHTML = html;
        container.appendChild(box);
    });
}

function updateBanner(index, field, value) { bannersData[index][field] = value; renderBanners(); }
function toggleBanner(index, checked) { bannersData[index].active = checked; renderBanners(); }
function moveBanner(index, dir) { moveItem(bannersData, index, index + dir); renderBanners(); }
function removeBanner(index) { if(confirm('Delete this banner entirely?')) { bannersData.splice(index, 1); renderBanners(); } }
function addBanner() {
    bannersData.push({title: 'New Banner', subtitle: 'Describe the offer here', tag: 'NEW', bg_gradient: 'from-blue-600 to-blue-800', icon: 'fa-gift', active: true});
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
