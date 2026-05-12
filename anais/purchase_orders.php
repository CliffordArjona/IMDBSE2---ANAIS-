<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/po_status_sync.php';

requireLogin();
requireRole(['Owner']);

$pageTitle = 'Purchase Orders';
$activePage = 'po';
$db = getDB();
$u = currentUser();

$msg = '';
$msgType = '';

function ensureSupplierPriceColumns(PDO $db): void {
    try {
        $db->exec("ALTER TABLE purchase_orders MODIFY supplier_status ENUM('Pending Confirmation','Waiting for Supplier Price','Supplier Prices Submitted','Owner Accepted','Owner Rejected','Confirmed','Processing','Shipped','In Transit','Delivered','Incomplete Delivery') NOT NULL DEFAULT 'Pending Confirmation'");
    } catch (Throwable $e) {}
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='purchase_orders' AND COLUMN_NAME='price_response_note'");
        $stmt->execute();
        if ((int)$stmt->fetchColumn() === 0) {
            $db->exec("ALTER TABLE purchase_orders ADD COLUMN price_response_note TEXT NULL AFTER payment_remarks");
        }
    } catch (Throwable $e) {}
}
ensureSupplierPriceColumns($db);

function generateManualPONumber(PDO $db): string {
    $prefix = 'PO-' . date('Ymd') . '-';

    $stmt = $db->prepare("SELECT po_number FROM purchase_orders WHERE po_number LIKE ? ORDER BY po_number DESC");
    $stmt->execute([$prefix . '%']);

    $max = 0;
    while ($row = $stmt->fetch()) {
        $poNumber = (string)($row['po_number'] ?? '');
        if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $poNumber, $m)) {
            $max = max($max, (int)$m[1]);
        }
    }

    return $prefix . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';


    if ($action === 'create_manual_po') {
        $supplier_id = (int)($_POST['supplier_id'] ?? 0);
        $branch = $_POST['branch'] ?? '';
        $expected_delivery_date = $_POST['expected_delivery_date'] ?? '';
        $remarks = trim($_POST['remarks'] ?? '');
        $product_ids = $_POST['product_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];

        if ($supplier_id <= 0 || !in_array($branch, ['Autobox','Autophoria'], true) || $expected_delivery_date === '') {
            $msg = 'Please complete supplier, branch, and expected delivery date.';
            $msgType = 'danger';
        } else {
            $items = [];

            foreach ($product_ids as $idx => $product_id) {
                $product_id = (int)$product_id;
                $qty = (int)($quantities[$idx] ?? 0);

                if ($product_id > 0 && $qty > 0) {
                    $items[] = [
                        'product_id' => $product_id,
                        'quantity' => $qty,
                        'unit_cost' => 0.00,
                    ];
                }
            }

            if (!$items) {
                $msg = 'Please add at least one product item with quantity.';
                $msgType = 'danger';
            } else {
                try {
                    $db->beginTransaction();

                    $poNumber = generateManualPONumber($db);

                    $stmt = $db->prepare("INSERT INTO purchase_orders
                        (po_number, supplier_id, branch, order_date, expected_delivery_date, po_status, is_draft, supplier_status, remarks, created_by, created_at, updated_at)
                        VALUES (?, ?, ?, CURDATE(), ?, 'Pending', 1, 'Pending Confirmation', ?, ?, NOW(), NOW())");
                    $stmt->execute([
                        $poNumber,
                        $supplier_id,
                        $branch,
                        $expected_delivery_date,
                        $remarks,
                        (int)$u['id']
                    ]);

                    $po_id = (int)$db->lastInsertId();

                    $itemStmt = $db->prepare("INSERT INTO po_items
                        (po_id, product_id, quantity_ordered, unit_cost)
                        VALUES (?, ?, ?, ?)");

                    foreach ($items as $item) {
                        $itemStmt->execute([
                            $po_id,
                            $item['product_id'],
                            $item['quantity'],
                            $item['unit_cost']
                        ]);
                    }

                    $db->commit();

                    $msg = 'Manual Purchase Order created. Supplier must set unit prices before Owner confirmation.';
                    $msgType = 'success';
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }

                    $msg = 'Manual Purchase Order failed: ' . $e->getMessage();
                    $msgType = 'danger';
                }
            }
        }
    }

    if ($action === 'confirm_draft') {
        $po_id = (int)($_POST['po_id'] ?? 0);

        if ($po_id <= 0) {
            $msg = 'Invalid purchase order.';
            $msgType = 'danger';
        } else {
            $stmt = $db->prepare("SELECT po_id, po_number, is_draft FROM purchase_orders WHERE po_id=? LIMIT 1");
            $stmt->execute([$po_id]);
            $po = $stmt->fetch();

            if (!$po) {
                $msg = 'Purchase Order not found.';
                $msgType = 'danger';
            } elseif ((int)$po['is_draft'] === 0) {
                $msg = 'This Purchase Order is already confirmed and visible to the supplier.';
                $msgType = 'warning';
            } else {
                $db->prepare("UPDATE purchase_orders
                    SET is_draft=0,
                        supplier_status='Waiting for Supplier Price',
                        updated_at=NOW()
                    WHERE po_id=?")
                   ->execute([$po_id]);

                syncSinglePOStatus($db, $po_id);

                $totals = getPODeliveryTotals($db, $po_id);
                if (!$totals['has_delivery']) {
                    $db->prepare("UPDATE purchase_orders
                        SET supplier_status='Waiting for Supplier Price', updated_at=NOW()
                        WHERE po_id=?")
                       ->execute([$po_id]);
                }

                $msg = 'Purchase Order sent to Supplier Portal. Supplier must enter unit costs, then Owner must accept or reject the price.';
                $msgType = 'success';
            }
        }
    }


    if ($action === 'accept_supplier_prices') {
        $po_id = (int)($_POST['po_id'] ?? 0);
        if ($po_id > 0) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM po_items WHERE po_id=? AND unit_cost <= 0");
            $stmt->execute([$po_id]);
            if ((int)$stmt->fetchColumn() > 0) {
                $msg = 'Cannot accept yet. Supplier must provide unit cost for every item.';
                $msgType = 'danger';
            } else {
                $db->prepare("UPDATE purchase_orders SET supplier_status='Owner Accepted', confirmed_at=NOW(), price_response_note=NULL, updated_at=NOW() WHERE po_id=?")
                   ->execute([$po_id]);
                $msg = 'Supplier prices accepted. The supplier can now process the order.';
                $msgType = 'success';
            }
        }
    }

    if ($action === 'reject_supplier_prices') {
        $po_id = (int)($_POST['po_id'] ?? 0);
        $note = trim($_POST['price_response_note'] ?? '');
        if ($po_id > 0) {
            $db->prepare("UPDATE purchase_orders SET supplier_status='Owner Rejected', price_response_note=?, updated_at=NOW() WHERE po_id=?")
               ->execute([$note ?: 'Please review and update the unit costs.', $po_id]);
            $msg = 'Supplier prices rejected. The reason was sent back to the supplier.';
            $msgType = 'warning';
        }
    }

    if ($action === 'cancel_po') {
        $po_id = (int)($_POST['po_id'] ?? 0);

        if ($po_id > 0) {
            $db->prepare("UPDATE purchase_orders
                SET po_status='Cancelled', updated_at=NOW()
                WHERE po_id=?")
               ->execute([$po_id]);

            $msg = 'Purchase Order cancelled.';
            $msgType = 'success';
        }
    }
}

syncAllOpenPOStatuses($db);

$stmt = $db->query("SELECT po.*, s.supplier_name
    FROM purchase_orders po
    JOIN suppliers s ON s.supplier_id = po.supplier_id
    ORDER BY po.created_at DESC, po.po_id DESC");
$orders = $stmt->fetchAll();


$suppliers = $db->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status='Active' ORDER BY supplier_name ASC")->fetchAll();
$products = $db->query("SELECT product_id, sku, product_name, brand, branch
    FROM products
    WHERE status='Active'
    ORDER BY product_name ASC")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h1>Purchase Orders</h1>
    <p>Confirm generated purchase orders before they appear in the Supplier Portal.</p>
  </div>
  <button class="btn btn-primary" onclick="openManualPOModal()">+ Manual Purchase Order</button>
</div>

<?php if ($msg): ?>
  <div class="alert alert-<?= htmlspecialchars($msgType) ?>" data-auto-dismiss><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="card">
  <div class="card-header">
    <span class="card-title">Purchase Orders (<?= count($orders) ?>)</span>
    <input type="text" id="tableSearch" placeholder="🔍 Search…" style="width:220px">
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>PO Number</th>
          <th>Supplier</th>
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
          <td colspan="8" style="text-align:center;padding:32px;color:var(--text-2)">
            No purchase orders found.
          </td>
        </tr>
      <?php else: foreach ($orders as $po): ?>
        <tr>
          <td>
            <strong><?= htmlspecialchars($po['po_number']) ?></strong>
            <?php if ((int)$po['is_draft'] === 1): ?>
              <br><span class="badge badge-warning">Draft / Owner Review</span>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($po['supplier_name']) ?></td>
          <td><span class="badge badge-info"><?= htmlspecialchars($po['branch']) ?></span></td>
          <td><?= date('M d, Y', strtotime($po['order_date'])) ?></td>
          <td><?= date('M d, Y', strtotime($po['expected_delivery_date'])) ?></td>
          <td><?= poStatusBadge($po['po_status']) ?></td>
          <td><?= supplierStatusBadge($po['supplier_status'] ?? 'Pending Confirmation') ?></td>
          <td style="display:flex;gap:6px;flex-wrap:wrap">
            <a href="/anais/po_view.php?id=<?= (int)$po['po_id'] ?>" class="btn btn-outline btn-sm">View</a>

            <?php if (($po['supplier_status'] ?? '') === 'Supplier Prices Submitted'): ?>
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="accept_supplier_prices">
                <input type="hidden" name="po_id" value="<?= (int)$po['po_id'] ?>">
                <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Accept supplier unit costs and approve this order?')">Accept Price</button>
              </form>
              <form method="POST" style="display:inline" onsubmit="return captureRejectReason(this)">
                <input type="hidden" name="action" value="reject_supplier_prices">
                <input type="hidden" name="po_id" value="<?= (int)$po['po_id'] ?>">
                <input type="hidden" name="price_response_note" value="">
                <button type="submit" class="btn btn-danger btn-sm">Reject Price</button>
              </form>
            <?php endif; ?>

            <?php if ((int)$po['is_draft'] === 1): ?>
              <a href="/anais/po_edit_qty.php?id=<?= (int)$po['po_id'] ?>" class="btn btn-outline btn-sm">Edit Qty</a>

              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="confirm_draft">
                <input type="hidden" name="po_id" value="<?= (int)$po['po_id'] ?>">
                <button type="submit" class="btn btn-primary btn-sm"
                  onclick="return confirm('Confirm this Purchase Order and send it to the Supplier Portal?')">
                  Confirm
                </button>
              </form>

              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="cancel_po">
                <input type="hidden" name="po_id" value="<?= (int)$po['po_id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm"
                  onclick="return confirm('Cancel this Purchase Order?')">
                  Cancel
                </button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>


<!-- Manual Purchase Order Modal -->
<div class="modal-backdrop hidden" id="manualPOModal">
  <div class="modal" style="max-width:920px">
    <div class="modal-header">
      <span class="modal-title">Manual Purchase Order</span>
      <button class="modal-close" onclick="closeModal('manualPOModal')">×</button>
    </div>

    <form method="POST">
      <input type="hidden" name="action" value="create_manual_po">

      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group">
            <label>Supplier *</label>
            <select name="supplier_id" required>
              <option value="">— Select Supplier —</option>
              <?php foreach ($suppliers as $s): ?>
                <option value="<?= (int)$s['supplier_id'] ?>"><?= htmlspecialchars($s['supplier_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label>Branch *</label>
            <select name="branch" required>
              <option value="">— Select Branch —</option>
              <option value="Autobox">Autobox</option>
              <option value="Autophoria">Autophoria</option>
            </select>
          </div>

          <div class="form-group">
            <label>Expected Delivery Date *</label>
            <input type="date" name="expected_delivery_date" required min="<?= date('Y-m-d') ?>">
          </div>

          <div class="form-group full">
            <label>Remarks</label>
            <textarea name="remarks" placeholder="Optional remarks for this purchase order"></textarea>
          </div>
        </div>

        <div class="card" style="margin-top:14px">
          <div class="card-header">
            <span class="card-title">PO Items</span>
            <small style="color:var(--text-2)">Unit price/cost will be provided by the supplier.</small>
            <button type="button" class="btn btn-outline btn-sm" onclick="addManualPORow()">+ Add Item</button>
          </div>

          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th style="width:60%">Product</th>
                  <th>Quantity</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody id="manualPOItems"></tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('manualPOModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Manual PO</button>
      </div>
    </form>
  </div>
</div>

<script>
const manualPOProducts = <?= json_encode($products) ?>;

function openManualPOModal() {
  const tbody = document.getElementById('manualPOItems');
  if (tbody && tbody.children.length === 0) {
    addManualPORow();
  }
  openModal('manualPOModal');
}

function addManualPORow() {
  const tbody = document.getElementById('manualPOItems');
  if (!tbody) return;

  const tr = document.createElement('tr');

  const options = manualPOProducts.map(function(p) {
    const label = `${p.sku} — ${p.product_name}${p.brand ? ' (' + p.brand + ')' : ''} [${p.branch}]`;
    return `<option value="${p.product_id}">${escapeHtml(label)}</option>`;
  }).join('');

  tr.innerHTML = `
    <td>
      <select name="product_id[]" required>
        <option value="">— Select Product —</option>
        ${options}
      </select>
    </td>
    <td>
      <input type="number" name="quantity[]" min="1" value="1" required>
    </td>
    <td>
      <button type="button" class="btn btn-danger btn-sm" onclick="this.closest('tr').remove()">Remove</button>
    </td>
  `;

  tbody.appendChild(tr);
}

function captureRejectReason(form) {
  const reason = prompt('Reason for rejecting supplier prices:', 'Please adjust the unit cost.');
  if (reason === null) return false;
  const input = form.querySelector('[name="price_response_note"]');
  if (input) input.value = reason;
  return true;
}

function escapeHtml(str) {
  return String(str).replace(/[&<>"']/g, function(m) {
    return ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;'
    })[m];
  });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
