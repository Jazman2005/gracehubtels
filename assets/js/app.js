// MsjPay Global Application Logic
document.addEventListener("DOMContentLoaded", () => {
    // UI Helpers
    window.toggleBal = function() {
        const wbal = document.getElementById('wbal');
        const cbal = document.getElementById('cbal');
        const hbtxt = document.getElementById('hbtxt');
        if(!wbal) return;
        
        if(wbal.textContent === '****') {
            wbal.textContent = wbal.getAttribute('data-v');
            if(cbal) cbal.textContent = cbal.getAttribute('data-v');
            if(hbtxt) hbtxt.textContent = 'Hide';
        } else {
            wbal.textContent = '****';
            if(cbal) cbal.textContent = '****';
            if(hbtxt) hbtxt.textContent = 'Show';
        }
    };

    window.copyAcct = window.copyNum = function(text) {
        navigator.clipboard.writeText(text).then(() => {
            toast('Copied to clipboard!', 'success');
        }).catch(err => {
            toast('Failed to copy', 'error');
        });
    };

    window.toast = function(msg, type='info') {
        const t = document.createElement('div');
        t.style.position = 'fixed';
        t.style.bottom = '80px';
        t.style.left = '50%';
        t.style.transform = 'translateX(-50%)';
        t.style.background = type === 'error' ? '#EF4444' : '#10B981';
        t.style.color = '#fff';
        t.style.padding = '12px 24px';
        t.style.borderRadius = '30px';
        t.style.fontSize = '14px';
        t.style.fontWeight = '600';
        t.style.zIndex = '9999';
        t.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
        t.innerText = msg;
        document.body.appendChild(t);
        setTimeout(() => t.remove(), 3000);
    };

    // Profile Modals & Settings
    window.openSection = function(id) {
        const sec = document.getElementById(id);
        if(sec) {
            sec.classList.add('open');
        } else {
            toast('Feature coming soon', 'info');
        }
    };
    
    window.closeSection = function(id) {
        const sec = document.getElementById(id);
        if(sec) sec.classList.remove('open');
    };

    window.submitProfileForm = async function(e, action, formId) {
        e.preventDefault();
        const form = document.getElementById(formId);
        const btn = form.querySelector('button[type="submit"]');
        const origText = btn.textContent;
        btn.textContent = 'Saving...';
        btn.disabled = true;

        const fd = new FormData(form);
        fd.append('action', action);
        
        if(!fd.has('csrf_token')) {
            fd.append('csrf_token', document.getElementById('csrfF')?.value || '');
        }

        try {
            const res = await fetch('', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd });
            const data = await res.json();
            if(data.success) {
                toast(data.message, 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                toast(data.message, 'error');
                btn.textContent = origText;
                btn.disabled = false;
            }
        } catch(err) {
            toast('Network error', 'error');
            btn.textContent = origText;
            btn.disabled = false;
        }
    };

    window.createVA = async function(event) {
        const btn = event.currentTarget;
        const origHTML = btn.innerHTML;
        btn.innerHTML = 'Generating...';
        btn.style.pointerEvents = 'none';

        const fd = new FormData();
        fd.append('action', 'create_va');
        fd.append('csrf_token', document.getElementById('csrfF')?.value || '');

        try {
            const url = location.pathname.includes('dashboard') ? '/msjpay/user/profile.php' : '';
            const res = await fetch(url, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd });
            const data = await res.json();
            if(data.success) {
                toast('Account created!', 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                toast(data.message || 'Failed to create account', 'error');
                btn.innerHTML = origHTML;
                btn.style.pointerEvents = 'auto';
            }
        } catch(err) {
            toast('Network error', 'error');
            btn.innerHTML = origHTML;
            btn.style.pointerEvents = 'auto';
        }
    };

    // Form Helpers
    window.selectNet = function(btn, net) {
        document.querySelectorAll('.net-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        const netF = document.getElementById('netF') || document.getElementById('networkF');
        if(netF) netF.value = net;
        
        if(typeof window.fetchDataPlans === 'function') {
            window.fetchDataPlans(net);
        }
    };

    window.setAmt = function(amt, btn) {
        const amtF = document.getElementById('amtF');
        if(amtF) amtF.value = '₦' + amt;
        
        if(typeof window.updateTotal === 'function') window.updateTotal();
    };

    window.useBenef = function(val, net, id) {
        const phoneF = document.getElementById('phoneF') || document.getElementById('acctF') || document.getElementById('meterF') || document.getElementById('smartF');
        if(phoneF) phoneF.value = val;
        
        if(net) {
            const btn = document.querySelector(`.net-btn[data-net="${net}"]`);
            if(btn) selectNet(btn, net);
        }
        
        const bankSel = document.getElementById('bankSel');
        if(bankSel && net) {
            bankSel.value = net; 
            if(typeof window.verifyAcct === 'function') window.verifyAcct();
        }
    };

    // Modals
    window.openConfirm = function(htmlContent = null) {
        const ovl = document.getElementById('confirmOvl');
        if(!ovl) return;
        
        if(!htmlContent) {
            htmlContent = buildConfirmDetails();
            if(!htmlContent) return; 
        }
        
        document.getElementById('confirmDetail').innerHTML = htmlContent;
        ovl.classList.add('open');
    };
    
    window.closeConfirm = function() {
        document.getElementById('confirmOvl').classList.remove('open');
    };

    function buildConfirmDetails() {
        let html = '';
        const phone = document.getElementById('phoneF')?.value;
        const net = document.getElementById('netF')?.value;
        const amt = document.getElementById('amtF')?.value;
        const plan = document.getElementById('planSel');
        
        if(document.getElementById('phoneF')) {
            if(!phone || phone.length < 10) return toast('Enter valid phone number', 'error'), false;
            html += `<div class="cd-row"><span>Number</span><span>${phone}</span></div>`;
        }
        if(document.getElementById('netF') && net) {
            html += `<div class="cd-row"><span>Network</span><span>${net}</span></div>`;
        }
        if(plan && plan.value) {
            const opt = plan.options[plan.selectedIndex];
            html += `<div class="cd-row"><span>Plan</span><span>${opt.text}</span></div>`;
        }
        if(document.getElementById('amtF')) {
            if(!amt || amt < 50) return toast('Invalid amount', 'error'), false;
            html += `<div class="cd-row"><span>Amount</span><span style="font-weight:800;color:var(--primary)">₦${amt}</span></div>`;
        }
        
        const acct = document.getElementById('acctF')?.value;
        const bank = document.getElementById('bankSel');
        const vName = document.getElementById('vName')?.textContent;
        if(acct) {
            if(!acct || !bank.value || !vName) return toast('Please verify account', 'error'), false;
            html += `<div class="cd-row"><span>Account</span><span>${acct}</span></div>`;
            html += `<div class="cd-row"><span>Name</span><span>${vName}</span></div>`;
            html += `<div class="cd-row"><span>Amount</span><span style="font-weight:800;color:var(--primary)">₦${amt}</span></div>`;
        }

        const meter = document.getElementById('meterF')?.value;
        if(meter) {
            if(!meter || !document.getElementById('vName')?.textContent) return toast('Verify meter first', 'error'), false;
            html += `<div class="cd-row"><span>Meter</span><span>${meter}</span></div>`;
            html += `<div class="cd-row"><span>Amount</span><span style="font-weight:800;color:var(--primary)">₦${amt}</span></div>`;
        }

        return html;
    }

    // PIN Pad Logic
    let currentPin = '';
    window.openPinModal = function() {
        closeConfirm();
        if (typeof userHasPin !== 'undefined' && !userHasPin) {
            openSection('setupPinModal');
            return;
        }
        const ovl = document.getElementById('pinOvl');
        if(!ovl) return;
        currentPin = '';
        updatePinUI();
        document.getElementById('pinErr').style.display = 'none';
        ovl.classList.add('open');
    };
    
    window.closePinModal = function() {
        document.getElementById('pinOvl').classList.remove('open');
    };

    window.pd = function(val) {
        if(val === 'del') {
            currentPin = currentPin.slice(0, -1);
        } else {
            if(currentPin.length < 4) currentPin += val;
        }
        updatePinUI();
        
        if(currentPin.length === 4) {
            submitTransaction(currentPin);
        }
    };

    function updatePinUI() {
        for(let i=0; i<4; i++) {
            const d = document.getElementById('pd'+i);
            if(d) {
                if(i < currentPin.length) d.classList.add('filled');
                else d.classList.remove('filled');
            }
        }
    }

    async function submitTransaction(pin) {
        const btnKeys = document.querySelectorAll('.key-btn');
        btnKeys.forEach(b => b.style.opacity = '0.5');
        btnKeys.forEach(b => b.style.pointerEvents = 'none');
        
        let payload = { pin: pin };
        
        const fields = ['phoneF', 'netF', 'amtF', 'acctF', 'bankSel', 'meterF', 'typeSel', 'smartF', 'planSel', 'qty', 'denom'];
        fields.forEach(f => {
            const el = document.getElementById(f);
            if(el) {
                let key = f.replace('F', '').replace('Sel', '');
                if(f==='acctF') key='account_number';
                if(f==='bankSel') key='bank_code';
                if(f==='meterF') key='meter_number';
                if(f==='smartF') key='smartcard_number';
                if(f==='planSel') key='plan_id';
                if(f==='netF') key='network';
                if(f==='typeSel') key='meter_type';
                if(f==='amtF') key='amount';
                
                let val = el.value;
                if (f === 'amtF' && val) val = val.replace(/[^0-9.]/g, '');
                
                payload[key] = val;
            }
        });
        
        payload.csrf_token = document.getElementById('csrfF')?.value || '';
        
        if(document.getElementById('saveBenef')?.checked) payload.save_beneficiary = '1';
        if(document.getElementById('narF')?.value) payload.narration = document.getElementById('narF').value;
        if(document.getElementById('payeeName')?.value) payload.account_name = document.getElementById('payeeName').value;

        try {
            const fd = new FormData();
            for(let k in payload) fd.append(k, payload[k]);
            
            const res = await fetch('', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            });
            const data = await res.json();
            
            if(data.success) {
                closePinModal();
                showSuccess(data.message, data.cashback);
            } else {
                showPinError(data.message || 'Transaction failed');
            }
        } catch(e) {
            showPinError('Network error. Check connection.');
        } finally {
            btnKeys.forEach(b => b.style.opacity = '1');
            btnKeys.forEach(b => b.style.pointerEvents = 'auto');
            currentPin = '';
            updatePinUI();
        }
    }

    window.submitSetupPin = async function(e) {
        e.preventDefault();
        const form = document.getElementById('setupPinForm');
        const btn = form.querySelector('button');
        const origText = btn.textContent;
        btn.textContent = 'Saving...';
        btn.disabled = true;

        const fd = new FormData(form);
        try {
            const res = await fetch('/msjpay/user/profile.php', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd });
            const data = await res.json();
            if(data.success) {
                userHasPin = true;
                closeSection('setupPinModal');
                toast('PIN setup successfully!', 'success');
                setTimeout(() => openPinModal(), 500);
            } else {
                toast(data.message, 'error');
                btn.textContent = origText;
                btn.disabled = false;
            }
        } catch(err) {
            toast('Network error', 'error');
            btn.textContent = origText;
            btn.disabled = false;
        }
    };

    function showPinError(msg) {
        const err = document.getElementById('pinErr');
        if(err) {
            err.textContent = msg;
            err.style.display = 'block';
        } else {
            toast(msg, 'error');
        }
        currentPin = '';
        updatePinUI();
    }

    function showSuccess(msg, cashback = 0) {
        const ovl = document.getElementById('succOvl');
        if(!ovl) return toast('Success!', 'success');
        
        document.getElementById('succMsg').textContent = msg;
        if(cashback > 0) {
            const cbBadge = document.getElementById('cashbackBadge') || document.getElementById('cbBadge');
            const cbAmt = document.getElementById('cbAmt') || document.getElementById('cbA');
            if(cbBadge && cbAmt) {
                cbBadge.style.display = 'inline-flex';
                cbAmt.textContent = cashback;
            }
        }
        ovl.style.display = 'flex';
    }

    window.resetVerify = function() {
        const vc = document.getElementById('verifyCard');
        if(vc) vc.style.display = 'none';
        const vBtn = document.getElementById('verifyBtn');
        if(vBtn) vBtn.style.display = 'block';
    };

    window.verifyAcct = async function() {
        const acct = document.getElementById('acctF')?.value;
        const bank = document.getElementById('bankSel')?.value;
        if(!acct || acct.length < 10 || !bank) return;
        
        const btn = document.getElementById('verifyBtn');
        if(btn) btn.textContent = '...';
        
        const fd = new FormData();
        fd.append('action', 'validate_account');
        fd.append('bank_code', bank);
        fd.append('account_number', acct);
        fd.append('csrf_token', document.getElementById('csrfF')?.value || '');
        
        try {
            const res = await fetch('', {
                method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd
            });
            const data = await res.json();
            if(data.success && data.account_name) {
                document.getElementById('verifyCard').style.display = 'flex';
                document.getElementById('vName').textContent = data.account_name;
                document.getElementById('payeeName').value = data.account_name;
                if(btn) btn.style.display = 'none';
            } else {
                toast(data.message || 'Invalid account', 'error');
                if(btn) btn.textContent = 'Verify';
            }
        } catch(e) {
            toast('Network error', 'error');
            if(btn) btn.textContent = 'Verify';
        }
    };

    const bankSel = document.getElementById('bankSel');
    if(bankSel && bankSel.options.length <= 1) {
        fetchBankList();
    }
    async function fetchBankList() {
        const fd = new FormData();
        fd.append('action', 'get_banks');
        fd.append('csrf_token', document.getElementById('csrfF')?.value || '');
        try {
            const res = await fetch('', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd});
            const data = await res.json();
            if(data.success && data.banks) {
                let html = '<option value="">-- Select Bank --</option>';
                data.banks.forEach(b => html += `<option value="${b.code}">${b.name}</option>`);
                document.getElementById('bankSel').innerHTML = html;
            }
        } catch(e) {}
    }

    window.verifyUtility = async function(type) {
        const net = document.getElementById('netF')?.value;
        const idF = type === 'electricity' ? document.getElementById('meterF') : document.getElementById('smartF');
        const mType = document.getElementById('typeSel')?.value;
        
        if(!idF.value || !net) return toast('Fill all fields', 'error');
        
        const btn = document.getElementById('verifyBtn');
        if(btn) btn.textContent = '...';
        
        const fd = new FormData();
        fd.append('action', type === 'electricity' ? 'verify_meter' : 'verify_smartcard');
        if(type === 'electricity') {
            fd.append('meter_number', idF.value);
            fd.append('provider', net);
            fd.append('meter_type', mType);
        } else {
            fd.append('smartcard_number', idF.value);
            fd.append('provider', net);
        }
        fd.append('csrf_token', document.getElementById('csrfF')?.value || '');
        
        try {
            const res = await fetch('', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd });
            const data = await res.json();
            if(data.success && data.name) {
                document.getElementById('verifyCard').style.display = 'flex';
                document.getElementById('vName').textContent = data.name;
                if(btn) btn.style.display = 'none';
            } else {
                toast(data.message || 'Verification failed', 'error');
                if(btn) btn.textContent = 'Verify';
            }
        } catch(e) {
            toast('Network error', 'error');
            if(btn) btn.textContent = 'Verify';
        }
    };
    
    window.fetchDataPlans = async function(network) {
        const planSel = document.getElementById('planSel');
        if(!planSel) return;
        planSel.innerHTML = '<option value="">Loading plans...</option>';
        const fd = new FormData();
        fd.append('action', 'get_plans');
        fd.append('network', network);
        fd.append('csrf_token', document.getElementById('csrfF')?.value || '');
        try {
            const res = await fetch('', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd });
            const data = await res.json();
            if(data.success && data.plans) {
                let h = '<option value="">Select Plan</option>';
                data.plans.forEach(p => {
                    h += `<option value="${p.id}" data-price="${p.price}">${p.name} - ₦${p.price}</option>`;
                });
                planSel.innerHTML = h;
            } else {
                planSel.innerHTML = '<option value="">No plans available</option>';
            }
        } catch(e) {
            planSel.innerHTML = '<option value="">Error loading plans</option>';
        }
    };
    
    const planSelElem = document.getElementById('planSel');
    if(planSelElem) {
        planSelElem.addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            const price = opt.getAttribute('data-price');
            const amtF = document.getElementById('amtF');
            if(amtF && price) amtF.value = '₦' + price;
        });
    }

});

window.toggleSave = function() {
    const chk = document.getElementById('saveBenef');
    const knob = document.getElementById('saveKnob');
    const toggle = document.getElementById('saveToggle');
    if(chk.checked) {
        chk.checked = false;
        knob.style.transform = 'translateX(0)';
        toggle.style.background = '#E5E7EB';
    } else {
        chk.checked = true;
        knob.style.transform = 'translateX(18px)';
        toggle.style.background = '#ff5500';
    }
};
