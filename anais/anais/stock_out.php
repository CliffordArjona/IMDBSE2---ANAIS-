<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
requireRole(['Owner','OIC']);

$pageTitle = 'Stock-Out';
$activePage = 'stock_out';
$db = getDB();
$u  = currentUser();
$msg = ''; $msgType = '';

function tableColumnExists(PDO $db, string $table, string $column): bool {
    try {
        $stmt = $db->prepare("SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function ensureStockOutDiscountColumns(PDO $db): bool {
    $columns = [
        'discount_type'   => "ALTER TABLE stock_transactions ADD COLUMN discount_type ENUM('amount','percent') NOT NULL DEFAULT 'amount' AFTER transaction_date",
        'discount_value'  => "ALTER TABLE stock_transactions ADD COLUMN discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_type",
        'discount_amount' => "ALTER TABLE stock_transactions ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_value",
        'total_amount'    => "ALTER TABLE stock_transactions ADD COLUMN total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_amount",
    ];

    foreach ($columns as $column => $sql) {
        if (!tableColumnExists($db, 'stock_transactions', $column)) {
            try {
                $db->exec($sql);
            } catch (Throwable $e) {
                // Ignore duplicate-column race, but fail if the column still does not exist after.
            }
        }
    }

    return tableColumnExists($db, 'stock_transactions', 'discount_type')
        && tableColumnExists($db, 'stock_transactions', 'discount_value')
        && tableColumnExists($db, 'stock_transactions', 'discount_amount')
        && tableColumnExists($db, 'stock_transactions', 'total_amount');
}

$hasDiscountCols = ensureStockOutDiscountColumns($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id        = (int)($_POST['product_id'] ?? 0);
    $quantity          = (int)($_POST['quantity'] ?? 0);
    $reference_no      = trim($_POST['reference_no'] ?? '');
    $transaction_date  = $_POST['transaction_date'] ?? '';
    $discount_type     = $_POST['discount_type'] ?? 'amount';
    $discount_value    = max(0, (float)($_POST['discount_value'] ?? 0));
    $remarks           = trim($_POST['remarks'] ?? '');

    if (!in_array($discount_type, ['amount','percent'], true)) {
        $discount_type = 'amount';
    }

    if ($product_id && $quantity > 0 && $transaction_date) {
        $stmt = $db->prepare("SELECT current_stock, product_name, unit_price FROM products WHERE product_id=?");
        $stmt->execute([$product_id]);
        $prod = $stmt->fetch();

        if (!$prod) {
            $msg = 'Product not found.'; $msgType = 'danger';
        } elseif ((int)$prod['current_stock'] < $quantity) {
            $msg = "Insufficient stock. Available: {$prod['current_stock']}"; $msgType = 'danger';
        } else {
            $unit_price = (float)($prod['unit_price'] ?? 0);
            $gross_total = $unit_price * $quantity;

            if ($discount_type === 'percent' && $discount_value > 100) {
                $msg = 'Percent discount cannot be greater than 100%.'; $msgType = 'danger';
            } else {
                $discount_amount = ($discount_type === 'percent')
                    ? ($gross_total * ($discount_value / 100))
                    : $discount_value;

                if ($discount_amount > $gross_total) {
                    $msg = 'Discount cannot be greater than the gross total.'; $msgType = 'danger';
                } else {
                    $total_amount = max(0, $gross_total - $discount_amount);

                    $db->beginTransaction();
                    try {
                        if ($hasDiscountCols) {
                            $db->prepare("INSERT INTO stock_transactions
                                (product_id, transaction_type, quantity, reference_no, transaction_date, discount_type, discount_value, discount_amount, total_amount, remarks, recorded_by, created_at)
                                VALUES (?, 'Stock-Out', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                               ->execute([$product_id, $quantity, $reference_no, $transaction_date, $discount_type, $discount_value, $discount_amount, $total_amount, $remarks, $u['id']]);
                        } else {
                            $fallbackRemarks = trim($remarks . " | Gross: ₱" . number_format($gross_total, 2) . " | Discount: " . ($discount_type === 'percent' ? $discount_value . '%' : '₱' . number_format($discount_value, 2)) . " | Discount Amount: ₱" . number_format($discount_amount, 2) . " | Total: ₱" . number_format($total_amount, 2));
                            $db->prepare("INSERT INTO stock_transactions
                                (product_id, transaction_type, quantity, reference_no, transaction_date, remarks, recorded_by, created_at)
                                VALUES (?, 'Stock-Out', ?, ?, ?, ?, ?, NOW())")
                               ->execute([$product_id, $quantity, $reference_no, $transaction_date, $fallbackRemarks, $u['id']]);
                        }

                        $db->prepare("UPDATE products SET current_stock = current_stock - ?, updated_at=NOW() WHERE product_id=?")
                           ->execute([$quantity, $product_id]);

                        $db->commit();
                        $msg = "Stock-Out recorded. Total: ₱" . number_format($total_amount, 2);
                        $msgType = 'success';
                    } catch (Exception $e) {
                        $db->rollBack();
                        $msg = 'Error recording transaction: ' . $e->getMessage(); $msgType = 'danger';
                    }
                }
            }
        }
    } else {
        $msg = 'Please fill in all required fields.'; $msgType = 'danger';
    }
}

$branch_clause = ($u['branch'] !== 'Both') ? "AND branch = ?" : "";
$branch_params = ($u['branch'] !== 'Both') ? [$u['branch']] : [];

$stmt = $db->prepare("SELECT product_id,product_name,branch,current_stock,unit,unit_price FROM products WHERE status='Active' $branch_clause ORDER BY product_name");
$stmt->execute($branch_params);
$products = $stmt->fetchAll();

$stmt = $db->prepare("SELECT st.*, p.product_name, p.branch, p.unit_price, u.full_name as by_name
    FROM stock_transactions st
    JOIN products p ON st.product_id=p.product_id
    LEFT JOIN users u ON st.recorded_by=u.user_id
    WHERE st.transaction_type='Stock-Out' " . ($u['branch']!=='Both' ? "AND p.branch=?" : "") . "
    ORDER BY st.created_at DESC LIMIT 30");
$stmt->execute($branch_params);
$records = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <div><h1>Stock-Out</h1><p>Record product sales and removals.</p></div>
</div>

<?php if ($msg): ?>
  <div class="alert alert-<?= $msgType ?>" data-auto-dismiss><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<?php if (!$hasDiscountCols): ?>
  <div class="alert alert-warning" data-auto-dismiss>
    Discount/Total database columns are still not detected in the active database. Check that config/db.php points to the database where you imported the SQL.
  </div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:390px 1fr;gap:20px;align-items:start">
<div class="card">
  <div class="card-header"><span class="card-title">New Stock-Out Entry</span></div>
  <div class="card-body">
    <form method="POST" id="stockOutForm">
      <div class="form-group" style="margin-bottom:12px">
        <label>Product *</label>
        <select name="product_id" id="productSelect" required>
          <option value="">— Select Product —</option>
          <?php foreach ($products as $p): ?>
            <option
              value="<?= (int)$p['product_id'] ?>"
              data-price="<?= htmlspecialchars((string)$p['unit_price']) ?>"
              data-stock="<?= (int)$p['current_stock'] ?>"
            >
              [<?= htmlspecialchars($p['branch']) ?>] <?= htmlspecialchars($p['product_name']) ?>
              — Stock: <?= (int)$p['current_stock'] ?> <?= htmlspecialchars($p['unit']) ?>
              — ₱<?= number_format((float)$p['unit_price'], 2) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group" style="margin-bottom:12px">
        <label>Quantity *</label>
        <input type="number" name="quantity" id="quantityInput" min="1" required>
      </div>

      <div class="form-group" style="margin-bottom:12px">
        <label>Unit Price</label>
        <input type="text" id="unitPriceDisplay" value="₱0.00" readonly>
      </div>

      <div class="form-group" style="margin-bottom:12px">
        <label>Gross Total</label>
        <input type="text" id="grossTotalDisplay" value="₱0.00" readonly>
      </div>

      <div class="form-grid" style="gap:10px;margin-bottom:12px">
        <div class="form-group">
          <label>Discount Type</label>
          <select name="discount_type" id="discountType">
            <option value="amount">Amount ₱</option>
            <option value="percent">Percent %</option>
          </select>
        </div>
        <div class="form-group">
          <label>Discount Value</label>
          <input type="number" name="discount_value" id="discountValue" min="0" step="0.01" value="0">
        </div>
      </div>

      <div class="form-group" style="margin-bottom:12px">
        <label>Computed Discount</label>
        <input type="text" id="discountAmountDisplay" value="₱0.00" readonly>
      </div>

      <div class="form-group" style="margin-bottom:12px">
        <label>Total Amount</label>
        <input type="text" id="totalAmountDisplay" value="₱0.00" readonly style="font-weight:700;color:var(--success)">
      </div>

      <div class="form-group" style="margin-bottom:12px">
        <label>Ref. No.</label>
        <input type="text" name="reference_no" placeholder="OR / Invoice / Customer Ref">
      </div>

      <div class="form-group" style="margin-bottom:12px">
        <label>Date *</label>
        <input type="date" name="transaction_date" value="<?= date('Y-m-d') ?>" required>
      </div>

      <div class="form-group" style="margin-bottom:16px">
        <label>Remarks</label>
        <textarea name="remarks" placeholder="Customer name, reason, notes, etc."></textarea>
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">Save Stock-Out</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <span class="card-title">Recent Stock-Out Records</span>
    <input type="text" id="tableSearch" placeholder="🔍 Search…" style="width:220px">
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Date</th><th>Time</th><th>Product</th><th>Branch</th><th>Qty</th>
          <th>Unit Price</th><th>Discount</th><th>Total</th><th>Ref No.</th><th>Recorded By</th><th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$records): ?>
        <tr><td colspan="11" style="text-align:center;padding:32px;color:var(--text-2)">No stock-out records yet.</td></tr>
      <?php else: foreach ($records as $r):
        $unitPrice = (float)($r['unit_price'] ?? 0);
        $qty = (int)$r['quantity'];
        $gross = $unitPrice * $qty;

        $discountType = $r['discount_type'] ?? 'amount';
        $discountValue = isset($r['discount_value']) ? (float)$r['discount_value'] : 0;
        $discountAmount = isset($r['discount_amount']) ? (float)$r['discount_amount'] : 0;
        $total = isset($r['total_amount']) ? (float)$r['total_amount'] : max(0, $gross - $discountAmount);

        $discountLabel = $discountAmount > 0
            ? (($discountType === 'percent' ? rtrim(rtrim(number_format($discountValue, 2), '0'), '.') . '% = ' : '') . '₱' . number_format($discountAmount, 2))
            : '—';
      ?>
        <tr>
          <td><?= date('M d, Y', strtotime($r['transaction_date'])) ?></td>
          <td><?= date('h:i A', strtotime($r['created_at'])) ?></td>
          <td><?= htmlspecialchars($r['product_name']) ?></td>
          <td><span class="badge badge-info"><?= htmlspecialchars($r['branch']) ?></span></td>
          <td><?= $qty ?></td>
          <td>₱<?= number_format($unitPrice, 2) ?></td>
          <td><?= htmlspecialchars($discountLabel) ?></td>
          <td><strong>₱<?= number_format($total, 2) ?></strong></td>
          <td><?= htmlspecialchars($r['reference_no'] ?: '—') ?></td>
          <td><?= htmlspecialchars($r['by_name'] ?: '—') ?></td>
          <td><a href="/anais/stock_out_or.php?id=<?= (int)$r['transaction_id'] ?>" target="_blank" class="btn btn-outline btn-sm">Print OR</a></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
</div>

<script>
(function () {
  const productSelect = document.getElementById('productSelect');
  const qtyInput = document.getElementById('quantityInput');
  const discountType = document.getElementById('discountType');
  const discountValue = document.getElementById('discountValue');
  const unitPriceDisplay = document.getElementById('unitPriceDisplay');
  const grossTotalDisplay = document.getElementById('grossTotalDisplay');
  const discountAmountDisplay = document.getElementById('discountAmountDisplay');
  const totalAmountDisplay = document.getElementById('totalAmountDisplay');

  function money(num) {
    return '₱' + Number(num || 0).toLocaleString(undefined, {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  function computeStockOutTotal() {
    const selected = productSelect.options[productSelect.selectedIndex];
    const price = selected ? parseFloat(selected.dataset.price || '0') : 0;
    const qty = parseInt(qtyInput.value || '0', 10);
    let dValue = parseFloat(discountValue.value || '0');
    const dType = discountType.value;

    if (isNaN(dValue) || dValue < 0) dValue = 0;

    const gross = price * qty;

    if (dType === 'percent' && dValue > 100) {
      dValue = 100;
      discountValue.value = '100';
    }

    let discountAmount = dType === 'percent' ? gross * (dValue / 100) : dValue;

    if (discountAmount > gross && gross > 0) {
      discountAmount = gross;
      if (dType === 'amount') discountValue.value = gross.toFixed(2);
    }

    const total = Math.max(0, gross - discountAmount);

    unitPriceDisplay.value = money(price);
    grossTotalDisplay.value = money(gross);
    discountAmountDisplay.value = money(discountAmount);
    totalAmountDisplay.value = money(total);
  }

  productSelect.addEventListener('change', computeStockOutTotal);
  qtyInput.addEventListener('input', computeStockOutTotal);
  discountType.addEventListener('change', computeStockOutTotal);
  discountValue.addEventListener('input', computeStockOutTotal);
  computeStockOutTotal();
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
