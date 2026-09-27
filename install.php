<?php
/**
 * 金狐开单系统 - Web 安装向导
 * ============================================
 * 使用方法：将全部代码上传到服务器网站目录后，浏览器访问 install.php
 *
 * 流程：
 *   1. 环境自检（PHP版本 / 扩展 / 目录权限），不达标给出提示并阻止安装
 *   2. 用户填写数据库连接信息 + 管理员账号
 *   3. 自动：建库 → 写 config.local.php（含随机备份加密密钥）→ 建表 → 创建管理员 → 落 install.lock
 *
 * 安全机制：
 *   - 安装完成后生成 install.lock，再次访问本文件将拒绝执行
 *   - 若 config.local.php 已存在且数据库已初始化（schema_version 存在），同样拒绝重装
 *   - 重装方法：删除 install.lock 和 config.local.php 后再访问本文件
 */

// ============ 基础设置 ============
error_reporting(E_ALL);
ini_set('display_errors', '0'); // 安装器自身不裸输错误，统一收集后展示
ini_set('log_errors', '1');

$ROOT = __DIR__;
$LOCK_FILE   = $ROOT . '/install.lock';
$LOCAL_CONF  = $ROOT . '/config.local.php';

// ============ 已安装检测 ============
function isAlreadyInstalled($lockFile, $localConf) {
    // 1) 显式锁文件
    if (file_exists($lockFile)) return '检测到安装锁文件 install.lock';
    // 2) config.local.php 存在且数据库已初始化
    if (file_exists($localConf)) {
        try {
            require_once $localConf;
            if (defined('DB_HOST') && defined('DB_USER')) {
                $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3
                ]);
                $v = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='schema_version'")->fetchColumn();
                if ($v !== false && $v !== null) return '检测到数据库已完成初始化（config.local.php 已配置且有效）';
            }
        } catch (Exception $e) {
            // config.local.php 存在但连不上/表不存在 → 视为可重装
        }
    }
    return false;
}

