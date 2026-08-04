<?php
$base_dir = 'c:\\xampp\\htdocs\\gracehubtels\\';

// 1. Fix data.php
$data_file = $base_dir . 'user\\data.php';
if (file_exists($data_file)) {
    $content = file_get_contents($data_file);
    
    // Fix Base64 image MIME type
    $content = str_replace('data:image/png;base64,/9j/', 'data:image/jpeg;base64,/9j/', $content);
    
    // Fix modal duplication in openConfirm
    $modal_fix = "function openConfirm() {\n    if(document.getElementById('txModal')) document.getElementById('txModal').remove();\n";
    if (strpos($content, "if(document.getElementById('txModal')) document.getElementById('txModal').remove();") === false) {
        $content = str_replace("function openConfirm() {\n", $modal_fix, $content);
    }
    
    file_put_contents($data_file, $content);
    echo "Fixed data.php\n";
}

// 2. Fix airtime.php
$airtime_file = $base_dir . 'user\\airtime.php';
if (file_exists($airtime_file)) {
    $content = file_get_contents($airtime_file);
    
    // Fix Base64 image MIME type
    $content = str_replace('data:image/png;base64,/9j/', 'data:image/jpeg;base64,/9j/', $content);
    
    // Fix redirect for failed/successful transactions with ref
    $old_fetch = "        if(res.success) {
            alert(`Success! Airtime Sent.\\n\${res.message}`);
            location.reload();
        } else {
            alert(`Error: \${res.message}`);
            btn.innerHTML = ogHtml;
            btn.disabled = false;
        }";
        
    $new_fetch = "        if (res.ref) {
            window.location.href = '/user/receipt.php?ref=' + res.ref;
            return;
        }
        if(res.success) {
            alert(`Success! Airtime Sent.\\n\${res.message}`);
            location.reload();
        } else {
            alert(`Error: \${res.message}`);
            btn.innerHTML = ogHtml;
            btn.disabled = false;
        }";
        
    if (strpos($content, "if (res.ref)") === false) {
        $content = str_replace($old_fetch, $new_fetch, $content);
    }
    
    file_put_contents($airtime_file, $content);
    echo "Fixed airtime.php\n";
}

// 3. Fix dashboard.php (transaction history logos)
$dash_file = $base_dir . 'user\\core\\dashboard.php';
if (file_exists($dash_file)) {
    $content = file_get_contents($dash_file);
    $content = str_replace('data:image/png;base64,/9j/', 'data:image/jpeg;base64,/9j/', $content);
    file_put_contents($dash_file, $content);
    echo "Fixed dashboard.php\n";
}

// 4. Fix cabletv.php and electricity.php
$files = ['user\\cabletv.php', 'user\\electricity.php', 'user\\data-card.php'];
foreach ($files as $f) {
    $path = $base_dir . $f;
    if (file_exists($path)) {
        $content = file_get_contents($path);
        $content = str_replace('data:image/png;base64,/9j/', 'data:image/jpeg;base64,/9j/', $content);
        file_put_contents($path, $content);
        echo "Fixed $f\n";
    }
}
