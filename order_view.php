<?php
// 【2026-09-09 修复】opcache + display_errors + 防意外输出污染 JSON
if (function_exists('opcache_invalidate')) { @opcache_invalidate(__FILE__, true); }
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
require_once 'config.php';
initDatabase();
checkLogin();

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    die('无效的订单ID');
}
$db = getDB();

// 获取订单信息
$order = $db->prepare("SELECT o.*, c.name as customer_name, c.contact, c.phone, c.address
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    WHERE o.id = ?");
$order->execute([$id]);
$order = $order->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die('订单不存在');
}

// 获取订单明细
$items = $db->prepare("SELECT * FROM order_items WHERE order_id = ?");
$items->execute([$id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

// 获取收款记录
$payments = $db->prepare("SELECT p.*, u.realname FROM payments p LEFT JOIN users u ON p.created_by = u.id WHERE p.order_id = ? ORDER BY p.created_at DESC");
$payments->execute([$id]);
$payments = $payments->fetchAll(PDO::FETCH_ASSOC);

// 处理收款凭证上传(复用支出上传逻辑)
function handlePaymentUpload($fieldName) {
    if (empty($_FILES[$fieldName]['name']) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        error_log('payment attachment upload error: ' . $_FILES[$fieldName]['error']);
        return null;
    }
    // 限制大小 (10MB)
    if ($_FILES[$fieldName]['size'] > 10 * 1024 * 1024) {
        error_log('payment attachment upload error: file too large ' . $_FILES[$fieldName]['size']);
        return null;
    }
    // 验证类型(图片 + PDF)
    $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'bmp'];
    $originalName = $_FILES[$fieldName]['name'];
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        error_log('payment attachment upload error: invalid ext ' . $ext);
        return null;
    }
    // 验证 MIME (如果 finfo 可用)
    $allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'application/pdf'];
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $_FILES[$fieldName]['tmp_name']);
            finfo_close($finfo);
            if ($mime && !in_array($mime, $allowedMime, true)) {
                error_log('payment attachment upload error: invalid mime ' . $mime);
                return null;
            }
        }
    }
    // 保存 - 使用相对路径确保兼容性
    $baseDir = realpath(dirname(__FILE__));
    if (!$baseDir) {
        $baseDir = dirname(__FILE__);
    }
    $uploadDir = $baseDir . '/uploads/payments';
    if (!is_dir($uploadDir)) {
        if (!@mkdir($uploadDir, 0755, true)) {
            $err = error_get_last();
            error_log('payment attachment upload error: cannot create dir ' . $uploadDir . ' - ' . ($err['message'] ?? 'unknown'));
            return null;
        }
    }
    $newName = date('YmdHis') . '_' . substr(md5(uniqid(mt_rand(), true)), 0, 8) . '.' . $ext;
    $dest = $uploadDir . '/' . $newName;
    if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $dest)) {
        $err = error_get_last();
        error_log('payment attachment upload error: move_uploaded_file failed from ' . $_FILES[$fieldName]['tmp_name'] . ' to ' . $dest . ' - ' . ($err['message'] ?? 'unknown'));
        return null;
    }
    // 【图片压缩】收款凭证压到60KB内，失败不影响上传
    @compressUploadedImage($dest);
    return 'uploads/payments/' . $newName;
}

// 处理收款请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'payment') {
    // CSRF验证(兼容旧版config.php)
    if (function_exists('verifyCsrfToken')) {
        if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
            die('非法请求');
        }
    } elseif (!isset($_SESSION['csrf_token']) || !isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('非法请求');
    }
    $amount = floatval($_POST['amount']);
    $paymentMethod = trim($_POST['payment_method'] ?? '');
    $remark = trim($_POST['payment_remark'] ?? '');
    $discount = floatval($_POST['discount'] ?? 0); // 本次优惠金额

    if ($amount < 0) {
        $error = '金额不能为负数';
    } else if ($amount == 0) {
        // 金额为 0 (订单已结清时用户误触),静默跳转不创建支付记录
        header('Location: order_view.php?id=' . $id);
        exit;
    } else {
        try {
            $db->beginTransaction();
            // SELECT FOR UPDATE 锁行,取实时 paid_amount,防止并发覆盖
            $lockStmt = $db->prepare("SELECT paid_amount, total_amount, discount_amount FROM orders WHERE id = ? FOR UPDATE");
            $lockStmt->execute([$id]);
            $lockedOrder = $lockStmt->fetch(PDO::FETCH_ASSOC);
            $existingDiscount = floatval($lockedOrder['discount_amount'] ?? 0);
            $newTotalDiscount = $existingDiscount + $discount; // 累加本次优惠
            $payable = $lockedOrder['total_amount'] - $newTotalDiscount; // 应付 = 总价 - 累计优惠
            $due = $payable - $lockedOrder['paid_amount']; // 未付 = 应付 - 已付
            if ($amount > $due + 0.005) {
                throw new Exception('收款金额超过未付款金额(并发冲销,请重试)');
            }
            // 处理收款凭证上传
            $attachment = handlePaymentUpload('attachment_file');
            $stmt = $db->prepare("INSERT INTO payments (order_id, amount, payment_method, remark, attachment, created_by, discount) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$id, $amount, $paymentMethod, $remark, $attachment, $_SESSION['user_id'], $discount]);
            $newPaidAmount = $lockedOrder['paid_amount'] + $amount;
            $paymentStatus = (round($newPaidAmount, 2) >= round($payable, 2)) ? 2 : 1;  // 已收不低于应付(允许多付)即视为已付清
            $stmt = $db->prepare("UPDATE orders SET paid_amount = ?, discount_amount = ?, payment_status = ? WHERE id = ?");
            $stmt->execute([$newPaidAmount, $newTotalDiscount, $paymentStatus, $id]);
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            $error = $e->getMessage();
        }
        header("Location: order_view.php?id=$id&payment=success");
        exit;
    }
}

