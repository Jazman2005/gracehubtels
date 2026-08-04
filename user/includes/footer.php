    </main>
</div> <!-- End Main content wrapper -->

<!-- Mobile Bottom Navigation -->
<?php $current_page = htmlspecialchars(basename($_SERVER['PHP_SELF'])); ?>
<nav class="md:hidden fixed bottom-0 w-full bg-white border-t border-slate-200 z-50 px-2 py-2 flex justify-between items-center shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.05)]">
    <a href="/user/dashboard" class="flex flex-col items-center justify-center w-1/5 <?= $current_page == 'dashboard.php' ? 'text-brand-orange' : 'text-slate-500 hover:text-slate-800' ?>">
        <i class="fa-solid fa-house text-lg mb-1"></i>
        <span class="text-[10px] font-medium">Home</span>
    </a>
    <a href="/user/transactions" class="flex flex-col items-center justify-center w-1/5 <?= $current_page == 'transactions.php' ? 'text-brand-orange' : 'text-slate-500 hover:text-slate-800' ?>">
        <i class="fa-solid fa-clock-rotate-left text-lg mb-1"></i>
        <span class="text-[10px] font-medium">History</span>
    </a>
    <a href="/user/support" class="flex flex-col items-center justify-center w-1/5 <?= $current_page == 'support.php' ? 'text-brand-orange' : 'text-slate-500 hover:text-slate-800' ?>">
        <i class="fa-solid fa-headset text-lg mb-1"></i>
        <span class="text-[10px] font-medium">Support</span>
    </a>
    <a href="/user/profile" class="flex flex-col items-center justify-center w-1/5 <?= $current_page == 'profile.php' ? 'text-brand-orange' : 'text-slate-500 hover:text-slate-800' ?>">
        <i class="fa-solid fa-user text-lg mb-1"></i>
        <span class="text-[10px] font-medium">Profile</span>
    </a>
    <a href="/user/settings" class="flex flex-col items-center justify-center w-1/5 <?= $current_page == 'settings.php' ? 'text-brand-orange' : 'text-slate-500 hover:text-slate-800' ?>">
        <i class="fa-solid fa-gear text-lg mb-1"></i>
        <span class="text-[10px] font-medium">Settings</span>
    </a>
</nav>

</div> <!-- End flex h-screen -->

<script>
    // Copy to clipboard utility
    function copyText(text, event) {
        if(event) {
            event.stopPropagation();
        }
        navigator.clipboard.writeText(text).then(() => {
            const btn = event ? event.currentTarget : null;
            if(btn) {
                const originalHtml = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check text-green-500"></i> Copied!';
                setTimeout(() => {
                    btn.innerHTML = originalHtml;
                }, 2000);
            } else {
                showAlert('Copied to clipboard.', 'Copied');
            }
        });
    }

    // Balance visibility toggle
    function toggleBalance() {
        const balElements = document.querySelectorAll('.bal-amt');
        const eyeIcon = document.getElementById('bal-eye-icon');
        const isHidden = eyeIcon.classList.contains('fa-eye-slash');
        
        balElements.forEach(el => {
            if (isHidden) {
                el.innerText = el.getAttribute('data-val');
            } else {
                el.innerText = '****';
            }
        });
        
        if (isHidden) {
            eyeIcon.classList.remove('fa-eye-slash');
            eyeIcon.classList.add('fa-eye');
            localStorage.setItem('hideBal', 'false');
        } else {
            eyeIcon.classList.remove('fa-eye');
            eyeIcon.classList.add('fa-eye-slash');
            localStorage.setItem('hideBal', 'true');
        }
    }

    // Initialize balance visibility on load
    document.addEventListener('DOMContentLoaded', () => {
        if (localStorage.getItem('hideBal') === 'true') {
            const eyeIcon = document.getElementById('bal-eye-icon');
            if(eyeIcon) {
                // Trigger hiding without saving to localstorage again
                const balElements = document.querySelectorAll('.bal-amt');
                balElements.forEach(el => el.innerText = '****');
                eyeIcon.classList.remove('fa-eye');
                eyeIcon.classList.add('fa-eye-slash');
            }
        }
    });
</script>

<!-- Global Alert Modal (replaces browser alert) -->
<div id="globalAlertModal" class="fixed inset-0 z-[200] flex items-center justify-center bg-slate-900/80 backdrop-blur-sm px-4 hidden opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-2xl w-full max-w-sm card-shadow overflow-hidden transform scale-95 transition-transform duration-300" id="globalAlertModalContent">
        <div class="p-8 text-center">
            <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-brand-orange/10 flex items-center justify-center" id="globalAlertIcon">
                <i class="fas fa-info-circle text-3xl text-brand-orange"></i>
            </div>
            <h3 class="text-lg font-bold text-slate-800 mb-2" id="globalAlertTitle">Notice</h3>
            <p class="text-sm text-slate-600 mb-6" id="globalAlertMessage"></p>
            <button type="button" onclick="closeGlobalAlert()" class="w-full bg-brand-orange hover:bg-brand-orangeHover text-white font-bold py-3 rounded-xl transition-all">OK</button>
        </div>
    </div>
