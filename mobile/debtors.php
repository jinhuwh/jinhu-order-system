<?php
/**
 * 手机端 - 欠款追踪
 */
require_once 'config.php';
initDatabase();
checkLogin();

$db = getDB();
$companyName = getSetting('company_name') ?: '广告公司';

// 获取欠款客户列表
$sql = "SELECT 
            c.id, c.name, c.contact, c.phone,
            COUNT(o.id) as order_count,
            COALESCE(SUM(o.total_amount), 0) as total_amount,
            COALESCE(SUM(o.paid_amount), 0) as paid_amount,
            COALESCE(SUM(COALESCE(o.discount_amount, 0)), 0) as discount_amount,
            COALESCE(SUM(GREATEST(0, o.total_amount - COALESCE(o.discount_amount, 0) - o.paid_amount)), 0) as debt_amount,
            MAX(o.updated_at) as last_order_date
        FROM customers c
        LEFT JOIN orders o ON c.id = o.customer_id AND o.status != 4
        GROUP BY c.id
        HAVING debt_amount > 0
        ORDER BY debt_amount DESC";

$debtors = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// 总统计
$totalDebt = 0;
$totalAmount = 0;
$totalPaid = 0;
$totalDiscount = 0;
foreach ($debtors as $d) {
    $totalDebt += floatval($d['debt_amount']);
    $totalAmount += floatval($d['total_amount']);
    $totalPaid += floatval($d['paid_amount']);
    $totalDiscount += floatval($d['discount_amount'] ?? 0);
}
$totalRealReceivable = $totalAmount - $totalDiscount;

