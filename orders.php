<?php
// 【2026-09-09 修复】opcache 强制清理 + 关闭错误显示 + 防意外输出污染 JSON
if (function_exists('opcache_invalidate')) { @opcache_invalidate(__FILE__, true); }
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
require_once 'config.php';
initDatabase();
checkLogin();

$db = getDB();

// 分页
$page = intval($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// 筛选
$where = [];
$params = [];

if (!empty($_GET['status']) && $_GET['status'] !== '') {
    $where[] = "o.status = ?";
    $params[] = $_GET['status'];
}

if (!empty($_GET['keyword'])) {
    $kw = addcslashes($_GET['keyword'], '%_\\');  // 转义LIKE通配符
    $where[] = "(o.order_no LIKE ? OR c.name LIKE ?)";
    $params[] = "%{$kw}%";
    $params[] = "%{$kw}%";
}

// 高级筛选：日期范围
if (!empty($_GET['date_start'])) {
    $where[] = "DATE(o.created_at) >= ?";
    $params[] = $_GET['date_start'];
}
if (!empty($_GET['date_end'])) {
    $where[] = "DATE(o.created_at) <= ?";
    $params[] = $_GET['date_end'];
}

// 高级筛选：金额范围
if (isset($_GET['amount_min']) && $_GET['amount_min'] !== '') {
    $where[] = "o.total_amount >= ?";
    $params[] = floatval($_GET['amount_min']);
}
if (isset($_GET['amount_max']) && $_GET['amount_max'] !== '') {
    $where[] = "o.total_amount <= ?";
    $params[] = floatval($_GET['amount_max']);
}

// 高级筛选：客户
if (!empty($_GET['customer_id'])) {
    $where[] = "o.customer_id = ?";
    $params[] = intval($_GET['customer_id']);
}

// 高级筛选：收款状态
if (isset($_GET['payment_status']) && $_GET['payment_status'] !== '') {
    $where[] = "o.payment_status = ?";
    $params[] = intval($_GET['payment_status']);
}

// 高级筛选：开票状态
if (isset($_GET['invoice_status']) && $_GET['invoice_status'] !== '') {
    $where[] = "o.invoice_status = ?";
    $params[] = intval($_GET['invoice_status']);
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// 获取客户列表（用于高级筛选下拉）
$customerOptions = $db->query("SELECT id, name FROM customers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// 获取总数
$countSql = "SELECT COUNT(*) FROM orders o LEFT JOIN customers c ON o.customer_id = c.id $whereSql";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$total = $stmt->fetchColumn();
$totalPages = ceil($total / $perPage);

// 删除订单（POST请求 + CSRF验证 + 状态限制 + 事务）
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete_order') {
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {

        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON

        while (ob_get_level() > 0) { ob_end_clean(); }

        echo json_encode(['ok' => false, 'msg' => '非法请求']);
        exit;
    }
    // 【2026-09-08 安全加固】删除订单需要 PERM_ORDER_DELETE 权限
    if (!hasPermission(PERM_ORDER_DELETE)) {

        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON

        while (ob_get_level() > 0) { ob_end_clean(); }

        echo json_encode(['ok' => false, 'msg' => '权限不足，无法删除订单']);
        exit;
    }
    $deleteId = intval($_POST['id'] ?? 0);
    if ($deleteId <= 0) {

        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON

        while (ob_get_level() > 0) { ob_end_clean(); }

        echo json_encode(['ok' => false, 'msg' => '参数错误']);
        exit;
    }
    try {
        // 1. 查订单当前状态
        $stmt = $db->prepare("SELECT status, order_no FROM orders WHERE id = ?");
        $stmt->execute([$deleteId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {

            // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON

            while (ob_get_level() > 0) { ob_end_clean(); }

            echo json_encode(['ok' => false, 'msg' => '订单不存在']);
            exit;
        }
        // 2. 状态限制：只允许待确认(0) 和 已取消(4) 删除
        if (!in_array(intval($order['status']), [0, 4])) {

            // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON

            while (ob_get_level() > 0) { ob_end_clean(); }

            echo json_encode(['ok' => false, 'msg' => '该状态订单不可删除（仅限待确认、已取消）']);
            exit;
        }
        // 3. 收集要删除的文件路径（事务外先取出来）
        $itemStmt = $db->prepare("SELECT image_path FROM order_items WHERE order_id = ? AND image_path != ''");
        $itemStmt->execute([$deleteId]);
        $itemImages = $itemStmt->fetchAll(PDO::FETCH_COLUMN);
        $payStmt = $db->prepare("SELECT attachment FROM payments WHERE order_id = ? AND attachment != ''");
        $payStmt->execute([$deleteId]);
        $paymentFiles = $payStmt->fetchAll(PDO::FETCH_COLUMN);

        // 4. 事务删除（订单+明细+收款记录）
        $db->beginTransaction();
        $db->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$deleteId]);
        $db->prepare("DELETE FROM payments WHERE order_id = ?")->execute([$deleteId]);
        $db->prepare("DELETE FROM orders WHERE id = ?")->execute([$deleteId]);
        $db->commit();

        // 5. 删除物理文件（订单明细参考图 + 收款凭证）——仅限 uploads/ 下订单子目录，防路径穿越
        $erpRoot = realpath(dirname(__FILE__));
        $safePrefixes = [
            $erpRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'order_items',
            $erpRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'batch',
            $erpRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'payments',
        ];
        $isSafe = function ($relativePath) use ($erpRoot, $safePrefixes) {
            $real = realpath($erpRoot . DIRECTORY_SEPARATOR . $relativePath);
            if ($real === false) return false;
            foreach ($safePrefixes as $p) {
                if (strpos($real, $p) === 0) return true;
            }
            return false;
        };
        $deletedFiles = [];
        foreach ($itemImages as $img) {
            if ($isSafe($img)) {
                $fullPath = $erpRoot . DIRECTORY_SEPARATOR . $img;
                if (file_exists($fullPath)) {
                    @unlink($fullPath);
                    $deletedFiles[] = $img . ' [item]';
                } else {
                    $deletedFiles[] = $img . ' [NOT_FOUND]';
                }
            } else {
                $deletedFiles[] = $img . ' [UNSAFE_SKIP]';
            }
        }
        foreach ($paymentFiles as $file) {
            if ($isSafe($file)) {
                $fullPath = $erpRoot . DIRECTORY_SEPARATOR . $file;
                if (file_exists($fullPath)) {
                    @unlink($fullPath);
                    $deletedFiles[] = $file . ' [pay]';
                } else {
                    $deletedFiles[] = $file . ' [PAY_NOT_FOUND]';
                }
            } else {
                $deletedFiles[] = $file . ' [UNSAFE_SKIP]';
            }
        }
        error_log("[delete_order #$deleteId] files: " . implode(', ', $deletedFiles));


        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON


        while (ob_get_level() > 0) { ob_end_clean(); }


        echo json_encode(['ok' => true, 'msg' => '订单 ' . $order['order_no'] . ' 已删除']);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("Delete order failed: " . $e->getMessage());

        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON

        while (ob_get_level() > 0) { ob_end_clean(); }

        echo json_encode(['ok' => false, 'msg' => '删除失败：' . $e->getMessage()]);
    }
    exit;
}

// 批量收款（POST + CSRF验证）
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'batch_payment') {
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode(['ok' => false, 'msg' => '非法请求']);
        exit;
    }

    $idsStr = $_POST['ids'] ?? '';
    $amount = floatval($_POST['amount'] ?? 0);
    $method = $_POST['method'] ?? '';
    $note = $_POST['note'] ?? '';

    if (empty($idsStr) || $amount <= 0 || empty($method)) {
        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode(['ok' => false, 'msg' => '参数错误']);
        exit;
    }

    $ids = array_filter(array_map('intval', explode(',', $idsStr)));
    if (empty($ids)) {
        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode(['ok' => false, 'msg' => '未选择有效订单']);
        exit;
    }

    // 验证收款方式
    $validMethods = ['cash' => '现金', 'wechat' => '微信', 'alipay' => '支付宝', 'bank' => '银行转账', 'other' => '其他'];
    if (!isset($validMethods[$method])) {
        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode(['ok' => false, 'msg' => '无效的收款方式']);
        exit;
    }

    try {
        // 确保 payments 表存在
        $db->exec("CREATE TABLE IF NOT EXISTS `payments` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `order_id` INT NOT NULL COMMENT '订单ID',
            `amount` DECIMAL(10,2) NOT NULL COMMENT '收款金额',
            `payment_method` VARCHAR(50) COMMENT '收款方式',
            `remark` TEXT COMMENT '备注',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='收款记录表'");

        $db->beginTransaction();

        // 一次性锁行取出所有订单（FOR UPDATE 防并发）
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT id, order_no, total_amount, discount_amount, paid_amount, payment_status
                              FROM orders WHERE id IN ($ph) FOR UPDATE");
        $stmt->execute($ids);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($orders)) {
            $db->rollBack();
            // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
            while (ob_get_level() > 0) { ob_end_clean(); }
            echo json_encode(['ok' => false, 'msg' => '未找到订单']);
            exit;
        }

        $batchDiscount = floatval($_POST['discount'] ?? 0);
        if ($batchDiscount < 0) {
            $db->rollBack();
            // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
            while (ob_get_level() > 0) { ob_end_clean(); }
            echo json_encode(['ok' => false, 'msg' => '优惠金额不能为负']);
            exit;
        }

        $remaining = $amount;          // 总池子，按订单 due 顺序递减分配
        $remainingDiscount = $batchDiscount; // 优惠池子同理
        $successCount = 0;
        $failMessages = [];
        $allocations = [];

        // 智能分配：
        // 1) 按 total_amount 降序让大单优先获得优惠
        // 2) 优惠“能吃到它身上”的条件：该单吃了优惠后能付清（或多付）
        // 3) 收款仅在线除“优惠后应付”后，min(收款池剩余, 该单新待收)
        // 4) 多出的部分继续分给小单

        // 先按 total_amount 降序拍
        if ($batchDiscount > 0) {
            usort($orders, function($a, $b) {
                return floatval($b['total_amount']) <=> floatval($a['total_amount']);
            });
        } else {
            // 无优惠时仍按用户勾选顺序
            $orderIndex = [];
            foreach ($orders as $o) $orderIndex[intval($o['id'])] = $o;
            $orders = [];
            foreach ($ids as $id) {
                if (isset($orderIndex[$id])) $orders[] = $orderIndex[$id];
            }
        }

        foreach ($orders as $order) {
            $oid = intval($order['id']);

            if (intval($order['payment_status']) == 2) {
                $failMessages[] = "订单 {$order['order_no']} 已付清";
                continue;
            }

            $totalAmount = floatval($order['total_amount']);
            $existingDiscount = floatval($order['discount_amount'] ?? 0);
            $currentPaid = floatval($order['paid_amount']);
            $payable = $totalAmount - $existingDiscount;
            $due = $payable - $currentPaid;

            if ($due <= 0.005) {
                $failMessages[] = "订单 {$order['order_no']} 无需收款";
                continue;
            }
            if ($remaining <= 0.005) {
                $failMessages[] = "订单 {$order['order_no']} 未分配到金额";
                continue;
            }

            // 本单可吃优惠 = min(优惠池剩余, 本单待收)
            // 多出的优惠不能留给下一单
            $giveDiscount = min($remainingDiscount, $due);
            $newDiscount = $existingDiscount + $giveDiscount;
            $newPayable = $totalAmount - $newDiscount;
            $newDue = $newPayable - $currentPaid;

            if ($newDue <= 0.005) {
                // 优惠后已付清（不需收款）
                $newPaid = $currentPaid;
                $newStatus = 2;
                $give = 0;
            } else {
                $give = min($remaining, $newDue);
                $newPaid = $currentPaid + $give;
                $newStatus = ($newPaid + 0.005 >= $newPayable) ? 2 : 1;
            }

            $db->prepare("UPDATE orders SET paid_amount = ?, discount_amount = ?, payment_status = ? WHERE id = ?")
               ->execute([$newPaid, $newDiscount, $newStatus, $oid]);

            $db->prepare("INSERT INTO payments (order_id, amount, payment_method, remark, discount, created_at) VALUES (?, ?, ?, ?, ?, NOW())")
               ->execute([$oid, $give, $validMethods[$method], $note, $giveDiscount]);

            $allocations[$order['order_no']] = [
                'collected' => round($give, 2),
                'discount'  => round($giveDiscount, 2),
                'status'    => $newStatus === 2 ? '已付清' : '部分收款',
            ];
            $remaining -= $give;
            $remainingDiscount -= $giveDiscount;
            $successCount++;
        }

        // 池子还有剩余：可能金额超过所有订单总和
        if ($remaining > 0.01) {
            $failMessages[] = '剩余 ¥' . number_format($remaining, 2) . ' 未分配（已超出所选订单待收总额）';
        }

        $db->commit();

        $msg = "成功收款 {$successCount} 个订单";
        if (!empty($failMessages)) {
            $msg .= "，" . count($failMessages) . " 个跳过（" . implode('、', array_slice($failMessages, 0, 3));
            if (count($failMessages) > 3) $msg .= " 等";
            $msg .= "）";
        }

        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode([
            'ok' => true,
            'msg' => $msg,
            'allocations' => $allocations,
            'skipped' => $failMessages,
        ]);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("Batch payment failed: " . $e->getMessage());
        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode(['ok' => false, 'msg' => '收款失败：' . $e->getMessage()]);
    }
    exit;
}


