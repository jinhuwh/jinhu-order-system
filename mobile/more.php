<?php
/**
 * 移动端 - 更多（设置/对账/密码）
 */
require_once 'config.php';
initDatabase();
checkLogin();

$companyName = getSetting('company_name') ?: '广告公司';
$user = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>更多 - <?php echo htmlspecialchars($companyName); ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="page-header">
        <h1>⚙️ 更多</h1>
        <p class="company"><?php echo htmlspecialchars($companyName); ?></p>
    </div>

    <div class="page">
        <!-- 系统信息 -->
        <div style="background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:white;border-radius:var(--radius-lg);padding:20px;margin-bottom:16px;box-shadow:0 4px 14px rgba(59,130,246,0.3);">
            <div style="font-size:14px;font-weight:600;margin-bottom:10px;"><?php echo htmlspecialchars($companyName); ?></div>
            <div style="font-size:12px;opacity:0.85;">广告制作订单管理系统 v2.0</div>
            <div style="display:flex;gap:16px;margin-top:12px;font-size:13px;">
                <span>👤 <?php echo htmlspecialchars($user['realname'] ?: $user['username']); ?></span>
                <span>📅 <?php echo date('Y-m-d'); ?></span>
            </div>
        </div>

        <!-- 功能菜单 -->
        <div class="menu-group">
            <a href="products.php" class="menu-item">
                <div class="icon" style="background:var(--success-bg);color:#15803D;">📦</div>
                <div class="text"><div class="title">产品管理</div><div class="desc">编辑产品分类、单价</div></div>
                <div class="arrow">›</div>
            </a>
            <a href="units.php" class="menu-item">
                <div class="icon" style="background:var(--info-bg);color:#0369A1;">📏</div>
                <div class="text"><div class="title">单位管理</div><div class="desc">计量单位维护</div></div>
                <div class="arrow">›</div>
            </a>
            <a href="statement.php" class="menu-item">
                <div class="icon" style="background:var(--info-bg);color:#0369A1;">🧾</div>
                <div class="text"><div class="title">客户对账单</div><div class="desc">按客户汇总订单金额</div></div>
                <div class="arrow">›</div>
            </a>
            <a href="debtors.php" class="menu-item">
                <div class="icon" style="background:#FEF2F2;color:#DC2626;">💳</div>
                <div class="text"><div class="title">欠款追踪</div><div class="desc">查看客户欠款，追踪应收款项</div></div>
                <div class="arrow">›</div>
            </a>
            <a href="finance.php" class="menu-item">
                <div class="icon" style="background:var(--success-bg);color:#047857;">💰</div>
                <div class="text"><div class="title">财务管理</div><div class="desc">应收、回款看板与导出</div></div>
                <div class="arrow">›</div>
            </a>
            <a href="reports.php" class="menu-item">
                <div class="icon" style="background:#FEF3C7;color:#B45309;">📊</div>
                <div class="text"><div class="title">数据统计</div><div class="desc">本月营收、客户/产品排行</div></div>
                <div class="arrow">›</div>
            </a>
            <a href="settings.php" class="menu-item">
                <div class="icon" style="background:var(--purple-bg);color:#6D28D9;">⚙️</div>
                <div class="text"><div class="title">系统设置</div><div class="desc">公司信息、系统配置</div></div>
                <div class="arrow">›</div>
            </a>
            <a href="users.php" class="menu-item">
                <div class="icon" style="background:var(--primary-bg);color:var(--primary-dark);">👥</div>
                <div class="text"><div class="title">用户管理</div><div class="desc">账号、角色与密码</div></div>
                <div class="arrow">›</div>
            </a>
            <a href="logs.php" class="menu-item">
                <div class="icon" style="background:var(--gray-100);color:var(--gray-500);">📋</div>
                <div class="text"><div class="title">操作日志</div><div class="desc">关键操作审计记录</div></div>
                <div class="arrow">›</div>
            </a>
            <a href="change_password.php" class="menu-item">
                <div class="icon" style="background:var(--warning-bg);color:#B45309;">🔐</div>
                <div class="text"><div class="title">修改密码</div><div class="desc">更改登录密码</div></div>
                <div class="arrow">›</div>
            </a>
        </div>

        <!-- 关于 -->
        <div class="menu-group">
            <div class="menu-item" style="cursor:default;">
                <div class="icon" style="background:var(--gray-100);color:var(--gray-500);">ℹ️</div>
                <div class="text"><div class="title">关于系统</div><div class="desc">金狐文化广告订单管理系统</div></div>
            </div>
        </div>

        <!-- 退出登录 -->
        <a href="../logout.php" class="btn btn-danger btn-block btn-lg" style="margin-top:8px;">
            🚪 退出登录
        </a>
    </div>

    <?php echo mobileNav('more'); ?>
</body>
</html>
