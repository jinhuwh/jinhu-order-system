<?php
/**
 * 新建订单 - 增强版
 * 支持：按平方计算、图片上传、Excel导出
 */
// 【2026-09-08】强制清本文件 opcache,避免 Web Station PHP-FPM 缓存了 19:37 之前的旧版
if (function_exists('opcache_invalidate')) { @opcache_invalidate(__FILE__, true); }
require_once 'config.php';
initDatabase();
// 注意:图片批量上传(below)不修改订单状态且本来就不需要 CSRF,
// 但 checkLogin() 会 302 重定向未登录用户到 login.php(返回 HTML),
// 导致 fetch 拿到 HTML 后 JSON.parse 报错。
// 解决方案:在调 checkLogin 前识别 batch_upload ajax 请求,
// session 存在但 user_id 失效则返回 JSON 错误而非 HTML 重定向。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'batch_upload_image') {
    if (session_status() === PHP_SESSION_NONE) { @session_start(); }
    if (empty($_SESSION['user_id'])) {
        header('Content-Type: application/json; charset=utf-8');
        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode(['ok' => false, 'msg' => '请重新登录后再上传图片(session 已过期)', 'need_login' => true]);
        exit;
    }
    // 已登录,直接进入下面的 batch_upload 处理
    $GLOBALS['__BYPASS_CHECKLOGIN__'] = true;
}
if (empty($GLOBALS['__BYPASS_CHECKLOGIN__'])) {
    checkLogin();
}

$db = getDB();

