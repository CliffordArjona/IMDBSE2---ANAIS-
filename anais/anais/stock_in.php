<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
requireRole(['Owner','OIC']);

$pageTitle = 'Stock-In';
$activePage = 'stock_in';
$db = getDB();
$u  = currentUser();
$msg = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id      = (int)$_POST['product_id'];
    $quantity        = (int)$_POST['quantity'];
    $reference_no    = trim($_POST['reference_no'] ?? '');
    $transaction_date = $_POST['transaction_date'];
    $remarks         = trim($_POST['remarks'] ?? '');

    if ($product_id && $quantity > 0 && $transaction_date) {
        $db->beginTransaction();
        try {
            $db->prepare("INSERT INTO stock_transactions (product_id,transaction_type,quantity,reference_no,transaction_date,remarks,recorded_by,created_at) VALUES (?,?,?,?,?,?,?,NOW())")
               ->execute([$product_id,'Stock-In',$quantity,$reference_no,$transaction_date,$remarks,$u['id']]);
            $db->prepare("UPDATE products SET current_stock = current_stock + ?, updated_at=NOW() WHERE product_id=?")
               ->execute([$quantity,$product_id]);
            $db->commit();
            $msg = "Stock-In recorded successfully. ($quantity units added)"; $msgType = 'success';
        } catch (Exception $e) {
            $db->rollBack();
            $msg = 'Error recording transaction.'; $msgType = 'danger';
        }
    } else {
        $msg = 'Please fill in all required fields.'; $msgType = 'danger';
    }
}

// Products for dropdown
$branch_clause = ($u['branch'] !== 'Both') ? "AND branch = ?" : "";
$branch_params = ($u['branch'] !== 'Both') ? [$u['branch']] : [];
$stmt = $db->prepare("SELECT product_id,product_name,branch,current_stock,unit FROM products WHERE status='Active' $branch_clause ORDER BY product_name");
$stmt->execute($branch_params);
$products = $stmt->fetchAll();

// Recent stock-in
$stmt = $db->prepare("SELECT st.*,p.product_name,p.branch,u.full_name as by_name
    FROM stock_transactions st JOIN products p ON st.product_id=p.product_id
    LEFT JOIN users u ON st.recorded_by=u.user_id
    WHERE st.transaction_type='Stock-In' " . ($u['branch']!=='Both'?"AND p.branch=?":"") . "
    ORDER BY st.created_at DESC LIMIT 30");
$stmt->execute($branch_params);
$records = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <div><h1>Stock-In</h1><p>Record incoming product deliveries.</p></div>
</div>
<?php if ($msg): ?>
  <div class="alert alert-<?= $msgType ?>" data-auto-dismiss><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:360px 1fr;gap:20px;align-items:start">

<!-- Form -->
<div class="card">
  <div class="card-header"><span class="card-title">New Stock-In Entry</span></div>
  <div class="card-body">
    <form method="POST">
      <div class="form-group" style="margin-bottom:12px">
        <label>Product *</label>
        <select name="product_id" required>
          <option value="">— Select Product —</option>
          <?php foreach ($products as $p): ?>
            <option value="<?= $p['product_id'] ?>">[<?= $p['branch'] ?>] <?= htmlspecialchars($p['product_name']) ?> (<?= $p['current_stock'] ?> <?= $p['unit'] ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label>Quantity *</label>
        <input type="number" name="quantity" min="1" required placeholder="0">
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label>Date *</label>
        <input type="date" name="transaction_date" value="<?= date('Y-m-d') ?>" required>
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label>Reference / DR No.</label>
        <input type="text" name="reference_no" placeholder="Optional">
      </div>
      <div class="form-group" style="margin-bottom:16px">
        <label>Remarks</label>
        <textarea name="remarks" placeholder="Optional notes…"></textarea>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">Record Stock-In</button>
    </form>
  </div>
</div>

<!-- History -->
<div class="card">
  <div class="card-header">
    <span class="card-title">Stock-In History</span>
    <input type="text" id="tableSearch" placeholder="🔍 Search…" style="width:200px">
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Time</th><th>Product</th><th>Branch</th><th>Qty</th><th>Ref No.</th><th>Remarks</th><th>Recorded By</th></tr></thead>
      <tbody>
      <?php if (empty($records)): ?>
        <tr><td colspan="8" style="text-align:center;color:var(--text-2);padding:28px">No records yet.</td></tr>
      <?php else: foreach ($records as $r): ?>
        <tr>
          <td><?= date('M d, Y', strtotime($r['transaction_date'])) ?></td>
          <td><?= !empty($r['created_at']) ? date('h:i A', strtotime($r['created_at'])) : '—' ?></td>
          <td><?= htmlspecialchars($r['product_name']) ?></td>
          <td><span class="badge badge-info"><?= $r['branch'] ?></span></td>
          <td style="font-weight:700;color:var(--success)">+<?= $r['quantity'] ?></td>
          <td><?= htmlspecialchars($r['reference_no'] ?: '—') ?></td>
          <td><?= htmlspecialchars($r['remarks'] ?: '—') ?></td>
          <td><?= htmlspecialchars($r['by_name'] ?? '—') ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
