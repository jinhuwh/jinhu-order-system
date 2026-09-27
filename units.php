<?php
/**
 * 产品单位管理
 */
require_once 'config.php';
initDatabase();
checkLogin();

if (!hasPermission(PERM_PRODUCT_EDIT)) {
    die('<script>alert("无权限访问，请联系管理员授权");location.href="index.php";</script>');
}

$msg = '';
$error = '';

$db = getDB();

// 处理 AJAX 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        echo json_encode(['ok' => false, 'msg' => '安全验证失败']);
        exit;
    }
    
    $action = $_POST['ajax_action'];
    
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $short_name = trim($_POST['short_name'] ?? '');
        // 【2026-09-15】排序留空时自动取最大排序+1
        $sort_raw = trim((string)($_POST['sort_order'] ?? ''));
        $sort_order = $sort_raw === ''
            ? (int)$db->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM product_units")->fetchColumn()
            : intval($sort_raw);
        
        if (empty($name)) {
            echo json_encode(['ok' => false, 'msg' => '单位名称不能为空']);
            exit;
        }
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM product_units WHERE name = ?");
        $stmt->execute([$name]);
        if ($stmt->fetchColumn() > 0) {
            echo json_encode(['ok' => false, 'msg' => '该单位已存在']);
            exit;
        }
        
        $stmt = $db->prepare("INSERT INTO product_units (name, short_name, sort_order) VALUES (?, ?, ?)");
        $stmt->execute([$name, $short_name, $sort_order]);
        
        logOperation('add_unit', ['unit' => $name]);
        echo json_encode(['ok' => true, 'msg' => '单位添加成功']);
        exit;
    }
    
    if ($action === 'edit') {
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $short_name = trim($_POST['short_name'] ?? '');
        $sort_order = intval($_POST['sort_order'] ?? 0);
        
        if ($id <= 0 || empty($name)) {
            echo json_encode(['ok' => false, 'msg' => '参数错误']);
            exit;
        }
        
        $stmt = $db->prepare("UPDATE product_units SET name = ?, short_name = ?, sort_order = ? WHERE id = ?");
        $stmt->execute([$name, $short_name, $sort_order, $id]);
        
        logOperation('edit_unit', ['id' => $id, 'unit' => $name]);
        echo json_encode(['ok' => true, 'msg' => '单位更新成功']);
        exit;
    }
    
    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'msg' => '参数错误']);
            exit;
        }

        // 【2026-09-15 修复】先取单位名，再用参数绑定比较（原子查询列比较在 MySQL 5.7
        // 两表 collation 不一致时报 Illegal mix of collations → 500 空响应）
        try {
            $nameStmt = $db->prepare("SELECT name FROM product_units WHERE id = ?");
            $nameStmt->execute([$id]);
            $unitName = $nameStmt->fetchColumn();
            if ($unitName === false) {
                echo json_encode(['ok' => false, 'msg' => '单位不存在或已被删除']);
                exit;
            }

            $cntStmt = $db->prepare("SELECT COUNT(*) FROM products WHERE unit = ?");
            $cntStmt->execute([$unitName]);
            if ($cntStmt->fetchColumn() > 0) {
                echo json_encode(['ok' => false, 'msg' => '该单位有产品使用，无法删除']);
                exit;
            }

            // 【2026-09-15 加强】订单明细直接存储单位字符串，即使没有产品在用，历史订单仍引用该单位
            $itemCount = $db->prepare("SELECT COUNT(*) FROM order_items WHERE unit = ?");
            $itemCount->execute([$unitName]);
            $cnt = (int)$itemCount->fetchColumn();
            if ($cnt > 0) {
                $recent = $db->prepare("SELECT DISTINCT o.order_no FROM order_items oi JOIN orders o ON oi.order_id = o.id WHERE oi.unit = ? ORDER BY oi.order_id DESC LIMIT 3");
                $recent->execute([$unitName]);
                $samples = array_column($recent->fetchAll(PDO::FETCH_ASSOC), 'order_no');
                echo json_encode(['ok' => false, 'msg' => "该单位已被 {$cnt} 个订单明细使用（如订单 " . implode(', ', $samples) . "），不能删除"]);
                exit;
            }

            $stmt = $db->prepare("DELETE FROM product_units WHERE id = ?");
            $stmt->execute([$id]);

            logOperation('delete_unit', ['id' => $id, 'unit' => $unitName]);
            echo json_encode(['ok' => true, 'msg' => '单位删除成功']);
        } catch (Exception $e) {
            // 【2026-09-15】任何数据库异常都返回 JSON，杜绝 500 空响应
            echo json_encode(['ok' => false, 'msg' => '操作失败：' . $e->getMessage()]);
        }
        exit;
    }
}

