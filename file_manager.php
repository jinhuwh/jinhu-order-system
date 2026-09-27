<?php
/**
 * 订单文件管理页面
 * 
 * 功能：
 * - 上传文件
 * - 查看已上传文件
 * - 下载文件
 * - 删除文件
 */

require_once 'config.php';
initDatabase();
checkLogin();

$orderId = intval($_GET['order_id'] ?? 0);
if ($orderId <= 0) {
    die('无效的订单ID');
}

$db = getDB();

// 获取订单信息
$stmt = $db->prepare("SELECT o.*, c.name as customer_name FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE o.id = ?");
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die('订单不存在');
}

// 获取已上传的文件
$stmt = $db->prepare("
    SELECT a.*, u.realname 
    FROM order_attachments a 
    LEFT JOIN users u ON a.uploaded_by = u.id 
    WHERE a.order_id = ? 
    ORDER BY a.uploaded_at DESC
");
$stmt->execute([$orderId]);
$attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 生成CSRF Token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$pageTitle = '文件管理 - 订单 ' . $order['order_no'];
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .upload-area {
            border: 2px dashed #d1d5db;
            border-radius: 12px;
            padding: 40px;
            text-align: center;
            background: #f9fafb;
            cursor: pointer;
            transition: all 0.3s;
            margin-bottom: 24px;
        }
        .upload-area:hover, .upload-area.dragover {
            border-color: #667eea;
            background: #eef2ff;
        }
        .upload-area .icon {
            font-size: 48px;
            margin-bottom: 12px;
        }
        .upload-area p {
            color: #6b7280;
            margin: 8px 0;
        }
        .upload-area input[type="file"] {
            display: none;
        }
        .file-list {
            margin-top: 24px;
        }
        .file-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            margin-bottom: 12px;
            transition: all 0.2s;
        }
        .file-item:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .file-info {
            display: flex;
            align-items: center;
            gap: 16px;
            flex: 1;
        }
        .file-icon {
            font-size: 32px;
        }
        .file-details h4 {
            margin: 0 0 4px 0;
            font-size: 14px;
        }
        .file-meta {
            font-size: 12px;
            color: #6b7280;
        }
        .file-actions {
            display: flex;
            gap: 8px;
        }
        .progress-bar {
            width: 100%;
            height: 4px;
            background: #e5e7eb;
            border-radius: 2px;
            overflow: hidden;
            margin-top: 12px;
            display: none;
        }
        .progress-bar .progress {
            height: 100%;
            background: linear-gradient(90deg, #667eea, #764ba2);
            width: 0%;
            transition: width 0.3s;
        }
        .upload-status {
            margin-top: 12px;
            padding: 12px;
            border-radius: 8px;
            display: none;
        }
        .upload-status.success {
            background: #d1fae5;
            color: #065f46;
        }
        .upload-status.error {
            background: #fee2e2;
            color: #991b1b;
        }
    </style>
</head>
<body>
    <?php echo renderNav('orders'); ?>
    
    <div class="container-fluid">
        <div class="page-title-bar">
            <div class="page-title-left">
                <h1>📎 文件管理</h1>
                <p>订单号: <?php echo $order['order_no']; ?> | 客户: <?php echo htmlspecialchars($order['customer_name']); ?></p>
            </div>
            <div class="page-title-actions">
                <a href="order_view.php?id=<?php echo $orderId; ?>" class="btn btn-ghost">← 返回订单详情</a>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">📤 上传文件</h5>
            </div>
            <div class="card-body">
                <div class="upload-area" id="uploadArea">
                    <div class="icon">📁</div>
                    <p><strong>点击选择文件或拖拽文件到此处</strong></p>
                    <p>支持图片、PDF、Office文档、压缩包等</p>
                    <p>最大文件大小：10MB</p>
                    <input type="file" id="fileInput" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.zip,.rar,.txt">
                </div>
                <div class="progress-bar" id="progressBar">
                    <div class="progress" id="progress"></div>
                </div>
                <div class="upload-status" id="uploadStatus"></div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">📄 已上传的文件 (<?php echo count($attachments); ?>)</h5>
            </div>
            <div class="card-body">
                <?php if (empty($attachments)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">📎</div>
                        <p>暂无文件</p>
                        <p class="text-muted">上传设计稿、合同或其他相关文件</p>
                    </div>
                <?php else: ?>
                    <div class="file-list">
                        <?php foreach ($attachments as $file): ?>
                            <div class="file-item" id="file-<?php echo $file['id']; ?>">
                                <div class="file-info">
                                    <div class="file-icon">
                                        <?php 
                                        $ext = strtolower(pathinfo($file['file_name'], PATHINFO_EXTENSION));
                                        $icon = '📄';
                                        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) $icon = '🖼️';
                                        elseif ($ext === 'pdf') $icon = '📕';
                                        elseif (in_array($ext, ['doc', 'docx'])) $icon = '📘';
                                        elseif (in_array($ext, ['xls', 'xlsx'])) $icon = '📗';
                                        elseif (in_array($ext, ['zip', 'rar'])) $icon = '📦';
                                        echo $icon;
                                        ?>
                                    </div>
                                    <div class="file-details">
                                        <h4><?php echo htmlspecialchars($file['file_name']); ?></h4>
                                        <div class="file-meta">
                                            <?php echo formatFileSize($file['file_size']); ?> | 
                                            上传者: <?php echo htmlspecialchars($file['realname'] ?? '未知'); ?> | 
                                            <?php echo date('Y-m-d H:i', strtotime($file['uploaded_at'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="file-actions">
                                    <a href="download.php?file_id=<?php echo $file['id']; ?>" class="btn btn-sm btn-outline-primary">
                                        ⬇️ 下载
                                    </a>
                                    <button class="btn btn-sm btn-outline-danger" onclick="deleteFile(<?php echo $file['id']; ?>, '<?php echo htmlspecialchars(addslashes($file['file_name'])); ?>')">
                                        🗑️ 删除
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
    const uploadArea = document.getElementById('uploadArea');
    const fileInput = document.getElementById('fileInput');
    const progressBar = document.getElementById('progressBar');
    const progress = document.getElementById('progress');
    const uploadStatus = document.getElementById('uploadStatus');
    
    // 点击上传区域
    uploadArea.addEventListener('click', () => fileInput.click());
    
    // 文件选择
    fileInput.addEventListener('change', (e) => {
        handleFiles(e.target.files);
    });
    
    // 拖拽上传
    uploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadArea.classList.add('dragover');
    });
    
    uploadArea.addEventListener('dragleave', () => {
        uploadArea.classList.remove('dragover');
    });
    
    uploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadArea.classList.remove('dragover');
        handleFiles(e.dataTransfer.files);
    });
    
    // 处理文件上传
    function handleFiles(files) {
        if (files.length === 0) return;
        
        const formData = new FormData();
        formData.append('file', files[0]);
        formData.append('order_id', <?php echo $orderId; ?>);
        formData.append('csrf_token', '<?php echo $csrfToken; ?>');
        
        // 显示进度条
        progressBar.style.display = 'block';
        uploadStatus.style.display = 'none';
        
        const xhr = new XMLHttpRequest();
        
        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                const percent = (e.loaded / e.total) * 100;
                progress.style.width = percent + '%';
            }
        });
        
        xhr.addEventListener('load', () => {
            progressBar.style.display = 'none';
            uploadStatus.style.display = 'block';
            
            try {
                const response = JSON.parse(xhr.responseText);
                if (response.ok) {
                    uploadStatus.className = 'upload-status success';
                    uploadStatus.innerHTML = '✅ ' + response.msg;
                    setTimeout(() => location.reload(), 1000);
                } else {
                    uploadStatus.className = 'upload-status error';
                    uploadStatus.innerHTML = '❌ ' + response.msg;
                }
            } catch (e) {
                uploadStatus.className = 'upload-status error';
                uploadStatus.innerHTML = '❌ 上传失败';
            }
        });
        
        xhr.addEventListener('error', () => {
            progressBar.style.display = 'none';
            uploadStatus.style.display = 'block';
            uploadStatus.className = 'upload-status error';
            uploadStatus.innerHTML = '❌ 网络错误';
        });
        
        xhr.open('POST', 'upload.php');
        xhr.send(formData);
    }
    
    // 删除文件
    function deleteFile(fileId, fileName) {
        if (!confirm('确定要删除文件 "' + fileName + '" 吗？')) {
            return;
        }
        
        const formData = new FormData();
        formData.append('action', 'delete_file');
        formData.append('file_id', fileId);
        formData.append('csrf_token', '<?php echo $csrfToken; ?>');
        
        fetch('delete_file.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(res => {
            if (res.ok) {
                document.getElementById('file-' + fileId).remove();
                showToast('✅ ' + res.msg);
            } else {
                showToast('❌ ' + res.msg, 'error');
            }
        });
    }
    
    // 显示提示
    function showToast(message, type = 'success') {
        const toast = document.createElement('div');
        toast.className = 'toast toast-' + type;
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
    }
    
    function formatFileSize(bytes) {
        if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
        if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
        return bytes + ' B';
    }
    </script>
</body>
</html>
