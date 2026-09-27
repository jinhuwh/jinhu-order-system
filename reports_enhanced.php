<?php
/**
 * 数据统计报表 - 增强版
 * 
 * 功能：
 * - 客户消费排行（含可视化条形图）
 * - 产品销售排行（含分类分析）
 * - 应收款统计
 * - 月度销售趋势（含柱状图）
 * - 订单状态分布（含饼图）
 * - 利润分析（含图表）
 */

require_once 'config.php';
initDatabase();
checkLogin();

$db = getDB();

// 获取筛选参数
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$reportType = $_GET['type'] ?? 'overview';

$companyName = getSetting('company_name') ?: SITE_TITLE;

// 统计数据
$stats = [];

// 1. 总体统计
$stmt = $db->prepare("
    SELECT
        COUNT(*) as total_orders,
        COALESCE(SUM(total_amount), 0) as total_sales,
        COALESCE(SUM(paid_amount), 0) as total_paid,
        COALESCE(SUM(GREATEST(0, total_amount - COALESCE(discount_amount, 0) - paid_amount)), 0) as total_unpaid
    FROM orders
    WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
");
$stmt->execute([$startDate, $endDate]);
$stats['overview'] = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$stats['overview'] || $stats['overview']['total_orders'] == 0) {
    $stats['overview'] = ['total_orders' => 0, 'total_sales' => 0, 'total_paid' => 0, 'total_unpaid' => 0];
}

