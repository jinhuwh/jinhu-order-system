<?php
/**
 * 订单导出Excel(v3 - 稳健版)
 * 关键改进:
 *   - 用 IOFactory::createWriter + save('php://output') 替代 save() 不可控输出
 *   - 每个图片独立 try/catch,单图失败不影响整体
 *   - 不再使用 getimagesize()(可能导致 warning 污染 buffer)
 *   - 不输出任何 echo 到 stdout
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
ini_set('display_errors', '0');

require_once 'config.php';
initDatabase();
checkLogin();

if (!isset($_GET['id'])) {
    header('Content-Type: text/plain; charset=utf-8');
    die('缺少订单ID');
}

$order_id = intval($_GET['id']);
$db = getDB();

$order = $db->prepare("SELECT o.*, c.name as customer_name, c.phone as customer_phone, c.address as customer_address, u.realname as creator_name
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    LEFT JOIN users u ON o.created_by = u.id
    WHERE o.id = ?");
$order->execute([$order_id]);
$order = $order->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header('Content-Type: text/plain; charset=utf-8');
    die('订单不存在');
}

$items = $db->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY id");
$items->execute([$order_id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

$payments = $db->prepare("SELECT * FROM payments WHERE order_id = ? ORDER BY created_at");
$payments->execute([$order_id]);
$payments = $payments->fetchAll(PDO::FETCH_ASSOC);

$statusMap = [0 => '待确认', 1 => '已确认', 2 => '生产中', 3 => '已完成', 4 => '已取消'];

require_once 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\IOFactory;

// 清理所有输出 buffer(防止任何之前的输出污染 Excel)
while (ob_get_level()) {
    ob_end_clean();
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('订单详情');

// ============== 列宽 ==============
$sheet->getColumnDimension('A')->setWidth(6);
$sheet->getColumnDimension('B')->setWidth(14);
$sheet->getColumnDimension('C')->setWidth(40);
$sheet->getColumnDimension('D')->setWidth(11);
$sheet->getColumnDimension('E')->setWidth(9);
$sheet->getColumnDimension('F')->setWidth(7);
$sheet->getColumnDimension('G')->setWidth(8);
$sheet->getColumnDimension('H')->setWidth(11);
$sheet->getColumnDimension('I')->setWidth(13);
$sheet->getColumnDimension('J')->setWidth(22);

// ============== 全局居中(逐个单元格设置)==============
// getDefaultStyle() 不存在于 Worksheet,仅默认样式可用
// 直接在每个需要的地方设 alignment

// ============== 标题 ==============
$sheet->mergeCells('A1:J1');
$sheet->setCellValue('A1', '广告制作订单');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(18);
$sheet->getStyle('A1')->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
    ->setVertical(Alignment::VERTICAL_CENTER);
$sheet->getRowDimension(1)->setRowHeight(32);

// 公共函数:给区域设垂直水平居中
$centerHelper = function($range) use ($sheet) {
    $sheet->getStyle($range)->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_CENTER);
};

// ============== 订单基本信息 ==============
$infoRows = [
    ['订单编号:', $order['order_no'], '订单日期:', $order['order_date']],
    ['客户名称:', $order['customer_name'], '联系电话:', $order['customer_phone']],
    ['客户地址:', $order['customer_address'], null, null],
    ['开单人员:', $order['creator_name'], '订单状态:', $statusMap[$order['status']] ?? '未知'],
];

$row = 3;
foreach ($infoRows as $ir) {
    $sheet->setCellValue('A' . $row, $ir[0]);
    $sheet->setCellValue('B' . $row, $ir[1] ?? '');
    $sheet->mergeCells('B' . $row . ':C' . $row);
    if (!empty($ir[2])) {
        $sheet->setCellValue('D' . $row, $ir[2]);
        $sheet->setCellValue('E' . $row, $ir[3] ?? '');
        $sheet->mergeCells('E' . $row . ':J' . $row);
    }

    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    $sheet->getStyle('D' . $row)->getFont()->setBold(true);
    // A列标签右对齐,内容左对齐
    $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('E' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    // 垂直居中
    $sheet->getStyle('A' . $row . ':J' . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getStyle('A' . $row . ':J' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getRowDimension($row)->setRowHeight(24);

    $row++;
}

// ============== 产品明细 ==============
$row += 1;

$sheet->setCellValue('A' . $row, '序号');
$sheet->setCellValue('B' . $row, '产品名称');
$sheet->setCellValue('C' . $row, '规格说明');
$sheet->setCellValue('D' . $row, '尺寸(米)');
$sheet->setCellValue('E' . $row, '平方数');
$sheet->setCellValue('F' . $row, '数量');
$sheet->setCellValue('G' . $row, '单位');
$sheet->setCellValue('H' . $row, '单价');
$sheet->setCellValue('I' . $row, '金额');
$sheet->setCellValue('J' . $row, '参考图');

$headerStyle = $sheet->getStyle('A' . $row . ':J' . $row);
$headerStyle->getFont()->setBold(true);
$headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFE0E0E0'));
$headerStyle->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
    ->setVertical(Alignment::VERTICAL_CENTER);
$headerStyle->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->getRowDimension($row)->setRowHeight(28);

$row++;

$totalAmount = 0;
$totalDiscount = floatval($order['discount_amount'] ?? 0);

foreach ($items as $index => $item) {
    $sheet->setCellValue('A' . $row, $index + 1);
    $sheet->setCellValue('B' . $row, $item['product_name']);
    $sheet->setCellValue('C' . $row, $item['specification']);
    $sheet->getStyle('C' . $row)->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_LEFT)
        ->setVertical(Alignment::VERTICAL_CENTER)
        ->setWrapText(true);

    $sizeText = '';
    if (!empty($item['length']) && !empty($item['width'])) {
        $sizeText = sprintf('%.2f x %.2f', $item['length'], $item['width']);
    }
    $sheet->setCellValue('D' . $row, $sizeText);

    if (!empty($item['square_meter'])) {
        $sheet->setCellValue('E' . $row, (float)$item['square_meter']);
    }

    $sheet->setCellValue('F' . $row, (float)$item['quantity']);
    $sheet->setCellValue('G' . $row, $item['unit']);
    $sheet->setCellValue('H' . $row, (float)$item['unit_price']);
    $sheet->setCellValue('I' . $row, (float)$item['amount']);

    $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('0.00');
    $sheet->getStyle('H' . $row . ':I' . $row)->getNumberFormat()->setFormatCode('¥#,##0.00');

    $sheet->getRowDimension($row)->setRowHeight(90);
    // 水平+垂直居中
    $sheet->getStyle('A' . $row . ':I' . $row)->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getStyle('A' . $row . ':J' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    // 图片嵌入(独立 try/catch,单图失败不影响整体)
    $imagePath = $item['image_path'] ?? '';
    if (!empty($imagePath) && file_exists($imagePath) && is_readable($imagePath)) {
        try {
            $imgInfo = @getimagesize($imagePath);
            if ($imgInfo !== false && $imgInfo[0] > 0 && $imgInfo[1] > 0) {
                $srcW = $imgInfo[0];
                $srcH = $imgInfo[1];

                // ★ Excel 所有 setWidth/Height/Offset/RowHeight 单位都是 pt
                //   1pt = 1.333px (96 DPI下)
                // ★ setColumnDimension()->setWidth(22) 是字符单位
                //   实际宽 = 22*7 + 5 = 159px = 119pt
                // ★ setRowHeight(90) 直接是 pt = 90pt = 120px

                // J 列宽转 pt
                $colWidthChar = 22;
                $colWidthPt = ($colWidthChar * 7 + 5) / 1.333;  // ≈ 119pt
                $rowHeightPt = 90;                              // 90pt = 120px

                // 图片最大尺寸 pt(留 4pt 边距)
                $maxWPt = $colWidthPt - 4;  // ≈ 115pt
                $maxHPt = $rowHeightPt - 4;  // ≈ 86pt

                $ratioW = $maxWPt / $srcW;
                $ratioH = $maxHPt / $srcH;
                $ratio = min($ratioW, $ratioH, 1.0);
                $finalWPt = $srcW * $ratio;
                $finalHPt = $srcH * $ratio;

                // 居中偏移 (Excel 显示以左上为原点)
                $offsetX = ($colWidthPt - $finalWPt) / 2;
                $offsetY = ($rowHeightPt - $finalHPt) / 2;

                $drawing = new Drawing();
                $drawing->setName('产品图_' . ($index + 1));
                $drawing->setPath($imagePath);
                $drawing->setWidth($finalWPt);
                $drawing->setHeight($finalHPt);
                $drawing->setCoordinates('J' . $row);
                $drawing->setOffsetX($offsetX);
                $drawing->setOffsetY($offsetY);
                $drawing->setWorksheet($sheet);
                $sheet->setCellValue('J' . $row, '');
            } else {
                $sheet->setCellValue('J' . $row, '-');
                $sheet->getStyle('J' . $row)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFCCCCCC'));
            }
        } catch (Exception $e) {
            // 单张图片失败不影响整体
            $sheet->setCellValue('J' . $row, '-');
            $sheet->getStyle('J' . $row)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFCCCCCC'));
        }
    } else {
        $sheet->setCellValue('J' . $row, '-');
        $sheet->getStyle('J' . $row)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFCCCCCC'));
    }

    $totalAmount += $item['amount'];
    $row++;
}

// 合计行
$sheet->setCellValue('A' . $row, '合计');
$sheet->mergeCells('A' . $row . ':H' . $row);
$sheet->setCellValue('I' . $row, '¥' . number_format($totalAmount, 2));
$sheet->mergeCells('I' . $row . ':J' . $row);

$totalStyle = $sheet->getStyle('A' . $row . ':J' . $row);
$totalStyle->getFont()->setBold(true);
$totalStyle->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFF3E0F7'));
$totalStyle->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getRowDimension($row)->setRowHeight(28);

// ============== 订单备注 ==============
$row += 2;
$sheet->setCellValue('A' . $row, '订单备注:');
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->mergeCells('B' . $row . ':J' . ($row + 2));
$sheet->setCellValue('B' . $row, $order['remark']);
$sheet->getStyle('A' . $row . ':J' . ($row + 2))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle('B' . $row)->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_LEFT)
    ->setVertical(Alignment::VERTICAL_TOP)
    ->setWrapText(true);

// ============== 收款记录 ==============
$row += 4;
$sheet->setCellValue('A' . $row, '收款记录');
$sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(14);

$row++;
$sheet->setCellValue('A' . $row, '序号');
$sheet->setCellValue('B' . $row, '收款日期');
$sheet->setCellValue('C' . $row, '收款金额');
$sheet->setCellValue('D' . $row, '收款方式');
$sheet->setCellValue('E' . $row, '备注');
$sheet->setCellValue('G' . $row, '收款凭证');
$sheet->mergeCells('E' . $row . ':F' . $row);
$sheet->mergeCells('G' . $row . ':J' . $row);

$paymentHeaderStyle = $sheet->getStyle('A' . $row . ':J' . $row);
$paymentHeaderStyle->getFont()->setBold(true);
$paymentHeaderStyle->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFE0E0E0'));
$paymentHeaderStyle->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->getRowDimension($row)->setRowHeight(28);

$row++;
$totalPaid = 0;

foreach ($payments as $index => $payment) {
    $sheet->setCellValue('A' . $row, $index + 1);
    $sheet->setCellValue('B' . $row, date('Y-m-d H:i', strtotime($payment['created_at'])));
    $sheet->setCellValue('C' . $row, (float)$payment['amount']);
    $sheet->getStyle('C' . $row)->getNumberFormat()->setFormatCode('¥#,##0.00');
    $sheet->setCellValue('D' . $row, $payment['payment_method']);
    $sheet->setCellValue('E' . $row, $payment['remark']);
    $sheet->mergeCells('E' . $row . ':F' . $row);

    $sheet->getRowDimension($row)->setRowHeight(70);
    $sheet->getStyle('A' . $row . ':J' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    $payImgPath = $payment['attachment'] ?? '';
    if (!empty($payImgPath) && file_exists($payImgPath) && is_readable($payImgPath)) {
        try {
            $imgInfo = @getimagesize($payImgPath);
            if ($imgInfo !== false && $imgInfo[0] > 0 && $imgInfo[1] > 0) {
                $srcW = $imgInfo[0];
                $srcH = $imgInfo[1];

                // G:J 合并区域宽 = (8+11+13+22) 字符 ≈ 54*7+5 = 383px = 287pt
                $colWidthChar = 8 + 11 + 13 + 22;  // G:H:I:J
                $colWidthPt = ($colWidthChar * 7 + 5) / 1.333;  // ≈ 287pt
                $rowHeightPt = 70;  // 70pt = 93px

                $maxWPt = $colWidthPt - 4;  // ≈ 283pt
                $maxHPt = $rowHeightPt - 4;  // ≈ 66pt

                $ratioW = $maxWPt / $srcW;
                $ratioH = $maxHPt / $srcH;
                $ratio = min($ratioW, $ratioH, 1.0);
                $finalWPt = $srcW * $ratio;
                $finalHPt = $srcH * $ratio;

                $offsetX = ($colWidthPt - $finalWPt) / 2;
                $offsetY = ($rowHeightPt - $finalHPt) / 2;

                $drawing = new Drawing();
                $drawing->setName('凭证_' . ($index + 1));
                $drawing->setPath($payImgPath);
                $drawing->setWidth($finalWPt);
                $drawing->setHeight($finalHPt);
                $drawing->setCoordinates('G' . $row);
                $drawing->setOffsetX($offsetX);
                $drawing->setOffsetY($offsetY);
                $drawing->setWorksheet($sheet);
                $sheet->setCellValue('G' . $row, '');
            }
        } catch (Exception $e) {
            // ignore
        }
    } else {
        $sheet->setCellValue('G' . $row, '-');
        $sheet->getStyle('G' . $row)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFCCCCCC'));
    }
    $sheet->mergeCells('G' . $row . ':J' . $row);

    $totalPaid += $payment['amount'];
    $row++;
}

// 收款合计
$row++;
$sheet->setCellValue('A' . $row, '订单金额:￥' . number_format($totalAmount, 2));
if ($totalDiscount > 0.01) {
    $sheet->setCellValue('B' . $row, '优惠:￥' . number_format($totalDiscount, 2));
    $sheet->setCellValue('D' . $row, '已收款:￥' . number_format($totalPaid, 2));
    $sheet->setCellValue('G' . $row, '待收款:￥' . number_format(max(0, $totalAmount - $totalDiscount - $totalPaid), 2));
} else {
    $sheet->setCellValue('D' . $row, '已收款:￥' . number_format($totalPaid, 2));
    $sheet->setCellValue('G' . $row, '待收款:￥' . number_format(max(0, $totalAmount - $totalPaid), 2));
}
$sheet->mergeCells('A' . $row . ':C' . $row);
$sheet->mergeCells('D' . $row . ':F' . $row);
$sheet->mergeCells('G' . $row . ':J' . $row);
$sheet->getStyle('A' . $row . ':J' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row . ':J' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->getRowDimension($row)->setRowHeight(28);

// ============== 输出 Excel ==============
$filename = '订单_' . $order['order_no'] . '_' . date('Ymd') . '.xlsx';

// 不能写入到 /volume1/web/erp/(权限 0555),必须先写到 /var/services/tmp
// 然后用 readfile() 输出给浏览器
$tmpFile = tempnam(sys_get_temp_dir(), 'erp_xlsx_');
if ($tmpFile === false) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(500);
    die('无法创建临时文件');
}

$writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
$writer->save($tmpFile);

if (!file_exists($tmpFile)) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(500);
    die('Excel 生成失败');
}

// 输出到浏览器
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpFile));
header('Cache-Control: max-age=0');
header('Pragma: public');

$fp = fopen($tmpFile, 'rb');
if ($fp) {
    while (!feof($fp)) {
        echo fread($fp, 8192);
        @ob_flush();
        @flush();
    }
    fclose($fp);
}
@unlink($tmpFile);
exit;