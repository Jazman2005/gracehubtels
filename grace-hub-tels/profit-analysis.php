<?php
require_once __DIR__.'/core/auth.php';
require_once __DIR__.'/core/dashboard.php';
auth_admin();require_permission('view_profit');$analysis=get_profit_analysis();
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Profit Analysis — Gracehubtels Admin</title><link rel="icon" type="image/png" href="/assets/images/gracehubtels-favicon.png"><link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="<?= ADMIN_URL ?>/css/admin.css"></head>
<body>
<?php include __DIR__.'/partials/sidebar.php';?>
<div class="main-wrap">
<?php include __DIR__.'/partials/topbar.php';?>
<div class="admin-fluid-container">
<div class="section-header"><div><h2>Profit Analysis</h2><p>Revenue and margin breakdown by service</p></div></div>
<div class="kpi-grid">
  <div class="metric-box"><div class="kpi-icon green"><svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div><div class="kpi-info"><span class="kpi-label">Total Revenue</span><span class="kpi-value">₦<?=number_format($analysis['total_revenue'],0)?></span></div></div>
  <div class="metric-box"><div class="kpi-icon orange"><svg viewBox="0 0 24 24"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/></svg></div><div class="kpi-info"><span class="kpi-label">Total Profit</span><span class="kpi-value">₦<?=number_format($analysis['total_profit'],0)?></span></div></div>
  <div class="metric-box"><div class="kpi-icon blue"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/></svg></div><div class="kpi-info"><span class="kpi-label">Margin</span><span class="kpi-value"><?=$analysis['total_revenue']>0?number_format($analysis['total_profit']/$analysis['total_revenue']*100,1):0?>%</span></div></div>
</div>
<div class="row-2col">
  <div class="data-panel"><div class="panel-title-bar"><h3>Margin by Service</h3></div><div class="chart-wrap"><canvas id="marginChart"></canvas></div></div>
  <div class="data-panel"><div class="panel-title-bar"><h3>Monthly Trend</h3></div><div class="chart-wrap"><canvas id="monthChart"></canvas></div></div>
</div>
<div class="data-panel">
  <div class="panel-title-bar"><h3>Profit by Service</h3></div>
  <div class="responsive-grid-wrapper"><table class="data-table"><thead><tr><th>Service</th><th>Transactions</th><th>Revenue</th><th>Cost</th><th>Profit</th><th>Margin %</th></tr></thead><tbody>
  <?php foreach($analysis['by_service'] as $s):$margin=$s['revenue']>0?($s['profit']/$s['revenue']*100):0;?>
  <tr><td><strong><?=ucwords(str_replace('_',' ',$s['category']))?></strong></td><td><?=$s['cnt']?></td><td>₦<?=number_format((float)$s['revenue'],2)?></td><td>₦<?=number_format((float)$s['cost'],2)?></td><td class="text-success">₦<?=number_format((float)$s['profit'],2)?></td><td><?=number_format($margin,1)?>%</td></tr>
  <?php endforeach;if(empty($analysis['by_service'])):?><tr><td colspan="6" class="empty-state">No profit data yet</td></tr><?php endif;?>
  </tbody></table></div>
</div>
</div></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
const byService=<?=json_encode($analysis['by_service'])?>;
const monthly=<?=json_encode(array_reverse($analysis['monthly']))?>;
new Chart(document.getElementById('marginChart'),{type:'doughnut',data:{labels:byService.map(s=>s.category),datasets:[{data:byService.map(s=>parseFloat(s.profit)),backgroundColor:['#F97316','#3B82F6','#22C55E','#8B5CF6','#14B8A6','#EC4899','var(--warning)','#06B6D4']}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{font:{size:10,family:'Sora'}}}}}});
new Chart(document.getElementById('monthChart'),{type:'line',data:{labels:monthly.map(m=>m.mo),datasets:[{label:'Revenue',data:monthly.map(m=>parseFloat(m.revenue)),borderColor:'#3B82F6',backgroundColor:'rgba(59,130,246,.1)',tension:.3},{label:'Profit',data:monthly.map(m=>parseFloat(m.profit)),borderColor:'#22C55E',backgroundColor:'rgba(34,197,94,.1)',tension:.3}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{labels:{font:{family:'Sora',size:11}}}},scales:{y:{ticks:{callback:v=>'₦'+v.toLocaleString()}}}}});
</script>
</body></html>
