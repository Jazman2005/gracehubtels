<?php
$base_dir = 'c:\\xampp\\htdocs\\gracehubtels\\';

// 1. Fix data.php processTx() logic
$data_file = $base_dir . 'user\\data.php';
if (file_exists($data_file)) {
    $content = file_get_contents($data_file);
    
    // Fix processTx
    $old_process_tx = "function processTx(pin) {
    if(!pin || pin.length < 4) return showAlert('Enter 4-digit PIN', 'Error');
    
    let btn = document.getElementById('payBtn');
    let ogHtml = btn.innerHTML;
    btn.innerHTML = '<i class=\"fas fa-spinner fa-spin\"></i> Processing...';
    btn.disabled = true;";
    
    $new_process_tx = "function processTx(pin) {
    if(!pin || pin.length < 4) return showAlert('Enter 4-digit PIN', 'Error');
    
    let btn = document.querySelector('button[onclick=\"openConfirm()\"]');
    let ogHtml = btn ? btn.innerHTML : '';
    if(btn) { btn.innerHTML = '<i class=\"fas fa-spinner fa-spin\"></i> Processing...'; btn.disabled = true; }";
    
    $content = str_replace($old_process_tx, $new_process_tx, $content);
    
    // Fix processTx catch blocks
    $old_catch1 = "            btn.innerHTML = ogHtml;
            btn.disabled = false;
        }
    })
    .catch(err => {
        showAlert('Network error occurred. Please try again.', 'Error');
        btn.innerHTML = ogHtml;
        btn.disabled = false;
    });";
    
    $new_catch1 = "            if(btn) { btn.innerHTML = ogHtml; btn.disabled = false; }
        }
    })
    .catch(err => {
        showAlert('Network error occurred. Please try again.', 'Error');
        if(btn) { btn.innerHTML = ogHtml; btn.disabled = false; }
    });";
    
    $content = str_replace($old_catch1, $new_catch1, $content);
    
    // Add logos
    $search_nets = '<?php foreach($nets as $n=>[$bg, $color]):?>
                <a href="?net=<?=$n?>" class="net-btn flex flex-col items-center justify-center p-2 sm:p-3 rounded-xl border-2 <?= $sel_net===$n?\'border-brand-orange bg-brand-orangeLight\':\'border-slate-100 bg-slate-50 hover:border-brand-orange/50\' ?> transition-all relative overflow-hidden text-center">
                    <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-full mb-1 sm:mb-2 overflow-hidden bg-white shadow-sm flex items-center justify-center <?= $bg ?> <?= $color ?> font-bold text-xs sm:text-base">
                        <?=substr($n,0,1)?>
                    </div>';
    $replace_nets = '<?php foreach($nets as $n=>[$bg, $color]): 
        $logo_url = "https://api.msjpay.ng/assets/logos/" . strtolower($n) . ".png";
    ?>
                <a href="?net=<?=$n?>" class="net-btn flex flex-col items-center justify-center p-2 sm:p-3 rounded-xl border-2 <?= $sel_net===$n?\'border-brand-orange bg-brand-orangeLight\':\'border-slate-100 bg-slate-50 hover:border-brand-orange/50\' ?> transition-all relative overflow-hidden text-center">
                    <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-full mb-1 sm:mb-2 overflow-hidden bg-white shadow-sm flex items-center justify-center <?= $bg ?> <?= $color ?> font-bold text-xs sm:text-base">
                        <img src="<?=$logo_url?>" alt="<?=$n?>" class="w-full h-full object-cover" onerror="this.style.display=\'none\';this.parentNode.innerHTML=\'<?=substr($n,0,1)?>\'">
                    </div>';
    
    $content = str_replace($search_nets, $replace_nets, $content);
    
    // Also remove closeModal if it's still there
    $content = preg_replace('/function closeModal\(\) \{.*?\}/s', '', $content);
    
    // Fix airtime redirect on failed
    $old_fetch = "        if(res.success) {
            if (res.ref) { window.location.href = '/user/receipt.php?ref=' + res.ref; return; }";
    $new_fetch = "        if (res.ref) { window.location.href = '/user/receipt.php?ref=' + res.ref; return; }
        if(res.success) {";
    $content = str_replace($old_fetch, $new_fetch, $content);

    file_put_contents($data_file, $content);
    echo "Fixed data.php\n";
}

// 2. Fix airtime.php
$airtime_file = $base_dir . 'user\\airtime.php';
if (file_exists($airtime_file)) {
    $content = file_get_contents($airtime_file);
    
    // Add logos
    $search_grid = '<div class="w-12 h-12 rounded-full mb-2 overflow-hidden bg-white shadow-sm flex items-center justify-center <?= $bg ?> <?= $color ?> font-bold">
                        <?=substr($name,0,1)?>
                    </div>';
    $replace_grid = '<?php $logo_url = "https://api.msjpay.ng/assets/logos/" . strtolower($name) . ".png"; ?>
                    <div class="w-12 h-12 rounded-full mb-2 overflow-hidden bg-white shadow-sm flex items-center justify-center <?= $bg ?> <?= $color ?> font-bold">
                        <img src="<?=$logo_url?>" alt="<?=$name?>" class="w-full h-full object-cover" onerror="this.style.display=\'none\';this.parentNode.innerHTML=\'<?=substr($name,0,1)?>\'">
                    </div>';
    
    $content = str_replace($search_grid, $replace_grid, $content);
    file_put_contents($airtime_file, $content);
    echo "Fixed airtime.php\n";
}
