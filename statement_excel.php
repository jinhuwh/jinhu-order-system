<?php
/**
 * 客户对账单 - 导出 Excel（含订单商品明细 + 参考图）
 * 2026-09-13 新增
 * 参数：customer_id / start_date / end_date（与 statement.php 一致）
 */
require_once 'config.php';
initDatabase();
checkLogin();

$db = getDB();

$customerId = intval($_GET['customer_id'] ?? 0);
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
// 是否嵌入参考图（全部客户导出量大时建议关闭，避免处理超时）
$includeImages = (isset($_GET['inc_images']) ? $_GET['inc_images'] : '1') !== '0';

if (empty($startDate) && empty($endDate)) {
    $startDate = date('Y-m-01');
    $endDate = date('Y-m-d');
}

// ---- 查询订单（与 statement.php 同口径） ----
$where = ["o.status != 4"];
$params = [];
if ($customerId > 0) {
    $where[] = "o.customer_id = ?";
    $params[] = $customerId;
}
if (!empty($startDate)) {
    $where[] = "o.created_at >= ?";
    $params[] = $startDate . ' 00:00:00';
}
if (!empty($endDate)) {
    $where[] = "o.created_at <= ?";
    $params[] = $endDate . ' 23:59:59';
}
$whereSql = implode(' AND ', $where);

