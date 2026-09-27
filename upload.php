<?php
/**
 * 订单文件上传处理
 * 支持：图片、PDF、Office文档、压缩包等
 */

// 【2026-09-09 修复】opcache + display_errors + 防意外输出污染 JSON
if (function_exists('opcache_invalidate')) { @opcache_invalidate(__FILE__, true); }
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
require_once 'config.php';

// 设置JSON响应头
header('Content-Type: application/json; charset=utf-8');

// 检查登录状态（XHR场景返回JSON而非跳转）
if (!isset($_SESSION['user_id'])) {

    // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON

    while (ob_get_level() > 0) { ob_end_clean(); }

    echo json_encode(['ok' => false, 'msg' => '登录已过期，请重新登录', 'code' => 401]);
    exit;
}

// 验证CSRF Token
$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrfToken)) {

    // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON

    while (ob_get_level() > 0) { ob_end_clean(); }

    echo json_encode(['ok' => false, 'msg' => '安全验证失败，请刷新页面后重试', 'code' => 403]);
    exit;
}

// 获取订单ID
$orderId = intval($_POST['order_id'] ?? 0);
if ($orderId <= 0) {

    // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON

    while (ob_get_level() > 0) { ob_end_clean(); }

    echo json_encode(['ok' => false, 'msg' => '订单ID无效', 'code' => 400]);
    exit;
}

// 【2026-09-08 安全加固】校验订单是否存在，防止向无效/不存在的订单写入孤儿文件
try {
    $dbCheck = getDB();
    $stmtCheck = $dbCheck->prepare("SELECT id FROM orders WHERE id = ?");
    $stmtCheck->execute([$orderId]);
    if (!$stmtCheck->fetchColumn()) {

        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON

        while (ob_get_level() > 0) { ob_end_clean(); }

        echo json_encode(['ok' => false, 'msg' => '订单不存在，无法上传文件', 'code' => 404]);
        exit;
    }
} catch (Exception $e) {
    error_log("Upload order existence check failed: " . $e->getMessage());

    // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON

    while (ob_get_level() > 0) { ob_end_clean(); }

    echo json_encode(['ok' => false, 'msg' => '服务器错误，请稍后重试', 'code' => 500]);
    exit;
}

// 检查是否有文件上传
if (!isset($_FILES['file']) || empty($_FILES['file']['name'])) {

    // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON

    while (ob_get_level() > 0) { ob_end_clean(); }

    echo json_encode(['ok' => false, 'msg' => '请选择要上传的文件', 'code' => 400]);
    exit;
}

$file = $_FILES['file'];

// 检查上传错误
if ($file['error'] !== UPLOAD_ERR_OK) {
    $errorMsg = '文件上传失败';
    switch ($file['error']) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            $errorMsg = '文件大小超过服务器限制';
            break;
        case UPLOAD_ERR_PARTIAL:
            $errorMsg = '文件上传不完整，请重试';
            break;
        case UPLOAD_ERR_NO_FILE:
            $errorMsg = '未选择文件';
            break;
        case UPLOAD_ERR_NO_TMP_DIR:
            $errorMsg = '服务器临时目录不可用';
            break;
        case UPLOAD_ERR_CANT_WRITE:
            $errorMsg = '服务器写入失败';
            break;
        case UPLOAD_ERR_EXTENSION:
            $errorMsg = '上传被服务器扩展阻止';
            break;
    }

    // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON

    while (ob_get_level() > 0) { ob_end_clean(); }

    echo json_encode(['ok' => false, 'msg' => $errorMsg, 'code' => $file['error']]);
    exit;
}

// 验证文件大小（10MB限制）
$maxSize = 10 * 1024 * 1024;
if ($file['size'] > $maxSize) {

    // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON

    while (ob_get_level() > 0) { ob_end_clean(); }

    echo json_encode(['ok' => false, 'msg' => '文件大小超过10MB限制', 'code' => 413]);
    exit;
}

// 验证文件类型
$originalName = preg_replace('/[\r\n"]/', '', $file['name']); // 清洗 CRLF/引号,防止下载头注入
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

$allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'rar', 'txt'];
if (!in_array($ext, $allowedExts)) {

    // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON

    while (ob_get_level() > 0) { ob_end_clean(); }

    echo json_encode(['ok' => false, 'msg' => '不支持的文件类型', 'code' => 415]);
    exit;
}

