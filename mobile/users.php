<?php
/**
 * 移动端 - 用户管理
 * Admin only. AJAX CRUD with CSRF protection.
 */
require_once 'config.php';
initDatabase();
checkLogin();

if (!isAdmin()) {
    die('<script>alert("无权限访问");location.href="index.php";</script>');
}

$db = getDB();
$msg = '';
$error = '';

// ── AJAX POST handler ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        echo json_encode(['ok' => false, 'msg' => '安全验证失败']);
        exit;
    }

    $action = $_POST['ajax_action'];

    if ($action === 'add') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $realname = trim($_POST['realname'] ?? '');
        $role     = intval($_POST['role'] ?? 1);

        if (empty($username) || empty($password)) {
            echo json_encode(['ok' => false, 'msg' => '用户名和密码不能为空']);
            exit;
        }
        if (strlen($password) < 6) {
            echo json_encode(['ok' => false, 'msg' => '密码长度至少6位']);
            exit;
        }

        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetchColumn() > 0) {
            echo json_encode(['ok' => false, 'msg' => '用户名已存在']);
            exit;
        }

        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, password, realname, role) VALUES (?, ?, ?, ?)");
        $stmt->execute([$username, $hashed, $realname, $role]);

        logOperation('add_user', ['username' => $username]);
        echo json_encode(['ok' => true, 'msg' => '用户添加成功']);
        exit;
    }

    if ($action === 'edit') {
        $id      = intval($_POST['id'] ?? 0);
        $realname = trim($_POST['realname'] ?? '');
        $role    = intval($_POST['role'] ?? 1);
        $status  = isset($_POST['status']) ? 1 : 0;

        if ($id <= 0) {
            echo json_encode(['ok' => false, 'msg' => '参数错误']);
            exit;
        }

        $stmt = $db->prepare("UPDATE users SET realname = ?, role = ?, status = ? WHERE id = ?");
        $stmt->execute([$realname, $role, $status, $id]);

        // Password update separately
        $newPw = trim($_POST['new_password'] ?? '');
        if (!empty($newPw)) {
            if (strlen($newPw) < 6) {
                echo json_encode(['ok' => false, 'msg' => '密码长度至少6位']);
                exit;
            }
            $hashed = password_hash($newPw, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed, $id]);
        }

        logOperation('edit_user', ['id' => $id]);
        echo json_encode(['ok' => true, 'msg' => '用户更新成功']);
        exit;
    }

    if ($action === 'reset_pwd') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'msg' => '参数错误']);
            exit;
        }

        // Generate random 8-char password
        $newPw = substr(bin2hex(random_bytes(4)), 0, 8);
        $hashed = password_hash($newPw, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hashed, $id]);

        logOperation('reset_password', ['id' => $id]);
        echo json_encode(['ok' => true, 'msg' => '密码已重置为: ' . $newPw, 'new_password' => $newPw]);
        exit;
    }

    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'msg' => '参数错误']);
            exit;
        }
        if ($id == $_SESSION['user_id']) {
            echo json_encode(['ok' => false, 'msg' => '不能删除当前登录用户']);
            exit;
        }

        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);

        logOperation('delete_user', ['id' => $id]);
        echo json_encode(['ok' => true, 'msg' => '用户删除成功']);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => '未知操作']);
    exit;
}

