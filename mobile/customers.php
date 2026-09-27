<?php
require_once 'config.php';
initDatabase();
checkLogin();

// 生成 CSRF Token（统一使用 config.php 中实现，与 PC 端一致）
$csrfToken = generateCsrfToken();

$db = getDB();

// 添加客户
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('非法请求');
    }
    // 【2026-09-15 审计修复】对齐 PC 端：添加客户需要 customer_create 权限
    if (!hasPermission(PERM_CUSTOMER_CREATE)) {
        die('<script>alert("无添加客户权限");location.href="customers.php";</script>');
    }
    $stmt = $db->prepare("INSERT INTO customers (name, contact, phone, address) VALUES (?, ?, ?, ?)");
    $stmt->execute([
        $_POST['name'] ?? '',
        $_POST['contact'] ?? '',
        $_POST['phone'] ?? '',
        $_POST['address'] ?? ''
    ]);
    $added = true;
}

// 删除客户（POST请求）
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete') {
    // 【2026-09-15】改为 AJAX(JSON) 返回，配合移动端统一弹窗展示结果（原先失败时页面顶部横幅提示，风格不统一）
    header('Content-Type: application/json; charset=utf-8');
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'msg' => '安全验证失败，请刷新页面后重试']);
        exit;
    }
    // 对齐 PC 端：删除客户需要 customer_delete 权限
    if (!hasPermission(PERM_CUSTOMER_DELETE)) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode(['ok' => false, 'msg' => '权限不足，无法删除客户']);
        exit;
    }
    $deleteId = intval($_POST['id'] ?? 0);
    if ($deleteId <= 0) {
        echo json_encode(['ok' => false, 'msg' => '参数错误']);
        exit;
    }
    // 对齐 PC 端：已有订单的客户不能删除（订单会失去客户引用）
    $orderCount = $db->prepare("SELECT COUNT(*) FROM orders WHERE customer_id = ?");
    $orderCount->execute([$deleteId]);
    $cnt = (int)$orderCount->fetchColumn();
    if ($cnt > 0) {
        $recent = $db->prepare("SELECT order_no FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT 3");
        $recent->execute([$deleteId]);
        $sampleStr = implode(', ', array_column($recent->fetchAll(PDO::FETCH_ASSOC), 'order_no'));
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode(['ok' => false, 'msg' => "该客户已被 $cnt 个订单引用（如 {$sampleStr}），不能删除。\n如需停用，请联系管理员禁用该客户。"]);
        exit;
    }
    $db->prepare("DELETE FROM customers WHERE id = ?")->execute([$deleteId]);
    echo json_encode(['ok' => true, 'msg' => '客户已删除']);
    exit;
}

$search = trim($_GET['search'] ?? '');
if ($search) {
    $stmt = $db->prepare("SELECT * FROM customers WHERE name LIKE ? OR phone LIKE ? OR contact LIKE ? ORDER BY name LIMIT 50");
    $stmt->execute(["%$search%", "%$search%", "%$search%"]);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $customers = $db->query("SELECT * FROM customers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

$companyName = getSetting('company_name') ?: '广告公司';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>客户管理 - <?php echo htmlspecialchars($companyName); ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .customer-card {
            background: white;
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 12px;
            box-shadow: var(--shadow);
        }
        .customer-card .name {
            font-weight: 600;
            font-size: 16px;
            color: #333;
            margin-bottom: 6px;
        }
        .customer-card .info {
            color: #666;
            font-size: 13px;
            line-height: 1.8;
        }
        .customer-card .actions {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid var(--border);
            display: flex;
            gap: 10px;
        }
        .add-form { background: white; border-radius: var(--radius); padding: 16px; margin-bottom: 16px; box-shadow: var(--shadow); }
        .add-form .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .add-form .form-row.single { grid-template-columns: 1fr; }
    </style>
</head>
<body>
    <div class="page-header">
        <h1>👥 客户管理</h1>
    </div>
    
    <div class="page">
        <?php if (!empty($added)): ?>
        <div class="msg msg-success">✅ 客户添加成功</div>
        <?php endif; ?>
        
        <!-- 添加客户 -->
        <div class="add-form">
            <div class="card-title">➕ 添加新客户</div>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <div class="form-row single">
                    <div class="form-group">
                        <label>客户名称 *</label>
                        <input type="text" name="name" class="form-control" placeholder="客户名称" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>联系人</label>
                        <input type="text" name="contact" class="form-control" placeholder="联系人">
                    </div>
                    <div class="form-group">
                        <label>联系电话</label>
                        <input type="text" name="phone" class="form-control" placeholder="手机/电话">
                    </div>
                </div>
                <div class="form-group">
                    <label>地址</label>
                    <input type="text" name="address" class="form-control" placeholder="地址">
                </div>
                <button type="submit" class="btn btn-primary btn-block">添加客户</button>
            </form>
        </div>
        
        <!-- 搜索 -->
        <form method="GET" class="search-bar">
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                   placeholder="搜索客户名称/电话/联系人" class="form-control">
            <button type="submit" class="btn btn-primary">🔍</button>
            <?php if ($search): ?>
            <a href="customers.php" class="btn btn-outline">重置</a>
            <?php endif; ?>
        </form>
        
        <!-- 客户列表 -->
        <?php if (empty($customers)): ?>
        <div class="empty-state">
            <div class="icon">👥</div>
            <div class="text">暂无客户</div>
        </div>
        <?php else: ?>
            <?php foreach ($customers as $c): ?>
            <div class="customer-card">
                <div class="name"><?php echo htmlspecialchars($c['name']); ?></div>
                <div class="info">
                    <?php if ($c['contact']): ?>📧 <?php echo htmlspecialchars($c['contact']); ?><br><?php endif; ?>
                    <?php if ($c['phone']): ?>📞 <?php echo htmlspecialchars($c['phone']); ?><br><?php endif; ?>
                    <?php if ($c['address']): ?>📍 <?php echo htmlspecialchars($c['address']); ?><?php endif; ?>
                </div>
                <div class="actions">
                    <a href="customer_edit.php?id=<?php echo $c['id']; ?>" class="btn btn-primary btn-sm">✏️</a>
                    <?php if ($c['phone']): ?>
                    <a href="tel:<?php echo htmlspecialchars($c['phone']); ?>" class="btn btn-success btn-sm">📞</a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-danger btn-sm" onclick="deleteCustomer(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars(addslashes($c['name'])); ?>')">🗑️</button>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <script>
    // 【2026-09-15】统一弹窗：确认 -> 提交 -> 结果提示（与产品/单位删除完全一致）
    function deleteCustomer(id, name) {
        mdlgConfirm('确定要删除客户「' + name + '」吗？\n删除后不可恢复。', function() {
            var fd = new FormData();
            fd.append('action', 'delete');
            fd.append('csrf_token', '<?php echo $csrfToken; ?>');
            fd.append('id', id);
            fetch('customers.php', { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(d) {
                    if (d.ok) {
                        mdlgAlert(d.msg || '客户已删除', true, function(){ location.reload(); }, 800);
                    } else {
                        // 删除失败时必须给出原因（订单引用 / 权限不足等）
                        mdlgAlert(d.msg || '删除失败', false);
                    }
                })
                .catch(function() { mdlgAlert('网络错误，删除失败', false); });
        }, { title: '删除客户' });
    }
    </script>

    <?php echo mobileDialog(); ?>

    <?php echo mobileNav('customers'); ?>
</body>
</html>
