<?php
/**
 * 数据备份页面
 * 
 * 功能：
 * - 一键备份数据库
 * - 备份上传文件
 * - 下载备份文件
 * - 查看备份历史
 * - 自动清理旧备份
 */

require_once 'config.php';
initDatabase();
checkLogin();

// 权限检查（仅管理员可访问）
if (!hasPermission(PERM_BACKUP)) {
    die('权限不足，请联系管理员授权');
}

$db = getDB();
// 【2026-09-12 修复】改用绝对路径 + 由系统自行创建的目录（避免外部重建目录导致属主/权限不符，http 用户无法写入）
// 【2026-09-15 审计修复】备份目录移出 webroot（getBackupDir 自动降级，见 config.php）
$backupDir = getBackupDir() . '/';
$message = '';
$messageType = '';

// 创建备份目录
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

/* ==================== 备份/恢复公共函数 ==================== */

// 生成当前数据库的完整 SQL 备份内容（备份与恢复前安全备份共用）
if (!function_exists('generateDbDump')) {
function generateDbDump(PDO $db, $dbName) {
    $tables = [];
    $stmt = $db->query("SHOW TABLES");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }

    $sql = "-- 数据库备份\n";
    $sql .= "-- 生成时间: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- 数据库: " . $dbName . "\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        // 表名安全过滤（只允许字母数字下划线）
        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        if (empty($safeTable)) continue;

        $sql .= "\n-- 表: {$safeTable}\n";
        $sql .= "DROP TABLE IF EXISTS `{$safeTable}`;\n";

        // 获取建表语句
        $stmt = $db->query("SHOW CREATE TABLE `{$safeTable}`");
        $row = $stmt->fetch(PDO::FETCH_NUM);
        $sql .= $row[1] . ";\n\n";

        // 获取表数据
        $stmt = $db->query("SELECT * FROM `{$safeTable}`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) > 0) {
            $sql .= "INSERT INTO `{$safeTable}` VALUES\n";
            $values = [];
            foreach ($rows as $row) {
                $rowValues = [];
                foreach ($row as $value) {
                    if ($value === null) {
                        $rowValues[] = 'NULL';
                    } else {
                        $rowValues[] = "'" . addslashes($value) . "'";
                    }
                }
                $values[] = "(" . implode(', ', $rowValues) . ")";
            }
            $sql .= implode(",\n", $values) . ";\n\n";
        }
    }

    $sql .= "\nSET FOREIGN_KEY_CHECKS=1;\n";
    return $sql;
}
}

// 将 SQL 备份内容按语句拆分（正确处理字符串字面量中的分号、引号与转义）
if (!function_exists('splitSqlDump')) {
function splitSqlDump($sql) {
    $statements = [];
    $len = strlen($sql);
    $buf = '';
    $inString = false;
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        if ($inString) {
            $buf .= $ch;
            if ($ch === '\\') {
                // 反斜杠转义，吞掉下一个字符
                if ($i + 1 < $len) { $buf .= $sql[$i + 1]; $i++; }
            } elseif ($ch === "'") {
                if ($i + 1 < $len && $sql[$i + 1] === "'") {
                    $buf .= "'"; $i++;  // SQL 标准的 '' 转义
                } else {
                    $inString = false;
                }
            }
        } else {
            if ($ch === "'") {
                $inString = true; $buf .= $ch;
            } elseif ($ch === ';') {
                $statements[] = $buf; $buf = '';
            } else {
                $buf .= $ch;
            }
        }
    }
    if (trim($buf) !== '') { $statements[] = $buf; }
    return $statements;
}
}

