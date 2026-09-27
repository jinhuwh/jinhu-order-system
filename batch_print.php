<?php
/** 批量打印订单 */
require_once "config.php";
initDatabase(); checkLogin();
$db = getDB();

$idsParam = trim($_GET["ids"] ?? "");
if (empty($idsParam)) { echo "<p style=text-align:center;margin-top:40px;>未选择订单 <a href=orders.php>返回</a></p>"; exit; }
$ids = array_filter(array_map("intval", explode(",", $idsParam)), function($v){return $v>0;});
if (empty($ids)) { echo "<p style=text-align:center;margin-top:40px;>ID无效 <a href=orders.php>返回</a></p>"; exit; }

$uid = getCurrentUserId(); $makerName = "管理员";
if ($uid) {
    $stmt = $db->prepare("SELECT realname FROM users WHERE id=?");
    $stmt->execute([$uid]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!empty($r["realname"])) $makerName = $r["realname"];
}
$companyName = getSetting("company_name") ?: "长沙金赋文化传播有限公司";

$ph = implode(",", array_fill(0, count($ids), "?"));
$sql = "SELECT o.*,c.name as customer_name,c.phone,c.contact,c.address FROM orders o LEFT JOIN customers c ON o.customer_id=c.id WHERE o.id IN(".$ph.") ORDER BY FIELD(o.id,".$ph.")";
$stmt = $db->prepare($sql); $stmt->execute(array_merge($ids,$ids)); $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($orders)) { echo "<p style=text-align:center;margin-top:40px;>未找到订单 <a href=orders.php>返回</a></p>"; exit; }