// 更新订单状态（POST + CSRF验证）
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_status') {
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode(['ok' => false, 'msg' => '非法请求']);
        exit;
    }
    $id = intval($_POST['id'] ?? 0);
    $status = intval($_POST['status'] ?? 0);
    if ($id > 0 && in_array($status, [0,1,2,3,4])) {
        $db->prepare("UPDATE orders SET status = ? WHERE id = ?")->execute([$status, $id]);
        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode(['ok' => true, 'msg' => '状态已更新']);
    } else {
        echo json_encode(['ok' => false, 'msg' => '参数错误']);
    }
    exit;
}

// 切换开票状态（POST + CSRF验证）
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'toggle_invoice') {
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode(['ok' => false, 'msg' => '非法请求']);
        exit;
    }
    $id = intval($_POST['id'] ?? 0);
    $st = intval($_POST['status'] ?? 0);
    if ($id > 0 && in_array($st, [0,1])) {
        $db->prepare("UPDATE orders SET invoice_status = ? WHERE id = ?")->execute([$st, $id]);
        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode(['ok' => true, 'msg' => $st ? '已标记开票' : '已取消开票']);
    } else {
        echo json_encode(['ok' => false, 'msg' => '参数错误']);
    }
    exit;
}

// 获取订单列表
$sql = "SELECT o.*, c.name as customer_name
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    $whereSql
    ORDER BY o.created_at DESC
    LIMIT $perPage OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>订单列表 - <?php echo htmlspecialchars(SITE_TITLE); ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php echo renderNav('orders'); ?>

    <div class="page-title-bar">
        <div class="page-title-left">
            <h1>订单列表</h1>
            <p>管理所有订单，查看详情、修改状态、删除订单</p>
        </div>
        <div class="page-title-actions">
            <a href="order_create.php" class="btn btn-primary btn-lg">➕ 新建订单</a>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <!-- 筛选栏 -->
            <form class="filter-panel" method="GET">
                <div class="filter-main">
                    <div class="filter-search">
                        <span class="filter-search-icon">🔍</span>
                        <input type="text" name="keyword" placeholder="搜索订单号、客户名..." value="<?php echo htmlspecialchars($_GET['keyword'] ?? ''); ?>">
                    </div>
                    <div class="filter-group">
                        <select name="status" class="filter-select">
                            <option value="">全部状态</option>
                            <option value="0" <?php echo ($_GET['status'] ?? '') === '0' ? 'selected' : ''; ?>>待确认</option>
                            <option value="1" <?php echo ($_GET['status'] ?? '') === '1' ? 'selected' : ''; ?>>已确认</option>
                            <option value="2" <?php echo ($_GET['status'] ?? '') === '2' ? 'selected' : ''; ?>>生产中</option>
                            <option value="3" <?php echo ($_GET['status'] ?? '') === '3' ? 'selected' : ''; ?>>已完成</option>
                            <option value="4" <?php echo ($_GET['status'] ?? '') === '4' ? 'selected' : ''; ?>>已取消</option>
                        </select>
                        <button type="submit" class="btn btn-primary filter-btn">筛选</button>
                        <?php if (!empty($_GET['keyword']) || !empty($_GET['status']) || !empty($_GET['date_start']) || !empty($_GET['date_end']) || !empty($_GET['amount_min']) || !empty($_GET['amount_max']) || !empty($_GET['customer_id']) || isset($_GET['payment_status'])): ?>
                        <a href="orders.php" class="btn btn-ghost filter-btn">重置</a>
                        <?php endif; ?>
                        <button type="button" class="btn btn-ghost filter-btn filter-adv-toggle" id="toggleAdvBtn" onclick="toggleAdvancedFilters()">
                            <span class="adv-icon">⚙️</span> 高级筛选
                        </button>
                    </div>
                </div>
            </form>

            <!-- 高级筛选面板 -->
            <form class="filter-advanced" id="advancedFilterBar" method="GET" style="display:none;">
                <div class="filter-advanced-inner">
                    <!-- 第一行：日期 + 金额 -->
                    <div class="filter-adv-row">
                        <div class="filter-adv-item filter-adv-item-half">
                            <label class="filter-adv-label">📅 日期范围</label>
                            <div class="filter-adv-inputs">
                                <input type="date" name="date_start" class="filter-date" value="<?php echo htmlspecialchars($_GET['date_start'] ?? ''); ?>">
                                <span class="filter-adv-sep">至</span>
                                <input type="date" name="date_end" class="filter-date" value="<?php echo htmlspecialchars($_GET['date_end'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="filter-adv-item filter-adv-item-half">
                            <label class="filter-adv-label">💰 金额范围</label>
                            <div class="filter-adv-inputs">
                                <input type="number" name="amount_min" class="filter-number" placeholder="最小金额" step="0.01" value="<?php echo htmlspecialchars($_GET['amount_min'] ?? ''); ?>">
                                <span class="filter-adv-sep">至</span>
                                <input type="number" name="amount_max" class="filter-number" placeholder="最大金额" step="0.01" value="<?php echo htmlspecialchars($_GET['amount_max'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                    <!-- 第二行：客户 + 收款状态 -->
                    <div class="filter-adv-row">
                        <div class="filter-adv-item filter-adv-item-half">
                            <label class="filter-adv-label">👤 客户</label>
                            <select name="customer_id" class="filter-select filter-select-wide">
                                <option value="">全部客户</option>
                                <?php foreach ($customerOptions as $c): ?>
                                <option value="<?php echo $c['id']; ?>" <?php echo intval($_GET['customer_id'] ?? 0) === intval($c['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-adv-item filter-adv-item-half">
                            <label class="filter-adv-label">💳 收款状态</label>
                            <select name="payment_status" class="filter-select">
                                <option value="">全部</option>
                                <option value="0" <?php echo intval($_GET['payment_status'] ?? -1) === 0 ? 'selected' : ''; ?>>未付款</option>
                                <option value="1" <?php echo intval($_GET['payment_status'] ?? -1) === 1 ? 'selected' : ''; ?>>部分收款</option>
                                <option value="2" <?php echo intval($_GET['payment_status'] ?? -1) === 2 ? 'selected' : ''; ?>>已付清</option>
                            </select>
                        </div>
                        <div class="filter-adv-item filter-adv-item-half">
                            <label class="filter-adv-label">🧾 开票状态</label>
                            <select name="invoice_status" class="filter-select">
                                <option value="">全部</option>
                                <option value="0" <?php echo intval($_GET['invoice_status'] ?? -1) === 0 ? 'selected' : ''; ?>>未开票</option>
                                <option value="1" <?php echo intval($_GET['invoice_status'] ?? -1) === 1 ? 'selected' : ''; ?>>已开票</option>
                            </select>
                        </div>
                    </div>
                    <div class="filter-adv-actions">
                        <button type="submit" class="btn btn-primary">✓ 应用筛选</button>
                        <a href="orders.php" class="btn btn-ghost">↺ 重置全部</a>
                        <button type="button" class="btn btn-ghost" onclick="toggleAdvancedFilters()">✕ 收起</button>
                    </div>
                </div>
            </form>

            <!-- 批量操作栏 -->
            <div class="batch-actions-bar" id="batchActionsBar" style="display:none;">
                <div class="batch-actions-left">
                    <span class="batch-actions-text">已选择 <strong id="batchSelectedCount">0</strong> 个订单</span>
                </div>
                <div class="batch-actions-right">
                    <button type="button" class="btn btn-secondary" id="batchPrintBtn" onclick="doBatchPrint()">
                        🖨️ 批量打印
                    </button>
                    <button type="button" class="btn btn-success" id="batchPaymentBtn" onclick="openBatchPaymentModal()">
                        💰 批量收款
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th class="th-checkbox"><input type="checkbox" id="selectAll" onchange="toggleAll(this)"></th>
                            <th>订单号</th>
                            <th>客户</th>
                            <th>应付金额</th>
                            <th>收款状态</th>
                            <th>订单状态</th>
                            <th>开单时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="8" class="empty-state">
                                <div class="empty-icon">📋</div>
                                <div>暂无订单</div>
                                <a href="order_create.php" class="btn btn-primary" style="margin-top:12px">创建第一个订单</a>
                            </td>
                        </tr>
                        <?php else: foreach ($orders as $o): ?>
                        <tr data-id="<?php echo $o['id']; ?>" data-total="<?php echo floatval($o['total_amount']); ?>" data-paid="<?php echo floatval($o['paid_amount']); ?>" data-payment-status="<?php echo intval($o['payment_status']); ?>">
                            <td class="td-checkbox"><input type="checkbox" class="order-check" value="<?php echo $o['id']; ?>" onchange="updateBatchBtn()"></td>
                            <td><a href="order_view.php?id=<?php echo $o['id']; ?>" class="link-primary"><?php echo htmlspecialchars($o['order_no']); ?></a></td>
                            <td><?php echo htmlspecialchars($o['customer_name']); ?></td>
                            <td class="money">
                                <?php
                                $disc = floatval($o['discount_amount'] ?? 0);
                                $payable = floatval($o['total_amount']) - $disc;
                                if ($disc > 0) {
                                    echo '<span style="color:#F97316;text-decoration:line-through;font-size:12px;display:block;">¥' . number_format($o['total_amount'],2) . '</span>';
                                    echo '<b style="color:#3B82F6;">¥' . number_format($payable, 2) . '</b>';
                                } else {
                                    echo '¥' . number_format($o['total_amount'], 2);
                                }
                                ?>
                            </td>
                            <td>
                                <?php
                                $ps = intval($o['payment_status']);
                                $pclass = getPaymentStatusClass($ps);
                                $ptext = getPaymentStatusText($ps);
                                ?>
                                <span class="badge badge-<?php echo $pclass; ?>"><?php echo $ptext; ?></span>
                                <?php if ($ps == 1): ?>
                                <span class="paid-hint">已收 <?php echo formatMoney($o['paid_amount']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $s = intval($o['status']);
                                $sclass = getStatusClass($s);
                                $stext = getStatusText($s);
                                ?>
                                <select class="status-select status-<?php echo $sclass; ?>"
                                    onchange="updateStatus(<?php echo $o['id']; ?>, this.value)"
                                    data-csrf="<?php echo $csrfToken; ?>">
                                    <option value="0" <?php echo $s === 0 ? 'selected' : ''; ?>>待确认</option>
                                    <option value="1" <?php echo $s === 1 ? 'selected' : ''; ?>>已确认</option>
                                    <option value="2" <?php echo $s === 2 ? 'selected' : ''; ?>>生产中</option>
                                    <option value="3" <?php echo $s === 3 ? 'selected' : ''; ?>>已完成</option>
                                    <option value="4" <?php echo $s === 4 ? 'selected' : ''; ?>>已取消</option>
                                </select>
                                <button type="button"
                                    class="invoice-btn <?php echo !empty($o['invoice_status']) ? 'invoice-on' : 'invoice-off'; ?>"
                                    onclick="toggleInvoice(<?php echo $o['id']; ?>, this)"
                                    data-csrf="<?php echo $csrfToken; ?>"
                                    title="点击切换开票状态">
                                    🧾 <?php echo !empty($o['invoice_status']) ? '已开票' : '未开票'; ?>
                                </button>
                            </td>
                            <td><?php echo date('Y-m-d H:i', strtotime($o['created_at'])); ?></td>
                            <td>
                                <div class="action-group">
                                    <a href="order_view.php?id=<?php echo $o['id']; ?>" class="btn btn-sm btn-secondary">详情</a>
                                    <?php if (in_array($o['status'], [0, 1])): ?>
                                    <a href="order_edit.php?id=<?php echo $o['id']; ?>" class="btn btn-sm btn-primary">修改</a>
                                    <?php endif; ?>
                                    <a href="order_view.php?id=<?php echo $o['id']; ?>&print=1" target="_blank" class="btn btn-sm btn-ghost">🖨️ 打印</a>
                                    <?php if (in_array(intval($o['status']), [0, 4])): ?>
                                    <button type="button" class="btn btn-sm btn-danger" onclick="deleteOrder(<?php echo $o['id']; ?>, '<?php echo htmlspecialchars($o['order_no']); ?>')">删除</button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="pagination-btn pagination-prev">← 上一页</a>
                <?php endif; ?>

                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                if ($start > 1) {
                    echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => 1])) . '" class="pagination-btn">1</a>';
                    if ($start > 2) echo '<span class="pagination-ellipsis">···</span>';
                }
                for ($i = $start; $i <= $end; $i++): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"
                    class="pagination-btn <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor;
                if ($end < $totalPages) {
                    if ($end < $totalPages - 1) echo '<span class="pagination-ellipsis">···</span>';
                    echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => $totalPages])) . '" class="pagination-btn">' . $totalPages . '</a>';
                }
                ?>

                <?php if ($page < $totalPages): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="pagination-btn pagination-next">下一页 →</a>
                <?php endif; ?>

                <span class="page-info">共 <strong><?php echo $total; ?></strong> 条，<strong><?php echo $page; ?></strong> / <?php echo $totalPages; ?> 页</span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 删除确认弹窗 -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>⚠️ 确认删除</h3>
            </div>
            <div class="modal-body">
                <p>确定要删除订单 <strong id="delOrderNo"></strong> 吗？</p>
                <p class="text-muted">此操作不可恢复，订单下的所有明细也将被删除。</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">取消</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">确认删除</button>
            </div>
        </div>
    </div>

    <input type="hidden" id="pageCsrf" value="<?php echo $csrfToken; ?>">
    <input type="hidden" id="batchIds">

    <!-- 批量收款弹窗 -->
    <div class="modal" id="batchPaymentModal">
        <div class="modal-content" style="max-width: 520px;">
            <div class="modal-header">
                <h3>💰 批量收款</h3>
                <button type="button" class="modal-close" onclick="closeBatchPaymentModal()">×</button>
            </div>
            <div class="modal-body">
                <div class="batch-payment-info">
                    <div class="batch-payment-stat">
                        <span class="batch-payment-label">已选订单</span>
                        <span class="batch-payment-value" id="batchCount">0</span>
                    </div>
                    <div class="batch-payment-stat">
                        <span class="batch-payment-label">订单总金额</span>
                        <span class="batch-payment-value" id="batchTotalAmount">¥0.00</span>
                    </div>
                    <div class="batch-payment-stat">
                        <span class="batch-payment-label">已收金额</span>
                        <span class="batch-payment-value" id="batchPaidAmount">¥0.00</span>
                    </div>
                    <div class="batch-payment-stat batch-payment-stat-highlight">
                        <span class="batch-payment-label">待收金额</span>
                        <span class="batch-payment-value" id="batchDueAmount">¥0.00</span>
                    </div>
                </div>
                <div class="batch-payment-form">
                    <div class="form-group">
                        <label class="form-label">本次收款金额 <span class="text-danger">*</span></label>
                        <div class="input-prefix">
                            <span class="input-prefix-text">¥</span>
                            <input type="number" id="batchPaymentAmount" class="form-input" step="0.01" min="0.01" placeholder="输入收款金额" oninput="onAmountChange()">
                        </div>
                        <div class="form-hint">应付合计：<span id="suggestAmount" class="text-primary">¥0.00</span></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">本次优惠(可不填)</label>
                        <div class="input-prefix">
                            <span class="input-prefix-text">-¥</span>
                            <input type="number" id="batchPaymentDiscount" class="form-input" step="0.01" min="0" value="0" placeholder="批量减免尾数(可选)" oninput="onDiscountChange.call(this)">
                        </div>
                        <div class="form-hint">例如:减免 5.60 元尾数，收款金额会自动调为“应付 - 优惠”</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">收款方式 <span class="text-danger">*</span></label>
                        <select id="batchPaymentMethod" class="form-select">
                            <option value="">请选择</option>
                            <option value="cash">现金</option>
                            <option value="wechat">微信</option>
                            <option value="alipay">支付宝</option>
                            <option value="bank">银行转账</option>
                            <option value="other">其他</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">收款备注</label>
                        <textarea id="batchPaymentNote" class="form-textarea" rows="2" placeholder="选填，如：6月货款"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeBatchPaymentModal()">取消</button>
                <button type="button" class="btn btn-success" id="confirmBatchPaymentBtn" onclick="doBatchPayment()">确认收款</button>
            </div>
        </div>
    </div>

    <script>
    // 更新订单状态
    function updateStatus(id, status) {
        const csrf = document.getElementById('pageCsrf').value;
        fetch('orders.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({action: 'update_status', id, status, csrf_token: csrf})
        })
        .then(r => r.json())
        .then(res => {
            if (res.ok) {
                showToast('✅ ' + res.msg);
                // 更新当前select的样式
                setTimeout(() => location.reload(), 800);
            } else {
                showToast('❌ ' + res.msg, 'error');
            }
        });
    }

    // 切换开票状态
    function toggleInvoice(id, btn) {
        const csrf = btn.getAttribute('data-csrf') || document.getElementById('pageCsrf').value;
        const isOn = btn.classList.contains('invoice-on');
        const newStatus = isOn ? 0 : 1;
        // 乐观更新
        btn.disabled = true;
        fetch('orders.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({action: 'toggle_invoice', id, status: newStatus, csrf_token: csrf})
        })
        .then(r => r.json())
        .then(res => {
            if (res.ok) {
                showToast('✅ ' + res.msg);
                if (newStatus) {
                    btn.classList.remove('invoice-off');
                    btn.classList.add('invoice-on');
                    btn.innerHTML = '🧾 已开票';
                } else {
                    btn.classList.remove('invoice-on');
                    btn.classList.add('invoice-off');
                    btn.innerHTML = '🧾 未开票';
                }
            } else {
                showToast('❌ ' + (res.msg || '操作失败'), 'error');
            }
            btn.disabled = false;
        })
        .catch(err => {
            showToast('❌ 网络错误', 'error');
            btn.disabled = false;
        });
    }

    // 删除订单
    function deleteOrder(id, orderNo) {
        document.getElementById('delOrderNo').textContent = orderNo;
        document.getElementById('confirmDeleteBtn').onclick = () => doDelete(id);
        document.getElementById('deleteModal').classList.add('active');
    }

    function closeDeleteModal() {
        document.getElementById('deleteModal').classList.remove('active');
    }

    function doDelete(id) {
        // 【2026-09-09 修复】doDelete 容错: redirect:manual + r.text() + .catch() + 始终关闭弹窗
        const csrf = document.getElementById('pageCsrf').value;
        const btn = document.getElementById('confirmDeleteBtn');
        if (btn) { btn.disabled = true; btn.textContent = '删除中...'; }
        fetch('orders.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({action: 'delete_order', id, csrf_token: csrf}),
            redirect: 'manual',
            credentials: 'same-origin'
        })
        .then(function(r) {
            if (r.type === 'opaqueredirect' || r.status === 302 || r.status === 301) {
                throw new Error('登录已过期(session 失效), 请刷新页面重新登录后重试');
            }
            return r.text();
        })
        .then(function(rawText) {
            let res;
            try { res = JSON.parse(rawText); }
            catch (parseErr) {
                throw new Error('服务器返回非 JSON: ' + rawText.replace(/\s+/g, ' ').substring(0, 200));
            }
            closeDeleteModal();
            if (res.ok) {
                showToast('订单已删除');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                showToast((res.msg || '删除失败'), 'error');
                if (btn) { btn.disabled = false; btn.textContent = '确认删除'; }
            }
        })
        .catch(function(err) {
            closeDeleteModal();
            showToast('删除失败: ' + err.message, 'error');
            if (btn) { btn.disabled = false; btn.textContent = '确认删除'; }
        });
    }

    // Toast提示
    function showToast(msg, type) {
        const t = document.createElement('div');
        t.className = 'toast toast-' + (type || 'success');
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(() => t.classList.add('show'), 10);
        setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 300); }, 2500);
    }

    // 批量选择
    function toggleAll(el) {
        document.querySelectorAll('.order-check').forEach(cb => { cb.checked = el.checked; });
        updateBatchBtn();
    }

    // 存储选中订单的完整数据
    let selectedOrdersData = [];

    function updateBatchBtn() {
        const checked = document.querySelectorAll('.order-check:checked');
        const bar = document.getElementById('batchActionsBar');
        const countEl = document.getElementById('batchSelectedCount');
        const idsInput = document.getElementById('batchIds');

        if (checked.length > 0) {
            bar.style.display = 'flex';
            countEl.textContent = checked.length;

            // 收集选中订单的数据
            selectedOrdersData = Array.from(checked).map(cb => {
                const row = cb.closest('tr');
                return {
                    id: cb.value,
                    total: parseFloat(row.dataset.total) || 0,
                    paid: parseFloat(row.dataset.paid) || 0,
                    paymentStatus: parseInt(row.dataset.paymentStatus) || 0
                };
            });

            idsInput.value = selectedOrdersData.map(o => o.id).join(',');
        } else {
            bar.style.display = 'none';
            selectedOrdersData = [];
            idsInput.value = '';
        }
    }

    // 批量收款弹窗
    // 默认应付金额