// AJAX: 加载某客户最近一次订单的明细
if (isset($_GET['action']) && $_GET['action'] == 'last_order' && isset($_GET['customer_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $cid = intval($_GET['customer_id']);
    // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
    while (ob_get_level() > 0) { ob_end_clean(); }
    if ($cid <= 0) { echo json_encode(['ok' => false]); exit; }
    $stmt = $db->prepare("SELECT o.id, o.order_no, o.order_date, o.total_amount
                           FROM orders o
                           WHERE o.customer_id = ? AND o.status != 4
                           ORDER BY o.created_at DESC LIMIT 1");
    $stmt->execute([$cid]);
    $lastOrder = $stmt->fetch(PDO::FETCH_ASSOC);
    // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
    while (ob_get_level() > 0) { ob_end_clean(); }
    if (!$lastOrder) { echo json_encode(['ok' => false, 'msg' => '该客户暂无历史订单']); exit; }
    $itemsStmt = $db->prepare("SELECT product_name, specification, quantity, unit, unit_price, amount, remark, length, width, square_meter, image_path
                               FROM order_items WHERE order_id = ?");
    $itemsStmt->execute([$lastOrder['id']]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'order' => $lastOrder, 'items' => $items]);
    exit;
}

// AJAX: 查询某客户某产品最近一次成交价（开单时自动按该客户之前的价格定价）
if (isset($_GET['action']) && $_GET['action'] == 'customer_price' && isset($_GET['customer_id']) && isset($_GET['product_name'])) {
    header('Content-Type: application/json; charset=utf-8');
    $cid = intval($_GET['customer_id']);
    $pname = trim($_GET['product_name']);
    // 只读请求, 清空输出缓冲防 HTML 污染 JSON
    while (ob_get_level() > 0) { ob_end_clean(); }
    if ($cid <= 0 || $pname === '') { echo json_encode(['ok' => false]); exit; }
    $stmt = $db->prepare("SELECT oi.unit_price, o.order_date
                          FROM order_items oi
                          JOIN orders o ON oi.order_id = o.id
                          WHERE o.customer_id = ? AND oi.product_name = ? AND o.status != 4 AND oi.unit_price > 0
                          ORDER BY o.created_at DESC LIMIT 1");
    $stmt->execute([$cid, $pname]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode($r ? ['ok' => true, 'price' => floatval($r['unit_price']), 'date' => substr($r['order_date'], 0, 10)] : ['ok' => false]);
    exit;
}

// 处理订单提交
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // 图片临时上传不修改订单状态、不会创建/更新业务记录，本就不需要 CSRF 保护，
    // 而且 verifyCsrfToken 验证成功后会立即轮换 token，但前端的 csrf input 是写死的
    // （同一页面生命周期不变），轮换后下一次上传 input 值与 session 不一致就会失败，
    // 历史令牌队列(history)一旦被填满5个还会让前 6 次以外的请求全部失败。
    // 因此 ajax_action === 'batch_upload_image' 跳过 CSRF 验证。
    // 【补充】batch_get_image_info 同理:也是只读请求,不修改状态,轮换 token 同样会误伤
    $isBatchUpload = (isset($_POST['ajax_action']) && in_array($_POST['ajax_action'], ['batch_upload_image', 'batch_get_image_info'], true));
    if (!$isBatchUpload && (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token']))) {
        // session 未激活时尝试验证一次（解决内网 / 群晖 88 端口 session 丢失的误报）
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        if (!isset($_SESSION['csrf_token']) || !isset($_POST['csrf_token']) ||
            !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            http_response_code(403);
            die('非法请求，请刷新页面后重试');
        }
    }
   
    // 批量识别开单 - 查询已上传图片的元数据（像素 / DPI / MIME）
    // 供前端读 PDF/AI/EPS/PSD 等浏览器 Image() 不支持的格式用
    if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'batch_get_image_info') {
        header('Content-Type: application/json; charset=utf-8');
        if (session_status() === PHP_SESSION_NONE) { @session_start(); }
        if (empty($_SESSION['user_id'])) {
            // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
            while (ob_get_level() > 0) { ob_end_clean(); }
            echo json_encode(['ok' => false, 'msg' => '未登录']);
            exit;
        }
        $relPath = $_POST['path'] ?? '';
        // 路径安全校验：必须在 uploads/batch/ 下
        $baseDir = realpath(__DIR__ . '/uploads/batch');
        $fullPath = realpath(__DIR__ . '/' . ltrim($relPath, '/'));
        if (!$baseDir || !$fullPath || strpos($fullPath, $baseDir) !== 0) {
            // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
            while (ob_get_level() > 0) { ob_end_clean(); }
            echo json_encode(['ok' => false, 'msg' => '非法路径']);
            exit;
        }
        $info = [
            'ok' => true,
            'width' => 0,
            'height' => 0,
            'dpi_x' => null,
            'dpi_y' => null,
            'mime' => '',
            'file_size' => filesize($fullPath),
            'has_imagick' => class_exists('Imagick'),
        ];
        // 优先 Imagick（读 DPI + 支持 PDF/AI/EPS）
        if (class_exists('Imagick')) {
            try {
                $img = new Imagick($fullPath);
                $info['width']  = $img->getImageWidth();
                $info['height'] = $img->getImageHeight();
                $res = $img->getImageResolution();
                if (is_array($res)) {
                    $info['dpi_x'] = isset($res['x']) ? round((float)$res['x']) : null;
                    $info['dpi_y'] = isset($res['y']) ? round((float)$res['y']) : null;
                }
                $info['mime'] = $img->getImageMimeType();
                $img->clear();
                $img->destroy();
            } catch (Exception $e) {
                // Imagick 读失败（例如不支持的格式），下除到 getimagesize
            }
        }
        // 回退 getimagesize
        if (!$info['width']) {
            $sz = @getimagesize($fullPath);
            if ($sz) {
                $info['width']  = $sz[0];
                $info['height'] = $sz[1];
                $info['mime']   = $sz['mime'] ?? '';
                // 【补充】getimagesize 返回 DPI: $sz[2] = XResolution, $sz[3] = YResolution
                if (isset($sz[2]) && is_numeric($sz[2])) {
                    $info['dpi_x'] = (int)round($sz[2]);
                }
                if (isset($sz[3]) && is_numeric($sz[3])) {
                    $info['dpi_y'] = (int)round($sz[3]);
                }
            }
        }
        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode($info, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 批量识别开单:接收单张压缩图,保存到 uploads/batch/
    if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'batch_upload_image') {
        header('Content-Type: application/json; charset=utf-8');
        // 必须登录后才能上传(取代 CSRF 保护,不靠 CSRF token)
        if (session_status() === PHP_SESSION_NONE) { @session_start(); }
        if (empty($_SESSION['user_id'])) {
            // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
            while (ob_get_level() > 0) { ob_end_clean(); }
            echo json_encode(['ok' => false, 'msg' => '请先登录后再上传图片']);
            exit;
        }
        try {
            if (!isset($_FILES['batch_file']) || $_FILES['batch_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('文件上传失败');
            }
            $tmp = $_FILES['batch_file']['tmp_name'];
            $orig = $_FILES['batch_file']['name'] ?? 'image.jpg';
            // 验证是图片
            $check = @getimagesize($tmp);
            if ($check === false) throw new Exception('不是有效图片');

            // 准备目录 - 仅接受写入 ERP 的 uploads/batch/ 永久目录
            $uploadDir = __DIR__ . '/uploads/batch/';
            if (!is_dir($uploadDir)) {
                if (!@mkdir($uploadDir, 0755, true)) {
                    throw new Exception('无法创建上传目录,请联系管理员检查权限');
                }
            }
            if (!is_writable($uploadDir)) {
                throw new Exception('上传目录不可写,请联系管理员');
            }
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION)) ?: 'jpg';
            $safeExt = in_array($ext, ['jpg','jpeg','png','gif','webp']) ? $ext : 'jpg';
            $newName = 'batch_' . date('Ymd_His') . '_' . substr(md5($orig . microtime(true)), 0, 8) . '.' . $safeExt;
            $dest = $uploadDir . $newName;
            if (!@move_uploaded_file($tmp, $dest)) {
                throw new Exception('文件保存失败,请重试');
            }
            // 【图片压缩】压到60KB内，失败不影响上传
            @compressUploadedImage($dest);
            // 暴露 URL(前端可访问)
            $url = 'uploads/batch/' . $newName;
            $webPath = __DIR__ . '/' . $url;
            if (!file_exists($webPath)) {
                // 理论上不应走到这里(move_uploaded_file 成功就存在)
                throw new Exception('文件保存后验证丢失,请重试');
            }
            // 【2026-09-09 修复】清空输出缓冲,防止 PHP 警告/通知混入 JSON 导致前端解析截断
            while (ob_get_level() > 0) { ob_end_clean(); }
            echo json_encode(['ok' => true, 'url' => $url, 'size' => filesize($dest)]);
            exit;
        } catch (Exception $e) {
            while (ob_get_level() > 0) { ob_end_clean(); }
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
            exit;
        }
    }
 
    // 处理客户
    if (!empty($_POST['new_customer_name'])) {
        $stmt = $db->prepare("INSERT INTO customers (name, contact, phone, address) VALUES (?, ?, ?, ?)");
        $stmt->execute([$_POST['new_customer_name'], $_POST['contact'], $_POST['phone'], $_POST['address']]);
        $customer_id = $db->lastInsertId();
    } else {
        $customer_id = intval($_POST['customer_id'] ?? 0);
        if ($customer_id <= 0) { die('请选择客户'); }
    }

    $order_no = generateOrderNo();
    $total_amount = 0;
    $user_id = $_SESSION['user_id'] ?? 0;
    $order_date = $_POST['order_date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $order_date)) $order_date = date('Y-m-d');

    $stmt = $db->prepare("INSERT INTO orders (order_no, customer_id, order_date, remark, created_by, discount_amount) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$order_no, $customer_id, $order_date, $_POST['order_remark'] ?? '', $user_id, floatval($_POST['discount_amount'] ?? 0)]);
    $order_id = $db->lastInsertId();

    // 处理每个产品项
    foreach ($_POST['items'] as $index => $item) {
        if (empty($item['product_name'])) continue;
        
        // 计算金额（按平方或按数量）
        $quantity = floatval($item['quantity'] ?? 0);
        $unit_price = floatval($item['unit_price'] ?? 0);
        $length = floatval($item['length'] ?? 0);
        $width = floatval($item['width'] ?? 0);
        $square_meter = floatval($item['square_meter'] ?? 0);
        $unit = $item['unit'] ?? '';
        
        // 有平方数且单位是面积类 → 平方数×单价×数量
        $areaUnits = ['㎡', '平方米', 'm²', '平方'];
        if ($square_meter > 0 && in_array($unit, $areaUnits, true)) {
            $amount = $square_meter * $unit_price * $quantity;
        } else {
            $amount = $quantity * $unit_price;
        }
        $total_amount += $amount;
        
        // 处理图片上传
        $image_path = '';
        // 浼樺厛鐢ㄦ壒閲忚瘑鍒?image_url
        if (!empty($item['image_url'])) { $image_path = $item['image_url']; }
        $fileKey = "item_image_{$index}";
        if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
            $image_path = handleItemImageUpload($fileKey);
        }

        $stmt = $db->prepare("INSERT INTO order_items
            (order_id, product_name, specification, quantity, unit, unit_price, amount, remark, length, width, square_meter, image_path)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $order_id,
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
    $db->prepare("UPDATE orders SET total_amount = ?, discount_amount = ? WHERE id = ?")->execute([$total_amount, $discount, $order_id]);
    header("Location: order_view.php?id=$order_id&created=1");
    exit;
}

// 加载数据
$customers = $db->query("SELECT * FROM customers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// 产品数据
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

// 最近使用的产品
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

// 图片上传处理函数
function handleItemImageUpload($fieldName) {
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 5 * 1024 * 1024; // 5MB
    
    if (!isset($_FILES[$fieldName])) return '';
    $file = $_FILES[$fieldName];
    
    if ($file['error'] !== UPLOAD_ERR_OK) return '';
    if ($file['size'] > $maxSize) return '';
    
    // 【兼容降级】fileinfo 缺失时降级：扩展名白名单 + getimagesize 图像签名校验（不依赖任何扩展）
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedTypes)) return '';
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($ext, $allowedExt, true)) return '';
        // getimagesize 读取图像文件头，非真实图片直接拒绝（防改扩展名绕过）
        if (@getimagesize($file['tmp_name']) === false) return '';
    }
    
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'item_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
    $uploadDir = 'uploads/order_items/';
    
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $filepath = $uploadDir . $filename;
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        // 【图片压缩】压到60KB内，失败不影响上传
        @compressUploadedImage($filepath);
        return $filepath;
    }
    return '';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>新建订单 - <?php echo SITE_TITLE; ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* 产品行样式 - 单行紧凑版 (2026-09-14 v2: 序号最前同行, 删除移行尾, 整行压矮) */
        .item-row {
            display: grid;
            grid-template-columns: 46px 1.7fr 1.35fr 1.45fr 0.62fr 0.72fr 0.95fr 0.95fr 1.5fr 40px;
            gap: 10px;
            align-items: end;
            padding: 10px 12px;
            background: white;
            border-radius: var(--radius-lg);
            margin-bottom: 10px;
            border: 2px solid var(--gray-100);
            transition: var(--transition-base);
        }
        .item-row:hover { border-color: var(--primary-light); box-shadow: var(--shadow-card); }
        /* 勾选反馈: 勾选后整行描边变青 */
        .item-row:has(.batch-item-checkbox:checked) { border-color: #22d3ee; background: #fcffff; box-shadow: 0 0 0 2px rgba(34,211,238,.15); }
        /* 序号列: 勾选框 + 数字, 与内容同一行 */
        .item-row-seq {
            display: flex; align-items: center; justify-content: center; gap: 4px;
            height: 36px;
            font-size: 13px; font-weight: 700; color: var(--primary);
            background: var(--primary-light);
            border-radius: 9px;
            cursor: pointer; user-select: none;
        }
        .item-row-seq input { cursor: pointer; margin: 0; }
        .item-row .field-label { font-size: 11.5px; color: var(--gray-400); margin-bottom: 4px; display: flex; align-items: center; gap: 5px; line-height: 1; white-space: nowrap; }
        .item-row .form-control { height: 36px; padding: 6px 10px; font-size: 13.5px; }
        .item-row .amount-display { background: #f0f9ff; font-weight: 700; color: var(--primary); border: 2px solid #bae6fd; border-radius: var(--radius-md); height: 36px; padding: 0 10px; width: 100%; text-align: right; }
        /* 平方数徽章: 放在"尺寸(米)"标签旁, 不占额外高度 */
        .sq-chip {
            font-size: 10.5px; font-weight: 700; color: #ffffff;
            background: #0e7490;
            border-radius: 999px; padding: 1px 7px; line-height: 1.5;
            white-space: nowrap;
        }
        .sq-chip.zero { color: #475569; background: #e2e8f0; }

        /* 尺寸输入区域 */
        .size-inputs {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .size-inputs input {
            width: calc(50% - 9px);
            min-width: 0;
            text-align: center;
            padding: 6px 4px !important;
        }
        .size-inputs span {
            color: var(--gray-400);
            font-size: 12px;
            flex: none;
        }
        /* 行尾删除按钮: 与输入框等高, 和序号列对称 */
        .btn-del-row {
            height: 36px; width: 36px; font-size: 15px; line-height: 1;
            border: 1.5px solid #fecaca; background: #fff; color: #ef4444;
            border-radius: 9px; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: all .15s;
        }
        .btn-del-row:hover { background: #fef2f2; border-color: #ef4444; }
        
        /* 图片上传 */
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
            height: 36px;
            padding: 0 10px;
            background: var(--gray-50);
            border: 2px dashed var(--gray-300);
            border-radius: var(--radius-md);
            cursor: pointer;
            font-size: 12.5px;
            color: var(--gray-500);
            transition: all 0.2s;
            white-space: nowrap;
        }
        .image-upload-label:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-light);
        }
        .image-upload-label [data-label-text] { overflow: hidden; text-overflow: ellipsis; max-width: 110px; }
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
        .image-preview {
            position: absolute;
            top: 100%;
            left: 0;
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
        
        /* 响应式 */
        @media (max-width: 1400px) {
            .item-row { grid-template-columns: 46px 1.6fr 1.3fr 1.4fr 0.6fr 0.7fr 0.9fr 0.9fr 1.4fr 36px; }
        }
        @media (max-width: 1200px) {
            .item-row {
                grid-template-columns: 46px 1fr;
                gap: 10px;
            }
            .item-row > * { grid-column: 2; }
            .item-row .item-row-seq { grid-column: 1; grid-row: 1 / span 10; align-self: start; }
            .item-row .btn-del-row { justify-self: start; }
        }

        /* 其他样式 */
        .customer-select { font-size: 15px; padding: 12px 16px; }
        .recent-bar {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding: 10px 14px;
            background: var(--gray-50);
            border-radius: var(--radius);
            margin-top: 12px;
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

        /* 可搜索下拉 */
        .searchable-select { position: relative; }
        .searchable-select .ss-trigger {
            padding: 6px 10px;
            font-size: 13.5px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            background: white;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            min-height: 36px;
            height: 36px;
            box-sizing: border-box;
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

        .item-row > div { position: relative; min-width: 0; }
        .price-hint {
            position: absolute;      /* 绝对定位: 不参与占高, 保证单价与其他列输入框同一水平线 */
            top: 100%;
            left: 2px;
            margin-top: 2px;
            font-size: 11px;
            color: var(--gray-400);
            line-height: 1.3;
            white-space: nowrap;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            pointer-events: none;
            z-index: 2;
        }
        .price-hint strong { color: var(--primary); font-weight: 600; }

        .last-order-banner {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
            border: 1px solid #7dd3fc;
            border-radius: var(--radius);
            margin-top: 12px;
            font-size: 13px;
        }
        .last-order-banner.hidden { display: none; }
        .last-order-banner .btn-copy {
            background: var(--primary);
            color: white;
            border: none;
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            font-weight: 600;
        }
        .last-order-banner .btn-copy:hover { background: #1e40af; }
        .last-order-banner .btn-dismiss {
            background: transparent;
            border: none;
            color: var(--gray-400);
            cursor: pointer;
            font-size: 18px;
            line-height: 1;
        }
        
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
    </style>
</head>
<body>
    <?php echo renderNav('order_create'); ?>

    <div class="page-title-bar">
        <div class="page-title-left">
            <h1>新建订单</h1>
            <p>选择客户、添加产品明细、创建新订单</p>
        </div>
    </div>

    <form method="POST" id="orderForm" enctype="multipart/form-data" onsubmit="return checkImagesBeforeSubmit()">
        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
        
        <!-- 客户信息区 -->
        <div class="card">
            <div class="card-header">
                <div class="card-title"><span class="title-icon">👤</span> 客户信息</div>
            </div>
            <div class="card-body">
                <div style="margin-bottom: 20px;">
                    <label style="margin-right: 24px; cursor: pointer;">
                        <input type="radio" name="customer_type" value="existing" checked onchange="toggleCustomer('existing')" style="margin-right: 8px;">
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
                        <select name="customer_id" class="form-control customer-select" required id="customerSelect" onchange="checkLastOrder()">
                            <option value="">请选择客户</option>
                            <?php foreach ($customers as $c): ?>
                            <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?> (<?php echo $c['phone'] ?: '无电话'; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="lastOrderBanner" class="last-order-banner hidden">
                        <span style="flex:1;">📋 该客户上次订单：<strong id="lastOrderNo"></strong>（<span id="lastOrderDate"></span>，<span id="lastOrderTotal"></span>，<span id="lastOrderItems"></span>）</span>
                        <button type="button" class="btn-copy" onclick="copyLastOrder()">📥 复制该订单明细</button>
                        <button type="button" class="btn-dismiss" onclick="dismissLastOrder()">×</button>
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

        <!-- 订单日期区 -->
        <div class="card">
            <div class="card-header">
                <div class="card-title"><span class="title-icon">📅</span> 订单日期</div>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">开单日期 <span class="required">*</span></label>
                    <input type="date" name="order_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" style="max-width: 300px;">
                </div>
            </div>
        </div>

        <!-- 订单明细区 -->
        <div class="card">
            <div class="card-header">
                <div class="card-title"><span class="title-icon">📦</span> 订单明细</div>
            </div>
            <div class="card-body">
                <?php if (!empty($recentProducts)): ?>
                <div class="recent-bar">
                    <span class="recent-bar-label">🕐 最近用过：</span>
                    <?php foreach ($recentProducts as $rp): ?>
                    <span class="recent-chip" data-product="<?php echo htmlspecialchars($rp['product_name']); ?>" onclick="quickFill(this)">
                        <?php echo htmlspecialchars($rp['product_name']); ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div style="background: var(--gray-50); border-radius: var(--radius-lg); padding: 20px; margin: 20px 0; border: 2px dashed var(--gray-300);">
                <!-- 📸 批量识别开单卡片 -->
                <div class="batch-upload-card" id="batchUploadCard" style="margin-top:16px;border:2px dashed #06B6D4;border-radius:12px;padding:16px;background:#ECFEFF;">
                    <div class="batch-upload-header" style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                        <i class="fas fa-magic" style="color:#06B6D4;font-size:18px;"></i>
                        <span style="font-weight:600;color:#0e7490;">批量识别开单</span>
                        <span style="color:#64748b;font-size:13px;">— 拖入多张微信截图/参考图,自动识别开单</span>
                        <span class="batch-status" id="batchStatus" style="margin-left:auto;font-size:12px;color:#64748b;"></span>
                    </div>
                    <div class="batch-upload-body">
                        <input type="file" id="batchImageInput" multiple accept="image/*" hidden>
                        <label for="batchImageInput" class="batch-upload-label" id="batchUploadLabel" style="display:flex;align-items:center;gap:12px;padding:20px;border:2px dashed #06B6D4;border-radius:8px;cursor:pointer;background:white;transition:all .2s;">
                            <i class="fas fa-cloud-upload-alt" style="font-size:32px;color:#06B6D4;"></i>
                            <div>
                                <div style="font-size:15px;font-weight:600;color:#0e7490;">点击或拖入多张图片</div>
                                <div style="font-size:12px;color:#94a3b8;margin-top:4px;">自动压缩到 60KB · 从文件名识别产品/尺寸/数量 · 无尺寸时读图片像素推算 · 自动检测 RGB/CMYK 色彩模式</div>
                            <div style="margin-top:6px;display:flex;align-items:center;gap:6px;font-size:12px;color:#475569;" id="dpiSelector">
                                <span>默认 DPI:</span>
                                <select onchange="window.__BATCH_DPI__=parseInt(this.value);" style="padding:2px 6px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;cursor:pointer;">
                                    <option value="100">100 · 灯箱/巨幅</option>
                                    <option value="150" selected>150 · 喷绘 (推荐)</option>
                                    <option value="200">200 · 写真</option>
                                    <option value="300">300 · 印刷原图</option>
                                </select>
                                <span style="color:#94a3b8;font-size:11px;">仅文件名无尺寸时使用</span>
                            </div>
                            </div>
                        </label>
                        <div class="batch-recognize-results" id="batchRecognizeResults" style="margin-top:12px;display:none;"></div>
                    </div>
                    <!-- 单据产品数量计数 + 硬限流(MAX_ITEMS_PER_ORDER=200) -->
                    <div id="itemCountDisplay" class="item-count-display" style="margin-top:10px;padding:8px 12px;border-radius:8px;background:#f1f5f9;font-size:13px;color:#475569;text-align:center;font-weight:500;">已添加 <strong id="itemCountNum" style="color:#0e7490;">0</strong> / 200 个产品</div>
                </div>
                <!-- 【2026-09-08 新增】整订单级批量修改工具栏 (与 order_edit.php 一致) -->
                <div style="background:white;border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin:16px 0;">
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
                        <label style="display:flex;align-items:center;gap:4px;color:#475569;">单价 ￥
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
                                <option value="m2">m2</option>
                                <option value="㎡">㎡</option>
                            </select>
                        </label>
                        <button type="button" onclick="applyBatchEdit()" style="background:#0e7490;color:white;border:none;padding:6px 14px;border-radius:4px;cursor:pointer;font-weight:500;">✓ 应用到选中</button>
                        <span id="batchSelectedCount" style="color:#64748b;font-size:12px;">已选 0 项</span>
                    </div>
                </div>
                <!-- 【2026-09-08 修复】明细行容器 (原容器被批量识别卡移位时误删, 恢复) -->
                <div id="itemsContainer" class="items-container" style="margin-top:16px;"></div>
                <button type="button" class="btn-add-item" onclick="addItem()" style="margin-top:12px;padding:10px 20px;background:linear-gradient(135deg,#06B6D4,#0e7490);color:white;border:none;border-radius:8px;cursor:pointer;font-size:14px;font-weight:500;">
                    <i class="fas fa-plus" style="margin-right:6px;"></i>添加产品
                </button>
                </div>

                <div class="form-group" style="margin-top:24px;">
                    <label class="form-label">订单备注</label>
                    <textarea name="order_remark" class="form-control" rows="3" placeholder="订单备注信息，如：交货时间、特殊要求等"></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">优惠金额（尾数减免，可不填）</label>
                    <input type="number" name="discount_input" id="discountInputUI" value="0" min="0" step="0.01"
                        placeholder="如客户抹零，输入减免金额"
                        style="width:100%;padding:10px 12px;border:2px solid #F97316;border-radius:10px;font-size:15px;box-sizing:border-box;color:#F97316;font-weight:600;"
                        oninput="updateDiscountDisplay()">
                </div>

                <div class="order-total-bar">
                    <div class="label">产品合计</div>
                    <div class="amount" id="productTotal">¥0.00</div>
                </div>
                <div class="order-total-bar" style="border-top:1px dashed #E5E7EB;margin-top:8px;padding-top:8px;">
                    <div class="label">优惠金额</div>
                    <div class="amount" style="color:#F97316;font-size:16px;">
                        -¥<span id="discountDisplay">0.00</span>
                    </div>
                </div>
                <div class="order-total-bar" style="border-top:2px solid #3B82F6;margin-top:8px;padding-top:10px;">
                    <div class="label" style="color:#3B82F6;font-weight:700;">应付金额</div>
                    <div class="amount" id="totalAmount" style="color:#3B82F6;font-weight:700;">¥0.00</div>
                </div>

                <input type="hidden" name="discount_amount" id="discountInput" value="0">

                <div style="text-align: center; margin-top: 32px;">
                    <button type="submit" class="btn btn-primary btn-lg">✅ 创建订单</button>
                    <a href="orders.php" class="btn btn-ghost btn-lg">取消</a>
                </div>
            </div>
        </div>
    </form>

    <?php echo renderFooter(); ?>

    <script>
    var ALL_PRODUCTS = <?php echo $jsProductsJson; ?>;
    var CURRENT_ROW_INDEX = 0;
// [2026-09-07] 单据产品硬上限:max_file_uploads=200 / max_input_vars=5000 的安全边界
// 200 来自 max_file_uploads,留余量给 $_POST['items'][*] 的 12 个字段 ~ 2400 < 5000
var MAX_ITEMS_PER_ORDER = 200;
var MAX_ITEMS_WARN = 180;

// 更新单据产品计数显示 + 控制添加按钮 disabled 状态
function updateItemCount() {
    var rows = document.querySelectorAll('#itemsContainer .item-row');
    var n = rows.length;
    var numEl = document.getElementById('itemCountNum');
    var boxEl = document.getElementById('itemCountDisplay');
    var btnEl = document.getElementById('addItemBtn');
    if (!numEl || !boxEl || !btnEl) return;

    numEl.textContent = n;
    boxEl.innerHTML = '已添加 <strong id="itemCountNum" style="color:#0e7490;">' + n + '</strong> / ' + MAX_ITEMS_PER_ORDER + ' 个产品';

    if (n >= MAX_ITEMS_PER_ORDER) {
        // 达到上限: 红色 + 按钮 disabled
        boxEl.style.background = '#fee2e2';
        boxEl.style.color = '#7f1d1d';
        boxEl.querySelector('strong').style.color = '#dc2626';
        btnEl.disabled = true;
        btnEl.style.opacity = '0.45';
        btnEl.style.cursor = 'not-allowed';
        btnEl.title = '已达单据上限 ' + MAX_ITEMS_PER_ORDER + ' 个产品';
    } else if (n >= MAX_ITEMS_WARN) {
        // 接近上限: 橙色预警
        boxEl.style.background = '#fef3c7';
        boxEl.style.color = '#78350f';
        boxEl.querySelector('strong').style.color = '#ea580c';
        btnEl.disabled = false;
        btnEl.style.opacity = '1';
        btnEl.style.cursor = 'pointer';
        btnEl.title = '已添加 ' + n + ' 个,接近上限 ' + MAX_ITEMS_PER_ORDER;
    } else {
        // 正常: 灰色
        boxEl.style.background = '#f1f5f9';
        boxEl.style.color = '#475569';
        boxEl.querySelector('strong').style.color = '#0e7490';
        btnEl.disabled = false;
        btnEl.style.opacity = '1';
        btnEl.style.cursor = 'pointer';
        btnEl.title = '';
    }
}
    var lastOrderCache = null;

    /**
     * 全局 showToast 兜底函数
     * 原因:本页面曾直接调用 showToast() 但本文件未定义该函数,
     *      导致上传失败分支抛出 ReferenceError 中断整个批量 for 循环(后几张图全部丢失)。
     * 兼容:若全局已有 showToast,则以已有为准;否则使用本兜底实现。
     */
    (function(){
        if (typeof window.showToast === 'function') return;
        window.showToast = function(message, type) {
            type = type || 'success';
            var colorMap = { success: '#10B981', error: '#EF4444', warning: '#F59E0B', info: '#06B6D4' };
            var bg = colorMap[type] || colorMap.info;
            var box = document.createElement('div');
            box.textContent = message;
            box.style.cssText = 'position:fixed;top:24px;right:24px;z-index:99999;padding:12px 18px;'
                + 'background:' + bg + ';color:#fff;border-radius:8px;'
                + 'box-shadow:0 6px 20px rgba(0,0,0,.18);font-size:14px;max-width:380px;'
                + 'opacity:0;transition:opacity .2s;';
            document.body.appendChild(box);
            requestAnimationFrame(function(){ box.style.opacity = '1'; });
            setTimeout(function(){
                box.style.opacity = '0';
                setTimeout(function(){ if (box.parentNode) box.parentNode.removeChild(box); }, 300);
            }, 3000);
        };
    })();

    /**
     * 渲染产品行 - 增强版（支持尺寸计算和图片上传）
     */
    function renderItemRow(index) {
        return '<div class="item-row" data-row="' + index + '">' +
            // 【2026-09-14 v2】序号 + 勾选框: 网格第一列, 与其他内容同一行
            '<label class="item-row-seq">' +
                '<input type="checkbox" class="batch-item-checkbox" onchange="updateBatchSelectedCount()">' +
                '<span class="item-seq-num">1</span>' +
            '</label>' +
            // 产品名称
            '<div>' +
                '<span class="field-label">产品名称</span>' +
                renderSearchableSelect(index) +
                '<input type="hidden" name="items[' + index + '][product_name]" data-product-name-input>' +
            '</div>' +
            // 规格说明
            '<div>' +
                '<span class="field-label">规格说明</span>' +
                '<input type="text" name="items[' + index + '][specification]" class="form-control" placeholder="材质、工艺等">' +
            '</div>' +
            // 尺寸（长×宽）: 平方数徽章放在标签旁, 不占额外高度
            '<div>' +
                '<span class="field-label">尺寸(米) <span class="sq-chip zero" data-square-display>0.00 ㎡</span></span>' +
                '<div class="size-inputs">' +
                    '<input type="number" name="items[' + index + '][length]" class="form-control length" step="any" placeholder="长" oninput="calcSquare(this)">' +
                    '<span>×</span>' +
                    '<input type="number" name="items[' + index + '][width]" class="form-control width" step="any" placeholder="宽" oninput="calcSquare(this)">' +
                '</div>' +
                '<input type="hidden" name="items[' + index + '][square_meter]" class="square-meter" value="0">' +
            '</div>' +
            // 数量
            '<div>' +
                '<span class="field-label">数量</span>' +
                '<input type="number" name="items[' + index + '][quantity]" class="form-control qty" step="0.01" value="1" oninput="calcAmount(this)">' +
            '</div>' +
            // 单位
            '<div>' +
                '<span class="field-label">单位</span>' +
                '<input type="text" name="items[' + index + '][unit]" class="form-control unit" placeholder="个/㎡">' +
            '</div>' +
            // 单价
            '<div>' +
                '<span class="field-label">单价</span>' +
                '<input type="number" name="items[' + index + '][unit_price]" class="form-control price" step="0.01" value="0" oninput="calcAmount(this)">' +
                '<div class="price-hint" data-price-hint></div>' +
            '</div>' +
            // 金额
            '<div>' +
                '<span class="field-label">金额</span>' +
                '<input type="text" class="amount-display" readonly value="¥0.00">' +
            '</div>' +
            // 图片上传: 单行等高按钮
            '<div>' +
                '<span class="field-label">参考图</span>' +
                '<div class="image-upload">' +
                    '<input type="file" name="item_image_' + index + '" id="item_image_' + index + '" accept="image/*" onchange="handleImageSelect(this)">' +
                    '<label for="item_image_' + index + '" class="image-upload-label" data-upload-label>' +
                        '<span>📎</span> <span data-label-text>上传 / 粘贴</span>' +
                    '</label>' +
                    '<div class="image-preview" data-image-preview>' +
                        '<img src="" alt="预览">' +
                    '</div>' +
                '</div>' +
            '</div>' +
            // 删除按钮: 行尾小图标, 与序号对称
            '<button type="button" class="btn-del-row" title="删除本行" onclick="removeItem(this)">🗑</button>' +
            '<input type="hidden" name="items[' + index + '][remark]">' +
            '<input type="hidden" name="items[' + index + '][image_url]" data-image-url>' +
        '</div>';
    }

    function renderSearchableSelect(rowIndex) {
        return '<div class="searchable-select" data-row="' + rowIndex + '">' +
            '<div class="ss-trigger" onclick="openDropdown(this)">' +
                '<span class="ss-text placeholder">— 选择产品 —</span>' +
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
            html += '<div class="ss-group-label">' + escapeHtml(cat) + '（' + groups[cat].length + '）</div>';
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

    /**
     * 【2026-09-14】按客户历史价自动定价:
     * 选了客户 + 产品后, 用该客户上一次购买此产品的成交价覆盖单价。
     * 历史价格不再显示在单价下方(用户要求), 只静默改价。
     */
    function applyCustomerPrice(row, productName) {
        var custEl = document.getElementById('customerSelect');
        var cid = custEl ? (parseInt(custEl.value, 10) || 0) : 0;
        if (!cid || !productName) return;
        var priceInput = row.querySelector('.price');
        if (!priceInput) return;
        fetch('order_create.php?action=customer_price&customer_id=' + cid + '&product_name=' + encodeURIComponent(productName))
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (d && d.ok && d.price > 0) {
                    // 只在单价仍为产品默认价(未被手工改过)时覆盖, 避免打掉用户刚填的价
                    var p = ALL_PRODUCTS.find(function(x){ return x.name === productName; });
                    var defaultPrice = p ? (p.price || 0) : 0;
                    if (parseFloat(priceInput.value) === defaultPrice || !parseFloat(priceInput.value)) {
                        priceInput.value = d.price;
                        calcAmount(priceInput);
                    }
                }
            })
            .catch(function(){});
    }

    // 客户切换后, 对已有产品名的行全部重查客户价
    function reapplyAllCustomerPrices() {
        document.querySelectorAll('.item-row').forEach(function(row) {
            var nameInput = row.querySelector('[data-product-name-input]');
            if (nameInput && nameInput.value) applyCustomerPrice(row, nameInput.value);
        });
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

        // 单价下方提示: 仅平方米提示填尺寸算平方; 历史价格不再显示(按客户历史价自动定价)
        var hint = row.querySelector('[data-price-hint]');
        if (unit === '㎡') {
            hint.innerHTML = '<span style="color:var(--info);">💡 填写长宽自动计算平方</span>';
        } else {
            hint.innerHTML = '';
        }

        // 该客户之前买过此产品 → 自动改用客户成交价
        applyCustomerPrice(row, name);

        calcAmount(row.querySelector('.price'));
    }

    function highlightMatch(text, kw) {
        if (!kw) return escapeHtml(text);
        var idx = text.toLowerCase().indexOf(kw);
        if (idx < 0) return escapeHtml(text);
        return escapeHtml(text.substring(0, idx)) + '<mark style="background:#fef08a;padding:0;">' + escapeHtml(text.substring(idx, idx + kw.length)) + '</mark>' + escapeHtml(text.substring(idx + kw.length));
    }

    /**
     * 计算平方数
     */
    function calcSquare(el) {
        var row = el.closest('.item-row');
        var length = parseFloat(row.querySelector('.length').value) || 0;
        var width = parseFloat(row.querySelector('.width').value) || 0;
        var square = length * width;
        
        row.querySelector('.square-meter').value = square.toFixed(2);
        var sqChip = row.querySelector('[data-square-display]');
        sqChip.textContent = '≈ ' + square.toFixed(2) + ' ㎡';
        sqChip.classList.toggle('zero', square <= 0);

        calcAmount(row.querySelector('.price'));
    }

    /**
     * 计算金额
     */
    function calcAmount(el) {
        var row = el.closest('.item-row');
        var qty = parseFloat(row.querySelector('.qty').value) || 0;
        var price = parseFloat(row.querySelector('.price').value) || 0;
        var unit = row.querySelector('.unit').value || '';
        var square = parseFloat(row.querySelector('.square-meter').value) || 0;
        
        var amount;
        // 有尺寸且单位是面积类 → 平方数×单价×数量
        if (square > 0 && (unit === '㎡' || unit === '平方米' || unit === 'm²' || unit === '平方')) {
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
            if (square > 0 && (unit === '㎡' || unit === '平方米' || unit === 'm²' || unit === '平方')) {
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

    /**
     * 图片上传处理
     */
    function handleImageSelect(input) {
        var label = input.closest('.image-upload').querySelector('[data-upload-label]');
        var labelText = input.closest('.image-upload').querySelector('[data-label-text]');
        var preview = input.closest('.image-upload').querySelector('[data-image-preview]');
        var previewImg = preview.querySelector('img');

        if (input.files && input.files[0]) {
            var file = input.files[0];
            label.classList.add('has-image');
            labelText.textContent = file.name.length > 10 ? file.name.substring(0, 10) + '...' : file.name;

            // 【2026-08-31】单张上传同时调用 parseFileName, 只填当前行为空白字段,
            // 行为与批量开单一致 (产品名/规格说明/尺寸/数量/单位/单价).
            // 如果之前该行是手动填的不会被覆盖。
            try {
                if (typeof parseFileName === 'function') {
                    var parsed = parseFileName(file.name);
                    var row = input.closest('.item-row');
                    if (row && parsed && parsed.confidence > 0) {
                        var r = fillRowFromParsed(row, parsed, '', { force: false });
                        if (r.filled.length > 0 && typeof showToast === 'function') {
                            showToast('已识别 ' + parsed.product_name + ' → ' + r.filled.join('/'), 'success');
                        } else if (r.filled.length === 0 && r.skipped.length > 0) {
                            console.log('[单张识别] 已填入字段,跳过现有字段: ' + r.skipped.join('/'));
                        }
                    }
                }
            } catch (e) {
                console.warn('[单张识别] parseFileName/fillRowFromParsed 失败:', e);
            }

            // 立即用原图出预览(不阻塞 UI); 后台异步压缩到 60KB 后静默替换 input.files
            var reader = new FileReader();
            reader.onload = function(e) {
                previewImg.src = e.target.result;
            };
            reader.readAsDataURL(file);

            // 鼠标悬停显示预览
            label.onmouseenter = function() { preview.style.display = 'block'; };
            label.onmouseleave = function() { preview.style.display = 'none'; };

            // 后台压缩(与服务器端 60KB 标准一致, 压不下去就用原图)
            (function(originalFile, srcInput, srcLabel, srcLabelText){
                compressImage(originalFile, 60).then(function(compressed){
                    if (!compressed || compressed.size >= originalFile.size) return; // 压缩后反而更大就用原图
                    try {
                        var dt = new DataTransfer();
                        dt.items.add(compressed);
                        srcInput.files = dt.files;
                        srcLabel.classList.add('has-image');
                        srcLabelText.textContent = compressed.name.length > 10
                            ? compressed.name.substring(0, 10) + '...' : compressed.name;
                        // 压缩后重新出一个预览(取预览图的新 dataURL)
                        var fr = new FileReader();
                        fr.onload = function(ev) {
                            var pv = srcInput.closest('.image-upload').querySelector('[data-image-preview] img');
                            if (pv) pv.src = ev.target.result;
                        };
                        fr.readAsDataURL(compressed);
                        console.log('[图片压缩] ' + originalFile.name + ': ' +
                            Math.round(originalFile.size/1024) + 'KB → ' +
                            Math.round(compressed.size/1024) + 'KB');
                    } catch (e) {
                        console.warn('[图片压缩] 替换文件失败,提交时将用原图:', e);
                    }
                }).catch(function(err){
                    console.warn('[图片压缩] 失败,使用原图:', err);
                });
            })(file, input, label, labelText);
        }
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
        // 闪烁反馈
        var container = input.closest('.image-upload');
        if (container) {
            container.classList.add('paste-flash');
            setTimeout(function() { container.classList.remove('paste-flash'); }, 600);
        }
        return true;
    }

    // 记录最后点击过的 image-upload(input 是 display:none 不会触发 focusin,所以改用 click)
    var lastClickedItemInput = null;

    

/**
 * ============================================================
 * 批量识别开单功能
 * - 自动压缩图片到 200KB
 * - 从文件名提取产品名/尺寸/数量
 * - 自动新增产品行
 * ============================================================
 */
var BATCH_RECOGNIZE_ENABLED = true;

function initBatchRecognize() {
    var label = document.getElementById('batchUploadLabel');
    var input = document.getElementById('batchImageInput');
    var status = document.getElementById('batchStatus');
    if (!label || !input) return;

    // hover 效果
    label.addEventListener('mouseenter', function() {
        this.style.background = '#F0F9FF';
        this.style.borderColor = '#0891B2';
    });
    label.addEventListener('mouseleave', function() {
        this.style.background = 'white';
        this.style.borderColor = '#06B6D4';
    });

    // 文件选择
    input.addEventListener('change', function(e) {
        var files = Array.from(e.target.files || []);
        if (files.length) processBatchImages(files);
        input.value = '';
    });

    // 拖拽
    label.addEventListener('dragover', function(e) {
        e.preventDefault();
        this.style.background = '#F0F9FF';
    });
    label.addEventListener('dragleave', function(e) {
        e.preventDefault();
        this.style.background = 'white';
    });
    label.addEventListener('drop', function(e) {
        e.preventDefault();
        this.style.background = 'white';
        var files = Array.from(e.dataTransfer.files || []).filter(function(f){
            return f.type.startsWith('image/');
        });
        if (files.length) processBatchImages(files);
    });
}

/**
 * 读取图片实际像素尺寸(只读 width/height,不读 EXIF/DPI)
 * @param File|Blob 图片对象
 * @returns Promise<{ok:boolean, width:number, height:number}>
 */
function readImagePixelSize(file) {
    return new Promise(function(resolve) {
        if (!file) { resolve({ ok: false }); return; }
        try {
            var img = new Image();
            var url = URL.createObjectURL(file);
            var done = false;
            var timedOut = false;
            function finish(payload) {
                if (done) return;
                done = true;
                try { URL.revokeObjectURL(url); } catch (e) {}
                resolve(payload);
            }
            img.onload = function() {
                if (img.naturalWidth && img.naturalHeight) {
                    finish({ ok: true, width: img.naturalWidth, height: img.naturalHeight });
                } else {
                    finish({ ok: false });
                }
            };
            img.onerror = function() {
                finish({ ok: false });
            };
            // 超时保护(某些大图 / 损坏图永不触发 onload)
            setTimeout(function() {
                if (!img.complete) {
                    timedOut = true;
                    finish({ ok: false });
                }
            }, 6000);
            img.src = url;
        } catch (e) {
            resolve({ ok: false });
        }
    });
}

/**
 * 把实际像素按估算DPI换算成厘米
 * @param px       像素数
 * @param dpi      估算的 DPI
 * @returns cm
 */
function pxToCm(px, dpi) {
    // 1 inch = 2.54 cm
    return parseFloat(((px / dpi) * 2.54).toFixed(1));
}

/**
 * 处理一批图片:压缩 → 上传 → 解析文件名 → 读像素 → 合并尺寸 → 新增产品行
 */
async function processBatchImages(files) {
    var status = document.getElementById('batchStatus');
    var resultsEl = document.getElementById('batchRecognizeResults');
    var itemsContainerEl = document.getElementById('itemsContainer');
    if (!BATCH_RECOGNIZE_ENABLED) return;

    // 【2026-09-08 新增】填充产品名称 datalist (一次性, 避免重复填充)
    var batchDl = document.getElementById('batchProductDatalist');
    if (batchDl && batchDl.children.length === 0 && typeof ALL_PRODUCTS !== 'undefined') {
        ALL_PRODUCTS.forEach(function(p) {
            var opt = document.createElement('option');
            opt.value = p.name;
            batchDl.appendChild(opt);
        });
    }

    resultsEl.style.display = 'block';
    resultsEl.innerHTML = '<div style="padding:12px;color:#06B6D4;">⏳ 正在处理 ' + files.length + ' 张图片...</div>';

    var successCount = 0;
    var failCount = 0;
    var skippedCount = 0;
    var results = [];

    // 广告喷绘常见 DPI 估算参考（可按需调整）
    // 写真 / 喷绘 ≈ 150-300 DPI；灯箱片 / 软膜 ≈ 100-150 DPI
    // 这里取一个折中值，作为"像素 → 物理尺寸"推算的默认值
    // 【可调】用户可在页面顶部选择默认 DPI,文件没有尺寸信息时按这个值推算
    var DPI_DEFAULT = (typeof window.__BATCH_DPI__ === 'number' && window.__BATCH_DPI__ > 0) ? window.__BATCH_DPI__ : 150;
    console.log('[批量识别] 本轮使用 DPI 默认值:', DPI_DEFAULT);

    var skippedCount = 0;
    for (var i = 0; i < files.length; i++) {
        try {
            // [2026-09-07] 每张前检查: 已达上限则跳过剩余
            var curCount = document.querySelectorAll('#itemsContainer .item-row').length;
            if (curCount >= MAX_ITEMS_PER_ORDER) {
                skippedCount = files.length - i;
                break;
            }
            status.textContent = '(' + (i + 1) + '/' + files.length + ') ' + files[i].name;
            resultsEl.innerHTML = '<div style="padding:12px;color:#06B6D4;">⏳ 正在处理 ' + (i + 1) + '/' + files.length + ': ' + files[i].name + '</div>';

            var compressed = await compressImage(files[i], 60);
            var parsed = parseFileName(files[i].name);

            // 【新增】读实际像素尺寸，用于补充文件名缺失的尺寸
            // 双轨读取：原图优先（保证完整像素），压缩图兑底（保证稳定）
            var pxInfo = await readImagePixelSize(files[i]);
            if (!pxInfo.ok) {
                pxInfo = await readImagePixelSize(compressed);
            }
            // 【EXIF DPI】前端直接从 JPEG/PNG 元数据读出 DPI。不依赖服务端 Imagick。
            var exifDpi = await readImageExifDpi(files[i]);
            if (!exifDpi.ok) {
                exifDpi = await readImageExifDpi(compressed);
            }
            console.log('[EXIF试读]', files[i].name, '→', exifDpi, '(原始文件大小:', files[i].size, 'B)');
            if (exifDpi.ok && exifDpi.dpi_x && exifDpi.dpi_x > 10) {
                // 用 EXIF DPI 覆盖当前默认值（只要 EXIF 有效）
                DPI_DEFAULT = exifDpi.dpi_x;
                console.log('[EXIF] ✓ 使用 EXIF DPI=' + exifDpi.dpi_x + ' 取代默认');
            } else {
                console.log('[EXIF] ✗ 未读到 EXIF DPI，使用默认 ' + DPI_DEFAULT);
            }
            // 【兏底】如果原图读不到 EXIF，试从服务端 metadata 获取（针对服务器上传后 Imagick 读取的 DPI）
            if (!exifDpi.ok && imageUrl && imageUrl.indexOf('blob:') !== 0) {
                try {
                    var serverDpiInfo = await fetchImageInfoFromServer(imageUrl);
                    console.log('[服务端DPI]', files[i].name, '→', serverDpiInfo);
                    if (serverDpiInfo && serverDpiInfo.ok && serverDpiInfo.dpi_x && serverDpiInfo.dpi_x > 10) {
                        DPI_DEFAULT = serverDpiInfo.dpi_x;
                        exifDpi = {ok:true, dpi_x: serverDpiInfo.dpi_x, dpi_y: serverDpiInfo.dpi_y};
                        console.log('[服务端EXIF] ✓ 使用服务端 DPI=' + serverDpiInfo.dpi_x + ' 取代默认');
                    }
                } catch(e) {
                    console.warn('[服务端DPI] 获取失败:', e);
                }
            }
            // 【2026-09-14 新增】检测图片色彩模式 RGB/CMYK —— 必须用原始文件(压缩前), canvas 重编码后永远是 RGB
            var colorModeInfo = {ok:false, mode:''};
            try {
                colorModeInfo = await detectImageColorMode(files[i]);
            } catch (cmErr) {
                console.warn('[色彩模式] 检测失败:', cmErr);
            }
            parsed.color_mode = colorModeInfo.ok ? colorModeInfo.mode : '';
            console.log('[色彩模式]', files[i].name, '→', colorModeInfo.ok ? colorModeInfo.mode : '未知', colorModeInfo);

            console.log('[像素读]', files[i].name, '→', pxInfo);
            if (pxInfo.ok && pxInfo.width > 0 && pxInfo.height > 0) {
                parsed.pixel_width  = pxInfo.width;
                parsed.pixel_height = pxInfo.height;

                if (!parsed.length || !parsed.width) {
                    // 文件名没有尺寸 → 用像素 + 默认 DPI 推算
                    var maxPx = Math.max(pxInfo.width, pxInfo.height);
                    var minPx = Math.min(pxInfo.width, pxInfo.height);
                    var lenCm = pxToCm(maxPx, DPI_DEFAULT);
                    var widCm = pxToCm(minPx, DPI_DEFAULT);
                    console.log('[像素推算] 默认 DPI=' + DPI_DEFAULT + ', 像素=' + pxInfo.width + '×' + pxInfo.height + ', 推算=' + lenCm + 'cm × ' + widCm + 'cm');
                    parsed.length = lenCm;
                    parsed.width  = widCm;
                    parsed.raw_size_source = 'pixel';
                    parsed.confidence = Math.max(0, parsed.confidence - 10);  // 推算的置信度略低
                    // 【提示】如果推算出来是“缩略图尺寸”(< 5cm) 则提醒:这是压缩预览图,物理尺寸应该更大
                    if (Math.max(lenCm, widCm) < 5) {
                        parsed.size_mismatch_warning = '⚠️ 像素推算仅 ' + lenCm + '×' + widCm + 'cm,可能这是缩略图/预览图。物理尺寸应远大于此,建议拖入原图重试或手动填尺寸。';
                    }
                } else {
                    // 文件名有尺寸 → 反推 DPI 判断一致性
                    // DPI = 像素 ÷ (尺寸cm ÷ 2.54)
                    var declaredMaxCm = Math.max(parsed.length, parsed.width);
                    var declaredMinCm = Math.min(parsed.length, parsed.width);
                    var actualMaxPx   = Math.max(pxInfo.width, pxInfo.height);
                    var actualMinPx   = Math.min(pxInfo.width, pxInfo.height);
                    var inferredDpiMax = Math.round(actualMaxPx * 2.54 / declaredMaxCm);
                    var inferredDpiMin = Math.round(actualMinPx * 2.54 / declaredMinCm);
                    var avgInferredDpi = Math.round((inferredDpiMax + inferredDpiMin) / 2);

                    // DPI 合理区间：20~600 (户外喷绘可能低至 30，印刷原图可能高达 1200)
                    if (avgInferredDpi < 20 || avgInferredDpi > 600) {
                        // 【不一致】给出像素推算的物理尺寸建议
                        var pxLengthCm = pxToCm(actualMaxPx, DPI_DEFAULT);
                        var pxWidthCm  = pxToCm(actualMinPx, DPI_DEFAULT);
                        parsed.size_mismatch_warning = 
                            '尺寸不一致！文件 ' + declaredMaxCm + '×' + declaredMinCm + 'cm 与像素 ' + 
                            pxInfo.width + '×' + pxInfo.height + 'px (反推DPI=' + avgInferredDpi + ') 不符。' +
                            '按 150 DPI 像素应为 ' + pxLengthCm.toFixed(1) + '×' + pxWidthCm.toFixed(1) + 'cm，请核对。';
                    } else if (avgInferredDpi < 30 || avgInferredDpi > 400) {
                        // 【可疑】边缘区间,仅轻提醒
                        parsed.size_mismatch_warning = 
                            '尺寸可考虑核对 (反推DPI=' + avgInferredDpi + '，偏低/偏高请人工确认)';
                    }
                }
            }

            // 【兏底】像素读不到且文件名也没尺寸 → 明示用户手动输入
            if ((!parsed.length || !parsed.width) && !parsed.pixel_width) {
                parsed.need_manual_size = true;
                parsed.size_mismatch_warning = '文件名无尺寸 + 像素读取失败,请手动填写长×宽(或拖入含 cm/m 单位的文件名)';
            }

            var imageUrl = '';
            try {
                imageUrl = await uploadBatchImage(compressed, files[i].name);
            } catch (upErr) {
                // 上传失败:返回内存 blob: URL 仅供本页预览,提交时会被拦截
                imageUrl = URL.createObjectURL(compressed);
                console.warn('上传服务器失败,将仅作本地预览:', upErr.message);
                // 立即提示用户(必须 typeof 保护,否则 ReferenceError 会中断整个批量 for 循环)
                if (typeof showToast === 'function') {
                    showToast('图片 ' + files[i].name + ' 上传失败: ' + upErr.message + '。请保存前重试。', 'error');
                } else {
                    // 兜底:简单 alert,避免因为没有 showToast 而抛 ReferenceError 中断循环
                    try { alert('图片 ' + files[i].name + ' 上传失败:\n' + upErr.message + '\n(此图为本地预览,保存前请重试)'); } catch (e) {}
                }
            }

            // 【增强】如果文件名没尺寸 且 不是 blob: (上传成功),立刻用服务端元数据补上 DPI 推算
            if (imageUrl && imageUrl.indexOf('blob:') !== 0 && (!parsed.length || !parsed.width)) {
                try {
                    var serverInfo = await fetchImageInfoFromServer(imageUrl);
                    if (serverInfo && serverInfo.ok && serverInfo.width && serverInfo.height) {
                        console.log('[服务端元数据]', files[i].name, '→', serverInfo);
                        var useDpi = (serverInfo.dpi_x && serverInfo.dpi_x > 0) ? serverInfo.dpi_x : DPI_DEFAULT;
                        var maxPxS = Math.max(serverInfo.width, serverInfo.height);
                        var minPxS = Math.min(serverInfo.width, serverInfo.height);
                        var lenCmS = pxToCm(maxPxS, useDpi);
                        var widCmS = pxToCm(minPxS, useDpi);
                        console.log('[服务端推算] DPI=' + useDpi + ', 像素=' + serverInfo.width + '×' + serverInfo.height + ', 推算=' + lenCmS + 'cm × ' + widCmS + 'cm');
                        parsed.length = lenCmS;
                        parsed.width  = widCmS;
                        parsed.pixel_width  = serverInfo.width;
                        parsed.pixel_height = serverInfo.height;
                        parsed.raw_size_source = 'pixel';
                        if (serverInfo.dpi_x && serverInfo.dpi_x > 0) {
                            parsed.size_mismatch_warning = '✅ 使用 EXIF DPI=' + useDpi + ' 推算 (' + serverInfo.width + '×' + serverInfo.height + 'px)';
                        }
                    }
                } catch (e) {
                    console.warn('[服务端元数据] 获取失败:', e);
                }
            }
            // 【2026-09-08 修复】同 fillRowFromParsed: 平方数 = 单张 (长×宽),不乘数量
            var sqm = 0;
            var lenM = 0, widM = 0;
            if (parsed.length) lenM = parseFloat(parsed.length) / 100;
            if (parsed.width) widM = parseFloat(parsed.width) / 100;
            if (lenM && widM) {
                sqm = (lenM * widM).toFixed(4);
            }
            var defaultPrice = 0;
            var productUnit = '';
            if (typeof ALL_PRODUCTS !== 'undefined') {
                var pMatch = ALL_PRODUCTS.find(function(x){ return x.name === parsed.product_name; });
                if (pMatch) {
                    if (pMatch.unit_price) defaultPrice = pMatch.unit_price;
                    else if (pMatch.default_price) defaultPrice = pMatch.default_price;
                    else if (pMatch.price) defaultPrice = pMatch.price;
                    // 优先使用产品表里的实际单位(如平方米/㎡),不默认“个”
                    if (pMatch.unit) productUnit = pMatch.unit;
                }
            }
            var prefill = {
                name: parsed.product_name,
                specification: parsed.specification,
                length: parsed.length,
                width: parsed.width,
                quantity: parsed.quantity,
                unit: productUnit || parsed.unit || '',
                price: defaultPrice,
                square_meter: sqm,
                imageUrl: imageUrl,
                originalName: files[i].name
            };
            addItem(prefill);
            // 【2026-09-08】记录本次创建的 item-row,供“批量修改”区反向定位
            var createdRow = itemsContainerEl.lastElementChild;
            // 【新增】上传成功后异步调服务端查询元数据（针对 PDF/AI/EPS 等浏览器不支持的格式补充）
            if (imageUrl && imageUrl.indexOf('blob:') !== 0) {
                fetchImageInfoFromServer(imageUrl).then(function(serverInfo){
                    if (serverInfo && serverInfo.ok && serverInfo.width && serverInfo.height) {
                        // 如果前端没读到像素（某些压缩后出现 0×0），用服务端覆盖
                        if (!parsed.pixel_width || !parsed.pixel_height) {
                            parsed.pixel_width  = serverInfo.width;
                            parsed.pixel_height = serverInfo.height;
                            // 如果原色件名也没有尺寸，现在用服务端像素 + 推算
                            if (!parsed.length || !parsed.width) {
                                parsed.length = pxToCm(Math.max(serverInfo.width, serverInfo.height), DPI_DEFAULT);
                                parsed.width  = pxToCm(Math.min(serverInfo.width, serverInfo.height), DPI_DEFAULT);
                                parsed.raw_size_source = 'pixel';
                            } else if (serverInfo.dpi_x && serverInfo.dpi_x > 0) {
                                // 有服务端 DPI → 重新精确推算 + 校验
                                var lenPx = (Math.max(serverInfo.width, serverInfo.height) / serverInfo.dpi_x) * 2.54;
                                var widPx = (Math.min(serverInfo.width, serverInfo.height) / serverInfo.dpi_x) * 2.54;
                                var declaredMax = Math.max(parsed.length, parsed.width);
                                if (Math.abs(lenPx - declaredMax) > declaredMax * 0.3) {
                                    parsed.size_mismatch_warning = '文件名尺寸与图像DPI不一致（推断 ' + Math.round(lenPx*10)/10 + 'cm），请核对';
                                }
                            }
                            // 更新结果列表 + 重渲染
                            for (var ri = 0; ri < results.length; ri++) {
                                if (results[ri].file === files[i].name && results[ri].ok) {
                                    results[ri].parsed = parsed; break;
                                }
                            }
                            renderBatchResults(results, resultsEl);
                        }
                    }
                }).catch(function(){ /* 静默失败 */ });
            }
            results.push({ file: files[i].name, ok: true, parsed: parsed, itemRow: createdRow });
            successCount++;
        } catch (err) {
            console.error('处理失败', files[i].name, err);
            results.push({ file: files[i].name, ok: false, error: err.message });
            failCount++;
        }
    }

    status.textContent = '完成! 成功 ' + successCount + ' / 失败 ' + failCount + (skippedCount ? ' / 跳过 ' + skippedCount : '');
    renderBatchResults(results, resultsEl);

    if (typeof showToast === 'function') {
        var msg = '已自动开单 ' + successCount + ' 个产品' + (failCount ? ',失败 ' + failCount : '') + (skippedCount ? ',因已达上限 ' + MAX_ITEMS_PER_ORDER + ' 跳过 ' + skippedCount + ' 张' : '');
        showToast(msg, successCount > 0 ? 'success' : 'warning');
    }
}

/**
 * 异步查询服务端图片元数据
 * @param path uploads/batch/... 下的相对路径
 * @returns Promise<Object>
 */
function fetchImageInfoFromServer(path) {
    return new Promise(function(resolve){
        try {
            var fd = new FormData();
            fd.append('ajax_action', 'batch_get_image_info');
            fd.append('path', path);
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'order_create.php', true);
            xhr.timeout = 5000;
            xhr.onload = function() {
                try {
                    var r = JSON.parse(xhr.responseText);
                    resolve(r);
                } catch (e) { resolve(null); }
            };
            xhr.onerror = function() { resolve(null); };
            xhr.ontimeout = function() { resolve(null); };
            xhr.send(fd);
        } catch (e) { resolve(null); }
    });
}

/**
 * 【纯前端】读取 JPEG EXIF 中的 DPI（XResolution/YResolution）。
 * 不依赖服务端 Imagick。原理：JPEG APP1 段(0xFFE1)里 XResolution 是 8 字节:RATIONAL(4字节分子+4字节分母)。
 * PNG 在 IHDR(0x49484452) 后续 8 字节:pHYs (4字节 ppm_x + 4字节 ppm_y)。
 * 返回 { ok, dpi_x, dpi_y }；解析失败返回 { ok:false }。
 */
function readImageExifDpi(file) {
    return new Promise(function(resolve){
        try {
            var fr = new FileReader();
            fr.onload = function(e) {
                try {
                    var ab = e.target.result;
                    var result = parseDpiFromImage(ab);
                    resolve(result || {ok:false});
                } catch (err) {
                    console.warn('[EXIF parse error]', err);
                    resolve({ok:false});
                }
            };
            fr.onerror = function(){ resolve({ok:false}); };
            // 只读前面 128KB（足够包含所有 APP 段)
            fr.readAsArrayBuffer(file.slice(0, 131072));
        } catch (err) {
            resolve({ok:false});
        }
    });
}

/**
 * 【全面】从 JPEG/PNG 提取 DPI。扫描所有可能位置：
 *   - JFIF APP0 (FFE0) - density units + Xdensity/Ydensity
 *   - EXIF APP1 (FFE1) - XResolution/YResolution
 *   - Photoshop 3.0 APP13 (FFED) - 0x03ED ResolutionInfo
 *   - PNG pHYs chunk
 */
function parseDpiFromImage(ab) {
    var buf = new DataView(ab);
    var len = buf.byteLength;
    if (len < 12) return null;
    // JPEG
    if (buf.getUint16(0) === 0xFFD8) {
        var off = 2;
        var segmentDebug = [];
        // 先扫描所有段,收集多个 DPI 候选,然后按优先级选
        var dpiCandidates = [];
        while (off < len - 4) {
            if (buf.getUint8(off) !== 0xFF) break;
            var marker = buf.getUint8(off + 1);
            if (marker === 0xDA) break;
            if (marker >= 0xD0 && marker <= 0xD7) { off += 2; continue; }
            if (marker === 0xD8 || marker === 0xD9 || marker === 0x01) { off += 2; continue; }
            var segLen = buf.getUint16(off + 2);
            if (segLen < 2 || off + 2 + segLen > len) break;
            segmentDebug.push('0x' + marker.toString(16) + '(len=' + segLen + ')');

            // APP13 Photoshop 3.0 (FFED) - 优先级 1 (最准确)
            if (marker === 0xED && segLen >= 16) {
                if (buf.getUint32(off + 4) === 0x50686F74 &&
                    buf.getUint32(off + 8) === 0x6F73686F &&
                    buf.getUint32(off + 12) === 0x7020332E &&
                    buf.getUint8(off + 17) === 0x00) {
                    var p = off + 18;
                    var segEnd = off + 2 + segLen;
                    while (p < segEnd - 12) {
                        var sig = buf.getUint32(p);
                        var resId = buf.getUint16(p + 4);
                        var pascalLen = buf.getUint8(p + 6);
                        var pascalTotal = 1 + pascalLen;
                        if (pascalTotal % 2 !== 0) pascalTotal++;
                        var dataStart = p + 6 + pascalTotal;
                        if (dataStart + 4 > segEnd) break;
                        var dataLen = buf.getUint32(dataStart);
                        if (sig === 0x3842494D && resId === 0x03ED) {
                            if (dataStart + 16 <= segEnd) {
                                var hRes = buf.getUint32(dataStart + 4) / 65536.0;
                                var vRes = buf.getUint32(dataStart + 12) / 65536.0;
                                if (hRes > 0 && hRes < 10000) {
                                    dpiCandidates.push({source:'Photoshop-IRB', priority:1, dpi_x:Math.round(hRes), dpi_y:Math.round(vRes)});
                                    break;
                                }
                            }
                        }
                        p = dataStart + 4 + dataLen;
                        if (dataLen % 2 !== 0) p++;
                    }
                }
            }

            // APP1 EXIF (FFE1) - 优先级 2
            if (marker === 0xE1 && segLen >= 8) {
                if (buf.getUint32(off + 4) === 0x45786966 && buf.getUint16(off + 8) === 0x0000) {
                    var tiffOff = off + 10;
                    var little = buf.getUint16(tiffOff) === 0x4949;
                    var get16 = little ? function(p){ return buf.getUint16(p, true); } : function(p){ return buf.getUint16(p, false); };
                    var get32 = little ? function(p){ return buf.getUint32(p, true); } : function(p){ return buf.getUint32(p, false); };
                    var ifd0Off = tiffOff + get32(tiffOff + 4);
                    var numEntries = get16(ifd0Off);
                    var exifIFDPtr = 0;
                    for (var i = 0; i < numEntries; i++) {
                        var tag = get16(ifd0Off + 2 + i*12);
                        if (tag === 0x8769) { exifIFDPtr = tiffOff + get32(ifd0Off + 2 + i*12 + 8); break; }
                    }
                    var subIFDs = [ifd0Off];
                    if (exifIFDPtr) subIFDs.push(exifIFDPtr);
                    var fx = null, fy = null;
                    for (var s = 0; s < subIFDs.length; s++) {
                        var so = subIFDs[s];
                        var n = get16(so);
                        for (var j = 0; j < n; j++) {
                            var t = get16(so + 2 + j*12);
                            if (t === 0x011A) {
                                var vo = tiffOff + get32(so + 2 + j*12 + 8);
                                var num = get32(vo), den = get32(vo + 4);
                                if (den > 0) fx = num / den;
                            } else if (t === 0x011B) {
                                var v2 = tiffOff + get32(so + 2 + j*12 + 8);
                                var n2 = get32(v2), d2 = get32(v2 + 4);
                                if (d2 > 0) fy = n2 / d2;
                            }
                        }
                    }
                    if (fx > 0 || fy > 0) {
                        dpiCandidates.push({source:'EXIF', priority:2, dpi_x:Math.round(fx||fy), dpi_y:Math.round(fy||fx)});
                    }
                }
            }

            // APP0 JFIF (FFE0) - 优先级 3 (PS 保存时 JFIF 通常是固定 72,与实际设计 DPI 不同)
            if (marker === 0xE0 && segLen >= 14) {
                if (buf.getUint32(off + 4) === 0x4A464946) {
                    var units = buf.getUint8(off + 11);
                    var xd = buf.getUint16(off + 12);
                    var yd = buf.getUint16(off + 14);
                    if (units === 1 && xd > 0) dpiCandidates.push({source:'JFIF', priority:3, dpi_x:xd, dpi_y:yd});
                    else if (units === 2 && xd > 0) dpiCandidates.push({source:'JFIF-dpcm', priority:3, dpi_x:Math.round(xd*2.54), dpi_y:Math.round(yd*2.54)});
                }
            }

            off += 2 + segLen;
        }
        // 按优先级 (priority 越小越优先) 返回首个有效 DPI
        if (dpiCandidates.length > 0) {
            dpiCandidates.sort(function(a, b){ return a.priority - b.priority; });
            var best = dpiCandidates[0];
            console.log('[EXIF段扫描]', segmentDebug.join(' → '), '→ 选择:', best.source, best.dpi_x);
            return {ok:true, dpi_x:best.dpi_x, dpi_y:best.dpi_y, source:best.source};
        }
        console.log('[EXIF段扫描]', segmentDebug.join(' → '), '→ 无 DPI');
        return {ok:false};
    }
    // PNG
    if (buf.getUint32(0) === 0x89504E47) {
        var p = 8;
        while (p < len - 12) {
            var cl = buf.getUint32(p);
            var ct = buf.getUint32(p + 4);
            if (ct === 0x70485973) {
                var ppmX = buf.getUint32(p + 8);
                var ppmY = buf.getUint32(p + 12);
                return {ok:true, dpi_x: Math.round(ppmX / 39.3701), dpi_y: Math.round(ppmY / 39.3701), source:'PNG-pHYs'};
            }
            p += 8 + cl + 4;
        }
        return {ok:false};
    }
    return {ok:false};
}

/**
 * 【2026-09-14 新增】检测图片色彩模式: RGB / CMYK / GRAY
 * 原理（纯前端二进制解析，不需要 Imagick）:
 *  - JPEG: 遍历段找 SOF0~SOF15 帧头(0xC0-0xCF, 排除 C4/C8/CC)，帧头第 9 字节是分量数:
 *          1=灰度  3=YCbCr(RGB)  4=CMYK/YCCK
 *  - PNG:  IHDR 的 color type (0=灰度 2/3/4/6=按 RGB 处理)
 *  - TIFF: IFD0 tag 0x0106 PhotometricInterpretation: 5=CMYK(Separated) 2=RGB 1/6=灰度
 *          (喷绘/印刷常传 TIFF, 一并支持)
 * 注意: 必须用"原始文件"检测 —— 前端压缩是 canvas 重编码, 输出永远是 RGB, 会失真!
 * @param File|Blob file
 * @returns Promise<{ok:boolean, mode:'RGB'|'CMYK'|'GRAY'|'', detail:string}>
 */
function detectImageColorMode(file) {
    // 【2026-09-15 修复】渐进式读取：1MB → 4MB → 16MB → 整文件。
    // 印刷厂 JPG 常带超大 ICC 配置文件/PS 元数据段(APP2/APP13, 好几MB)，
    // 固定读 1MB 时段还没走完就被截断 → "未找到 SOF 段" → 全部检测失败。
    return new Promise(function(resolve){
        if (!file) { resolve({ok:false, mode:'', detail:'无文件'}); return; }
        var steps = [1048576, 4194304, 16777216, file.size];
        var idx = 0;
        function attempt() {
            if (idx >= steps.length) { resolve({ok:false, mode:'', detail:'未找到 SOF 段'}); return; }
            var sliceSize = Math.min(steps[idx++], file.size);
            var isFull = sliceSize >= file.size;
            var fr = new FileReader();
            fr.onload = function(e) {
                var r;
                try {
                    r = parseImageColorMode(e.target.result, isFull);
                } catch (err) {
                    console.warn('[色彩模式] 解析失败:', err);
                    r = {ok:false, mode:'', detail:'解析失败'};
                }
                // 段被截断且还有剩余字节 → 扩大读取量重试
                if (!r.ok && r.needMore && sliceSize < file.size) { attempt(); return; }
                delete r.needMore;
                resolve(r);
            };
            fr.onerror = function(){ resolve({ok:false, mode:'', detail:'读取失败'}); };
            fr.readAsArrayBuffer(file.slice(0, sliceSize));
        }
        try {
            attempt();
        } catch (err) {
            resolve({ok:false, mode:'', detail:'读取异常'});
        }
    });
}

function parseImageColorMode(ab, isFull) {
    if (isFull === undefined) isFull = true;
    var buf = new DataView(ab);
    var len = buf.byteLength;
    if (len < 12) return {ok:false, mode:'', detail:'文件过小'};
    // ---- JPEG ----
    if (buf.getUint16(0) === 0xFFD8) {
        var off = 2;
        while (off < len - 4) {
            if (buf.getUint8(off) !== 0xFF) break;
            var marker = buf.getUint8(off + 1);
            if (marker === 0xFF) { off += 1; continue; } // 【2026-09-15 修复】跳过 0xFF 填充字节
            if (marker === 0xDA) break; // SOS 之后是压缩数据
            if (marker >= 0xD0 && marker <= 0xD7) { off += 2; continue; }
            if (marker === 0xD8 || marker === 0xD9 || marker === 0x01) { off += 2; continue; }
            var segLen = buf.getUint16(off + 2);
            if (segLen < 2 || off + 2 + segLen > len) {
                // 【2026-09-15 修复】段跨过了缓冲区末尾 → 若是部分读取则要求扩量重试
                if (!isFull) return {ok:false, mode:'', needMore:true, detail:'段被截断'};
                break;
            }
            // SOF0/1/2/3/5/6/7/9/10/11/13/14/15 = 帧头段
            if (marker >= 0xC0 && marker <= 0xCF && marker !== 0xC4 && marker !== 0xC8 && marker !== 0xCC) {
                var nComp = buf.getUint8(off + 9); // 段内: len(2)+precision(1)+height(2)+width(2) → 分量数在第 9 字节
                if (nComp === 4) return {ok:true, mode:'CMYK', detail:'JPEG 分量数=4'};
                if (nComp === 3) return {ok:true, mode:'RGB', detail:'JPEG 分量数=3'};
                if (nComp === 1) return {ok:true, mode:'GRAY', detail:'JPEG 分量数=1 (灰度)'};
                return {ok:false, mode:'', detail:'JPEG 分量数=' + nComp};
            }
            off += 2 + segLen;
        }
        // 【2026-09-15 修复】走到缓冲区末尾仍未找到 SOF/SOS → 部分读取时扩量重试
        if (!isFull) return {ok:false, mode:'', needMore:true, detail:'需更多数据'};
        return {ok:false, mode:'', detail:'未找到 SOF 段'};
    }
    // ---- PNG ----
    if (buf.getUint32(0) === 0x89504E47) {
        // IHDR: 签名8字节 + 长度4 + 'IHDR'4 → color type 位于第 25 字节(0 起)
        if (len >= 26) {
            var ct = buf.getUint8(25);
            if (ct === 0) return {ok:true, mode:'GRAY', detail:'PNG colorType=0 (灰度)'};
            return {ok:true, mode:'RGB', detail:'PNG colorType=' + ct};
        }
        return {ok:false, mode:'', detail:'PNG IHDR 不完整'};
    }
    // ---- TIFF (II/M + 42) ----
    var magic16 = buf.getUint16(0);
    if (magic16 === 0x4949 || magic16 === 0x4D4D) {
        var little = (magic16 === 0x4949);
        var g16 = function(p){ return buf.getUint16(p, little); };
        var g32 = function(p){ return buf.getUint32(p, little); };
        if (g16(2) !== 42) return {ok:false, mode:'', detail:'非标准 TIFF'};
        var ifdOff = g32(4);
        if (ifdOff + 2 > len) return {ok:false, mode:'', detail:'TIFF IFD 越界'};
        var n = g16(ifdOff);
        for (var i = 0; i < n; i++) {
            var eOff = ifdOff + 2 + i * 12;
            if (eOff + 12 > len) break;
            if (g16(eOff) === 0x0106) { // PhotometricInterpretation
                // SHORT 值按 TIFF 规范"左对齐"存于 value 字段起始 2 字节(小端/大端统一读 eOff+8)
                var v = g16(eOff + 8);
                if (v === 5) return {ok:true, mode:'CMYK', detail:'TIFF Photometric=5 (Separated/CMYK)'};
                if (v === 2) return {ok:true, mode:'RGB', detail:'TIFF Photometric=2 (RGB)'};
                if (v === 1 || v === 6) return {ok:true, mode:'GRAY', detail:'TIFF Photometric=' + v};
                return {ok:false, mode:'', detail:'TIFF Photometric=' + v};
            }
        }
        return {ok:false, mode:'', detail:'TIFF 未找到 Photometric 标签'};
    }
    // WebP/其他格式一律按 RGB 处理(浏览器生态基本都是 RGB)
    return {ok:false, mode:'', detail:'该格式不检测(按 RGB 处理)'};
}

/**
 * 压缩图片到指定 KB 以内。
 * 强化点（解决 90+ 张图时偶发黑色/全0字节 JPEG）：
 * 1) 整个流程包 try-catch —— 任何环节失败(EXIF 损坏/内存不足/canvas 渲染失败)都返回原图, 永不丢图
 * 2) 校验 img.naturalWidth > 0 —— Canvas 拿到 0 尺寸图时会渲染空白画布
 * 3) Image.onerror + onload 双重防护 —— 防止 i.oncomplete 永不触发
 * 4) 质量降到 0.5 还超 → 二次降分辨率(由 maxW 1600 → 1200)再压一次
 * 5) 极端情况(canvas 渲染出 0 字节 blob)→ 兜底返回原图
 */
async function compressImage(file, maxSizeKB) {
    if (!file || !file.type || file.type.indexOf('image/') !== 0) return file; // 非图片直接返回
    if (file.size <= maxSizeKB * 1024) return file; // 已经在目标大小内, 直接返回避免不必要压缩
    
    // 一次性尝试,失败 fallback 到原图
    for (var attempt = 0; attempt < 2; attempt++) {
        try {
            var maxW = attempt === 0 ? 1600 : 1200; // 第二次重试时再激进一些
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
                    } else {
                        resolve(i);
                    }
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
            // 用白色填充,避免 PNG 透明背景转 JPEG 时变全黑
            ctx.fillStyle = '#FFFFFF';
            ctx.fillRect(0, 0, w, h);
            ctx.drawImage(img, 0, 0, w, h);
            
            var q = 0.9;
            var blob = await new Promise(function(r) {
                canvas.toBlob(function(b){ r(b); }, 'image/jpeg', q);
            });
            // 降质量循环
            while (blob && blob.size > maxSizeKB * 1024 && q > 0.4) {
                q -= 0.1;
                blob = await new Promise(function(r) {
                    canvas.toBlob(function(b){ r(b); }, 'image/jpeg', q);
                });
            }
            
            // 兜底校验:blob 有效 + 体积正常 + 不是空 blob
            if (blob && blob.size > 1024 && blob.size < file.size) {
                return new File([blob], (file.name || 'image').replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' });
            }
            // blob 太小(可能是 0 字节黑图) 或 大于原图 → 进入重试
            console.warn('[compressImage] attempt ' + attempt + ' produced invalid blob (size=' + (blob ? blob.size : 0) + '), retrying...');
        } catch (e) {
            console.warn('[compressImage] attempt ' + attempt + ' failed: ' + e.message);
        }
    }
    // 两次都失败, 兜底返回原图
    console.warn('[compressImage] fallback to original for: ' + (file ? file.name : '?'));
    return file;
}

async function uploadBatchImage(file, originalName) {
    var fd = new FormData();
    fd.append('ajax_action', 'batch_upload_image');
    fd.append('batch_file', file);
    fd.append('original_name', originalName);
    var csrfInput = document.querySelector('input[name="csrf_token"]');
    var csrfToken = csrfInput ? csrfInput.value : (typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '');
    fd.append('csrf_token', csrfToken);
    var resp = await fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin', redirect: 'manual' });
    // 检查重定向(未登录时 checkLogin 会 302 -> login.php)
    if (resp.type === 'opaqueredirect' || resp.status === 302 || resp.status === 301) {
        throw new Error('登录已过期(session 失效),请刷新页面重新登录后重试');
    }
    // 拿原始文本,出错了能看到后端返回了什么(避免“is not valid JSON”抹掉真实错误)
    var rawText = await resp.text();
    var data;
    try {
        data = JSON.parse(rawText);
    } catch (parseErr) {
        // 后端返回的不是 JSON(通常是 PHP 警告/错误页、404、502 等)
        var snippet = rawText.replace(/\s+/g, ' ').substring(0, 300);
        throw new Error('服务器响应异常(status=' + resp.status + '): ' + snippet);
    }
    if (!data.ok) throw new Error(data.msg || '上传失败');
    return data.url;
}

/**
 * 把上传图片的文件名解析成结构化字段
 * 解析维度:
 *   - product_name:  产品名（匹配 ALL_PRODUCTS）
 *   - length/width:  物理尺寸（单位 cm，识别后已统一换算）
 *   - quantity:      数量（张 / 个）
 *   - specification: 材质/工艺描述（去掉产品名+尺寸后剩下的文本）
 *   - raw_size_source: 'filename' | 'pixel' | 'none'，标识尺寸来源
 *   - confidence:    解析置信度（0-100）
 */
function parseFileName(name) {
    var result = {
        product_name: null,
        specification: '',
        length: null,
        width: null,
        quantity: null,
        unit: null,
        confidence: 0,
        raw_size_source: 'filename'
    };

    // 1) 剥离扩展名（仅 扩展名部分的点，不动文件名中间的小数点）
    var s = name.replace(/\.[a-zA-Z]+$/, '');
    // 2) 中文 / ASCII 括号、下划线、连字符、空格 → 统一为空格
    //    ⚠️ 绝对不能裸 "点 ." ，否则 45.0cm 里的数字小数点会被吃成 45 0cm！
    s = s.replace(/[_\-【】\[\]()（）]/g, ' ');
    s = s.replace(/\s+/g, ' ').trim();

    // 2) 产品名匹配（取最长命中）
    if (typeof ALL_PRODUCTS !== 'undefined') {
        var best = null, bestScore = 0;
        for (var i = 0; i < ALL_PRODUCTS.length; i++) {
            var pName = ALL_PRODUCTS[i].name;
            if (pName && s.indexOf(pName) >= 0 && pName.length > bestScore) {
                best = pName; bestScore = pName.length;
            }
        }
        if (best) {
            result.product_name = best;
            result.confidence += 50;
        }
    }

    // 3) 【修复点】尺寸识别 — 关键改进：
    //    a. 把单位也放进捕获组，剥离 cm/mm/米，避免单位被算作数字的一部分
    //    b. 统一换算成 cm
    //    c. 优先匹配带单位的形式，再回退纯数字形式
    var lenM = null, widM = null, sizeSource = null;
    // 策略1：x/X/×/* 分隔（支持 中间夹"宽""长""高"关键字）
    //      例: "4.8x2.5m", "4.8x宽2.5m", "4.8m×2.5m", "4.8×宽2.5"
    //      单位默认 cm; 任一边带 m/米 则没单位的另一边也按 m 算
    var sizeM = s.match(/(\d+(?:\.\d+)?)\s*(cm|毫米|mm|米|m)?\s*[xX×*]\s*(宽|高|长|短)?\s*(\d+(?:\.\d+)?)\s*(cm|毫米|mm|米|m)?/i);
    if (sizeM) {
        var lenVal = parseFloat(sizeM[1]);
        var widVal = parseFloat(sizeM[4]);
        var lenUnit = (sizeM[2] || '').toLowerCase();
        var widUnit = (sizeM[5] || '').toLowerCase();
        // 启发式：任一边是 m/米，另一边默认按 m；都不是则默认 cm
        // 【2026-09-15 修复】同理：任一边是 mm/毫米，没写单位的那边也按 mm（例：595X1750mm → 595 应是 mm，
        //   修复前 595 被当 cm 变 5.95 米，10 倍误差）
        if (!lenUnit && !widUnit) {
            lenUnit = 'cm'; widUnit = 'cm';
        } else if (!lenUnit && (widUnit === 'm' || widUnit === '米')) {
            lenUnit = 'm';
        } else if (!widUnit && (lenUnit === 'm' || lenUnit === '米')) {
            widUnit = 'm';
        } else if (!lenUnit && (widUnit === 'mm' || widUnit === '毫米')) {
            lenUnit = 'mm';
        } else if (!widUnit && (lenUnit === 'mm' || lenUnit === '毫米')) {
            widUnit = 'mm';
        }
        // 换算成 cm
        if (lenUnit === 'mm' || lenUnit === '毫米') lenVal /= 10;
        else if (lenUnit === 'm' || lenUnit === '米') lenVal *= 100;
        if (widUnit === 'mm' || widUnit === '毫米') widVal /= 10;
        else if (widUnit === 'm' || widUnit === '米') widVal *= 100;
        // 中间出现"宽"关键字 → 第二个数字被标为宽(传统写法"长×宽" = 第一位长、第二位宽)
        // 这里不交换: 已默认 lenVal=第一位、widVal=第二位,符合主流命名习惯。
        lenM = lenVal; widM = widVal;
        sizeSource = 'symbol';
        result.confidence += 35;
    }
    // 策略2：中文关键字 正向（高/长 × 宽），中间可以是 空格/cm/mm/米 或 X/×/*
    if (lenM === null) {
        var cn = s.match(/(高|长)\s*(\d+(?:\.\d+)?)\s*(cm|毫米|mm|米|m)?\s*[xX×*]?\s*(宽|高|长|短)\s*(\d+(?:\.\d+)?)\s*(cm|毫米|mm|米|m)?/);
        if (cn) {
            lenM = parseFloat(cn[2]);
            widM = parseFloat(cn[5]);
            // 处理单位:任一边带 m/米 则另一边默认也按 m(避免长4.8宽2.5m 被解读为 4.8cm×250cm)
            var cnLenUnit = (cn[3] || '').toLowerCase();
            var cnWidUnit = (cn[6] || '').toLowerCase();
            if (!cnLenUnit && !cnWidUnit) { cnLenUnit = 'cm'; cnWidUnit = 'cm'; }
            else if (!cnLenUnit && (cnWidUnit === 'm' || cnWidUnit === '米')) { cnLenUnit = 'm'; }
            else if (!cnWidUnit && (cnLenUnit === 'm' || cnLenUnit === '米')) { cnWidUnit = 'm'; }
            else if (!cnLenUnit && (cnWidUnit === 'mm' || cnWidUnit === '毫米')) { cnLenUnit = 'mm'; }
            else if (!cnWidUnit && (cnLenUnit === 'mm' || cnLenUnit === '毫米')) { cnWidUnit = 'mm'; }
            if (cnLenUnit === 'mm' || cnLenUnit === '毫米') lenM /= 10;
            else if (cnLenUnit === 'm' || cnLenUnit === '米') lenM *= 100;
            if (cnWidUnit === 'mm' || cnWidUnit === '毫米') widM /= 10;
            else if (cnWidUnit === 'm' || cnWidUnit === '米') widM *= 100;
            sizeSource = 'cn-keyword';
            result.confidence += 35;
        } else {
            // 策略2 反向（宽 × 高/长）
            var cnRev = s.match(/(宽|短)\s*(\d+(?:\.\d+)?)\s*(cm|毫米|mm|米|m)?\s*[xX×*]?\s*(高|长)\s*(\d+(?:\.\d+)?)\s*(cm|毫米|mm|米|m)?/);
            if (cnRev) {
                lenM = parseFloat(cnRev[5]);
                widM = parseFloat(cnRev[2]);
                var cnRLenUnit = (cnRev[6] || '').toLowerCase();
                var cnRWidUnit = (cnRev[3] || '').toLowerCase();
                if (!cnRLenUnit && !cnRWidUnit) { cnRLenUnit = 'cm'; cnRWidUnit = 'cm'; }
                else if (!cnRLenUnit && (cnRWidUnit === 'm' || cnRWidUnit === '米')) { cnRLenUnit = 'm'; }
                else if (!cnRWidUnit && (cnRLenUnit === 'm' || cnRLenUnit === '米')) { cnRWidUnit = 'm'; }
                else if (!cnRLenUnit && (cnRWidUnit === 'mm' || cnRWidUnit === '毫米')) { cnRLenUnit = 'mm'; }
                else if (!cnRWidUnit && (cnRLenUnit === 'mm' || cnRLenUnit === '毫米')) { cnRWidUnit = 'mm'; }
                if (cnRLenUnit === 'mm' || cnRLenUnit === '毫米') lenM /= 10;
                else if (cnRLenUnit === 'm' || cnRLenUnit === '米') lenM *= 100;
                if (cnRWidUnit === 'mm' || cnRWidUnit === '毫米') widM /= 10;
                else if (cnRWidUnit === 'm' || cnRWidUnit === '米') widM *= 100;
                sizeSource = 'cn-keyword-rev';
                result.confidence += 35;
            }
        }
    }
    // 策略3：纯数字对（启发式：长=大的，宽=小的）
    if (lenM === null) {
        var pure = s.match(/(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)(?!\s*(cm|mm|米|m|毫米|x|X|×|\*))/);
        if (pure) {
            var a = parseFloat(pure[1]);
            var b = parseFloat(pure[2]);
            lenM = Math.max(a, b);
            widM = Math.min(a, b);
            sizeSource = 'pure-number';
            result.confidence += 20;
        }
    }
    if (lenM !== null && widM !== null) {
        result.length = Math.round(lenM * 10) / 10;
        result.width  = Math.round(widM * 10) / 10;
        result.raw_size_source = sizeSource;
        // 合理性检查：长宽比超过 10:1 或任一边超过 10000cm (100米) 提示用户核对
        var maxSide = Math.max(result.length, result.width);
        var minSide = Math.min(result.length, result.width);
        if (maxSide > 10000 || (minSide > 0 && maxSide / minSide > 10)) {
            result.size_mismatch_warning = '尺寸异常：' + result.length + '×' + result.width + 'cm（可能是文件名单位打错，如"米m"被当成厘米）';
        }
    }

    // 4) 数量识别（多种模式）
    var qtyPatterns = [
        /[xX×]\s*(\d+)\s*张/,
        /(\d+)\s*张/,
        /[xX×]\s*(\d+)\s*个/,
        /(\d+)\s*个/,
        /共\s*(\d+)/,
        /数量\s*[:：]?\s*(\d+)/,
        /[xX×]\s*(\d+)\s*$/,            // 末尾 "x 5"
        /\s(\d{1,4})\s*$/                // 文件末尾数字
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

    // 5) 【用户需求】规格说明 = 清洗后的完整文件名文字
    //    用户明确要求"规格说明直接用图片文件名，准确填写图片的全部名称"
    //    原始 name 已是完整的客户识别信息，保留扩展名被剩离后的全名
    //    仅需折叠中间的多余空白
    var specText = s.replace(/\s+/g, ' ').trim();
    if (specText) {
        result.specification = specText;
        result.confidence += 10;
    }

    return result;
}

/**
 * 公共函数: 按 parseFileName 解析结果 + imageUrl 填入一个 item-row。
 * 被单张上传 (handleImageSelect) 和批量上传 (addItem) 共同调用,逻辑统一。
 *
 * @param row           .item-row DOM 节点
 * @param parsed        parseFileName() 返回对象
 * @param imageUrl      已上传到服务器的图片 URL;空字符串则只填文字不填图片
 * @param opts.force    true=覆盖已有值 (批量 addItem); false=只填空白 (单张上传,不覆盖用户已填)
 * @returns {{filled:string[], skipped:string[]}} 填入了哪些字段,跳过了哪些字段
 */
function fillRowFromParsed(row, parsed, imageUrl, opts) {
    if (!row) return { filled: [], skipped: [] };
    opts = opts || {};
    var force = !!opts.force;
    var filled = [], skipped = [];

    function setIfEmptyOrForce(sel, value, key) {
        var el = row.querySelector(sel);
        if (!el || value === undefined || value === null || value === '') return false;
        if (!force && el.value && String(el.value).trim() !== '') {
            skipped.push(key);
            return false;
        }
        el.value = value;
        filled.push(key);
        return true;
    }
    function setTextIfEmptyOrForce(sel, value, key) {
        var el = row.querySelector(sel);
        if (!el || value === undefined || value === null || value === '') return false;
        if (!force && el.textContent && el.textContent.trim() !== '' && el.textContent !== '—选择产品—' && el.textContent !== '—选择产品—') {
            skipped.push(key);
            return false;
        }
        el.textContent = value;
        filled.push(key);
        return true;
    }

    // 1) 产品名 (走 searchable-select)
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

    // 2) 规格说明
    if (parsed.specification) {
        setIfEmptyOrForce('[name$="[specification]"]', parsed.specification, '规格说明');
    }

    // 3) 尺寸 (长 / 宽 / 平方数)
    // 【关键】parseFileName 里识别结果统一以 cm 为单位,但表单字段单位是"米"
    // 所以这里需要除以 100 换成米后再填入,避免出现 "300米"、"480米" 这种错误
    // 【四舍五入】按 0.01 米精度取整,避免 HTML5 number input step="0.01" 报警告(0.895 → 0.9)
    if (parsed.length) {
        var lenM = parsed.length / 100;
        var lenRounded = Math.round(lenM * 100) / 100;
        var lenStr = (lenRounded + '').replace(/\.?0+$/, '') || '0';
        setIfEmptyOrForce('.length', lenStr, '长');
    }
    if (parsed.width) {
        var widM2 = parsed.width / 100;
        var widRounded = Math.round(widM2 * 100) / 100;
        var widStr = (widRounded + '').replace(/\.?0+$/, '') || '0';
        setIfEmptyOrForce('.width', widStr, '宽');
    }
    // 【2026-09-08 修复】平方数 = 单张面积 (长×宽),不再乘以数量
    // 数量独立存于 .qty 字段,金额在 calcAmount 里: 单张平方数 × 单价 × 数量
    // 之前: sqm = lenM*widM*qtyNum → 0.54*34=18.36 (错误,被金额二次乘数变成 18727.20)
    // 现在: sqm = lenM*widM       → 0.9*0.6=0.54  (正确,金额 = 0.54*30*34=550.80)
    var lenM = parsed.length ? parseFloat(parsed.length) / 100 : 0;
    var widM = parsed.width  ? parseFloat(parsed.width)  / 100 : 0;
    if (lenM && widM) {
        var sqm = (lenM * widM).toFixed(4);
        setIfEmptyOrForce('.square-meter', sqm, '平方数');
        var sqDisplay = row.querySelector('[data-square-display]');
        if (sqDisplay) {
            sqDisplay.textContent = '≈ ' + parseFloat(sqm).toFixed(2) + ' ㎡';
            sqDisplay.classList.remove('zero');
        }
    }

    // 4) 数量
    if (parsed.quantity) setIfEmptyOrForce('.qty', parsed.quantity, '数量');

    // 5) 单位: 优先产品表实际单位,其次解析的单位,都不覆盖已有
    var productUnit = '';
    if (typeof ALL_PRODUCTS !== 'undefined' && parsed.product_name) {
        var pMatch = ALL_PRODUCTS.find(function(x){ return x.name === parsed.product_name; });
        if (pMatch && pMatch.unit) productUnit = pMatch.unit;
    }
    if (productUnit) {
        setIfEmptyOrForce('.unit', productUnit, '单位');
    }

    // 6) 单价 (从产品表默认价)
    var defaultPrice = 0;
    if (typeof ALL_PRODUCTS !== 'undefined' && parsed.product_name) {
        var pMatch2 = ALL_PRODUCTS.find(function(x){ return x.name === parsed.product_name; });
        if (pMatch2) {
            if (pMatch2.unit_price) defaultPrice = pMatch2.unit_price;
            else if (pMatch2.default_price) defaultPrice = pMatch2.default_price;
            else if (pMatch2.price) defaultPrice = pMatch2.price;
        }
    }
    if (defaultPrice > 0) {
        setIfEmptyOrForce('.price', defaultPrice, '单价');
    }

    // 7) 图片 URL (上传后才填)
    if (imageUrl) {
        var urlInput = row.querySelector('input[data-image-url]');
        if (urlInput) {
            urlInput.value = imageUrl;
            filled.push('参考图');
            // 预览
            var previewEl = row.querySelector('.image-preview, [data-image-preview]');
            if (previewEl) {
                var previewImg = previewEl.querySelector('img');
                if (previewImg) previewImg.src = imageUrl;
                else previewEl.innerHTML = '<img src="' + imageUrl + '" style="max-width:200px;max-height:200px;border-radius:4px;" />';
            }
        }
    }

    // 8) 【2026-09-14】历史价格提示已取消(用户要求不显示); 改为按客户历史价自动定价
    if (parsed.product_name) {
        applyCustomerPrice(row, parsed.product_name);
    }

    // 9) 重算金额
    var priceEl = row.querySelector('.price');
    if (priceEl) calcAmount(priceEl);

    return { filled: filled, skipped: skipped };
}

function renderBatchResults(results, container) {
    var html = '<div style="background:white;border-radius:8px;padding:12px;">';
    html += '<div style="font-weight:600;margin-bottom:8px;color:#0e7490;">📋 识别结果</div>';
    // 【2026-09-08 修复】识别结果区不再重复工具栏 (避免 ID 冲突), 改为提示
    html += '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px;margin-bottom:12px;color:#64748b;font-size:12px;">';
    html += '💡 识别结果仅作预览；如需批量编辑，请在下方「明细」区勾选行后使用批量修改工具栏';
    html += '</div>';
    for (var i = 0; i < results.length; i++) {
        var r = results[i];
        var okCls = r.ok ? '#10B981' : '#EF4444';
        var okIcon = r.ok ? '✅' : '❌';
        html += '<div class="batch-result-row" data-result-idx="' + i + '" style="padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:13px;">';
        html += '<div style="display:flex;align-items:center;">';
        html += '<span style="color:' + okCls + ';">' + okIcon + '</span> ';
        html += '<span style="color:#475569;">' + escapeHtml(r.file) + '</span></div>';
        if (r.ok && r.parsed) {
            // 产品名行
            html += '<div style="margin-top:4px;padding-left:20px;">';
            html += '<span style="color:' + (r.parsed.product_name ? '#0e7490' : '#dc2626') + ';font-weight:600;">📦 ' + escapeHtml(r.parsed.product_name || '未识别产品') + '</span>';
            html += '</div>';
            // 规格行
            html += '<div style="margin-top:2px;padding-left:20px;color:#64748b;">';
            html += '📝 ' + escapeHtml(r.parsed.specification || '（无规格）');
            html += '</div>';
            // 【2026-09-14 新增】色彩模式徽章: RGB 红 / CMYK 绿
            // 【2026-09-15 修复】检测失败也显示灰色"未知"徽章, 不再整行消失(用户会以为功能没生效)
            {
                var modeVal  = r.parsed.color_mode || '未知';
                var modeCls  = r.parsed.color_mode === 'CMYK' ? '#16A34A' : (r.parsed.color_mode === 'GRAY' ? '#64748B' : (r.parsed.color_mode === 'RGB' ? '#DC2626' : '#94A3B8'));
                var modeTitle = r.parsed.color_mode === 'CMYK'
                    ? 'CMYK 印刷色彩模式（四色印刷用；网页预览颜色可能有轻微偏差）'
                    : (r.parsed.color_mode === 'GRAY'
                        ? '灰度图（单色）'
                        : (r.parsed.color_mode === 'RGB'
                            ? 'RGB 屏幕显示色彩模式（喷绘/写真常用；如需印刷请先转 CMYK）'
                            : '未能解析该图片的色彩信息（可能是不支持的格式或元数据异常），请人工确认'));
                html += '<div style="margin-top:2px;padding-left:20px;color:#64748b;">';
                html += '🎨 色彩模式: <span style="background:' + modeCls + ';color:#fff;padding:1px 8px;border-radius:4px;font-size:11px;font-weight:600;" title="' + modeTitle + '">' + modeVal + '</span>';
                html += '</div>';
            }
            // 尺寸行
            html += '<div style="margin-top:2px;padding-left:20px;color:#64748b;">';
            if (r.parsed.length && r.parsed.width) {
                var sourceTag = '';
                if (r.parsed.raw_size_source === 'pixel') {
                    sourceTag = ' <span style="background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:4px;font-size:11px;">像素推算</span>';
                } else if (r.parsed.raw_size_source === 'cn-keyword' || r.parsed.raw_size_source === 'cn-keyword-rev') {
                    sourceTag = ' <span style="background:#dbeafe;color:#1e40af;padding:1px 6px;border-radius:4px;font-size:11px;">中文识别</span>';
                } else if (r.parsed.raw_size_source === 'symbol') {
                    sourceTag = ' <span style="background:#dcfce7;color:#166534;padding:1px 6px;border-radius:4px;font-size:11px;">×符号识别</span>';
                } else if (r.parsed.raw_size_source === 'pure-number') {
                    sourceTag = ' <span style="background:#fde68a;color:#92400e;padding:1px 6px;border-radius:4px;font-size:11px;">纯数字对</span>';
                }
                var warnTag = '';
                var isMismatch = false;
                if (r.parsed.size_mismatch_warning) {
                    isMismatch = true;
                    warnTag = ' <span style="background:#fee2e2;color:#b91c1c;padding:1px 6px;border-radius:4px;font-size:11px;font-weight:600;" title="' + escapeHtml(r.parsed.size_mismatch_warning) + '">⚠️ 尺寸不一致</span>';
                } else if (r.parsed.pixel_width && r.parsed.pixel_height) {
                    // 两者一致时给一个绿色 ✓ 提示作为正向反馈
                    warnTag = ' <span style="background:#dcfce7;color:#166534;padding:1px 6px;border-radius:4px;font-size:11px;">✓ 已校验</span>';
                }
                html += '📏 ' + r.parsed.length + 'cm × ' + r.parsed.width + 'cm' + sourceTag + warnTag;
            } else {
                html += '<span style="color:#f59e0b;">📏 未识别尺寸</span>';
            }
            if (r.parsed.quantity) {
                html += ' <span style="color:#64748b;">· ×' + r.parsed.quantity + '个</span>';
            }
            html += ' <span style="color:#94a3b8;font-size:11px;">(置信 ' + r.parsed.confidence + '%)</span>';
            html += '</div>';
            if (r.parsed.pixel_width && r.parsed.pixel_height) {
                html += '<div style="margin-top:2px;padding-left:20px;color:#94a3b8;font-size:11px;">';
                html += '🖼️ 像素: ' + r.parsed.pixel_width + ' × ' + r.parsed.pixel_height + ' px';
                // 反推 DPI 供手动参者
                if (r.parsed.length && r.parsed.width) {
                    var declaredMaxCm2 = Math.max(r.parsed.length, r.parsed.width);
                    var actualMaxPx2   = Math.max(r.parsed.pixel_width, r.parsed.pixel_height);
                    var infDpi = Math.round(actualMaxPx2 * 2.54 / declaredMaxCm2);
                    html += ' · 反推DPI: <b>' + infDpi + '</b>';
                    if (isMismatch) {
                        // 不一致时额外给出“按 150DPI 像素应是多大”供用户参者
                        var pxL = (actualMaxPx2 / 150 * 2.54).toFixed(1);
                        var pxW = (Math.min(r.parsed.pixel_width, r.parsed.pixel_height) / 150 * 2.54).toFixed(1);
                        html += '<div style="margin-top:3px;padding:4px 8px;background:#fef2f2;border-left:3px solid #ef4444;border-radius:4px;color:#7f1d1d;">';
                        html += '像素推算 (150 DPI): <b>' + pxL + '×' + pxW + ' cm</b>';
                        html += '</div>';
                    }
                }
                html += '</div>';
            }
        } else {
            html += '<div style="margin-top:4px;padding-left:20px;color:#EF4444;">' + escapeHtml(r.error || '失败') + '</div>';
        }
        html += '</div>';
    }
    html += '</div>';
    container.innerHTML = html;
}

function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, function(c) {
        return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
    });
}

// 【2026-09-08 新增】批量修改区辅助函数
function toggleBatchSelectAll(checked) {
    // 【2026-09-08 修复】只勾选/取消勾选明细行 (.item-row) 内的复选框，不动识别结果行
    // (识别结果行是"待加入"的虚副本，工具栏是为"已加入"的明细行而设)
    document.querySelectorAll('.item-row .batch-item-checkbox').forEach(function(cb){
        cb.checked = checked;
    });
    updateBatchSelectedCount();
}

function updateBatchSelectedCount() {
    // 统计全部已选 (识别结果行 + 明细行一起计, 便于查看总数)
    var n = document.querySelectorAll('.batch-item-checkbox:checked').length;
    var el = document.getElementById('batchSelectedCount');
    if (el) el.textContent = '已选 ' + n + ' 项';
    var allCb = document.getElementById('batchSelectAll');
    // "全选"指示器只看明细行总数 (不包含识别结果行,它们独立可勾)
    var totalCb = document.querySelectorAll('.item-row .batch-item-checkbox').length;
    if (allCb) {
        var checkedItemCb = document.querySelectorAll('.item-row .batch-item-checkbox:checked').length;
        allCb.checked = (checkedItemCb > 0 && checkedItemCb === totalCb);
        allCb.indeterminate = (checkedItemCb > 0 && checkedItemCb < totalCb);
    }
}

// 【2026-09-08 新增】应用批量修改：选中的 item-row 同步更新材质/单价/单位
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
    var checkedCbs = document.querySelectorAll('.batch-item-checkbox:checked');
    if (checkedCbs.length === 0) {
        if (typeof showToast === 'function') showToast('请先勾选要修改的项', 'warning');
        else alert('请先勾选要修改的项');
        return;
    }
        var count = 0;
    // 【2026-09-08 修复】同时处理 .batch-result-row (识别结果行) 和 .item-row (明细行)
    // 之前只处理 .batch-result-row 内的 checkbox (用 data-result-idx 索引),
    //   明细行 checkbox 没有 data-result-idx → resultRows[idx] undefined → return → 改 0 个
    var resultRows = document.querySelectorAll('.batch-result-row');
    var allRows = document.querySelectorAll('#itemsContainer .item-row');
    checkedCbs.forEach(function(cb) {
        var targetRow = null;
        var resultRow = cb.closest('.batch-result-row');
        var itemRow = cb.closest('.item-row');
        if (resultRow) {
            // 识别结果行: 提取 product_name → 在 itemsContainer 反查同名 row
            var productNameEl = resultRow.querySelector('span[style*="0e7490"]');
            var productName = '';
            if (productNameEl) {
                productName = productNameEl.textContent.replace(/[\u2728\?]/g, '').trim();
            }
            for (var i = 0; i < allRows.length; i++) {
                var nameInput = allRows[i].querySelector('[data-product-name-input]');
                if (nameInput && nameInput.value === productName) {
                    targetRow = allRows[i];
                    i = allRows.length; // 退出循环 (避免 break 在 forEach 内的语法错误)
                }
            }
        } else if (itemRow) {
            // 明细行: 直接操作当前 row
            targetRow = itemRow;
        }
        if (!targetRow) return;
        // 材质 → 规格说明 input [name$="[specification]"]
        if (hasMat) {
            var specInput = targetRow.querySelector('[name$="[specification]"]');
            if (specInput) {
                specInput.value = mat;
                try { specInput.dispatchEvent(new Event('input', {bubbles:true})); specInput.dispatchEvent(new Event('change', {bubbles:true})); } catch(e) {}
            }
        }
        // 单价
        if (hasPrice) {
            var priceInput = targetRow.querySelector('.price');
            if (priceInput) {
                priceInput.value = parseFloat(priceVal).toFixed(2);
                calcAmount(priceInput);
            }
        }
        // 单位
        if (hasUnit) {
            var unitInput = targetRow.querySelector('.unit');
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
    // 取消选中,避免重复点击
    document.getElementById('batchSelectAll').checked = false;
    checkedCbs.forEach(function(cb){ cb.checked = false; });
    updateBatchSelectedCount();
}

    

    /**
     * 【2026-09-08 新增】批量修改产品名称
     * 从工具栏产品名称 input 读值,在 ALL_PRODUCTS 找匹配,
     * 遍历选中行 → 反向定位 itemsContainer 同名 row,
     * 改 product_name + 联动回填 unit/price/specification
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
                // 模糊: 包含即匹配
                for (var j = 0; j < ALL_PRODUCTS.length; j++) {
                    if (ALL_PRODUCTS[j].name.indexOf(productName) >= 0 || productName.indexOf(ALL_PRODUCTS[j].name) >= 0) {
                        product = ALL_PRODUCTS[j]; break;
                    }
                }
            }
        }

        var checkedCbs = document.querySelectorAll('.batch-item-checkbox:checked');
        if (checkedCbs.length === 0) {
            if (typeof showToast === 'function') showToast('请先勾选要修改的项', 'warning');
            else alert('请先勾选要修改的项');
            return;
        }
        var resultRows = document.querySelectorAll('.batch-result-row');
        var allItemRows = document.querySelectorAll('#itemsContainer .item-row');
        var count = 0;
        var finalName = product ? product.name : productName;  // 提升作用域供 toast 复用
        checkedCbs.forEach(function(cb) {
            // 【2026-09-08 修复】同时处理两种勾选源: 识别结果行 (.batch-result-row) 和明细行 (.item-row)
            var resultRow = cb.closest('.batch-result-row');
            var itemRow = cb.closest('.item-row');
            var targetRow = null;
            var productNameEl = null;
            if (resultRow) {
                // 识别结果行: 提取旧产品名 → 反推 itemsContainer 同名 row
                productNameEl = resultRow.querySelector('span[style*="0e7490"]');
                var oldName = '';
                if (productNameEl) {
                    oldName = productNameEl.textContent.replace(/[\u2728\?]/g, '').trim();
                }
                for (var k = 0; k < allItemRows.length; k++) {
                    var nameInput = allItemRows[k].querySelector('[data-product-name-input]');
                    if (nameInput && nameInput.value === oldName) {
                        targetRow = allItemRows[k]; break;
                    }
                }
            } else if (itemRow) {
                // 明细行: 直接使用当前 row
                targetRow = itemRow;
            }
            if (!targetRow) return;
            // 改 product_name (模糊匹配时写匹配到的产品名, 而非搜索词)
            var nameInput2 = targetRow.querySelector('[data-product-name-input]');
            if (nameInput2) nameInput2.value = finalName;
            var ssText = targetRow.querySelector('.ss-text');
            if (ssText) {
                ssText.textContent = finalName;
                ssText.classList.remove('placeholder');
            }
            // 联动回填
            if (product) {
                if (product.unit) {
                    var unitEl = targetRow.querySelector('.unit');
                    if (unitEl) unitEl.value = product.unit;
                }
                if (product.price) {
                    var priceEl = targetRow.querySelector('.price');
                    if (priceEl) {
                        priceEl.value = parseFloat(product.price).toFixed(2);
                        if (typeof calcAmount === 'function') calcAmount(priceEl);
                    }
                }
                if (product.specification) {
                    var specEl = targetRow.querySelector('[name$="[specification]"]');
                    if (specEl && (!specEl.value || specEl.value.trim() === '')) {
                        specEl.value = product.specification;
                    }
                }
            } else {
                // 找不到匹配产品时, 仅改 product_name, 触发一下 calcAmount
                var p2 = targetRow.querySelector('.price');
                if (p2 && typeof calcAmount === 'function') calcAmount(p2);
            }
            // 同时改识别结果行显示 (如果该勾选来自识别结果)
            if (productNameEl) {
                productNameEl.textContent = '\u2728 ' + finalName;
            }
            count++;
        });
        if (typeof showToast === 'function') {
            showToast('已修改 ' + count + ' 个产品为 "' + finalName + '"' + (product ? ' (联动单位/单价/规格)' : ''), 'success');
        } else {
            alert('已修改 ' + count + ' 个产品为 "' + finalName + '"');
        }
        // 清空产品名输入
        if (inputEl) inputEl.value = '';
        document.getElementById('batchSelectAll').checked = false;
        checkedCbs.forEach(function(cb){ cb.checked = false; });
        if (typeof updateBatchSelectedCount === 'function') updateBatchSelectedCount();
    }
/**
     * 全局 paste + drag/drop + click + mouseover 追踪
     * 策略:鼠标位置优先(所见即所得)
     */
    function initItemImagePasteUpload() {
        // 委托:点击 .image-upload-label 时记住对应 input
        document.addEventListener('click', function(e) {
            var label = e.target.closest && e.target.closest('.image-upload-label');
            if (label) {
                var container = label.closest('.image-upload');
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

        // 鼠标位置追踪:实时更新当前 hover 的 image-upload
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

        // 全局 paste —— 鼠标位置优先
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

                    // 优先:鼠标位置
                    var targetInput = null;
                    var hoverEl = document.querySelector('.image-upload.mouse-hover');
                    if (hoverEl) {
                        targetInput = hoverEl.querySelector('input[type="file"]');
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

        // 拖拽上传 (delegation)
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

    function highlightTargetImageRow(input) {
        document.querySelectorAll('.image-upload.paste-active').forEach(function(el) {
            el.classList.remove('paste-active');
        });
        var container = input.closest('.image-upload');
        if (container) container.classList.add('paste-active');
    }

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

        var msg = '请选择要粘贴到的产品行：\n\n' + options.join('\n') + '\n\n输入行号(1-' + allInputs.length + ')，或点取消';
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
        document.addEventListener('DOMContentLoaded', function(){
            initItemImagePasteUpload();
            if (typeof initBatchRecognize === 'function') initBatchRecognize();
        });
    } else {
        initItemImagePasteUpload();
        if (typeof initBatchRecognize === 'function') initBatchRecognize();
    }

    function addItem(prefill) {
        var container = document.getElementById('itemsContainer');
        // [2026-09-07] 硬限流: 达 MAX_ITEMS_PER_ORDER 直接拒绝, 不渲染新行
        var currentCount = container.querySelectorAll('.item-row').length;
        if (currentCount >= MAX_ITEMS_PER_ORDER) {
            if (typeof window.showToast === 'function') {
                window.showToast('已达单据上限 ' + MAX_ITEMS_PER_ORDER + ' 个产品,无法继续添加。如需更多请分单。', 'error');
            } else {
                alert('已达单据上限 ' + MAX_ITEMS_PER_ORDER + ' 个产品');
            }
            updateItemCount();
            return;
        }
        var html = renderItemRow(CURRENT_ROW_INDEX);
        container.insertAdjacentHTML('beforeend', html);
        var newRow = container.lastElementChild;

        if (prefill) {
            // 统一调用 fillRowFromParsed (force=true) — 批量添加是新行肯定空白。
            // 这样与单张上传 (handleImageSelect) 使用同一份代码。
            var parsedObj = {
                product_name: prefill.name,
                specification: prefill.specification,
                length: prefill.length,
                width: prefill.width,
                quantity: prefill.quantity,
                confidence: prefill.name ? 50 : 0
            };
            fillRowFromParsed(newRow, parsedObj, prefill.imageUrl || '', { force: true });

            // 顶部预览名、图片预览微调 (fillRowFromParsed 未处理)
            if (prefill.imageUrl) {
                var labelEl = newRow.querySelector('.image-upload-label');
                var labelTextEl = newRow.querySelector('[data-label-text]');
                var imgName = prefill.originalName || prefill.name || 'image';
                if (labelEl) labelEl.classList.add('has-image');
                if (labelTextEl) labelTextEl.textContent = imgName.length > 10 ? imgName.substring(0, 10) + '...' : imgName;
            }
        }

        CURRENT_ROW_INDEX++;
        // 【2026-09-08 新增】重排所有行序号 (以 #itemsContainer 内子元素顺序为准)
        refreshAllItemSeq();
    }

    function removeItem(btn) {
        var rows = document.querySelectorAll('.item-row');
        if (rows.length > 1) {
            btn.closest('.item-row').remove();
            calcTotal();
            updateItemCount();
            // 【2026-09-08 新增】删除后重排所有行序号
            refreshAllItemSeq();
        } else {
            alert('至少需要保留一个产品项');
        }
    }

    // 【2026-09-08 新增】重排所有明细行序号与勾选框 (行顺序变化时调用)
    function refreshAllItemSeq() {
        var rows = document.querySelectorAll('#itemsContainer .item-row');
        rows.forEach(function(r, i) {
            var seqEl = r.querySelector('.item-seq-num');
            if (seqEl) seqEl.textContent = String(i + 1);
        });
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
        // 历史价格不再显示; 该客户买过则自动改用客户成交价
        targetRow.querySelector('[data-price-hint]').innerHTML = '';
        applyCustomerPrice(targetRow, p.name);
        calcAmount(targetRow.querySelector('.price'));
    }

    function toggleCustomer(type) {
        document.getElementById('existingCustomer').style.display = type === 'existing' ? 'block' : 'none';
        document.getElementById('newCustomer').style.display = type === 'new' ? 'block' : 'none';
        document.querySelector('[name="customer_id"]').required = type === 'existing';
        document.querySelector('[name="new_customer_name"]').required = type === 'new';
    }

    function checkLastOrder() {
        var cid = document.getElementById('customerSelect').value;
        var banner = document.getElementById('lastOrderBanner');
        if (!cid) { banner.classList.add('hidden'); lastOrderCache = null; return; }

        fetch('order_create.php?action=last_order&customer_id=' + cid)
            .then(function(r){ return r.json(); })
            .then(function(data) {
                if (data.ok) {
                    lastOrderCache = data;
                    document.getElementById('lastOrderNo').textContent = data.order.order_no;
                    document.getElementById('lastOrderDate').textContent = data.order.order_date;
                    document.getElementById('lastOrderTotal').textContent = '¥' + parseFloat(data.order.total_amount).toFixed(2);
                    document.getElementById('lastOrderItems').textContent = data.items.length + ' 个产品';
                    banner.classList.remove('hidden');
                } else {
                    banner.classList.add('hidden');
                    lastOrderCache = null;
                }
            })
            .catch(function(){ banner.classList.add('hidden'); });

        // 【2026-09-14】切换客户后, 已填产品名的行全部重查该客户的历史成交价
        reapplyAllCustomerPrices();
    }

    function copyLastOrder() {
        if (!lastOrderCache || !lastOrderCache.items || lastOrderCache.items.length === 0) return;
        if (!confirm('将用上次订单的 ' + lastOrderCache.items.length + ' 个产品替换当前明细，继续？')) return;

        var container = document.getElementById('itemsContainer');
        container.innerHTML = '';

        lastOrderCache.items.forEach(function(item) {
            addItem({
                name: item.product_name,
                unit: item.unit,
                price: parseFloat(item.unit_price) || 0,
                specification: item.specification,
                quantity: parseFloat(item.quantity) || 1,
                length: item.length,
                width: item.width,
                square_meter: item.square_meter
            });
        });

        document.getElementById('lastOrderBanner').classList.add('hidden');
    }

    function dismissLastOrder() {
        document.getElementById('lastOrderBanner').classList.add('hidden');
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
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

    // 【2026-09-08 修复】预填充产品名 datalist (页面加载时,不等用户上传图片)
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

    addItem();
    updateItemCount(); // [2026-09-07] 初始化计数显示

    // 提交前拦截:检查是否有临时 blob/data: URL 未上传成功
    function checkImagesBeforeSubmit() {
        var inputs = document.querySelectorAll('input[name^="items["][name$="[image_path]"], input[name="item_image_new_0"], input.image-path-input');
        var bad = [];
        for (var i = 0; i < inputs.length; i++) {
            var v = inputs[i].value || '';
            if (v.indexOf('blob:') === 0 || v.indexOf('data:') === 0) {
                bad.push(v.substring(0, 60));
            }
        }
        if (bad.length > 0) {
            if (typeof showToast === 'function') {
                showToast('有 ' + bad.length + ' 张图片仅存在本地(未上传到服务器),请重新拖入上传后再提交', 'error');
            } else {
                alert('有 ' + bad.length + ' 张图片仅存在本地(未上传到服务器),请重新拖入上传后再提交');
            }
            return false;
        }
        return true;
    }
    </script>
</body>
</html>

