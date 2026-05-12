<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
requireRole(['Owner']);

$pageTitle = 'Automated Purchase Order Generation';
$activePage = 'auto_po';
$db = getDB();

// Low-stock products that CAN be used for Auto PO.
// Rule: Auto PO needs a registered active supplier, so products without supplier are excluded.
$stmt = $db->query("SELECT p.*, s.supplier_name
    FROM products p
    JOIN suppliers s ON p.default_supplier_id=s.supplier_id AND s.status='Active'
    WHERE p.status='Active'
      AND p.reorder_level > 0
      AND p.current_stock <= p.reorder_level
      AND NOT EXISTS (
          SELECT 1
          FROM po_items pi
          JOIN purchase_orders po ON po.po_id = pi.po_id
          WHERE pi.product_id = p.product_id
            AND po.po_status NOT IN ('Fully Delivered', 'Cancelled')
      )
    ORDER BY s.supplier_name, p.branch, p.product_name");
$lowItems = $stmt->fetchAll();

// Low-stock products that CANNOT be used for Auto PO yet because no active supplier is assigned.
$noSupplierStmt = $db->query("SELECT p.*, s.supplier_name, s.status AS supplier_status
    FROM products p
    LEFT JOIN suppliers s ON p.default_supplier_id=s.supplier_id
    WHERE p.status='Active'
      AND p.reorder_level > 0
      AND p.current_stock <= p.reorder_level
      AND (p.default_supplier_id IS NULL OR p.default_supplier_id = 0 OR s.supplier_id IS NULL OR s.status <> 'Active')
    ORDER BY p.branch, p.product_name");
$noSupplierItems = $noSupplierStmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <div>
    <h1>Automated Purchase Order Generation</h1>
    <p>Draft POs are generated only for low-stock products with an active assigned supplier.</p>
  </div>
</div>

<div id="ajaxNotice"></div>

<?php if (!empty($noSupplierItems)): ?>
<div class="alert alert-warning" data-auto-dismiss>
  Some low-stock products have no active supplier assigned. They are not included in Auto PO. Assign a supplier first, or restock manually through Inventory Stock-In.
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px">
  <div class="card-body">
    <form class="ajax-form" method="POST" action="/anais/api/auto_po_generate.php" data-reload="1">
      <button class="btn btn-primary" type="submit">⚙ Generate Draft PO from Low-Stock Alerts</button>
      <a class="btn btn-outline" href="/anais/purchase_orders.php">Review Draft POs</a>
      <a class="btn btn-outline" href="/anais/suppliers.php">Manage Suppliers</a>
    </form>
  </div>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-header">
    <span class="card-title">Ready for Auto Purchase Order (<?= count($lowItems) ?>)</span>
    <input type="text" id="tableSearch" placeholder="🔍 Search…" style="width:220px">
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>SKU</th><th>Product</th><th>Branch</th><th>Stock</th><th>Reorder Level</th><th>Suggested Qty</th><th>Supplier</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$lowItems): ?>
        <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text-2)">No low-stock products with active suppliers are ready for Auto PO.</td></tr>
      <?php else: foreach ($lowItems as $p):
        $suggested = max(1, (int)$p['reorder_level'] - (int)$p['current_stock']);
      ?>
        <tr class="low-stock">
          <td><?= htmlspecialchars($p['sku']) ?></td>
          <td><strong><?= htmlspecialchars($p['product_name']) ?></strong></td>
          <td><span class="badge badge-info"><?= htmlspecialchars($p['branch']) ?></span></td>
          <td style="color:var(--danger);font-weight:700"><?= (int)$p['current_stock'] ?></td>
          <td><?= (int)$p['reorder_level'] ?></td>
          <td><?= $suggested ?></td>
          <td><?= htmlspecialchars($p['supplier_name']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <span class="card-title">Low-Stock Items Without Active Supplier (<?= count($noSupplierItems) ?>)</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>SKU</th><th>Product</th><th>Branch</th><th>Stock</th><th>Reorder Level</th><th>Suggested Qty</th><th>Issue</th><th>What to do</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$noSupplierItems): ?>
        <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--text-2)">All low-stock products have an active assigned supplier.</td></tr>
      <?php else: foreach ($noSupplierItems as $p):
        $suggested = max(1, (int)$p['reorder_level'] - (int)$p['current_stock']);
        $issue = empty($p['default_supplier_id']) ? 'No Supplier Assigned' : 'Supplier Inactive / Missing';
      ?>
        <tr class="low-stock">
          <td><?= htmlspecialchars($p['sku']) ?></td>
          <td><strong><?= htmlspecialchars($p['product_name']) ?></strong></td>
          <td><span class="badge badge-info"><?= htmlspecialchars($p['branch']) ?></span></td>
          <td style="color:var(--danger);font-weight:700"><?= (int)$p['current_stock'] ?></td>
          <td><?= (int)$p['reorder_level'] ?></td>
          <td><?= $suggested ?></td>
          <td><span class="badge badge-warning"><?= htmlspecialchars($issue) ?></span></td>
          <td>
            <a class="btn btn-outline btn-sm" href="/anais/inventory.php">Assign Supplier in Inventory</a>
            <a class="btn btn-outline btn-sm" href="/anais/suppliers.php">Add Supplier</a>
            <a class="btn btn-primary btn-sm" href="/anais/inventory.php">Manual Stock-In</a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="/anais/assets/js/ajax-forms.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