// 执行拆分后的 SQL 备份内容，返回成功执行的语句数
if (!function_exists('executeSqlDump')) {
function executeSqlDump(PDO $db, $sql) {
    $count = 0;
    foreach (splitSqlDump($sql) as $stmt) {
        $t = trim($stmt);
        // 去掉语句前的注释行（备份格式中 "-- 表: xxx" 与语句在同一块里）
        while (true) {
            $lt = ltrim($t);
            if (strpos($lt, '--') === 0 || strpos($lt, '#') === 0) {
                $nl = strpos($lt, "\n");
                if ($nl === false) { $t = ''; break; }
                $t = substr($lt, $nl + 1);
            } else {
                $t = $lt;
                break;
            }
        }
        if ($t === '') continue;
        if ($db->exec($t) === false) {
            $err = $db->errorInfo();
            throw new Exception('SQL 执行失败: ' . ($err[2] ?? '未知错误') . ' | 语句开头: ' . mb_substr($t, 0, 80));
        }
        $count++;
    }
    return $count;
}
}

// 从完整备份 ZIP 中还原 uploads/ 附件（覆盖同名文件；带路径穿越防护）
if (!function_exists('restoreUploadsFromZip')) {
function restoreUploadsFromZip($zipPath) {
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== TRUE) return -1;
    $baseReal = realpath(__DIR__);
    if ($baseReal === false) { $zip->close(); return -1; }
    $count = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false || $name === '') continue;
        $name = str_replace('\\', '/', $name);
        if (substr($name, -1) === '/') continue;          // 目录条目跳过
        if (strpos($name, 'uploads/') !== 0) continue;    // 只处理 uploads/ 下的文件
        if (strpos($name, '..') !== false || $name[0] === '/') continue; // 防路径穿越
        $targetDir = dirname(__DIR__ . '/' . $name);
        if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);
        $realDir = realpath($targetDir);
        if ($realDir === false || strpos($realDir, $baseReal) !== 0) continue;
        $stream = $zip->getStream($name);
        if (!$stream) continue;
        $fp = @fopen($realDir . '/' . basename($name), 'wb');
        if (!$fp) { fclose($stream); continue; }
        while (!feof($stream)) {
            fwrite($fp, fread($stream, 65536));
        }
        fclose($fp);
        fclose($stream);
        $count++;
    }
    $zip->close();
    return $count;
}
}

/* ==================== 请求处理 ==================== */

