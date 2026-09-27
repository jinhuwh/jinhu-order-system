<?php
/**
 * 文件下载处理脚本
 */

require_once 'config.php';
initDatabase();
checkLogin();

$fileId = intval($_GET['file_id'] ?? 0);
if ($fileId <= 0) {
    die('无效的文件ID');
}

$db = getDB();

// 获取文件信息（同时获取订单信息用于权限验证）
$stmt = $db->prepare("SELECT a.*, o.created_by 
    FROM order_attachments a 
    JOIN orders o ON a.order_id = o.id 
    WHERE a.id = ?");
$stmt->execute([$fileId]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$file) {
    die('文件不存在');
}

// 权限检查：普通用户只能下载自己创建的订单的文件
if (!isAdmin() && $file['created_by'] != $_SESSION['user_id']) {
    die('无权访问该文件');
}

// 【2026-09-09 安全加固】realpath 路径校验（文件存在性 + 防路径穿越读取任意文件）
$baseDir = realpath(dirname(__FILE__));
$rawPath = $file['file_path'];
$realFilePath = false;
// 兼容相对路径（uploads/orders/N/xxx）与绝对路径
if (file_exists($rawPath)) {
    $realFilePath = realpath($rawPath);
} else {
    $candidate = $baseDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rawPath);
    if (file_exists($candidate)) {
        $realFilePath = realpath($candidate);
    }
}
if ($realFilePath === false) {
    http_response_code(404);
    die('文件已被删除');
}
// 必须位于站点根目录内，防止路径穿越读取任意文件
if (strpos($realFilePath, $baseDir . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(403);
    die('非法访问');
}

// 设置下载头
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . sanitizeDownloadFilename($file['file_name']) . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . $file['file_size']);

// 输出文件
readfile($realFilePath);
exit;
?>
