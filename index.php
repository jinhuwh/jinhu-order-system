<?php
require_once 'config.php';
initDatabase();
checkLogin();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>首页概览 - <?php echo SITE_TITLE; ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php echo renderNav('index'); ?>
    
    <div class="page-title-bar animate-in">
        <div class="page-title-left">
            <h1>首页概览</h1>
            <p>查看系统运行数据与最近订单动态</p>
        </div>
        <div class="page-title-actions">
            <a href="reports.php" class="btn btn-outline">📊 数据统计</a>
            <a href="order_create.php" class="btn btn-primary">➕ 新建订单</a>
        </div>
    </div>
    
    <?php
    $db = getDB();
    $today = date('Y-m-d');
    $stmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = ?");
    $stmt->execute([$today]);
    $todayOrders = $stmt->fetchColumn();
    $pendingOrders = $db->query("SELECT COUNT(*) FROM orders WHERE status = 0")->fetchColumn();
    $producingOrders = $db->query("SELECT COUNT(*) FROM orders WHERE status = 2")->fetchColumn();
    $monthRevenue = $db->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status != 4 AND MONTH(created_at) = MONTH(CURRENT_DATE())")->fetchColumn();
    ?>
    
    <?php
    // 趋势数据（示例：与昨日对比）
    $yesterdayOrders = 0;
    try {
        $yStmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = DATE_SUB(CURRENT_DATE(), INTERVAL 1 DAY)");
        $yStmt->execute();
        $yesterdayOrders = $yStmt->fetchColumn();
    } catch (Exception $e) {}
    
    $orderTrendVal = $todayOrders - $yesterdayOrders;
    $orderTrendPct = $yesterdayOrders > 0 ? round(($todayOrders - $yesterdayOrders) / $yesterdayOrders * 100, 1) : 0;
    $orderTrendClass = $orderTrendVal >= 0 ? 'up' : 'down';
    $orderTrendIcon = $orderTrendVal >= 0 ? '↑' : '↓';
    ?>

    <div class="stats-grid">
        <div class="stat-card blue animate-in delay-1">
            <div class="stat-card-header">
                <span class="stat-card-label">今日订单</span>
                <div class="stat-card-icon">📋</div>
            </div>
            <div class="stat-card-value"><?php echo $todayOrders; ?></div>
            <div class="stat-card-trend <?php echo $orderTrendClass; ?>">
                <?php if ($orderTrendVal != 0): ?><?php echo $orderTrendIcon; ?> <?php echo abs($orderTrendVal); ?><?php endif; ?>
                <span class="trend-sub">较昨日</span>
            </div>
        </div>
        <div class="stat-card orange animate-in delay-2">
            <div class="stat-card-header">
                <span class="stat-card-label">待确认</span>
                <div class="stat-card-icon">⏳</div>
            </div>
            <div class="stat-card-value"><?php echo $pendingOrders; ?></div>
            <div class="stat-card-trend neutral">
                +<?php echo $pendingOrders; ?> 待处理
                <span class="trend-sub">需确认</span>
            </div>
        </div>
        <div class="stat-card purple animate-in delay-3">
            <div class="stat-card-header">
                <span class="stat-card-label">生产中</span>
                <div class="stat-card-icon">🏭</div>
            </div>
            <div class="stat-card-value"><?php echo $producingOrders; ?></div>
            <div class="stat-card-trend neutral">
                正进行中
                <span class="trend-sub"></span>
            </div>
        </div>
        <div class="stat-card green animate-in delay-4">
            <div class="stat-card-header">
                <span class="stat-card-label">本月营收</span>
                <div class="stat-card-icon">💰</div>
            </div>
            <div class="stat-card-value money"><?php echo formatMoney($monthRevenue); ?></div>
            <div class="stat-card-trend up">
                ↑ 8.5%
                <span class="trend-sub">较上月</span>
            </div>
        </div>
    </div>
    
    <div class="card animate-in">
        <div class="card-header">
            <div class="card-title"><span class="title-icon">📋</span> 最近订单 <span class="card-title-sub">最新10条</span></div>
            <a href="orders.php" class="btn btn-ghost btn-sm">查看全部 →</a>
        </div>
        <div class="table-wrapper recent-orders-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>订单号</th>
                        <th>客户</th>
                        <th>金额</th>
                        <th>订单状态</th>
                        <th>收款状态</th>
                        <th>创建时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $orders = $db->query("SELECT o.*, c.name as customer_name 
                    FROM orders o LEFT JOIN customers c ON o.customer_id = c.id 
                    ORDER BY o.created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
                if (empty($orders)): ?>
                    <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--gray-400);">暂无订单，<a href="order_create.php" style="color:var(--primary);">点击创建第一个订单</a></td></tr>
                <?php else: foreach ($orders as $order): ?>
                    <tr>
                        <td><a href="order_view.php?id=<?php echo $order['id']; ?>" class="td-link"><?php echo $order['order_no']; ?></a></td>
                        <td style="font-weight:600;"><?php echo htmlspecialchars($order['customer_name'] ?? '未知客户'); ?></td>
                        <td class="td-money"><?php echo formatMoney($order['total_amount']); ?></td>
                        <td><span class="badge badge-<?php echo getStatusClass($order['status']); ?>"><?php echo getStatusText($order['status']); ?></span></td>
                        <td>
                            <?php
                            $payStatus = $order['payment_status'] ?? 0;
                            $payClass = function_exists('getPaymentStatusClass') ? getPaymentStatusClass($payStatus) : ($payStatus == 2 ? 'success' : ($payStatus == 1 ? 'warning' : 'danger'));
                            $payText = function_exists('getPaymentStatusText') ? getPaymentStatusText($payStatus) : ($payStatus == 2 ? '已付清' : ($payStatus == 1 ? '部分收款' : '未付款'));
                            ?>
                            <span class="badge badge-<?php echo $payClass; ?>"><?php echo $payText; ?></span>
                        </td>
                        <td class="td-muted"><?php echo date('m-d H:i', strtotime($order['created_at'])); ?></td>
                        <td><a href="order_view.php?id=<?php echo $order['id']; ?>" class="btn btn-primary btn-sm">查看</a></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php echo renderFooter(); ?>
</body>
</html>