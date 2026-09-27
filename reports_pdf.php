<?php
/**
 * 数据统计报表 - PDF 导出 (TCPDF)
 */
require_once 'config.php';
require_once __DIR__ . '/tcpdf/tcpdf.php';
initDatabase();
checkLogin();

$db = getDB();

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate   = $_GET['end_date'] ?? date('Y-m-d');

$companyName = getSetting('company_name') ?: SITE_TITLE;
$title    = $companyName . ' 数据统计报表';
$subtitle = $startDate . ' ~ ' . $endDate;

// ── 1. 综合概览 ──
$stmt = $db->prepare("
    SELECT
        COUNT(*) as total_orders,
        COALESCE(SUM(total_amount), 0) as total_sales,
        COALESCE(SUM(paid_amount), 0) as total_paid,
        COALESCE(SUM(GREATEST(0, total_amount - COALESCE(discount_amount, 0) - paid_amount)), 0) as total_unpaid
    FROM orders
    WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
");
$stmt->execute([$startDate, $endDate]);
$ov = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_orders'=>0,'total_sales'=>0,'total_paid'=>0,'total_unpaid'=>0];

// ── 2. 月度趋势 ──
$stmt = $db->prepare("
    SELECT DATE_FORMAT(created_at, '%Y-%m') as month,
           COUNT(*) as order_count,
           COALESCE(SUM(total_amount), 0) as total_amount,
           COALESCE(SUM(paid_amount), 0) as paid_amount
    FROM orders
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month DESC LIMIT 12
");
$stmt->execute();
$monthly = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

// ── 3. 客户排行 ──
$stmt = $db->prepare("
    SELECT c.id, c.name, c.phone,
           COUNT(o.id) as order_count,
           COALESCE(SUM(o.total_amount), 0) as total_amount,
           COALESCE(SUM(o.paid_amount), 0) as paid_amount,
           COALESCE(SUM(GREATEST(0, o.total_amount - COALESCE(o.discount_amount, 0) - o.paid_amount)), 0) as unpaid_amount
    FROM customers c
    INNER JOIN orders o ON c.id = o.customer_id
    WHERE o.created_at >= ? AND o.created_at <= DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY c.id
    ORDER BY total_amount DESC LIMIT 20
");
$stmt->execute([$startDate, $endDate]);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── 4. 产品排行 ──
$stmt = $db->prepare("
    SELECT oi.product_name,
           SUM(oi.quantity) as total_quantity,
           SUM(oi.amount) as total_amount
    FROM order_items oi
    INNER JOIN orders o ON oi.order_id = o.id
    WHERE o.created_at >= ? AND o.created_at <= DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY oi.product_name
    ORDER BY total_amount DESC LIMIT 20
");
$stmt->execute([$startDate, $endDate]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── 创建 PDF ──
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator($companyName);
$pdf->SetAuthor($companyName);
$pdf->SetTitle($title);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(true, 15);

// 尝试中文字体
$fontFamily = 'helvetica';
$fontBold   = 'helvetica';
$fontPath   = TCPDF_FONTS::getFontFullPath('helvetica');
$cidFonts   = ['cid0cs', 'cid0ct', 'cid0jp', 'cid0kr'];
foreach ($cidFonts as $f) {
    if (TCPDF_FONTS::getFontFullPath($f) !== false) {
        $fontFamily = $f;
        $fontBold   = $f;
        break;
    }
}

// 标题
$pdf->AddPage();
$pdf->SetFont($fontBold, 'B', 18);
$pdf->Cell(0, 10, $title, 0, 1, 'C');
$pdf->SetFont($fontFamily, '', 11);
$pdf->Cell(0, 6, '统计周期：' . $subtitle, 0, 1, 'C');
$pdf->Cell(0, 6, '生成时间：' . date('Y-m-d H:i'), 0, 1, 'C');
$pdf->Ln(4);

// 一、综合概览
$pdf->SetFont($fontBold, 'B', 13);
$pdf->Cell(0, 8, '一、综合概览', 0, 1, 'L');
$pdf->SetFont($fontFamily, '', 11);

$ovRows = [
    ['订单总数',  number_format($ov['total_orders']) . ' 单'],
    ['销售总额',  '￥ ' . number_format($ov['total_sales'], 2)],
    ['已收金额',  '￥ ' . number_format($ov['total_paid'], 2)],
    ['未收金额',  '￥ ' . number_format($ov['total_unpaid'], 2)],
    ['收款率',    $ov['total_sales'] > 0
                    ? round($ov['total_paid'] / $ov['total_sales'] * 100, 1) . '%'
                    : '0%'],
];
$pdf->SetFillColor(240, 245, 255);
foreach ($ovRows as $r) {
    $pdf->Cell(35, 8, $r[0], 1, 0, 'L', true);
    $pdf->Cell(140, 8, $r[1], 1, 1, 'L');
}
$pdf->Ln(4);

// 二、月度趋势
if (!empty($monthly)) {
    $pdf->SetFont($fontBold, 'B', 13);
    $pdf->Cell(0, 8, '二、月度趋势（近 12 个月）', 0, 1, 'L');
    $pdf->SetFont($fontBold, 'B', 10);
    $pdf->SetFillColor(220, 230, 240);
    $pdf->Cell(25, 7, '月份', 1, 0, 'C', true);
    $pdf->Cell(25, 7, '订单数', 1, 0, 'C', true);
    $pdf->Cell(50, 7, '销售额', 1, 0, 'C', true);
    $pdf->Cell(50, 7, '已收款', 1, 0, 'C', true);
    $pdf->Cell(25, 7, '收款率', 1, 1, 'C', true);
    $pdf->SetFont($fontFamily, '', 10);
    foreach ($monthly as $m) {
        $rate = $m['total_amount'] > 0
              ? round($m['paid_amount'] / $m['total_amount'] * 100, 1) . '%'
              : '0%';
        $pdf->Cell(25, 6, $m['month'], 1, 0, 'C');
        $pdf->Cell(25, 6, $m['order_count'], 1, 0, 'C');
        $pdf->Cell(50, 6, '￥' . number_format($m['total_amount'], 2), 1, 0, 'R');
        $pdf->Cell(50, 6, '￥' . number_format($m['paid_amount'], 2), 1, 0, 'R');
        $pdf->Cell(25, 6, $rate, 1, 1, 'C');
    }
    $pdf->Ln(4);
}

// 三、客户排行
if (!empty($customers)) {
    $pdf->AddPage();
    $pdf->SetFont($fontBold, 'B', 13);
    $pdf->Cell(0, 8, '三、客户排行 TOP 20', 0, 1, 'L');
    $pdf->SetFont($fontBold, 'B', 9);
    $pdf->SetFillColor(220, 230, 240);
    $pdf->Cell(8,  7, '#', 1, 0, 'C', true);
    $pdf->Cell(38, 7, '客户名称', 1, 0, 'C', true);
    $pdf->Cell(30, 7, '电话', 1, 0, 'C', true);
    $pdf->Cell(20, 7, '订单数', 1, 0, 'C', true);
    $pdf->Cell(38, 7, '销售总额', 1, 0, 'C', true);
    $pdf->Cell(38, 7, '未收金额', 1, 1, 'C', true);
    $pdf->SetFont($fontFamily, '', 9);
    foreach ($customers as $i => $c) {
        $pdf->Cell(8,  6, ($i + 1), 1, 0, 'C');
        $pdf->Cell(38, 6, mb_substr($c['name'], 0, 12), 1, 0, 'L');
        $pdf->Cell(30, 6, $c['phone'] ?? '-', 1, 0, 'C');
        $pdf->Cell(20, 6, $c['order_count'], 1, 0, 'C');
        $pdf->Cell(38, 6, '￥' . number_format($c['total_amount'], 2), 1, 0, 'R');
        $pdf->Cell(38, 6, '￥' . number_format($c['unpaid_amount'], 2), 1, 1, 'R');
    }
    $pdf->Ln(4);
}

// 四、产品排行
if (!empty($products)) {
    $pdf->SetFont($fontBold, 'B', 13);
    $pdf->Cell(0, 8, '四、产品销量排行 TOP 20', 0, 1, 'L');
    $pdf->SetFont($fontBold, 'B', 10);
    $pdf->SetFillColor(220, 230, 240);
    $pdf->Cell(10, 7, '#', 1, 0, 'C', true);
    $pdf->Cell(70, 7, '产品名称', 1, 0, 'C', true);
    $pdf->Cell(40, 7, '销售数量', 1, 0, 'C', true);
    $pdf->Cell(60, 7, '销售金额', 1, 1, 'C', true);
    $pdf->SetFont($fontFamily, '', 10);
    foreach ($products as $i => $p) {
        $pdf->Cell(10, 6, ($i + 1), 1, 0, 'C');
        $pdf->Cell(70, 6, mb_substr($p['product_name'], 0, 22), 1, 0, 'L');
        $pdf->Cell(40, 6, number_format($p['total_quantity']), 1, 0, 'C');
        $pdf->Cell(60, 6, '￥' . number_format($p['total_amount'], 2), 1, 1, 'R');
    }
}

// ── 输出下载 ──
$filename = '报表_' . $startDate . '_' . $endDate . '.pdf';
$pdf->Output($filename, 'D');
exit;
