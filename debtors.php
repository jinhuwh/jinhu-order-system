<?php
/**
 * 客户欠款追踪
 */
require_once 'config.php';
initDatabase();
checkLogin();

$db = getDB();

// 获取欠款客户列表（按欠款金额倒序）
$sql = "SELECT 
            c.id, c.name, c.contact, c.phone, c.address,
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
        $stmt = $db->prepare("SELECT o.*, c.name as customer_name,
            (SELECT GROUP_CONCAT(CONCAT(oi.product_name, ' x', oi.quantity, oi.unit, ' ¥', oi.amount) SEPARATOR ' | ')
             FROM order_items oi WHERE oi.order_id = o.id) as items_detail
            FROM orders o
            LEFT JOIN customers c ON o.customer_id = c.id
            WHERE o.customer_id = ? AND o.status != 4 AND o.payment_status != 2
            ORDER BY o.created_at DESC");
        $stmt->execute([$selectedCustomer]);
        $unpaidOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// 获取客户的最近收款记录
function getPaymentRecords($db, $customerId, $limit = 5) {
    $stmt = $db->prepare("SELECT p.*, o.order_no 
        FROM payments p 
        JOIN orders o ON p.order_id = o.id 
        WHERE o.customer_id = ? 
        ORDER BY p.created_at DESC 
        LIMIT ?");
    $stmt->bindValue(1, $customerId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>欠款追踪 - <?php echo SITE_TITLE; ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .debt-stat-card { background: linear-gradient(135deg, #FEF2F2 0%, #FEE2E2 100%); border: 1px solid #FECACA; }
        .debt-danger { color: #DC2626; font-weight: 700; }
        .debt-warning { color: #D97706; font-weight: 600; }
        .debt-healthy { color: #059669; font-weight: 600; }
        .debt-row { transition: all 0.2s ease; }
        .debt-row:hover { background: #FFF1F2 !important; }
        .debt-row td { vertical-align: middle; padding: 14px 10px; }
        
        .progress-bar-bg { height: 6px; background: #E5E7EB; border-radius: 99px; overflow: hidden; }
        .progress-bar-fill { height: 100%; border-radius: 99px; transition: width 0.5s ease; }
        .progress-bar-fill.green { background: linear-gradient(90deg, #34D399, #10B981); }
        .progress-bar-fill.yellow { background: linear-gradient(90deg, #FBBF24, #F59E0B); }
        .progress-bar-fill.red { background: linear-gradient(90deg, #F87171, #DC2626); }
        
        .debt-detail-panel { display: none; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 10px; padding: 16px; margin-top: 8px; }
        .debt-detail-panel.active { display: block; }
        
        .pay-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px dashed #E5E7EB; font-size: 13px; }
        .pay-row:last-child { border-bottom: none; }
        .pay-method { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; background: #F3F4F6; color: #374151; }
        .pay-method.wechat { background: #D1FAE5; color: #065F46; }
        .pay-method.alipay { background: #DBEAFE; color: #1E40AF; }
        .pay-method.cash { background: #FEF3C7; color: #92400E; }
        .pay-method.bank { background: #EDE9FE; color: #5B21B6; }
        
        .customer-header { background: linear-gradient(135deg, #1E293B 0%, #334155 100%); color: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; }
        .customer-header h2 { margin: 0 0 4px 0; font-size: 20px; }
        .customer-header .sub { opacity: 0.75; font-size: 13px; }
        .customer-header .back-link { color: #94A3B8; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 4px; margin-bottom: 10px; }
        .customer-header .back-link:hover { color: white; }
    </style>
</head>
<body>
    <?php echo renderNav('debtors'); ?>

    <?php if ($selectedCustomer > 0 && $customerInfo): ?>
    <!-- 客户详情视图 -->
    <div class="customer-header">
        <a href="debtors.php" class="back-link">← 返回欠款列表</a>
        <h2>🏢 <?php echo htmlspecialchars($customerInfo['name']); ?></h2>
        <div class="sub">
            <?php echo htmlspecialchars($customerInfo['contact'] ?? '无联系人'); ?>
            <?php if ($customerInfo['phone']): ?> · 电话：<?php echo htmlspecialchars($customerInfo['phone']); ?><?php endif; ?>
            <?php if ($customerInfo['address']): ?> · <?php echo htmlspecialchars($customerInfo['address']); ?><?php endif; ?>
        </div>
    </div>

    <!-- 未付款订单明细 -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">📋 未付清订单（<?php echo count($unpaidOrders); ?> 笔）</div>
        </div>
        <div class="card-body">
            <?php if (empty($unpaidOrders)): ?>
            <div class="empty-state">✅ 该客户已全部付清，暂无欠款</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>订单号</th>
                            <th>订单内容</th>
                            <th>应收金额</th>
                            <th>已收</th>
                            <th>未收</th>
                            <th>收款状态</th>
                            <th>订单状态</th>
                            <th>开单时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $sumAmount = 0; $sumPaid = 0; $sumDiscount = 0;
                        foreach ($unpaidOrders as $o):
                            $due = max(0, floatval($o['total_amount']) - floatval($o['discount_amount'] ?? 0) - floatval($o['paid_amount']));
                            $sumAmount += floatval($o['total_amount']);
                            $sumPaid += floatval($o['paid_amount']);
                            $sumDiscount += floatval($o['discount_amount'] ?? 0);
                        ?>
                        <tr>
                            <td><a href="order_view.php?id=<?php echo $o['id']; ?>" class="td-link"><?php echo htmlspecialchars($o['order_no']); ?></a></td>
                            <td><span style="font-size:12px;color:#64748B;" title="<?php echo htmlspecialchars($o['items_detail'] ?? ''); ?>"><?php echo htmlspecialchars(mb_substr($o['items_detail'] ?: '-', 0, 50)); ?></span></td>
                            <td class="td-money"><?php echo formatMoney($o['total_amount']); ?></td>
                            <td class="td-money" style="color:#10B981;"><?php echo formatMoney($o['paid_amount']); ?></td>
                            <td class="td-money" style="color:#DC2626;font-weight:700;"><?php echo formatMoney($due); ?></td>
                            <td><span class="badge badge-<?php echo getPaymentStatusClass($o['payment_status']); ?>"><?php echo getPaymentStatusText($o['payment_status']); ?></span></td>
                            <td><span class="badge badge-<?php echo getStatusClass($o['status']); ?>"><?php echo getStatusText($o['status']); ?></span></td>
                            <td class="td-muted"><?php echo date('Y-m-d', strtotime($o['created_at'])); ?></td>
                            <td><a href="order_view.php?id=<?php echo $o['id']; ?>#paymentSection" class="btn btn-sm btn-primary">收款</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight:700;background:#FEF2F2;">
                            <td colspan="2">合计（<?php echo count($unpaidOrders); ?> 笔）</td>
                            <td style="color:#DC2626;"><?php echo formatMoney($sumAmount); ?></td>
                            <td style="color:#10B981;"><?php echo formatMoney($sumPaid); ?></td>
                            <td style="color:#DC2626;"><?php echo formatMoney(max(0, $sumAmount - $sumPaid - $sumDiscount)); ?></td>
                            <td colspan="4"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 收款记录 -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">💰 最近收款记录</div>
        </div>
        <div class="card-body">
            <?php 
            $payments = getPaymentRecords($db, $selectedCustomer);
            if (empty($payments)): 
            ?>
            <div class="empty-state">暂无收款记录</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>时间</th>
                            <th>关联订单</th>
                            <th>金额</th>
                            <th>方式</th>
                            <th>备注</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                        <tr>
                            <td class="td-muted"><?php echo date('Y-m-d H:i', strtotime($p['created_at'])); ?></td>
                            <td><a href="order_view.php?id=<?php echo $p['order_id']; ?>" class="td-link"><?php echo htmlspecialchars($p['order_no']); ?></a></td>
                            <td class="td-money" style="color:#059669;"><?php echo formatMoney($p['amount']); ?></td>
                            <td><span class="pay-method <?php echo getPayMethodClass($p['payment_method']); ?>"><?php echo htmlspecialchars($p['payment_method'] ?: '-'); ?></span></td>
                            <td style="color:#6B7280;font-size:13px;"><?php echo htmlspecialchars($p['remark'] ?? ''); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php else: ?>
    <!-- 欠款总览视图 -->
    <div class="page-title-bar">
        <div class="page-title-left">
            <h1>📋 欠款追踪</h1>
            <p>按客户汇总欠款金额，追踪应收款项</p>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card blue">
            <div class="stat-card-header">
                <span class="stat-card-label">欠款客户数</span>
                <div class="stat-card-icon">🏢</div>
            </div>
            <div class="stat-card-value"><?php echo count($debtors); ?></div>
            <div class="stat-card-trend neutral">个客户有欠款</div>
        </div>
        <div class="stat-card red">
            <div class="stat-card-header">
                <span class="stat-card-label">欠款总额</span>
                <div class="stat-card-icon">⚠️</div>
            </div>
            <div class="stat-card-value money"><?php echo formatMoney($totalDebt); ?></div>
            <div class="stat-card-trend down">↓ 待收款</div>
        </div>
        <div class="stat-card green">
            <div class="stat-card-header">
                <span class="stat-card-label">已回收</span>
                <div class="stat-card-icon">✅</div>
            </div>
            <div class="stat-card-value money"><?php echo formatMoney($totalPaid); ?></div>
            <div class="stat-card-trend up">↑ 占 <?php echo $totalRealReceivable > 0 ? round($totalPaid / $totalRealReceivable * 100) : 0; ?>%</div>
        </div>
        <div class="stat-card purple">
            <div class="stat-card-header">
                <span class="stat-card-label">总应收</span>
                <div class="stat-card-icon">💰</div>
            </div>
            <div class="stat-card-value money"><?php echo formatMoney($totalAmount); ?></div>
            <div class="stat-card-trend neutral">实收 <?php echo formatMoney($totalRealReceivable); ?><?php echo $totalDiscount > 0 ? '，优惠 ' . formatMoney($totalDiscount) : ''; ?></div>
        </div>
    </div>

    <div class="card">
        <div class="card-body" style="padding:0;">
            <?php if (empty($debtors)): ?>
            <div class="empty-state" style="padding:60px 20px;">
                <div class="icon">🎉</div>
                <div class="text">所有客户已付清，暂无欠款</div>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>客户名称</th>
                            <th>联系人</th>
                            <th>电话</th>
                            <th>总订单</th>
                            <th>应收总额</th>
                            <th>已收</th>
                            <th>优惠</th>
                            <th>欠款金额</th>
                            <th>收款率</th>
                            <th>最后订单</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($debtors as $d): 
                            $debt = floatval($d['debt_amount']);
                            $totalAmt = floatval($d['total_amount']);
                            $paidAmt = floatval($d['paid_amount']);
                            $discAmt = floatval($d['discount_amount'] ?? 0);
                            $realReceivable = $totalAmt - $discAmt;
                            $ratio = $realReceivable > 0 ? round($paidAmt / $realReceivable * 100) : 0;
                            $ratio = min(100, max(0, $ratio));
                        ?>
                        <tr class="debt-row">
                            <td><strong><?php echo htmlspecialchars($d['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($d['contact'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($d['phone'] ?: '-'); ?></td>
                            <td><?php echo intval($d['order_count']); ?></td>
                            <td class="td-money"><?php echo formatMoney($totalAmt); ?></td>
                            <td class="td-money" style="color:#10B981;"><?php echo formatMoney($paidAmt); ?></td>
                            <td class="td-money" style="color:#F59E0B;"><?php echo $discAmt > 0 ? formatMoney($discAmt) : '-'; ?></td>
                            <td class="td-money" style="color:<?php echo $debt > 10000 ? '#DC2626' : ($debt > 1000 ? '#D97706' : '#6B7280'); ?>;font-weight:700;">
                                <?php echo formatMoney($debt); ?>
                            </td>
                            <td>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <div class="progress-bar-bg" style="flex:1;min-width:60px;">
                                        <div class="progress-bar-fill <?php echo $ratio >= 70 ? 'green' : ($ratio >= 30 ? 'yellow' : 'red'); ?>" style="width:<?php echo $ratio; ?>%;"></div>
                                    </div>
                                    <span style="font-size:12px;font-weight:600;color:<?php echo $ratio >= 70 ? '#059669' : ($ratio >= 30 ? '#D97706' : '#DC2626'); ?>;"><?php echo $ratio; ?>%</span>
                                </div>
                            </td>
                            <td class="td-muted"><?php echo $d['last_order_date'] ? date('Y-m-d', strtotime($d['last_order_date'])) : '-'; ?></td>
                            <td><a href="debtors.php?customer_id=<?php echo $d['id']; ?>" class="btn btn-sm btn-primary">查看详情</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php echo renderFooter(); ?>
</body>
</html>
