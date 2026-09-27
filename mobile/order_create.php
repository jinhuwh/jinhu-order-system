<?php
/**
 * 移动端 - 新建订单（含产品搜索/常用/最近/历史价）
 */
require_once 'config.php';
initDatabase();
checkLogin();

$db = getDB();
$companyName = getSetting('company_name') ?: '广告公司';
$customers = $db->query("SELECT * FROM customers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// AJAX：加载某客户最近一次订单明细
if (isset($_GET['action']) && $_GET['action'] == 'last_order' && isset($_GET['customer_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $cid = intval($_GET['customer_id']);
    if ($cid <= 0) { echo json_encode(['ok' => false]); exit; }
    $stmt = $db->prepare("SELECT o.id, o.order_no, o.order_date, o.total_amount
                           FROM orders o
                           WHERE o.customer_id = ? AND o.status != 4
                           ORDER BY o.created_at DESC LIMIT 1");
    $stmt->execute([$cid]);
    $lastOrder = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$lastOrder) { echo json_encode(['ok' => false, 'msg' => '该客户暂无历史订单']); exit; }
    $itemsStmt = $db->prepare("SELECT product_name, specification, quantity, unit, unit_price, amount, remark
                               FROM order_items WHERE order_id = ?");
    $itemsStmt->execute([$lastOrder['id']]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'order' => $lastOrder, 'items' => $items]);
    exit;
}

$csrfToken = generateCsrfToken();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'msg' => '非法请求']); exit;
    }
    $customerId = intval($_POST['customer_id'] ?? 0);
    if ($customerId <= 0) { echo json_encode(['ok' => false, 'msg' => '请选择客户']); exit; }

    $orderNo = generateOrderNo();
    $remark = trim($_POST['remark'] ?? '');
    $totalAmount = 0;

    try {
        $db->beginTransaction();
        $stmt = $db->prepare("INSERT INTO orders (order_no, customer_id, order_date, total_amount, remark) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$orderNo, $customerId, date('Y-m-d'), 0, $remark]);
        $orderId = $db->lastInsertId();

        $pNames = $_POST['product_name'] ?? [];
        $specs = $_POST['specification'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $units = $_POST['unit'] ?? [];
        $prices = $_POST['unit_price'] ?? [];
        $amounts = $_POST['amount'] ?? [];
        $remarks = $_POST['item_remark'] ?? [];

        for ($i = 0; $i < count($pNames); $i++) {
            $pName = trim($pNames[$i] ?? '');
            if (empty($pName)) continue;
            $qty = floatval($quantities[$i] ?? 0);
            $price = floatval($prices[$i] ?? 0);
            $amt = floatval($amounts[$i] ?? ($qty * $price));
            $stmt2 = $db->prepare("INSERT INTO order_items (order_id, product_name, specification, quantity, unit, unit_price, amount, remark) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt2->execute([$orderId, $pName, $specs[$i] ?? '', $qty, $units[$i] ?? '', $price, $amt, $remarks[$i] ?? '']);
            $totalAmount += $amt;
        }
        $db->prepare("UPDATE orders SET total_amount = ? WHERE id = ?")->execute([$totalAmount, $orderId]);
        $db->commit();
        echo json_encode(['ok' => true, 'id' => $orderId]);
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['ok' => false, 'msg' => '保存失败']);
    }
    exit;
}

// 产品 + 使用次数（按历史订单数降序，datalist 内显示）
$products = $db->query("
    SELECT p.*, COALESCE(u.cnt, 0) as usage_count
    FROM products p
    LEFT JOIN (
        SELECT oi.product_name, COUNT(DISTINCT oi.order_id) as cnt
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        WHERE o.status != 4
        GROUP BY oi.product_name
    ) u ON p.name = u.product_name
    ORDER BY u.cnt DESC, p.category, p.name
")->fetchAll(PDO::FETCH_ASSOC);

// 最近用过的产品
$recentProducts = $db->query("
    SELECT oi.product_name, MAX(o.created_at) as last_used
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    WHERE o.status != 4 AND oi.product_name IS NOT NULL AND oi.product_name != ''
    GROUP BY oi.product_name
    ORDER BY last_used DESC
    LIMIT 12
")->fetchAll(PDO::FETCH_ASSOC);

// 每个产品最近 3 次成交价
$historyPrices = [];
$hpStmt = $db->query("
    SELECT oi.product_name, oi.unit_price, o.created_at
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    WHERE oi.unit_price > 0 AND o.status != 4
    ORDER BY o.created_at DESC
");
while ($row = $hpStmt->fetch(PDO::FETCH_ASSOC)) {
    $name = $row['product_name'];
    if (!isset($historyPrices[$name])) $historyPrices[$name] = [];
    if (count($historyPrices[$name]) < 3) {
        $historyPrices[$name][] = ['price' => floatval($row['unit_price']), 'date' => substr($row['created_at'], 5, 5)];
    }
}

// 准备给 JS 用的产品数据
$jsProducts = [];
foreach ($products as $p) {
    $jsProducts[] = [
        'name' => $p['name'],
        'category' => $p['category'] ?: '未分类',
        'unit' => $p['unit'] ?? '',
        'price' => floatval($p['price'] ?? 0),
        'usage' => intval($p['usage_count']),
        'history' => $historyPrices[$p['name']] ?? []
    ];
}
$jsProductsJson = json_encode($jsProducts, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>新建订单 - <?php echo htmlspecialchars($companyName); ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .recent-bar-m {
            display: flex;
            gap: 6px;
            overflow-x: auto;
            padding: 8px 12px;
            background: var(--gray-50);
            border-radius: var(--radius);
            margin: 0 0 12px;
            border: 1px dashed var(--gray-200);
            -webkit-overflow-scrolling: touch;
        }
        .recent-bar-m::-webkit-scrollbar { display: none; }
        .recent-bar-m .recent-label {
            font-size: 11px;
            color: var(--gray-500);
            font-weight: 600;
            display: flex;
            align-items: center;
            flex-shrink: 0;
        }
        .recent-bar-m .recent-chip {
            flex-shrink: 0;
            padding: 4px 10px;
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: 99px;
            font-size: 12px;
            cursor: pointer;
            white-space: nowrap;
        }
        .recent-bar-m .recent-chip:active { background: var(--primary); color: white; }

        .last-order-m {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 12px;
            background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
            border: 1px solid #7dd3fc;
            border-radius: var(--radius);
            margin: 10px 0;
            font-size: 12px;
        }
        .last-order-m.hidden { display: none; }
        .last-order-m .copy-btn {
            background: var(--primary);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            font-weight: 600;
            flex-shrink: 0;
        }
        .last-order-m .info { flex: 1; line-height: 1.5; }

        .price-hint-m {
            font-size: 11px;
            color: var(--gray-500);
            margin-top: 4px;
            line-height: 1.5;
        }
        .price-hint-m strong { color: var(--primary); }

        .order-item-m {
            background: white;
            border-radius: var(--radius);
            padding: 14px;
            margin-bottom: 12px;
            box-shadow: var(--shadow);
        }
        .order-item-m .row-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 1px dashed var(--gray-100);
        }
        .order-item-m .row-num {
            font-weight: 700;
            color: var(--primary);
            font-size: 13px;
        }
        .order-item-m .row-del {
            color: var(--danger);
            font-size: 12px;
            text-decoration: none;
        }
        .product-input-wrap {
            position: relative;
        }
        .product-input-wrap .prod-usage-badge {
            position: absolute;
            right: 36px;
            top: 50%;
            transform: translateY(-50%);
            background: var(--primary);
            color: white;
            font-size: 10px;
            padding: 1px 6px;
            border-radius: 99px;
            pointer-events: none;
            display: none;
        }
        /* 产品下拉建议（兼容 iOS/Android datalist 不支持的情况） */
        .product-suggest {
            position: absolute;
            z-index: 9999;
            background: white;
            border: 1px solid var(--gray-100);
            border-radius: var(--radius);
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
            max-height: 260px;
            overflow-y: auto;
            margin-top: 2px;
            left: 0;
            right: 0;
        }
        .suggest-item {
            display: flex;
            flex-direction: column;
            gap: 2px;
            padding: 10px 14px;
            cursor: pointer;
            border-bottom: 1px solid var(--gray-100);
            transition: background 0.15s;
        }
        .suggest-item:last-child { border-bottom: none; }
        .suggest-item:hover, .suggest-item.active { background: var(--primary-bg); }
        .suggest-item .sname { font-size: 14px; font-weight: 600; color: var(--gray-900); }
        .suggest-item .smeta { font-size: 11px; color: var(--gray-500); }
    </style>
</head>
<body>
    <div class="page-header">
        <h1>➕ 新建订单</h1>
    </div>

    <div class="page">
        <form id="orderForm" method="POST" onsubmit="return submitOrder(event)">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            <input type="hidden" name="submit" value="1">

            <!-- 客户选择 -->
            <div class="card">
                <div class="card-title">👤 选择客户 *</div>
                <select name="customer_id" id="customerSelect" class="form-control" required onchange="checkLastOrder()">
                    <option value="">— 请选择客户 —</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?php echo $c['id']; ?>">
                        <?php echo htmlspecialchars($c['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 复制历史订单 -->
            <div id="lastOrderBanner" class="last-order-m hidden">
                <div class="info">
                    📋 上次订单：<strong id="lastOrderNo"></strong>
                    <span id="lastOrderDate"></span> · <span id="lastOrderTotal"></span> · <span id="lastOrderItems"></span>
                </div>
                <button type="button" class="copy-btn" onclick="copyLastOrder()">📥 复制</button>
            </div>

            <!-- 订单明细 -->
            <div class="card">
                <div class="card-title" style="justify-content:space-between;display:flex;align-items:center;">
                    <span>📦 订单明细</span>
                    <a href="javascript:void(0)" onclick="addRow()" style="font-size:13px;color:var(--primary);text-decoration:none;font-weight:600;">+ 添加一行</a>
                </div>

                <!-- 🕐 最近用过的产品 -->
                <?php if (!empty($recentProducts)): ?>
                <div class="recent-bar-m">
                    <span class="recent-label">🕐 最近</span>
                    <?php foreach ($recentProducts as $rp): ?>
                    <span class="recent-chip" data-product="<?php echo htmlspecialchars($rp['product_name']); ?>" onclick="quickFill(this)">
                        <?php echo htmlspecialchars($rp['product_name']); ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div id="itemsContainer"></div>
            </div>

            <!-- 备注 -->
            <div class="card">
                <div class="card-title">📝 备注说明</div>
                <textarea name="remark" class="form-control" rows="3" placeholder="订单备注（选填）"></textarea>
            </div>

            <button type="submit" class="btn btn-primary btn-block btn-lg" style="margin-bottom:80px;">✅ 提交订单</button>
        </form>
    </div>

    <!-- 产品下拉菜单（自定义实现，兼容 iOS/Android datalist 不支持） -->
    <div id="productDropdown" class="product-dropdown" style="display:none;"></div>

    <?php echo mobileNav('create'); ?>

    <script>
    function h(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    var ALL_PRODUCTS = <?php echo $jsProductsJson; ?>;
    var lastOrderCache = null;

    function addRow(prefill) {
        var container = document.getElementById('itemsContainer');
        var idx = container.children.length;
        var html = '<div class="order-item-m">' +
            '<div class="row-head">' +
                '<span class="row-num">产品 #' + (idx + 1) + '</span>' +
                '<a href="javascript:void(0)" class="row-del" onclick="removeRow(this)">🗑️ 删除</a>' +
            '</div>' +
            '<div class="form-group product-input-wrap">' +
                '<label>产品名称 *</label>' +
                '<input type="text" name="product_name[]" class="form-control prod-name-input prod-name-input-native" placeholder="输入或选择产品" required autocomplete="off">' +
                '<span class="prod-usage-badge" data-usage-badge></span>' +
                '<div class="product-suggest" id="suggestList" style="display:none;"></div>' +
            '</div>' +
            '<div class="form-group">' +
                '<label>规格</label>' +
                '<input type="text" name="specification[]" class="form-control" placeholder="规格">' +
            '</div>' +
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">' +
                '<div class="form-group">' +
                    '<label>数量</label>' +
                    '<input type="number" name="quantity[]" class="form-control" placeholder="0" min="0" step="0.01" oninput="calcAmount(this)">' +
                '</div>' +
                '<div class="form-group">' +
                    '<label>单位</label>' +
                    '<input type="text" name="unit[]" class="form-control" placeholder="个/米/平方">' +
                '</div>' +
            '</div>' +
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">' +
                '<div class="form-group">' +
                    '<label>单价</label>' +
                    '<input type="number" name="unit_price[]" class="form-control" placeholder="0.00" min="0" step="0.01" oninput="calcAmount(this)">' +
                '</div>' +
                '<div class="form-group">' +
                    '<label>金额</label>' +
                    '<input type="number" name="amount[]" class="form-control" placeholder="0.00" readonly style="background:#f5f5f5;">' +
                '</div>' +
            '</div>' +
            '<div class="price-hint-m" data-price-hint></div>' +
            '<div class="form-group">' +
                '<label>备注</label>' +
                '<input type="text" name="item_remark[]" class="form-control" placeholder="制作要求">' +
            '</div>' +
        '</div>';
        container.insertAdjacentHTML('beforeend', html);
        var newItem = container.lastElementChild;
        bindProductInput(newItem);

        if (prefill) {
            var nameInput = newItem.querySelector('.prod-name-input');
            nameInput.value = prefill.name;
            if (prefill.unit) newItem.querySelector('[name="unit[]"]').value = prefill.unit;
            if (prefill.price) newItem.querySelector('[name="unit_price[]"]').value = prefill.price;
            if (prefill.specification) newItem.querySelector('[name="specification[]"]').value = prefill.specification;
            if (prefill.quantity) newItem.querySelector('[name="quantity[]"]').value = prefill.quantity;
            showProductMeta(newItem, prefill.name);
            calcAmount(newItem.querySelector('[name="unit_price[]"]'));
        }
    }

    // 点击页面其他地方关闭下拉
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.product-input-wrap')) {
            document.querySelectorAll('.product-suggest').forEach(function(el){ el.style.display='none'; });
        }
    });

    function bindProductInput(item) {
        var nameInput = item.querySelector('.prod-name-input');
        var suggestEl = item.querySelector('.product-suggest');

        // input 事件：实时过滤 + 显示下拉
        nameInput.addEventListener('input', function() {
            onProductNameInput(item, nameInput, suggestEl);
        });

        // 点击/聚焦：显示已有输入的匹配结果
        nameInput.addEventListener('focus', function() {
            onProductNameInput(item, nameInput, suggestEl);
        });

        // 键盘支持
        nameInput.addEventListener('keydown', function(e) {
            var suggestions = suggestEl.querySelectorAll('.suggest-item');
            var active = suggestEl.querySelector('.suggest-item.active');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!suggestEl.style.display || suggestEl.style.display === 'none') {
                    onProductNameInput(item, nameInput, suggestEl);
                } else if (active) {
                    active.classList.remove('active');
                    active.nextElementSibling && active.nextElementSibling.classList.add('active');
                } else if (suggestions.length > 0) {
                    suggestions[0].classList.add('active');
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (active && active.previousElementSibling) {
                    active.classList.remove('active');
                    active.previousElementSibling.classList.add('active');
                }
            } else if (e.key === 'Enter') {
                if (active) {
                    e.preventDefault();
                    selectSuggestion(item, nameInput, suggestEl, active);
                }
            } else if (e.key === 'Escape') {
                suggestEl.style.display = 'none';
            }
        });

        // 失焦时稍延迟关闭（让 click 事件先触发）
        nameInput.addEventListener('blur', function() {
            setTimeout(function(){ suggestEl.style.display = 'none'; }, 200);
        });
    }

    function onProductNameInput(item, nameInput, suggestEl) {
        var q = nameInput.value.trim().toLowerCase();
        showProductMeta(item, q);
        var matches;
        if (!q) {
            // 空值：显示默认前 8 条（按使用次数倒序），让用户感知到有下拉
            matches = ALL_PRODUCTS.slice().sort(function(a,b){
                return (b.usage||0) - (a.usage||0);
            }).slice(0, 8);
            if (matches.length === 0) { suggestEl.style.display = 'none'; return; }
        } else {
            matches = ALL_PRODUCTS.filter(function(p) {
                return p.name.toLowerCase().indexOf(q) !== -1 ||
                       (p.category && p.category.toLowerCase().indexOf(q) !== -1);
            }).slice(0, 8);
            if (matches.length === 0) { suggestEl.style.display = 'none'; return; }
        }
        var html = '';
        matches.forEach(function(p, i) {
            html += '<div class="suggest-item' + (i === 0 ? ' active' : '') + '" ' +
                    'data-name="' + h(p.name) + '" ' +
                    'data-unit="' + h(p.unit) + '" ' +
                    'data-price="' + p.price + '" ' +
                    'data-category="' + h(p.category) + '" ' +
                    'data-usage="' + p.usage + '" ' +
                    'onclick="selectSuggestion(getItemRoot(this), this.closest(\x27.order-item-m\x27).querySelector(\x27.prod-name-input\x27), this.closest(\x27.product-suggest\x27), this)"> ' +
                    '<span class="sname">' + h(p.name) + '</span>' +
                    '<span class="smeta">' + h(p.category) + ' · ' + (p.price ? '\xA5' + p.price.toFixed(2) : '') + (p.usage > 0 ? ' · ' + p.usage + '次' : '') + '</span>' +
                    '</div>';
        });
        suggestEl.innerHTML = html;
        suggestEl.style.display = 'block';
        // 限制下拉宽度不超过输入框
        var wrap = item.querySelector('.product-input-wrap');
        suggestEl.style.width = Math.min(wrap.offsetWidth, window.innerWidth - 32) + 'px';
    }

    function selectSuggestion(item, nameInput, suggestEl, el) {
        var name = el.dataset.name;
        var unit = el.dataset.unit;
        var price = parseFloat(el.dataset.price) || 0;
        nameInput.value = name;
        suggestEl.style.display = 'none';
        if (unit) item.querySelector('[name="unit[]"]').value = unit;
        if (price) {
            item.querySelector('[name="unit_price[]"]').value = price;
            calcAmount(item.querySelector('[name="unit_price[]"]'));
        }
        showProductMeta(item, name);
        // 下一个 input 聚焦
        var next = item.querySelector('[name="specification[]"]');
        next && next.focus();
    }

    function getItemRoot(el) {
        return el.closest('.order-item-m') || document.body;
    }

    function showProductMeta(item, name) {
        var p = ALL_PRODUCTS.find(function(x){ return x.name === name; });
        var hint = item.querySelector('[data-price-hint]');
        var badge = item.querySelector('[data-usage-badge]');
        if (!p) {
            hint.innerHTML = name ? '<span style="color:var(--danger);">⚠️ 产品库中无此产品（将作为自由文本保存）</span>' : '';
            badge.style.display = 'none';
            return;
        }
        // 使用次数 badge
        if (p.usage > 0) {
            badge.textContent = p.usage + '次';
            badge.style.display = 'inline';
        } else {
            badge.style.display = 'none';
        }
        // 历史价 hint
        if (p.history && p.history.length > 0) {
            var hh = '📊 历史: ';
            p.history.forEach(function(h, i){ if (i>0) hh += ' / '; hh += '<strong>¥' + h.price.toFixed(2) + '</strong>(' + h.date + ')'; });
            hh += ' · 分类: ' + p.category;
            hint.innerHTML = hh;
        } else {
            hint.innerHTML = '📁 分类: ' + p.category;
        }
    }

    function removeRow(btn) {
        var items = document.querySelectorAll('.order-item-m');
        if (items.length <= 1) { alert('至少保留一行'); return; }
        btn.closest('.order-item-m').remove();
    }

    function calcAmount(input) {
        var item = input.closest('.order-item-m');
        var qty = parseFloat(item.querySelector('[name="quantity[]"]').value) || 0;
        var price = parseFloat(item.querySelector('[name="unit_price[]"]').value) || 0;
        item.querySelector('[name="amount[]"]').value = (qty * price).toFixed(2);
    }

    function quickFill(chip) {
        var productName = chip.dataset.product;
        var targetItem = null;
        var items = document.querySelectorAll('.order-item-m');
        for (var i = 0; i < items.length; i++) {
            if (!items[i].querySelector('.prod-name-input').value) {
                targetItem = items[i];
                break;
            }
        }
        if (!targetItem) {
            addRow({name: productName});
            return;
        }
        var p = ALL_PRODUCTS.find(function(x){ return x.name === productName; });
        if (!p) return;
        targetItem.querySelector('.prod-name-input').value = p.name;
        if (p.unit) targetItem.querySelector('[name="unit[]"]').value = p.unit;
        if (p.price) targetItem.querySelector('[name="unit_price[]"]').value = p.price;
        showProductMeta(targetItem, p.name);
        calcAmount(targetItem.querySelector('[name="unit_price[]"]'));
    }

    function checkLastOrder() {
        var cid = document.getElementById('customerSelect').value;
        var banner = document.getElementById('lastOrderBanner');
        if (!cid) { banner.classList.add('hidden'); lastOrderCache = null; return; }
        fetch('order_create.php?action=last_order&customer_id=' + cid)
            .then(function(r){ return r.json(); })
            .then(function(data) {
                if (data.ok) {
                    lastOrderCache = data;
                    document.getElementById('lastOrderNo').textContent = data.order.order_no;
                    document.getElementById('lastOrderDate').textContent = '(' + data.order.order_date + ')';
                    document.getElementById('lastOrderTotal').textContent = '¥' + parseFloat(data.order.total_amount).toFixed(2);
                    document.getElementById('lastOrderItems').textContent = data.items.length + '项';
                    banner.classList.remove('hidden');
                } else {
                    banner.classList.add('hidden');
                    lastOrderCache = null;
                }
            })
            .catch(function(){ banner.classList.add('hidden'); });
    }

    function copyLastOrder() {
        if (!lastOrderCache || !lastOrderCache.items || lastOrderCache.items.length === 0) return;
        if (!confirm('将用上次订单的 ' + lastOrderCache.items.length + ' 个产品替换当前明细？')) return;
        var container = document.getElementById('itemsContainer');
        container.innerHTML = '';
        lastOrderCache.items.forEach(function(item) {
            addRow({
                name: item.product_name,
                unit: item.unit,
                price: parseFloat(item.unit_price) || 0,
                specification: item.specification,
                quantity: parseFloat(item.quantity) || 1
            });
        });
        document.getElementById('lastOrderBanner').classList.add('hidden');
    }

    function submitOrder(e) {
        e.preventDefault();
        if (!confirm('确认提交订单？')) return false;
        var form = document.getElementById('orderForm');
        var formData = new FormData(form);
        var btn = form.querySelector('button[type="submit"]');
        var oldText = btn.textContent;
        btn.disabled = true;
        btn.textContent = '提交中...';
        fetch('order_create.php', { method: 'POST', body: formData })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (data.ok) {
                alert('✅ 订单创建成功！');
                location.href = 'order_view.php?id=' + data.id;
            } else {
                alert('❌ 提交失败：' + data.msg);
                btn.disabled = false;
                btn.textContent = oldText;
            }
        })
        .catch(function(err) {
            alert('❌ 请求失败：' + err);
            btn.disabled = false;
            btn.textContent = oldText;
        });
        return false;
    }

    // 初始添加一行
    addRow();
    </script>
</body>
</html>
