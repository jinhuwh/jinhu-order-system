<?php
require_once 'config.php';
initDatabase();
checkLogin();

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    echo '<script>alert("无效订单");history.back();</script>';
    exit;
}

$db = getDB();

// 获取订单信息
$order = $db->prepare("SELECT o.*, c.name as customer_name, c.contact, c.phone, c.address 
    FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE o.id = ?");
$order->execute([$id]);
$order = $order->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    echo '<script>alert("订单不存在");location.href="order_list.php";</script>';
    exit;
}

// 数据权限校验：普通用户只能查看自己创建的订单，管理员可查看全部
$currentUser = getCurrentUser();
if ($currentUser['role'] != 2 && $order['created_by'] != $currentUser['id']) {
    echo '<script>alert("无权访问该订单");location.href="order_list.php";</script>';
    exit;
}

// 获取订单明细
$items = $db->prepare("SELECT * FROM order_items WHERE order_id = ?");
$items->execute([$id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

// 生成/初始化 CSRF Token（必须在所有 POST 处理之前，确保 verifyCsrfToken 可用）
$csrfToken = generateCsrfToken();

// 处理状态变更（POST请求）
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['confirm', 'produce', 'complete', 'cancel'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'msg' => '非法请求']);
        exit;
    }
    $statusMap = ['confirm' => 1, 'produce' => 2, 'complete' => 3, 'cancel' => 4];
    $db->prepare("UPDATE orders SET status = ? WHERE id = ?")->execute([$statusMap[$_POST['action']], $id]);
    echo json_encode(['ok' => true, 'msg' => '状态已更新']);
    exit;
}

// 处理收款请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'payment') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'msg' => '非法请求']);
        exit;
    }
    
    $amount = floatval($_POST['amount'] ?? 0);
    if ($amount <= 0) {
        echo json_encode(['ok' => false, 'msg' => '收款金额必须大于0']);
        exit;
    }
    
    try {
        $db->beginTransaction();
        // 行锁读取实时已收款/已优惠，避免并发两笔收款互相覆盖导致丢款（与 PC 端 order_view.php 修复一致）
        $lockStmt = $db->prepare("SELECT total_amount, paid_amount, COALESCE(discount_amount, 0) AS discount_amount FROM orders WHERE id = ? FOR UPDATE");
        $lockStmt->execute([$id]);
        $cur = $lockStmt->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            $db->rollBack();
            echo json_encode(['ok' => false, 'msg' => '订单不存在']);
            exit;
        }
        $curTotal = floatval($cur['total_amount']);
        $curPaid = floatval($cur['paid_amount']);
        // 【2026-09-15】支持本次优惠：对齐 PC 端（payments.discount 列 + orders.discount_amount 累加）
        $discount = floatval($_POST['discount'] ?? 0);
        if ($discount < 0) {
            $db->rollBack();
            echo json_encode(['ok' => false, 'msg' => '优惠金额不能为负']);
            exit;
        }
        $curDiscount = floatval($cur['discount_amount']);
        $newDiscount = $curDiscount + $discount;
        if ($newDiscount > $curTotal + 0.01) {
            $db->rollBack();
            echo json_encode(['ok' => false, 'msg' => '累计优惠不能超过订单金额']);
            exit;
        }
        $payable = $curTotal - $newDiscount;   // 应付 = 总价 - 累计优惠
        $due = $payable - $curPaid;            // 未付 = 应付 - 已付
        if ($amount > $due + 0.01) {
            $db->rollBack();
            echo json_encode(['ok' => false, 'msg' => '收款金额不能超过未付款金额' . ($discount > 0 ? '（已扣除本次优惠）' : '')]);
            exit;
        }
        
        $paymentMethod = trim($_POST['payment_method'] ?? '');
        $remark = trim($_POST['payment_remark'] ?? '');
        
        // 插入收款记录（含本次优惠）
        $stmt = $db->prepare("INSERT INTO payments (order_id, amount, payment_method, remark, discount, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$id, $amount, $paymentMethod, $remark, $discount, $_SESSION['user_id']]);
        
        // 更新订单已收款金额与累计优惠（基于行锁后的实时值）
        $newPaidAmount = $curPaid + $amount;
        $paymentStatus = ($newPaidAmount + 0.005 >= $payable && $payable > 0) ? 2 : (($payable <= 0.005 && $newDiscount >= $curTotal - 0.01) ? 2 : 1);
        
        $stmt = $db->prepare("UPDATE orders SET paid_amount = ?, discount_amount = ?, payment_status = ? WHERE id = ?");
        $stmt->execute([$newPaidAmount, $newDiscount, $paymentStatus, $id]);
        
        $db->commit();
        echo json_encode(['ok' => true, 'msg' => '收款成功']);
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        echo json_encode(['ok' => false, 'msg' => '收款失败：' . $e->getMessage()]);
        exit;
    }
}