// 验证 MIME 类型（【兼容降级】无 fileinfo 扩展时跳过 MIME 校验，仅用扩展名白名单 + 图像签名兜底）
$imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
    if ($finfo) finfo_close($finfo);

    $allowedMimes = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip', 'application/x-rar-compressed', 'application/x-zip-compressed',
        'text/plain'
    ];

    if ($mimeType === false || !in_array($mimeType, $allowedMimes)) {

        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON

        while (ob_get_level() > 0) { ob_end_clean(); }

        echo json_encode(['ok' => false, 'msg' => '文件类型验证失败', 'code' => 415]);
        exit;
    }
} else {
    // 无 fileinfo：扩展名白名单已在上面校验；图片类型额外用 getimagesize 验证文件头（不依赖任何扩展）
    if (in_array($ext, $imageExts, true) && @getimagesize($file['tmp_name']) === false) {

        while (ob_get_level() > 0) { ob_end_clean(); }

        echo json_encode(['ok' => false, 'msg' => '图片文件内容无效', 'code' => 415]);
        exit;
    }
}

// 创建上传目录
$baseDir = realpath(__DIR__);
if ($baseDir === false) {
    $baseDir = __DIR__;
}
$uploadDir = $baseDir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'orders' . DIRECTORY_SEPARATOR . $orderId;

if (!is_dir($uploadDir)) {
    if (!@mkdir($uploadDir, 0755, true)) {

        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON

        while (ob_get_level() > 0) { ob_end_clean(); }

        echo json_encode(['ok' => false, 'msg' => '无法创建上传目录', 'code' => 500]);
        exit;
    }
    @file_put_contents($uploadDir . DIRECTORY_SEPARATOR . '.htaccess', "Options -ExecCGI\nphp_flag engine off\n");
}

if (!is_writable($uploadDir)) {

    // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON

    while (ob_get_level() > 0) { ob_end_clean(); }

    echo json_encode(['ok' => false, 'msg' => '上传目录不可写', 'code' => 500]);
    exit;
}

// 生成安全文件名
$safeName = preg_replace('/[^A-Za-z0-9_.\-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
if (empty($safeName)) {
    $safeName = 'file';
}
$uniqueName = $safeName . '_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(4)), 0, 6) . '.' . $ext;
$targetPath = $uploadDir . DIRECTORY_SEPARATOR . $uniqueName;

if (!@move_uploaded_file($file['tmp_name'], $targetPath)) {

    // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON

    while (ob_get_level() > 0) { ob_end_clean(); }

    echo json_encode(['ok' => false, 'msg' => '文件保存失败', 'code' => 500]);
    exit;
}

// 图片压缩到60KB内（非图片类型自动跳过，失败不影响上传）
@compressUploadedImage($targetPath);

try {
    $db = getDB();
    $relPath = 'uploads/orders/' . $orderId . '/' . $uniqueName;

    $stmt = $db->prepare("INSERT INTO order_attachments (order_id, file_name, file_path, file_size, file_type, uploaded_by, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([
        $orderId,
        $originalName,
        $relPath,
        filesize($targetPath),
        $mimeType,
        $_SESSION['user_id']
    ]);
    
    $fileId = $db->lastInsertId();
    
    logOperation('upload_file', [
        'order_id' => $orderId,
        'file_id' => $fileId,
        'file_name' => $originalName,
        'file_size' => $file['size']
    ]);

    
    // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON

    
    while (ob_get_level() > 0) { ob_end_clean(); }

    
    echo json_encode([
        'ok' => true,
        'msg' => '上传成功',
        'data' => [
            'id' => $fileId,
            'file_name' => $originalName,
            'file_size' => formatFileSize($file['size'])
        ]
    ]);
    
} catch (PDOException $e) {
    @unlink($targetPath);
    error_log("Upload DB error: " . $e->getMessage());

    // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON

    while (ob_get_level() > 0) { ob_end_clean(); }

    echo json_encode(['ok' => false, 'msg' => '保存文件记录失败', 'code' => 500]);
    exit;
} catch (Exception $e) {
    @unlink($targetPath);
    error_log("Upload error: " . $e->getMessage());

    // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON

    while (ob_get_level() > 0) { ob_end_clean(); }

    echo json_encode(['ok' => false, 'msg' => '上传处理失败', 'code' => 500]);
    exit;
}