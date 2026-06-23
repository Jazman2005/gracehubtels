<?php
require_once __DIR__ . '/core/auth.php';
auth_user();
$page_title = 'Scan QR Code'; 
include __DIR__ . '/includes/header.php'; 
include __DIR__ . '/includes/sidebar.php'; 
?>

<div class="max-w-md mx-auto min-h-[70vh] flex flex-col pt-4">
    <!-- Header -->
    <div class="flex items-center justify-between mb-8">
        <div class="flex items-center gap-4">
            <a href="/msjpay/user/dashboard" class="w-10 h-10 rounded-full bg-white border border-slate-200 flex items-center justify-center text-slate-600 hover:bg-slate-50 transition-colors">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-slate-800">Scan QR Code</h1>
            </div>
        </div>
    </div>

    <!-- Scan Area -->
    <div class="flex-1 flex flex-col items-center justify-center">
        <div class="text-center mb-6">
            <div class="w-20 h-20 bg-brand-orangeLight text-brand-orange rounded-full flex items-center justify-center text-4xl mx-auto mb-4">
                <i class="fas fa-qrcode"></i>
            </div>
            <h2 class="text-xl font-bold text-slate-800 mb-2">QR Scanner</h2>
            <p class="text-sm text-slate-500 max-w-xs mx-auto">Scan QR codes to instantly send money or confirm transactions</p>
        </div>
        
        <div class="bg-white p-8 rounded-3xl border-2 border-dashed border-slate-300 w-full max-w-sm card-shadow relative overflow-hidden mb-8">
            <!-- Animated scan line -->
            <div class="absolute left-0 right-0 h-1 bg-brand-orange/50 shadow-[0_0_15px_3px_rgba(255,85,0,0.5)] animate-[scan_2s_ease-in-out_infinite]"></div>
            
            <div class="aspect-square bg-slate-50 rounded-2xl flex items-center justify-center relative border border-slate-200">
                <!-- Corner markers -->
                <div class="absolute top-4 left-4 w-6 h-6 border-t-4 border-l-4 border-brand-orange rounded-tl-lg"></div>
                <div class="absolute top-4 right-4 w-6 h-6 border-t-4 border-r-4 border-brand-orange rounded-tr-lg"></div>
                <div class="absolute bottom-4 left-4 w-6 h-6 border-b-4 border-l-4 border-brand-orange rounded-bl-lg"></div>
                <div class="absolute bottom-4 right-4 w-6 h-6 border-b-4 border-r-4 border-brand-orange rounded-br-lg"></div>
                
                <div class="text-slate-300">
                    <i class="fas fa-camera text-5xl"></i>
                </div>
            </div>
        </div>
        
        <div class="bg-blue-50 text-blue-600 px-4 py-2 rounded-xl text-sm font-semibold flex items-center gap-2 border border-blue-200">
            <i class="fas fa-info-circle"></i> QR scanning coming soon
        </div>
    </div>
</div>

<style>
@keyframes scan {
    0% { top: 10%; }
    50% { top: 90%; }
    100% { top: 10%; }
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>