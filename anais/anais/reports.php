<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
requireRole(['Owner','OIC']);

$pageTitle = 'ANAIS System Auto Generated Report';
$activePage = 'reports';
$db = getDB();
$u  = currentUser();

// Filters
$report_type  = $_GET['type']       ?? 'inventory';
$filterBranch = $_GET['branch']     ?? ($u['branch'] !== 'Both' ? $u['branch'] : '');
$date_from    = $_GET['date_from']  ?? date('Y-m-01');
$date_to      = $_GET['date_to']    ?? date('Y-m-d');

$branch_clause  = $filterBranch ? "AND p.branch = :branch" : "";
$branch_clause2 = $filterBranch ? "AND branch = :branch" : "";
$binds = $filterBranch ? ['branch' => $filterBranch] : [];

// ── Report Queries ─────────────────────────────────────────
$data = [];
$columns = [];

if ($report_type === 'inventory') {
    $columns = ['Product Name','Category','Brand','Branch','Unit','Current Stock','Reorder Level','Unit Price','Stock Value','Status'];
    $stmt = $db->prepare("SELECT product_name,category,brand,branch,unit,current_stock,reorder_level,unit_price,(current_stock*unit_price) as stock_value,status FROM products WHERE status='Active' $branch_clause2 ORDER BY product_name");
    $stmt->execute($binds);
    $data = $stmt->fetchAll();

} elseif ($report_type === 'stock_in') {
    $columns = ['Date','Product','Branch','Category','Qty In','Reference No.','Recorded By'];
    $stmt = $db->prepare("SELECT st.transaction_date,p.product_name,p.branch,p.category,st.quantity,st.reference_no,u.full_name
        FROM stock_transactions st JOIN products p ON st.product_id=p.product_id LEFT JOIN users u ON st.recorded_by=u.user_id
        WHERE st.transaction_type='Stock-In' AND st.transaction_date BETWEEN :df AND :dt $branch_clause
        ORDER BY st.transaction_date DESC");
    $stmt->execute(array_merge($binds,['df'=>$date_from,'dt'=>$date_to]));
    $data = $stmt->fetchAll();

} elseif ($report_type === 'stock_out' || $report_type === 'sales_history') {
    $columns = ['Transaction No.','Date','Product','Branch','Category','Qty Out','Reference No.','ID No.','Discount','Total Amount','Recorded By'];
    $stmt = $db->prepare("SELECT CONCAT('#', LPAD(st.transaction_id,6,'0')) AS tx_no, st.transaction_date,p.product_name,p.branch,p.category,st.quantity,st.reference_no,COALESCE(st.customer_id_no,'') AS customer_id_no,st.discount_amount,st.total_amount,u.full_name
        FROM stock_transactions st JOIN products p ON st.product_id=p.product_id LEFT JOIN users u ON st.recorded_by=u.user_id
        WHERE st.transaction_type='Stock-Out' AND st.transaction_date BETWEEN :df AND :dt $branch_clause
        ORDER BY st.transaction_date DESC, st.transaction_id DESC");
    $stmt->execute(array_merge($binds,['df'=>$date_from,'dt'=>$date_to]));
    $data = $stmt->fetchAll();

} elseif ($report_type === 'low_stock') {
    $columns = ['Product','Branch','Category','Unit','Current Stock','Reorder Level','Deficit'];
    $stmt = $db->prepare("SELECT product_name,branch,category,unit,current_stock,reorder_level,(reorder_level-current_stock) as deficit FROM products WHERE status='Active' AND current_stock <= reorder_level AND reorder_level > 0 $branch_clause2 ORDER BY (reorder_level-current_stock) DESC");
    $stmt->execute($binds);
    $data = $stmt->fetchAll();

} elseif ($report_type === 'purchase_orders') {
    $columns = ['PO Number','Supplier','Branch','Order Date','Expected Delivery','Status','Created By'];
    $b2 = $filterBranch ? "AND po.branch=:branch" : "";
    $stmt = $db->prepare("SELECT po.po_number,s.supplier_name,po.branch,po.order_date,po.expected_delivery_date,po.po_status,u.full_name
        FROM purchase_orders po JOIN suppliers s ON po.supplier_id=s.supplier_id LEFT JOIN users u ON po.created_by=u.user_id
        WHERE po.order_date BETWEEN :df AND :dt $b2 ORDER BY po.order_date DESC");
    $stmt->execute(array_merge($binds,['df'=>$date_from,'dt'=>$date_to]));
    $data = $stmt->fetchAll();
}

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <div><h1>Reports</h1><p>Generate branch-wise and consolidated inventory reports.</p></div>
  <button class="btn btn-outline" onclick="window.print()">🖨 Print</button>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:16px">
  <div class="card-body" style="padding:14px 20px">
    <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
      <div class="form-group" style="margin:0">
        <label>Report Type</label>
        <select name="type" onchange="this.form.submit()">
          <option value="inventory"       <?= $report_type==='inventory'?'selected':'' ?>>Inventory Status</option>
          <option value="stock_in"        <?= $report_type==='stock_in'?'selected':'' ?>>Stock-In Transactions</option>
          <option value="stock_out"       <?= $report_type==='stock_out'?'selected':'' ?>>Stock-Out Transactions</option>
          <option value="sales_history"   <?= $report_type==='sales_history'?'selected':'' ?>>Sales History</option>
          <option value="low_stock"       <?= $report_type==='low_stock'?'selected':'' ?>>Low Stock Alert</option>
          <option value="purchase_orders" <?= $report_type==='purchase_orders'?'selected':'' ?>>Purchase Orders</option>
        </select>
      </div>
      <?php if ($u['branch'] === 'Both'): ?>
      <div class="form-group" style="margin:0">
        <label>Branch</label>
        <select name="branch">
          <option value="">All Branches</option>
          <option value="Autobox"    <?= $filterBranch==='Autobox'?'selected':'' ?>>Autobox</option>
          <option value="Autophoria" <?= $filterBranch==='Autophoria'?'selected':'' ?>>Autophoria</option>
        </select>
      </div>
      <?php endif; ?>
      <?php if (!in_array($report_type,['inventory','low_stock'])): ?>
      <div class="form-group" style="margin:0">
        <label>Date From</label>
        <input type="date" name="date_from" value="<?= $date_from ?>">
      </div>
      <div class="form-group" style="margin:0">
        <label>Date To</label>
        <input type="date" name="date_to" value="<?= $date_to ?>">
      </div>
      <?php endif; ?>
      <button type="submit" class="btn btn-primary">Generate</button>
    </form>
  </div>
</div>

<!-- Report Table -->
<?php
$reportTitles = [
    'inventory'       => 'Inventory Status Report',
    'stock_in'        => "Stock-In Report ($date_from to $date_to)",
    'stock_out'       => "Stock-Out Report ($date_from to $date_to)",
    'sales_history'   => "Sales History ($date_from to $date_to)",
    'low_stock'       => 'Low Stock Alert Report',
    'purchase_orders' => "Purchase Orders Report ($date_from to $date_to)",
];
?>
<div class="card" id="printArea">
  <div class="card-header">
    <div>
      <span class="card-title"><?= $reportTitles[$report_type] ?></span>
      <?php if ($filterBranch): ?><span class="badge badge-info" style="margin-left:8px"><?= $filterBranch ?></span><?php endif; ?>
      <div style="font-size:12px;color:var(--text-2);margin-top:2px">Generated: <?= date('F j, Y g:i A') ?> &nbsp;|&nbsp; <?= count($data) ?> record(s)</div>
    </div>
    <input type="text" id="tableSearch" placeholder="🔍 Search…" style="width:200px">
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <?php foreach ($columns as $col): ?><th><?= htmlspecialchars($col) ?></th><?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php if (empty($data)): ?>
        <tr><td colspan="<?= count($columns) ?>" style="text-align:center;padding:32px;color:var(--text-2)">No data found for selected filters.</td></tr>
      <?php else: foreach ($data as $row):
          $vals = array_values($row); ?>
        <tr>
          <?php foreach ($vals as $ci => $val):
              $col = $columns[$ci] ?? '';
              $formatted = $val;
              if (str_contains($col,'Price') || str_contains($col,'Value') || str_contains($col,'Amount') || str_contains($col,'Discount')) $formatted = '₱'.number_format((float)$val,2);
              elseif (str_contains($col,'Date'))  $formatted = $val ? date('M d, Y', strtotime($val)) : '—';
              elseif ($val === null || $val === '') $formatted = '—';
          ?>
          <td><?= htmlspecialchars((string)$formatted) ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; endif; ?>

      <!-- Summary row for stock value -->
      <?php if ($report_type === 'inventory' && !empty($data)):
          $totalVal = array_sum(array_column($data,'stock_value')); ?>
        <tr style="background:#f0fdf4;font-weight:700">
          <td colspan="8" style="text-align:right">Total Inventory Value:</td>
          <td>₱<?= number_format($totalVal,2) ?></td>
          <td></td>
        </tr>
      <?php elseif (in_array($report_type,['stock_in','stock_out','sales_history']) && !empty($data)):
          $totalQty = array_sum(array_column($data,'quantity')); ?>
        <tr style="background:#f9fafb;font-weight:700">
          <td colspan="4" style="text-align:right">Total Quantity:</td>
          <td><?= $totalQty ?></td>
          <td colspan="2"></td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<style>
@media print {
  .sidebar, .filter-bar, .page-header .btn, #tableSearch { display: none !important; }
  .main-content { margin-left: 0 !important; }
  .card { box-shadow: none; border: 1px solid #ddd; }
}
</style>


<div class="print-report-footer">
  ANAIS System Auto Generated Report
</div>

<style>
@media print {
  title {
    display: none;
  }

  .print-report-footer {
    display: block !important;
    position: fixed;
    bottom: 8mm;
    left: 0;
    right: 0;
    text-align: center;
    font-size: 11px;
    color: #6b7280;
  }

  .btn,
  .sidebar,
  .sidebar-footer,
  .filter-bar,
  form,
  button {
    display: none !important;
  }

  .main-content {
    margin-left: 0 !important;
  }

  .page-inner {
    padding: 0 !important;
  }

  .card {
    box-shadow: none !important;
    border: none !important;
  }

  .page-header::after {
    content: "ANAIS System Auto Generated Report";
    display: block;
    margin-top: 6px;
    font-size: 12px;
    color: #6b7280;
  }
}

@media screen {
  .print-report-footer {
    display: none;
  }
}
</style>

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

<?php include __DIR__ . '/includes/footer.php'; ?>
