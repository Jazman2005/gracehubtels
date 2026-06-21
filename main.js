document.addEventListener('DOMContentLoaded', () => {
    // ==========================================
    // 1. Sticky Header scroll behavior
    // ==========================================
    const header = document.querySelector('.header');
    
    const handleScroll = () => {
        if (window.scrollY > 20) {
            header.classList.add('scrolled');
        } else {
            header.classList.remove('scrolled');
        }
    };
    
    window.addEventListener('scroll', handleScroll);
    handleScroll(); // Initial check

    // ==========================================
    // 2. Mobile Menu Toggle
    // ==========================================
    const mobileToggle = document.querySelector('.mobile-toggle');
    const navMenu = document.querySelector('.nav-menu');
    
    if (mobileToggle && navMenu) {
        mobileToggle.addEventListener('click', () => {
            navMenu.classList.toggle('open');
            const isOpen = navMenu.classList.contains('open');
            mobileToggle.innerHTML = isOpen ? '<i class="fas fa-times">✕</i>' : '<i class="fas fa-bars">☰</i>';
        });
    }

    // Close menu when clicking navigation links
    const navLinks = document.querySelectorAll('.nav-link');
    navLinks.forEach(link => {
        link.addEventListener('click', () => {
            if (navMenu && navMenu.classList.contains('open')) {
                navMenu.classList.remove('open');
                mobileToggle.innerHTML = '<i class="fas fa-bars">☰</i>';
            }
        });
    });

    // ==========================================
    // 3. Contact Form & Field Validation
    // ==========================================
    const contactForm = document.getElementById('contact-form');
    const messageInput = document.getElementById('message');
    const charCounter = document.getElementById('char-count');
    
    // Character Counter for Message Box
    if (messageInput && charCounter) {
        messageInput.addEventListener('input', (e) => {
            const count = e.target.value.length;
            charCounter.textContent = `${count} / 1000`;
            if (count > 1000) {
                charCounter.style.color = 'var(--accent-orange)';
            } else {
                charCounter.style.color = 'var(--neutral-muted)';
            }
        });
    }

    // Input Validation Helpers
    const setError = (element, message) => {
        const inputGroup = element.closest('.form-group');
        const errorDisplay = inputGroup.querySelector('.error-msg');
        
        inputGroup.classList.add('has-error');
        if (errorDisplay) {
            errorDisplay.textContent = message;
        }
    };

    const removeError = (element) => {
        const inputGroup = element.closest('.form-group');
        inputGroup.classList.remove('has-error');
    };

    const validateEmail = (email) => {
        const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return regex.test(email);
    };

    // Live validation check on blur
    const formInputs = document.querySelectorAll('.form-input, .form-select, .form-textarea');
    formInputs.forEach(input => {
        input.addEventListener('blur', () => {
            if (input.required && !input.value.trim()) {
                setError(input, `Please enter your ${input.name || 'field'}`);
            } else if (input.type === 'email' && input.value.trim() && !validateEmail(input.value)) {
                setError(input, 'Please enter a valid email address');
            } else {
                removeError(input);
            }
        });

        input.addEventListener('input', () => {
            if (input.value.trim()) {
                removeError(input);
            }
        });
    });

    // Form Submission Actions
    if (contactForm) {
        contactForm.addEventListener('submit', (e) => {
            e.preventDefault();
            
            let isFormValid = true;
            
            // Loop inputs and confirm values
            formInputs.forEach(input => {
                if (input.required && !input.value.trim()) {
                    setError(input, `Please enter your ${input.name || 'field'}`);
                    isFormValid = false;
                } else if (input.type === 'email' && input.value.trim() && !validateEmail(input.value)) {
                    setError(input, 'Please enter a valid email address');
                    isFormValid = false;
                } else {
                    removeError(input);
                }
            });

            if (isFormValid) {
                const submitBtn = contactForm.querySelector('.form-submit-btn');
                const origText = submitBtn.textContent;
                
                // Show loading state
                submitBtn.disabled = true;
                submitBtn.textContent = 'Sending...';
                submitBtn.style.opacity = '0.7';

                // Simulate API post response
                setTimeout(() => {
                    // Open the success modal overlay
                    const overlay = document.getElementById('success-overlay');
                    if (overlay) {
                        // Personalize modal content
                        const clientName = document.getElementById('fullname').value;
                        const clientEmail = document.getElementById('email').value;
                        
                        const clientNameText = overlay.querySelector('.success-client-name');
                        const clientEmailText = overlay.querySelector('.success-client-email');
                        
                        if (clientNameText) clientNameText.textContent = clientName;
                        if (clientEmailText) clientEmailText.textContent = clientEmail;
                        
                        overlay.style.display = 'flex';
                    }
                    
                    // Reset fields
                    contactForm.reset();
                    if (charCounter) charCounter.textContent = '0 / 1000';
                    
                    // Restore button state
                    submitBtn.disabled = false;
                    submitBtn.textContent = origText;
                    submitBtn.style.opacity = '1';
                }, 1500);
            }
        });
    }

    // Modal Close Action
    const closeModalBtn = document.getElementById('close-modal-btn');
    const overlay = document.getElementById('success-overlay');
    
    if (closeModalBtn && overlay) {
        closeModalBtn.addEventListener('click', () => {
            overlay.style.display = 'none';
        });
        
        // Also close when clicking outer shadow background
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                overlay.style.display = 'none';
            }
        });
    }
});
