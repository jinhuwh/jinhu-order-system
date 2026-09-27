<?php
// 【2026-09-09 修复】opcache 强制清理，防止缓存导致编辑保存无反应
if (function_exists('opcache_invalidate')) { @opcache_invalidate(__FILE__, true); }
require_once 'config.php';
initDatabase();
checkLogin();

$db = getDB();

// 添加客户
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add') {
    // CSRF验证
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        die('非法请求');
    }
    $stmt = $db->prepare("INSERT INTO customers (name, contact, phone, address) VALUES (?, ?, ?, ?)");
    $stmt->execute([
        $_POST['name'] ?? '',
        $_POST['contact'] ?? '',
        $_POST['phone'] ?? '',
        $_POST['address'] ?? ''
    ]);
    header("Location: customers.php?added=1");
    exit;
}

// 删除客户(改为POST请求)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete') {
    // CSRF验证
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        header('Content-Type: application/json; charset=utf-8');
        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode(['ok' => false, 'msg' => '非法请求']);
        exit;
    }
    // 【2026-09-08 安全加固】删除客户需要 PERM_CUSTOMER_DELETE 权限
    if (!hasPermission(PERM_CUSTOMER_DELETE)) {
        header('Content-Type: application/json; charset=utf-8');
        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode(['ok' => false, 'msg' => '权限不足，无法删除客户']);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    $deleteId = intval($_POST['id'] ?? 0);
    try {
    if ($deleteId > 0) {
        // 【2026-09-08 修复】已有订单的客户不能删除（订单会失去客户引用）
        $orderCount = $db->prepare("SELECT COUNT(*) FROM orders WHERE customer_id = ?");
        $orderCount->execute([$deleteId]);
        $cnt = (int)$orderCount->fetchColumn();
        if ($cnt > 0) {
            $recent = $db->prepare("SELECT order_no FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT 3");
            $recent->execute([$deleteId]);
            $samples = array_column($recent->fetchAll(PDO::FETCH_ASSOC), 'order_no');
            $sampleStr = implode(', ', $samples);
            // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
            while (ob_get_level() > 0) { ob_end_clean(); }
            echo json_encode(['ok' => false, 'msg' => "该客户已被 {$cnt} 个订单引用（如 {$sampleStr}），不能删除。如需停用，请联系管理员禁用该客户。"]);
            exit;
        }
        $db->prepare("DELETE FROM customers WHERE id = ?")->execute([$deleteId]);
    }
    echo json_encode(['ok' => true, 'msg' => '已删除']);
    } catch (Exception $e) {
        // 【2026-09-15】任何数据库异常都返回 JSON，杜绝 500 空响应
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode(['ok' => false, 'msg' => '操作失败：' . $e->getMessage()]);
    }
    exit;
}

// 更新客户
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update') {
    // CSRF验证
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        header('Content-Type: application/json; charset=utf-8');
        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode(['ok' => false, 'msg' => '非法请求']);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    $id = intval($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if ($id <= 0 || empty($name)) {
        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode(['ok' => false, 'msg' => '参数错误']);
        exit;
    }
    $stmt = $db->prepare("UPDATE customers SET name = ?, contact = ?, phone = ?, address = ? WHERE id = ?");
    $stmt->execute([
        $name,
        $_POST['contact'] ?? '',
        $_POST['phone'] ?? '',
        $_POST['address'] ?? '',
        $id
    ]);
    // 【2026-09-09 修复】清空输出缓冲防止 PHP 警告/通知混入 JSON 响应
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
    while (ob_get_level() > 0) { ob_end_clean(); }
    echo json_encode(['ok' => true, 'msg' => '修改成功']);
    exit;
}

// 导出客户
if (isset($_GET['action']) && $_GET['action'] == 'export') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="客户列表_' . date('Y-m-d') . '.csv"');

    // 添加 BOM 防止 Excel 乱码
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');
    fputcsv($output, ['客户名称', '联系人', '联系电话', '地址']);

    $stmt = $db->query("SELECT name, contact, phone, address FROM customers ORDER BY name");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