// ── Page load ─────────────────────────────────────────────────────────────
$users = $db->query("SELECT * FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>用户管理</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .user-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 16px;
            margin-bottom: 12px;
            box-shadow: var(--shadow-card);
        }
        .user-card .top-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
        }
        .user-card .avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        .user-card .info { flex: 1; min-width: 0; }
        .user-card .username {
            font-weight: 700;
            font-size: 15px;
            color: var(--gray-800);
        }
        .user-card .realname {
            font-size: 12px;
            color: var(--gray-400);
            margin-top: 1px;
        }
        .user-card .role-badge {
            padding: 3px 10px;
            border-radius: var(--radius-full);
            font-size: 11px;
            font-weight: 600;
            flex-shrink: 0;
        }
        .role-admin  { background: var(--danger-bg);  color: var(--danger); }
        .role-normal { background: var(--info-bg);    color: #0369A1; }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 12px;
            padding: 3px 10px;
            border-radius: var(--radius-full);
            margin-right: 6px;
        }
        .status-on  { background: var(--success-bg); color: #047857; }
        .status-off { background: var(--gray-100);  color: var(--gray-400); }
        .user-card .actions {
            display: flex;
            gap: 8px;
            padding-top: 12px;
            border-top: 1px solid var(--gray-100);
        }
        .user-card .btn-xs {
            flex: 1;
            padding: 8px 0;
            font-size: 13px;
            border-radius: var(--radius-sm);
            border: none;
            cursor: pointer;
            font-weight: 600;
            text-align: center;
            transition: var(--transition-base);
        }
        .btn-xs-primary { background: var(--primary-bg); color: var(--primary-dark); }
        .btn-xs-warning { background: var(--warning-bg); color: #B45309; }
        .btn-xs-danger  { background: var(--danger-bg);  color: var(--danger); }
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
            box-sizing: border-box;
        }
        .modal-field .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
        }
        .toggle-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
        }
        .toggle-label { font-size: 14px; color: var(--gray-700); font-weight: 500; }
        .toggle-switch {
            position: relative;
            width: 44px;
            height: 24px;
            border-radius: 12px;
            background: var(--gray-300);
            cursor: pointer;
            transition: var(--transition-base);
        }
        .toggle-switch.on { background: var(--success); }
        .toggle-switch::after {
            content: '';
            position: absolute;
            top: 2px;
            left: 2px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: white;
            transition: var(--transition-base);
        }
        .toggle-switch.on::after { left: 22px; }
        .modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        .modal-actions .btn { flex: 1; padding: 13px; font-size: 15px; border-radius: var(--radius-sm); font-weight: 600; border: none; cursor: pointer; }
        .modal-actions .btn-cancel { background: var(--gray-100); color: var(--gray-600); }
        .modal-actions .btn-submit { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; box-shadow: 0 2px 8px rgba(59,130,246,0.3); }
        .pwd-hint { font-size: 11px; color: var(--gray-400); margin-top: 4px; }
        .page-subtitle { font-size: 13px; color: rgba(255,255,255,0.7); margin-top: 3px; position: relative; z-index: 1; }
    </style>
