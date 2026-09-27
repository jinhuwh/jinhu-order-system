<?php
// 【2026-09-09 修复】opcache 强制清理, 防止缓存导致编辑保存无反应
if (function_exists('opcache_invalidate')) { @opcache_invalidate(__FILE__, true); }
require_once 'config.php';
initDatabase();
checkLogin();

$db = getDB();

// 导入产品 - 处理上传（必须在 AJAX 添加之前判断，避免被误判为添加产品）
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

    // 修复字符集冲突
    try {
        $db->exec("ALTER TABLE products MODIFY name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    } catch (Exception $e) {}

    $file = $_FILES['csv_file']['tmp_name'];
    $content = file_get_contents($file);

    // 移除 UTF-8 BOM
    if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
        $content = substr($content, 3);
    }

    // 自动检测并转码为 UTF-8
    if (!mb_check_encoding($content, 'UTF-8')) {
        $converted = @mb_convert_encoding($content, 'UTF-8', 'GBK');
        if ($converted && mb_check_encoding($converted, 'UTF-8')) {
            $content = $converted;
        } else {
            $converted = @mb_convert_encoding($content, 'UTF-8', 'GB2312');
            if ($converted && mb_check_encoding($converted, 'UTF-8')) {
                $content = $converted;
            }
        }
    }

    $tempFile = tempnam(sys_get_temp_dir(), 'csv_import_');
    file_put_contents($tempFile, $content);
    $handle = fopen($tempFile, 'r');
    fgetcsv($handle); // 跳过表头

    $added = 0;
    $updated = 0;
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < 1) continue;
        $name = trim($row[0] ?? '');
        if (empty($name)) continue;
        $category = $row[1] ?? '';
        $unit = $row[2] ?? '';
        $price = ($row[3] ?? '') !== '' ? floatval($row[3]) : null;

        $check = $db->prepare("SELECT id FROM products WHERE name = ?");
        $check->execute([$name]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $db->prepare("UPDATE products SET category = ?, unit = ?, price = ? WHERE id = ?")->execute([$category, $unit, $price, $existing['id']]);
            // 【2026-09-09 修复】CSV 导入路径也同步分类字典
            if ($category !== '') {
                $chkCsv = $db->prepare("SELECT id FROM product_categories WHERE name = ?");
                $chkCsv->execute([$category]);
                if (!$chkCsv->fetchColumn()) {
                    try {
                        $db->prepare("INSERT INTO product_categories (name, sort_order) VALUES (?, 99)")->execute([$category]);
                    } catch (Exception $e) { /* ignore */ }
                }
            }
            $updated++;
        } else {
            $db->prepare("INSERT INTO products (name, category, unit, price) VALUES (?, ?, ?, ?)")->execute([$name, $category, $unit, $price]);
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

// AJAX 添加产品（排除 do_import/delete/update，只对添加操作触发）
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_GET['edit']) && !isset($_GET['delete']) && !in_array($_POST['action'] ?? '', ['do_import', 'delete', 'update'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode(['ok' => false, 'msg' => '非法请求']);
        exit;
    }
    $name = trim($_POST['name'] ?? '');
    if (empty($name)) {
        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode(['ok' => false, 'msg' => '产品名称不能为空']);
        exit;
    }
    $stmt = $db->prepare("INSERT INTO products (name, category, unit, price) VALUES (?, ?, ?, ?)");
    $stmt->execute([
        $name,
        $_POST['category'] ?? '',
        $_POST['unit'] ?? '',
        floatval($_POST['price'] ?? 0) ?: null
    ]);
    // 【2026-09-09 修复】自动同步分类字典, 避免产品里出现字典表里没有的"野分类"
    $autoCat = trim($_POST['category'] ?? '');
    if ($autoCat !== '') {
        $chkCat = $db->prepare("SELECT id FROM product_categories WHERE name = ?");
        $chkCat->execute([$autoCat]);
        if (!$chkCat->fetchColumn()) {
            try {
                $db->prepare("INSERT INTO product_categories (name, sort_order) VALUES (?, 99)")->execute([$autoCat]);
            } catch (Exception $e) { /* 重复 INSERT 不影响主流程 */ }
        }
    }
    // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
    while (ob_get_level() > 0) { ob_end_clean(); }
    echo json_encode(['ok' => true, 'msg' => '添加成功', 'id' => $db->lastInsertId()]);
    exit;
}

