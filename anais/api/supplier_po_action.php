<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/po_status_sync.php';

header('Content-Type: application/json');

function respondJson(bool $ok, string $message, array $extra = []): void {
    echo json_encode(array_merge([
        'ok' => $ok,
        'success' => $ok,
        'message' => $message
    ], $extra));
    exit;
}

try {
    requireLogin();

    function ensureSupplierPriceApiColumns(PDO $db): void {
        try {
            $db->exec("ALTER TABLE purchase_orders MODIFY supplier_status ENUM('Pending Confirmation','Waiting for Supplier Price','Supplier Prices Submitted','Owner Accepted','Owner Rejected','Confirmed','Processing','Shipped','In Transit','Delivered','Incomplete Delivery','Replacement Required','Replacement Scheduled') NOT NULL DEFAULT 'Pending Confirmation'");
        } catch (Throwable $e) {}
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='purchase_orders' AND COLUMN_NAME='price_response_note'");
            $stmt->execute();
            if ((int)$stmt->fetchColumn() === 0) {
                $db->exec("ALTER TABLE purchase_orders ADD COLUMN price_response_note TEXT NULL AFTER payment_remarks");
            }
        } catch (Throwable $e) {}

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
        } catch (Throwable $e) {}

        $returnCols = [
            'replacement_status' => "ALTER TABLE po_returns ADD COLUMN replacement_status ENUM('Required','Scheduled','Received','Cancelled') NOT NULL DEFAULT 'Required' AFTER recorded_by",
            'replacement_qty' => "ALTER TABLE po_returns ADD COLUMN replacement_qty MEDIUMINT UNSIGNED NULL AFTER replacement_status",
            'estimated_redelivery_date' => "ALTER TABLE po_returns ADD COLUMN estimated_redelivery_date DATE NULL AFTER replacement_qty",
            'supplier_redelivery_note' => "ALTER TABLE po_returns ADD COLUMN supplier_redelivery_note TEXT NULL AFTER estimated_redelivery_date",
            'redelivery_scheduled_at' => "ALTER TABLE po_returns ADD COLUMN redelivery_scheduled_at DATETIME NULL AFTER supplier_redelivery_note",
        ];
        foreach ($returnCols as $column => $sql) {
            try {
                $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='po_returns' AND COLUMN_NAME=?");
                $stmt->execute([$column]);
                if ((int)$stmt->fetchColumn() === 0) {
                    $db->exec($sql);
                }
            } catch (Throwable $e) {}
        }
    }

    requireRole(['Supplier']);

    $db = getDB();
    ensureSupplierPriceApiColumns($db);
    $u = currentUser();

    $supplier_id = (int)($u['supplier_id'] ?? 0);
    if ($supplier_id <= 0) {
        respondJson(false, 'Supplier account is not linked to a supplier record.');
    }

    $action = $_POST['action'] ?? '';
    $po_id = (int)($_POST['po_id'] ?? 0);

    if ($po_id <= 0) {
        respondJson(false, 'Invalid purchase order.');
    }

    $stmt = $db->prepare("SELECT po_id, supplier_id, po_status, supplier_status, is_draft
        FROM purchase_orders
        WHERE po_id=? AND supplier_id=?
        LIMIT 1");
    $stmt->execute([$po_id, $supplier_id]);
    $po = $stmt->fetch();

    if (!$po) {
        respondJson(false, 'Purchase order not found for your supplier account.');
    }

    if ((int)$po['is_draft'] === 1) {
        respondJson(false, 'This Purchase Order is still waiting for Owner confirmation.');
    }

    if (($po['po_status'] ?? '') === 'Cancelled') {
        respondJson(false, 'This purchase order is cancelled and cannot be updated.');
    }

    if ($action === 'submit_prices') {
        $unit_costs = $_POST['unit_cost'] ?? [];
        if (!is_array($unit_costs) || empty($unit_costs)) {
            respondJson(false, 'Please enter unit cost for every item.');
        }

        if (!in_array(($po['supplier_status'] ?? ''), ['Waiting for Supplier Price','Owner Rejected','Pending Confirmation'], true)) {
            respondJson(false, 'Prices cannot be changed at the current order status.');
        }

        $stmtItems = $db->prepare("SELECT po_item_id FROM po_items WHERE po_id=?");
        $stmtItems->execute([$po_id]);
        $itemIds = array_map('intval', $stmtItems->fetchAll(PDO::FETCH_COLUMN));
        if (!$itemIds) {
            respondJson(false, 'No PO items found.');
        }

        $db->beginTransaction();
        $update = $db->prepare("UPDATE po_items SET unit_cost=? WHERE po_item_id=? AND po_id=?");
        foreach ($itemIds as $itemId) {
            $cost = isset($unit_costs[$itemId]) ? (float)$unit_costs[$itemId] : 0;
            if ($cost <= 0) {
                $db->rollBack();
                respondJson(false, 'Unit cost must be greater than zero for every item.');
            }
            $update->execute([$cost, $itemId, $po_id]);
        }
        $db->prepare("UPDATE purchase_orders
            SET supplier_status='Supplier Prices Submitted', price_response_note=NULL, updated_at=NOW()
            WHERE po_id=? AND supplier_id=?")
           ->execute([$po_id, $supplier_id]);
        $db->commit();

        respondJson(true, 'Unit costs submitted. Waiting for Owner to accept or reject the order price.', [
            'po_status' => $po['po_status'],
            'supplier_status' => 'Supplier Prices Submitted'
        ]);
    }

    if ($action === 'confirm') {
        respondJson(false, 'Please enter unit costs first, then submit them for Owner approval.');
    }


    if ($action === 'schedule_redelivery') {
        $return_id = (int)($_POST['return_id'] ?? 0);
        $replacement_qty = max(1, (int)($_POST['replacement_qty'] ?? 0));
        $estimated_date = $_POST['estimated_redelivery_date'] ?? '';
        $note = trim($_POST['supplier_redelivery_note'] ?? '');

        if ($return_id <= 0) {
            respondJson(false, 'Invalid returned item.');
        }
        if ($estimated_date === '') {
            respondJson(false, 'Please enter the estimated redelivery date.');
        }

        $retStmt = $db->prepare("SELECT r.*, po.supplier_id, po.po_status
            FROM po_returns r
            JOIN purchase_orders po ON po.po_id = r.po_id
            WHERE r.return_id = ? AND po.supplier_id = ? AND po.po_id = ?
            LIMIT 1");
        $retStmt->execute([$return_id, $supplier_id, $po_id]);
        $ret = $retStmt->fetch();

        if (!$ret) {
            respondJson(false, 'Returned item not found for your supplier account.');
        }
        if (($ret['replacement_status'] ?? 'Required') === 'Received') {
            respondJson(false, 'This replacement was already received by the Owner/OIC.');
        }
        if ($replacement_qty > (int)$ret['quantity_returned']) {
            respondJson(false, 'Replacement quantity cannot exceed the returned quantity. Returned: ' . (int)$ret['quantity_returned']);
        }

        $db->beginTransaction();
        $db->prepare("UPDATE po_returns
            SET replacement_status='Scheduled',
                replacement_qty=?,
                estimated_redelivery_date=?,
                supplier_redelivery_note=?,
                redelivery_scheduled_at=NOW()
            WHERE return_id=?")
           ->execute([$replacement_qty, $estimated_date, $note, $return_id]);

        $db->prepare("UPDATE purchase_orders
            SET po_status = IF(po_status='Fully Delivered','Partially Delivered',po_status),
                supplier_status='Replacement Scheduled',
                updated_at=NOW()
            WHERE po_id=? AND supplier_id=?")
           ->execute([$po_id, $supplier_id]);
        $db->commit();

        respondJson(true, 'Replacement/redelivery scheduled. Owner/OIC can receive it in Deliveries using the same PO.', [
            'po_status' => 'Partially Delivered',
            'supplier_status' => 'Replacement Scheduled'
        ]);
    }

    if ($action === 'update_status') {
        $allowed = ['Processing', 'Shipped', 'In Transit', 'Delivered'];
        $requested = trim($_POST['supplier_status'] ?? '');

        if (!in_array($requested, $allowed, true)) {
            respondJson(false, 'Invalid supplier status.');
        }

        if (!in_array(($po['supplier_status'] ?? ''), ['Owner Accepted','Confirmed','Processing','Shipped','In Transit','Delivered','Incomplete Delivery','Replacement Required','Replacement Scheduled'], true)) {
            respondJson(false, 'Owner has not accepted the supplier prices yet. Please wait before updating delivery status.');
        }

        $totals = getPODeliveryTotals($db, $po_id);

        if ($requested === 'Delivered' && !$totals['complete']) {
            if ($totals['has_delivery']) {
                $db->prepare("UPDATE purchase_orders
                    SET po_status='Partially Delivered', supplier_status='Incomplete Delivery', updated_at=NOW()
                    WHERE po_id=? AND supplier_id=?")
                   ->execute([$po_id, $supplier_id]);

                respondJson(false, 'Cannot mark as Delivered yet. Owner/OIC has only received '.$totals['received'].' of '.$totals['ordered'].'. Remaining: '.$totals['remaining'].'.', [
                    'po_status' => 'Partially Delivered',
                    'supplier_status' => 'Incomplete Delivery'
                ]);
            }

            respondJson(false, 'Cannot mark as Delivered yet. Owner/OIC has not received the full delivery.');
        }

        if ($totals['complete']) {
            $poStatus = 'Fully Delivered';
            $supplierStatus = 'Delivered';
        } elseif ($totals['has_delivery']) {
            $poStatus = 'Partially Delivered';
            $supplierStatus = 'Incomplete Delivery';
        } else {
            $poStatus = 'Pending';
            $supplierStatus = $requested;
        }

        $db->prepare("UPDATE purchase_orders
            SET po_status=?, supplier_status=?, updated_at=NOW()
            WHERE po_id=? AND supplier_id=?")
           ->execute([$poStatus, $supplierStatus, $po_id, $supplier_id]);

        respondJson(true, 'Supplier status updated and synced with Owner Purchase Orders.', [
            'po_status' => $poStatus,
            'supplier_status' => $supplierStatus
        ]);
    }

    respondJson(false, 'Invalid action.');
} catch (Throwable $e) {
    respondJson(false, 'Error: ' . $e->getMessage());
}