// 更新状态(POST-only，token 不出现在 URL/日志/Referer)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['confirm', 'produce', 'complete', 'cancel'])) {
    $currentToken = $_SESSION['csrf_token'] ?? null;
    if (!is_string($currentToken) || !is_string($_POST['csrf_token'] ?? '') || !hash_equals($currentToken, $_POST['csrf_token'])) {
        die('非法请求');
    }
    $statusMap = ['confirm' => 1, 'produce' => 2, 'complete' => 3, 'cancel' => 4];
    $db->prepare("UPDATE orders SET status = ? WHERE id = ?")->execute([$statusMap[$_POST['action']], $id]);
    header("Location: order_view.php?id=$id");
    exit;
}
// GET 请求带 action：兼容旧书签，重定向到干净 URL（不再执行状态变更）
if (isset($_GET['action']) && in_array($_GET['action'], ['confirm', 'produce', 'complete', 'cancel'])) {
    header("Location: order_view.php?id=$id");
    exit;
}

// 重新获取订单信息(可能刚更新过)
$order = $db->prepare("SELECT o.*, c.name as customer_name, c.contact, c.phone, c.address
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    WHERE o.id = ?");
$order->execute([$id]);
$order = $order->fetch(PDO::FETCH_ASSOC);

$created = isset($_GET['created']);
$paymentSuccess = isset($_GET['payment']);
$error = $error ?? '';

// 处理开票状态切换(AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_invoice') {
    header('Content-Type: application/json; charset=utf-8');
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {

        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON

        while (ob_get_level() > 0) { ob_end_clean(); }

        echo json_encode(['ok' => false, 'msg' => '非法请求']);
        exit;
    }
    $oid = intval($_POST['id'] ?? 0);
    $st = intval($_POST['status'] ?? 0);
    if ($oid <= 0) {

        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON

        while (ob_get_level() > 0) { ob_end_clean(); }

        echo json_encode(['ok' => false, 'msg' => '参数错误']);
        exit;
    }
    try {
        $db->prepare("UPDATE orders SET invoice_status = ? WHERE id = ?")->execute([$st, $oid]);

        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON

        while (ob_get_level() > 0) { ob_end_clean(); }

        echo json_encode(['ok' => true, 'msg' => $st ? '已标记开票' : '已取消开票']);
    } catch (Exception $e) {

        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON

        while (ob_get_level() > 0) { ob_end_clean(); }

        echo json_encode(['ok' => false, 'msg' => '操作失败']);
    }
    exit;
}
// 获取系统设置

$companyName = getSetting('company_name') ?: '广告公司';
$companyPhone = getSetting('company_phone') ?: '';
$companyAddress = getSetting('company_address') ?: '';

// 当前登录用户(制单人)
$currentUser = getCurrentUser();
$makerName = $currentUser['realname'] ?: $currentUser['username'];

// 优惠金额
$discount = 0;
$discountedAmount = $order['total_amount'] - $discount;

