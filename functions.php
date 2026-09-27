<?php
/**
 * 安全辅助函数库
 * 
 * 包含输入验证、输出转义、安全工具函数
 */

// ===== 安全输出函数 =====

/**
 * HTML转义输出
 * @param mixed $string 要转义的字符串
 * @return string 转义后的字符串
 */
function h($string) {
    if ($string === null) {
        return '';
    }
    return htmlspecialchars(strval($string), ENT_QUOTES, 'UTF-8');
}

/**
 * JavaScript转义输出
 * @param mixed $data 要转义的数据
 * @return string 转义后的JSON
 */
function j($data) {
    return json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
}

/**
 * URL编码输出
 * @param mixed $string 要编码的字符串
 * @return string 编码后的字符串
 */
function u($string) {
    return urlencode($string ?? '');
}

/**
 * 清理并验证输入
 * @param string $input 输入字符串
 * @param int|null $maxLength 最大长度限制
 * @param bool $allowHtml 是否允许HTML
 * @return string 清理后的字符串
 */
function cleanInput($input, $maxLength = null, $allowHtml = false) {
    $input = trim($input ?? '');
    
    if (!$allowHtml) {
        $input = strip_tags($input);
    }
    
    if ($maxLength !== null && mb_strlen($input, 'UTF-8') > $maxLength) {
        $input = mb_substr($input, 0, $maxLength, 'UTF-8');
    }
    
    return $input;
}

// ===== 密码安全函数 =====

/**
 * 验证密码复杂度
 * @param string $password 密码
 * @return array 错误信息数组，空数组表示验证通过
 */
function validatePassword($password) {
    $errors = [];
    
    if (strlen($password) < 8) {
        $errors[] = '密码长度至少8位';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = '密码必须包含大写字母';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = '密码必须包含小写字母';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = '密码必须包含数字';
    }
    
    return $errors;
}

/**
 * 生成随机密码
 * @param int $length 密码长度
 * @return string 随机密码
 */
function generateRandomPassword($length = 12) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%^&*';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

// ===== 输入验证函数 =====

/**
 * 验证手机号
 * @param string $phone 手机号
 * @return bool 是否有效
 */
function validatePhone($phone) {
    return preg_match('/^1[3-9]\d{9}$/', $phone);
}

/**
 * 验证邮箱
 * @param string $email 邮箱
 * @return bool 是否有效
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * 验证金额
 * @param mixed $amount 金额
 * @return bool 是否有效
 */
function validateAmount($amount) {
    return is_numeric($amount) && $amount >= 0 && $amount <= 999999999.99;
}

/**
 * 验证日期
 * @param string $date 日期字符串
 * @param string $format 日期格式
 * @return bool 是否有效
 */
function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

// ===== 格式化工具函数 =====

/**
 * 格式化文件大小（B → KB/MB/GB）
 * @param int $bytes 字节数
 * @param int $decimals 保留小数位数
 * @return string 人类可读的文件大小
 */
function formatFileSize($bytes, $decimals = 2) {
    $bytes = (int)$bytes;
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, $decimals) . ' GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, $decimals) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, $decimals) . ' KB';
    }
    return $bytes . ' B';
}

// ===== 安全工具函数 =====

/**
 * 安全的重定向
 * @param string $url 目标URL
 * @param bool $allowExternal 是否允许外部URL
 */
function safeRedirect($url, $allowExternal = false) {
    // 防止开放重定向
    if (!$allowExternal) {
        $parsed = parse_url($url);
        if (isset($parsed['host']) || strpos($url, '://') !== false) {
            $url = 'index.php'; // 默认跳转到首页
        }
    }
    
    header('Location: ' . $url);
    exit;
}

/**
 * 安全的文件下载
 * @param string $filePath 文件路径
 * @param string $fileName 下载文件名
 */
function safeDownload($filePath, $fileName = null) {
    // 检查文件是否存在
    if (!file_exists($filePath)) {
        http_response_code(404);
        die('文件不存在');
    }
    
    // 检查路径是否在允许的目录内
    $realPath = realpath($filePath);
    $baseDir = realpath(__DIR__);
    if (strpos($realPath, $baseDir) !== 0) {
        http_response_code(403);
        die('非法访问');
    }
    
    // 设置下载头
    $fileName = sanitizeDownloadFilename($fileName ?? basename($filePath));
    $mimeType = mime_content_type($realPath);
    
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize($realPath));
    header('X-Content-Type-Options: nosniff');
    
    readfile($realPath);
    exit;
}

// 清洗下载文件名，防止 CRLF / 双引号导致的响应头注入（response splitting）
function sanitizeDownloadFilename($name) {
    $name = basename((string)($name ?? ''));
    $name = preg_replace('/[\r\n"]/', '', $name);
    return $name === '' ? 'download' : $name;
}

/**
 * 频率限制（使用Session实现简单版本）
 * @param string $key 限制键名
 * @param int $maxRequests 最大请求数
 * @param int $period 时间周期（秒）
 * @return bool 是否超过限制
 */
