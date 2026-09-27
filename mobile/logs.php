<?php
/**
 * 移动端 - 操作日志
 * Admin only. Filterable, paginated log viewer.
 */
require_once 'config.php';
initDatabase();
checkLogin();

if (!isAdmin()) {
    die('<script>alert("无权限访问");location.href="index.php";</script>');
}

$db = getDB();

// ── Pagination ────────────────────────────────────────────────────────────
$page    = max(1, intval($_GET['page'] ?? 1));
$perPage = 30;
$offset  = ($page - 1) * $perPage;

// ── Filters ───────────────────────────────────────────────────────────────
$where = ['1=1'];
$params = [];

if (!empty($_GET['username'])) {
    $where[] = 'username LIKE ?';
    $params[] = '%' . $_GET['username'] . '%';
}

if (!empty($_GET['action'])) {
    $where[] = 'action = ?';
    $params[] = $_GET['action'];
}

if (!empty($_GET['date_start'])) {
    $where[] = 'DATE(created_at) >= ?';
    $params[] = $_GET['date_start'];
}

if (!empty($_GET['date_end'])) {
    $where[] = 'DATE(created_at) <= ?';
    $params[] = $_GET['date_end'];
}

$whereSql = implode(' AND ', $where);

// Total count
$stmt = $db->prepare("SELECT COUNT(*) FROM operation_logs WHERE $whereSql");
$stmt->execute($params);
$total      = intval($stmt->fetchColumn());
$totalPages = max(1, ceil($total / $perPage));

// Log list
$sql = "SELECT * FROM operation_logs WHERE $whereSql ORDER BY created_at DESC LIMIT $perPage OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Action maps (copied from PC logs.php) ────────────────────────────────
$actionLabels = [
    'login'              => '登录',
    'logout'             => '退出登录',
    'add_order'          => '新建订单',
    'edit_order'         => '编辑订单',
    'delete_order'       => '删除订单',
    'update_order_status'=> '更新订单状态',
    'add_payment'        => '收款',
    'add_customer'       => '新建客户',
    'edit_customer'      => '编辑客户',
    'delete_customer'    => '删除客户',
    'add_product'        => '新建产品',
    'edit_product'       => '编辑产品',
    'delete_product'     => '删除产品',
    'import_products'    => '导入产品',
    'import_customers'   => '导入客户',
    'add_expense'        => '添加支出',
    'delete_expense'     => '删除支出',
    'add_category'       => '添加分类',
    'edit_category'      => '编辑分类',
    'delete_category'    => '删除分类',
    'add_unit'           => '添加单位',
    'edit_unit'          => '编辑单位',
    'delete_unit'        => '删除单位',
    'add_user'           => '添加用户',
    'edit_user'          => '编辑用户',
    'delete_user'        => '删除用户',
    'reset_password'     => '重置密码',
    'upload_file'        => '上传文件',
    'delete_file'        => '删除文件',
    'change_password'    => '修改密码',
    'backup_db'          => '备份数据库',
    'backup_files'       => '备份文件',
    'update_settings'    => '更新设置',
];

$actionIcons = [
    'login'              => '🔐',
    'logout'             => '🚪',
    'add_order'          => '📋',
    'edit_order'         => '✏️',
    'delete_order'       => '🗑️',
    'update_order_status'=> '🔄',
    'add_payment'        => '💰',
    'add_customer'       => '👤',
    'edit_customer'      => '✏️',
    'delete_customer'    => '🗑️',
    'add_product'        => '📦',
    'edit_product'       => '✏️',
    'delete_product'     => '🗑️',
    'import_products'    => '📥',
    'import_customers'   => '📥',
    'add_expense'        => '💸',
    'delete_expense'     => '🗑️',
    'add_category'       => '📁',
    'edit_category'      => '✏️',
    'delete_category'    => '🗑️',
    'add_unit'           => '📏',
    'edit_unit'          => '✏️',
    'delete_unit'        => '🗑️',
    'add_user'           => '👤➕',
    'edit_user'          => '✏️',
    'delete_user'        => '🗑️',
    'reset_password'     => '🔑',
    'upload_file'        => '📤',
    'delete_file'        => '🗑️',
    'change_password'    => '🔑',
    'backup_db'          => '💾',
    'backup_files'       => '💾',
    'update_settings'    => '⚙️',
];

