<?php
require_once dirname(__DIR__,2).'/user/core/db.php';
function admin_stats():array{
    $db=db();
    $users=(int)$db->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();
    $new_today=(int)$db->query("SELECT COUNT(*) FROM users WHERE DATE(created_at)=CURDATE()")->fetchColumn();
    $kyc_pending=(int)$db->query("SELECT COUNT(*) FROM kyc_requests WHERE status='pending'")->fetchColumn();
    $total_tx=(int)$db->query("SELECT COUNT(*) FROM transactions")->fetchColumn();
    $success_tx=(int)$db->query("SELECT COUNT(*) FROM transactions WHERE status='success'")->fetchColumn();
    $pending_tx=(int)$db->query("SELECT COUNT(*) FROM transactions WHERE status='pending'")->fetchColumn();
    $failed_tx=(int)$db->query("SELECT COUNT(*) FROM transactions WHERE status='failed'")->fetchColumn();
    $total_vol=(float)$db->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status='success' AND type='debit'")->fetchColumn();
    $today_vol=(float)$db->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status='success' AND type='debit' AND DATE(created_at)=CURDATE()")->fetchColumn();
    $total_profit=(float)$db->query("SELECT COALESCE(SUM(profit),0) FROM transactions WHERE status='success' AND profit IS NOT NULL")->fetchColumn();
    $today_profit=(float)$db->query("SELECT COALESCE(SUM(profit),0) FROM transactions WHERE status='success' AND profit IS NOT NULL AND DATE(created_at)=CURDATE()")->fetchColumn();
    $wallet_sum=(float)$db->query("SELECT COALESCE(SUM(wallet_balance),0) FROM users WHERE is_active=1")->fetchColumn();
    $cashback_sum=(float)$db->query("SELECT COALESCE(SUM(cashback_balance),0) FROM users WHERE is_active=1")->fetchColumn();
    $pending_a2c=(int)$db->query("SELECT COUNT(*) FROM airtime_to_cash WHERE status IN ('pending','processing')")->fetchColumn();
    $chart=$db->query("SELECT DATE(created_at) as d,COALESCE(SUM(amount),0) as vol,COALESCE(SUM(profit),0) as profit FROM transactions WHERE status='success' AND type='debit' AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) GROUP BY DATE(created_at) ORDER BY d")->fetchAll();
    $recent_tx=$db->query("SELECT t.*,u.full_name,u.phone FROM transactions t LEFT JOIN users u ON t.user_id=u.id ORDER BY t.created_at DESC LIMIT 15")->fetchAll();
    $categories=$db->query("SELECT category,COUNT(*) as cnt,COALESCE(SUM(amount),0) as vol,COALESCE(SUM(profit),0) as profit FROM transactions WHERE status='success' AND type='debit' AND DATE(created_at)=CURDATE() GROUP BY category ORDER BY vol DESC")->fetchAll();
    return compact('users','new_today','kyc_pending','total_tx','success_tx','pending_tx','failed_tx','total_vol','today_vol','total_profit','today_profit','wallet_sum','cashback_sum','pending_a2c','chart','recent_tx','categories');
}
function get_users_list(int $page=1,int $per=20,string $search='',string $filter=''):array{
    $db=db();$offset=($page-1)*$per;$where='WHERE 1=1';$params=[];
    if($search){$where.=" AND (full_name LIKE ? OR phone LIKE ? OR username LIKE ?)";$s="%{$search}%";$params=array_merge($params,[$s,$s,$s]);}
    if($filter==='suspended')$where.=" AND is_suspended=1";
    elseif($filter==='active')$where.=" AND is_active=1 AND is_suspended=0";
    elseif($filter==='kyc1')$where.=" AND kyc_level>=1";
    elseif($filter==='no_kyc')$where.=" AND kyc_level=0";
    $cs=db()->prepare("SELECT COUNT(*) FROM users $where");$cs->execute($params);$total=(int)$cs->fetchColumn();
    $p2=$params;$p2[]=$per;$p2[]=$offset;
    $st=$db->prepare("SELECT * FROM users $where ORDER BY created_at DESC LIMIT ? OFFSET ?");$st->execute($p2);
    return['data'=>$st->fetchAll(),'total'=>$total,'pages'=>(int)ceil($total/$per),'page'=>$page];
}
function get_transactions_list(int $page=1,int $per=25,array $filters=[]):array{
    $db=db();$offset=($page-1)*$per;$where='WHERE 1=1';$params=[];
    if(!empty($filters['user_id'])){$where.=" AND t.user_id=?";$params[]=$filters['user_id'];}
    if(!empty($filters['status'])){$where.=" AND t.status=?";$params[]=$filters['status'];}
    if(!empty($filters['category'])){$where.=" AND t.category=?";$params[]=$filters['category'];}
    if(!empty($filters['date_from'])){$where.=" AND DATE(t.created_at)>=?";$params[]=$filters['date_from'];}
    if(!empty($filters['date_to'])){$where.=" AND DATE(t.created_at)<=?";$params[]=$filters['date_to'];}
    $cs=$db->prepare("SELECT COUNT(*) FROM transactions t $where");$cs->execute($params);$total=(int)$cs->fetchColumn();
    $p2=$params;$p2[]=$per;$p2[]=$offset;
    $st=$db->prepare("SELECT t.*,u.full_name,u.phone,u.email FROM transactions t LEFT JOIN users u ON t.user_id=u.id $where ORDER BY t.created_at DESC LIMIT ? OFFSET ?");$st->execute($p2);
    return['data'=>$st->fetchAll(),'total'=>$total,'pages'=>(int)ceil($total/$per),'page'=>$page];
}
function get_profit_analysis():array{
    $db=db();
    $by_service=$db->query("SELECT category,COUNT(*) as cnt,COALESCE(SUM(amount),0) as revenue,COALESCE(SUM(cost_price),0) as cost,COALESCE(SUM(profit),0) as profit FROM transactions WHERE status='success' AND profit IS NOT NULL GROUP BY category ORDER BY profit DESC")->fetchAll();
    $monthly=$db->query("SELECT DATE_FORMAT(created_at,'%Y-%m') as mo,COALESCE(SUM(amount),0) as revenue,COALESCE(SUM(profit),0) as profit FROM transactions WHERE status='success' AND profit IS NOT NULL GROUP BY mo ORDER BY mo DESC LIMIT 12")->fetchAll();
    return['by_service'=>$by_service,'monthly'=>$monthly,'total_profit'=>array_sum(array_column($by_service,'profit')),'total_revenue'=>array_sum(array_column($by_service,'revenue'))];
}
