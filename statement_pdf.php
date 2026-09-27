<?php
/**
 * 客户对账单 - PDF 导出 (TCPDF)
 */
require_once 'config.php';
require_once __DIR__ . '/tcpdf/tcpdf.php';
initDatabase();
checkLogin();

$db = getDB();

$customerId = intval($_GET['customer_id'] ?? 0);
$startDate  = $_GET['start_date'] ?? '';
$endDate    = $_GET['end_date'] ?? '';

if (empty($startDate) && empty($endDate)) {
    $startDate = date('Y-m-01');
    $endDate   = date('Y-m-d');
}

$companyName = getSetting('company_name') ?: SITE_TITLE;

// 数字金额转中文大写
function amountToChinese($amount) {
    $amount = round($amount, 2);
    if ($amount == 0) return '零元整';
    
    $cnNums = ['零', '壹', '贰', '叁', '肆', '伍', '陆', '柒', '捌', '玖'];
    $cnUnits = ['', '拾', '佰', '仟'];
    $cnBigUnits = ['', '万', '亿', '万亿'];
    $cnDecUnits = ['角', '分'];
    
    $integer = floor($amount);
    $decimal = round(($amount - $integer) * 100);
    
    $result = '';
    
    // 处理整数部分
    if ($integer > 0) {
        $intStr = strval($integer);
        $intLen = strlen($intStr);
        $zero = false;
        $unitPos = 0;
        
        for ($i = $intLen - 1; $i >= 0; $i--) {
            $digit = intval($intStr[$i]);
            $pos = ($intLen - 1 - $i) % 4;
            $bigPos = floor(($intLen - 1 - $i) / 4);
            
            if ($digit == 0) {
                if (!$zero && $result != '') {
                    $result = $cnNums[0] . $result;
                    $zero = true;
                }
            } else {
                $zero = false;
                $unit = ($pos == 0 && $bigPos > 0) ? $cnBigUnits[$bigPos] : $cnUnits[$pos];
                $result = $cnNums[$digit] . $unit . $result;
            }
            
            if ($pos == 0 && $bigPos > 0 && substr($intStr, max(0, $i - 3), 4) != '0000') {
                $result = $cnBigUnits[$bigPos] . $result;
            }
        }
        $result .= '元';
    } else {
        $result = '零元';
    }
    
    // 处理小数部分
    $jiao = floor($decimal / 10);
    $fen = $decimal % 10;
    
    if ($jiao == 0 && $fen == 0) {
        $result .= '整';
    } else {
        if ($jiao > 0) {
            $result .= $cnNums[$jiao] . '角';
        } else if ($integer > 0) {
            $result .= '零';
        }
        if ($fen > 0) {
            $result .= $cnNums[$fen] . '分';
        }
    }
    
    return $result;
}

// 客户信息
$customerName = '所有客户';
$customerPhone = '';
$customerContact = '';
$customerAddress = '';
if ($customerId > 0) {
    $cStmt = $db->prepare("SELECT name, phone, contact, address FROM customers WHERE id = ?");
    $cStmt->execute([$customerId]);
    $cInfo = $cStmt->fetch(PDO::FETCH_ASSOC);
    if ($cInfo) {
        $customerName    = $cInfo['name'];
        $customerPhone   = $cInfo['phone'] ?? '';
        $customerContact = $cInfo['contact'] ?? '';
        $customerAddress = $cInfo['address'] ?? '';
    }
}

// 订单查询
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

$sql = "SELECT o.id, o.order_no, o.total_amount, COALESCE(o.discount_amount, 0) as discount_amount, COALESCE(o.paid_amount, 0) as paid_amount,
               o.payment_status, o.status, o.created_at, o.order_date,
               c.name as customer_name, c.contact, c.phone, c.address
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        WHERE $whereSql
        ORDER BY o.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 统计
$totalOrderAmount = 0;
$totalPaidAmount  = 0;
$totalDiscountAmount = 0;
foreach ($orders as $o) {
    $totalOrderAmount += floatval($o['total_amount']);
    $totalPaidAmount  += floatval($o['paid_amount']);
    $totalDiscountAmount += floatval($o['discount_amount'] ?? 0);
}
$totalUnpaid = max(0, $totalOrderAmount - $totalPaidAmount - $totalDiscountAmount);

