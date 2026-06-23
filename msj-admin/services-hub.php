<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
$csrf=admin_csrf();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Services Hub – MsjPay Admin</title>
    <link rel="icon" type="image/png" href="/msjpay/assets/images/msjpay-favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/msjpay/msj-admin/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .hub-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(250px, 1fr)); gap:24px; margin-top:24px; }
        .hub-card { background:#fff; border-radius:16px; padding:24px; border:1px solid #E2E8F0; text-decoration:none; color:inherit; display:flex; flex-direction:column; align-items:flex-start; transition:all 0.3s ease; box-shadow:0 2px 10px rgba(0,0,0,0.02); cursor:pointer; }
        .hub-card:hover { transform:translateY(-4px); box-shadow:0 10px 25px rgba(0,0,0,0.08); border-color:var(--primary); }
        .hub-icon { width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:20px; margin-bottom:16px; background:rgba(255,90,31,0.1); color:var(--primary); }
        .hub-title { font-weight:700; font-size:16px; color:var(--navy-dark); margin-bottom:8px; }
        .hub-desc { font-size:13px; color:var(--text-muted); line-height:1.5; }
    </style>
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="page-content">

<div class="page-head">
    <div>
        <h2>Services Hub</h2>
        <p>Manage all VTU services, pricing, and availability controlling the user dashboard.</p>
    </div>
</div>

<div class="hub-grid">
    <a href="services-control.php" class="hub-card" onclick="sessionStorage.setItem('serviceTab','data')">
        <div class="hub-icon"><i class="fas fa-wifi"></i></div>
        <div class="hub-title">Data Plans</div>
        <div class="hub-desc">Configure data bundles, sizes, validity, and pricing across all networks.</div>
    </a>
    <a href="services-control.php" class="hub-card" onclick="sessionStorage.setItem('serviceTab','pricing')">
        <div class="hub-icon"><i class="fas fa-phone-alt"></i></div>
        <div class="hub-title">Airtime & VTU</div>
        <div class="hub-desc">Set airtime discount percentages and base costs for all networks.</div>
    </a>
    <a href="services-control.php" class="hub-card" onclick="sessionStorage.setItem('serviceTab','cable')">
        <div class="hub-icon"><i class="fas fa-tv"></i></div>
        <div class="hub-title">Cable TV</div>
        <div class="hub-desc">Manage DSTV, GOTV, and Startimes bouquets and pricing rules.</div>
    </a>
    <a href="services-control.php" class="hub-card" onclick="sessionStorage.setItem('serviceTab','disco')">
        <div class="hub-icon"><i class="fas fa-lightbulb"></i></div>
        <div class="hub-title">Electricity (Discos)</div>
        <div class="hub-desc">Set service charges and manage available electricity providers.</div>
    </a>
    <a href="services-control.php" class="hub-card" onclick="sessionStorage.setItem('serviceTab','pricing')">
        <div class="hub-icon"><i class="fas fa-exchange-alt"></i></div>
        <div class="hub-title">Airtime 2 Cash</div>
        <div class="hub-desc">Configure A2C conversion rates and manage service availability.</div>
    </a>
    <a href="services-control.php" class="hub-card" onclick="sessionStorage.setItem('serviceTab','pricing')">
        <div class="hub-icon"><i class="fas fa-graduation-cap"></i></div>
        <div class="hub-title">Exam PINs</div>
        <div class="hub-desc">Configure pricing for WAEC, NECO, NABTEB result checking PINs.</div>
    </a>
    <a href="services-control.php" class="hub-card" onclick="sessionStorage.setItem('serviceTab','pricing')">
        <div class="hub-icon"><i class="fas fa-barcode"></i></div>
        <div class="hub-title">Recharge Cards</div>
        <div class="hub-desc">Manage pricing and discounts for bulk recharge card generation.</div>
    </a>
    <a href="services-control.php" class="hub-card" onclick="sessionStorage.setItem('serviceTab','pricing')">
        <div class="hub-icon"><i class="fas fa-envelope"></i></div>
        <div class="hub-title">Bulk SMS</div>
        <div class="hub-desc">Set the per-page rate for users sending outbound SMS messages.</div>
    </a>
</div>

</div></div>
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
</script>
</body>
</html>
