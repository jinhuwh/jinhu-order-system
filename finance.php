<?php
/**
 * 财务管理
 * 包含:收入统计、支出管理、利润分析
 */

// PhpSpreadsheet 命名空间(条件加载)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
 require_once __DIR__ . '/vendor/autoload.php';
}

// 【2026-09-09 修复】强制清理 opcache + 隐藏错误, 防 JSON 污染 + 加快热更新
if (function_exists('opcache_invalidate')) { @opcache_invalidate(__FILE__, true); }
require_once 'config.php';
initDatabase();
checkLogin();

if (!hasPermission(PERM_FINANCE_VIEW)) {
 die('<script>alert("无权限访问,请联系管理员授权");location.href="index.php";</script>');
}

$db = getDB();

/**
 * 处理附件上传(支持图片/PDF,兼容 expense / payment / 编辑支出 等场景)
 * @param string $fieldName form 中的 input name
 * @param string $subDir 保存子目录,例如 'expenses' / 'payments'
 * @return string|null 返回相对路径 uploads/{subDir}/xxx,失败返回 null
 */
function handleAttachmentUpload($fieldName, $subDir = 'expenses') {
 if (empty($_FILES[$fieldName]['name']) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
 return null;
 }
 if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
 error_log($subDir . ' attachment upload error: ' . $_FILES[$fieldName]['error']);
 return null;
 }
 // 限制大小 (10MB)
 if ($_FILES[$fieldName]['size'] > 10 * 1024 * 1024) {
 error_log($subDir . ' attachment upload error: file too large ' . $_FILES[$fieldName]['size']);
 return null;
 }
 // 验证类型(图片 + PDF)
 $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'bmp'];
 $originalName = $_FILES[$fieldName]['name'];
 $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
 if (!in_array($ext, $allowedExt, true)) {
 error_log($subDir . ' attachment upload error: invalid ext ' . $ext);
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
 error_log($subDir . ' attachment upload error: invalid mime ' . $mime);
 return null;
 }
 }
 }
 // 保存 - 使用相对路径确保兼容性
 $baseDir = realpath(dirname(__FILE__));
 if (!$baseDir) {
 $baseDir = dirname(__FILE__);
 }
 $subDir = preg_replace('/[^a-zA-Z0-9_\-]/', '', $subDir);
 $uploadDir = $baseDir . '/uploads/' . $subDir;
 if (!is_dir($uploadDir)) {
 if (!@mkdir($uploadDir, 0755, true)) {
 $err = error_get_last();
 error_log($subDir . ' attachment upload error: cannot create dir ' . $uploadDir . ' - ' . ($err['message'] ?? 'unknown'));
 return null;
 }
 }
 $newName = date('YmdHis') . '_' . substr(md5(uniqid(mt_rand(), true)), 0, 8) . '.' . $ext;
 $dest = $uploadDir . '/' . $newName;
 if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $dest)) {
 $err = error_get_last();
 error_log($subDir . ' attachment upload error: move_uploaded_file failed from ' . $_FILES[$fieldName]['tmp_name'] . ' to ' . $dest . ' - ' . ($err['message'] ?? 'unknown'));
 return null;
 }
 // 【图片压缩】支出凭证/收款凭证压到60KB内，失败不影响上传
 @compressUploadedImage($dest);
 return 'uploads/' . $subDir . '/' . $newName;
}

// 兼容旧调用 (支出上传)
function handleExpenseUpload($fieldName) {
 return handleAttachmentUpload($fieldName, 'expenses');
}

// 获取筛选时间
$year = intval($_GET['year'] ?? date('Y'));
$monthParam = $_GET['month'] ?? date('n');
$isFullYear = ($monthParam === 'all');
$month = $isFullYear ? 1 : intval($monthParam);
$dateStart = $isFullYear ? sprintf('%04d-01-01', $year) : sprintf('%04d-%02d-01', $year, $month);
$dateEnd = $isFullYear ? sprintf('%04d-12-31', $year) : date('Y-m-t', strtotime($dateStart));

// 处理 AJAX 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
 header('Content-Type: application/json; charset=utf-8');

 if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
 echo json_encode(['ok' => false, 'msg' => '安全验证失败']);
 exit;
 }

 $action = $_POST['ajax_action'];

 if ($action === 'add_expense') {
 $category = trim($_POST['category'] ?? '');
 $amount = floatval($_POST['amount'] ?? 0);
 $expense_date = trim($_POST['expense_date'] ?? '');
 $description = trim($_POST['description'] ?? '');
 $order_id = intval($_POST['order_id'] ?? 0);

 if (empty($category) || $amount <= 0 || empty($expense_date)) {
 echo json_encode(['ok' => false, 'msg' => '请填写完整信息']);
 exit;
 }

 // 如果有关联订单,验证订单存在
 $order_no = '';
 if ($order_id > 0) {
 $checkStmt = $db->prepare("SELECT order_no FROM orders WHERE id = ?");
 $checkStmt->execute([$order_id]);
 $orderRow = $checkStmt->fetch(PDO::FETCH_ASSOC);
 if (!$orderRow) {
 echo json_encode(['ok' => false, 'msg' => '关联订单不存在']);
 exit;
 }
 $order_no = $orderRow['order_no'];
 }

 // 处理支付截图上传
 $attachment = handleExpenseUpload('attachment_file');

 $stmt = $db->prepare("INSERT INTO expenses (category, amount, expense_date, description, order_id, attachment, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
 $stmt->execute([$category, $amount, $expense_date, $description, $order_id > 0 ? $order_id : null, $attachment, $_SESSION['user_id']]);

 logOperation('add_expense', [
 'category' => $category,
 'amount' => $amount,
 'date' => $expense_date,
 'order_id' => $order_id,
 'order_no' => $order_no,
 'attachment' => $attachment
 ]);

 $msg = '支出记录添加成功';
 if ($order_no) $msg .= '(已关联订单 ' . $order_no . ')';
 echo json_encode(['ok' => true, 'msg' => $msg]);
 exit;
 }

 if ($action === 'delete_expense') {
 $id = intval($_POST['id'] ?? 0);
 // 查询原记录附件,删除时一起清理
 $attStmt = $db->prepare("SELECT attachment FROM expenses WHERE id = ?");
 $attStmt->execute([$id]);
 $oldAtt = $attStmt->fetchColumn();
 $baseDir = dirname(__FILE__);
 if ($oldAtt && file_exists($baseDir . '/' . $oldAtt)) {
 @unlink($baseDir . '/' . $oldAtt);
 }
 $stmt = $db->prepare("DELETE FROM expenses WHERE id = ?");
 $stmt->execute([$id]);

 logOperation('delete_expense', ['id' => $id]);
 echo json_encode(['ok' => true, 'msg' => '支出记录已删除']);
 exit;
 }

 if ($action === 'update_expense') {
 $id = intval($_POST['id'] ?? 0);
 $category = trim($_POST['category'] ?? '');
 $amount = floatval($_POST['amount'] ?? 0);
 $expense_date = trim($_POST['expense_date'] ?? '');
 $description = trim($_POST['description'] ?? '');
 $order_id = intval($_POST['order_id'] ?? 0);
 $remove_attachment = isset($_POST['remove_attachment']) && $_POST['remove_attachment'] === '1';

 if (empty($category) || $amount <= 0 || empty($expense_date)) {
 echo json_encode(['ok' => false, 'msg' => '请填写完整信息']);
 exit;
 }

 // 验证订单
 $order_no = '';
 if ($order_id > 0) {
 $checkStmt = $db->prepare("SELECT order_no FROM orders WHERE id = ?");
 $checkStmt->execute([$order_id]);
 $orderRow = $checkStmt->fetch(PDO::FETCH_ASSOC);
 if (!$orderRow) {
 echo json_encode(['ok' => false, 'msg' => '关联订单不存在']);
 exit;
 }
 $order_no = $orderRow['order_no'];
 }

 // 查询原记录获取旧截图
 $oldRow = $db->prepare("SELECT attachment FROM expenses WHERE id = ?");
 $oldRow->execute([$id]);
 $oldAttachment = $oldRow->fetchColumn();

 $newAttachment = $oldAttachment;
 $baseDir = dirname(__FILE__);
 if (!empty($_FILES['attachment_file']['name'])) {
 $uploaded = handleExpenseUpload('attachment_file');
 if ($uploaded) {
 // 删除旧文件
 if ($oldAttachment && file_exists($baseDir . '/' . $oldAttachment)) {
 @unlink($baseDir . '/' . $oldAttachment);
 }
 $newAttachment = $uploaded;
 }
 } elseif ($remove_attachment) {
 if ($oldAttachment && file_exists($baseDir . '/' . $oldAttachment)) {
 @unlink($baseDir . '/' . $oldAttachment);
 }
 $newAttachment = null;
 }

 $stmt = $db->prepare("UPDATE expenses SET category = ?, amount = ?, expense_date = ?, description = ?, order_id = ?, attachment = ? WHERE id = ?");
 $stmt->execute([$category, $amount, $expense_date, $description, $order_id > 0 ? $order_id : null, $newAttachment, $id]);

 logOperation('update_expense', [
 'id' => $id,
 'category' => $category,
 'amount' => $amount,
 'date' => $expense_date,
 'order_id' => $order_id,
 'order_no' => $order_no,
 'attachment' => $newAttachment
 ]);

 $msg = '支出记录修改成功';
 if ($order_no) $msg .= '(已关联订单 ' . $order_no . ')';
 echo json_encode(['ok' => true, 'msg' => $msg]);
 exit;
 }

 if ($action === 'get_expense') {
 $id = intval($_POST['id'] ?? 0);
 $stmt = $db->prepare("SELECT * FROM expenses WHERE id = ?");
 $stmt->execute([$id]);
 $expense = $stmt->fetch(PDO::FETCH_ASSOC);
 echo json_encode(['ok' => true, 'data' => $expense]);
 exit;
 }

 if ($action === 'add_payment') {
 $order_id = intval($_POST['order_id'] ?? 0);
 $amount = floatval($_POST['amount'] ?? 0);
 $pay_date = trim($_POST['pay_date'] ?? '');
 $pay_method = trim($_POST['pay_method'] ?? '现金');
 $remark = trim($_POST['remark'] ?? '');

 if (empty($order_id) || $amount <= 0 || empty($pay_date)) {
 echo json_encode(['ok' => false, 'msg' => '请填写完整信息']);
 exit;
 }

 // 处理收款凭证上传(事务外,避免上传失败影响收款事务)
 $paymentAttachment = handleAttachmentUpload('attachment_file', 'payments');
 // 标记上传是否尝试了(用于友好提示)
 $uploadAttempted = !empty($_FILES['attachment_file']['name']) && $_FILES['attachment_file']['error'] !== UPLOAD_ERR_NO_FILE;

 try {
 $db->beginTransaction();
 $stmt = $db->prepare("SELECT paid_amount, total_amount FROM orders WHERE id = ? FOR UPDATE");
 $stmt->execute([$order_id]);
 $order = $stmt->fetch(PDO::FETCH_ASSOC);
 if (!$order) {
 throw new Exception('订单不存在');
 }
 $discount = floatval($_POST['discount'] ?? 0);
 $unpaid = ($order['total_amount'] - floatval($order['discount_amount'] ?? 0)) - floatval($order['paid_amount'] ?? 0);
 if ($amount > $unpaid + 0.005) {
 throw new Exception('收款金额不能超过未收款 ￥' . number_format($unpaid, 2));
 }
 $stmt = $db->prepare("INSERT INTO payments (order_id, amount, payment_method, remark, attachment, created_by, created_at, discount) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
 $stmt->execute([$order_id, $amount, $pay_method, $remark, $paymentAttachment, $_SESSION['user_id'], $pay_date . ' ' . date('H:i:s'), $discount]);
 $newPaid = floatval($order['paid_amount'] ?? 0) + $amount;
 // 同步把本次优惠累加到 orders.discount_amount(数据源 = 收款时录入的优惠)
 $newDiscount = floatval($order['discount_amount'] ?? 0) + $discount;
 // 付款状态判断:实收 + 优惠 >= 总额 即视为已付清
 $newStatus = ($newPaid + $newDiscount) >= ($order['total_amount'] - 0.01) ? 2 : ($newPaid > 0 ? 1 : 0);
 $stmt = $db->prepare("UPDATE orders SET paid_amount = ?, discount_amount = ?, payment_status = ? WHERE id = ?");
 $stmt->execute([$newPaid, $newDiscount, $newStatus, $order_id]);
 $db->commit();
 } catch (Exception $e) {
 if ($db->inTransaction()) $db->rollBack();
 // 收款失败,删除已上传的凭证
 if ($paymentAttachment && file_exists(__DIR__ . '/' . $paymentAttachment)) {
 @unlink(__DIR__ . '/' . $paymentAttachment);
 }
 echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
 exit;
 }

 logOperation('add_payment', [
 'order_id' => $order_id,
 'amount' => $amount,
 'method' => $pay_method,
 'date' => $pay_date,
 'attachment' => $paymentAttachment
 ]);

 $msg = '收款登记成功!新共付 ¥' . number_format($newPaid, 2) . ' / 订单总额 ¥' . number_format($order['total_amount'], 2);
 if ($uploadAttempted && !$paymentAttachment) {
 $msg .= '(凭证未上传,请检查文件格式/大小)';
 }
 echo json_encode([
 'ok' => true,
 'msg' => $msg
 ]);
 exit;
 }

 if ($action === 'get_unpaid_orders') {
 // 获取未收款订单(包含所有必要字段)
 $stmt = $db->query("SELECT o.id, o.order_no, o.order_date, o.total_amount, o.discount_amount, o.paid_amount, c.name as customer_name FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE o.payment_status IN (0,1) AND o.status IN (1,2,3) ORDER BY o.order_date ASC");
 $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
 echo json_encode(['ok' => true, 'data' => $orders]);
 exit;
 }

 if ($action === 'get_month_stats') {
 // 获取指定月份的统计数据
 $y = intval($_POST['year']);
 $m = intval($_POST['month']);
 $start = sprintf('%04d-%02d-01', $y, $m);
 $end = date('Y-m-t', strtotime($start));

 // 收入(已完成订单的总金额)
 $stmt = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status IN (1,2,3) AND created_at >= ? AND created_at <= ?");
 $stmt->execute([$start, $end . ' 23:59:59']);
 $revenue = $stmt->fetchColumn();

 // 已收款(口径统一:按收款时间过滤,与月度趋势/导出一致)
 $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments p WHERE p.created_at >= ? AND p.created_at <= ?");
 $stmt->execute([$start, $end . ' 23:59:59']);
 $received = $stmt->fetchColumn();

 // 支出
 $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date >= ? AND expense_date <= ?");
 $stmt->execute([$start, $end]);
 $expense = $stmt->fetchColumn();

 // 利润
 $profit = $revenue - $expense;

 echo json_encode([
 'ok' => true,
 'data' => [
 'revenue' => $revenue,
 'received' => $received,
 'expense' => $expense,
 'profit' => $profit
 ]
 ]);
 exit;
 }
}

// ============ 导出 Excel ============
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
 // 缓出 HTML 及后续输出,等数据全部加载后再处理
 ob_start();
 $exportAfterLoad = true;
}

