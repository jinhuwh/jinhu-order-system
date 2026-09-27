<?php
/**
 * 手机端 - 数据统计报表
 * 简化版：本月核心数据 + Top 客户/产品
 */

require_once 'config.php';
initDatabase();
checkLogin();

$db = getDB();

// 统计周期（支持从 GET 传入，缺省为本月）
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate   = $_GET['end_date']   ?? date('Y-m-d');

// 总体统计
$stmt = $db->prepare("
    SELECT
        COUNT(*) as total_orders,
        COALESCE(SUM(total_amount), 0) as total_sales,
        COALESCE(SUM(paid_amount), 0) as total_paid,
        COALESCE(SUM(COALESCE(discount_amount, 0)), 0) as total_discount,
        COALESCE(SUM(GREATEST(0, total_amount - COALESCE(discount_amount, 0) - paid_amount)), 0) as total_unpaid
    FROM orders
    WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
");
$stmt->execute([$startDate, $endDate]);
$overview = $stmt->fetch(PDO::FETCH_ASSOC);

// 今日数据
$today = date('Y-m-d');
$stmt = $db->prepare("
    SELECT
        COUNT(*) as today_orders,
        COALESCE(SUM(total_amount), 0) as today_sales
    FROM orders
    WHERE DATE(created_at) = ?
");
$stmt->execute([$today]);
$todayStats = $stmt->fetch(PDO::FETCH_ASSOC);

// 客户消费排行 TOP 5
$stmt = $db->prepare("
    SELECT
        c.name,
        COUNT(o.id) as order_count,
        COALESCE(SUM(o.total_amount), 0) as total_amount
    FROM customers c
    INNER JOIN orders o ON c.id = o.customer_id
    WHERE o.created_at >= ? AND o.created_at <= DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY c.id
    ORDER BY total_amount DESC
    LIMIT 5
");
$stmt->execute([$startDate, $endDate]);
$topCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 产品销售排行 TOP 5
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
    LIMIT 5
");
$stmt->execute([$startDate, $endDate]);
$topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── CSV 导出（必须在任何 HTML 输出之前处理并 exit）──
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM，保证 Excel 中文不乱码
    header('Content-Disposition: attachment; filename=report_' . str_replace('-', '', $startDate) . '_' . str_replace('-', '', $endDate) . '.csv');

    $out = fopen('php://output', 'w');

    fputcsv($out, ['数据统计报表', $startDate . ' ~ ' . $endDate]);
    fputcsv($out, []);

    fputcsv($out, ['总体统计']);
    fputcsv($out, ['订单数', '销售总额', '已收款', '未收款']);
    fputcsv($out, [
        $overview['total_orders'],
        $overview['total_sales'],
        $overview['total_paid'],
        $overview['total_unpaid'],
    ]);
    fputcsv($out, []);

    fputcsv($out, ['客户排行 TOP 5']);
    fputcsv($out, ['客户名称', '订单数', '消费金额']);
    foreach ($topCustomers as $c) {
        fputcsv($out, [h($c['name']), $c['order_count'], $c['total_amount']]);
    }
    fputcsv($out, []);

    fputcsv($out, ['产品排行 TOP 5']);
    fputcsv($out, ['产品名称', '销售数量', '销售金额']);
    foreach ($topProducts as $p) {
        fputcsv($out, [h($p['product_name']), $p['total_quantity'], $p['total_amount']]);
    }

    fclose($out);
    exit;
}

$companyName = getSetting('company_name') ?: '广告公司';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>数据统计 - <?php echo htmlspecialchars($companyName); ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .stat-card { background: white; border-radius: var(--radius); padding: 16px; margin-bottom: 16px; box-shadow: var(--shadow); }
        .stat-card .card-title { font-size: 15px; font-weight: 600; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
        
        .stat-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .stat-item { background: #f8f9fa; border-radius: var(--radius-sm); padding: 12px; text-align: center; }
        .stat-item .label { font-size: 12px; color: #999; margin-bottom: 6px; }
        .stat-item .value { font-size: 20px; font-weight: 700; color: var(--primary); }
        .stat-item .value.small { font-size: 16px; }
        
        .stat-item.highlight { background: var(--primary); color: white; }
        .stat-item.highlight .label { color: rgba(255,255,255,0.8); }
        .stat-item.highlight .value { color: white; }
        
        .rank-list { margin-top: 12px; }
        .rank-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--border); }
        .rank-item:last-child { border-bottom: none; }
        .rank-item .rank { width: 24px; height: 24px; border-radius: 50%; background: var(--primary); color: white; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; margin-right: 10px; }
        .rank-item .rank.r1 { background: #F59E0B; }
        .rank-item .rank.r2 { background: #94A3B8; }
        .rank-item .rank.r3 { background: #CD7F32; }
        .rank-item .name { flex: 1; font-size: 14px; }
        .rank-item .amount { font-size: 14px; font-weight: 600; color: var(--primary); }
        
        .empty-tip { text-align: center; padding: 30px; color: #999; font-size: 14px; }
    </style>
</head>
<body>
    <!-- 顶部栏 -->
    <div class="page-header">
        <h1>📊 数据统计</h1>
    </div>
    
    <div class="page">
        <!-- 本月概览 -->
        <div class="stat-card">
            <div class="card-title">📅 本月概览 (<?php echo date('Y年m月'); ?>)</div>
            <div class="stat-grid">
                <div class="stat-item highlight">
                    <div class="label">订单数</div>
                    <div class="value"><?php echo $overview['total_orders']; ?></div>
                </div>
                <div class="stat-item highlight">
                    <div class="label">销售额</div>
                    <div class="value small">¥<?php echo number_format($overview['total_sales'], 0); ?></div>
                </div>
                <div class="stat-item">
                    <div class="label">已收款</div>
                    <div class="value">¥<?php echo number_format($overview['total_paid'], 0); ?></div>
                </div>
                <?php $mobileReportDiscount = floatval($overview['total_discount'] ?? 0); ?>
                <?php if ($mobileReportDiscount > 0.01): ?>
                <div class="stat-item">
                    <div class="label">优惠</div>
                    <div class="value" style="color:#F59E0B;">¥<?php echo number_format($mobileReportDiscount, 0); ?></div>
                </div>
                <?php endif; ?>
                <div class="stat-item">
                    <div class="label">未收款</div>
                    <div class="value" style="color: #EF4444;">¥<?php echo number_format($overview['total_unpaid'], 0); ?></div>
                </div>
            </div>
        </div>
        
        <!-- 今日数据 -->
        <div class="stat-card">
            <div class="card-title">📆 今日数据 (<?php echo date('m月d日'); ?>)</div>
            <div class="stat-grid">
                <div class="stat-item">
                    <div class="label">今日订单</div>
                    <div class="value"><?php echo $todayStats['today_orders']; ?></div>
                </div>
                <div class="stat-item">
                    <div class="label">今日销售额</div>
                    <div class="value small">¥<?php echo number_format($todayStats['today_sales'], 0); ?></div>
                </div>
            </div>
        </div>
        
        <!-- Top 5 客户 -->
        <div class="stat-card">
            <div class="card-title">🏆 客户排行 (TOP 5)</div>
            <?php if (empty($topCustomers)): ?>
            <div class="empty-tip">本月暂无数据</div>
            <?php else: ?>
            <div class="rank-list">
                <?php foreach ($topCustomers as $i => $c): ?>
                <div class="rank-item">
                    <div class="rank <?php echo $i == 0 ? 'r1' : ($i == 1 ? 'r2' : ($i == 2 ? 'r3' : '')); ?>">
                        <?php echo $i + 1; ?>
                    </div>
                    <div class="name"><?php echo htmlspecialchars($c['name']); ?></div>
                    <div class="amount">¥<?php echo number_format($c['total_amount'], 0); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Top 5 产品 -->
        <div class="stat-card">
            <div class="card-title">📦 产品排行 (TOP 5)</div>
            <?php if (empty($topProducts)): ?>
            <div class="empty-tip">本月暂无数据</div>
            <?php else: ?>
            <div class="rank-list">
                <?php foreach ($topProducts as $i => $p): ?>
                <div class="rank-item">
                    <div class="rank <?php echo $i == 0 ? 'r1' : ($i == 1 ? 'r2' : ($i == 2 ? 'r3' : '')); ?>">
                        <?php echo $i + 1; ?>
                    </div>
                    <div class="name"><?php echo htmlspecialchars($p['product_name']); ?></div>
                    <div class="amount">¥<?php echo number_format($p['total_amount'], 0); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- 查看完整报表 / 导出 -->
        <div class="stat-card" style="text-align: center;">
            <a href="../reports_pdf.php?start_date=<?php echo h($startDate); ?>&end_date=<?php echo h($endDate); ?>" target="_blank" class="btn btn-primary">📄 导出 PDF 报表</a>
            <div style="margin-top:10px;">
                <a href="reports.php?export=csv&start_date=<?php echo h($startDate); ?>&end_date=<?php echo h($endDate); ?>" class="btn btn-outline">📊 导出 CSV</a>
            </div>
            <div style="margin-top:10px;">
                <a href="../reports.php" class="btn btn-outline">📈 查看完整报表 (PC端)</a>
            </div>
            <p style="color: #999; font-size: 12px; margin-top: 8px;">完整报表请在电脑端查看</p>
        </div>
    </div>
    
    <?php echo mobileNav('more'); ?>
</body>
</html>