// AJAX 删除产品
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode(['ok' => false, 'msg' => '非法请求']);
        exit;
    }
    // 【2026-09-08 安全加固】删除产品需要 PERM_PRODUCT_DELETE 权限
    if (!hasPermission(PERM_PRODUCT_DELETE)) {
        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode(['ok' => false, 'msg' => '权限不足，无法删除产品']);
        exit;
    }
    $deleteId = intval($_POST['id'] ?? 0);
    try {
    if ($deleteId > 0) {
        // 【2026-09-08 修复】已有订单明细的产品不能删除（order_items 表只存 product_name 字符串）
        $nameStmt = $db->prepare("SELECT name FROM products WHERE id = ?");
        $nameStmt->execute([$deleteId]);
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
                // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
                while (ob_get_level() > 0) { ob_end_clean(); }
                echo json_encode(['ok' => false, 'msg' => "该产品已被 $cnt 个订单明细引用（如订单 {$sampleStr}），不能删除。如需停用，请联系管理员禁用该产品。"]);
                exit;
            }
        }
        $db->prepare("DELETE FROM products WHERE id = ?")->execute([$deleteId]);
    }
    // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
    while (ob_get_level() > 0) { ob_end_clean(); }
    echo json_encode(['ok' => true, 'msg' => '已删除']);
    } catch (Exception $e) {
        // 【2026-09-15】任何数据库异常都返回 JSON，杜绝 500 空响应
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode(['ok' => false, 'msg' => '操作失败：' . $e->getMessage()]);
    }
    exit;
}

// AJAX 更新产品
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update') {
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode(['ok' => false, 'msg' => '非法请求']);
        exit;
    }
    $editId = intval($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if ($editId <= 0 || empty($name)) {
        // 【2026-09-09 修复】清空输出缓冲, 防止 PHP 警告/HTML 污染 JSON
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode(['ok' => false, 'msg' => '参数错误']);
        exit;
    }
    $stmt = $db->prepare("UPDATE products SET name = ?, category = ?, unit = ?, price = ? WHERE id = ?");
    $stmt->execute([
        $name,
        $_POST['category'] ?? '',
        $_POST['unit'] ?? '',
        floatval($_POST['price'] ?? 0) ?: null,
        $editId
    ]);
    // 【2026-09-09 修复】清空输出缓冲防止 PHP 警告/通知混入 JSON 响应
    while (ob_get_level() > 0) { ob_end_clean(); }
    echo json_encode(['ok' => true, 'msg' => '修改成功']);
    exit;
}

// 导出产品
if (isset($_GET['action']) && $_GET['action'] == 'export') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="产品列表_' . date('Y-m-d') . '.csv"');

    // 添加 BOM 防止 Excel 乱码
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');
    fputcsv($output, ['产品名称', '分类', '单位', '参考单价']);

    $stmt = $db->query("SELECT name, category, unit, price FROM products ORDER BY category, name");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

// 导入产品 - 显示上传表单
$showImport = isset($_GET['action']) && $_GET['action'] == 'import';

// 获取所有分类(用于筛选下拉) - 合并数据库分类表和产品表中的分类
$dbCategories = [];
try {
    $stmt = $db->query("SELECT name FROM product_categories ORDER BY sort_order, name");
    $dbCategories = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
} catch (Exception $e) {}
$allCategories = $db->query("SELECT DISTINCT category FROM products WHERE category != '' ORDER BY category")->fetchAll(PDO::FETCH_ASSOC);
$productCategories = array_column($allCategories, 'category');
$categories = array_unique(array_merge($dbCategories, $productCategories));

