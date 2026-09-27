<?php
/**
 * 手机端 - 编辑客户
 */

require_once 'config.php';
initDatabase();
checkLogin();

$db = getDB();

// 获取客户ID
$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    echo '<script>alert("无效客户");history.back();</script>';
    exit;
}

// 获取客户信息
$customer = $db->prepare("SELECT * FROM customers WHERE id = ?");
$customer->execute([$id]);
$customer = $customer->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    echo '<script>alert("客户不存在");location.href="customers.php";</script>';
    exit;
}

// 处理修改提交
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // CSRF验证
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        echo json_encode(['ok' => false, 'msg' => '非法请求']);
        exit;
    }
    // 【2026-09-15 审计修复】对齐 PC 端：修改客户需要 customer_edit 权限
    if (!hasPermission(PERM_CUSTOMER_EDIT)) {
        echo '<script>alert("无修改客户权限");location.href="customers.php";</script>';
        exit;
    }
    
    try {
        $stmt = $db->prepare("UPDATE customers SET name = ?, contact = ?, phone = ?, address = ? WHERE id = ?");
        $stmt->execute([
            $_POST['name'] ?? '',
            $_POST['contact'] ?? '',
            $_POST['phone'] ?? '',
            $_POST['address'] ?? '',
            $id
        ]);
        
        echo '<script>alert("✅ 客户修改成功");location.href="customers.php";</script>';
        exit;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// 生成 CSRF Token（统一使用 config.php 中的实现，确保 session_history 被正确初始化）
$csrfToken = generateCsrfToken();

$companyName = getSetting('company_name') ?: '广告公司';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>编辑客户 - <?php echo htmlspecialchars($customer['name']); ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <!-- 顶部栏 -->
    <div class="page-header">
        <h1>✏️ 编辑客户</h1>
    </div>
    
    <div class="page">
        <?php if (isset($error)): ?>
        <div class="msg msg-error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            
            <div class="card">
                <div class="card-title">👤 客户信息</div>
                
                <div class="form-group">
                    <label class="form-label">客户名称 <span class="required">*</span></label>
                    <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($customer['name']); ?>" required>
                </div>
                
                <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="form-group">
                        <label class="form-label">联系人</label>
                        <input type="text" name="contact" class="form-control" value="<?php echo htmlspecialchars($customer['contact'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">联系电话</label>
                        <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">地址</label>
                    <input type="text" name="address" class="form-control" value="<?php echo htmlspecialchars($customer['address'] ?? ''); ?>">
                </div>
            </div>
            
            <div class="btn-area" style="display:flex;gap:12px;margin-bottom:20px;">
                <button type="submit" class="btn btn-primary" style="flex:1;">✅ 保存修改</button>
                <a href="customers.php" class="btn btn-outline" style="flex:1;text-align:center;">取消</a>
            </div>
        </form>
    </div>
    
    <?php echo mobileNav('customers'); ?>
</body>
</html>
