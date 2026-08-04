<?php
$base_dir = 'c:\\xampp\\htdocs\\gracehubtels\\';

// 1. Fix data.php
$data_file = $base_dir . 'user\\data.php';
if (file_exists($data_file)) {
    $content = file_get_contents($data_file);
    
    // Restore network grid loop
    $search_grid = '<?php foreach($nets as $n=>$pinfo): 
    $bg = $pinfo[0]; 
    $color = $pinfo[1]; 
    $logo = $pinfo[2];?>
                <a href="?net=<?=$n?>" class="net-btn flex flex-col items-center justify-center p-2 sm:p-3 rounded-xl border-2 <?= $sel_net===$n?\'border-brand-orange bg-brand-orangeLight\':\'border-slate-100 bg-slate-50 hover:border-brand-orange/50\' ?> transition-all relative overflow-hidden text-center">
                    <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-full mb-1 sm:mb-2 overflow-hidden bg-white shadow-sm flex items-center justify-center <?= $bg ?> <?= $color ?> font-bold text-xs sm:text-base">
                        <img src="<?=$logo?>" alt="<?=$n?>" class="w-full h-full object-cover" onerror="this.style.display=\'none\';this.parentNode.innerHTML=\'<?=substr($n,0,1)?>\'">
                    </div>';
                    
    $replace_grid = '<?php foreach($nets as $n=>[$bg, $color]):?>
                <a href="?net=<?=$n?>" class="net-btn flex flex-col items-center justify-center p-2 sm:p-3 rounded-xl border-2 <?= $sel_net===$n?\'border-brand-orange bg-brand-orangeLight\':\'border-slate-100 bg-slate-50 hover:border-brand-orange/50\' ?> transition-all relative overflow-hidden text-center">
                    <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-full mb-1 sm:mb-2 overflow-hidden bg-white shadow-sm flex items-center justify-center <?= $bg ?> <?= $color ?> font-bold text-xs sm:text-base">
                        <?=substr($n,0,1)?>
                    </div>';
                    
    $content = str_replace($search_grid, $replace_grid, $content);
    
    // Replace openConfirm in data.php
    $search_oc = 'function openConfirm() {
    if(document.getElementById(\'txModal\')) document.getElementById(\'txModal\').remove();
    let planId = document.getElementById(\'selectedPlan\').value;
    let phone = document.getElementById(\'phoneF\').value;
    
    if(!planId) return showAlert(\'Please select a data plan\', \'Error\', \'exclamation-triangle\', \'text-amber-500\');
    if(!phone || phone.length < 10) return showAlert(\'Please enter a valid phone number\', \'Error\', \'exclamation-triangle\', \'text-amber-500\');
    
    // Show custom modal
    const modalHtml = `
    <div id="txModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm opacity-0 transition-opacity">
        <div class="bg-white rounded-3xl w-full max-w-sm overflow-hidden shadow-2xl transform scale-95 transition-transform duration-300">
            <div class="bg-brand-navy p-5 text-center relative overflow-hidden">
                <div class="absolute inset-0 opacity-10" style="background-image: radial-gradient(circle at 2px 2px, white 1px, transparent 0); background-size: 16px 16px;"></div>
                <h3 class="text-lg font-bold text-white relative z-10">Confirm Purchase</h3>
                <p class="text-white/70 text-sm relative z-10">Please verify transaction details</p>
            </div>
            <div class="p-5 sm:p-6 space-y-4">
                <div class="flex justify-between items-center pb-3 border-b border-slate-100">
                    <span class="text-slate-500 text-sm font-medium">Network</span>
                    <span class="text-slate-800 font-bold flex items-center gap-2">
                        <span class="w-6 h-6 rounded-full bg-slate-100 flex items-center justify-center text-[10px]"><?=$sel_net?></span>
                        <?=$sel_net?>
                    </span>
                </div>
                <div class="flex justify-between items-center pb-3 border-b border-slate-100">
                    <span class="text-slate-500 text-sm font-medium">Data Plan</span>
                    <span class="text-slate-800 font-bold" id="c_plan"></span>
                </div>
                <div class="flex justify-between items-center pb-3 border-b border-slate-100">
                    <span class="text-slate-500 text-sm font-medium">Phone Number</span>
                    <span class="text-slate-800 font-bold tracking-wide">${phone}</span>
                </div>
                <div class="flex justify-between items-center pb-4 border-b border-slate-100">
                    <span class="text-slate-500 text-sm font-medium">Amount to Pay</span>
                    <span class="text-brand-orange font-black text-lg" id="c_amt"></span>
                </div>
                
                <div class="pt-2">
                    <label class="block text-xs font-semibold text-slate-700 mb-2">Transaction PIN</label>
                    <div class="flex gap-2 justify-between">
                        <input type="password" class="pin-box w-12 h-12 rounded-xl border border-slate-200 text-center text-xl font-bold text-brand-navy focus:border-brand-orange focus:ring-2 focus:ring-brand-orange/20 transition-all bg-slate-50" maxlength="1" onkeyup="movePin(this,1)">
                        <input type="password" class="pin-box w-12 h-12 rounded-xl border border-slate-200 text-center text-xl font-bold text-brand-navy focus:border-brand-orange focus:ring-2 focus:ring-brand-orange/20 transition-all bg-slate-50" maxlength="1" onkeyup="movePin(this,2)">
                        <input type="password" class="pin-box w-12 h-12 rounded-xl border border-slate-200 text-center text-xl font-bold text-brand-navy focus:border-brand-orange focus:ring-2 focus:ring-brand-orange/20 transition-all bg-slate-50" maxlength="1" onkeyup="movePin(this,3)">
                        <input type="password" class="pin-box w-12 h-12 rounded-xl border border-slate-200 text-center text-xl font-bold text-brand-navy focus:border-brand-orange focus:ring-2 focus:ring-brand-orange/20 transition-all bg-slate-50" maxlength="1" onkeyup="movePin(this,4)">
                    </div>
                </div>
                
                <div class="flex gap-3 mt-6">
                    <button onclick="closeModal()" class="flex-1 px-4 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl font-bold text-sm transition-colors">Cancel</button>
                    <button onclick="processTx()" id="payBtn" class="flex-1 px-4 py-3 bg-brand-orange hover:bg-brand-orangeHover text-white rounded-xl font-bold text-sm transition-colors shadow-lg shadow-orange-500/30 flex items-center justify-center gap-2">
                        Pay Now
                    </button>
                </div>
            </div>
        </div>
    </div>`;
    
    document.body.insertAdjacentHTML(\'beforeend\', modalHtml);
    
    // Fill dynamic data
    let pCard = document.querySelector(\'.plan-card[data-id="\'+planId+\'"]\');
    document.getElementById(\'c_plan\').textContent = pCard.dataset.size;
    document.getElementById(\'c_amt\').textContent = \'₦\' + parseInt(pCard.dataset.price).toLocaleString();
    
    // Animate in
    setTimeout(() => {
        document.getElementById(\'txModal\').classList.remove(\'opacity-0\');
        document.getElementById(\'txModal\').querySelector(\'.transform\').classList.remove(\'scale-95\');
    }, 10);
    
    // Focus first pin
    setTimeout(() => document.querySelector(\'.pin-box\').focus(), 100);
}

function movePin(el, next) {
    if(el.value.length === 1 && next <= 4) {
        let boxes = document.querySelectorAll(\'.pin-box\');
        if(next < 4) boxes[next].focus();
    }
}

function closeModal() {
    let m = document.getElementById(\'txModal\');
    m.classList.add(\'opacity-0\');
    m.querySelector(\'.transform\').classList.add(\'scale-95\');
    setTimeout(() => m.remove(), 300);
}

function processTx() {
    let pin = Array.from(document.querySelectorAll(\'.pin-box\')).map(i=>i.value).join(\'\');
    if(pin.length !== 4) return showAlert(\'Enter 4-digit PIN\', \'Error\');';

    $replace_oc = 'function openConfirm() {
    let planId = document.getElementById(\'selectedPlan\').value;
    let phone = document.getElementById(\'phoneF\').value;
    
    if(!planId) return showAlert(\'Please select a data plan\', \'Error\', \'exclamation-triangle\', \'text-amber-500\');
    if(!phone || phone.length < 10) return showAlert(\'Please enter a valid phone number\', \'Error\', \'exclamation-triangle\', \'text-amber-500\');
    
    let pCard = document.querySelector(\'.plan-card[data-id="\'+planId+\'"]\');
    let planSize = pCard.dataset.size;
    let amt = \'₦\' + parseInt(pCard.dataset.price).toLocaleString();
    let net = "<?=$sel_net?>";
    
    if(typeof confirmTransaction === \'function\') {
        confirmTransaction({
            "Transaction": "Data Purchase",
            "Network": net,
            "Plan": planSize,
            "Phone": phone,
            "Amount": amt
        }, function(pin) {
            processTx(pin);
        });
    } else {
        const pin = prompt(`Confirm Data Purchase:\\nNetwork: ${net}\\nPlan: ${planSize}\\nPhone: ${phone}\\nAmount: ${amt}\\n\\nEnter your 4-digit PIN:`);
        if(pin && pin.length >= 4) processTx(pin);
    }
}

function processTx(pin) {
    if(!pin || pin.length < 4) return showAlert(\'Enter 4-digit PIN\', \'Error\');';

    $content = str_replace($search_oc, $replace_oc, $content);
    
    // Also remove the base64 from $nets array if present
    $content = preg_replace("/,\s*'data:image\/[^']+'\]/", "]", $content);
    
    file_put_contents($data_file, $content);
    echo "Fixed data.php\n";
}

// 2. Fix airtime.php
$airtime_file = $base_dir . 'user\\airtime.php';
if (file_exists($airtime_file)) {
    $content = file_get_contents($airtime_file);
    
    // Restore network grid loop
    $search_grid = '<div class="w-12 h-12 rounded-full mb-2 overflow-hidden bg-white shadow-sm flex items-center justify-center <?= $bg ?> <?= $color ?> font-bold">
                        <img src="<?=$logo_map[$name]?>" alt="<?=$name?>" class="w-full h-full object-cover" onerror="this.style.display=\'none\';this.parentNode.innerHTML=\'<?=substr($name,0,1)?>\'">
                    </div>';
    $replace_grid = '<div class="w-12 h-12 rounded-full mb-2 overflow-hidden bg-white shadow-sm flex items-center justify-center <?= $bg ?> <?= $color ?> font-bold">
                        <?=substr($name,0,1)?>
                    </div>';
    
    $content = str_replace($search_grid, $replace_grid, $content);
    file_put_contents($airtime_file, $content);
    echo "Fixed airtime.php\n";
}
