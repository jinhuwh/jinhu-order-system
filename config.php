<?php
/**
 * 广告公司开单系统 - 核心配置文件
 */

// 引入安全响应头
require_once __DIR__ . '/security_headers.php';

// ===== 全局 CSRF 拦截器:自动输出 csrf-token meta + 加载 erp-fetch.js =====
// 0 改动各业务页,打开输出缓冲,在 head 里插 meta、body 末尾插 JS
if (!defined('ERP_CSRF_INJECTOR')) {
    define('ERP_CSRF_INJECTOR', true);
    ob_start(function ($html) {
        // 跳过空输出 / 跳转输出 / API JSON
        if (stripos($html, '<html') === false && stripos($html, '<head') === false) {
            return $html;
        }
        // 1) 如果页里有 <meta name="csrf-token"> 就不重复加
        if (stripos($html, 'name="csrf-token"') === false && stripos($html, "name='csrf-token'") === false) {
            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }
            $token = htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8');
            $meta = '<meta name="csrf-token" content="' . $token . '">';
            $pos = stripos($html, '<head>');
            if ($pos !== false) {
                $html = substr($html, 0, $pos + 6) . $meta . substr($html, $pos + 6);
            }
        }
        // 2) 加载 erp-fetch.js
        if (stripos($html, 'assets/erp-fetch.js') === false) {
            $script = '<script src="assets/erp-fetch.js"></script>';
            $pos = strripos($html, '</body>');
            if ($pos !== false) {
                $html = substr($html, 0, $pos) . $script . substr($html, $pos);
            }
        }
        return $html;
    });
}

// ===== 手机访问自动跳转移动端 =====
// 在 require config.php 之前定义 SKIP_MOBILE_REDIRECT 可禁用跳转
if (!defined('SKIP_MOBILE_REDIRECT')) {
    $isMobile = isset($_SERVER['HTTP_USER_AGENT']) &&
        (preg_match('/iPhone|iPod|Android|BlackBerry|IEMobile|WPDesktop|Mobile/i', $_SERVER['HTTP_USER_AGENT'])
         || isset($_GET['mobile']));
    $currentScript = basename($_SERVER['SCRIPT_NAME']);
    $pcPages = ['index.php','orders.php','order_create.php','order_view.php','customers.php',
                'products.php','settings.php','statement.php','change_password.php'];
    if ($isMobile && in_array($currentScript, $pcPages) && strpos($_SERVER['SCRIPT_NAME'], '/mobile/') === false) {
        header('Location: mobile/' . $currentScript . (isset($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
        exit;
    }
    // 登录页也跳转(login.php 不在 pcPages 因为移动端没有 login.php,跳转到移动端首页即可触发登录)
    if ($isMobile && $currentScript === 'login.php' && strpos($_SERVER['SCRIPT_NAME'], '/mobile/') === false && !isset($_GET['pc'])) {
        // 不再自动跳转,让手机用户在 login.php 正常登录
        // 登录成功后 login.php 会根据 $_SESSION['from_mobile'] 跳转到 mobile/
        // header('Location: mobile/index.php');
        // exit;
    }
}

// ===== 安全配置 =====

// 暴力破解防护配置
define('MAX_LOGIN_ATTEMPTS', 5);           // 最大尝试次数
define('LOGIN_LOCKOUT_MINUTES', 15);       // 锁定时间(分钟)

// 数据库凭据优先从外部配置文件加载
$localConfigPath = __DIR__ . '/config.local.php';
if (file_exists($localConfigPath)) {
    require_once $localConfigPath;
} else {
    // 开发环境示例配置(生产环境必须使用 config.local.php)
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'ad_order_system');

    // 安全提示:检测到使用默认配置
    if (php_sapi_name() !== 'cli') {
        error_log('SECURITY WARNING: Using default database config. Please create config.local.php!');
    }
}
define('SITE_TITLE', '长沙金狐文化开单系统');

// 登录过期时间(秒),默认2小时
define('SESSION_TIMEOUT', 2 * 3600);

// 登录页面路径(移动端可通过提前 define 覆盖)
if (!defined('LOGIN_URL')) {
    define('LOGIN_URL', 'login.php');
}

// 开启 Session(安全配置)
ini_set('session.cookie_httponly', 1);  // 防止JavaScript访问Cookie
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));  // 仅HTTPS传输
ini_set('session.cookie_samesite', 'Lax');  // 防止CSRF（Strict 在内网HTTP/表单提交时容易丢 session）
ini_set('session.use_strict_mode', 1);  // 严格模式
ini_set('session.gc_maxlifetime', SESSION_TIMEOUT);  // 垃圾回收时间

// ===== 生产环境错误输出 hardening =====
// 关闭错误直接输出，避免未捕获异常泄露绝对路径/堆栈信息。
// 原实现仅在 hideDbError() 内设置，而该函数从未被自动调用，此前一直依赖 php.ini 默认值。
if (!defined('DEBUG_MODE') || !DEBUG_MODE) {
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

session_start();

// 防止会话固定攻击
// 每日清理一次过期的登录尝试记录（通过 session 标记控制频率）
if (!isset($_SESSION['login_attempts_cleaned']) || $_SESSION['login_attempts_cleaned'] !== date('Y-m-d')) {
    // try/catch：全新安装时 login_attempts 表可能尚未创建（initDatabase 未运行），不能让清理逻辑阻断启动
    try {
        cleanupLoginAttempts();
    } catch (Exception $e) {
        // 表不存在时忽略，初始化完成后下次访问即正常
    }
    $_SESSION['login_attempts_cleaned'] = date('Y-m-d');
}
if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
    $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
}

// 引入安全函数库
require_once __DIR__ . '/functions.php';

/**
 * 压缩上传的图片文件到目标大小（默认60KB），节约硬盘空间。
 * 仅处理 jpg/jpeg/png/webp（其他类型如 PDF 原样保留）。
 * 逐级降质量+缩边长直到达标；失败时保留现有文件，绝不影响上传流程。
 * @return bool true=已处理落盘, false=无需处理或压缩失败(原图保留)
 */
function compressUploadedImage($path, $targetKB = 60) {
    if (empty($path) || !file_exists($path) || filesize($path) <= $targetKB * 1024) return false;
    if (!function_exists('imagecreatetruecolor')) return false;
    $info = @getimagesize($path);
    if (!$info) return false;
    list($w, $h) = $info;
    $type = $info[2];
    $createMap = [
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_PNG  => 'imagecreatefrompng',
        IMAGETYPE_WEBP => 'imagecreatefromwebp',
    ];
    if (!isset($createMap[$type]) || !function_exists($createMap[$type])) return false;
    $src = @$createMap[$type]($path);
    if (!$src) return false;

    $scales   = [1920, 1600, 1280, 1024, 800, 640, 480];
    $jpegQualities = [82, 70, 58, 46, 36, 28, 22];
    try {
        foreach ($scales as $maxSide) {
            if (max($w, $h) > $maxSide) {
                $scale = $maxSide / max($w, $h);
                $nw = max(1, (int)round($w * $scale));
                $nh = max(1, (int)round($h * $scale));
                $tmp = imagecreatetruecolor($nw, $nh);
                if ($type == IMAGETYPE_PNG) {
                    imagealphablending($tmp, false);
                    imagesavealpha($tmp, true);
                } else {
                    $bg = imagecolorallocate($tmp, 255, 255, 255);
                    imagefill($tmp, 0, 0, $bg);
                }
                imagecopyresampled($tmp, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($src);
                $src = $tmp;
                $w = $nw; $h = $nh;
            }
            foreach ($jpegQualities as $q) {
                if ($type == IMAGETYPE_JPEG) {
                    imagejpeg($src, $path, $q);
                } elseif ($type == IMAGETYPE_WEBP && function_exists('imagewebp')) {
                    imagewebp($src, $path, $q);
                } else { // PNG: 无质量参数，用最高压缩级别
                    imagepng($src, $path, 9);
                }
                clearstatcache();
                if (filesize($path) <= $targetKB * 1024) {
                    break 2;
                }
            }
        }
    } catch (\Throwable $e) {
        // 压缩失败保留现有文件
    }
    if (is_resource($src) || (is_object($src) && method_exists($src, 'destroy'))) {
        @imagedestroy($src);
    }
    clearstatcache();
    return file_exists($path) && filesize($path) > 0;
}

// 连接数据库
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec("SET NAMES utf8mb4");
        } catch (PDOException $e) {
            // 生产环境隐藏详细错误信息
            error_log("Database connection failed: " . $e->getMessage());
            die("数据库连接失败,请联系管理员");
        }
    }
    return $pdo;
}

