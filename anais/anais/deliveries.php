<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/po_status_sync.php';
requireLogin();
requireRole(['Owner','OIC']);

$pageTitle = 'Supplier Deliveries';
$activePage = 'deliveries';
$db = getDB();
$u  = currentUser();
$msg = ''; $msgType = '';
$prefillPO = (int)($_GET['po_id'] ?? 0);

// ── Helpers ─────────────────────────────────────────────────
function getPODeliveryTotals(PDO $db, int $po_id): array {
    // Group by product first so partial deliveries are counted cumulatively per PO item.
    // LEAST() prevents over-received rows from blocking/completing calculations incorrectly.
    $stmt = $db->prepare("SELECT
            COALESCE(SUM(x.quantity_ordered),0) AS total_ordered,
            COALESCE(SUM(LEAST(x.quantity_received_total, x.quantity_ordered)),0) AS total_received,
            COALESCE(SUM(GREATEST(x.quantity_ordered - x.quantity_received_total, 0)),0) AS total_remaining
        FROM (
            SELECT
                pi.product_id,
                SUM(pi.quantity_ordered) AS quantity_ordered,
                COALESCE(sd.quantity_received_total,0) AS quantity_received_total
            FROM po_items pi
            LEFT JOIN (
                SELECT po_id, product_id, SUM(quantity_received) AS quantity_received_total
                FROM supplier_deliveries
                WHERE po_id = ?
                GROUP BY po_id, product_id
            ) sd ON sd.po_id = pi.po_id AND sd.product_id = pi.product_id
            WHERE pi.po_id = ?
            GROUP BY pi.product_id, sd.quantity_received_total
        ) x");
    $stmt->execute([$po_id, $po_id]);
    $row = $stmt->fetch() ?: [];
    $ordered = (int)($row['total_ordered'] ?? 0);
    $received = (int)($row['total_received'] ?? 0);
    $remaining = (int)($row['total_remaining'] ?? 0);
    return [
        'ordered' => $ordered,
        'received' => $received,
        'remaining' => $remaining,
        'complete' => $ordered > 0 && $remaining <= 0,
    ];
}

function recomputePOStatus(PDO $db, int $po_id): string {
    $totals = getPODeliveryTotals($db, $po_id);
    if ($totals['complete']) {
        return 'Fully Delivered';
    }
    if ($totals['received'] > 0) {
        return 'Partially Delivered';
    }
    return 'Pending';
}

function getRemainingQty(PDO $db, int $po_id, int $product_id): array|false {
    $stmt = $db->prepare("SELECT
            pi.product_id,
            SUM(pi.quantity_ordered) AS quantity_ordered,
            MAX(p.product_name) AS product_name,
            MAX(p.unit) AS unit,
            COALESCE(sd.quantity_received_total,0) AS quantity_received_total
        FROM po_items pi
        JOIN products p ON pi.product_id = p.product_id
        LEFT JOIN (
            SELECT po_id, product_id, SUM(quantity_received) AS quantity_received_total
            FROM supplier_deliveries
            WHERE po_id = ? AND product_id = ?
            GROUP BY po_id, product_id
        ) sd ON sd.po_id = pi.po_id AND sd.product_id = pi.product_id
        WHERE pi.po_id = ? AND pi.product_id = ?
        GROUP BY pi.product_id, sd.quantity_received_total
        LIMIT 1");
    $stmt->execute([$po_id, $product_id, $po_id, $product_id]);
    $item = $stmt->fetch();
    if (!$item) {
        return false;
    }
    $item['remaining_qty'] = max(0, (int)$item['quantity_ordered'] - (int)$item['quantity_received_total']);
    return $item;
}

// ── CRUD ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $po_id            = (int)$_POST['po_id'];
    $product_id       = (int)$_POST['product_id'];
    $qty_received     = (int)$_POST['quantity_received'];
    $delivery_date    = $_POST['delivery_date'];
    $receipt_no       = trim($_POST['delivery_receipt_no'] ?? '');
    $remarks          = trim($_POST['remarks'] ?? '');

    if ($po_id && $product_id && $qty_received > 0 && $delivery_date) {
        $db->beginTransaction();
        try {
            // Lock the PO row while validating and recording delivery to avoid duplicate over-receiving.
            $poCheckSql = "SELECT po_id, branch, po_status FROM purchase_orders WHERE po_id=? AND po_status NOT IN ('Cancelled','Fully Delivered')";
            $poParams = [$po_id];
            if ($u['branch'] !== 'Both') {
                $poCheckSql .= " AND branch=?";
                $poParams[] = $u['branch'];
            }
            $poCheckSql .= " FOR UPDATE";
            $poCheck = $db->prepare($poCheckSql);
            $poCheck->execute($poParams);
            $poRow = $poCheck->fetch();
            if (!$poRow) {
                throw new Exception('This purchase order is not open for delivery or is not assigned to your branch.');
            }

            $item = getRemainingQty($db, $po_id, $product_id);
            if (!$item) {
                throw new Exception('Selected product does not belong to this purchase order.');
            }

            $qty_ordered = (int)$item['quantity_ordered'];
            $already_received = (int)$item['quantity_received_total'];
            $remaining_qty = (int)$item['remaining_qty'];

            if ($remaining_qty <= 0) {
                throw new Exception('This item is already fully delivered.');
            }
            if ($qty_received > $remaining_qty) {
                throw new Exception('Received quantity cannot exceed the remaining quantity. Remaining: ' . $remaining_qty);
            }

            // Save only the actual delivered quantity. Incomplete deliveries keep the PO open.
            $db->prepare("INSERT INTO supplier_deliveries (po_id,product_id,quantity_ordered,quantity_received,delivery_date,delivery_receipt_no,received_by,remarks,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())")
               ->execute([$po_id,$product_id,$qty_ordered,$qty_received,$delivery_date,$receipt_no,$u['id'],$remarks]);

            // Add only the delivered quantity to inventory stock.
            $db->prepare("UPDATE products SET current_stock = current_stock + ?, updated_at=NOW() WHERE product_id=?")
               ->execute([$qty_received,$product_id]);

            // Record stock-in transaction for the delivered quantity only.
            $db->prepare("INSERT INTO stock_transactions (product_id,transaction_type,quantity,reference_no,transaction_date,remarks,recorded_by,created_at) VALUES (?,'Stock-In',?,?,?,?,?,NOW())")
               ->execute([$product_id,$qty_received,$receipt_no,$delivery_date,"Delivery from PO #" . $po_id . " (received $qty_received of remaining $remaining_qty)",$u['id']]);

            $newStatus = recomputePOStatus($db, $po_id);
            $supplierStatus = $newStatus === 'Fully Delivered' ? 'Delivered' : 'Incomplete Delivery';
            $db->prepare("UPDATE purchase_orders SET po_status=?, supplier_status=?, updated_at=NOW() WHERE po_id=?")
               ->execute([$newStatus,$supplierStatus,$po_id]);

            if (!empty($po_id)) { syncSinglePOStatus($db, (int)$po_id); }
            $db->commit();
            $left = max(0, $remaining_qty - $qty_received);
            if ($left > 0) {
                $msg = "Incomplete delivery recorded. $qty_received units added to stock. Remaining for this item: $left.";
            } else {
                $msg = "Delivery recorded. $qty_received units added to stock. This item is now complete.";
            }
            $msgType = 'success';
        } catch (Exception $e) {
            $db->rollBack();
            $msg = 'Error: ' . $e->getMessage(); $msgType = 'danger';
        }
    } else {
        $msg = 'Please fill in all required fields.'; $msgType = 'danger';
    }
}

// ── Fetch open POs ──────────────────────────────────────────
$branch_clause = ($u['branch'] !== 'Both') ? "AND po.branch = ?" : "";
$branch_params = ($u['branch'] !== 'Both') ? [$u['branch']] : [];
$stmt = $db->prepare("SELECT po.po_id, po.po_number, po.branch, s.supplier_name
    FROM purchase_orders po JOIN suppliers s ON po.supplier_id=s.supplier_id
    WHERE po.po_status IN ('Pending','Partially Delivered','Incomplete Delivery') $branch_clause
    ORDER BY po.order_date DESC");
$stmt->execute($branch_params);
$openPOs = $stmt->fetchAll();

// PO Items for selected PO (AJAX-style via JS)
$poItemsMap = [];
if ($prefillPO) {
    $stmt = $db->prepare("SELECT pi.product_id, SUM(pi.quantity_ordered) AS quantity_ordered, MAX(p.product_name) AS product_name, MAX(p.unit) AS unit, COALESCE(sd.quantity_received_total,0) AS quantity_received_total, GREATEST(SUM(pi.quantity_ordered) - COALESCE(sd.quantity_received_total,0),0) AS remaining_qty FROM po_items pi JOIN products p ON pi.product_id=p.product_id LEFT JOIN (SELECT po_id, product_id, SUM(quantity_received) AS quantity_received_total FROM supplier_deliveries WHERE po_id=? GROUP BY po_id, product_id) sd ON sd.po_id=pi.po_id AND sd.product_id=pi.product_id WHERE pi.po_id=? GROUP BY pi.product_id, sd.quantity_received_total HAVING remaining_qty > 0");
    $stmt->execute([$prefillPO,$prefillPO]);
    $poItemsMap = $stmt->fetchAll();
}

// Delivery history
$stmt = $db->prepare("SELECT sd.*, p.product_name, po.po_number, u.full_name as by_name, GREATEST(po_item_totals.quantity_ordered - COALESCE(delivery_totals.quantity_received_total,0),0) AS product_remaining
    FROM supplier_deliveries sd
    JOIN products p ON sd.product_id=p.product_id
    JOIN purchase_orders po ON sd.po_id=po.po_id
    LEFT JOIN (SELECT po_id, product_id, SUM(quantity_ordered) AS quantity_ordered FROM po_items GROUP BY po_id, product_id) po_item_totals ON po_item_totals.po_id=sd.po_id AND po_item_totals.product_id=sd.product_id
    LEFT JOIN (SELECT po_id, product_id, SUM(quantity_received) AS quantity_received_total FROM supplier_deliveries GROUP BY po_id, product_id) delivery_totals ON delivery_totals.po_id=sd.po_id AND delivery_totals.product_id=sd.product_id
    LEFT JOIN users u ON sd.received_by=u.user_id
    " . ($u['branch']!=='Both' ? "WHERE po.branch=?" : "") . "
    ORDER BY sd.created_at DESC LIMIT 30");
$stmt->execute($branch_params);
$history = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <div><h1>Supplier Deliveries</h1><p>Record received goods against purchase orders.</p></div>
</div>

<?php if ($msg): ?>
  <div class="alert alert-<?= $msgType ?>" data-auto-dismiss><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:380px 1fr;gap:20px;align-items:start">

<!-- Form -->
<div class="card">
  <div class="card-header"><span class="card-title">Record Delivery</span></div>
  <div class="card-body">
    <form method="POST" id="deliveryForm">
      <div class="form-group" style="margin-bottom:12px">
        <label>Purchase Order *</label>
        <select name="po_id" id="poSelect" required onchange="loadPOItems(this.value)">
          <option value="">— Select PO —</option>
          <?php foreach ($openPOs as $po): ?>
            <option value="<?= $po['po_id'] ?>" <?= $prefillPO===$po['po_id']?'selected':'' ?>>
              <?= htmlspecialchars($po['po_number']) ?> — <?= htmlspecialchars($po['supplier_name']) ?> [<?= $po['branch'] ?>]
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label>Product *</label>
        <select name="product_id" id="productSelect" required>
          <option value="">— Select PO first —</option>
          <?php foreach ($poItemsMap as $item): ?>
            <option value="<?= $item['product_id'] ?>" data-qty="<?= $item['quantity_ordered'] ?>" data-received="<?= $item['quantity_received_total'] ?>" data-remaining="<?= $item['remaining_qty'] ?>">
              <?= htmlspecialchars($item['product_name']) ?> (Ordered: <?= $item['quantity_ordered'] ?>, Received: <?= $item['quantity_received_total'] ?>, Remaining: <?= $item['remaining_qty'] ?> <?= $item['unit'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label>Qty Ordered (from PO)</label>
        <input type="number" id="qtyOrdered" readonly style="background:#f9fafb" value="0">
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label>Already Received</label>
        <input type="number" id="qtyAlreadyReceived" readonly style="background:#f9fafb" value="0">
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label>Remaining Qty</label>
        <input type="number" id="qtyRemaining" readonly style="background:#f9fafb;font-weight:700" value="0">
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label>Qty Received Now *</label>
        <input type="number" name="quantity_received" id="qtyReceived" min="1" required placeholder="0">
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label>Delivery Date *</label>
        <input type="date" name="delivery_date" value="<?= date('Y-m-d') ?>" required>
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label>Delivery Receipt No.</label>
        <input type="text" name="delivery_receipt_no" placeholder="Optional">
      </div>
      <div class="form-group" style="margin-bottom:16px">
        <label>Remarks</label>
        <textarea name="remarks" rows="2"></textarea>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">Record Delivery</button>
    </form>
  </div>
</div>

<!-- History -->
<div class="card">
  <div class="card-header">
    <span class="card-title">Delivery History</span>
    <input type="text" id="tableSearch" placeholder="🔍 Search…" style="width:200px">
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>PO No.</th><th>Product</th><th>Qty Ordered</th><th>Qty Received</th><th>Receipt No.</th><th>Status</th><th>Received By</th></tr></thead>
      <tbody>
      <?php if (empty($history)): ?>
        <tr><td colspan="8" style="text-align:center;padding:28px;color:var(--text-2)">No deliveries yet.</td></tr>
      <?php else: foreach ($history as $d): ?>
        <tr>
          <td><?= date('M d, Y', strtotime($d['delivery_date'])) ?></td>
          <td><a href="/anais/po_view.php?id=<?= $d['po_id'] ?>" style="color:var(--accent)"><?= htmlspecialchars($d['po_number']) ?></a></td>
          <td><?= htmlspecialchars($d['product_name']) ?></td>
          <td><?= $d['quantity_ordered'] ?></td>
          <td style="font-weight:700;color:var(--success)"><?= $d['quantity_received'] ?></td>
          <td><?= htmlspecialchars($d['delivery_receipt_no'] ?: '—') ?></td>
          <td><?= ((int)($d['product_remaining'] ?? 0) > 0) ? '<span class="badge badge-warning">Incomplete Delivery</span>' : '<span class="badge badge-success">Complete</span>' ?></td>
          <td><?= htmlspecialchars($d['by_name'] ?? '—') ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div>

<script>
// PO items data passed from PHP. Only items with remaining quantity are listed.
const poItemsData = <?= json_encode(array_reduce($openPOs, function($acc, $po) use ($db) {
    $stmt = $db->prepare("SELECT
            pi.product_id,
            SUM(pi.quantity_ordered) AS quantity_ordered,
            MAX(p.product_name) AS product_name,
            MAX(p.unit) AS unit,
            COALESCE(sd.quantity_received_total,0) AS quantity_received_total,
            GREATEST(SUM(pi.quantity_ordered) - COALESCE(sd.quantity_received_total,0),0) AS remaining_qty
        FROM po_items pi
        JOIN products p ON pi.product_id=p.product_id
        LEFT JOIN (
            SELECT po_id, product_id, SUM(quantity_received) AS quantity_received_total
            FROM supplier_deliveries
            WHERE po_id=?
            GROUP BY po_id, product_id
        ) sd ON sd.po_id=pi.po_id AND sd.product_id=pi.product_id
        WHERE pi.po_id=?
        GROUP BY pi.product_id, sd.quantity_received_total
        HAVING remaining_qty > 0");
    $stmt->execute([$po['po_id'], $po['po_id']]);
    $acc[$po['po_id']] = $stmt->fetchAll();
    return $acc;
}, [])) ?>;

function resetQtyFields() {
    document.getElementById('qtyOrdered').value = 0;
    document.getElementById('qtyAlreadyReceived').value = 0;
    document.getElementById('qtyRemaining').value = 0;
    const qtyReceived = document.getElementById('qtyReceived');
    qtyReceived.value = '';
    qtyReceived.removeAttribute('max');
}

function loadPOItems(poId) {
    const sel = document.getElementById('productSelect');
    const items = poItemsData[poId] || [];
    sel.innerHTML = '<option value="">— Select Product —</option>';

    if (!items.length && poId) {
        sel.innerHTML = '<option value="">All items are already fully delivered</option>';
    }

    items.forEach(function(item) {
        const opt = document.createElement('option');
        opt.value = item.product_id;
        opt.dataset.qty = item.quantity_ordered;
        opt.dataset.received = item.quantity_received_total;
        opt.dataset.remaining = item.remaining_qty;
        opt.textContent = item.product_name + ' (Ordered: ' + item.quantity_ordered + ', Received: ' + item.quantity_received_total + ', Remaining: ' + item.remaining_qty + ' ' + item.unit + ')';
        sel.appendChild(opt);
    });
    resetQtyFields();
}

document.getElementById('productSelect').addEventListener('change', function() {
    const sel = this.options[this.selectedIndex];
    const remaining = sel.dataset.remaining || 0;
    document.getElementById('qtyOrdered').value = sel.dataset.qty || 0;
    document.getElementById('qtyAlreadyReceived').value = sel.dataset.received || 0;
    document.getElementById('qtyRemaining').value = remaining;
    const qtyReceived = document.getElementById('qtyReceived');
    qtyReceived.max = remaining;
    qtyReceived.value = '';
});

<?php if ($prefillPO): ?>
loadPOItems(<?= $prefillPO ?>);
<?php endif; ?>
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
