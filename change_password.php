<?php
require_once 'config.php';
initDatabase();
checkLogin();

// 检查是否是强制修改密码
$isForceChange = isset($_SESSION['must_change_password']) && $_SESSION['must_change_password'] === true;

$user = getCurrentUser();
$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        die('非法请求，请刷新页面后重试');
    }
    $old_password = $_POST['old_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // 强制修改时，不需要原密码
    $requireOldPassword = !$isForceChange;
    
    if ($requireOldPassword && empty($old_password)) {
        $error = '请输入原密码';
    } elseif (empty($new_password) || empty($confirm_password)) {
        $error = '请填写新密码和确认密码';
    } else {
        $db = getDB();
        
        // 验证原密码（如果不是强制修改）
        if ($requireOldPassword) {
            $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!password_verify($old_password, $current['password'])) {
                $error = '原密码错误';
            }
        }
        
        if (empty($error)) {
            // 验证密码复杂度
            $passwordErrors = validatePassword($new_password);
            if (!empty($passwordErrors)) {
                $error = implode('，', $passwordErrors);
            } elseif ($new_password !== $confirm_password) {
                $error = '两次输入的新密码不一致';
            } else {
                $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?");
                $stmt->execute([$hashed, $_SESSION['user_id']]);
                
                // 清除强制修改标记
                unset($_SESSION['must_change_password']);
                
                // 记录操作日志
                logOperation('change_password', []);
                
                $msg = '密码修改成功';
                
                // 如果是强制修改，成功后跳转首页
                if ($isForceChange) {
                    $_SESSION['success_message'] = '密码修改成功，请重新登录';
                    header('Location: index.php');
                    exit;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>修改密码 - <?php echo SITE_TITLE; ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php echo renderNav(''); ?>
    
    <div class="page-title-bar">
        <div class="page-title-left">
            <h1>修改密码</h1>
            <p>更新您的登录密码</p>
        </div>
    </div>
    
    <?php if ($isForceChange): ?>
    <div class="alert alert-warning" style="margin-bottom: 20px;">
        ⚠️ <strong>安全提示：</strong>为了您的账户安全，首次登录必须修改密码。
    </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom: 20px;"><?php echo h($error); ?></div>
    <?php endif; ?>
    
    <?php if ($msg): ?>
    <div class="alert alert-success" style="margin-bottom: 20px;"><?php echo h($msg); ?></div>
    <?php endif; ?>
    
    <div class="card" style="max-width:480px;">
        <div class="card-header">
            <div class="card-title"><span class="title-icon">🔒</span> 修改密码</div>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <?php if (!$isForceChange): ?>
                <div class="form-group">
                    <label class="form-label">原密码</label>
                    <input type="password" name="old_password" class="form-control" required>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label class="form-label">新密码</label>
                    <input type="password" name="new_password" class="form-control" required minlength="8" id="new_password">
                    <small style="color:var(--gray-500);display:block;margin-top:4px;">
                        密码要求：至少8位，包含大小写字母和数字
                    </small>
                    <div id="password-strength" style="margin-top:8px;font-size:12px;"></div>
                </div>
                <div class="form-group">
                    <label class="form-label">确认新密码</label>
                    <input type="password" name="confirm_password" class="form-control" required id="confirm_password">
                    <div id="password-match" style="margin-top:4px;font-size:12px;"></div>
                </div>
                <div style="display:flex;gap:12px;margin-top:24px;">
                    <button type="submit" class="btn btn-primary" id="submitBtn">确认修改</button>
                    <?php if (!$isForceChange): ?>
                    <a href="index.php" class="btn btn-ghost">返回</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    
    <script>
    // 密码强度检查
    document.getElementById('new_password').addEventListener('input', function() {
        const password = this.value;
        const strengthDiv = document.getElementById('password-strength');
        
        let strength = 0;
        let tips = [];
        
        if (password.length >= 8) strength++;
        else tips.push('至少8位');
        
        if (/[a-z]/.test(password)) strength++;
        else tips.push('需要小写字母');
        
        if (/[A-Z]/.test(password)) strength++;
        else tips.push('需要大写字母');
        
        if (/[0-9]/.test(password)) strength++;
        else tips.push('需要数字');
        
        const colors = ['#EF4444', '#F59E0B', '#10B981', '#3B82F6'];
        const labels = ['弱', '一般', '较强', '强'];
        
        if (password.length === 0) {
            strengthDiv.innerHTML = '';
        } else {
            strengthDiv.innerHTML = '<span style="color:' + colors[strength-1] + '">密码强度：' + labels[strength-1] + '</span>';
            if (tips.length > 0) {
                strengthDiv.innerHTML += ' <span style="color:#64748B;">（建议：' + tips.join('、') + '）</span>';
            }
        }
    });
    
    // 密码匹配检查
    document.getElementById('confirm_password').addEventListener('input', function() {
        const password = document.getElementById('new_password').value;
        const confirm = this.value;
        const matchDiv = document.getElementById('password-match');
        
        if (confirm.length === 0) {
            matchDiv.innerHTML = '';
        } else if (password === confirm) {
            matchDiv.innerHTML = '<span style="color:#10B981;">✓ 密码匹配</span>';
        } else {
            matchDiv.innerHTML = '<span style="color:#EF4444;">✗ 密码不匹配</span>';
        }
    });
    </script>
    
    <?php echo renderFooter(); ?>
</body>
</html>