$created = isset($_GET['created']);
$companyName = getSetting('company_name') ?: '广告公司';
$currentUser = getCurrentUser();
$makerName = $currentUser['realname'] ?: $currentUser['username'];

// 已收优惠金额
$discountAmt = floatval($order['discount_amount'] ?? 0);
$payableAmt = $order['total_amount'] - $discountAmt;      // 应付 = 总价 - 累计优惠
$dueAmt = max(0, $payableAmt - $order['paid_amount']);    // 待收 = 应付 - 已收

// 获取收款记录
$payments = $db->prepare("SELECT p.*, u.realname FROM payments p LEFT JOIN users u ON p.created_by = u.id WHERE p.order_id = ? ORDER BY p.created_at DESC");
$payments->execute([$id]);
$payments = $payments->fetchAll(PDO::FETCH_ASSOC);

// 支付状态显示
$paymentStatusText = ['未付款', '部分收款', '已付清'][$order['payment_status']];
$paymentBadgeClass = $order['payment_status'] == 2 ? 'success' : ($order['payment_status'] == 1 ? 'warning' : 'danger');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>订单详情 - <?php echo htmlspecialchars($order['order_no']); ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .order-detail-card { background: white; border-radius: var(--radius); padding: 16px; margin-bottom: 16px; box-shadow: var(--shadow); }
        .detail-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; }
        .detail-header .order-no { font-size: 17px; font-weight: 700; color: #333; }
        .detail-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid var(--border); }
        .detail-row:last-child { border-bottom: none; }
        .detail-row .label { color: #999; font-size: 13px; }
        .detail-row .value { color: #333; font-weight: 500; text-align: right; }
        .amount-summary { background: #f8f9fa; border-radius: var(--radius-sm); padding: 14px; margin-top: 12px; }
        .amount-row { display: flex; justify-content: space-between; padding: 6px 0; font-size: 14px; }
        .amount-row.total { font-weight: 700; font-size: 18px; color: var(--primary); border-top: 2px solid var(--primary); margin-top: 6px; padding-top: 10px; }
        .action-bar { background: white; border-radius: var(--radius); padding: 14px; margin-bottom: 16px; box-shadow: var(--shadow); }
        .action-bar h3 { font-size: 15px; margin-bottom: 12px; }
        .action-btns { display: flex; gap: 10px; flex-wrap: wrap; }
        
        /* 打印区域（屏幕隐藏） */
        .print-area { display: none; }
        
        /* PC 端打印样式（实际打印时生效） */
        @media print {
            @page { size: A4 portrait; margin: 10mm 12mm; }
            html, body { background: white !important; margin: 0 !important; padding: 0 !important; }
            /* 隐藏手机端所有非打印区域 */
            body > *:not(.print-area) { display: none !important; }
            .page > *:not(.print-area) { display: none !important; }
            /* PC 端完整打印样式（完全一致） */
            .print-area { display: block !important; position: static !important; visibility: visible !important; font-family: "SimSun", "宋体", serif; font-size: 10pt; color: #000; line-height: 1.5; width: 95%; max-width: 680px; margin: 0 auto; padding: 10px 4px; }
            .print-company { text-align: center; font-size: 16pt; font-weight: bold; margin-bottom: 4px; letter-spacing: 2px; }
            .print-title { text-align: center; font-size: 18pt; font-weight: bold; margin-bottom: 8px; letter-spacing: 4px; }
            .print-info-table { width: 100%; border-collapse: collapse; border: none !important; margin-bottom: 4px; font-size: 10pt; }
            .print-info-table td { padding: 2px 6px; white-space: nowrap; border: none !important; }
            .print-items { width: 100%; border-collapse: collapse; border: 1px solid #000 !important; margin: 4px 2px; font-size: 9pt; table-layout: fixed; }
            .print-items th:nth-child(1) { width: 6%; }
            .print-items th:nth-child(2) { width: 23%; }
            .print-items th:nth-child(3) { width: 6%; }
            .print-items th:nth-child(4) { width: 11%; }
            .print-items th:nth-child(5) { width: 11%; }
            .print-items th:nth-child(6) { width: 14%; }
            .print-items th:nth-child(7) { width: 29%; }
            /* 含平方数列时的 8 列宽度（仅在订单中有平方数时启用） */
            .print-items.with-sqm th:nth-child(1) { width: 6%; }
            .print-items.with-sqm th:nth-child(2) { width: 20%; }
            .print-items.with-sqm th:nth-child(3) { width: 11%; }
            .print-items.with-sqm th:nth-child(4) { width: 6%; }
            .print-items.with-sqm th:nth-child(5) { width: 9%; }
            .print-items.with-sqm th:nth-child(6) { width: 10%; }
            .print-items.with-sqm th:nth-child(7) { width: 13%; }
            .print-items.with-sqm th:nth-child(8) { width: 25%; }
            .print-items th { border: 1px solid #000; padding: 3px 4px; text-align: center; font-weight: bold; background: #fff; }
            .print-items td { border: 1px solid #000; padding: 3px 4px; text-align: center; }
            .print-items .total-row { font-weight: bold; }
            .print-items tr.caps-row td { font-weight: normal; text-align: left; padding: 4px 8px; }
            .print-summary { width: 100%; border-collapse: collapse; border: none !important; margin-top: 2px; font-size: 10pt; }
            .print-summary td { padding: 3px 6px; white-space: nowrap; border: none !important; }
            .print-sign { width: 100%; border-collapse: collapse; border: none !important; margin-top: 6px; font-size: 10pt; }
            .print-sign td { padding: 4px 6px; width: 33%; border: none !important; }
            .sign-line { display: inline-block; width: 80px; border-bottom: 1px solid #000; margin-left: 4px; }
        }
        
    </style>
</head>
<body>
    <!-- 顶部栏 -->
    <div class="page-header">
        <h1>📋 订单详情</h1>
        <div class="user-bar">
            <span><?php echo getStatusText($order['status']); ?></span>
        </div>
    </div>
    
    <div class="page">
        <?php if ($created): ?>
        <div class="msg msg-success">✅ 订单创建成功！</div>
        <?php endif; ?>
        
        <!-- 进度步骤条 -->
        <div class="step-wrap">
            <div class="step-bar">
                <div class="step-item <?php echo $order['status'] >= 0 ? 'done' : ''; ?> <?php echo $order['status'] == 0 ? 'active' : ''; ?>">
                    <div class="step-icon">1</div>
                    <div class="step-label">待确认</div>
                </div>
                <div class="step-item <?php echo $order['status'] >= 1 ? 'done' : ''; ?> <?php echo $order['status'] == 1 ? 'active' : ''; ?>">
                    <div class="step-icon">2</div>
                    <div class="step-label">已确认</div>
                </div>
                <div class="step-item <?php echo $order['status'] >= 2 ? 'done' : ''; ?> <?php echo $order['status'] == 2 ? 'active' : ''; ?>">
                    <div class="step-icon">3</div>
                    <div class="step-label">生产中</div>
                </div>
                <div class="step-item <?php echo $order['status'] >= 3 ? 'done' : ''; ?> <?php echo $order['status'] == 3 ? 'active' : ''; ?>">
                    <div class="step-icon">✓</div>
                    <div class="step-label">已完成</div>
                </div>
            </div>
        </div>
        
        <!-- 客户信息 -->
        <div class="order-detail-card">
            <div class="card-title">👤 客户信息</div>
            <div class="detail-row">
                <span class="label">客户名称</span>
                <span class="value"><?php echo htmlspecialchars($order['customer_name']); ?></span>
            </div>
            <?php if ($order['contact']): ?>
            <div class="detail-row">
                <span class="label">联系人</span>
                <span class="value"><?php echo htmlspecialchars($order['contact']); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($order['phone']): ?>
            <div class="detail-row">
                <span class="label">联系电话</span>
                <span class="value"><a href="tel:<?php echo htmlspecialchars($order['phone']); ?>" style="color:var(--primary);text-decoration:none;"><?php echo htmlspecialchars($order['phone']); ?> 📞</a></span>
            </div>
            <?php endif; ?>
            <?php if ($order['address']): ?>
            <div class="detail-row">
                <span class="label">地址</span>
                <span class="value" style="max-width:60%;text-align:right;"><?php echo htmlspecialchars($order['address']); ?></span>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- 订单明细 -->
        <div class="order-detail-card">
            <div class="card-title">📦 订单明细</div>
            <table class="order-table">
                <thead>
                    <tr>
                        <th>产品</th>
                        <th>数量</th>
                        <th>单价</th>
                        <th class="num">金额</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td>
                            <div style="font-weight:500;"><?php echo htmlspecialchars($item['product_name']); ?></div>
                            <?php if ($item['specification']): ?>
                            <div style="font-size:12px;color:#999;">规格: <?php echo htmlspecialchars($item['specification']); ?></div>
                            <?php endif; ?>
                            <?php if ($item['remark']): ?>
                            <div style="font-size:12px;color:#666;">备注: <?php echo htmlspecialchars($item['remark']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $item['quantity']; ?> <?php echo htmlspecialchars($item['unit']); ?></td>
                        <td>¥<?php echo number_format($item['unit_price'], 2); ?></td>
                        <td class="num">¥<?php echo number_format($item['amount'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div class="amount-summary">
                <div class="amount-row total">
                    <span>订单总金额</span>
                    <span>¥<?php echo number_format($order['total_amount'], 2); ?></span>
                </div>
                <div class="amount-row" style="margin-top:8px;color:#999;">
                    <span>大写金额</span>
                    <span><?php echo numToChinese($order['total_amount']); ?></span>
                </div>
            </div>
        </div>
        
        <!-- 订单信息 -->
        <div class="order-detail-card">
            <div class="card-title">ℹ️ 订单信息</div>
            <div class="detail-row">
                <span class="label">订单编号</span>
                <span class="value"><?php echo $order['order_no']; ?></span>
            </div>
            <div class="detail-row">
                <span class="label">创建时间</span>
                <span class="value"><?php echo $order['created_at']; ?></span>
            </div>
            <div class="detail-row">
                <span class="label">制单人</span>
                <span class="value"><?php echo htmlspecialchars($makerName); ?></span>
            </div>
            <div class="detail-row">
                <span class="label">当前状态</span>
                <span class="value"><span class="badge badge-<?php echo $order['status']; ?>"><?php echo getStatusText($order['status']); ?></span></span>
            </div>
            <?php if ($order['remark']): ?>
            <div class="detail-row" style="flex-direction:column;gap:4px;">
                <span class="label">备注说明</span>
                <div style="color:#333;background:#f8f9fa;padding:10px;border-radius:6px;margin-top:4px;">
                    <?php echo nl2br(htmlspecialchars($order['remark'])); ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- 收款信息 -->
        <div class="order-detail-card">
            <div class="card-title">💰 收款信息</div>
            <div class="detail-row">
                <span class="label">收款状态</span>
                <span class="value"><span class="badge badge-<?php echo $paymentBadgeClass; ?>"><?php echo $paymentStatusText; ?></span></span>
            </div>
            <div class="detail-row">
                <span class="label">订单总金额</span>
                <span class="value" style="font-weight:600;">¥<?php echo number_format($order['total_amount'], 2); ?></span>
            </div>
            <?php $discountAmount = floatval($order['discount_amount'] ?? 0); ?>
            <?php if ($discountAmount > 0): ?>
            <div class="detail-row">
                <span class="label">优惠</span>
                <span class="value" style="color:#F59E0B;">-¥<?php echo number_format($discountAmount, 2); ?></span>
            </div>
            <?php endif; ?>
            <div class="detail-row">
                <span class="label">已收款</span>
                <span class="value" style="color:#22c55e;">¥<?php echo number_format($order['paid_amount'], 2); ?></span>
            </div>
            <div class="detail-row">
                <span class="label">待收款</span>
                <span class="value" style="color:#ef4444;font-weight:600;">¥<?php echo number_format(max(0, floatval($order['total_amount']) - $discountAmount - floatval($order['paid_amount'])), 2); ?></span>
            </div>
            
            <?php if ($order['payment_status'] < 2): ?>
            <div style="margin-top:12px;">
                <button class="btn btn-success btn-block" onclick="openPaymentModal()">💰 录入收款</button>
            </div>
            <?php endif; ?>
            
            <?php if (count($payments) > 0): ?>
            <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border);">
                <div style="font-weight:600;margin-bottom:8px;">📋 收款记录</div>
                <?php foreach ($payments as $payment): ?>
                <div style="background:#f8f9fa;padding:10px;border-radius:6px;margin-bottom:8px;font-size:13px;">
                    <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                        <span style="font-weight:500;">+ ¥<?php echo number_format($payment['amount'], 2); ?></span>
                        <span style="color:#666;"><?php echo $payment['created_at']; ?></span>
                    </div>
                    <div style="color:#666;">
                        <?php if ($payment['payment_method']): ?>方式：<?php echo htmlspecialchars($payment['payment_method']); ?> | <?php endif; ?>
                        操作人：<?php echo htmlspecialchars($payment['realname'] ?: '系统'); ?>
                        <?php if ($payment['remark']): ?><br>备注：<?php echo htmlspecialchars($payment['remark']); ?><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- 操作按钮 -->
        <div class="action-bar">
            <h3>⚡ 操作</h3>
            <div class="action-btns">
                <?php if ($order['status'] == 0): ?>
                <button class="btn btn-success" onclick="changeStatus('confirm', '确认订单？')">✅ 确认订单</button>
                <?php endif; ?>
                <?php if ($order['status'] == 1): ?>
                <button class="btn btn-primary" onclick="changeStatus('produce', '开始生产？')">🏭 开始生产</button>
                <?php endif; ?>
                <?php if ($order['status'] == 2): ?>
                <button class="btn btn-success" onclick="changeStatus('complete', '完成订单？')">✨ 完成订单</button>
                <?php endif; ?>
                <?php if ($order['status'] < 3): ?>
                <button class="btn btn-danger" onclick="changeStatus('cancel', '取消订单？')">❌ 取消订单</button>
                <?php endif; ?>
                <?php if ($order['status'] == 0 || $order['status'] == 1): ?>
                <a href="order_edit.php?id=<?php echo $id; ?>" class="btn btn-primary">✏️ 编辑</a>
                <?php endif; ?>
                <button class="btn btn-primary" onclick="doPrint()">🖨️ 打印</button>
                <a href="order_list.php" class="btn btn-outline">← 返回</a>
            </div>
        </div>
        
        <form id="statusForm" method="POST" style="display:none;">
            <input type="hidden" name="action" id="statusAction">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
        </form>
    </div>
    
    <?php
    // 打印用：判断本单是否含平方数（有则打印显示平方数列，无则与原样一致）
    $hasSqm = false; $sqmTotal = 0;
    foreach ($items as $__it) {
        if (floatval($__it['square_meter'] ?? 0) > 0) { $hasSqm = true; $sqmTotal += floatval($__it['square_meter']); }
    }
    ?>

    <!-- 打印专用区域 -->
    <div class="print-area">
        <div class="print-company"><?php echo htmlspecialchars($companyName); ?></div>
        <div class="print-title">销售单</div>
        
        <table class="print-info-table">
            <tr>
                <td>客户：<?php echo htmlspecialchars($order['customer_name']); ?></td>
                <td>电话：<?php echo htmlspecialchars($order['phone'] ?: ''); ?></td>
                <td>单据日期：<?php echo htmlspecialchars($order['order_date'] ?? date('Y-m-d', strtotime($order['created_at']))); ?></td>
                <td>单据编号：<?php echo $order['order_no']; ?></td>
            </tr>
            <tr>
                <td>联系人：<?php echo htmlspecialchars($order['contact'] ?: ''); ?></td>
                <td colspan="3">地址：<?php echo htmlspecialchars($order['address'] ?: ''); ?></td>
            </tr>
        </table>
        
        <table class="print-items<?php echo $hasSqm ? ' with-sqm' : ''; ?>">
            <thead>
                <tr>
                    <th>序号</th>
                    <th>商品</th>
                    <?php if ($hasSqm): ?><th>平方数</th><?php endif; ?>
                    <th>单位</th>
                    <th>数量</th>
                    <th>单价</th>
                    <th>销售金额</th>
                    <th>制作要求</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $i => $item): ?>
                <tr>
                    <td><?php echo $i + 1; ?></td>
                    <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                    <?php if ($hasSqm): ?><td><?php echo floatval($item['square_meter'] ?? 0) > 0 ? formatSqm($item['square_meter']) : ''; ?></td><?php endif; ?>
                    <td><?php echo htmlspecialchars($item['unit']); ?></td>
                    <td><?php echo number_format($item['quantity'], 2); ?></td>
                    <td><?php echo number_format($item['unit_price'], 2); ?></td>
                    <td><?php echo number_format($item['amount'], 2); ?></td>
                    <td><?php echo htmlspecialchars($item['specification'] ?: ''); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <?php if ($hasSqm): ?>
                    <td colspan="2" style="text-align:right;">合计：</td>
                    <td><?php echo formatSqm($sqmTotal); ?></td>
                    <td><?php echo number_format(array_sum(array_column($items, 'quantity')), 2); ?></td>
                    <td></td>
                    <td><?php echo number_format($order['total_amount'], 2); ?></td>
                    <td></td>
                    <?php else: ?>
                    <td colspan="3" style="text-align:right;">合计：</td>
                    <td><?php echo number_format(array_sum(array_column($items, 'quantity')), 2); ?></td>
                    <td></td>
                    <td><?php echo number_format($order['total_amount'], 2); ?></td>
                    <td></td>
                    <?php endif; ?>
                </tr>
                <tr class="caps-row">
                    <td colspan="<?php echo $hasSqm ? 8 : 7; ?>">合计金额（大写）：<?php echo numToChinese($order['total_amount']); ?></td>
                </tr>
            </tbody>
        </table>
        
        <table class="print-summary">
            <tr>
                <td>订单金额：<?php echo number_format($order['total_amount'], 2); ?></td>
                <?php $discountAmount2 = floatval($order['discount_amount'] ?? 0); ?>
                <?php if ($discountAmount2 > 0): ?>
                <td>优惠：<span style="color:#F59E0B;"><?php echo number_format($discountAmount2, 2); ?></span></td>
                <?php endif; ?>
                <td>已收款：<?php echo number_format($order['paid_amount'], 2); ?></td>
                <td>待收款：<?php echo number_format(max(0, floatval($order['total_amount']) - $discountAmount2 - floatval($order['paid_amount'])), 2); ?></td>
                <td>收款状态：<?php echo ['未付款', '部分收款', '已付清'][$order['payment_status']]; ?></td>
            </tr>
            <tr>
                <td colspan="4">备注说明：<?php echo htmlspecialchars($order['remark'] ?: ''); ?></td>
            </tr>
        </table>
        
        <table class="print-sign">
            <tr>
                <td>制单人：<?php echo htmlspecialchars($makerName); ?></td>
                <td>销售人员：<?php echo htmlspecialchars($makerName); ?></td>
                <td>客户签字：</td>
            </tr>
        </table>
    </div>

    <?php echo mobileNav('orders'); ?>

    <script>
    function changeStatus(action, confirmMsg) {
        if (!confirm(confirmMsg)) return;
        var formData = new FormData();
        formData.append('action', action);
        formData.append('csrf_token', '<?php echo $csrfToken; ?>');
        fetch('order_view.php?id=<?php echo $id; ?>', { method: 'POST', body: formData })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (data.ok) { alert('✅ ' + data.msg); location.reload(); }
            else { alert('❌ ' + data.msg); }
        })
        .catch(function(err){ alert('❌ 请求失败：' + err); });
    }
    
    // 用 iframe 打印：避免 window.print() 干扰主页面点击事件（某些手机浏览器会破坏 history/click）
    function doPrint() {
        var printArea = document.querySelector('.print-area');
        if (!printArea) { window.print(); return; }

        // 创建隐藏 iframe
        var iframe = document.createElement('iframe');
        iframe.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
        document.body.appendChild(iframe);

        var doc = iframe.contentDocument;
        doc.open();
        doc.write('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>打印</title>');
        doc.write('<style>');
        // 页面设置
        doc.write('@page { size: A4 portrait; margin: 10mm 12mm; }');
        // 基础重置
        doc.write('html, body { background: #fff !important; margin: 0 !important; padding: 0 !important; }');
        // 打印区域主样式（与 @media print 完全一致）
        doc.write('.print-area { display: block !important; position: static !important; visibility: visible !important; font-family: "SimSun", "宋体", serif; font-size: 10pt; color: #000; line-height: 1.5; width: 95%; max-width: 680px; margin: 0 auto; padding: 10px 4px; }');
        doc.write('.print-company { text-align: center; font-size: 16pt; font-weight: bold; margin-bottom: 4px; letter-spacing: 2px; }');
        doc.write('.print-title { text-align: center; font-size: 18pt; font-weight: bold; margin-bottom: 8px; letter-spacing: 4px; }');
        doc.write('.print-info-table { width: 100%; border-collapse: collapse; border: none !important; margin-bottom: 4px; font-size: 10pt; }');
        doc.write('.print-info-table td { padding: 2px 6px; white-space: nowrap; border: none !important; }');
        doc.write('.print-items { width: 100%; border-collapse: collapse; border: 1px solid #000 !important; margin: 4px 2px; font-size: 9pt; table-layout: fixed; }');
        // 列宽按是否含平方数列分两套
        if (<?php echo $hasSqm ? 'true' : 'false'; ?>) {
            doc.write('.print-items th:nth-child(1) { width: 6%; }');
            doc.write('.print-items th:nth-child(2) { width: 20%; }');
            doc.write('.print-items th:nth-child(3) { width: 11%; }');
            doc.write('.print-items th:nth-child(4) { width: 6%; }');
            doc.write('.print-items th:nth-child(5) { width: 9%; }');
            doc.write('.print-items th:nth-child(6) { width: 10%; }');
            doc.write('.print-items th:nth-child(7) { width: 13%; }');
            doc.write('.print-items th:nth-child(8) { width: 25%; }');
        } else {
            doc.write('.print-items th:nth-child(1) { width: 6%; }');
            doc.write('.print-items th:nth-child(2) { width: 23%; }');
            doc.write('.print-items th:nth-child(3) { width: 6%; }');
            doc.write('.print-items th:nth-child(4) { width: 11%; }');
            doc.write('.print-items th:nth-child(5) { width: 11%; }');
            doc.write('.print-items th:nth-child(6) { width: 14%; }');
            doc.write('.print-items th:nth-child(7) { width: 29%; }');
        }
        doc.write('.print-items th { border: 1px solid #000; padding: 3px 4px; text-align: center; font-weight: bold; background: #fff; }');
        doc.write('.print-items td { border: 1px solid #000; padding: 3px 4px; text-align: center; }');
        doc.write('.print-items .total-row { font-weight: bold; }');
        doc.write('.print-items tr.caps-row td { font-weight: normal; text-align: left; padding: 4px 8px; }');
        doc.write('.print-summary { width: 100%; border-collapse: collapse; border: none !important; margin-top: 2px; font-size: 10pt; }');
        doc.write('.print-summary td { padding: 3px 6px; white-space: nowrap; border: none !important; }');
        doc.write('.print-sign { width: 100%; border-collapse: collapse; border: none !important; margin-top: 6px; font-size: 10pt; }');
        doc.write('.print-sign td { padding: 4px 6px; width: 33%; border: none !important; }');
        doc.write('.sign-line { display: inline-block; width: 80px; border-bottom: 1px solid #000; margin-left: 4px; }');
        doc.write('</style></head><body>');
        doc.write(printArea.outerHTML);
        doc.write('</body></html>');
        doc.close();

        // 等待 iframe 内容加载后打印
        setTimeout(function() {
            try {
                iframe.contentWindow.focus();
                iframe.contentWindow.print();
            } catch (e) {
                console.error('iframe 打印失败，降级为主页面打印', e);
                if (iframe.parentNode) iframe.parentNode.removeChild(iframe);
                window.print(); // 降级方案
            }
            // 打印对话框关闭后移除 iframe（1秒后，避免某些浏览器需要时间）
            setTimeout(function() {
                if (iframe.parentNode) iframe.parentNode.removeChild(iframe);
            }, 1000);
        }, 150);
    }
    </script>
    <!-- 收款弹窗 -->
    <div id="paymentModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
        <div style="background:white;padding:24px;border-radius:12px;max-width:90%;margin:20px;width:100%;">
            <h3 style="margin:0 0 20px 0;font-size:18px;">💰 录入收款</h3>
            <form id="paymentForm" onsubmit="return submitPayment(event)">
                <input type="hidden" name="action" value="payment">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                
                <div style="background:#f8f9fa;padding:12px;border-radius:6px;margin-bottom:16px;">
                    <div style="display:flex;justify-content:space-between;margin-bottom:6px;font-size:13px;">
                        <span>订单金额：</span>
                        <span style="font-weight:600;">¥<?php echo number_format($order["total_amount"], 2); ?></span>
                    </div>
                    <?php if ($discountAmt > 0): ?>
                    <div style="display:flex;justify-content:space-between;font-size:13px;color:#F97316;">
                        <span>已优惠：</span>
                        <span style="font-weight:600;">-¥<?php echo number_format($discountAmt, 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <div style="display:flex;justify-content:space-between;font-size:13px;color:#22c55e;">
                        <span>已收款：</span>
                        <span style="font-weight:600;">¥<?php echo number_format($order["paid_amount"], 2); ?></span>
                    </div>
                </div>
                
                <div class="form-group">
                    <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151;">本次收款金额 *</label>
                    <!-- 【2026-09-15】自动填入待收金额（已扣累计优惠）；填写优惠后自动减去优惠 -->
                    <input type="number" name="amount" id="payAmount" step="0.01" min="0.01" value="<?php echo number_format($dueAmt, 2, ".", ""); ?>" class="form-control" oninput="payUserTouched = true" required>
                </div>
                
                <div class="form-group">
                    <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151;">本次优惠（可不填）</label>
                    <input type="number" name="discount" id="payDiscount" step="0.01" min="0" value="0" class="form-control" placeholder="如减免尾数" oninput="onPayDiscountInput()" style="border-color:#F97316;color:#F97316;">
                    <small style="color:#999;font-size:12px;">填写优惠后，收款金额会自动减去优惠部分</small>
                </div>
                
                <div class="form-group">
                    <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151;">收款方式</label>
                    <select name="payment_method" class="form-control">
                        <option value="现金">现金</option>
                        <option value="微信">微信</option>
                        <option value="支付宝">支付宝</option>
                        <option value="银行转账">银行转账</option>
                        <option value="其他">其他</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151;">备注</label>
                    <textarea name="payment_remark" rows="2" class="form-control" placeholder="选填"></textarea>
                </div>
                
                <div style="display:flex;gap:12px;margin-top:20px;">
                    <button type="submit" class="btn btn-success" style="flex:1;">确认收款</button>
                    <button type="button" class="btn btn-secondary" onclick="closePaymentModal()" style="flex:1;">取消</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
    var payDefaultDue = <?php echo json_encode((float)$dueAmt); ?>; // 【2026-09-15】待收金额基准（已扣累计优惠）
    var payUserTouched = false;

    // 优惠变动 → 收款金额自动调整：收款金额 = 待收 - 优惠
    function onPayDiscountInput() {
        if (payUserTouched) return;
        var discount = parseFloat(document.getElementById('payDiscount').value) || 0;
        if (discount < 0) discount = 0;
        document.getElementById('payAmount').value = Math.max(0, payDefaultDue - discount).toFixed(2);
    }

    function openPaymentModal() {
        document.getElementById("paymentModal").style.display = "flex";
        // 【2026-09-15】每次打开重置为待收金额
        payUserTouched = false;
        document.getElementById('payAmount').value = payDefaultDue > 0 ? payDefaultDue.toFixed(2) : '';
        document.getElementById('payDiscount').value = 0;
    }
    function closePaymentModal() {
        document.getElementById("paymentModal").style.display = "none";
    }
    function submitPayment(e) {
        e.preventDefault();
        var form = document.getElementById("paymentForm");
        var formData = new FormData(form);
        var btn = form.querySelector('button[type="submit"]');
        var oldText = btn.textContent;
        btn.disabled = true;
        btn.textContent = '处理中...';
        
        fetch('order_view.php?id=<?php echo $id; ?>', {
            method: 'POST',
            body: formData
        })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (data.ok) {
                alert('✅ 收款成功！');
                location.reload();
            } else {
                alert('❌ 收款失败：' + data.msg);
                btn.disabled = false;
                btn.textContent = oldText;
            }
        })
        .catch(function(err) {
            alert('❌ 请求失败：' + err.message);
            btn.disabled = false;
            btn.textContent = oldText;
        });
        return false;
    }
    </script>
</body>
</html>



