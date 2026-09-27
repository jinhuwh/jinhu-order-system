<?php
/**
 * 客户对账单
 */
require_once 'config.php';
initDatabase();
checkLogin();

$db = getDB();

// 获取参数
$customerId = intval($_GET['customer_id'] ?? 0);
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';

// 默认显示当月
if (empty($startDate) && empty($endDate)) {
    $startDate = date('Y-m-01');
    $endDate = date('Y-m-d');
}

// 获取客户列表
$customers = $db->query("SELECT id, name FROM customers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// 构建查询
$where = ["o.status != 4"];
$params = [];

if ($customerId > 0) {
    $where[] = "o.customer_id = ?";
    $params[] = $customerId;
}
if (!empty($startDate)) {
    $where[] = "o.created_at >= ?";
    $params[] = $startDate . ' 00:00:00';
}
if (!empty($endDate)) {
    $where[] = "o.created_at <= ?";
    $params[] = $endDate . ' 23:59:59';
}

$whereSql = implode(' AND ', $where);

// 查询订单列表
$sql = "SELECT o.id, o.order_no, o.total_amount, COALESCE(o.discount_amount, 0) as discount_amount, COALESCE(o.paid_amount, 0) as paid_amount, o.payment_status, o.status, o.created_at,
               c.name as customer_name, c.contact, c.phone, c.address,
               (SELECT GROUP_CONCAT(CONCAT(oi.product_name, ' x', oi.quantity, oi.unit, ' ¥', oi.amount) SEPARATOR ' | ')
                FROM order_items oi WHERE oi.order_id = o.id) as items_detail
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        WHERE $whereSql
        ORDER BY o.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 统计数据
$totalOrderAmount = 0;
$totalPaidAmount = 0;
$totalDiscountAmount = 0;
$totalOrderCount = count($orders);
foreach ($orders as $o) {
    $totalOrderAmount += floatval($o['total_amount']);
    $totalPaidAmount += floatval($o['paid_amount']);
    $totalDiscountAmount += floatval($o['discount_amount'] ?? 0);
}

// 获取选中客户的累计历史总金额
$historyTotal = 0;
if ($customerId > 0) {
    $hStmt = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE customer_id = ? AND status != 4");
    $hStmt->execute([$customerId]);
    $historyTotal = floatval($hStmt->fetchColumn());
}

// 获取客户名称
$customerName = '';
if ($customerId > 0) {
    foreach ($customers as $c) {
        if ($c['id'] == $customerId) {
            $customerName = $c['name'];
            break;
        }
    }
}

// 获取每笔订单的收款记录
$paymentMap = [];
if (!empty($orders)) {
    $orderIds = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $pStmt = $db->prepare("SELECT p.*, o.order_no FROM payments p JOIN orders o ON p.order_id = o.id WHERE p.order_id IN ($placeholders) ORDER BY p.created_at DESC");
    $pStmt->execute($orderIds);
    $allPayments = $pStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allPayments as $p) {
        $paymentMap[$p['order_id']][] = $p;
    }
}

// 获取每笔订单的商品明细（打印/导出用）
$itemsByOrder = [];
if (!empty($orders)) {
    $orderIds = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $iStmt = $db->prepare("SELECT order_id, product_name, specification, quantity, unit, unit_price, amount, length, width, square_meter, image_path\n        FROM order_items WHERE order_id IN ($placeholders) ORDER BY id ASC");
    $iStmt->execute($orderIds);
    foreach ($iStmt->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $itemsByOrder[$it['order_id']][] = $it;
    }
}