// 导入客户 - 显示上传表单
$showImport = isset($_GET['action']) && $_GET['action'] == 'import';

// 导入客户 - 处理上传
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'do_import') {
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode(['ok' => false, 'msg' => '非法请求']);
        exit;
    }
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] != 0) {
        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode(['ok' => false, 'msg' => '请上传CSV文件']);
        exit;
    }

    // 修复字符集冲突:原字段 collation 是 utf8_general_ci,与 PDO 默认 utf8mb4_general_ci 不兼容
    try {
        $db->exec("ALTER TABLE customers MODIFY name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    } catch (Exception $e) {
        // 如果 ALTER 失败,后续查询可能仍会出错
    }

    $file = $_FILES['csv_file']['tmp_name'];

    // 1. 一次性读取整个文件内容
    $content = file_get_contents($file);

    // 2. 移除 UTF-8 BOM（如果存在）
    if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
        $content = substr($content, 3);
    }

    // 3. 检测并整体转码为 UTF-8（处理 Excel 默认 GBK 编码的 CSV）
    if (!mb_check_encoding($content, 'UTF-8')) {
        // 不是有效 UTF-8，尝试 GBK
        $converted = @mb_convert_encoding($content, 'UTF-8', 'GBK');
        if (mb_check_encoding($converted, 'UTF-8')) {
            $content = $converted;
        } else {
            // GBK 失败，尝试 GB2312
            $converted = @mb_convert_encoding($content, 'UTF-8', 'GB2312');
            if (mb_check_encoding($converted, 'UTF-8')) {
                $content = $converted;
            } else {
                // 最后尝试 BIG5
                $converted = @mb_convert_encoding($content, 'UTF-8', 'BIG5');
                if (mb_check_encoding($converted, 'UTF-8')) {
                    $content = $converted;
                }
            }
        }
    }

    // 4. 写入临时文件（保证是 UTF-8 编码的 CSV）
    $tempFile = tempnam(sys_get_temp_dir(), 'csv_import_');
    file_put_contents($tempFile, $content);

    $handle = fopen($tempFile, 'r');
    // 跳过表头
    fgetcsv($handle);

    $added = 0;
    $updated = 0;
    $errors = [];

    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < 1) continue;

        // 字段已经是 UTF-8，不需要逐行转码
        $name = trim($row[0] ?? '');
        if (empty($name)) continue;

        $contact = $row[1] ?? '';
        $phone = $row[2] ?? '';
        $address = $row[3] ?? '';

        // 检查是否已存在
        $check = $db->prepare("SELECT id FROM customers WHERE name = ?");
        $check->execute([$name]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // 更新
            $stmt = $db->prepare("UPDATE customers SET contact = ?, phone = ?, address = ? WHERE id = ?");
            $stmt->execute([$contact, $phone, $address, $existing['id']]);
            $updated++;
        } else {
            // 新增
            $stmt = $db->prepare("INSERT INTO customers (name, contact, phone, address) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $contact, $phone, $address]);
            $added++;
        }
    }
    fclose($handle);
    @unlink($tempFile);
    // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
    while (ob_get_level() > 0) { ob_end_clean(); }
    echo json_encode(['ok' => true, 'msg' => "导入完成：新增 {$added} 条，更新 {$updated} 条"]);
    exit;
}