</div>

<!-- Global Confirm Modal (replaces browser confirm) -->
<div id="globalConfirmChoiceModal" class="fixed inset-0 z-[200] flex items-center justify-center bg-slate-900/80 backdrop-blur-sm px-4 hidden opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-2xl w-full max-w-sm card-shadow overflow-hidden transform scale-95 transition-transform duration-300" id="globalConfirmChoiceModalContent">
        <div class="p-8 text-center">
            <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-amber-50 flex items-center justify-center">
                <i class="fas fa-question-circle text-3xl text-amber-500"></i>
            </div>
            <h3 class="text-lg font-bold text-slate-800 mb-2" id="globalConfirmChoiceTitle">Confirm</h3>
            <p class="text-sm text-slate-600 mb-6 whitespace-pre-wrap" id="globalConfirmChoiceMessage"></p>
            <div class="flex gap-3">
                <button type="button" onclick="closeGlobalConfirmChoice(false)" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-3 rounded-xl transition-all">Cancel</button>
                <button type="button" id="globalConfirmChoiceOk" class="flex-1 bg-brand-orange hover:bg-brand-orangeHover text-white font-bold py-3 rounded-xl transition-all">Yes, Continue</button>
            </div>
        </div>
    </div>
</div>

<!-- Global Prompt Modal (replaces browser prompt) -->
<div id="globalPromptModal" class="fixed inset-0 z-[200] flex items-center justify-center bg-slate-900/80 backdrop-blur-sm px-4 hidden opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-2xl w-full max-w-sm card-shadow overflow-hidden transform scale-95 transition-transform duration-300" id="globalPromptModalContent">
        <div class="p-6">
            <h3 class="text-lg font-bold text-slate-800 mb-2 text-center" id="globalPromptTitle">Input Required</h3>
            <p class="text-sm text-slate-600 mb-4 text-center" id="globalPromptMessage"></p>
            <input type="text" id="globalPromptInput" class="w-full py-3 px-4 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors text-sm" placeholder="">
            <div class="flex gap-3 mt-4">
                <button type="button" onclick="closeGlobalPrompt(null)" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-3 rounded-xl transition-all">Cancel</button>
                <button type="button" id="globalPromptOk" class="flex-1 bg-brand-orange hover:bg-brand-orangeHover text-white font-bold py-3 rounded-xl transition-all">Submit</button>
            </div>
        </div>
    </div>
</div>

<!-- Global Transaction Confirmation Modal -->
<div id="globalConfirmModal" class="fixed inset-0 z-[200] flex items-center justify-center bg-slate-900/80 backdrop-blur-sm px-4 hidden opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-2xl w-full max-w-md card-shadow overflow-hidden transform scale-95 transition-transform duration-300" id="globalConfirmModalContent">
        <!-- Header -->
        <div class="bg-gradient-to-r from-brand-navy to-brand-navyLight px-6 py-5 text-white flex justify-between items-center">
            <h3 class="text-lg font-bold flex items-center gap-2">
                <i class="fas fa-shield-alt text-brand-orange"></i> Confirm Transaction
            </h3>
            <button type="button" onclick="closeGlobalConfirm()" class="w-8 h-8 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center transition-colors">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <!-- Details Body -->
        <div class="p-6 bg-slate-50 border-b border-slate-100">
            <div id="globalConfirmDetails" class="space-y-3">
                <!-- Dynamically populated details go here -->
            </div>
        </div>
        
        <!-- PIN Body -->
        <div class="p-6">
            <p class="text-sm text-slate-500 mb-4 text-center font-medium">Enter your 4-digit Transaction PIN to authorize</p>
            
            <div class="flex justify-center mb-6">
                <input type="password" id="globalConfirmPin" maxlength="4" placeholder="••••" class="block w-2/3 text-center py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-orange/50 focus:border-brand-orange transition-colors font-black text-2xl tracking-[0.5em]" required inputmode="numeric" autocomplete="off">
            </div>
            
            <button type="button" id="globalConfirmBtn" class="w-full bg-brand-orange hover:bg-brand-orangeHover text-white font-bold py-3.5 rounded-xl shadow-md shadow-brand-orange/20 transition-all active:scale-[0.98]">
                Authorize Payment
            </button>
        </div>
    </div>
</div>

