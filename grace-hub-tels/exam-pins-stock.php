<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
// Mutations below were already gated on manage_pricing, but the page-load
// rendering of plaintext exam PINs (real resale value) was not gated at all.
require_permission('manage_pricing');

try {
    db()->exec("CREATE TABLE IF NOT EXISTS exam_pins_stock (
        id INT AUTO_INCREMENT PRIMARY KEY,
        exam_type VARCHAR(50) NOT NULL,
        pin VARCHAR(100) NOT NULL,
        serial VARCHAR(100) NOT NULL,
        is_used TINYINT(1) DEFAULT 0,
        used_by INT DEFAULT NULL,
        transaction_ref VARCHAR(100) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        used_at DATETIME DEFAULT NULL
    )");
} catch(\Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_admin_csrf($_POST['csrf'] ?? '')) {
        json_response(['success' => false, 'message' => 'Invalid CSRF token'], 403);
    }
    // Exam PINs have real resale value and are shown in plaintext on this page —
    // gate mutations behind the same permission as other pricing/stock pages.
    if (!has_permission('manage_pricing')) {
        json_response(['success' => false, 'message' => 'Missing permission: manage_pricing'], 403);
    }

    $action = $_POST['action'];

    if ($action === 'delete_stock') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare("DELETE FROM exam_pins_stock WHERE id=?")->execute([$id]);
        json_response(['success' => true]);
    }
    
    if ($action === 'clear_used') {
        db()->prepare("DELETE FROM exam_pins_stock WHERE is_used=1")->execute();
        json_response(['success' => true]);
    }

    if ($action === 'upload_stock') {
        $exam_type = strtoupper(trim($_POST['exam_type'] ?? ''));
        if (!in_array($exam_type, ['WAEC', 'NECO', 'NABTEB'])) {
            json_response(['success' => false, 'message' => 'Invalid exam type']);
        }

        $pins_to_insert = [];

        // Manual Textarea parsing (Regex to find PIN and Serial)
        // If it's comma separated, or space separated, etc.
        $raw_text = trim($_POST['raw_text'] ?? '');
        if (!empty($raw_text)) {
            $lines = explode("\n", $raw_text);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                // Try comma separated first (PIN, Serial)
                if (strpos($line, ',') !== false) {
                    $parts = array_map('trim', explode(',', $line));
                    $pin = $parts[0] ?? '';
                    $serial = $parts[1] ?? 'NULL';
                    if (!empty($pin)) $pins_to_insert[] = ['pin' => $pin, 'serial' => $serial];
                } 
                // Regex fallback for text copied from PDF (NECO usually doesn't have a serial, just a PIN)
                else {
                    // Look for long numeric strings (10-15 digits typically for pins)
                    if (preg_match_all('/\b(\d{10,15})\b/', $line, $matches)) {
                        foreach ($matches[1] as $m) {
                            $pins_to_insert[] = ['pin' => $m, 'serial' => 'NULL'];
                        }
                    } else {
                        // Just use the line as PIN
                        $pins_to_insert[] = ['pin' => $line, 'serial' => 'NULL'];
                    }
                }
            }
        }

        // CSV/File Upload Parsing
        if (isset($_FILES['file_upload']) && $_FILES['file_upload']['error'] === UPLOAD_ERR_OK) {
            $tmpName = $_FILES['file_upload']['tmp_name'];
            $ext = strtolower(pathinfo($_FILES['file_upload']['name'], PATHINFO_EXTENSION));
            
            if (in_array($ext, ['csv', 'txt'])) {
                $file = fopen($tmpName, 'r');
                if ($file) {
                    while (($row = fgetcsv($file)) !== false) {
                        // If it's a single column, it might be space or semicolon separated
                        if (count($row) === 1) {
                            $row = preg_split('/[\s,;]+/', trim($row[0]));
                        }

                        // Clean Excel exported format `="12345"`
                        $cleaned_row = array_map(function($v) {
                            $v = trim($v);
                            if (preg_match('/^="([^"]*)"$/', $v, $matches)) return $matches[1];
                            return $v;
                        }, $row);

                        $pin = '';
                        $serial = 'NULL';

                        // Smart find: look for a 10-15 digit string to be the PIN
                        foreach ($cleaned_row as $idx => $val) {
                            if (preg_match('/^\d{9,16}$/', $val)) {
                                $pin = $val;
                                // Try to find a serial in the other columns
                                $other_cols = $cleaned_row;
                                unset($other_cols[$idx]);
                                // Filter out short index columns (e.g. 1, 2, 3)
                                $valid_serials = array_filter($other_cols, function($c) { return strlen($c) > 3; });
                                if (!empty($valid_serials)) {
                                    $serial = array_values($valid_serials)[0];
                                }
                                break;
                            }
                        }

                        // Fallback
                        if (empty($pin)) {
                            $pin = $cleaned_row[0] ?? '';
                            $serial = $cleaned_row[1] ?? 'NULL';
                        }

                        if (!empty($pin) && preg_match('/\d/', $pin)) {
                            $pins_to_insert[] = ['pin' => $pin, 'serial' => $serial];
                        }
                    }
                    fclose($file);
                }
            }
        }

        // Deduplicate pins to be inserted against themselves
        $unique_pins = [];
        $final_inserts = [];
        foreach ($pins_to_insert as $p) {
            if (!in_array($p['pin'], $unique_pins)) {
                $unique_pins[] = $p['pin'];
                $final_inserts[] = $p;
            }
        }

        if (empty($final_inserts)) {
            json_response(['success' => false, 'message' => 'No valid PINs found to upload']);
        }

        // Insert into DB
        $inserted = 0;
        $stmt = db()->prepare("INSERT INTO exam_pins_stock (exam_type, pin, serial) SELECT ?, ?, ? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM exam_pins_stock WHERE pin = ?)");
        
        foreach ($final_inserts as $p) {
            $stmt->execute([$exam_type, $p['pin'], $p['serial'], $p['pin']]);
            if ($stmt->rowCount() > 0) $inserted++;
        }

        json_response(['success' => true, 'message' => "Successfully uploaded $inserted unique PINs for $exam_type!"]);
    }
}

