<?php
/**
 * 金狐开单系统 - 匿名使用统计 / 更新检查
 * ------------------------------------------------
 * 本文件只做两件事：
 *   1. 检查更新：向官方接口询问最新版本号
 *   2. 匿名使用统计（可选，默认关闭）：管理员在"系统设置"里开启后，
 *      每周上报一次匿名信息，帮助作者了解有多少人在使用、用的什么版本。
 *
 * 上报内容（仅此 5 项，绝无其他）：
 *   - 实例 ID（安装时随机生成，与公司/人员无关）
 *   - 系统版本号
 *   - PHP 版本号
 *   - MySQL 版本号
 *   - 上报时间
 *
 * 绝不上报：订单、客户、金额、文件等任何业务数据。
 * 您可以随时在 系统设置 → 关于与更新 中一键关闭，本文件代码完全公开可审计。
 */

if (!defined('APP_VERSION')) {
    define('APP_VERSION', '1.3.0');       // 每次发版改这里
}
define('TELEMETRY_ENDPOINT', 'https://www.jinhuwh.vip/t/report.php'); // 官方统计接口
define('TELEMETRY_INTERVAL', 7 * 86400);                           // 每 7 天上报一次

/**
 * 静默心跳：符合条件（开关已开 + 距上次上报超过间隔）时上报一次
 * 任何异常都不抛出、不影响业务，全程 2 秒超时
 */
function jh_telemetry_ping($force = false)
{
    try {
        $db = getDB();

        // 开关：settings 表 telemetry_enabled，默认 0（关闭）
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'telemetry_enabled' LIMIT 1");
        $stmt->execute();
        $enabled = $stmt->fetchColumn();
        if (!$force && $enabled !== '1') {
            return null;
        }

        // 距上次上报不足 7 天则跳过（手动检查更新时跳过此限制）
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'telemetry_last_ping' LIMIT 1");
        $stmt->execute();
        $lastPing = (int) $stmt->fetchColumn();
        if (!$force && $lastPing > 0 && (time() - $lastPing) < TELEMETRY_INTERVAL) {
            return null;
        }

        // 实例 ID：首次生成随机 16 位十六进制，存在 settings 表
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'telemetry_instance_id' LIMIT 1");
        $stmt->execute();
        $instanceId = $stmt->fetchColumn();
        if (!$instanceId) {
            $instanceId = bin2hex(random_bytes(8));
            $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('telemetry_instance_id', ?) "
                . "ON DUPLICATE KEY UPDATE setting_value = ?")->execute([$instanceId, $instanceId]);
        }

        // MySQL 版本
        try {
            $mysqlVer = (string) $db->query("SELECT VERSION()")->fetchColumn();
            $mysqlVer = preg_replace('/[^0-9a-zA-Z.\-]/', '', substr($mysqlVer, 0, 20));
        } catch (Exception $e) {
            $mysqlVer = 'unknown';
        }

        $payload = http_build_query([
            'id'      => substr($instanceId, 0, 32),
            'ver'     => APP_VERSION,
            'php'     => PHP_VERSION,
            'mysql'   => $mysqlVer !== '' ? $mysqlVer : 'unknown',
        ]);

        $resp = jh_telemetry_http(TELEMETRY_ENDPOINT . '?' . $payload);

        // 连接失败：记录原因供后台"检查更新"显示
        if ($resp === false) {
            $GLOBALS['jh_telemetry_last_error'] = isset($GLOBALS['jh_telemetry_http_error'])
                ? $GLOBALS['jh_telemetry_http_error']
                : '无法发起外网请求（curl 和 allow_url_fopen 均不可用）';
            if (!$force) return null;
        } else {
            // 记录本次上报时间（仅成功时，用于每周一次的间隔控制）
            $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('telemetry_last_ping', ?) "
                . "ON DUPLICATE KEY UPDATE setting_value = ?")->execute([(string) time(), (string) time()]);

            $data = json_decode($resp, true);
            if (is_array($data) && isset($data['latest'])) {
                return [
                    'latest'    => (string) $data['latest'],
                    'note'      => isset($data['note']) ? (string) $data['note'] : '',
                    'current'   => APP_VERSION,
                    'has_new'   => version_compare(APP_VERSION, (string) $data['latest'], '<'),
                ];
            }
            $GLOBALS['jh_telemetry_last_error'] = '服务器响应异常：' . substr((string) $resp, 0, 120);
            if (!$force) return null;
        }
        return null;
    } catch (Exception $e) {
        return null;   // 统计失败绝不影响系统运行
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * 发起 HTTP GET，优先 curl，失败退回 file_get_contents，再失败返回 false
 */
function jh_telemetry_http($url)
{
    $timeout = 2;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'JinhuERP-UpdateCheck/' . APP_VERSION,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            // 记录 curl 具体错误（DNS / 证书 / 超时等），供后台显示
            $GLOBALS['jh_telemetry_http_error'] = 'curl 错误 #' . curl_errno($ch) . '：' . curl_error($ch);
        }
        curl_close($ch);
        if ($resp !== false) {
            return $resp;
        }
    }
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create([
            'http' => ['timeout' => $timeout, 'user_agent' => 'JinhuERP-UpdateCheck/' . APP_VERSION],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false && !isset($GLOBALS['jh_telemetry_http_error'])) {
            $err = error_get_last();
            $GLOBALS['jh_telemetry_http_error'] = 'file_get_contents 失败：' . ($err['message'] ?? '未知原因');
        }
        return $resp;
    }
    return false;
}
