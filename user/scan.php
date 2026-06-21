<?php
require_once __DIR__ . '/core/auth.php';
auth_user();
?>
<?php $page_title = 'Scan � MsjPay'; include __DIR__ . '/includes/header.php'; include __DIR__ . '/includes/sidebar.php'; ?> <header class="page-header"> <a href="/msjpay/user/dashboard" class="back-btn"><svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg></a> <h1>Scan QR Code</h1> <div></div>
</header> <div class="page-body" style="align-items:center;justify-content:center;min-height:60vh"> <div style="text-align:center;padding:40px 20px"> <div style="font-size:64px;margin-bottom:16px">📷</div> <h2 style="font-size:20px;font-weight:700;margin-bottom:8px">QR Scanner</h2> <p style="font-size:14px;color:var(--text-muted);max-width:260px;margin:0 auto 24px"> Scan QR codes to instantly send money or confirm transactions </p> <div style="background:var(--bg);border:2px dashed var(--border);border-radius:20px;padding:32px;margin-bottom:24px"> <div style="width:200px;height:200px;background:linear-gradient(45deg₦rgba(249₦115₦22₦.1)₦rgba(249₦115₦22₦.05));border-radius:16px;margin:0 auto;display:flex;align-items:center;justify-content:center;font-size:48px"> 🔲 </div> </div> <p style="font-size:12px;color:var(--text-muted)">QR scanning coming soon in the MsjPay app</p> </div>
</div> <?php include __DIR__ . '/includes/footer.php'; ?>
