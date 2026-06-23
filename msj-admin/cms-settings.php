<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_admin('superadmin'); // CMS requires superadmin

if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    $action = $_POST['action']??'';
    if($action==='add_banner'){
        $url = filter_var($_POST['image_url']??'', FILTER_SANITIZE_URL);
        $link = filter_var($_POST['link']??'', FILTER_SANITIZE_URL);
        
        if(!$url || !filter_var($url, FILTER_VALIDATE_URL)){
            echo json_encode(['success'=>false,'message'=>'Valid Image URL is required']);
            exit;
        }
        
        db()->prepare("INSERT INTO banners (image_url, link, is_active) VALUES (?, ?, 1)")->execute([$url, $link ?: null]);
        echo json_encode(['success'=>true,'message'=>'Banner added successfully']);
        exit;
    }
    if($action==='delete_banner'){
        db()->prepare("DELETE FROM banners WHERE id=?")->execute([(int)$_POST['id']]);
        echo json_encode(['success'=>true]);
        exit;
    }
    if($action==='toggle_banner'){
        db()->prepare("UPDATE banners SET is_active = NOT is_active WHERE id=?")->execute([(int)$_POST['id']]);
        echo json_encode(['success'=>true]);
        exit;
    }
}

$banners = db()->query("SELECT * FROM banners ORDER BY id DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>CMS & Banners – MsjPay Admin</title><link rel="icon" type="image/png" href="/msjpay/assets/images/msjpay-favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="/msjpay/msj-admin/css/admin.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="page-content">

<div class="page-head" style="margin-bottom:20px">
  <div><h2>CMS & Banners</h2><p>Manage dynamic content and homepage sliders</p></div>
  <button class="btn-primary" onclick="document.getElementById('newBannerModal').style.display='flex'">+ Add Banner</button>
</div>

<div class="card">
  <h3 style="border-bottom:1px solid var(--border);padding-bottom:12px;margin-bottom:16px">Homepage Banners</h3>
  
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px">
      <?php foreach($banners as $b): ?>
      <div style="border:1px solid var(--border);border-radius:12px;overflow:hidden;background:#fff">
          <div style="height:140px;background:#F3F4F6;position:relative">
              <img src="<?=htmlspecialchars($b['image_url'])?>" style="width:100%;height:100%;object-fit:cover" onerror="this.src='/msjpay/assets/images/placeholder.png'">
              <?php if(!$b['is_active']): ?>
              <div style="position:absolute;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700">INACTIVE</div>
              <?php endif; ?>
          </div>
          <div style="padding:12px">
              <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                  Link: <a href="<?=htmlspecialchars($b['link'])?>" target="_blank"><?=htmlspecialchars($b['link']??'None')?></a>
              </div>
              <div style="display:flex;gap:8px">
                  <button class="btn-ghost btn-sm" style="flex:1;justify-content:center;background:var(--bg)" onclick="toggleBanner(<?=$b['id']?>)">
                      <?=$b['is_active']?'Disable':'Enable'?>
                  </button>
                  <button class="btn-ghost btn-sm" style="color:var(--danger);background:rgba(239,68,68,0.1)" onclick="deleteBanner(<?=$b['id']?>)">
                      <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                  </button>
              </div>
          </div>
      </div>
      <?php endforeach; if(empty($banners)): ?>
      <div style="grid-column:1/-1" class="empty-state">No banners active on the homepage.</div>
      <?php endif; ?>
  </div>
</div>

</div></div>

<!-- Add Banner Modal -->
<div class="modal-overlay" id="newBannerModal" style="display:none">
  <div class="modal" style="max-width:500px">
    <div class="modal-header"><h3>Add New Banner</h3><button class="modal-close" onclick="document.getElementById('newBannerModal').style.display='none'">✕</button></div>
    <div class="modal-body">
      <div class="form-group" style="margin-bottom:12px">
        <label>Image URL (Must be a direct link to a JPG/PNG)</label>
        <input type="url" class="form-control" id="image_url" placeholder="https://example.com/banner.png">
      </div>
      <div class="form-group" style="margin-bottom:16px">
        <label>Redirect Link (Optional)</label>
        <input type="url" class="form-control" id="link" placeholder="Where should clicking it take them?">
      </div>
      <button class="btn-primary" style="width:100%;height:44px;justify-content:center" onclick="addBanner()">Upload Banner</button>
    </div>
  </div>
</div>

<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
async function addBanner(){
  const i=document.getElementById('image_url').value, l=document.getElementById('link').value;
  if(!i)return alert('Image URL is required');
  const fd=new FormData();fd.append('action','add_banner');fd.append('image_url',i);fd.append('link',l);
  const r=await fetch('',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
  if(r.success)location.reload(); else alert(r.message);
}
async function deleteBanner(id){
    if(!confirm('Delete this banner?'))return;
    const fd=new FormData();fd.append('action','delete_banner');fd.append('id',id);
    const r=await fetch('',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    if(r.success)location.reload();
}
async function toggleBanner(id){
    const fd=new FormData();fd.append('action','toggle_banner');fd.append('id',id);
    const r=await fetch('',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    if(r.success)location.reload();
}
</script>
</body></html>
