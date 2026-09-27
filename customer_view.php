<?php
require_once 'config.php';
initDatabase();
checkLogin();

if (!hasPermission(PERM_CUSTOMER_VIEW)) {
    die('<script>alert("无权限访问,请联系管理员授权");location.href="index.php";</script>');
}

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    die('无效的客户ID');
}

$db = getDB();

// 获取客户信息
$customer = $db->prepare("SELECT * FROM customers WHERE id = ?");
$customer->execute([$id]);
$customer = $customer->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    die('客户不存在');
}

// 汇总数据
$summaryStmt = $db->prepare("SELECT
    COUNT(*) AS order_count,
    COALESCE(SUM(total_amount), 0) AS total_amount,
    COALESCE(SUM(paid_amount), 0) AS paid_amount,
    COALESCE(SUM(total_amount - COALESCE(paid_amount,0)), 0) AS unpaid_amount,
    COALESCE(SUM(total_amount - COALESCE(discount_amount,0) - COALESCE(paid_amount,0)), 0) AS real_unpaid
    FROM orders
    WHERE customer_id = ? AND status IN (1,2,3)");
$summaryStmt->execute([$id]);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

// 客户订单列表
$ordersStmt = $db->prepare("SELECT o.*,
    (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = o.id) AS paid_sum
    FROM orders o
    WHERE o.customer_id = ?
    ORDER BY o.order_date DESC, o.id DESC
    LIMIT 200");
$ordersStmt->execute([$id]);
$orderList = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

// 收款记录
$paymentsStmt = $db->prepare("SELECT p.*, o.order_no, u.realname AS receiver_name
    FROM payments p
    JOIN orders o ON p.order_id = o.id
    LEFT JOIN users u ON p.created_by = u.id
    WHERE o.customer_id = ?
    ORDER BY p.created_at DESC
    LIMIT 200");
$paymentsStmt->execute([$id]);
$paymentList = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = $_SESSION['csrf_token'] ?? '';
if (empty($csrfToken)) {
    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrfToken;
}

// 格式化金额
function formatMoney2($amount) {
    return '¥' . number_format(floatval($amount ?? 0), 2);
}

$totalAmount = floatval($summary['total_amount'] ?? 0);
$paidAmount = floatval($summary['paid_amount'] ?? 0);
$unpaidAmount = floatval($summary['real_unpaid'] ?? 0);
$receiveRate = $totalAmount > 0 ? round($paidAmount / $totalAmount * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>客户详情 - <?php echo h($customer['name']); ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .page-title-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
        .btn { padding: 10px 20px; border-radius: 8px; border: none; cursor: pointer; font-size: 14px; }
        .btn-primary { background: #3B82F6; color: white; }
        .btn-outline { background: white; color: #3B82F6; border: 1px solid #3B82F6; }
        .card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: 24px; margin-bottom: 20px; }
        .card-title { font-size: 18px; font-weight: 600; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .stat-card.income { border-left: 4px solid #3B82F6; }
        .stat-card.received { border-left: 4px solid #10B981; }
        .stat-card.unpaid { border-left: 4px solid #F59E0B; }
        .stat-card.orders { border-left: 4px solid #8B5CF6; }
        .stat-label { color: #6B7280; font-size: 14px; margin-bottom: 8px; }
        .stat-value { font-size: 28px; font-weight: 700; color: #1F2937; }
        .stat-sub { color: #9CA3AF; font-size: 12px; margin-top: 4px; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
        .info-item { padding: 12px 16px; background: #F9FAFB; border-radius: 8px; }
        .info-label { color: #6B7280; font-size: 12px; margin-bottom: 4px; }
        .info-value { color: #1F2937; font-size: 15px; font-weight: 500; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 14px; text-align: left; border-bottom: 1px solid #E5E7EB; font-size: 14px; }
        th { background: #F9FAFB; font-weight: 600; color: #374151; }
        tr:hover { background: #F9FAFB; }
        .money { font-weight: 600; font-family: monospace; }
        .money-positive { color: #10B981; }
        .money-negative { color: #EF4444; }
        .money-warn { color: #F59E0B; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12px; }
        .badge-green { background: #D1FAE5; color: #065F46; }
        .badge-yellow { background: #FEF3C7; color: #92400E; }
        .badge-red { background: #FEE2E2; color: #991B1B; }
        .badge-gray { background: #F3F4F6; color: #4B5563; }
        .progress-bar { height: 6px; background: #F3F4F6; border-radius: 3px; overflow: hidden; margin-top: 8px; }
        .progress-fill { height: 100%; background: linear-gradient(to right, #F59E0B, #10B981); }
        .empty-state { text-align: center; padding: 40px; color: #9CA3AF; }
        .back-link { color: #3B82F6; text-decoration: none; font-size: 14px; }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <?php echo renderNav('customers'); ?>

    <div class="page-title-bar">
        <div>
            <a href="customers.php" class="back-link">← 返回客户列表</a>
            <h1 style="margin-top:8px;">👤 <?php echo h($customer['name']); ?></h1>
        </div>
    </div>

    <!-- 客户基本信息 -->
    <div class="card">
        <div class="card-title">📇 客户信息</div>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">客户名称</div>
                <div class="info-value"><?php echo h($customer['name']); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">联系人</div>
                <div class="info-value"><?php echo h($customer['contact'] ?? '-'); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">联系电话</div>
                <div class="info-value"><?php echo h($customer['phone'] ?? '-'); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">地址</div>
                <div class="info-value"><?php echo h($customer['address'] ?? '-'); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">建档时间</div>
                <div class="info-value"><?php echo $customer['created_at']; ?></div>
            </div>
        </div>
    </div>

    <!-- 汇总统计 -->
    <div class="stats-grid">
        <div class="stat-card orders">
            <div class="stat-label">📦 订单数</div>
            <div class="stat-value"><?php echo intval($summary['order_count'] ?? 0); ?></div>
            <div class="stat-sub">有效订单(已确认/生产中/已完成)</div>
        </div>
        <div class="stat-card income">
            <div class="stat-label">💰 累计消费</div>
            <div class="stat-value"><?php echo formatMoney2($totalAmount); ?></div>
            <div class="stat-sub">订单总额</div>
        </div>
        <div class="stat-card received">
            <div class="stat-label">💵 已付款</div>
            <div class="stat-value money-positive"><?php echo formatMoney2($paidAmount); ?></div>
            <div class="stat-sub">
                收款进度: <?php echo $receiveRate; ?>%
                <div class="progress-bar"><div class="progress-fill" style="width:<?php echo $receiveRate; ?>%;"></div></div>
            </div>
        </div>
        <div class="stat-card unpaid">
            <div class="stat-label">⏳ 未付款</div>
            <div class="stat-value money-warn"><?php echo formatMoney2($unpaidAmount); ?></div>
            <div class="stat-sub">应收未收金额</div>
        </div>
    </div>

    <!-- 订单列表 -->
    <div class="card">
        <div class="card-title">📋 订单记录(<?php echo count($orderList); ?> 笔)</div>
        <?php if (empty($orderList)): ?>
            <div class="empty-state">该客户暂无订单</div>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>订单号</th>
                            <th>订单日期</th>
                            <th>订单金额</th>
                            <th>已收款</th>
                            <th>未收款</th>
                            <th>付款状态</th>
                            <th>订单状态</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orderList as $o):
                        $oTotal = floatval($o['total_amount'] ?? 0);
                        $oPaid = floatval($o['paid_sum'] ?? $o['paid_amount'] ?? 0);
                        $oDiscount = floatval($o['discount_amount'] ?? 0);
                        $oUnpaid = max(0, $oTotal - $oDiscount - $oPaid);
                        $pStatus = intval($o['payment_status'] ?? 0);
                        $oStatus = intval($o['status'] ?? 0);
                        $statusText = ['0'=>'草稿', '1'=>'已确认', '2'=>'生产中', '3'=>'已完成', '4'=>'已取消'][$oStatus] ?? '未知';
                    ?>
                        <tr>
                            <td><a href="order_view.php?id=<?php echo intval($o['id']); ?>" style="color:#3B82F6;text-decoration:none;font-weight:600;">📄 <?php echo h($o['order_no']); ?></a></td>
                            <td><?php echo $o['order_date']; ?></td>
                            <td class="money"><?php echo formatMoney2($oTotal); ?></td>
                            <td class="money money-positive"><?php echo formatMoney2($oPaid); ?></td>
                            <td class="money <?php echo $oUnpaid > 0 ? 'money-warn' : 'money-negative'; ?>"><?php echo formatMoney2($oUnpaid); ?></td>
                            <td>
                                <?php if ($pStatus == 2): ?>
                                    <span class="badge badge-green">已付清</span>
                                <?php elseif ($pStatus == 1): ?>
                                    <span class="badge badge-yellow">部分付款</span>
                                <?php else: ?>
                                    <span class="badge badge-red">未付款</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-gray"><?php echo h($statusText); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:#FEF3C7;font-weight:600;">
                            <td colspan="2">合计(<?php echo count($orderList); ?> 笔)</td>
                            <td class="money"><?php echo formatMoney2($totalAmount); ?></td>
                            <td class="money money-positive"><?php echo formatMoney2($paidAmount); ?></td>
                            <td class="money money-warn"><?php echo formatMoney2($unpaidAmount); ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- 收款记录 -->
    <div class="card">
        <div class="card-title">💰 收款记录(<?php echo count($paymentList); ?> 笔)</div>
        <?php if (empty($paymentList)): ?>
            <div class="empty-state">该客户暂无收款记录</div>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>收款日期</th>
                            <th>订单号</th>
                            <th>收款金额</th>
                            <th>优惠</th>
                            <th>收款方式</th>
                            <th>备注</th>
                            <th>登记人</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($paymentList as $pay): ?>
                        <tr>
                            <td><?php echo substr($pay['created_at'] ?? '', 0, 16); ?></td>
                            <td><a href="order_view.php?id=<?php echo intval($pay['order_id']); ?>" style="color:#3B82F6;text-decoration:none;">📄 <?php echo h($pay['order_no']); ?></a></td>
                            <td class="money money-positive">+<?php echo formatMoney2($pay['amount']); ?></td>
                            <td><?php if (floatval($pay['discount'] ?? 0) > 0): ?><span class="money-warn">-<?php echo formatMoney2($pay['discount']); ?></span><?php else: ?>-<?php endif; ?></td>
                            <td><span class="badge badge-green"><?php echo h($pay['payment_method']); ?></span></td>
                            <td style="color:#6B7280;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo h($pay['remark'] ?? ''); ?>"><?php echo h($pay['remark'] ?? '') ?: '-'; ?></td>
                            <td><?php echo h($pay['receiver_name'] ?? '系统'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:#D1FAE5;font-weight:600;">
                            <td colspan="2">合计收款(<?php echo count($paymentList); ?> 笔)</td>
                            <td class="money money-positive">+<?php echo formatMoney2(array_sum(array_column($paymentList, 'amount'))); ?></td>
                            <td colspan="4"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
