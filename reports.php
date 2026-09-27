<?php
/**
 * 数据统计报表
 *
 * 功能：
 * - 客户消费排行
 * - 产品销售排行
 * - 应收款统计
 * - 月度销售趋势
 * - 订单状态分布
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

// 4. 应收款统计（按客户）
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

// 5. 月度销售趋势（最近12个月）
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
$stats['monthly_trend'] = array_reverse($stats['monthly_trend']); // 按时间正序

// 6. 订单状态分布
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
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>数据统计报表 - <?php echo htmlspecialchars($companyName); ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .reports-2col { display: grid; grid-template-columns: 1.4fr 1fr; gap: 16px; }
        @media (max-width:768px) { .reports-2col { grid-template-columns: 1fr; } }

        .rank-badge {
            display: inline-flex; align-items: center; justify-content: center;
            width: 28px; height: 28px; border-radius: 50%;
            font-weight: 700; font-size: 13px; color: #fff;
        }
        .rank-1 { background: linear-gradient(135deg, #FFD700, #FFA500); }
        .rank-2 { background: linear-gradient(135deg, #C0C0C0, #909090); }
        .rank-3 { background: linear-gradient(135deg, #CD7F32, #8B4513); }
        .rank-other { background: var(--gray-200, #E5E7EB); color: var(--gray-700, #374151); }

        .order-count-pill {
            display: inline-flex; align-items: center; justify-content: center;
            background: var(--primary-light, #DBEAFE); color: var(--primary, #2563EB);
            padding: 3px 12px; min-width: 36px; height: 22px;
            border-radius: 11px; font-size: 13px; font-weight: 700;
        }

        .money-strong { color: var(--danger, #EF4444); font-weight: 600; }
        .money-paid { color: #10B981; font-weight: 600; }
        .money-pending { color: #F59E0B; font-weight: 600; }

        .status-bar {
            height: 6px; background: var(--gray-100, #F3F4F6);
            border-radius: 3px; overflow: hidden; margin-top: 6px;
        }
        .status-bar-fill { height: 100%; transition: width 0.3s; }

        .reports-toolbar { display: flex; gap: 8px; }
    </style>
</head>
<body>
    <?php echo renderNav('reports'); ?>

    <div class="page-title-bar">
        <div class="page-title-left">
            <h1>数据统计报表</h1>
            <p>客户消费排行、产品销售排行、应收款统计</p>
        </div>
        <div class="reports-toolbar">
            <button class="btn btn-success" onclick="exportCSV('customer')">📥 导出客户</button>
            <button class="btn btn-success" onclick="exportCSV('product')">📥 导出产品</button>
            <a href="reports_pdf.php?start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>" class="btn btn-danger" target="_blank">📄 导出 PDF</a>
        </div>
    </div>

    <!-- 筛选区域 -->
    <div class="card">
        <div class="card-body">
            <form class="filter-bar" method="GET" id="filterForm">
                <span class="filter-label">统计区间：</span>
                <input type="date" name="start_date" class="filter-input" value="<?php echo htmlspecialchars($startDate); ?>">
                <span class="filter-sep">至</span>
                <input type="date" name="end_date" class="filter-input" value="<?php echo htmlspecialchars($endDate); ?>">
                <select name="type" class="filter-input" style="min-width:140px;">
                    <option value="overview" <?php echo $reportType === 'overview' ? 'selected' : ''; ?>>📊 综合概览</option>
                    <option value="customers" <?php echo $reportType === 'customers' ? 'selected' : ''; ?>>👥 客户排行</option>
                    <option value="products" <?php echo $reportType === 'products' ? 'selected' : ''; ?>>📦 产品排行</option>
                    <option value="receivables" <?php echo $reportType === 'receivables' ? 'selected' : ''; ?>>💰 应收款统计</option>
                </select>
                <button type="submit" class="btn btn-primary">🔍 查询</button>
                <a href="reports.php" class="btn btn-ghost">重置</a>
            </form>
        </div>
    </div>

    <!-- 综合概览统计卡片 -->
    <?php if ($reportType === 'overview'): ?>
    <div class="stats-grid">
        <div class="stat-card blue animate-in delay-1">
            <div class="stat-card-header">
                <span class="stat-card-label">订单总数</span>
                <div class="stat-card-icon">📋</div>
            </div>
            <div class="stat-card-value"><?php echo $stats['overview']['total_orders']; ?></div>
            <div class="stat-card-trend neutral">笔订单</div>
        </div>
        <div class="stat-card purple animate-in delay-2">
            <div class="stat-card-header">
                <span class="stat-card-label">销售总额</span>
                <div class="stat-card-icon">💎</div>
            </div>
            <div class="stat-card-value money"><?php echo formatMoney($stats['overview']['total_sales']); ?></div>
            <div class="stat-card-trend up">↑ 营收</div>
        </div>
        <div class="stat-card green animate-in delay-3">
            <div class="stat-card-header">
                <span class="stat-card-label">已收款</span>
                <div class="stat-card-icon">✅</div>
            </div>
            <div class="stat-card-value money"><?php echo formatMoney($stats['overview']['total_paid']); ?></div>
            <div class="stat-card-trend up">↑ 已收</div>
        </div>
        <div class="stat-card red animate-in delay-4">
            <div class="stat-card-header">
                <span class="stat-card-label">待收款</span>
                <div class="stat-card-icon">⏳</div>
            </div>
            <div class="stat-card-value money"><?php echo formatMoney($stats['overview']['total_unpaid']); ?></div>
            <div class="stat-card-trend down">↓ 待收</div>
        </div>
    </div>

    <!-- 月度趋势 + 状态分布 -->
    <div class="reports-2col">
        <div class="card animate-in delay-2">
            <div class="card-header">
                <div class="card-title"><span class="title-icon">📈</span> 月度销售趋势（最近12个月）</div>
            </div>
            <div class="card-body" style="padding:0;">
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="width:25%;">月份</th>
                                <th style="width:20%;text-align:center;">订单数</th>
                                <th style="width:27.5%;text-align:right;">销售额</th>
                                <th style="width:27.5%;text-align:right;">已收款</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['monthly_trend'] as $item): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($item['month']); ?></strong></td>
                                <td style="text-align:center;"><span class="order-count-pill"><?php echo $item['order_count']; ?></span></td>
                                <td class="td-money money-strong"><?php echo formatMoney($item['total_amount']); ?></td>
                                <td class="td-money money-paid"><?php echo formatMoney($item['paid_amount']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($stats['monthly_trend'])): ?>
                            <tr><td colspan="4" class="empty-state"><div class="icon">📭</div><div class="text">暂无月度数据</div></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card animate-in delay-3">
            <div class="card-header">
                <div class="card-title"><span class="title-icon">🎯</span> 订单状态分布</div>
            </div>
            <div class="card-body" style="padding:0;">
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>状态</th>
                                <th style="text-align:center;">数量</th>
                                <th style="text-align:right;">金额</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['order_status'] as $item):
                                $pct = $totalStatusCount > 0 ? round($item['count'] / $totalStatusCount * 100) : 0;
                            ?>
                            <tr>
                                <td>
                                    <span class="badge badge-<?php echo getStatusClass($item['status']); ?>"><?php echo getStatusText($item['status']); ?></span>
                                    <div class="status-bar"><div class="status-bar-fill" style="width:<?php echo $pct; ?>%;background:var(--primary,#2563EB);"></div></div>
                                </td>
                                <td style="text-align:center;"><?php echo $item['count']; ?> <span class="td-muted" style="font-size:12px;">(<?php echo $pct; ?>%)</span></td>
                                <td class="td-money money-strong"><?php echo formatMoney($item['total_amount']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($stats['order_status'])): ?>
                            <tr><td colspan="3" class="empty-state"><div class="icon">📭</div><div class="text">暂无状态数据</div></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 客户消费排行 -->
    <?php if ($reportType === 'overview' || $reportType === 'customers'): ?>
    <div class="card" style="margin-top:16px;">
        <div class="card-header">
            <div class="card-title"><span class="title-icon">👥</span> 客户消费排行 TOP 20</div>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width:60px;">排名</th>
                            <th>客户名称</th>
                            <th>联系电话</th>
                            <th style="text-align:center;">订单数</th>
                            <th style="text-align:right;">订单总额</th>
                            <th style="text-align:right;">已付款</th>
                            <th style="text-align:right;">欠款金额</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rank = 1; foreach ($stats['customer_rank'] as $customer):
                            $rankClass = $rank <= 3 ? 'rank-' . $rank : 'rank-other';
                        ?>
                        <tr>
                            <td><span class="rank-badge <?php echo $rankClass; ?>"><?php echo $rank; ?></span></td>
                            <td><a href="customer_view.php?id=<?php echo $customer['id']; ?>" class="td-link"><?php echo htmlspecialchars($customer['name']); ?></a></td>
                            <td class="td-muted"><?php echo htmlspecialchars($customer['phone'] ?? '-'); ?></td>
                            <td style="text-align:center;"><span class="order-count-pill"><?php echo $customer['order_count']; ?></span></td>
                            <td class="td-money money-strong"><?php echo formatMoney($customer['total_amount']); ?></td>
                            <td class="td-money money-paid"><?php echo formatMoney($customer['paid_amount']); ?></td>
                            <td class="td-money <?php echo $customer['unpaid_amount'] > 0 ? 'money-pending' : 'td-muted'; ?>"><?php echo formatMoney($customer['unpaid_amount']); ?></td>
                        </tr>
                        <?php $rank++; endforeach; ?>
                        <?php if (empty($stats['customer_rank'])): ?>
                        <tr><td colspan="7" class="empty-state"><div class="icon">📭</div><div class="text">暂无客户消费数据</div></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 产品销售排行 -->
    <?php if ($reportType === 'overview' || $reportType === 'products'): ?>
    <?php if (!empty($stats['product_rank']) || $reportType === 'products'): ?>
    <div class="card" style="margin-top:16px;">
        <div class="card-header">
            <div class="card-title"><span class="title-icon">📦</span> 产品销售排行 TOP 20</div>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width:60px;">排名</th>
                            <th>产品名称</th>
                            <th style="text-align:center;">销售数量</th>
                            <th style="text-align:right;">销售金额</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rank = 1; foreach ($stats['product_rank'] as $product):
                            $rankClass = $rank <= 3 ? 'rank-' . $rank : 'rank-other';
                        ?>
                        <tr>
                            <td><span class="rank-badge <?php echo $rankClass; ?>"><?php echo $rank; ?></span></td>
                            <td><strong><?php echo htmlspecialchars($product['product_name']); ?></strong></td>
                            <td style="text-align:center;"><span class="order-count-pill"><?php echo intval($product['total_quantity']); ?></span></td>
                            <td class="td-money money-strong"><?php echo formatMoney($product['total_amount']); ?></td>
                        </tr>
                        <?php $rank++; endforeach; ?>
                        <?php if (empty($stats['product_rank'])): ?>
                        <tr><td colspan="4" class="empty-state"><div class="icon">📭</div><div class="text">暂无产品销售数据</div></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- 应收款统计 -->
    <?php if ($reportType === 'receivables'): ?>
    <div class="card" style="margin-top:16px;">
        <div class="card-header">
            <div class="card-title"><span class="title-icon">💰</span> 应收款统计</div>
            <?php if (!empty($stats['receivables'])): ?>
            <span class="badge badge-danger" style="font-size:14px;padding:6px 14px;">
                总欠款：<?php echo formatMoney(array_sum(array_column($stats['receivables'], 'unpaid_amount'))); ?>
            </span>
            <?php endif; ?>
        </div>
        <div class="card-body" style="padding:0;">
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
                            <td><a href="customer_view.php?id=<?php echo $customer['id']; ?>" class="td-link"><?php echo htmlspecialchars($customer['name']); ?></a></td>
                            <td class="td-muted"><?php echo htmlspecialchars($customer['phone'] ?? '-'); ?></td>
                            <td style="text-align:center;"><span class="order-count-pill"><?php echo $customer['order_count']; ?></span></td>
                            <td class="td-money money-strong"><?php echo formatMoney($customer['total_amount']); ?></td>
                            <td class="td-money money-paid"><?php echo formatMoney($customer['paid_amount']); ?></td>
                            <td class="td-money" style="color:var(--danger,#EF4444);font-weight:700;font-size:15px;"><?php echo formatMoney($customer['unpaid_amount']); ?></td>
                            <td style="text-align:center;">
                                <a href="statement.php?customer_id=<?php echo $customer['id']; ?>" class="btn btn-primary" style="font-size:12px;padding:4px 12px;">📋 对账单</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($stats['receivables'])): ?>
                        <tr><td colspan="7" class="empty-state"><div class="icon">🎉</div><div class="text">太棒了！暂无应收款</div></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php echo renderFooter(); ?>

    <script>
    function exportCSV(type) {
        let csv = '\uFEFF';
        let params = new URLSearchParams(window.location.search);
        let startDate = params.get('start_date') || '<?php echo date('Y-m-01'); ?>';
        let endDate = params.get('end_date') || '<?php echo date('Y-m-d'); ?>';

        if (type === 'customer') {
            csv += '排名,客户名称,联系电话,订单数,订单总额,已付款,欠款金额\n';
            let tables = document.querySelectorAll('table.data-table');
            let target = null;
            tables.forEach(function(t) {
                if (t.querySelector('thead th:nth-child(2)') &&
                    t.querySelector('thead th:nth-child(2)').textContent.trim() === '客户名称') {
                    target = t;
                }
            });
            if (target) {
                target.querySelectorAll('tbody tr').forEach(function(tr) {
                    let tds = tr.querySelectorAll('td');
                    if (tds.length >= 7) {
                        let rank = tds[0].textContent.trim();
                        let name = tds[1].textContent.trim();
                        let phone = tds[2].textContent.trim();
                        let orders = tds[3].textContent.trim();
                        let total = tds[4].textContent.trim().replace(/[^\d.]/g, '');
                        let paid = tds[5].textContent.trim().replace(/[^\d.]/g, '');
                        let unpaid = tds[6].textContent.trim().replace(/[^\d.]/g, '');
                        csv += rank + ',' + name + ',' + phone + ',' + orders + ',' + total + ',' + paid + ',' + unpaid + '\n';
                    }
                });
            }
        } else if (type === 'product') {
            csv += '排名,产品名称,销售数量,销售金额\n';
            let tables = document.querySelectorAll('table.data-table');
            let target = null;
            tables.forEach(function(t) {
                if (t.querySelector('thead th:nth-child(2)') &&
                    t.querySelector('thead th:nth-child(2)').textContent.trim() === '产品名称') {
                    target = t;
                }
            });
            if (target) {
                target.querySelectorAll('tbody tr').forEach(function(tr) {
                    let tds = tr.querySelectorAll('td');
                    if (tds.length >= 4) {
                        let rank = tds[0].textContent.trim();
                        let name = tds[1].textContent.trim();
                        let qty = tds[2].textContent.trim();
                        let total = tds[3].textContent.trim().replace(/[^\d.]/g, '');
                        csv += rank + ',' + name + ',' + qty + ',' + total + '\n';
                    }
                });
            }
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