// ============ 环境检测 ============
function checkEnvironment($ROOT) {
    $items = [];

    // PHP 版本（【2026-09-23 调整】业务代码与依赖库已兼容 PHP 7.4，放宽版本要求）
    $phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
    $items[] = [
        'name' => 'PHP 版本 >= 7.4',
        'ok' => $phpOk,
        'current' => PHP_VERSION,
        'hint' => $phpOk ? '' : '当前 PHP 版本过低，请安装 PHP 7.4 及以上（推荐 8.2）后重试。'
    ];

    // 必需扩展
    $exts = [
        'pdo_mysql' => '数据库连接（必需）',
        'mbstring'  => '中文字符串处理（必需）',
        'openssl'   => '备份文件 AES 加密（必需）',
        'json'      => '接口数据输出（必需）',
        'gd'        => '图片处理 / 验证码（必需）',
    ];
    foreach ($exts as $ext => $desc) {
        $ok = extension_loaded($ext);
        $items[] = [
            'name' => "PHP 扩展：{$ext}",
            'ok' => $ok,
            'current' => $ok ? '已加载' : '未安装',
            'hint' => $ok ? '' : "请在 php.ini 中启用 {$ext} 扩展（{$desc}），重启 Web 服务后重试。"
        ];
    }

    // fileinfo 可选：未安装仅提示，不阻止安装（上传类型校验已支持降级，见 functions.php / upload.php / order_create.php）
    $fiOk = extension_loaded('fileinfo');
    $items[] = [
        'name' => 'PHP 扩展：fileinfo（可选）',
        'ok' => true,
        'current' => $fiOk ? '已加载' : '未安装',
        'hint' => $fiOk ? '' : '未安装时上传校验自动降级为扩展名白名单+图像签名校验，安全性可控，但建议安装以获得更严格的 MIME 校验。'
    ];

    // Session 可用
    $sessionOk = true;
    try { if (session_status() === PHP_SESSION_NONE) @session_start(); $sessionOk = session_status() === PHP_SESSION_ACTIVE; } catch (Exception $e) { $sessionOk = false; }
    $items[] = [
        'name' => 'Session 会话支持',
        'ok' => $sessionOk,
        'current' => $sessionOk ? '正常' : '不可用',
        'hint' => $sessionOk ? '' : 'Session 不可用将无法登录，请检查 php.ini 的 session 配置及 session 保存目录写权限。'
    ];

    // 根目录可写（需写入 config.local.php / install.lock）
    $rootOk = is_writable($ROOT);
    $items[] = [
        'name' => '网站根目录可写',
        'ok' => $rootOk,
        'current' => $rootOk ? '可写' : '不可写',
        'hint' => $rootOk ? '' : '安装程序需要在网站根目录创建 config.local.php 和 install.lock，请授予 Web 进程用户（如 www-data / http）对该目录的写权限。'
    ];

    // uploads/ 目录
    $upDir = $ROOT . '/uploads';
    if (!is_dir($upDir)) @mkdir($upDir, 0755, true);
    $upOk = is_dir($upDir) && is_writable($upDir);
    $items[] = [
        'name' => 'uploads/ 目录可写（上传附件）',
        'ok' => $upOk,
        'current' => $upOk ? '可写' : '不可写',
        'hint' => $upOk ? '' : '请创建 uploads 目录并授予 Web 进程用户写权限，否则订单图片无法上传。'
    ];

    // 备份目录（【2026-09-23 修改】与 config.php 的 BACKUP_DIR 一致，位于站点目录内；
    // 备份文件强制 AES 加密为 .enc，站内目录安全性有保障，且与 WEB 进程同属主天然可写）
    $bkDir = $ROOT . '/erp_backups';
    if (!is_dir($bkDir)) @mkdir($bkDir, 0755, true);
    $bkOk = is_dir($bkDir) && is_writable($bkDir);
    $items[] = [
        'name' => '备份目录可写（erp_backups，位于站点目录内）',
        'ok' => $bkOk,
        'current' => $bkOk ? '可写' : '不可写',
        'hint' => $bkOk ? '' : '站点内 erp_backups 目录不可写，请检查磁盘配额/只读挂载，否则每日自动加密备份将失败。'
    ];

    return $items;
}

// ============ 数据库连接测试（不含库名） ============
function testDbServer($host, $port, $user, $pass, &$err) {
    try {
        $pdo = new PDO("mysql:host={$host};port={$port}", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5
        ]);
        return $pdo;
    } catch (PDOException $e) {
        $err = $e->getMessage();
        return false;
    }
}

// 解析数据库版本，判断是否满足系统要求
// 【2026-09-23 调整】MySQL 门槛从 5.7 放宽到 5.5.3 —— 系统对数据库的唯一硬要求是
// utf8mb4 字符集（5.5.3 起原生支持），代码未使用任何 5.7+ 特性（JSON 列、CTE 等均未用）。
// MySQL 5.6.x（如宝塔常见 5.6.50）实测完全兼容。
function checkDbVersion($pdo, &$err) {
    $ver = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    if (stripos($ver, 'MariaDB') !== false) {
        $num = parseFloatVersion($ver);
        if (version_compare($num, '10.1', '>=')) return ['ok' => true, 'ver' => $ver];
        $err = "MariaDB 版本过低（{$ver}），系统要求 >= 10.1";
    } else {
        $num = parseFloatVersion($ver);
        if (version_compare($num, '5.5.3', '>=')) return ['ok' => true, 'ver' => $ver];
        $err = "MySQL 版本过低（{$ver}），系统要求 >= 5.5.3（需支持 utf8mb4）";
    }
    return ['ok' => false, 'ver' => $ver];
}
function parseFloatVersion($ver) {
    if (preg_match('/^(\d+\.\d+\.\d+)/', $ver, $m)) return $m[1];
    return '0.0.0';
}

