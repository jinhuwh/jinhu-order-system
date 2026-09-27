<?php
require_once 'config.php';
initDatabase();
checkLogin();

$stats = getMobileDashboardData();
$recentOrders = getMobileOrders('', '', 1, 5);
$companyName = getSetting('company_name') ?: '广告公司';
$user = getCurrentUser();

// 趋势数据
$yesterdayOrders = 0;
try {
    $yStmt = getDB()->prepare("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = DATE_SUB(CURRENT_DATE(), INTERVAL 1 DAY)");
    $yStmt->execute();
    $yesterdayOrders = $yStmt->fetchColumn();
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>首页 - <?php echo htmlspecialchars($companyName); ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <!-- 顶部栏 -->
    <div class="page-header">
        <h1><img src="../logo.png" alt="logo" class="company-logo" style="height:24px;width:24px;vertical-align:middle;margin-right:4px;border-radius:3px;"><?php echo htmlspecialchars($companyName); ?></h1>
        <p class="company">广告制作订单管理系统</p>
        <div class="user-bar">
            <span>👤 <?php echo htmlspecialchars($user['realname'] ?: $user['username']); ?></span>
            <a href="../logout.php">退出</a>
        </div>
    </div>
    
    <div class="page">
        <!-- 统计卡片（白底+彩色顶边+图标+趋势） -->
        <div class="stats-grid">
            <div class="stat-card blue">
                <div class="stat-header">
                    <span class="stat-label">今日订单</span>
                    <div class="stat-icon">📋</div>
                </div>
                <div class="num"><?php echo $stats['today_count']; ?></div>
                <div class="label">今日新增</div>
                <?php if ($stats['today_count'] != $yesterdayOrders): ?>
                <div class="trend <?php echo ($stats['today_count'] >= $yesterdayOrders) ? 'up' : 'down'; ?>">
                    <?php echo ($stats['today_count'] >= $yesterdayOrders) ? '↑' : '↓'; ?>
                    <?php echo abs($stats['today_count'] - $yesterdayOrders); ?> 较昨日
                </div>
                <?php endif; ?>
            </div>
            
            <div class="stat-card orange">
                <div class="stat-header">
                    <span class="stat-label">待确认</span>
                    <div class="stat-icon">⏳</div>
                </div>
                <div class="num" style="color:#F97316;"><?php echo $stats['pending_count']; ?></div>
                <div class="label">需要处理</div>
                <div class="trend neutral"><?php echo $stats['pending_count']; ?> 待处理</div>
            </div>
            
            <div class="stat-card purple">
                <div class="stat-header">
                    <span class="stat-label">生产中</span>
                    <div class="stat-icon">🏭</div>
                </div>
                <div class="num" style="color:#8B5CF6;"><?php echo $stats['producing_count']; ?></div>
                <div class="label">正在生产</div>
                <div class="trend neutral">进行中</div>
            </div>
            
            <div class="stat-card green">
                <div class="stat-header">
                    <span class="stat-label">本月营收</span>
                    <div class="stat-icon">💰</div>
                </div>
                <div class="num money">¥<?php echo number_format($stats['month_amount'], 0); ?></div>
                <div class="label">累计金额</div>
                <div class="trend up">↑ 8.5% 较上月</div>
            </div>
        </div>
        
        <!-- 快捷操作 -->
        <div class="card">
            <div class="card-title">⚡ 快捷操作</div>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;">
                <a href="order_create.php" class="btn btn-primary btn-block" style="padding:12px 8px;font-size:13px;">➕ 新建订单</a>
                <a href="order_list.php" class="btn btn-outline btn-block" style="padding:12px 8px;font-size:13px;">📋 订单列表</a>
                <a href="customers.php" class="btn btn-outline btn-block" style="padding:12px 8px;font-size:13px;">👥 客户管理</a>
            </div>
        </div>
        
        <!-- 最近订单 -->
        <div class="card">
            <div class="card-title" style="justify-content:space-between;display:flex;align-items:center;">
                <span>📋 最近订单</span>
                <a href="order_list.php" style="font-size:13px;color:var(--primary);text-decoration:none;font-weight:normal;">查看全部 ›</a>
            </div>
            
            <?php if (empty($recentOrders)): ?>
            <div class="empty-state" style="padding:30px 0;">
                <div class="icon">📭</div>
                <div class="text">暂无订单，<a href="order_create.php" style="color:var(--primary);text-decoration:none;">创建第一单</a></div>
            </div>
            <?php else: ?>
                <?php foreach ($recentOrders as $order): ?>
                <a href="order_view.php?id=<?php echo $order['id']; ?>" class="order-card status-<?php echo $order['status']; ?>">
                    <div class="order-header">
                        <span class="order-no"><?php echo $order['order_no']; ?></span>
                        <span class="order-amount">¥<?php echo number_format($order['total_amount'], 2); ?></span>
                    </div>
                    <div class="customer"><?php echo htmlspecialchars($order['customer_name'] ?: '未知客户'); ?></div>
                    <div class="order-payment-info" style="display:flex;gap:8px;margin:6px 0;font-size:12px;">
                        <?php
                        $paid = floatval($order['paid_amount'] ?? 0);
                        $total = floatval($order['total_amount']);
                        $unpaid = $total - $paid;
                        $payStatus = intval($order['payment_status'] ?? 0);
                        $payBadgeClass = $payStatus == 2 ? 'success' : ($payStatus == 1 ? 'warning' : 'danger');
                        $payText = $payStatus == 2 ? '已付清' : ($payStatus == 1 ? '部分收款' : '未付款');
                        ?>
                        <span style="color:#22c55e;">已收: ¥<?php echo number_format($paid, 2); ?></span>
                        <span style="color:#ef4444;">未收: ¥<?php echo number_format($unpaid, 2); ?></span>
                        <span class="badge badge-<?php echo $payBadgeClass; ?>" style="font-size:11px;padding:2px 6px;"><?php echo $payText; ?></span>
                    </div>
                    <div class="order-footer">
                        <span class="order-time"><?php echo date('m-d H:i', strtotime($order['created_at'])); ?></span>
                        <span class="badge badge-<?php echo $order['status']; ?>"><?php echo getStatusText($order['status']); ?></span>
                    </div>
                </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <?php echo mobileNav('home'); ?>
</body>
</html>
