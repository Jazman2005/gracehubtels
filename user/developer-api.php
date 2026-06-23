<?php
require_once __DIR__.'/core/auth.php';
auth_user();
$uid = $_SESSION['user_id'];
$user = get_user($uid);
$name = explode(' ', $user['full_name'])[0];

if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
    $action = $_POST['action']??'';
    if($action==='generate_keys'){
        $token = 'msj_live_' . bin2hex(random_bytes(24));
        db()->prepare("UPDATE users SET api_token=? WHERE id=?")->execute([$token, $uid]);
        echo json_encode(['success'=>true,'message'=>'New API Key Generated!']);
        exit;
    }
    if($action==='save_webhook'){
        $url = filter_var($_POST['webhook_url']??'', FILTER_SANITIZE_URL);
        if($url && !filter_var($url, FILTER_VALIDATE_URL)){
            echo json_encode(['success'=>false,'message'=>'Invalid Webhook URL format']);
            exit;
        }
        db()->prepare("UPDATE users SET api_webhook_url=? WHERE id=?")->execute([$url, $uid]);
        echo json_encode(['success'=>true,'message'=>'Webhook URL updated successfully']);
        exit;
    }
}

$page_title = 'Developer API'; 
include __DIR__ . '/includes/header.php'; 
include __DIR__ . '/includes/sidebar.php'; 
?>

<div class="mb-8">
    <h2 class="text-2xl font-bold text-slate-800">Developer API</h2>
    <p class="text-slate-500 mt-1">Integrate MsjPay into your own applications seamlessly.</p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- API Keys Section -->
    <div class="lg:col-span-2 space-y-6">
        <div class="bg-white rounded-2xl border border-slate-200 p-6 card-shadow">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-full bg-brand-navy/10 text-brand-navy flex items-center justify-center">
                    <i class="fa-solid fa-key"></i>
                </div>
                <h3 class="text-lg font-bold text-slate-800">Live API Key</h3>
            </div>
            
            <p class="text-sm text-slate-500 mb-6">Use this key to authenticate your API requests. Keep it secret and do not expose it in client-side code.</p>
            
            <?php if($user['api_token']): ?>
            <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 flex items-center justify-between gap-4 mb-6 relative overflow-hidden">
                <code class="font-mono text-sm text-slate-800 truncate" id="apiKey" style="filter: blur(4px); transition: filter 0.3s;"><?=htmlspecialchars($user['api_token'])?></code>
                <div class="flex gap-2 shrink-0">
                    <button onclick="document.getElementById('apiKey').style.filter='none'" class="text-slate-500 hover:text-brand-navy transition-colors p-2" title="Reveal">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                    <button onclick="copyToClipboard('apiKey')" class="text-brand-orange hover:text-brand-orangeHover transition-colors p-2" title="Copy">
                        <i class="fa-solid fa-copy"></i>
                    </button>
                </div>
            </div>
            <button onclick="generateKey()" class="bg-red-50 hover:bg-red-100 text-red-600 px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                <i class="fa-solid fa-rotate mr-1"></i> Roll API Key
            </button>
            <?php else: ?>
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-6 text-center">
                <p class="text-amber-700 text-sm mb-4">You have not generated an API key yet.</p>
                <button onclick="generateKey()" class="bg-brand-navy hover:bg-brand-navyDark text-white px-5 py-2.5 rounded-xl font-medium transition-colors text-sm shadow-md">
                    Generate Live Key
                </button>
            </div>
            <?php endif; ?>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 p-6 card-shadow">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-full bg-emerald-500/10 text-emerald-500 flex items-center justify-center">
                    <i class="fa-solid fa-satellite-dish"></i>
                </div>
                <h3 class="text-lg font-bold text-slate-800">Webhook Configuration</h3>
            </div>
            <p class="text-sm text-slate-500 mb-6">Receive real-time HTTP POST notifications when transactions complete.</p>
            
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Webhook URL</label>
                    <input type="url" id="webhook_url" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-slate-800 focus:outline-none focus:border-brand-navy transition-colors placeholder:text-slate-400" placeholder="https://yourwebsite.com/webhook" value="<?=htmlspecialchars($user['api_webhook_url']??'')?>">
                </div>
                <button onclick="saveWebhook()" class="bg-brand-orange hover:bg-brand-orangeHover text-white px-6 py-2.5 rounded-xl font-medium transition-colors text-sm shadow-md">
                    Save URL
                </button>
            </div>
        </div>
    </div>

    <!-- Quick Documentation -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 card-shadow h-fit">
        <h3 class="text-lg font-bold text-slate-800 mb-4">Quick Start</h3>
        <div class="prose prose-sm text-slate-600">
            <p><strong>Base URL:</strong><br><code class="bg-slate-100 text-brand-navy px-1.5 py-0.5 rounded text-xs">https://msjpay.com/api/v1</code></p>
            
            <p class="mt-4"><strong>Authentication:</strong><br>Include your API key in the Authorization header of all requests:</p>
            <div class="bg-slate-800 text-green-400 p-3 rounded-lg text-xs font-mono overflow-x-auto mt-2">
                Authorization: Bearer msj_live_...
            </div>
            
            <div class="mt-6">
                <strong class="text-slate-800">Available Endpoints:</strong>
                <ul class="list-disc pl-4 mt-2 space-y-1">
                    <li><code>GET /balance.php</code></li>
                    <li><code>GET /profile.php</code></li>
                    <li><code>POST /data/purchase.php</code> (Coming Soon)</li>
                    <li><code>POST /airtime/purchase.php</code> (Coming Soon)</li>
                </ul>
            </div>
            
            <div class="mt-8 pt-6 border-t border-slate-200">
                <a href="#" class="text-brand-orange hover:text-brand-orangeHover font-medium flex items-center gap-1">
                    Read Full API Docs <i class="fa-solid fa-arrow-right text-xs"></i>
                </a>
            </div>
        </div>
    </div>

</div>

<script>
async function generateKey(){
  if(!confirm("Generate a new API key? This will immediately invalidate your old key!")) return;
  const fd=new FormData();fd.append('action','generate_keys');
  const r=await fetch('',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
  if(r.success) location.reload();
  else alert(r.message);
}

async function saveWebhook(){
  const w=document.getElementById('webhook_url').value;
  const fd=new FormData();fd.append('action','save_webhook'); fd.append('webhook_url',w);
  const r=await fetch('',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json());
  if(r.success) { alert(r.message); location.reload(); }
  else alert(r.message);
}

function copyToClipboard(id) {
  const el = document.getElementById(id);
  navigator.clipboard.writeText(el.innerText).then(() => alert("Copied to clipboard!"));
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
