<?php
/**
 * 移动端配置 - 复用PC端核心逻辑
 */

// 在加载 PC 端 config 之前，覆盖登录页路径常量
define('LOGIN_URL', '../login.php');

require_once dirname(__DIR__) . '/config.php';

// 标记移动端来源（用于登录后跳转回移动端）
$_SESSION['from_mobile'] = true;

// 移动端不需要额外的数据库连接，直接使用PC端的

// 判断是否为移动端请求（也可通过URL参数强制指定）
function isMobileRequest() {
    return true; // 移动版全部使用移动端样式
}

// 获取移动端首页数据
function getMobileDashboardData() {
    $db = getDB();
    $today = date('Y-m-d');
    $monthStart = date('Y-m-01');
    
    // 使用参数化查询防止 SQL 注入（即使 $today/$monthStart 是本地生成）
    $stmt1 = $db->prepare("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = ?");
    $stmt1->execute([$today]);
    
    $stmt2 = $db->prepare("SELECT COUNT(*) FROM orders WHERE status = 0");
    $stmt2->execute();
    
    $stmt3 = $db->prepare("SELECT COUNT(*) FROM orders WHERE status = 2");
    $stmt3->execute();
    
    $stmt4 = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status != 4 AND created_at >= ?");
    $stmt4->execute([$monthStart]);
    
    return [
        'today_count' => $stmt1->fetchColumn(),
        'pending_count' => $stmt2->fetchColumn(),
        'producing_count' => $stmt3->fetchColumn(),
        'month_amount' => $stmt4->fetchColumn(),
    ];
}

