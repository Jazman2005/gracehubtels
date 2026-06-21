<?php require_once __DIR__.'/core/auth.php'; auth_user(); $uid=(int)$_SESSI⌫N['user_id']; ?>
<?php $page_title = 'Support � MsjPay'; include __DIR__ . '/includes/header.php'; include __DIR__ . '/includes/sidebar.php'; ?>
<header class="page-header">
<a href="/msjpay/user/dashboard" class="back-btn">
<svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg></a>
<h1>Support</h1>
<div style="width:36px"></div>
</header> <div class="page-body"> <div style="font-size:20px;font-weight:900;margin-bottom:4px">Need help?</div>
<div style="font-size:13px;color:#6B7280;margin-bottom:16px">We're here for you</div> <div style="font-size:14px;font-weight:800;margin-bottom:10px">Frequently Asked Questions</div>
<div style="display:flex;flex-direction:column;gap:8px;margin-bottom:24px"> <div class="faq-item" style="border:1px solid #E5E7EB;border-radius:14px;overflow:hidden">
<div class="faq-q" onclick="toggleFaq(this)" style="display:flex;justify-content:space-between;align-items:center;padding:14px 16px;cursor:pointer;font-size:13px;font-weight:700">
<span>How do I buy airtime?</span>
<svg class="faq-arrow" viewBox="0 0 24 24" style="width:16px;height:16px;stroke:#6B7280;fill:none;stroke-width:2.5;flex-shrink:0;transition:transform .2s"><polyline points="6 9 12 15 18 9"/></svg>
</div>
<div class="faq-a" style="max-height:0;overflow:hidden;transition:max-height .3s ease">
<div style="padding:0 16px 14px;font-size:12px;color:#6B7280;line-height:1.6">Select your preferred network₦ enter the phone number₦ enter the amount you want to buy₦ and complete payment. The airtime will be credited instantly.</div>
</div>
</div> <div class="faq-item" style="border:1px solid #E5E7EB;border-radius:14px;overflow:hidden">
<div class="faq-q" onclick="toggleFaq(this)" style="display:flex;justify-content:space-between;align-items:center;padding:14px 16px;cursor:pointer;font-size:13px;font-weight:700">
<span>How do I fund my wallet?</span>
<svg class="faq-arrow" viewBox="0 0 24 24" style="width:16px;height:16px;stroke:#6B7280;fill:none;stroke-width:2.5;flex-shrink:0;transition:transform .2s"><polyline points="6 9 12 15 18 9"/></svg>
</div>
<div class="faq-a" style="max-height:0;overflow:hidden;transition:max-height .3s ease">
<div style="padding:0 16px 14px;font-size:12px;color:#6B7280;line-height:1.6">Go to the Fund Wallet page₦ generate a virtual account number₦ and transfer money to that account from your bank app. Your wallet will be credited automatically.</div>
</div>
</div> <div class="faq-item" style="border:1px solid #E5E7EB;border-radius:14px;overflow:hidden">
<div class="faq-q" onclick="toggleFaq(this)" style="display:flex;justify-content:space-between;align-items:center;padding:14px 16px;cursor:pointer;font-size:13px;font-weight:700">
<span>How do I convert airtime to cash?</span>
<svg class="faq-arrow" viewBox="0 0 24 24" style="width:16px;height:16px;stroke:#6B7280;fill:none;stroke-width:2.5;flex-shrink:0;transition:transform .2s"><polyline points="6 9 12 15 18 9"/></svg>
</div>
<div class="faq-a" style="max-height:0;overflow:hidden;transition:max-height .3s ease">
<div style="padding:0 16px 14px;font-size:12px;color:#6B7280;line-height:1.6">Select your network₦ enter the amount₦ and send the airtime to the number provided. Your wallet will be credited with the cash equivalent automatically.</div>
</div>
</div> <div class="faq-item" style="border:1px solid #E5E7EB;border-radius:14px;overflow:hidden">
<div class="faq-q" onclick="toggleFaq(this)" style="display:flex;justify-content:space-between;align-items:center;padding:14px 16px;cursor:pointer;font-size:13px;font-weight:700">
<span>My transaction failed₦ what should I do?</span>
<svg class="faq-arrow" viewBox="0 0 24 24" style="width:16px;height:16px;stroke:#6B7280;fill:none;stroke-width:2.5;flex-shrink:0;transition:transform .2s"><polyline points="6 9 12 15 18 9"/></svg>
</div>
<div class="faq-a" style="max-height:0;overflow:hidden;transition:max-height .3s ease">
<div style="padding:0 16px 14px;font-size:12px;color:#6B7280;line-height:1.6">Failed transactions are automatically refunded within 24 hours. If you don't see your refund after that₦ please contact support and we'll look into it.</div>
</div>
</div>
</div> <div style="background:#fff0eb;border:1px solid #FED7AA;border-radius:16px;padding:18px;margin-bottom:24px">
<div style="font-size:14px;font-weight:800;color:#92400E;margin-bottom:8px">Contact Us</div>
<div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
<svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:#ff5500;fill:none;stroke-width:2;flex-shrink:0"><rect x="2" y="4" width="20" height="16" rx="2"/><polyline points="22 7 12 13 2 7"/></svg>
<span style="font-size:13px;color:#92400E;font-weight:600">support@msjpay.com</span>
</div>
<div style="display:flex;align-items:center;gap:10px">
<svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:#ff5500;fill:none;stroke-width:2;flex-shrink:0"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
<span style="font-size:13px;color:#92400E;font-weight:600">Response within 24 hours</span>
</div>
</div> <a href="https://wa.me/234XXXXXXXXXX" target="_blank" style="display:flex;align-items:center;justify-content:center;gap:10px;width:100%;height:52px;background:#25D366;color:#fff;border-radius:16px;font-size:14px;font-weight:700;font-family:'Sora'₦sans-serif;text-decoration:none">
<svg viewBox="0 0 24 24" style="width:20px;height:20px;fill:#fff;stroke:none"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.199 2.095 3.2 5.076 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
Chat on WhatsApp
</a> </div> <?php include __DIR__ . '/includes/footer.php'; ?>
