<?php
require_once 'config.php';
initDatabase();
checkLogin();

// 只有管理员才能访问系统设置
if (!hasPermission(PERM_SETTINGS_EDIT)) {
    die('<script>alert("无权限访问，请联系管理员授权");location.href="index.php";</script>');
}

$msg = '';
$error = '';

// 保存系统设置
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    // CSRF验证
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $error = '非法请求';
    } else {
        $company_name = trim($_POST['company_name'] ?? '');
        $company_phone = trim($_POST['company_phone'] ?? '');
        $company_address = trim($_POST['company_address'] ?? '');
        
        if (empty($company_name)) {
            $error = '公司名称不能为空';
        } else {
            $db = getDB();
            $settings = [
                'company_name' => $company_name,
                'company_phone' => $company_phone,
                'company_address' => $company_address,
            ];
            $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            foreach ($settings as $key => $value) {
                $stmt->execute([$key, $value, $value]);
            }
            $msg = '系统设置保存成功';
        }
    }
}

// 匿名使用统计开关 / 检查更新（删除 telemetry.php 后自动降级，仅隐藏相关卡片功能）
$hasTelemetry = file_exists(__DIR__ . '/telemetry.php');
if ($hasTelemetry) {
    require_once __DIR__ . '/telemetry.php';
}
$updateInfo = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $hasTelemetry) {
    if ($_POST['action'] === 'save_telemetry') {
        if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
            $error = '非法请求';
        } else {
            $val = isset($_POST['telemetry_enabled']) ? '1' : '0';
            $db = getDB();
            $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('telemetry_enabled', ?) "
                . "ON DUPLICATE KEY UPDATE setting_value = ?")->execute([$val, $val]);
            $msg = $val === '1'
                ? '匿名使用统计已开启：每周上报一次版本信息（不含任何业务数据）'
                : '匿名使用统计已关闭';
        }
    } elseif ($_POST['action'] === 'check_update') {
        if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
            $error = '非法请求';
        } else {
            $updateInfo = jh_telemetry_ping(true);
            if ($updateInfo === null) {
                $updateInfo = ['error' => true,
                    'error_detail' => isset($GLOBALS['jh_telemetry_last_error'])
                        ? $GLOBALS['jh_telemetry_last_error'] : '未知原因'];
            }
        }
    }
}