<script>
    // ==================== GLOBAL CUSTOM MODALS ====================
    let _alertCallback = null;
    let _confirmCallback = null;
    let _promptCallback = null;
    let globalConfirmCallback = null;

    function _showModal(id, contentId) {
        const m = document.getElementById(id);
        const c = document.getElementById(contentId);
        m.classList.remove('hidden');
        setTimeout(() => { m.classList.remove('opacity-0'); c.classList.remove('scale-95'); c.classList.add('scale-100'); }, 10);
    }
    function _hideModal(id, contentId) {
        const m = document.getElementById(id);
        const c = document.getElementById(contentId);
        m.classList.add('opacity-0'); c.classList.remove('scale-100'); c.classList.add('scale-95');
        setTimeout(() => { m.classList.add('hidden'); }, 300);
    }

    // Custom Alert
    window.showAlert = function(msg, title = 'Notice', icon = 'info-circle', color = 'text-brand-orange') {
        document.getElementById('globalAlertTitle').textContent = title;
        document.getElementById('globalAlertMessage').textContent = msg;
        document.getElementById('globalAlertIcon').innerHTML = `<i class="fas fa-${icon} text-3xl ${color}"></i>`;
        _showModal('globalAlertModal', 'globalAlertModalContent');
        return new Promise(resolve => { _alertCallback = resolve; });
    };
    window.closeGlobalAlert = function() { _hideModal('globalAlertModal', 'globalAlertModalContent'); if (_alertCallback) { _alertCallback(); _alertCallback = null; } };

    // Custom Confirm
    window.showConfirm = function(msg, title = 'Confirm') {
        document.getElementById('globalConfirmChoiceTitle').textContent = title;
        document.getElementById('globalConfirmChoiceMessage').textContent = msg;
        _showModal('globalConfirmChoiceModal', 'globalConfirmChoiceModalContent');
        return new Promise(resolve => { _confirmCallback = resolve; });
    };
    window.closeGlobalConfirmChoice = function(ok) { _hideModal('globalConfirmChoiceModal', 'globalConfirmChoiceModalContent'); if (_confirmCallback) { _confirmCallback(ok); _confirmCallback = null; } };
    document.getElementById('globalConfirmChoiceOk').addEventListener('click', function() { closeGlobalConfirmChoice(true); });

    // Custom Prompt
    window.showPrompt = function(msg, title = 'Input Required', placeholder = '', defaultValue = '') {
        document.getElementById('globalPromptTitle').textContent = title;
        document.getElementById('globalPromptMessage').textContent = msg;
        document.getElementById('globalPromptInput').placeholder = placeholder;
        document.getElementById('globalPromptInput').value = defaultValue;
        _showModal('globalPromptModal', 'globalPromptModalContent');
        document.getElementById('globalPromptInput').focus();
        return new Promise(resolve => { _promptCallback = resolve; });
    };
    window.closeGlobalPrompt = function(val) { _hideModal('globalPromptModal', 'globalPromptModalContent'); if (_promptCallback) { _promptCallback(val); _promptCallback = null; } };
    document.getElementById('globalPromptOk').addEventListener('click', function() { closeGlobalPrompt(document.getElementById('globalPromptInput').value); });

    // ==================== TRANSACTION CONFIRM ====================
    window.confirmTransaction = function(details, callback) {
        globalConfirmCallback = callback;
        const detailsContainer = document.getElementById('globalConfirmDetails');
        const pinInput = document.getElementById('globalConfirmPin');
        detailsContainer.innerHTML = '';
        pinInput.value = '';
        for (const [key, value] of Object.entries(details)) {
            const row = document.createElement('div');
            row.className = 'flex justify-between items-center py-2 border-b border-slate-200/50 last:border-0';
            const k = document.createElement('span'); k.className = 'text-sm font-semibold text-slate-500'; k.innerText = key;
            const v = document.createElement('span'); v.className = 'text-sm font-bold text-slate-800 text-right'; v.innerHTML = value;
            row.appendChild(k); row.appendChild(v); detailsContainer.appendChild(row);
        }
        _showModal('globalConfirmModal', 'globalConfirmModalContent');
        setTimeout(() => pinInput.focus(), 100);
    };
    window.closeGlobalConfirm = function() { _hideModal('globalConfirmModal', 'globalConfirmModalContent'); globalConfirmCallback = null; };
    document.getElementById('globalConfirmBtn').addEventListener('click', function() {
        const pin = document.getElementById('globalConfirmPin').value;
        if (!pin || pin.length < 4) { showAlert('Please enter your 4-digit PIN.', 'Invalid PIN'); return; }
        if (globalConfirmCallback) globalConfirmCallback(pin);
        closeGlobalConfirm();
    });
</script>

<?php if(file_exists(__DIR__ . '/../js/app.js')): ?>
<script src="/user/js/app.js"></script>
<?php endif; ?>
</body>
</html>