// 获取所有单位(用于下拉)
$allUnits = [];
try {
    $stmt = $db->query("SELECT name, short_name FROM product_units ORDER BY sort_order, name");
    $allUnits = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// 搜索和筛选
$search = trim($_GET['q'] ?? '');
$filterCategory = trim($_GET['cat'] ?? '');
$sortBy = $_GET['sort'] ?? 'name'; // name | price | usage | revenue

$where = "1=1";
$params = [];
if ($search !== '') {
    $where .= " AND (p.name LIKE ? OR p.category LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($filterCategory !== '') {
    $where .= " AND p.category = ?";
    $params[] = $filterCategory;
}

$productsStmt = $db->prepare("SELECT p.* FROM products p WHERE $where ORDER BY p.category, p.name");
$productsStmt->execute($params);
$products = $productsStmt->fetchAll(PDO::FETCH_ASSOC);

// 读取使用统计(按产品名称聚合)
$usageMap = [];
$usageStmt = $db->query("
    SELECT oi.product_name,
           COUNT(DISTINCT oi.order_id) as order_count,
           SUM(oi.quantity) as total_qty,
           SUM(oi.quantity * oi.unit_price) as total_revenue
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    WHERE oi.product_name IS NOT NULL AND oi.product_name != '' AND o.status != 4
    GROUP BY oi.product_name
");
while ($row = $usageStmt->fetch(PDO::FETCH_ASSOC)) {
    $usageMap[$row['product_name']] = $row;
}

// 按分类分组
$grouped = [];
foreach ($products as $p) {
    $cat = $p['category'] ?: '未分类';
    if (!isset($grouped[$cat])) $grouped[$cat] = [];
    $p['_usage'] = $usageMap[$p['name']] ?? null;
    $grouped[$cat][] = $p;
}

// 按销量/金额排序(组内排序)
$sortFn = function($a, $b) use ($sortBy) {
    $ua = $a['_usage'];
    $ub = $b['_usage'];
    if ($sortBy === 'usage') {
        $va = $ua ? intval($ua['order_count']) : 0;
        $vb = $ub ? intval($ub['order_count']) : 0;
    } elseif ($sortBy === 'revenue') {
        $va = $ua ? floatval($ua['total_revenue']) : 0;
        $vb = $ub ? floatval($ub['total_revenue']) : 0;
    } elseif ($sortBy === 'price') {
        $va = floatval($a['price'] ?? 0);
        $vb = floatval($b['price'] ?? 0);
    } else {
        return strcmp($a['name'], $b['name']);
    }
    return $vb <=> $va; // 降序
};

foreach ($grouped as $cat => $items) {
    usort($grouped[$cat], $sortFn);
}

$totalProducts = count($products);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>产品管理 - <?php echo SITE_TITLE; ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php echo renderNav('products'); ?>

    <div class="page-title-bar">
        <div class="page-title-left">
            <h1>产品管理</h1>
            <p>管理产品信息,支持添加、编辑、删除产品</p>
        </div>
    </div>

    <div id="msg-area"></div>

    <!-- 添加新产品 -->
    <div class="card">
        <div class="card-header">
            <div class="card-title"><span class="title-icon">➕</span> 添加新产品</div>
        </div>
        <div class="card-body">
            <form id="addForm" class="form-row form-row-4" onsubmit="return addProduct(event)">
                <div class="form-group">
                    <label class="form-label">产品名称 <span class="required">*</span></label>
                    <input type="text" name="name" class="form-control" placeholder="产品名称" required id="f_name">
                </div>
                <div class="form-group">
                    <label class="form-label">分类</label>
                    <input type="text" name="category" class="form-control" placeholder="选择或输入分类" list="categories" id="f_category" autocomplete="off">
                    <datalist id="categories">
                        <?php foreach ($categories as $c): ?>
                        <option value="<?php echo htmlspecialchars($c); ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="form-group">
                    <label class="form-label">单位</label>
                    <input type="text" name="unit" class="form-control" placeholder="选择或输入单位" list="units" id="f_unit" autocomplete="off">
                    <datalist id="units">
                        <?php foreach ($allUnits as $u): ?>
                        <option value="<?php echo htmlspecialchars($u['name']); ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="form-group">
                    <label class="form-label">参考单价</label>
                    <input type="number" name="price" class="form-control" placeholder="参考单价" step="0.01" id="f_price">
                </div>
                <div class="form-group" style="grid-column: 1 / -1;">
                    <button type="submit" class="btn btn-success" id="btnAdd">添加产品</button>
                    <a href="categories.php" class="btn btn-outline btn-sm" style="margin-left:8px;">📁 管理分类</a>
                    <a href="units.php" class="btn btn-outline btn-sm">📏 管理单位</a>
                </div>
            </form>
        </div>
    </div>

    <!-- 产品列表(按分类折叠) -->
    <div class="card">
        <div class="card-header">
            <div class="card-title"><span class="title-icon">📦</span> 产品列表
                <span style="font-size:13px;font-weight:400;color:var(--gray-400);margin-left:8px;">共 <?php echo $totalProducts; ?> 个产品</span>
            </div>
            <div style="display:flex;gap:12px;align-items:center;">
                <a href="products.php?action=export" class="btn btn-success btn-sm">📥 导出</a>
                <a href="products.php?action=import" class="btn btn-primary btn-sm">📤 导入</a>
            </div>
        </div>

        <?php if ($showImport): ?>
        <div class="card-body" style="border-bottom:1px solid var(--gray-200);">
            <h3 style="margin-bottom:16px;">导入产品</h3>
            <form id="importForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="do_import">
                <input type="hidden" name="csrf_token" id="importCsrfToken">
                <div class="form-row form-row-4">
                    <div class="form-group">
                        <label class="form-label">选择CSV文件</label>
                        <input type="file" name="csv_file" id="importFile" accept=".csv" class="form-control" required>
                        <small style="color:var(--gray-500);margin-top:4px;display:block;">CSV格式:产品名称,分类,单位,参考单价(可用Excel另存为CSV)</small>
                    </div>
                    <div class="form-group" style="align-self:flex-end;">
                        <button type="submit" id="btnImport" class="btn btn-success">开始导入</button>
                        <a href="products.php" class="btn btn-ghost">取消</a>
                    </div>
                </div>
            </form>
            <div id="importMsg" style="margin-top:10px;"></div>
        </div>
        <?php endif; ?>

        <!-- 搜索 & 筛选工具栏 -->
        <div class="card-body" style="padding-bottom:0;">
            <form method="GET" id="filterForm" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                <div class="form-group" style="margin:0;flex:1;min-width:180px;">
                    <label class="form-label" style="font-size:12px;">🔍 搜索</label>
                    <input type="text" name="q" class="form-control" placeholder="产品名称 / 分类" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="form-group" style="margin:0;min-width:140px;">
                    <label class="form-label" style="font-size:12px;">📂 分类</label>
                    <select name="cat" class="form-control" onchange="document.getElementById('filterForm').submit()">
                        <option value="">全部分类</option>
                        <?php foreach ($categories as $c): ?>
                        <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $filterCategory === $c ? 'selected' : ''; ?>><?php echo htmlspecialchars($c); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0;min-width:130px;">
                    <label class="form-label" style="font-size:12px;">📊 排序</label>
                    <select name="sort" class="form-control" onchange="document.getElementById('filterForm').submit()">
                        <option value="name" <?php echo $sortBy === 'name' ? 'selected' : ''; ?>>按名称</option>
                        <option value="price" <?php echo $sortBy === 'price' ? 'selected' : ''; ?>>按单价</option>
                        <option value="usage" <?php echo $sortBy === 'usage' ? 'selected' : ''; ?>>按订单数</option>
                        <option value="revenue" <?php echo $sortBy === 'revenue' ? 'selected' : ''; ?>>按销售额</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-bottom:1px;">筛选</button>
                <?php if ($search || $filterCategory || $sortBy !== 'name'): ?>
                <a href="products.php" class="btn btn-ghost" style="margin-bottom:1px;">重置</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="card-body" style="padding-top:0;">
            <?php if (empty($grouped)): ?>
            <div class="empty-state">
                <div class="icon">📦</div>
                <div class="text">暂无产品</div>
            </div>
            <?php else: ?>
            <?php foreach ($grouped as $cat => $items): ?>
            <?php
                $catUsage = 0; $catRevenue = 0; $catOrders = 0;
                foreach ($items as $p) {
                    $u = $p['_usage'];
                    if ($u) { $catOrders += intval($u['order_count']); $catUsage += intval($u['total_qty']); $catRevenue += floatval($u['total_revenue']); }
                }
            ?>
            <div class="cat-group" id="cat_<?php echo md5($cat); ?>">
                <!-- 分类头(可折叠) -->
                <div class="cat-header" onclick="toggleCat('<?php echo md5($cat); ?>')">
                    <div style="display:flex;align-items:center;gap:8px;flex:1;">
                        <span class="cat-toggle" id="toggle_<?php echo md5($cat); ?>">▼</span>
                        <span class="cat-name"><?php echo htmlspecialchars($cat); ?></span>
                        <span class="badge badge-primary" style="font-size:11px;"><?php echo count($items); ?> 个</span>
                    </div>
                    <div style="display:flex;gap:16px;font-size:12px;color:var(--gray-400);">
                        <?php if ($catOrders > 0): ?>
                        <span><?php echo $catOrders; ?> 单</span>
                        <span>¥<?php echo number_format($catRevenue, 2); ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 分类下的产品表格 -->
                <div class="cat-body" id="body_<?php echo md5($cat); ?>">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>产品名称</th>
                                <th>单位</th>
                                <th>参考单价</th>
                                <th>订单数</th>
                                <th>累计销量</th>
                                <th>累计销售额</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $p):
                                $u = $p['_usage'];
                            ?>
                            <tr id="row_<?php echo $p['id']; ?>">
                                <td style="font-weight:600;"><?php echo htmlspecialchars($p['name']); ?></td>
                                <td><?php echo htmlspecialchars($p['unit'] ?: '-'); ?></td>
                                <td class="td-money"><?php echo $p['price'] ? formatMoney($p['price']) : '-'; ?></td>
                                <td style="text-align:center;color:var(--primary);font-weight:600;">
                                    <?php echo $u ? $u['order_count'] . ' 单' : '-'; ?>
                                </td>
                                <td style="text-align:center;">
                                    <?php echo $u && $u['total_qty'] ? number_format(intval($u['total_qty'])) : '-'; ?>
                                </td>
                                <td class="td-money" style="color:#059669;font-weight:600;">
                                    <?php echo $u && $u['total_revenue'] > 0 ? formatMoney($u['total_revenue']) : '-'; ?>
                                </td>
                                <td style="white-space:nowrap;">
                                    <button class="btn btn-primary btn-sm" onclick="editProduct(<?php echo $p['id']; ?>)">编辑</button>
                                    <button class="btn btn-danger btn-sm" onclick="deleteProduct(<?php echo $p['id']; ?>, this)" data-refs="<?php echo $u ? intval($u['order_count']) : 0; ?>" data-pname="<?php echo htmlspecialchars($p['name']); ?>" style="margin-left:6px;" title="<?php echo $u && intval($u['order_count']) > 0 ? '该产品已被 ' . intval($u['order_count']) . ' 个订单引用，不能删除' : ''; ?>">删除</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 分类汇总栏 -->
                <?php if ($catOrders > 0): ?>
                <div class="cat-summary">
                    <span>本分类小计:</span>
                    <span><strong><?php echo $catOrders; ?></strong> 个订单</span>
                    <span><strong><?php echo number_format($catUsage); ?></strong> 件销量</span>
                    <span style="color:#059669;"><strong>¥<?php echo number_format($catRevenue, 2); ?></strong> 销售额</span>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <!-- 全局汇总 -->
            <?php
                $totalOrders = 0; $totalRevenue = 0; $totalQty = 0;
                foreach ($usageMap as $u) { $totalOrders += intval($u['order_count']); $totalQty += intval($u['total_qty']); $totalRevenue += floatval($u['total_revenue']); }
            ?>
            <?php if ($totalOrders > 0): ?>
            <div class="total-bar" style="margin-top:14px;padding:12px 16px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;">
                <span style="font-size:13px;color:#0369a1;">📊 全局汇总:</span>
                <span style="font-size:13px;"><strong><?php echo $totalOrders; ?></strong> 个订单</span>
                <span style="font-size:13px;"><strong><?php echo number_format($totalQty); ?></strong> 件销量</span>
                <span style="font-size:13px;color:#059669;"><strong>¥<?php echo number_format($totalRevenue, 2); ?></strong> 销售额</span>
                <span style="font-size:13px;color:var(--gray-400);">(含已取消订单外的全部历史数据)</span>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php echo renderFooter(); ?>

    <!-- 编辑弹窗 -->
    <div id="editModal" class="modal-overlay">
        <div class="modal-sheet">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;">
                <h3 style="font-size:17px;font-weight:700;">✏️ 编辑产品</h3>
                <a href="javascript:void(0)" onclick="closeModal()" style="font-size:28px;color:var(--gray-400);text-decoration:none;line-height:1;">×</a>
            </div>
            <input type="hidden" id="edit_id">
            <div class="form-group">
                <label class="form-label">产品名称 <span class="required">*</span></label>
                <input type="text" id="edit_name" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">分类</label>
                <input type="text" id="edit_category" class="form-control" list="categories">
            </div>
            <div class="form-row form-row-2">
                <div class="form-group">
                    <label class="form-label">单位</label>
                    <input type="text" id="edit_unit" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">参考单价</label>
                    <input type="number" id="edit_price" class="form-control" step="0.01">
                </div>
            </div>
            <button class="btn btn-primary btn-block btn-lg" onclick="saveProduct()" style="margin-top:4px;">保存修改</button>
        </div>
    </div>

    <script>
    var csrfToken = '<?php echo generateCsrfToken(); ?>';
    if (document.getElementById('importCsrfToken')) {
        document.getElementById('importCsrfToken').value = csrfToken;
    }

    function showMsg(text, ok) {
        // 【2026-09-09 修复】兼容模态框: 如果 editModal 打开, 提示写到 modal 顶部 + alert 兜底
        var modal = document.getElementById('editModal');
        var alertClass = 'alert alert-' + (ok ? 'success' : 'danger');
        if (modal && modal.classList.contains('show')) {
            // 模态框内顶部显示
            var sheet = modal.querySelector('.modal-sheet');
            if (sheet) {
                var old = sheet.querySelector('.modal-msg');
                if (old) old.remove();
                var div = document.createElement('div');
                div.className = 'modal-msg ' + alertClass;
                div.style.cssText = 'margin-bottom:14px;';
                div.textContent = text;
                sheet.insertBefore(div, sheet.firstChild);
                setTimeout(function(){ var d = sheet.querySelector('.modal-msg'); if (d) d.remove(); }, 3500);
            }
            // 同时 alert 兜底, 用户必定看到
            if (!ok) alert(text);
            return;
        }
        var area = document.getElementById('msg-area');
        area.innerHTML = '<div class="' + alertClass + '">' + text + '</div>';
        setTimeout(function(){ area.innerHTML = ''; }, 3500);
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
        // 确保用当前页面的 csrfToken
        fd.set('csrf_token', csrfToken);
        fetch('products.php', { method: 'POST', body: fd })
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

    function addProduct(e) {
        e.preventDefault();
        var btn = document.getElementById('btnAdd');
        btn.disabled = true;
        btn.textContent = '提交中...';
        var fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('name', document.getElementById('f_name').value);
        fd.append('category', document.getElementById('f_category').value);
        fd.append('unit', document.getElementById('f_unit').value);
        fd.append('price', document.getElementById('f_price').value);
        fetch('products.php', { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(data){
                btn.disabled = false;
                btn.textContent = '添加产品';
                if (data.ok) {
                    showMsg('✅ ' + data.msg, true);
                    document.getElementById('f_name').value = '';
                    document.getElementById('f_category').value = '';
                    document.getElementById('f_unit').value = '';
                    document.getElementById('f_price').value = '';
                    setTimeout(function(){ location.reload(); }, 600);
                } else {
                    showMsg('❌ ' + data.msg, false);
                }
            })
            .catch(function(){
                btn.disabled = false;
                btn.textContent = '添加产品';
                showMsg('❌ 网络错误', false);
            });
        return false;
    }

    function deleteProduct(id, btn) {
        // 【2026-09-08 修复】按钮上已带 data-refs, 提前拦截被订单引用的产品
        var refs = 0, pname = '';
        if (btn) {
            refs = parseInt(btn.getAttribute('data-refs') || '0', 10);
            pname = btn.getAttribute('data-pname') || '';
        }
        if (refs > 0) {
            alert('删除失败：产品「' + pname + '」已被 ' + refs + ' 个订单引用，不能删除。\n如需停用，请联系管理员禁用该产品。');
            return;
        }
        if (!confirm('确定删除该产品「' + pname + '」?删除后不可恢复。')) return;
        var fd = new FormData();
        fd.append('action', 'delete');
        fd.append('csrf_token', csrfToken);
        fd.append('id', id);
        // 【2026-09-15】统一删除请求处理：失败一律 alert("删除失败：原因")，
        // 服务器空响应/非 JSON（500、WAF 拦截）也给出明确中文提示，不再报英文解析错误
        fetch('products.php', { method: 'POST', body: fd })
            .then(function(r){ return r.text().then(function(t){ return { status: r.status, text: t }; }); })
            .then(function(res){
                var data = null;
                try { data = JSON.parse(res.text); } catch (e) { data = null; }
                if (data === null) {
                    alert('删除失败：服务器返回异常(HTTP ' + res.status + ')' + (res.text ? '\n' + res.text.substring(0, 150) : '\n（无响应内容，请联系管理员查看服务器错误日志）'));
                    return;
                }
                if (data.ok) {
                    showMsg('已删除', true);
                    var row = document.getElementById('row_' + id);
                    if (row) row.style.opacity = '0.4';
                    setTimeout(function(){ location.reload(); }, 400);
                } else {
                    alert('删除失败：' + (data.msg || '未知原因'));
                }
            })
            .catch(function(){
                alert('删除失败：网络错误，请重试');
            });
    }
function editProduct(id) {
        var row = document.getElementById('row_' + id);
        if (!row) return;
        var cells = row.getElementsByTagName('td');
        var name = cells[0].textContent.trim();
        var unit = cells[1].textContent.trim().replace('-', '');
        var priceText = cells[2].textContent.trim().replace('-', '').replace('¥', '').replace(',', '');
        var price = priceText ? parseFloat(priceText) : '';
        // 分类从 cat-header 中读取
        var catHeader = row.closest('.cat-group').querySelector('.cat-name');
        var cat = catHeader ? catHeader.textContent.trim() : '';
        if (cat === '未分类') cat = '';

        document.getElementById('edit_id').value = id;
        document.getElementById('edit_name').value = name;
        document.getElementById('edit_category').value = cat;
        document.getElementById('edit_unit').value = unit;
        document.getElementById('edit_price').value = price;
        document.getElementById('editModal').classList.add('show');
    }

    function closeModal() {
        document.getElementById('editModal').classList.remove('show');
    }

    function saveProduct() {
        var name = document.getElementById('edit_name').value.trim();
        if (!name) { showMsg('❌ 产品名称不能为空', false); return; }
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
            .then(function(data){
                if (data.ok) {
                    showMsg('✅ ' + data.msg, true);
                    closeModal();
                    setTimeout(function(){ location.reload(); }, 400);
                } else {
                    showMsg('❌ ' + data.msg, false);
                }
            })
            .catch(function(){
                showMsg('❌ 网络错误', false);
            });
    }

    // 点击遮罩关闭
    document.getElementById('editModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    // 分类折叠
    function toggleCat(hash) {
        var body = document.getElementById('body_' + hash);
        var toggle = document.getElementById('toggle_' + hash);
        if (!body || !toggle) return;
        var isClosed = body.style.display === 'none';
        if (isClosed) {
            body.style.display = '';
            toggle.textContent = '▼';
        } else {
            body.style.display = 'none';
            toggle.textContent = '▶';
        }
    }

    // 初始化:默认全部展开(无 JS 折叠时也可读)
    </script>

    <style>
    .cat-group {
        margin-bottom: 16px;
        border: 1px solid var(--gray-200);
        border-radius: var(--radius);
        overflow: hidden;
    }
    .cat-group:last-child { margin-bottom: 0; }
    .cat-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 14px;
        background: var(--gray-50);
        border-bottom: 1px solid var(--gray-200);
        cursor: pointer;
        user-select: none;
        font-size: 14px;
    }
    .cat-header:hover { background: var(--gray-100); }
    .cat-toggle {
        color: var(--gray-400);
        font-size: 11px;
        width: 16px;
        display: inline-block;
        text-align: center;
        transition: transform 0.15s;
    }
    .cat-name {
        font-weight: 600;
        color: var(--gray-700);
    }
    .cat-body { }
    .cat-body .data-table {
        margin: 0;
        border: none;
        border-radius: 0;
    }
    .cat-body .data-table tr:first-child td { border-top: none; }
    .cat-summary {
        display: flex;
        gap: 20px;
        padding: 8px 14px;
        background: #f8fafc;
        border-top: 1px solid var(--gray-100);
        font-size: 12px;
        color: var(--gray-500);
    }
    .cat-summary span { display: flex; align-items: center; gap: 4px; }
    .total-bar {
        display: flex;
        gap: 20px;
        align-items: center;
        flex-wrap: wrap;
    }
    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.45);
        z-index: 1000;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }
    .modal-overlay.show { display: flex; }
    .modal-sheet {
        background: white;
        border-radius: 16px;
        padding: 24px;
        width: 100%;
        max-width: 440px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        max-height: 90vh;
        overflow-y: auto;
    }
    </style>
</body>
</html>
