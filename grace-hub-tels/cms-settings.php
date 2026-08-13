<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_admin('superadmin'); // CMS requires superadmin
$csrf = admin_csrf();

if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    if(!verify_admin_csrf($_POST['csrf']??'')){ echo json_encode(['success'=>false,'message'=>'Invalid token']); exit; }
    $action = $_POST['action']??'';
    if($action==='add_banner'){
        // FILTER_SANITIZE_URL alone doesn't block dangerous schemes like
        // javascript:/data: — this link renders as a raw <a href> on the public
        // homepage, so require it to actually be a well-formed http(s) URL.
        $link = trim($_POST['link']??'');
        if ($link !== '' && (!filter_var($link, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $link))) {
            echo json_encode(['success'=>false,'message'=>'Banner link must be a valid http:// or https:// URL']);
            exit;
        }
        $url = '';
        
        if(!empty($_FILES['image_file']['tmp_name'])){
            $target_dir = $_SERVER['DOCUMENT_ROOT'].'/assets/uploads/banners/';
            if(!is_dir($target_dir)) mkdir($target_dir, 0755, true);
            $ext = strtolower(pathinfo($_FILES['image_file']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','webp'];
            if(!in_array($ext, $allowed)) die(json_encode(['success'=>false,'message'=>'Allowed: JPG, PNG, WebP']));
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES['image_file']['tmp_name']);
            finfo_close($finfo);
            $allowed_mime = ['image/jpeg','image/png','image/webp'];
            if(!in_array($mime, $allowed_mime)) die(json_encode(['success'=>false,'message'=>'Invalid file type']));
            $name = 'banner_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
            move_uploaded_file($_FILES['image_file']['tmp_name'], $target_dir.$name);
            $url = '/assets/uploads/banners/'.$name;
        } else {
            $url = filter_var($_POST['image_url']??'', FILTER_SANITIZE_URL);
            if(!$url || !filter_var($url, FILTER_VALIDATE_URL)){
                echo json_encode(['success'=>false,'message'=>'Select an image file or paste a valid image URL']);
                exit;
            }
        }
        
        db()->prepare("INSERT INTO banners (image_url, link, sort_order, is_active) VALUES (?, ?, ?, 1)")->execute([$url, $link ?: null, (int)($_POST['sort_order'] ?? 0)]);
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

try {
    $banners = db()->query("SELECT * FROM banners ORDER BY sort_order ASC, id ASC")->fetchAll();
} catch (Exception $e) {
    $banners = [];
    error_log("Banners table missing: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>CMS & Banners — <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title><link rel="icon" type="image/png" href="/assets/images/favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header" style="margin-bottom:20px">
  <div><h2>CMS & Banners</h2><p>Manage dynamic content and homepage sliders</p></div>
  <button class="btn-primary" onclick="document.getElementById('newBannerModal').style.display='flex'">+ Add Banner</button>
</div>

<div class="data-panel">
  <h3 style="border-bottom:1px solid var(--border);padding-bottom:12px;margin-bottom:16px">Homepage Banners</h3>
  
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px">
      <?php foreach($banners as $b): ?>
      <div style="border:1px solid var(--border);border-radius:12px;overflow:hidden;background:#fff">
          <div style="height:140px;background:#F3F4F6;position:relative">
              <img src="<?=htmlspecialchars($b['image_url'])?>" style="width:100%;height:100%;object-fit:cover" onerror="this.src='/assets/images/placeholder.png'">
              <?php if(!$b['is_active']): ?>
              <div style="position:absolute;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700">INACTIVE</div>
              <?php endif; ?>
          </div>
          <div style="padding:12px">
              <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px">
                  <span style="font-size:11px;background:#F3F4F6;padding:2px 8px;border-radius:4px;color:var(--text-muted)">#<?=(int)$b['sort_order']?></span>
                  <div style="font-size:12px;color:var(--text-muted);flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                  Link: <a href="<?=htmlspecialchars($b['link'])?>" target="_blank"><?=htmlspecialchars($b['link']??'None')?></a>
          </div>
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
    <div class="modal-header"><h3>Add New Banner</h3><button class="modal-close" onclick="closeModal()">✕</button></div>
    <div class="modal-body">
      <div class="form-group" style="margin-bottom:12px">
        <label>Upload Image <span style="color:var(--text-muted);font-weight:400;font-size:11px">(Recommended: 1200 x 400 px, max 2 MB)</span></label>
        <input type="file" class="form-control" id="image_file" accept="image/png,image/jpeg,image/webp" onchange="previewBanner(this)" style="padding:8px;height:auto">
        <div id="bannerPreview" style="display:none;margin-top:8px;border-radius:8px;overflow:hidden;max-height:120px;background:#f3f4f6"><img id="previewImg" style="width:100%;height:auto;object-fit:cover"></div>
      </div>
      <div style="text-align:center;color:var(--text-muted);font-size:13px;margin-bottom:12px">— OR —</div>
      <div class="form-group" style="margin-bottom:12px">
        <label>Paste Image URL</label>
        <input type="url" class="form-control" id="image_url" placeholder="https://example.com/banner.png">
      </div>
      <div class="form-group" style="margin-bottom:16px">
        <label>Redirect Link (Optional)</label>
        <input type="url" class="form-control" id="link" placeholder="https://example.com/offer">
      </div>
      <div class="form-group" style="margin-bottom:16px">
        <label>Sort Order</label>
        <input type="number" class="form-control" id="sort_order" value="0" style="max-width:100px">
      </div>
      <button class="btn-primary" style="width:100%;height:44px;justify-content:center" onclick="addBanner()">Add Banner</button>
    </div>
  </div>
</div>

<script>
const CSRF_TOKEN = <?=json_encode($csrf)?>;
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
function closeModal(){document.getElementById('newBannerModal').style.display='none';}
function previewBanner(input){const p=document.getElementById('bannerPreview'),i=document.getElementById('previewImg');if(input.files&&input.files[0]){const r=new FileReader();r.onload=e=>{i.src=e.target.result;p.style.display='block'};r.readAsDataURL(input.files[0])}}
async function addBanner(){
  const imgFile=document.getElementById('image_file').files[0], imgUrl=document.getElementById('image_url').value, l=document.getElementById('link').value, so=document.getElementById('sort_order').value;
  if(!imgFile&&!imgUrl)return alert('Select an image or paste a URL');
  const fd=new FormData();fd.append('action','add_banner');fd.append('link',l);fd.append('sort_order',so);fd.append('csrf',CSRF_TOKEN);
  if(imgFile)fd.append('image_file',imgFile);else fd.append('image_url',imgUrl);
  const r=await fetch('',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
  if(r.success)location.reload(); else alert(r.message);
}
async function deleteBanner(id){
    if(!confirm('Delete this banner?'))return;
    const fd=new FormData();fd.append('action','delete_banner');fd.append('id',id);fd.append('csrf',CSRF_TOKEN);
    const r=await fetch('',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    if(r.success)location.reload();
}
async function toggleBanner(id){
    const fd=new FormData();fd.append('action','toggle_banner');fd.append('id',id);fd.append('csrf',CSRF_TOKEN);
    const r=await fetch('',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
    if(r.success)location.reload();
}
</script>
</body></html>
