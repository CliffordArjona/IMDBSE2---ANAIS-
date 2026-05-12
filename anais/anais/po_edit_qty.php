<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();
requireRole(['Owner']);

$pageTitle = 'Edit PO Quantity';
$activePage = 'po';

$db = getDB();
$u = currentUser();

$po_id = (int)($_GET['id'] ?? $_POST['po_id'] ?? 0);
$msg = '';
$msgType = '';

$stmt = $db->prepare("SELECT po.*, s.supplier_name
    FROM purchase_orders po
    JOIN suppliers s ON s.supplier_id = po.supplier_id
    WHERE po.po_id = ?
    LIMIT 1");
$stmt->execute([$po_id]);
$po = $stmt->fetch();

if (!$po) {
    include __DIR__ . '/includes/header.php';
    echo '<div class="alert alert-danger" data-auto-dismiss>Purchase Order not found.</div>';
    echo '<p><a href="/anais/purchase_orders.php" class="btn btn-outline">Back to Purchase Orders</a></p>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

if ((int)$po['is_draft'] !== 1) {
    include __DIR__ . '/includes/header.php';
    echo '<div class="alert alert-warning" data-auto-dismiss>Quantity can only be edited before the Purchase Order is confirmed.</div>';
    echo '<p><a href="/anais/purchase_orders.php" class="btn btn-outline">Back to Purchase Orders</a></p>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $qtys = $_POST['quantity_ordered'] ?? [];

    try {
        $db->beginTransaction();

        $update = $db->prepare("UPDATE po_items
            SET quantity_ordered = ?
            WHERE po_item_id = ?
              AND po_id = ?");

        foreach ($qtys as $po_item_id => $qty) {
            $po_item_id = (int)$po_item_id;
            $qty = (int)$qty;

            if ($po_item_id > 0 && $qty > 0) {
                $update->execute([$qty, $po_item_id, $po_id]);
            }
        }

        $db->prepare("UPDATE purchase_orders SET updated_at = NOW() WHERE po_id = ?")
           ->execute([$po_id]);

        $db->commit();

        $_SESSION['anais_flash_alert'] = [
            'type' => 'success',
            'message' => 'PO quantities updated successfully. You can now confirm the Purchase Order.'
        ];

        header('Location: /anais/purchase_orders.php');
        exit;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        $msg = 'Unable to update quantities: ' . $e->getMessage();
        $msgType = 'danger';
    }
}

$itemStmt = $db->prepare("SELECT pi.*, p.sku, p.product_name, p.brand, p.unit
    FROM po_items pi
    JOIN products p ON p.product_id = pi.product_id
    WHERE pi.po_id = ?
    ORDER BY p.product_name ASC");
$itemStmt->execute([$po_id]);
$items = $itemStmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h1>Edit PO Quantity</h1>
    <p><?= htmlspecialchars($po['po_number']) ?> — edit ordered quantities before confirmation.</p>
  </div>
  <a href="/anais/purchase_orders.php" class="btn btn-outline">Back</a>
</div>

<?php if ($msg): ?>
  <div class="alert alert-<?= htmlspecialchars($msgType) ?>" data-auto-dismiss><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="card">
  <div class="card-header">
    <span class="card-title">PO Details</span>
  </div>
  <div class="card-body">
    <p><strong>Supplier:</strong> <?= htmlspecialchars($po['supplier_name']) ?></p>
    <p><strong>Branch:</strong> <?= htmlspecialchars($po['branch']) ?></p>
    <p><strong>Expected Delivery:</strong> <?= date('M d, Y', strtotime($po['expected_delivery_date'])) ?></p>
  </div>
</div>

<div class="card" style="margin-top:16px">
  <div class="card-header">
    <span class="card-title">Edit Ordered Quantities</span>
  </div>

  <form method="POST">
    <input type="hidden" name="po_id" value="<?= (int)$po_id ?>">

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Product</th>
            <th>Brand</th>
            <th>Unit</th>
            <th>Quantity Ordered</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $item): ?>
          <tr>
            <td>
              <strong><?= htmlspecialchars($item['product_name']) ?></strong>
              <br><small style="color:var(--text-2)"><?= htmlspecialchars($item['sku']) ?></small>
            </td>
            <td><?= htmlspecialchars($item['brand'] ?? '—') ?></td>
            <td><?= htmlspecialchars($item['unit']) ?></td>
            <td style="max-width:160px">
              <input type="number"
                     name="quantity_ordered[<?= (int)$item['po_item_id'] ?>]"
                     min="1"
                     value="<?= (int)$item['quantity_ordered'] ?>"
                     required>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="modal-footer">
      <a href="/anais/purchase_orders.php" class="btn btn-outline">Cancel</a>
      <button type="submit" class="btn btn-primary">Save Quantities</button>
    </div>
  </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