// 自动创建数据库和表
// 【2026-09-13 优化】settings 表中保存 schema_version 标记；已初始化的库直接走快速路径，
// 跳过全部 CREATE TABLE / SHOW COLUMNS / INSERT IGNORE（原先每个请求要跑几十条额外查询）
define('SCHEMA_VERSION', '1');
// 【2026-09-13 补丁 v2】v3 纯净包漏建 4 张辅助表（product_categories / product_units /
// operation_logs / order_attachments），导致全新安装后 分类、单位、日志、附件 页面白屏。
// AUX_TABLES_VERSION 独立于 SCHEMA_VERSION：已安装的老库上传本文件后也会自动补建一次。
define('AUX_TABLES_VERSION', '2');

function ensureAuxTables($pdo) {
    // 已处理过则跳过（每次仅多一条主键查询，开销可忽略）
    try {
        $v = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'aux_tables_version'")->fetchColumn();
        if ($v !== false && $v !== null && $v >= AUX_TABLES_VERSION) {
            return;
        }
    } catch (Exception $e) {
        // settings 表不存在（全新安装走到完整初始化分支）→ 继续建表
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS product_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL COMMENT '分类名称',
        sort_order INT DEFAULT 0 COMMENT '排序',
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS product_units (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(20) NOT NULL COMMENT '单位名称',
        short_name VARCHAR(10) DEFAULT NULL COMMENT '简称',
        sort_order INT DEFAULT 0 COMMENT '排序',
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS operation_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT DEFAULT NULL COMMENT '操作人ID',
        username VARCHAR(50) DEFAULT NULL COMMENT '操作人用户名',
        action VARCHAR(50) NOT NULL COMMENT '操作类型',
        target_type VARCHAR(50) DEFAULT NULL COMMENT '目标类型',
        target_id INT DEFAULT NULL COMMENT '目标ID',
        target_desc VARCHAR(255) DEFAULT NULL COMMENT '目标描述',
        details LONGTEXT COMMENT '操作详情(JSON)',
        ip_address VARCHAR(45) DEFAULT NULL COMMENT 'IP地址',
        user_agent VARCHAR(500) DEFAULT NULL COMMENT '浏览器UA',
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user (user_id),
        KEY idx_action (action),
        KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS order_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL COMMENT '订单ID',
        file_name VARCHAR(255) NOT NULL COMMENT '文件名',
        file_path VARCHAR(500) NOT NULL COMMENT '文件路径',
        file_size INT NOT NULL COMMENT '文件大小',
        file_type VARCHAR(100) DEFAULT NULL COMMENT '文件类型',
        uploaded_by INT DEFAULT NULL COMMENT '上传人ID',
        uploaded_at DATETIME NOT NULL COMMENT '上传时间',
        KEY idx_order (order_id),
        CONSTRAINT fk_order_attachments_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 【v2 迁移】补 payments.discount 列（收款时录入优惠，finance.php 登记收款依赖；
    // v1 安装的库缺此列会报 1054 Unknown column 'discount'）
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM payments LIKE 'discount'");
        if ($chk->rowCount() === 0) {
            $pdo->exec("ALTER TABLE payments ADD COLUMN discount DECIMAL(10,2) DEFAULT 0 COMMENT '收款时优惠金额'");
        }
    } catch (Exception $e) {
        // 忽略迁移错误
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('aux_tables_version', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([AUX_TABLES_VERSION]);
    } catch (Exception $e) {
        // 忽略标记写入失败
    }
}

/**
 * 【2026-09-15】纯净版演示数据种子
 * 全新安装时写入：产品单位/产品分类/演示客户/演示订单(含明细)/收款记录/支出记录
 * - 仅在对应表为空时插入，老库升级（schema_version 已存在走快速路径）完全不受影响
 * - 每个段独立 try/catch，演示数据失败不影响系统初始化
 */
function seedDemoData($pdo) {
    // 1) 产品单位（与示例产品所用单位对齐）
    try {
        if ($pdo->query("SELECT COUNT(*) FROM product_units")->fetchColumn() == 0) {
            $units = [
                ['平方米', '㎡', 1],
                ['个', '个', 2],
                ['米', 'M', 3],
                ['厘米', 'cm', 4],
                ['张', '张', 5],
                ['盒', '盒', 6],
            ];
            $stmt = $pdo->prepare("INSERT INTO product_units (name, short_name, sort_order) VALUES (?, ?, ?)");
            foreach ($units as $u) { $stmt->execute($u); }
        }
    } catch (Exception $e) { @error_log('[demo] units: ' . $e->getMessage()); }

    // 2) 产品分类（与示例产品所用分类对齐）
    try {
        if ($pdo->query("SELECT COUNT(*) FROM product_categories")->fetchColumn() == 0) {
            $cats = ['UV打印', '喷绘', '板材', '展架', '横幅', '字牌', '印刷'];
            $stmt = $pdo->prepare("INSERT INTO product_categories (name, sort_order) VALUES (?, ?)");
            foreach ($cats as $i => $c) { $stmt->execute([$c, $i + 1]); }
        }
    } catch (Exception $e) { @error_log('[demo] categories: ' . $e->getMessage()); }

    // 3) 演示客户 + 订单 + 明细 + 收款 + 支出（覆盖：已付清/部分收款/未付款欠款 三种状态）
    try {
        $hasCustomers = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn() == 0;
        $hasOrders    = $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn() == 0;
        if (!($hasCustomers && $hasOrders)) {
            return; // 已有客户或订单，视为真实使用中，不插入演示数据
        }

        $customers = [
            ['长沙市红星汽贸4S店', '王主管', '13807310001', '开福区万家丽北路66号'],
            ['星辰奶茶连锁（银盆岭店）', '张店长', '13707310002', '岳麓区银盆岭路12号'],
            ['雨花区实验小学', '李老师', '18607310003', '雨花区韶山中路20号'],
            ['天心区餐饮管理公司', '刘经理', '13807310004', '天心区芙蓉中路二段88号'],
        ];
        $stmt = $pdo->prepare("INSERT INTO customers (name, contact, phone, address) VALUES (?, ?, ?, ?)");
        foreach ($customers as $c) { $stmt->execute($c); }

        // 订单定义：[客户下标, 天数偏移, 订单状态, 优惠, 已收, 收款状态, [明细...]]
        // 明细：[产品名, 规格, 数量, 单位, 单价, 金额, 长, 宽, 平方数]
        $orders = [
            [0, 8, 3, 0, 1150, 2, [   // 已完成 + 已付清
                ['5mm亚克力UV打印', '车头招牌画面', 1, '平方米', 80, 1000, 5, 2.5, 12.5],
                ['发光字制作', '门头发光字 不锈钢包边', 50, '厘米', 3, 150, null, null, null],
            ]],
            [1, 5, 2, 40, 900, 1, [   // 生产中 + 部分收款（合计1440，优惠40，应收1400，已收900，欠500）
                ['写真喷绘', '室内写真 背胶裱KT', 1, '平方米', 40, 1200, 6, 5, 30],
                ['KT板制作', '活动展板', 8, '平方米', 30, 240, null, null, null],
            ]],
            [2, 2, 1, 0, 0, 0, [      // 已确认 + 未付款（欠520）
                ['易拉宝', '80x200cm 含画面', 3, '个', 120, 360, null, null, null],
                ['横幅制作', '会场条幅', 20, '米', 8, 160, null, null, null],
            ]],
            [3, 0, 0, 0, 0, 0, [      // 今日新单 + 未付款（欠550）
                ['名片印刷', '双面铜款纸 2盒起做', 10, '盒', 25, 250, null, null, null],
                ['门型展架', '80x180cm', 2, '个', 150, 300, null, null, null],
            ]],
        ];

        $insOrder = $pdo->prepare("INSERT INTO orders (order_no, customer_id, order_date, total_amount, paid_amount, discount_amount, payment_status, status, remark, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insItem  = $pdo->prepare("INSERT INTO order_items (order_id, product_name, specification, quantity, unit, unit_price, amount, length, width, square_meter) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insPay   = $pdo->prepare("INSERT INTO payments (order_id, amount, payment_method, remark, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)");

        $demoSeq = 0;
        foreach ($orders as $o) {
            $demoSeq++;
            list($custIdx, $daysAgo, $orderStatus, $discount, $paid, $payStatus, $items) = $o;
            $total = 0;
            foreach ($items as $it) { $total += $it[5]; }
            $orderNo = date('Ymd', strtotime("-{$daysAgo} days")) . 'D' . str_pad($demoSeq, 3, '0', STR_PAD_LEFT);
            $orderDate = date('Y-m-d', strtotime("-{$daysAgo} days"));
            $insOrder->execute([
                $orderNo, $custIdx + 1, $orderDate, $total, $paid, $discount,
                $payStatus, $orderStatus, '演示数据，体验后可删除', 1
            ]);
            $orderId = $pdo->lastInsertId();
            foreach ($items as $it) {
                $insItem->execute([$orderId, $it[0], $it[1], $it[2], $it[3], $it[4], $it[5], $it[6], $it[7], $it[8]]);
            }
            if ($paid > 0) {
                $method = $demoSeq === 1 ? '微信' : '现金';
                $payRemark = $payStatus == 2 ? '演示数据：全款结清' : '演示数据：部分收款';
                $insPay->execute([$orderId, $paid, $method, $payRemark, 1, date('Y-m-d H:i:s', strtotime("-" . max($daysAgo - 1, 0) . " days"))]);
            }
        }

        // 支出记录
        $expenses = [
            ['采购', 1200, 8, '写真材料采购（演示数据）'],
            ['房租', 3000, 15, '店面月租金（演示数据）'],
            ['运输', 150, 5, '喷绘布送货运费（演示数据）'],
            ['水电', 260, 3, '门店水电费（演示数据）'],
        ];
        $insExp = $pdo->prepare("INSERT INTO expenses (category, amount, expense_date, description, created_by) VALUES (?, ?, ?, ?, ?)");
        foreach ($expenses as $e) {
            $insExp->execute([$e[0], $e[1], date('Y-m-d', strtotime("-{$e[2]} days")), $e[3], 1]);
        }
    } catch (Exception $e) { @error_log('[demo] orders/customers: ' . $e->getMessage()); }
}

function initDatabase() {
    static $done = null;
    if ($done !== null) return $done;
    try {
        // 【2026-09-24 修复】必须启用 ERRMODE_EXCEPTION：默认静默模式下 settings 表不存在时
        // query() 返回 false 而非抛异常，导致下方 ->fetchColumn() 报
        // "Call to a member function fetchColumn() on bool"（全新安装必现的 500）
        $pdo = new PDO("mysql:host=".DB_HOST, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS ".DB_NAME." CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE ".DB_NAME);

        // ===== 快速路径：schema 已初始化则跳过 DDL，仅处理自动备份触发 =====
        try {
            $v = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'schema_version'")->fetchColumn();
            if ($v !== false && $v !== null) {
                ensureAuxTables($pdo);
                if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
                    autoDailyBackup($pdo);
                }
                $done = true;
                return true;
            }
        } catch (Exception $e) {
            // settings 表尚不存在（全新安装）→ 落到下方完整初始化
        }

        // 用户表
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL COMMENT '用户名',
            password VARCHAR(255) NOT NULL COMMENT '密码',
            realname VARCHAR(50) COMMENT '真实姓名',
            role TINYINT DEFAULT 1 COMMENT '1普通用户 2管理员',
            status TINYINT DEFAULT 1 COMMENT '1启用 0禁用',
            must_change_password TINYINT DEFAULT 0 COMMENT '1需要强制修改密码',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // 系统设置表
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) UNIQUE NOT NULL COMMENT '设置项键名',
            setting_value TEXT COMMENT '设置项值',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

        // 客户表
        $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL COMMENT '客户名称',
            contact VARCHAR(50) COMMENT '联系人',
            phone VARCHAR(20) COMMENT '电话',
            address TEXT COMMENT '地址',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // 产品/服务表
        $pdo->exec("CREATE TABLE IF NOT EXISTS products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200) NOT NULL COMMENT '产品名称',
            category VARCHAR(50) COMMENT '分类',
            unit VARCHAR(20) COMMENT '单位',
            price DECIMAL(10,2) COMMENT '参考单价',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // 订单表
        $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_no VARCHAR(20) UNIQUE NOT NULL COMMENT '订单编号',
            customer_id INT NOT NULL COMMENT '客户ID',
            order_date DATE NOT NULL COMMENT '订单日期',
            total_amount DECIMAL(10,2) DEFAULT 0 COMMENT '总金额',
            paid_amount DECIMAL(10,2) DEFAULT 0 COMMENT '已收款金额',
            discount_amount DECIMAL(10,2) DEFAULT 0 COMMENT '优惠金额',
            payment_status TINYINT DEFAULT 0 COMMENT '0未付款 1部分收款 2已付清',
            status TINYINT DEFAULT 0 COMMENT '0待确认 1已确认 2生产中 3已完成 4已取消',
            invoice_status TINYINT DEFAULT 0 COMMENT '0未开票 1已开票',
            remark TEXT COMMENT '备注',
            created_by INT COMMENT '开单人ID',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

        // 订单明细表
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL COMMENT '订单ID',
            product_name VARCHAR(200) NOT NULL COMMENT '产品名称',
            specification VARCHAR(500) COMMENT '规格说明',
            quantity DECIMAL(10,2) NOT NULL COMMENT '数量',
            unit VARCHAR(20) COMMENT '单位',
            unit_price DECIMAL(10,2) NOT NULL COMMENT '单价',
            amount DECIMAL(10,2) NOT NULL COMMENT '金额',
            remark TEXT COMMENT '备注',
            length DECIMAL(10,2) DEFAULT NULL COMMENT '长度(米)',
            width DECIMAL(10,2) DEFAULT NULL COMMENT '宽度(米)',
            square_meter DECIMAL(10,4) DEFAULT NULL COMMENT '平方数',
            image_path VARCHAR(500) DEFAULT NULL COMMENT '产品图片路径'
        )");

        // 收款记录表
        $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL COMMENT '订单ID',
            amount DECIMAL(10,2) NOT NULL COMMENT '收款金额',
            discount DECIMAL(10,2) DEFAULT 0 COMMENT '收款时优惠金额',
            payment_method VARCHAR(50) COMMENT '收款方式',
            remark TEXT COMMENT '备注',
            created_by INT COMMENT '操作人ID',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // 支出记录表
        $pdo->exec("CREATE TABLE IF NOT EXISTS expenses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category VARCHAR(50) NOT NULL COMMENT '支出类别',
            amount DECIMAL(10,2) NOT NULL COMMENT '支出金额',
            expense_date DATE NOT NULL COMMENT '支出日期',
            description TEXT COMMENT '支出说明',
            order_id INT DEFAULT NULL COMMENT '关联订单ID（可选）',
            created_by INT COMMENT '登记人ID',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        // 为已存在的 expenses 表补上 order_id 列（迁移）
        try {
            $checkCol = $pdo->query("SHOW COLUMNS FROM expenses LIKE 'order_id'");
            if ($checkCol->rowCount() === 0) {
                $pdo->exec("ALTER TABLE expenses ADD COLUMN order_id INT DEFAULT NULL COMMENT '关联订单ID（可选）' AFTER description");
                $pdo->exec("ALTER TABLE expenses ADD INDEX idx_order_id (order_id)");
            }
        } catch (Exception $e) {
            // 忽略迁移错误
        }

        // 为已存在的 orders 表补上 invoice_status 列（迁移）
        try {
            $checkCol = $pdo->query("SHOW COLUMNS FROM orders LIKE 'invoice_status'");
            if ($checkCol->rowCount() === 0) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN invoice_status TINYINT DEFAULT 0 COMMENT '0未开票 1已开票' AFTER status");
                $pdo->exec("ALTER TABLE orders ADD INDEX idx_invoice_status (invoice_status)");
            }
        } catch (Exception $e) {
            // 忽略迁移错误
        }

        // 为 orders 表补上 discount_amount 列（迁移：优惠金额，财务/欠款功能依赖）
        try {
            $checkCol = $pdo->query("SHOW COLUMNS FROM orders LIKE 'discount_amount'");
            if ($checkCol->rowCount() === 0) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN discount_amount DECIMAL(10,2) DEFAULT 0 COMMENT '优惠金额' AFTER paid_amount");
            }
        } catch (Exception $e) {
            // 忽略迁移错误
        }

        // 为 users 表补上 must_change_password 列（迁移：强制改密标记，登录/改密功能依赖）
        try {
            $checkCol = $pdo->query("SHOW COLUMNS FROM users LIKE 'must_change_password'");
            if ($checkCol->rowCount() === 0) {
                $pdo->exec("ALTER TABLE users ADD COLUMN must_change_password TINYINT DEFAULT 0 COMMENT '1需要强制修改密码' AFTER status");
            }
        } catch (Exception $e) {
            // 忽略迁移错误
        }

        // 为 users 表补上 permissions 列（权限管理迁移）
        try {
            $checkCol = $pdo->query("SHOW COLUMNS FROM users LIKE 'permissions'");
            if ($checkCol->rowCount() === 0) {
                $pdo->exec("ALTER TABLE users ADD COLUMN permissions VARCHAR(500) DEFAULT '' COMMENT '权限，多个用逗号分隔' AFTER role");
            }
        } catch (Exception $e) {
            // 忽略迁移错误
        }

        // 为 payments 表补上 attachment 列（收款凭证图片）
        try {
            $checkCol = $pdo->query("SHOW COLUMNS FROM payments LIKE 'attachment'");
            if ($checkCol->rowCount() === 0) {
                $pdo->exec("ALTER TABLE payments ADD COLUMN attachment VARCHAR(500) DEFAULT NULL COMMENT '收款凭证图片路径' AFTER remark");
            }
        } catch (Exception $e) {
            // 忽略迁移错误
        }

        // 为 expenses 表补上 attachment 列（迁移）
        try {
            $checkCol = $pdo->query("SHOW COLUMNS FROM expenses LIKE 'attachment'");
            if ($checkCol->rowCount() === 0) {
                $pdo->exec("ALTER TABLE expenses ADD COLUMN attachment VARCHAR(500) DEFAULT NULL COMMENT '支付截图路径' AFTER order_id");
            }
        } catch (Exception $e) {
            // 忽略迁移错误
        }

        // 为 order_items 表补上尺寸和图片字段（迁移）
        try {
            $checkCol = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'length'");
            if ($checkCol->rowCount() === 0) {
                $pdo->exec("ALTER TABLE order_items ADD COLUMN length DECIMAL(10,2) DEFAULT NULL COMMENT '长度(米)' AFTER remark");
            }
            $checkCol = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'width'");
            if ($checkCol->rowCount() === 0) {
                $pdo->exec("ALTER TABLE order_items ADD COLUMN width DECIMAL(10,2) DEFAULT NULL COMMENT '宽度(米)' AFTER length");
            }
            $checkCol = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'square_meter'");
            if ($checkCol->rowCount() === 0) {
                $pdo->exec("ALTER TABLE order_items ADD COLUMN square_meter DECIMAL(10,4) DEFAULT NULL COMMENT '平方数' AFTER width");
            }
            $checkCol = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'image_path'");
            if ($checkCol->rowCount() === 0) {
                $pdo->exec("ALTER TABLE order_items ADD COLUMN image_path VARCHAR(500) DEFAULT NULL COMMENT '产品图片路径' AFTER square_meter");
            }
        } catch (Exception $e) {
            // 忽略迁移错误
        }

        // 登录尝试记录表(暴力破解防护)
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL COMMENT 'IP地址',
            username VARCHAR(50) NOT NULL COMMENT '尝试登录的用户名',
            attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '尝试时间',
            INDEX idx_ip_time (ip_address, attempted_at)
        )");

        // 【2026-09-13 补丁 v2】补建辅助表（分类/单位/日志/附件）
        ensureAuxTables($pdo);

        // 插入默认管理员(随机生成密码)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users");
        $stmt->execute();
        if ($stmt->fetchColumn() == 0) {
            $randomPassword = generateRandomPassword(12);
            $hashed = password_hash($randomPassword, PASSWORD_DEFAULT);
            $pdo->exec("INSERT INTO users (username, password, realname, role, status, must_change_password) VALUES ('admin', '$hashed', '系统管理员', 2, 1, 1)");

            // 将初始密码写入安装日志文件
            $logFile = __DIR__ . '/install.log';
            $logContent = date('Y-m-d H:i:s') . " - Initial admin password: $randomPassword\n";
            file_put_contents($logFile, $logContent, FILE_APPEND | LOCK_EX);

            // 同时输出到控制台(CLI模式)
            if (php_sapi_name() === 'cli') {
                echo "\n========================================\n";
                echo "初始管理员密码: $randomPassword\n";
                echo "请立即登录并修改密码!\n";
                echo "========================================\n\n";
            }
        }

        // 插入默认系统设置
        $defaults = [
            ['company_name', '示例广告公司'],
            ['company_phone', '0731-88888888'],
            ['company_address', '湖南省长沙市'],
        ];
        $stmt = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)");
        foreach ($defaults as $d) {
            $stmt->execute($d);
        }

        // 插入示例产品数据(仅在表为空时插入,防止重复)
        $stmt = $pdo->query("SELECT COUNT(*) FROM products");
        if ($stmt->fetchColumn() == 0) {
            $products = [
                ['5mm亚克力UV打印', 'UV打印', '平方米', 80],
                ['写真喷绘', '喷绘', '平方米', 40],
                ['KT板制作', '板材', '平方米', 30],
                ['易拉宝', '展架', '个', 120],
                ['门型展架', '展架', '个', 150],
                ['横幅制作', '横幅', '米', 8],
                ['发光字制作', '字牌', '厘米', 3],
                ['名片印刷', '印刷', '盒', 25],
            ];
            $stmt = $pdo->prepare("INSERT INTO products (name, category, unit, price) VALUES (?, ?, ?, ?)");
            foreach ($products as $p) {
                $stmt->execute($p);
            }
        }

        // 【2026-09-15】插入演示数据（分类/单位/客户/订单/收款/支出，仅在表为空时写入）
        seedDemoData($pdo);

        // 后台自动备份检查:仅在已登录用户访问时触发,避免公开页面拍快照
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
            autoDailyBackup($pdo);
        }

        // 【2026-09-13 优化】写入 schema 版本标记，后续请求走快速路径
        try {
            $pdo->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES ('schema_version', ?)")
                ->execute([SCHEMA_VERSION]);
        } catch (Exception $e) {
            // 标记失败不影响本次初始化结果
        }

        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// 检查用户是否登录(含过期检测)
function checkLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . LOGIN_URL);
        exit;
    }
    // 检查登录是否过期
    if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > SESSION_TIMEOUT)) {
        session_destroy();
        header('Location: ' . LOGIN_URL . '?msg=timeout');
        exit;
    }
    // 刷新最后活动时间（滑动窗口：每次活跃操作刷新过期时间）
    $_SESSION['login_time'] = time();

    // 若 session 即将过期（剩余 < 3 分钟），自动展期
    // 前端 countdown 会每分钟 ping 此 endpoint 以保持会话活跃
    if (isset($_GET['_refresh_session']) && $_GET['_refresh_session'] == 1) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'expires_in' => SESSION_TIMEOUT, 'now' => time()]);
        exit;
    }

    // 加载用户权限
    $_SESSION['user_permissions'] = getUserPermissions($_SESSION['user_id']);
}

// 检查是否管理员（管理员拥有所有权限）
function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] == 2;
}

// 权限常量定义
define('PERM_ORDER_VIEW',      'order_view');
define('PERM_ORDER_CREATE',    'order_create');
define('PERM_ORDER_EDIT',      'order_edit');
define('PERM_ORDER_DELETE',    'order_delete');
define('PERM_ORDER_EXPORT',   'order_export');
define('PERM_CUSTOMER_VIEW',   'customer_view');
define('PERM_CUSTOMER_CREATE', 'customer_create');
define('PERM_CUSTOMER_EDIT',  'customer_edit');
define('PERM_CUSTOMER_DELETE', 'customer_delete');
define('PERM_PRODUCT_VIEW',    'product_view');
define('PERM_PRODUCT_CREATE',  'product_create');
define('PERM_PRODUCT_EDIT',    'product_edit');
define('PERM_PRODUCT_DELETE',  'product_delete');
define('PERM_FINANCE_VIEW',   'finance_view');
define('PERM_FINANCE_EDIT',   'finance_edit');
define('PERM_REPORT_VIEW',    'report_view');
define('PERM_REPORT_EXPORT',  'report_export');
define('PERM_SETTINGS_EDIT',  'settings_edit');
define('PERM_BACKUP',         'backup');
define('PERM_LOG_VIEW',       'log_view');
define('PERM_USER_MANAGE',    'user_manage');

