<?php
/**
 * 手机端 - 订单编辑
 * 支持：修改客户、订单日期、产品明细、备注
 * 权限：待确认/已确认可改，生产中/已完成/已取消禁止修改
 */

require_once 'config.php';
initDatabase();
checkLogin();

$db = getDB();

// 获取订单ID
$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    echo '<script>alert("无效订单");history.back();</script>';
    exit;
}

// 获取订单信息
$order = $db->prepare("SELECT o.*, c.name as customer_name, c.contact, c.phone, c.address 
    FROM orders o 
    LEFT JOIN customers c ON o.customer_id = c.id 
    WHERE o.id = ?");
$order->execute([$id]);
$order = $order->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    echo '<script>alert("订单不存在");location.href="order_list.php";</script>';
    exit;
}

// 数据权限校验：普通用户只能编辑自己创建的订单，管理员可编辑全部
$currentUser = getCurrentUser();
if ($currentUser['role'] != 2 && $order['created_by'] != $currentUser['id']) {
    echo '<script>alert("无权编辑该订单");location.href="order_list.php";</script>';
    exit;
}

// 状态检查：生产中/已完成/已取消禁止修改
if (in_array($order['status'], [2, 3, 4])) {
    $statusText = getStatusText($order['status']);
    echo '<script>alert("订单状态为「'.$statusText.'」，禁止修改");location.href="order_view.php?id='.$id.'";</script>';
    exit;
}

// 获取订单明细
$items = $db->prepare("SELECT * FROM order_items WHERE order_id = ?");
$items->execute([$id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

// 处理修改提交
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // CSRF验证
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        echo json_encode(['ok' => false, 'msg' => '非法请求']);
        exit;
    }
    
    try {
        $db->beginTransaction();
        
        // 1. 更新客户
        if (!empty($_POST['new_customer_name'])) {
            // 新客户
            $stmt = $db->prepare("INSERT INTO customers (name, contact, phone, address) VALUES (?, ?, ?, ?)");
            $stmt->execute([$_POST['new_customer_name'], $_POST['contact'], $_POST['phone'], $_POST['address']]);
            $customer_id = $db->lastInsertId();
        } else {
            $customer_id = intval($_POST['customer_id'] ?? 0);
            if ($customer_id <= 0) {
                throw new Exception('请选择客户');
            }
        }
        
        // 2. 订单日期
        $order_date = $_POST['order_date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $order_date)) {
            $order_date = date('Y-m-d');
        }
        
        // 3. 更新订单主表
        $stmt = $db->prepare("UPDATE orders SET customer_id = ?, order_date = ?, remark = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$customer_id, $order_date, $_POST['order_remark'] ?? '', $id]);
        
        // 4. 删除旧的订单明细
        $db->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$id]);
        
        // 5. 重新插入订单明细
        $total_amount = 0;
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $item) {
                if (empty($item['product_name'])) continue;
                
                $amount = floatval($item['quantity']) * floatval($item['unit_price']);
                $total_amount += $amount;
                
                $stmt = $db->prepare("INSERT INTO order_items 
                    (order_id, product_name, specification, quantity, unit, unit_price, amount, remark) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $id, 
                    $item['product_name'] ?? '', 
                    $item['specification'] ?? '', 
                    floatval($item['quantity'] ?? 0), 
                    $item['unit'] ?? '', 
                    floatval($item['unit_price'] ?? 0), 
                    $amount, 
                    $item['remark'] ?? ''
                ]);
            }
        }
        
        // 6. 更新订单总金额，并校正已收款（防止改小金额后待收变负，与 PC 端 P0-3 修复一致）
        $curPaid = floatval($order['paid_amount'] ?? 0);
        $curDiscount = floatval($order['discount_amount'] ?? 0);
        $newPaid = min($curPaid, $total_amount);
        $paymentStatus = ($newPaid + $curDiscount) >= ($total_amount - 0.01) ? 2 : ($newPaid > 0 ? 1 : 0);
        $db->prepare("UPDATE orders SET total_amount = ?, paid_amount = ?, payment_status = ? WHERE id = ?")
            ->execute([$total_amount, $newPaid, $paymentStatus, $id]);
        
        $db->commit();
        
        echo '<script>alert("✅ 订单修改成功");location.href="order_view.php?id='.$id.'&edited=1";</script>';
        exit;
        
    } catch (Exception $e) {
        $db->rollBack();
        $error = $e->getMessage();
    }
}

