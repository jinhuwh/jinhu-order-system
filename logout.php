<?php
require_once 'config.php';
session_start();

// 记录登出日志
$uid = $_SESSION['user_id'] ?? null;
$uname = $_SESSION['username'] ?? null;
if ($uid) {
    logOperation('logout', ['event' => 'user_logout', 'username' => $uname]);
}

// 清除所有会话变量
$_SESSION = [];

// 销毁会话 cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

header('Location: login.php');
exit;
