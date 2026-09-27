<?php
/**
 * 移动端 - 财务管理
 * 看板：应收总额 / 已收 / 未收 / 本月回款 / 逾期未收款
 * 列表：未收款订单（可跳订单收款）、近期收款明细
 * 导出：跳 PC 端 finance.php 的 Excel/PDF 导出（移动端复用，免重写）
 */
require_once 'config.php';
initDatabase();
checkLogin();

$db = getDB();
$isAdmin = isAdmin();

// 本月起止（用于"本月回款"，口径统一按收款时间，与 P1 修复一致）
$monthStart = date('Y-m-01') . ' 00:00:00';
$monthEnd   = date('Y-m-t') . ' 23:59:59';
// 逾期：订单超过 60 天仍未付清
$overdueDate = date('Y-m-d', strtotime('-60 days'));

// 看板口径（与 PC finance.php 一致：status IN (1,2,3) 为有效订单）
// 已考虑 discount_amount: 未收 = max(0, total - discount - paid)
$stmt = $db->prepare("SELECT COALESCE(SUM(total_amount),0), COALESCE(SUM(paid_amount),0), COALESCE(SUM(COALESCE(discount_amount, 0)), 0) FROM orders WHERE status IN (1,2,3)");
$stmt->execute();
list($totalAmtSum, $paidAmtSum, $discountSum) = $stmt->fetch(PDO::FETCH_NUM);
$receivable = floatval($totalAmtSum);
$received = floatval($paidAmtSum);
$totalDiscount = floatval($discountSum);
$pending = max(0, $receivable - $totalDiscount - $received);
$realReceivable = $receivable - $totalDiscount;

// 本月回款（按收款时间，与 P1 统一口径）
$stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments p WHERE p.created_at >= ? AND p.created_at <= ?");
$stmt->execute([$monthStart, $monthEnd]);
$monthReceived = floatval($stmt->fetchColumn());

// 逾期未收款（考虑优惠）
$stmt = $db->prepare("SELECT COALESCE(SUM(GREATEST(0, total_amount - COALESCE(discount_amount, 0) - paid_amount)), 0) FROM orders WHERE payment_status IN (0,1) AND status IN (1,2,3) AND order_date <= ?");
$stmt->execute([$overdueDate]);
$overdueAmount = floatval($stmt->fetchColumn());

// 未收款订单（前 20，考虑优惠）
$stmt = $db->prepare("SELECT o.id, o.order_no, o.total_amount, o.paid_amount, COALESCE(o.discount_amount, 0) AS discount_amount, c.name AS customer_name FROM orders o LEFT JOIN customers c ON o.customer_id=c.id WHERE o.payment_status IN (0,1) AND o.status IN (1,2,3) ORDER BY o.order_date ASC LIMIT 20");
$stmt->execute();
$unpaidOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 近期收款明细（前 20）
$stmt = $db->prepare("SELECT p.amount, p.payment_method, p.created_at, o.order_no, c.name AS customer_name, u.realname AS receiver FROM payments p JOIN orders o ON p.order_id=o.id LEFT JOIN customers c ON o.customer_id=c.id LEFT JOIN users u ON p.created_by=u.id ORDER BY p.created_at DESC LIMIT 20");
$stmt->execute();
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 本月支出（与本月回款口径统一）
$stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date >= ? AND expense_date <= ?");
$monthEndDate = date('Y-m-t');
$stmt->execute([date('Y-m-01'), $monthEndDate]);
$monthExpense = floatval($stmt->fetchColumn());