// 处理备份请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        $message = '安全验证失败';
        $messageType = 'error';
    } else {
        $action = $_POST['action'];
        @set_time_limit(600); // 打包/加密大文件可能耗时较长

        if ($action === 'backup_db') {
            // 备份数据库
            $filename = 'db_backup_' . date('Y-m-d_H-i-s') . '.sql';
            $filepath = $backupDir . $filename;

            try {
                $sql = generateDbDump($db, DB_NAME);
                // 保存文件（必须加密存储；加密失败则中止，严禁明文落盘）
                if (!defined('BACKUP_ENCRYPT_KEY')) {
                    throw new Exception('未配置 BACKUP_ENCRYPT_KEY，已中止备份（禁止明文备份）');
                }
                $encrypted = encryptData($sql);
                if ($encrypted === false) {
                    throw new Exception('备份加密失败（请检查 PHP openssl 扩展是否启用），已中止以避免明文备份落盘');
                }
                $filename = str_replace('.sql', '.sql.enc', $filename);
                $filepath = $backupDir . $filename;
                if (@file_put_contents($filepath, $encrypted) === false) {
                    throw new Exception('备份文件写入失败：目录权限不足（' . $backupDir . '）');
                }
                
                $message = '数据库备份成功！文件: ' . $filename;
                $messageType = 'success';
                
            } catch (Exception $e) {
                $message = '备份失败: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
        
        elseif ($action === 'backup_files') {
            // 备份上传文件
            $filename = 'files_backup_' . date('Y-m-d_H-i-s') . '.zip';
            $filepath = $backupDir . $filename;
            
            try {
                $zip = new ZipArchive();
                if ($zip->open($filepath, ZipArchive::CREATE) === TRUE) {
                    $uploadDir = 'uploads/';
                    if (is_dir($uploadDir)) {
                        $files = new RecursiveIteratorIterator(
                            new RecursiveDirectoryIterator($uploadDir),
                            RecursiveIteratorIterator::LEAVES_ONLY
                        );
                        
                        foreach ($files as $name => $file) {
                            if (!$file->isDir()) {
                                $filePath = $file->getRealPath();
                                $relativePath = substr($filePath, strlen(realpath($uploadDir)) + 1);
                                $zip->addFile($filePath, $relativePath);
                            }
                        }
                    }
                    $zip->close();
                    
                    // 加密备份文件（【2026-09-12】改为流式加密，135MB+ 大文件不再超出内存限制）
                    if (!defined('BACKUP_ENCRYPT_KEY')) {
                        @unlink($filepath);
                        throw new Exception('未配置 BACKUP_ENCRYPT_KEY，已中止备份（禁止明文备份）');
                    }
                    $zipPath = $filepath;
                    $encPath = $zipPath . '.enc';
                    if (!encryptFileToFile($zipPath, $encPath)) {
                        @unlink($zipPath); @unlink($encPath);
                        throw new Exception('备份加密失败（请检查 PHP openssl 扩展是否启用），已中止以避免明文备份落盘');
                    }
                    unlink($zipPath); // 删除未加密原件
                    $filepath = $encPath;
                    $filename = basename($filepath);

                    $message = '文件备份成功！文件: ' . $filename;
                    $messageType = 'success';
                } else {
                    $message = '无法创建ZIP文件';
                    $messageType = 'error';
                }
            } catch (Exception $e) {
                $message = '备份失败: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
        
        elseif ($action === 'backup_all') {
            // 完整备份（数据库 + 文件）
            $filename = 'full_backup_' . date('Y-m-d_H-i-s') . '.zip';
            $filepath = $backupDir . $filename;
            
            try {
                $zip = new ZipArchive();
                if ($zip->open($filepath, ZipArchive::CREATE) === TRUE) {
                    // 备份数据库
                    $dbSql = generateDbDump($db, DB_NAME);

                    $zip->addFromString('database.sql', $dbSql);
                    
                    // 备份上传文件
                    $uploadDir = 'uploads/';
                    if (is_dir($uploadDir)) {
                        $files = new RecursiveIteratorIterator(
                            new RecursiveDirectoryIterator($uploadDir),
                            RecursiveIteratorIterator::LEAVES_ONLY
                        );
                        
                        foreach ($files as $name => $file) {
                            if (!$file->isDir()) {
                                $filePath = $file->getRealPath();
                                $relativePath = 'uploads/' . substr($filePath, strlen(realpath($uploadDir)) + 1);
                                $zip->addFile($filePath, $relativePath);
                            }
                        }
                    }
                    
                    $zip->close();

                    // 加密备份文件（【2026-09-12】流式加密 + 修复原 isEncryptedBackup 条件永远为假导致完整备份从不加密）
                    if (!defined('BACKUP_ENCRYPT_KEY')) {
                        @unlink($filepath);
                        throw new Exception('未配置 BACKUP_ENCRYPT_KEY，已中止备份（禁止明文备份）');
                    }
                    $zipPath = $filepath;
                    $encPath = $zipPath . '.enc';
                    if (!encryptFileToFile($zipPath, $encPath)) {
                        @unlink($zipPath); @unlink($encPath);
                        throw new Exception('备份加密失败（请检查 PHP openssl 扩展是否启用），已中止以避免明文备份落盘');
                    }
                    unlink($zipPath);
                    $filepath = $encPath;
                    $filename = basename($filepath);

                    $message = '完整备份成功！文件: ' . $filename;
                    $messageType = 'success';
                }
            } catch (Exception $e) {
                $message = '备份失败: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
        
        elseif ($action === 'delete_backup') {
            // 删除备份文件
            $filename = basename($_POST['filename'] ?? '');

            // 严格验证文件名格式：db_backup / files_backup / full_backup + 日期_时间
            // 支持 .sql / .zip 及其 .enc 加密后缀（【2026-09-12 修复】原正则不认 .enc，加密备份无法删除）
            if (!preg_match('/^(db_backup|files_backup|full_backup)_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.(zip|sql)(\.enc)?$/', $filename)) {
                $message = '文件名格式无效';
                $messageType = 'error';
            } else {
                $filepath = $backupDir . $filename;
                
                // 额外安全检查：验证 realpath 在备份目录内
                $realPath = realpath($filepath);
                $realBase = realpath($backupDir);
                if ($realPath !== false && strpos($realPath, $realBase) === 0 && file_exists($filepath)) {
                    unlink($filepath);
                    $message = '备份文件已删除';
                    $messageType = 'success';
                } else {
                    $message = '文件不存在或非法路径';
                    $messageType = 'error';
                }
            }
        }

        elseif ($action === 'restore_db') {
            // 【2026-09-12 新增】从备份文件恢复数据库
            $filename = basename($_POST['filename'] ?? '');
            $okDb   = preg_match('/^db_backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.sql(\.enc)?$/', $filename);
            $okFull = preg_match('/^full_backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.zip(\.enc)?$/', $filename);

            if (!$okDb && !$okFull) {
                $message = '仅支持从「备份数据库」（db_backup_*.sql.enc）或「完整备份」（full_backup_*.zip.enc）文件恢复';
                $messageType = 'error';
            } else {
                $filepath = $backupDir . $filename;

                // 额外安全检查：验证 realpath 在备份目录内
                $realPath = realpath($filepath);
                $realBase = realpath($backupDir);
                if ($realPath === false || strpos($realPath, $realBase) !== 0 || !file_exists($filepath)) {
                    $message = '文件不存在或非法路径';
                    $messageType = 'error';
                } else {
                    @set_time_limit(300); // 大备份恢复可能耗时较长
                    try {
                        // 第一步：恢复前自动为当前数据做一次安全备份（可回退）
                        if (!defined('BACKUP_ENCRYPT_KEY')) {
                            throw new Exception('未配置备份加密密钥，禁止执行恢复操作');
                        }
                        $safetyEnc = encryptData(generateDbDump($db, DB_NAME));
                        if ($safetyEnc === false) {
                            throw new Exception('恢复前的安全备份加密失败，已中止恢复');
                        }
                        $safetyName = 'db_backup_' . date('Y-m-d_H-i-s') . '.sql.enc';
                        if (@file_put_contents($backupDir . $safetyName, $safetyEnc) === false) {
                            throw new Exception('恢复前的安全备份写入失败，已中止恢复');
                        }

                        // 第二步：取出备份文件中的 SQL 内容（.enc 用流式解密，不受内存限制）
                        if (substr($filename, -4) === '.enc') {
                            $srcData = tempnam(sys_get_temp_dir(), 'rst_');
                            if (!decryptFileToFile($filepath, $srcData)) {
                                @unlink($srcData);
                                throw new Exception('备份文件解密失败（加密密钥不匹配或文件损坏）');
                            }
                        } else {
                            $srcData = $filepath;
                        }
                        $tmpZip = null;
                        if ($okFull) {
                            // 完整备份：解压 ZIP 提取其中的 database.sql（临时 ZIP 暂保留，数据库恢复后再还原附件）
                            $tmpZip = $srcData;
                            $zip = new ZipArchive();
                            if ($zip->open($tmpZip) !== TRUE) {
                                if ($srcData !== $filepath) @unlink($srcData);
                                throw new Exception('完整备份 ZIP 包打开失败');
                            }
                            $idx = $zip->locateName('database.sql', ZipArchive::FL_NODIR);
                            if ($idx === false) {
                                $zip->close();
                                if ($srcData !== $filepath) @unlink($srcData);
                                throw new Exception('完整备份包中未找到 database.sql');
                            }
                            $sql = $zip->getFromIndex($idx);
                            $zip->close();
                        } else {
                            $sql = file_get_contents($srcData);
                            if ($srcData !== $filepath) @unlink($srcData);
                        }

                        // 第三步：内容校验（必须是本系统生成的备份）
                        if (empty($sql) || strpos($sql, '-- 数据库备份') === false) {
                            throw new Exception('文件内容不是本系统生成的数据库备份，已中止');
                        }

                        // 第四步：关闭外键检查后逐条执行
                        $db->exec("SET FOREIGN_KEY_CHECKS=0");
                        $count = executeSqlDump($db, $sql);
                        $db->exec("SET FOREIGN_KEY_CHECKS=1");

                        // 第五步：完整备份同时还原 uploads/ 附件（图片等，覆盖同名文件）
                        $fileMsg = '';
                        if ($okFull && $tmpZip !== null) {
                            $restored = restoreUploadsFromZip($tmpZip);
                            if ($srcData !== $filepath) @unlink($srcData);
                            if ($restored < 0) {
                                $fileMsg = '；附件还原失败（ZIP 打不开）';
                            } else {
                                $fileMsg = "；同时还原了 {$restored} 个附件文件（同名已覆盖）";
                            }
                        } else {
                            $fileMsg = '；注：数据库备份不含附件图片，附件请用完整备份恢复';
                        }

                        $message = "恢复成功！共执行 {$count} 条 SQL 语句{$fileMsg}。当前数据已自动备份为：{$safetyName}（如恢复有误可用它回退）";
                        $messageType = 'success';
                    } catch (Exception $e) {
                        try { $db->exec("SET FOREIGN_KEY_CHECKS=1"); } catch (Exception $ignore) {}
                        $message = '恢复失败: ' . $e->getMessage();
                        $messageType = 'error';
                    }
                }
            }
        }
    }
}

// 获取备份文件列表
$backupFiles = [];
if (is_dir($backupDir)) {
    $files = scandir($backupDir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $filepath = $backupDir . $file;
            $backupFiles[] = [
                'name' => $file,
                'size' => filesize($filepath),
                'time' => filemtime($filepath),
                'path' => $filepath
            ];
        }
    }
    
    // 按时间倒序排序
    usort($backupFiles, function($a, $b) {
        return $b['time'] - $a['time'];
    });
}

// 生成CSRF Token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$pageTitle = '数据备份';
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - <?php echo SITE_TITLE; ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .backup-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .backup-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 24px;
            text-align: center;
            cursor: pointer;
            transition: transform 0.3s;
        }
        .backup-card:hover {
            transform: translateY(-4px);
        }
        .backup-card .icon {
            font-size: 48px;
            margin-bottom: 12px;
        }
        .backup-card h3 {
            margin: 0 0 8px 0;
            font-size: 18px;
        }
        .backup-card p {
            margin: 0;
            opacity: 0.9;
            font-size: 14px;
        }
        .backup-list {
            margin-top: 24px;
        }
        .backup-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            margin-bottom: 12px;
        }
        .backup-info {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .backup-icon {
            font-size: 32px;
        }
        .backup-details h4 {
            margin: 0 0 4px 0;
            font-size: 14px;
        }
        .backup-meta {
            font-size: 12px;
            color: #6b7280;
        }
        .backup-actions-btns {
            display: flex;
            gap: 8px;
        }
    </style>
</head>
<body>
    <?php echo renderNav('settings'); ?>
    
    <div class="container-fluid">
        <div class="page-title-bar">
            <div class="page-title-left">
                <h1>💾 数据备份与恢复</h1>
                <p>定期备份数据，防止数据丢失；支持一键恢复</p>
            </div>
        </div>
        
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?> alert-dismissible">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="close" onclick="this.parentElement.remove()">&times;</button>
        </div>
        <?php endif; ?>
        
        <div class="backup-actions">
            <div class="backup-card" onclick="submitBackup('backup_db')">
                <div class="icon">🗄️</div>
                <h3>备份数据库</h3>
                <p>导出所有表结构和数据</p>
            </div>
            
            <div class="backup-card" onclick="submitBackup('backup_files')" style="background:linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                <div class="icon">📁</div>
                <h3>备份上传文件</h3>
                <p>打包所有上传的附件</p>
            </div>
            
            <div class="backup-card" onclick="submitBackup('backup_all')" style="background:linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                <div class="icon">📦</div>
                <h3>完整备份</h3>
                <p>数据库 + 上传文件</p>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">📂 备份文件列表</h5>
            </div>
            <div class="card-body">
                <?php if (empty($backupFiles)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">📂</div>
                        <p>暂无备份文件</p>
                        <p class="text-muted">点击上方卡片开始备份</p>
                    </div>
                <?php else: ?>
                    <div class="backup-list">
                        <?php foreach ($backupFiles as $backup): ?>
                            <div class="backup-item">
                                <div class="backup-info">
                                    <div class="backup-icon">
                                        <?php
                                        $nameLower = strtolower($backup['name']);
                                        echo (strpos($nameLower, '.sql') !== false) ? '🗄️' : '📦';
                                        ?>
                                    </div>
                                    <div class="backup-details">
                                        <h4><?php echo htmlspecialchars($backup['name']); ?></h4>
                                        <div class="backup-meta">
                                            <?php echo formatFileSize($backup['size']); ?> | 
                                            <?php echo date('Y-m-d H:i:s', $backup['time']); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="backup-actions-btns">
                                    <?php if (preg_match('/^(db_backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.sql|full_backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.zip)(\.enc)?$/', $backup['name'])): ?>
                                    <button class="btn btn-sm btn-outline-warning" onclick="restoreBackup('<?php echo htmlspecialchars(addslashes($backup['name'])); ?>')">
                                        ♻️ 恢复
                                    </button>
                                    <?php endif; ?>
                                    <a href="download_backup.php?file=<?php echo urlencode($backup['name']); ?>" class="btn btn-sm btn-outline-primary">
                                        ⬇️ 下载
                                    </a>
                                    <button class="btn btn-sm btn-outline-danger" onclick="deleteBackup('<?php echo htmlspecialchars(addslashes($backup['name'])); ?>')">
                                        🗑️ 删除
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <form id="backupForm" method="POST" style="display:none;">
        <input type="hidden" name="action" id="backupAction">
        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
    </form>
    
    <script>
    function submitBackup(action) {
        if (!confirm('确定要开始备份吗？')) {
            return;
        }
        document.getElementById('backupAction').value = action;
        document.getElementById('backupForm').submit();
    }
    
    function deleteBackup(filename) {
        if (!confirm('确定要删除备份文件 "' + filename + '" 吗？')) {
            return;
        }
        
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_backup">
            <input type="hidden" name="filename" value="${filename}">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
        `;
        document.body.appendChild(form);
        form.submit();
    }
    
    function restoreBackup(filename) {
        if (!confirm('⚠️ 高危操作确认！\n\n恢复将【清空并覆盖】当前数据库中的全部数据！\n\n备份文件: ' + filename + '\n\n· 数据库备份：仅恢复数据库（不含附件图片）\n· 完整备份：恢复数据库 + 还原附件图片\n\n（系统会在恢复前自动备份当前数据，用于回退）\n\n确定要继续吗？')) {
            return;
        }
        const code = prompt('请输入「恢复」两个字以确认执行（输入其他内容将取消）：');
        if (code === null || code.trim() !== '恢复') {
            alert('输入不匹配，已取消恢复操作');
            return;
        }
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="restore_db">
            <input type="hidden" name="filename" value="${filename}">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
        `;
        document.body.appendChild(form);
        form.submit();
    }

    function formatFileSize(bytes) {
        if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
        if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
        return bytes + ' B';
    }
    </script>
</body>
</html>
