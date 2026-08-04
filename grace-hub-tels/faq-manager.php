<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_admin('superadmin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    if (!verify_admin_csrf($_POST['csrf'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_faqs') {
        $faqs = json_decode($_POST['faqs_json'] ?? '[]', true) ?: [];
        $clean = [];
        foreach ($faqs as $f) {
            $q = trim($f['question'] ?? '');
            $a = trim($f['answer'] ?? '');
            if ($q && $a) $clean[] = ['question' => $q, 'answer' => $a];
        }
        setting_set('faqs_data', json_encode($clean));
        echo json_encode(['success' => true, 'message' => 'FAQs saved']);
        exit;
    }
}

$faqs_json = setting('faqs_data', '[]');
$faqs = json_decode($faqs_json, true) ?: [];
$csrf = admin_csrf();
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>FAQ Manager — Gracehubtels Admin</title><link rel="icon" type="image/png" href="/assets/images/gracehubtels-favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header" style="margin-bottom:20px">
  <div><h2>FAQ Manager</h2><p>Manage questions and answers shown in the app support page</p></div>
  <button class="btn-primary" onclick="addFaq()">+ Add Question</button>
</div>

<div class="data-panel">
  <div id="faqList"></div>
  <div id="faqEmpty" class="empty-state" style="display:<?=empty($faqs)?'block':'none'?>">No FAQs yet. Click "Add Question" to create one.</div>
</div>

<div style="margin-top:20px;display:flex;justify-content:flex-end">
  <button class="btn-primary" onclick="saveFaqs()" style="min-width:160px;height:44px;justify-content:center">Save All FAQs</button>
</div>

</div></div>
<div class="save-toast" id="toast"></div>

<script>
const CSRF = '<?=$csrf?>';
let faqs = <?=json_encode($faqs)?>;

function render() {
    const list = document.getElementById('faqList');
    const empty = document.getElementById('faqEmpty');
    if (faqs.length === 0) {
        list.innerHTML = '';
        empty.style.display = 'block';
        return;
    }
    empty.style.display = 'none';
    list.innerHTML = faqs.map((f, i) => `
        <div class="faq-item" data-index="${i}" style="border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:12px;background:#fff">
            <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:10px">
                <span style="background:var(--primary);color:#fff;width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0">${i + 1}</span>
                <div style="flex:1">
                    <div class="form-group" style="margin-bottom:8px">
                        <label style="font-size:12px;font-weight:600">Question</label>
                        <input type="text" class="form-control" value="${esc(f.question)}" oninput="faqs[${i}].question=this.value" placeholder="e.g. How do I fund my wallet?">
                    </div>
                    <div class="form-group" style="margin-bottom:0">
                        <label style="font-size:12px;font-weight:600">Answer</label>
                        <textarea class="form-control" rows="3" oninput="faqs[${i}].answer=this.value" placeholder="Write the answer here...">${esc(f.answer)}</textarea>
                    </div>
                </div>
                <button class="btn-ghost" style="color:var(--danger);flex-shrink:0;margin-top:4px" onclick="deleteFaq(${i})">
                    <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" fill="none"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                </button>
            </div>
            <div style="display:flex;gap:8px;padding-left:40px">
                <button class="btn-ghost btn-sm" style="font-size:11px;gap:4px" onclick="moveFaq(${i}, -1)" ${i === 0 ? 'disabled' : ''}>
                    <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none"><polyline points="18 15 12 9 6 15"/></svg>
                    Move Up
                </button>
                <button class="btn-ghost btn-sm" style="font-size:11px;gap:4px" onclick="moveFaq(${i}, 1)" ${i === faqs.length - 1 ? 'disabled' : ''}>
                    <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none"><polyline points="6 9 12 15 18 9"/></svg>
                    Move Down
                </button>
            </div>
        </div>
    `).join('');
}

function esc(s) {
    if (!s) return '';
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function addFaq() {
    faqs.push({ question: '', answer: '' });
    render();
    setTimeout(() => {
        const items = document.querySelectorAll('.faq-item');
        const last = items[items.length - 1];
        if (last) last.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, 50);
}

function deleteFaq(i) {
    if (!confirm('Delete this FAQ question?')) return;
    faqs.splice(i, 1);
    render();
}

function moveFaq(i, dir) {
    const j = i + dir;
    if (j < 0 || j >= faqs.length) return;
    [faqs[i], faqs[j]] = [faqs[j], faqs[i]];
    render();
}

async function saveFaqs() {
    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('action', 'save_faqs');
    fd.append('faqs_json', JSON.stringify(faqs));
    const r = await fetch(location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd }).then(r => r.json());
    showToast(r.message, r.success);
}

function showToast(msg, ok) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'save-toast show ' + (ok ? 'ok' : 'err');
    setTimeout(() => t.classList.remove('show'), 2500);
}

render();
</script>
</body></html>
