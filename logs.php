<?php
/**
 * 操作日志
 */
require_once 'config.php';
initDatabase();
checkLogin();

if (!hasPermission(PERM_LOG_VIEW)) {
    die('<script>alert("无权限访问，请联系管理员授权");location.href="index.php";</script>');
}

$db = getDB();

// 分页
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// 筛选
$where = ['1=1'];
$params = [];

if (!empty($_GET['action'])) {
    $where[] = "action = ?";
    $params[] = $_GET['action'];
}

if (!empty($_GET['username'])) {
    $where[] = "username LIKE ?";
    $params[] = '%' . $_GET['username'] . '%';
}

if (!empty($_GET['date_start'])) {
    $where[] = "DATE(created_at) >= ?";
    $params[] = $_GET['date_start'];
}

if (!empty($_GET['date_end'])) {
    $where[] = "DATE(created_at) <= ?";
    $params[] = $_GET['date_end'];
}

$whereSql = implode(' AND ', $where);

// 获取总数
$stmt = $db->prepare("SELECT COUNT(*) FROM operation_logs WHERE $whereSql");
$stmt->execute($params);
$total = $stmt->fetchColumn();
$totalPages = ceil($total / $perPage);

// 获取日志列表
$sql = "SELECT * FROM operation_logs WHERE $whereSql ORDER BY created_at DESC LIMIT $perPage OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取操作类型统计
$actionStats = $db->query("SELECT action, COUNT(*) as cnt FROM operation_logs GROUP BY action ORDER BY cnt DESC LIMIT 20")->fetchAll(PDO::FETCH_KEY_PAIR);

// 操作类型中文映射
$actionLabels = [
    'login' => '登录',
    'logout' => '退出登录',
    'add_order' => '新建订单',
    'edit_order' => '编辑订单',
    'delete_order' => '删除订单',
    'update_order_status' => '更新订单状态',
    'add_payment' => '收款',
    'add_customer' => '新建客户',
    'edit_customer' => '编辑客户',
    'delete_customer' => '删除客户',
    'add_product' => '新建产品',
    'edit_product' => '编辑产品',
    'delete_product' => '删除产品',
    'import_products' => '导入产品',
    'import_customers' => '导入客户',
    'add_expense' => '添加支出',
    'update_expense' => '编辑支出',
    'delete_expense' => '删除支出',
    'add_category' => '添加分类',
    'edit_category' => '编辑分类',
    'delete_category' => '删除分类',
    'add_unit' => '添加单位',
    'edit_unit' => '编辑单位',
    'delete_unit' => '删除单位',
    'upload_file' => '上传文件',
    'delete_file' => '删除文件',
    'change_password' => '修改密码',
    'backup_db' => '备份数据库',
    'backup_files' => '备份文件',
    'update_settings' => '更新设置',
    'fix_batch_payment_data' => '修复批量收款数据',
];

function getActionLabel($action) {
    global $actionLabels;
    return $actionLabels[$action] ?? $action;
}

// JSON key 中英文映射（用于详情字段名翻译）
$keyLabels = [
    'id' => '编号',
    'category' => '分类',
    'username' => '用户名',
    'unit' => '单位',
    'order_id' => '订单ID',
    'order_no' => '订单号',
    'amount' => '金额',
    'date' => '日期',
    'attachment' => '附件',
    'method' => '方式',
    'file_id' => '文件ID',
    'file_name' => '文件名',
    'file_size' => '文件大小',
    'old_customer_id' => '原客户ID',
    'new_customer_id' => '新客户ID',
    'old_total' => '原总额',
    'new_total' => '新总额',
    'payment_method' => '收款方式',
    'pay_date' => '收款日期',
    'expense_date' => '支出日期',
    'expense_id' => '支出ID',
    'customer_id' => '客户ID',
    'product_id' => '产品ID',
    'product_name' => '产品名',
    'order_count' => '订单数',
    'total_amount' => '总额',
    'paid_amount' => '已收款',
    'discount_amount' => '优惠金额',
    'ip' => 'IP地址',
    'ip_address' => 'IP地址',
    'user_agent' => '浏览器',
    'status' => '状态',
    'remark' => '备注',
    'description' => '描述',
    'created_at' => '创建时间',
];

// 递归翻译数组 key
function translateKeys($data) {
    global $keyLabels;
    if (!is_array($data)) return $data;
    $result = [];
    foreach ($data as $k => $v) {
        $newKey = $keyLabels[$k] ?? $k;
        if (is_array($v)) {
            $result[$newKey] = translateKeys($v);
        } else {
            $result[$newKey] = $v;
        }
    }
    return $result;
}

