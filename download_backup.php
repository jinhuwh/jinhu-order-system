<?php
/**
 * 备份文件下载处理脚本
 */

require_once 'config.php';
initDatabase();
checkLogin();

// 权限检查
if (!hasPermission(PERM_BACKUP)) {
    die('权限不足，请联系管理员授权');
}

$filename = basename($_GET['file'] ?? '');
if (empty($filename)) {
    die('文件名不能为空');
}

// 严格验证文件名格式：db_backup / files_backup / full_backup + 日期_时间
// 支持 .sql / .zip / 加密后的 .sql.enc / .zip.enc 后缀
if (!preg_match('/^(db_backup|files_backup|full_backup)_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.(zip|sql)(\.enc)?$/', $filename)) {
    die('非法文件名格式');
}

$filepath = getBackupDir() . '/' . $filename;

// 验证文件路径是否在允许目录内（防止路径遍历）
$realPath = realpath($filepath);
$basePath = realpath(getBackupDir() . '/');
if ($realPath === false || strpos($realPath, $basePath) !== 0) {
    die('非法访问');
}

if (!file_exists($filepath)) {
    die('备份文件不存在');
}

// 设置安全下载头
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($filepath));
header('X-Content-Type-Options: nosniff');

// 输出文件（.enc 文件需先解密）
if (isEncryptedBackup($filename)) {
    @set_time_limit(300);
    // 【2026-09-12】流式解密到临时文件：大文件不受内存限制，且 Content-Length 与明文体积一致（修复下载损坏问题）
    $downloadName = preg_replace('/\.enc$/', '', $filename);
    $tmpFile = tempnam(sys_get_temp_dir(), 'bkd_');
    if ($tmpFile === false || !decryptFileToFile($filepath, $tmpFile)) {
        @unlink($tmpFile);
        die('备份文件解密失败，请检查加密密钥是否正确');
    }
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($tmpFile));
    readfile($tmpFile);
    @unlink($tmpFile);
} else {
    readfile($filepath);
}
exit;
?>
