<?php
require_once 'config.php';
initDatabase();
checkLogin();
$user = getCurrentUser();

$canDeleteOrder = hasPermission(PERM_ORDER_DELETE); // 【2026-09-15】删除按钮仅对有权限者显示
$status = $_GET['status'] ?? 'all';
$keyword = trim($_GET['keyword'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 15;

$db = getDB();

// 删除订单【2026-09-15 安全修复】原实现为 GET 无权限校验无 CSRF，任何登录账号可删任意订单；
// 现对齐 PC 端：POST + CSRF + PERM_ORDER_DELETE 权限 + 状态限制 + 事务删除 + 物理文件清理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_order') {
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        echo json_encode(['ok' => false, 'msg' => '非法请求']);
        exit;
    }
    if (!hasPermission(PERM_ORDER_DELETE)) {
        echo json_encode(['ok' => false, 'msg' => '权限不足，无法删除订单']);
        exit;
    }
    $deleteId = intval($_POST['id'] ?? 0);
    if ($deleteId <= 0) {
        echo json_encode(['ok' => false, 'msg' => '参数错误']);
        exit;
    }
    try {
        // 1. 查订单当前状态
        $stmt = $db->prepare("SELECT status, order_no FROM orders WHERE id = ?");
        $stmt->execute([$deleteId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            echo json_encode(['ok' => false, 'msg' => '订单不存在']);
            exit;
        }
        // 2. 状态限制：只允许待确认(0) 和 已取消(4) 删除（与 PC 端一致）
        if (!in_array(intval($order['status']), [0, 4])) {
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

        // 4. 事务删除（订单+明细+收款记录；order_attachments 由外键级联删除）
        $db->beginTransaction();
        $db->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$deleteId]);
        $db->prepare("DELETE FROM payments WHERE order_id = ?")->execute([$deleteId]);
        $db->prepare("DELETE FROM orders WHERE id = ?")->execute([$deleteId]);
        $db->commit();

        // 5. 删除物理文件（仅限 uploads/ 下订单子目录，防路径穿越）
        // mobile/ 的上一级即站点根（与 PC 端 dirname(__FILE__) 等价）
        $erpRoot = realpath(dirname(__DIR__));
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
        foreach ($itemImages as $img) {
            if ($isSafe($img)) {
                $fullPath = $erpRoot . DIRECTORY_SEPARATOR . $img;
                if (file_exists($fullPath)) @unlink($fullPath);
            }
        }
        foreach ($paymentFiles as $file) {
            if ($isSafe($file)) {
                $fullPath = $erpRoot . DIRECTORY_SEPARATOR . $file;
                if (file_exists($fullPath)) @unlink($fullPath);
            }
        }

        echo json_encode(['ok' => true, 'msg' => '订单 ' . $order['order_no'] . ' 已删除']);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("[mobile delete_order #$deleteId] failed: " . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => '删除失败：' . $e->getMessage()]);
    }
    exit;
}

