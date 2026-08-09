<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Us - Gracehubtels</title>
    <meta name="description" content="Get in touch with Gracehubtels. Submit a merchant inquiry or account support request, or reach us directly at our Lagos headquarters.">
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <!-- ==========================================
       Header / Navigation Bar
       ========================================== -->
    <header class="header scrolled" id="header">
        <div class="container">
            <nav class="nav-container">
                <a href="index.php" class="logo-link" id="logo-link">
                    <img src="assets/IMG_3899.PNG" alt="Gracehubtels Logo" class="logo-img" id="logo-img-header">
                    <span class="logo-text" id="logo-text-header">Msj<span>Pay</span></span>
                </a>
                
                <ul class="nav-menu" id="nav-menu">
                    <li><a href="index.php#features" class="nav-link" id="link-features">Features</a></li>
                    <li><a href="index.php#how-it-works" class="nav-link" id="link-how">How It Works</a></li>
                    <li><a href="contact.php" class="nav-link active" id="link-contact">Contact</a></li>
                    <li><a href="privacy-policy.php" class="nav-link" id="link-privacy">Privacy Policy</a></li>
                </ul>

                <div class="nav-actions" id="nav-actions">
                    <a href="user/login.php" class="btn btn-ghost" id="btn-login-header">Sign In</a>
                    <a href="user/signup.php" class="btn btn-primary" id="btn-signup-header">Get Started</a>
                </div>

                <button class="mobile-toggle" id="mobile-toggle" aria-label="Toggle Navigation">
                    <i>☰</i>
                </button>
            </nav>
        </div>
    </header>

    <!-- ==========================================
       Page Title Header
       ========================================== -->
    <div class="page-header">
        <div class="container text-center" style="max-width: 700px;">
            <h1 id="contact-title">Get in Touch with Gracehubtels</h1>
            <p class="page-meta" style="font-size: 16px; margin-top: 12px; line-height: 1.6;" id="contact-subtitle">
                Have questions about setting up your merchant account? Or need support with a transaction? Our engineering and support teams are on standby. Fill out the contact form or reach us via our direct channels—we aim to respond to all inquiries within 2 hours.
            </p>
        </div>
    </div>

    <!-- ==========================================
       Contact Layout (Split Screen)
       ========================================== -->
    <main class="section container">
        <div class="contact-layout">
            <!-- Left Info Panel -->
            <div class="contact-info-panel" id="contact-info-panel">
                
                <!-- Email Support -->
                <div class="contact-card" id="contact-card-email">
                    <div class="contact-icon-box">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                    </div>
                    <div class="contact-card-content">
                        <h3>Email Support</h3>
                        <p style="margin-bottom: 8px;">Direct your technical or payment questions here:</p>
                        <a href="mailto:support@gracehubtels.com">support@gracehubtels.com</a>
                        <p style="font-size: 11px; margin-top: 6px; color: var(--neutral-muted);">Response time: Under 2 hours</p>
                    </div>
                </div>

                <!-- Call Support -->
                <div class="contact-card" id="contact-card-phone">
                    <div class="contact-icon-box">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                    </div>
                    <div class="contact-card-content">
                        <h3>Customer Hotline</h3>
                        <p style="margin-bottom: 8px;">Call us any time for urgent settlement issues:</p>
                        <a href="tel:+2348126368886">+234 8126368886</a>
                        <p style="font-size: 11px; margin-top: 6px; color: var(--neutral-muted);">Available 24/7, 365 days a year</p>
                    </div>
                </div>

                <!-- Headquarters -->
                <div class="contact-card" id="contact-card-address">
                    <div class="contact-icon-box">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                    </div>
                    <div class="contact-card-content">
                        <h3>Corporate Headquarters</h3>
                        <p style="line-height: 1.5; color: var(--neutral-muted);">
                            Gracehubtels Technologies Limited<br>
                            F.C.T MUNICIPAL AREA COUNCIL,<br>Abuja,Nigeria.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Right Interactive Form -->
            <div class="contact-form-container" id="contact-form-container">
                <div class="contact-form-card" id="contact-form-card">
                    <h2>Send Us a Message</h2>
                    
                    <form id="contact-form" novalidate>
                        <!-- Full Name -->
                        <div class="form-group">
                            <label for="fullname" class="form-label">Full Name</label>
                            <input type="text" id="fullname" name="fullname" class="form-input" placeholder="e.g., Jazman OLawale" required>
                            <span class="error-msg">Please enter your full name</span>
                        </div>

                        <!-- Email -->
                        <div class="form-group">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" id="email" name="email" class="form-input" placeholder="e.g., jazman@domail.com" required>
                            <span class="error-msg">Please enter a valid email address</span>
                        </div>

                        <!-- Subject Dropdown -->
                        <div class="form-group">
                            <label for="subject" class="form-label">How Can We Help You?</label>
                            <select id="subject" name="subject" class="form-select" required>
                                <option value="" disabled selected>Please select an option</option>
                                <option value="integration">Merchant Account &amp; API Integration</option>
                                <option value="settlement">Transaction Failures / Chargebacks</option>
                                <option value="wallet">Digital Wallet &amp; Transfers</option>
                                <option value="corporate">Corporate Inquiries &amp; Partnerships</option>
                                <option value="other">General Feedback / Other</option>
                            </select>
                            <span class="error-msg">Please select an inquiry topic</span>
                        </div>

                        <!-- Message -->
                        <div class="form-group">
                            <label for="message" class="form-label">Your Message</label>
                            <textarea id="message" name="message" class="form-textarea" rows="5" placeholder="Describe your request in detail. If referencing a transaction, please include your transaction ID..." required></textarea>
                            <div class="char-counter" id="char-count">0 / 1000</div>
                            <span class="error-msg">Please enter your message details</span>
                        </div>

                        <!-- Submit Button -->
                        <button type="submit" class="btn btn-primary form-submit-btn" id="btn-submit-form">Send Message</button>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <!-- ==========================================
       Success Popup Modal Overlay
       ========================================== -->
    <div class="modal-overlay" id="success-overlay">
        <div class="success-modal">
            <div class="success-icon-box">✓</div>
            <h3>Message Sent!</h3>
            <p>Thank you, <strong class="success-client-name">user</strong>. Your inquiry has been logged successfully. A payment specialist will respond to <strong class="success-client-email">your email</strong> within 2 hours.</p>
            <button class="btn btn-navy" id="close-modal-btn" style="padding: 10px 24px;">Close Window</button>
        </div>
    </div>

    <!-- ==========================================
       Footer Component
       ========================================== -->
    <footer class="footer" id="footer">
        <div class="container">
            <div class="footer-grid">
                <div class="footer-brand">
                    <a href="index.php" class="logo-link" style="margin-bottom: 20px;">
                        <img src="assets/IMG_3901.PNG" alt="Gracehubtels Footer Logo" class="logo-img" style="height: 36px;" id="logo-img-footer">
                        <span class="logo-text" style="color: var(--white);" id="logo-text-footer">Msj<span>Pay</span></span>
                    </a>
                    <p>Gracehubtels Technologies Limited is a licensed financial services platform enabling secure and instant payment operations across Nigeria.</p>
                    <div class="footer-socials">
                        <a href="#" class="social-icon">F</a>
                        <a href="#" class="social-icon">T</a>
                        <a href="#" class="social-icon">L</a>
                        <a href="#" class="social-icon">I</a>
                    </div>
                </div>
                
                <div>
                    <h3 class="footer-title">Platform</h3>
                    <ul class="footer-links">
                        <li><a href="index.php#features" class="footer-link">Features</a></li>
                        <li><a href="index.php#how-it-works" class="footer-link">How It Works</a></li>
                        <li><a href="index.php#testimonials" class="footer-link">Testimonials</a></li>
                    </ul>
                </div>
                
                <div>
                    <h3 class="footer-title">Support</h3>
                    <ul class="footer-links">
                        <li><a href="contact.php" class="footer-link">Contact Us</a></li>
                        <li><a href="mailto:support@gracehubtels.com" class="footer-link">support@gracehubtels.com</a></li>
                        <li><a href="tel:+234 8126368886" class="footer-link">+234 8126368886</a></li>
                    </ul>
                </div>
                
                <div>
                    <h3 class="footer-title">Legal</h3>
                    <ul class="footer-links">
                        <li><a href="privacy-policy.php" class="footer-link">Privacy Policy</a></li>
                        <li><a href="terms.php" class="footer-link">Terms of Service</a></li>
                    </ul>
                </div>
            </div>

            <div class="footer-bottom">
                <p>&copy; 2026 Gracehubtels Technologies Limited. All rights reserved.</p>
                <div class="footer-bottom-links">
                    <a href="privacy-policy.php" class="footer-bottom-link">Privacy Policy</a>
                    <a href="terms.php" class="footer-bottom-link">Terms of Service</a>
                </div>
            </div>
        </div>
    </footer>

    <!-- Script -->
    <script src="main.js"></script>
</body>
</html>
