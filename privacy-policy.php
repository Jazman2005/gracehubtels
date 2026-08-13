<?php require_once __DIR__.'/user/core/db.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy - <?=htmlspecialchars(setting('site_name','Gracehubtels'))?></title>
    <meta name="description" content="Read <?=htmlspecialchars(setting('site_name','Gracehubtels'))?>'s Privacy Policy. Learn about the personal and financial information we collect, how we store and process data securely, and your rights under the NDPR.">
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
                    <img src="assets/IMG_3899.PNG" alt="<?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Logo" class="logo-img" id="logo-img-header">
                    <span class="logo-text" id="logo-text-header">Msj<span>Pay</span></span>
                </a>
                
                <ul class="nav-menu" id="nav-menu">
                    <li><a href="index.php#features" class="nav-link" id="link-features">Features</a></li>
                    <li><a href="index.php#how-it-works" class="nav-link" id="link-how">How It Works</a></li>
                    <li><a href="contact.php" class="nav-link" id="link-contact">Contact</a></li>
                    <li><a href="privacy-policy.php" class="nav-link active" id="link-privacy">Privacy Policy</a></li>
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
        <div class="container">
            <h1 id="privacy-title">Privacy Policy</h1>
            <div class="page-meta" id="privacy-meta">Effective Date: June 19, 2026 | <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Technologies Limited</div>
        </div>
    </div>

    <!-- ==========================================
       Legal Content Layout
       ========================================== -->
    <main class="section container">
        <div class="legal-layout">
            <!-- Sidebar Index Navigation -->
            <aside class="legal-sidebar" id="legal-sidebar">
                <h2 class="sidebar-title">Sections</h2>
                <ul class="sidebar-menu">
                    <li><a href="#info-we-collect" class="sidebar-link active" id="link-sec-1">1. Information We Collect</a></li>
                    <li><a href="#how-we-use" class="sidebar-link" id="link-sec-2">2. How We Use Data</a></li>
                    <li><a href="#security-storage" class="sidebar-link" id="link-sec-3">3. Security &amp; Storage</a></li>
                    <li><a href="#cookies-tech" class="sidebar-link" id="link-sec-4">4. Cookies &amp; Tracking</a></li>
                    <li><a href="#user-rights" class="sidebar-link" id="link-sec-5">5. Your Legal Rights</a></li>
                </ul>
            </aside>

            <!-- Policy Text -->
            <article class="legal-content" id="privacy-content-body">
                <p>Welcome to <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> (accessible via <strong><?=htmlspecialchars(site_domain())?></strong>). At <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> (operated by <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Technologies Limited), your privacy and data security are our highest priorities. This Privacy Policy describes how we collect, use, store, process, and protect your personal and financial information when you use our website, mobile application, APIs, and digital financial services.</p>
                <p>By accessing or using <?=htmlspecialchars(setting('site_name','Gracehubtels'))?>, you agree to the collection and use of your information in accordance with this Privacy Policy and the Nigeria Data Protection Regulation (NDPR) and other applicable international data protection standards.</p>

                <section id="info-we-collect">
                    <h2>1. Information We Collect</h2>
                    <p>To provide secure, licensed financial services, we must collect specific information. This includes:</p>
                    <ul>
                        <li><strong>Personal Identification Information:</strong> Full name, physical address, email address, phone number, date of birth, biometric verification data, Bank Verification Number (BVN), National Identification Number (NIN), and government-issued ID documents. (Note: We use your BVN solely for identity verification in line with Central Bank of Nigeria requirements).</li>
                        <li><strong>Financial and Transactional Data:</strong> Bank account details, debit/credit card numbers (stored via tokenized, PCI-DSS compliant secure vaults), transaction history, account balances, and merchant invoice data.</li>
                        <li><strong>Device and Technical Information:</strong> IP address, device type, operating system version, browser type, location coordinates, cookies, and mobile identifier tracking data.</li>
                        <li><strong>Communication Records:</strong> Records of interaction with customer support, survey responses, and feedback submissions.</li>
                    </ul>
                </section>

                <section id="how-we-use">
                    <h2>2. How We Use Your Data</h2>
                    <p>We process your personal information for legitimate business and regulatory purposes:</p>
                    <ul>
                        <li><strong>Identity Data for Account Issuance:</strong> Where a feature requires it — such as generating a dedicated virtual bank account — we collect and share identifying information (e.g. BVN/NIN) with our licensed banking and payment processing partners, who use it to meet their own Central Bank of Nigeria (CBN) Anti-Money Laundering (AML) and Counter-Terrorism Financing (CFT) obligations.</li>
                        <li><strong>Service Delivery:</strong> Processing incoming and outgoing payments, settling merchant accounts, generating digital invoices, and running our digital wallet operations.</li>
                        <li><strong>Fraud Prevention &amp; Security:</strong> Actively scanning transactions to detect and block fraudulent, abusive, or unauthorized activities.</li>
                        <li><strong>Product Optimization:</strong> Analyzing platform usage data to troubleshoot software bugs, improve visual UX, and design new financial features.</li>
                        <li><strong>Notifications &amp; Marketing:</strong> Sending transactional receipts, service updates, and optional marketing alerts. You can opt-out of marketing communications at any time.</li>
                    </ul>
                </section>

                <section id="security-storage">
                    <h2>3. Data Security &amp; Storage</h2>
                    <p>We employ military-grade security infrastructure to safeguard your information:</p>
                    <p><strong>Encryption:</strong> All data in transit is encrypted using Secure Socket Layer (SSL/TLS) protocols. Data at rest is secured via AES-256 standard encryption keys.</p>
                    <p><strong>PCI-DSS Compliance:</strong> Our processing architecture is fully compliant with the Payment Card Industry Data Security Standard (PCI-DSS). We do not store raw card PINs or CVVs on our servers.</p>
                    <p><strong>Physical &amp; Network Security:</strong> Data is stored in secure cloud environments featuring automated threat detection and restricted access protocols.</p>
                    <p><strong>Data Retention:</strong> We retain your information only as long as necessary to comply with Nigerian banking regulations and statutory limitation periods (typically 6-10 years post-account closure).</p>
                </section>

                <section id="cookies-tech">
                    <h2>4. Cookies and Tracking Technologies</h2>
                    <p>Cookies are tiny text files placed on your browser to improve site speed and usability.</p>
                    <p><strong>Essential Cookies:</strong> Required to maintain secure login sessions and active transaction flows.</p>
                    <p><strong>Analytical Cookies:</strong> Help us aggregate anonymized traffic patterns to optimize layout performance.</p>
                    <p><strong>Managing Cookies:</strong> You can disable cookies in your web browser settings. However, disabling essential cookies will restrict security processes and prevent you from transacting on <?=htmlspecialchars(site_domain())?>.</p>
                </section>

                <section id="user-rights">
                    <h2>5. User Rights</h2>
                    <p>Under the Nigeria Data Protection Regulation (NDPR), you have the following rights regarding your data:</p>
                    <ul>
                        <li><strong>Right to Access:</strong> You can request details of the personal data we hold about you.</li>
                        <li><strong>Right to Rectification:</strong> You can request instant corrections to inaccurate or incomplete information.</li>
                        <li><strong>Right to Erasure ("Right to be Forgotten"):</strong> You can request the deletion of your account and personal data, subject to regulatory retention laws.</li>
                        <li><strong>Right to Portability:</strong> You can request a structured, machine-readable export of your transactional history.</li>
                        <li><strong>Right to File a Complaint:</strong> You can report any data privacy concerns directly to the Nigeria Data Protection Commission (NDPC).</li>
                    </ul>
                    <p>If you wish to exercise any of these rights, please contact our Data Protection Officer at <strong><?=htmlspecialchars('dpo@'.site_domain())?></strong>.</p>
                </section>
            </article>
        </div>
    </main>

    <!-- ==========================================
       Footer Component
       ========================================== -->
    <footer class="footer" id="footer">
        <div class="container">
            <div class="footer-grid">
                <div class="footer-brand">
                    <a href="index.php" class="logo-link" style="margin-bottom: 20px;">
                        <img src="assets/IMG_3901.PNG" alt="<?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Footer Logo" class="logo-img" style="height: 36px;" id="logo-img-footer">
                        <span class="logo-text" style="color: var(--white);" id="logo-text-footer">Msj<span>Pay</span></span>
                    </a>
                    <p><?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Technologies Limited is a licensed financial services platform enabling secure and instant payment operations across Nigeria.</p>
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
                        <li><a href="mailto:<?=htmlspecialchars(setting('site_email','support@gracehubtels.com'))?>" class="footer-link"><?=htmlspecialchars(setting('site_email','support@gracehubtels.com'))?></a></li>
                        <li><a href="tel:+234014598890" class="footer-link">+234 (0) 1 459 8890</a></li>
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
                <p>&copy; 2026 <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Technologies Limited (RC: 1899021). All rights reserved.</p>
                <div class="footer-bottom-links">
                    <a href="privacy-policy.php" class="footer-bottom-link">Privacy Policy</a>
                    <a href="terms.php" class="footer-bottom-link">Terms of Service</a>
                </div>
            </div>
        </div>
    </footer>

    <!-- Script -->
    <script src="main.js"></script>
    <script>
        // Smooth highlighting for legal sidebar
        document.addEventListener('DOMContentLoaded', () => {
            const sections = document.querySelectorAll('.legal-content section');
            const sidebarLinks = document.querySelectorAll('.sidebar-link');
            
            window.addEventListener('scroll', () => {
                let current = '';
                sections.forEach(section => {
                    const sectionTop = section.offsetTop - 150;
                    if (window.scrollY >= sectionTop) {
                        current = section.getAttribute('id');
                    }
                });
                
                sidebarLinks.forEach(link => {
                    link.classList.remove('active');
                    if (link.getAttribute('href').slice(1) === current) {
                        link.classList.add('active');
                    }
                });
            });
        });
    </script>
</body>
</html>