function getActionIcon($action) {
    $icons = [
        'login' => '🔐',
        'logout' => '🚪',
        'add_order' => '📋',
        'edit_order' => '✏️',
        'delete_order' => '🗑️',
        'update_order_status' => '🔄',
        'add_payment' => '💰',
        'add_customer' => '👤',
        'edit_customer' => '✏️',
        'delete_customer' => '🗑️',
        'add_product' => '📦',
        'edit_product' => '✏️',
        'delete_product' => '🗑️',
        'import_products' => '📥',
        'import_customers' => '📥',
        'add_expense' => '💸',
        'delete_expense' => '🗑️',
        'add_category' => '📁',
        'edit_category' => '✏️',
        'delete_category' => '🗑️',
        'add_unit' => '📏',
        'edit_unit' => '✏️',
        'delete_unit' => '🗑️',
        'upload_file' => '📤',
        'delete_file' => '🗑️',
        'change_password' => '🔑',
        'backup_db' => '💾',
        'backup_files' => '💾',
        'update_settings' => '⚙️',
        'fix_batch_payment_data' => '🔧',
    ];
    return $icons[$action] ?? '📝';
}

function getActionColor($action) {
    $colors = [
        'login' => '#10B981',
        'logout' => '#6B7280',
        'add_order' => '#3B82F6',
        'edit_order' => '#F59E0B',
        'delete_order' => '#EF4444',
        'update_order_status' => '#8B5CF6',
        'add_payment' => '#10B981',
        'add_customer' => '#3B82F6',
        'edit_customer' => '#F59E0B',
        'delete_customer' => '#EF4444',
        'add_product' => '#3B82F6',
        'edit_product' => '#F59E0B',
        'delete_product' => '#EF4444',
        'import_products' => '#3B82F6',
        'import_customers' => '#3B82F6',
        'add_expense' => '#EF4444',
        'delete_expense' => '#EF4444',
        'upload_file' => '#3B82F6',
        'delete_file' => '#EF4444',
        'backup_db' => '#8B5CF6',
        'backup_files' => '#8B5CF6',
        'fix_batch_payment_data' => '#8B5CF6',
    ];
    return $colors[$action] ?? '#6B7280';
}