// 获取客户列表和产品列表
$customers = $db->query("SELECT * FROM customers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$products = $db->query("SELECT * FROM products ORDER BY category, name")->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = generateCsrfToken();
$companyName = getSetting('company_name') ?: '广告公司';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>修改订单 - <?php echo htmlspecialchars($order['order_no']); ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .edit-warning { background: #FEF3C7; border: 2px solid #F59E0B; border-radius: var(--radius); padding: 12px 16px; margin-bottom: 16px; display: flex; align-items: center; gap: 10px; }
        .edit-warning .icon { font-size: 20px; }
        .edit-warning .text { flex: 1; font-size: 13px; color: #92400E; }
        .edit-warning .text strong { display: block; margin-bottom: 2px; }
        
        .order-no-bar { background: white; border-radius: var(--radius); padding: 14px; margin-bottom: 16px; box-shadow: var(--shadow); display: flex; justify-content: space-between; align-items: center; }
        .order-no-bar .no { font-size: 16px; font-weight: 700; color: #333; }
        .order-no-bar .status { font-size: 13px; }
        
        .card { background: white; border-radius: var(--radius); padding: 16px; margin-bottom: 16px; box-shadow: var(--shadow); }
        .card-title { font-size: 15px; font-weight: 600; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
        
        .form-group { margin-bottom: 14px; }
        .form-label { font-size: 13px; color: #666; margin-bottom: 6px; display: block; }
        .form-label .required { color: #EF4444; }
        .form-control { width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 14px; }
        .form-control:focus { border-color: var(--primary); outline: none; }
        select.form-control { background: white; }
        
        .radio-group { display: flex; gap: 20px; margin-bottom: 14px; }
        .radio-group label { font-size: 14px; cursor: pointer; }
        
        /* 产品明细 */
        .item-card { background: #f8f9fa; border-radius: var(--radius-sm); padding: 14px; margin-bottom: 12px; border: 1px solid var(--border); }
        .item-card .item-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .item-card .item-header .idx { font-size: 13px; color: #999; }
        .item-card .item-header .btn-remove { background: none; border: none; color: #EF4444; font-size: 18px; cursor: pointer; padding: 0 6px; }
        
        .item-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px; }
        .item-row:last-child { margin-bottom: 0; }
        
        .amount-display { background: white; border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 10px 12px; text-align: right; font-weight: 600; color: var(--primary); }
        
        .add-item-btn { background: #f0fdf4; border: 2px dashed #22C55E; border-radius: var(--radius-sm); padding: 12px; text-align: center; color: #16A34A; font-weight: 500; cursor: pointer; margin-bottom: 16px; }
        
        .total-bar { background: var(--primary); border-radius: var(--radius); padding: 16px; display: flex; justify-content: space-between; align-items: center; color: white; margin-bottom: 16px; }
        .total-bar .label { font-size: 14px; opacity: 0.9; }
        .total-bar .amount { font-size: 24px; font-weight: 700; }
        
        .btn-area { display: flex; gap: 12px; margin-bottom: 20px; }
        .btn-area .btn { flex: 1; text-align: center; }
        
        @media (max-width: 480px) {
            .item-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <!-- 顶部栏 -->
    <div class="page-header">
        <h1>✏️ 修改订单</h1>
    </div>
    
    <div class="page">
        <!-- 订单号 + 状态 -->
        <div class="order-no-bar">
            <div class="no"><?php echo htmlspecialchars($order['order_no']); ?></div>
            <div class="status"><span class="badge badge-<?php echo $order['status']; ?>"><?php echo getStatusText($order['status']); ?></span></div>
        </div>
        
        <?php if (isset($error)): ?>
        <div class="msg msg-error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($order['status'] == 1): ?>
        <div class="edit-warning">
            <div class="icon">⚠️</div>
            <div class="text">
                <strong>订单已确认</strong>
                修改订单明细会影响生产安排，请谨慎操作。
            </div>
        </div>
        <?php endif; ?>
        
        <form method="POST" id="orderForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            
            <!-- 客户信息 -->
            <div class="card">
                <div class="card-title">👤 客户信息</div>
                
                <div class="radio-group">
                    <label>
                        <input type="radio" name="customer_type" value="existing" <?php echo $order['customer_id'] ? 'checked' : ''; ?> onchange="toggleCustomer('existing')">
                        选择已有客户
                    </label>
                    <label>
                        <input type="radio" name="customer_type" value="new" onchange="toggleCustomer('new')">
                        添加新客户
                    </label>
                </div>
                
                <div id="existingCustomer">
                    <div class="form-group">
                        <label class="form-label">选择客户 <span class="required">*</span></label>
                        <select name="customer_id" class="form-control" id="customerSelect">
                            <option value="">请选择客户</option>
                            <?php foreach ($customers as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $c['id'] == $order['customer_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['name']); ?> (<?php echo $c['phone'] ?: '无电话'; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div id="newCustomer" style="display:none;">
                    <div class="form-group">
                        <label class="form-label">客户名称 <span class="required">*</span></label>
                        <input type="text" name="new_customer_name" class="form-control" placeholder="客户名称" id="newCustomerName">
                    </div>
                    <div class="item-row">
                        <div class="form-group">
                            <label class="form-label">联系人</label>
                            <input type="text" name="contact" class="form-control" placeholder="联系人" value="<?php echo htmlspecialchars($order['contact']); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">联系电话</label>
                            <input type="text" name="phone" class="form-control" placeholder="联系电话" value="<?php echo htmlspecialchars($order['phone']); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">地址</label>
                        <input type="text" name="address" class="form-control" placeholder="客户地址" value="<?php echo htmlspecialchars($order['address']); ?>">
                    </div>
                </div>
            </div>
            
            <!-- 订单日期 -->
            <div class="card">
                <div class="card-title">📅 订单日期</div>
                <div class="form-group">
                    <label class="form-label">开单日期 <span class="required">*</span></label>
                    <input type="date" name="order_date" class="form-control" value="<?php echo htmlspecialchars($order['order_date'] ?? date('Y-m-d', strtotime($order['created_at']))); ?>">
                    <p style="color:#999;font-size:12px;margin-top:6px;">可选择任意日期，不限于当天</p>
                </div>
            </div>
            
            <!-- 订单明细 -->
            <div class="card">
                <div class="card-title">📦 订单明细</div>
                
                <div id="itemsContainer">
                    <?php foreach ($items as $i => $item): ?>
                    <div class="item-card">
                        <div class="item-header">
                            <span class="idx">产品 #<?php echo $i + 1; ?></span>
                            <button type="button" class="btn-remove" onclick="removeItem(this)">✕</button>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">产品名称</label>
                            <select class="form-control product-select" onchange="fillProduct(this)">
                                <option value="">选择产品</option>
                                <?php foreach ($products as $p): ?>
                                <option value="<?php echo htmlspecialchars($p['name']); ?>" 
                                        data-unit="<?php echo $p['unit']; ?>"
                                        data-price="<?php echo $p['price']; ?>"
                                        <?php echo $p['name'] === $item['product_name'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($p['name']); ?> (<?php echo $p['category'] ?: '未分类'; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="items[<?php echo $i; ?>][product_name]" value="<?php echo htmlspecialchars($item['product_name']); ?>" class="product-name-input">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">规格说明</label>
                            <input type="text" name="items[<?php echo $i; ?>][specification]" class="form-control" placeholder="尺寸、材质等" value="<?php echo htmlspecialchars($item['specification']); ?>">
                        </div>
                        
                        <div class="item-row">
                            <div class="form-group">
                                <label class="form-label">数量</label>
                                <input type="number" name="items[<?php echo $i; ?>][quantity]" class="form-control" step="0.01" value="<?php echo $item['quantity']; ?>" onchange="calcAmount(this)">
                            </div>
                            <div class="form-group">
                                <label class="form-label">单位</label>
                                <input type="text" name="items[<?php echo $i; ?>][unit]" class="form-control" placeholder="个/㎡" value="<?php echo htmlspecialchars($item['unit']); ?>">
                            </div>
                        </div>
                        
                        <div class="item-row">
                            <div class="form-group">
                                <label class="form-label">单价</label>
                                <input type="number" name="items[<?php echo $i; ?>][unit_price]" class="form-control" step="0.01" value="<?php echo $item['unit_price']; ?>" onchange="calcAmount(this)">
                            </div>
                            <div class="form-group">
                                <label class="form-label">金额</label>
                                <div class="amount-display">¥<?php echo number_format($item['amount'], 2); ?></div>
                            </div>
                        </div>
                        
                        <input type="hidden" name="items[<?php echo $i; ?>][remark]" value="<?php echo htmlspecialchars($item['remark'] ?? ''); ?>">
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="add-item-btn" onclick="addItem()">➕ 添加产品</div>
                
                <div class="form-group">
                    <label class="form-label">订单备注</label>
                    <textarea name="order_remark" class="form-control" rows="3" placeholder="订单备注信息，如：交货时间、特殊要求等"><?php echo htmlspecialchars($order['remark']); ?></textarea>
                </div>
                
                <div class="total-bar">
                    <div class="label">订单总金额</div>
                    <div class="amount" id="totalAmount">¥<?php echo number_format($order['total_amount'], 2); ?></div>
                </div>
            </div>
            
            <!-- 按钮 -->
            <div class="btn-area">
                <button type="submit" class="btn btn-primary">✅ 保存修改</button>
                <a href="order_view.php?id=<?php echo $id; ?>" class="btn btn-outline">取消</a>
            </div>
        </form>
    </div>
    
    <?php echo mobileNav('orders'); ?>
    
    <script>
        let itemIndex = <?php echo count($items); ?>;
        const products = <?php echo json_encode($products); ?>;
        
        function toggleCustomer(type) {
            document.getElementById('existingCustomer').style.display = type === 'existing' ? 'block' : 'none';
            document.getElementById('newCustomer').style.display = type === 'new' ? 'block' : 'none';
            document.getElementById('customerSelect').required = type === 'existing';
            document.getElementById('newCustomerName').required = type === 'new';
        }
        
        function fillProduct(select) {
            const option = select.options[select.selectedIndex];
            const card = select.closest('.item-card');
            card.querySelector('.product-name-input').value = option.value;
            if (option.dataset.unit) {
                card.querySelector('[name$="[unit]"]').value = option.dataset.unit;
            }
            if (option.dataset.price) {
                card.querySelector('[name$="[unit_price]"]').value = option.dataset.price;
            }
            calcAmount(select);
        }
        
        function calcAmount(el) {
            const card = el.closest('.item-card');
            const qty = parseFloat(card.querySelector('[name$="[quantity]"]').value) || 0;
            const price = parseFloat(card.querySelector('[name$="[unit_price]"]').value) || 0;
            const amount = qty * price;
            card.querySelector('.amount-display').textContent = '¥' + amount.toFixed(2);
            calcTotal();
        }
        
        function calcTotal() {
            let total = 0;
            document.querySelectorAll('.item-card').forEach(card => {
                const qty = parseFloat(card.querySelector('[name$="[quantity]"]').value) || 0;
                const price = parseFloat(card.querySelector('[name$="[unit_price]"]').value) || 0;
                total += qty * price;
            });
            document.getElementById('totalAmount').textContent = '¥' + total.toFixed(2);
        }
        
        function addItem() {
            const container = document.getElementById('itemsContainer');
            const firstCard = container.querySelector('.item-card');
            const newCard = firstCard.cloneNode(true);
            
            // 更新索引
            itemIndex++;
            newCard.querySelector('.idx').textContent = '产品 #' + itemIndex;
            
            // 清空输入
            newCard.querySelectorAll('input, select').forEach(input => {
                if (input.type !== 'hidden' && !input.classList.contains('amount-display')) {
                    input.value = input.type === 'number' ? (input.name.includes('quantity') ? 1 : 0) : '';
                }
            });
            
            // 重置产品选择
            newCard.querySelector('.product-select').selectedIndex = 0;
            newCard.querySelector('.product-name-input').value = '';
            newCard.querySelector('.amount-display').textContent = '¥0.00';
            
            // 更新 name 属性中的索引
            newCard.querySelectorAll('[name]').forEach(input => {
                if (input.name) {
                    input.name = input.name.replace(/\[\d+\]/, '[' + (itemIndex - 1) + ']');
                }
            });
            
            container.appendChild(newCard);
        }
        
        function removeItem(btn) {
            const cards = document.querySelectorAll('.item-card');
            if (cards.length > 1) {
                btn.closest('.item-card').remove();
                calcTotal();
            } else {
                alert('至少需要保留一个产品项');
            }
        }
        
        // 页面加载时计算总额
        calcTotal();
    </script>
</body>
</html>