function rateLimit($key, $maxRequests = 10, $period = 60) {
    $sessionKey = 'rate_limit_' . $key;
    
    if (!isset($_SESSION[$sessionKey])) {
        $_SESSION[$sessionKey] = [
            'count' => 0,
            'reset_time' => time() + $period
        ];
    }
    
    // 检查是否过期
    if (time() > $_SESSION[$sessionKey]['reset_time']) {
        $_SESSION[$sessionKey] = [
            'count' => 0,
            'reset_time' => time() + $period
        ];
    }
    
    $_SESSION[$sessionKey]['count']++;
    
    return $_SESSION[$sessionKey]['count'] > $maxRequests;
}

/**
 * 记录操作日志
 * @param string $action 操作类型
 * @param array $details 操作详情
 * @param string $targetType 操作对象类型
 * @param int $targetId 操作对象ID
 * @param string $targetDesc 操作对象描述
 */
function logOperation($action, $details = [], $targetType = '', $targetId = 0, $targetDesc = '') {
    $db = getDB();
    
    try {
        $stmt = $db->prepare("INSERT INTO operation_logs 
            (user_id, username, action, target_type, target_id, target_desc, details, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $_SESSION['user_id'] ?? 0,
            $_SESSION['username'] ?? '',
            $action,
            $targetType,
            $targetId,
            $targetDesc,
            json_encode($details, JSON_UNESCAPED_UNICODE),
            $_SERVER['REMOTE_ADDR'] ?? '',
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
        ]);
    } catch (Exception $e) {
        error_log("Failed to log operation: " . $e->getMessage());
    }
}

/**
 * 统一的错误处理
 * @param string $userMessage 用户看到的错误信息
 * @param string|null $logMessage 记录到日志的详细信息
 */
function handleError($userMessage, $logMessage = null) {
    if ($logMessage) {
        error_log("[System Error] " . $logMessage);
    }
    
    $_SESSION['error_message'] = $userMessage;
    header('Location: error.php');
    exit;
}

/**
 * 验证文件上传
 * @param array $file $_FILES 数组中的文件
 * @param array $allowedTypes 允许的MIME类型
 * @param int $maxSize 最大文件大小（字节）
 * @return array ['valid' => bool, 'error' => string|null]
 */
function validateFileUpload($file, $allowedTypes = [], $maxSize = 2097152) {
    $result = ['valid' => false, 'error' => null];
    
    // 检查上传错误
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $result['error'] = '文件上传失败：' . $file['error'];
        return $result;
    }
    
    // 检查文件大小
    if ($file['size'] > $maxSize) {
        $result['error'] = '文件大小超过限制（最大 ' . round($maxSize / 1024 / 1024, 2) . 'MB）';
        return $result;
    }
    
    // 检查MIME类型
    // 【兼容降级】fileinfo 扩展缺失时降级：改用扩展名白名单校验（32位ARM/低内存服务器可能无法安装 fileinfo）
    if (!empty($allowedTypes)) {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mimeType, $allowedTypes)) {
                $result['error'] = '文件类型不允许';
                return $result;
            }
        } else {
            // 无 fileinfo：按原始文件名扩展名映射 MIME，且映射结果必须在允许列表内
            $extMimeMap = [
                'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
                'pdf' => 'application/pdf',
            ];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!isset($extMimeMap[$ext]) || !in_array($extMimeMap[$ext], $allowedTypes)) {
                $result['error'] = '文件类型不允许';
                return $result;
            }
        }
    }
    
    $result['valid'] = true;
    return $result;
}

/**
 * AES-256-CBC 加密（用于备份文件加密）
 * 输出格式：base64(IV . 密文)，IV 随机生成内置于密文头部
 * @param string $data 明文数据
 * @return string|false 加密后数据，失败返回 false
 */
function encryptData($data) {
    if (!defined('BACKUP_ENCRYPT_KEY')) return false;
    $key = BACKUP_ENCRYPT_KEY;
    if (strlen($key) < 16) return false;
    $iv = openssl_random_pseudo_bytes(16);
    $ciphertext = openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($ciphertext === false) return false;
    // 输出：IV(16字节) + 密文，base64 编码
    return base64_encode($iv . $ciphertext);
}

/**
 * AES-256-CBC 解密
 * @param string $encrypted base64(IV . 密文)
 * @return string|false 解密后明文，失败返回 false
 */
function decryptData($encrypted) {
    if (!defined('BACKUP_ENCRYPT_KEY')) return false;
    $key = BACKUP_ENCRYPT_KEY;
    $raw = base64_decode($encrypted, true);
    if ($raw === false || strlen($raw) < 17) return false;
    $iv = substr($raw, 0, 16);
    $ciphertext = substr($raw, 16);
    $plaintext = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return $plaintext;
}

/**
 * 流式加密文件（用于大文件，如完整备份 ZIP）
 * 输出格式与 encryptData 完全一致：base64(IV . 密文)，可互相解密
 * 分块 CBC 链式处理，内存占用恒定（约数 MB），不受文件大小影响
 * @param string $srcPath 明文源文件路径
 * @param string $dstPath 密文输出文件路径
 * @return bool 成功 true，失败 false
 */
