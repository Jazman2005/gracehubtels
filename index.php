<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <!-- Primary SEO Meta Tags -->
    <title>Gracehubtels | Seamless Payments & Instant Settlements in Nigeria</title>
    <meta name="description" content="Gracehubtels is a secure, all-in-one payment gateway and digital wallet built for Nigerian businesses, freelancers, and individuals. Accept payments, send instant bank transfers, and pay utility bills at zero cost.">
    <meta name="keywords" content="Gracehubtels, payment gateway Nigeria, digital wallet Lagos, accept card payments, instant settlement, pay bills Nigeria, secure transactions, BVN verification, fintech Nigeria">
    
    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://gracehubtels.com/">
    <meta property="og:title" content="Gracehubtels | Seamless Payments & Instant Settlements in Nigeria">
    <meta property="og:description" content="Accept card deposits, send instant bank transfers, and pay bills at zero cost with Gracehubtels. The smarter payment gateway for Nigeria.">
    <meta property="og:image" content="assets/IMG_3901.PNG">
    <link rel="icon" type="image/png" href="assets/images/gracehubtels-favicon.png">

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Tailwind CSS Engine -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        heading: ['Outfit', 'sans-serif'],
                        sans: ['Inter', 'sans-serif'],
                    },
                    colors: {
                        brand: {
                            primary: '#2563EB',      // Royal Blue
                            primaryDark: '#1D4ED8',  // Indigo
                            primaryLight: '#EFF6FF', 
                            accent: '#EC4899',       // Pink
                            accentDark: '#DB2777',
                            gold: '#F59E0B',         // Premium Gold
                            goldHover: '#D97706',
                            navy: '#1E293B',         // Slate 800
                            navyLight: '#334155'
                        }
                    },
                    animation: {
                        'float': 'float 6s ease-in-out infinite',
                        'float-delayed': 'float 6s ease-in-out 3s infinite',
                        'marquee': 'marquee 25s linear infinite',
                    },
                    keyframes: {
                        float: {
                            '0%, 100%': { transform: 'translateY(0)' },
                            '50%': { transform: 'translateY(-20px)' },
                        },
                        marquee: {
                            '0%': { transform: 'translateX(0%)' },
                            '100%': { transform: 'translateX(-100%)' },
                        }
                    }
                }
            }
        }
    </script>
    <style>
        .glass-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        .text-gradient {
            background: linear-gradient(135deg, #2563EB, #EC4899);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .hero-bg {
            background-color: #f8fafc;
            background-image: 
                radial-gradient(at 0% 0%, hsla(217,100%,70%,0.15) 0px, transparent 50%),
                radial-gradient(at 100% 0%, hsla(330,81%,70%,0.15) 0px, transparent 50%);
        }
    </style>
</head>
<body class="font-sans text-slate-600 bg-slate-50 antialiased overflow-x-hidden">

    <!-- Navbar -->
    <nav class="fixed w-full z-50 transition-all duration-300" id="navbar">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-20">
                <!-- Logo -->
                <a href="index.php" class="flex items-center gap-2 group">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-brand-primary to-brand-primaryDark flex items-center justify-center text-white font-bold text-xl shadow-lg shadow-blue-500/30 group-hover:scale-105 transition-transform">
                        <img src="assets/IMG_3899.PNG" alt="Gracehubtels Logo" class="w-full h-full object-contain p-1" onerror="this.style.display='none'; this.parentNode.innerHTML='G'">
                    </div>
                    <span class="font-heading font-bold text-xl text-brand-navy tracking-tight">Gracehub<span class="text-brand-primary">tels</span></span>
                </a>

                <!-- Desktop Menu -->
                <div class="hidden md:flex items-center space-x-8">
                    <a href="#features" class="font-medium text-slate-600 hover:text-brand-primary transition-colors">Features</a>
                    <a href="#services" class="font-medium text-slate-600 hover:text-brand-primary transition-colors">Services</a>
                    <a href="#how-it-works" class="font-medium text-slate-600 hover:text-brand-primary transition-colors">How it Works</a>
                </div>

                <!-- Auth Buttons -->
                <div class="hidden md:flex items-center space-x-4">
                    <a href="user/login" class="font-medium text-brand-navy hover:text-brand-primary transition-colors">Sign In</a>
                    <a href="user/signup" class="bg-brand-navy hover:bg-slate-800 text-white px-6 py-2.5 rounded-full font-medium transition-all hover:shadow-lg hover:-translate-y-0.5">Get Started</a>
                </div>

                <!-- Mobile menu button -->
                <div class="md:hidden flex items-center">
                    <button id="mobile-menu-btn" class="text-brand-navy hover:text-brand-primary focus:outline-none">
                        <i class="fas fa-bars text-2xl"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Mobile Menu Panel -->
        <div id="mobile-menu" class="hidden md:hidden bg-white border-b border-slate-100 shadow-xl absolute w-full">
            <div class="px-4 pt-2 pb-6 space-y-2">
                <a href="#features" class="block px-3 py-3 font-medium text-slate-700 hover:text-brand-primary hover:bg-slate-50 rounded-lg">Features</a>
                <a href="#services" class="block px-3 py-3 font-medium text-slate-700 hover:text-brand-primary hover:bg-slate-50 rounded-lg">Services</a>
                <a href="#how-it-works" class="block px-3 py-3 font-medium text-slate-700 hover:text-brand-primary hover:bg-slate-50 rounded-lg">How it Works</a>
                <div class="border-t border-slate-100 pt-4 mt-2 flex flex-col gap-3">
                    <a href="user/login" class="block text-center px-3 py-3 font-medium text-brand-navy border border-slate-200 rounded-lg">Sign In</a>
                    <a href="user/signup" class="block text-center px-3 py-3 font-medium text-white bg-brand-primary rounded-lg shadow-md shadow-blue-500/20">Get Started</a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="relative pt-32 pb-20 lg:pt-48 lg:pb-32 overflow-hidden hero-bg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="grid lg:grid-cols-2 gap-12 lg:gap-8 items-center">
                <!-- Hero Content -->
                <div class="max-w-2xl text-center lg:text-left mx-auto lg:mx-0">
                    <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-white border border-slate-200 shadow-sm mb-6">
                        <span class="flex h-2 w-2 rounded-full bg-brand-gold animate-pulse"></span>
                        <span class="text-xs font-semibold uppercase tracking-wider text-slate-600">Secure & Borderless Fintech</span>
                    </div>
                    <h1 class="font-heading text-4xl sm:text-5xl lg:text-6xl font-extrabold text-brand-navy leading-[1.15] mb-6 tracking-tight">
                        Seamless Payments,<br>
                        <span class="text-gradient">Instant Settlements.</span>
                    </h1>
                    <p class="text-lg text-slate-600 mb-8 max-w-xl mx-auto lg:mx-0 leading-relaxed">
                        The all-in-one digital wallet and payment gateway designed for Nigerian businesses and individuals. Pay bills, send money, and accept cards securely with zero hassle.
                    </p>
                    <div class="flex flex-col sm:flex-row gap-4 justify-center lg:justify-start">
                        <a href="user/signup" class="bg-brand-primary hover:bg-brand-primaryDark text-white px-8 py-3.5 rounded-full font-semibold text-lg transition-all hover:shadow-xl hover:shadow-blue-500/30 flex items-center justify-center gap-2 hover:-translate-y-1">
                            Create Free Account <i class="fas fa-arrow-right text-sm"></i>
                        </a>
                        <a href="user/login" class="bg-white hover:bg-slate-50 text-brand-navy border border-slate-200 px-8 py-3.5 rounded-full font-semibold text-lg transition-all hover:shadow-md flex items-center justify-center">
                            Sign In
                        </a>
                    </div>
                    <div class="mt-10 flex items-center justify-center lg:justify-start gap-6 text-sm font-medium text-slate-500">
                        <div class="flex items-center gap-2"><i class="fas fa-check-circle text-brand-gold"></i> CBN Licensed</div>
                        <div class="flex items-center gap-2"><i class="fas fa-shield-alt text-brand-gold"></i> 256-bit Secure</div>
                    </div>
                </div>

                <!-- Hero Visual / Mockups -->
                <div class="relative lg:h-[600px] flex items-center justify-center hidden sm:flex">
                    <!-- Main Dashboard Card -->
                    <div class="relative w-full max-w-sm glass-card rounded-3xl p-6 shadow-2xl z-20 animate-float border-t border-white/50">
                        <div class="flex justify-between items-center mb-8">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-brand-accent to-brand-accentDark text-white flex items-center justify-center font-bold shadow-md">CO</div>
                                <div>
                                    <div class="font-bold text-brand-navy leading-tight">Chidi Okafor</div>
                                    <div class="text-[10px] text-slate-400 font-medium uppercase tracking-wider">Personal Account</div>
                                </div>
                            </div>
                            <button class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-500"><i class="fas fa-bell text-xs"></i></button>
                        </div>
                        
                        <div class="bg-gradient-to-br from-brand-primary to-brand-primaryDark rounded-2xl p-5 text-white shadow-lg shadow-blue-500/25 mb-6 relative overflow-hidden">
                            <div class="absolute -right-6 -top-6 w-24 h-24 bg-white/10 rounded-full blur-xl"></div>
                            <div class="text-xs font-medium text-blue-100 mb-1 opacity-90">Available Balance</div>
                            <div class="text-3xl font-heading font-bold mb-4 tracking-tight">₦450,250<span class="text-blue-300 text-lg">.00</span></div>
                            <div class="flex justify-between items-center text-sm">
                                <span class="font-mono opacity-80 tracking-widest">**** 8890</span>
                                <i class="fab fa-cc-visa text-2xl opacity-90"></i>
                            </div>
                        </div>

                        <div>
                            <div class="font-bold text-brand-navy mb-4 text-sm uppercase tracking-wider">Recent Activity</div>
                            <div class="space-y-4">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-full bg-blue-50 text-brand-primary flex items-center justify-center"><i class="fas fa-arrow-down"></i></div>
                                        <div>
                                            <div class="font-semibold text-brand-navy text-sm">Wallet Funded</div>
                                            <div class="text-xs text-slate-500">Today, 2:40 PM</div>
                                        </div>
                                    </div>
                                    <div class="font-bold text-emerald-500 text-sm">+ ₦50,000</div>
                                </div>
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-full bg-pink-50 text-brand-accent flex items-center justify-center"><i class="fas fa-paper-plane"></i></div>
                                        <div>
                                            <div class="font-semibold text-brand-navy text-sm">Providus Bank</div>
                                            <div class="text-xs text-slate-500">Yesterday</div>
                                        </div>
                                    </div>
                                    <div class="font-bold text-slate-700 text-sm">- ₦12,500</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Floating Elements -->
                    <div class="absolute top-10 right-0 glass-card p-4 rounded-2xl shadow-xl z-30 animate-float-delayed flex items-center gap-3 border-t border-white/50">
                        <div class="w-10 h-10 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center"><i class="fas fa-check"></i></div>
                        <div>
                            <div class="font-bold text-brand-navy text-sm">Payment Secured</div>
                            <div class="text-xs text-slate-500">Settled in 0.4s</div>
                        </div>
                    </div>
                    
                    <div class="absolute bottom-20 -left-10 glass-card p-4 rounded-2xl shadow-xl z-30 animate-float flex items-center gap-3 border-t border-white/50" style="animation-duration: 7s;">
                        <div class="w-10 h-10 rounded-full bg-brand-gold/20 text-brand-goldHover flex items-center justify-center"><i class="fas fa-bolt"></i></div>
                        <div>
                            <div class="font-bold text-brand-navy text-sm">Bill Paid</div>
                            <div class="text-xs text-slate-500">Zero Fees Applied</div>
                        </div>
                    </div>
                    
                    <!-- Decorative blur -->
                    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[120%] h-[120%] bg-gradient-to-tr from-brand-primary/10 to-brand-accent/10 blur-3xl rounded-full z-0"></div>
                </div>
            </div>
        </div>
    </section>

    <!-- Trusted Partners (Marquee) -->
    <div class="border-y border-slate-200 bg-white py-8 overflow-hidden relative">
        <div class="text-center text-xs font-bold text-slate-400 uppercase tracking-widest mb-6">Supported Integrations & Clearing Partners</div>
        <div class="flex overflow-hidden relative w-full">
            <div class="flex whitespace-nowrap animate-marquee items-center gap-16 px-8">
                <!-- Set 1 -->
                <div class="text-xl font-heading font-extrabold text-slate-300">PROVIDUS<span class="text-slate-800">BANK</span></div>
                <div class="text-xl font-heading font-extrabold text-slate-300">WEMA<span class="text-slate-800">BANK</span></div>
                <div class="text-xl font-heading font-extrabold text-slate-300">INTER<span class="text-slate-800">SWITCH</span></div>
                <div class="text-xl font-heading font-extrabold text-slate-300">PAY<span class="text-slate-800">STACK</span></div>
                <div class="text-xl font-heading font-extrabold text-slate-300">FLUTTER<span class="text-slate-800">WAVE</span></div>
                <!-- Set 2 (for continuous scroll) -->
                <div class="text-xl font-heading font-extrabold text-slate-300">PROVIDUS<span class="text-slate-800">BANK</span></div>
                <div class="text-xl font-heading font-extrabold text-slate-300">WEMA<span class="text-slate-800">BANK</span></div>
                <div class="text-xl font-heading font-extrabold text-slate-300">INTER<span class="text-slate-800">SWITCH</span></div>
                <div class="text-xl font-heading font-extrabold text-slate-300">PAY<span class="text-slate-800">STACK</span></div>
                <div class="text-xl font-heading font-extrabold text-slate-300">FLUTTER<span class="text-slate-800">WAVE</span></div>
            </div>
        </div>
    </div>

    <!-- Quick Services Grid -->
    <section id="services" class="py-24 bg-slate-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-3xl mx-auto mb-16">
                <h2 class="font-heading text-3xl md:text-4xl font-bold text-brand-navy mb-4">Everything you need, in one place.</h2>
                <p class="text-lg text-slate-600">From everyday bills to business transfers, experience a comprehensive suite of financial services designed for speed and reliability.</p>
            </div>
            
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 sm:gap-6">
                <!-- Service Card -->
                <div class="bg-white rounded-2xl p-6 text-center border border-slate-100 shadow-sm hover:shadow-xl hover:border-pink-200 transition-all duration-300 hover:-translate-y-1 group">
                    <div class="w-14 h-14 mx-auto rounded-full bg-pink-50 text-brand-accent flex items-center justify-center text-2xl mb-4 group-hover:scale-110 transition-transform">
                        <i class="fas fa-mobile-alt"></i>
                    </div>
                    <h3 class="font-bold text-brand-navy mb-1">Buy Airtime</h3>
                    <p class="text-xs text-slate-500">Instant recharge across all networks.</p>
                </div>
                <!-- Service Card -->
                <div class="bg-white rounded-2xl p-6 text-center border border-slate-100 shadow-sm hover:shadow-xl hover:border-blue-200 transition-all duration-300 hover:-translate-y-1 group">
                    <div class="w-14 h-14 mx-auto rounded-full bg-blue-50 text-brand-primary flex items-center justify-center text-2xl mb-4 group-hover:scale-110 transition-transform">
                        <i class="fas fa-wifi"></i>
                    </div>
                    <h3 class="font-bold text-brand-navy mb-1">Data Bundles</h3>
                    <p class="text-xs text-slate-500">Cheap and reliable internet data.</p>
                </div>
                <!-- Service Card -->
                <div class="bg-white rounded-2xl p-6 text-center border border-slate-100 shadow-sm hover:shadow-xl hover:border-orange-200 transition-all duration-300 hover:-translate-y-1 group">
                    <div class="w-14 h-14 mx-auto rounded-full bg-orange-50 text-orange-500 flex items-center justify-center text-2xl mb-4 group-hover:scale-110 transition-transform">
                        <i class="fas fa-tv"></i>
                    </div>
                    <h3 class="font-bold text-brand-navy mb-1">Cable TV</h3>
                    <p class="text-xs text-slate-500">DSTV, GOTV & Startimes subs.</p>
                </div>
                <!-- Service Card -->
                <div class="bg-white rounded-2xl p-6 text-center border border-slate-100 shadow-sm hover:shadow-xl hover:border-purple-200 transition-all duration-300 hover:-translate-y-1 group">
                    <div class="w-14 h-14 mx-auto rounded-full bg-purple-50 text-purple-600 flex items-center justify-center text-2xl mb-4 group-hover:scale-110 transition-transform">
                        <i class="fas fa-bolt"></i>
                    </div>
                    <h3 class="font-bold text-brand-navy mb-1">Electricity</h3>
                    <p class="text-xs text-slate-500">Zero fee prepaid meter tokens.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="py-24 bg-white border-t border-slate-100">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid lg:grid-cols-2 gap-16 items-center">
                <div>
                    <h2 class="font-heading text-3xl md:text-4xl font-bold text-brand-navy mb-6">Built for modern commercial growth</h2>
                    <p class="text-lg text-slate-600 mb-8">We build enterprise-grade payment solutions that give you the security, routing speed, and reliability your finances deserve.</p>
                    
                    <div class="space-y-8">
                        <div class="flex gap-4">
                            <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-blue-50 text-brand-primary flex items-center justify-center text-xl">
                                <i class="fas fa-shield-alt"></i>
                            </div>
                            <div>
                                <h4 class="font-bold text-brand-navy text-lg mb-2">Bank-Grade Security</h4>
                                <p class="text-slate-600">Your funds and private details are guarded by multi-level 256-bit SSL encryption, mandatory 2FA, and AI fraud monitoring.</p>
                            </div>
                        </div>
                        <div class="flex gap-4">
                            <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-amber-50 text-brand-gold flex items-center justify-center text-xl">
                                <i class="fas fa-bolt"></i>
                            </div>
                            <div>
                                <h4 class="font-bold text-brand-navy text-lg mb-2">Instant Settlements</h4>
                                <p class="text-slate-600">Keep your cash flow fluid. Gracehubtels routes and settles your transaction payouts directly to any Nigerian bank account instantly.</p>
                            </div>
                        </div>
                        <div class="flex gap-4">
                            <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-pink-50 text-brand-accent flex items-center justify-center text-xl">
                                <i class="fas fa-headset"></i>
                            </div>
                            <div>
                                <h4 class="font-bold text-brand-navy text-lg mb-2">24/7 Dedicated Support</h4>
                                <p class="text-slate-600">Talk to real financial specialists. Our customer success team is available 24/7/365 to keep your operations running.</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="relative">
                    <div class="absolute inset-0 bg-gradient-to-tr from-brand-primary/10 to-brand-accent/10 rounded-3xl transform rotate-3 scale-105"></div>
                    <img src="https://images.unsplash.com/photo-1556742049-0cfed4f6a45d?auto=format&fit=crop&q=80&w=800" alt="Team working" class="relative rounded-3xl shadow-2xl object-cover h-[500px] w-full">
                    
                    <!-- Floating stat -->
                    <div class="absolute -bottom-6 -left-6 glass-card p-5 rounded-2xl shadow-xl flex items-center gap-4">
                        <div class="w-12 h-12 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-xl"><i class="fas fa-chart-line"></i></div>
                        <div>
                            <div class="text-2xl font-bold text-brand-navy">99.99%</div>
                            <div class="text-sm text-slate-500 font-medium">Uptime Guarantee</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works -->
    <section id="how-it-works" class="py-24 bg-slate-900 text-white relative overflow-hidden">
        <!-- Abstract bg -->
        <div class="absolute top-0 right-0 w-[800px] h-[800px] bg-brand-primary/20 blur-[120px] rounded-full translate-x-1/3 -translate-y-1/3 pointer-events-none"></div>
        
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="text-center max-w-3xl mx-auto mb-16">
                <h2 class="font-heading text-3xl md:text-4xl font-bold mb-4">Get Started in 3 Simple Steps</h2>
                <p class="text-lg text-slate-400">No paperwork, no branch visits. Our onboarding is designed to get you processing live payments in under 10 minutes.</p>
            </div>
            
            <div class="grid md:grid-cols-3 gap-8">
                <!-- Step 1 -->
                <div class="bg-white/5 border border-white/10 rounded-2xl p-8 hover:bg-white/10 transition-colors">
                    <div class="w-14 h-14 rounded-full bg-brand-primary/20 text-brand-primaryLight flex items-center justify-center text-2xl font-bold mb-6 font-heading">1</div>
                    <h3 class="font-bold text-xl mb-3">Create an Account</h3>
                    <p class="text-slate-400 leading-relaxed">Sign up on gracehubtels.com in minutes. Verify your identity seamlessly with your BVN/NIN for total security.</p>
                </div>
                <!-- Step 2 -->
                <div class="bg-white/5 border border-white/10 rounded-2xl p-8 hover:bg-white/10 transition-colors">
                    <div class="w-14 h-14 rounded-full bg-brand-gold/20 text-brand-gold flex items-center justify-center text-2xl font-bold mb-6 font-heading">2</div>
                    <h3 class="font-bold text-xl mb-3">Fund Your Wallet</h3>
                    <p class="text-slate-400 leading-relaxed">Instantly deposit funds into your secure wallet using bank transfers, USSD, or debit cards with zero hidden fees.</p>
                </div>
                <!-- Step 3 -->
                <div class="bg-white/5 border border-white/10 rounded-2xl p-8 hover:bg-white/10 transition-colors">
                    <div class="w-14 h-14 rounded-full bg-brand-accent/20 text-pink-300 flex items-center justify-center text-2xl font-bold mb-6 font-heading">3</div>
                    <h3 class="font-bold text-xl mb-3">Transact & Scale</h3>
                    <p class="text-slate-400 leading-relaxed">Send money, pay bills, buy airtime, or integrate our API to accept payments and watch your business thrive.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Testimonials -->
    <section class="py-24 bg-slate-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-3xl mx-auto mb-16">
                <h2 class="font-heading text-3xl md:text-4xl font-bold text-brand-navy mb-4">Loved by Thousands across Nigeria</h2>
            </div>
            
            <div class="max-w-4xl mx-auto">
                <div class="bg-white rounded-3xl p-8 md:p-12 shadow-xl shadow-slate-200/50 border border-slate-100 text-center relative">
                    <i class="fas fa-quote-left text-4xl text-brand-primary/20 absolute top-8 left-8"></i>
                    <p class="text-xl md:text-2xl text-slate-700 font-medium leading-relaxed mb-8 relative z-10">
                        "Integrating Gracehubtels took our sales to the next level. Settlements are actually instant, and checkout drop-offs are a thing of the past! Highly recommended for any serious business."
                    </p>
                    <div class="flex items-center justify-center gap-4">
                        <div class="w-14 h-14 rounded-full bg-gradient-to-br from-brand-gold to-brand-goldHover text-white flex items-center justify-center font-bold text-lg shadow-md">AO</div>
                        <div class="text-left">
                            <div class="font-bold text-brand-navy text-lg">Amara Okafor</div>
                            <div class="text-sm text-slate-500">CEO, ShopVibe Nigeria</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="py-20 relative overflow-hidden">
        <div class="absolute inset-0 bg-gradient-to-r from-brand-primary to-brand-primaryDark"></div>
        <!-- Decorative abstract -->
        <div class="absolute -top-24 -right-24 w-96 h-96 bg-white/10 blur-3xl rounded-full"></div>
        <div class="absolute -bottom-24 -left-24 w-96 h-96 bg-brand-accent/20 blur-3xl rounded-full"></div>
        
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10 text-center">
            <h2 class="font-heading text-3xl md:text-5xl font-bold text-white mb-6">Ready to experience smooth payments?</h2>
            <p class="text-xl text-blue-100 mb-10 max-w-2xl mx-auto">Join thousands of Nigerian companies and smart savers today. Set up your wallet in minutes and start transacting with peace of mind.</p>
            <a href="user/signup" class="inline-block bg-white text-brand-primary px-8 py-4 rounded-full font-bold text-lg transition-all hover:shadow-2xl hover:scale-105 shadow-blue-900/50">
                Get Started For Free
            </a>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-slate-900 text-slate-400 py-16 border-t border-slate-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid md:grid-cols-4 gap-12 mb-12 border-b border-slate-800 pb-12">
                <div class="md:col-span-2">
                    <a href="index.php" class="flex items-center gap-2 mb-6">
                        <img src="assets/IMG_3901.PNG" alt="Gracehubtels Footer Logo" class="h-8" onerror="this.style.display='none'">
                        <span class="font-heading font-bold text-xl text-white tracking-tight">Gracehub<span class="text-brand-primary">tels</span></span>
                    </a>
                    <p class="text-slate-400 leading-relaxed max-w-md">
                        Gracehubtels Technologies Limited is a licensed financial services platform enabling secure and instant payment operations across Nigeria.
                    </p>
                </div>
                <div>
                    <h4 class="text-white font-bold mb-6">Company</h4>
                    <ul class="space-y-4">
                        <li><a href="contact.php" class="hover:text-white transition-colors">Contact Us</a></li>
                        <li><a href="terms.php" class="hover:text-white transition-colors">Terms of Service</a></li>
                        <li><a href="privacy-policy.php" class="hover:text-white transition-colors">Privacy Policy</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-white font-bold mb-6">Connect</h4>
                    <div class="flex gap-4">
                        <a href="#" class="w-10 h-10 rounded-full bg-slate-800 hover:bg-brand-primary flex items-center justify-center text-white transition-colors"><i class="fab fa-twitter"></i></a>
                        <a href="#" class="w-10 h-10 rounded-full bg-slate-800 hover:bg-brand-primary flex items-center justify-center text-white transition-colors"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" class="w-10 h-10 rounded-full bg-slate-800 hover:bg-brand-accent flex items-center justify-center text-white transition-colors"><i class="fab fa-instagram"></i></a>
                    </div>
                </div>
            </div>
            <div class="text-sm text-slate-500 flex flex-col md:flex-row justify-between items-center gap-4">
                <p>&copy; 2026 Gracehubtels. All rights reserved.</p>
                <p>Registered in Nigeria.</p>
            </div>
        </div>
    </footer>

    <!-- Mobile Menu Script -->
    <script>
        document.getElementById('mobile-menu-btn').addEventListener('click', function() {
            const menu = document.getElementById('mobile-menu');
            if(menu.classList.contains('hidden')) {
                menu.classList.remove('hidden');
            } else {
                menu.classList.add('hidden');
            }
        });

        // Navbar scroll effect
        window.addEventListener('scroll', function() {
            const navbar = document.getElementById('navbar');
            if (window.scrollY > 20) {
                navbar.classList.add('bg-white/90', 'backdrop-blur-md', 'shadow-sm');
                navbar.classList.remove('bg-transparent');
            } else {
                navbar.classList.add('bg-transparent');
                navbar.classList.remove('bg-white/90', 'backdrop-blur-md', 'shadow-sm');
            }
        });
    </script>
</body>
</html>
