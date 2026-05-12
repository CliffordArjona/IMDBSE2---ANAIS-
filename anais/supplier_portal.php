<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/po_status_sync.php';

requireLogin();
requireRole(['Supplier']);

$pageTitle = 'Supplier PO Portal';
$activePage = 'supplier_portal';
$db = getDB();
$u = currentUser();

$supplier_id = (int)($u['supplier_id'] ?? 0);

if ($supplier_id <= 0) {
    include __DIR__ . '/includes/header.php';
    echo '<div class="alert alert-danger" data-auto-dismiss>Supplier account is not linked to a supplier record.</div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}


// Return notifications for the supplier.
// When Owner/OIC records a damaged or wrong item return in PO View,
// the supplier can immediately see the returned product, quantity, and reason here.
try {
    $db->exec("CREATE TABLE IF NOT EXISTS po_returns (
        return_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        po_id INT UNSIGNED NOT NULL,
        product_id MEDIUMINT UNSIGNED NOT NULL,
        quantity_returned MEDIUMINT UNSIGNED NOT NULL,
        return_type ENUM('Damaged','Wrong Item','Other') NOT NULL DEFAULT 'Damaged',
        return_date DATE NOT NULL,
        remarks TEXT NULL,
        recorded_by MEDIUMINT UNSIGNED NULL,
        replacement_status ENUM('Required','Scheduled','Received','Cancelled') NOT NULL DEFAULT 'Required',
        replacement_qty MEDIUMINT UNSIGNED NULL,
        estimated_redelivery_date DATE NULL,
        supplier_redelivery_note TEXT NULL,
        redelivery_scheduled_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY po_id (po_id),
        KEY product_id (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try {
        $db->exec("ALTER TABLE purchase_orders MODIFY supplier_status ENUM('Pending Confirmation','Waiting for Supplier Price','Supplier Prices Submitted','Owner Accepted','Owner Rejected','Confirmed','Processing','Shipped','In Transit','Delivered','Incomplete Delivery','Replacement Required','Replacement Scheduled') NOT NULL DEFAULT 'Pending Confirmation'");
    } catch (Throwable $e) {}

    foreach ([
        'replacement_status' => "ALTER TABLE po_returns ADD COLUMN replacement_status ENUM('Required','Scheduled','Received','Cancelled') NOT NULL DEFAULT 'Required' AFTER recorded_by",
        'replacement_qty' => "ALTER TABLE po_returns ADD COLUMN replacement_qty MEDIUMINT UNSIGNED NULL AFTER replacement_status",
        'estimated_redelivery_date' => "ALTER TABLE po_returns ADD COLUMN estimated_redelivery_date DATE NULL AFTER replacement_qty",
        'supplier_redelivery_note' => "ALTER TABLE po_returns ADD COLUMN supplier_redelivery_note TEXT NULL AFTER estimated_redelivery_date",
        'redelivery_scheduled_at' => "ALTER TABLE po_returns ADD COLUMN redelivery_scheduled_at DATETIME NULL AFTER supplier_redelivery_note",
    ] as $column => $sql) {
        try {
            $chk = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='po_returns' AND COLUMN_NAME=?");
            $chk->execute([$column]);
            if ((int)$chk->fetchColumn() === 0) { $db->exec($sql); }
        } catch (Throwable $e) {}
    }

    $returnStmt = $db->prepare("SELECT
            r.return_id,
            r.return_date,
            r.quantity_returned,
            r.return_type,
            r.remarks,
            r.replacement_status,
            r.replacement_qty,
            r.estimated_redelivery_date,
            r.supplier_redelivery_note,
            r.redelivery_scheduled_at,
            r.created_at,
            po.po_id,
            po.po_number,
            po.branch,
            p.product_name,
            p.sku,
            u.full_name AS recorded_by_name
        FROM po_returns r
        JOIN purchase_orders po ON po.po_id = r.po_id
        JOIN products p ON p.product_id = r.product_id
        LEFT JOIN users u ON u.user_id = r.recorded_by
        WHERE po.supplier_id = ?
          AND po.is_draft = 0
        ORDER BY r.created_at DESC, r.return_id DESC
        LIMIT 10");
    $returnStmt->execute([$supplier_id]);
    $returnNotifications = $returnStmt->fetchAll();
} catch (Throwable $e) {
    $returnNotifications = [];
}

// Sync statuses after return/redelivery columns are available, so Replacement Required/Scheduled is preserved.
syncAllOpenPOStatuses($db, $supplier_id);

$stmt = $db->prepare("SELECT po.*, s.supplier_name
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id=s.supplier_id
    WHERE po.supplier_id=? AND po.is_draft=0
    ORDER BY po.created_at DESC, po.po_id DESC");
$stmt->execute([$supplier_id]);
$orders = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <div>
    <h1>Supplier Purchase Orders</h1>
    <p>Enter unit costs for purchase orders, then wait for Owner price approval before processing delivery.</p>
  </div>
</div>

<div id="ajaxNotice"></div>

<?php if (!empty($returnNotifications)): ?>
<div class="card" style="margin-bottom:16px;border-left:5px solid var(--warning)">
  <div class="card-header">
    <span class="card-title">⚠️ Return Notifications (<?= count($returnNotifications) ?>)</span>
    <small style="color:var(--text-2)">Items returned by Owner/OIC with reason</small>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Date</th>
          <th>PO Number</th>
          <th>Product</th>
          <th>Qty Returned</th>
          <th>Reason</th>
          <th>Replacement Status</th>
          <th>Remarks</th>
          <th>Recorded By</th>
          <th>Redelivery</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($returnNotifications as $r): ?>
        <tr class="low-stock">
          <td><?= date('M d, Y', strtotime($r['return_date'])) ?></td>
          <td><a href="/anais/po_view.php?id=<?= (int)$r['po_id'] ?>"><strong><?= htmlspecialchars($r['po_number']) ?></strong></a></td>
          <td>
            <strong><?= htmlspecialchars($r['product_name']) ?></strong>
            <?php if (!empty($r['sku'])): ?><br><small style="color:var(--text-2)">SKU: <?= htmlspecialchars($r['sku']) ?></small><?php endif; ?>
          </td>
          <td><strong><?= (int)$r['quantity_returned'] ?></strong></td>
          <td><span class="badge badge-warning"><?= htmlspecialchars($r['return_type']) ?></span></td>
          <td>
            <?php $rep = $r['replacement_status'] ?? 'Required'; ?>
            <span class="badge <?= $rep === 'Scheduled' ? 'badge-info' : ($rep === 'Received' ? 'badge-success' : 'badge-warning') ?>"><?= htmlspecialchars($rep) ?></span>
            <?php if (!empty($r['estimated_redelivery_date'])): ?>
              <br><small style="color:var(--text-2)">ETA: <?= date('M d, Y', strtotime($r['estimated_redelivery_date'])) ?></small>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($r['remarks'] ?: 'No remarks provided.') ?></td>
          <td><?= htmlspecialchars($r['recorded_by_name'] ?? 'Owner/OIC') ?></td>
          <td style="min-width:220px">
            <?php if (($r['replacement_status'] ?? 'Required') !== 'Received'): ?>
              <form class="ajax-form" method="POST" action="/anais/api/supplier_po_action.php" data-reload="1" style="display:grid;gap:6px">
                <input type="hidden" name="action" value="schedule_redelivery">
                <input type="hidden" name="po_id" value="<?= (int)$r['po_id'] ?>">
                <input type="hidden" name="return_id" value="<?= (int)$r['return_id'] ?>">
                <input type="number" name="replacement_qty" min="1" max="<?= (int)$r['quantity_returned'] ?>" value="<?= (int)($r['replacement_qty'] ?: $r['quantity_returned']) ?>" required style="padding:6px 8px;font-size:12px" title="Replacement quantity">
                <input type="date" name="estimated_redelivery_date" value="<?= htmlspecialchars($r['estimated_redelivery_date'] ?? '') ?>" required style="padding:6px 8px;font-size:12px">
                <input type="text" name="supplier_redelivery_note" value="<?= htmlspecialchars($r['supplier_redelivery_note'] ?? '') ?>" placeholder="Note / tracking / remarks" style="padding:6px 8px;font-size:12px">
                <button type="submit" class="btn btn-primary btn-sm">Schedule Redelivery</button>
              </form>
            <?php else: ?>
              <span class="badge badge-success">Received by Owner/OIC</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  if (typeof window.showToast === 'function') {
    window.showToast('warning', 'You have returned item notification(s). Please check the reason in Return Notifications.');
  }
});
</script>
<?php endif; ?>

<div class="card">
  <div class="card-header">
    <span class="card-title">Issued Purchase Orders (<?= count($orders) ?>)</span>
    <input type="text" id="tableSearch" placeholder="🔍 Search…" style="width:220px">
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>PO Number</th>
          <th>Branch</th>
          <th>Order Date</th>
          <th>Expected Delivery</th>
          <th>PO Status</th>
          <th>Supplier / Price Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$orders): ?>
        <tr>
          <td colspan="7" style="text-align:center;padding:32px;color:var(--text-2)">
            No purchase orders issued yet. The Owner must confirm the PO first.
          </td>
        </tr>
      <?php else: foreach ($orders as $po): ?>
        <tr>
          <td><strong><?= htmlspecialchars($po['po_number']) ?></strong></td>
          <td><span class="badge badge-info"><?= htmlspecialchars($po['branch']) ?></span></td>
          <td><?= date('M d, Y', strtotime($po['order_date'])) ?></td>
          <td><?= date('M d, Y', strtotime($po['expected_delivery_date'])) ?></td>
          <td><?= poStatusBadge($po['po_status']) ?></td>
          <td><?= supplierStatusBadge($po['supplier_status'] ?? 'Pending Confirmation') ?></td>
          <td style="display:flex;gap:6px;flex-wrap:wrap">
            <a href="/anais/po_view.php?id=<?= (int)$po['po_id'] ?>" class="btn btn-outline btn-sm">View</a>

            <?php if (in_array(($po['supplier_status'] ?? ''), ['Waiting for Supplier Price','Owner Rejected','Pending Confirmation'], true)): ?>
              <a href="/anais/po_view.php?id=<?= (int)$po['po_id'] ?>" class="btn btn-primary btn-sm">Set Unit Cost</a>
            <?php elseif (($po['supplier_status'] ?? '') === 'Supplier Prices Submitted'): ?>
              <span class="badge badge-info">Waiting for Owner Approval</span>
            <?php elseif (in_array(($po['supplier_status'] ?? ''), ['Owner Accepted','Confirmed'], true)): ?>
              <span class="badge badge-success">Owner Accepted</span>
            <?php endif; ?>

            <form class="ajax-form" method="POST" action="/anais/api/supplier_po_action.php" data-reload="1" style="display:inline">
              <input type="hidden" name="action" value="update_status">
              <input type="hidden" name="po_id" value="<?= (int)$po['po_id'] ?>">
              <select name="supplier_status" <?= in_array(($po['supplier_status'] ?? ''), ['Owner Accepted','Confirmed','Processing','Shipped','In Transit','Delivered','Incomplete Delivery','Replacement Required','Replacement Scheduled'], true) ? '' : 'disabled' ?> onchange="this.form.requestSubmit()" style="padding:5px 8px;font-size:12px;border-radius:6px;border:1px solid var(--border)">
                <option value="">Update</option>
                <option value="Processing">Processing</option>
                <option value="Shipped">Shipped</option>
                <option value="In Transit">In Transit</option>
                <option value="Delivered">Delivered</option>
              </select>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
