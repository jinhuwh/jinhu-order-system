<?php
/**
 * 移动端 - 产品管理
 * 功能：添加 / 编辑 / 删除 / 搜索 / 分类折叠 / 累计销量统计
 */
require_once 'config.php';
initDatabase();
checkLogin();

$db = getDB();

// 确保会话 CSRF token 已初始化（与桌面端一致）
generateCsrfToken();

// CSRF 校验（移动端产品增删改）
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['add', 'delete', 'update'])) {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'msg' => '安全验证失败']);
        exit;
    }
}

// AJAX 添加产品
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add') {
    // 【2026-09-15 审计修复】对齐 PC 端：添加产品需要 product_create 权限
    if (!hasPermission(PERM_PRODUCT_CREATE)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'msg' => '无添加产品权限']);
        exit;
    }
    $stmt = $db->prepare("INSERT INTO products (name, category, unit, price) VALUES (?, ?, ?, ?)");
    $stmt->execute([
        htmlspecialchars(trim($_POST['name'] ?? '')),
        htmlspecialchars($_POST['category'] ?? ''),
        htmlspecialchars($_POST['unit'] ?? ''),
        floatval($_POST['price'] ?? 0)
    ]);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'msg' => '添加成功']);
    exit;
}

// AJAX 删除
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete') {
    // 【2026-09-15 审计修复】对齐 PC 端：删除产品需要 product_delete 权限
    if (!hasPermission(PERM_PRODUCT_DELETE)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'msg' => '无删除产品权限']);
        exit;
    }
    $id = intval($_POST['id'] ?? 0);
    if ($id > 0) {
        // 【2026-09-15 审计修复】对齐 PC 端：已有订单明细的产品不能删除（order_items 表只存 product_name 字符串）
        $nameStmt = $db->prepare("SELECT name FROM products WHERE id = ?");
        $nameStmt->execute([$id]);
        $productName = $nameStmt->fetchColumn();
        if ($productName) {
            $itemCount = $db->prepare("SELECT COUNT(*) FROM order_items WHERE product_name = ?");
            $itemCount->execute([$productName]);
            $cnt = (int)$itemCount->fetchColumn();
            if ($cnt > 0) {
                $recent = $db->prepare("SELECT DISTINCT o.order_no FROM order_items oi JOIN orders o ON oi.order_id = o.id WHERE oi.product_name = ? ORDER BY oi.order_id DESC LIMIT 3");
                $recent->execute([$productName]);
                $samples = array_column($recent->fetchAll(PDO::FETCH_ASSOC), 'order_no');
                $sampleStr = implode(', ', $samples);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'msg' => "该产品已被 {$cnt} 个订单明细引用（如订单 {$sampleStr}），不能删除。如需停用，请联系管理员禁用该产品。"]);
                exit;
            }
        }
        $db->prepare("DELETE FROM products WHERE id = ?")->execute([$id]);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'msg' => '已删除']);
    exit;
}

// AJAX 更新
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update') {
    // 【2026-09-15 审计修复】对齐 PC 端：修改产品需要 product_edit 权限
    if (!hasPermission(PERM_PRODUCT_EDIT)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'msg' => '无修改产品权限']);
        exit;
    }
    $id = intval($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if ($id <= 0 || empty($name)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'msg' => '参数错误']);
        exit;
    }
    $stmt = $db->prepare("UPDATE products SET name = ?, category = ?, unit = ?, price = ? WHERE id = ?");
    $stmt->execute([
        htmlspecialchars($name),
        htmlspecialchars($_POST['category'] ?? ''),
        htmlspecialchars($_POST['unit'] ?? ''),
        floatval($_POST['price'] ?? 0),
        $id
    ]);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'msg' => '修改成功']);
    exit;
}

// 搜索 & 分类筛选
$search = trim($_GET['q'] ?? '');
$filterCat = trim($_GET['cat'] ?? '');

$where = "1=1";
$params = [];
if ($search !== '') {
    $where .= " AND (p.name LIKE ? OR p.category LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($filterCat !== '') {
    $where .= " AND p.category = ?";
    $params[] = $filterCat;
}

$sql = "SELECT p.* FROM products p WHERE $where ORDER BY p.category, p.name";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 所有分类（用于筛选）
$allCats = $db->query("SELECT DISTINCT category FROM products WHERE category != '' ORDER BY category")->fetchAll(PDO::FETCH_ASSOC);

