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
function deliveryTableColumnExists(PDO $db, string $table, string $column): bool {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function ensurePOReturnsTable(PDO $db): void {
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
}
ensurePOReturnsTable($db);

// Redelivery support for damaged/wrong items returned to supplier.
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
    if (!deliveryTableColumnExists($db, 'po_returns', $column)) {
        try { $db->exec($sql); } catch (Throwable $e) {}
    }
}


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



function getPOReturnedQty(PDO $db, int $po_id, int $product_id): int {
    $stmt = $db->prepare("SELECT COALESCE(SUM(quantity_returned),0)
        FROM po_returns
        WHERE po_id = ? AND product_id = ?");
    $stmt->execute([$po_id, $product_id]);
    return (int)$stmt->fetchColumn();
}

function getPOAcceptedQty(PDO $db, int $po_id, int $product_id): int {
    $stmt = $db->prepare("SELECT COALESCE(SUM(quantity_received),0)
        FROM supplier_deliveries
        WHERE po_id = ? AND product_id = ?");
    $stmt->execute([$po_id, $product_id]);
    return (int)$stmt->fetchColumn();
}

function poAllowsDelivery(PDO $db, int $po_id, array $u): array|false {
    $poCheckSql = "SELECT po_id, branch, po_status FROM purchase_orders WHERE po_id=? AND po_status NOT IN ('Cancelled','Fully Delivered')";
    $poParams = [$po_id];
    if (($u['branch'] ?? '') !== 'Both') {
        $poCheckSql .= " AND branch=?";
        $poParams[] = $u['branch'];
    }
    $poCheckSql .= " FOR UPDATE";
    $poCheck = $db->prepare($poCheckSql);
    $poCheck->execute($poParams);
    return $poCheck->fetch() ?: false;
}

// ── CRUD ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'record_delivery';

    if ($action === 'record_delivery') {
        $po_id            = (int)($_POST['po_id'] ?? 0);
        $product_id       = (int)($_POST['product_id'] ?? 0);
        $qty_received     = max(0, (int)($_POST['quantity_received'] ?? 0)); // Good/accepted items only.
        $qty_returned     = max(0, (int)($_POST['quantity_returned'] ?? 0)); // Damaged/wrong items returned to supplier.
        $return_type      = $_POST['return_type'] ?? 'Damaged';
        $return_remarks   = trim($_POST['return_remarks'] ?? '');
        $delivery_date    = $_POST['delivery_date'] ?? '';
        $receipt_no       = trim($_POST['delivery_receipt_no'] ?? '');
        $remarks          = trim($_POST['remarks'] ?? '');

        if (!in_array($return_type, ['Damaged','Wrong Item','Other'], true)) {
            $return_type = 'Other';
        }

        if ($po_id && $product_id && ($qty_received > 0 || $qty_returned > 0) && $delivery_date) {
            $db->beginTransaction();
            try {
                // Lock the PO row while validating and recording delivery to avoid duplicate over-receiving.
                if (!poAllowsDelivery($db, $po_id, $u)) {
                    throw new Exception('This purchase order is not open for delivery or is not assigned to your branch.');
                }

                $item = getRemainingQty($db, $po_id, $product_id);
                if (!$item) {
                    throw new Exception('Selected product does not belong to this purchase order.');
                }

                $qty_ordered = (int)$item['quantity_ordered'];
                $remaining_qty = (int)$item['remaining_qty'];
                $already_accepted = getPOAcceptedQty($db, $po_id, $product_id);
                $already_returned = getPOReturnedQty($db, $po_id, $product_id);
                $total_reported_now = $qty_received + $qty_returned;
                // Returned items are expected to be replaced/redelivered later, so they do not reduce the remaining accepted quantity.
                $max_reportable_now = $remaining_qty;

                if ($remaining_qty <= 0) {
                    throw new Exception('This item is already fully delivered.');
                }
                if ($qty_received > $remaining_qty) {
                    throw new Exception('Accepted quantity cannot exceed the remaining quantity. Remaining: ' . $remaining_qty);
                }
                if ($total_reported_now > $max_reportable_now) {
                    throw new Exception('Accepted plus returned quantity cannot exceed the current remaining quantity. Already accepted: ' . $already_accepted . ', ordered: ' . $qty_ordered . ', remaining: ' . $remaining_qty . '.');
                }

                // Save only the actual accepted quantity. Damaged/wrong items are recorded as returns and are NOT added to stock.
                if ($qty_received > 0) {
                    $db->prepare("INSERT INTO supplier_deliveries (po_id,product_id,quantity_ordered,quantity_received,delivery_date,delivery_receipt_no,received_by,remarks,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())")
                       ->execute([$po_id,$product_id,$qty_ordered,$qty_received,$delivery_date,$receipt_no,$u['id'],$remarks]);

                    // Add only accepted items to inventory stock.
                    $db->prepare("UPDATE products SET current_stock = current_stock + ?, updated_at=NOW() WHERE product_id=?")
                       ->execute([$qty_received,$product_id]);

                    // Record stock-in transaction for accepted quantity only.
                    $db->prepare("INSERT INTO stock_transactions (product_id,transaction_type,quantity,reference_no,transaction_date,remarks,recorded_by,created_at) VALUES (?,'Stock-In',?,?,?,?,?,NOW())")
                       ->execute([$product_id,$qty_received,$receipt_no,$delivery_date,"Delivery from PO #" . $po_id . " (accepted $qty_received of remaining $remaining_qty)",$u['id']]);
                }

                if ($qty_returned > 0) {
                    $returnNote = $return_remarks !== '' ? $return_remarks : 'Recorded during delivery receiving.';
                    $db->prepare("INSERT INTO po_returns (po_id,product_id,quantity_returned,return_type,return_date,remarks,recorded_by,replacement_status,replacement_qty,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())")
                       ->execute([$po_id,$product_id,$qty_returned,$return_type,$delivery_date,$returnNote,(int)$u['id'],'Required',$qty_returned]);
                }

                $newStatus = recomputePOStatus($db, $po_id);
                if ($newStatus === 'Fully Delivered') {
                    $supplierStatus = 'Delivered';
                    $db->prepare("UPDATE po_returns SET replacement_status='Received' WHERE po_id=? AND replacement_status IN ('Required','Scheduled')")
                       ->execute([$po_id]);
                } elseif ($qty_returned > 0) {
                    $supplierStatus = 'Replacement Required';
                } else {
                    // Keep scheduled replacement status if the supplier already scheduled a redelivery.
                    $openReplacement = $db->prepare("SELECT replacement_status FROM po_returns WHERE po_id=? AND replacement_status IN ('Required','Scheduled') ORDER BY FIELD(replacement_status,'Required','Scheduled') LIMIT 1");
                    $openReplacement->execute([$po_id]);
                    $replacementRow = $openReplacement->fetch();
                    if ($replacementRow) {
                        $supplierStatus = ($replacementRow['replacement_status'] === 'Scheduled') ? 'Replacement Scheduled' : 'Replacement Required';
                    } else {
                        $supplierStatus = ($newStatus === 'Partially Delivered') ? 'Incomplete Delivery' : 'Pending Confirmation';
                    }
                }
                $db->prepare("UPDATE purchase_orders SET po_status=?, supplier_status=?, updated_at=NOW() WHERE po_id=?")
                   ->execute([$newStatus,$supplierStatus,$po_id]);

                if (!empty($po_id) && $supplierStatus === 'Delivered') { syncSinglePOStatus($db, (int)$po_id); }
                $db->commit();

                $left = max(0, $remaining_qty - $qty_received);
                $parts = [];
                if ($qty_received > 0) { $parts[] = "$qty_received accepted item(s) added to stock"; }
                if ($qty_returned > 0) { $parts[] = "$qty_returned item(s) recorded as $return_type and returned to supplier"; }
                $msg = implode('. ', $parts) . '. Remaining accepted quantity for this item: ' . $left . '.';
                $msgType = $qty_returned > 0 ? 'warning' : 'success';
            } catch (Exception $e) {
                $db->rollBack();
                $msg = 'Error: ' . $e->getMessage(); $msgType = 'danger';
            }
        } else {
            $msg = 'Please select PO/product/date and enter accepted quantity or returned/damaged quantity.'; $msgType = 'danger';
        }
    }

    if ($action === 'record_return_only') {
        $po_id = (int)($_POST['return_po_id'] ?? 0);
        $product_id = (int)($_POST['return_product_id'] ?? 0);
        $qty_returned = max(0, (int)($_POST['return_quantity'] ?? 0));
        $return_type = $_POST['return_type'] ?? 'Damaged';
        $return_date = $_POST['return_date'] ?? date('Y-m-d');
        $return_remarks = trim($_POST['return_remarks'] ?? '');
        $deduct_stock = isset($_POST['deduct_stock']) ? 1 : 0;

        if (!in_array($return_type, ['Damaged','Wrong Item','Other'], true)) {
            $return_type = 'Other';
        }

        if ($po_id && $product_id && $qty_returned > 0 && $return_date) {
            $db->beginTransaction();
            try {
                if (!poAllowsDelivery($db, $po_id, $u)) {
                    // Allow return-only even if PO is already fully delivered, as long as branch is authorized.
                    $poCheckSql = "SELECT po_id, branch FROM purchase_orders WHERE po_id=? AND po_status <> 'Cancelled'";
                    $poParams = [$po_id];
                    if (($u['branch'] ?? '') !== 'Both') { $poCheckSql .= " AND branch=?"; $poParams[] = $u['branch']; }
                    $poCheck = $db->prepare($poCheckSql);
                    $poCheck->execute($poParams);
                    if (!$poCheck->fetch()) {
                        throw new Exception('This purchase order is not available or is not assigned to your branch.');
                    }
                }

                $check = $db->prepare("SELECT COUNT(*) FROM po_items WHERE po_id=? AND product_id=?");
                $check->execute([$po_id,$product_id]);
                if ((int)$check->fetchColumn() <= 0) {
                    throw new Exception('Selected product does not belong to this purchase order.');
                }

                // Prevent unlimited returns. Return-after-delivery is limited to accepted items that have not yet been returned.
                $acceptedQty = getPOAcceptedQty($db, $po_id, $product_id);
                $returnedQty = getPOReturnedQty($db, $po_id, $product_id);
                $maxReturnable = max(0, $acceptedQty - $returnedQty);

                if ($qty_returned > $maxReturnable) {
                    throw new Exception('Return quantity cannot exceed remaining accepted items. Accepted: ' . $acceptedQty . ', already returned: ' . $returnedQty . ', returnable now: ' . $maxReturnable . '.');
                }

                $db->prepare("INSERT INTO po_returns (po_id,product_id,quantity_returned,return_type,return_date,remarks,recorded_by,replacement_status,replacement_qty,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())")
                   ->execute([$po_id,$product_id,$qty_returned,$return_type,$return_date,$return_remarks,(int)$u['id'],'Required',$qty_returned]);

                $db->prepare("UPDATE purchase_orders SET po_status = IF(po_status='Fully Delivered','Partially Delivered',po_status), supplier_status='Replacement Required', updated_at=NOW() WHERE po_id=?")
                   ->execute([$po_id]);

                if ($deduct_stock) {
                    $db->prepare("UPDATE products SET current_stock = GREATEST(current_stock - ?, 0), updated_at=NOW() WHERE product_id=?")
                       ->execute([$qty_returned,$product_id]);
                }

                $db->commit();
                $msg = 'Return/damaged item recorded. Supplier will see the notification with reason.';
                $msgType = 'warning';
            } catch (Exception $e) {
                $db->rollBack();
                $msg = 'Error: ' . $e->getMessage(); $msgType = 'danger';
            }
        } else {
            $msg = 'Please complete PO, product, quantity, and return date.'; $msgType = 'danger';
        }
    }
}

// ── Fetch POs available for delivery ─────────────────────────
// Fully Delivered / Cancelled POs are hidden from the Record Delivery list.
// Their old delivery/return records are still visible in the history tables.
$branch_clause = ($u['branch'] !== 'Both') ? "AND po.branch = ?" : "";
$branch_params = ($u['branch'] !== 'Both') ? [$u['branch']] : [];
$stmt = $db->prepare("SELECT po.po_id, po.po_number, po.branch, po.po_status, s.supplier_name
    FROM purchase_orders po JOIN suppliers s ON po.supplier_id=s.supplier_id
    WHERE po.po_status NOT IN ('Cancelled','Fully Delivered')
      AND EXISTS (
          SELECT 1
          FROM po_items pi
          LEFT JOIN (
              SELECT po_id, product_id, SUM(quantity_received) AS quantity_received_total
              FROM supplier_deliveries
              GROUP BY po_id, product_id
          ) sd ON sd.po_id = pi.po_id AND sd.product_id = pi.product_id
          WHERE pi.po_id = po.po_id
          GROUP BY pi.po_id, pi.product_id
          HAVING SUM(pi.quantity_ordered) > COALESCE(MAX(sd.quantity_received_total),0)
      )
      $branch_clause
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

// Return / damaged history
$stmt = $db->prepare("SELECT r.*, p.product_name, po.po_number, po.po_id, u.full_name AS by_name
    FROM po_returns r
    JOIN products p ON p.product_id = r.product_id
    JOIN purchase_orders po ON po.po_id = r.po_id
    LEFT JOIN users u ON u.user_id = r.recorded_by
    " . ($u['branch']!=='Both' ? "WHERE po.branch=?" : "") . "
    ORDER BY r.created_at DESC, r.return_id DESC LIMIT 30");
$stmt->execute($branch_params);
$returnHistory = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <div><h1>Supplier Deliveries</h1><p>Record accepted deliveries and damaged/wrong items returned to suppliers.</p></div>
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
      <input type="hidden" name="action" value="record_delivery">
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
        <label>Already Accepted / Received</label>
        <input type="number" id="qtyAlreadyReceived" readonly style="background:#f9fafb" value="0">
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label>Remaining Qty</label>
        <input type="number" id="qtyRemaining" readonly style="background:#f9fafb;font-weight:700" value="0">
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label>Qty Accepted Now</label>
        <input type="number" name="quantity_received" id="qtyReceived" min="0" placeholder="Good items to add to stock">
        <small style="color:var(--text-2)">Only this quantity will be added to inventory.</small>
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label>Qty Damaged / Returned</label>
        <input type="number" name="quantity_returned" id="qtyReturned" min="0" value="0" placeholder="Damaged or wrong items">
        <small style="color:var(--text-2)">This will notify the supplier and will not be added to stock.</small>
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label>Return Reason</label>
        <select name="return_type">
          <option value="Damaged">Damaged</option>
          <option value="Wrong Item">Wrong Item</option>
          <option value="Other">Other</option>
        </select>
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label>Return Remarks</label>
        <textarea name="return_remarks" rows="2" placeholder="Describe damage/wrong item if any"></textarea>
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
        <label>Delivery Remarks</label>
        <textarea name="remarks" rows="2"></textarea>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">Record Delivery / Return</button>
    </form>
  </div>
</div>

<!-- History -->
<div style="display:flex;flex-direction:column;gap:20px">
<div class="card">
  <div class="card-header">
    <span class="card-title">Delivery History</span>
    <input type="text" id="tableSearch" placeholder="🔍 Search…" style="width:200px">
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>PO No.</th><th>Product</th><th>Qty Ordered</th><th>Qty Accepted</th><th>Receipt No.</th><th>Status</th><th>Received By</th></tr></thead>
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

<div class="card">
  <div class="card-header">
    <span class="card-title">Damaged / Returned Items</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>PO No.</th><th>Product</th><th>Qty Returned</th><th>Reason</th><th>Replacement</th><th>Remarks</th><th>Recorded By</th></tr></thead>
      <tbody>
      <?php if (empty($returnHistory)): ?>
        <tr><td colspan="8" style="text-align:center;padding:24px;color:var(--text-2)">No damaged or returned items recorded.</td></tr>
      <?php else: foreach ($returnHistory as $r): ?>
        <tr class="low-stock">
          <td><?= date('M d, Y', strtotime($r['return_date'])) ?></td>
          <td><a href="/anais/po_view.php?id=<?= (int)$r['po_id'] ?>" style="color:var(--accent)"><?= htmlspecialchars($r['po_number']) ?></a></td>
          <td><?= htmlspecialchars($r['product_name']) ?></td>
          <td style="font-weight:700;color:var(--danger)"><?= (int)$r['quantity_returned'] ?></td>
          <td><span class="badge badge-warning"><?= htmlspecialchars($r['return_type']) ?></span></td>
          <td>
            <?php $rep = $r['replacement_status'] ?? 'Required'; ?>
            <span class="badge <?= $rep === 'Scheduled' ? 'badge-info' : ($rep === 'Received' ? 'badge-success' : 'badge-warning') ?>"><?= htmlspecialchars($rep) ?></span>
            <?php if (!empty($r['estimated_redelivery_date'])): ?><br><small style="color:var(--text-2)">ETA: <?= date('M d, Y', strtotime($r['estimated_redelivery_date'])) ?></small><?php endif; ?>
          </td>
          <td><?= htmlspecialchars($r['remarks'] ?: '—') ?></td>
          <td><?= htmlspecialchars($r['by_name'] ?? '—') ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
</div>

</div>

<div class="card" style="margin-top:20px">
  <div class="card-header"><span class="card-title">Record Return After Delivery</span></div>
  <div class="card-body">
    <form method="POST" class="form-grid">
      <input type="hidden" name="action" value="record_return_only">
      <div class="form-group">
        <label>Purchase Order *</label>
        <select name="return_po_id" id="returnPoSelect" required onchange="loadReturnPOItems(this.value)">
          <option value="">— Select PO —</option>
          <?php foreach ($openPOs as $po): ?>
            <option value="<?= (int)$po['po_id'] ?>"><?= htmlspecialchars($po['po_number']) ?> — <?= htmlspecialchars($po['supplier_name']) ?> [<?= htmlspecialchars($po['branch']) ?>]</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Product *</label>
        <select name="return_product_id" id="returnProductSelect" required>
          <option value="">— Select PO first —</option>
        </select>
      </div>
      <div class="form-group">
        <label>Qty Returned *</label>
        <input type="number" name="return_quantity" min="1" required>
      </div>
      <div class="form-group">
        <label>Reason</label>
        <select name="return_type"><option>Damaged</option><option>Wrong Item</option><option>Other</option></select>
      </div>
      <div class="form-group">
        <label>Return Date</label>
        <input type="date" name="return_date" value="<?= date('Y-m-d') ?>" required>
      </div>
      <div class="form-group">
        <label style="display:flex;align-items:center;gap:8px;margin-top:24px">
          <input type="checkbox" name="deduct_stock" value="1" style="width:auto"> Deduct from stock
        </label>
        <small style="color:var(--text-2)">Use this only if the item was already added to inventory before returning.</small>
      </div>
      <div class="form-group full">
        <label>Remarks</label>
        <textarea name="return_remarks" rows="2" placeholder="Describe the damage/wrong item and what was returned."></textarea>
      </div>
      <div class="form-group full">
        <button class="btn btn-danger" type="submit">Record Damaged / Returned Item</button>
      </div>
    </form>
  </div>
</div>

<script>
// PO items data passed from PHP. Only items with remaining quantity are listed for delivery form.
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

// All PO items data for return-only form.
const allPOItemsData = <?= json_encode(array_reduce($openPOs, function($acc, $po) use ($db) {
    $stmt = $db->prepare("SELECT pi.product_id, SUM(pi.quantity_ordered) AS quantity_ordered, MAX(p.product_name) AS product_name, MAX(p.unit) AS unit FROM po_items pi JOIN products p ON pi.product_id=p.product_id WHERE pi.po_id=? GROUP BY pi.product_id");
    $stmt->execute([$po['po_id']]);
    $acc[$po['po_id']] = $stmt->fetchAll();
    return $acc;
}, [])) ?>;

function resetQtyFields() {
    document.getElementById('qtyOrdered').value = 0;
    document.getElementById('qtyAlreadyReceived').value = 0;
    document.getElementById('qtyRemaining').value = 0;
    const qtyReceived = document.getElementById('qtyReceived');
    const qtyReturned = document.getElementById('qtyReturned');
    qtyReceived.value = '';
    qtyReturned.value = 0;
    qtyReceived.removeAttribute('max');
    qtyReturned.removeAttribute('max');
}

function loadPOItems(poId) {
    const sel = document.getElementById('productSelect');
    const items = poItemsData[poId] || [];
    sel.innerHTML = '<option value="">— Select Product —</option>';

    if (!items.length && poId) {
        sel.innerHTML = '<option value="">All items are already fully accepted/delivered</option>';
    }

    items.forEach(function(item) {
        const opt = document.createElement('option');
        opt.value = item.product_id;
        opt.dataset.qty = item.quantity_ordered;
        opt.dataset.received = item.quantity_received_total;
        opt.dataset.remaining = item.remaining_qty;
        opt.textContent = item.product_name + ' (Ordered: ' + item.quantity_ordered + ', Accepted: ' + item.quantity_received_total + ', Remaining: ' + item.remaining_qty + ' ' + item.unit + ')';
        sel.appendChild(opt);
    });
    resetQtyFields();
}

function loadReturnPOItems(poId) {
    const sel = document.getElementById('returnProductSelect');
    const items = allPOItemsData[poId] || [];
    sel.innerHTML = '<option value="">— Select Product —</option>';
    items.forEach(function(item) {
        const opt = document.createElement('option');
        opt.value = item.product_id;
        opt.textContent = item.product_name + ' (Ordered: ' + item.quantity_ordered + ' ' + item.unit + ')';
        sel.appendChild(opt);
    });
}

document.getElementById('productSelect').addEventListener('change', function() {
    const sel = this.options[this.selectedIndex];
    const remaining = sel.dataset.remaining || 0;
    document.getElementById('qtyOrdered').value = sel.dataset.qty || 0;
    document.getElementById('qtyAlreadyReceived').value = sel.dataset.received || 0;
    document.getElementById('qtyRemaining').value = remaining;
    const qtyReceived = document.getElementById('qtyReceived');
    const qtyReturned = document.getElementById('qtyReturned');
    qtyReceived.max = remaining;
    qtyReturned.max = remaining;
    qtyReceived.value = '';
    qtyReturned.value = 0;
});

<?php if ($prefillPO): ?>
loadPOItems(<?= $prefillPO ?>);
<?php endif; ?>
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