// ============ 导出 PDF(打印友好 HTML)============
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
 // 直接收集少量关键数据后立即输出 PDF 页面
 $pdfYear = intval($_GET['year'] ?? date('Y'));
 $pdfMonth = $_GET['month'] ?? date('n');
 $pdfRange = $_GET['range'] ?? 'month';
 $pdfType = $_GET['type'] ?? 'summary';

 if ($pdfRange === 'all') {
 $pdfStart = $pdfYear . '-01-01';
 $pdfEnd = $pdfYear . '-12-31';
 $pdfLabel = $pdfYear . '年 全年';
 } elseif ($pdfRange === 'custom') {
 $pdfStart = trim($_GET['start'] ?? '');
 $pdfEnd = trim($_GET['end'] ?? '');
 $pdfLabel = substr($pdfStart, 0, 10) . ' ~ ' . substr($pdfEnd, 0, 10);
 } else {
 $pdfStart = sprintf('%04d-%02d-01', $pdfYear, intval($pdfMonth));
 $pdfEnd = sprintf('%04d-%02d', $pdfYear, intval($pdfMonth)) . '-' . date('t', strtotime($pdfStart));
 $pdfLabel = $pdfYear . '年 ' . intval($pdfMonth) . '月';
 }

 // 查询 PDF 数据
 $pdfOrders = $db->prepare("SELECT o.*, c.name AS customer_name FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE o.status IN (1,2,3) AND o.created_at >= ? AND o.created_at <= ? ORDER BY o.order_date ASC");
 $pdfOrders->execute([$pdfStart . ' 00:00:00', $pdfEnd . ' 23:59:59']);
 $pdfOrders = $pdfOrders->fetchAll(PDO::FETCH_ASSOC);

 $pdfPayments = $db->prepare("SELECT p.*, o.order_no FROM payments p JOIN orders o ON p.order_id = o.id WHERE p.created_at >= ? AND p.created_at <= ? ORDER BY p.created_at ASC");
 $pdfPayments->execute([$pdfStart . ' 00:00:00', $pdfEnd . ' 23:59:59']);
 $pdfPayments = $pdfPayments->fetchAll(PDO::FETCH_ASSOC);

 $pdfExpenses = $db->prepare("SELECT e.*, u.realname FROM expenses e LEFT JOIN users u ON e.created_by = u.id WHERE e.expense_date >= ? AND e.expense_date <= ? ORDER BY e.expense_date ASC");
 $pdfExpenses->execute([$pdfStart, $pdfEnd]);
 $pdfExpenses = $pdfExpenses->fetchAll(PDO::FETCH_ASSOC);

 $pdfRevenue = array_sum(array_column($pdfOrders, 'total_amount'));
 $pdfReceived = array_sum(array_column($pdfPayments, 'amount')); // 与月度趋势/仪表盘口径一致,按收款时间汇总
 $pdfPending = $pdfRevenue - $pdfReceived;
 $pdfExpenseTotal = array_sum(array_column($pdfExpenses, 'amount'));
 $pdfProfit = $pdfRevenue - $pdfExpenseTotal;
 $pdfOrderCount = count($pdfOrders);

 // 输出打印友好 HTML
 header('Content-Type: text/html; charset=utf-8');
 ?>
 <!DOCTYPE html>
 <html lang="zh">
 <head>
 <meta charset="UTF-8">
 <title>财务报表 - <?php echo $pdfLabel; ?></title>
 <style>
 @import url('https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@400;700&display=swap');
 * { margin: 0; padding: 0; box-sizing: border-box; }
 body { font-family: 'Noto Sans SC', 'Microsoft YaHei', Arial, sans-serif; font-size: 14px; color: #111; padding: 24px; background: #fff; }

 /* 打印控制 */
 @media print {
 @page { margin: 15mm; size: A4 portrait; }
 body { padding: 0; font-size: 12px; }
 .no-print { display: none !important; }
 h2, h3 { page-break-after: avoid; }
 table { page-break-inside: auto; }
 tr { page-break-inside: avoid; }
 }

 .header { text-align: center; margin-bottom: 24px; border-bottom: 2px solid #1a56db; padding-bottom: 12px; }
 .header h1 { font-size: 22px; color: #1a56db; margin-bottom: 4px; }
 .header p { font-size: 12px; color: #666; }

 /* 顶部工具栏 */
 .toolbar { background: #f3f4f6; border: 1px solid #d1d5db; border-radius: 6px; padding: 12px 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
 .toolbar .tip { flex: 1; font-size: 13px; color: #374151; }
 .btn-print { background: #1a56db; color: #fff; border: none; padding: 8px 20px; border-radius: 5px; font-size: 14px; cursor: pointer; font-weight: 600; }
 .btn-print:hover { background: #1e40af; }

 /* 摘要卡片 */
 .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 24px; }
 .summary-card { border: 1px solid #e5e7eb; border-radius: 6px; padding: 14px 16px; background: #fff; }
 .summary-card .label { font-size: 12px; color: #6b7280; margin-bottom: 4px; }
 .summary-card .value { font-size: 18px; font-weight: 700; color: #111; }
 .summary-card .sub { font-size: 11px; color: #9ca3af; }
 .summary-card.blue { border-left: 4px solid #3b82f6; }
 .summary-card.green { border-left: 4px solid #10b981; }
 .summary-card.orange { border-left: 4px solid #f59e0b; }
 .summary-card.red { border-left: 4px solid #ef4444; }
 .summary-card.purple { border-left: 4px solid #8b5cf6; }

 /* 区块 */
 .section { margin-bottom: 28px; }
 .section-title { font-size: 16px; font-weight: 700; color: #1f2937; border-bottom: 1px solid #e5e7eb; padding-bottom: 6px; margin-bottom: 10px; }

 /* 表格 */
 table { width: 100%; border-collapse: collapse; font-size: 13px; }
 th { background: #1a56db; color: #fff; padding: 8px 10px; text-align: left; }
 td { padding: 7px 10px; border-bottom: 1px solid #f3f4f6; }
 tr:nth-child(even) td { background: #f9fafb; }
 tr:hover td { background: #eff6ff; }
 .money { text-align: right; font-variant-numeric: tabular-nums; }
 .money-neg { color: #dc2626; }
 .money-pos { color: #16a34a; }
 .badge { display: inline-block; padding: 2px 7px; border-radius: 10px; font-size: 11px; font-weight: 600; }
 .badge-green { background: #dcfce7; color: #15803d; }
 .badge-yellow { background: #fef9c3; color: #a16207; }
 .badge-red { background: #fee2e2; color: #991b1b; }
 .badge-gray { background: #f3f4f6; color: #4b5563; }

 /* 合计行 */
 tfoot td { background: #e0e7ff; font-weight: 700; color: #1e40af; border-bottom: none; }

 /* 打印时每页重复表头 */
 thead { display: table-header-group; }

 .footer { text-align: center; font-size: 11px; color: #9ca3af; margin-top: 24px; border-top: 1px solid #e5e7eb; padding-top: 10px; }
 </style>
 </head>
 <body>

 <!-- 顶部工具栏(打印时隐藏) -->
 <div class="toolbar no-print">
 <span class="tip">💡 如需保存 PDF,请在浏览器打印对话框中选择「另存为 PDF」</span>
 <button class="btn-print" onclick="window.print()">🖨 打印 / 另存为 PDF</button>
 </div>

 <!-- 表头 -->
 <div class="header">
 <h1>📊 财务报表</h1>
 <p><?php echo $pdfLabel; ?> &nbsp;|&nbsp; 生成时间:<?php echo date('Y-m-d H:i'); ?></p>
 </div>

 <!-- 摘要 -->
 <div class="summary-grid">
 <div class="summary-card blue">
 <div class="label">📈 总收入</div>
 <div class="value money-pos">¥<?php echo number_format($pdfRevenue, 2); ?></div>
 <div class="sub"><?php echo $pdfOrderCount; ?> 个有效订单</div>
 </div>
 <div class="summary-card green">
 <div class="label">💵 已收款</div>
 <div class="value money-pos">¥<?php echo number_format($pdfReceived, 2); ?></div>
 <div class="sub">占比 <?php echo $pdfRevenue > 0 ? round($pdfReceived / $pdfRevenue * 100, 1) : 0; ?>%</div>
 </div>
 <div class="summary-card orange">
 <div class="label">⏳ 未收款</div>
 <div class="value" style="color:#d97706;">¥<?php echo number_format($pdfPending, 2); ?></div>
 <div class="sub">需跟进催款</div>
 </div>
 <div class="summary-card red">
 <div class="label">💸 支出合计</div>
 <div class="value money-neg">-¥<?php echo number_format($pdfExpenseTotal, 2); ?></div>
 <div class="sub"><?php echo count($pdfExpenses); ?> 笔支出</div>
 </div>
 <div class="summary-card purple">
 <div class="label">🎯 净利润</div>
 <div class="value" style="color:<?php echo $pdfProfit >= 0 ? '#16a34a' : '#dc2626'; ?>;">¥<?php echo number_format($pdfProfit, 2); ?></div>
 <div class="sub">收入 - 支出</div>
 </div>
 </div>

 <?php if ($pdfType === 'summary'): ?>
 <!-- 汇总模式:收款明细 + 支出明细 -->

 <!-- 收款明细 -->
 <div class="section">
 <div class="section-title">💰 收款明细(<?php echo count($pdfPayments); ?> 笔)</div>
 <?php if (empty($pdfPayments)): ?>
 <p style="color:#9ca3af;font-size:13px;text-align:center;padding:20px;">暂无收款记录</p>
 <?php else: ?>
 <table>
 <thead><tr><th>日期</th><th>订单号</th><th>收款方式</th><th>收款金额</th><th>备注</th></tr></thead>
 <tbody>
 <?php foreach ($pdfPayments as $p): ?>
 <tr>
 <td><?php echo substr($p['created_at'], 0, 16); ?></td>
 <td><?php echo h($p['order_no']); ?></td>
 <td><span class="badge badge-green"><?php echo h($p['payment_method']); ?></span></td>
 <td class="money money-pos">+¥<?php echo number_format($p['amount'], 2); ?></td>
 <td><?php echo h($p['remark']) ?: '-'; ?></td>
 </tr>
 <?php endforeach; ?>
 </tbody>
 <tfoot><tr><td colspan="3">合计</td><td class="money money-pos">+¥<?php echo number_format($pdfReceived, 2); ?></td><td></td></tr></tfoot>
 </table>
 <?php endif; ?>
 </div>

 <!-- 支出明细 -->
 <div class="section">
 <div class="section-title">💸 支出明细(<?php echo count($pdfExpenses); ?> 笔)</div>
 <?php if (empty($pdfExpenses)): ?>
 <p style="color:#9ca3af;font-size:13px;text-align:center;padding:20px;">暂无支出记录</p>
 <?php else: ?>
 <table>
 <thead><tr><th>日期</th><th>类别</th><th>金额</th><th>说明</th><th>登记人</th></tr></thead>
 <tbody>
 <?php foreach ($pdfExpenses as $exp): ?>
 <tr>
 <td><?php echo $exp['expense_date']; ?></td>
 <td><span class="badge badge-gray"><?php echo h($exp['category']); ?></span></td>
 <td class="money money-neg">-¥<?php echo number_format($exp['amount'], 2); ?></td>
 <td><?php echo h($exp['description']) ?: '-'; ?></td>
 <td><?php echo h($exp['realname'] ?: '系统'); ?></td>
 </tr>
 <?php endforeach; ?>
 </tbody>
 <tfoot><tr><td colspan="2">合计</td><td class="money money-neg">-¥<?php echo number_format($pdfExpenseTotal, 2); ?></td><td colspan="2"></td></tr></tfoot>
 </table>
 <?php endif; ?>
 </div>

 <?php else: ?>
 <!-- 明细模式:订单列表 + 收款 + 支出 -->

 <!-- 订单列表 -->
 <div class="section">
 <div class="section-title">📋 订单列表(<?php echo $pdfOrderCount; ?> 笔)</div>
 <?php if (empty($pdfOrders)): ?>
 <p style="color:#9ca3af;font-size:13px;text-align:center;padding:20px;">暂无订单</p>
 <?php else: ?>
 <table>
 <thead><tr><th>订单日期</th><th>订单号</th><th>客户</th><th>订单金额</th><th>已付</th><th>未付</th><th>状态</th></tr></thead>
 <tbody>
 <?php foreach ($pdfOrders as $o): ?>
 <?php $oUnpaid = max(0, floatval($o['total_amount']) - floatval($o['paid_amount'] ?? 0)); ?>
 <tr>
 <td><?php echo $o['order_date']; ?></td>
 <td><?php echo h($o['order_no']); ?></td>
 <td><?php echo h($o['customer_name'] ?: '散客'); ?></td>
 <td class="money">¥<?php echo number_format(floatval($o['total_amount']), 2); ?></td>
 <td class="money money-pos">¥<?php echo number_format(floatval($o['paid_amount'] ?? 0), 2); ?></td>
 <td class="money money-neg">¥<?php echo number_format($oUnpaid, 2); ?></td>
 <td>
 <?php if ($o['payment_status'] == 2): ?><span class="badge badge-green">已付清</span>
 <?php elseif ($o['payment_status'] == 1): ?><span class="badge badge-yellow">部分</span>
 <?php else: ?><span class="badge badge-red">未付</span><?php endif; ?>
 </td>
 </tr>
 <?php endforeach; ?>
 </tbody>
 <tfoot><tr><td colspan="3">合计(<?php echo $pdfOrderCount; ?> 笔)</td><td class="money">¥<?php echo number_format($pdfRevenue, 2); ?></td><td class="money money-pos">¥<?php echo number_format($pdfReceived, 2); ?></td><td class="money money-neg">¥<?php echo number_format(max(0, $pdfPending), 2); ?></td><td></td></tr></tfoot>
 </table>
 <?php endif; ?>
 </div>

 <!-- 收款明细 -->
 <div class="section">
 <div class="section-title">💰 收款明细(<?php echo count($pdfPayments); ?> 笔)</div>
 <?php if (empty($pdfPayments)): ?>
 <p style="color:#9ca3af;font-size:13px;text-align:center;padding:20px;">暂无收款记录</p>
 <?php else: ?>
 <table>
 <thead><tr><th>日期</th><th>订单号</th><th>收款方式</th><th>收款金额</th><th>备注</th></tr></thead>
 <tbody>
 <?php foreach ($pdfPayments as $p): ?>
 <tr>
 <td><?php echo substr($p['created_at'], 0, 16); ?></td>
 <td><?php echo h($p['order_no']); ?></td>
 <td><span class="badge badge-green"><?php echo h($p['payment_method']); ?></span></td>
 <td class="money money-pos">+¥<?php echo number_format($p['amount'], 2); ?></td>
 <td><?php echo h($p['remark']) ?: '-'; ?></td>
 </tr>
 <?php endforeach; ?>
 </tbody>
 <tfoot><tr><td colspan="3">合计</td><td class="money money-pos">+¥<?php echo number_format($pdfReceived, 2); ?></td><td></td></tr></tfoot>
 </table>
 <?php endif; ?>
 </div>

 <!-- 支出明细 -->
 <div class="section">
 <div class="section-title">💸 支出明细(<?php echo count($pdfExpenses); ?> 笔)</div>
 <?php if (empty($pdfExpenses)): ?>
 <p style="color:#9ca3af;font-size:13px;text-align:center;padding:20px;">暂无支出记录</p>
 <?php else: ?>
 <table>
 <thead><tr><th>日期</th><th>类别</th><th>金额</th><th>关联订单</th><th>说明</th><th>凭证</th><th>登记人</th></tr></thead>
 <tbody>
 <?php
 foreach ($pdfExpenses as $exp):
 $expOrderNo = '';
 if (!empty($exp['order_id'])) {
 $eoStmt = $db->prepare("SELECT order_no FROM orders WHERE id = ?");
 $eoStmt->execute([$exp['order_id']]);
 $eoRow = $eoStmt->fetch(PDO::FETCH_ASSOC);
 if ($eoRow) $expOrderNo = $eoRow['order_no'];
 }
 ?>
 <tr>
 <td><?php echo $exp['expense_date']; ?></td>
 <td><span class="badge badge-gray"><?php echo h($exp['category']); ?></span></td>
 <td class="money money-neg">-¥<?php echo number_format($exp['amount'], 2); ?></td>
 <td><?php echo h($expOrderNo) ?: '-'; ?></td>
 <td><?php echo h($exp['description']) ?: '-'; ?></td>
 <td><?php echo !empty($exp['attachment']) ? (strtolower(pathinfo($exp['attachment'], PATHINFO_EXTENSION)) === 'pdf' ? 'PDF' : '图片') : '-'; ?></td>
 <td><?php echo h($exp['realname'] ?: '系统'); ?></td>
 </tr>
 <?php endforeach; ?>
 </tbody>
 <tfoot><tr><td colspan="2">合计</td><td class="money money-neg">-¥<?php echo number_format($pdfExpenseTotal, 2); ?></td><td colspan="4"></td></tr></tfoot>
 </table>
 <?php endif; ?>
 </div>
 <?php endif; ?>

 <div class="footer">
 <?php echo h($SITE_TITLE ?? '金狐广告订单系统'); ?> &nbsp;|&nbsp; 财务报表 · <?php echo $pdfLabel; ?> &nbsp;|&nbsp; <?php echo date('Y-m-d H:i'); ?>
 </div>

 <script>window.onload = function() { window.print(); }</script>
 </body>
 </html>
 <?php
 exit;
}
// ============ 数据统计 ============

// 收入统计(已完成订单)——同取优惠与已收，供未收款/收款率折算
$stmt = $db->prepare("SELECT COALESCE(SUM(total_amount), 0), COALESCE(SUM(COALESCE(discount_amount, 0)), 0), COALESCE(SUM(COALESCE(paid_amount, 0)), 0) FROM orders WHERE status IN (1,2,3) AND created_at >= ? AND created_at <= ?");
$stmt->execute([$dateStart, $dateEnd . ' 23:59:59']);
list($monthRevenue, $monthDiscount, $monthPaidOnOrders) = $stmt->fetch(PDO::FETCH_NUM);

// 已收款
$stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments p JOIN orders o ON p.order_id = o.id WHERE o.created_at >= ? AND o.created_at <= ?");
$stmt->execute([$dateStart, $dateEnd . ' 23:59:59']);
$monthReceived = $stmt->fetchColumn();

// 未收款(折后应收 - 已收；【2026-09-13 修复】优惠金额不计入未收，与数据统计/客户对账/欠款追踪口径一致)
$monthReceivable = $monthRevenue - $monthDiscount;
$monthPending = max(0, $monthReceivable - $monthPaidOnOrders);
$receiveRate = $monthReceivable > 0 ? round(min(100, $monthPaidOnOrders / $monthReceivable * 100), 1) : 0;

// 逾期未收款(订单超过 60 天仍未付清；【2026-09-13 修复】同步扣除优惠金额)
$overdueDate = date('Y-m-d', strtotime('-60 days'));
$stmt = $db->prepare("SELECT COALESCE(SUM(GREATEST(0, total_amount - COALESCE(discount_amount,0) - COALESCE(paid_amount,0))), 0) FROM orders WHERE payment_status IN (0,1) AND status IN (1,2,3) AND order_date <= ?");
$stmt->execute([$overdueDate]);
$overdueAmount = $stmt->fetchColumn();

// 支出
$stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date >= ? AND expense_date <= ?");
$stmt->execute([$dateStart, $dateEnd]);
$monthExpense = $stmt->fetchColumn();

// 利润
$monthProfit = $monthRevenue - $monthExpense;

// 年度累计
$yearStart = sprintf('%04d-01-01', $year);
$yearEnd = sprintf('%04d-12-31', $year);

$stmt = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status IN (1,2,3) AND created_at >= ? AND created_at <= ?");
$stmt->execute([$yearStart, $yearEnd . ' 23:59:59']);
$yearRevenue = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date >= ? AND expense_date <= ?");
$stmt->execute([$yearStart, $yearEnd]);
$yearExpense = $stmt->fetchColumn();

// 年度累计已收款
$stmt = $db->prepare("SELECT COALESCE(SUM(paid_amount), 0) FROM orders WHERE status IN (1,2,3) AND created_at >= ? AND created_at <= ?");
$stmt->execute([$yearStart, $yearEnd . ' 23:59:59']);
$yearReceived = $stmt->fetchColumn();
$yearPending = $yearRevenue - $yearReceived;
$yearReceiveRate = $yearRevenue > 0 ? round($yearReceived / $yearRevenue * 100, 1) : 0;
$yearProfit = $yearRevenue - $yearExpense;

// 按月统计(年度趋势,含已收款)
$monthlyStats = [];
for ($m = 1; $m <= 12; $m++) {
 $mStart = sprintf('%04d-%02d-01', $year, $m);
 $mEnd = date('Y-m-t', strtotime($mStart));

 if ($mEnd > date('Y-m-d')) break;

 $stmt = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status IN (1,2,3) AND created_at >= ? AND created_at <= ?");
 $stmt->execute([$mStart, $mEnd . ' 23:59:59']);
 $rev = $stmt->fetchColumn();

 // 已收款(只看有效订单的 paid_amount 变化)
 $stmt = $db->prepare("SELECT COALESCE(SUM(paid_amount), 0) FROM orders WHERE status IN (1,2,3) AND created_at >= ? AND created_at <= ?");
 $stmt->execute([$mStart, $mEnd . ' 23:59:59']);
 $received = $stmt->fetchColumn();

 $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date >= ? AND expense_date <= ?");
 $stmt->execute([$mStart, $mEnd]);
 $exp = $stmt->fetchColumn();

 $monthlyStats[$m] = ['revenue' => $rev, 'received' => $received, 'expense' => $exp, 'profit' => $rev - $exp];
}

// 去年同比(仅统计已有数据的月份)
$lastYearStats = [];
$lastYear = $year - 1;
for ($m = 1; $m <= 12; $m++) {
 $mStart = sprintf('%04d-%02d-01', $lastYear, $m);
 $mEnd = date('Y-m-t', strtotime($mStart));
 if ($mEnd > date('Y-m-d')) break; // 去年超过今天的月份不统计

 $stmt = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status IN (1,2,3) AND created_at >= ? AND created_at <= ?");
 $stmt->execute([$mStart, $mEnd . ' 23:59:59']);
 $rev = $stmt->fetchColumn();

 $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date >= ? AND expense_date <= ?");
 $stmt->execute([$mStart, $mEnd]);
 $exp = $stmt->fetchColumn();

 $lastYearStats[$m] = ['revenue' => $rev, 'expense' => $exp, 'profit' => $rev - $exp];
}

// 汇总今年 vs 去年
$totalLastRevenue = array_sum(array_column($lastYearStats, 'revenue'));
$totalLastExpense = array_sum(array_column($lastYearStats, 'expense'));
$totalLastProfit = array_sum(array_column($lastYearStats, 'profit'));
$revChange = $totalLastRevenue > 0 ? round(($yearRevenue - $totalLastRevenue) / $totalLastRevenue * 100, 1) : null;
$expChange = $totalLastExpense > 0 ? round(($yearExpense - $totalLastExpense) / $totalLastExpense * 100, 1) : null;

// 支出分类统计
$expenseByCategory = $db->prepare("SELECT category, COALESCE(SUM(amount), 0) as total FROM expenses WHERE expense_date >= ? AND expense_date <= ? GROUP BY category ORDER BY total DESC");
$expenseByCategory->execute([$dateStart, $dateEnd]);
$expenseCategories = $expenseByCategory->fetchAll(PDO::FETCH_ASSOC);

// 支出记录列表
$expenses = $db->prepare("SELECT e.*, u.realname, o.order_no AS order_no_linked FROM expenses e LEFT JOIN users u ON e.created_by = u.id LEFT JOIN orders o ON e.order_id = o.id WHERE e.expense_date >= ? AND e.expense_date <= ? ORDER BY e.expense_date DESC, e.id DESC");
$expenses->execute([$dateStart, $dateEnd]);
$expenseList = $expenses->fetchAll(PDO::FETCH_ASSOC);

// 收款记录列表
$paymentList = [];
$paymentStmt = $db->prepare("SELECT p.*, o.order_no, o.order_date, o.customer_id, c.name as customer_name, u.realname as receiver_name
 FROM payments p
 JOIN orders o ON p.order_id = o.id
 LEFT JOIN customers c ON o.customer_id = c.id
 LEFT JOIN users u ON p.created_by = u.id
 WHERE DATE(p.created_at) >= ? AND DATE(p.created_at) <= ?
 ORDER BY p.created_at DESC");
$paymentStmt->execute([$dateStart, $dateEnd]);
$paymentList = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);

// 获取费用类别设置
$expenseCategoriesSetting = getSetting('expense_categories');
$expenseCategoryOptions = $expenseCategoriesSetting ? explode('|', $expenseCategoriesSetting) : ['房租', '水电', '物业', '工资', '采购', '运输', '广告', '维修', '耗材', '其他'];

$csrfToken = $_SESSION['csrf_token'] ?? '';
// 确保 CSRF token 已生成(首次访问时)
if (empty($csrfToken)) {
 $csrfToken = bin2hex(random_bytes(32));
 $_SESSION['csrf_token'] = $csrfToken;
}

// ============ 导出 Excel (所有数据加载完成后)============
if (isset($exportAfterLoad) && $exportAfterLoad) {
 while (ob_get_level() > 0) { ob_end_clean(); } // 清除所有 HTML 缓存
 try {

 // 安全验证:仅管理员可导出
 if (!hasPermission(PERM_FINANCE_EDIT)) {
 die('无权限执行此操作');
 }

 $exportType = $_GET['type'] ?? 'detail';

 // 导出数据范围:根据 URL 参数决定
 if (isset($_GET['start']) && isset($_GET['end'])) {
 $exportStart = $_GET['start'];
 $exportEnd = $_GET['end'];
 $filename = $exportType === 'summary'
 ? sprintf('财务汇总_%s至%s.xlsx', $exportStart, $exportEnd)
 : sprintf('支出明细_%s至%s.xlsx', $exportStart, $exportEnd);
 $exportTitle = sprintf('%s 至 %s', $exportStart, $exportEnd);
 } elseif (isset($_GET['range']) && $_GET['range'] === 'all') {
 $exportStart = sprintf('%04d-01-01', $year);
 $exportEnd = sprintf('%04d-12-31', $year);
 $filename = $exportType === 'summary'
 ? sprintf('财务汇总_%d年全年.xlsx', $year)
 : sprintf('支出明细_%d年全年.xlsx', $year);
 $exportTitle = sprintf('%d年全年', $year);
 } else {
 $exportStart = $dateStart;
 $exportEnd = $dateEnd;
 $filename = $exportType === 'summary'
 ? sprintf('财务汇总_%04d年%02d月.xlsx', $year, $month)
 : sprintf('支出明细_%04d年%02d月.xlsx', $year, $month);
 $exportTitle = sprintf('%04d年%02d月', $year, $month);
 }

 // 重新查询导出范围的数据
 $exportExpenseList = $db->prepare("SELECT e.*, u.realname, o.order_no AS order_no_linked FROM expenses e LEFT JOIN users u ON e.created_by = u.id LEFT JOIN orders o ON e.order_id = o.id WHERE e.expense_date >= ? AND e.expense_date <= ? ORDER BY e.expense_date DESC, e.id DESC");
 $exportExpenseList->execute([$exportStart, $exportEnd]);
 $exportExpenseList = $exportExpenseList->fetchAll(PDO::FETCH_ASSOC);

 $exportPaymentList = $db->prepare("SELECT p.*, o.order_no, o.order_date, c.name as customer_name, u.realname as receiver_name
 FROM payments p
 JOIN orders o ON p.order_id = o.id
 LEFT JOIN customers c ON o.customer_id = c.id
 LEFT JOIN users u ON p.created_by = u.id
 WHERE DATE(p.created_at) >= ? AND DATE(p.created_at) <= ?
 ORDER BY p.created_at DESC");
 $exportPaymentList->execute([$exportStart, $exportEnd]);
 $exportPaymentList = $exportPaymentList->fetchAll(PDO::FETCH_ASSOC);

 $exportRevenue = $db->prepare("SELECT COALESCE(SUM(total_amount), 0), COALESCE(SUM(COALESCE(discount_amount, 0)), 0), COALESCE(SUM(COALESCE(paid_amount, 0)), 0) FROM orders WHERE status IN (1,2,3) AND DATE(created_at) >= ? AND DATE(created_at) <= ?");
 $exportRevenue->execute([$exportStart, $exportEnd]);
 list($exportRevenue, $exportDiscount, $exportPaidOnOrders) = $exportRevenue->fetch(PDO::FETCH_NUM);

 $exportReceived = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments p JOIN orders o ON p.order_id = o.id WHERE DATE(p.created_at) >= ? AND DATE(p.created_at) <= ?");
 $exportReceived->execute([$exportStart, $exportEnd]);
 $exportReceived = $exportReceived->fetchColumn();

 // 【2026-09-13 修复】导出未收款 = 折后应收 - 已收，优惠金额不计入，与页面口径一致
 $exportPending = max(0, ($exportRevenue - $exportDiscount) - $exportPaidOnOrders);
 $exportProfit = $exportRevenue - array_sum(array_column($exportExpenseList, 'amount'));

 // 分类统计
 $exportExpenseCategories = $db->prepare("SELECT category, SUM(amount) as total FROM expenses WHERE expense_date >= ? AND expense_date <= ? GROUP BY category ORDER BY total DESC");
 $exportExpenseCategories->execute([$exportStart, $exportEnd]);
 $exportExpenseCategories = $exportExpenseCategories->fetchAll(PDO::FETCH_ASSOC);

 // 使用 PhpSpreadsheet 生成真正的 Excel 文件
 if (file_exists(__DIR__ . '/vendor/autoload.php')) {
 $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
 $sheet = $spreadsheet->getActiveSheet();

 $sheet->setTitle($exportType === 'summary' ? '财务汇总' : '支出明细');

 $sheet->getParent()->getDefaultStyle()->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
 $sheet->getParent()->getDefaultStyle()->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

 if ($exportType === 'summary') {
 $sheet->mergeCells('A1:B1');
 $sheet->setCellValue('A1', '长沙金狐文化开单系统 - 财务汇总 (' . $exportTitle . ')');
 $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
 $sheet->getStyle('A1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('3B82F6');
 $sheet->getStyle('A1')->getFont()->getColor()->setRGB('FFFFFF');
 $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
 $sheet->getStyle('A1')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

 $row = 2;
 $totalExpense = array_sum(array_column($exportExpenseList, 'amount'));
 $receiveRate = ($exportRevenue - $exportDiscount) > 0 ? round($exportReceived / ($exportRevenue - $exportDiscount) * 100) : 0;

 $sheet->setCellValue('A' . $row, '本月收入');
 $sheet->setCellValue('B' . $row, $exportRevenue);
 $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('¥#,##0.00');
 $sheet->getStyle('B' . $row)->getFont()->getColor()->setRGB('10B981');
 $row++;

 $sheet->setCellValue('A' . $row, '本月已收款');
 $sheet->setCellValue('B' . $row, $exportReceived);
 $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('¥#,##0.00');
 $sheet->setCellValue('C' . $row, '(' . $receiveRate . '%)');
 $sheet->getStyle('C' . $row)->getFont()->getColor()->setRGB('6B7280');
 $row++;

 $sheet->setCellValue('A' . $row, '本月未收款');
 $sheet->setCellValue('B' . $row, $exportPending);
 $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('¥#,##0.00');
 $sheet->getStyle('A' . $row . ':B' . $row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('FEF3C7');
 $sheet->getStyle('B' . $row)->getFont()->getColor()->setRGB('F59E0B');
 $row++;

 $sheet->setCellValue('A' . $row, '本月支出');
 $sheet->setCellValue('B' . $row, $totalExpense);
 $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('¥#,##0.00');
 $sheet->getStyle('B' . $row)->getFont()->getColor()->setRGB('EF4444');
 $row++;

 $sheet->setCellValue('A' . $row, '本月利润');
 $sheet->setCellValue('B' . $row, $exportProfit);
 $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('¥#,##0.00');
 if ($exportProfit >= 0) {
 $sheet->getStyle('B' . $row)->getFont()->getColor()->setRGB('10B981');
 } else {
 $sheet->getStyle('B' . $row)->getFont()->getColor()->setRGB('EF4444');
 }
 $row++;
 $row++;

 $sheet->setCellValue('A' . $row, '分类明细');
 $sheet->mergeCells('A' . $row . ':C' . $row);
 $sheet->getStyle('A' . $row)->getFont()->setBold(true);
 $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
 $row++;

 $sheet->setCellValue('A' . $row, '类别');
 $sheet->setCellValue('B' . $row, '金额');
 $sheet->getStyle('A' . $row . ':B' . $row)->getFont()->setBold(true);
 $sheet->getStyle('A' . $row . ':B' . $row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('F3F4F6');
 $row++;

 foreach ($exportExpenseCategories as $cat) {
 $sheet->setCellValue('A' . $row, $cat['category']);
 $sheet->setCellValue('B' . $row, $cat['total']);
 $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('¥#,##0.00');
 $row++;
 }

 $row++;
 $row++;

 $sheet->setCellValue('A' . $row, '收款明细');
 $sheet->mergeCells('A' . $row . ':E' . $row);
 $sheet->getStyle('A' . $row)->getFont()->setBold(true);
 $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
 $row++;

 $sheet->setCellValue('A' . $row, '收款日期');
 $sheet->setCellValue('B' . $row, '订单号');
 $sheet->setCellValue('C' . $row, '客户');
 $sheet->setCellValue('D' . $row, '收款金额');
 $sheet->setCellValue('E' . $row, '收款方式');
 $sheet->setCellValue('F' . $row, '备注');
 $sheet->getStyle('A' . $row . ':F' . $row)->getFont()->setBold(true);
 $sheet->getStyle('A' . $row . ':F' . $row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('D1FAE5');
 $row++;

 foreach ($exportPaymentList as $pay) {
 $sheet->setCellValue('A' . $row, substr($pay['created_at'], 0, 16));
 $sheet->setCellValue('B' . $row, $pay['order_no']);
 $sheet->setCellValue('C' . $row, $pay['customer_name'] ?: '散客');
 $sheet->setCellValue('D' . $row, $pay['amount']);
 $sheet->getStyle('D' . $row)->getNumberFormat()->setFormatCode('¥#,##0.00');
 $sheet->getStyle('D' . $row)->getFont()->getColor()->setRGB('059669');
 $sheet->setCellValue('E' . $row, $pay['payment_method']);
 $sheet->setCellValue('F' . $row, $pay['remark'] ?: '-');
 $row++;
 }

 if (!empty($exportPaymentList)) {
 $sheet->setCellValue('A' . $row, '合计收款');
 $sheet->mergeCells('A' . $row . ':C' . $row);
 $sheet->setCellValue('D' . $row, array_sum(array_column($exportPaymentList, 'amount')));
 $sheet->getStyle('D' . $row)->getNumberFormat()->setFormatCode('¥#,##0.00');
 $sheet->getStyle('A' . $row . ':F' . $row)->getFont()->setBold(true);
 $sheet->getStyle('A' . $row . ':F' . $row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('D1FAE5');
 $row++;
 }

 $sheet->getColumnDimension('A')->setWidth(15);
 $sheet->getColumnDimension('B')->setWidth(15);
 $sheet->getColumnDimension('C')->setWidth(10);
 $sheet->getColumnDimension('D')->setWidth(12);
 $sheet->getColumnDimension('E')->setWidth(12);
 $sheet->getColumnDimension('F')->setWidth(20);
 } else {
 $sheet->mergeCells('A1:G1');
 $sheet->setCellValue('A1', '长沙金狐文化开单系统 - 支出明细 (' . $exportTitle . ')');
 $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
 $sheet->getStyle('A1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('3B82F6');
 $sheet->getStyle('A1')->getFont()->getColor()->setRGB('FFFFFF');
 $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
 $sheet->getStyle('A1')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

 $row = 2;
 $headers = ['日期', '类别', '金额', '关联订单', '说明', '凭证', '登记人'];
 $col = 'A';
 foreach ($headers as $header) {
 $sheet->setCellValue($col . $row, $header);
 $sheet->getStyle($col . $row)->getFont()->setBold(true);
 $sheet->getStyle($col . $row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('F3F4F6');
 $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
 $sheet->getStyle($col . $row)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
 $col++;
 }

 $baseDir = dirname(__FILE__);
 foreach ($exportExpenseList as $exp) {
 $row++;
 $sheet->setCellValue('A' . $row, $exp['expense_date']);
 $sheet->setCellValue('B' . $row, $exp['category']);
 $sheet->setCellValue('C' . $row, $exp['amount']);
 $sheet->getStyle('C' . $row)->getNumberFormat()->setFormatCode('¥#,##0.00');
 $sheet->setCellValue('D' . $row, $exp['order_no_linked'] ?: '-');
 $sheet->setCellValue('E' . $row, $exp['description'] ?: '-');
 $sheet->setCellValue('G' . $row, $exp['realname'] ?: '系统');

 if (!empty($exp['attachment'])) {
 $filePath = $baseDir . '/' . $exp['attachment'];
 if (file_exists($filePath)) {
 $attExt = strtolower(pathinfo($exp['attachment'], PATHINFO_EXTENSION));
 $imgExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
 if (in_array($attExt, $imgExts)) {
 try {
 $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
 $drawing->setName('凭证');
 $drawing->setDescription('支出凭证');
 $drawing->setPath($filePath);
 $drawing->setHeight(60);
 $drawing->setCoordinates('F' . $row);
 $drawing->setWorksheet($sheet);
 $sheet->getRowDimension($row)->setRowHeight(50);
 } catch (Exception $e) {
 $sheet->setCellValue('F' . $row, '图片加载失败');
 }
 } else {
 $sheet->setCellValue('F' . $row, $attExt === 'pdf' ? 'PDF' : '文件');
 }
 } else {
 $sheet->setCellValue('F' . $row, '文件不存在');
 }
 } else {
 $sheet->setCellValue('F' . $row, '-');
 }
 }

 $row++;
 $sheet->setCellValue('A' . $row, '合计');
 $sheet->mergeCells('A' . $row . ':B' . $row);
 $sheet->setCellValue('C' . $row, array_sum(array_column($exportExpenseList, 'amount')));
 $sheet->getStyle('C' . $row)->getNumberFormat()->setFormatCode('¥#,##0.00');
 $sheet->setCellValue('D' . $row, count($exportExpenseList) . ' 笔记录');
 $sheet->mergeCells('D' . $row . ':G' . $row);
 $sheet->getStyle('A' . $row . ':G' . $row)->getFont()->setBold(true);
 $sheet->getStyle('A' . $row . ':G' . $row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('FEF3C7');
 $sheet->getStyle('A' . $row . ':G' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
 $sheet->getStyle('A' . $row . ':G' . $row)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

 $sheet->getColumnDimension('A')->setWidth(12);
 $sheet->getColumnDimension('B')->setWidth(12);
 $sheet->getColumnDimension('C')->setWidth(12);
 $sheet->getColumnDimension('D')->setWidth(15);
 $sheet->getColumnDimension('E')->setWidth(25);
 $sheet->getColumnDimension('F')->setWidth(15);
 $sheet->getColumnDimension('G')->setWidth(10);

 $spreadsheet->setActiveSheetIndex(0);

 $paymentSheet = $spreadsheet->createSheet();
 $paymentSheet->setTitle('收款明细');

 $paymentSheet->mergeCells('A1:F1');
 $paymentSheet->setCellValue('A1', '长沙金狐文化开单系统 - 收款明细 (' . $exportTitle . ')');
 $paymentSheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
 $paymentSheet->getStyle('A1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('10B981');
 $paymentSheet->getStyle('A1')->getFont()->getColor()->setRGB('FFFFFF');
 $paymentSheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
 $paymentSheet->getStyle('A1')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

 $pRow = 2;
 $pHeaders = ['收款日期', '订单号', '客户', '收款金额', '优惠', '收款方式', '备注', '凭证', '登记人'];
 $pCol = 'A';
 foreach ($pHeaders as $header) {
 $paymentSheet->setCellValue($pCol . $pRow, $header);
 $paymentSheet->getStyle($pCol . $pRow)->getFont()->setBold(true);
 $paymentSheet->getStyle($pCol . $pRow)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('D1FAE5');
 $paymentSheet->getStyle($pCol . $pRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
 $paymentSheet->getStyle($pCol . $pRow)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
 $pCol++;
 }

 foreach ($exportPaymentList as $pay) {
 $pRow++;
 $paymentSheet->setCellValue('A' . $pRow, substr($pay['created_at'], 0, 16));
 $paymentSheet->setCellValue('B' . $pRow, $pay['order_no']);
 $paymentSheet->setCellValue('C' . $pRow, $pay['customer_name'] ?: '散客');
 $paymentSheet->setCellValue('D' . $pRow, $pay['amount']);
 $paymentSheet->getStyle('D' . $pRow)->getNumberFormat()->setFormatCode('¥#,##0.00');
 $paymentSheet->getStyle('D' . $pRow)->getFont()->getColor()->setRGB('059669');
 $paymentSheet->setCellValue('E' . $pRow, floatval($pay['discount'] ?? 0) > 0 ? floatval($pay['discount']) : '-');
 $paymentSheet->setCellValue('F' . $pRow, $pay['payment_method']);
 $paymentSheet->setCellValue('G' . $pRow, $pay['remark'] ?: '-');
 $paymentSheet->setCellValue('I' . $pRow, $pay['receiver_name'] ?: '系统');

 if (!empty($pay['attachment'])) {
 $payFilePath = $baseDir . '/' . $pay['attachment'];
 if (file_exists($payFilePath)) {
 $payAttExt = strtolower(pathinfo($pay['attachment'], PATHINFO_EXTENSION));
 $payImgExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
 if (in_array($payAttExt, $payImgExts)) {
 try {
 $payDrawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
 $payDrawing->setName('收款凭证');
 $payDrawing->setDescription('收款凭证');
 $payDrawing->setPath($payFilePath);
 $payDrawing->setHeight(60);
 $payDrawing->setCoordinates('H' . $pRow);
 $payDrawing->setWorksheet($paymentSheet);
 $paymentSheet->getRowDimension($pRow)->setRowHeight(50);
 } catch (Exception $e) {
 $paymentSheet->setCellValue('H' . $pRow, '图片加载失败');
 }
 } else {
 $paymentSheet->setCellValue('H' . $pRow, $payAttExt === 'pdf' ? 'PDF' : '文件');
 }
 } else {
 $paymentSheet->setCellValue('H' . $pRow, '文件不存在');
 }
 } else {
 $paymentSheet->setCellValue('H' . $pRow, '-');
 }
 }

 if (!empty($exportPaymentList)) {
 $pRow++;
 $paymentSheet->setCellValue('A' . $pRow, '合计收款');
 $paymentSheet->mergeCells('A' . $pRow . ':C' . $pRow);
 $paymentSheet->setCellValue('D' . $pRow, array_sum(array_column($exportPaymentList, 'amount')));
 $paymentSheet->getStyle('D' . $pRow)->getNumberFormat()->setFormatCode('¥#,##0.00');
 $paymentSheet->setCellValue('E' . $pRow, count($exportPaymentList) . ' 笔记录');
 $paymentSheet->mergeCells('E' . $pRow . ':H' . $pRow);
 $paymentSheet->getStyle('A' . $pRow . ':H' . $pRow)->getFont()->setBold(true);
 $paymentSheet->getStyle('A' . $pRow . ':H' . $pRow)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('D1FAE5');
 $paymentSheet->getStyle('A' . $pRow . ':H' . $pRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
 }

 $paymentSheet->getColumnDimension('A')->setWidth(16);
 $paymentSheet->getColumnDimension('B')->setWidth(18);
 $paymentSheet->getColumnDimension('C')->setWidth(15);
 $paymentSheet->getColumnDimension('D')->setWidth(12);
 $paymentSheet->getColumnDimension('E')->setWidth(12);
 $paymentSheet->getColumnDimension('F')->setWidth(20);
 $paymentSheet->getColumnDimension('G')->setWidth(15);
 $paymentSheet->getColumnDimension('H')->setWidth(10);

 $paymentSheet->getParent()->getDefaultStyle()->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
 $paymentSheet->getParent()->getDefaultStyle()->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
 }

 header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
 header('Content-Disposition: attachment; filename="' . $filename . '"');
 header('Cache-Control: no-cache');

 $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
 $writer->save('php://output');
 exit;
 } else {
 header('Content-Type: application/vnd.ms-excel; charset=utf-8');
 header('Content-Disposition: attachment; filename="' . str_replace('.xlsx', '.xls', $filename) . '"');
 header('Cache-Control: no-cache');
 echo "\xEF\xBB\xBF";
 echo '<html><head><meta charset="utf-8"></head><body>';
 echo '<p>PhpSpreadsheet 未安装,请使用导出按钮旁的「兼容模式」</p>';
 echo '</body></html>';
 exit;
 }
} catch (\Throwable $e) {
 error_log('Finance export error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

 echo '<html><head><meta charset="UTF-8"><title>导出失败</title></head><body style="font-family:system-ui;padding:40px;text-align:center;">';
 echo '<h2 style="color:#dc2626;">⚠️ 导出失败</h2>';
 echo '<p style="color:#6b7280;">数据导出时发生错误,请稍后重试或联系管理员。</p>';
 echo '<p style="font-size:12px;color:#9ca3af;margin-top:20px;">错误编号: ' . date('YmdHis') . '</p>';
 echo '</body></html>';
 exit;
}
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
 <meta charset="UTF-8">
 <meta name="viewport" content="width=device-width, initial-scale=1.0">
 <title>财务管理 - <?php echo SITE_TITLE; ?></title>
 <link rel="stylesheet" href="style.css">
 <style>
 .page-title-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
 .date-filter { display: flex; align-items: center; gap: 12px; }
 .date-filter select, .date-filter input { padding: 8px 12px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: 14px; }
 .btn { padding: 10px 20px; border-radius: 8px; border: none; cursor: pointer; font-size: 14px; }
 .btn-primary { background: #3B82F6; color: white; }
 .btn-outline { background: white; color: #3B82F6; border: 1px solid #3B82F6; }
 .btn-danger { background: #EF4444; color: white; padding: 6px 12px; font-size: 13px; }
 .card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: 24px; margin-bottom: 20px; }
 .card-title { font-size: 18px; font-weight: 600; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; }
 .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
 .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
 .stat-card.income { border-left: 4px solid #10B981; }
 .stat-card.expense { border-left: 4px solid #EF4444; }
 .stat-card.profit { border-left: 4px solid #3B82F6; }
 .stat-card.received { border-left: 4px solid #F59E0B; }
 .stat-card.pending { border-left: 4px solid #FBBF24; }
 .stat-label { color: #6B7280; font-size: 14px; margin-bottom: 8px; }
 .stat-value { font-size: 28px; font-weight: 700; color: #1F2937; }
 .stat-sub { color: #9CA3AF; font-size: 12px; margin-top: 4px; }
 .profit-positive { color: #10B981; }
 .profit-negative { color: #EF4444; }

 .chart-container { height: 300px; display: flex; align-items: flex-end; gap: 8px; padding: 20px 0; }
 .chart-bar { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 8px; }
 .chart-bar-inner { width: 100%; border-radius: 4px 4px 0 0; transition: height 0.3s; }
 .chart-bar-income { background: linear-gradient(to top, #10B981, #34D399); }
 .chart-bar-expense { background: linear-gradient(to top, #EF4444, #F87171); }
 .chart-bar-label { font-size: 12px; color: #6B7280; }
 .chart-value { font-size: 11px; color: #374151; font-weight: 500; }

 table { width: 100%; border-collapse: collapse; }
 th, td { padding: 14px 16px; text-align: left; border-bottom: 1px solid #E5E7EB; }
 th { background: #F9FAFB; font-weight: 600; color: #374151; }
 tr:hover { background: #F9FAFB; }
 .money { font-weight: 600; font-family: monospace; }
 .money-positive { color: #10B981; }
 .money-negative { color: #EF4444; }

 .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
 @media (max-width: 900px) { .two-col { grid-template-columns: 1fr; } }

 .pie-container { display: flex; align-items: center; gap: 40px; flex-wrap: wrap; }
 .pie-legend { flex: 1; min-width: 200px; }
 .legend-item { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
 .legend-color { width: 16px; height: 16px; border-radius: 4px; }

 .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 1000; }
 .modal-overlay.show { display: flex !important; }
 .modal-content {
    background: white;
    border-radius: 16px;
    padding: 0;
    width: 90%;
    max-width: 480px;
    max-height: 90vh;
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.modal-content > form,
.modal-content > div:not(.modal-actions) {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: 32px;
}
.modal-content > form > .modal-actions,
.modal-content > .modal-actions {
    flex-shrink: 0;
    padding: 16px 32px;
    border-top: 1px solid #E5E7EB;
    background: #F9FAFB;
    border-radius: 0 0 16px 16px;
}
/* 明显滚动条 (Edge/Chrome 自动隐藏滚动条问题) */
.modal-content ::-webkit-scrollbar {
    width: 10px;
}
.modal-content ::-webkit-scrollbar-track {
    background: #F3F4F6;
    border-radius: 5px;
}
.modal-content ::-webkit-scrollbar-thumb {
    background: #9CA3AF;
    border-radius: 5px;
}
.modal-content ::-webkit-scrollbar-thumb:hover {
    background: #6B7280;
}
 .modal-title { font-size: 20px; font-weight: 600; margin-bottom: 20px; flex-shrink: 0; }
 .form-group { margin-bottom: 20px; }
 .form-label { display: block; margin-bottom: 8px; font-weight: 500; color: #374151; }
 .form-control { width: 100%; padding: 12px 16px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: 14px; box-sizing: border-box; }
 .form-control:focus { outline: none; border-color: #3B82F6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
 .modal-actions { display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px; }
 .empty-state { text-align: center; padding: 40px; color: #9CA3AF; }

 .paste-upload-area {
 position: relative;
 border: 2px dashed #CBD5E1;
 border-radius: 10px;
 padding: 14px 12px;
 background: #F8FAFC;
 cursor: pointer;
 transition: all .15s ease;
 outline: none;
 }
 .paste-upload-area:hover { border-color: #3B82F6; background: #EFF6FF; }
 .paste-upload-area:focus { border-color: #3B82F6; box-shadow: 0 0 0 3px rgba(59,130,246,0.15); }
 .paste-upload-area.paste-drag { border-color: #10B981; background: #ECFDF5; }
 .paste-upload-area.paste-flash { animation: pasteFlash .6s ease; }
 @keyframes pasteFlash {
 0% { box-shadow: 0 0 0 0 rgba(16,185,129,0); }
 30% { box-shadow: 0 0 0 6px rgba(16,185,129,0.35); border-color: #10B981; }
 100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); }
 }
 .paste-file-input {
 position: absolute;
 top: 0; left: 0; right: 0; bottom: 0;
 width: 100%; height: 100%;
 opacity: 0;
 cursor: pointer;
 padding: 0 !important;
 border: none !important;
 }
 .paste-hint {
 display: flex; flex-direction: column; align-items: center; gap: 4px;
 color: #475569; font-size: 13px; pointer-events: none;
 }
 .paste-icon { font-size: 24px; line-height: 1; }
 .paste-text b { color: #3B82F6; }
 .paste-sub { font-size: 11px; color: #94A3B8; }
 .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12px; }
 </style>
</head>
<body>
 <?php echo renderNav('finance'); ?>

 <div class="page-title-bar">
 <div>
 <h1>💰 财务管理</h1>
 <p style="color: #6B7280; margin-top: 4px;">收入、支出与利润分析</p>
 </div>
 <div style="display:flex;gap:8px;flex-wrap:wrap;">
 <button class="btn btn-outline" onclick="exportExcel()">📊 导出Excel</button>
 <button class="btn btn-outline" onclick="exportPDF('summary')" title="打印友好,可在浏览器中另存为PDF">🖨 打印报表(汇总)</button>
 <button class="btn btn-outline" onclick="exportPDF('detail')" title="打印友好,可在浏览器中另存为PDF">🖨 打印报表(明细)</button>
 <button class="btn btn-primary" onclick="openAddModal()">➕ 记一笔支出</button>
 </div>
 </div>

 <!-- 月份选择器 -->
 <div class="card" style="margin-bottom: 20px;">
 <div class="date-filter">
 <select id="filterYear" onchange="changeMonth()">
 <?php for ($y = date('Y') - 2; $y <= date('Y'); $y++): ?>
 <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>><?php echo $y; ?>年</option>
 <?php endfor; ?>
 </select>
 <select id="filterMonth" onchange="changeMonth()">
 <option value="all">全年</option>
 <?php for ($m = 1; $m <= 12; $m++): ?>
 <option value="<?php echo $m; ?>" <?php echo $m == $month ? 'selected' : ''; ?>><?php echo $m; ?>月</option>
 <?php endfor; ?>
 </select>
 <span style="color: #6B7280; margin-left: 12px;">年度累计收入: <strong style="color: #10B981;">¥<?php echo number_format($yearRevenue, 2); ?></strong></span>
 <span style="color: #6B7280;">年度累计支出: <strong style="color: #EF4444;">¥<?php echo number_format($yearExpense, 2); ?></strong></span>
 <button class="btn btn-outline" style="margin-left:16px;padding:6px 12px;font-size:13px;" onclick="openCustomRange()">📅 自定义日期范围</button>
 <button class="btn btn-outline" style="padding:6px 12px;font-size:13px;" onclick="openPaymentModal()">💳 登记收款</button>
 </div>
 </div>

 <!-- 统计卡片 -->
 <div class="stats-grid">
 <div class="stat-card income">
 <div class="stat-label">📈 本月收入</div>
 <div class="stat-value">¥<?php echo number_format($monthRevenue, 2); ?></div>
 <div class="stat-sub">全年累计 ¥<?php echo number_format($yearRevenue, 2); ?></div>
 </div>
 <div class="stat-card received">
 <div class="stat-label">💵 本月收款</div>
 <div class="stat-value">¥<?php echo number_format($monthReceived, 2); ?></div>
 <div class="stat-sub">收款进度: <?php echo $receiveRate; ?>%</div>
 <div style="height:4px;background:#F3F4F6;border-radius:2px;margin-top:6px;overflow:hidden;">
 <div style="height:100%;background:linear-gradient(to right,#F59E0B,#10B981);width:<?php echo $receiveRate; ?>%;"></div>
 </div>
 <div class="stat-sub" style="margin-top:4px;">全年已收 ¥<?php echo number_format($yearReceived, 2); ?>(年<?php echo $yearReceiveRate; ?>%)</div>
 </div>
 <div class="stat-card pending">
 <div class="stat-label">⏳ 本月未收</div>
 <div class="stat-value" style="color:#F59E0B;">¥<?php echo number_format($monthPending, 2); ?></div>
 <div class="stat-sub">应收未收金额</div>
 <?php if ($overdueAmount > 0): ?>
 <div style="margin-top:6px;padding:4px 8px;background:#FEE2E2;border-radius:4px;font-size:11px;color:#991B1B;">
 ⚠️ 超 60 天未收: ¥<?php echo number_format($overdueAmount, 2); ?>
 </div>
 <?php endif; ?>
 </div>
 <div class="stat-card expense">
 <div class="stat-label">📉 本月支出</div>
 <div class="stat-value">¥<?php echo number_format($monthExpense, 2); ?></div>
 <div class="stat-sub"><?php echo count($expenseList); ?> 笔支出 | 全年 ¥<?php echo number_format($yearExpense, 2); ?></div>
 </div>
 <div class="stat-card profit">
 <div class="stat-label">🎯 本月利润</div>
 <div class="stat-value <?php echo $monthProfit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
 ¥<?php echo number_format($monthProfit, 2); ?>
 </div>
 <div class="stat-sub">收入 - 支出 | 全年 ¥<?php echo number_format($yearProfit, 2); ?></div>
 </div>
 </div>

 <div class="two-col">
 <!-- 年度趋势图 -->
 <div class="card">
 <div class="card-title">📊 <?php echo $year; ?>年月度趋势 <?php if ($revChange !== null): ?><span style="font-size:12px;font-weight:normal;color:#6B7280;">(收入同比 <?php echo $revChange >= 0 ? '+' : ''; ?><?php echo $revChange; ?>%)</span><?php endif; ?></div>
 <div class="chart-container" id="monthlyChart">
 <?php
 $maxValue = max(array_column($monthlyStats, 'revenue'));
 if ($maxValue == 0) $maxValue = 1;
 foreach ($monthlyStats as $m => $stat):
 $incomeHeight = ($stat['revenue'] / $maxValue) * 200;
 $receivedHeight = ($stat['received'] / $maxValue) * 200;
 $expenseHeight = ($stat['expense'] / $maxValue) * 200;
 ?>
 <div class="chart-bar">
 <div class="chart-value" style="font-size:10px;"><?php echo $stat['revenue'] > 0 ? round($stat['revenue']/10000, 1).'w' : ''; ?></div>
 <div style="display:flex;gap:2px;align-items:flex-end;height:200px;">
 <div class="chart-bar-inner" style="height:<?php echo max($incomeHeight, 2); ?>px;width:10px;background:#10B981;border-radius:3px 3px 0 0;" title="收入 ¥<?php echo number_format($stat['revenue']); ?>"></div>
 <div class="chart-bar-inner" style="height:<?php echo max($receivedHeight, 2); ?>px;width:10px;background:#3B82F6;border-radius:3px 3px 0 0;" title="已收 ¥<?php echo number_format($stat['received']); ?>"></div>
 <div class="chart-bar-inner" style="height:<?php echo max($expenseHeight, 2); ?>px;width:10px;background:#EF4444;border-radius:3px 3px 0 0;" title="支出 ¥<?php echo number_format($stat['expense']); ?>"></div>
 </div>
 <div class="chart-bar-label"><?php echo $m; ?>月</div>
 </div>
 <?php endforeach; ?>
 <div style="display:flex;gap:16px;justify-content:center;margin-top:12px;font-size:13px;">
 <span><span style="display:inline-block;width:14px;height:14px;background:#10B981;border-radius:2px;vertical-align:middle;"></span> 收入</span>
 <span><span style="display:inline-block;width:14px;height:14px;background:#3B82F6;border-radius:2px;vertical-align:middle;"></span> 已收款</span>
 <span><span style="display:inline-block;width:14px;height:14px;background:#EF4444;border-radius:2px;vertical-align:middle;"></span> 支出</span>
 </div>
 </div>
 <div style="display:flex;gap:20px;justify-content:center;margin-top:12px;font-size:13px;">
 <span><span style="display:inline-block;width:14px;height:14px;background:#10B981;border-radius:2px;vertical-align:middle;"></span> 收入</span>
 <span><span style="display:inline-block;width:14px;height:14px;background:#EF4444;border-radius:2px;vertical-align:middle;"></span> 支出</span>
 </div>
 </div>

 <!-- 支出分类 -->
 <div class="card">
 <div class="card-title">📁 支出分类(本月)</div>
 <?php if (empty($expenseCategories)): ?>
 <div class="empty-state">本月暂无支出记录</div>
 <?php else: ?>
 <?php
 $colors = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899', '#06B6D4', '#84CC16'];
 $totalExpense = array_sum(array_column($expenseCategories, 'total'));
 $idx = 0;
 foreach ($expenseCategories as $cat):
 $pct = $totalExpense > 0 ? round($cat['total'] / $totalExpense * 100, 1) : 0;
 $color = $colors[$idx % count($colors)];
 ?>
 <div class="legend-item">
 <div class="legend-color" style="background:<?php echo $color; ?>;"></div>
 <div style="flex:1;">
 <div style="font-weight:500;"><?php echo h($cat['category']); ?></div>
 <div style="font-size:12px;color:#6B7280;"><?php echo $pct; ?>%</div>
 </div>
 <div class="money money-negative">¥<?php echo number_format($cat['total'], 2); ?></div>
 </div>
 <?php $idx++; endforeach; ?>
 <?php endif; ?>
 </div>
 </div>

 <!-- 未收款订单明细 -->
 <?php
 $unpaidOrders = $db->query("SELECT o.*, c.name as customer_name FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE o.payment_status IN (0,1) AND o.status IN (1,2,3) ORDER BY o.order_date ASC")->fetchAll(PDO::FETCH_ASSOC);
 $unpaidTotal = 0;
 $overdueList = [];
 foreach ($unpaidOrders as &$uo) {
 // 用引用(&)修改数组元素,确保改动保存
 $uo['paid_amount'] = floatval($uo['paid_amount'] ?? 0);
 $uo['discount_amount'] = floatval($uo['discount_amount'] ?? 0);
 $uo['unpaid'] = max(0, $uo['total_amount'] - $uo['discount_amount'] - $uo['paid_amount']);
 $uo['daysAgo'] = $uo['order_date'] ? (strtotime(date('Y-m-d')) - strtotime($uo['order_date'])) / 86400 : 0;
 $uo['isOverdue'] = $uo['daysAgo'] > 60;
 $unpaidTotal += $uo['unpaid'];
 if ($uo['isOverdue']) $overdueList[] = &$uo;
 }
 unset($uo); // 解除引用
 $overdueTotal = array_sum(array_column($overdueList, 'unpaid'));
 // TOP3 久拖订单
 usort($overdueList, fn($a, $b) => ($b['daysAgo'] ?? 0) <=> ($a['daysAgo'] ?? 0));
 $top3Overdue = array_slice($overdueList, 0, 3);
 ?>
 <div class="card" style="margin-bottom:20px;">
 <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
 <div class="card-title" style="margin:0;">💳 未收款订单(本年)</div>
 <button class="btn btn-primary" style="padding:6px 16px;font-size:13px;" onclick="openPaymentModal()">💰 登记收款</button>
 </div>
 <?php if (!empty($overdueList)): ?>
 <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
 <div style="flex:1;min-width:160px;padding:8px 12px;background:#FEF2F2;border:1px solid #FECACA;border-radius:6px;">
 <div style="font-size:11px;color:#991B1B;margin-bottom:2px;">⚠️ 逾期未收(超60天)</div>
 <div style="font-size:18px;font-weight:700;color:#EF4444;">¥<?php echo number_format($overdueTotal, 2); ?></div>
 <div style="font-size:11px;color:#991B1B;"><?php echo count($overdueList); ?> 笔订单</div>
 </div>
 <div style="flex:1;min-width:160px;padding:8px 12px;background:#FEF9C3;border:1px solid #FDE68A;border-radius:6px;">
 <div style="font-size:11px;color:#92400E;margin-bottom:2px;">📋 全部未收款</div>
 <div style="font-size:18px;font-weight:700;color:#D97706;">¥<?php echo number_format($unpaidTotal, 2); ?></div>
 <div style="font-size:11px;color:#92400E;"><?php echo count($unpaidOrders); ?> 笔订单</div>
 </div>
 <?php if (!empty($top3Overdue)): ?>
 <div style="padding:8px 12px;background:#FEF2F2;border:1px solid #FECACA;border-radius:6px;min-width:200px;">
 <div style="font-size:11px;color:#991B1B;margin-bottom:4px;">🔴 TOP3 久拖订单</div>
 <?php foreach ($top3Overdue as $t): ?>
 <div style="display:flex;justify-content:space-between;font-size:12px;color:#7F1D1D;margin-bottom:2px;">
 <span><?php echo h($t['order_no']); ?></span>
 <span style="font-weight:600;"><?php echo (int)($t['daysAgo'] ?? 0); ?>天</span>
 </div>
 <?php endforeach; ?>
 </div>
 <?php endif; ?>
 </div>
 <?php endif; ?>
 <?php if (empty($unpaidOrders)): ?>
 <div class="empty-state">🎉 本年所有订单已收齐款项</div>
 <?php else: ?>
 <div style="overflow-x:auto;">
 <table>
 <thead>
 <tr>
 <th>订单号</th>
 <th>客户</th>
 <th>订单日期</th>
 <th>订单金额</th>
 <th>已收款</th>
 <th>未收款</th>
 <th>状态</th>
 <th>距今天</th>
 </tr>
 </thead>
 <tbody>
 <?php foreach ($unpaidOrders as $uo): ?>
 <?php $isOverdue = $uo['isOverdue'] ?? false; ?>
 <?php $daysAgo = (int)($uo['daysAgo'] ?? 0); ?>
 <?php $unpaidVal = $uo['unpaid'] ?? 0; ?>
 <tr style="<?php echo $isOverdue ? 'background:#FEF2F2;' : ''; ?>">
 <td><strong><?php echo h($uo['order_no']); ?></strong></td>
 <td><?php echo h($uo['customer_name'] ?: '散客'); ?></td>
 <td><?php echo $uo['order_date']; ?></td>
 <td class="money">¥<?php echo number_format(floatval($uo['total_amount']), 2); ?></td>
 <td style="color:#10B981;">¥<?php echo number_format($uo['paid_amount'] ?? 0, 2); ?></td>
 <td class="money money-negative">¥<?php echo number_format($unpaidVal, 2); ?></td>
 <td>
 <?php if ($uo['payment_status'] == 0): ?>
 <span class="badge" style="background:#FEE2E2;color:#991B1B;">未付款</span>
 <?php else: ?>
 <span class="badge" style="background:#FEF3C3;color:#92400E;">部分付款</span>
 <?php endif; ?>
 </td>
 <td>
 <?php if ($isOverdue): ?>
 <span style="color:#EF4444;font-weight:600;">⚠️ <?php echo $daysAgo; ?>天</span>
 <?php else: ?>
 <span style="color:#6B7280;"><?php echo $daysAgo; ?>天</span>
 <?php endif; ?>
 </td>
 </tr>
 <?php endforeach; ?>
 </tbody>
 <tfoot>
 <tr style="background:#FEF3C7;font-weight:600;">
 <td colspan="5">合计未收款(<?php echo count($unpaidOrders); ?> 笔订单)</td>
 <td class="money money-negative">¥<?php echo number_format($unpaidTotal, 2); ?></td>
 <td colspan="2"></td>
 </tr>
 </tfoot>
 </table>
 </div>
 <?php endif; ?>
 </div>

 <!-- 收款记录列表 -->
 <div class="card" style="margin-bottom:20px;">
 <div class="card-title">💰 收款记录(<?php echo $year; ?>年<?php echo $isFullYear ? '全年' : $month.'月'; ?>)</div>
 <?php if (empty($paymentList)): ?>
 <div class="empty-state"><?php echo $isFullYear ? '本年暂无收款记录' : '本月暂无收款记录'; ?></div>
 <?php else: ?>
 <table>
 <thead>
 <tr>
 <th>收款日期</th>
 <th>订单号</th>
 <th>客户</th>
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
 <td>
 <a href="order_view.php?id=<?php echo intval($pay['order_id']); ?>" style="color:#3B82F6;text-decoration:none;font-weight:600;" title="查看订单详情">
 📋 <?php echo h($pay['order_no']); ?>
 </a>
 </td>
 <td><?php echo h($pay['customer_name'] ?: '散客'); ?></td>
 <td class="money money-positive" style="color:#10B981;font-weight:700;">+¥<?php echo number_format(floatval($pay['amount'] ?? 0), 2); ?></td>
 <td><?php if (floatval($pay['discount'] ?? 0) > 0): ?><span style="color:#F97316;font-size:13px;">-¥<?php echo number_format(floatval($pay['discount']), 2); ?></span><?php else: ?>-<?php endif; ?></td>
 <td><span class="badge" style="background:#D1FAE5;color:#065F46;"><?php echo h($pay['payment_method']); ?></span></td>
 <td style="color:#6B7280;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo h($pay['remark'] ?? ''); ?>"><?php echo h($pay['remark']) ?: '-'; ?></td>
 <td><?php echo h($pay['receiver_name'] ?: '系统'); ?></td>
 </tr>
 <?php endforeach; ?>
 </tbody>
 <tfoot>
 <tr style="background:#D1FAE5;font-weight:600;">
 <td colspan="3">合计收款(<?php echo count($paymentList); ?> 笔)</td>
 <td class="money" style="color:#065F46;">+¥<?php echo number_format(array_sum(array_column($paymentList, 'amount')), 2); ?></td>
 <td colspan="3"></td>
 </tr>
 </tfoot>
 </table>
 <?php endif; ?>
 </div>

 <!-- 支出记录列表 -->
 <div class="card">
 <div class="card-title">📝 支出记录(<?php echo $year; ?>年<?php echo $month; ?>月)</div>
 <?php if (empty($expenseList)): ?>
 <div class="empty-state">本月暂无支出记录,点击上方按钮添加</div>
 <?php else: ?>
 <table>
 <thead>
 <tr>
 <th>日期</th>
 <th>类别</th>
 <th>金额</th>
 <th>关联订单</th>
 <th>说明</th>
 <th>凭证</th>
 <th>登记人</th>
 <th style="width:100px;">操作</th>
 </tr>
 </thead>
 <tbody>
 <?php foreach ($expenseList as $exp): ?>
 <tr>
 <td><?php echo $exp['expense_date']; ?></td>
 <td><span class="badge"><?php echo h($exp['category']); ?></span></td>
 <td class="money money-negative">-¥<?php echo number_format($exp['amount'], 2); ?></td>
 <td>
 <?php if (!empty($exp['order_id']) && !empty($exp['order_no_linked'])): ?>
 <a href="order_view.php?id=<?php echo intval($exp['order_id']); ?>" style="color:#3B82F6;text-decoration:none;" title="查看订单详情">📋 <?php echo h($exp['order_no_linked']); ?></a>
 <?php else: ?>
 <span style="color:#9CA3AF;">-</span>
 <?php endif; ?>
 </td>
 <td style="color:#6B7280;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo h($exp['description'] ?? ''); ?>"><?php echo h($exp['description']) ?: '-'; ?></td>
 <td>
 <?php if (!empty($exp['attachment'])): ?>
 <?php if (strtolower(pathinfo($exp['attachment'], PATHINFO_EXTENSION)) === 'pdf'): ?>
 <a href="<?php echo h($exp['attachment']); ?>" target="_blank" style="color:#3B82F6;text-decoration:none;font-size:12px;">📄 PDF</a>
 <?php else: ?>
 <a href="<?php echo h($exp['attachment']); ?>" target="_blank" title="点击查看大图">
 <img src="<?php echo h($exp['attachment']); ?>" alt="凭证" style="width:40px;height:40px;object-fit:cover;border-radius:4px;border:1px solid #E5E7EB;cursor:pointer;">
 </a>
 <?php endif; ?>
 <?php else: ?>
 <span style="color:#9CA3AF;font-size:12px;">未上传</span>
 <?php endif; ?>
 </td>
 <td><?php echo h($exp['realname'] ?: '系统'); ?></td>
 <td style="display:flex;gap:6px;">
 <button class="btn btn-outline" style="padding:6px 12px;font-size:13px;" onclick="editExpense(<?php echo intval($exp['id']); ?>)">编辑</button>
 <button class="btn btn-danger" onclick="deleteExpense(<?php echo intval($exp['id']); ?>)">删除</button>
 </td>
 </tr>
 <?php endforeach; ?>
 </tbody>
 <tfoot>
 <tr style="background:#FEE2E2;font-weight:600;">
 <td colspan="2">合计支出(<?php echo count($expenseList); ?> 笔)</td>
 <td class="money" style="color:#991B1B;">-¥<?php echo number_format(array_sum(array_column($expenseList, 'amount')), 2); ?></td>
 <td colspan="5"></td>
 </tr>
 </tfoot>
 </table>
 <?php endif; ?>
 </div>

 <!-- 添加支出弹窗 -->
 <div class="modal-overlay" id="modal">
 <div class="modal-content">
 <div class="modal-title">记一笔支出</div>
 <form id="expenseForm" enctype="multipart/form-data">
 <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
 <input type="hidden" name="ajax_action" value="add_expense">

 <div class="form-group">
 <label class="form-label">费用类别</label>
 <select name="category" id="expenseCategory" class="form-control" required>
 <option value="">请选择类别</option>
 <?php foreach ($expenseCategoryOptions as $opt): ?>
 <option value="<?php echo h($opt); ?>"><?php echo h($opt); ?></option>
 <?php endforeach; ?>
 </select>
 </div>

 <div class="form-group">
 <label class="form-label">金额(元)</label>
 <input type="number" name="amount" id="expenseAmount" class="form-control" placeholder="0.00" step="0.01" min="0.01" required>
 </div>

 <div class="form-group">
 <label class="form-label">费用日期</label>
 <input type="date" name="expense_date" id="expenseDate" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
 </div>

 <div class="form-group">
 <label class="form-label">关联订单(可选)</label>
 <select name="order_id" id="expenseOrderId" class="form-control">
 <option value="">不关联订单</option>
 <?php
 $expenseOrderList = $db->query("SELECT o.id, o.order_no, o.order_date, o.total_amount, c.name AS customer_name FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE o.status IN (1,2,3) ORDER BY o.order_date DESC, o.id DESC LIMIT 200");
 foreach ($expenseOrderList as $eo):
 $label = sprintf('%s | %s | ¥%s', $eo['order_no'], $eo['customer_name'] ?? '散客', number_format($eo['total_amount'], 2));
 if ($eo['order_date']) $label = $eo['order_date'] . ' ' . $label;
 ?>
 <option value="<?php echo $eo['id']; ?>"><?php echo h($label); ?></option>
 <?php endforeach; ?>
 </select>
 <small class="form-hint">如采购/运输/跟单费、印件后补等可关联到具体订单</small>
 </div>

 <div class="form-group">
 <label class="form-label">说明(可选)</label>
 <textarea name="description" id="expenseDesc" class="form-control" rows="3" placeholder="备注信息..."></textarea>
 </div>

 <div class="form-group">
 <label class="form-label">📎 支付截图/凭证(可选)</label>
 <div class="paste-upload-area" id="expensePasteArea" tabindex="0">
 <input type="file" name="attachment_file" id="expenseAttachment" class="form-control paste-file-input" accept="image/*,application/pdf" onchange="previewAttachment(this)">
 <div class="paste-hint">
 <span class="paste-icon">📋</span>
 <span class="paste-text">点击选择 / 拖入文件 / <b>Ctrl+V 粘贴截图</b></span>
 <span class="paste-sub">支持图片和 PDF,最多 10MB</span>
 </div>
 </div>
 <div id="attachmentPreview" style="margin-top:8px;display:none;">
 <img id="attachmentImg" src="" alt="预览" style="max-width:100%;max-height:160px;border-radius:8px;border:1px solid #E5E7EB;display:block;">
 <div id="attachmentPdf" style="display:none;padding:8px 12px;background:#FEF3C7;border-radius:6px;color:#92400E;">📄 已选择 PDF 文件</div>
 <button type="button" class="btn btn-ghost" style="margin-top:6px;font-size:12px;color:#EF4444;" onclick="clearExpenseAttachment()">✕ 移除凭证</button>
 </div>
 </div>

 <div class="modal-actions">
 <button type="button" class="btn btn-outline" onclick="closeModal()">取消</button>
 <button type="submit" class="btn btn-primary">保存</button>
 </div>
 </form>
 </div>
 </div>

 <!-- 登记收款弹窗 -->
 <div class="modal-overlay" id="paymentModal">
 <div class="modal-content">
 <div class="modal-title">💳 登记收款</div>
 <form id="paymentForm">
 <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
 <input type="hidden" name="ajax_action" value="add_payment">

 <div class="form-group">
 <label class="form-label">选择订单</label>
 <select name="order_id" id="paymentOrderId" class="form-control" required>
 <option value="">请选择未收款订单</option>
 </select>
 </div>

 <div class="form-group">
 <label class="form-label">收款金额(元)</label>
 <input type="number" name="amount" id="paymentAmount" class="form-control" placeholder="0.00" step="0.01" min="0.01" required>
 </div>

 <div class="form-group">
 <label class="form-label">本次优惠(可不填)</label>
 <input type="number" name="discount" id="paymentDiscount" class="form-control" placeholder="如收款时减免尾数" step="0.01" min="0" value="0">
 </div>

 <div class="form-group">
 <label class="form-label">收款日期</label>
 <input type="date" name="pay_date" id="paymentDate" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
 </div>

 <div class="form-group">
 <label class="form-label">收款方式</label>
 <select name="pay_method" id="paymentMethod" class="form-control">
 <option value="现金">现金</option>
 <option value="微信">微信</option>
 <option value="支付宝">支付宝</option>
 <option value="银行转账">银行转账</option>
 <option value="rego">rego</option>
 </select>
 </div>

 <div class="form-group">
 <label class="form-label">备注(可选)</label>
 <textarea name="remark" id="paymentRemark" class="form-control" rows="2" placeholder="如:现金 1 万、转账 5 万等"></textarea>
 </div>

 <div class="form-group">
 <label class="form-label">📎 收款凭证/截图(可选)</label>
 <div class="paste-upload-area" id="paymentPasteArea" tabindex="0">
 <input type="file" name="attachment_file" id="paymentAttachment" class="form-control paste-file-input" accept="image/*,application/pdf" onchange="previewPaymentAttachment(this)">
 <div class="paste-hint">
 <span class="paste-icon">📋</span>
 <span class="paste-text">点击选择 / 拖入文件 / <b>Ctrl+V 粘贴截图</b></span>
 <span class="paste-sub">支持图片和 PDF,最多 10MB</span>
 </div>
 </div>
 <div id="paymentAttachmentPreview" style="margin-top:8px;display:none;">
 <img id="paymentAttachmentImg" src="" alt="预览" style="max-width:100%;max-height:160px;border-radius:8px;border:1px solid #E5E7EB;display:block;">
 <div id="paymentAttachmentPdf" style="display:none;padding:8px 12px;background:#FEF3C7;border-radius:6px;color:#92400E;">📄 已选择 PDF 文件</div>
 <button type="button" class="btn btn-ghost" style="margin-top:6px;font-size:12px;color:#EF4444;" onclick="clearPaymentAttachment()">✕ 移除凭证</button>
 </div>
 </div>

 <div class="modal-actions">
 <button type="button" class="btn btn-outline" onclick="closePaymentModal()">取消</button>
 <button type="submit" class="btn btn-primary">确认收款</button>
 </div>
 </form>
 </div>
 </div>

 <!-- 自定义日期范围弹窗 -->
 <div class="modal-overlay" id="rangeModal">
 <div class="modal-content">
 <div class="modal-title">📅 自定义日期范围</div>
 <form id="rangeForm">
 <div class="form-group">
 <label class="form-label">起始日期</label>
 <input type="date" name="start" id="rangeStart" class="form-control" required>
 </div>
 <div class="form-group">
 <label class="form-label">结束日期</label>
 <input type="date" name="end" id="rangeEnd" class="form-control" required>
 </div>
 <div class="form-group">
 <label class="form-label">导出类型</label>
 <select name="type" id="rangeType" class="form-control">
 <option value="detail">支出明细</option>
 <option value="summary">财务汇总</option>
 </select>
 </div>
 <div class="modal-actions">
 <button type="button" class="btn btn-outline" onclick="closeRangeModal()">取消</button>
 <button type="button" class="btn btn-primary" onclick="confirmExportRange()">导出</button>
 </div>
 </form>
 </div>
 </div>

 <!-- 编辑支出弹窗 -->
 <div class="modal-overlay" id="editModal">
 <div class="modal-content">
 <div class="modal-title">编辑支出</div>
 <form id="editExpenseForm" enctype="multipart/form-data">
 <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
 <input type="hidden" name="ajax_action" value="update_expense">
 <input type="hidden" name="id" id="editId">

 <div class="form-group">
 <label class="form-label">费用类别</label>
 <select name="category" id="editCategory" class="form-control" required>
 <option value="">请选择类别</option>
 <?php foreach ($expenseCategoryOptions as $opt): ?>
 <option value="<?php echo h($opt); ?>"><?php echo h($opt); ?></option>
 <?php endforeach; ?>
 </select>
 </div>

 <div class="form-group">
 <label class="form-label">金额(元)</label>
 <input type="number" name="amount" id="editAmount" class="form-control" placeholder="0.00" step="0.01" min="0.01" required>
 </div>

 <div class="form-group">
 <label class="form-label">费用日期</label>
 <input type="date" name="expense_date" id="editDate" class="form-control" required>
 </div>

 <div class="form-group">
 <label class="form-label">关联订单(可选)</label>
 <select name="order_id" id="editOrderId" class="form-control">
 <option value="">不关联订单</option>
 <?php
 $editExpenseOrderList = $db->query("SELECT o.id, o.order_no, o.order_date, o.total_amount, c.name AS customer_name FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE o.status IN (1,2,3) ORDER BY o.order_date DESC, o.id DESC LIMIT 200");
 foreach ($editExpenseOrderList as $eo):
 $label = sprintf('%s | %s | ¥%s', $eo['order_no'], $eo['customer_name'] ?? '散客', number_format($eo['total_amount'], 2));
 if ($eo['order_date']) $label = $eo['order_date'] . ' ' . $label;
 ?>
 <option value="<?php echo $eo['id']; ?>"><?php echo h($label); ?></option>
 <?php endforeach; ?>
 </select>
 </div>

 <div class="form-group">
 <label class="form-label">说明(可选)</label>
 <textarea name="description" id="editDesc" class="form-control" rows="3" placeholder="备注信息..."></textarea>
 </div>

 <div class="form-group">
 <label class="form-label">📎 支付截图/凭证</label>
 <div id="editAttachmentCurrent" style="display:none;margin-bottom:8px;padding:8px;background:#F3F4F6;border-radius:6px;">
 <a id="editAttachmentLink" href="" target="_blank" style="color:#3B82F6;text-decoration:none;">📄 查看当前凭证</a>
 <button type="button" class="btn btn-ghost" style="font-size:12px;padding:2px 8px;margin-left:8px;color:#EF4444;" onclick="removeAttachment()">✕ 移除</button>
 <input type="hidden" name="remove_attachment" id="removeAttachmentFlag" value="0">
 </div>
 <div class="paste-upload-area" id="editPasteArea" tabindex="0">
 <input type="file" name="attachment_file" id="editAttachment" class="form-control paste-file-input" accept="image/*,application/pdf" onchange="previewEditAttachment(this)">
 <div class="paste-hint">
 <span class="paste-icon">📋</span>
 <span class="paste-text">点击选择 / 拖入文件 / <b>Ctrl+V 粘贴截图</b></span>
 <span class="paste-sub">选择新文件会替换原凭证</span>
 </div>
 </div>
 <div id="editAttachmentPreview" style="margin-top:8px;display:none;">
 <img id="editAttachmentImg" src="" alt="预览" style="max-width:100%;max-height:160px;border-radius:8px;border:1px solid #E5E7EB;display:block;">
 <div id="editAttachmentPdf" style="display:none;padding:8px 12px;background:#FEF3C7;border-radius:6px;color:#92400E;">📄 已选择 PDF 文件</div>
 <button type="button" class="btn btn-ghost" style="margin-top:6px;font-size:12px;color:#EF4444;" onclick="clearEditAttachment()">✕ 移除凭证</button>
 </div>
 </div>

 <div class="modal-actions">
 <button type="button" class="btn btn-outline" onclick="closeEditModal()">取消</button>
 <button type="submit" class="btn btn-primary">保存修改</button>
 </div>
 </form>
 </div>
 </div>

 <script>
 const csrfToken = '<?php echo $csrfToken; ?>';

 function changeMonth() {
 const year = document.getElementById('filterYear').value;
 const month = document.getElementById('filterMonth').value;
 if (month === 'all') {
 location.href = `finance.php?year=${year}&month=all`;
 } else {
 location.href = `finance.php?year=${year}&month=${month}`;
 }
 }

 function exportExcel() {
 const type = confirm('点击"确定"导出本月明细,点击"取消"导出本月汇总') ? 'detail' : 'summary';
 const year = document.getElementById('filterYear').value;
 const month = document.getElementById('filterMonth').value;
 if (month === 'all') {
 window.location.href = `finance.php?export=excel&type=${type}&year=${year}&range=all`;
 } else {
 window.location.href = `finance.php?export=excel&type=${type}&year=${year}&month=${month}`;
 }
 }

 function exportPDF(type) {
 const year = document.getElementById('filterYear').value;
 const month = document.getElementById('filterMonth').value;
 if (month === 'all') {
 window.open(`finance.php?export=pdf&type=${type}&year=${year}&range=all`, '_blank');
 } else {
 window.open(`finance.php?export=pdf&type=${type}&year=${year}&month=${month}`, '_blank');
 }
 }

 // ===================== 登记收款 =====================
 async function openPaymentModal() {
 try {
 const fd = new FormData();
 fd.append('csrf_token', csrfToken);
 fd.append('ajax_action', 'get_unpaid_orders');
 const response = await fetch('finance.php', { method: 'POST', body: fd });
 const result = await response.json();

 const select = document.getElementById('paymentOrderId');
 select.innerHTML = '<option value="">请选择未收款订单</option>';
 if (result.ok && result.data) {
 result.data.forEach(o => {
 const disc = parseFloat(o.discount_amount || 0);
 const paid = parseFloat(o.paid_amount || 0);
 const unpaid = (parseFloat(o.total_amount) - disc - paid).toFixed(2);
 const payable = (parseFloat(o.total_amount) - disc).toFixed(2);
 const opt = document.createElement('option');
 opt.value = o.id;
 opt.textContent = `${o.order_no} | ${o.customer_name || '散客'} | 应付 ¥${payable} | 未收 ¥${unpaid}`;
 opt.dataset.unpaid = unpaid;
 select.appendChild(opt);
 });
 }
 document.getElementById('paymentModal').classList.add('show');
 } catch (err) {
 alert('加载未收款订单失败: ' + err.message);
 }
 }

 function closePaymentModal() {
 document.getElementById('paymentModal').classList.remove('show');
 }

 var financeUnpaid = 0;        // 【2026-09-15】当前所选订单的未收金额
 var financeAmountTouched = false; // 用户是否手动改过收款金额

 document.getElementById('paymentOrderId').onchange = function() {
 const opt = this.options[this.selectedIndex];
 if (opt && opt.dataset.unpaid) {
 financeUnpaid = parseFloat(opt.dataset.unpaid) || 0;
 financeAmountTouched = false;
 applyFinanceAmount();
 }
 };

 // 【2026-09-15】优惠联动：收款金额 = 未收 - 优惠（与订单页/批量收款逻辑一致）
 document.getElementById('paymentDiscount').oninput = function() {
 if (financeAmountTouched) return; // 手动改过金额时不覆盖
 applyFinanceAmount();
 };
 document.getElementById('paymentAmount').oninput = function() {
 financeAmountTouched = true;
 };

 function applyFinanceAmount() {
 var discount = parseFloat(document.getElementById('paymentDiscount').value) || 0;
 if (discount < 0) discount = 0;
 if (financeUnpaid > 0) {
 document.getElementById('paymentAmount').value = Math.max(0, financeUnpaid - discount).toFixed(2);
 }
 }

 document.getElementById('paymentForm').onsubmit = async function(e) {
 e.preventDefault();
 const formData = new FormData(this);
 try {
 const response = await fetch('finance.php', { method: 'POST', body: formData });
 const result = await response.json();
 if (result.ok) {
 alert(result.msg);
 location.reload();
 } else {
 alert('错误: ' + result.msg);
 }
 } catch (err) {
 alert('请求失败: ' + err.message);
 }
 };

 document.getElementById('paymentModal').onclick = function(e) {
 if (e.target === this) closePaymentModal();
 };

 // ===================== 自定义日期范围 =====================
 function openCustomRange() {
 document.getElementById('rangeStart').value = '<?php echo date('Y-m-01'); ?>';
 document.getElementById('rangeEnd').value = '<?php echo date('Y-m-d'); ?>';
 document.getElementById('rangeModal').classList.add('show');
 }

 function closeRangeModal() {
 document.getElementById('rangeModal').classList.remove('show');
 }

 function confirmExportRange() {
 const start = document.getElementById('rangeStart').value;
 const end = document.getElementById('rangeEnd').value;
 const type = document.getElementById('rangeType').value;
 if (!start || !end) {
 alert('请选择日期范围');
 return;
 }
 window.location.href = `finance.php?export=excel&type=${type}&start=${start}&end=${end}`;
 setTimeout(() => closeRangeModal(), 500);
 }

 document.getElementById('rangeModal').onclick = function(e) {
 if (e.target === this) closeRangeModal();
 };

 function openAddModal() {
 document.getElementById('expenseForm').reset();
 document.getElementById('expenseDate').value = '<?php echo date('Y-m-d'); ?>';
 document.getElementById('attachmentPreview').style.display = 'none';
 document.getElementById('attachmentImg').src = '';
 document.getElementById('attachmentPdf').style.display = 'none';
 document.getElementById('modal').classList.add('show');
 }

 function closeModal() {
 document.getElementById('modal').classList.remove('show');
 }

 function previewAttachment(input) {
 const preview = document.getElementById('attachmentPreview');
 const img = document.getElementById('attachmentImg');
 const pdf = document.getElementById('attachmentPdf');
 img.src = ''; img.style.display = 'none';
 pdf.style.display = 'none';
 if (!input.files || !input.files[0]) { preview.style.display = 'none'; return; }
 const file = input.files[0];
 if (file.type === 'application/pdf') {
 pdf.style.display = 'block';
 preview.style.display = 'block';
 } else if (file.type.startsWith('image/')) {
 const reader = new FileReader();
 reader.onload = e => {
 img.src = e.target.result;
 img.style.display = 'block';
 preview.style.display = 'block';
 };
 reader.readAsDataURL(file);
 }
 }

 function previewEditAttachment(input) {
 const preview = document.getElementById('editAttachmentPreview');
 const img = document.getElementById('editAttachmentImg');
 const pdf = document.getElementById('editAttachmentPdf');
 img.src = ''; img.style.display = 'none';
 if (pdf) pdf.style.display = 'none';
 if (!input.files || !input.files[0]) { preview.style.display = 'none'; return; }
 const file = input.files[0];
 if (file.type === 'application/pdf') {
 if (pdf) pdf.style.display = 'block';
 preview.style.display = 'block';
 } else if (file.type.startsWith('image/')) {
 const reader = new FileReader();
 reader.onload = e => {
 img.src = e.target.result;
 img.style.display = 'block';
 preview.style.display = 'block';
 };
 reader.readAsDataURL(file);
 }
 }

 function removeAttachment() {
 if (!confirm('确定移除现有凭证吗?保存后不可恢复。')) return;
 document.getElementById('editAttachmentCurrent').style.display = 'none';
 document.getElementById('removeAttachmentFlag').value = '1';
 }

 // ===================== 收款凭证预览/清除 =====================
 function previewPaymentAttachment(input) {
 const preview = document.getElementById('paymentAttachmentPreview');
 const img = document.getElementById('paymentAttachmentImg');
 const pdf = document.getElementById('paymentAttachmentPdf');
 img.src = ''; img.style.display = 'none';
 pdf.style.display = 'none';
 if (!input.files || !input.files[0]) { preview.style.display = 'none'; return; }
 const file = input.files[0];
 if (file.type === 'application/pdf') {
 pdf.style.display = 'block';
 preview.style.display = 'block';
 } else if (file.type.startsWith('image/')) {
 const reader = new FileReader();
 reader.onload = e => {
 img.src = e.target.result;
 img.style.display = 'block';
 preview.style.display = 'block';
 };
 reader.readAsDataURL(file);
 }
 }

 function clearPaymentAttachment() {
 document.getElementById('paymentAttachment').value = '';
 document.getElementById('paymentAttachmentPreview').style.display = 'none';
 document.getElementById('paymentAttachmentImg').src = '';
 }

 // ===================== 截图粘贴支持 =====================
 function applyPastedFile(file, inputId, previewFn) {
 if (!file) return false;
 const okType = file.type && (file.type.startsWith('image/') || file.type === 'application/pdf');
 if (!okType) {
 alert('仅支持粘贴图片或 PDF 文件');
 return false;
 }
 if (file.size > 10 * 1024 * 1024) {
 alert('文件超过 10MB 限制');
 return false;
 }
 const input = document.getElementById(inputId);
 try {
 const dt = new DataTransfer();
 dt.items.add(file);
 input.files = dt.files;
 } catch (e) {
 alert('当前浏览器不支持该粘贴方式,请用 Chrome/Edge 或选择文件');
 return false;
 }
 if (typeof window[previewFn] === 'function') {
 window[previewFn](input);
 }
 const area = input.closest('.paste-upload-area');
 if (area) {
 area.classList.add('paste-flash');
 setTimeout(() => area.classList.remove('paste-flash'), 600);
 }
 return true;
 }

 function enablePasteUpload(areaId, inputId, previewFn) {
 const area = document.getElementById(areaId);
 const input = document.getElementById(inputId);
 if (!area || !input) return;

 area.addEventListener('click', e => {
 if (e.target.closest('button, a')) return;
 input.click();
 });

 area.addEventListener('paste', e => {
 e.preventDefault();
 e.stopPropagation();
 const items = (e.clipboardData || window.clipboardData).items || [];
 for (const item of items) {
 if (item.kind === 'file') {
 const file = item.getAsFile();
 if (file) {
 applyPastedFile(file, inputId, previewFn);
 return;
 }
 }
 }
 alert('剪贴板中没有可粘贴的图片,请先用截图工具截图后重试');
 });

 area.addEventListener('dragover', e => { e.preventDefault(); area.classList.add('paste-drag'); });
 area.addEventListener('dragleave', e => { e.preventDefault(); area.classList.remove('paste-drag'); });
 area.addEventListener('drop', e => {
 e.preventDefault();
 area.classList.remove('paste-drag');
 const file = e.dataTransfer.files && e.dataTransfer.files[0];
 if (file) applyPastedFile(file, inputId, previewFn);
 });

 document.addEventListener('paste', e => {
 const modal = area.closest('.modal-overlay');
 if (!modal || !modal.classList.contains('show')) return;
 const ae = document.activeElement;
 if (ae && (ae.tagName === 'TEXTAREA' || (ae.tagName === 'INPUT' && ae.type !== 'file'))) {
 return;
 }
 const items = (e.clipboardData || window.clipboardData).items || [];
 for (const item of items) {
 if (item.kind === 'file') {
 const file = item.getAsFile();
 if (file) {
 e.preventDefault();
 applyPastedFile(file, inputId, previewFn);
 return;
 }
 }
 }
 });
 }

 if (document.readyState === 'loading') {
 document.addEventListener('DOMContentLoaded', initPasteUpload);
 } else {
 initPasteUpload();
 }
 function initPasteUpload() {
 enablePasteUpload('expensePasteArea', 'expenseAttachment', 'previewAttachment');
 enablePasteUpload('paymentPasteArea', 'paymentAttachment', 'previewPaymentAttachment');
 enablePasteUpload('editPasteArea', 'editAttachment', 'previewEditAttachment');
 }

 function clearExpenseAttachment() {
 document.getElementById('expenseAttachment').value = '';
 document.getElementById('attachmentPreview').style.display = 'none';
 document.getElementById('attachmentImg').src = '';
 }

 function clearEditAttachment() {
 document.getElementById('editAttachment').value = '';
 document.getElementById('editAttachmentPreview').style.display = 'none';
 document.getElementById('editAttachmentImg').src = '';
 }

 document.getElementById('expenseForm').onsubmit = async function(e) {
 e.preventDefault();
 const formData = new FormData(this);

 try {
 const response = await fetch('finance.php', { method: 'POST', body: formData });
 const result = await response.json();

 if (result.ok) {
 alert(result.msg);
 location.reload();
 } else {
 alert('错误: ' + result.msg);
 }
 } catch (err) {
 alert('请求失败: ' + err.message);
 }
 };

 async function editExpense(id) {
 try {
 const fd = new FormData();
 fd.append('csrf_token', csrfToken);
 fd.append('ajax_action', 'get_expense');
 fd.append('id', id);

 const response = await fetch('finance.php', { method: 'POST', body: fd });
 const result = await response.json();

 if (result.ok && result.data) {
 document.getElementById('editId').value = result.data.id;
 document.getElementById('editCategory').value = result.data.category;
 document.getElementById('editAmount').value = result.data.amount;
 document.getElementById('editDate').value = result.data.expense_date;
 document.getElementById('editDesc').value = result.data.description || '';
 document.getElementById('editOrderId').value = result.data.order_id || '';
 document.getElementById('editAttachment').value = '';
 document.getElementById('removeAttachmentFlag').value = '0';
 document.getElementById('editAttachmentPreview').style.display = 'none';
 document.getElementById('editAttachmentImg').src = '';
 const att = result.data.attachment;
 if (att) {
 const link = document.getElementById('editAttachmentLink');
 link.href = att;
 link.textContent = att.toLowerCase().endsWith('.pdf') ? '📄 查看当前 PDF 凭证' : '🖼️ 查看当前凭证';
 document.getElementById('editAttachmentCurrent').style.display = 'block';
 } else {
 document.getElementById('editAttachmentCurrent').style.display = 'none';
 }
 document.getElementById('editModal').classList.add('show');
 } else {
 alert('获取记录失败: ' + (result.msg || ''));
 }
 } catch (err) {
 alert('请求失败: ' + err.message);
 }
 }

 function closeEditModal() {
 document.getElementById('editModal').classList.remove('show');
 }

 document.getElementById('editExpenseForm').onsubmit = async function(e) {
 e.preventDefault();
 const formData = new FormData(this);

 try {
 const response = await fetch('finance.php', { method: 'POST', body: formData });
 const result = await response.json();

 if (result.ok) {
 alert(result.msg);
 location.reload();
 } else {
 alert('错误: ' + result.msg);
 }
 } catch (err) {
 alert('请求失败: ' + err.message);
 }
 };

 document.getElementById('editModal').onclick = function(e) {
 if (e.target === this) closeEditModal();
 };

 async function deleteExpense(id) {
 if (!confirm('确定要删除这条支出记录吗?')) return;

 const formData = new FormData();
 formData.append('csrf_token', csrfToken);
 formData.append('ajax_action', 'delete_expense');
 formData.append('id', id);

 try {
 const response = await fetch('finance.php', { method: 'POST', body: formData });
 const result = await response.json();
 alert(result.msg);
 if (result.ok) location.reload();
 } catch (err) {
 alert('请求失败: ' + err.message);
 }
 }

 document.getElementById('modal').onclick = function(e) {
 if (e.target === this) closeModal();
 };
 </script>
</body>
</html>
