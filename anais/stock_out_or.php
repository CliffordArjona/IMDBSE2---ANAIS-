<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
requireRole(['Owner','OIC']);

$db = getDB();
$u = currentUser();

$id = (int)($_GET['id'] ?? 0);
if (!$id) die('Invalid OR.');

$stmt = $db->prepare("SELECT st.*, p.product_name, p.sku, p.unit, p.unit_price, p.branch, u.full_name as by_name
    FROM stock_transactions st
    JOIN products p ON st.product_id=p.product_id
    LEFT JOIN users u ON st.recorded_by=u.user_id
    WHERE st.transaction_id=? AND st.transaction_type='Stock-Out'
    LIMIT 1");
$stmt->execute([$id]);
$r = $stmt->fetch();

if (!$r) die('Stock-Out record not found.');
if (($u['branch'] ?? '') !== 'Both' && ($r['branch'] ?? '') !== $u['branch']) die('Unauthorized.');

$unitPrice = (float)($r['unit_price'] ?? 0);
$qty = (int)$r['quantity'];
$gross = $unitPrice * $qty;
$discountType = $r['discount_type'] ?? 'amount';
$discountValue = isset($r['discount_value']) ? (float)$r['discount_value'] : 0;
$discountAmount = isset($r['discount_amount']) ? (float)$r['discount_amount'] : 0;
$total = isset($r['total_amount']) ? (float)$r['total_amount'] : max(0, $gross - $discountAmount);
$ref = $r['reference_no'] ?: ('OR-' . str_pad((string)$r['transaction_id'], 6, '0', STR_PAD_LEFT));
$discountLabel = $discountType === 'percent'
    ? rtrim(rtrim(number_format($discountValue, 2), '0'), '.') . '%'
    : 'Amount';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>ANAIS System Auto Generated Report</title>
<style>
  @page { margin: 12mm; }
  body { font-family: Arial, sans-serif; background:#f3f4f6; margin:0; padding:24px; color:#111827; }
  .receipt { max-width:460px; margin:0 auto; background:#fff; padding:24px; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,.12); }
  .center { text-align:center; }
  h1 { font-size:20px; margin:0; letter-spacing:.5px; }
  h2 { font-size:14px; margin:4px 0 16px; font-weight:normal; color:#6b7280; }
  .line { border-top:1px dashed #9ca3af; margin:16px 0; }
  .row { display:flex; justify-content:space-between; gap:12px; margin:8px 0; font-size:14px; }
  .label { color:#6b7280; }
  .total { font-size:18px; font-weight:bold; }
  .items { width:100%; border-collapse:collapse; margin-top:10px; }
  .items th, .items td { text-align:left; padding:8px 0; border-bottom:1px solid #e5e7eb; font-size:13px; vertical-align:top; }
  .items th:last-child, .items td:last-child { text-align:right; }
  .signature-wrap { display:grid; grid-template-columns:1fr 1fr; gap:22px; margin-top:34px; }
  .signature-box { text-align:center; font-size:12px; color:#374151; }
  .signature-line { border-top:1px solid #111827; height:1px; margin-bottom:6px; }
  .signature-name { min-height:15px; font-weight:bold; color:#111827; margin-bottom:2px; }
  .print { margin:20px auto; display:block; padding:10px 16px; border:0; background:#2563eb; color:#fff; border-radius:8px; cursor:pointer; }
  @media print {
    body { background:#fff; padding:0; }
    .receipt { box-shadow:none; max-width:none; border-radius:0; }
    .print { display:none; }
  }
</style>
</head>
<body>
<button class="print" onclick="window.print()">Print OR</button>

<div class="receipt">
  <div class="center">
    <h1>ANAIS</h1>
    <h2>Autobox &amp; Autophoria Inventory System</h2>
    <strong>OFFICIAL RECEIPT</strong>
  </div>

  <div class="line"></div>
  <div class="row"><span class="label">OR / Ref No.</span><strong><?= htmlspecialchars($ref) ?></strong></div>
  <div class="row"><span class="label">Date</span><span><?= date('M d, Y', strtotime($r['transaction_date'])) ?></span></div>
  <div class="row"><span class="label">Time</span><span><?= date('h:i A', strtotime($r['created_at'])) ?></span></div>
  <div class="row"><span class="label">Branch</span><span><?= htmlspecialchars($r['branch']) ?></span></div>
  <div class="row"><span class="label">Recorded By</span><span><?= htmlspecialchars($r['by_name'] ?: '—') ?></span></div>

  <div class="line"></div>
  <table class="items">
    <thead>
      <tr>
        <th>Item</th>
        <th>Qty</th>
        <th>Amount</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>
          <strong><?= htmlspecialchars($r['product_name']) ?></strong><br>
          <span class="label"><?= htmlspecialchars($r['sku'] ?? '') ?></span>
        </td>
        <td><?= $qty ?></td>
        <td>₱<?= number_format($gross, 2) ?></td>
      </tr>
    </tbody>
  </table>

  <div class="line"></div>
  <div class="row"><span class="label">Unit Price</span><span>₱<?= number_format($unitPrice, 2) ?></span></div>
  <div class="row"><span class="label">Gross Total</span><span>₱<?= number_format($gross, 2) ?></span></div>
  <div class="row"><span class="label">Discount <?= htmlspecialchars($discountLabel) ?></span><span>- ₱<?= number_format($discountAmount, 2) ?></span></div>
  <div class="row total"><span>Total</span><span>₱<?= number_format($total, 2) ?></span></div>

  <?php if (!empty($r['remarks'])): ?>
  <div class="line"></div>
  <div style="font-size:13px">
    <span class="label">Remarks:</span><br>
    <?= nl2br(htmlspecialchars($r['remarks'])) ?>
  </div>
  <?php endif; ?>

  <div class="line"></div>

  <div class="signature-wrap">
    <div class="signature-box">
      <div class="signature-line"></div>
      <div class="signature-name"><?= htmlspecialchars($r['by_name'] ?: '') ?></div>
      <div>Owner / Authorized Signature</div>
    </div>

    <div class="signature-box">
      <div class="signature-line"></div>
      <div class="signature-name">&nbsp;</div>
      <div>Customer Signature</div>
    </div>
  </div>

  <div class="line"></div>
  <div class="center" style="font-size:12px;color:#6b7280">ANAIS System Auto Generated Report</div>
</div>
<script>
(function () {
  var oldTitle = document.title;
  window.addEventListener('beforeprint', function () {
    document.title = 'ANAIS System Auto Generated Report';
  });
  window.addEventListener('afterprint', function () {
    document.title = oldTitle;
  });
})();
</script>
</body>
</html>