// ============ 写入 config.local.php ============
function writeLocalConfig($path, $host, $port, $name, $user, $pass) {
    $key = bin2hex(random_bytes(32)); // 64位hex = 32字节 AES-256 密钥
    $tpl = <<<PHPEOT
<?php
/**
 * 数据库配置文件 - 由安装程序自动生成于 {DATE}
 *
 * ⚠️ 此文件包含敏感信息，请勿上传到 Git 仓库
 */

// 数据库配置
define('DB_HOST', '{HOST}');
define('DB_USER', '{USER}');
define('DB_PASS', '{PASS}');
define('DB_NAME', '{NAME}');

// 备份加密密钥（AES-256-CBC，32字节 = 256位）
// 注意：更换密钥后旧备份将无法解密，请妥善保管！
if (!defined('BACKUP_ENCRYPT_KEY')) {
    define('BACKUP_ENCRYPT_KEY', hex2bin('{KEY}'));
}
PHPEOT;
    $content = str_replace(
        ['{DATE}', '{HOST}', '{USER}', '{PASS}', '{NAME}', '{KEY}'],
        [date('Y-m-d H:i:s'), addslashes($host), addslashes($user), addslashes($pass), addslashes($name), $key],
        $tpl
    );
    return file_put_contents($path, $content . "\n", LOCK_EX) !== false;
}

