    </main>
</div> <!-- End Main content wrapper -->

<!-- Mobile Bottom Navigation -->
<?php $current_page = basename($_SERVER['PHP_SELF']); ?>
<nav class="md:hidden fixed bottom-0 w-full bg-white border-t border-slate-200 z-50 px-2 py-2 flex justify-between items-center shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.05)]">
    <a href="/msjpay/user/dashboard" class="flex flex-col items-center justify-center w-1/5 <?= $current_page == 'dashboard.php' ? 'text-brand-orange' : 'text-slate-500 hover:text-slate-800' ?>">
        <i class="fa-solid fa-house text-lg mb-1"></i>
        <span class="text-[10px] font-medium">Home</span>
    </a>
    <a href="/msjpay/user/transactions" class="flex flex-col items-center justify-center w-1/5 <?= $current_page == 'transactions.php' ? 'text-brand-orange' : 'text-slate-500 hover:text-slate-800' ?>">
        <i class="fa-solid fa-clock-rotate-left text-lg mb-1"></i>
        <span class="text-[10px] font-medium">History</span>
    </a>
    <a href="/msjpay/user/support" class="flex flex-col items-center justify-center w-1/5 <?= $current_page == 'support.php' ? 'text-brand-orange' : 'text-slate-500 hover:text-slate-800' ?>">
        <i class="fa-solid fa-headset text-lg mb-1"></i>
        <span class="text-[10px] font-medium">Support</span>
    </a>
    <a href="/msjpay/user/profile" class="flex flex-col items-center justify-center w-1/5 <?= $current_page == 'profile.php' ? 'text-brand-orange' : 'text-slate-500 hover:text-slate-800' ?>">
        <i class="fa-solid fa-user text-lg mb-1"></i>
        <span class="text-[10px] font-medium">Profile</span>
    </a>
    <a href="/msjpay/user/settings" class="flex flex-col items-center justify-center w-1/5 <?= $current_page == 'settings.php' ? 'text-brand-orange' : 'text-slate-500 hover:text-slate-800' ?>">
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
                alert('Copied: ' + text);
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

<?php if(file_exists(__DIR__ . '/../js/app.js')): ?>
<script src="/msjpay/user/js/app.js"></script>
<?php endif; ?>
</body>
</html>