// 使用统计
$usageMap = [];
$uStmt = $db->query("
    SELECT oi.product_name,
           COUNT(DISTINCT oi.order_id) as order_count,
           SUM(oi.quantity) as total_qty,
           SUM(oi.quantity * oi.unit_price) as total_revenue
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    WHERE oi.product_name IS NOT NULL AND oi.product_name != '' AND o.status != 4
    GROUP BY oi.product_name
");
while ($row = $uStmt->fetch(PDO::FETCH_ASSOC)) {
    $usageMap[$row['product_name']] = $row;
}

// 按分类分组
$grouped = [];
foreach ($products as $p) {
    $cat = $p['category'] ?: '未分类';
    if (!isset($grouped[$cat])) $grouped[$cat] = [];
    $p['_u'] = $usageMap[$p['name']] ?? null;
    $grouped[$cat][] = $p;
}

$companyName = getSetting('company_name') ?: '广告公司';
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>产品管理 - <?php echo htmlspecialchars($companyName); ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="page-header">
        <h1>📦 产品管理</h1>
    </div>

    <div class="page">
        <!-- 搜索 & 筛选 -->
        <form method="GET" style="display:flex;gap:8px;margin-bottom:14px;">
            <input type="text" name="q" class="form-control" placeholder="🔍 搜索产品名称/分类" value="<?php echo htmlspecialchars($search); ?>" style="flex:1;">
            <button type="submit" class="btn btn-primary" style="flex-shrink:0;">搜索</button>
        </form>

        <?php if ($search || $filterCat): ?>
        <div style="margin-bottom:10px;">
            <span style="font-size:12px;color:var(--gray-400);">
                找到 <strong style="color:var(--primary);"><?php echo count($products); ?></strong> 个产品
            </span>
            <a href="products.php" class="btn btn-ghost btn-sm" style="margin-left:8px;">✕ 清除筛选</a>
        </div>
        <?php endif; ?>

        <!-- 分类筛选标签 -->
        <?php if (!empty($allCats)): ?>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;">
            <a href="products.php?q=<?php echo urlencode($search); ?>" class="badge <?php echo !$filterCat ? 'badge-primary' : 'badge-gray'; ?>" style="font-size:12px;padding:4px 10px;">全部</a>
            <?php foreach ($allCats as $c): ?>
            <a href="?q=<?php echo urlencode($search); ?>&cat=<?php echo urlencode($c['category']); ?>"
               class="badge <?php echo $filterCat === $c['category'] ? 'badge-primary' : 'badge-gray'; ?>"
               style="font-size:12px;padding:4px 10px;">
               <?php echo htmlspecialchars($c['category']); ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- 添加产品 -->
        <div class="card" style="margin-bottom:14px;">
            <div class="card-title" style="font-size:14px;">➕ 添加新产品</div>
            <form id="addForm">
                <div class="form-group" style="margin-bottom:10px;">
                    <input type="text" name="name" class="form-control" placeholder="产品名称 *" required id="f_name" style="font-size:14px;">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                    <div class="form-group" style="margin:0;">
                        <input type="text" name="category" class="form-control" placeholder="分类" id="f_cat" style="font-size:14px;">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <input type="text" name="unit" class="form-control" placeholder="单位" id="f_unit" style="font-size:14px;">
                    </div>
                </div>
                <div style="display:flex;gap:10px;align-items:center;">
                    <input type="number" name="price" step="0.01" class="form-control" placeholder="参考单价" id="f_price" style="font-size:14px;flex:1;">
                    <button type="submit" class="btn btn-success" id="btnAdd" style="flex-shrink:0;padding:10px 16px;">添加</button>
                </div>
            </form>
        </div>

        <!-- 产品列表（按分类折叠） -->
        <?php if (empty($grouped)): ?>
        <div class="empty-state">
            <div class="icon">📦</div>
            <div class="text">暂无产品</div>
        </div>
        <?php else: ?>
            <?php foreach ($grouped as $cat => $items): ?>
            <?php
                $catOrders = 0; $catRevenue = 0;
                foreach ($items as $p) {
                    $u = $p['_u'];
                    if ($u) { $catOrders += intval($u['order_count']); $catRevenue += floatval($u['total_revenue']); }
                }
            ?>
            <div class="cat-group-m" id="g_<?php echo md5($cat); ?>">
                <div class="cat-header-m" onclick="toggleCat('<?php echo md5($cat); ?>')">
                    <div style="display:flex;align-items:center;gap:8px;flex:1;min-width:0;">
                        <span class="cat-arrow" id="arr_<?php echo md5($cat); ?>">▼</span>
                        <span style="font-weight:600;font-size:14px;color:var(--gray-700);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($cat); ?></span>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">
                        <span class="badge badge-gray" style="font-size:11px;"><?php echo count($items); ?></span>
                        <?php if ($catOrders > 0): ?>
                        <span style="font-size:11px;color:#059669;font-weight:600;">¥<?php echo number_format($catRevenue, 0); ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="cat-body-m" id="body_<?php echo md5($cat); ?>">
                    <?php foreach ($items as $p):
                        $u = $p['_u'];
                    ?>
                    <div class="product-card-m" id="card_<?php echo $p['id']; ?>">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:4px;">
                            <div style="font-weight:600;font-size:15px;color:var(--gray-800);flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <?php echo htmlspecialchars($p['name']); ?>
                            </div>
                            <div style="font-weight:750;color:var(--primary);font-size:16px;flex-shrink:0;">
                                <?php echo $p['price'] ? '¥' . number_format($p['price'], 2) : '-'; ?>
                            </div>
                        </div>
                        <div style="font-size:12px;color:var(--gray-400);margin-bottom:4px;">
                            <?php if ($p['unit']): ?><span>📏 <?php echo htmlspecialchars($p['unit']); ?></span><?php endif; ?>
                            <?php if ($p['category'] && $p['category'] !== $cat): ?><span style="margin-left:6px;">📁 <?php echo htmlspecialchars($p['category']); ?></span><?php endif; ?>
                        </div>
                        <?php if ($u): ?>
                        <div style="font-size:12px;color:var(--gray-500);margin-bottom:8px;">
                            <span style="color:var(--primary);font-weight:600;"><?php echo $u['order_count']; ?> 单</span>
                            · 销量 <?php echo number_format(intval($u['total_qty'])); ?>
                            · 销售额 <span style="color:#059669;font-weight:600;">¥<?php echo number_format($u['total_revenue'], 2); ?></span>
                        </div>
                        <?php endif; ?>
                        <div style="display:flex;gap:6px;">
                            <a href="javascript:void(0)" class="btn btn-primary btn-sm" style="flex:1;text-align:center;"
                               onclick="editProduct(<?php echo $p['id']; ?>, '<?php echo htmlspecialchars(addslashes($p['name'])); ?>', '<?php echo htmlspecialchars(addslashes($p['category'])); ?>', '<?php echo htmlspecialchars(addslashes($p['unit'])); ?>', '<?php echo $p['price']; ?>')">
                               ✏️ 编辑
                            </a>
                            <a href="javascript:void(0)" class="btn btn-danger btn-sm" onclick="deleteProduct(<?php echo $p['id']; ?>, '<?php echo htmlspecialchars(addslashes($p['name'])); ?>')" style="padding:8px 12px;">🗑️</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- 编辑弹窗 -->
    <div id="editModal" class="modal-overlay">
        <div class="modal-sheet">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;">
                <h3 style="font-size:17px;font-weight:700;">✏️ 编辑产品</h3>
                <a href="javascript:void(0)" onclick="closeModal()" style="font-size:28px;color:var(--gray-400);text-decoration:none;line-height:1;">×</a>
            </div>
            <input type="hidden" id="edit_id">
            <div class="form-group">
                <label style="font-size:13px;font-weight:500;color:var(--gray-600);margin-bottom:4px;display:block;">产品名称 *</label>
                <input type="text" id="edit_name" class="form-control" style="font-size:15px;">
            </div>
            <div class="form-group">
                <label style="font-size:13px;font-weight:500;color:var(--gray-600);margin-bottom:4px;display:block;">分类</label>
                <input type="text" id="edit_category" class="form-control" style="font-size:15px;">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div class="form-group">
                    <label style="font-size:13px;font-weight:500;color:var(--gray-600);margin-bottom:4px;display:block;">单位</label>
                    <input type="text" id="edit_unit" class="form-control" style="font-size:15px;">
                </div>
                <div class="form-group">
                    <label style="font-size:13px;font-weight:500;color:var(--gray-600);margin-bottom:4px;display:block;">参考单价</label>
                    <input type="number" id="edit_price" step="0.01" class="form-control" style="font-size:15px;">
                </div>
            </div>
            <button class="btn btn-primary btn-block btn-lg" onclick="saveProduct()" style="margin-top:6px;">保存修改</button>
        </div>
    </div>

    <script>
    var csrfToken = '<?php echo $csrfToken; ?>';

    // 【2026-09-15】统一弹窗：确认 -> 提交 -> 结果提示
    function deleteProduct(id, name) {
        mdlgConfirm('确定要删除产品「' + name + '」吗？\n删除后不可恢复。', function() {
            var fd = new FormData();
            fd.append('action', 'delete');
            fd.append('csrf_token', csrfToken);
            fd.append('id', id);
            fetch('products.php', { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(d) {
                    if (d.ok) {
                        mdlgAlert(d.msg || '产品已删除', true, function(){ location.reload(); }, 800);
                    } else {
                        // 删除失败时必须给出原因（订单引用 / 权限不足等）
                        mdlgAlert(d.msg || '删除失败', false);
                    }
                })
                .catch(function() { mdlgAlert('网络错误，删除失败', false); });
        }, { title: '删除产品' });
    }

    // 添加产品
    document.getElementById('addForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var btn = document.getElementById('btnAdd');
        btn.disabled = true;
        btn.textContent = '添加中...';
        var fd = new FormData();
        fd.append('action', 'add');
        fd.append('csrf_token', csrfToken);
        fd.append('name', document.getElementById('f_name').value.trim());
        fd.append('category', document.getElementById('f_cat').value);
        fd.append('unit', document.getElementById('f_unit').value);
        fd.append('price', document.getElementById('f_price').value);
        fetch('products.php', { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(d) {
                btn.disabled = false;
                btn.textContent = '添加';
                if (d.ok) {
                    document.getElementById('f_name').value = '';
                    document.getElementById('f_cat').value = '';
                    document.getElementById('f_unit').value = '';
                    document.getElementById('f_price').value = '';
                    mdlgAlert(d.msg || '添加成功', true, function(){ location.reload(); }, 800);
                } else {
                    mdlgAlert(d.msg || '添加失败', false);
                }
            })
            .catch(function() {
                btn.disabled = false;
                btn.textContent = '添加';
                mdlgAlert('网络错误', false);
            });
    });

    function editProduct(id, name, category, unit, price) {
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_name').value = name;
        document.getElementById('edit_category').value = category;
        document.getElementById('edit_unit').value = unit;
        document.getElementById('edit_price').value = price;
        document.getElementById('editModal').classList.add('show');
    }

    function closeModal() {
        document.getElementById('editModal').classList.remove('show');
    }

    function saveProduct() {
        var name = document.getElementById('edit_name').value.trim();
        if (!name) { mdlgAlert('产品名称不能为空', false); return; }
        var fd = new FormData();
        fd.append('action', 'update');
        fd.append('csrf_token', csrfToken);
        fd.append('id', document.getElementById('edit_id').value);
        fd.append('name', name);
        fd.append('category', document.getElementById('edit_category').value);
        fd.append('unit', document.getElementById('edit_unit').value);
        fd.append('price', document.getElementById('edit_price').value);
        fetch('products.php', { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(d) {
                if (d.ok) {
                    closeModal();
                    mdlgAlert(d.msg || '修改成功', true, function(){ location.reload(); }, 800);
                } else {
                    mdlgAlert(d.msg || '修改失败', false);
                }
            })
            .catch(function() { mdlgAlert('网络错误', false); });
    }

    // 遮罩关闭
    document.getElementById('editModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    // 分类折叠
    function toggleCat(hash) {
        var body = document.getElementById('body_' + hash);
        var arrow = document.getElementById('arr_' + hash);
        if (!body || !arrow) return;
        if (body.style.display === 'none') {
            body.style.display = '';
            arrow.textContent = '▼';
        } else {
            body.style.display = 'none';
            arrow.textContent = '▶';
        }
    }
    </script>

    <style>
    .cat-group-m {
        margin-bottom: 10px;
        border-radius: var(--radius-lg);
        overflow: hidden;
        background: white;
        box-shadow: var(--shadow-card);
    }
    .cat-header-m {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 14px;
        background: var(--gray-50);
        border-bottom: 1px solid var(--gray-100);
        cursor: pointer;
        user-select: none;
    }
    .cat-header-m:active { background: var(--gray-100); }
    .cat-arrow { font-size: 10px; color: var(--gray-400); width: 14px; text-align: center; }
    .cat-body-m { }
    .product-card-m {
        padding: 14px;
        border-bottom: 1px solid var(--gray-100);
    }
    .product-card-m:last-child { border-bottom: none; }
    .badge-gray {
        background: var(--gray-100);
        color: var(--gray-500);
    }
    .msg { margin-bottom: 10px; }
    </style>

    <?php echo mobileDialog(); ?>

    <?php echo mobileNav(''); ?>
</body>
</html>