$stmt = $db->prepare("SELECT o.id, o.order_no, o.total_amount, COALESCE(o.discount_amount,0) as discount_amount, COALESCE(o.paid_amount,0) as paid_amount, o.payment_status, o.status, o.created_at, c.name as customer_name
    FROM orders o LEFT JOIN customers c ON o.customer_id = c.id
    WHERE $whereSql ORDER BY o.created_at ASC, o.id ASC");
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---- 查询订单商品明细 ----
$itemsByOrder = [];
if (!empty($orders)) {
    $orderIds = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $iStmt = $db->prepare("SELECT order_id, product_name, specification, quantity, unit, unit_price, amount, length, width, square_meter, image_path
        FROM order_items WHERE order_id IN ($placeholders) ORDER BY id ASC");
    $iStmt->execute($orderIds);
    foreach ($iStmt->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $itemsByOrder[$it['order_id']][] = $it;
    }
}

// ---- 汇总 ----
$totalOrderAmount = 0; $totalPaidAmount = 0; $totalDiscountAmount = 0;
foreach ($orders as $o) {
    $totalOrderAmount += floatval($o['total_amount']);
    $totalPaidAmount += floatval($o['paid_amount']);
    $totalDiscountAmount += floatval($o['discount_amount']);
}
$totalUnpaid = max(0, $totalOrderAmount - $totalPaidAmount - $totalDiscountAmount);

// ---- 客户信息 ----
$customerName = '全部客户';
$custData = ['contact' => '', 'phone' => '', 'address' => ''];
if ($customerId > 0) {
    $cStmt = $db->prepare("SELECT name, contact, phone, address FROM customers WHERE id = ?");
    $cStmt->execute([$customerId]);
    if ($c = $cStmt->fetch(PDO::FETCH_ASSOC)) {
        $customerName = $c['name'];
        $custData = $c;
    }
}
$companyName = getSetting('company_name') ?: SITE_TITLE;

// ---- 文件名 ----
$safeName = preg_replace('/[\\\\\/\:\*\?\"\<\>\|\s]/', '_', $customerName);
$filename = sprintf('对账单_%s_%s至%s.xlsx', $safeName, $startDate, $endDate);

// 生成缩略图供嵌入（大图直接嵌入会耗尽 PHP 内存且文件巨大），按源路径+大小缓存
function statementMakeThumb($src) {
    static $cache = [];
    if (isset($cache[$src])) return $cache[$src];
    $result = $src; // 默认用原图
    try {
        $info = @getimagesize($src);
        if ($info && $info[0] > 300) {
            list($w, $h) = $info;
            $tw = 280;
            $th = max(1, intval($h * $tw / $w));
            $createMap = [
                IMAGETYPE_JPEG => 'imagecreatefromjpeg',
                IMAGETYPE_PNG  => 'imagecreatefrompng',
                IMAGETYPE_GIF  => 'imagecreatefromgif',
                IMAGETYPE_WEBP => 'imagecreatefromwebp',
            ];
            if (isset($createMap[$info[2]]) && function_exists($createMap[$info[2]])) {
                $srcImg = @$createMap[$info[2]]($src);
                if ($srcImg) {
                    $dst = imagecreatetruecolor($tw, $th);
                    if ($info[2] == IMAGETYPE_PNG) {
                        imagealphablending($dst, false);
                        imagesavealpha($dst, true);
                    }
                    imagecopyresampled($dst, $srcImg, 0, 0, 0, 0, $tw, $th, $w, $h);
                    $tmp = sys_get_temp_dir() . '/stmt_thumb_' . md5($src . filesize($src)) . '.png';
                    imagepng($dst, $tmp, 6);
                    imagedestroy($srcImg);
                    imagedestroy($dst);
                    if (file_exists($tmp)) $result = $tmp;
                }
            }
        }
    } catch (\Throwable $e) {
        // 缩略失败回退原图，Drawing 处的内层 catch 再兜底
    }
    $cache[$src] = $result;
    return $result;
}

try {
    // 全客户导出数据量大：提高内存与执行时限，避免 0 字节
    @ini_set('memory_limit', '512M');
    @set_time_limit(300);

    if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
        throw new Exception('PhpSpreadsheet 未安装');
    }
    require_once __DIR__ . '/vendor/autoload.php';

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('对账单明细');

    $sheet->getParent()->getDefaultStyle()->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    $sheet->getParent()->getDefaultStyle()->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
    $sheet->getParent()->getDefaultStyle()->getFont()->setSize(10);

    // 列宽 A-L：序号 订单号 日期 产品 规格说明 尺寸 平方数 数量 单位 单价 金额 参考图
    foreach (['A'=>6,'B'=>16,'C'=>11,'D'=>16,'E'=>32,'F'=>11,'G'=>9,'H'=>7,'I'=>8,'J'=>9,'K'=>11,'L'=>9] as $col => $w) {
        $sheet->getColumnDimension($col)->setWidth($w);
    }

    $borderStyle = [
        'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => '999999']]],
    ];

    // ---- 标题区 ----
    $sheet->mergeCells('A1:L1');
    $sheet->setCellValue('A1', $companyName . ' - 客户对账单');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getRowDimension(1)->setRowHeight(28);

    $sheet->mergeCells('A2:L2');
    $sheet->setCellValue('A2', '客户名称：' . $customerName . '    联系人：' . ($custData['contact'] ?: '-') . '    电话：' . ($custData['phone'] ?: '-'));
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);

    $sheet->mergeCells('A3:L3');
    $sheet->setCellValue('A3', '地址：' . ($custData['address'] ?: '-'));
    $sheet->getStyle('A3')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);

    $sheet->mergeCells('A4:L4');
    $sheet->setCellValue('A4', '对账期间：' . ($startDate ?: '起始') . ' 至 ' . ($endDate ?: '截止') . '    导出时间：' . date('Y-m-d H:i'));
    $sheet->getStyle('A4')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);

    // ---- 表头 ----
    $headRow = 5;
    $headers = ['序号', '订单号', '下单日期', '产品名称', '规格说明', '尺寸(米)', '平方数', '数量', '单位', '单价', '金额', '参考图'];
    $colLetters = ['A','B','C','D','E','F','G','H','I','J','K','L'];
    foreach ($headers as $i => $h) {
        $sheet->setCellValue($colLetters[$i] . $headRow, $h);
    }
    $sheet->getStyle('A' . $headRow . ':L' . $headRow)->getFont()->setBold(true);
    $sheet->getStyle('A' . $headRow . ':L' . $headRow)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('F0F0F0');
    $sheet->getRowDimension($headRow)->setRowHeight(22);

    // ---- 明细行 ----
    $r = $headRow + 1;
    $seq = 0;       // 全局序号
    $hasImageRow = false;

    foreach ($orders as $o) {
        $items = $itemsByOrder[$o['id']] ?? [];
        if (empty($items)) {
            // 无明细的订单也保留一行，避免漏单
            $seq++;
            $sheet->setCellValue('A' . $r, $seq);
            $sheet->setCellValue('B' . $r, $o['order_no']);
            $sheet->setCellValue('C' . $r, date('Y-m-d', strtotime($o['created_at'])));
            $sheet->setCellValue('D' . $r, '（该订单无商品明细）');
            $sheet->mergeCells('D' . $r . ':J' . $r);
            $sheet->setCellValue('K' . $r, floatval($o['total_amount']));
            $sheet->getStyle('K' . $r)->getNumberFormat()->setFormatCode('#,##0.00');
            $r++;
            continue;
        }

        foreach ($items as $i => $it) {
            $seq++;
            $sheet->setCellValue('A' . $r, $seq);
            $sheet->setCellValue('B' . $r, $i === 0 ? $o['order_no'] : '');
            $sheet->setCellValue('C' . $r, $i === 0 ? date('Y-m-d', strtotime($o['created_at'])) : '');
            $sheet->setCellValue('D' . $r, $it['product_name']);
            $sheet->setCellValue('E' . $r, $it['specification'] ?: '-');
            // 规格说明过长时自动换行（未显式设行高的行由 Excel 自动展开为2-3行）
            $sheet->getStyle('E' . $r)->getAlignment()->setWrapText(true);
            $sheet->getStyle('D' . $r)->getAlignment()->setWrapText(true);
            $sheet->setCellValue('F' . $r, ($it['length'] && $it['width']) ? (floatval($it['length']) . ' × ' . floatval($it['width'])) : '-');
            if ($it['square_meter'] !== null && floatval($it['square_meter']) > 0) {
                $sheet->setCellValue('G' . $r, floatval($it['square_meter']));
                $sheet->getStyle('G' . $r)->getNumberFormat()->setFormatCode('0.####');
            } else {
                $sheet->setCellValue('G' . $r, '-');
            }
            $sheet->setCellValue('H' . $r, floatval($it['quantity']));
            $sheet->getStyle('H' . $r)->getNumberFormat()->setFormatCode('0.##');
            $sheet->setCellValue('I' . $r, $it['unit'] ?: '-');
            $sheet->setCellValue('J' . $r, floatval($it['unit_price']));
            $sheet->getStyle('J' . $r)->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->setCellValue('K' . $r, floatval($it['amount']));
            $sheet->getStyle('K' . $r)->getNumberFormat()->setFormatCode('#,##0.00');

            // 参考图（嵌入；关闭开关或无图时显示占位符）
            $imgPath = $it['image_path'] ?? '';
            $rowHasImage = false;
            if ($imgPath === '' || !$includeImages) {
                $sheet->setCellValue('L' . $r, $imgPath !== '' ? '图略' : '-');
            } else {
                if (!preg_match('#^[A-Za-z]:[\\\\/]|^/#', $imgPath)) {
                    $imgPath = __DIR__ . '/' . $imgPath;
                }
                if (file_exists($imgPath)) {
                    try {
                        $thumbPath = statementMakeThumb($imgPath);
                        $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
                        $drawing->setName('参考图');
                        $drawing->setPath($thumbPath);
                        $drawing->setHeight(42);
                        $drawing->setCoordinates('L' . $r);
                        $drawing->setOffsetX(3);
                        $drawing->setOffsetY(2);
                        $drawing->setWorksheet($sheet);
                        $rowHasImage = true;
                    } catch (\Throwable $e) {
                        $sheet->setCellValue('L' . $r, '图片丢失');
                    }
                } else {
                    $sheet->setCellValue('L' . $r, '图片丢失');
                }
            }

            // 有图片的行加高以便缩略图显示；无图片行留自动行高（配合换行文字自动展开）
            if ($rowHasImage) {
                $sheet->getRowDimension($r)->setRowHeight(45);
            }
            $r++;
        }

        // 订单小计行（已收=蓝色 优惠=橙色 未收=红色）
        $due = max(0, floatval($o['total_amount']) - floatval($o['discount_amount']) - floatval($o['paid_amount']));
        $sheet->mergeCells('A' . $r . ':J' . $r);
        $rich = new \PhpOffice\PhpSpreadsheet\RichText\RichText();
        $rich->createText('订单 ' . $o['order_no'] . ' 小计（');
        $run = $rich->createTextRun('已收 ¥' . number_format($o['paid_amount'], 2));
        $run->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF0070C0'))->setBold(true);
        $rich->createText('，');
        $run = $rich->createTextRun('优惠 ¥' . number_format($o['discount_amount'], 2));
        $run->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFF8C00'))->setBold(true);
        $rich->createText('，');
        $run = $rich->createTextRun('未收 ¥' . number_format($due, 2));
        $run->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFF0000'))->setBold(true);
        $rich->createText('）');
        $sheet->getCell('A' . $r)->setValue($rich);
        $sheet->getStyle('A' . $r)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
        $sheet->setCellValue('K' . $r, floatval($o['total_amount']));
        $sheet->getStyle('K' . $r)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('A' . $r . ':L' . $r)->getFont()->setBold(true);
        $sheet->getStyle('A' . $r . ':L' . $r)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('FEF3C7');
        $r++;
    }

    // ---- 汇总行 ----
    $sheet->mergeCells('A' . $r . ':J' . $r);
    $sheet->setCellValue('A' . $r, '合计（' . count($orders) . '笔订单）');
    $sheet->getStyle('A' . $r)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
    $sheet->setCellValue('K' . $r, $totalOrderAmount);
    $sheet->getStyle('K' . $r)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('A' . $r . ':L' . $r)->getFont()->setBold(true)->setSize(11);
    $sheet->getStyle('A' . $r . ':L' . $r)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('E0E7FF');
    $totalRow = $r;
    $r += 2;

    // ---- 汇总信息区（已收=蓝色 优惠=橙色 未收=红色） ----
    $summary = [
        ['本期应收总额：¥' . number_format($totalOrderAmount, 2), null],
        ['本期已收款：¥' . number_format($totalPaidAmount, 2), 'FF0070C0'],
        ['本期优惠金额：¥' . number_format($totalDiscountAmount, 2), 'FFFF8C00'],
        ['本期未收款：¥' . number_format($totalUnpaid, 2), 'FFFF0000'],
        ['大写金额（折后应收）：' . numToChinese($totalOrderAmount - $totalDiscountAmount), null],
    ];
    foreach ($summary as $s) {
        $sheet->mergeCells('A' . $r . ':L' . $r);
        $sheet->setCellValue('A' . $r, $s[0]);
        $sheet->getStyle('A' . $r)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);
        $sumFont = $sheet->getStyle('A' . $r)->getFont()->setBold(true);
        if ($s[1] !== null) {
            $sumFont->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($s[1]));
        }
        $r++;
    }

    // 制表信息
    $sheet->mergeCells('A' . $r . ':L' . $r);
    $sheet->setCellValue('A' . $r, '制单人：_______________        客户确认签字：_______________        日期：______年____月____日');
    $sheet->getStyle('A' . $r)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);

    // 明细区边框
    $sheet->getStyle('A' . $headRow . ':L' . $totalRow)->applyFromArray($borderStyle);

    // 冻结表头
    $sheet->freezePane('A' . ($headRow + 1));

    // ---- 输出下载 ----
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;

} catch (\Throwable $e) {
    $detail = $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
    // 写日志（便于远程排查）
    @file_put_contents(
        getBackupDir() . '/statement_export_error.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $detail . "\n" . $e->getTraceAsString() . "\n\n",
        FILE_APPEND
    );
    echo '<html><head><meta charset="UTF-8"><title>导出失败</title></head><body style="font-family:system-ui;padding:40px;text-align:center;">';
    echo '<h2 style="color:#dc2626;">⚠️ 导出失败</h2>';
    echo '<p style="color:#6b7280;">对账单导出出错，请稍后重试或联系管理员。</p>';
    echo '</body></html>';
    exit;
}