// 近期支出明细（前 20）
$stmt = $db->prepare("SELECT e.id, e.category, e.amount, e.expense_date, e.description, e.created_at, u.realname AS creator FROM expenses e LEFT JOIN users u ON e.created_by=u.id ORDER BY e.expense_date DESC, e.id DESC LIMIT 20");
$stmt->execute();
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 支出分类统计（本年）
$yearStart = date('Y-01-01');
$stmt = $db->prepare("SELECT category, COALESCE(SUM(amount),0) AS total FROM expenses WHERE expense_date >= ? GROUP BY category ORDER BY total DESC");
$stmt->execute([$yearStart]);
$expenseCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
$yearExpenseTotal = array_sum(array_column($expenseCategories, 'total'));

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>财务管理</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="page-header">
        <h1>💰 财务管理</h1>
        <p class="company">应收 / 回款 / 导出</p>
    </div>

    <div class="page">
        <!-- 看板 -->
        <div class="stats-grid">
            <div class="stat-card blue">
                <div class="stat-header"><span class="stat-label">应收总额</span><div class="stat-icon">📊</div></div>
                <div class="num money">￥<?php echo number_format($realReceivable, 2); ?></div>
                <?php if ($totalDiscount > 0.01): ?>
                <div style="font-size:11px;color:#F59E0B;margin-top:4px;">原 ￥<?php echo number_format($receivable, 2); ?> 减优惠 ￥<?php echo number_format($totalDiscount, 2); ?></div>
                <?php endif; ?>
            </div>
            <div class="stat-card green">
                <div class="stat-header"><span class="stat-label">已收</span><div class="stat-icon">✅</div></div>
                <div class="num money">￥<?php echo number_format($received, 2); ?></div>
            </div>
            <div class="stat-card orange">
                <div class="stat-header"><span class="stat-label">未收</span><div class="stat-icon">⏳</div></div>
                <div class="num money">￥<?php echo number_format($pending, 2); ?></div>
            </div>
            <div class="stat-card purple">
                <div class="stat-header"><span class="stat-label">本月回款</span><div class="stat-icon">💸</div></div>
                <div class="num money">￥<?php echo number_format($monthReceived, 2); ?></div>
            </div>
        </div>

        <!-- 本月收支对比 -->
        <div class="stats-grid" style="grid-template-columns: 1fr 1fr;">
            <div class="stat-card" style="background:linear-gradient(135deg,#F0FDF4 0%,#DCFCE7 100%);">
                <div class="stat-header"><span class="stat-label" style="color:#059669;">本月收入</span><div class="stat-icon">📈</div></div>
                <div class="num money" style="color:#059669;">￥<?php echo number_format($monthReceived, 2); ?></div>
            </div>
            <div class="stat-card" style="background:linear-gradient(135deg,#FEF2F2 0%,#FEE2E2 100%);">
                <div class="stat-header"><span class="stat-label" style="color:#DC2626;">本月支出</span><div class="stat-icon">📉</div></div>
                <div class="num money" style="color:#DC2626;">￥<?php echo number_format($monthExpense, 2); ?></div>
            </div>
        </div>

        <?php if ($overdueAmount > 0.01): ?>
        <div class="msg msg-warning">⚠️ 逾期(60天+)未收款：￥<?php echo number_format($overdueAmount, 2); ?></div>
        <?php endif; ?>

        <!-- 导出（仅管理员）-->
        <?php if ($isAdmin): ?>
        <div class="card">
            <div class="card-title">📤 导出报表</div>
            <a href="../finance.php?export=excel&range=month&year=<?php echo date('Y'); ?>&month=<?php echo date('n'); ?>" target="_blank" class="btn btn-success btn-block">📊 导出 Excel（本月）</a>
            <a href="../finance.php?export=pdf&range=month&year=<?php echo date('Y'); ?>&month=<?php echo date('n'); ?>" target="_blank" class="btn btn-danger btn-block" style="margin-top:10px;">📄 导出 PDF（本月）</a>
            <p style="color:var(--gray-400);font-size:12px;margin-top:10px;">完整财务看板与筛选请在电脑端访问 finance.php</p>
        </div>
        <?php endif; ?>

        <!-- 未收款订单 -->
        <div class="card">
            <div class="card-title">📋 未收款订单（<?php echo count($unpaidOrders); ?>）</div>
            <?php if (empty($unpaidOrders)): ?>
                <div class="empty-state"><div class="icon">🎉</div><div class="text">暂无未收款订单</div></div>
            <?php else: foreach ($unpaidOrders as $o):
                $discAmt = floatval($o['discount_amount'] ?? 0);
                $unpaid = max(0, floatval($o['total_amount']) - $discAmt - floatval($o['paid_amount'])); ?>
                <a href="order_view.php?id=<?php echo $o['id']; ?>" class="order-card">
                    <div class="order-header">
                        <span class="order-no"><?php echo h($o['order_no']); ?></span>
                        <span class="order-amount">￥<?php echo number_format($unpaid, 2); ?></span>
                    </div>
                    <div class="customer"><?php echo h($o['customer_name'] ?: '散客'); ?></div>
                    <div class="order-footer">
                        <span class="order-time">待收</span>
                        <span class="payment-info"><span class="paid">已收 ￥<?php echo number_format($o['paid_amount'], 2); ?></span> / ￥<?php echo number_format($o['total_amount'], 2); ?><?php echo $discAmt > 0 ? ' 优惠 ￥' . number_format($discAmt, 2) : ''; ?></span>
                    </div>
                </a>
            <?php endforeach; endif; ?>
        </div>

        <!-- 近期收款明细 -->
        <div class="card">
            <div class="card-title">💰 近期收款明细</div>
            <?php if (empty($payments)): ?>
                <div class="empty-state"><div class="icon">📭</div><div class="text">暂无收款记录</div></div>
            <?php else: foreach ($payments as $p): ?>
                <div class="list-item">
                    <div class="item-content">
                        <div class="item-title"><?php echo h($p['customer_name'] ?: '散客'); ?></div>
                        <div class="item-desc"><?php echo h($p['order_no']); ?> · <?php echo h($p['payment_method'] ?: '未填方式'); ?> · <?php echo date('m-d H:i', strtotime($p['created_at'])); ?> · <?php echo h($p['receiver'] ?: '系统'); ?></div>
                    </div>
                    <div class="item-right">
                        <div class="num" style="color:var(--success);font-weight:700;">+￥<?php echo number_format($p['amount'], 2); ?></div>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <!-- 支出分类统计（本年） -->
        <?php if (!empty($expenseCategories)): ?>
        <div class="card">
            <div class="card-title">📊 本年支出分类（合计 ￥<?php echo number_format($yearExpenseTotal, 2); ?>）</div>
            <?php foreach ($expenseCategories as $ec):
                $pct = $yearExpenseTotal > 0 ? round(floatval($ec['total']) / $yearExpenseTotal * 100) : 0;
            ?>
            <div style="margin-bottom:12px;">
                <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px;">
                    <span style="font-weight:600;"><?php echo h($ec['category']); ?></span>
                    <span style="color:#DC2626;font-weight:600;">￥<?php echo number_format($ec['total'], 2); ?> · <?php echo $pct; ?>%</span>
                </div>
                <div style="height:6px;background:#E5E7EB;border-radius:99px;overflow:hidden;">
                    <div style="height:100%;width:<?php echo $pct; ?>%;background:linear-gradient(90deg,#FCA5A5,#DC2626);border-radius:99px;"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- 近期支出明细 -->
        <div class="card">
            <div class="card-title">💸 近期支出明细（<?php echo count($expenses); ?>）</div>
            <?php if (empty($expenses)): ?>
                <div class="empty-state"><div class="icon">📭</div><div class="text">暂无支出记录</div></div>
            <?php else: foreach ($expenses as $e): ?>
                <div class="list-item">
                    <div class="item-content">
                        <div class="item-title" style="display:flex;align-items:center;gap:6px;">
                            <span style="background:#FEE2E2;color:#DC2626;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:600;"><?php echo h($e['category']); ?></span>
                            <?php if ($e['description']): ?>
                            <span style="color:#666;font-size:12px;"><?php echo h(mb_substr($e['description'], 0, 20)); ?><?php echo mb_strlen($e['description']) > 20 ? '...' : ''; ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="item-desc"><?php echo h($e['expense_date']); ?> · <?php echo h($e['creator'] ?: '系统'); ?></div>
                    </div>
                    <div class="item-right">
                        <div class="num" style="color:#DC2626;font-weight:700;">-￥<?php echo number_format($e['amount'], 2); ?></div>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <?php echo mobileNav('more'); ?>
</body>
</html>
