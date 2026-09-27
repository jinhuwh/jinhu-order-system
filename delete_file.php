<?php
/**
 * 文件删除处理脚本
 */

require_once 'config.php';
initDatabase();
checkLogin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => '非法请求']);
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    echo json_encode(['ok' => false, 'msg' => '安全验证失败']);
    exit;
}

$fileId = intval($_POST['file_id'] ?? 0);
if ($fileId <= 0) {
    echo json_encode(['ok' => false, 'msg' => '文件ID无效']);
    exit;
}

$db = getDB();

// 获取文件信息（同时获取订单信息用于权限验证）
$stmt = $db->prepare("SELECT a.*, o.created_by 
    FROM order_attachments a 
    JOIN orders o ON a.order_id = o.id 
    WHERE a.id = ?");
$stmt->execute([$fileId]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$file) {
    echo json_encode(['ok' => false, 'msg' => '文件不存在']);
    exit;
}

// 权限检查：普通用户只能删除自己创建的订单的文件
if (!isAdmin() && $file['created_by'] != $_SESSION['user_id']) {
    echo json_encode(['ok' => false, 'msg' => '无权删除该文件']);
    exit;
}

// 路径安全检查:仅允许删除 uploads/orders/ 目录下的文件
$erpRoot = realpath(__DIR__);
$fileReal = realpath($file['file_path']);
if ($erpRoot === false || $fileReal === false || strpos($fileReal, $erpRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'orders') !== 0) {
    echo json_encode(['ok' => false, 'msg' => '文件路径不合法，拒绝删除']);
    exit;
}

try {
    $db->beginTransaction();
    if (file_exists($fileReal)) {
        unlink($fileReal);
    }
    $stmt = $db->prepare("DELETE FROM order_attachments WHERE id = ?");
    $stmt->execute([$fileId]);
    $db->commit();
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log("Delete file failed: " . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => '删除失败：' . $e->getMessage()]);
    exit;
}

echo json_encode(['ok' => true, 'msg' => '文件已删除']);
?>
