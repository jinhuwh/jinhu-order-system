<?php
/**
 * 安全响应头配置
 * 在每个页面开头包含此文件
 */

// 防止直接访问
if (!defined('INCLUDED_FROM_CONFIG')) {
    // 允许作为独立文件包含
}

// 添加安全响应头
function addSecurityHeaders() {
    // 防止 MIME 类型嗅探
    header('X-Content-Type-Options: nosniff');
    
    // 防止点击劫持
    header('X-Frame-Options: DENY');
    
    // XSS保护
    header('X-XSS-Protection: 1; mode=block');
    
    // 引用策略
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // 内容安全策略
    // 2026-09-09 安全加固：移除 'unsafe-eval'（全站扫描确认 0 处 eval/new Function/字符串 setTimeout）
    // 'unsafe-inline' 仍保留：兼容现有大量内联 onclick/script/style
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://fonts.googleapis.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; img-src 'self' data: blob: https:; font-src 'self' data: https://fonts.gstatic.com https://fonts.googleapis.com;");
    
    // 移除PHP版本信息
    header_remove('X-Powered-By');
    
    // 如果是HTTPS，添加HSTS
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// 自动调用
if (php_sapi_name() !== 'cli') {
    addSecurityHeaders();
}
?>