// 获取选中客户的未付款订单
$selectedCustomer = intval($_GET['customer_id'] ?? 0);
$unpaidOrders = [];
$customerInfo = null;
if ($selectedCustomer > 0) {
    $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$selectedCustomer]);
    $customerInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($customerInfo) {
        $stmt = $db->prepare("SELECT o.* FROM orders o WHERE o.customer_id = ? AND o.status != 4 AND o.payment_status != 2 ORDER BY o.created_at DESC");
        $stmt->execute([$selectedCustomer]);
        $unpaidOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// 获取客户的最近收款记录
function getPaymentRecords($db, $customerId, $limit = 5) {
    $stmt = $db->prepare("SELECT p.*, o.order_no FROM payments p JOIN orders o ON p.order_id = o.id WHERE o.customer_id = ? ORDER BY p.created_at DESC LIMIT ?");
    $stmt->bindValue(1, $customerId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>欠款追踪 - <?php echo htmlspecialchars($companyName); ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .summary-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 20px; }
        .summary-item { background: white; border-radius: var(--radius); padding: 16px 10px; text-align: center; box-shadow: var(--shadow); }
        .summary-item .num { font-size: 18px; font-weight: 700; color: var(--primary); margin-bottom: 6px; }
        .summary-item .num.red { color: #EF4444; }
        .summary-item .num.green { color: #10B981; }
        .summary-item .label { font-size: 12px; color: #999; }
        
        .debtor-card { display: block; background: white; border-radius: var(--radius); padding: 16px; margin-bottom: 12px; box-shadow: var(--shadow); text-decoration: none; color: inherit; }
        .debtor-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .debtor-name { font-size: 16px; font-weight: 600; }
        .debtor-amount { font-size: 20px; font-weight: 700; color: #EF4444; }
        .debtor-info { display: flex; gap: 16px; font-size: 13px; color: #666; margin-bottom: 10px; }
        .debtor-info span { display: flex; align-items: center; gap: 4px; }
        .debtor-progress { margin-top: 10px; }
        .progress-bar { height: 6px; background: #E5E7EB; border-radius: 99px; overflow: hidden; }
        .progress-fill { height: 100%; border-radius: 99px; }
        .progress-fill.green { background: #10B981; }
        .progress-fill.yellow { background: #F59E0B; }
        .progress-fill.red { background: #EF4444; }
        .progress-text { font-size: 12px; color: #999; margin-top: 4px; text-align: right; }
        
        .order-list { margin-top: 12px; }
        .order-item { display: block; background: white; border-radius: var(--radius); padding: 14px 16px; margin-bottom: 10px; box-shadow: var(--shadow); text-decoration: none; color: inherit; }
        .order-item:last-child { margin-bottom: 0; }
        .order-item-content { display: flex; justify-content: space-between; align-items: flex-start; }
        .order-left { flex: 1; min-width: 0; }
        .order-right { text-align: right; margin-left: 12px; }
        .order-no { font-size: 15px; font-weight: 600; color: var(--primary); margin-bottom: 4px; }
        .order-meta { display: flex; align-items: center; gap: 8px; font-size: 12px; color: #999; }
        .order-meta .badge { padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; }
        .order-meta .badge-success { background: #D1FAE5; color: #065F46; }
        .order-meta .badge-warning { background: #FEF3C7; color: #92400E; }
        .order-meta .badge-danger { background: #FEE2E2; color: #991B1B; }
        .order-due { font-size: 18px; font-weight: 700; color: #EF4444; margin-bottom: 2px; }
        .order-paid { font-size: 12px; color: #10B981; font-weight: 500; }
        
        .payment-list { margin-top: 16px; }
        .payment-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; background: #F8FAFC; border-radius: 8px; margin-bottom: 8px; }
        .payment-left { display: flex; flex-direction: column; gap: 2px; }
        .payment-time { font-size: 12px; color: #999; }
        .payment-order { font-size: 13px; color: var(--primary); }
        .payment-method { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; background: #F3F4F6; color: #374151; }
        .payment-method.wechat { background: #D1FAE5; color: #065F46; }
        .payment-method.alipay { background: #DBEAFE; color: #1E40AF; }
        .payment-method.cash { background: #FEF3C7; color: #92400E; }
        .payment-method.bank { background: #EDE9FE; color: #5B21B6; }
        .payment-amount { font-size: 15px; font-weight: 700; color: #10B981; }
        
        .empty-tip { text-align: center; padding: 40px 20px; color: #999; }
        .empty-tip .icon { font-size: 48px; margin-bottom: 12px; }
        
        .back-link { display: inline-flex; align-items: center; gap: 6px; color: var(--primary); font-size: 14px; margin-bottom: 16px; }
        .customer-header { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; border-radius: var(--radius); padding: 20px; margin-bottom: 16px; }
        .customer-header h2 { margin: 0 0 4px 0; font-size: 18px; }
        .customer-header .sub { opacity: 0.85; font-size: 13px; }
        
        .section-title { font-size: 15px; font-weight: 600; margin: 20px 0 12px 0; display: flex; align-items: center; gap: 6px; }
    </style>
</head>
<body>
    <?php if ($selectedCustomer > 0 && $customerInfo): ?>
    <!-- 客户详情视图 -->
    <div class="page-header">
        <h1>💳 欠款详情</h1>
    </div>
    
    <div class="page">
        <a href="debtors.php" class="back-link">← 返回欠款列表</a>
        
        <div class="customer-header">
            <h2><?php echo htmlspecialchars($customerInfo['name']); ?></h2>
            <div class="sub">
                <?php echo htmlspecialchars($customerInfo['contact'] ?: '无联系人'); ?>
                <?php if ($customerInfo['phone']): ?> · <?php echo htmlspecialchars($customerInfo['phone']); ?><?php endif; ?>
            </div>
        </div>
        
        <!-- 未付款订单 -->
        <div class="section-title">📋 未付清订单（<?php echo count($unpaidOrders); ?> 笔）</div>
        <?php if (empty($unpaidOrders)): ?>
        <div class="empty-tip">✅ 该客户已全部付清</div>
        <?php else: ?>
        <div class="order-list">
            <?php 
            $sumAmount = 0; $sumPaid = 0; $sumDiscount = 0;
            foreach ($unpaidOrders as $o): 
                $due = max(0, floatval($o['total_amount']) - floatval($o['discount_amount'] ?? 0) - floatval($o['paid_amount']));
                $sumAmount += floatval($o['total_amount']);
                $sumPaid += floatval($o['paid_amount']);
                $sumDiscount += floatval($o['discount_amount'] ?? 0);
            ?>
            <a href="order_view.php?id=<?php echo $o['id']; ?>" class="order-item">
                <div class="order-item-content">
                    <div class="order-left">
                        <div class="order-no"><?php echo htmlspecialchars($o['order_no']); ?></div>
                        <div class="order-meta">
                            <span><?php echo date('Y-m-d', strtotime($o['created_at'])); ?></span>
                            <span class="badge badge-<?php echo $o['status'] == 3 ? 'success' : ($o['status'] == 2 ? 'warning' : 'danger'); ?>"><?php echo getStatusText($o['status']); ?></span>
                        </div>
                    </div>
                    <div class="order-right">
                        <div class="order-due">¥<?php echo number_format($due, 2); ?></div>
                        <div class="order-paid">已收 ¥<?php echo number_format(floatval($o['paid_amount']), 2); ?></div>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <div class="debtor-summary-bar">
            <div class="debtor-summary-item">
                <span class="label">订单总额</span>
                <span class="value">¥<?php echo number_format($sumAmount, 2); ?></span>
            </div>
            <div class="debtor-summary-item">
                <span class="label">已收金额</span>
                <span class="value green">¥<?php echo number_format($sumPaid, 2); ?></span>
            </div>
            <?php if ($sumDiscount > 0): ?>
            <div class="debtor-summary-item">
                <span class="label">优惠</span>
                <span class="value" style="color:#F59E0B;">-¥<?php echo number_format($sumDiscount, 2); ?></span>
            </div>
            <?php endif; ?>
            <div class="debtor-summary-item highlight">
                <span class="label">合计欠款</span>
                <span class="value red">¥<?php echo number_format(max(0, $sumAmount - $sumPaid - $sumDiscount), 2); ?></span>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- 最近收款记录 -->
        <div class="section-title">💰 最近收款记录</div>
        <?php 
        $payments = getPaymentRecords($db, $selectedCustomer);
        if (empty($payments)): 
        ?>
        <div class="empty-tip">暂无收款记录</div>
        <?php else: ?>
        <div class="payment-list">
            <?php foreach ($payments as $p): ?>
            <div class="payment-item">
                <div class="payment-left">
                    <span class="payment-time"><?php echo date('Y-m-d H:i', strtotime($p['created_at'])); ?></span>
                    <span class="payment-order"><?php echo htmlspecialchars($p['order_no']); ?></span>
                    <?php if ($p['remark']): ?><span style="font-size:12px;color:#666;"><?php echo htmlspecialchars($p['remark']); ?></span><?php endif; ?>
                </div>
                <div style="text-align:right;">
                    <span class="payment-method <?php echo getPayMethodClass($p['payment_method']); ?>"><?php echo htmlspecialchars($p['payment_method'] ?: '未指定'); ?></span>
                    <div class="payment-amount">+¥<?php echo number_format(floatval($p['amount']), 2); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <?php else: ?>
    <!-- 欠款总览视图 -->
    <div class="page-header">
        <h1>💳 欠款追踪</h1>
    </div>
    
    <div class="page">
        <!-- 统计卡片 -->
        <div class="summary-grid">
            <div class="summary-item">
                <div class="num"><?php echo count($debtors); ?></div>
                <div class="label">欠款客户</div>
            </div>
            <div class="summary-item">
                <div class="num red">¥<?php echo number_format($totalDebt, 0); ?></div>
                <div class="label">欠款总额</div>
            </div>
            <div class="summary-item">
                <div class="num green">¥<?php echo number_format($totalPaid, 0); ?></div>
                <div class="label">已回收</div>
            </div>
            <div class="summary-item">
                <div class="num"><?php echo $totalRealReceivable > 0 ? min(100, round($totalPaid / $totalRealReceivable * 100)) : 0; ?>%</div>
                <div class="label">回收率</div>
            </div>
        </div>
        
        <!-- 欠款客户列表 -->
        <?php if (empty($debtors)): ?>
        <div class="empty-tip">
            <div class="icon">🎉</div>
            <div>所有客户已付清，暂无欠款</div>
        </div>
        <?php else: ?>
        <?php foreach ($debtors as $d): 
            $debt = floatval($d['debt_amount']);
            $totalAmt = floatval($d['total_amount']);
            $paidAmt = floatval($d['paid_amount']);
            $discAmt = floatval($d['discount_amount'] ?? 0);
            $realReceivable = $totalAmt - $discAmt;
            $ratio = $realReceivable > 0 ? min(100, max(0, round($paidAmt / $realReceivable * 100))) : 0;
        ?>
        <a href="debtors.php?customer_id=<?php echo $d['id']; ?>" class="debtor-card">
            <div class="debtor-header">
                <span class="debtor-name"><?php echo htmlspecialchars($d['name']); ?></span>
                <span class="debtor-amount">¥<?php echo number_format($debt, 2); ?></span>
            </div>
            <div class="debtor-info">
                <span>📞 <?php echo htmlspecialchars($d['phone'] ?: '-'); ?></span>
                <span>📋 <?php echo intval($d['order_count']); ?> 单</span>
            </div>
            <div class="debtor-progress">
                <div class="progress-bar">
                    <div class="progress-fill <?php echo $ratio >= 70 ? 'green' : ($ratio >= 30 ? 'yellow' : 'red'); ?>" style="width:<?php echo $ratio; ?>%;"></div>
                </div>
                <div class="progress-text">已收 ¥<?php echo number_format($paidAmt, 2); ?> / 应收 ¥<?php echo number_format($realReceivable, 2); ?>（<?php echo $ratio; ?>%）<?php echo $discAmt > 0 ? ' 优惠¥' . number_format($discAmt, 2) : ''; ?></div>
            </div>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <?php echo mobileNav('more'); ?>
</body>
</html>