// 系统设置
$companyName = getSetting('company_name') ?: SITE_TITLE;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>客户对账 - <?php echo SITE_TITLE; ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .order-detail { font-size: 12px; color: var(--gray-600); max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; cursor: default; display: block; }
        .order-detail:hover { white-space: normal; overflow: visible; }
        
        /* 收款明细展开 */
        .pay-toggle { cursor: pointer; color: var(--primary); font-size: 12px; padding: 2px 8px; border: 1px dashed var(--primary); border-radius: 4px; }
        .pay-toggle:hover { background: var(--primary-bg); }
        .pay-toggle.open { background: var(--primary); color: white; }
        .pay-detail-row { display: none; }
        .pay-detail-row.active { display: table-row; }
        .pay-detail-cell { padding: 12px 16px !important; background: #F8FAFC; }
        .pay-list { display: flex; flex-direction: column; gap: 6px; }
        .pay-item { display: flex; justify-content: space-between; align-items: center; padding: 6px 10px; background: white; border: 1px solid #E5E7EB; border-radius: 6px; font-size: 13px; }
        .pay-item .left { display: flex; align-items: center; gap: 10px; }
        .pay-item .time { color: #9CA3AF; font-size: 12px; }
        .pay-method-tag { display: inline-block; padding: 1px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
        .pay-method-tag.wechat { background: #D1FAE5; color: #065F46; }
        .pay-method-tag.alipay { background: #DBEAFE; color: #1E40AF; }
        .pay-method-tag.cash { background: #FEF3C7; color: #92400E; }
        .pay-method-tag.bank { background: #EDE9FE; color: #5B21B6; }
        .pay-item .amount { font-weight: 700; color: #059669; }
        .pay-empty { color: #9CA3AF; font-size: 12px; padding: 8px; }
        
        .running-balance { font-weight: 700; font-size: 14px; }
        .balance-positive { color: #DC2626; }
        .balance-zero { color: #10B981; }
        
        .td-due { font-weight: 700; color: #DC2626; }
        
        #statementTable th, #statementTable td { padding: 10px 8px; }
        
        @media print {
            @page { size: A4 portrait; margin: 10mm; }
            body { background: white; margin: 0; padding: 0; }
            .main-content { padding: 0 !important; margin: 0 !important; }
            .topbar, .sidebar, .footer, .filter-bar, .statement-actions, .btn, .no-print, .card-header { display: none !important; }
            .pay-toggle, .pay-detail-row { display: none !important; }
            .print-area { display: block !important; font-family: "SimSun", "宋体", serif; font-size: 10pt; color: #000; line-height: 1.5; width: 95%; max-width: 700px; margin: 0 auto; }
            .print-title { text-align: center; font-size: 18pt; font-weight: bold; letter-spacing: 4px; margin-bottom: 8px; }
            .print-company { text-align: center; font-size: 11pt; margin-bottom: 4px; }
            .print-period { text-align: center; font-size: 10pt; color: #333; margin-bottom: 12px; }
            .print-info { width: 100%; border-collapse: collapse; margin-bottom: 8px; font-size: 10pt; }
            .print-info td { padding: 3px 8px; border: none; }
            .print-table { width: 100%; border-collapse: collapse; border: 1px solid #000; font-size: 9pt; margin-bottom: 8px; }
            .print-table th { border: 1px solid #000; padding: 5px 6px; background: #f0f0f0; font-weight: bold; text-align: center; }
            .print-table td { border: 1px solid #000; padding: 4px 6px; text-align: center; }
            .print-table td.left { text-align: left; }
            .print-table .total-row { font-weight: bold; background: #f8f8f8; }
            .print-table img { width: 36px; height: 36px; object-fit: cover; border-radius: 3px; vertical-align: middle; }
            .print-subtotal td { font-weight: bold; background: #FEF3C7; }
            .c-paid { color: #0070C0; }
            .c-discount { color: #FF8C00; }
            .c-unpaid { color: #FF0000; }
            .print-summary { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 10pt; }
            .print-summary td { padding: 4px 8px; border: none; }
            .print-sign { width: 100%; border-collapse: collapse; margin-top: 16px; font-size: 10pt; }
            .print-sign td { padding: 4px 8px; width: 33%; border: none; }
            .sign-line { display: inline-block; width: 100px; border-bottom: 1px solid #000; margin-left: 4px; }
        }
        .print-area { display: none; }
    </style>
</head>
<body>
    <?php echo renderNav('statement'); ?>
    
    <div class="page-title-bar no-print">
        <div class="page-title-left">
            <h1>客户对账</h1>
            <p>查询客户订单明细，生成对账单</p>
        </div>
    </div>
    
    <!-- 筛选区域 -->
    <div class="card no-print">
        <div class="card-body">
            <form class="filter-bar" method="GET" id="filterForm">
                <select name="customer_id" class="filter-input" onchange="document.getElementById('filterForm').submit()">
                    <option value="">全部客户</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?php echo $c['id']; ?>" <?php echo $customerId == $c['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($c['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <input type="date" name="start_date" class="filter-input" value="<?php echo htmlspecialchars($startDate); ?>">
                <input type="date" name="end_date" class="filter-input" value="<?php echo htmlspecialchars($endDate); ?>">
                <button type="submit" class="btn btn-primary">查询</button>
                <a href="statement.php" class="btn btn-ghost">重置</a>
            </form>
        </div>
    </div>

    <?php if ($customerId > 0 || !empty($orders)): ?>
    
    <!-- 汇总统计 -->
    <div class="stats-grid no-print">
        <div class="stat-card blue animate-in delay-1">
            <div class="stat-card-header">
                <span class="stat-card-label">订单数量</span>
                <div class="stat-card-icon">📋</div>
            </div>
            <div class="stat-card-value"><?php echo $totalOrderCount; ?></div>
            <div class="stat-card-trend neutral">
                笔订单
            </div>
        </div>
        <div class="stat-card green animate-in delay-2">
            <div class="stat-card-header">
                <span class="stat-card-label">本期应收总额</span>
                <div class="stat-card-icon">💰</div>
            </div>
            <div class="stat-card-value money"><?php echo formatMoney($totalOrderAmount); ?></div>
            <div class="stat-card-trend neutral">
                应收金额
            </div>
        </div>
        <div class="stat-card purple animate-in delay-3">
            <div class="stat-card-header">
                <span class="stat-card-label">本期已收款</span>
                <div class="stat-card-icon">✅</div>
            </div>
            <div class="stat-card-value money"><?php echo formatMoney($totalPaidAmount); ?></div>
            <div class="stat-card-trend up">
                ↑ 已收
            </div>
        </div>
        <div class="stat-card red animate-in delay-4">
            <div class="stat-card-header">
                <span class="stat-card-label">本期未收款</span>
                <div class="stat-card-icon">⏳</div>
            </div>
            <div class="stat-card-value money"><?php echo formatMoney(max(0, $totalOrderAmount - $totalPaidAmount - $totalDiscountAmount)); ?></div>
            <div class="stat-card-trend down">
                ↓ 待收
            </div>
        </div>
    </div>
    
    <!-- 操作按钮 -->
    <div style="margin-bottom:20px;" class="no-print">
        <label class="no-print" style="display:inline-flex;align-items:center;gap:4px;margin-right:6px;font-size:13px;cursor:pointer;vertical-align:middle;" title="全部客户数据量大，建议不勾选，避免导出超时">
            <input type="checkbox" id="incImages" <?php echo $customerId > 0 ? 'checked' : ''; ?> onchange="updateExcelLink()"> Excel含参考图
        </label>
        <a href="statement_excel.php?customer_id=<?php echo $customerId; ?>&start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>" id="excelLink" class="btn btn-primary" target="_blank">📊 导出 Excel</a>
        <script>
        function updateExcelLink() {
            var a = document.getElementById('excelLink');
            if (!a) return;
            var url = new URL(a.getAttribute('href'), location.href);
            url.searchParams.set('inc_images', document.getElementById('incImages').checked ? '1' : '0');
            a.href = url.toString();
        }
        updateExcelLink();
        </script>
        <a href="statement_pdf.php?customer_id=<?php echo $customerId; ?>&start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>" class="btn btn-danger" target="_blank">📄 导出 PDF</a>
        <button class="btn btn-success" onclick="window.print()">🖨️ 打印对账单</button>
    </div>
    <?php endif; ?>

    <!-- 订单明细表 -->
    <div class="card no-print">
        <div class="card-header">
            <div class="card-title"><span class="title-icon">📋</span> 对账明细<?php if ($customerName): ?> - <?php echo htmlspecialchars($customerName); ?><?php endif; ?></div>
        </div>
        <div class="card-body">
            <div class="table-wrapper">
                <table class="data-table" id="statementTable">
                    <thead>
                        <tr>
                            <th>订单号</th>
                            <th>客户</th>
                            <th>订单内容</th>
                            <th>应收金额</th>
                            <th>已收款</th>
                            <th>优惠</th>
                            <th>未收</th>
                            <th>状态</th>
                            <th>下单时间</th>
                            <th style="width:70px;">收款</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $o):
                            $due = max(0, floatval($o['total_amount']) - floatval($o['discount_amount'] ?? 0) - floatval($o['paid_amount']));
                            $payments = $paymentMap[$o['id']] ?? [];
                        ?>
                        <tr>
                            <td><a href="order_view.php?id=<?php echo $o['id']; ?>" class="td-link"><?php echo $o['order_no']; ?></a></td>
                            <td><?php echo htmlspecialchars($o['customer_name']); ?></td>
                            <td><span class="order-detail" title="<?php echo htmlspecialchars($o['items_detail'] ?? ''); ?>"><?php echo htmlspecialchars($o['items_detail'] ?: '-'); ?></span></td>
                            <td class="td-money"><?php echo formatMoney($o['total_amount']); ?></td>
                            <td class="td-money" style="color:<?php echo floatval($o['paid_amount']) > 0 ? '#10B981' : '#9CA3AF'; ?>"><?php echo formatMoney($o['paid_amount']); ?></td>
                            <td class="td-money" style="color:#F59E0B;"><?php echo floatval($o['discount_amount'] ?? 0) > 0 ? '-'.formatMoney($o['discount_amount']) : '-'; ?></td>
                            <td class="td-money td-due"><?php echo formatMoney($due); ?></td>
                            <td><span class="badge badge-<?php echo getPaymentStatusClass($o['payment_status']); ?>"><?php echo getPaymentStatusText($o['payment_status']); ?></span></td>
                            <td class="td-muted"><?php echo date('Y-m-d', strtotime($o['created_at'])); ?></td>
                            <td>
                                <span class="pay-toggle" onclick="togglePay(<?php echo $o['id']; ?>)" id="payToggle_<?php echo $o['id']; ?>">
                                    💳 <?php echo count($payments); ?>
                                </span>
                            </td>
                        </tr>
                        <tr class="pay-detail-row" id="payDetail_<?php echo $o['id']; ?>">
                            <td colspan="9" class="pay-detail-cell">
                                <div class="pay-list">
                                    <?php if (empty($payments)): ?>
                                    <div class="pay-empty">暂无收款记录</div>
                                    <?php else: ?>
                                    <?php foreach ($payments as $p): ?>
                                    <div class="pay-item">
                                        <div class="left">
                                            <span class="time"><?php echo date('Y-m-d H:i', strtotime($p['created_at'])); ?></span>
                                            <span class="pay-method-tag <?php echo getPayMethodClass($p['payment_method']); ?>"><?php echo htmlspecialchars($p['payment_method'] ?: '未指定'); ?></span>
                                            <?php if ($p['remark']): ?>
                                            <span style="color:#6B7280;font-size:12px;"><?php echo htmlspecialchars($p['remark']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="amount">+¥<?php echo number_format(floatval($p['amount']), 2); ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($orders)): ?>
                        <tr><td colspan="9" class="empty-state"><div class="icon">📭</div><div class="text">请选择客户和日期范围进行对账</div></td></tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($orders)): ?>
                    <tfoot>
                        <tr style="background:linear-gradient(135deg, rgba(59, 130, 246, 0.05) 0%, rgba(139, 92, 246, 0.05) 100%);font-weight:700;">
                            <td colspan="3" style="text-align:right;">合计（<?php echo $totalOrderCount; ?>笔订单）：</td>
                            <td style="color:var(--danger);font-size:16px;"><?php echo formatMoney($totalOrderAmount); ?></td>
                            <td style="color:#10B981;font-size:16px;"><?php echo formatMoney($totalPaidAmount); ?></td>
                            <td style="color:#F59E0B;font-size:16px;"><?php echo $totalDiscountAmount > 0 ? '-'.formatMoney($totalDiscountAmount) : '-'; ?></td>
                            <td style="color:#DC2626;font-size:16px;"><?php echo formatMoney(max(0, $totalOrderAmount - $totalPaidAmount - $totalDiscountAmount)); ?></td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- 打印专用区域 -->
    <div class="print-area" id="printArea">
        <div class="print-company"><?php echo htmlspecialchars($companyName); ?></div>
        <div class="print-title">客户对账单</div>
        <div class="print-period">对账期间：<?php echo ($startDate ?: '起始') . ' 至 ' . ($endDate ?: '截止'); ?></div>
        
        <?php if ($customerId > 0): ?>
        <?php
        $custDetail = $db->prepare("SELECT contact, phone, address FROM customers WHERE id = ?");
        $custDetail->execute([$customerId]);
        $custData = $custDetail->fetch(PDO::FETCH_ASSOC);
        ?>
        <table class="print-info">
            <tr>
                <td>客户名称：<?php echo htmlspecialchars($customerName); ?></td>
                <td>联系人：<?php echo htmlspecialchars($custData['contact'] ?? ''); ?></td>
                <td>电话：<?php echo htmlspecialchars($custData['phone'] ?? ''); ?></td>
            </tr>
            <tr>
                <td colspan="3">地址：<?php echo htmlspecialchars($custData['address'] ?? ''); ?></td>
            </tr>
        </table>
        <?php endif; ?>

        <table class="print-table">
            <thead>
                <tr>
                    <th colspan="11" style="text-align:left;background:#E0E7FF;">一、商品明细（含参考图）</th>
                </tr>
                <tr>
                    <th style="width:10%;">订单号</th>
                    <th style="width:4%;">序号</th>
                    <th style="width:12%;">产品名称</th>
                    <th style="width:17%;">规格说明</th>
                    <th style="width:9%;">尺寸(米)</th>
                    <th style="width:7%;">平方数</th>
                    <th style="width:6%;">数量</th>
                    <th style="width:6%;">单位</th>
                    <th style="width:8%;">单价</th>
                    <th style="width:9%;">金额</th>
                    <th style="width:12%;">参考图</th>
                </tr>
            </thead>
            <tbody>
                <?php $printSeq = 0; foreach ($orders as $o):
                    $items = $itemsByOrder[$o['id']] ?? [];
                    if (empty($items)) continue;
                ?>
                <?php foreach ($items as $i => $it):
                    $printSeq++;
                    $imgPath = $it['image_path'] ?? '';
                    $imgOk = false;
                    if ($imgPath !== '') {
                        if (!preg_match('#^[A-Za-z]:[\\/]|^/#', $imgPath)) {
                            $imgPath = __DIR__ . '/' . $imgPath;
                        }
                        $imgOk = file_exists($imgPath);
                    }
                ?>
                <tr>
                    <td style="font-size:8pt;"><?php echo $i === 0 ? $o['order_no'] : ''; ?></td>
                    <td><?php echo $i + 1; ?></td>
                    <td class="left"><?php echo htmlspecialchars($it['product_name']); ?></td>
                    <td class="left" style="font-size:8pt;"><?php echo htmlspecialchars($it['specification'] ?: '-'); ?></td>
                    <td><?php echo ($it['length'] && $it['width']) ? floatval($it['length']) . ' × ' . floatval($it['width']) : '-'; ?></td>
                    <td><?php echo ($it['square_meter'] !== null && floatval($it['square_meter']) > 0) ? formatSqm($it['square_meter']) : '-'; ?></td>
                    <td><?php echo rtrim(rtrim(number_format($it['quantity'], 2), '0'), '.'); ?></td>
                    <td><?php echo htmlspecialchars($it['unit'] ?: '-'); ?></td>
                    <td><?php echo number_format($it['unit_price'], 2); ?></td>
                    <td><?php echo number_format($it['amount'], 2); ?></td>
                    <td><?php if ($it['image_path'] && $imgOk): ?><img src="<?php echo htmlspecialchars($it['image_path']); ?>" alt=""><?php elseif ($it['image_path']): ?>丢失<?php else: ?>-<?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="print-subtotal">
                    <td colspan="9" style="text-align:right;">订单 <?php echo $o['order_no']; ?> 小计（<span class="c-paid">已收 ¥<?php echo number_format($o['paid_amount'], 2); ?></span>，<span class="c-discount">优惠 ¥<?php echo number_format($o['discount_amount'], 2); ?></span>，<span class="c-unpaid">未收 ¥<?php echo number_format(max(0, floatval($o['total_amount']) - floatval($o['discount_amount']) - floatval($o['paid_amount'])), 2); ?></span>）</td>
                    <td><?php echo number_format($o['total_amount'], 2); ?></td>
                    <td></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($orders)): ?>
                <tr><td colspan="11">所选期间暂无订单</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <table class="print-table" style="margin-top:8px;">
            <thead>
                <tr>
                    <th colspan="8" style="text-align:left;background:#E0E7FF;">二、订单收款汇总</th>
                </tr>
                <tr>
                    <th style="width:13%;">订单号</th>
                    <th style="width:23%;">订单内容</th>
                    <th style="width:12%;">金额</th>
                    <th style="width:12%;">已收款</th>
                    <th style="width:10%;">优惠</th>
                    <th style="width:10%;">未收</th>
                    <th style="width:8%;">状态</th>
                    <th style="width:12%;">下单日期</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $o): ?>
                <tr>
                    <td><?php echo $o['order_no']; ?></td>
                    <td class="left" style="font-size:8pt;"><?php echo htmlspecialchars($o['items_detail'] ?: '-'); ?></td>
                    <td><?php echo number_format($o['total_amount'], 2); ?></td>
                    <td><?php echo number_format($o['paid_amount'], 2); ?></td>
                    <td><?php echo floatval($o['discount_amount'] ?? 0) > 0 ? number_format($o['discount_amount'], 2) : '-'; ?></td>
                    <td><?php echo number_format(max(0, floatval($o['total_amount']) - floatval($o['discount_amount'] ?? 0) - floatval($o['paid_amount'])), 2); ?></td>
                    <td><?php echo getPaymentStatusText($o['payment_status']); ?></td>
                    <td><?php echo date('Y-m-d', strtotime($o['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!empty($orders)): ?>
                <tr class="total-row">
                    <td colspan="2" style="text-align:right;">合计（<?php echo $totalOrderCount; ?>笔）</td>
                    <td><?php echo number_format($totalOrderAmount, 2); ?></td>
                    <td><?php echo number_format($totalPaidAmount, 2); ?></td>
                    <td><?php echo $totalDiscountAmount > 0 ? number_format($totalDiscountAmount, 2) : '-'; ?></td>
                    <td><?php echo number_format(max(0, $totalOrderAmount - $totalPaidAmount - $totalDiscountAmount), 2); ?></td>
                    <td colspan="2"></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <table class="print-summary">
            <tr>
                <td>本期应收总额：<strong><?php echo number_format($totalOrderAmount, 2); ?></strong> 元</td>
                <td>本期已收款：<strong style="color:#10B981;"><?php echo number_format($totalPaidAmount, 2); ?></strong> 元</td>
            </tr>
            <tr>
                <td>本期未收款：<strong style="color:#EF4444;"><?php echo number_format(max(0, $totalOrderAmount - $totalPaidAmount - $totalDiscountAmount), 2); ?></strong> 元</td>
                <td>本期优惠金额：<strong style="color:#F59E0B;"><?php echo $totalDiscountAmount > 0 ? number_format($totalDiscountAmount, 2) : '0.00'; ?></strong> 元</td>
            </tr>
            <tr>
                <td colspan="2">大写金额：<?php echo numToChinese($totalOrderAmount - $totalDiscountAmount); ?></td>
            </tr>
        </table>

        <table class="print-sign">
            <tr>
                <td>制单人：_______________</td>
                <td>客户确认签字：<span class="sign-line"></span></td>
                <td>日期：______年____月____日</td>
            </tr>
        </table>
    </div>
    
    <script>
    function togglePay(orderId) {
        const row = document.getElementById('payDetail_' + orderId);
        const toggle = document.getElementById('payToggle_' + orderId);
        if (row.classList.contains('active')) {
            row.classList.remove('active');
            toggle.classList.remove('open');
        } else {
            row.classList.add('active');
            toggle.classList.add('open');
        }
    }
    </script>

    <?php echo renderFooter(); ?>
</body>
</html>