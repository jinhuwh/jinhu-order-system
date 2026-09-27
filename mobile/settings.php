<?php
/**
 * 移动端 - 系统设置
 */
require_once 'config.php';
initDatabase();
checkLogin();

if (!hasPermission(PERM_SETTINGS_EDIT)) {
    die('<script>alert("无权限访问,请联系管理员授权");location.href="mobile/index.php";</script>');
}

$db = getDB();
// 确保会话 CSRF token 已初始化（与桌面端一致）
generateCsrfToken();
$companyName = getSetting('company_name') ?: '';
$phone = getSetting('company_phone') ?: '';
$address = getSetting('company_address') ?: '';

$success = false;
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save'])) {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $errorMsg = '安全验证失败';
    } else {
        $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('company_name', ?) ON DUPLICATE KEY UPDATE setting_value = ?")
            ->execute([$_POST['company_name'], $_POST['company_name']]);
        $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('company_phone', ?) ON DUPLICATE KEY UPDATE setting_value = ?")
            ->execute([$_POST['phone'], $_POST['phone']]);
        $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('company_address', ?) ON DUPLICATE KEY UPDATE setting_value = ?")
            ->execute([$_POST['address'], $_POST['address']]);
        $success = true;
        // 刷新变量
        $companyName = $_POST['company_name'];
        $phone = $_POST['phone'];
        $address = $_POST['address'];
    }
}

// 统计数据
$userCount = 0; $orderCount = 0; $customerCount = 0;
try {
    $userCount = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $orderCount = $db->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    $customerCount = $db->query("SELECT COUNT(*) FROM customers")->fetchColumn();
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>系统设置</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="page-header">
        <h1>⚙️ 系统设置</h1>
    </div>

    <div class="page">
        <?php if ($success): ?>
        <div class="msg msg-success">✅ 设置保存成功！</div>
        <?php endif; ?>
        <?php if (isset($errorMsg)): ?>
        <div class="msg msg-danger">❌ <?php echo htmlspecialchars($errorMsg); ?></div>
        <?php endif; ?>

        <!-- 公司信息 -->
        <div class="card">
            <div class="card-title">🏢 公司信息</div>
            <form method="POST">
                <input type="hidden" name="save" value="1">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <div class="form-group">
                    <label>公司名称</label>
                    <input type="text" name="company_name" class="form-control" 
                           value="<?php echo htmlspecialchars($companyName); ?>" placeholder="公司名称">
                </div>
                <div class="form-group">
                    <label>联系电话</label>
                    <input type="text" name="phone" class="form-control"
                           value="<?php echo htmlspecialchars($phone); ?>" placeholder="公司电话">
                </div>
                <div class="form-group">
                    <label>公司地址</label>
                    <input type="text" name="address" class="form-control"
                           value="<?php echo htmlspecialchars($address); ?>" placeholder="公司地址">
                </div>
                <button type="submit" class="btn btn-primary btn-block btn-lg">💾 保存设置</button>
            </form>
        </div>

        <!-- 系统统计 -->
        <div class="summary-grid">
            <div class="summary-item">
                <div class="num"><?php echo $userCount; ?></div>
                <div class="label">用户数</div>
            </div>
            <div class="summary-item">
                <div class="num"><?php echo $orderCount; ?></div>
                <div class="label">订单数</div>
            </div>
            <div class="summary-item">
                <div class="num"><?php echo $customerCount; ?></div>
                <div class="label">客户数</div>
            </div>
            <div class="summary-item">
                <div class="num">v2.0</div>
                <div class="label">系统版本</div>
            </div>
        </div>
    </div>

    <?php echo mobileNav('more'); ?>
</body>
</html>