$settings = getAllSettings();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统设置 - <?php echo SITE_TITLE; ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php echo renderNav('settings'); ?>
    
    <div class="page-title-bar">
        <div class="page-title-left">
            <h1>系统设置</h1>
            <p>配置公司信息和系统参数</p>
        </div>
    </div>
    
    <?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom: 20px;"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <?php if ($msg): ?>
    <div class="alert alert-success" style="margin-bottom: 20px;"><?php echo $msg; ?></div>
    <?php endif; ?>
    
    <div class="alert alert-warning" style="margin-bottom: 20px;">
        💡 <strong>提示：</strong>普通用户无法访问系统设置和用户管理功能。
    </div>
    
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:24px;">
        
        <!-- 公司信息设置 -->
        <div class="card">
            <div class="card-header">
                <div class="card-title"><span class="title-icon">🏢</span> 公司信息</div>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="save_settings">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <div class="form-group">
                        <label class="form-label">公司名称</label>
                        <input type="text" name="company_name" class="form-control" value="<?php echo htmlspecialchars($settings['company_name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">公司电话</label>
                        <input type="text" name="company_phone" class="form-control" value="<?php echo htmlspecialchars($settings['company_phone'] ?? ''); ?>" placeholder="例: 0731-88888888">
                    </div>
                    <div class="form-group">
                        <label class="form-label">公司地址</label>
                        <input type="text" name="company_address" class="form-control" value="<?php echo htmlspecialchars($settings['company_address'] ?? ''); ?>" placeholder="例: 湖南省长沙市">
                    </div>
                    <button type="submit" class="btn btn-primary">💾 保存设置</button>
                </form>
            </div>
        </div>
        
        <!-- 快捷入口 -->
        <div class="card">
            <div class="card-header">
                <div class="card-title"><span class="title-icon">🔧</span> 系统管理</div>
            </div>
            <div class="card-body">
                <div style="display:flex;flex-direction:column;gap:12px;">
                    <a href="users.php" class="btn btn-primary" style="text-align:center;">👥 用户管理</a>
                    <a href="change_password.php" class="btn btn-success" style="text-align:center;">🔒 修改密码</a>
                </div>
                
                <?php
                $db = getDB();
                $userCount = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
                $orderCount = $db->query("SELECT COUNT(*) FROM orders")->fetchColumn();
                $customerCount = $db->query("SELECT COUNT(*) FROM customers")->fetchColumn();
                $productCount = $db->query("SELECT COUNT(*) FROM products")->fetchColumn();
                ?>
                
                <div style="margin-top:24px;background:var(--gray-50);border-radius:var(--radius-lg);padding:20px;">
                    <div style="font-weight:700;margin-bottom:16px;color:var(--gray-700);">📊 系统统计</div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                        <div>
                            <div style="font-size:28px;font-weight:800;color:var(--primary);"><?php echo $userCount; ?></div>
                            <div style="font-size:12px;color:var(--gray-500);">用户数</div>
                        </div>
                        <div>
                            <div style="font-size:28px;font-weight:800;color:var(--accent);"><?php echo $orderCount; ?></div>
                            <div style="font-size:12px;color:var(--gray-500);">订单数</div>
                        </div>
                        <div>
                            <div style="font-size:28px;font-weight:800;color:var(--info);"><?php echo $customerCount; ?></div>
                            <div style="font-size:12px;color:var(--gray-500);">客户数</div>
                        </div>
                        <div>
                            <div style="font-size:28px;font-weight:800;color:var(--success);"><?php echo $productCount; ?></div>
                            <div style="font-size:12px;color:var(--gray-500);">产品数</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
    </div>

    <?php if ($hasTelemetry): ?>
    <!-- 关于与更新 -->
    <div class="card" style="margin-top:24px;">
        <div class="card-header">
            <div class="card-title"><span class="title-icon">ℹ️</span> 关于与更新</div>
        </div>
        <div class="card-body">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:24px;">
                <div>
                    <div style="font-weight:700;margin-bottom:8px;">当前版本：v<?php echo APP_VERSION; ?></div>
                    <form method="POST" action="" style="margin-bottom:12px;">
                        <input type="hidden" name="action" value="check_update">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                        <button type="submit" class="btn btn-primary">🔄 检查更新</button>
                    </form>
                    <?php if (is_array($updateInfo) && isset($updateInfo['error'])): ?>
                        <div style="color:#B45309;font-size:13px;">
                            暂时无法连接更新服务器，原因：<br>
                            <code style="word-break:break-all;"><?php echo htmlspecialchars($updateInfo['error_detail'] ?? '未知'); ?></code><br>
                            常见原因：服务器无法访问外网、DNS 解析失败、HTTPS 证书校验失败。
                        </div>
                    <?php elseif (is_array($updateInfo) && isset($updateInfo['has_new'])): ?>
                        <?php if ($updateInfo['has_new']): ?>
                            <div class="alert alert-success" style="margin:0;">
                                发现新版本 <strong>v<?php echo htmlspecialchars($updateInfo['latest']); ?></strong>
                                （当前 v<?php echo htmlspecialchars($updateInfo['current']); ?>）
                                <?php if ($updateInfo['note']): ?><br><small><?php echo htmlspecialchars($updateInfo['note']); ?></small><?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div style="color:var(--success);font-size:13px;">✅ 已是最新版本</div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="save_telemetry">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                        <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;">
                            <input type="checkbox" name="telemetry_enabled" value="1"
                                <?php echo (($settings['telemetry_enabled'] ?? '0') === '1') ? 'checked' : ''; ?>
                                style="margin-top:4px;">
                            <span style="font-size:13px;line-height:1.7;">
                                <strong>匿名使用统计</strong>（默认关闭）<br>
                                开启后每周向作者上报一次匿名信息：随机实例 ID、系统版本、PHP/MySQL 版本。<br>
                                <span style="color:var(--gray-500);">绝不上报订单、客户、金额等任何业务数据，代码见 telemetry.php，可随时关闭。</span>
                            </span>
                        </label>
                        <button type="submit" class="btn btn-secondary" style="margin-top:10px;">保存统计设置</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php echo renderFooter(); ?>
</body>
</html>