// 2. 客户消费排行 TOP 20
$stmt = $db->prepare("
    SELECT
        c.id,
        c.name,
        c.phone,
        COUNT(o.id) as order_count,
        COALESCE(SUM(o.total_amount), 0) as total_amount,
        COALESCE(SUM(o.paid_amount), 0) as paid_amount,
        COALESCE(SUM(GREATEST(0, o.total_amount - COALESCE(o.discount_amount, 0) - o.paid_amount)), 0) as unpaid_amount
    FROM customers c
    INNER JOIN orders o ON c.id = o.customer_id
    WHERE o.created_at >= ? AND o.created_at <= DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY c.id
    ORDER BY total_amount DESC
    LIMIT 20
");
$stmt->execute([$startDate, $endDate]);
$stats['customer_rank'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. 产品销售排行 TOP 20
$stmt = $db->prepare("
    SELECT
        oi.product_name,
        SUM(oi.quantity) as total_quantity,
        SUM(oi.amount) as total_amount
    FROM order_items oi
    INNER JOIN orders o ON oi.order_id = o.id
    WHERE o.created_at >= ? AND o.created_at <= DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY oi.product_name
    ORDER BY total_amount DESC
    LIMIT 20
");
$stmt->execute([$startDate, $endDate]);
$stats['product_rank'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. 产品分类统计
$stmt = $db->prepare("
    SELECT
        p.category,
        COUNT(*) as product_count,
        COALESCE(SUM(oi.quantity), 0) as total_quantity,
        COALESCE(SUM(oi.amount), 0) as total_amount
    FROM order_items oi
    INNER JOIN orders o ON oi.order_id = o.id
    LEFT JOIN products p ON oi.product_name = p.name
    WHERE o.created_at >= ? AND o.created_at <= DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY p.category
    ORDER BY total_amount DESC
");
$stmt->execute([$startDate, $endDate]);
$stats['category_stats'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 5. 应收款统计（按客户）
$stmt = $db->query("
    SELECT
        c.id,
        c.name,
        c.phone,
        COUNT(o.id) as order_count,
        COALESCE(SUM(o.total_amount), 0) as total_amount,
        COALESCE(SUM(o.paid_amount), 0) as paid_amount,
        COALESCE(SUM(GREATEST(0, o.total_amount - COALESCE(o.discount_amount, 0) - o.paid_amount)), 0) as unpaid_amount
    FROM customers c
    INNER JOIN orders o ON c.id = o.customer_id
    WHERE o.total_amount > o.paid_amount
    GROUP BY c.id
    ORDER BY unpaid_amount DESC
    LIMIT 100
");
$stats['receivables'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 6. 月度销售趋势（最近12个月）
$stmt = $db->prepare("
    SELECT
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as order_count,
        COALESCE(SUM(total_amount), 0) as total_amount,
        COALESCE(SUM(paid_amount), 0) as paid_amount
    FROM orders
    WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month DESC
");
$stmt->execute([$startDate, $endDate]);
$stats['monthly_trend'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stats['monthly_trend'] = array_reverse($stats['monthly_trend']);

// 7. 订单状态分布
$stmt = $db->prepare("
    SELECT
        status,
        COUNT(*) as count,
        COALESCE(SUM(total_amount), 0) as total_amount
    FROM orders
    WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY status
    ORDER BY status
");
$stmt->execute([$startDate, $endDate]);
$stats['order_status'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalStatusCount = array_sum(array_column($stats['order_status'], 'count'));

// 8. 收款方式统计
$stmt = $db->prepare("
    SELECT
        payment_method,
        COUNT(*) as count,
        COALESCE(SUM(amount), 0) as total_amount
    FROM payments p
    JOIN orders o ON p.order_id = o.id
    WHERE o.created_at >= ? AND o.created_at <= DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY payment_method
    ORDER BY total_amount DESC
");
$stmt->execute([$startDate, $endDate]);
$stats['payment_methods'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 9. 每日订单趋势（本月）
$stmt = $db->prepare("
    SELECT
        DATE(created_at) as date,
        COUNT(*) as order_count,
        COALESCE(SUM(total_amount), 0) as total_amount
    FROM orders
    WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY DATE(created_at)
    ORDER BY date
");
$stmt->execute([date('Y-m-01'), date('Y-m-d')]);
$stats['daily_trend'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 10. 年度对比
$currentYear = date('Y');
$lastYear = date('Y') - 1;

$stmt = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) as total FROM orders WHERE status IN (1,2,3) AND YEAR(created_at) = ?");
$stmt->execute([$currentYear]);
$stats['current_year_revenue'] = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) as total FROM orders WHERE status IN (1,2,3) AND YEAR(created_at) = ?");
$stmt->execute([$lastYear]);
$stats['last_year_revenue'] = $stmt->fetchColumn();

$stats['year_growth'] = $stats['last_year_revenue'] > 0 
    ? round(($stats['current_year_revenue'] - $stats['last_year_revenue']) / $stats['last_year_revenue'] * 100, 1) 
    : 0;

// 辅助函数
function formatMoney($amount) {
    return '¥' . number_format($amount, 2);
}

function getStatusClass($status) {
    $classes = ['warning', 'info', 'primary', 'success', 'danger'];
    return $classes[$status] ?? 'secondary';
}

function getStatusText($status) {
    $texts = ['待确认', '已确认', '生产中', '已完成', '已取消'];
    return $texts[$status] ?? '未知';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>数据分析中心 - <?php echo htmlspecialchars($companyName); ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .page-title-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
        
        /* 统计卡片 */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(0,0,0,0.12); }
        .stat-card.blue { border-left: 4px solid #3B82F6; }
        .stat-card.green { border-left: 4px solid #10B981; }
        .stat-card.red { border-left: 4px solid #EF4444; }
        .stat-card.purple { border-left: 4px solid #8B5CF6; }
        .stat-card.orange { border-left: 4px solid #F59E0B; }
        .stat-label { color: #6B7280; font-size: 14px; margin-bottom: 8px; }
        .stat-value { font-size: 28px; font-weight: 700; color: #1F2937; }
        .stat-sub { font-size: 12px; color: #9CA3AF; margin-top: 4px; }
        .growth-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .growth-up { background: #D1FAE5; color: #065F46; }
        .growth-down { background: #FEE2E2; color: #991B1B; }
        
        /* 卡片布局 */
        .card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: 24px; margin-bottom: 20px; }
        .card-title { font-size: 18px; font-weight: 600; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; }
        
        /* 图表容器 */
        .chart-wrapper { display: flex; gap: 20px; flex-wrap: wrap; }
        .chart-main { flex: 2; min-width: 300px; }
        .chart-side { flex: 1; min-width: 200px; }
        
        /* 柱状图 */
        .bar-chart { display: flex; align-items: flex-end; gap: 6px; height: 200px; padding: 10px 0; }
        .bar-item { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 6px; }
        .bar { width: 100%; max-width: 40px; background: linear-gradient(to top, #3B82F6, #60A5FA); border-radius: 4px 4px 0 0; transition: height 0.3s; min-height: 2px; }
        .bar-label { font-size: 11px; color: #6B7280; }
        .bar-value { font-size: 10px; color: #374151; font-weight: 500; }
        
        /* 进度条图 */
        .rank-list { display: flex; flex-direction: column; gap: 12px; }
        .rank-item { display: flex; align-items: center; gap: 12px; }
        .rank-num { width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 13px; color: white; flex-shrink: 0; }
        .rank-1 { background: linear-gradient(135deg, #FFD700, #FFA500); }
        .rank-2 { background: linear-gradient(135deg, #C0C0C0, #909090); }
        .rank-3 { background: linear-gradient(135deg, #CD7F32, #8B4513); }
        .rank-other { background: #E5E7EB; color: #374151; }
        .rank-content { flex: 1; min-width: 0; }
        .rank-name { font-weight: 500; color: #1F2937; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .rank-bar { height: 8px; background: #F3F4F6; border-radius: 4px; overflow: hidden; }
        .rank-bar-fill { height: 100%; background: linear-gradient(90deg, #3B82F6, #60A5FA); border-radius: 4px; transition: width 0.3s; }
        .rank-info { display: flex; justify-content: space-between; font-size: 12px; color: #6B7280; margin-top: 2px; }
        
        /* 饼图 */
        .pie-chart { width: 160px; height: 160px; border-radius: 50%; position: relative; margin: 0 auto; }
        .pie-legend { display: flex; flex-direction: column; gap: 8px; margin-top: 16px; }
        .legend-item { display: flex; align-items: center; gap: 8px; font-size: 13px; }
        .legend-dot { width: 12px; height: 12px; border-radius: 3px; flex-shrink: 0; }
        
        /* 表格 */
        .table-wrapper { overflow-x: auto; }
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th, .data-table td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #F3F4F6; }
        .data-table th { background: #F9FAFB; font-weight: 600; color: #374151; white-space: nowrap; }
        .data-table tr:hover { background: #F9FAFB; }
        .data-table .td-money { font-weight: 600; font-family: monospace; }
        .data-table .td-link { color: #3B82F6; text-decoration: none; }
        .data-table .td-link:hover { text-decoration: underline; }
        .data-table .td-muted { color: #9CA3AF; }
        
        /* 状态标签 */
        .badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 500; }
        .badge-warning { background: #FEF3C7; color: #92400E; }
        .badge-info { background: #DBEAFE; color: #1E40AF; }
        .badge-primary { background: #E0E7FF; color: #3730A3; }
        .badge-success { background: #D1FAE5; color: #065F46; }
        .badge-danger { background: #FEE2E2; color: #991B1B; }
        
        /* 筛选器 */
        .filter-bar { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; padding: 16px; background: #F9FAFB; border-radius: 8px; margin-bottom: 20px; }
        .filter-bar input, .filter-bar select { padding: 8px 12px; border: 1px solid #D1D5DB; border-radius: 6px; font-size: 14px; }
        .filter-bar .btn { padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; }
        .btn-primary { background: #3B82F6; color: white; }
        .btn-success { background: #10B981; color: white; }
        .btn-danger { background: #EF4444; color: white; }
        .btn-ghost { background: transparent; color: #6B7280; }
        
        /* 网格布局 */
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; }
        @media (max-width: 900px) { .grid-2, .grid-3 { grid-template-columns: 1fr; } }
        
        /* 空白状态 */
        .empty-state { text-align: center; padding: 40px; color: #9CA3AF; }
        .empty-state .icon { font-size: 48px; margin-bottom: 12px; }
        
        /* 金额颜色 */
        .money-red { color: #EF4444; font-weight: 600; }
        .money-green { color: #10B981; font-weight: 600; }
        .money-orange { color: #F59E0B; font-weight: 600; }
        
        /* 工具栏 */
        .toolbar { display: flex; gap: 8px; flex-wrap: wrap; }
    </style>
</head>
<body>
    <?php echo renderNav('reports'); ?>

    <div class="page-title-bar">
        <div>
            <h1>📊 数据分析中心</h1>
            <p style="color: #6B7280; margin-top: 4px;">多维度洞察业务数据</p>
        </div>
        <div class="toolbar">
            <button class="btn btn-success" onclick="exportCSV('customer')">📥 导出客户</button>
            <button class="btn btn-success" onclick="exportCSV('product')">📥 导出产品</button>
            <a href="reports_pdf.php?start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>" class="btn btn-danger" target="_blank">📄 导出 PDF</a>
        </div>
    </div>

    <!-- 筛选区域 -->
    <div class="card">
        <form class="filter-bar" method="GET" style="margin-bottom:0;background:transparent;padding:0;">
            <span style="font-weight:500;color:#374151;">统计区间：</span>
            <input type="date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
            <span style="color:#6B7280;">至</span>
            <input type="date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
            <select name="type">
                <option value="overview" <?php echo $reportType === 'overview' ? 'selected' : ''; ?>>📊 综合概览</option>
                <option value="customers" <?php echo $reportType === 'customers' ? 'selected' : ''; ?>>👥 客户排行</option>
                <option value="products" <?php echo $reportType === 'products' ? 'selected' : ''; ?>>📦 产品排行</option>
                <option value="receivables" <?php echo $reportType === 'receivables' ? 'selected' : ''; ?>>💰 应收款统计</option>
            </select>
            <button type="submit" class="btn btn-primary">🔍 查询</button>
            <a href="reports.php" class="btn btn-ghost">重置</a>
        </form>
    </div>

    <?php if ($reportType === 'overview'): ?>
    
    <!-- 核心指标卡片 -->
    <div class="stats-grid">
        <div class="stat-card blue">
            <div class="stat-label">📋 订单总数</div>
            <div class="stat-value"><?php echo number_format($stats['overview']['total_orders']); ?></div>
            <div class="stat-sub">笔订单</div>
        </div>
        <div class="stat-card green">
            <div class="stat-label">💰 销售总额</div>
            <div class="stat-value"><?php echo formatMoney($stats['overview']['total_sales']); ?></div>
            <div class="stat-sub">已完成订单</div>
        </div>
        <div class="stat-card purple">
            <div class="stat-label">✅ 已收款</div>
            <div class="stat-value"><?php echo formatMoney($stats['overview']['total_paid']); ?></div>
            <?php 
            $paidRate = $stats['overview']['total_sales'] > 0 
                ? round($stats['overview']['total_paid'] / $stats['overview']['total_sales'] * 100, 1) 
                : 0;
            ?>
            <div class="stat-sub">收款率 <?php echo $paidRate; ?>%</div>
        </div>
        <div class="stat-card red">
            <div class="stat-label">⏳ 待收款</div>
            <div class="stat-value"><?php echo formatMoney($stats['overview']['total_unpaid']); ?></div>
            <div class="stat-sub">应收款</div>
        </div>
    </div>

    <!-- 年度对比 & 月度趋势 -->
    <div class="grid-2">
        <!-- 年度对比 -->
        <div class="card">
            <div class="card-title">📈 年度业绩对比</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;text-align:center;">
                <div style="padding:20px;background:#F9FAFB;border-radius:12px;">
                    <div style="font-size:14px;color:#6B7280;margin-bottom:8px;"><?php echo $lastYear; ?>年</div>
                    <div style="font-size:24px;font-weight:700;color:#374151;"><?php echo formatMoney($stats['last_year_revenue']); ?></div>
                </div>
                <div style="padding:20px;background:linear-gradient(135deg,#EFF6FF,#DBEAFE);border-radius:12px;">
                    <div style="font-size:14px;color:#3B82F6;margin-bottom:8px;"><?php echo $currentYear; ?>年</div>
                    <div style="font-size:24px;font-weight:700;color:#1D4ED8;"><?php echo formatMoney($stats['current_year_revenue']); ?></div>
                    <?php if ($stats['year_growth'] != 0): ?>
                    <span class="growth-badge <?php echo $stats['year_growth'] >= 0 ? 'growth-up' : 'growth-down'; ?>" style="margin-top:8px;">
                        <?php echo $stats['year_growth'] >= 0 ? '↑' : '↓'; ?> <?php echo abs($stats['year_growth']); ?>%
                    </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 收款方式分布 -->
        <div class="card">
            <div class="card-title">💳 收款方式分布</div>
            <?php if (empty($stats['payment_methods'])): ?>
            <div class="empty-state"><div class="icon">📭</div><div>暂无收款数据</div></div>
            <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:12px;">
                <?php 
                $totalPayment = array_sum(array_column($stats['payment_methods'], 'total_amount'));
                $methodLabels = ['cash' => '💵 现金', 'wechat' => '💚 微信', 'alipay' => '💙 支付宝', 'bank' => '🏦 银行转账', 'other' => '📝 其他'];
                $methodColors = ['cash' => '#10B981', 'wechat' => '#07C160', 'alipay' => '#1677FF', 'bank' => '#722ED1', 'other' => '#6B7280'];
                foreach ($stats['payment_methods'] as $method): 
                    $pct = $totalPayment > 0 ? round($method['total_amount'] / $totalPayment * 100, 1) : 0;
                    $color = $methodColors[$method['payment_method']] ?? '#6B7280';
                    $label = $methodLabels[$method['payment_method']] ?? $method['payment_method'];
                ?>
                <div>
                    <div style="display:flex;justify-content:space-between;margin-bottom:4px;font-size:14px;">
                        <span><?php echo $label; ?></span>
                        <span><strong><?php echo formatMoney($method['total_amount']); ?></strong> <span style="color:#9CA3AF;">(<?php echo $pct; ?>%)</span></span>
                    </div>
                    <div class="rank-bar"><div class="rank-bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $color; ?>;"></div></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 月度趋势柱状图 -->
    <div class="card">
        <div class="card-title">📈 月度销售趋势（最近12个月）</div>
        <?php if (empty($stats['monthly_trend'])): ?>
        <div class="empty-state"><div class="icon">📭</div><div>暂无月度数据</div></div>
        <?php else: ?>
        <div class="bar-chart">
            <?php 
            $maxAmount = max(array_column($stats['monthly_trend'], 'total_amount'));
            if ($maxAmount == 0) $maxAmount = 1;
            foreach ($stats['monthly_trend'] as $item): 
                $height = round($item['total_amount'] / $maxAmount * 180);
            ?>
            <div class="bar-item">
                <div class="bar-value"><?php echo $item['total_amount'] > 0 ? round($item['total_amount']/10000, 1).'w' : ''; ?></div>
                <div class="bar" style="height:<?php echo max($height, 2); ?>px;"></div>
                <div class="bar-label"><?php echo substr($item['month'], 5); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- 订单状态分布 -->
    <div class="grid-2">
        <div class="card">
            <div class="card-title">🎯 订单状态分布</div>
            <?php if (empty($stats['order_status'])): ?>
            <div class="empty-state"><div class="icon">📭</div><div>暂无数据</div></div>
            <?php else: ?>
            <?php
            $statusColors = ['#F59E0B', '#3B82F6', '#8B5CF6', '#10B981', '#EF4444'];
            $statusLabels = ['待确认', '已确认', '生产中', '已完成', '已取消'];
            $totalAmountByStatus = array_sum(array_column($stats['order_status'], 'total_amount'));
            ?>
            <div style="display:flex;gap:20px;align-items:center;flex-wrap:wrap;">
                <div style="position:relative;width:140px;height:140px;">
                    <svg viewBox="0 0 36 36" style="width:140px;height:140px;transform:rotate(-90deg);">
                        <?php 
                        $cumPct = 0;
                        foreach ($stats['order_status'] as $i => $item):
                            $pct = $totalStatusCount > 0 ? $item['count'] / $totalStatusCount * 100 : 0;
                            $color = $statusColors[$item['status']] ?? '#E5E7EB';
                        ?>
                        <circle cx="18" cy="18" r="15.9" fill="none" stroke="<?php echo $color; ?>" 
                            stroke-width="3" stroke-dasharray="<?php echo $pct; ?> <?php echo 100 - $pct; ?>" 
                            stroke-dashoffset="<?php echo -$cumPct * 0.628; ?>" />
                        <?php $cumPct += $pct; endforeach; ?>
                    </svg>
                </div>
                <div class="pie-legend">
                    <?php foreach ($stats['order_status'] as $item): ?>
                    <div class="legend-item">
                        <div class="legend-dot" style="background:<?php echo $statusColors[$item['status']] ?? '#E5E7EB'; ?>;"></div>
                        <span><?php echo $statusLabels[$item['status']] ?? '未知'; ?></span>
                        <span style="margin-left:auto;font-weight:600;"><?php echo $item['count']; ?> 笔</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- 产品分类统计 -->
        <div class="card">
            <div class="card-title">📁 产品分类占比</div>
            <?php if (empty($stats['category_stats'])): ?>
            <div class="empty-state"><div class="icon">📭</div><div>暂无分类数据</div></div>
            <?php else: ?>
            <?php 
            $catColors = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899', '#06B6D4', '#84CC16'];
            $totalCatAmount = array_sum(array_column($stats['category_stats'], 'total_amount'));
            $idx = 0;
            foreach ($stats['category_stats'] as $cat): 
                $pct = $totalCatAmount > 0 ? round($cat['total_amount'] / $totalCatAmount * 100, 1) : 0;
                $color = $catColors[$idx % count($catColors)];
            ?>
            <div style="margin-bottom:12px;">
                <div style="display:flex;justify-content:space-between;margin-bottom:4px;font-size:14px;">
                    <span style="color:<?php echo $color; ?>;">● <?php echo h($cat['category'] ?: '未分类'); ?></span>
                    <span><strong><?php echo formatMoney($cat['total_amount']); ?></strong> <span style="color:#9CA3AF;">(<?php echo $pct; ?>%)</span></span>
                </div>
                <div class="rank-bar"><div class="rank-bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $color; ?>;"></div></div>
            </div>
            <?php $idx++; endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>

    <!-- 客户消费排行 -->
    <?php if ($reportType === 'overview' || $reportType === 'customers'): ?>
    <div class="card">
        <div class="card-title">👥 客户消费排行 TOP 20</div>
        <?php if (empty($stats['customer_rank'])): ?>
        <div class="empty-state"><div class="icon">📭</div><div>暂无客户消费数据</div></div>
        <?php else: ?>
        <div class="grid-2">
            <!-- 可视化排行 -->
            <div class="rank-list">
                <?php 
                $maxCustomerAmount = $stats['customer_rank'][0]['total_amount'] ?? 1;
                $rank = 1;
                foreach (array_slice($stats['customer_rank'], 0, 10) as $customer):
                    $pct = round($customer['total_amount'] / $maxCustomerAmount * 100);
                    $rankClass = $rank <= 3 ? 'rank-' . $rank : 'rank-other';
                ?>
                <div class="rank-item">
                    <div class="rank-num <?php echo $rankClass; ?>"><?php echo $rank; ?></div>
                    <div class="rank-content">
                        <div class="rank-name"><?php echo h($customer['name']); ?></div>
                        <div class="rank-bar"><div class="rank-bar-fill" style="width:<?php echo $pct; ?>%;"></div></div>
                        <div class="rank-info">
                            <span><?php echo $customer['order_count']; ?> 笔订单</span>
                            <span class="money-red"><?php echo formatMoney($customer['total_amount']); ?></span>
                        </div>
                    </div>
                </div>
                <?php $rank++; endforeach; ?>
            </div>
            
            <!-- 详细表格 -->
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>客户名称</th>
                            <th>电话</th>
                            <th style="text-align:center;">订单</th>
                            <th style="text-align:right;">总额</th>
                            <th style="text-align:right;">已付</th>
                            <th style="text-align:right;">欠款</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rank = 1; foreach ($stats['customer_rank'] as $customer): ?>
                        <tr>
                            <td><?php echo $rank; ?></td>
                            <td><a href="customer_view.php?id=<?php echo $customer['id']; ?>" class="td-link"><?php echo h($customer['name']); ?></a></td>
                            <td class="td-muted"><?php echo h($customer['phone'] ?? '-'); ?></td>
                            <td style="text-align:center;"><?php echo $customer['order_count']; ?></td>
                            <td class="td-money money-red"><?php echo formatMoney($customer['total_amount']); ?></td>
                            <td class="td-money money-green"><?php echo formatMoney($customer['paid_amount']); ?></td>
                            <td class="td-money <?php echo $customer['unpaid_amount'] > 0 ? 'money-orange' : ''; ?>"><?php echo formatMoney($customer['unpaid_amount']); ?></td>
                        </tr>
                        <?php $rank++; endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- 产品销售排行 -->
    <?php if ($reportType === 'overview' || $reportType === 'products'): ?>
    <?php if (!empty($stats['product_rank']) || $reportType === 'products'): ?>
    <div class="card">
        <div class="card-title">📦 产品销售排行 TOP 20</div>
        <?php if (empty($stats['product_rank'])): ?>
        <div class="empty-state"><div class="icon">📭</div><div>暂无产品销售数据</div></div>
        <?php else: ?>
        <div class="grid-2">
            <!-- 可视化排行 -->
            <div class="rank-list">
                <?php 
                $maxProductAmount = $stats['product_rank'][0]['total_amount'] ?? 1;
                $rank = 1;
                $prodColors = ['#10B981', '#06B6D4', '#8B5CF6', '#EC4899', '#F59E0B'];
                foreach (array_slice($stats['product_rank'], 0, 10) as $product):
                    $pct = round($product['total_amount'] / $maxProductAmount * 100);
                    $color = $prodColors[($rank - 1) % count($prodColors)];
                ?>
                <div class="rank-item">
                    <div class="rank-num <?php echo $rank <= 3 ? 'rank-'.$rank : 'rank-other'; ?>" style="background:<?php echo $color; ?>;"><?php echo $rank; ?></div>
                    <div class="rank-content">
                        <div class="rank-name"><?php echo h($product['product_name']); ?></div>
                        <div class="rank-bar"><div class="rank-bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $color; ?>;"></div></div>
                        <div class="rank-info">
                            <span><?php echo intval($product['total_quantity']); ?> 件</span>
                            <span class="money-red"><?php echo formatMoney($product['total_amount']); ?></span>
                        </div>
                    </div>
                </div>
                <?php $rank++; endforeach; ?>
            </div>
            
            <!-- 详细表格 -->
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>产品名称</th>
                            <th style="text-align:center;">销售数量</th>
                            <th style="text-align:right;">销售金额</th>
                            <th style="text-align:right;">平均单价</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rank = 1; foreach ($stats['product_rank'] as $product): 
                            $avgPrice = $product['total_quantity'] > 0 ? $product['total_amount'] / $product['total_quantity'] : 0;
                        ?>
                        <tr>
                            <td><?php echo $rank; ?></td>
                            <td><strong><?php echo h($product['product_name']); ?></strong></td>
                            <td style="text-align:center;"><?php echo intval($product['total_quantity']); ?></td>
                            <td class="td-money money-red"><?php echo formatMoney($product['total_amount']); ?></td>
                            <td class="td-muted"><?php echo formatMoney($avgPrice); ?>/件</td>
                        </tr>
                        <?php $rank++; endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- 应收款统计 -->
    <?php if ($reportType === 'receivables'): ?>
    <div class="card">
        <div class="card-title">💰 应收款统计</div>
        <?php 
        $totalUnpaid = array_sum(array_column($stats['receivables'], 'unpaid_amount'));
        if ($totalUnpaid > 0): 
        ?>
        <div style="background:linear-gradient(135deg,#FEF3C7,#FEE2E2);padding:20px;border-radius:12px;margin-bottom:20px;text-align:center;">
            <div style="font-size:14px;color:#92400E;margin-bottom:8px;">⚠️ 当前总欠款</div>
            <div style="font-size:36px;font-weight:700;color:#DC2626;"><?php echo formatMoney($totalUnpaid); ?></div>
            <div style="font-size:14px;color:#92400E;margin-top:8px;">共 <?php echo count($stats['receivables']); ?> 位客户有欠款</div>
        </div>
        <?php endif; ?>
        
        <?php if (empty($stats['receivables'])): ?>
        <div class="empty-state"><div class="icon">🎉</div><div>太棒了！暂无应收款</div></div>
        <?php else: ?>
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>客户名称</th>
                        <th>联系电话</th>
                        <th style="text-align:center;">订单数</th>
                        <th style="text-align:right;">订单总额</th>
                        <th style="text-align:right;">已付款</th>
                        <th style="text-align:right;">欠款金额</th>
                        <th style="text-align:center;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats['receivables'] as $customer): ?>
                    <tr>
                        <td><a href="customer_view.php?id=<?php echo $customer['id']; ?>" class="td-link"><?php echo h($customer['name']); ?></a></td>
                        <td class="td-muted"><?php echo h($customer['phone'] ?? '-'); ?></td>
                        <td style="text-align:center;"><?php echo $customer['order_count']; ?></td>
                        <td class="td-money"><?php echo formatMoney($customer['total_amount']); ?></td>
                        <td class="td-money money-green"><?php echo formatMoney($customer['paid_amount']); ?></td>
                        <td class="td-money money-orange" style="font-size:16px;font-weight:700;"><?php echo formatMoney($customer['unpaid_amount']); ?></td>
                        <td style="text-align:center;">
                            <a href="statement.php?customer_id=<?php echo $customer['id']; ?>" class="btn btn-primary" style="font-size:12px;padding:4px 12px;">📋 对账单</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <script>
    function exportCSV(type) {
        let csv = '\uFEFF';
        let params = new URLSearchParams(window.location.search);
        let startDate = params.get('start_date') || '<?php echo date('Y-m-01'); ?>';
        let endDate = params.get('end_date') || '<?php echo date('Y-m-d'); ?>';

        if (type === 'customer') {
            csv += '排名,客户名称,联系电话,订单数,订单总额,已付款,欠款金额\n';
            <?php foreach ($stats['customer_rank'] as $i => $c): ?>
            csv += '<?php echo $i+1; ?>,<?php echo h($c['name']); ?>,<?php echo h($c['phone'] ?? ''); ?>,<?php echo $c['order_count']; ?>,<?php echo $c['total_amount']; ?>,<?php echo $c['paid_amount']; ?>,<?php echo $c['unpaid_amount']; ?>\n';
            <?php endforeach; ?>
        } else if (type === 'product') {
            csv += '排名,产品名称,销售数量,销售金额\n';
            <?php foreach ($stats['product_rank'] as $i => $p): ?>
            csv += '<?php echo $i+1; ?>,<?php echo h($p['product_name']); ?>,<?php echo intval($p['total_quantity']); ?>,<?php echo $p['total_amount']; ?>\n';
            <?php endforeach; ?>
        }

        let blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        let link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = type + '_rank_' + startDate + '_' + endDate + '.csv';
        link.click();
    }
    </script>
</body>
</html>