// 权限分组定义（用于权限设置界面）
$PERMISSION_GROUPS = [
    'order' => [
        'label' => '📋 订单管理',
        'items' => [
            PERM_ORDER_VIEW      => ['label' => '查看订单', 'desc' => '查看订单列表和详情'],
            PERM_ORDER_CREATE    => ['label' => '创建订单', 'desc' => '新建订单'],
            PERM_ORDER_EDIT      => ['label' => '编辑订单', 'desc' => '修改订单信息'],
            PERM_ORDER_DELETE    => ['label' => '删除订单', 'desc' => '删除订单（危险）'],
            PERM_ORDER_EXPORT    => ['label' => '导出订单', 'desc' => '导出Excel'],
        ],
    ],
    'customer' => [
        'label' => '👥 客户管理',
        'items' => [
            PERM_CUSTOMER_VIEW      => ['label' => '查看客户', 'desc' => '查看客户列表'],
            PERM_CUSTOMER_CREATE    => ['label' => '新建客户', 'desc' => '添加新客户'],
            PERM_CUSTOMER_EDIT      => ['label' => '编辑客户', 'desc' => '修改客户信息'],
            PERM_CUSTOMER_DELETE    => ['label' => '删除客户', 'desc' => '删除客户'],
        ],
    ],
    'product' => [
        'label' => '📦 产品管理',
        'items' => [
            PERM_PRODUCT_VIEW      => ['label' => '查看产品', 'desc' => '查看产品列表'],
            PERM_PRODUCT_CREATE    => ['label' => '新建产品', 'desc' => '添加新产品'],
            PERM_PRODUCT_EDIT      => ['label' => '编辑产品', 'desc' => '修改产品信息'],
            PERM_PRODUCT_DELETE    => ['label' => '删除产品', 'desc' => '删除产品（危险）'],
        ],
    ],
    'finance' => [
        'label' => '💰 财务管理',
        'items' => [
            PERM_FINANCE_VIEW  => ['label' => '查看财务', 'desc' => '查看收款、支出记录'],
            PERM_FINANCE_EDIT  => ['label' => '财务操作', 'desc' => '收款、支出登记'],
        ],
    ],
    'report' => [
        'label' => '📊 报表统计',
        'items' => [
            PERM_REPORT_VIEW   => ['label' => '查看报表', 'desc' => '查看统计报表'],
            PERM_REPORT_EXPORT => ['label' => '导出报表', 'desc' => '导出报表数据'],
        ],
    ],
    'system' => [
        'label' => '⚙️ 系统设置',
        'items' => [
            PERM_SETTINGS_EDIT => ['label' => '系统设置', 'desc' => '修改系统配置'],
            PERM_BACKUP        => ['label' => '数据备份', 'desc' => '备份和恢复数据'],
            PERM_LOG_VIEW      => ['label' => '操作日志', 'desc' => '查看操作日志'],
            PERM_USER_MANAGE   => ['label' => '用户管理', 'desc' => '添加/编辑/删除用户'],
        ],
    ],
];