$is = $db->prepare("SELECT * FROM order_items WHERE order_id IN(".$ph.") ORDER BY id");
$is->execute($ids);
while ($item = $is->fetch(PDO::FETCH_ASSOC)) $itemsByOrder[$item["order_id"]][] = $item;
$paymentText = ["未付款","部分收款","已付清"]; $printSeq = 0;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>批量打印 - <?php echo count($orders); ?> 份订单</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#fff;font-family:"SimSun","宋体",serif}
.toolbar{position:fixed;top:0;left:0;right:0;background:#1E293B;color:#fff;padding:12px 24px;display:flex;align-items:center;gap:16px;z-index:9999;font-size:14px}
.toolbar h2{font-size:16px;font-weight:normal}
.toolbar .cnt{color:#94A3B8}
.btn{padding:6px 16px;border-radius:6px;cursor:pointer;font-size:14px;border:none;text-decoration:none;display:inline-block;color:#fff}
.btn-print{background:#3B82F6}.btn-print:hover{background:#2563EB}
.btn-close{background:#475569}.btn-close:hover{background:#334155}
.print-wrapper{padding-top:64px}
.print-area{display:block;font-family:"SimSun","宋体",serif;font-size:10pt;color:#000;line-height:1.5;width:95%;max-width:680px;margin:0 auto;padding:10px 4px}
.print-company{text-align:center;font-size:16pt;font-weight:bold;margin-bottom:4px;letter-spacing:2px}
.print-title{text-align:center;font-size:18pt;font-weight:bold;margin-bottom:8px;letter-spacing:4px}
.print-info-table{width:100%;border-collapse:collapse;border:none!important;margin-bottom:4px;font-size:10pt}
.print-info-table td{padding:2px 6px;white-space:nowrap;border:none!important}
.print-items{width:100%;border-collapse:collapse;border:1px solid #000!important;margin:4px 2px;font-size:9pt;table-layout:fixed}
.print-items th:nth-child(1){width:6%}.print-items th:nth-child(2){width:23%}.print-items th:nth-child(3){width:6%}
.print-items th:nth-child(4){width:11%}.print-items th:nth-child(5){width:11%}.print-items th:nth-child(6){width:14%}.print-items th:nth-child(7){width:29%}
.print-items.with-sqm th:nth-child(1){width:6%}.print-items.with-sqm th:nth-child(2){width:20%}.print-items.with-sqm th:nth-child(3){width:11%}
.print-items.with-sqm th:nth-child(4){width:6%}.print-items.with-sqm th:nth-child(5){width:9%}.print-items.with-sqm th:nth-child(6){width:10%}.print-items.with-sqm th:nth-child(7){width:13%}.print-items.with-sqm th:nth-child(8){width:25%}
.print-items th{border:1px solid #000;padding:3px 4px;text-align:center;font-weight:bold;background:#fff}
.print-items td{border:1px solid #000;padding:3px 4px;text-align:center}
.print-items .total-row{font-weight:bold}
.print-items tr.caps-row td{font-weight:normal;text-align:left;padding:4px 8px}
.print-summary{width:100%;border-collapse:collapse;border:none!important;margin-top:2px;font-size:10pt}
.print-summary td{padding:3px 6px;white-space:nowrap;border:none!important}
.print-sign{width:100%;border-collapse:collapse;border:none!important;margin-top:6px;font-size:10pt}
.print-sign td{padding:4px 6px;width:33%;border:none!important}
.sign-line{display:inline-block;width:80px;border-bottom:1px solid #000;margin-left:4px}
.order-sep{border-bottom:2px dashed #CBD5E1;margin:0 auto 8px;width:95%;max-width:680px}
.print-hint{text-align:center;color:#94A3B8;font-size:12px;padding:12px;background:#F8FAFC;border-bottom:1px solid #E2E8F0}
@media print{@page{size:A4 portrait;margin:10mm 12mm}body{background:white;margin:0;padding:0}.toolbar,.print-hint{display:none!important}.print-wrapper{padding-top:0}.order-sep{display:none!important}.print-area{page-break-before:always}.print-area:first-child{page-break-before:auto}}
</style>
</head>
<body>
<div class="toolbar">
  <h2>批量打印</h2>
  <span class="cnt">共 <?php echo count($orders); ?> 份订单</span>
  <div style="flex:1"></div>
  <button class="btn btn-print" onclick="window.print()">开始打印</button>
  <button class="btn btn-close" onclick="window.close()">关闭</button>
  <a href="orders.php" class="btn btn-close" style="display:inline-block">返回</a>
</div>
<div class="print-hint">点击「开始打印」或按 Ctrl+P 调出打印窗口</div>
<div class="print-wrapper">
<?php foreach($orders as $order):
  $oid=$order["id"]; $items=isset($itemsByOrder[$oid])?$itemsByOrder[$oid]:[]; $printSeq++; $tq=array_sum(array_column($items,"quantity"));
  // 本单是否含平方数（有则打印显示平方数列）
  $hasSqm=false; $sqmTotal=0;
  foreach($items as $__it){ if(floatval($__it["square_meter"]??0)>0){ $hasSqm=true; $sqmTotal+=floatval($__it["square_meter"]); } }
?>
<?php if($printSeq>1) echo "<div class=order-sep></div>"; ?>
<div class="print-area">
  <div class="print-company"><?php echo htmlspecialchars($companyName); ?></div>
  <div class="print-title">销售单</div>
  <table class="print-info-table">
    <tr>
      <td>客户：<?php echo htmlspecialchars($order["customer_name"]); ?></td>
      <td>电话：<?php echo htmlspecialchars($order["phone"]?:""); ?></td>
      <td>单据日期：<?php echo htmlspecialchars($order["order_date"]?:date("Y-m-d",strtotime($order["created_at"]))); ?></td>
      <td>单据编号：<?php echo $order["order_no"]; ?></td>
    </tr>
    <tr>
      <td>联系人：<?php echo htmlspecialchars($order["contact"]?:""); ?></td>
      <td colspan="3">地址：<?php echo htmlspecialchars($order["address"]?:""); ?></td>
    </tr>
  </table>
  <table class="print-items<?php echo $hasSqm?" with-sqm":""; ?>">
    <thead><tr><th>序号</th><th>商品</th><?php if($hasSqm): ?><th>平方数</th><?php endif; ?><th>单位</th><th>数量</th><th>单价</th><th>销售金额</th><th>制作要求</th></tr></thead>
    <tbody>
    <?php foreach($items as $i=>$item): ?>
      <tr>
        <td><?php echo $i+1; ?></td>
        <td><?php echo htmlspecialchars($item["product_name"]?:""); ?></td>
        <?php if($hasSqm): ?><td><?php echo floatval($item["square_meter"]??0)>0 ? formatSqm($item["square_meter"]) : ""; ?></td><?php endif; ?>
        <td><?php echo htmlspecialchars($item["unit"]); ?></td>
        <td><?php echo number_format($item["quantity"],2); ?></td>
        <td><?php echo number_format($item["unit_price"],2); ?></td>
        <td><?php echo number_format($item["amount"],2); ?></td>
        <td><?php echo htmlspecialchars($item["specification"]?:""); ?></td>
      </tr>
    <?php endforeach; ?>
      <?php if($hasSqm): ?>
      <tr class="total-row"><td colspan="2" style="text-align:right">合计：</td><td><?php echo formatSqm($sqmTotal); ?></td><td><?php echo number_format($tq,2); ?></td><td></td><td><?php echo number_format($order["total_amount"],2); ?></td><td></td></tr>
      <tr class="caps-row"><td colspan="8">合计金额（大写）：<?php echo numToChinese($order["total_amount"]); ?></td></tr>
      <?php else: ?>
      <tr class="total-row"><td colspan="3" style="text-align:right">合计：</td><td><?php echo number_format($tq,2); ?></td><td></td><td><?php echo number_format($order["total_amount"],2); ?></td><td></td></tr>
      <tr class="caps-row"><td colspan="7">合计金额（大写）：<?php echo numToChinese($order["total_amount"]); ?></td></tr>
      <?php endif; ?>
    </tbody>
  </table>
  <table class="print-summary">
    <tr>
      <td>订单金额：<?php echo number_format($order["total_amount"],2); ?></td>
      <?php $batchDiscount = floatval($order['discount_amount'] ?? 0); ?>
      <?php if ($batchDiscount > 0.01): ?>
      <td style="color:#F59E0B;">优惠：<?php echo number_format($batchDiscount,2); ?></td>
      <?php endif; ?>
      <td>已收款：<?php echo number_format($order["paid_amount"],2); ?></td>
      <td>待收款：<?php echo number_format(max(0, floatval($order["total_amount"]) - $batchDiscount - floatval($order["paid_amount"])),2); ?></td>
      <td>收款状态：<?php echo $paymentText[intval($order["payment_status"])]; ?></td>
    </tr>
    <tr><td colspan="4">备注说明：<?php echo htmlspecialchars($order["remark"]?:""); ?></td></tr>
  </table>
  <table class="print-sign">
    <tr>
      <td>制单人：<?php echo htmlspecialchars($makerName); ?></td>
      <td>销售人员：<?php echo htmlspecialchars($makerName); ?></td>
      <td>客户签字：</td>
    </tr>
  </table>
</div>
<?php endforeach; ?>
</div>
<script>window.addEventListener("load",function(){document.body.focus()});</script>
</body>
</html>
