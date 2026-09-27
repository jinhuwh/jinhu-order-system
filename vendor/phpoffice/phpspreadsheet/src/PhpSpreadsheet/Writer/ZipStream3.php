<?php

namespace PhpOffice\PhpSpreadsheet\Writer;

use ZipStream\ZipStream;

/**
 * ZipStream 3.x 适配器（原文件使用 PHP 8.0+ 命名参数语法，PHP 7.4 下解析即报错）。
 * 【2026-09-23 PHP7.4 兼容】改用参数数组 + 变长展开：PHP 8 下等价于命名参数；
 * PHP 7.4 本环境只会走 ZipStream2 适配器（ZipStream0::newZipStream 检测到
 * ZipStream\Option\Archive 即用 2.x），此分支仅作兜底并给出明确提示。
 */
class ZipStream3
{
    /**
     * @param resource $fileHandle
     */
    public static function newZipStream($fileHandle): ZipStream
    {
        if (PHP_VERSION_ID < 80000) {
            throw new \RuntimeException(
                'ZipStream 3.x 适配器需要 PHP 8.0+；当前为 PHP ' . PHP_VERSION
                . '。请安装 zipstream-php 2.x（本系统已自带 2.4.0），Xlsx 写出器会自动选用 ZipStream2 适配器。'
            );
        }
        // PHP 8.0+：关联数组展开 = 命名参数，与原实现完全等价
        return new ZipStream(...[
            'enableZip64' => false,
            'outputStream' => $fileHandle,
            'sendHttpHeaders' => false,
            'defaultEnableZeroHeader' => false,
        ]);
    }
}
