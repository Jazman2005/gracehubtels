<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_admin('admin');
require_feature_admin('admin_accounting');

$tot_wallet = (float)db()->query("SELECT SUM(wallet_balance) FROM users")->fetchColumn() ?: 0;
$tot_cashback = (float)db()->query("SELECT SUM(cashback_balance) FROM users")->fetchColumn() ?: 0;
$total_liability = $tot_wallet + $tot_cashback;

$tot_funding = (float)db()->query("SELECT SUM(amount) FROM transactions WHERE category='funding' AND status='success'")->fetchColumn() ?: 0;
$tot_profit = (float)db()->query("SELECT SUM(profit) FROM transactions WHERE status='success'")->fetchColumn() ?: 0;
$tot_sales = (float)db()->query("SELECT SUM(amount) FROM transactions WHERE type='debit' AND status='success'")->fetchColumn() ?: 0;

$monnify_balance = 0; // In a real app, you would fetch this from Monnify API
$paystack_balance = 0;

$discrepancy = ($tot_funding + $tot_profit) - $total_liability;
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Accounting & Reconciliation — <?=htmlspecialchars(setting('site_name','Gracehubtels'))?> Admin</title><link rel="icon" type="image/png" href="/assets/images/favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">

<div class="section-header"><div><h2>Accounting & Reconciliation</h2><p>Track total user liabilities versus actual cash inflow</p></div></div>

<div class="kpi-grid">
  <div class="metric-box">
    <div class="kpi-icon red"><svg viewBox="0 0 24 24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div>
    <div class="kpi-info"><span class="kpi-label">Total User Liability</span><span class="kpi-value">₦<?=number_format($total_liability,2)?></span><span class="kpi-sub">System Debt</span></div>
  </div>
  <div class="metric-box">
    <div class="kpi-icon green"><svg viewBox="0 0 24 24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div>
    <div class="kpi-info"><span class="kpi-label">Total Lifetime Deposits</span><span class="kpi-value">₦<?=number_format($tot_funding,2)?></span><span class="kpi-sub">Actual Inflow</span></div>
  </div>
  <div class="metric-box">
    <div class="kpi-icon purple"><svg viewBox="0 0 24 24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div>
    <div class="kpi-info"><span class="kpi-label">Total Lifetime Profit</span><span class="kpi-value">₦<?=number_format($tot_profit,2)?></span><span class="kpi-sub">Realized Gain</span></div>
  </div>
  <div class="metric-box">
    <div class="kpi-icon yellow"><svg viewBox="0 0 24 24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div>
    <div class="kpi-info"><span class="kpi-label">Total Sales Value</span><span class="kpi-value">₦<?=number_format($tot_sales,2)?></span><span class="kpi-sub">Gross Sales</span></div>
  </div>
</div>

<div class="row-2col">
  <div class="data-panel">
    <div class="panel-title-bar"><h3>Balance Sheet Breakdown</h3></div>
    <div class="responsive-grid-wrapper">
      <table class="data-table">
        <tbody>
          <tr><td><strong>User Main Wallets</strong></td><td style="text-align:right">₦<?=number_format($tot_wallet,2)?></td></tr>
          <tr><td><strong>User Cashback Wallets</strong></td><td style="text-align:right">₦<?=number_format($tot_cashback,2)?></td></tr>
          <tr style="background:var(--bg)"><td style="color:var(--danger);font-weight:800">TOTAL LIABILITIES (System Debt)</td><td style="text-align:right;color:var(--danger);font-weight:800">₦<?=number_format($total_liability,2)?></td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="data-panel">
    <div class="panel-title-bar"><h3>Reconciliation Estimate</h3></div>
    <div style="padding:24px;">
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:20px;line-height:1.6">Ideally, your external payment gateway balances (Monnify/Paystack) minus your profit should roughly equal your Total Liabilities. If Liability is much higher than actual cash inflow, you may have credited wallets manually without real gateway deposits.</p>
      
      <div style="background:var(--bg);padding:24px;border-radius:var(--radius);border:1px solid var(--border-light);display:flex;justify-content:space-between;align-items:center;">
        <div>
          <div style="font-size:11px;text-transform:uppercase;font-weight:800;color:var(--text-muted);letter-spacing:1px;margin-bottom:4px">System Solvency Value</div>
          <div style="font-size:28px;font-weight:800;color:<?=$discrepancy>=0?'var(--success)':'var(--danger)'?>">₦<?=number_format($discrepancy,2)?></div>
        </div>
        <div>
          <span class="badge <?=$discrepancy>=0?'badge-success':'badge-danger'?>"><?=$discrepancy>=0?'Solvent / Profitable':'Potential Deficit'?></span>
        </div>
      </div>
    </div>
  </div>
</div>

</div></div>
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
</script>
</body></html>