// ============ 处理安装请求 ============
$installError = '';
$installSuccess = false;
$isAdminPreCreated = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'install') {
    // 【2026-09-23 加固】建表流程在弱机（ARM 盒子）上可能较慢，放宽脚本时限，防止 max_execution_time 中途 fatal
    @set_time_limit(300);
    @ini_set('memory_limit', '256M');
    // 【2026-09-23 排障】兜底捕获致命错误：500 白屏时直接在页面显示真实原因（原 catch(Exception) 接不住 PHP7 的 Error/解析错误）
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            while (ob_get_level() > 0) { @ob_end_clean(); }
            http_response_code(200);
            echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>安装错误详情</title></head><body style="font-family:sans-serif;background:#f0f2f5;padding:40px 16px;">'
               . '<div style="max-width:760px;margin:0 auto;background:#fff;border-left:4px solid #f53f3f;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08);padding:24px;">'
               . '<h2 style="color:#c9353f;margin:0 0 12px;">安装过程中发生 PHP 致命错误</h2>'
               . '<p style="margin:6px 0;"><b>错误信息：</b>' . htmlspecialchars($e['message']) . '</p>'
               . '<p style="margin:6px 0;color:#86909c;"><b>位置：</b>' . htmlspecialchars($e['file']) . ' 第 ' . (int)$e['line'] . ' 行</p>'
               . '<p style="margin:16px 0 0;color:#4e5969;">请把本页截图发给技术人员，或对照该位置排查代码。</p>'
               . '</div></body></html>';
        }
    });
    $locked = isAlreadyInstalled($LOCK_FILE, $LOCAL_CONF);
    if ($locked) {
        $installError = $locked . '，如需重新安装请先删除锁文件。';
    } else {
        // 收集参数
        $dbHost  = trim($_POST['db_host'] ?? '127.0.0.1') ?: '127.0.0.1';
        $dbPort  = trim($_POST['db_port'] ?? '3306') ?: '3306';
        $dbName  = trim($_POST['db_name'] ?? '');
        $dbUser  = trim($_POST['db_user'] ?? '');
        $dbPass  = (string)($_POST['db_pass'] ?? '');
        $adminUser = trim($_POST['admin_user'] ?? 'admin') ?: 'admin';
        $adminPass = (string)($_POST['admin_pass'] ?? '');
        $companyName = trim($_POST['company_name'] ?? '') ?: '示例广告公司';

        // 服务端再次校验环境（防止绕过前端）
        $envFail = [];
        foreach (checkEnvironment($ROOT) as $item) { if (!$item['ok']) $envFail[] = $item['name']; }
        if ($envFail) {
            $installError = '环境检测未通过：' . implode('、', $envFail);
        } elseif ($dbName === '' || !preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
            $installError = '数据库名称不能为空，且只能包含字母、数字、下划线';
        } elseif ($dbUser === '') {
            $installError = '请填写数据库用户名';
        } elseif (strlen($adminPass) < 8) {
            $installError = '管理员密码至少 8 位';
        } else {
            // 1) 连接测试
            $pdo = testDbServer($dbHost, $dbPort, $dbUser, $dbPass, $connErr);
            if (!$pdo) {
                $installError = '数据库连接失败：' . $connErr . '（请检查主机、端口、用户名、密码）';
            } else {
                // 2) 数据库版本检查（utf8mb4 字符集要求）
                $verCheck = checkDbVersion($pdo, $verErr);
                if (!$verCheck['ok']) {
                    $installError = $verErr . '。请升级数据库后重试。';
                } else {
                    // 3) 建库
                    try {
                        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    } catch (PDOException $e) {
                        $installError = '创建数据库失败：' . $e->getMessage() . '（数据库账号可能没有建库权限，可手动建库后重试）';
                    }
                    if ($installError === '') {
                        // 4) 写 config.local.php
                        if (!writeLocalConfig($LOCAL_CONF, $dbHost, $dbPort, $dbName, $dbUser, $dbPass)) {
                            $installError = '写入 config.local.php 失败，请检查网站根目录写权限';
                        } else {
                            // 5) 复用系统建表逻辑（config.php 会自动加载新的 config.local.php）
                            $initOk = false;
                            try {
                                require_once $ROOT . '/config.php';
                                $initOk = initDatabase();
                            } catch (Throwable $e) {
                                // 【2026-09-23】Throwable：连 Error（类未找到/调用未定义方法等）也能显示真实原因，不再 500 白屏
                                $installError = '数据库初始化异常：' . $e->getMessage() . '（' . basename($e->getFile()) . ' 第 ' . $e->getLine() . ' 行）';
                            }
                            if ($installError === '' && !$initOk) {
                                $installError = '数据库初始化失败，请查看 PHP 错误日志（可能原因：数据库版本不兼容 / 账号权限不足）';
                            }
                            if ($installError === '' && $initOk) {
                                // 6) 设置管理员账号密码（覆盖 initDatabase 随机生成的初始密码）
                                try {
                                    $pdo2 = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbName}", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                                    $hashed = password_hash($adminPass, PASSWORD_DEFAULT);
                                    $stmt = $pdo2->prepare("SELECT id FROM users WHERE username = ?");
                                    $stmt->execute([$adminUser]);
                                    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                        $pdo2->prepare("UPDATE users SET password=?, role=2, status=1, must_change_password=0 WHERE id=?")
                                             ->execute([$hashed, $row['id']]);
                                    } else {
                                        $pdo2->prepare("INSERT INTO users (username, password, realname, role, status, must_change_password) VALUES (?,?,?,?,1,0)")
                                             ->execute([$adminUser, $hashed, '系统管理员', 2]);
                                    }
                                    // 公司信息写入系统设置
                                    $pdo2->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES ('company_name', ?)")
                                         ->execute([$companyName]);
                                    // 匿名使用统计：按安装时用户勾选写入（默认开启，可随时在系统设置中关闭）
                                    $telemetryVal = isset($_POST['telemetry_enabled']) ? '1' : '0';
                                    $pdo2->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES ('telemetry_enabled', ?)")
                                         ->execute([$telemetryVal]);
                                    $isAdminPreCreated = true;
                                } catch (Exception $e) {
                                    // 管理员设置失败不阻断安装（initDatabase 已有兜底随机密码）
                                    @error_log('[installer] admin setup: ' . $e->getMessage());
                                }
                                // 7) 落安装锁
                                @file_put_contents($LOCK_FILE, date('Y-m-d H:i:s') . " installed\n");
                                $installSuccess = true;
                            }
                        }
                    }
                }
            }
        }
    }
}

