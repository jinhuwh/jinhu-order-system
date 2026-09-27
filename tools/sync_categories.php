<?php
/**
 * 一次性脚本：把 products 表里"用过但 product_categories 字典里没有"的野分类补录到字典
 *
 * 用法：
 *   1. 先访问 https://your-host/tools/sync_categories.php 看预览（不写库）
 *   2. 再访问 https://your-host/tools/sync_categories.php?confirm=1 真正写入
 *
 * 完成后可保留（防野分类再次出现时再跑）或删除
 */

// 【2026-09-09 修复】opcache 强制清理
if (function_exists('opcache_invalidate')) { @opcache_invalidate(__FILE__, true); }
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/functions.php';  // logOperation 等工具函数
initDatabase();
checkLogin();
requirePermission(PERM_PRODUCT_EDIT);  // 必须是产品编辑权限

$db = getDB();
$confirm = isset($_GET['confirm']) && $_GET['confirm'] === '1';

// 1. 读字典表
$dictStmt = $db->query("SELECT id, name, sort_order FROM product_categories ORDER BY sort_order, id");
$dictRows = $dictStmt->fetchAll(PDO::FETCH_ASSOC);
$dictNames = array_column($dictRows, 'name');

// 2. 读 products 表用过的所有 category
$usedStmt = $db->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category");
$usedRows = $usedStmt->fetchAll(PDO::FETCH_ASSOC);
$usedNames = array_column($usedRows, 'category');

// 3. 算差集
$missing = array_values(array_diff($usedNames, $dictNames));
$extra = array_values(array_diff($dictNames, $usedNames));  // 字典里但产品没用过（信息性，不动）

?><!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>分类字典同步 - 工具</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        body { font-family: -apple-system, sans-serif; padding: 24px; max-width: 920px; margin: 0 auto; }
        h1 { font-size: 22px; }
        .ok { color: #059669; }
        .warn { color: #d97706; }
        .err { color: #dc2626; }
        table { width: 100%; border-collapse: collapse; margin: 16px 0; }
        th, td { border: 1px solid #e5e7eb; padding: 8px 12px; text-align: left; font-size: 14px; }
        th { background: #f9fafb; font-weight: 600; }
        .btn { display: inline-block; padding: 10px 20px; background: #2563eb; color: #fff;
               border-radius: 6px; text-decoration: none; font-weight: 600; margin-top: 16px; }
        .btn-danger { background: #dc2626; }
        pre { background: #f9fafb; padding: 12px; border-radius: 6px; font-size: 12px; overflow: auto; }
    </style>
</head>
<body>
    <h1>🛠️ 产品分类字典同步</h1>
    <p><strong>时间</strong>: <?php echo date('Y-m-d H:i:s'); ?> &nbsp; <strong>模式</strong>: <?php echo $confirm ? '<span class="err">已确认（将写入）</span>' : '<span class="warn">预览（只读）</span>'; ?></p>

    <h2>📊 当前数据</h2>
    <table>
        <tr><th>数据源</th><th>数量</th><th>说明</th></tr>
        <tr><td>字典表 <code>product_categories</code></td><td><?php echo count($dictNames); ?></td><td>用户主动维护的分类</td></tr>
        <tr><td>产品表用过的 <code>products.category DISTINCT</code></td><td><?php echo count($usedNames); ?></td><td>实战中产品用过的所有分类</td></tr>
        <tr><td><strong>差集（要补录）</strong></td><td><strong class="<?php echo empty($missing) ? 'ok' : 'err'; ?>"><?php echo count($missing); ?></strong></td><td>产品用过但字典里没有的"野分类"</td></tr>
    </table>

    <?php if (!empty($extra)): ?>
    <h2>ℹ️ 字典里有但产品没用过（仅供参考，不处理）</h2>
    <table>
        <tr><th>#</th><th>分类名</th></tr>
        <?php foreach ($extra as $i => $n): ?>
        <tr><td><?php echo $i + 1; ?></td><td><?php echo htmlspecialchars($n); ?></td></tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <?php if (!empty($missing)): ?>
    <h2>📥 待补录的"野分类"（sort_order=99）</h2>
    <table>
        <tr><th>#</th><th>分类名</th><th>出现的产品数</th></tr>
        <?php
        // 统计每个野分类下有多少产品
        $countStmt = $db->prepare("SELECT COUNT(*) FROM products WHERE category = ?");
        foreach ($missing as $i => $name):
            $countStmt->execute([$name]);
            $count = $countStmt->fetchColumn();
        ?>
        <tr><td><?php echo $i + 1; ?></td><td><?php echo htmlspecialchars($name); ?></td><td><?php echo $count; ?></td></tr>
        <?php endforeach; ?>
    </table>

    <?php if ($confirm): ?>
        <?php
        // 真正写入
        $inserted = 0; $skipped = 0; $errors = [];
        $insStmt = $db->prepare("INSERT INTO product_categories (name, sort_order) VALUES (?, 99)");
        $chkStmt = $db->prepare("SELECT id FROM product_categories WHERE name = ?");
        foreach ($missing as $name) {
            $chkStmt->execute([$name]);
            if ($chkStmt->fetchColumn()) { $skipped++; continue; }
            try {
                $insStmt->execute([$name]);
                $inserted++;
            } catch (Exception $e) {
                $errors[] = $name . ' - ' . $e->getMessage();
            }
        }
        logOperation('sync_categories', ['inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors]);
        ?>
        <h2>✅ 执行结果</h2>
        <p><strong class="ok">新增：<?php echo $inserted; ?> 个</strong> &nbsp;
           <strong>已存在跳过：<?php echo $skipped; ?> 个</strong>
           <?php if (!empty($errors)): ?><br><span class="err">错误：</span><?php echo implode('; ', $errors); ?><?php endif; ?>
        </p>
        <p>同步完成！刷新 <a href="../products.php">products.php</a> 和 <a href="../categories.php">categories.php</a> 即可看到一致的分类列表。</p>
    <?php else: ?>
        <h2>⚠️ 确认执行</h2>
        <p>点击下方按钮执行 INSERT（带 <code>?confirm=1</code> 参数）：</p>
        <a class="btn btn-danger" href="?confirm=1">🚀 确认补录 <?php echo count($missing); ?> 个分类</a>
    <?php endif; ?>

    <?php else: ?>
    <h2 class="ok">✅ 字典已完整</h2>
    <p>产品表里用过的所有分类都已经在字典表里了，无需补录。</p>
    <?php endif; ?>

    <hr style="margin: 32px 0; border:none; border-top:1px solid #e5e7eb;">
    <p style="color:var(--gray-500);font-size:13px;">
        工具脚本 <code>tools/sync_categories.php</code>。<br>
        权限: <?php echo htmlspecialchars($_SESSION['username'] ?? '?'); ?> (role=<?php echo intval($_SESSION['role'] ?? 0); ?>)
    </p>
</body>
</html>
