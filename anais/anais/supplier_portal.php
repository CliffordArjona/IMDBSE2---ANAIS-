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
    <p>View purchase orders sent by the Owner and update supplier action status.</p>
  </div>
</div>

<div id="ajaxNotice"></div>

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
          <th>Supplier Action Status</th>
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

            <?php if (($po['supplier_status'] ?? 'Pending Confirmation') === 'Pending Confirmation'): ?>
            <form class="ajax-form" method="POST" action="/anais/api/supplier_po_action.php" data-reload="1" style="display:inline">
              <input type="hidden" name="action" value="confirm">
              <input type="hidden" name="po_id" value="<?= (int)$po['po_id'] ?>">
              <button type="submit" class="btn btn-primary btn-sm">Confirm</button>
            </form>
            <?php endif; ?>

            <form class="ajax-form" method="POST" action="/anais/api/supplier_po_action.php" data-reload="1" style="display:inline">
              <input type="hidden" name="action" value="update_status">
              <input type="hidden" name="po_id" value="<?= (int)$po['po_id'] ?>">
              <select name="supplier_status" onchange="this.form.requestSubmit()" style="padding:5px 8px;font-size:12px;border-radius:6px;border:1px solid var(--border)">
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
