<?php
/**
 * 移动端 - 修改密码
 */
require_once 'config.php';
initDatabase();
checkLogin();

$db = getDB();
$error = '';
$success = false;

// 生成 CSRF Token（统一使用 config.php 中实现，与 PC 端一致）
$csrfToken = generateCsrfToken();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // CSRF 校验
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = '非法请求';
    } else {
    $oldPwd = $_POST['old_password'] ?? '';
    $newPwd = $_POST['new_password'] ?? '';
    $confirmPwd = $_POST['confirm_password'] ?? '';
    
    if (empty($oldPwd) || empty($newPwd) || empty($confirmPwd)) {
        $error = '请填写所有字段';
    } elseif ($newPwd !== $confirmPwd) {
        $error = '两次输入的新密码不一致';
    } elseif (strlen($newPwd) < 6) {
        $error = '新密码长度不能少于6位';
    } else {
        // 验证旧密码
        $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row && password_verify($oldPwd, $row['password'])) {
            $hash = password_hash($newPwd, PASSWORD_DEFAULT);
            $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $_SESSION['user_id']]);
            $success = true;
        } else {
            $error = '原密码错误';
        }
    }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>修改密码</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="page-header">
        <h1>🔐 修改密码</h1>
    </div>

    <div class="page">
        <?php if ($success): ?>
        <div class="card" style="text-align:center;padding:40px 20px;">
            <div style="font-size:52px;margin-bottom:14px;">✅</div>
            <h3 style="color:var(--gray-800);margin-bottom:8px;">密码修改成功</h3>
            <p style="color:var(--gray-400);font-size:13px;">下次登录请使用新密码</p>
            <a href="more.php" class="btn btn-primary btn-lg" style="margin-top:20px;">返回</a>
        </div>
        <?php else: ?>
        
        <?php if ($error): ?>
        <div class="msg msg-error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="card">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <div class="form-group">
                    <label>当前密码</label>
                    <input type="password" name="old_password" class="form-control" placeholder="请输入当前密码" required autofocus>
                </div>
                <div class="form-group">
                    <label>新密码</label>
                    <input type="password" name="new_password" class="form-control" placeholder="至少6位字符" required minlength="6">
                </div>
                <div class="form-group">
                    <label>确认新密码</label>
                    <input type="password" name="confirm_password" class="form-control" placeholder="再次输入新密码" required minlength="6">
                </div>
                <button type="submit" class="btn btn-primary btn-block btn-lg">✅ 确认修改</button>
            </form>
        </div>

        <div style="padding:12px;text-align:center;color:var(--gray-400);font-size:12px;">
            密码修改后需重新登录
        </div>

        <?php endif; ?>
    </div>

    <?php echo mobileNav('more'); ?>
</body>
</html>