$units = $db->query("SELECT * FROM product_units ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);

// 获取产品数量统计
$stats = [];
foreach ($units as $unit) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE unit = ?");
    $stmt->execute([$unit['name']]);
    $stats[$unit['name']] = $stmt->fetchColumn();
}

$csrfToken = $_SESSION['csrf_token'] ?? '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>产品单位管理 - <?php echo SITE_TITLE; ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .page-title-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .btn { padding: 10px 20px; border-radius: 8px; border: none; cursor: pointer; font-size: 14px; }
        .btn-primary { background: #3B82F6; color: white; }
        .btn-outline { background: white; color: #3B82F6; border: 1px solid #3B82F6; }
        .btn-sm { padding: 6px 12px; font-size: 13px; }
        .btn-danger { background: #EF4444; color: white; }
        .card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: 24px; margin-bottom: 16px; }
        .card-title { font-size: 18px; font-weight: 600; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 14px 16px; text-align: left; border-bottom: 1px solid #E5E7EB; }
        th { background: #F9FAFB; font-weight: 600; color: #374151; }
        tr:hover { background: #F9FAFB; }
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; background: #E0E7FF; color: #4338CA; }
        .badge-short { background: #D1FAE5; color: #065F46; }
        .actions { display: flex; gap: 8px; }
        .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 1000; }
        .modal-overlay.show { display: flex !important; }
        .modal-overlay .modal-box { background: white; border-radius: 16px; padding: 32px; width: 90%; max-width: 480px; }
        .modal-title { font-size: 20px; font-weight: 600; margin-bottom: 24px; }
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; margin-bottom: 8px; font-weight: 500; color: #374151; }
        .form-control { width: 100%; padding: 12px 16px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: 14px; box-sizing: border-box; }
        .form-control:focus { outline: none; border-color: #3B82F6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        .modal-actions { display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px; }
        .alert { padding: 14px 20px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
        .alert-success { background: #D1FAE5; color: #065F46; }
        .alert-danger { background: #FEE2E2; color: #991B1B; }
    </style>
</head>
<body>
    <?php echo renderNav('products'); ?>
    
    <div class="page-title-bar">
        <div>
            <h1>产品单位管理</h1>
            <p style="color: #6B7280; margin-top: 4px;">管理计量单位，便于产品录入</p>
        </div>
        <button class="btn btn-primary" onclick="openAddModal()">➕ 添加单位</button>
    </div>
    
    <?php if ($msg): ?>
    <div class="alert alert-success"><?php echo h($msg); ?></div>
    <?php endif; ?>
    
    <div class="card">
        <div class="card-title">📏 单位列表（<?php echo count($units); ?> 个）</div>
        
        <?php if (empty($units)): ?>
        <div style="text-align: center; padding: 40px; color: #9CA3AF;">
            暂无单位，点击上方按钮添加
        </div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th style="width: 80px;">排序</th>
                    <th>单位名称</th>
                    <th style="width: 100px;">缩写</th>
                    <th style="width: 120px;">使用次数</th>
                    <th style="width: 180px;">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($units as $unit): ?>
                <tr data-id="<?php echo $unit['id']; ?>" data-name="<?php echo h($unit['name']); ?>" data-short="<?php echo h($unit['short_name']); ?>" data-sort="<?php echo $unit['sort_order']; ?>">
                    <td><?php echo $unit['sort_order']; ?></td>
                    <td style="font-weight: 500;"><?php echo h($unit['name']); ?></td>
                    <td><?php if ($unit['short_name']): ?><span class="badge badge-short"><?php echo h($unit['short_name']); ?></span><?php endif; ?></td>
                    <td><span class="badge"><?php echo $stats[$unit['name']] ?? 0; ?> 个产品</span></td>
                    <td class="actions">
                        <button class="btn btn-outline btn-sm" onclick="openEditModal(<?php echo $unit['id']; ?>)">编辑</button>
                        <button class="btn btn-danger btn-sm" onclick="deleteUnit(<?php echo $unit['id']; ?>, '<?php echo h($unit['name']); ?>')">删除</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    
    <!-- 添加/编辑弹窗 -->
    <div class="modal-overlay" id="modal">
        <div class="modal-box">
            <div class="modal-title" id="modalTitle">添加单位</div>
            <form id="unitForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="ajax_action" id="formAction" value="add">
                <input type="hidden" name="id" id="formId" value="">
                
                <div class="form-group">
                    <label class="form-label">单位名称</label>
                    <input type="text" name="name" id="formName" class="form-control" placeholder="如：平方米、个、米" required maxlength="20">
                </div>
                
                <div class="form-group">
                    <label class="form-label">缩写（可选）</label>
                    <input type="text" name="short_name" id="formShort" class="form-control" placeholder="如：㎡、个、M" maxlength="10">
                </div>
                
                <div class="form-group">
                    <label class="form-label">排序（数字越小越靠前）</label>
                    <input type="number" name="sort_order" id="formSort" class="form-control" value="0" min="0" max="999">
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="closeModal()">取消</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
    const csrfToken = '<?php echo $csrfToken; ?>';
    
    // 【2026-09-15】新增时排序自动填写为当前最大排序+1
    function nextSort() {
        let max = 0;
        document.querySelectorAll('tr[data-sort]').forEach(function(r) {
            const v = parseInt(r.dataset.sort) || 0;
            if (v > max) max = v;
        });
        return max + 1;
    }
    
    function openAddModal() {
        document.getElementById('modalTitle').textContent = '添加单位';
        document.getElementById('formAction').value = 'add';
        document.getElementById('formId').value = '';
        document.getElementById('formName').value = '';
        document.getElementById('formShort').value = '';
        document.getElementById('formSort').value = nextSort();
        document.getElementById('modal').classList.add('show');
    }
    
    function openEditModal(id) {
        const row = document.querySelector(`tr[data-id="${id}"]`);
        document.getElementById('modalTitle').textContent = '编辑单位';
        document.getElementById('formAction').value = 'edit';
        document.getElementById('formId').value = id;
        document.getElementById('formName').value = row.dataset.name;
        document.getElementById('formShort').value = row.dataset.short || '';
        document.getElementById('formSort').value = row.dataset.sort;
        document.getElementById('modal').classList.add('show');
    }
    
    function closeModal() {
        document.getElementById('modal').classList.remove('show');
    }
    
    document.getElementById('unitForm').onsubmit = async function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        
        try {
            const response = await fetch('units.php', { method: 'POST', body: formData });
            const result = await response.json();
            
            if (result.ok) {
                alert(result.msg);
                location.reload();
            } else {
                alert('错误: ' + result.msg);
            }
        } catch (err) {
            alert('请求失败: ' + err.message);
        }
    };
    
    // 【2026-09-15】统一删除请求处理：失败一律 alert("删除失败：原因")，
    // 服务器空响应/非 JSON（500、WAF 拦截）也给出明确中文提示，不再报英文解析错误
    function delRequest(url, fd, onOk) {
        fetch(url, { method: 'POST', body: fd })
            .then(function(r){
                return r.text().then(function(t){ return { status: r.status, text: t }; });
            })
            .then(function(res){
                var data = null;
                try { data = JSON.parse(res.text); } catch (e) { data = null; }
                if (data === null) {
                    alert('删除失败：服务器返回异常(HTTP ' + res.status + ')' + (res.text ? '\n' + res.text.substring(0, 150) : '\n（无响应内容，请联系管理员查看服务器错误日志）'));
                    return;
                }
                if (data.ok) { onOk(data); }
                else { alert('删除失败：' + (data.msg || '未知原因')); }
            })
            .catch(function(){ alert('删除失败：网络错误，请重试'); });
    }

    async function deleteUnit(id, name) {
        if (!confirm(`确定要删除单位"${name}"吗？`)) return;

        const formData = new FormData();
        formData.append('csrf_token', csrfToken);
        formData.append('ajax_action', 'delete');
        formData.append('id', id);

        delRequest('units.php', formData, function(data) {
            alert(data.msg || '单位删除成功');
            location.reload();
        });
    }
    
    document.getElementById('modal').onclick = function(e) {
        if (e.target === this) closeModal();
    };
    </script>
</body>
</html>
