<?php
/**
 * 订单编辑页面(增强版)
 * 支持:尺寸计算、图片上传、保留原有图片
 */

require_once 'config.php';
// 【2026-09-08】强制清本文件 opcache,避免 Web Station PHP-FPM 缓存了旧版
if (function_exists('opcache_invalidate')) { @opcache_invalidate(__FILE__, true); }

// ============ 调大 PHP 上传限制 (适应 90+ 行订单修改) ============
// 默认 post_max_size=8M / max_input_vars=1000 会被 90 行订单表单超过。
// 上限调高到 100M / 5000, 与单图上传接口一起, 保证表单提交不被 PHP 拦截。
@ini_set('post_max_size', '100M');
@ini_set('upload_max_filesize', '20M');
@ini_set('max_input_vars', 5000);
@ini_set('max_file_uploads', 200);
@ini_set('memory_limit', '256M');
initDatabase();

// ===================== 异步单图上传接口(在 checkLogin 之前) =====================
// 让 order_edit.php 也能像 order_create.php 一样, 选完图立即异步上传到服务端,
// 表单提交时只带 URL 字符串, 避开 PHP post_max_size 8M / max_input_vars 1000 限制。
// 不动订单状态, 只接收图片, 跳过 CSRF(用登录态校验替代)。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'item_upload_image') {
    if (session_status() === PHP_SESSION_NONE) { @session_start(); }
    if (empty($_SESSION['user_id'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'msg' => '请重新登录后再上传图片(session 已过期)', 'need_login' => true]);
        exit;
    }
    $GLOBALS['__BYPASS_CHECKLOGIN__'] = true;
    // 复用同文件内的 handleItemImageUpload 处理
    if (isset($_POST['item_index'])) {
        $idx = intval($_POST['item_index']);
        $url = handleItemImageUpload('item_image_' . $idx, $idx);
        header('Content-Type: application/json; charset=utf-8');
        if ($url) {
            echo json_encode(['ok' => true, 'url' => $url, 'size' => filesize(__DIR__ . '/' . $url)]);
        } else {
            $err = $_FILES['item_image_' . $idx]['error'] ?? -1;
            echo json_encode(['ok' => false, 'msg' => '图片上传失败 (error=' . $err . ')', 'php_post_max' => ini_get('post_max_size'), 'php_upload_max' => ini_get('upload_max_filesize')]);
        }
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => '缺少 item_index 参数']);
    exit;
}

if (empty($GLOBALS['__BYPASS_CHECKLOGIN__'])) {
    checkLogin();
}

$db = getDB();

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    die('无效的订单ID');
}

$order = $db->prepare("SELECT o.*, c.name as customer_name, c.contact, c.phone, c.address
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    WHERE o.id = ?");
$order->execute([$id]);
$order = $order->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die('订单不存在');
}

if (in_array($order['status'], [2, 3, 4])) {
    $statusText = getStatusText($order['status']);
    die("订单状态为「{$statusText}」,禁止修改。如需调整请联系管理员。");
}