// 检查用户是否有指定权限
// 管理员(superadmin role=2)拥有所有权限，普通用户(role=1)只拥有分配给自己的权限
function hasPermission($permission) {
    // 管理员拥有所有权限
    if (isAdmin()) return true;

    // 【2026-09-09 安全策略】删除类权限仅管理员可用，普通员工一律拦截（即使 DB/会话中有残留）
    if (in_array($permission, [PERM_ORDER_DELETE, PERM_CUSTOMER_DELETE, PERM_PRODUCT_DELETE], true)) {
        return false;
    }

    // 检查个人权限列表
    $perms = $_SESSION['user_permissions'] ?? [];
    if (is_string($perms)) {
        $perms = $perms ? explode(',', $perms) : [];
    }
    return in_array($permission, $perms);
}

// 要求权限，不满足则403
function requirePermission($permission) {
    if (!hasPermission($permission)) {
        http_response_code(403);
        die('无权限访问此功能，请联系管理员授权。');
    }
}

// 获取用户的所有权限（合并角色权限和个人权限）
function getUserPermissions($userId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT role, permissions FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) return [];

    // 管理员拥有所有权限
    if ($user['role'] == 2) {
        // 返回所有权限常量
        global $PERMISSION_GROUPS;
        $all = [];
        foreach ($PERMISSION_GROUPS as $group) {
            $all = array_merge($all, array_keys($group['items']));
        }
        return $all;
    }

    // 【2026-09-09 安全策略】普通员工不保留删除类权限（登录加载时即剥离，UI/审计一致）
    $permList = $user['permissions'] ? explode(',', $user['permissions']) : [];
    return array_values(array_filter($permList, function ($p) {
        return !in_array(trim($p), [PERM_ORDER_DELETE, PERM_CUSTOMER_DELETE, PERM_PRODUCT_DELETE], true);
    }));
}