function encryptFileToFile($srcPath, $dstPath) {
    if (!defined('BACKUP_ENCRYPT_KEY')) return false;
    $key = BACKUP_ENCRYPT_KEY;
    if (strlen($key) < 16) return false;
    $in = @fopen($srcPath, 'rb');
    $out = @fopen($dstPath, 'wb');
    if (!$in || !$out) {
        if ($in) fclose($in);
        if ($out) { fclose($out); @unlink($dstPath); }
        return false;
    }
    $chunkSize = 3145728; // 3MB，16 的倍数（CBC 块对齐）
    $iv = openssl_random_pseudo_bytes(16);
    $pending = $iv; // base64 需按 3 字节对齐拼接，IV 先进缓冲
    $ok = true;
    while (!feof($in)) {
        $raw = fread($in, $chunkSize);
        if ($raw === false) { $ok = false; break; }
        if ($raw === '') break;
        $atEof = feof($in);
        // 中间块：原始数据 + 零填充标志（输入必须是块对齐，3MB 满足）；末块：PKCS7 标准填充
        $flags = $atEof ? OPENSSL_RAW_DATA : (OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
        $enc = openssl_encrypt($raw, 'AES-256-CBC', $key, $flags, $iv);
        if ($enc === false) { $ok = false; break; }
        $pending .= $enc;
        $use = strlen($pending) - (strlen($pending) % 3);
        if (fwrite($out, base64_encode(substr($pending, 0, $use))) === false) { $ok = false; break; }
        $pending = substr($pending, $use);
        $iv = substr($enc, -16); // CBC 链：下一块 IV = 上一块最后 16 字节
    }
    if ($ok && $pending !== '') {
        $ok = fwrite($out, base64_encode($pending)) !== false;
    }
    fclose($in);
    fclose($out);
    if (!$ok) { @unlink($dstPath); return false; }
    return true;
}

/**
 * 流式解密 .enc 备份文件（用于大文件）
 * 与 encryptData / encryptFileToFile 输出格式兼容
 * @param string $srcPath 密文源文件路径
 * @param string $dstPath 明文输出文件路径
 * @return bool 成功 true，失败 false
 */
function decryptFileToFile($srcPath, $dstPath) {
    if (!defined('BACKUP_ENCRYPT_KEY')) return false;
    $key = BACKUP_ENCRYPT_KEY;
    $in = @fopen($srcPath, 'rb');
    $out = @fopen($dstPath, 'wb');
    if (!$in || !$out) {
        if ($in) fclose($in);
        if ($out) { fclose($out); @unlink($dstPath); }
        return false;
    }
    $b64Chunk = 4194304; // 4MB，4 的倍数保证 base64 对齐（解码后恰好 3MB）
    $buf = '';
    $iv = null;
    $ok = true;
    while (!feof($in)) {
        $data = fread($in, $b64Chunk);
        if ($data === false) { $ok = false; break; }
        $buf .= $data;
        $use = strlen($buf) - (strlen($buf) % 4);
        if ($use === 0) continue;
        $raw = base64_decode(substr($buf, 0, $use), true);
        $buf = substr($buf, $use);
        if ($raw === false || $raw === '') { $ok = false; break; }
        if ($iv === null) {
            if (strlen($raw) < 32) { $ok = false; break; } // 至少 IV + 一个密文块
            $iv = substr($raw, 0, 16);
            $raw = substr($raw, 16);
        }
        // 密文必然块对齐（PKCS7 单次加密产物），统一用原始数据+零填充标志（末块 PKCS7 填充最后手动剥离）
        $plain = openssl_decrypt($raw, 'AES-256-CBC', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
        if ($plain === false) { $ok = false; break; }
        if (fwrite($out, $plain) === false) { $ok = false; break; }
        $iv = substr($raw, -16); // 下一块 IV = 本块最后 16 字节密文
    }
    fclose($in);
    fclose($out);
    if (!$ok) { @unlink($dstPath); return false; }

    // 手动剥离末块 PKCS7 填充
    $size = filesize($dstPath);
    if ($size < 1) { @unlink($dstPath); return false; }
    $f = @fopen($dstPath, 'r+b');
    if (!$f) return false;
    fseek($f, -1, SEEK_END);
    $pad = ord(fgetc($f));
    if ($pad >= 1 && $pad <= 16 && $pad <= $size) {
        ftruncate($f, $size - $pad);
    }
    fclose($f);
    return true;
}

/**
 * 判断备份文件是否已加密（.enc 后缀）
 * @param string $filename
 * @return bool
 */
function isEncryptedBackup($filename) {
    // 【2026-09-23 PHP7.4 兼容】str_ends_with 为 PHP 8.0+ 函数，改用 substr 实现
    return substr($filename, -4) === '.enc';
}

/**
 * 生成随机备份密钥（供管理员一次性使用）
 * @return string 32 字节密钥的十六进制表示（64字符）
 */
function generateBackupKey() {
    return bin2hex(openssl_random_pseudo_bytes(32));
}
?>