// 批量收款(POST + CSRF 验证,复用 PC 端池子法 + 行锁)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'batch_payment') {
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        echo json_encode(['ok' => false, 'msg' => '非法请求']);
        exit;
    }

    $idsInput = $_POST['ids'] ?? '';
    $amount   = floatval($_POST['amount'] ?? 0);
    $method   = trim($_POST['method'] ?? '');
    $note     = trim($_POST['note'] ?? '');

    $ids = array_filter(array_map('intval', explode(',', $idsInput)));
    if (empty($ids) || $amount <= 0 || $method === '') {
        echo json_encode(['ok' => false, 'msg' => '参数错误']);
        exit;
    }

    $validMethods = ['现金' => '现金', '微信' => '微信', '支付宝' => '支付宝', '银行转账' => '银行转账', '其他' => '其他'];
    if (!isset($validMethods[$method])) {
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
            `created_by` INT COMMENT '操作人',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='收款记录表'");

        $db->beginTransaction();

        // 一次性锁行取出所有订单(FOR UPDATE 防并发)
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT id, order_no, total_amount, paid_amount, COALESCE(discount_amount, 0) AS discount_amount, payment_status
                              FROM orders WHERE id IN ($ph) FOR UPDATE");
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            $db->rollBack();
            echo json_encode(['ok' => false, 'msg' => '未找到订单']);
            exit;
        }

        // 【2026-09-15】本次优惠（对齐 PC 端池子法）
        $batchDiscount = floatval($_POST['discount'] ?? 0);
        if ($batchDiscount < 0) {
            $db->rollBack();
            echo json_encode(['ok' => false, 'msg' => '优惠金额不能为负']);
            exit;
        }

        $remaining = $amount;                 // 总池子，按订单顺序递减分配
        $remainingDiscount = $batchDiscount;  // 优惠池子同理
        $successCount = 0;

        // 智能分配（对齐 PC 端）：
        // 1) 有优惠时按 total_amount 降序，大单优先吃优惠（吃到即付清）
        // 2) 无优惠时按用户勾选顺序
        if ($batchDiscount > 0) {
            usort($rows, function ($a, $b) {
                return floatval($b['total_amount']) <=> floatval($a['total_amount']);
            });
        } else {
            $orderIndex = [];
            foreach ($rows as $o) $orderIndex[intval($o['id'])] = $o;
            $rows = [];
            foreach ($ids as $id) {
                if (isset($orderIndex[$id])) $rows[] = $orderIndex[$id];
            }
        }

        foreach ($rows as $o) {
            $oid = intval($o['id']);

            if (intval($o['payment_status']) == 2) {
                continue; // 已付清跳过
            }

            $totalAmount = floatval($o['total_amount']);
            $existingDiscount = floatval($o['discount_amount'] ?? 0);
            $currentPaid = floatval($o['paid_amount']);
            $payable = $totalAmount - $existingDiscount;
            $due = $payable - $currentPaid;

            // 已收满或池子已用完则跳过
            if ($due <= 0.005 || $remaining <= 0.005) {
                continue;
            }

            // 本单可吃优惠 = min(优惠池剩余, 本单待收)
            $giveDiscount = min($remainingDiscount, $due);
            $newDiscount = $existingDiscount + $giveDiscount;
            $newPayable = $totalAmount - $newDiscount;
            $newDue = $newPayable - $currentPaid;

            if ($newDue <= 0.005) {
                // 优惠后已付清（不需收款）
                $newPaid = $currentPaid;
                $give = 0;
                $newStatus = 2;
            } else {
                $give = min($remaining, $newDue);
                $newPaid = $currentPaid + $give;
                $newStatus = ($newPaid + 0.005 >= $newPayable) ? 2 : 1;
            }

            // UPDATE 与 INSERT 使用同一个 $give,保证两边一致
            $db->prepare("UPDATE orders SET paid_amount = ?, discount_amount = ?, payment_status = ? WHERE id = ?")
               ->execute([$newPaid, $newDiscount, $newStatus, $oid]);

            $db->prepare("INSERT INTO payments (order_id, amount, payment_method, remark, discount, created_by) VALUES (?, ?, ?, ?, ?, ?)")
               ->execute([$oid, $give, $validMethods[$method], $note, $giveDiscount, $user['id'] ?? null]);

            $remaining -= $give;
            $remainingDiscount -= $giveDiscount;
            $successCount++;
        }

        // 池子还有剩余:金额超过所选订单待收总额,整笔回滚
        if ($remaining > 0.01) {
            $db->rollBack();
            echo json_encode(['ok' => false, 'msg' => '超出待收总额']);
            exit;
        }

        $db->commit();
        echo json_encode(['ok' => true, 'msg' => "成功收款 {$successCount} 个订单"]);
    } catch (Exception $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        error_log("Batch payment failed: " . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => '收款失败:' . $e->getMessage()]);
    }
    exit;
}

$orders = getMobileOrders($status, $keyword, $page, $perPage);
$total = getMobileOrderCount($status, $keyword);
$totalPages = ceil($total / $perPage);

$companyName = getSetting('company_name') ?: '广告公司';
$user = getCurrentUser();
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>订单列表 - <?php echo htmlspecialchars($companyName); ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .order-card-inner { display: flex; align-items: flex-start; gap: 10px; }
        .order-card-inner .order-main { flex: 1; min-width: 0; }
        .batch-check { display: none; margin-top: 2px; flex-shrink: 0; }
        .batch-check input { width: 20px; height: 20px; }
        body.batch-mode .batch-check { display: block; }
        .batch-toolbar { display: flex; align-items: center; gap: 12px; background: white; border-radius: var(--radius-sm); padding: 10px 14px; margin-bottom: 12px; box-shadow: var(--shadow); }
        .batch-toolbar .batch-summary { flex: 1; font-size: 13px; color: var(--gray-600); }
    </style>
