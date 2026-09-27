<?php
require_once 'config.php';
initDatabase();
checkLogin();

// 只有管理员才能访问
if (!hasPermission(PERM_USER_MANAGE)) {
    die('<script>alert("无权限访问，请联系管理员授权");location.href="index.php";</script>');
}

$msg = '';
$error = '';
$db = getDB();

// 引入权限分组变量（全局）
global $PERMISSION_GROUPS;

// 添加/编辑用户
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // CSRF验证
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $error = '非法请求';
    } else {

        if ($_POST['action'] === 'add') {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $realname = trim($_POST['realname'] ?? '');
            $role = intval($_POST['role'] ?? 1);
            $status = isset($_POST['status']) ? 1 : 0;
            // 收集权限
            $perms = isset($_POST['permissions']) && is_array($_POST['permissions'])
                ? implode(',', array_map('trim', $_POST['permissions']))
                : '';
            // 【2026-09-15 调整】移除强制剥离删除类权限的逻辑，按管理员勾选保存

            if (empty($username) || empty($password)) {
                $error = '用户名和密码不能为空';
            } elseif ($pwErrors = validatePassword($password)) {
                $error = '密码复杂度不足：' . implode('；', $pwErrors);
            } else {
                $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                $stmt->execute([$username]);
                if ($stmt->fetchColumn() > 0) {
                    $error = '用户名已存在';
                } else {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("INSERT INTO users (username, password, realname, role, status, permissions) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$username, $hashed, $realname, $role, $status, $perms]);
                    $msg = '用户添加成功';
                }
            }
        } elseif ($_POST['action'] === 'edit') {
            $id = intval($_POST['id']);
            $realname = trim($_POST['realname'] ?? '');
            $role = intval($_POST['role'] ?? 1);
            $status = isset($_POST['status']) ? 1 : 0;
            // 收集权限
            $perms = isset($_POST['permissions']) && is_array($_POST['permissions'])
                ? implode(',', array_map('trim', $_POST['permissions']))
                : '';
            // 【2026-09-15 调整】移除强制剥离删除类权限的逻辑，按管理员勾选保存

            $stmt = $db->prepare("UPDATE users SET realname = ?, role = ?, status = ?, permissions = ? WHERE id = ?");
            $stmt->execute([$realname, $role, $status, $perms, $id]);
            $msg = '用户更新成功';

            // 如果填写了新密码
            if (!empty($_POST['new_password'])) {
                if ($pwErrors = validatePassword($_POST['new_password'])) {
                    $error = '密码复杂度不足：' . implode('；', $pwErrors);
                } else {
                    $hashed = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                    $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->execute([$hashed, $id]);
                    $msg = '用户信息和新密码更新成功';
                }
            }
        } elseif ($_POST['action'] === 'delete') {
            $id = intval($_POST['id']);
            if ($id == $_SESSION['user_id']) {
                $error = '不能删除当前登录的用户';
            } else {
                $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$id]);
                $msg = '用户删除成功';
            }
        }
    }
}

