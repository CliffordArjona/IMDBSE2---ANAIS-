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
    requireRole(['Supplier']);

    $db = getDB();
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

    if ($action === 'confirm') {
        $db->prepare("UPDATE purchase_orders
            SET supplier_status='Confirmed', updated_at=NOW()
            WHERE po_id=? AND supplier_id=?")
           ->execute([$po_id, $supplier_id]);

        respondJson(true, 'Purchase order confirmed.', [
            'po_status' => $po['po_status'],
            'supplier_status' => 'Confirmed'
        ]);
    }

    if ($action === 'update_status') {
        $allowed = ['Processing', 'Shipped', 'In Transit', 'Delivered'];
        $requested = trim($_POST['supplier_status'] ?? '');

        if (!in_array($requested, $allowed, true)) {
            respondJson(false, 'Invalid supplier status.');
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