</head>
<body>
    <!-- 顶部栏 -->
    <div class="page-header">
        <h1>📋 订单列表</h1>
        <div class="user-bar">
            <span>共 <?php echo $total; ?> 个订单</span>
            <span style="display:flex;gap:8px;align-items:center;">
                <button id="batchToggleBtn" class="btn btn-outline btn-sm" onclick="toggleBatchMode()" style="padding:5px 12px;font-size:12px;">批量收款</button>
                <a href="../logout.php">退出</a>
            </span>
        </div>
    </div>

    <div class="page">
        <!-- 搜索 -->
        <form method="GET" class="search-bar">
            <input type="text" name="keyword" value="<?php echo htmlspecialchars($keyword); ?>"
                   placeholder="搜索订单号 / 客户名" class="form-control">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
            <button type="submit" class="btn btn-primary">🔍</button>
        </form>

        <!-- 批量收款工具栏（默认隐藏，批量模式下显示） -->
        <div class="batch-toolbar" id="batchToolbar" style="display:none;">
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;">
                <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)"> 全选
            </label>
            <span class="batch-summary" id="batchSummary">已选 0 单，待收 ¥0.00</span>
            <button class="btn btn-success btn-sm" onclick="openBatchModal()">收款</button>
        </div>

        <!-- 状态筛选 -->
        <div class="status-tabs">
            <a href="?status=all&keyword=<?php echo urlencode($keyword); ?>" <?php echo $status === 'all' ? 'class="active"' : ''; ?>>全部</a>
            <a href="?status=0&keyword=<?php echo urlencode($keyword); ?>" <?php echo $status === '0' ? 'class="active"' : ''; ?>>⏳ 待确认</a>
            <a href="?status=1&keyword=<?php echo urlencode($keyword); ?>" <?php echo $status === '1' ? 'class="active"' : ''; ?>>✅ 已确认</a>
            <a href="?status=2&keyword=<?php echo urlencode($keyword); ?>" <?php echo $status === '2' ? 'class="active"' : ''; ?>>🏭 生产中</a>
            <a href="?status=3&keyword=<?php echo urlencode($keyword); ?>" <?php echo $status === '3' ? 'class="active"' : ''; ?>>✨ 已完成</a>
            <a href="?status=4&keyword=<?php echo urlencode($keyword); ?>" <?php echo $status === '4' ? 'class="active"' : ''; ?>>❌ 已取消</a>
        </div>

        <!-- 订单列表 -->
        <?php if (empty($orders)): ?>
        <div class="empty-state">
            <div class="icon">📭</div>
            <div class="text">暂无订单</div>
        </div>
        <?php else: ?>
            <?php foreach ($orders as $order): ?>
            <?php
            $paid = floatval($order['paid_amount'] ?? 0);
            $total = floatval($order['total_amount']);
            $disc = floatval($order['discount_amount'] ?? 0);
            $unpaid = max(0, $total - $disc - $paid);
            $payStatus = intval($order['payment_status'] ?? 0);
            $payBadgeClass = $payStatus == 2 ? 'success' : ($payStatus == 1 ? 'warning' : 'danger');
            $payText = $payStatus == 2 ? '已付清' : ($payStatus == 1 ? '部分收款' : '未付款');
            ?>
            <div class="order-card status-<?php echo $order['status']; ?>" style="cursor:pointer;" onclick="onOrderClick(<?php echo $order['id']; ?>)">
                <div class="order-card-inner">
                <?php if (intval($order['payment_status']) != 2): ?>
                <label class="batch-check" onclick="event.stopPropagation()">
                    <input type="checkbox" class="order-select" value="<?php echo $order['id']; ?>" data-due="<?php echo number_format($unpaid, 2, '.', ''); ?>" data-no="<?php echo h($order['order_no']); ?>" onchange="updateBatchSummary()">
                </label>
                <?php endif; ?>
                <div class="order-main">
                <div class="order-header">
                    <span class="order-no"><?php echo $order['order_no']; ?></span>
                    <span class="order-amount">¥<?php echo number_format($order['total_amount'], 2); ?></span>
                </div>
                <div class="customer"><?php echo htmlspecialchars($order['customer_name'] ?: '未知客户'); ?></div>
                <div class="order-payment-info" style="display:flex;gap:8px;margin:6px 0;font-size:12px;align-items:center;">
                    <!-- 收款变量已在循环顶部预计算 -->
                    <span style="color:#22c55e;">已收: ¥<?php echo number_format($paid, 2); ?></span>
                    <span style="color:#ef4444;">未收: ¥<?php echo number_format($unpaid, 2); ?></span>
                    <span class="badge badge-<?php echo $payBadgeClass; ?>" style="font-size:11px;padding:2px 6px;"><?php echo $payText; ?></span>
                </div>
                <div class="order-footer">
                    <span class="order-time"><?php echo date('m-d H:i', strtotime($order['created_at'])); ?></span>
                    <span style="display:flex;gap:8px;align-items:center;">
                        <span class="badge badge-<?php echo $order['status']; ?>"><?php echo getStatusText($order['status']); ?></span>
                        <?php if ($order['status'] == 0 || $order['status'] == 1): ?>
                        <!-- 待确认/已确认:显示编辑 -->
                        <a href="order_edit.php?id=<?php echo $order['id']; ?>" class="btn btn-primary btn-sm" onclick="event.stopPropagation();" style="padding:4px 10px;font-size:12px;">✏️</a>
                        <?php endif; ?>
                        <?php if ($canDeleteOrder && $order['status'] != 2 && $order['status'] != 3): ?>
                        <!-- 【2026-09-15】有删除权限且非生产中/已完成:显示删除 -->
                        <a href="javascript:void(0)" class="btn btn-danger btn-sm" onclick="event.stopPropagation();deleteOrder(<?php echo $order['id']; ?>, '<?php echo htmlspecialchars(addslashes($order['order_no'])); ?>')" style="padding:4px 10px;font-size:12px;">🗑️</a>
                        <?php endif; ?>
                    </span>
                </div>
                </div><!-- /order-main -->
                </div><!-- /order-card-inner -->
            </div>
            <?php endforeach; ?>

            <!-- 加载更多 / 分页 -->
            <?php if ($totalPages > 1): ?>
            <div class="load-more">
                <?php if ($page < $totalPages): ?>
                <a href="?page=<?php echo $page + 1; ?>&status=<?php echo urlencode($status); ?>&keyword=<?php echo urlencode($keyword); ?>">
                    加载更多 (<?php echo $page; ?>/<?php echo $totalPages; ?>)
                </a>
                <?php else: ?>
                <span style="color:var(--gray-400);">- 已加载全部 <?php echo $total; ?> 条 -</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- 批量收款弹窗 -->
    <div class="modal-overlay" id="batchModal" onclick="if(event.target===this) closeBatchModal()">
        <div class="modal-sheet">
            <h3 style="margin:0 0 16px;font-size:18px;">💰 批量收款</h3>
            <div id="batchOrderList" style="max-height:38vh;overflow:auto;"></div>
            <div class="form-group" style="margin-top:14px;">
                <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151;">本次收款总额 (¥)</label>
                <input type="number" id="batchAmount" step="0.01" min="0.01" class="form-control" oninput="batchUserTouched = true">
                <small style="color:#999;font-size:12px;">已自动填入待收总额，可修改；超出部分将自动拒绝</small>
            </div>
            <div class="form-group">
                <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151;">本次优惠（可不填）</label>
                <input type="number" id="batchDiscount" step="0.01" min="0" value="0" class="form-control" placeholder="如减免尾数，自动分摊到所选订单" oninput="onBatchDiscountInput()" style="border-color:#F97316;color:#F97316;">
                <small style="color:#F97316;font-size:12px;">填写优惠后，收款总额会自动减去优惠部分</small>
            </div>
            <div class="form-group">
                <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151;">收款方式</label>
                <select id="batchMethod" class="form-control">
                    <option value="现金">现金</option>
                    <option value="微信">微信</option>
                    <option value="支付宝">支付宝</option>
                    <option value="银行转账">银行转账</option>
                    <option value="其他">其他</option>
                </select>
            </div>
            <div class="form-group">
                <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151;">备注</label>
                <textarea id="batchNote" rows="2" class="form-control" placeholder="选填"></textarea>
            </div>
            <div style="display:flex;gap:12px;margin-top:8px;">
                <button class="btn btn-success" style="flex:1;" onclick="submitBatchPayment()">确认收款</button>
                <button class="btn btn-outline" style="flex:1;" onclick="closeBatchModal()">取消</button>
            </div>
        </div>
    </div>

    <?php echo mobileDialog(); ?>

    <?php echo mobileNav('orders'); ?>
    <script>
    // 【2026-09-15】统一弹窗：与产品/客户/单位删除交互完全一致
    function deleteOrder(id, orderNo) {
        mdlgConfirm('确定要删除订单「' + orderNo + '」吗？\n关联明细和收款记录也会一并删除，且不可恢复。', function() {
            var fd = new FormData();
            fd.append('action', 'delete_order');
            fd.append('id', id);
            fd.append('csrf_token', CSRF_TOKEN);
            fetch('order_list.php', { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(data){
                    if (data.ok) {
                        mdlgAlert(data.msg || '订单已删除', true, function(){ location.reload(); }, 800);
                    } else {
                        mdlgAlert(data.msg || '删除失败', false);
                    }
                })
                .catch(function(){ mdlgAlert('网络错误，删除失败', false); });
        }, { title: '删除订单' });
    }
    </script>
    <script>
    var CSRF_TOKEN = '<?php echo $csrfToken; ?>';
    var batchMode = false;

    function toggleBatchMode() {
        batchMode = !batchMode;
        document.body.classList.toggle('batch-mode', batchMode);
        document.getElementById('batchToolbar').style.display = batchMode ? 'flex' : 'none';
        document.getElementById('batchToggleBtn').textContent = batchMode ? '取消' : '批量收款';
        if (!batchMode) {
            document.querySelectorAll('.order-select').forEach(function(c){ c.checked = false; });
            updateBatchSummary();
        }
    }

    function onOrderClick(id) {
        if (!batchMode) { location.href = 'order_view.php?id=' + id; return; }
        var cb = document.querySelector('.order-select[value="' + id + '"]');
        if (cb) { cb.checked = !cb.checked; updateBatchSummary(); }
    }

    function toggleSelectAll(box) {
        document.querySelectorAll('.order-select').forEach(function(c){ c.checked = box.checked; });
        updateBatchSummary();
    }

    function updateBatchSummary() {
        var cbs = document.querySelectorAll('.order-select:checked');
        var count = cbs.length;
        var sum = 0;
        cbs.forEach(function(c){ sum += parseFloat(c.getAttribute('data-due')) || 0; });
        document.getElementById('batchSummary').textContent = '已选 ' + count + ' 单，待收 ¥' + sum.toFixed(2);
    }

    var batchDefaultDue = 0;   // 【2026-09-15】待收总额基准
    var batchUserTouched = false; // 用户是否手动改过收款总额

    function openBatchModal() {
        var cbs = document.querySelectorAll('.order-select:checked');
        if (cbs.length === 0) { alert('请先选择订单'); return; }
        var html = '';
        var totalDue = 0;
        cbs.forEach(function(c){
            var due = parseFloat(c.getAttribute('data-due')) || 0;
            totalDue += due;
            html += '<div class="list-item"><div class="item-content"><div class="item-title">' + c.getAttribute('data-no') +
                    '</div><div class="item-desc">待收 ¥' + due.toFixed(2) + '</div></div></div>';
        });
        document.getElementById('batchOrderList').innerHTML = html;
        batchDefaultDue = totalDue;
        batchUserTouched = false;
        document.getElementById('batchAmount').value = totalDue > 0 ? totalDue.toFixed(2) : '';
        document.getElementById('batchDiscount').value = 0;
        document.getElementById('batchModal').classList.add('show');
    }

    // 【2026-09-15】优惠变动 → 收款总额自动减去优惠（与 PC 端批量收款逻辑一致）
    function onBatchDiscountInput() {
        if (batchUserTouched) return;
        var discount = parseFloat(document.getElementById('batchDiscount').value) || 0;
        if (discount < 0) discount = 0;
        document.getElementById('batchAmount').value = Math.max(0, batchDefaultDue - discount).toFixed(2);
    }

    function closeBatchModal() {
        document.getElementById('batchModal').classList.remove('show');
    }

    function submitBatchPayment() {
        var cbs = document.querySelectorAll('.order-select:checked');
        var ids = [];
        cbs.forEach(function(c){ ids.push(c.value); });
        var amount = parseFloat(document.getElementById('batchAmount').value);
        var discount = parseFloat(document.getElementById('batchDiscount').value) || 0;
        var method = document.getElementById('batchMethod').value;
        var note = document.getElementById('batchNote').value;
        if (!(amount > 0)) { alert('请输入有效的收款金额'); return; }
        if (discount < 0) { alert('优惠金额不能为负'); return; }

        var fd = new FormData();
        fd.append('action', 'batch_payment');
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('ids', ids.join(','));
        fd.append('amount', amount);
        fd.append('discount', discount);
        fd.append('method', method);
        fd.append('note', note);

        var btn = document.querySelector('#batchModal .btn-success');
        var oldText = btn.textContent;
        btn.disabled = true;
        btn.textContent = '处理中...';

        fetch('order_list.php', { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(data){
                if (data.ok) { alert('✅ ' + data.msg); closeBatchModal(); location.reload(); }
                else { alert('❌ ' + data.msg); btn.disabled = false; btn.textContent = oldText; }
            })
            .catch(function(err){
                alert('❌ 请求失败：' + err.message);
                btn.disabled = false; btn.textContent = oldText;
            });
    }
    </script>
</body>
</html>