// 获取订单列表（移动端）
function getMobileOrders($status = '', $keyword = '', $page = 1, $perPage = 15) {
    $db = getDB();
    // 【2026-09-13 加固】LIMIT/OFFSET 强制整数，即使调用方漏了 intval 也不存在注入面
    $page = max(1, intval($page));
    $perPage = max(1, intval($perPage));
    $where = ['1=1'];
    $params = [];
    
    if ($status !== '' && $status !== 'all') {
        $where[] = 'o.status = ?';
        $params[] = intval($status);
    }
    
    if (!empty($keyword)) {
        $where[] = '(o.order_no LIKE ? OR c.name LIKE ?)';
        $params[] = "%$keyword%";
        $params[] = "%$keyword%";
    }
    
    $offset = ($page - 1) * $perPage;
    
    $sql = "SELECT o.*, c.name as customer_name 
        FROM orders o 
        LEFT JOIN customers c ON o.customer_id = c.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY o.created_at DESC 
        LIMIT $perPage OFFSET $offset";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 获取订单总数
function getMobileOrderCount($status = '', $keyword = '') {
    $db = getDB();
    $where = ['1=1'];
    $params = [];
    
    if ($status !== '' && $status !== 'all') {
        $where[] = 'o.status = ?';
        $params[] = intval($status);
    }
    
    if (!empty($keyword)) {
        $where[] = '(o.order_no LIKE ? OR c.name LIKE ?)';
        $params[] = "%$keyword%";
        $params[] = "%$keyword%";
    }
    
    $sql = "SELECT COUNT(*) FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE " . implode(' AND ', $where);
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

// 底部导航高亮
function mobileNav($active = '') {
    $items = [
        'home' => ['🏠', '首页', 'index.php'],
        'orders' => ['📋', '订单', 'order_list.php'],
        'create' => ['➕', '新建', 'order_create.php'],
        'customers' => ['👥', '客户', 'customers.php'],
        'more' => ['⚙️', '更多', 'more.php'],
    ];
    
    $html = '<nav class="bottom-nav">';
    foreach ($items as $key => $item) {
        $cls = ($active === $key) ? ' class="active"' : '';
        $html .= '<a href="'.$item[2].'"'.$cls.'>';
        $html .= '<span class="nav-icon">'.$item[0].'</span>';
        $html .= '<span class="nav-label">'.$item[1].'</span>';
        $html .= '</a>';
    }
    $html .= '</nav>';
    
    return $html;
}

/**
 * 【2026-09-15】移动端统一弹窗组件（删除确认 / 操作结果提示）
 *
 * 所有移动端页面在 </body> 前加一行 <?php echo mobileDialog(); ?> 即可获得
 * 完全一致的弹窗，保证单位 / 产品 / 客户等各模块的删除交互风格统一。
 *
 * 用法：
 *   mdlgConfirm('确定要删除「XX」吗？', function(){ ...提交... }, {title:'删除产品'});
 *   mdlgAlert('已删除', true, function(){ location.reload(); }, 800); // 成功，800ms 后自动关闭
 *   mdlgAlert('该产品已被订单引用，不能删除', false);                   // 失败，需手动关闭
 */
function mobileDialog() {
    return <<<'HTML'
<div class="mdlg-overlay" id="mdlgOverlay">
    <div class="mdlg-box">
        <div class="mdlg-icon warn" id="mdlgIcon">⚠️</div>
        <div class="mdlg-title" id="mdlgTitle">确认删除</div>
        <div class="mdlg-text" id="mdlgText"></div>
        <div class="mdlg-actions">
            <button type="button" class="mdlg-btn mdlg-cancel" id="mdlgCancel">取消</button>
            <button type="button" class="mdlg-btn mdlg-ok" id="mdlgOk">确定</button>
        </div>
    </div>
</div>
<style>
.mdlg-overlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,0.55);
    -webkit-backdrop-filter:blur(4px);backdrop-filter:blur(4px);z-index:1200;display:none;
    align-items:center;justify-content:center;padding:24px;}
.mdlg-overlay.show{display:flex;}
.mdlg-box{background:#fff;border-radius:16px;width:100%;max-width:340px;padding:24px 20px 18px;
    text-align:center;box-shadow:0 12px 40px rgba(0,0,0,0.18);animation:mdlgIn .22s ease;font-family:inherit;}
@keyframes mdlgIn{from{transform:scale(.92);opacity:0;}to{transform:scale(1);opacity:1;}}
.mdlg-icon{width:52px;height:52px;border-radius:50%;margin:0 auto 12px;display:flex;
    align-items:center;justify-content:center;font-size:24px;background:rgba(245,158,11,0.12);}
.mdlg-icon.warn{background:rgba(245,158,11,0.12);}
.mdlg-icon.success{background:rgba(16,185,129,0.10);}
.mdlg-icon.error{background:rgba(239,68,68,0.10);}
.mdlg-title{font-size:16px;font-weight:700;color:#1E293B;margin-bottom:6px;}
.mdlg-text{font-size:14px;color:#475569;line-height:1.65;word-break:break-word;white-space:pre-wrap;}
.mdlg-actions{display:flex;gap:10px;margin-top:20px;}
.mdlg-btn{flex:1;padding:12px;border:none;border-radius:10px;font-size:15px;font-weight:600;
    cursor:pointer;font-family:inherit;}
.mdlg-btn:active{opacity:.85;}
.mdlg-cancel{background:#F1F5F9;color:#475569;}
.mdlg-ok{background:#EF4444;color:#fff;}
.mdlg-ok.primary{background:linear-gradient(135deg,#3B82F6,#2563EB);}
</style>
<script>
(function () {
    var ov = document.getElementById('mdlgOverlay');
    if (!ov) return;
    var iconEl = document.getElementById('mdlgIcon');
    var titleEl = document.getElementById('mdlgTitle');
    var textEl = document.getElementById('mdlgText');
    var cancelEl = document.getElementById('mdlgCancel');
    var okEl = document.getElementById('mdlgOk');
    var onOk = null, timer = null;

    function open(opts) {
        if (timer) { clearTimeout(timer); timer = null; }
        var icon = opts.icon || 'warn';
        iconEl.className = 'mdlg-icon ' + icon;
        iconEl.textContent = icon === 'success' ? '✅' : (icon === 'error' ? '❌' : '⚠️');
        titleEl.textContent = opts.title || '提示';
        textEl.textContent = opts.text || '';
        okEl.textContent = opts.okText || '确定';
        okEl.className = 'mdlg-btn mdlg-ok' + (opts.btn === 'primary' ? ' primary' : '');
        cancelEl.style.display = opts.cancel === false ? 'none' : '';
        onOk = opts.onOk || null;
        ov.classList.add('show');
        if (opts.autoMs) {
            timer = setTimeout(function () {
                timer = null;
                var fn = onOk; onOk = null;
                ov.classList.remove('show');
                if (fn) fn();
            }, opts.autoMs);
        }
    }

    window.mdlgClose = function () {
        if (timer) { clearTimeout(timer); timer = null; }
        ov.classList.remove('show');
        onOk = null;
    };

    // 删除确认弹窗
    window.mdlgConfirm = function (text, cb, opts) {
        opts = opts || {};
        open({
            icon: opts.icon || 'warn',
            btn: opts.btn || 'danger',
            title: opts.title || '确认删除',
            text: text,
            okText: opts.okText || '确认删除',
            cancel: true,
            onOk: cb
        });
    };

    // 结果提示弹窗：ok=true 成功 / false 失败；autoMs>0 时自动关闭并执行回调
    window.mdlgAlert = function (text, ok, cb, autoMs) {
        open({
            icon: ok ? 'success' : 'error',
            btn: 'primary',
            title: ok ? '操作成功' : '操作失败',
            text: text,
            okText: '知道了',
            cancel: false,
            onOk: cb,
            autoMs: autoMs || 0
        });
    };

    okEl.onclick = function () { var fn = onOk; window.mdlgClose(); if (fn) fn(); };
    cancelEl.onclick = function () { window.mdlgClose(); };
    ov.onclick = function (e) { if (e.target === ov) window.mdlgClose(); };
})();
</script>
HTML;
}
