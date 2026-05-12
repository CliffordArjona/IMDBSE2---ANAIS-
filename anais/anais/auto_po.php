<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
requireRole(['Owner']);

$pageTitle = 'Automated Purchase Order Generation';
$activePage = 'auto_po';
$db = getDB();

$stmt = $db->query("SELECT p.*, s.supplier_name
    FROM products p
    LEFT JOIN suppliers s ON p.default_supplier_id=s.supplier_id
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
    ORDER BY p.branch, s.supplier_name, p.product_name");
$lowItems = $stmt->fetchAll();
include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <div>
    <h1>Automated Purchase Order Generation</h1>
    <p>Generates draft purchase orders from low-stock items. Owner must confirm before supplier can see them.</p>
  </div>
</div>

<div id="ajaxNotice"></div>

<div class="card" style="margin-bottom:16px">
  <div class="card-body">
    <form class="ajax-form" method="POST" action="/anais/api/auto_po_generate.php" data-reload="1">
      <button class="btn btn-primary" type="submit">⚙ Generate Draft PO from Low-Stock Alerts</button>
      <a class="btn btn-outline" href="/anais/purchase_orders.php">Review Draft POs</a>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <span class="card-title">Low-Stock Items Ready for Auto Purchase Order (<?= count($lowItems) ?>)</span>
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
        <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text-2)">No new low-stock products found. Items already included in draft/open purchase orders are hidden.</td></tr>
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
          <td><?= $p['supplier_name'] ? htmlspecialchars($p['supplier_name']) : '<span class="badge badge-warning">No Supplier Assigned</span>' ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="/anais/assets/js/ajax-forms.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