$csrfToken = $_SESSION['csrf_token'] ?? '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>操作日志 - <?php echo SITE_TITLE; ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .page-title-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
        .card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: 24px; margin-bottom: 20px; }
        .card-title { font-size: 18px; font-weight: 600; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; }
        
        /* 筛选器 */
        .filter-bar { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; padding: 16px; background: #F9FAFB; border-radius: 8px; }
        .filter-bar input, .filter-bar select { padding: 8px 12px; border: 1px solid #D1D5DB; border-radius: 6px; font-size: 14px; }
        .filter-bar button { padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; }
        .btn-filter { background: #3B82F6; color: white; }
        .btn-reset { background: #6B7280; color: white; }
        
        /* 统计 */
        .stats-row { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 20px; }
        .stat-badge { padding: 6px 12px; border-radius: 20px; font-size: 13px; cursor: pointer; transition: all 0.2s; border: 2px solid transparent; }
        .stat-badge:hover { border-color: currentColor; }
        
        /* 日志列表 */
        .log-item { display: flex; gap: 16px; padding: 16px 0; border-bottom: 1px solid #F3F4F6; }
        .log-item:last-child { border-bottom: none; }
        .log-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
        .log-content { flex: 1; min-width: 0; }
        .log-title { font-weight: 500; color: #1F2937; margin-bottom: 4px; }
        .log-meta { font-size: 13px; color: #6B7280; display: flex; gap: 16px; flex-wrap: wrap; }
        .log-details { font-size: 13px; color: #374151; background: #F3F4F6; padding: 8px 12px; border-radius: 6px; margin-top: 8px; font-family: monospace; overflow-x: auto; }
        .log-time { color: #9CA3AF; font-size: 12px; white-space: nowrap; }
        
        /* 分页 */
        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 24px; }
        .pagination a, .pagination span { padding: 8px 14px; border-radius: 6px; text-decoration: none; font-size: 14px; }
        .pagination a { background: #F3F4F6; color: #374151; }
        .pagination a:hover { background: #E5E7EB; }
        .pagination .current { background: #3B82F6; color: white; }
        .pagination .disabled { color: #9CA3AF; pointer-events: none; }
        
        .empty-state { text-align: center; padding: 60px 20px; color: #9CA3AF; }
        .two-col { display: grid; grid-template-columns: 1fr 2fr; gap: 20px; }
        @media (max-width: 900px) { .two-col { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <?php echo renderNav('settings'); ?>
    
    <div class="page-title-bar">
        <div>
            <h1>📋 操作日志</h1>
            <p style="color: #6B7280; margin-top: 4px;">追踪所有关键操作记录</p>
        </div>
        <div style="color:#6B7280;font-size:14px;">
            共 <?php echo number_format($total); ?> 条记录
        </div>
    </div>
    
    <!-- 操作类型统计 -->
    <?php if (!empty($actionStats)): ?>
    <div class="card">
        <div class="card-title">📊 操作统计</div>
        <div class="stats-row">
            <?php foreach ($actionStats as $action => $cnt): ?>
            <a href="?action=<?php echo urlencode($action); ?>" class="stat-badge" style="background:<?php echo getActionColor($action); ?>20;color:<?php echo getActionColor($action); ?>;">
                <?php echo getActionIcon($action); ?> <?php echo getActionLabel($action); ?> (<?php echo $cnt; ?>)
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="two-col">
        <!-- 侧边栏：统计信息 -->
        <div class="card">
            <div class="card-title">🕐 最近活动</div>
            <?php
            // 获取今日/昨日/本周统计
            $today = date('Y-m-d');
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $weekStart = date('Y-m-d', strtotime('monday this week'));
            
            $todayCnt = $db->prepare("SELECT COUNT(*) FROM operation_logs WHERE DATE(created_at) = ?")->execute([$today]) ? $db->query("SELECT COUNT(*) FROM operation_logs WHERE DATE(created_at) = '$today'")->fetchColumn() : 0;
            $yesterdayCnt = $db->query("SELECT COUNT(*) FROM operation_logs WHERE DATE(created_at) = '$yesterday'")->fetchColumn();
            $weekCnt = $db->query("SELECT COUNT(*) FROM operation_logs WHERE DATE(created_at) >= '$weekStart'")->fetchColumn();
            ?>
            <div style="margin-bottom: 20px;">
                <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #F3F4F6;">
                    <span style="color:#6B7280;">今日</span>
                    <strong><?php echo $todayCnt; ?></strong>
                </div>
                <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #F3F4F6;">
                    <span style="color:#6B7280;">昨日</span>
                    <strong><?php echo $yesterdayCnt; ?></strong>
                </div>
                <div style="display:flex;justify-content:space-between;padding:10px 0;">
                    <span style="color:#6B7280;">本周</span>
                    <strong><?php echo $weekCnt; ?></strong>
                </div>
            </div>
            
            <div class="card-title" style="margin-top:24px;">👥 活跃用户</div>
            <?php
            $activeUsers = $db->query("SELECT username, COUNT(*) as cnt FROM operation_logs GROUP BY username ORDER BY cnt DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($activeUsers as $user): 
            ?>
            <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #F3F4F6;font-size:14px;">
                <span><?php echo h($user['username']); ?></span>
                <span style="color:#6B7280;"><?php echo $user['cnt']; ?> 次</span>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- 日志列表 -->
        <div class="card">
            <!-- 筛选器 -->
            <form method="get" class="filter-bar">
                <input type="text" name="username" placeholder="用户名" value="<?php echo h($_GET['username'] ?? ''); ?>" style="width:120px;">
                <input type="date" name="date_start" value="<?php echo h($_GET['date_start'] ?? ''); ?>" style="width:140px;">
                <input type="date" name="date_end" value="<?php echo h($_GET['date_end'] ?? ''); ?>" style="width:140px;">
                <select name="action" style="width:140px;">
                    <option value="">全部操作</option>
                    <?php foreach ($actionLabels as $k => $v): ?>
                    <option value="<?php echo $k; ?>" <?php echo ($_GET['action'] ?? '') === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-filter">筛选</button>
                <a href="logs.php" class="btn-reset">重置</a>
            </form>
            
            <?php if (empty($logs)): ?>
            <div class="empty-state">
                <div style="font-size:48px;margin-bottom:16px;">📭</div>
                <div>暂无日志记录</div>
            </div>
            <?php else: ?>
            <div class="log-list">
                <?php foreach ($logs as $log): 
                    $details = $log['details'] ? json_decode($log['details'], true) : [];
                ?>
                <div class="log-item">
                    <div class="log-icon" style="background:<?php echo getActionColor($log['action']); ?>20;">
                        <?php echo getActionIcon($log['action']); ?>
                    </div>
                    <div class="log-content">
                        <div class="log-title">
                            <span style="color:<?php echo getActionColor($log['action']); ?>;"><?php echo getActionLabel($log['action']); ?></span>
                            <?php if ($log['target_desc']): ?>
                            — <?php echo h($log['target_desc']); ?>
                            <?php endif; ?>
                        </div>
                        <div class="log-meta">
                            <span>👤 <?php echo h($log['username'] ?: '系统'); ?></span>
                            <span>📍 <?php echo h($log['ip_address'] ?: '-'); ?></span>
                            <span class="log-time"><?php echo $log['created_at']; ?></span>
                        </div>
                        <?php if (!empty($details)): ?>
                        <div class="log-details"><?php echo json_encode(translateKeys($details), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- 分页 -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">上一页</a>
                <?php else: ?>
                <span class="disabled">上一页</span>
                <?php endif; ?>
                
                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                if ($start > 1) echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => 1])) . '">1</a>';
                if ($start > 2) echo '<span>...</span>';
                for ($i = $start; $i <= $end; $i++):
                    if ($i == $page):
                        echo "<span class=\"current\">$i</span>";
                    else:
                        echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => $i])) . "\">$i</a>";
                    endif;
                endfor;
                if ($end < $totalPages - 1) echo '<span>...</span>';
                if ($end < $totalPages) echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => $totalPages])) . "\">$totalPages</a>";
                ?>
                
                <?php if ($page < $totalPages): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">下一页</a>
                <?php else: ?>
                <span class="disabled">下一页</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