$installed = isAlreadyInstalled($LOCK_FILE, $LOCAL_CONF);
$envItems = $installed ? [] : checkEnvironment($ROOT);
$envAllOk = $installed ? false : !in_array(false, array_column($envItems, 'ok'), true);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>系统安装向导</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif; background:#f0f2f5; min-height:100vh; padding:40px 16px; color:#1f2329; }
.wrap { max-width:760px; margin:0 auto; }
.card { background:#fff; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,.08); padding:32px; margin-bottom:20px; }
h1 { font-size:22px; display:flex; align-items:center; gap:10px; margin-bottom:6px; }
h1 img { width:32px; height:32px; object-fit:contain; }
.sub { color:#86909c; font-size:13px; margin-bottom:24px; }
h2 { font-size:16px; margin:0 0 14px; padding-bottom:10px; border-bottom:1px solid #f0f0f0; }
table { width:100%; border-collapse:collapse; font-size:14px; }
td { padding:10px 8px; border-bottom:1px solid #f5f5f5; vertical-align:top; }
td:first-child { width:46%; font-weight:500; }
.status-ok { color:#00b42a; font-weight:600; white-space:nowrap; }
.status-fail { color:#f53f3f; font-weight:600; white-space:nowrap; }
.hint { color:#f53f3f; font-size:12px; margin-top:4px; line-height:1.5; }
.current { color:#86909c; font-size:12px; }
.banner-fail { background:#ffece8; border:1px solid #fdcdc5; border-radius:8px; padding:14px 16px; margin:16px 0 4px; color:#c9353f; font-size:13px; line-height:1.6; }
.banner-pass { background:#e8ffea; border:1px solid #aff0b5; border-radius:8px; padding:12px 16px; margin:16px 0 4px; color:#009a29; font-size:13px; }
.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px 16px; }
.form-item label { display:block; font-size:13px; color:#4e5969; margin-bottom:6px; font-weight:500; }
.form-item label .req { color:#f53f3f; }
.form-item input { width:100%; padding:9px 12px; border:1px solid #e5e6eb; border-radius:6px; font-size:14px; outline:none; transition:border .2s; }
.form-item input:focus { border-color:#4080ff; }
.form-item .tip { font-size:12px; color:#86909c; margin-top:4px; }
.full { grid-column:1 / -1; }
.btn { display:inline-block; background:#165dff; color:#fff; border:none; border-radius:8px; padding:12px 40px; font-size:15px; font-weight:600; cursor:pointer; transition:background .2s; }
.btn:hover { background:#4080ff; }
.btn:disabled { background:#94bfff; cursor:not-allowed; }
.btn-center { text-align:center; margin-top:24px; }
.success-icon { font-size:52px; text-align:center; margin-bottom:12px; }
.success-title { font-size:20px; font-weight:700; text-align:center; color:#00b42a; margin-bottom:8px; }
.success-desc { text-align:center; color:#4e5969; font-size:14px; margin-bottom:24px; }
.next-steps { background:#f7f8fa; border-radius:8px; padding:16px 20px; font-size:13px; line-height:2; color:#4e5969; }
.next-steps b { color:#1d2129; }
.action-bar { text-align:center; margin-top:20px; }
.link-btn { display:inline-block; background:#165dff; color:#fff; text-decoration:none; border-radius:8px; padding:11px 36px; font-size:15px; font-weight:600; }
.link-btn:hover { background:#4080ff; }
.info-box { background:#f7f8fa; border-radius:8px; padding:14px 18px; font-size:13px; color:#4e5969; line-height:1.8; }
.step-tag { display:inline-block; background:#e8f3ff; color:#165dff; font-size:12px; font-weight:600; padding:2px 10px; border-radius:10px; margin-bottom:12px; }
</style>
</head>
<body>
<div class="wrap">

<?php if ($installed): ?>
    <!-- ===== 已安装守卫页 ===== -->
    <div class="card">
        <div class="success-icon">🔒</div>
        <div class="success-title">系统已安装</div>
        <div class="success-desc"><?= htmlspecialchars($installed) ?></div>
        <div class="info-box">
            为安全起见，安装程序已锁定。请直接访问 <b>login.php</b> 进入系统。<br>
            如确需重新安装（<b>会清空当前配置</b>），请先在服务器上删除以下文件后再刷新本页：<br>
            ① <b>install.lock</b>　② <b>config.local.php</b>（建议先备份）
        </div>
        <div class="action-bar"><a class="link-btn" href="login.php">前往登录 →</a></div>
    </div>

<?php elseif ($installSuccess): ?>
    <!-- ===== 安装成功页 ===== -->
    <div class="card">
        <div class="success-icon">🎉</div>
        <div class="success-title">安装完成！</div>
        <div class="success-desc">数据库已初始化，系统配置已生成</div>
        <div class="next-steps">
            <b>管理员账号：</b><?= htmlspecialchars($adminUser) ?><br>
            <?php if ($isAdminPreCreated): ?>
                <b>管理员密码：</b>你在安装时设置的密码（首次登录无需修改）<br>
            <?php else: ?>
                <b>管理员密码：</b>系统生成了随机初始密码，记录在网站目录的 <b>install.log</b> 中，请登录后立即修改<br>
            <?php endif; ?>
            <b>备份加密密钥：</b>已自动生成并写入 config.local.php（旧备份依赖此密钥，请妥善保管该文件）<br>
            <b>匿名使用统计：</b><?php echo (isset($telemetryVal) && $telemetryVal === '0') ? '未开启（你在安装时取消了勾选）' : '已开启（每周上报一次匿名版本信息，不含任何业务数据；可在 系统设置 → 关于与更新 中随时关闭）'; ?><br>
            <b>演示数据：</b>系统已内置演示用的分类、单位、产品、客户、订单、收款与支出记录，方便快速体验；正式使用时可直接删除这些演示数据<br>
        </div>
        <div class="next-steps" style="margin-top:12px;">
            <b>收尾建议（重要）：</b><br>
            ① 安装锁已自动生成（install.lock），本安装程序已不可再次运行<br>
            ② 建议删除服务器上的 <b>install.php</b> 和 <b>install.log</b>（后者含初始密码记录）<br>
            ③ 确认 config.local.php 已妥善保管，勿提交到代码仓库<br>
            ④ 登录系统后在「系统设置」中完善公司名称、电话、地址
        </div>
        <div class="action-bar"><a class="link-btn" href="login.php">前往登录 →</a></div>
    </div>

<?php else: ?>
    <!-- ===== 安装向导主页面 ===== -->
    <div class="card">
        <h1><img src="logo.png" alt="logo">金狐开单系统 · 安装向导</h1>
        <div class="sub">第 1 步：环境自检　→　第 2 步：填写数据库信息　→　自动完成安装</div>

        <h2><span class="step-tag">步骤 1</span> 服务器环境检测</h2>
        <table>
            <tr style="color:#86909c;font-size:12px;"><td>检测项</td><td>检测结果</td><td>当前值</td></tr>
            <?php foreach ($envItems as $item): ?>
            <tr>
                <td><?= htmlspecialchars($item['name']) ?></td>
                <td class="<?= $item['ok'] ? 'status-ok' : 'status-fail' ?>"><?= $item['ok'] ? '✔ 通过' : '✘ 未通过' ?></td>
                <td class="current"><?= htmlspecialchars($item['current']) ?></td>
            </tr>
            <?php if (!$item['ok']): ?>
            <tr><td colspan="3" style="padding-top:0;"><div class="hint">💡 <?= htmlspecialchars($item['hint']) ?></div></td></tr>
            <?php endif; ?>
            <?php endforeach; ?>
        </table>

        <?php if (!$envAllOk): ?>
            <div class="banner-fail">
                ⚠️ <b>环境检测未全部通过，暂时无法安装。</b><br>
                请按上方 💡 提示逐项修复（安装/启用 PHP 扩展、授予目录写权限），完成后<b>刷新本页</b>重新检测。
            </div>
        <?php else: ?>
            <div class="banner-pass">✔ 环境检测全部通过，请填写下方信息开始安装</div>
        <?php endif; ?>
    </div>

    <?php if ($installError): ?>
    <div class="card" style="border-left:4px solid #f53f3f;">
        <h2 style="border:none;padding:0;margin:0;color:#c9353f;">❌ 安装失败</h2>
        <div style="font-size:13px;color:#4e5969;margin-top:8px;line-height:1.7;"><?= htmlspecialchars($installError) ?></div>
        <div style="font-size:12px;color:#86909c;margin-top:8px;">修复问题后可直接重新提交表单，已创建的数据库和配置文件会被安全覆盖。</div>
    </div>
    <?php endif; ?>

    <?php if ($envAllOk): ?>
    <div class="card">
        <h2><span class="step-tag">步骤 2</span> 数据库与管理员配置</h2>
        <form method="post" action="install.php" autocomplete="off">
            <input type="hidden" name="action" value="install">
            <div class="form-grid">
                <div class="form-item">
                    <label>数据库主机</label>
                    <input type="text" name="db_host" value="127.0.0.1" required>
                </div>
                <div class="form-item">
                    <label>数据库端口</label>
                    <input type="text" name="db_port" value="3306" required>
                </div>
                <div class="form-item">
                    <label>数据库名称 <span class="req">*</span></label>
                    <input type="text" name="db_name" placeholder="如：jinhu" required>
                    <div class="tip">只需填名称，不存在会自动创建（仅字母/数字/下划线）</div>
                </div>
                <div class="form-item">
                    <label>数据库用户名 <span class="req">*</span></label>
                    <input type="text" name="db_user" placeholder="如：root" required>
                </div>
                <div class="form-item full">
                    <label>数据库密码</label>
                    <input type="password" name="db_pass">
                </div>
                <div class="form-item">
                    <label>公司名称</label>
                    <input type="text" name="company_name" placeholder="显示在系统顶部和单据上">
                </div>
                <div class="form-item">
                    <label>管理员用户名</label>
                    <input type="text" name="admin_user" value="admin">
                </div>
                <div class="form-item">
                    <label>管理员密码 <span class="req">*</span></label>
                    <input type="password" name="admin_pass" minlength="8" required>
                    <div class="tip">至少 8 位，用于登录系统</div>
                </div>
            </div>
            <div style="background:#f7f8fa;border-radius:8px;padding:14px 18px;margin-top:4px;font-size:13px;color:#4e5969;line-height:1.7;">
                <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;">
                    <input type="checkbox" name="telemetry_enabled" value="1" checked style="margin-top:3px;">
                    <span>
                        <b style="color:#1d2129;">参与匿名使用统计</b>（可随时在 系统设置 → 关于与更新 中关闭）<br>
                        开启后系统每周向作者上报一次匿名信息：<b>随机实例 ID、系统版本、PHP/MySQL 版本</b>，用于统计有多少人在使用、指导版本更新。<br>
                        <span style="color:#86909c;">绝不收集订单、客户、金额等任何业务数据；统计代码在 telemetry.php，完全公开可审计。</span>
                    </span>
                </label>
            </div>
            <div class="btn-center">
                <button type="submit" class="btn">开始安装 🚀</button>
            </div>
        </form>
        <div class="info-box" style="margin-top:18px;">
            ℹ️ 安装程序将自动完成：创建数据库 → 生成 config.local.php（含随机备份加密密钥）→ 创建全部数据表和示例数据 → 创建管理员账号 → 生成安装锁。<br>
            整个过程通常在几秒内完成，不会影响服务器上的其他数据库。
        </div>
    </div>
    <?php endif; ?>

<?php endif; ?>

</div>
</body>
</html>