$csrf = admin_csrf();

// Fetch metrics
$metrics = db()->query("
    SELECT exam_type, 
           SUM(CASE WHEN is_used=0 THEN 1 ELSE 0 END) as available,
           SUM(CASE WHEN is_used=1 THEN 1 ELSE 0 END) as used
    FROM exam_pins_stock 
    GROUP BY exam_type
")->fetchAll(PDO::FETCH_ASSOC);

$stock_map = ['WAEC' => ['available' => 0, 'used' => 0], 'NECO' => ['available' => 0, 'used' => 0], 'NABTEB' => ['available' => 0, 'used' => 0]];
foreach ($metrics as $m) {
    if (isset($stock_map[$m['exam_type']])) {
        $stock_map[$m['exam_type']]['available'] = $m['available'];
        $stock_map[$m['exam_type']]['used'] = $m['used'];
    }
}

// Fetch all pins for table
$page = (int)($_GET['page'] ?? 1);
$limit = 100;
$offset = ($page - 1) * $limit;
$filter = $_GET['filter'] ?? 'all';

$where = "1=1";
$params = [];
if ($filter === 'available') { $where .= " AND is_used=0"; }
if ($filter === 'used') { $where .= " AND is_used=1"; }

$exam_filter = $_GET['exam'] ?? '';
if (!empty($exam_filter)) {
    $where .= " AND exam_type = ?";
    $params[] = $exam_filter;
}

$total = db()->prepare("SELECT COUNT(*) FROM exam_pins_stock WHERE $where");
$total->execute($params);
$total = $total->fetchColumn();
$pages = ceil($total / $limit);

$pins = db()->prepare("
    SELECT s.*, u.full_name, u.phone 
    FROM exam_pins_stock s 
    LEFT JOIN users u ON s.used_by = u.id 
    WHERE $where
    ORDER BY s.id DESC 
    LIMIT $limit OFFSET $offset
");
$pins->execute($params);
$pins = $pins->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Local Exam PIN Stock – Gracehubtels Admin</title>
    <link rel="icon" type="image/png" href="/assets/images/gracehubtels-favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css">
    <style>
        .metric-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .metric-card { background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #E2E8F0; text-align: center; }
        .metric-card h4 { margin: 0 0 8px; font-size: 14px; color: #64748b; }
        .metric-card .available { font-size: 28px; font-weight: 800; color: var(--success); }
        .metric-card .used { font-size: 13px; color: #94a3b8; margin-top: 4px; }
        .two-col-page { display:grid; grid-template-columns: 1fr 2fr; gap: 24px; align-items: start; }
        @media (max-width:1024px) { .two-col-page { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

    <div class="section-header">
        <div>
            <h2><i class="fas fa-key" style="color:var(--primary);margin-right:12px;"></i> Local Exam PIN Stock <?= !empty($exam_filter) ? "- " . htmlspecialchars($exam_filter) : "" ?></h2>
            <p>Upload and manage local stock for WAEC, NECO, and NABTEB PINs.</p>
        </div>
    </div>

    <!-- Metrics -->
    <div class="metric-cards">
        <?php foreach ($stock_map as $type => $data): ?>
        <div class="metric-card">
            <h4><?=$type?> Stock</h4>
            <div class="available"><?=number_format($data['available'])?></div>
            <div class="used"><?=number_format($data['used'])?> Used</div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Upload Panel -->
    <div class="data-panel" style="margin-bottom: 24px;">
        <div class="panel-title-bar">
            <h3><i class="fas fa-upload" style="color:var(--primary);margin-right:8px;"></i> Upload New Stock</h3>
        </div>
        <div style="padding:20px;">
            <p style="font-size:13px; color:#64748b; margin-bottom:16px;">
                <strong>WAEC:</strong> Upload your CSV file directly.<br>
                <strong>NECO:</strong> Copy the text from the PDF and paste it into the Manual Input box. We will automatically extract the PINs.<br>
                <strong>Format:</strong> PIN, Serial (or just PIN if serial is not applicable).
            </p>

            <form id="uploadForm" onsubmit="handleUpload(event)" class="two-col-page">
                <input type="hidden" name="csrf" value="<?=$csrf?>">
                <input type="hidden" name="action" value="upload_stock">

                <div>
                    <div class="form-group">
                        <label>Exam Type</label>
                        <select name="exam_type" class="form-control" required>
                            <option value="WAEC" <?= $exam_filter==='WAEC'?'selected':'' ?>>WAEC</option>
                            <option value="NECO" <?= $exam_filter==='NECO'?'selected':'' ?>>NECO</option>
                            <option value="NABTEB" <?= $exam_filter==='NABTEB'?'selected':'' ?>>NABTEB</option>
                        </select>
                    </div>

                    <div class="form-group" style="margin-top:16px;">
                        <label>Upload CSV File <span style="font-size:11px;color:#9ca3af">(Optional)</span></label>
                        <input type="file" name="file_upload" accept=".csv, text/csv, .txt, text/plain, application/vnd.ms-excel" class="form-control" style="padding:10px;">
                    </div>
                </div>

                <div>
                    <div class="form-group">
                        <label>Manual Input / Paste from PDF <span style="font-size:11px;color:#9ca3af">(One PIN per line)</span></label>
                        <textarea name="raw_text" class="form-control" style="height:150px;" placeholder="e.g. 
123456789012, SN12345
987654321098, SN67890"></textarea>
                    </div>

                    <button type="submit" class="btn-primary" style="margin-top:16px;" id="uploadBtn">
                        <i class="fas fa-upload"></i> Upload Stock
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Inventory Table -->
    <div class="data-panel">
        <div class="panel-title-bar" style="display:flex; justify-content:space-between; align-items:center;">
            <h3><i class="fas fa-list" style="color:var(--primary);margin-right:8px;"></i> Inventory List</h3>
            <div class="filter-tabs" style="margin:0; background:transparent;">
                <?php $url_exam = !empty($exam_filter) ? "&exam=" . urlencode($exam_filter) : ""; ?>
                <a href="?filter=all<?=$url_exam?>" class="filter-tab <?=$filter==='all'?'active':''?>" style="text-decoration:none">All</a>
                <a href="?filter=available<?=$url_exam?>" class="filter-tab <?=$filter==='available'?'active':''?>" style="text-decoration:none">Available</a>
                <a href="?filter=used<?=$url_exam?>" class="filter-tab <?=$filter==='used'?'active':''?>" style="text-decoration:none">Used</a>
                <?php if ($filter === 'used' || $filter === 'all'): ?>
                <button onclick="clearUsed()" class="btn-danger" style="margin-left:8px; padding:6px 12px; font-size:12px;"><i class="fas fa-trash"></i> Clear Used</button>
                <?php endif; ?>
            </div>
        </div>

        <?php 
        $grouped_pins = ['WAEC' => [], 'NECO' => [], 'NABTEB' => []];
        foreach ($pins as $p) {
            if (isset($grouped_pins[$p['exam_type']])) {
                $grouped_pins[$p['exam_type']][] = $p;
            } else {
                $grouped_pins[$p['exam_type']] = [$p];
            }
        }
        
        foreach ($grouped_pins as $exam_name => $exam_group_pins): 
            if (empty($exam_filter) || $exam_filter === $exam_name):
        ?>
        <div style="margin-bottom: 32px;">
            <h4 style="margin-bottom: 12px; color: var(--slate-800); font-weight: 600; border-bottom: 1px solid #E2E8F0; padding-bottom: 8px;">
                <i class="fas fa-file-invoice" style="color:var(--primary); margin-right:6px;"></i> <?=$exam_name?> Inventory
            </h4>
            <div class="responsive-grid-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>PIN</th>
                            <th>Serial</th>
                            <th>Status</th>
                            <th>Used By</th>
                            <th>Trans Ref</th>
                            <th>Date Added</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($exam_group_pins as $p): ?>
                        <tr>
                            <td style="font-family:monospace; font-size:13px;"><?=htmlspecialchars($p['pin'])?></td>
                            <td style="font-size:12px; color:#64748b;"><?=htmlspecialchars($p['serial'] === 'NULL' ? '—' : $p['serial'])?></td>
                            <td>
                                <span class="badge <?=$p['is_used']?'badge-danger':'badge-success'?>"><?=$p['is_used']?'Used':'Available'?></span>
                            </td>
                            <td>
                                <?php if($p['is_used']): ?>
                                    <div class="user-cell" style="padding:0">
                                        <div class="u-avatar" style="width:24px;height:24px;font-size:10px;"><?=strtoupper(substr($p['full_name']??'?',0,1))?></div>
                                        <div><span class="u-name" style="font-size:12px;"><?=htmlspecialchars($p['full_name']??'—')?></span></div>
                                    </div>
                                <?php else: ?>
                                    <span style="color:#9ca3af">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:11px;"><?=htmlspecialchars($p['transaction_ref'] ?? '—')?></td>
                            <td style="font-size:12px; color:#64748b;"><?=date('d M Y, H:i', strtotime($p['created_at']))?></td>
                            <td>
                                <?php if(!$p['is_used']): ?>
                                <button class="btn-danger" style="padding:4px 8px; font-size:11px;" onclick="deleteStock(<?=$p['id']?>)"><i class="fas fa-trash"></i></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; if(empty($exam_group_pins)): ?>
                        <tr><td colspan="7" class="empty-state" style="padding: 16px; text-align: center; color: #64748b;">No <?=$exam_name?> PINs found in stock matching filter.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php 
            endif;
        endforeach; 
        ?>

        <?php if ($pages > 1): ?>
        <div class="pagination" style="padding:16px; border-top:1px solid #E2E8F0; display:flex; gap:8px;">
            <?php for($i=1; $i<=$pages; $i++): ?>
                <a href="?filter=<?=$filter?>&page=<?=$i?><?=$url_exam?>" class="btn-primary" style="padding:6px 12px; <?=$i===$page?'':'background:var(--navy-light)'?>"><?=$i?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

    </div>
</div>
</div>
<script>
    const CSRF = '<?=$csrf?>';

    async function handleUpload(e) {
        e.preventDefault();
        const form = e.target;
        const btn = document.getElementById('uploadBtn');
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
        btn.disabled = true;

        const formData = new FormData(form);

        try {
            const res = await fetch('', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                alert(data.message);
                window.location.reload();
            } else {
                alert(data.message || 'Upload failed');
                btn.innerHTML = '<i class="fas fa-upload"></i> Upload Stock';
                btn.disabled = false;
            }
        } catch (err) {
            alert('A network error occurred.');
            btn.innerHTML = '<i class="fas fa-upload"></i> Upload Stock';
            btn.disabled = false;
        }
    }

    async function deleteStock(id) {
        if(!confirm('Delete this PIN?')) return;
        const fd = new FormData();
        fd.append('csrf', CSRF);
        fd.append('action', 'delete_stock');
        fd.append('id', id);
        try {
            const res = await fetch('', { method:'POST', body:fd });
            const data = await res.json();
            if (data.success) window.location.reload();
        } catch(e) {}
    }

    async function clearUsed() {
        if(!confirm('Are you sure you want to delete all USED PINs to clear space?')) return;
        const fd = new FormData();
        fd.append('csrf', CSRF);
        fd.append('action', 'clear_used');
        try {
            const res = await fetch('', { method:'POST', body:fd });
            const data = await res.json();
            if (data.success) window.location.reload();
        } catch(e) {}
    }
    
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('open');
    }
</script>
</body>
</html>