// 搜索功能
$keyword = $_GET['keyword'] ?? '';
if ($keyword) {
    $stmt = $db->prepare("SELECT * FROM customers WHERE name LIKE ? OR contact LIKE ? OR phone LIKE ? ORDER BY name");
    $stmt->execute(["%$keyword%", "%$keyword%", "%$keyword%"]);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $customers = $db->query("SELECT * FROM customers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

    // 【2026-09-08 修复】一次性查所有客户的订单数, 用于删除按钮提示
    $orderCountMap = [];
    $orderCountStmt = $db->query("SELECT customer_id, COUNT(*) as cnt FROM orders GROUP BY customer_id");
    while ($r = $orderCountStmt->fetch(PDO::FETCH_ASSOC)) {
        $orderCountMap[intval($r['customer_id'])] = intval($r['cnt']);
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>客户管理 - <?php echo SITE_TITLE; ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php echo renderNav('customers'); ?>

    <div class="page-title-bar">
        <div class="page-title-left">
            <h1>客户管理</h1>
            <p>管理客户信息,支持添加、编辑、删除客户</p>
        </div>
    </div>

    <?php if (isset($_GET['added'])): ?>
    <div class="alert alert-success" style="margin-bottom: 20px;">✅ 客户添加成功!</div>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-danger" style="margin-bottom: 20px;">🗑️ 客户已删除</div>
    <?php endif; ?>

    <!-- 添加新客户 -->
    <div class="card">
        <div class="card-header">
            <div class="card-title"><span class="title-icon">➕</span> 添加新客户</div>
        </div>
        <div class="card-body">
            <form method="POST" class="form-row form-row-4">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <div class="form-group">
                    <label class="form-label">客户名称 <span class="required">*</span></label>
                    <input type="text" name="name" class="form-control" placeholder="客户名称" required>
                </div>
                <div class="form-group">
                    <label class="form-label">联系人</label>
                    <input type="text" name="contact" class="form-control" placeholder="联系人姓名">
                </div>
                <div class="form-group">
                    <label class="form-label">联系电话</label>
                    <input type="text" name="phone" class="form-control" placeholder="联系电话">
                </div>
                <div class="form-group">
                    <label class="form-label">地址</label>
                    <input type="text" name="address" class="form-control" placeholder="客户地址">
                </div>
                <div class="form-group" style="grid-column: 1 / -1;">
                    <button type="submit" class="btn btn-success">添加客户</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 客户列表 -->
    <div class="card">
        <div class="card-header">
            <div class="card-title"><span class="title-icon">👥</span> 客户列表</div>
            <div style="display:flex;gap:12px;align-items:center;">
                <a href="customers.php?action=export" class="btn btn-success btn-sm">📥 导出</a>
                <a href="customers.php?action=import" class="btn btn-primary btn-sm">📤 导入</a>
                <form method="GET" style="display:flex;gap:12px;">
                    <div class="search-box" style="max-width:280px;">
                        <span class="search-box-icon">🔍</span>
                        <input type="text" name="keyword" placeholder="搜索客户..." value="<?php echo htmlspecialchars($keyword); ?>">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">搜索</button>
                    <?php if ($keyword): ?>
                    <a href="customers.php" class="btn btn-ghost btn-sm">重置</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <?php if ($showImport): ?>
        <div class="card-body" style="border-bottom:1px solid var(--gray-200);">
            <h3 style="margin-bottom:16px;">导入客户</h3>
            <form id="importForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="do_import">
                <input type="hidden" name="csrf_token" id="importCsrfToken">
                <div class="form-row form-row-4">
                    <div class="form-group">
                        <label class="form-label">选择CSV文件</label>
                        <input type="file" name="csv_file" id="importFile" accept=".csv" class="form-control" required>
                        <small style="color:var(--gray-500);margin-top:4px;display:block;">CSV格式:客户名称,联系人,联系电话,地址(可用Excel另存为CSV)</small>
                    </div>
                    <div class="form-group" style="align-self:flex-end;">
                        <button type="submit" id="btnImport" class="btn btn-success">开始导入</button>
                        <a href="customers.php" class="btn btn-ghost">取消</a>
                    </div>
                </div>
            </form>
            <div id="importMsg" style="margin-top:10px;"></div>
        </div>
        <?php endif; ?>
        <div class="card-body">
            <?php foreach ($customers as $c): ?>
            <div class="customer-card" id="card_<?php echo $c['id']; ?>">
                <div class="view-mode">
                    <div class="name"><?php echo htmlspecialchars($c['name']); ?></div>
                    <div class="meta">
                        <?php if ($c['contact']): ?>
                        <span>👤 <?php echo htmlspecialchars($c['contact']); ?></span>
                        <?php endif; ?>
                        <?php if ($c['phone']): ?>
                        <span>📞 <?php echo htmlspecialchars($c['phone']); ?></span>
                        <?php endif; ?>
                        <?php if ($c['address']): ?>
                        <span>📍 <?php echo htmlspecialchars($c['address']); ?></span>
                        <?php endif; ?>
                        <span style="color:var(--gray-400);">创建于 <?php echo date('Y-m-d', strtotime($c['created_at'])); ?></span>
                    </div>
                    <div class="actions">
                        <button class="btn btn-primary btn-sm" onclick="editCustomer(<?php echo $c['id']; ?>)">编辑</button>
                        <button class="btn btn-danger btn-sm" onclick="deleteCustomer(<?php echo $c['id']; ?>, this)" data-refs="<?php echo intval($orderCountMap[$c['id']] ?? 0); ?>" data-cname="<?php echo htmlspecialchars($c['name']); ?>" title="<?php echo intval($orderCountMap[$c['id']] ?? 0) > 0 ? '该客户已被 ' . intval($orderCountMap[$c['id']] ?? 0) . ' 个订单引用，不能删除' : ''; ?>">删除</button>
                    </div>
                </div>
                <div class="edit-form" style="display:none;">
                    <div class="form-row form-row-4">
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">客户名称</label>
                            <input type="text" class="form-control edit-name" value="<?php echo htmlspecialchars($c['name']); ?>">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">联系人</label>
                            <input type="text" class="form-control edit-contact" value="<?php echo htmlspecialchars($c['contact'] ?? ''); ?>">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">联系电话</label>
                            <input type="text" class="form-control edit-phone" value="<?php echo htmlspecialchars($c['phone'] ?? ''); ?>">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">地址</label>
                            <input type="text" class="form-control edit-address" value="<?php echo htmlspecialchars($c['address'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="actions" style="margin-top:16px;">
                        <button class="btn btn-success btn-sm" onclick="saveCustomer(<?php echo $c['id']; ?>)">保存</button>
                        <button class="btn btn-ghost btn-sm" onclick="cancelEdit(<?php echo $c['id']; ?>)">取消</button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($customers)): ?>
            <div class="empty-state">
                <div class="icon">📭</div>
                <div class="text">暂无客户,请在上方添加新客户</div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php echo renderFooter(); ?>

    <script>
    var csrfToken = '<?php echo generateCsrfToken(); ?>';
    if (document.getElementById('importCsrfToken')) {
        document.getElementById('importCsrfToken').value = csrfToken;
    }

    // AJAX 导入（不整页刷新，token 始终同步）
    var importForm = document.getElementById('importForm');
    if (importForm) {
    importForm.onsubmit = function(e) {
        e.preventDefault();
        var btn = document.getElementById('btnImport');
        var msg = document.getElementById('importMsg');
        btn.disabled = true;
        btn.textContent = '导入中…';
        var fd = new FormData(this);
        fd.set('csrf_token', csrfToken);
        fetch('customers.php', { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(data){
                btn.disabled = false;
                btn.textContent = '开始导入';
                if (data.ok) {
                    msg.innerHTML = '<div class="alert alert-success">' + data.msg + '，页面将自动刷新…</div>';
                    setTimeout(function(){ location.reload(); }, 800);
                } else {
                    msg.innerHTML = '<div class="alert alert-danger">' + data.msg + '</div>';
                }
            })
            .catch(function(){
                btn.disabled = false;
                btn.textContent = '开始导入';
                msg.innerHTML = '<div class="alert alert-danger">网络错误，请重试</div>';
            });
        return false;
    };
    }

    function editCustomer(id) {
        var card = document.getElementById('card_' + id);
        if (!card) return;

        card.style.borderColor = 'var(--primary)';
        card.style.background = 'rgba(59, 130, 246, 0.02)';
        card.querySelector('.view-mode').style.display = 'none';
        card.querySelector('.edit-form').style.display = 'block';
    }

    function cancelEdit(id) {
        var card = document.getElementById('card_' + id);
        if (!card) return;

        card.style.borderColor = '';
        card.style.background = '';
        card.querySelector('.view-mode').style.display = 'block';
        card.querySelector('.edit-form').style.display = 'none';
    }

    function saveCustomer(id) {
        var card = document.getElementById('card_' + id);
        var name = card.querySelector('.edit-name').value.trim();
        if (!name) { alert('客户名称不能为空'); return; }

        var fd = new FormData();
        fd.append('action', 'update');
        fd.append('csrf_token', csrfToken);
        fd.append('id', id);
        fd.append('name', name);
        fd.append('contact', card.querySelector('.edit-contact').value);
        fd.append('phone', card.querySelector('.edit-phone').value);
        fd.append('address', card.querySelector('.edit-address').value);

        // 【2026-09-09 修复】r.json() 解析失败时无 catch 会导致 promise 静默失败，加 .catch + 错误详情便于诊断
        fetch('customers.php', { method: 'POST', body: fd })
            .then(function(r){
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text().then(function(txt){
                    try { return JSON.parse(txt); }
                    catch(e) {
                        console.error('[saveCustomer] 非JSON响应:', txt.substring(0, 300));
                        throw new Error('服务器返回非JSON: ' + txt.substring(0, 80));
                    }
                });
            })
            .then(function(data){
                if (data && data.ok) {
                    location.reload();
                } else {
                    alert('保存失败: ' + ((data && data.msg) || '未知错误'));
                }
            })
            .catch(function(err){
                console.error('[saveCustomer] 错误:', err);
                alert('保存失败: ' + err.message);
            });
    }

    function deleteCustomer(id, btn) {
        // 【2026-09-08 修复】按钮上已带 data-refs, 提前拦截被订单引用的客户
        var refs = 0, cname = '';
        if (btn) {
            refs = parseInt(btn.getAttribute('data-refs') || '0', 10);
            cname = btn.getAttribute('data-cname') || '';
        }
        if (refs > 0) {
            alert('删除失败：客户「' + cname + '」已被 ' + refs + ' 个订单引用，不能删除。\n如需停用，请联系管理员禁用该客户。');
            return;
        }
        if (!confirm('确定删除该客户「' + cname + '」?')) return;

        var fd = new FormData();
        fd.append('action', 'delete');
        fd.append('csrf_token', csrfToken);
        fd.append('id', id);

        // 【2026-09-15】统一删除请求处理：失败一律 alert("删除失败：原因")，
        // 服务器空响应/非 JSON（500、WAF 拦截）也给出明确中文提示，不再报英文解析错误
        fetch('customers.php', { method: 'POST', body: fd })
            .then(function(r){ return r.text().then(function(t){ return { status: r.status, text: t }; }); })
            .then(function(res){
                var data = null;
                try { data = JSON.parse(res.text); } catch (e) { data = null; }
                if (data === null) {
                    alert('删除失败：服务器返回异常(HTTP ' + res.status + ')' + (res.text ? '\n' + res.text.substring(0, 150) : '\n（无响应内容，请联系管理员查看服务器错误日志）'));
                    return;
                }
                if (data.ok) {
                    location.reload();
                } else {
                    alert('删除失败：' + (data.msg || '未知原因'));
                }
            })
            .catch(function(){
                alert('删除失败：网络错误，请重试');
            });
    }
    </script>
</body>
</html>