$actionColors = [
    'login'              => '#10B981',
    'logout'             => '#6B7280',
    'add_order'          => '#3B82F6',
    'edit_order'         => '#F59E0B',
    'delete_order'       => '#EF4444',
    'update_order_status'=> '#8B5CF6',
    'add_payment'        => '#10B981',
    'add_customer'       => '#3B82F6',
    'edit_customer'      => '#F59E0B',
    'delete_customer'    => '#EF4444',
    'add_product'        => '#3B82F6',
    'edit_product'       => '#F59E0B',
    'delete_product'     => '#EF4444',
    'import_products'    => '#3B82F6',
    'import_customers'   => '#3B82F6',
    'add_expense'        => '#EF4444',
    'delete_expense'     => '#EF4444',
    'upload_file'        => '#3B82F6',
    'delete_file'        => '#EF4444',
    'backup_db'          => '#8B5CF6',
    'backup_files'       => '#8B5CF6',
    'add_user'           => '#3B82F6',
    'edit_user'          => '#F59E0B',
    'delete_user'        => '#EF4444',
    'reset_password'     => '#F59E0B',
    'change_password'    => '#F59E0B',
    'add_unit'           => '#3B82F6',
    'edit_unit'          => '#F59E0B',
    'delete_unit'        => '#EF4444',
];

function getActionLabel($action) {
    global $actionLabels;
    return $actionLabels[$action] ?? $action;
}

function getActionIcon($action) {
    global $actionIcons;
    return $actionIcons[$action] ?? '📝';
}

function getActionColor($action) {
    global $actionColors;
    return $actionColors[$action] ?? '#6B7280';
}

// Current filter values for form repopulation
$curUsername  = h($_GET['username'] ?? '');
$curAction    = h($_GET['action'] ?? '');
$curDateStart = h($_GET['date_start'] ?? '');
$curDateEnd   = h($_GET['date_end'] ?? '');

// Build query string for pagination links (preserving filters)
$queryParts = [];
if ($curUsername)  $queryParts[] = 'username='    . urlencode($curUsername);
if ($curAction)    $queryParts[] = 'action='      . urlencode($curAction);
if ($curDateStart) $queryParts[] = 'date_start=' . urlencode($curDateStart);
if ($curDateEnd)   $queryParts[] = 'date_end='   . urlencode($curDateEnd);
$queryStr = implode('&', $queryParts);
if ($queryStr) $queryStr .= '&';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>操作日志</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .log-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 15px 16px;
            margin-bottom: 12px;
            box-shadow: var(--shadow-card);
            display: flex;
            gap: 13px;
            align-items: flex-start;
        }
        .log-card .icon-wrap {
            width: 42px;
            height: 42px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
            flex-shrink: 0;
        }
        .log-card .body { flex: 1; min-width: 0; }
        .log-card .title {
            font-weight: 650;
            font-size: 14px;
            color: var(--gray-800);
            margin-bottom: 4px;
        }
        .log-card .meta {
            font-size: 12px;
            color: var(--gray-400);
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .log-card .meta span {
            display: inline-flex;
            align-items: center;
            gap: 2px;
        }
        .log-card .detail {
            font-size: 12px;
            color: var(--gray-500);
            background: var(--gray-50);
            border-radius: var(--radius-sm);
            padding: 7px 10px;
            margin-top: 8px;
            font-family: monospace;
            overflow-x: auto;
            white-space: pre-wrap;
            word-break: break-all;
        }
        .log-card .target {
            font-weight: 500;
            color: var(--gray-700);
        }

        /* Filter panel */
        .filter-panel {
            background: white;
            border-radius: var(--radius-lg);
            padding: 16px;
            margin-bottom: 16px;
            box-shadow: var(--shadow-card);
        }
        .filter-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 10px;
        }
        .filter-row.single { grid-template-columns: 1fr; }
        .filter-row .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1.5px solid var(--gray-200);
            border-radius: var(--radius-sm);
            font-size: 14px;
            background: white;
            color: var(--gray-800);
            box-sizing: border-box;
        }
        .filter-row .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
        }
        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2394A3B8' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
            padding-right: 30px;
        }
        .filter-actions {
            display: flex;
            gap: 8px;
        }
        .filter-actions .btn { flex: 1; padding: 10px; font-size: 14px; border-radius: var(--radius-sm); font-weight: 600; border: none; cursor: pointer; }
        .filter-actions .btn-reset { background: var(--gray-100); color: var(--gray-500); text-decoration: none; text-align: center; display: block; }
        .filter-actions .btn-submit { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; }

        /* Pagination */
        .pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 16px 0;
            flex-wrap: wrap;
        }
        .pagination a, .pagination span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            height: 36px;
            padding: 0 8px;
            border-radius: var(--radius-sm);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }
        .pagination a {
            background: white;
            color: var(--gray-600);
            box-shadow: var(--shadow-sm);
        }
        .pagination a:active { background: var(--gray-100); }
        .pagination .cur {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: 0 2px 8px rgba(59,130,246,0.25);
        }
        .pagination .gap { color: var(--gray-300); }

        /* Stats bar */
        .stats-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
            font-size: 13px;
        }
        .stats-bar .count { color: var(--gray-500); }
        .stats-bar .filter-indicator { color: var(--primary); font-weight: 600; }

        .page-subtitle { font-size: 13px; color: rgba(255,255,255,0.7); margin-top: 3px; position: relative; z-index: 1; }
    </style>