let batchDefaultDue = 0;
let userTouchedAmount = false;  // 用户是否手动改过收款金额

function openBatchPaymentModal() {
        if (selectedOrdersData.length === 0) {
            showToast('请先选择要收款的订单', 'error');
            return;
        }

        const totalAmount = selectedOrdersData.reduce((sum, o) => sum + o.total, 0);
        const paidAmount = selectedOrdersData.reduce((sum, o) => sum + o.paid, 0);
        const dueAmount = totalAmount - paidAmount;

        document.getElementById('batchCount').textContent = selectedOrdersData.length + ' 个';
        document.getElementById('batchTotalAmount').textContent = '¥' + totalAmount.toFixed(2);
        document.getElementById('batchPaidAmount').textContent = '¥' + paidAmount.toFixed(2);
        document.getElementById('batchDueAmount').textContent = '¥' + dueAmount.toFixed(2);
        document.getElementById('suggestAmount').textContent = '¥' + dueAmount.toFixed(2);

        batchDefaultDue = dueAmount;
        userTouchedAmount = false;
        document.getElementById('batchPaymentAmount').value = dueAmount > 0 ? dueAmount.toFixed(2) : '';
        document.getElementById('batchPaymentDiscount').value = 0;
        document.getElementById('batchPaymentMethod').value = '';
        document.getElementById('batchPaymentNote').value = '';

        document.getElementById('batchPaymentModal').classList.add('active');
    }

    // 收款金额变动 → 标记用户已手动改（优惠变化时不再覆盖）
    function onAmountChange() {
        userTouchedAmount = true;
    }

    // 优惠变动 → 收款金额自动调整：收款金额 = 应付合计 - 优惠
    // 语义：优惠减免应付，客户只需付剩下的部分
    function onDiscountChange() {
        if (!userTouchedAmount) {
            const discount = parseFloat(this.value) || 0;
            const newAmount = Math.max(0, batchDefaultDue - discount);
            document.getElementById('batchPaymentAmount').value = newAmount.toFixed(2);
        }
    }

    function closeBatchPaymentModal() {
        document.getElementById('batchPaymentModal').classList.remove('active');
    }

    function doBatchPayment() {
        const amount = parseFloat(document.getElementById('batchPaymentAmount').value);
        const discount = parseFloat(document.getElementById('batchPaymentDiscount').value) || 0;
        const method = document.getElementById('batchPaymentMethod').value;
        const note = document.getElementById('batchPaymentNote').value.trim();
        const ids = document.getElementById('batchIds').value;

        if (!amount || amount <= 0) {
            showToast('请输入有效的收款金额', 'error');
            return;
        }
        if (discount < 0) {
            showToast('优惠金额不能为负数', 'error');
            return;
        }
        if (!method) {
            showToast('请选择收款方式', 'error');
            return;
        }

        const csrf = document.getElementById('pageCsrf').value;
        const btn = document.getElementById('confirmBatchPaymentBtn');
        btn.disabled = true;
        btn.textContent = '处理中...';

        fetch('orders.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                action: 'batch_payment',
                ids: ids,
                amount: amount,        // 用户填的收款金额（联动后已是应收合计）
                method: method,
                note: note,
                discount: discount,    // 应付减免
                csrf_token: csrf
            })
        })
        .then(r => r.json())
        .then(res => {
            btn.disabled = false;
            btn.textContent = '确认收款';
            closeBatchPaymentModal();
            if (res.ok) {
                // 展示每单分配明细
                let detail = res.msg;
                if (res.allocations) {
                    const lines = Object.entries(res.allocations)
                        .map(([no, d]) => `${no}: 收款 ¥${d.collected} ${d.discount > 0 ? '(优惠 ¥' + d.discount + ')' : ''} → ${d.status}`)
                        .join('\n');
                    if (lines) detail = lines;
                }
                showToast('✅ ' + detail);
                setTimeout(() => location.reload(), 1200);
            } else {
                showToast('❌ ' + res.msg, 'error');
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.textContent = '确认收款';
            showToast('❌ 请求失败，请重试', 'error');
        });
    }

    // 高级筛选展开/收起
    function toggleAdvancedFilters() {
        const bar = document.getElementById('advancedFilterBar');
        const btn = document.getElementById('toggleAdvBtn');
        if (bar.style.display === 'none') {
            bar.style.display = 'flex';
            btn.textContent = '⚙️ 收起筛选';
        } else {
            bar.style.display = 'none';
            btn.textContent = '⚙️ 高级筛选';
        }
    }

    // 高级筛选有值时自动展开
    (function() {
        const params = new URLSearchParams(window.location.search);
        if (params.get('date_start') || params.get('date_end') ||
            params.get('amount_min') || params.get('amount_max') ||
            params.get('customer_id') || params.get('payment_status') !== null) {
            document.getElementById('advancedFilterBar').style.display = 'flex';
            document.getElementById('toggleAdvBtn').textContent = '⚙️ 收起筛选';
        }
    })();

    function doBatchPrint() {
        const ids = document.getElementById('batchIds').value;
        if (!ids) { showToast('请先选择要打印的订单', 'error'); return; }
        window.open('batch_print.php?ids=' + encodeURIComponent(ids), '_blank', 'width=800,height=600');
    }
    </script>

    <style>
    .text-muted { color: #64748B; font-size: 13px; }
    .paid-hint { display: block; font-size: 11px; color: #10B981; margin-top: 2px; }
    .toast {
        position: fixed; top: 24px; right: 24px; z-index: 9999;
        padding: 12px 20px; border-radius: 10px;
        background: #1E293B; color: #fff; font-size: 14px;
        box-shadow: 0 4px 16px rgba(0,0,0,0.2);
        opacity: 0; transform: translateY(-10px);
        transition: all 0.3s ease;
    }
    .toast.show { opacity: 1; transform: translateY(0); }
    .toast-error { background: #EF4444; }
    .th-checkbox, .td-checkbox { width: 36px; text-align: center; }
    .th-checkbox input, .td-checkbox input { width: 16px; height: 16px; cursor: pointer; }
    /* 序号列 */
    .td-checkbox { vertical-align: middle; }
    /* 订单号列 */
    .data-table td:nth-child(2) { font-family: 'Courier New', monospace; font-size: 13px; color: #64748b; letter-spacing: 0.3px; }
    /* 客户列 */
    .data-table td:nth-child(3) { font-weight: 600; color: #334155; max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    /* 金额列 */
    .data-table td:nth-child(4) { font-weight: 700; font-size: 15px; color: #1e293b; }
    /* 时间列 */
    .data-table td:nth-child(7) { color: #94a3b8; font-size: 13px; white-space: nowrap; }
    /* 操作按钮组 */
    .action-group { display: flex; gap: 4px; flex-wrap: nowrap; align-items: center; }
    .action-group .btn { font-size: 12px; padding: 4px 10px; border-radius: 6px; }
    /* 状态select */
    .status-select { padding: 4px 8px; border-radius: 20px; border: 1.5px solid; font-size: 12px; font-weight: 600; cursor: pointer; }
    .status-select.status-success { border-color: #10b981; color: #10b981; background: #ecfdf5; }
    .status-select.status-warning { border-color: #f59e0b; color: #f59e0b; background: #fffbeb; }
    .status-select.status-danger { border-color: #ef4444; color: #ef4444; background: #fef2f2; }
    .status-select.status-info { border-color: #6366f1; color: #6366f1; background: #eef2ff; }
    .status-select.status-default { border-color: #94a3b8; color: #64748b; background: #f8fafc; }
    /* 开票按钮 */
    .invoice-btn { padding: 4px 10px; border-radius: 20px; border: 1.5px solid; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s; margin-left: 6px; background: white; }
    .invoice-btn.invoice-on { border-color: #10b981; color: #10b981; background: #ecfdf5; }
    .invoice-btn.invoice-off { border-color: #94a3b8; color: #64748b; background: #f8fafc; }
    .invoice-btn:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 2px 6px rgba(0,0,0,0.1); }
    .invoice-btn:disabled { opacity: 0.5; cursor: wait; }
    /* 行hover增强 */
    .data-table tbody tr:hover { background: linear-gradient(90deg, #f0f6ff 0%, #faf5ff 100%); }
    .data-table tbody tr:hover td { border-color: #e0eaff; }
    </style>

    <?php echo renderFooter(); ?>
</body>
</html>
