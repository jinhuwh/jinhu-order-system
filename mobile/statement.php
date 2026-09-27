<?php
/**
 * 移动端 - 客户对账单
 */
require_once 'config.php';
initDatabase();
checkLogin();

$db = getDB();

$customerId = intval($_GET['customer_id'] ?? 0);
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';

$companyName = getSetting('company_name') ?: '广告公司';
$customers = $db->query("SELECT * FROM customers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// 查询条件构建
$where = "WHERE o.status != 4";
$params = [];
if ($customerId > 0) {
    $where .= " AND o.customer_id = ?";
    $params[] = $customerId;
}
if (!empty($startDate)) {
    $where .= " AND DATE(o.created_at) >= ?";
    $params[] = $startDate;
}
if (!empty($endDate)) {
    $where .= " AND DATE(o.created_at) <= ?";
    $params[] = $endDate;
}

$sql = "SELECT o.*, c.name as customer_name FROM orders o LEFT JOIN customers c ON o.customer_id = c.id $where ORDER BY o.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 统计（含收款 + 优惠）
$totalAmount = 0;
$totalPaid = 0;
$totalDiscount = 0;
$orderCount = count($orders);
foreach ($orders as $o) {
    $totalAmount += floatval($o['total_amount']);
    $totalPaid += floatval($o['paid_amount']);
    $totalDiscount += floatval($o['discount_amount'] ?? 0);
}

// 按客户汇总
$summarySql = "SELECT c.name, COUNT(o.id) as cnt, COALESCE(SUM(o.total_amount), 0) as total
               FROM customers c LEFT JOIN orders o ON c.id = o.customer_id AND o.status != 4";
if (!empty($startDate)) {
    $summarySql .= " AND DATE(o.created_at) >= ?";
}
if (!empty($endDate)) {
    $summarySql .= " AND DATE(o.created_at) <= ?";
}
$summarySql .= " GROUP BY c.id ORDER BY total DESC";

$sParams = [];
if (!empty($startDate)) $sParams[] = $startDate;
if (!empty($endDate)) $sParams[] = $endDate;

$sumStmt = $db->prepare($summarySql);
$sumStmt->execute($sParams);
$summaries = $sumStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>客户对账单 - <?php echo htmlspecialchars($companyName); ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="page-header">
        <h1>🧾 客户对账单</h1>
        <div class="user-bar">
            <span>共 <?php echo $orderCount; ?> 单</span>
            <a href="../logout.php">退出</a>
        </div>
    </div>

    <div class="page">
        <!-- 筛选 -->
        <div class="filter-card">
            <form method="GET">
                <div class="form-group">
                    <label>客户</label>
                    <select name="customer_id" class="form-control">
                        <option value="">全部客户</option>
                        <?php foreach ($customers as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo $customerId == $c['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>开始日期</label>
                        <input type="date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>结束日期</label>
                        <input type="date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>" class="form-control">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-block btn-sm">🔍 筛选</button>
            </form>
        </div>

        <!-- 汇总统计 -->
        <div class="summary-grid">
            <div class="summary-item">
                <div class="num"><?php echo $orderCount; ?></div>
                <div class="label">订单数</div>
            </div>
            <div class="summary-item">
                <div class="num money" style="font-size:16px;">¥<?php echo number_format($totalAmount, 2); ?></div>
                <div class="label">应收总额</div>
            </div>
            <div class="summary-item" style="background:#F0FDF4;border-color:#86EFAC;">
                <div class="num money" style="color:#059669;font-size:16px;">¥<?php echo number_format($totalPaid, 2); ?></div>
                <div class="label">已收款 ✅</div>
            </div>
            <div class="summary-item" style="background:#FEF2F2;border-color:#FCA5A5;">
                <div class="num money" style="color:#DC2626;font-size:16px;">¥<?php echo number_format(max(0, $totalAmount - $totalPaid - $totalDiscount), 2); ?></div>
                <div class="label">未收款 ⏳</div>
            </div>
        </div>

        <!-- PDF 导出按钮 -->
        <?php if ($orderCount > 0): ?>
        <div style="margin:12px 0;">
            <a href="../statement_pdf.php?customer_id=<?php echo $customerId; ?>&start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>" class="btn btn-danger btn-block" target="_blank">📄 导出 PDF</a>
        </div>
        <?php endif; ?>

        <!-- 按客户汇总 -->
        <?php if (empty($customerId)): ?>
        <div class="card">
            <div class="card-title">📊 按客户汇总</div>
            <?php foreach ($summaries as $s): ?>
            <a href="?customer_id=<?php echo $s['id']; ?>&start_date=<?php echo htmlspecialchars($startDate); ?>&end_date=<?php echo htmlspecialchars($endDate); ?>" style="text-decoration:none;color:inherit;">
                <div class="list-item">
                    <div class="item-content">
                        <div class="item-title"><?php echo htmlspecialchars($s['name']); ?></div>
                        <div class="item-desc"><?php echo $s['cnt']; ?> 个订单</div>
                    </div>
                    <div class="item-right">
                        <div style="font-weight:700;color:var(--primary);font-size:15px;">¥<?php echo number_format(floatval($s['total']), 2); ?></div>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- 订单明细列表 -->
        <div class="card">
            <div class="card-title">📋 订单明细</div>

            <?php if (empty($orders)): ?>
            <div class="empty-state" style="padding:30px 0;">
                <div class="icon">📋</div>
                <div class="text">暂无订单数据</div>
            </div>
            <?php else: ?>
                <?php foreach ($orders as $o):
                    $due = max(0, floatval($o['total_amount']) - floatval($o['discount_amount'] ?? 0) - floatval($o['paid_amount']));
                    $statusLabels = ['待确认', '已确认', '生产中', '已完成', '已取消'];
                    $statusClass = ['badge-danger', 'badge-warning', 'badge-warning', 'badge-success', 'badge-danger'];
                ?>
                <a href="order_view.php?id=<?php echo $o['id']; ?>" class="order-card status-<?php echo $o['status']; ?>">
                    <div class="order-header">
                        <div class="order-no"><?php echo $o['order_no']; ?></div>
                        <div class="order-amount">¥<?php echo number_format(floatval($o['total_amount']), 2); ?></div>
                    </div>
                    <div class="customer"><?php echo htmlspecialchars($o['customer_name']); ?></div>
                    <div class="order-footer">
                        <div style="display:flex;align-items:center;gap:6px;">
                            <span class="badge <?php echo $statusClass[$o['status']] ?? 'badge-warning'; ?>"><?php echo $statusLabels[$o['status']] ?? '未知'; ?></span>
                            <span class="order-time"><?php echo date('m-d H:i', strtotime($o['created_at'])); ?></span>
                        </div>
                        <div class="payment-info">
                            <span class="paid">收¥<?php echo number_format(floatval($o['paid_amount']), 2); ?></span>
                            <?php if ($due > 0): ?>
                            <span class="due">· 欠¥<?php echo number_format($due, 2); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>

                <!-- 总计 -->
                <div class="total-bar" style="margin-top:14px;flex-wrap:wrap;gap:8px;">
                    <span class="total-label">合计(<?php echo $orderCount; ?> 单)</span>
                    <span style="display:flex;gap:12px;font-size:13px;">
                        <span>应收:<strong style="color:#DC2626;">¥<?php echo number_format($totalAmount, 2); ?></strong></span>
                        <span>已收:<strong style="color:#059669;">¥<?php echo number_format($totalPaid, 2); ?></strong></span>
                        <span>未收：<strong style="color:#DC2626;">￥<?php echo number_format(max(0, $totalAmount - $totalPaid - $totalDiscount), 2); ?></strong></span>
                    </span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php echo mobileNav('more'); ?>
</body>
</html>