// 历史总金额（仅当筛选了具体客户）
$historyTotal = 0;
if ($customerId > 0) {
    $hStmt = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE customer_id = ? AND status != 4");
    $hStmt->execute([$customerId]);
    $historyTotal = floatval($hStmt->fetchColumn());
}

// 收款记录
$paymentMap = [];
if (!empty($orders)) {
    $orderIds = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $pStmt = $db->prepare("SELECT order_id, amount, payment_method, created_at, remark
                           FROM payments
                           WHERE order_id IN ($placeholders)
                           ORDER BY created_at DESC");
    $pStmt->execute($orderIds);
    foreach ($pStmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $paymentMap[$p['order_id']][] = $p;
    }
}

// ── 创建 PDF ──
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator($companyName);
$pdf->SetAuthor($companyName);
$pdf->SetTitle('客户对账单 - ' . $customerName);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(true, 18);

// 中文字体
$fontFamily = 'helvetica';
$fontBold   = 'helvetica';
$cidFonts   = ['cid0cs', 'cid0ct', 'cid0jp', 'cid0kr'];
foreach ($cidFonts as $f) {
    if (TCPDF_FONTS::getFontFullPath($f) !== false) {
        $fontFamily = $f;
        $fontBold   = $f;
        break;
    }
}

$pdf->AddPage();

// 标题
$pdf->SetFont($fontBold, 'B', 20);
$pdf->Cell(0, 12, $companyName, 0, 1, 'C');
$pdf->SetFont($fontBold, 'B', 16);
$pdf->Cell(0, 8, '客 户 对 账 单', 0, 1, 'C');
$pdf->SetFont($fontFamily, '', 10);
$pdf->Cell(0, 6, '统计周期：' . $startDate . ' ~ ' . $endDate, 0, 1, 'C');
$pdf->Ln(2);

// 客户信息
$pdf->SetFont($fontBold, 'B', 11);
$pdf->SetFillColor(240, 245, 255);
$pdf->Cell(0, 7, '客户信息', 1, 1, 'L', true);
$pdf->SetFont($fontFamily, '', 10);
$pdf->Cell(25, 6, '客户名称', 1, 0);
$pdf->Cell(60, 6, $customerName, 1, 0);
$pdf->Cell(25, 6, '联系电话', 1, 0);
$pdf->Cell(70, 6, $customerPhone ?: '-', 1, 1);
if (!empty($customerContact)) {
    $pdf->Cell(25, 6, '联系人', 1, 0);
    $pdf->Cell(40, 6, $customerContact, 1, 0);
    $pdf->Cell(25, 6, '客户地址', 1, 0);
    $pdf->Cell(90, 6, mb_substr($customerAddress ?: '-', 0, 36), 1, 1);
}
$pdf->Ln(3);

// 汇总
$pdf->SetFont($fontBold, 'B', 11);
$pdf->SetFillColor(240, 245, 255);
$pdf->Cell(0, 7, '汇总信息', 1, 1, 'L', true);
$pdf->SetFont($fontFamily, '', 10);
$pdf->Cell(36, 6, '本期订单数', 1, 0, 'C');
$pdf->Cell(36, 6, '本期应收总额', 1, 0, 'C');
$pdf->Cell(36, 6, '本期已收款', 1, 0, 'C');
$pdf->Cell(36, 6, '本期优惠金额', 1, 0, 'C');
$pdf->Cell(36, 6, '本期未收款', 1, 1, 'C');
$pdf->SetFont($fontBold, 'B', 11);
$pdf->Cell(36, 8, count($orders) . ' 单', 1, 0, 'C');
$pdf->Cell(36, 8, '￥' . number_format($totalOrderAmount, 2), 1, 0, 'C');
$pdf->Cell(36, 8, '￥' . number_format($totalPaidAmount, 2), 1, 0, 'C');
$pdf->Cell(36, 8, $totalDiscountAmount > 0 ? '-￥' . number_format($totalDiscountAmount, 2) : '￥0.00', 1, 0, 'C');
$pdf->Cell(36, 8, '￥' . number_format($totalUnpaid, 2), 1, 1, 'C');
$pdf->SetFont($fontFamily, '', 10);
$pdf->Cell(180, 6, '本期销售额大写：' . amountToChinese($totalOrderAmount), 1, 1, 'L');
$pdf->Ln(3);

// 订单明细
$pdf->SetFont($fontBold, 'B', 11);
$pdf->SetFillColor(240, 245, 255);
$pdf->Cell(0, 7, '订单明细', 1, 1, 'L', true);

if (empty($orders)) {
    $pdf->SetFont($fontFamily, 'I', 10);
    $pdf->Cell(180, 10, '（所选时间段无订单）', 1, 1, 'C');
} else {
    $pdf->SetFont($fontBold, 'B', 9);
    $pdf->SetFillColor(220, 230, 240);
    $pdf->Cell(30, 7, '订单号', 1, 0, 'C', true);
    $pdf->Cell(20, 7, '日期', 1, 0, 'C', true);
    $pdf->Cell(28, 7, '订单金额', 1, 0, 'C', true);
    $pdf->Cell(28, 7, '已收金额', 1, 0, 'C', true);
    $pdf->Cell(24, 7, '优惠', 1, 0, 'C', true);
    $pdf->Cell(24, 7, '未收金额', 1, 0, 'C', true);
    $pdf->Cell(26, 7, '状态', 1, 1, 'C', true);
    $pdf->SetFont($fontFamily, '', 9);

    $statusMap = [
        0 => '待确认', 1 => '已确认', 2 => '生产中', 3 => '已完成', 4 => '已取消',
    ];

    foreach ($orders as $o) {
        $unpaid = max(0, floatval($o['total_amount']) - floatval($o['discount_amount'] ?? 0) - floatval($o['paid_amount']));
        $date   = !empty($o['order_date'])
                ? date('Y-m-d', strtotime($o['order_date']))
                : date('Y-m-d', strtotime($o['created_at']));
        $status = $statusMap[$o['status']] ?? '-';

        $discount = floatval($o['discount_amount'] ?? 0);
        $pdf->Cell(30, 6, $o['order_no'], 1, 0, 'C');
        $pdf->Cell(20, 6, $date, 1, 0, 'C');
        $pdf->Cell(28, 6, '￥' . number_format($o['total_amount'], 2), 1, 0, 'R');
        $pdf->Cell(28, 6, '￥' . number_format($o['paid_amount'], 2), 1, 0, 'R');
        $pdf->Cell(24, 6, $discount > 0 ? '-￥' . number_format($discount, 2) : '-', 1, 0, 'R');
        $pdf->Cell(24, 6, '￥' . number_format($unpaid, 2), 1, 0, 'R');
        $pdf->Cell(26, 6, $status, 1, 1, 'C');

        // 收款记录
        if (!empty($paymentMap[$o['id']])) {
            $pdf->SetFont($fontFamily, 'I', 8);
            $pdf->SetTextColor(80, 80, 80);
            $line = '  └ 收款记录：';
            $items = [];
            foreach ($paymentMap[$o['id']] as $p) {
                $items[] = date('Y-m-d', strtotime($p['created_at']))
                         . ' ￥' . number_format($p['amount'], 2)
                         . ($p['payment_method'] ? ' (' . $p['payment_method'] . ')' : '');
            }
            $line .= implode('；', $items);
            $pdf->MultiCell(180, 5, $line, 1, 'L', false, 1);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont($fontFamily, '', 9);
        }
    }
}

// 落款
$pdf->Ln(8);
$pdf->SetFont($fontFamily, '', 10);
$pdf->Cell(0, 6, '对账人：________________', 0, 1, 'R');
$pdf->Cell(0, 6, '对账日期：' . date('Y-m-d'), 0, 1, 'R');

// ── 输出下载 ──
$filename = '对账单_' . $customerName . '_' . $startDate . '_' . $endDate . '.pdf';
$pdf->Output($filename, 'D');
exit;