</head>
<body>
    <div class="page-header">
        <h1>📋 操作日志</h1>
        <p class="page-subtitle">追踪所有关键操作记录</p>
    </div>

    <div class="page">
        <!-- Stats bar -->
        <div class="stats-bar">
            <span class="count">共 <?php echo number_format($total); ?> 条记录</span>
            <?php if ($total > 0): ?>
                <span class="filter-indicator">第 <?php echo $page; ?>/<?php echo $totalPages; ?> 页</span>
            <?php endif; ?>
        </div>

        <!-- Filter panel -->
        <form method="get" class="filter-panel" id="filterForm">
            <div class="filter-row">
                <input type="text" name="username" class="form-control" placeholder="👤 用户名" value="<?php echo $curUsername; ?>">
                <select name="action" class="form-control">
                    <option value="">📝 全部操作</option>
                    <?php foreach ($actionLabels as $k => $v): ?>
                    <option value="<?php echo $k; ?>" <?php echo $curAction === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-row">
                <input type="date" name="date_start" class="form-control" value="<?php echo $curDateStart; ?>" placeholder="开始日期">
                <input type="date" name="date_end" class="form-control" value="<?php echo $curDateEnd; ?>" placeholder="结束日期">
            </div>
            <div class="filter-row single">
                <div class="filter-actions">
                    <a href="logs.php" class="btn btn-reset">重置</a>
                    <button type="submit" class="btn btn-submit">🔍 筛选</button>
                </div>
            </div>
        </form>

        <!-- Log list -->
        <?php if (empty($logs)): ?>
            <div class="empty-state">
                <div class="icon">📭</div>
                <div class="text">暂无日志记录</div>
            </div>
        <?php else: foreach ($logs as $log):
            $icon    = getActionIcon($log['action']);
            $color   = getActionColor($log['action']);
            $label   = getActionLabel($log['action']);
            $details = $log['details'] ? json_decode($log['details'], true) : [];
        ?>
            <div class="log-card">
                <div class="icon-wrap" style="background:<?php echo $color; ?>18;">
                    <?php echo $icon; ?>
                </div>
                <div class="body">
                    <div class="title">
                        <span style="color:<?php echo $color; ?>;"><?php echo $label; ?></span>
                        <?php if (!empty($log['target_desc'])): ?>
                            — <span class="target"><?php echo h($log['target_desc']); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="meta">
                        <span>👤 <?php echo h($log['username'] ?: '系统'); ?></span>
                        <?php if (!empty($log['ip_address'])): ?>
                            <span>📍 <?php echo h($log['ip_address']); ?></span>
                        <?php endif; ?>
                        <span>🕐 <?php echo date('m-d H:i', strtotime($log['created_at'])); ?></span>
                    </div>
                    <?php if (!empty($details)): ?>
                        <div class="detail"><?php echo json_encode($details, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; endif; ?>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php
            $start = max(1, $page - 2);
            $end   = min($totalPages, $page + 2);

            if ($page > 1):
                echo '<a href="?' . $queryStr . 'page=' . ($page - 1) . '">‹ 上一页</a>';
            endif;

            if ($start > 1):
                echo '<a href="?' . $queryStr . 'page=1">1</a>';
                if ($start > 2) echo '<span class="gap">…</span>';
            endif;

            for ($i = $start; $i <= $end; $i++):
                if ($i == $page):
                    echo "<span class=\"cur\">$i</span>";
                else:
                    echo '<a href="?' . $queryStr . 'page=' . $i . '">' . $i . '</a>';
                endif;
            endfor;

            if ($end < $totalPages):
                if ($end < $totalPages - 1) echo '<span class="gap">…</span>';
                echo '<a href="?' . $queryStr . 'page=' . $totalPages . '">' . $totalPages . '</a>';
            endif;

            if ($page < $totalPages):
                echo '<a href="?' . $queryStr . 'page=' . ($page + 1) . '">下一页 ›</a>';
            endif;
            ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
<?php echo mobileNav('more'); ?>