// 获取系统设置
function getSetting($key) {
    $db = getDB();
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    return $stmt->fetchColumn() ?: '';
}

// 获取所有系统设置
function getAllSettings() {
    $db = getDB();
    $rows = $db->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    return $rows;
}

// 获取当前用户信息
function getCurrentUser() {
    if (!isset($_SESSION['user_id'])) return null;
    $db = getDB();
    $stmt = $db->prepare("SELECT id, username, realname, role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// 获取当前用户ID
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

// 生成订单号(12位数字:日期6位+序列号6位)
function generateOrderNo() {
    $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $random = substr(str_shuffle($chars), 0, 4);
    return date('Ymd') . $random;
}

// 格式化金额
function formatMoney($amount) {
    return '¥' . number_format($amount, 2);
}

// 平方数显示格式：最多4位小数，去掉末尾多余的0（30.0000→30，12.5000→12.5）
function formatSqm($v) {
    $s = number_format((float)$v, 4, '.', '');
    $s = rtrim(rtrim($s, '0'), '.');
    return ($s === '' || $s === '-0') ? '0' : $s;
}

// 金额转大写中文
function numToChinese($num) {
    $num = round($num, 2);
    if ($num == 0) return '零元整';
    $digits = ['零','壹','贰','叁','肆','伍','陆','柒','捌','玖'];
    $units = ['','拾','佰','仟'];
    $bigUnits = ['','万','亿'];
    $decUnits = ['角','分'];

    $result = '';
    $isNeg = $num < 0;
    $num = abs($num);
    $intPart = floor($num);
    $decPart = round(($num - $intPart) * 100);

    // 整数部分
    if ($intPart > 0) {
        $intStr = strval($intPart);
        $len = strlen($intStr);
        $zeroFlag = false;
        for ($i = 0; $i < $len; $i++) {
            $d = intval($intStr[$i]);
            $pos = $len - 1 - $i;
            $bigIdx = floor($pos / 4);
            $unitIdx = $pos % 4;
            if ($d == 0) {
                $zeroFlag = true;
                if ($unitIdx == 0 && $bigIdx > 0) {
                    $result .= $bigUnits[$bigIdx];
                    $zeroFlag = false;
                }
            } else {
                if ($zeroFlag) { $result .= '零'; $zeroFlag = false; }
                $result .= $digits[$d] . $units[$unitIdx];
                if ($unitIdx == 0 && $bigIdx > 0) {
                    $result .= $bigUnits[$bigIdx];
                }
            }
        }
        $result .= '元';
    }

    // 小数部分
    if ($decPart > 0) {
        $jiao = floor($decPart / 10);
        $fen = $decPart % 10;
        if ($jiao > 0) $result .= $digits[$jiao] . '角';
        else if ($intPart > 0) $result .= '零';
        if ($fen > 0) $result .= $digits[$fen] . '分';
    } else {
        $result .= '整';
    }

    return ($isNeg ? '负' : '') . $result;
}

// 获取状态文字
function getStatusText($status) {
    $map = [0 => '待确认', 1 => '已确认', 2 => '生产中', 3 => '已完成', 4 => '已取消'];
    return $map[$status] ?? '未知';
}

// 获取状态样式
function getStatusClass($status) {
    $map = [0 => 'warning', 1 => 'info', 2 => 'primary', 3 => 'success', 4 => 'danger'];
    return $map[$status] ?? 'secondary';
}

// 获取收款状态文字
function getPaymentStatusText($status) {
    $map = [0 => '未付款', 1 => '部分收款', 2 => '已付清'];
    return $map[$status] ?? '未知';
}

// 获取收款状态样式
function getPaymentStatusClass($status) {
    $map = [0 => 'danger', 1 => 'warning', 2 => 'success'];
    return $map[$status] ?? 'secondary';
}

// ===== CSRF 防护函数 =====

// 收款方式CSS类
function getPayMethodClass($method) {
    $method = strtolower(trim($method ?? ''));
    if (strpos($method, '微信') !== false) return 'wechat';
    if (strpos($method, '支付宝') !== false) return 'alipay';
    if (strpos($method, '现金') !== false) return 'cash';
    if (strpos($method, '银行') !== false) return 'bank';
    return '';
}

// 生成CSRF Token（仅在缺失时生成，保持页面渲染期稳定，避免多标签页/并发 fetch 失同步）
function generateCsrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_history'] = [];
    }
    return $_SESSION['csrf_token'];
}

// 校验成功后轮换 token，并保留最近若干历史 token 作为宽限期：
// 既限制 token 一旦泄露后的有效窗口，又避免单页多次提交/多标签页场景下 token 失同步。
function rotateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        return generateCsrfToken();
    }
    $history = $_SESSION['csrf_token_history'] ?? [];
    $history[] = $_SESSION['csrf_token'];
    if (count($history) > 5) {
        array_shift($history); // 仅保留最近 5 个，限制会话内有效 token 数量
    }
    $_SESSION['csrf_token_history'] = $history;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

// 验证CSRF Token（时序安全比较 + 成功后轮换）
function verifyCsrfToken($token) {
    if (!isset($_SESSION['csrf_token']) || !is_string($token) || $token === '') {
        return false;
    }
    $candidates = array_merge([$_SESSION['csrf_token']], $_SESSION['csrf_token_history'] ?? []);
    foreach ($candidates as $valid) {
        if (is_string($valid) && hash_equals($valid, $token)) {
            rotateCsrfToken();
            return true;
        }
    }
    return false;
}

// ===== 暴力破解防护函数 =====

// 获取客户端真实IP（防止IP伪造攻击）
function getClientIP() {
    // 只在已确认是反向代理时（如群晖NAS）才信任 X-Forwarded-For
    // 否则直接使用 REMOTE_ADDR（它由Web服务器设置，攻击者无法伪造）
    $trustedProxies = ['127.0.0.1', 'localhost', '::1']; // 可信任的代理IP列表
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    
    // 如果请求来自可信任的代理（如Nginx反向代理），则解析 X-Forwarded-For
    if (in_array($remoteAddr, $trustedProxies, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // 反向代理会【追加】客户端IP，故 XFF 中【最右侧】才是最靠近代理写入的真实客户端，
        // 最左侧是客户端可伪造的。从右往左取第一个“非受信代理”的合法IP，
        // 使攻击者无法通过伪造 XFF 来切换锁定计数所用的 IP。
        $parts = array_reverse(array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])));
        foreach ($parts as $candidate) {
            if ($candidate === '' || !filter_var($candidate, FILTER_VALIDATE_IP)) {
                continue;
            }
            if (in_array($candidate, $trustedProxies, true)) {
                continue; // 跳过受信代理自身
            }
            return $candidate; // 第一个“非受信代理”的合法IP即真实客户端
        }
        return $remoteAddr; // XFF 无可信客户端IP时回退到直连代理IP
    }
    
    // 直接使用 REMOTE_ADDR（最可靠，除非直接暴露给公网）
    $ip = $remoteAddr;
    
    // 如果 REMOTE_ADDR 也不可信（直接公网访问），再检查 Client-IP
    if (!in_array($remoteAddr, $trustedProxies, true) && !empty($_SERVER['HTTP_CLIENT_IP'])) {
        $clientIp = trim($_SERVER['HTTP_CLIENT_IP']);
        if (filter_var($clientIp, FILTER_VALIDATE_IP)) {
            $ip = $clientIp;
        }
    }
    
    return $ip;
}

// 检查是否已被锁定
function isLoginLocked() {
    $ip = getClientIP();
    $db = getDB();
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE ip_address = ?
         AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
    );
    $stmt->execute([$ip, LOGIN_LOCKOUT_MINUTES]);
    $attempts = $stmt->fetchColumn();

    if ($attempts >= MAX_LOGIN_ATTEMPTS) {
        $waitMinutes = LOGIN_LOCKOUT_MINUTES;
        return "登录失败次数过多,请{$waitMinutes}分钟后再试";
    }
    return false;
}

