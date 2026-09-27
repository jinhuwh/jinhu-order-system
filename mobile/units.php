<?php
/**
 * 移动端 - 产品单位管理
 * Admin only. AJAX CRUD with CSRF protection.
 */
require_once 'config.php';
initDatabase();
checkLogin();

if (!isAdmin()) {
    die('<script>alert("无权限访问");location.href="index.php";</script>');
}

$db = getDB();

// ── AJAX POST handler ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        echo json_encode(['ok' => false, 'msg' => '安全验证失败']);
        exit;
    }

    $action = $_POST['ajax_action'];

    if ($action === 'add') {
        $name       = trim($_POST['name'] ?? '');
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
        $id         = intval($_POST['id'] ?? 0);
        $name       = trim($_POST['name'] ?? '');
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

        // Get unit name for check
        $stmt = $db->prepare("SELECT name FROM product_units WHERE id = ?");
        $stmt->execute([$id]);
        $unitName = $stmt->fetchColumn();

        if ($unitName) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE unit = ?");
            $stmt->execute([$unitName]);
            if ($stmt->fetchColumn() > 0) {
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
        }

        $stmt = $db->prepare("DELETE FROM product_units WHERE id = ?");
        $stmt->execute([$id]);

        logOperation('delete_unit', ['id' => $id]);
        echo json_encode(['ok' => true, 'msg' => '单位删除成功']);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => '未知操作']);
    exit;
}