</head>
<body>
    <div class="page-header">
        <h1>👥 用户管理</h1>
        <p class="page-subtitle">账户 · 角色 · 密码重置</p>
    </div>

    <div class="page">
        <!-- Top action bar -->
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <span style="color:var(--gray-500);font-size:13px;">共 <?php echo count($users); ?> 个用户</span>
            <button onclick="openAddModal()" class="btn btn-primary btn-sm">➕ 添加用户</button>
        </div>

        <!-- User list -->
        <?php if (empty($users)): ?>
            <div class="empty-state">
                <div class="icon">👥</div>
                <div class="text">暂无用户</div>
            </div>
        <?php else: foreach ($users as $u):
            $isAdmin  = ($u['role'] == 2);
            $isActive = ($u['status'] == 1);
            $isSelf   = ($u['id'] == $_SESSION['user_id']);
        ?>
            <div class="user-card"
                 data-id="<?php echo $u['id']; ?>"
                 data-username="<?php echo h($u['username']); ?>"
                 data-realname="<?php echo h($u['realname']); ?>"
                 data-role="<?php echo $u['role']; ?>"
                 data-status="<?php echo $u['status']; ?>">
                <div class="top-row">
                    <div class="avatar" style="background:<?php echo $isAdmin ? 'var(--danger-bg)' : 'var(--info-bg)'; ?>;">
                        <?php echo $isAdmin ? '👑' : '👤'; ?>
                    </div>
                    <div class="info">
                        <div class="username"><?php echo h($u['username']); ?><?php if ($isSelf): ?> <span style="font-size:11px;color:var(--primary);font-weight:600;">(我)</span><?php endif; ?></div>
                        <div class="realname"><?php echo h($u['realname'] ?: '未填姓名'); ?></div>
                    </div>
                    <span class="role-badge <?php echo $isAdmin ? 'role-admin' : 'role-normal'; ?>">
                        <?php echo $isAdmin ? '管理员' : '普通用户'; ?>
                    </span>
                </div>

                <div style="padding-bottom:10px;">
                    <span class="status-badge <?php echo $isActive ? 'status-on' : 'status-off'; ?>">
                        <?php echo $isActive ? '✅ 启用' : '❌ 禁用'; ?>
                    </span>
                    <span style="font-size:12px;color:var(--gray-400);">
                        创建于 <?php echo date('Y-m-d', strtotime($u['created_at'])); ?>
                    </span>
                </div>

                <div class="actions">
                    <button class="btn-xs btn-xs-primary" onclick="openEditModal(<?php echo $u['id']; ?>)">编辑</button>
                    <button class="btn-xs btn-xs-warning" onclick="resetPwd(<?php echo $u['id']; ?>, '<?php echo h($u['username']); ?>')">重置密码</button>
                    <?php if (!$isSelf): ?>
                    <button class="btn-xs btn-xs-danger" onclick="deleteUser(<?php echo $u['id']; ?>, '<?php echo h($u['username']); ?>')">删除</button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- Modal: Add -->
    <div class="modal-overlay" id="modalAdd">
        <div class="modal-sheet">
            <div style="font-size:18px;font-weight:700;color:var(--gray-800);margin-bottom:20px;">➕ 添加用户</div>
            <form id="formAdd">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="ajax_action" value="add">

                <div class="modal-field">
                    <label>用户名 *</label>
                    <input type="text" name="username" class="form-control" placeholder="登录用户名" required maxlength="30">
                </div>
                <div class="modal-field">
                    <label>密码 *</label>
                    <input type="password" name="password" class="form-control" placeholder="至少6位" required minlength="6">
                </div>
                <div class="modal-field">
                    <label>姓名（可选）</label>
                    <input type="text" name="realname" class="form-control" placeholder="真实姓名">
                </div>
                <div class="modal-field">
                    <label>角色</label>
                    <select name="role" class="form-control">
                        <option value="1">普通用户</option>
                        <option value="2">管理员</option>
                    </select>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-cancel" onclick="closeModal('modalAdd')">取消</button>
                    <button type="submit" class="btn btn-submit">添加</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Edit -->
    <div class="modal-overlay" id="modalEdit">
        <div class="modal-sheet">
            <div style="font-size:18px;font-weight:700;color:var(--gray-800);margin-bottom:20px;">✏️ 编辑用户</div>
            <form id="formEdit">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="ajax_action" value="edit">
                <input type="hidden" name="id" id="editId" value="">

                <div class="modal-field">
                    <label>用户名</label>
                    <input type="text" id="editUsername" class="form-control" disabled style="background:var(--gray-100);cursor:not-allowed;">
                </div>
                <div class="modal-field">
                    <label>姓名（可选）</label>
                    <input type="text" name="realname" id="editRealname" class="form-control">
                </div>
                <div class="modal-field">
                    <label>新密码（留空则不修改）</label>
                    <input type="password" name="new_password" class="form-control" placeholder="至少6位" minlength="6">
                    <div class="pwd-hint">不填则保留原密码</div>
                </div>
                <div class="modal-field">
                    <label>角色</label>
                    <select name="role" id="editRole" class="form-control">
                        <option value="1">普通用户</option>
                        <option value="2">管理员</option>
                    </select>
                </div>
                <div class="modal-field">
                    <div class="toggle-row">
                        <span class="toggle-label">账号状态</span>
                        <div id="editStatusSwitch" class="toggle-switch" onclick="toggleStatus()"></div>
                    </div>
                    <input type="hidden" name="status" id="editStatusVal" value="1">
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-cancel" onclick="closeModal('modalEdit')">取消</button>
                    <button type="submit" class="btn btn-submit">保存</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    const csrfToken = '<?php echo $csrfToken; ?>';

    function openAddModal() {
        document.getElementById('modalAdd').classList.add('show');
    }

    function openEditModal(id) {
        const card = document.querySelector(`.user-card[data-id="${id}"]`);
        document.getElementById('editId').value = id;
        document.getElementById('editUsername').value = card.dataset.username;
        document.getElementById('editRealname').value = card.dataset.realname || '';
        document.getElementById('editRole').value = card.dataset.role;
        const statusOn = card.dataset.status == '1';
        document.getElementById('editStatusVal').value = statusOn ? '1' : '0';
        const sw = document.getElementById('editStatusSwitch');
        sw.classList.toggle('on', statusOn);
        document.getElementById('modalEdit').classList.add('show');
    }

    function toggleStatus() {
        const sw = document.getElementById('editStatusSwitch');
        const isOn = sw.classList.toggle('on');
        document.getElementById('editStatusVal').value = isOn ? '1' : '0';
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('show');
    }

    function submitForm(formId) {
        const form = document.getElementById(formId);
        const formData = new FormData(form);
        return fetch('users.php', { method: 'POST', body: formData })
            .then(r => r.json());
    }

    document.getElementById('formAdd').onsubmit = async function(e) {
        e.preventDefault();
        const result = await submitForm('formAdd');
        alert(result.msg);
        if (result.ok) location.reload();
    };

    document.getElementById('formEdit').onsubmit = async function(e) {
        e.preventDefault();
        const result = await submitForm('formEdit');
        alert(result.msg);
        if (result.ok) location.reload();
    };

    async function resetPwd(id, username) {
        if (!confirm('确定要重置用户 "' + username + '" 的密码吗？\n系统将自动生成一个新密码并显示。')) return;
        const formData = new FormData();
        formData.append('csrf_token', csrfToken);
        formData.append('ajax_action', 'reset_pwd');
        formData.append('id', id);
        try {
            const resp = await fetch('users.php', { method: 'POST', body: formData });
            const result = await resp.json();
            alert(result.msg);
            if (result.ok && result.new_password) {
                // Show the new password prominently
                const p = document.createElement('div');
                p.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:white;padding:24px;border-radius:16px;z-index:999;text-align:center;box-shadow:0 8px 30px rgba(0,0,0,0.2);max-width:320px;';
                p.innerHTML = '<div style="font-size:13px;color:var(--gray-500);margin-bottom:8px;">新密码（请告知用户）</div><div style="font-size:28px;font-weight:800;letter-spacing:4px;color:var(--primary);">' + result.new_password + '</div><div style="font-size:12px;color:var(--gray-400);margin-top:12px;">请尽快让用户登录并修改密码</div>';
                document.body.appendChild(p);
                setTimeout(() => p.remove(), 8000);
            }
        } catch(err) {
            alert('请求失败: ' + err.message);
        }
    }

    async function deleteUser(id, username) {
        if (!confirm('确定要删除用户 "' + username + '" 吗？此操作不可恢复！')) return;
        const formData = new FormData();
        formData.append('csrf_token', csrfToken);
        formData.append('ajax_action', 'delete');
        formData.append('id', id);
        try {
            const resp = await fetch('users.php', { method: 'POST', body: formData });
            const result = await resp.json();
            alert(result.msg);
            if (result.ok) location.reload();
        } catch(err) {
            alert('请求失败: ' + err.message);
        }
    }

    document.getElementById('modalAdd').onclick = function(e) { if (e.target === this) closeModal('modalAdd'); };
    document.getElementById('modalEdit').onclick = function(e) { if (e.target === this) closeModal('modalEdit'); };
    </script>
</body>
</html>
<?php echo mobileNav('more'); ?>