$users = $db->query("SELECT * FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

// 统计每个用户的权限数量
function countUserPerms($permStr) {
    if (empty($permStr)) return 0;
    return count(array_filter(array_map('trim', explode(',', $permStr))));
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>用户管理 - <?php echo SITE_TITLE; ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .perm-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .perm-item {
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 4px;
            background: var(--gray-100);
            font-size: 13px;
        }
        .perm-item.has-perm {
            background: #e8f5e9;
            color: #2e7d32;
        }
        .perm-item.no-perm {
            background: #f5f5f5;
            color: #999;
        }
        .badge-perm-count {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 600;
            background: #e3f2fd;
            color: #1565c0;
        }
        .perm-modal-body {
            max-height: 70vh;
            overflow-y: auto;
        }
        .perm-card {
            border: 1px solid var(--border);
            border-radius: 8px;
            margin-bottom: 12px;
            overflow: hidden;
        }
        .perm-card-header {
            background: var(--gray-100);
            padding: 10px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
            font-size: 14px;
        }
        .perm-card-header label {
            cursor: pointer;
            margin: 0;
            font-weight: 500;
            font-size: 13px;
        }
        .perm-card-body {
            padding: 12px 16px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .perm-check-item {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 5px 10px;
            border-radius: 6px;
            border: 1px solid var(--border);
            cursor: pointer;
            transition: all 0.2s;
            min-width: 130px;
        }
        .perm-check-item:hover {
            border-color: var(--primary);
            background: #f0f7ff;
        }
        .perm-check-item input {
            margin: 0;
        }
        .perm-check-item .perm-label {
            font-size: 13px;
        }
        .perm-desc {
            font-size: 11px;
            color: var(--gray-500);
        }
        .form-section {
            margin-bottom: 20px;
        }
        .form-section-title {
            font-size: 13px;
            font-weight: 600;
            color: var(--gray-600);
            margin-bottom: 10px;
            padding-bottom: 6px;
            border-bottom: 1px solid var(--border);
        }
    </style>
</head>
<body>
    <?php echo renderNav('users'); ?>

    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <span class="title-icon">👥</span>
                <span>用户管理</span>
            </div>
            <button onclick="openModal('add')" class="btn btn-primary btn-sm">➕ 添加用户</button>
        </div>
        <div class="card-body">
            <?php if ($error): ?>
            <div class="msg error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($msg): ?>
            <div class="msg success"><?php echo htmlspecialchars($msg); ?></div>
            <?php endif; ?>

            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>用户名</th>
                        <th>姓名</th>
                        <th>角色</th>
                        <th>状态</th>
                        <th>权限</th>
                        <th>创建时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <?php
                        $permCount = countUserPerms($u['permissions']);
                        $isAdminUser = ($u['role'] == 2);
                    ?>
                    <tr>
                        <td><?php echo $u['id']; ?></td>
                        <td style="font-weight:500;"><?php echo htmlspecialchars($u['username']); ?></td>
                        <td><?php echo htmlspecialchars($u['realname'] ?: '-'); ?></td>
                        <td>
                            <?php if ($isAdminUser): ?>
                                <span class="badge badge-danger">管理员</span>
                            <?php else: ?>
                                <span class="badge badge-info">普通用户</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($u['status'] == 1): ?>
                                <span class="badge badge-success">启用</span>
                            <?php else: ?>
                                <span class="badge badge-danger">禁用</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isAdminUser): ?>
                                <span class="badge-perm-count" title="管理员拥有所有权限">全部权限</span>
                            <?php else: ?>
                                <span class="badge-perm-count"><?php echo $permCount; ?> 项</span>
                            <?php endif; ?>
                        </td>
                        <td style="color:var(--gray-500);"><?php echo date('Y-m-d', strtotime($u['created_at'])); ?></td>
                        <td style="text-align:center;white-space:nowrap;">
                            <button onclick='openModal("edit", <?php echo json_encode($u); ?>)' class="btn btn-primary btn-sm">编辑</button>
                            <?php if ($u['id'] != $_SESSION['user_id']): ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('确定删除该用户？')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                                <button type="submit" class="btn btn-danger btn-sm">删除</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 添加用户弹窗 -->
    <div id="modal-add" class="modal">
        <div class="modal-content" style="max-width:720px;">
            <div class="modal-header">
                <div class="modal-title">➕ 添加用户</div>
                <button class="modal-close" onclick="closeModal('add')">&times;</button>
            </div>
            <div class="modal-body perm-modal-body">
                <form method="POST" action="" id="form-add">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">

                    <div class="form-section">
                        <div class="form-section-title">基本信息</div>
                        <div class="form-group">
                            <label class="form-label">👤 用户名</label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">🔒 密码</label>
                            <input type="password" name="password" class="form-control" required minlength="8" placeholder="至少8位，含大小写字母与数字">
                        </div>
                        <div class="form-group">
                            <label class="form-label">📝 姓名</label>
                            <input type="text" name="realname" class="form-control" placeholder="真实姓名（选填）">
                        </div>
                        <div style="display:flex;gap:16px;">
                            <div class="form-group" style="flex:1;">
                                <label class="form-label">🏷️ 角色</label>
                                <select name="role" class="form-control" id="add-role">
                                    <option value="1">普通用户</option>
                                    <option value="2">管理员</option>
                                </select>
                            </div>
                            <div class="form-group" style="flex:1;">
                                <label class="form-label">状态</label>
                                <div style="display:flex;align-items:center;height:38px;gap:8px;">
                                    <input type="checkbox" name="status" value="1" id="add-status" checked>
                                    <label for="add-status" style="margin:0;cursor:pointer;">启用账号</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 权限设置 -->
                    <div class="form-section" id="add-perm-section">
                        <div class="form-section-title">🔐 权限配置 <small style="color:#c9353f;font-weight:400;">（⚠️ 删除类权限影响大，请仅授予可信人员）</small></div>
                        <?php foreach ($PERMISSION_GROUPS as $groupKey => $group): ?>
                        <div class="perm-card">
                            <div class="perm-card-header">
                                <span><?php echo $group['label']; ?></span>
                                <label><input type="checkbox" class="check-all-perm" data-group="add_<?php echo $groupKey; ?>"> 全选</label>
                            </div>
                            <div class="perm-card-body">
                                <?php foreach ($group['items'] as $perm => $info): ?>
                                <label class="perm-check-item">
                                    <input type="checkbox" class="perm-check"
                                           name="permissions[]"
                                           id="add_perm_<?php echo $perm; ?>"
                                           value="<?php echo $perm; ?>"
                                           data-group="add_<?php echo $groupKey; ?>">
                                    <div>
                                        <div class="perm-label"><?php echo $info['label']; ?></div>
                                        <div class="perm-desc"><?php echo $info['desc']; ?></div>
                                    </div>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width:100%;">添加用户</button>
                </form>
            </div>
        </div>
    </div>

    <!-- 编辑用户弹窗 -->
    <div id="modal-edit" class="modal">
        <div class="modal-content" style="max-width:720px;">
            <div class="modal-header">
                <div class="modal-title">✏️ 编辑用户</div>
                <button class="modal-close" onclick="closeModal('edit')">&times;</button>
            </div>
            <div class="modal-body perm-modal-body">
                <form method="POST" action="" id="form-edit">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <input type="hidden" name="id" id="edit-id">

                    <div class="form-section">
                        <div class="form-section-title">基本信息</div>
                        <div class="form-group">
                            <label class="form-label">👤 用户名</label>
                            <input type="text" id="edit-username" class="form-control" disabled style="background:var(--gray-100);">
                        </div>
                        <div class="form-group">
                            <label class="form-label">📝 姓名</label>
                            <input type="text" name="realname" id="edit-realname" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">🔑 新密码 <small style="color:var(--gray-500);">（留空则不修改）</small></label>
                            <input type="password" name="new_password" class="form-control" minlength="8" placeholder="留空保持原密码（填写则至少8位）">
                        </div>
                        <div style="display:flex;gap:16px;">
                            <div class="form-group" style="flex:1;">
                                <label class="form-label">🏷️ 角色</label>
                                <select name="role" id="edit-role" class="form-control">
                                    <option value="1">普通用户</option>
                                    <option value="2">管理员</option>
                                </select>
                            </div>
                            <div class="form-group" style="flex:1;">
                                <label class="form-label">状态</label>
                                <div style="display:flex;align-items:center;height:38px;gap:8px;">
                                    <input type="checkbox" name="status" value="1" id="edit-status">
                                    <label for="edit-status" style="margin:0;cursor:pointer;">启用账号</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 权限设置 -->
                    <div class="form-section" id="edit-perm-section">
                        <div class="form-section-title">🔐 权限配置 <small style="color:#c9353f;font-weight:400;">（⚠️ 删除类权限影响大，请仅授予可信人员）</small></div>
                        <?php foreach ($PERMISSION_GROUPS as $groupKey => $group): ?>
                        <div class="perm-card">
                            <div class="perm-card-header">
                                <span><?php echo $group['label']; ?></span>
                                <label><input type="checkbox" class="check-all-perm" data-group="edit_<?php echo $groupKey; ?>"> 全选</label>
                            </div>
                            <div class="perm-card-body">
                                <?php foreach ($group['items'] as $perm => $info): ?>
                                <label class="perm-check-item">
                                    <input type="checkbox" class="perm-check"
                                           name="permissions[]"
                                           id="edit_perm_<?php echo $perm; ?>"
                                           value="<?php echo $perm; ?>"
                                           data-group="edit_<?php echo $groupKey; ?>">
                                    <div>
                                        <div class="perm-label"><?php echo $info['label']; ?></div>
                                        <div class="perm-desc"><?php echo $info['desc']; ?></div>
                                    </div>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width:100%;">保存修改</button>
                </form>
            </div>
        </div>
    </div>

    <?php echo renderFooter(); ?>

    <script>
    // 全选功能
    document.querySelectorAll('.check-all-perm').forEach(function(cb) {
        cb.addEventListener('change', function() {
            var group = this.dataset.group;
            document.querySelectorAll('.perm-check[data-group="' + group + '"]').forEach(function(c) {
                c.checked = this.checked;
            }.bind(this));
        });
    });

    // 角色切换：管理员隐藏权限配置区
    function togglePermSection(prefix, isAdmin) {
        var section = document.getElementById(prefix + '-perm-section');
        if (!section) return;
        section.style.display = isAdmin ? 'none' : 'block';
        if (isAdmin) {
            section.querySelectorAll('.perm-check').forEach(function(c) { c.checked = false; });
        }
    }

    document.getElementById('add-role').addEventListener('change', function() {
        togglePermSection('add', this.value == '2');
    });

    // 编辑弹窗的角色切换
    document.getElementById('edit-role').addEventListener('change', function() {
        togglePermSection('edit', this.value == '2');
    });

    function openModal(type, data) {
        document.getElementById('modal-' + type).classList.add('active');
        if (type === 'edit' && data) {
            document.getElementById('edit-id').value = data.id;
            document.getElementById('edit-username').value = data.username;
            document.getElementById('edit-realname').value = data.realname || '';
            document.getElementById('edit-role').value = data.role;
            document.getElementById('edit-status').checked = data.status == 1;

            // 恢复权限勾选状态
            var perms = data.permissions ? data.permissions.split(',') : [];
            document.querySelectorAll('#edit-perm-section .perm-check').forEach(function(c) {
                c.checked = perms.indexOf(c.value) !== -1;
            });

            // 管理员角色隐藏权限区
            togglePermSection('edit', data.role == '2');
        } else if (type === 'add') {
            // 重置添加表单
            document.getElementById('form-add').reset();
            document.getElementById('add-status').checked = true;
            document.querySelectorAll('#add-perm-section .perm-check').forEach(function(c) { c.checked = false; });
            togglePermSection('add', false);
        }
    }

    function closeModal(type) {
        document.getElementById('modal-' + type).classList.remove('active');
    }

    // 点击外部关闭
    document.querySelectorAll('.modal').forEach(function(m) {
        m.addEventListener('click', function(e) {
            if (e.target === m) m.classList.remove('active');
        });
    });
    </script>
</body>
</html>