$items = $db->prepare("SELECT * FROM order_items WHERE order_id = ?");
$items->execute([$id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

// 图片上传处理函数
function handleItemImageUpload($fieldName, $itemIndex) {
    if (!isset($_FILES[$fieldName])) return null;
    $file = $_FILES[$fieldName];
    if ($file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > 5 * 1024 * 1024) return null;

    $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) return null;

    $baseDir = realpath(dirname(__FILE__)) ?: dirname(__FILE__);
    $uploadDir = $baseDir . '/uploads/order_items';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    $newName = 'item_' . date('Ymd_His') . '_' . $itemIndex . '_' . substr(md5(uniqid(mt_rand(), true)), 0, 8) . '.' . $ext;
    $dest = $uploadDir . '/' . $newName;
    if (move_uploaded_file($file['tmp_name'], $dest)) {
        // 【图片压缩】压到60KB内，失败不影响上传
        @compressUploadedImage($dest);
        // 验证落盘成功，防 Web Station/PHP 缓存问题导致“入库但文件不存在”
        if (file_exists($dest) && filesize($dest) > 0) {
            return 'uploads/order_items/' . $newName;
        }
        @unlink($dest);
        error_log("handleItemImageUpload: move_uploaded_file OK but file missing/empty: $dest");
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // ============ 诊断: 如果提交体非空但 $_POST 为空 → PHP 拦截了 multipart 解析 ============
    if (empty($_POST) && empty($_FILES) && !empty($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0) {
        $contentLen = intval($_SERVER['CONTENT_LENGTH']);
        $postMaxSize = ini_get('post_max_size');
        $inputVars = ini_get('max_input_vars');
        $fileMax = ini_get('max_file_uploads');
        die(
            '<div style="font-family:monospace;padding:20px;background:#fee2e2;border:2px solid #ef4444;border-radius:8px;color:#7f1d1d;">' .
            '<h2>⚠️ PHP 拒收了提交请求</h2>' .
            '<p>提交体 (' . number_format($contentLen / 1024 / 1024, 2) . ' MB) 超过 PHP 限制, 整个 $_POST 和 $_FILES 被丢弃。</p>' .
            '<table style="margin-top:12px;border-collapse:collapse;">' .
            '<tr><td style="padding:4px 12px;background:#fecaca;">post_max_size</td><td style="padding:4px 12px;"><b>' . $postMaxSize . '</b></td></tr>' .
            '<tr><td style="padding:4px 12px;background:#fecaca;">max_input_vars</td><td style="padding:4px 12px;"><b>' . $inputVars . '</b></td></tr>' .
            '<tr><td style="padding:4px 12px;background:#fecaca;">max_file_uploads</td><td style="padding:4px 12px;"><b>' . $fileMax . '</b></td></tr>' .
            '</table>' .
            '<p style="margin-top:12px;">联系管理员在 php.ini 或 nginx/fpm 中调大上述参数, 重启 PHP-FPM 后重试。</p>' .
            '</div>'
        );
    }
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        // 兜底：session 可能被重置（cookie 丢失），重新启动 + 退回原本的 token
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        if (isset($_POST['csrf_token']) && isset($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            // 验证通过
        } else {
            $postHasToken = isset($_POST['csrf_token']) ? 'YES (len=' . strlen($_POST['csrf_token']) . ')' : 'NO';
            $filesSummary = [];
            foreach ($_FILES as $k => $f) {
                $filesSummary[] = "$k: err={$f['error']} size={$f['size']} tmp={$f['tmp_name']}";
            }
            die('非法请求<br><br>DEBUG:<br>POST has csrf_token: ' . $postHasToken . '<br>FILES: ' . implode('<br>', $filesSummary) . '<br>Session csrf_token: ' . (isset($_SESSION['csrf_token']) ? 'YES (len=' . strlen($_SESSION['csrf_token']) . ')' : 'NO') . '<br>POST keys: ' . implode(',', array_keys($_POST)));
        }
    }

    try {
        $db->beginTransaction();

        if (!empty($_POST['new_customer_name'])) {
            $stmt = $db->prepare("INSERT INTO customers (name, contact, phone, address) VALUES (?, ?, ?, ?)");
            $stmt->execute([$_POST['new_customer_name'], $_POST['contact'], $_POST['phone'], $_POST['address']]);
            $customer_id = $db->lastInsertId();
        } else {
            $customer_id = intval($_POST['customer_id'] ?? 0);
            if ($customer_id <= 0) throw new Exception('请选择客户');
        }

        $order_date = $_POST['order_date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $order_date)) {
            $order_date = date('Y-m-d');
        }

        $stmt = $db->prepare("UPDATE orders SET customer_id = ?, order_date = ?, remark = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$customer_id, $order_date, $_POST['order_remark'] ?? '', $id]);

        // 保存原有图片路径以便清理
        $oldItems = $db->prepare("SELECT id, image_path FROM order_items WHERE order_id = ?");
        $oldItems->execute([$id]);
        $oldImages = [];
        foreach ($oldItems->fetchAll(PDO::FETCH_ASSOC) as $oi) {
            if (!empty($oi['image_path'])) $oldImages[] = $oi['image_path'];
        }

        // 收集原明细快照（含 image_path）。不删除明细，依靠 INSERT id + 判重
        // 防止前端表单提交不全/不按顺序时丢失原有图片
        $itemMap = [];
        foreach ($oldItems->fetchAll(PDO::FETCH_ASSOC) as $oi) {
            $itemMap[intval($oi['id'])] = $oi;
        }

        // 仅删除原明细（后面会重新 INSERT 提交上来的项）
        $db->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$id]);

        $total_amount = 0;
        $newImages = [];
        foreach ($_POST['items'] as $index => $item) {
            if (empty($item['product_name'])) continue;

            $quantity = floatval($item['quantity'] ?? 0);
            $unit_price = floatval($item['unit_price'] ?? 0);
            $length = floatval($item['length'] ?? 0);
            $width = floatval($item['width'] ?? 0);
            $square_meter = floatval($item['square_meter'] ?? 0);
            $unit = $item['unit'] ?? '';

            // 有平方数且单位是面积类 → 按平方计算
            $areaUnits = ['m2', '平方米', 'm2', '平方'];
            if ($square_meter > 0 && in_array($unit, $areaUnits, true)) {
                $amount = $square_meter * $unit_price * $quantity;
            } else {
                $amount = $quantity * $unit_price;
            }
            $total_amount += $amount;

            // 处理图片路径（异步上传后只带 URL 字符串，不再带 file input）
            $image_path = '';
            // 1) 优先:前端通过 item_upload_image 异步上传后的 image_url
            if (!empty($item['image_url'])) {
                $image_path = $item['image_url'];
                $newImages[] = $image_path;
            }
            // 2) 兑底:原有图片(为避免重复 SELECT 之后的 fetchAll 复用同一个 PDOStatement)
            if (!$image_path && !empty($item['existing_image'])) {
                $image_path = $item['existing_image'];
            }
            // 3) 兑底兑底:同一请求中还有 file input 提交(某些浏览器/老逻辑)。仅作为兼容兑底。
            $fileKey = "item_image_{$index}";
            if (!$image_path && isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
                $image_path = handleItemImageUpload($fileKey, $index);
                if ($image_path) $newImages[] = $image_path;
            }

            $stmt = $db->prepare("INSERT INTO order_items
                (order_id, product_name, specification, quantity, unit, unit_price, amount, remark, length, width, square_meter, image_path)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $id,
                $item['product_name'] ?? '',
                $item['specification'] ?? '',
                $quantity,
                $unit,
                $unit_price,
                $amount,
                $item['remark'] ?? '',
                $length > 0 ? $length : null,
                $width > 0 ? $width : null,
                $square_meter > 0 ? $square_meter : null,
                $image_path
            ]);
        }

        $discount = floatval($_POST['discount_amount'] ?? 0);
        // 校验:优惠金额不能为负、不能超过总金额
        if ($discount < 0) {
            throw new Exception('优惠金额不能为负数');
        }
        if ($discount > $total_amount) {
            throw new Exception('优惠金额不能超过订单总额');
        }
        $db->prepare("UPDATE orders SET total_amount = ?, discount_amount = ? WHERE id = ?")->execute([$total_amount, $discount, $id]);

        $paid = floatval($order['paid_amount']);
        $newPayable = $total_amount - $discount;
        // 如果已收超过新应付,裁剪到新应付(多付的钱需手动退款/调整)
        $newPaid = ($paid > $newPayable) ? $newPayable : $paid;
        $newStatus = ($newPayable - $newPaid <= 0.005) ? 2 : (($newPaid > 0) ? 1 : 0);
        // 如果裁剪了已收,记录提示
        $overpaid = $paid - $newPaid;
        $db->prepare("UPDATE orders SET paid_amount = ?, payment_status = ? WHERE id = ?")->execute([$newPaid, $newStatus, $id]);

        logOperation('edit_order', [
            'order_id' => $id,
            'order_no' => $order['order_no'],
            'old_customer_id' => $order['customer_id'],
            'new_customer_id' => $customer_id,
            'old_total' => $order['total_amount'],
            'new_total' => $total_amount
        ]);

        // 注意：不主动 unlink 原图。前端在勾“删除图”时才会调删除接口
        // 以前这里会 unlink 所有“不在 newImages”的图，导致“修改但不上传 = 所有图片丢失”
        // 这个 bug 导致：后端 DELETE 原 order_items，INSERT 新 item 时只拿 newImages
        // （existing_image 没动），导致“不在 newImages”的图被 unlink，但用户没重传，
        // 实际是“未在 newImages”的图原本是应该保留的。只 unlink 真正“用户主动删除”的图。
        //
        // 改：什么都不删。原图全部保留（如果用户在编辑页勾了删除，会走 delete_file.php）
        $db->commit();

        $redirectParams = "edited=1";
        if ($overpaid > 0.01) {
            $redirectParams .= "&overpaid=" . urlencode(number_format($overpaid, 2, '.', ''));
        }
        header("Location: order_view.php?id=$id&$redirectParams");
        exit;

    } catch (Exception $e) {
        $db->rollBack();
        $error = $e->getMessage();
    }
}

$customers = $db->query("SELECT * FROM customers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// 产品数据(含使用次数)
$products = $db->query("
    SELECT p.*, COALESCE(u.cnt, 0) as usage_count
    FROM products p
    LEFT JOIN (
        SELECT oi.product_name, COUNT(DISTINCT oi.order_id) as cnt
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        WHERE o.status != 4
        GROUP BY oi.product_name
    ) u ON p.name = u.product_name
    ORDER BY u.cnt DESC, p.category, p.name
")->fetchAll(PDO::FETCH_ASSOC);

// 最近用过的产品
$recentProducts = $db->query("
    SELECT oi.product_name, MAX(o.created_at) as last_used
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    WHERE o.status != 4 AND oi.product_name IS NOT NULL AND oi.product_name != ''
    GROUP BY oi.product_name
    ORDER BY last_used DESC
    LIMIT 12
")->fetchAll(PDO::FETCH_ASSOC);

// 历史成交价
$historyPrices = [];
$hpStmt = $db->query("
    SELECT oi.product_name, oi.unit_price, o.created_at
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    WHERE oi.unit_price > 0 AND o.status != 4
    ORDER BY o.created_at DESC
");
while ($row = $hpStmt->fetch(PDO::FETCH_ASSOC)) {
    $name = $row['product_name'];
    if (!isset($historyPrices[$name])) $historyPrices[$name] = [];
    if (count($historyPrices[$name]) < 3) {
        $historyPrices[$name][] = ['price' => floatval($row['unit_price']), 'date' => substr($row['created_at'], 0, 10)];
    }
}

// 准备JS数据
$jsProducts = [];
foreach ($products as $p) {
    $jsProducts[] = [
        'name' => $p['name'],
        'category' => $p['category'] ?: '未分类',
        'unit' => $p['unit'],
        'price' => floatval($p['price'] ?? 0),
        'usage' => intval($p['usage_count']),
        'history' => $historyPrices[$p['name']] ?? []
    ];
}
$jsProductsJson = json_encode($jsProducts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$recentJson = json_encode($recentProducts, JSON_UNESCAPED_UNICODE);

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>修改订单 - <?php echo SITE_TITLE; ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .item-row {
            display: grid;
            grid-template-columns: 2fr 1.2fr 0.7fr 0.7fr 0.7fr 0.6fr 0.8fr 0.9fr auto;
            gap: 10px;
            align-items: end;
            padding: 16px 16px 16px 40px;  /* 【2026-09-08 修复】左边让出空间给绝对定位的勾选框和序号 */
            background: white;
            border-radius: var(--radius-lg);
            margin-bottom: 12px;
            border: 2px solid var(--gray-100);
            transition: var(--transition-base);
            position: relative;  /* 【2026-09-08 修复】作为绝对定位勾选框+序号的定位上下文 */
        }
        .item-row:hover { border-color: var(--primary-light); box-shadow: var(--shadow-card); }
        .item-row .field-label { font-size: 11px; color: var(--gray-400); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; display: block; }
        .item-row .form-control { padding: 10px 12px; font-size: 14px; }
        .item-row .amount-display { background: var(--gray-50); font-weight: 700; color: var(--primary); border: 2px solid var(--gray-200); border-radius: var(--radius-md); padding: 10px 12px; width: 100%; text-align: right; }

        .size-inputs {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .size-inputs input {
            width: 60px;
            text-align: center;
            padding: 10px 4px !important;
        }
        .size-inputs span {
            color: var(--gray-400);
            font-size: 12px;
        }
        .square-display {
            font-size: 12px;
            color: var(--primary);
            font-weight: 600;
            margin-top: 4px;
        }

        .image-upload {
            position: relative;
        }
        .image-upload input[type="file"] {
            display: none;
        }
        .image-upload-label {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 10px 12px;
            background: var(--gray-50);
            border: 2px dashed var(--gray-300);
            border-radius: var(--radius-md);
            cursor: pointer;
            font-size: 13px;
            color: var(--gray-500);
            transition: all 0.2s;
            white-space: nowrap;
            min-height: 42px;
        }
        .image-upload-label:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-light);
        }
        .image-upload-label.has-image {
            background: var(--success-light);
            border-color: var(--success);
            color: var(--success);
        }
        .image-upload.paste-drag .image-upload-label {
            border-color: #10B981 !important;
            background: #ECFDF5 !important;
            color: #10B981 !important;
        }
        .image-upload.paste-active .image-upload-label {
            border-color: var(--primary) !important;
            color: var(--primary) !important;
            background: var(--primary-light) !important;
            box-shadow: 0 0 0 2px rgba(59,130,246,0.2) !important;
        }
        .image-upload.mouse-hover .image-upload-label {
            border-color: #06B6D4 !important;
            background: #ECFEFF !important;
            color: #06B6D4 !important;
        }
        .image-upload.paste-flash .image-upload-label {
            animation: orderItemPasteFlash .6s ease;
        }
        @keyframes orderItemPasteFlash {
            0%   { box-shadow: 0 0 0 0 rgba(16,185,129,0); }
            30%  { box-shadow: 0 0 0 6px rgba(16,185,129,0.35); }
            100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); }
        }
        .image-upload-label .paste-tip {
            font-size: 10px;
            color: #94A3B8;
            display: block;
            margin-top: 2px;
        }
        .image-upload-label .paste-tip b {
            color: var(--primary);
        }
        .image-upload-label .existing-thumb {
            width: 30px;
            height: 30px;
            border-radius: 4px;
            object-fit: cover;
            margin-right: 4px;
        }
        .image-preview {
            position: absolute;
            top: 100%;
            right: 0;
            z-index: 100;
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-md);
            box-shadow: 0 8px 24px rgba(0,0,0,0.15);
            padding: 8px;
            display: none;
            margin-top: 4px;
        }
        .image-preview img {
            max-width: 200px;
            max-height: 200px;
            border-radius: var(--radius-sm);
        }

        @media (max-width: 1400px) {
            .item-row { grid-template-columns: 1.8fr 1fr 0.6fr 0.6fr 0.6fr 0.5fr 0.7fr 0.8fr auto; }
        }
        @media (max-width: 1200px) {
            .item-row { grid-template-columns: 1fr 1fr; gap: 12px; }
            .item-row > div:nth-child(1) { grid-column: 1 / -1; }
            .item-row > div:nth-child(2) { grid-column: 1 / -1; }
        }

        .customer-select { font-size: 15px; padding: 12px 16px; }
        .recent-bar {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding: 10px 14px;
            background: var(--gray-50);
            border-radius: var(--radius);
            margin-bottom: 12px;
            border: 1px dashed var(--gray-200);
        }
        .recent-bar-label {
            font-size: 12px;
            color: var(--gray-500);
            font-weight: 600;
            display: flex;
            align-items: center;
            flex-shrink: 0;
        }
        .recent-chip {
            flex-shrink: 0;
            padding: 6px 12px;
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: 99px;
            font-size: 13px;
            cursor: pointer;
            transition: var(--transition-base);
            white-space: nowrap;
        }
        .recent-chip:hover { background: var(--primary); color: white; border-color: var(--primary); }

        .searchable-select { position: relative; }
        .searchable-select .ss-trigger {
            padding: 10px 14px;
            font-size: 14px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            background: white;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            min-height: 42px;
        }
        .searchable-select .ss-trigger:hover { border-color: var(--primary-light); }
        .searchable-select .ss-trigger .ss-text { color: var(--gray-700); flex: 1; }
        .searchable-select .ss-trigger .ss-text.placeholder { color: var(--gray-400); }
        .searchable-select .ss-trigger .ss-arrow { color: var(--gray-400); font-size: 10px; margin-left: 8px; }
        .searchable-select .ss-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-md);
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
            max-height: 320px;
            overflow-y: auto;
            z-index: 100;
            display: none;
            margin-top: 4px;
        }
        .searchable-select.open .ss-dropdown { display: block; }
        .searchable-select .ss-search {
            width: 100%;
            padding: 8px 12px;
            border: none;
            border-bottom: 1px solid var(--gray-100);
            outline: none;
            font-size: 13px;
            background: var(--gray-50);
        }
        .searchable-select .ss-option {
            padding: 8px 12px;
            cursor: pointer;
            font-size: 13px;
            border-bottom: 1px solid var(--gray-50);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
        }
        .searchable-select .ss-option:hover { background: var(--primary-light); }
        .searchable-select .ss-option.active { background: var(--primary); color: white; }
        .searchable-select .ss-option .opt-name { flex: 1; }
        .searchable-select .ss-option .opt-meta { font-size: 11px; color: var(--gray-400); }
        .searchable-select .ss-option.active .opt-meta { color: rgba(255,255,255,0.7); }
        .searchable-select .ss-group-label {
            padding: 4px 12px;
            background: var(--gray-50);
            color: var(--gray-500);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--gray-100);
        }
        .searchable-select .ss-usage {
            background: var(--primary);
            color: white;
            font-size: 10px;
            padding: 1px 6px;
            border-radius: 99px;
            font-weight: 600;
        }
        .searchable-select .ss-option.active .ss-usage { background: white; color: var(--primary); }
        .searchable-select .ss-empty { padding: 20px; text-align: center; color: var(--gray-400); font-size: 13px; }

        .price-hint {
            font-size: 11px;
            color: var(--gray-400);
            margin-top: 4px;
            padding-left: 4px;
            line-height: 1.5;
        }
        .price-hint strong { color: var(--primary); font-weight: 600; }

        .order-total-bar {
            background: var(--gradient-hero);
            border-radius: var(--radius-xl);
            padding: 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 32px;
            color: white;
        }
        .order-total-bar .label { font-size: 16px; opacity: 0.9; }
        .order-total-bar .amount { font-size: 42px; font-weight: 800; letter-spacing: -2px; }

        .edit-warning { background: #FEF3C7; border: 2px solid #F59E0B; border-radius: var(--radius-lg); padding: 16px 20px; margin-bottom: 24px; display: flex; align-items: center; gap: 12px; }
        .edit-warning .icon { font-size: 24px; }
        .edit-warning .text { flex: 1; }
        .edit-warning .text strong { display: block; margin-bottom: 4px; }
        .edit-warning .text span { color: #92400E; font-size: 13px; }
    </style>
</head>
<body>
    <?php echo renderNav('orders'); ?>

    <div class="page-title-bar">
        <div class="page-title-left">
            <h1>修改订单</h1>
            <p>订单号:<?php echo htmlspecialchars($order['order_no']); ?> | 当前状态:<?php echo getStatusText($order['status']); ?></p>
        </div>
        <div class="page-title-actions">
            <a href="order_view.php?id=<?php echo $id; ?>" class="btn btn-ghost">← 返回详情</a>
        </div>
    </div>

    <?php if (isset($error)): ?>
    <div class="alert alert-danger" style="margin-bottom: 20px;">❌ <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($order['status'] == 1): ?>
    <div class="edit-warning">
        <div class="icon">⚠️</div>
        <div class="text">
            <strong>订单已确认</strong>
            <span>修改订单明细会影响生产安排,请谨慎操作。建议修改后重新通知生产部门。</span>
        </div>
    </div>
    <?php endif; ?>

    <form method="POST" id="orderForm" enctype="multipart/form-data" onsubmit="return beforeOrderSubmit()">
        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

        <div class="card">
            <div class="card-header">
                <div class="card-title"><span class="title-icon">👤</span> 客户信息</div>
            </div>
            <div class="card-body">
                <div style="margin-bottom: 20px;">
                    <label style="margin-right: 24px; cursor: pointer;">
                        <input type="radio" name="customer_type" value="existing" <?php echo $order['customer_id'] ? 'checked' : ''; ?> onchange="toggleCustomer('existing')" style="margin-right: 8px;">
                        <span style="font-weight: 500;">选择已有客户</span>
                    </label>
                    <label style="cursor: pointer;">
                        <input type="radio" name="customer_type" value="new" onchange="toggleCustomer('new')" style="margin-right: 8px;">
                        <span style="font-weight: 500;">添加新客户</span>
                    </label>
                </div>

                <div id="existingCustomer">
                    <div class="form-group">
                        <label class="form-label">选择客户 <span class="required">*</span></label>
                        <select name="customer_id" class="form-control customer-select">
                            <option value="">请选择客户</option>
                            <?php foreach ($customers as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $c['id'] == $order['customer_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['name']); ?> (<?php echo $c['phone'] ?: '无电话'; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div id="newCustomer" style="display:none;">
                    <div class="form-row form-row-4">
                        <div class="form-group">
                            <label class="form-label">客户名称 <span class="required">*</span></label>
                            <input type="text" name="new_customer_name" class="form-control" placeholder="客户名称">
                        </div>
                        <div class="form-group">
                            <label class="form-label">联系人</label>
                            <input type="text" name="contact" class="form-control" placeholder="联系人姓名">
                        </div>
                        <div class="form-group">
                            <label class="form-label">联系电话</label>
                            <input type="text" name="phone" class="form-control" placeholder="联系电话">
                        </div>
                        <div class="form-group">
                            <label class="form-label">地址</label>
                            <input type="text" name="address" class="form-control" placeholder="客户地址">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-title"><span class="title-icon">📅</span> 订单日期</div>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">开单日期 <span class="required">*</span></label>
                    <input type="date" name="order_date" class="form-control" value="<?php echo htmlspecialchars($order['order_date'] ?? date('Y-m-d', strtotime($order['created_at']))); ?>" style="max-width: 300px;">
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-title"><span class="title-icon">📦</span> 订单明细</div>
            </div>
            <div class="card-body">
                <?php if (!empty($recentProducts)): ?>
                <div class="recent-bar">
                    <span class="recent-bar-label">🕐 最近用过:</span>
                    <?php foreach ($recentProducts as $rp): ?>
                    <span class="recent-chip" data-product="<?php echo htmlspecialchars($rp['product_name']); ?>" onclick="quickFill(this)">
                        <?php echo htmlspecialchars($rp['product_name']); ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div style="background: var(--gray-50); border-radius: var(--radius-lg); padding: 20px; margin-bottom: 20px; border: 2px dashed var(--gray-300);">
                    <!-- 【2026-09-08 新增】批量修改工具栏 -->
                    <div style="background:white;border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin-bottom:16px;">
                        <div style="display:flex;align-items:center;flex-wrap:wrap;gap:10px;font-size:13px;">
                            <label style="display:flex;align-items:center;gap:4px;cursor:pointer;color:#475569;font-weight:500;">
                                <input type="checkbox" id="batchSelectAll" onchange="toggleBatchSelectAll(this.checked)" style="cursor:pointer;"> 全选
                            </label>
                            <span style="color:#cbd5e1;">|</span>
                            <span style="color:#0e7490;font-weight:600;">批量修改:</span>
                            <label style="display:flex;align-items:center;gap:4px;color:#475569;">产品名称
                                <span style="position:relative;display:inline-block;">
                                    <input type="text" id="batchProductInput" list="batchProductDatalist" placeholder="— 选择产品 —" autocomplete="off" style="width:150px;padding:4px 24px 4px 8px;border:1px solid #cbd5e1;border-radius:4px;cursor:pointer;">
                                    <span style="position:absolute;right:8px;top:50%;transform:translateY(-50%);color:#475569;font-size:10px;pointer-events:none;">▼</span>
                                </span>
                                <datalist id="batchProductDatalist"></datalist>
                            </label>
                            <button type="button" onclick="applyBatchProduct()" style="background:#0e7490;color:white;border:none;padding:4px 10px;border-radius:4px;cursor:pointer;font-size:12px;">应用产品名</button>
                            <label style="display:flex;align-items:center;gap:4px;color:#475569;">材质
                                <input type="text" id="batchMaterialInput" placeholder="选填填入" style="width:120px;padding:4px 8px;border:1px solid #cbd5e1;border-radius:4px;">
                            </label>
                            <label style="display:flex;align-items:center;gap:4px;color:#475569;">单价 ¥
                                <input type="number" id="batchPriceInput" placeholder="0.00" step="0.01" min="0" style="width:80px;padding:4px 8px;border:1px solid #cbd5e1;border-radius:4px;">
                            </label>
                            <label style="display:flex;align-items:center;gap:4px;color:#475569;">单位
                                <select id="batchUnitInput" style="padding:4px 8px;border:1px solid #cbd5e1;border-radius:4px;">
                                    <option value="">不修改</option>
                                    <option value="个">个</option>
                                    <option value="张">张</option>
                                    <option value="本">本</option>
                                    <option value="套">套</option>
                                    <option value="盒">盒</option>
                                    <option value="次">次</option>
                                    <option value="米">米</option>
                                    <option value="厘米">厘米</option>
                                    <option value="件">件</option>
                                    <option value="人">人</option>
                                    <option value="平方米">平方米</option>
                                    <option value="平方">平方</option>
                                    <option value="m²">m²</option>
                                    <option value="㎡">㎡</option>
                                </select>
                            </label>
                            <button type="button" onclick="applyBatchEdit()" style="background:#0e7490;color:white;border:none;padding:6px 14px;border-radius:4px;cursor:pointer;font-weight:500;">✓ 应用到选中</button>
                            <span id="batchSelectedCount" style="color:#64748b;font-size:12px;">已选 0 项</span>
                        </div>
                    </div>
                    <div id="itemsContainer"></div>
                    <button type="button" class="btn btn-success" onclick="addItem()" style="margin-top:12px;">➕ 添加产品</button>
                </div>

                <div class="form-group" style="margin-top:24px;">
                    <label class="form-label">订单备注</label>
                    <textarea name="order_remark" class="form-control" rows="3" placeholder="订单备注信息,如:交货时间、特殊要求等"><?php echo htmlspecialchars($order['remark']); ?></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">优惠金额(尾数减免,可不填)</label>
                    <input type="number" name="discount_input_ui" id="discountInputUI" value="<?php echo $order['discount_amount'] ?? 0; ?>" min="0" step="0.01"
                        placeholder="如客户抹零,输入减免金额"
                        style="width:100%;padding:10px 12px;border:2px solid #F97316;border-radius:10px;font-size:15px;box-sizing:border-box;color:#F97316;font-weight:600;"
                        oninput="updateDiscountDisplay()">
                </div>

                <div class="order-total-bar">
                    <div class="label">产品合计</div>
                    <div class="amount" id="productTotal">¥<?php echo number_format($order['total_amount'], 2); ?></div>
                </div>
                <div class="order-total-bar" style="border-top:1px dashed #E5E7EB;margin-top:8px;padding-top:8px;">
                    <div class="label">优惠金额</div>
                    <div class="amount" style="color:#F97316;font-size:16px;">
                        -¥<span id="discountDisplay"><?php echo number_format($order['discount_amount'] ?? 0, 2); ?></span>
                    </div>
                </div>
                <div class="order-total-bar" style="border-top:2px solid #3B82F6;margin-top:8px;padding-top:10px;">
                    <div class="label" style="color:#3B82F6;font-weight:700;">应付金额</div>
                    <div class="amount" id="totalAmount" style="color:#3B82F6;font-weight:700;">¥<?php echo number_format(max(0, $order['total_amount'] - ($order['discount_amount'] ?? 0)), 2); ?></div>
                </div>

                <input type="hidden" name="discount_amount" id="discountInput" value="<?php echo $order['discount_amount'] ?? 0; ?>">

                <div style="text-align: center; margin-top: 32px;">
                    <button type="submit" class="btn btn-primary btn-lg">✅ 保存修改</button>
                    <a href="order_view.php?id=<?php echo $id; ?>" class="btn btn-ghost btn-lg">取消</a>
                </div>
            </div>
        </div>
    </form>

    <?php echo renderFooter(); ?>

    <script>
    var ALL_PRODUCTS = <?php echo $jsProductsJson; ?>;
    var CURRENT_ROW_INDEX = 0;
    // [2026-09-07] 单据产品硬上限:max_file_uploads=200 / max_input_vars=5000 的安全边界
    var MAX_ITEMS_PER_ORDER = 200;
    var MAX_ITEMS_WARN = 180;
    function updateItemCount() {
        var rows = document.querySelectorAll('#itemsContainer .item-row');
        var n = rows.length;
        var numEl = document.getElementById('itemCountNum');
        var boxEl = document.getElementById('itemCountDisplay');
        var btnEl = document.getElementById('addItemBtn');
        if (!numEl || !boxEl || !btnEl) return;
        boxEl.innerHTML = '已添加 <strong id="itemCountNum" style="color:#0e7490;">' + n + '</strong> / ' + MAX_ITEMS_PER_ORDER + ' 个产品';
        if (n >= MAX_ITEMS_PER_ORDER) {
            boxEl.style.background = '#fee2e2'; boxEl.style.color = '#7f1d1d';
            boxEl.querySelector('strong').style.color = '#dc2626';
            btnEl.disabled = true; btnEl.style.opacity = '0.45'; btnEl.style.cursor = 'not-allowed';
            btnEl.title = '已达单据上限 ' + MAX_ITEMS_PER_ORDER + ' 个产品';
        } else if (n >= MAX_ITEMS_WARN) {
            boxEl.style.background = '#fef3c7'; boxEl.style.color = '#78350f';
            boxEl.querySelector('strong').style.color = '#ea580c';
            btnEl.disabled = false; btnEl.style.opacity = '1'; btnEl.style.cursor = 'pointer';
        } else {
            boxEl.style.background = '#f1f5f9'; boxEl.style.color = '#475569';
            boxEl.querySelector('strong').style.color = '#0e7490';
            btnEl.disabled = false; btnEl.style.opacity = '1'; btnEl.style.cursor = 'pointer';
        }
    }
    var INITIAL_ITEMS = <?php echo json_encode($items, JSON_UNESCAPED_UNICODE); ?>;

    function renderItemRow(index, prefill) {
        prefill = prefill || {};
        // 标准化字段,避免 null 报错
        var productName = prefill.product_name || '';
        var spec = prefill.specification || '';
        var lengthVal = prefill.length || '';
        var widthVal = prefill.width || '';
        var squareVal = prefill.square_meter || 0;
        var qtyVal = prefill.quantity || 1;
        var unitVal = prefill.unit || '';
        var priceVal = prefill.unit_price || 0;
        var amountVal = parseFloat(prefill.amount) || 0;
        var remarkVal = prefill.remark || '';
        var imgPath = prefill.image_path || '';
        var hasImage = !!imgPath;
        var imageHtml = '';

        if (hasImage) {
            imageHtml = '<div class="image-upload">' +
                '<input type="file" name="item_image_' + index + '" id="item_image_' + index + '" accept="image/*" onchange="handleImageSelect(this)">' +
                '<label for="item_image_' + index + '" class="image-upload-label has-image" data-upload-label>' +
                    '<div style="display:flex;flex-direction:column;align-items:center;gap:2px;line-height:1.3;">' +
                        '<div><img src="' + escapeHtml(imgPath) + '" class="existing-thumb" alt=""><span data-label-text>已上传 / 重新粘贴</span></div>' +
                        '<span class="paste-tip"><b>Ctrl+V</b> 粘贴截图</span>' +
                    '</div>' +
                '</label>' +
                '<input type="hidden" name="items[' + index + '][existing_image]" value="' + escapeHtml(imgPath) + '">' +
                '<input type="hidden" name="items[' + index + '][image_url]" value="">' +
                '<div class="image-preview" data-image-preview><img src="' + escapeHtml(imgPath) + '" alt="预览"></div>' +
            '</div>';
        } else {
            imageHtml = '<div class="image-upload">' +
                '<input type="file" name="item_image_' + index + '" id="item_image_' + index + '" accept="image/*" onchange="handleImageSelect(this)">' +
                '<label for="item_image_' + index + '" class="image-upload-label" data-upload-label>' +
                    '<div style="display:flex;flex-direction:column;align-items:center;gap:2px;line-height:1.3;">' +
                        '<span><span>📎</span> <span data-label-text>上传图片 / 拖入 / 粘贴</span></span>' +
                        '<span class="paste-tip"><b>Ctrl+V</b> 粘贴截图</span>' +
                    '</div>' +
                '</label>' +
                '<input type="hidden" name="items[' + index + '][existing_image]" value="">' +
                '<input type="hidden" name="items[' + index + '][image_url]" value="">' +
                '<div class="image-preview" data-image-preview><img src="" alt="预览"></div>' +
            '</div>';
        }

        var isPlaceholder = !productName ? 'placeholder' : '';
        var squareText = squareVal ? '平方数: ' + parseFloat(squareVal).toFixed(2) + ' m2' : '平方数: 0.00 m2';

        var historyHint = '';
        if (productName) {
            var p = ALL_PRODUCTS.find(function(x){ return x.name === productName; });
            if (p && p.history && p.history.length > 0) {
                historyHint = '历史: ';
                p.history.forEach(function(h, i) {
                    if (i > 0) historyHint += ' / ';
                    historyHint += '<strong>¥' + h.price.toFixed(2) + '</strong>(' + h.date + ')';
                });
            } else if (unitVal === 'm2') {
                historyHint = '<span style="color:var(--info);">💡 填写长宽自动计算平方</span>';
            }
        }

        return '<div class="item-row" data-row="' + index + '" data-row-seq="' + index + '" style="position:relative;">' +
            // 【2026-09-08 新增】序号 + 勾选框 (绝对定位,不破坏 grid 布局)
            '<label class="item-row-selector" style="position:absolute;top:6px;left:6px;display:flex;align-items:center;gap:3px;background:rgba(14,116,144,0.08);border:1px solid rgba(14,116,144,0.25);border-radius:4px;padding:1px 6px;cursor:pointer;z-index:5;font-size:11px;color:#0e7490;font-weight:600;user-select:none;">' +
                '<input type="checkbox" class="batch-item-checkbox" onchange="updateBatchSelectedCount()" style="cursor:pointer;margin:0;">' +
                '<span class="item-seq-num">#' + (index + 1) + '</span>' +
            '</label>' +
            '<div>' +
                '<span class="field-label">产品名称</span>' +
                '<div class="searchable-select" data-row="' + index + '">' +
                    '<div class="ss-trigger" onclick="openDropdown(this)">' +
                        '<span class="ss-text ' + isPlaceholder + '">' + escapeHtml(productName || '- 选择产品 -') + '</span>' +
                        '<span class="ss-arrow">▼</span>' +
                    '</div>' +
                    '<div class="ss-dropdown">' +
                        '<input type="text" class="ss-search" placeholder="🔍 搜索产品..." oninput="filterOptions(this)">' +
                        '<div class="ss-list" data-list></div>' +
                    '</div>' +
                '</div>' +
                '<input type="hidden" name="items[' + index + '][product_name]" data-product-name-input value="' + escapeHtml(productName) + '">' +
            '</div>' +
            '<div>' +
                '<span class="field-label">规格说明</span>' +
                '<input type="text" name="items[' + index + '][specification]" class="form-control" placeholder="材质、工艺等" value="' + escapeHtml(spec) + '">' +
            '</div>' +
            '<div>' +
                '<span class="field-label">尺寸(米)</span>' +
                '<div class="size-inputs">' +
                    '<input type="number" name="items[' + index + '][length]" class="form-control length" step="0.01" placeholder="长" value="' + escapeHtml(lengthVal) + '" oninput="calcSquare(this)">' +
                    '<span>×</span>' +
                    '<input type="number" name="items[' + index + '][width]" class="form-control width" step="0.01" placeholder="宽" value="' + escapeHtml(widthVal) + '" oninput="calcSquare(this)">' +
                '</div>' +
                '<div class="square-display" data-square-display>' + squareText + '</div>' +
                '<input type="hidden" name="items[' + index + '][square_meter]" class="square-meter" value="' + escapeHtml(squareVal) + '">' +
            '</div>' +
            '<div>' +
                '<span class="field-label">数量</span>' +
                '<input type="number" name="items[' + index + '][quantity]" class="form-control qty" step="0.01" value="' + escapeHtml(qtyVal) + '" oninput="calcAmount(this)">' +
            '</div>' +
            '<div>' +
                '<span class="field-label">单位</span>' +
                '<input type="text" name="items[' + index + '][unit]" class="form-control unit" placeholder="个/m2" value="' + escapeHtml(unitVal) + '">' +
            '</div>' +
            '<div>' +
                '<span class="field-label">单价</span>' +
                '<input type="number" name="items[' + index + '][unit_price]" class="form-control price" step="0.01" value="' + escapeHtml(priceVal) + '" oninput="calcAmount(this)">' +
                '<div class="price-hint" data-price-hint>' + historyHint + '</div>' +
            '</div>' +
            '<div>' +
                '<span class="field-label">金额</span>' +
                '<input type="text" class="amount-display" readonly value="¥' + amountVal.toFixed(2) + '">' +
            '</div>' +
            '<div>' +
                '<span class="field-label">参考图</span>' +
                imageHtml +
            '</div>' +
            '<div style="padding-top: 22px;">' +
                '<button type="button" class="btn btn-danger btn-sm" onclick="removeItem(this)">删除</button>' +
            '</div>' +
            '<input type="hidden" name="items[' + index + '][remark]" value="' + escapeHtml(remarkVal) + '">' +
        '</div>';
    }

    function renderSearchableSelect(rowIndex) {
        return '<div class="searchable-select" data-row="' + rowIndex + '">' +
            '<div class="ss-trigger" onclick="openDropdown(this)">' +
                '<span class="ss-text placeholder">- 选择产品 -</span>' +
                '<span class="ss-arrow">▼</span>' +
            '</div>' +
            '<div class="ss-dropdown">' +
                '<input type="text" class="ss-search" placeholder="🔍 搜索产品..." oninput="filterOptions(this)">' +
                '<div class="ss-list" data-list></div>' +
            '</div>' +
        '</div>';
    }

    function openDropdown(trigger) {
        var ss = trigger.closest('.searchable-select');
        document.querySelectorAll('.searchable-select.open').forEach(function(s){
            if (s !== ss) s.classList.remove('open');
        });
        ss.classList.toggle('open');
        if (ss.classList.contains('open')) {
            var search = ss.querySelector('.ss-search');
            search.value = '';
            renderOptions(ss, '');
            setTimeout(function(){ search.focus(); }, 50);
        }
    }

    function renderOptions(ss, keyword) {
        var list = ss.querySelector('[data-list]');
        var kw = keyword.trim().toLowerCase();
        var filtered = ALL_PRODUCTS.filter(function(p) {
            if (!kw) return true;
            return p.name.toLowerCase().indexOf(kw) >= 0 || p.category.toLowerCase().indexOf(kw) >= 0;
        });

        if (filtered.length === 0) {
            list.innerHTML = '<div class="ss-empty">未找到匹配产品</div>';
            return;
        }

        var groups = {};
        filtered.forEach(function(p) {
            if (!groups[p.category]) groups[p.category] = [];
            groups[p.category].push(p);
        });

        Object.keys(groups).forEach(function(cat) {
            groups[cat].sort(function(a, b) { return b.usage - a.usage; });
        });

        var html = '';
        var sortedCats = Object.keys(groups).sort(function(a, b) {
            if (a === '未分类') return 1;
            if (b === '未分类') return -1;
            return a.localeCompare(b);
        });

        sortedCats.forEach(function(cat) {
            html += '<div class="ss-group-label">' + escapeHtml(cat) + '(' + groups[cat].length + ')</div>';
            groups[cat].forEach(function(p) {
                var usageTag = p.usage > 0 ? '<span class="ss-usage">' + p.usage + '</span>' : '';
                var meta = [];
                if (p.unit) meta.push(p.unit);
                if (p.price) meta.push('¥' + p.price.toFixed(2));
                var metaStr = meta.length ? '<span class="opt-meta">' + escapeHtml(meta.join(' · ')) + '</span>' : '';
                html += '<div class="ss-option" data-name="' + escapeHtml(p.name) + '" data-unit="' + escapeHtml(p.unit || '') + '" data-price="' + p.price + '" data-history=\'' + JSON.stringify(p.history) + '\' onclick="selectProduct(this)">' +
                    '<span class="opt-name">' + highlightMatch(p.name, kw) + '</span>' +
                    metaStr + usageTag +
                '</div>';
            });
        });

        list.innerHTML = html;
    }

    function filterOptions(input) {
        var ss = input.closest('.searchable-select');
        renderOptions(ss, input.value);
    }

    function selectProduct(option) {
        var ss = option.closest('.searchable-select');
        var row = ss.closest('.item-row');
        var name = option.dataset.name;
        var unit = option.dataset.unit || '';
        var price = parseFloat(option.dataset.price) || 0;
        var history = JSON.parse(option.dataset.history || '[]');

        ss.querySelector('.ss-text').textContent = name;
        ss.querySelector('.ss-text').classList.remove('placeholder');
        ss.classList.remove('open');

        row.querySelector('[data-product-name-input]').value = name;
        row.querySelector('.unit').value = unit;
        row.querySelector('.price').value = price || 0;

        var hint = row.querySelector('[data-price-hint]');
        if (unit === 'm2') {
            hint.innerHTML = '<span style="color:var(--info);">💡 填写长宽自动计算平方</span>';
        } else if (history.length > 0) {
            var historyHtml = '历史: ';
            history.forEach(function(h, i) {
                if (i > 0) historyHtml += ' / ';
                historyHtml += '<strong>¥' + h.price.toFixed(2) + '</strong>(' + h.date + ')';
            });
            hint.innerHTML = historyHtml;
        } else {
            hint.innerHTML = '';
        }

        calcAmount(row.querySelector('.price'));
    }

    function highlightMatch(text, kw) {
        if (!kw) return escapeHtml(text);
        var idx = text.toLowerCase().indexOf(kw);
        if (idx < 0) return escapeHtml(text);
        return escapeHtml(text.substring(0, idx)) + '<mark style="background:#fef08a;padding:0;">' + escapeHtml(text.substring(idx, idx + kw.length)) + '</mark>' + escapeHtml(text.substring(idx + kw.length));
    }

    function calcSquare(el) {
        var row = el.closest('.item-row');
        var length = parseFloat(row.querySelector('.length').value) || 0;
        var width = parseFloat(row.querySelector('.width').value) || 0;
        var square = length * width;

        row.querySelector('.square-meter').value = square.toFixed(2);
        row.querySelector('[data-square-display]').textContent = '平方数: ' + square.toFixed(2) + ' m2';

        calcAmount(row.querySelector('.price'));
    }

    function calcAmount(el) {
        var row = el.closest('.item-row');
        var qty = parseFloat(row.querySelector('.qty').value) || 0;
        var price = parseFloat(row.querySelector('.price').value) || 0;
        var unit = row.querySelector('.unit').value || '';
        var square = parseFloat(row.querySelector('.square-meter').value) || 0;

        var amount;
        // 有尺寸且单位是面积类 → 平方数×单价×数量
        if (square > 0 && (unit === 'm2' || unit === '平方米' || unit === 'm2' || unit === '平方')) {
            amount = square * price * qty;
        } else {
            amount = qty * price;
        }

        row.querySelector('.amount-display').value = '¥' + amount.toFixed(2);
        calcTotal();
    }

    function calcTotal() {
        var total = 0;
        document.querySelectorAll('.item-row').forEach(function(row) {
            var qty = parseFloat(row.querySelector('.qty').value) || 0;
            var price = parseFloat(row.querySelector('.price').value) || 0;
            var unit = row.querySelector('.unit').value || '';
            var square = parseFloat(row.querySelector('.square-meter').value) || 0;

            // 有尺寸且单位是面积类 → 平方数×单价×数量
            if (square > 0 && (unit === 'm2' || unit === '平方米' || unit === 'm2' || unit === '平方')) {
                total += square * price * qty;
            } else {
                total += qty * price;
            }
        });
        document.getElementById('productTotal').textContent = '¥' + total.toFixed(2);
        updateDiscountDisplay();
    }

    function updateDiscountDisplay() {
        var discount = parseFloat(document.getElementById('discountInputUI').value) || 0;
        document.getElementById('discountInput').value = discount;
        document.getElementById('discountDisplay').textContent = discount.toFixed(2);
        var productTotal = 0;
        document.querySelectorAll('.item-row').forEach(function(row) {
            var inp = row.querySelector('.item-amount-input');
            var amt = inp ? (parseFloat(inp.value) || 0) : 0;
            productTotal += amt;
        });
        document.getElementById('totalAmount').textContent = '¥' + Math.max(0, productTotal - discount).toFixed(2);
    }

    // ===================== handleImageSelect (异步上传版) =====================
    // 流程: 选图 → 压缩 60KB → 异步 POST 到 item_upload_image → 拿 URL 填到 hidden input
    // 同步调 parseFileName + fillRowFromParsed 自动填产品/规格/单位/单价
    // 拖拽、粘贴都走 applyItemPastedFile 走同一路径
    function handleImageSelect(input) {
        var label = input.closest('.image-upload').querySelector('[data-upload-label]');
        var labelText = input.closest('.image-upload').querySelector('[data-label-text]');
        var preview = input.closest('.image-upload').querySelector('[data-image-preview]');
        var previewImg = preview ? preview.querySelector('img') : null;

        if (!input.files || !input.files[0]) return;
        var file = input.files[0];

        // 立即显示文件名 + 预览 base64
        label.classList.add('has-image');
        labelText.textContent = file.name.length > 10 ? file.name.substring(0, 10) + '...' : file.name;

        var reader = new FileReader();
        reader.onload = function(e) {
            if (previewImg) previewImg.src = e.target.result;
        };
        reader.readAsDataURL(file);

        // 隐藏 existing-thumb
        var existingThumb = label.querySelector('.existing-thumb');
        if (existingThumb) existingThumb.style.display = 'none';
        labelText.textContent = '上传中: ' + (file.name.length > 12 ? file.name.substring(0, 12) + '...' : file.name);

        label.onmouseenter = function() { if (preview) preview.style.display = 'block'; };
        label.onmouseleave = function() { if (preview) preview.style.display = 'none'; };

        // 取出 item_index: item_image_5 → 5
        var m = (input.name || '').match(/^item_image_(\d+)$/);
        var itemIndex = m ? m[1] : null;
        if (itemIndex === null) return;

        // 异步压缩 + 上传 + 填表
        (function(originalFile, srcInput, itemIndexStr){
            // 调父页面的 compressImage(order_create.php 是 order_create, order_edit 复用同一函数体)
            var compressFn = (typeof window.compressImage === 'function') ? window.compressImage : null;
            // 创建 promise 以便 beforeOrderSubmit 等待所有上传完成
            var uploadPromise = Promise.resolve(compressFn ? compressFn(originalFile, 150) : originalFile).then(function(compressed){
                var fd = new FormData();
                fd.append('ajax_action', 'item_upload_image');
                fd.append('item_index', itemIndexStr);
                fd.append('item_image_' + itemIndexStr, compressed, originalFile.name);
                var csrfInput = document.querySelector('input[name="csrf_token"]');
                if (csrfInput) fd.append('csrf_token', csrfInput.value);
                // fetch + 10秒超时 (避免后台卡死导致保存死等)
                return Promise.race([
                    fetch(window.location.pathname + window.location.search, {
                        method: 'POST', body: fd, credentials: 'same-origin', redirect: 'manual'
                    }),
                    new Promise(function(_, reject){
                        setTimeout(function(){ reject(new Error('上传超时 (10秒) - 请检查网络或后端')); }, 10000);
                    })
                ]).then(function(resp){
                    if (resp.type === 'opaqueredirect' || resp.status === 302 || resp.status === 301) {
                        throw new Error('登录已过期,请刷新页面重新登录');
                    }
                    return resp.text();
                }).then(function(raw){
                    try { return JSON.parse(raw); }
                    catch (e) { throw new Error('后端返回非JSON: ' + raw.substring(0, 200)); }
                });
            }).then(function(data){
                if (!data.ok) throw new Error(data.msg || '上传失败');
                // 拿 URL → 填入 hidden input
                // 之前用 items[${itemIndexStr}][image_url] selector - 但是 CURRENT_ROW_INDEX 跑到 80+ 后 selector 不匹配
                // 现在直接用 srcInput.closest('.item-row').querySelector('input[name$="[image_url]"]') - 同一行只会有一个
                var urlInput = srcInput.closest('.item-row') && srcInput.closest('.item-row').querySelector('input[name$="[image_url]"]');
                if (urlInput) {
                    urlInput.value = data.url;
                    console.log('[handleImageSelect] urlInput.value 填成功, selector=items[' + (urlInput.name.match(/\[\d+\]/) || ['?'])[0] + '][image_url], row=' + (srcInput.closest('.item-row') ? srcInput.closest('.item-row').className : '?'));
                } else {
                    console.warn('[handleImageSelect] 找不到 [image_url] hidden input in current row');
                }
                // 改 label 文案
                var labelEl = srcInput.closest('.image-upload').querySelector('[data-label-text]');
                if (labelEl) labelEl.textContent = '✓ ' + (originalFile.name.length > 12 ? originalFile.name.substring(0, 12) + '...' : originalFile.name);
                console.log('[handleImageSelect] 上传成功:', data.url, '(' + data.size + ' bytes)');
                // 自动填表(产品名/规格/尺寸/单位/单价/平方数)——复用 order_create.php 的 fillRowFromParsed
                try {
                    if (typeof parseFileName === 'function' && typeof fillRowFromParsed === 'function') {
                        var parsed = parseFileName(originalFile.name);
                        var row = srcInput.closest('.item-row');
                        if (parsed && parsed.product_name && row) {
                            var r = fillRowFromParsed(row, parsed, '', { force: false });
                            console.log('[文件名识别]', originalFile.name, '→', r);
                            if (typeof showToast === 'function') {
                                showToast('已识别 ' + parsed.product_name + ' → ' + (r.filled.length ? r.filled.join('/') : '无新字段'), 'success');
                            }
                        }
                    }
                } catch (e) {
                    console.warn('[handleImageSelect] fillRowFromParsed 失败:', e);
                }
                return { ok: true, url: data.url };
            }).catch(function(err){
                console.error('[handleImageSelect] 上传失败:', err);
                var labelEl = srcInput.closest('.image-upload').querySelector('[data-label-text]');
                if (labelEl) labelEl.textContent = '✗ 上传失败: ' + err.message.substring(0, 20);
                if (typeof showToast === 'function') showToast('上传失败: ' + err.message, 'error');
                else alert('上传失败: ' + err.message);
                return { ok: false, error: err.message };
            }).finally(function(){
                // 从 pendingUploads 里移除
                if (window.__pendingUploads && window.__pendingUploads[itemIndexStr]) {
                    delete window.__pendingUploads[itemIndexStr];
                    var cnt = Object.keys(window.__pendingUploads).length;
                    var tip = document.getElementById('__pending_upload_tip');
                    if (tip) {
                        if (cnt === 0) tip.style.display = 'none';
                        else tip.textContent = '⏳ ' + cnt + ' 张图片上传中,请勿点保存...';
                    }
                }
            });
            // 登记到全局 pending map, 让 beforeOrderSubmit 等待
            if (!window.__pendingUploads) window.__pendingUploads = {};
            window.__pendingUploads[itemIndexStr] = uploadPromise;
            var tip = document.getElementById('__pending_upload_tip');
            if (!tip) {
                tip = document.createElement('div');
                tip.id = '__pending_upload_tip';
                tip.style.cssText = 'position:fixed;bottom:80px;right:20px;background:#fef3c7;color:#92400e;padding:8px 14px;border-radius:6px;z-index:9999;font-size:13px;box-shadow:0 2px 8px rgba(0,0,0,0.15);';
                document.body.appendChild(tip);
            }
            var cnt0 = Object.keys(window.__pendingUploads).length;
            tip.textContent = '⏳ ' + cnt0 + ' 张图片上传中,请勿点保存...';
            tip.style.display = 'block';
        })(file, input, itemIndex);
    }

    // ===================== 截图粘贴 + 拖拽支持 =====================
    /**
     * 将粘贴的 File 装入指定 input[type=file] 并触发预览
     */
    function applyItemPastedFile(file, input) {
        if (!file) return false;
        var okType = file.type && file.type.startsWith('image/');
        if (!okType) { alert('仅支持粘贴图片文件'); return false; }
        if (file.size > 10 * 1024 * 1024) { alert('文件超过 10MB 限制'); return false; }
        try {
            var dt = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;
        } catch (e) {
            alert('当前浏览器不支持该粘贴方式,请用 Chrome/Edge 或选择文件');
            return false;
        }
        handleImageSelect(input);
        var container = input.closest('.image-upload');
        if (container) {
            container.classList.add('paste-flash');
            setTimeout(function() { container.classList.remove('paste-flash'); }, 600);
        }
        return true;
    }

    var lastClickedItemInput = null;

    function initItemImagePasteUpload() {
        // 点击 .image-upload-label 记录对应 input
        document.addEventListener('click', function(e) {
            var lbl = e.target.closest && e.target.closest('.image-upload-label');
            if (lbl) {
                var container = lbl.closest('.image-upload');
                if (container) {
                    var input = container.querySelector('input[type="file"]');
                    if (input) {
                        lastClickedItemInput = input;
                        document.querySelectorAll('.image-upload.paste-active').forEach(function(el) {
                            el.classList.remove('paste-active');
                        });
                        container.classList.add('paste-active');
                    }
                }
            }
        });

        // 鼠标位置追踪 + 实时高亮:鼠标进入哪个 .image-upload 就高亮哪个
        // 用 mousemove + elementFromPoint 组合,确保准确
        function updateMouseHover(e) {
            var x = e.clientX, y = e.clientY;
            var el = document.elementFromPoint(x, y);
            if (el) {
                var container = el.closest && el.closest('.image-upload');
                var currentHover = document.querySelector('.image-upload.mouse-hover');
                if (container !== currentHover) {
                    if (currentHover) currentHover.classList.remove('mouse-hover');
                    if (container) container.classList.add('mouse-hover');
                }
            }
        }
        document.addEventListener('mousemove', updateMouseHover);
        document.addEventListener('mouseover', updateMouseHover);

        // 调试:在 console 打印 hover 状态
        window.__debugHover = function() {
            var h = document.querySelector('.image-upload.mouse-hover');
            if (!h) return 'NONE';
            var inp = h.querySelector('input[type="file"]');
            return 'hover: ' + (inp ? inp.name : '?');
        };

        // 全局 paste -- 鼠标位置优先策略
        document.addEventListener('paste', function(e) {
            var ae = document.activeElement;
            if (ae && (ae.tagName === 'TEXTAREA' ||
                (ae.tagName === 'INPUT' && ae.type !== 'file') ||
                ae.tagName === 'SELECT')) {
                return;
            }
            var items = (e.clipboardData || window.clipboardData).items || [];
            for (var i = 0; i < items.length; i++) {
                if (items[i].kind === 'file') {
                    var file = items[i].getAsFile();
                    if (!file) continue;

                    // 优先策略:鼠标位置(实时检测,不依赖 mouse-hover 类)
                    var targetInput = null;
                    var allRows = document.querySelectorAll('.image-upload');
                    var hoverRow = null;
                    // 优先用 mouse-hover 类
                    hoverRow = document.querySelector('.image-upload.mouse-hover');
                    // 备选:实时检查每个 .image-upload,看鼠标当前在哪个上面
                    if (!hoverRow) {
                        // 从最后操作过的 input 倒推(基于浏览器的鼠标坐标)
                        // 这里采用最可靠的方案:直接查 mouse-hover,如果没有则 fallback
                    }
                    if (hoverRow) {
                        targetInput = hoverRow.querySelector('input[type="file"]');
                    }
                    // 备选1: 焦点在 file input 上
                    if (!targetInput && ae && ae.tagName === 'INPUT' && ae.type === 'file' && ae.name && ae.name.indexOf('item_image_') === 0) {
                        targetInput = ae;
                    }
                    // 备选2: 最后点击过图片框
                    if (!targetInput && lastClickedItemInput) {
                        targetInput = lastClickedItemInput;
                    }
                    // 备选3: 页面上只有一个 input
                    if (!targetInput) {
                        var allInputs = document.querySelectorAll('input[type="file"][name^="item_image_"]');
                        if (allInputs.length === 1) {
                            targetInput = allInputs[0];
                        }
                    }

                    e.preventDefault();
                    if (!targetInput) {
                        showItemImagePicker(file);
                        return;
                    }
                    highlightTargetImageRow(targetInput);
                    applyItemPastedFile(file, targetInput);
                    return;
                }
            }
        });

        // 拖拽上传
        document.addEventListener('dragover', function(e) {
            var container = e.target.closest && e.target.closest('.image-upload');
            if (container) { e.preventDefault(); container.classList.add('paste-drag'); }
        });
        document.addEventListener('dragleave', function(e) {
            var container = e.target.closest && e.target.closest('.image-upload');
            if (container) { container.classList.remove('paste-drag'); }
        });
        document.addEventListener('drop', function(e) {
            var container = e.target.closest && e.target.closest('.image-upload');
            if (!container) return;
            e.preventDefault();
            container.classList.remove('paste-drag');
            var file = e.dataTransfer.files && e.dataTransfer.files[0];
            var input = container.querySelector('input[type="file"]');
            if (file && input) {
                lastClickedItemInput = input;
                applyItemPastedFile(file, input);
            }
        });
    }

    /**
     * 高亮显示"刚被粘贴/拖入"的目标行
     */
    function highlightTargetImageRow(input) {
        document.querySelectorAll('.image-upload.paste-active').forEach(function(el) {
            el.classList.remove('paste-active');
        });
        var container = input.closest('.image-upload');
        if (container) container.classList.add('paste-active');
    }

    /**
     * 鼠标位置查不到时,弹选择菜单
     */
    function showItemImagePicker(file) {
        var allInputs = document.querySelectorAll('input[type="file"][name^="item_image_"]');
        if (allInputs.length === 0) return;

        var options = [];
        allInputs.forEach(function(inp, idx) {
            var row = inp.closest('.item-row, [data-item-row], .order-item, tr');
            var productName = '';
            if (row) {
                var nameEl = row.querySelector('input[name*="[product_name]"], .product-name, .ss-text');
                if (nameEl) productName = nameEl.value || nameEl.textContent || '';
                if (!productName) {
                    var allInputsInRow = row.querySelectorAll('input[type="text"], input[type="number"]');
                    for (var i = 0; i < allInputsInRow.length; i++) {
                        if (allInputsInRow[i].value) { productName = allInputsInRow[i].value; break; }
                    }
                }
            }
            options.push((idx + 1) + '. 第' + (idx + 1) + '行' + (productName ? ' (' + productName.substring(0, 20) + ')' : ''));
        });

        var msg = '请选择要粘贴到的产品行:\n\n' + options.join('\n') + '\n\n输入行号(1-' + allInputs.length + '),或点取消';
        var choice = prompt(msg, '1');
        if (choice === null) return;
        var idx = parseInt(choice, 10) - 1;
        if (isNaN(idx) || idx < 0 || idx >= allInputs.length) {
            alert('无效选择');
            return;
        }
        var target = allInputs[idx];
        lastClickedItemInput = target;
        highlightTargetImageRow(target);
        applyItemPastedFile(file, target);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initItemImagePasteUpload);
    } else {
        initItemImagePasteUpload();
    }

    function addItem(prefill) {
        var container = document.getElementById('itemsContainer');
        // [2026-09-07] 硬限流
        var currentCount = container.querySelectorAll('.item-row').length;
        if (currentCount >= MAX_ITEMS_PER_ORDER) {
            if (typeof window.showToast === 'function') {
                window.showToast('已达单据上限 ' + MAX_ITEMS_PER_ORDER + ' 个产品,无法继续添加。如需更多请分单。', 'error');
            } else { alert('已达单据上限 ' + MAX_ITEMS_PER_ORDER + ' 个产品'); }
            updateItemCount(); return;
        }
        var html = renderItemRow(CURRENT_ROW_INDEX, prefill);
        container.insertAdjacentHTML('beforeend', html);
        CURRENT_ROW_INDEX++;
        calcTotal();
        updateItemCount();
        // 【2026-09-08】添加后重排所有行的序号
        refreshAllItemSeq();
    }

    // 提交前清空所有 <input type="file"> (图片已异步上传过,不需要 file input 进 form body)
    // 90+ 行订单下, 90 个空 file input 的 multipart segment 会让 post body 超过 PHP 默认 8M
    // 以及 max_input_vars 默认 1000, 导致 $_POST 和 $_FILES 同时被丢弃
    // ===================== 提交前等待所有图片上传完成 =====================
    var __submitInProgress = false;
    function beforeOrderSubmit() {
        if (__submitInProgress) { return false; }
        try {
            // 0) 如果还有 pending 上传, 等待 (最多 15 秒) - 防止 race condition (上传未完成就提交)
            if (window.__pendingUploads && Object.keys(window.__pendingUploads).length > 0) {
                __submitInProgress = true;
                var cnt = Object.keys(window.__pendingUploads).length;
                if (typeof showToast === 'function') {
                    showToast('⏳ 还有 ' + cnt + ' 张图片上传中,等待完成...', 'info');
                } else {
                    alert('还有 ' + cnt + ' 张图片上传中,请稍候...');
                }
                // 把表单提交按钮 disable,避免重复点
                var submitBtn = document.querySelector('#orderForm button[type="submit"], #orderForm input[type="submit"]');
                if (submitBtn) { submitBtn.disabled = true; submitBtn.dataset._origText = submitBtn.textContent; submitBtn.textContent = '上传中...'; }
                Promise.all(Object.values(window.__pendingUploads)).then(function() {
                    __submitInProgress = false;
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = submitBtn.dataset._origText || submitBtn.textContent; }
                    // 等待完成后, 重新触发提交 (用 form.submit() 绕过 beforeOrderSubmit 递归)
                    document.getElementById('orderForm').submit();
                }).catch(function(e) {
                    __submitInProgress = false;
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = submitBtn.dataset._origText || submitBtn.textContent; }
                    if (typeof showToast === 'function') showToast('上传失败,无法保存: ' + e.message, 'error');
                    else alert('上传失败,无法保存: ' + e.message);
                });
                return false; // 第一次点保存,先异步等,后面 Promise.all.then 里 form.submit() 绕过这个钩子
            }

            // 1) 清空所有 file input (避免空 multipart segment 浪费 body)
            var fileInputs = document.querySelectorAll('#orderForm input[type="file"]');
            fileInputs.forEach(function(inp) {
                try { inp.value = ''; } catch(e) { /* IE 10- 不能重置 file, 忽略 */ }
                // 不 remove, 只清空值 (PHP 仍会以 UPLOAD_ERR_NO_FILE 处理)
            });
            // 2) 给个提示
            if (typeof showToast === 'function') {
                showToast('正在保存...', 'info');
            }
            calcTotal();
            return true;
        } catch (e) {
            console.error('[beforeOrderSubmit] 失败:', e);
            alert('提交准备失败: ' + e.message);
            return false;
        }
    }

    function removeItem(btn) {
        var rows = document.querySelectorAll('.item-row');
        if (rows.length > 1) {
            btn.closest('.item-row').remove();
            calcTotal();
            updateItemCount();
            // 【2026-09-08】删除后重排所有行的序号
            refreshAllItemSeq();
        } else {
            alert('至少需要保留一个产品项'); updateItemCount();
        }
    }

    function quickFill(chip) {
        var productName = chip.dataset.product;
        var targetRow = null;
        var rows = document.querySelectorAll('.item-row');
        for (var i = 0; i < rows.length; i++) {
            if (!rows[i].querySelector('[data-product-name-input]').value) {
                targetRow = rows[i];
                break;
            }
        }
        if (!targetRow) {
            addItem({name: productName});
            return;
        }
        var p = ALL_PRODUCTS.find(function(x){ return x.name === productName; });
        if (!p) return;
        targetRow.querySelector('.searchable-select .ss-text').textContent = p.name;
        targetRow.querySelector('.searchable-select .ss-text').classList.remove('placeholder');
        targetRow.querySelector('[data-product-name-input]').value = p.name;
        targetRow.querySelector('.unit').value = p.unit || '';
        targetRow.querySelector('.price').value = p.price || 0;
        if (p.history && p.history.length > 0) {
            var hh = '历史: ';
            p.history.forEach(function(h, i){ if(i>0) hh += ' / '; hh += '<strong>¥' + h.price.toFixed(2) + '</strong>(' + h.date + ')'; });
            targetRow.querySelector('[data-price-hint]').innerHTML = hh;
        }
        calcAmount(targetRow.querySelector('.price'));
    }

    function toggleCustomer(type) {
        document.getElementById('existingCustomer').style.display = type === 'existing' ? 'block' : 'none';
        document.getElementById('newCustomer').style.display = type === 'new' ? 'block' : 'none';
        document.querySelector('[name="customer_id"]').required = type === 'existing';
        document.querySelector('[name="new_customer_name"]').required = type === 'new';
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    // ===================== 【2026-09-08 新增】批量修改辅助函数 =====================
    function refreshAllItemSeq() {
        var rows = document.querySelectorAll('#itemsContainer .item-row');
        rows.forEach(function(r, i) {
            var seqEl = r.querySelector('.item-seq-num');
            if (seqEl) seqEl.textContent = '#' + (i + 1);
        });
    }

    function toggleBatchSelectAll(checked) {
        document.querySelectorAll('#itemsContainer .batch-item-checkbox').forEach(function(cb) {
            cb.checked = checked;
        });
        updateBatchSelectedCount();
    }

    function updateBatchSelectedCount() {
        var all = document.querySelectorAll('#itemsContainer .batch-item-checkbox');
        var checked = document.querySelectorAll('#itemsContainer .batch-item-checkbox:checked');
        var n = checked.length;
        var el = document.getElementById('batchSelectedCount');
        if (el) el.textContent = '已选 ' + n + ' / ' + all.length + ' 项';
        var allCb = document.getElementById('batchSelectAll');
        if (allCb) {
            allCb.checked = (n > 0 && n === all.length);
            allCb.indeterminate = (n > 0 && n < all.length);
        }
    }

    function applyBatchEdit() {
        var mat = document.getElementById('batchMaterialInput').value;
        var priceVal = document.getElementById('batchPriceInput').value;
        var unitVal = document.getElementById('batchUnitInput').value;
        var hasMat = mat && String(mat).trim() !== '';
        var hasPrice = priceVal !== '' && !isNaN(parseFloat(priceVal));
        var hasUnit = unitVal && unitVal !== '';
        if (!hasMat && !hasPrice && !hasUnit) {
            if (typeof showToast === 'function') showToast('请至少输入一项要修改的内容', 'warning');
            else alert('请至少输入一项要修改的内容');
            return;
        }
        var checkedCbs = document.querySelectorAll('#itemsContainer .batch-item-checkbox:checked');
        if (checkedCbs.length === 0) {
            if (typeof showToast === 'function') showToast('请先勾选要修改的项', 'warning');
            else alert('请先勾选要修改的项');
            return;
        }
        var count = 0;
        checkedCbs.forEach(function(cb) {
            var row = cb.closest('.item-row');
            if (!row) return;
            if (hasMat) {
                var specInput = row.querySelector('input[name$="[specification]"]');
                if (specInput) {
                    specInput.value = String(mat).trim();
                    try { specInput.dispatchEvent(new Event('input', {bubbles:true})); specInput.dispatchEvent(new Event('change', {bubbles:true})); } catch(e) {}
                }
            }
            if (hasPrice) {
                var priceInput = row.querySelector('.price');
                if (priceInput) {
                    priceInput.value = parseFloat(priceVal).toFixed(2);
                    calcAmount(priceInput);
                }
            }
            if (hasUnit) {
                var unitInput = row.querySelector('.unit');
                if (unitInput) {
                    unitInput.value = unitVal;
                    try { unitInput.dispatchEvent(new Event('change', {bubbles:true})); } catch(e) {}
                }
            }
            count++;
        });
        if (typeof showToast === 'function') {
            showToast('已修改 ' + count + ' 个产品' + (hasMat ? ' (材质)' : '') + (hasPrice ? ' (单价)' : '') + (hasUnit ? ' (单位)' : ''), 'success');
        } else {
            alert('已修改 ' + count + ' 个产品');
        }
        document.getElementById('batchSelectAll').checked = false;
        checkedCbs.forEach(function(cb) { cb.checked = false; });
        updateBatchSelectedCount();
    }


    /**
     * 【2026-09-08 新增】批量修改产品名称 (order_edit.php)
     * 遍历勾选行, 改 product_name + 联动 unit/price/specification
     * 与 order_create.php 版本区别: 直接用勾选行的 item-row 操作, 不需要 product_name 反向定位
     */
    function applyBatchProduct() {
        var inputEl = document.getElementById('batchProductInput');
        var productName = inputEl ? String(inputEl.value || '').trim() : '';
        if (!productName) {
            if (typeof showToast === 'function') showToast('请先输入产品名称', 'warning');
            else alert('请先输入产品名称');
            return;
        }
        // 在 ALL_PRODUCTS 找匹配产品 (精确 or 模糊)
        var product = null;
        if (typeof ALL_PRODUCTS !== 'undefined') {
            for (var i = 0; i < ALL_PRODUCTS.length; i++) {
                if (ALL_PRODUCTS[i].name === productName) { product = ALL_PRODUCTS[i]; break; }
            }
            if (!product) {
                for (var j = 0; j < ALL_PRODUCTS.length; j++) {
                    if (ALL_PRODUCTS[j].name.indexOf(productName) >= 0 || productName.indexOf(ALL_PRODUCTS[j].name) >= 0) {
                        product = ALL_PRODUCTS[j]; break;
                    }
                }
            }
        }
        // 首次填充 datalist (一次性) - 【2026-09-08 修复】同时在页面初始化时预填充 (否则用户点击下拉看不到产品)
        var dl = document.getElementById('batchProductDatalist');
        if (dl && dl.children.length === 0 && typeof ALL_PRODUCTS !== 'undefined') {
            ALL_PRODUCTS.forEach(function(p) {
                var opt = document.createElement('option');
                opt.value = p.name;
                dl.appendChild(opt);
            });
        }
        var checkedCbs = document.querySelectorAll('.batch-item-checkbox:checked');
        if (checkedCbs.length === 0) {
            if (typeof showToast === 'function') showToast('请先勾选要修改的项', 'warning');
            else alert('请先勾选要修改的项');
            return;
        }
        var count = 0;
        var finalName = product ? product.name : productName;  // 【2026-09-08 修复】提升到 forEach 外供 toast 复用
        checkedCbs.forEach(function(cb) {
            var rowEl = cb.closest('.item-row');
            if (!rowEl) return;
            // 改 product_name (模糊匹配时写匹配到的产品名, 而非搜索词)
            var nameInput = rowEl.querySelector('[data-product-name-input]');
            if (nameInput) nameInput.value = finalName;
            var ssText = rowEl.querySelector('.ss-text');
            if (ssText) {
                ssText.textContent = finalName;
                ssText.classList.remove('placeholder');
            }
            // 联动回填
            if (product) {
                if (product.unit) {
                    var unitEl = rowEl.querySelector('.unit');
                    if (unitEl) unitEl.value = product.unit;
                }
                if (product.price) {
                    var priceEl = rowEl.querySelector('.price');
                    if (priceEl) {
                        priceEl.value = parseFloat(product.price).toFixed(2);
                        if (typeof calcAmount === 'function') calcAmount(priceEl);
                    }
                }
                if (product.specification) {
                    var specEl = rowEl.querySelector('[name$="[specification]"]');
                    if (specEl && (!specEl.value || specEl.value.trim() === '')) {
                        specEl.value = product.specification;
                    }
                }
            } else {
                var p2 = rowEl.querySelector('.price');
                if (p2 && typeof calcAmount === 'function') calcAmount(p2);
            }
            count++;
        });
        if (typeof showToast === 'function') {
            showToast('已修改 ' + count + ' 个产品为 "' + finalName + '"' + (product ? ' (联动单位/单价/规格)' : ''), 'success');
        } else {
            alert('已修改 ' + count + ' 个产品为 "' + finalName + '"');
        }
        if (inputEl) inputEl.value = '';
        document.getElementById('batchSelectAll').checked = false;
        checkedCbs.forEach(function(cb){ cb.checked = false; });
        updateBatchSelectedCount();
    }


    document.addEventListener('click', function(e) {
        if (!e.target.closest('.searchable-select')) {
            document.querySelectorAll('.searchable-select.open').forEach(function(s){ s.classList.remove('open'); });
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.searchable-select.open').forEach(function(s){ s.classList.remove('open'); });
        }
    });

    // 初始化:渲染现有订单明细
    // 【2026-09-08 修复】预填充产品名 datalist (在 addItem 之前,避免用户点击下拉看不到产品)
    (function() {
        var dlInit = document.getElementById('batchProductDatalist');
        if (dlInit && dlInit.children.length === 0 && typeof ALL_PRODUCTS !== 'undefined') {
            ALL_PRODUCTS.forEach(function(p) {
                var opt = document.createElement('option');
                opt.value = p.name;
                dlInit.appendChild(opt);
            });
        }
    })();

    if (INITIAL_ITEMS && INITIAL_ITEMS.length > 0) {
        INITIAL_ITEMS.forEach(function(item) {
            addItem(item);
        });
    } else {
        addItem();
    }

    calcTotal();
    // [2026-09-07] 初始计数显示(可能已超过 200 时强制显示红色)
    updateItemCount();

    // ===================== 共享函数(从 order_create.php 复用) =====================
    // compressImage / parseFileName / fillRowFromParsed
    // 使修改订单时单图上传也能自动压缩、识别产品、填表(与批量开单对齐)。

// === INJECT START ===
async function compressImage(file, maxSizeKB) {
    if (!file || !file.type || file.type.indexOf('image/') !== 0) return file;
    if (file.size <= maxSizeKB * 1024) return file;
    for (var attempt = 0; attempt < 2; attempt++) {
        try {
            var maxW = attempt === 0 ? 1600 : 1200;
            var dataUrl = await new Promise(function(resolve, reject) {
                var fr = new FileReader();
                fr.onload = function() { resolve(fr.result); };
                fr.onerror = function() { reject(new Error('FileReader error')); };
                setTimeout(function(){ reject(new Error('FileReader timeout')); }, 10000);
                fr.readAsDataURL(file);
            });
            var img = await new Promise(function(resolve, reject) {
                var i = new Image();
                var done = false;
                i.onload = function() {
                    if (done) return;
                    done = true;
                    if (!i.naturalWidth || !i.naturalHeight) {
                        reject(new Error('Image decoded but 0 dimension: ' + file.name));
                    } else { resolve(i); }
                };
                i.onerror = function() {
                    if (done) return;
                    done = true;
                    reject(new Error('Image decode fail: ' + file.name));
                };
                setTimeout(function() {
                    if (done) return;
                    done = true;
                    reject(new Error('Image load timeout: ' + file.name));
                }, 15000);
                i.src = dataUrl;
            });
            var w = img.naturalWidth, h = img.naturalHeight;
            if (Math.max(w, h) > maxW) {
                var r = maxW / Math.max(w, h);
                w = Math.round(w * r);
                h = Math.round(h * r);
            }
            var canvas = document.createElement('canvas');
            canvas.width = w;
            canvas.height = h;
            var ctx = canvas.getContext('2d');
            ctx.fillStyle = '#FFFFFF';
            ctx.fillRect(0, 0, w, h);
            ctx.drawImage(img, 0, 0, w, h);
            var q = 0.9;
            var blob = await new Promise(function(r) {
                canvas.toBlob(function(b){ r(b); }, 'image/jpeg', q);
            });
            while (blob && blob.size > maxSizeKB * 1024 && q > 0.4) {
                q -= 0.1;
                blob = await new Promise(function(r) {
                    canvas.toBlob(function(b){ r(b); }, 'image/jpeg', q);
                });
            }
            if (blob && blob.size > 1024 && blob.size < file.size) {
                return new File([blob], (file.name || 'image').replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' });
            }
            console.warn('[compressImage] attempt ' + attempt + ' produced invalid blob, retrying...');
        } catch (e) {
            console.warn('[compressImage] attempt ' + attempt + ' failed: ' + e.message);
        }
    }
    return file;
}

function parseFileName(name) {
    var result = {
        product_name: null,
        specification: '',
        length: null,
        width: null,
        quantity: null,
        unit: null,
        confidence: 0
    };
    var s = name.replace(/\.[^.]+$/, '');
    s = s.replace(/[_\-\.]+/g, ' ').trim();
    if (typeof ALL_PRODUCTS !== 'undefined') {
        var best = null, bestScore = 0;
        for (var i = 0; i < ALL_PRODUCTS.length; i++) {
            var pName = ALL_PRODUCTS[i].name;
            if (s.indexOf(pName) >= 0) {
                var score = pName.length;
                if (score > bestScore) { best = pName; bestScore = score; }
            }
        }
        if (best) {
            result.product_name = best;
            result.confidence += 50;
        }
    }
    var sizeRe = /(\d+(?:\.\d+)?)\s*[xX×*]\s*(\d+(?:\.\d+)?)/i;
    var sizeM = s.match(sizeRe);
    if (sizeM) {
        result.length = parseFloat(sizeM[1]);
        result.width = parseFloat(sizeM[2]);
        result.confidence += 30;
    } else {
        var cnSize = s.match(/长[^0-9]*(\d+(?:\.\d+)?)[^0-9]*(?:宽|高)[^0-9]*(\d+(?:\.\d+)?)/);
        if (cnSize) {
            result.length = parseFloat(cnSize[1]);
            result.width = parseFloat(cnSize[2]);
            result.confidence += 25;
        }
    }
    var qtyPatterns = [
        /[xX×](\d+)\s*张/, /(\d+)\s*张/, /共\s*(\d+)/,
        /数量\s*[:：]?\s*(\d+)/, /\s(\d{1,4})\s*$/
    ];
    for (var j = 0; j < qtyPatterns.length; j++) {
        var qm = s.match(qtyPatterns[j]);
        if (qm) {
            var q = parseInt(qm[1], 10);
            if (q > 0 && q < 100000) {
                result.quantity = q;
                result.confidence += 15;
                break;
            }
        }
    }
    var kwList = ['UV', '可移黑胶', '不背胶', '车贴', '写真', '冷板', '热板', '安装费', '运输', '易拉宝', '灯箱', '喷绘', '条幅', '软膜', '灯布', '黑胶', '背胶', '亚克力', '喷印', '2mm', '3mm', '5mm'];
    var specParts = [];
    for (var k = 0; k < kwList.length; k++) {
        if (s.toUpperCase().indexOf(kwList[k].toUpperCase()) >= 0 && kwList[k] !== result.product_name) {
            specParts.push(kwList[k]);
        }
    }
    if (specParts.length) {
        result.specification = specParts.join(',');
        result.confidence += 10;
    } else {
        result.specification = s.replace(/\s+/g, ' ').trim();
        result.confidence += 3;
    }
    return result;
}

function fillRowFromParsed(row, parsed, imageUrl, opts) {
    if (!row) return { filled: [], skipped: [] };
    opts = opts || {};
    var force = !!opts.force;
    var filled = [], skipped = [];

    function setIfEmptyOrForce(sel, value, key) {
        var el = row.querySelector(sel);
        if (!el || value === undefined || value === null || value === '') return false;
        if (!force) {
            var cur = String(el.value || '').trim();
            // 0/0.00/空白都看作“未填”——可覆盖
            // 其他有效值 > 0 才跳过
            if (cur !== '' && cur !== '0' && cur !== '0.0' && cur !== '0.00') {
                var curNum = parseFloat(cur);
                if (!isNaN(curNum) && curNum > 0) {
                    skipped.push(key);
                    return false;
                }
            }
        }
        el.value = value;
        filled.push(key);
        return true;
    }
    function setTextIfEmptyOrForce(sel, value, key) {
        var el = row.querySelector(sel);
        if (!el || value === undefined || value === null || value === '') return false;
        if (!force && el.textContent && el.textContent.trim() !== '' && el.textContent.indexOf('选择产品') < 0) {
            skipped.push(key); return false;
        }
        el.textContent = value;
        filled.push(key);
        return true;
    }

    if (parsed.product_name) {
        var ss = row.querySelector('.searchable-select');
        if (ss) {
            var ssText = ss.querySelector('.ss-text');
            var isEmpty = !ssText.textContent || ssText.textContent.indexOf('选择产品') >= 0 || ssText.classList.contains('placeholder');
            if (force || isEmpty) {
                ssText.textContent = parsed.product_name;
                ssText.classList.remove('placeholder');
                var nameInput = row.querySelector('[data-product-name-input]');
                if (nameInput) nameInput.value = parsed.product_name;
                filled.push('产品名');
            } else {
                skipped.push('产品名');
            }
        }
    }
    if (parsed.specification) setIfEmptyOrForce('[name$="[specification]"]', parsed.specification, '规格说明');
    if (parsed.length) setIfEmptyOrForce('.length', parsed.length, '长');
    if (parsed.width)  setIfEmptyOrForce('.width',  parsed.width,  '宽');
    var lenM = parsed.length ? parseFloat(parsed.length) / 100 : 0;
    var widM = parsed.width  ? parseFloat(parsed.width)  / 100 : 0;
    if (lenM && widM) {
        var qtyNum = parseInt(parsed.quantity) || 1;
        var sqm = (lenM * widM * qtyNum).toFixed(4);
        setIfEmptyOrForce('.square-meter', sqm, '平方数');
        var sqDisplay = row.querySelector('[data-square-display]');
        if (sqDisplay) sqDisplay.textContent = '平方数: ' + parseFloat(sqm).toFixed(4) + ' ㎡';
    }
    // 数量: 识别到用识别值,没识别到默认 1 (避免 0.00 提交后变 "免费订单")
    // 这里是关键——以前 force=false 且 el.value="1" 或 "0" 都会跳过, 导致数量一直保持原值
    var qtyValue = (parsed.quantity != null && parsed.quantity !== '' && parseInt(parsed.quantity) > 0)
        ? parseInt(parsed.quantity)
        : 1;
    setIfEmptyOrForce('.qty', qtyValue, '数量');

    var productUnit = '';
    if (typeof ALL_PRODUCTS !== 'undefined' && parsed.product_name) {
        var pMatch = ALL_PRODUCTS.find(function(x){ return x.name === parsed.product_name; });
        if (pMatch && pMatch.unit) productUnit = pMatch.unit;
    }
    if (productUnit) setIfEmptyOrForce('.unit', productUnit, '单位');

    // 单价: 优先从产品表取, 如果表里 price=0, 则从历史价格 history[0].price 取
    var defaultPrice = 0;
    if (typeof ALL_PRODUCTS !== 'undefined' && parsed.product_name) {
        var pMatch2 = ALL_PRODUCTS.find(function(x){ return x.name === parsed.product_name; });
        if (pMatch2) {
            if (pMatch2.unit_price) defaultPrice = pMatch2.unit_price;
            else if (pMatch2.default_price) defaultPrice = pMatch2.default_price;
            else if (pMatch2.price) defaultPrice = pMatch2.price;
            // 表里没填价格时, 从历史价格取最近一次
            if (!defaultPrice && pMatch2.history && pMatch2.history.length > 0 && pMatch2.history[0].price) {
                defaultPrice = pMatch2.history[0].price;
            }
        }
    }
    if (defaultPrice > 0) setIfEmptyOrForce('.price', defaultPrice, '单价');

    if (imageUrl) {
        var urlInput = row.querySelector('input[data-image-url]');
        if (urlInput) {
            urlInput.value = imageUrl;
            filled.push('参考图');
            var previewEl = row.querySelector('.image-preview, [data-image-preview]');
            if (previewEl) {
                var previewImg = previewEl.querySelector('img');
                if (previewImg) previewImg.src = imageUrl;
                else previewEl.innerHTML = '<img src="' + imageUrl + '" style="max-width:200px;max-height:200px;border-radius:4px;" />';
            }
        }
    }

    if (parsed.product_name && typeof ALL_PRODUCTS !== 'undefined') {
        var p3 = ALL_PRODUCTS.find(function(x){ return x.name === parsed.product_name; });
        var hint = row.querySelector('[data-price-hint]');
        if (p3 && p3.history && p3.history.length > 0 && hint) {
            var hh = '历史: ';
            p3.history.forEach(function(h, i){ if(i>0) hh += ' / '; hh += '<strong>￥' + h.price.toFixed(2) + '</strong>(' + h.date + ')'; });
            hint.innerHTML = hh;
        }
    }

    var priceEl = row.querySelector('.price');
    if (priceEl) calcAmount(priceEl);

    return { filled: filled, skipped: skipped };
}
// === INJECT END ===

    </script>
</body>
</html>