// ── Page load: fetch units + usage stats ─────────────────────────────────
$units = $db->query("SELECT * FROM product_units ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);

$stats = [];
foreach ($units as $unit) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE unit = ?");
    $stmt->execute([$unit['name']]);
    $stats[$unit['name']] = $stmt->fetchColumn();
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>产品单位管理</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .unit-item {
            background: white;
            border-radius: var(--radius-lg);
            padding: 16px;
            margin-bottom: 12px;
            box-shadow: var(--shadow-card);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .unit-item .icon-wrap {
            width: 44px;
            height: 44px;
            border-radius: var(--radius);
            background: var(--primary-bg);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        .unit-item .info { flex: 1; min-width: 0; }
        .unit-item .name {
            font-weight: 650;
            font-size: 15px;
            color: var(--gray-800);
        }
        .unit-item .meta {
            font-size: 12px;
            color: var(--gray-400);
            margin-top: 2px;
        }
        .unit-item .badge-count {
            background: var(--primary-bg);
            color: var(--primary-dark);
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: var(--radius-full);
            flex-shrink: 0;
        }
        .unit-item .actions {
            display: flex;
            gap: 8px;
            flex-shrink: 0;
        }
        .unit-item .btn-xs {
            padding: 5px 12px;
            font-size: 12px;
            border-radius: var(--radius-sm);
            border: none;
            cursor: pointer;
            font-weight: 600;
        }
        .unit-item .btn-edit {
            background: var(--primary-bg);
            color: var(--primary-dark);
        }
        .unit-item .btn-del {
            background: var(--danger-bg);
            color: var(--danger);
        }
        .modal-field { margin-bottom: 14px; }
        .modal-field label {
            display: block;
            margin-bottom: 5px;
            color: var(--gray-700);
            font-weight: 600;
            font-size: 13px;
        }
        .modal-field .form-control {
            width: 100%;
            padding: 11px 12px;
            border: 1.5px solid var(--gray-200);
            border-radius: var(--radius-sm);
            font-size: 15px;
            background: white;
            color: var(--gray-800);
        }
        .modal-field .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
        }
        .modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        .modal-actions .btn { flex: 1; padding: 13px; font-size: 15px; border-radius: var(--radius-sm); font-weight: 600; border: none; cursor: pointer; }
        .modal-actions .btn-cancel { background: var(--gray-100); color: var(--gray-600); }
        .modal-actions .btn-submit { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; box-shadow: 0 2px 8px rgba(59,130,246,0.3); }
        .page-subtitle { font-size: 13px; color: rgba(255,255,255,0.7); margin-top: 3px; position: relative; z-index: 1; }
    </style>
</head>
<body>
    <div class="page-header">
        <h1>📏 产品单位管理</h1>
        <p class="page-subtitle">计量单位 · 便于产品录入</p>
    </div>

    <div class="page">
        <!-- Top action bar -->
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <span style="color:var(--gray-500);font-size:13px;">共 <?php echo count($units); ?> 个单位</span>
            <button onclick="openAddModal()" class="btn btn-primary btn-sm">➕ 添加单位</button>
        </div>

        <!-- Unit list -->
        <?php if (empty($units)): ?>
            <div class="empty-state">
                <div class="icon">📏</div>
                <div class="text">暂无单位，点击上方按钮添加</div>
            </div>
        <?php else: foreach ($units as $unit):
            $count = $stats[$unit['name']] ?? 0;
        ?>
            <div class="unit-item"
                 data-id="<?php echo $unit['id']; ?>"
                 data-name="<?php echo h($unit['name']); ?>"
                 data-short="<?php echo h($unit['short_name']); ?>"
                 data-sort="<?php echo $unit['sort_order']; ?>">
                <div class="icon-wrap">📏</div>
                <div class="info">
                    <div class="name"><?php echo h($unit['name']); ?></div>
                    <div class="meta">
                        <?php if ($unit['short_name']): ?>缩写: <?php echo h($unit['short_name']); ?> · <?php endif; ?>
                        排序: <?php echo $unit['sort_order']; ?>
                    </div>
                </div>
                <span class="badge-count"><?php echo $count; ?> 个产品</span>
                <div class="actions">
                    <button class="btn-xs btn-edit" onclick="openEditModal(<?php echo $unit['id']; ?>)">编辑</button>
                    <button class="btn-xs btn-del" onclick="deleteUnit(<?php echo $unit['id']; ?>, '<?php echo h($unit['name']); ?>')">删除</button>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- Modal: Add / Edit -->
    <div class="modal-overlay" id="modal">
        <div class="modal-sheet">
            <div style="font-size:18px;font-weight:700;color:var(--gray-800);margin-bottom:20px;" id="modalTitle">添加单位</div>
            <form id="unitForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="ajax_action" id="formAction" value="add">
                <input type="hidden" name="id" id="formId" value="">

                <div class="modal-field">
                    <label>单位名称 *</label>
                    <input type="text" name="name" id="formName" class="form-control" placeholder="如：平方米、个、米" required maxlength="20">
                </div>

                <div class="modal-field">
                    <label>缩写（可选）</label>
                    <input type="text" name="short_name" id="formShort" class="form-control" placeholder="如：㎡、个、M" maxlength="10">
                </div>

                <div class="modal-field">
                    <label>排序（数字越小越靠前）</label>
                    <input type="number" name="sort_order" id="formSort" class="form-control" value="0" min="0" max="999">
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-cancel" onclick="closeModal()">取消</button>
                    <button type="submit" class="btn btn-submit">保存</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    const csrfToken = '<?php echo $csrfToken; ?>';

    // 【2026-09-15】新增时排序自动填写为当前最大排序+1
    function nextSort() {
        let max = 0;
        document.querySelectorAll('.unit-item[data-sort]').forEach(function(r) {
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
        const row = document.querySelector(`.unit-item[data-id="${id}"]`);
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
            const resp = await fetch('units.php', { method: 'POST', body: formData });
            const result = await resp.json();
            if (result.ok) {
                mdlgAlert(result.msg, true, function(){ location.reload(); }, 800);
            } else {
                mdlgAlert(result.msg, false);
            }
        } catch(err) {
            mdlgAlert('请求失败：' + err.message, false);
        }
    };

    // 【2026-09-15】统一弹窗：确认 -> 提交 -> 结果提示
    async function deleteUnit(id, name) {
        mdlgConfirm('确定要删除单位「' + name + '」吗？\n删除后不可恢复。', async function() {
            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('ajax_action', 'delete');
            formData.append('id', id);
            try {
                const resp = await fetch('units.php', { method: 'POST', body: formData });
                const result = await resp.json();
                if (result.ok) {
                    mdlgAlert(result.msg, true, function(){ location.reload(); }, 800);
                } else {
                    mdlgAlert(result.msg, false);
                }
            } catch(err) {
                mdlgAlert('请求失败：' + err.message, false);
            }
        }, { title: '删除单位' });
    }

    document.getElementById('modal').onclick = function(e) {
        if (e.target === this) closeModal();
    };
    </script>

    <?php echo mobileDialog(); ?>
</body>
</html>
<?php echo mobileNav('more'); ?>
