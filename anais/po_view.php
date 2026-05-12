<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pageTitle = 'View Purchase Order';
$activePage = 'po';
$db = getDB();
$u  = currentUser();

$po_id = (int)($_GET['id'] ?? 0);
if (!$po_id) { header('Location: /anais/purchase_orders.php'); exit; }


function poColumnExists(PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $stmt->execute([$table,$column]);
    return (int)$stmt->fetchColumn() > 0;
}
function poEnsureSRSColumns(PDO $db): void {
    $cols = [
        ['purchase_orders','payment_status', "ALTER TABLE purchase_orders ADD COLUMN payment_status ENUM('Unpaid','Partial','Paid') NOT NULL DEFAULT 'Unpaid' AFTER supplier_status"],
        ['purchase_orders','payment_method', "ALTER TABLE purchase_orders ADD COLUMN payment_method VARCHAR(50) NULL AFTER payment_status"],
        ['purchase_orders','amount_paid', "ALTER TABLE purchase_orders ADD COLUMN amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER payment_method"],
        ['purchase_orders','payment_remarks', "ALTER TABLE purchase_orders ADD COLUMN payment_remarks TEXT NULL AFTER amount_paid"],
        ['purchase_orders','price_response_note', "ALTER TABLE purchase_orders ADD COLUMN price_response_note TEXT NULL AFTER payment_remarks"],
    ];
    foreach($cols as [$t,$c,$sql]){ if(!poColumnExists($db,$t,$c)){ try{$db->exec($sql);}catch(Throwable $e){} } }
    try { $db->exec("ALTER TABLE purchase_orders MODIFY supplier_status ENUM('Pending Confirmation','Waiting for Supplier Price','Supplier Prices Submitted','Owner Accepted','Owner Rejected','Confirmed','Processing','Shipped','In Transit','Delivered','Incomplete Delivery') NOT NULL DEFAULT 'Pending Confirmation'"); } catch(Throwable $e) {}
    $db->exec("CREATE TABLE IF NOT EXISTS po_returns (
        return_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        po_id INT UNSIGNED NOT NULL,
        product_id MEDIUMINT UNSIGNED NOT NULL,
        quantity_returned MEDIUMINT UNSIGNED NOT NULL,
        return_type ENUM('Damaged','Wrong Item','Other') NOT NULL DEFAULT 'Damaged',
        return_date DATE NOT NULL,
        remarks TEXT NULL,
        recorded_by MEDIUMINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY po_id (po_id), KEY product_id (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
poEnsureSRSColumns($db);



function getPODeliveryTotals(PDO $db, int $po_id): array {
    $stmt = $db->prepare("SELECT
            COALESCE(SUM(x.quantity_ordered),0) AS total_ordered,
            COALESCE(SUM(LEAST(x.quantity_received_total, x.quantity_ordered)),0) AS total_received,
            COALESCE(SUM(GREATEST(x.quantity_ordered - x.quantity_received_total, 0)),0) AS total_remaining
        FROM (
            SELECT pi.product_id, SUM(pi.quantity_ordered) AS quantity_ordered, COALESCE(sd.quantity_received_total,0) AS quantity_received_total
            FROM po_items pi
            LEFT JOIN (
                SELECT po_id, product_id, SUM(quantity_received) AS quantity_received_total
                FROM supplier_deliveries
                WHERE po_id=?
                GROUP BY po_id, product_id
            ) sd ON sd.po_id=pi.po_id AND sd.product_id=pi.product_id
            WHERE pi.po_id=?
            GROUP BY pi.product_id, sd.quantity_received_total
        ) x");
    $stmt->execute([$po_id, $po_id]);
    $row = $stmt->fetch() ?: [];
    $ordered = (int)($row['total_ordered'] ?? 0);
    $received = (int)($row['total_received'] ?? 0);
    $remaining = (int)($row['total_remaining'] ?? 0);
    return ['ordered'=>$ordered, 'received'=>$received, 'remaining'=>$remaining, 'complete'=>$ordered > 0 && $remaining <= 0];
}

function recomputePOStatus(PDO $db, int $po_id): string {
    $totals = getPODeliveryTotals($db, $po_id);
    if ($totals['complete']) return 'Fully Delivered';
    if ($totals['received'] > 0) return 'Partially Delivered';
    return 'Pending';
}



if ($_SERVER['REQUEST_METHOD'] === 'POST' && canEdit()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_payment') {
        $payment_status = $_POST['payment_status'] ?? 'Unpaid';
        if (!in_array($payment_status, ['Unpaid','Partial','Paid'], true)) $payment_status = 'Unpaid';
        $payment_method = trim($_POST['payment_method'] ?? '');
        $amount_paid = max(0, (float)($_POST['amount_paid'] ?? 0));
        $payment_remarks = trim($_POST['payment_remarks'] ?? '');
        $db->prepare("UPDATE purchase_orders SET payment_status=?, payment_method=?, amount_paid=?, payment_remarks=?, updated_at=NOW() WHERE po_id=?")
           ->execute([$payment_status,$payment_method,$amount_paid,$payment_remarks,$po_id]);
        $_SESSION['anais_flash_alert'] = ['type'=>'success','message'=>'Payment details updated.'];
        header('Location: /anais/po_view.php?id='.$po_id); exit;
    }
    if ($action === 'add_return') {
        $product_id = (int)($_POST['product_id'] ?? 0);
        $qty = max(0, (int)($_POST['quantity_returned'] ?? 0));
        $type = $_POST['return_type'] ?? 'Damaged';
        if (!in_array($type, ['Damaged','Wrong Item','Other'], true)) $type='Other';
        $date = $_POST['return_date'] ?? date('Y-m-d');
        $remarks = trim($_POST['remarks'] ?? '');
        if ($product_id > 0 && $qty > 0) {
            $db->beginTransaction();
            $db->prepare("INSERT INTO po_returns (po_id,product_id,quantity_returned,return_type,return_date,remarks,recorded_by,created_at) VALUES (?,?,?,?,?,?,?,NOW())")
               ->execute([$po_id,$product_id,$qty,$type,$date,$remarks,(int)$u['id']]);
            $db->prepare("UPDATE products SET current_stock = GREATEST(current_stock - ?, 0), updated_at=NOW() WHERE product_id=?")
               ->execute([$qty,$product_id]);
            $db->commit();
            $_SESSION['anais_flash_alert'] = ['type'=>'warning','message'=>'Returned item recorded, stock adjusted, and supplier will see the return notification with reason.'];
        }
        header('Location: /anais/po_view.php?id='.$po_id); exit;
    }
}

$stmt = $db->prepare("SELECT po.*, s.supplier_name, s.contact_person, s.contact_number, s.email,
    u.full_name as created_by_name FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.supplier_id
    LEFT JOIN users u ON po.created_by = u.user_id
    WHERE po.po_id = ?");
$stmt->execute([$po_id]);
$po = $stmt->fetch();
if (!$po) { header('Location: /anais/purchase_orders.php'); exit; }
if (isSupplier() && ((int)$po['supplier_id'] !== (int)$u['supplier_id'] || !empty($po['is_draft']))) {
    header('Location: /anais/supplier_portal.php?error=unauthorized');
    exit;
}

// Keep the PO status accurate even for older incomplete deliveries.
$calculatedStatus = recomputePOStatus($db, $po_id);
$calculatedSupplierStatus = ($calculatedStatus === 'Fully Delivered') ? 'Delivered' : (($calculatedStatus === 'Partially Delivered') ? 'Incomplete Delivery' : ($po['supplier_status'] ?? 'Pending Confirmation'));
if (($po['po_status'] ?? '') !== $calculatedStatus || ($calculatedStatus === 'Fully Delivered' && ($po['supplier_status'] ?? '') !== 'Delivered')) {
    $db->prepare("UPDATE purchase_orders SET po_status=?, supplier_status=?, updated_at=NOW() WHERE po_id=?")
       ->execute([$calculatedStatus, $calculatedSupplierStatus, $po_id]);
    
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canEdit()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_payment') {
        $payment_status = $_POST['payment_status'] ?? 'Unpaid';
        if (!in_array($payment_status, ['Unpaid','Partial','Paid'], true)) $payment_status = 'Unpaid';
        $payment_method = trim($_POST['payment_method'] ?? '');
        $amount_paid = max(0, (float)($_POST['amount_paid'] ?? 0));
        $payment_remarks = trim($_POST['payment_remarks'] ?? '');
        $db->prepare("UPDATE purchase_orders SET payment_status=?, payment_method=?, amount_paid=?, payment_remarks=?, updated_at=NOW() WHERE po_id=?")
           ->execute([$payment_status,$payment_method,$amount_paid,$payment_remarks,$po_id]);
        $_SESSION['anais_flash_alert'] = ['type'=>'success','message'=>'Payment details updated.'];
        header('Location: /anais/po_view.php?id='.$po_id); exit;
    }
    if ($action === 'add_return') {
        $product_id = (int)($_POST['product_id'] ?? 0);
        $qty = max(0, (int)($_POST['quantity_returned'] ?? 0));
        $type = $_POST['return_type'] ?? 'Damaged';
        if (!in_array($type, ['Damaged','Wrong Item','Other'], true)) $type='Other';
        $date = $_POST['return_date'] ?? date('Y-m-d');
        $remarks = trim($_POST['remarks'] ?? '');
        if ($product_id > 0 && $qty > 0) {
            $db->beginTransaction();
            $db->prepare("INSERT INTO po_returns (po_id,product_id,quantity_returned,return_type,return_date,remarks,recorded_by,created_at) VALUES (?,?,?,?,?,?,?,NOW())")
               ->execute([$po_id,$product_id,$qty,$type,$date,$remarks,(int)$u['id']]);
            $db->prepare("UPDATE products SET current_stock = GREATEST(current_stock - ?, 0), updated_at=NOW() WHERE product_id=?")
               ->execute([$qty,$product_id]);
            $db->commit();
            $_SESSION['anais_flash_alert'] = ['type'=>'warning','message'=>'Returned item recorded, stock adjusted, and supplier will see the return notification with reason.'];
        }
        header('Location: /anais/po_view.php?id='.$po_id); exit;
    }
}

$stmt = $db->prepare("SELECT po.*, s.supplier_name, s.contact_person, s.contact_number, s.email,
        u.full_name as created_by_name FROM purchase_orders po
        JOIN suppliers s ON po.supplier_id = s.supplier_id
        LEFT JOIN users u ON po.created_by = u.user_id
        WHERE po.po_id = ?");
    $stmt->execute([$po_id]);
    $po = $stmt->fetch();
}

// Line items
$stmt = $db->prepare("SELECT
       pi.po_id,
       pi.product_id,
       SUM(pi.quantity_ordered) AS quantity_ordered,
       MAX(pi.unit_cost) AS unit_cost,
       MAX(p.product_name) AS product_name,
       MAX(p.unit) AS unit,
       COALESCE(sd.quantity_received_total,0) AS quantity_received_total,
       GREATEST(SUM(pi.quantity_ordered) - COALESCE(sd.quantity_received_total,0),0) AS remaining_qty
       FROM po_items pi
       JOIN products p ON pi.product_id = p.product_id
       LEFT JOIN (
           SELECT po_id, product_id, SUM(quantity_received) AS quantity_received_total
           FROM supplier_deliveries
           WHERE po_id=?
           GROUP BY po_id, product_id
       ) sd ON sd.po_id=pi.po_id AND sd.product_id=pi.product_id
       WHERE pi.po_id = ?
       GROUP BY pi.po_id, pi.product_id, sd.quantity_received_total");
$stmt->execute([$po_id, $po_id]);
$items = $stmt->fetchAll();

$priceStmt = $db->prepare("SELECT pi.po_item_id, pi.product_id, pi.quantity_ordered, pi.unit_cost, p.product_name, p.sku, p.unit
    FROM po_items pi
    JOIN products p ON p.product_id = pi.product_id
    WHERE pi.po_id=?
    ORDER BY pi.po_item_id ASC");
$priceStmt->execute([$po_id]);
$priceItems = $priceStmt->fetchAll();

// Deliveries against this PO
$stmt = $db->prepare("SELECT sd.*, p.product_name, u.full_name as received_by_name, GREATEST(po_item_totals.quantity_ordered - COALESCE(delivery_totals.quantity_received_total,0),0) AS product_remaining FROM supplier_deliveries sd
    JOIN products p ON sd.product_id = p.product_id
    LEFT JOIN (SELECT po_id, product_id, SUM(quantity_ordered) AS quantity_ordered FROM po_items GROUP BY po_id, product_id) po_item_totals ON po_item_totals.po_id=sd.po_id AND po_item_totals.product_id=sd.product_id
    LEFT JOIN (SELECT po_id, product_id, SUM(quantity_received) AS quantity_received_total FROM supplier_deliveries GROUP BY po_id, product_id) delivery_totals ON delivery_totals.po_id=sd.po_id AND delivery_totals.product_id=sd.product_id
    LEFT JOIN users u ON sd.received_by = u.user_id
    WHERE sd.po_id = ? ORDER BY sd.delivery_date DESC");
$stmt->execute([$po_id]);
$deliveries = $stmt->fetchAll();


$stmt = $db->prepare("SELECT r.*, p.product_name, u.full_name AS by_name FROM po_returns r JOIN products p ON p.product_id=r.product_id LEFT JOIN users u ON u.user_id=r.recorded_by WHERE r.po_id=? ORDER BY r.return_date DESC, r.return_id DESC");
$stmt->execute([$po_id]);
$returns = $stmt->fetchAll();

function poStatusBadge(string $s): string {
    return match($s) {
        'Pending'             => '<span class="badge badge-warning">Pending</span>',
        'Partially Delivered' => '<span class="badge badge-warning">Incomplete Delivery</span>',
        'Incomplete Delivery'  => '<span class="badge badge-warning">Incomplete Delivery</span>',
        'Fully Delivered'     => '<span class="badge badge-success">Fully Delivered</span>',
        'Cancelled'           => '<span class="badge badge-gray">Cancelled</span>',
        default               => "<span class='badge'>$s</span>",
    };
}

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <div>
    <h1><?= htmlspecialchars($po['po_number']) ?></h1>
    <p>Purchase Order Details <?= poStatusBadge($po['po_status']) ?></p>
  </div>
  <a href="/anais/purchase_orders.php" class="btn btn-outline">← Back</a>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">
  <!-- PO Info -->
  <div class="card">
    <div class="card-header"><span class="card-title">Order Information</span></div>
    <div class="card-body">
      <table style="width:100%">
        <tr><td style="color:var(--text-2);width:160px;padding:6px 0">PO Number</td><td><strong><?= htmlspecialchars($po['po_number']) ?></strong></td></tr>
        <tr><td style="color:var(--text-2);padding:6px 0">Branch</td><td><span class="badge badge-info"><?= $po['branch'] ?></span></td></tr>
        <tr><td style="color:var(--text-2);padding:6px 0">Order Date</td><td><?= date('M d, Y', strtotime($po['order_date'])) ?></td></tr>
        <tr><td style="color:var(--text-2);padding:6px 0">Expected Delivery</td><td><?= date('M d, Y', strtotime($po['expected_delivery_date'])) ?></td></tr>
        <tr><td style="color:var(--text-2);padding:6px 0">Status</td><td><?= !empty($po['is_draft']) ? '<span class="badge badge-warning">Draft</span>' : poStatusBadge($po['po_status']) ?></td></tr>
        <tr><td style="color:var(--text-2);padding:6px 0">Supplier Status</td><td><span class="badge badge-info"><?= htmlspecialchars($po['supplier_status'] ?? '—') ?></span></td></tr>
        <?php if (!empty($po['price_response_note'])): ?>
        <tr><td style="color:var(--text-2);padding:6px 0">Price Response Note</td><td><?= nl2br(htmlspecialchars($po['price_response_note'])) ?></td></tr>
        <?php endif; ?>
        <tr><td style="color:var(--text-2);padding:6px 0">Payment</td><td><span class="badge badge-info"><?= htmlspecialchars($po['payment_status'] ?? 'Unpaid') ?></span> ₱<?= number_format((float)($po['amount_paid'] ?? 0), 2) ?></td></tr>
        <tr><td style="color:var(--text-2);padding:6px 0">Created By</td><td><?= htmlspecialchars($po['created_by_name'] ?? '—') ?></td></tr>
        <?php if ($po['remarks']): ?>
        <tr><td style="color:var(--text-2);padding:6px 0">Remarks</td><td><?= nl2br(htmlspecialchars($po['remarks'])) ?></td></tr>
        <?php endif; ?>
      </table>
    </div>
  </div>
  <!-- Supplier Info -->
  <div class="card">
    <div class="card-header"><span class="card-title">Supplier Information</span></div>
    <div class="card-body">
      <table style="width:100%">
        <tr><td style="color:var(--text-2);width:160px;padding:6px 0">Name</td><td><strong><?= htmlspecialchars($po['supplier_name']) ?></strong></td></tr>
        <tr><td style="color:var(--text-2);padding:6px 0">Contact Person</td><td><?= htmlspecialchars($po['contact_person']) ?></td></tr>
        <tr><td style="color:var(--text-2);padding:6px 0">Phone</td><td><?= htmlspecialchars($po['contact_number']) ?></td></tr>
        <tr><td style="color:var(--text-2);padding:6px 0">Email</td><td><?= htmlspecialchars($po['email'] ?: '—') ?></td></tr>
      </table>
    </div>
  </div>
</div>

<?php if (canEdit()): ?>
<div class="card" style="margin-bottom:20px"><div class="card-header"><span class="card-title">Payment Details</span></div><div class="card-body"><form method="POST"><input type="hidden" name="action" value="update_payment"><div class="form-grid"><div class="form-group"><label>Payment Status</label><select name="payment_status"><option value="Unpaid" <?= ($po['payment_status'] ?? 'Unpaid')==='Unpaid'?'selected':'' ?>>Unpaid</option><option value="Partial" <?= ($po['payment_status'] ?? '')==='Partial'?'selected':'' ?>>Partial</option><option value="Paid" <?= ($po['payment_status'] ?? '')==='Paid'?'selected':'' ?>>Paid</option></select></div><div class="form-group"><label>Payment Method</label><input name="payment_method" value="<?= htmlspecialchars($po['payment_method'] ?? '') ?>" placeholder="Cash / GCash / Bank"></div><div class="form-group"><label>Amount Paid</label><input type="number" step="0.01" min="0" name="amount_paid" value="<?= htmlspecialchars((string)($po['amount_paid'] ?? '0.00')) ?>"></div><div class="form-group full"><label>Payment Remarks</label><textarea name="payment_remarks"><?= htmlspecialchars($po['payment_remarks'] ?? '') ?></textarea></div></div><button class="btn btn-primary" type="submit" style="margin-top:12px">Save Payment</button></form></div></div>
<?php endif; ?>


<?php if (isSupplier() && in_array(($po['supplier_status'] ?? ''), ['Waiting for Supplier Price','Owner Rejected','Pending Confirmation'], true)): ?>
<div class="card" style="margin-bottom:20px;border-left:5px solid var(--accent)">
  <div class="card-header">
    <span class="card-title">Set Unit Costs</span>
    <small style="color:var(--text-2)">Enter the supplier unit cost for each item, then submit to Owner for approval.</small>
  </div>
  <div class="card-body">
    <?php if (($po['supplier_status'] ?? '') === 'Owner Rejected' && !empty($po['price_response_note'])): ?>
      <div class="alert alert-warning">Owner rejected the previous prices: <?= htmlspecialchars($po['price_response_note']) ?></div>
    <?php endif; ?>
    <form class="ajax-form" method="POST" action="/anais/api/supplier_po_action.php" data-reload="1">
      <input type="hidden" name="action" value="submit_prices">
      <input type="hidden" name="po_id" value="<?= (int)$po_id ?>">
      <div class="table-wrap">
        <table>
          <thead><tr><th>Product</th><th>Qty Ordered</th><th>Unit</th><th>Supplier Unit Cost</th></tr></thead>
          <tbody>
          <?php foreach ($priceItems as $it): ?>
            <tr>
              <td><strong><?= htmlspecialchars($it['product_name']) ?></strong><br><small style="color:var(--text-2)">SKU: <?= htmlspecialchars($it['sku'] ?? '—') ?></small></td>
              <td><?= (int)$it['quantity_ordered'] ?></td>
              <td><?= htmlspecialchars($it['unit']) ?></td>
              <td><input type="number" step="0.01" min="0.01" name="unit_cost[<?= (int)$it['po_item_id'] ?>]" value="<?= htmlspecialchars((string)$it['unit_cost']) ?>" required></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <button class="btn btn-primary" type="submit" style="margin-top:12px">Submit Unit Costs to Owner</button>
    </form>
  </div>
</div>
<?php elseif (isSupplier() && ($po['supplier_status'] ?? '') === 'Supplier Prices Submitted'): ?>
<div class="alert alert-warning" data-auto-dismiss>Unit costs submitted. Please wait for Owner to accept or reject the order price.</div>
<?php elseif (isSupplier() && in_array(($po['supplier_status'] ?? ''), ['Owner Accepted','Confirmed','Processing','Shipped','In Transit'], true)): ?>
<div class="alert alert-success" data-auto-dismiss>Owner accepted the supplier prices. You may now process this order.</div>
<?php endif; ?>

<!-- Line Items -->
<div class="card" style="margin-bottom:20px">
  <div class="card-header"><span class="card-title">Ordered Items</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>Product</th><th>Unit</th><th>Qty Ordered</th><th>Received</th><th>Remaining</th><th>Unit Cost</th><th>Total</th></tr></thead>
      <tbody>
      <?php $grandTotal = 0; foreach ($items as $i => $item):
        $total = $item['quantity_ordered'] * $item['unit_cost'];
        $grandTotal += $total;
      ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><?= htmlspecialchars($item['product_name']) ?></td>
          <td><?= htmlspecialchars($item['unit']) ?></td>
          <td><?= $item['quantity_ordered'] ?></td>
          <td style="font-weight:700;color:var(--success)"><?= (int)$item['quantity_received_total'] ?></td>
          <td><?= ((int)$item['remaining_qty'] > 0) ? '<span class="badge badge-warning">'.(int)$item['remaining_qty'].' left</span>' : '<span class="badge badge-success">Complete</span>' ?></td>
          <td>₱<?= number_format($item['unit_cost'],2) ?></td>
          <td>₱<?= number_format($total,2) ?></td>
        </tr>
      <?php endforeach; ?>
      <tr style="background:#f9fafb">
        <td colspan="7" style="text-align:right;font-weight:700">Grand Total:</td>
        <td style="font-weight:700;font-size:15px">₱<?= number_format($grandTotal,2) ?></td>
      </tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Deliveries -->
<div class="card">
  <div class="card-header">
    <span class="card-title">Delivery Records</span>
    <?php if (canEdit() && $po['po_status'] !== 'Cancelled' && $po['po_status'] !== 'Fully Delivered'): ?>
    <a href="/anais/deliveries.php?po_id=<?= $po_id ?>" class="btn btn-primary btn-sm">+ Record Delivery</a>
    <?php endif; ?>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Product</th><th>Qty Ordered</th><th>Qty Received</th><th>Receipt No.</th><th>Status</th><th>Received By</th></tr></thead>
      <tbody>
      <?php if (empty($deliveries)): ?>
        <tr><td colspan="6" style="text-align:center;padding:24px;color:var(--text-2)">No deliveries recorded yet.</td></tr>
      <?php else: foreach ($deliveries as $d): ?>
        <tr>
          <td><?= date('M d, Y', strtotime($d['delivery_date'])) ?></td>
          <td><?= htmlspecialchars($d['product_name']) ?></td>
          <td><?= $d['quantity_ordered'] ?></td>
          <td style="font-weight:700;color:var(--success)"><?= $d['quantity_received'] ?></td>
          <td><?= htmlspecialchars($d['delivery_receipt_no'] ?: '—') ?></td>
          <td><?= ((int)($d['product_remaining'] ?? 0) > 0) ? '<span class="badge badge-warning">Incomplete Delivery</span>' : '<span class="badge badge-success">Complete</span>' ?></td>
          <td><?= htmlspecialchars($d['received_by_name'] ?? '—') ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>


<?php if (canEdit()): ?>
<div class="card" style="margin-top:20px">
  <div class="card-header"><span class="card-title">Damaged / Wrong Item Returns</span></div>
  <div class="card-body">
    <form method="POST" class="form-grid">
      <input type="hidden" name="action" value="add_return">
      <div class="form-group"><label>Product</label><select name="product_id" required><?php foreach($items as $item): ?><option value="<?= (int)$item['product_id'] ?>"><?= htmlspecialchars($item['product_name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Quantity Returned</label><input type="number" name="quantity_returned" min="1" required></div>
      <div class="form-group"><label>Reason</label><select name="return_type"><option>Damaged</option><option>Wrong Item</option><option>Other</option></select></div>
      <div class="form-group"><label>Return Date</label><input type="date" name="return_date" value="<?= date('Y-m-d') ?>" required></div>
      <div class="form-group full"><label>Remarks</label><textarea name="remarks" placeholder="Describe the damage or wrong item received."></textarea></div>
      <div class="form-group full"><button class="btn btn-danger" type="submit">Record Return / Damaged Item</button></div>
    </form>
  </div>
  <div class="table-wrap"><table><thead><tr><th>Date</th><th>Product</th><th>Qty</th><th>Reason</th><th>Remarks</th><th>Recorded By</th></tr></thead><tbody><?php if(empty($returns)): ?><tr><td colspan="6" style="text-align:center;padding:18px;color:var(--text-2)">No returns recorded.</td></tr><?php else: foreach($returns as $r): ?><tr><td><?= date('M d, Y', strtotime($r['return_date'])) ?></td><td><?= htmlspecialchars($r['product_name']) ?></td><td><?= (int)$r['quantity_returned'] ?></td><td><span class="badge badge-warning"><?= htmlspecialchars($r['return_type']) ?></span></td><td><?= htmlspecialchars($r['remarks'] ?? '—') ?></td><td><?= htmlspecialchars($r['by_name'] ?? '—') ?></td></tr><?php endforeach; endif; ?></tbody></table></div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