// 记录登录尝试
function recordLoginAttempt($username) {
    $ip = getClientIP();
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO login_attempts (ip_address, username) VALUES (?, ?)");
    $stmt->execute([$ip, $username]);

    // 返回剩余尝试次数
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE ip_address = ?
         AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
    );
    $stmt->execute([$ip, LOGIN_LOCKOUT_MINUTES]);
    $attempts = $stmt->fetchColumn();
    $remaining = MAX_LOGIN_ATTEMPTS - $attempts;

    return $remaining > 0 ? $remaining : 0;
}

// 清除登录尝试记录(登录成功后调用)
function clearLoginAttempts() {
    $ip = getClientIP();
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
    $stmt->execute([$ip]);
}

// 清理过期的登录尝试记录（建议定期调用，如每日一次）
function cleanupLoginAttempts() {
    $db = getDB();
    // 删除 LOGIN_LOCKOUT_MINUTES 之前的记录
    $stmt = $db->prepare("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)");
    $stmt->execute([LOGIN_LOCKOUT_MINUTES * 2]); // 保留2倍锁定时间的记录
}

// 隐藏数据库错误信息(生产环境用)
function hideDbError() {
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}

// 菜单key到权限key的映射
function menuKeyToPermission($key) {
    $map = [
        'index'          => null,       // 所有人可访问
        'order_create'   => PERM_ORDER_CREATE,
        'orders'         => PERM_ORDER_VIEW,
        'customers'      => PERM_CUSTOMER_VIEW,
        'products'       => PERM_PRODUCT_VIEW,
        'categories'     => PERM_PRODUCT_EDIT,
        'units'          => PERM_PRODUCT_EDIT,
        'finance'        => PERM_FINANCE_VIEW,
        'reports'        => PERM_REPORT_VIEW,
        'statement'      => PERM_FINANCE_VIEW,
        'debtors'        => PERM_FINANCE_VIEW,
        'logs'           => PERM_LOG_VIEW,
        'settings'       => PERM_SETTINGS_EDIT,
        'backup'         => PERM_BACKUP,
        'users'          => PERM_USER_MANAGE,
    ];
    return $map[$key] ?? null;
}

