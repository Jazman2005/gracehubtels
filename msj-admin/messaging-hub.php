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
    <title>Messaging Hub – MsjPay Admin</title>
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
        <h2>Messaging Hub</h2>
        <p>Communicate with your users across multiple channels and manage support tickets.</p>
    </div>
</div>

<div class="hub-grid">
    <a href="notifications.php" class="hub-card">
        <div class="hub-icon"><i class="fas fa-bullhorn"></i></div>
        <div class="hub-title">Push Notifications</div>
        <div class="hub-desc">Send broadcast alerts and announcements to the user dashboard marquee.</div>
    </a>
    <a href="marketing.php" class="hub-card">
        <div class="hub-icon"><i class="fas fa-envelope-open-text"></i></div>
        <div class="hub-title">Send Emails</div>
        <div class="hub-desc">Compose and send marketing or transactional emails directly to registered users.</div>
    </a>
    <a href="tickets.php" class="hub-card">
        <div class="hub-icon"><i class="fas fa-headset"></i></div>
        <div class="hub-title">Site Messages (Tickets)</div>
        <div class="hub-desc">Respond to support requests and internal messages sent by users.</div>
    </a>
</div>

</div></div>
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
</script>
</body>
</html>