// 生成 CSRF Token（统一使用 config.php 中的实现，确保 session_history 被正确初始化）
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken); ?>">
    <title>订单详情 - <?php echo SITE_TITLE; ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .order-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }

        /* 收款状态样式 */
        .payment-badge { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: var(--radius-lg); font-weight: 600; font-size: 14px; }
        .payment-badge.unpaid { background: #FEE2E2; color: #991B1B; }
        .payment-badge.partial { background: #FEF3C7; color: #92400E; }
        .payment-badge.paid { background: #D1FAE5; color: #065F46; }

        .payment-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: var(--radius-xl); padding: 24px; margin-bottom: 24px; }
        .payment-card h3 { margin: 0 0 16px 0; font-size: 18px; display: flex; align-items: center; gap: 8px; }
        .payment-info { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
        .payment-item label { font-size: 12px; opacity: 0.8; display: block; margin-bottom: 4px; }
        .payment-item .value { font-size: 24px; font-weight: 800; }

        .payments-list { margin-top: 24px; }
        .payments-list h4 { font-size: 16px; margin-bottom: 12px; color: var(--gray-700); }
        .payment-record { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: var(--gray-50); border-radius: var(--radius-lg); margin-bottom: 8px; }
        .payment-record .info { display: flex; align-items: center; gap: 16px; }
        .payment-record .amount { font-weight: 700; color: var(--success); font-size: 16px; }

        /* 收款弹窗 */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.active { display: flex !important; }
        .modal {
            background: white;
            border-radius: var(--radius-xl);
            padding: 24px;
            width: 400px;
            max-width: 90%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            z-index: 10000;
        }
        .modal h3 { margin: 0 0 20px 0; font-size: 18px; }
        .modal .form-group { margin-bottom: 16px; }
        .modal label { display: block; font-weight: 600; margin-bottom: 6px; color: var(--gray-700); }
        .modal input, .modal select, .modal textarea { width: 100%; padding: 10px 12px; border: 1px solid var(--gray-300); border-radius: var(--radius-lg); font-size: 14px; }
        .modal .modal-actions { display: flex; gap: 12px; margin-top: 20px; }

        .order-header h1 { font-size: 28px; font-weight: 800; color: var(--gray-900); }
        .order-header .order-time { font-size: 14px; color: var(--gray-500); margin-top: 4px; }
        .order-meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .meta-item { background: var(--gray-50); border-radius: var(--radius-lg); padding: 16px 20px; }
        .meta-item label { font-size: 12px; color: var(--gray-500); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; display: block; }
        .meta-item .value { font-size: 16px; font-weight: 600; color: var(--gray-800); }
        .items-table { width: 100%; margin: 20px 0; border-collapse: separate; border-spacing: 0; border-radius: var(--radius-lg); overflow: hidden; table-layout: fixed; }
        .items-table th { background: var(--gradient-primary); color: white; padding: 14px 16px; text-align: center; font-weight: 600; }
        .items-table td { padding: 14px 16px; border-bottom: 1px solid var(--gray-100); text-align: center; word-wrap: break-word; overflow-wrap: break-word; }
        .items-table td.left { text-align: left; }
        .items-table th.spec-col, .items-table td.spec-col { text-align: left; max-width: 260px; word-break: break-all; line-height: 1.5; }
        .items-table tbody tr:hover { background: rgba(59, 130, 246, 0.02); }
        .items-table .total-row { background: var(--gray-50); font-weight: 700; }
        .actions-bar { margin-top: 32px; padding: 24px; background: var(--gray-50); border-radius: var(--radius-xl); display: flex; gap: 12px; flex-wrap: wrap; }

        /* 打印样式 */
        @media print {
            @page { size: A4 portrait; margin: 10mm 12mm; }
            body { background: white; margin: 0; padding: 0; }
            .topbar, .sidebar, .footer, .actions-bar, .btn, .success-msg, .screen-area { display: none !important; }
            .layout { display: block !important; }
            .main-content { padding: 0; max-width: 100%; }
            .print-area { display: block !important; font-family: "SimSun", "宋体", serif; font-size: 10pt; color: #000; line-height: 1.5; width: 95%; max-width: 680px; margin: 0 auto; padding: 10px 4px; }
            .print-company { text-align: center; font-size: 16pt; font-weight: bold; margin-bottom: 4px; letter-spacing: 2px; }
            .print-title { text-align: center; font-size: 18pt; font-weight: bold; margin-bottom: 8px; letter-spacing: 4px; }
            .print-info-table { width: 100%; border-collapse: collapse; border: none !important; margin-bottom: 4px; font-size: 10pt; }
            .print-info-table td { padding: 2px 6px; white-space: nowrap; border: none !important; }
            .print-items { width: 100%; border-collapse: collapse; border: 1px solid #000 !important; margin: 4px 2px; font-size: 9pt; table-layout: fixed; }
            .print-items th:nth-child(1) { width: 6%; } /* 序号 */
            .print-items th:nth-child(2) { width: 23%; } /* 商品 */
            .print-items th:nth-child(3) { width: 6%; } /* 单位 */
            .print-items th:nth-child(4) { width: 11%; } /* 数量 */
            .print-items th:nth-child(5) { width: 11%; } /* 单价 */
            .print-items th:nth-child(6) { width: 14%; } /* 销售金额 */
            .print-items th:nth-child(7) { width: 29%; } /* 制作要求 */
            /* 含平方数列时的 8 列宽度（仅在订单中有平方数时启用） */
            .print-items.with-sqm th:nth-child(1) { width: 6%; }
            .print-items.with-sqm th:nth-child(2) { width: 20%; }
            .print-items.with-sqm th:nth-child(3) { width: 11%; } /* 平方数 */
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
        .print-area { display: none; }

        /* 粘贴上传区域状态 */
        .paste-upload-area:hover { border-color: #3B82F6 !important; background: #EFF6FF !important; }
        .paste-upload-area:focus { border-color: #3B82F6 !important; box-shadow: 0 0 0 3px rgba(59,130,246,0.15) !important; }
        .paste-upload-area.paste-drag { border-color: #10B981 !important; background: #ECFDF5 !important; }
        .paste-upload-area.paste-flash { animation: pasteFlashAnim .6s ease; }
        @keyframes pasteFlashAnim {
            0%   { box-shadow: 0 0 0 0 rgba(16,185,129,0); }
            30%  { box-shadow: 0 0 0 6px rgba(16,185,129,0.35); border-color: #10B981; }
            100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); }
        }
    </style>
</head>
<body>
    <?php echo renderNav('orders'); ?>

    <!-- 屏幕显示区域 -->
    <div class="screen-area">
        <?php if ($created): ?>
        <div class="alert alert-success" style="margin-bottom: 20px;">✅ 订单创建成功!</div>
        <?php endif; ?>
        <?php if (isset($_GET['edited'])): ?>
        <div class="alert alert-success" style="margin-bottom: 20px;">✅ 订单修改成功!<?php if (isset($_GET['overpaid'])): ?> <span style="color:#92400E;">⚠️ 由于优惠后应付减少,已收金额多出 ¥<?php echo htmlspecialchars($_GET['overpaid']); ?> 已被裁剪,实际收款记录里仍有 ¥<?php echo htmlspecialchars($_GET['overpaid']); ?> 需手动处理(退款/冲减)。</span><?php endif; ?></div>
        <?php endif; ?>
        <?php if ($paymentSuccess): ?>
        <div class="alert alert-success" style="margin-bottom: 20px;">✅ 收款成功!</div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="alert alert-danger" style="margin-bottom: 20px;">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="order-header">
            <div>
                <h1>订单号:<?php echo h($order['order_no']); ?></h1>
                <div class="order-time">订单日期:<?php echo htmlspecialchars($order['order_date'] ?? date('Y-m-d', strtotime($order['created_at']))); ?> | 创建时间:<?php echo $order['created_at']; ?></div>
            </div>
            <div style="display:flex;gap:12px;align-items:center;">
                <a href="order_export.php?id=<?php echo $id; ?>" class="btn btn-outline" style="font-size:14px;padding:8px 16px;">📊 导出Excel</a>
                <?php if (in_array($order['status'], [0, 1])): ?>
                <a href="order_edit.php?id=<?php echo $id; ?>" class="btn btn-primary" style="font-size:14px;padding:8px 16px;">✏️ 修改订单</a>
                <?php endif; ?>
                <span class="payment-badge <?php echo ['unpaid', 'partial', 'paid'][$order['payment_status']]; ?>">
                    <?php echo ['未付款', '部分收款', '已付清'][$order['payment_status']]; ?>
                    <?php if ($order['payment_status'] == 1): ?>
                    (<?php echo number_format($order['paid_amount'], 2); ?>/<?php echo number_format($order['total_amount'], 2); ?>)
                    <?php endif; ?>
                </span>
                <span class="badge badge-<?php echo getStatusClass($order['status']); ?>" style="font-size:14px;padding:8px 16px;">
                    <?php echo getStatusText($order['status']); ?>
                </span>
                <button type="button" class="btn <?php echo !empty($order['invoice_status']) ? 'btn-success' : 'btn-ghost'; ?>" style="font-size:14px;padding:8px 16px;" onclick="toggleInvoice(<?php echo $order['id']; ?>, <?php echo !empty($order['invoice_status']) ? 0 : 1; ?>)">
                    <?php echo !empty($order['invoice_status']) ? '🧾 已开票' : '🧾 标记开票'; ?>
                </button>
            </div>
        </div>

        <!-- 收款信息卡片 -->
        <?php
        $discountAmt = floatval($order['discount_amount'] ?? 0);
        $payableAmt = $order['total_amount'] - $discountAmt;
        $dueAmt = $payableAmt - $order['paid_amount'];
        ?>
        <div class="payment-card">
            <h3>💰 收款信息</h3>
            <div class="payment-info">
                <div class="payment-item">
                    <label>订单金额</label>
                    <div class="value">￥<?php echo number_format($order['total_amount'], 2); ?></div>
                </div>
                <?php if ($discountAmt > 0): ?>
                <div class="payment-item">
                    <label>优惠</label>
                    <div class="value" style="color:#F97316;">-￥<?php echo number_format($discountAmt, 2); ?></div>
                </div>
                <div class="payment-item">
                    <label>应付金额</label>
                    <div class="value">￥<?php echo number_format($payableAmt, 2); ?></div>
                </div>
                <?php else: ?>
                <div class="payment-item">
                    <label>应付金额</label>
                    <div class="value">￥<?php echo number_format($payableAmt, 2); ?></div>
                </div>
                <?php endif; ?>
                <div class="payment-item">
                    <label>已收款</label>
                    <div class="value">￥<?php echo number_format($order['paid_amount'], 2); ?></div>
                </div>
                <div class="payment-item">
                    <label>待收款</label>
                    <div class="value" style="color:<?php echo $dueAmt > 0 ? '#EF4444' : '#10B981'; ?>;">￥<?php echo number_format(max(0, $dueAmt), 2); ?></div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-title"><span class="title-icon">👤</span> 客户信息</div>
            </div>
            <div class="card-body">
                <div class="order-meta">
                    <div class="meta-item"><label>客户名称</label><div class="value"><?php echo h($order['customer_name']); ?></div></div>
                    <div class="meta-item"><label>联系人</label><div class="value"><?php echo h($order['contact'] ?: '-'); ?></div></div>
                    <div class="meta-item"><label>联系电话</label><div class="value"><?php echo h($order['phone'] ?: '-'); ?></div></div>
                    <div class="meta-item"><label>地址</label><div class="value"><?php echo h($order['address'] ?: '-'); ?></div></div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-title"><span class="title-icon">📦</span> 订单明细</div>
            </div>
            <div class="card-body">
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>序号</th>
                            <th>商品</th>
                            <th class="spec-col">规格说明</th>
                            <th>尺寸(米)</th>
                            <th>平方数</th>
                            <th>数量</th>
                            <th>单位</th>
                            <th>单价</th>
                            <th>销售金额</th>
                            <th>参考图</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $i => $item):
                            $sizeText = ($item['length'] && $item['width']) ? $item['length'] . ' × ' . $item['width'] : '-';
                        ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td style="font-weight:600;"><?php echo h($item['product_name']); ?></td>
                            <td class="spec-col"><?php echo h($item['specification'] ?: '-'); ?></td>
                            <td><?php echo $sizeText; ?></td>
                            <td><?php echo $item['square_meter'] ? formatSqm($item['square_meter']) : '-'; ?></td>
                            <td><?php echo number_format($item['quantity'], 2); ?></td>
                            <td><?php echo h($item['unit']); ?></td>
                            <td><?php echo number_format($item['unit_price'], 2); ?></td>
                            <td style="font-weight:600;color:var(--primary);"><?php echo number_format($item['amount'], 2); ?></td>
                            <td>
                                <?php
                                // 【2026-09-09 修复】兼容相对路径: PHP-FPM cwd 不一定是 web 根, file_exists("uploads/...") 可能返回 false
                                $imgPath = $item['image_path'] ?? '';
                                $imgExists = false;
                                if ($imgPath !== '') {
                                    if (!preg_match('#^[A-Za-z]:[\\\\/]|^/#', $imgPath)) {
                                        $imgPath = __DIR__ . '/' . $imgPath;
                                    }
                                    $imgExists = file_exists($imgPath);
                                }
                                ?>
                                <?php if (!empty($item['image_path']) && $imgExists): ?>
                                <a href="javascript:void(0)" onclick="openLightbox('<?php echo htmlspecialchars(addslashes($item['image_path'])); ?>')" style="display:inline-block;width:40px;height:40px;border-radius:4px;overflow:hidden;border:1px solid var(--gray-200);">
                                    <img src="<?php echo htmlspecialchars($item['image_path']); ?>" loading="lazy" style="width:100%;height:100%;object-fit:cover;cursor:zoom-in;" alt="参考图">
                                </a>
                                <?php elseif (!empty($item['image_path'])): ?>
                                <span title="原文件已丢失，请重新上传" style="display:inline-block;padding:2px 6px;border-radius:4px;background:#FEF3C7;color:#92400E;font-size:11px;cursor:help;">图片丢失</span>
                                <?php else: ?>
                                <span style="color:var(--gray-400);">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td colspan="5" style="text-align:right;">合计:</td>
                            <td><?php echo number_format(array_sum(array_column($items, 'quantity')), 2); ?></td>
                            <td colspan="2"></td>
                            <td style="color:var(--danger);"><?php echo number_format($order['total_amount'], 2); ?></td>
                            <td></td>
                        </tr>
                        <tr class="total-row">
                            <td colspan="10" style="text-align:left;">合计金额(大写):<?php echo numToChinese($order['total_amount']); ?></td>
                        </tr>
                    </tbody>
                </table>

                <?php if ($order['remark']): ?>
                <div style="margin-top:20px;padding:16px;background:var(--gray-50);border-radius:var(--radius-lg);">
                    <strong>备注说明:</strong><?php echo nl2br(h($order['remark'])); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 收款记录 -->
        <?php if (count($payments) > 0): ?>
        <div class="payments-list">
            <h4>📋 收款记录</h4>
            <?php foreach ($payments as $payment): ?>
            <div class="payment-record">
                <div class="info">
                    <span class="amount">+￥<?php echo number_format($payment['amount'], 2); ?></span>
                    <?php if (floatval($payment['discount']) > 0): ?>
                    <span style="color:#F97316;font-size:13px;">优惠-￥<?php echo number_format($payment['discount'], 2); ?></span>
                    <?php endif; ?>
                    <span><?php echo htmlspecialchars($payment['payment_method'] ?: '未指定'); ?></span>
                    <span style="color:var(--gray-500);font-size:13px;"><?php echo $payment['created_at']; ?></span>
                    <?php if (!empty($payment['attachment'])): ?>
                    <a href="<?php echo htmlspecialchars($payment['attachment']); ?>" target="_blank" style="font-size:12px;color:var(--primary);text-decoration:none;">📎 查看凭证</a>
                    <?php endif; ?>
                </div>
                <div style="color:var(--gray-500);font-size:13px;">
                    <?php echo htmlspecialchars($payment['realname'] ?: '系统'); ?>
                    <?php if ($payment['remark']): ?>
                    <span style="margin-left:8px;color:var(--gray-400);">(<?php echo htmlspecialchars($payment['remark']); ?>)</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- 操作按钮 -->
        <div class="actions-bar">
            <?php if ($dueAmt > 0.005): ?>
            <button class="btn btn-success" onclick="openPaymentModal()">💰 收款</button>
            <?php endif; ?>
            <button class="btn btn-primary" onclick="window.open('file_manager.php?order_id=<?php echo $id; ?>', '_blank')">📎 文件管理</button>
            <?php if ($order['status'] == 0): ?>
            <button type="button" class="btn btn-primary" onclick="changeStatus(<?php echo $id; ?>, 'confirm', '确认订单?')">✅ 确认订单</button>
            <?php endif; ?>
            <?php if ($order['status'] == 1): ?>
            <button type="button" class="btn btn-primary" onclick="changeStatus(<?php echo $id; ?>, 'produce', '开始生产?')">🏭 开始生产</button>
            <?php endif; ?>
            <?php if ($order['status'] == 2): ?>
            <button type="button" class="btn btn-success" onclick="changeStatus(<?php echo $id; ?>, 'complete', '完成订单?')">✨ 完成订单</button>
            <?php endif; ?>
            <?php if ($order['status'] < 3): ?>
            <button type="button" class="btn btn-danger" onclick="changeStatus(<?php echo $id; ?>, 'cancel', '取消订单?')">❌ 取消订单</button>
            <?php endif; ?>
            <button class="btn btn-primary" onclick="window.print()">🖨️ 打印销售单</button>
            <a href="orders.php" class="btn btn-ghost">返回列表</a>
        </div>
    </div>

    <!-- 收款弹窗 -->
    <div id="paymentModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:2147483647;align-items:center;justify-content:center;padding:90px 20px 20px;box-sizing:border-box;overflow-y:auto;">
        <div style="background:white;border-radius:16px;padding:0;width:420px;max-width:90%;max-height:var(--modal-max-h, calc(100vh - 100px));box-shadow:0 20px 60px rgba(0,0,0,0.4);display:flex;flex-direction:column;overflow:hidden;margin:0 auto;">
            <h3 style="position:sticky;top:0;z-index:2;background:white;margin:0;padding:18px 24px;font-size:18px;font-weight:600;color:#1f2937;border-bottom:1px solid #f3f4f6;flex-shrink:0;">💰 录入收款</h3>
            <form method="POST" enctype="multipart/form-data" style="flex:1;min-height:0;overflow-y:auto;padding:18px 24px 24px;display:flex;flex-direction:column;">
                <input type="hidden" name="action" value="payment">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                <div style="margin-bottom:14px;">
                    <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151;">订单金额</label>
                    <input type="text" value="¥<?php echo number_format($order['total_amount'], 2); ?>" disabled style="width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;font-size:14px;background:#f9fafb;">
                </div>
                <?php if ($discountAmt > 0): ?>
                <div style="margin-bottom:14px;">
                    <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151;">优惠金额</label>
                    <input type="text" value="-¥<?php echo number_format($discountAmt, 2); ?>" disabled style="width:100%;padding:10px 12px;border:1px solid #F97316;border-radius:10px;font-size:14px;background:#FFF7ED;color:#F97316;font-weight:600;">
                </div>
                <div style="margin-bottom:14px;">
                    <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151;">应付金额</label>
                    <input type="text" value="¥<?php echo number_format($payableAmt, 2); ?>" disabled style="width:100%;padding:10px 12px;border:1px solid #3B82F6;border-radius:10px;font-size:14px;background:#EFF6FF;color:#1D4ED8;font-weight:700;">
                </div>
                <?php endif; ?>

                <div style="margin-bottom:14px;">
                    <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151;">已收款</label>
                    <input type="text" value="¥<?php echo number_format($order['paid_amount'], 2); ?>" disabled style="width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;font-size:14px;background:#f9fafb;">
                </div>

                <div style="margin-bottom:14px;">
                    <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151;">本次收款金额 *</label>
                    <!-- 【2026-09-15】自动填入待收金额；填写优惠后自动减去优惠 -->
                    <input type="number" name="amount" id="payAmount" step="0.01" min="0" max="<?php echo max(0, $dueAmt); ?>" value="<?php echo number_format(max(0, $dueAmt), 2, '.', ''); ?>" placeholder="最大可收:<?php echo number_format(max(0, $dueAmt), 2); ?>" oninput="payUserTouched = true" style="width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;font-size:14px;">
                    <div style="font-size:12px;color:#6B7280;margin-top:4px;">已自动填入待收金额，可修改</div>
                </div>

                <div style="margin-bottom:14px;">
                    <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151;">本次优惠（可不填）</label>
                    <input type="number" name="discount" id="payDiscount" step="0.01" min="0" value="0" placeholder="本次收款优惠金额" oninput="onPayDiscountInput()" style="width:100%;padding:10px 12px;border:1px solid #F97316;border-radius:10px;font-size:14px;color:#F97316;">
                    <div style="font-size:12px;color:#F97316;margin-top:4px;">填写优惠后，收款金额会自动减去优惠部分</div>
                </div>

                <div style="margin-bottom:14px;">
                    <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151;">收款方式</label>
                    <select name="payment_method" style="width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;font-size:14px;">
                        <option value="现金">现金</option>
                        <option value="微信">微信</option>
                        <option value="支付宝">支付宝</option>
                        <option value="银行转账">银行转账</option>
                        <option value="其他">其他</option>
                    </select>
                </div>

                <div style="margin-bottom:14px;">
                    <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151;">备注</label>
                    <textarea name="payment_remark" rows="2" placeholder="可选" style="width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;font-size:14px;"></textarea>
                </div>

                <div style="margin-bottom:18px;">
                    <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151;">
                        <span style="margin-right:6px;">📎</span>收款凭证/截图(可选)
                    </label>
                    <div class="paste-upload-area" id="paymentPasteArea" tabindex="0" style="position:relative;border:2px dashed #CBD5E1;border-radius:10px;padding:14px 12px;background:#F8FAFC;cursor:pointer;transition:all .15s ease;outline:none;">
                        <input type="file" name="attachment_file" id="paymentAttachment" accept="image/*,.pdf" onchange="previewPaymentAttachment(this)" style="position:absolute;top:0;left:0;right:0;bottom:0;width:100%;height:100%;opacity:0;cursor:pointer;padding:0;border:none;">
                        <div style="display:flex;flex-direction:column;align-items:center;gap:4px;color:#475569;font-size:13px;pointer-events:none;">
                            <span style="font-size:24px;line-height:1;">📋</span>
                            <span>点击选择 / 拖入文件 / <b style="color:#3B82F6;">Ctrl+V 粘贴截图</b></span>
                            <span style="font-size:11px;color:#94A3B8;">支持图片和 PDF,最多 10MB</span>
                        </div>
                    </div>
                    <div id="paymentAttachmentPreview" style="margin-top:8px;display:none;">
                        <img id="paymentAttachmentImg" src="" alt="预览" style="max-width:100%;max-height:160px;border-radius:8px;border:1px solid #E5E7EB;display:block;">
                        <div id="paymentAttachmentPdf" style="display:none;padding:8px 12px;background:#FEF3C7;border-radius:6px;color:#92400E;">📄 已选择 PDF 文件</div>
                        <button type="button" onclick="clearPaymentAttachment()" style="margin-top:6px;font-size:12px;color:#EF4444;background:none;border:none;cursor:pointer;padding:4px 8px;">✕ 移除凭证</button>
                    </div>
                </div>

                <div style="display:flex;gap:12px;flex-shrink:0;padding:16px 28px;border-top:1px solid #E5E7EB;background:#F9FAFB;border-radius:0 0 16px 16px;">
                    <button type="submit" style="flex:1;padding:12px;background:#10B981;color:white;border:none;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer;">✅ 确认收款</button>
                    <button type="button" onclick="closePaymentModal()" style="flex:1;padding:12px;background:#f3f4f6;color:#374151;border:none;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer;">取消</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    var payDefaultDue = <?php echo json_encode(max(0, (float)$dueAmt)); ?>; // 【2026-09-15】待收金额基准
    var payUserTouched = false; // 用户是否手动改过收款金额

    // 优惠变动 → 收款金额自动调整：收款金额 = 待收 - 优惠（与批量收款逻辑一致）
    function onPayDiscountInput() {
        if (payUserTouched) return; // 手动改过金额时不覆盖
        var discount = parseFloat(document.getElementById('payDiscount').value) || 0;
        if (discount < 0) discount = 0;
        document.getElementById('payAmount').value = Math.max(0, payDefaultDue - discount).toFixed(2);
    }

    function openPaymentModal() {
        var modal = document.getElementById('paymentModal');
        // Edge 浏览器中 calc(100vh) 可能包含 chrome, 用 window.innerHeight 更准确
        var maxH = window.innerHeight - 130;  // 上下各留 40px + topbar 余量
        modal.style.setProperty('--modal-max-h', maxH + 'px');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';  // 防止背景滚动
        // 【2026-09-15】每次打开重置为待收金额
        payUserTouched = false;
        document.getElementById('payAmount').value = payDefaultDue > 0 ? payDefaultDue.toFixed(2) : '';
        document.getElementById('payDiscount').value = 0;
    }
    function closePaymentModal() {
        document.getElementById('paymentModal').style.display = 'none';
        document.body.style.overflow = '';
    }

    // ===================== 收款凭证预览/清除 =====================
    function previewPaymentAttachment(input) {
        var preview = document.getElementById('paymentAttachmentPreview');
        var img = document.getElementById('paymentAttachmentImg');
        var pdf = document.getElementById('paymentAttachmentPdf');
        img.src = ''; img.style.display = 'none';
        pdf.style.display = 'none';
        if (!input.files || !input.files[0]) { preview.style.display = 'none'; return; }
        var file = input.files[0];
        if (file.type === 'application/pdf') {
            pdf.style.display = 'block';
            preview.style.display = 'block';
        } else if (file.type.startsWith('image/')) {
            var reader = new FileReader();
            reader.onload = function(e) {
                img.src = e.target.result;
                img.style.display = 'block';
                preview.style.display = 'block';
            };
            reader.readAsDataURL(file);
        }
    }

    function clearPaymentAttachment() {
        var input = document.getElementById('paymentAttachment');
        input.value = '';
        document.getElementById('paymentAttachmentPreview').style.display = 'none';
        document.getElementById('paymentAttachmentImg').src = '';
    }

    // ===================== 截图粘贴支持 =====================
    function applyPastedFile(file, inputId, previewFn) {
        if (!file) return false;
        var okType = file.type && (file.type.startsWith('image/') || file.type === 'application/pdf');
        if (!okType) { alert('仅支持粘贴图片或 PDF 文件'); return false; }
        if (file.size > 10 * 1024 * 1024) { alert('文件超过 10MB 限制'); return false; }
        var input = document.getElementById(inputId);
        try {
            var dt = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;
        } catch (e) {
            alert('当前浏览器不支持该粘贴方式,请用 Chrome/Edge 或选择文件');
            return false;
        }
        if (typeof window[previewFn] === 'function') {
            window[previewFn](input);
        }
        var area = input.closest('.paste-upload-area');
        if (area) {
            area.classList.add('paste-flash');
            setTimeout(function() { area.classList.remove('paste-flash'); }, 600);
        }
        return true;
    }

    function enablePaymentPasteUpload() {
        var area = document.getElementById('paymentPasteArea');
        var input = document.getElementById('paymentAttachment');
        if (!area || !input) return;

        area.addEventListener('click', function(e) {
            if (e.target.closest('button, a')) return;
            input.click();
        });

        area.addEventListener('paste', function(e) {
            e.preventDefault(); e.stopPropagation();
            var items = (e.clipboardData || window.clipboardData).items || [];
            for (var i = 0; i < items.length; i++) {
                if (items[i].kind === 'file') {
                    var file = items[i].getAsFile();
                    if (file) { applyPastedFile(file, 'paymentAttachment', 'previewPaymentAttachment'); return; }
                }
            }
            alert('剪贴板中没有可粘贴的图片,请先用截图工具截图后重试');
        });

        area.addEventListener('dragover', function(e) { e.preventDefault(); area.classList.add('paste-drag'); });
        area.addEventListener('dragleave', function(e) { e.preventDefault(); area.classList.remove('paste-drag'); });
        area.addEventListener('drop', function(e) {
            e.preventDefault();
            area.classList.remove('paste-drag');
            var file = e.dataTransfer.files && e.dataTransfer.files[0];
            if (file) applyPastedFile(file, 'paymentAttachment', 'previewPaymentAttachment');
        });

        // 全局 paste 兜底
        document.addEventListener('paste', function(e) {
            var modal = document.getElementById('paymentModal');
            if (!modal || modal.style.display === 'none') return;
            var ae = document.activeElement;
            if (ae && (ae.tagName === 'TEXTAREA' || (ae.tagName === 'INPUT' && ae.type !== 'file'))) return;
            var items = (e.clipboardData || window.clipboardData).items || [];
            for (var i = 0; i < items.length; i++) {
                if (items[i].kind === 'file') {
                    var file = items[i].getAsFile();
                    if (file) {
                        e.preventDefault();
                        applyPastedFile(file, 'paymentAttachment', 'previewPaymentAttachment');
                        return;
                    }
                }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', enablePaymentPasteUpload);
    } else {
        enablePaymentPasteUpload();
    }
    </script>

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
                <td>客户:<?php echo htmlspecialchars($order['customer_name']); ?></td>
                <td>电话:<?php echo htmlspecialchars($order['phone'] ?: ''); ?></td>
                <td>单据日期:<?php echo htmlspecialchars($order['order_date'] ?? date('Y-m-d', strtotime($order['created_at']))); ?></td>
                <td>单据编号:<?php echo $order['order_no']; ?></td>
            </tr>
            <tr>
                <td>联系人:<?php echo htmlspecialchars($order['contact'] ?: ''); ?></td>
                <td colspan="3">地址:<?php echo htmlspecialchars($order['address'] ?: ''); ?></td>
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
                    <td ><?php echo htmlspecialchars($item['product_name']); ?></td>
                    <?php if ($hasSqm): ?><td><?php echo floatval($item['square_meter'] ?? 0) > 0 ? formatSqm($item['square_meter']) : ''; ?></td><?php endif; ?>
                    <td><?php echo htmlspecialchars($item['unit']); ?></td>
                    <td><?php echo number_format($item['quantity'], 2); ?></td>
                    <td><?php echo number_format($item['unit_price'], 2); ?></td>
                    <td><?php echo number_format($item['amount'], 2); ?></td>
                    <td ><?php echo htmlspecialchars($item['specification'] ?: ''); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <?php if ($hasSqm): ?>
                    <td colspan="2" style="text-align:right;">合计:</td>
                    <td><?php echo formatSqm($sqmTotal); ?></td>
                    <td><?php echo number_format(array_sum(array_column($items, 'quantity')), 2); ?></td>
                    <td></td>
                    <td><?php echo number_format($order['total_amount'], 2); ?></td>
                    <td></td>
                    <?php else: ?>
                    <td colspan="3" style="text-align:right;">合计:</td>
                    <td><?php echo number_format(array_sum(array_column($items, 'quantity')), 2); ?></td>
                    <td></td>
                    <td><?php echo number_format($order['total_amount'], 2); ?></td>
                    <td></td>
                    <?php endif; ?>
                </tr>
                <tr class="caps-row">
                    <td colspan="<?php echo $hasSqm ? 8 : 7; ?>">合计金额(大写):<?php echo numToChinese($order['total_amount']); ?></td>
                </tr>
            </tbody>
        </table>

        <table class="print-summary">
            <tr>
                <td>订单金额:<?php echo number_format($order['total_amount'], 2); ?></td>
                <?php if ($discountAmt > 0): ?>
                <td>优惠:<?php echo number_format($discountAmt, 2); ?></td>
                <td>应付:<?php echo number_format($payableAmt, 2); ?></td>
                <?php endif; ?>
                <td>已收款:<?php echo number_format($order['paid_amount'], 2); ?></td>
                <td>待收款:<?php echo number_format(max(0, $dueAmt), 2); ?></td>
                <td>收款状态:<?php echo ['未付款', '部分收款', '已付清'][$order['payment_status']]; ?></td>
            </tr>
            <tr>
                <td colspan="4">备注说明:<?php echo htmlspecialchars($order['remark'] ?: ''); ?></td>
            </tr>
        </table>

        <table class="print-sign">
            <tr>
                <td>制单人:<?php echo htmlspecialchars($makerName); ?></td>
                <td>销售人员:<?php echo htmlspecialchars($makerName); ?></td>
                <td>客户签字:</td>
            </tr>
        </table>
    </div>

    <?php echo renderFooter(); ?>
<script>
function toggleInvoice(orderId, newStatus) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    fetch('order_view.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=toggle_invoice&id=' + orderId + '&status=' + newStatus + '&csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            location.reload();
        } else {
            alert(data.msg || '操作失败');
        }
    })
    .catch(() => alert('网络错误'));
}

    function changeStatus(orderId, action, confirmMsg) {
        if (!confirm(confirmMsg)) return;
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        fetch('order_view.php?id=' + orderId, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=' + action + '&csrf_token=' + encodeURIComponent(csrfToken)
        })
        .then(r => r.text())
        .then(() => location.reload())
        .catch(() => alert('操作失败'));
    }
</script>

<!-- Lightbox 缩略图放大弹窗 -->
<div id="lightboxOverlay" onclick="closeLightbox()" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.85);z-index:9999;cursor:zoom-out;justify-content:center;align-items:center;">
    <img id="lightboxImg" src="" style="max-width:90%;max-height:90%;box-shadow:0 4px 24px rgba(0,0,0,0.5);border-radius:4px;">
</div>
<script>
function openLightbox(url) {
    var lb = document.getElementById('lightboxOverlay');
    document.getElementById('lightboxImg').src = url;
    lb.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeLightbox() {
    document.getElementById('lightboxOverlay').style.display = 'none';
    document.body.style.overflow = '';
    document.getElementById('lightboxImg').src = '';
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeLightbox();
});
</script>
</body>
</html>