// 页面头部导航(顶栏 + 侧边栏布局)
function renderNav($active = '') {
    $user = getCurrentUser();
    $companyName = getSetting('company_name') ?: SITE_TITLE;

    // 侧边栏菜单
    $menuGroups = [
        '工作台' => [
            'icon' => '🏠',
            'items' => [
                'index' => ['📊', '首页概览', 'index.php'],
                'order_create' => ['➕', '新建订单', 'order_create.php'],
                'orders' => ['📋', '订单列表', 'orders.php'],
            ],
        ],
        '客户与产品' => [
            'icon' => '🛍️',
            'items' => [
                'customers' => ['👥', '客户管理', 'customers.php'],
                'products' => ['📦', '产品管理', 'products.php'],
                'categories' => ['📁', '产品分类', 'categories.php'],
                'units' => ['📏', '产品单位', 'units.php'],
            ],
        ],
        '财务与数据' => [
            'icon' => '💰',
            'items' => [
                'finance' => ['💵', '财务管理', 'finance.php'],
                'reports' => ['📊', '数据统计', 'reports.php'],
                'statement' => ['📑', '客户对账', 'statement.php'],
                'debtors' => ['💳', '欠款追踪', 'debtors.php'],
            ],
        ],
        '系统' => [
            'icon' => '⚙️',
            'items' => [
                'logs' => ['📋', '操作日志', 'logs.php'],
                'settings' => ['🔧', '系统设置', 'settings.php'],
                'backup' => ['💾', '数据备份', 'backup.php'],
                'users' => ['👤', '用户管理', 'users.php'],
            ],
        ],
    ];

    $realname = $user['realname'] ?? $user['username'];
    $avatarText = mb_substr($realname, 0, 1, 'UTF-8');

    // 顶栏
    $html = '<div class="topbar">';
    $html .= '<div class="topbar-brand">';
    $html .= '<div class="topbar-logo"><img src="logo.png" alt="' . htmlspecialchars($companyName) . '"></div>';
    $html .= '<div><div class="topbar-title">' . htmlspecialchars($companyName) . '</div>';
    $html .= '<div class="topbar-subtitle">广告制作订单管理系统</div></div>';
    $html .= '</div>';
    $html .= '<div class="topbar-user">';
    $html .= '<div class="topbar-avatar">' . htmlspecialchars($avatarText) . '</div>';
    $html .= '<div class="topbar-username">' . htmlspecialchars($realname) . '</div>';
    $html .= '<div class="topbar-actions">';
    $html .= '<a href="change_password.php" class="topbar-action-btn">🔒 修改密码</a>';
    $html .= '<a href="logout.php" class="topbar-action-btn danger">退出</a>';
    $html .= '</div></div></div>';

    // 布局容器开始
    $html .= '<div class="layout">';

    // 侧边栏（支持分组折叠）
    $html .= '<div class="sidebar">';
    foreach ($menuGroups as $label => $group) {
        $groupKey = 'nav_' . md5($label);
        $groupIcon = $group['icon'] ?? '';
        $items = $group['items'] ?? [];
        // 权限过滤：移除无权限的菜单项
        $filteredItems = [];
        foreach ($items as $key => $item) {
            $perm = menuKeyToPermission($key);
            if ($perm === null || hasPermission($perm)) {
                $filteredItems[$key] = $item;
            }
        }
        $items = $filteredItems;
        // 如果分组没有任何可见菜单项，跳过该分组
        if (empty($items)) continue;
        // 判断当前 active 项是否在该分组内
        $isActiveGroup = array_key_exists($active, $items);
        $groupCls = $isActiveGroup ? ' sidebar-group-active' : '';
        $html .= '<div class="sidebar-group' . $groupCls . '" data-group="' . $groupKey . '">';
        $html .= '<div class="sidebar-label" data-toggle="' . $groupKey . '">';
        $html .= '<span class="sidebar-label-icon">' . $groupIcon . '</span>';
        $html .= '<span class="sidebar-label-text">' . $label . '</span>';
        $html .= '<span class="toggle-icon">▾</span>';
        $html .= '</div>';
        $html .= '<div class="sidebar-items">';
        foreach ($items as $key => $item) {
            $cls = ($active === $key) ? ' active' : '';
            $html .= '<a href="' . $item[2] . '" class="sidebar-item' . $cls . '">';
            $html .= '<span class="icon">' . $item[0] . '</span> ' . $item[1] . '</a>';
        }
        $html .= '</div>'; // /sidebar-items
        $html .= '</div>'; // /sidebar-group
    }
    $html .= '</div>'; // /sidebar

    // 折叠状态管理 JS（状态持久化到 localStorage）
    $html .= '<script>
    (function() {
        var KEY = "sidebarNavState_v1";
        var state = {};
        try { state = JSON.parse(localStorage.getItem(KEY) || "{}"); } catch(e) {}
        // 初始应用折叠状态
        document.querySelectorAll(".sidebar-group").forEach(function(g) {
            var k = g.getAttribute("data-group");
            if (state[k] === true) g.classList.add("collapsed");
        });
        // 如果 active 项在折叠组里，自动展开
        document.querySelectorAll(".sidebar-group.collapsed .sidebar-item.active").forEach(function(item) {
            var g = item.closest(".sidebar-group");
            if (g) {
                g.classList.remove("collapsed");
                state[g.getAttribute("data-group")] = false;
                try { localStorage.setItem(KEY, JSON.stringify(state)); } catch(e) {}
            }
        });
        // 绑定点击切换
        document.querySelectorAll(".sidebar-label[data-toggle]").forEach(function(lbl) {
            lbl.addEventListener("click", function() {
                var k = this.getAttribute("data-toggle");
                var g = document.querySelector(\'.sidebar-group[data-group="\' + k + \'"]\');
                if (!g) return;
                g.classList.toggle("collapsed");
                state[k] = g.classList.contains("collapsed");
                try { localStorage.setItem(KEY, JSON.stringify(state)); } catch(e) {}
            });
        });
    })();
    </script>';

    // 主内容区开始

    // 会话保活：每 25 分钟 ping 一次服务器刷新 login_time（滑动窗口）
    // 若服务器返回 302 重定向到登录页，说明会话已失效，前端提示并跳转
    $html .= '<script>
(function() {
    var timer, warnTimer;  // 【2026-09-09 修复】声明 timer/warnTimer, 避免 scheduleNext 首次调用 clearTimeout(timer) 抛 ReferenceError
    var SESSION_MS = ' . SESSION_TIMEOUT . ' * 1000;
    var WARN_MS = 5 * 60 * 1000;
    var pingMs = Math.min(25 * 60 * 1000, SESSION_MS - WARN_MS);
    var userInfo = document.querySelector(".topbar-username");

    function showWarn(msg) {
        if (userInfo) { userInfo.style.color = "#e74c3c"; userInfo.title = msg; }
    }

    function ping() {
        fetch(location.pathname + "?_refresh_session=1")
            .then(function(r) {
                if (r.redirected || r.url.indexOf("login.php") >= 0) {
                    alert("会话已过期，请重新登录。");
                    location.href = "login.php?msg=timeout";
                } else {
                    if (userInfo) { userInfo.style.color = ""; userInfo.title = ""; }
                }
            })
            .catch(function() {});
    }

    function scheduleNext() {
        clearTimeout(timer); clearTimeout(warnTimer);
        var remaining = (SESSION_MS - (Date.now() - (window.__sessionStart || Date.now()))) || SESSION_MS;
        if (remaining <= WARN_MS) {
            showWarn("会话即将过期，请保存工作！");
            warnTimer = setTimeout(scheduleNext, 60000);
        } else {
            timer = setTimeout(function() {
                ping();
                scheduleNext();
            }, Math.min(remaining - WARN_MS, pingMs));
        }
    }

    window.__sessionStart = Date.now();
    scheduleNext();
})();
</script>';

    $html .= '<div class="main-content">';

    return $html;
}

// 页面尾部(关闭布局)
function renderFooter() {
    $html = '</div>'; // /main-content
    $html .= '</div>'; // /layout
    $html .= '<div class="footer">' . htmlspecialchars(getSetting('company_name') ?: SITE_TITLE) . ' · 广告制作订单管理系统</div>';
    return $html;
}

// 【2026-09-23 修改】备份目录改到站点目录内（原 ../erp_backups 位于 webroot 之外，
// NAS 面板授权麻烦导致安装自检不通过）。备份文件落盘前强制 AES-256 加密为 .enc，
// 站内目录即使被 HTTP 直达也拿不到明文，安全性有保障。
if (!defined('BACKUP_DIR')) {
    define('BACKUP_DIR', __DIR__ . '/erp_backups');
}

// 取可用备份目录：站点内 erp_backups（与 WEB 进程同属主，天然可写）；
// 若异常不可写则回退到 webroot 内 backups_data，保证自动备份永不静默失败。
function getBackupDir() {
    $safe = BACKUP_DIR;
    if (!is_dir($safe)) {
        if (@mkdir($safe, 0755, true) && is_writable($safe)) return $safe;
    } elseif (is_writable($safe)) {
        return $safe;
    }
    $fallback = __DIR__ . '/backups_data';
    if (!is_dir($fallback)) @mkdir($fallback, 0755, true);
    return $fallback;
}

// 每天首次访问时自动备份数据库(以 .php 调用为触发器)
// 【2026-09-13 安全修复】改写入 backups_data/（已授权可写），落盘前强制 AES-256 加密为 .enc：
// 原实现对 backups/db/ 写明文 .sql —— 该目录在 webroot 内且 Nginx 不读 .htaccess，
// 88/8443 端口可直接下载全库明文。加密失败则中止并写 .failed 标记，绝不明文落盘。
// 【2026-09-23 修改】目录改到站点内 erp_backups（配合加密备份，安全性不变）
// 使用文件锁 + 日期文件名防重复;保留 7 天滚动
function autoDailyBackup($pdo) {
    $backupDir = getBackupDir();
    $lockFile = $backupDir . '/.autobackup.lock';
    $today = date('Ymd');

    // 0) 先确保目录存在(锁文件依赖此目录)
    if (!is_dir($backupDir)) {
        if (!@mkdir($backupDir, 0755, true)) return;
    }
    if (!is_writable($backupDir)) return;

    // 1) 文件锁防并发(多个请求同时拍)
    $lockFp = @fopen($lockFile, 'c');
    if (!$lockFp) return;
    if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
        fclose($lockFp);
        return;
    }

    try {
        // 2) 今天已拍过（或已标记失败）就跳过
        $outFile = $backupDir . "/erp_db_{$today}_auto.sql.enc";
        $failMark = $backupDir . "/erp_db_{$today}_auto.failed";
        if (file_exists($outFile) || file_exists($failMark)) return;

        // 3) 转储所有表到临时文件（使用 $pdo,不能直接调 mysqldump 因为没 shell）
        $tmpFile = $backupDir . "/.tmp_erp_db_{$today}_" . uniqid() . ".sql";
        $fp = @fopen($tmpFile, 'w');
        if (!$fp) { @touch($failMark); return; }
        fwrite($fp, "-- ERP DB backup @ " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            fwrite($fp, "DROP TABLE IF EXISTS `$t`;\n");
            $row = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM);
            fwrite($fp, $row[1] . ";\n\n");
            $rows = $pdo->query("SELECT * FROM `$t`");
            $cols = array_keys($rows->fetch(PDO::FETCH_ASSOC) ?: []);
            if (empty($cols)) continue;
            $colList = '`' . implode('`,`', $cols) . '`';
            foreach ($rows as $r) {
                $vals = array_map(function($v) use ($pdo) {
                    if ($v === null) return 'NULL';
                    return $pdo->quote($v);
                }, array_values($r));
                fwrite($fp, "INSERT INTO `$t` ($colList) VALUES (" . implode(',', $vals) . ");\n");
            }
            fwrite($fp, "\n");
        }
        fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fp);

        // 4) 流式加密落盘；失败则删除明文、写失败标记（当日不再重试）
        $ok = false;
        if (function_exists('encryptFileToFile')) {
            $ok = @encryptFileToFile($tmpFile, $outFile);
        }
        if (!$ok) {
            @unlink($tmpFile);
            @touch($failMark);
            @error_log('[ERP auto-backup] 加密失败，已中止（禁止明文落盘），当日不再重试');
            return;
        }
        @unlink($tmpFile); // 加密成功后立即删除明文临时文件

        // 5) 清理 7 天前的自动备份(.enc)与失败标记
        $cutoff = time() - 7 * 86400;
        foreach (array_merge(
            glob($backupDir . '/erp_db_*_auto.sql.enc') ?: [],
            glob($backupDir . '/erp_db_*_auto.failed') ?: []
        ) as $f) {
            if (filemtime($f) < $cutoff) @unlink($f);
        }
    } catch (Exception $e) {
        // 备份失败不能影响主流程
        @error_log('[ERP auto-backup] ' . $e->getMessage());
    } finally {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
    }
}
?>
