<?php
// includes/po_status_sync.php
// Safe shared PO status sync functions.

if (!function_exists('getPODeliveryTotals')) {
    function getPODeliveryTotals(PDO $db, int $po_id): array {
        $stmt = $db->prepare("
            SELECT
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
            ) x
        ");
        $stmt->execute([$po_id, $po_id]);
        $row = $stmt->fetch() ?: [];

        $ordered = (int)($row['total_ordered'] ?? 0);
        $received = (int)($row['total_received'] ?? 0);
        $remaining = (int)($row['total_remaining'] ?? max($ordered - $received, 0));

        return [
            'ordered' => $ordered,
            'received' => $received,
            'remaining' => $remaining,
            'complete' => $ordered > 0 && $remaining <= 0,
            'has_delivery' => $received > 0,
        ];
    }
}

if (!function_exists('normalizePODeliveryTotals')) {
    function normalizePODeliveryTotals(array $totals): array {
        $ordered = (int)($totals['ordered'] ?? $totals['total_ordered'] ?? 0);
        $received = (int)($totals['received'] ?? $totals['total_received'] ?? 0);
        $remaining = (int)($totals['remaining'] ?? $totals['total_remaining'] ?? max($ordered - $received, 0));

        return [
            'ordered' => $ordered,
            'received' => $received,
            'remaining' => $remaining,
            'complete' => (bool)($totals['complete'] ?? ($ordered > 0 && $remaining <= 0)),
            'has_delivery' => (bool)($totals['has_delivery'] ?? ($received > 0)),
        ];
    }
}


if (!function_exists('getPOOpenReplacementStatus')) {
    function getPOOpenReplacementStatus(PDO $db, int $po_id): ?string {
        try {
            $stmt = $db->prepare("SELECT replacement_status, COUNT(*) AS cnt
                FROM po_returns
                WHERE po_id = ?
                  AND replacement_status IN ('Required','Scheduled')
                GROUP BY replacement_status
                ORDER BY FIELD(replacement_status,'Required','Scheduled')
                LIMIT 1");
            $stmt->execute([$po_id]);
            $row = $stmt->fetch();
            if (!$row) return null;
            return ($row['replacement_status'] === 'Scheduled') ? 'Replacement Scheduled' : 'Replacement Required';
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('syncSinglePOStatus')) {
    function syncSinglePOStatus(PDO $db, int $po_id): array {
        $stmt = $db->prepare("SELECT po_id, po_status, supplier_status FROM purchase_orders WHERE po_id=? LIMIT 1");
        $stmt->execute([$po_id]);
        $po = $stmt->fetch();

        if (!$po) {
            return ['ok' => false, 'message' => 'Purchase order not found.'];
        }

        $totals = normalizePODeliveryTotals(getPODeliveryTotals($db, $po_id));

        if (($po['po_status'] ?? '') === 'Cancelled') {
            return [
                'ok' => true,
                'po_status' => 'Cancelled',
                'supplier_status' => $po['supplier_status'] ?? 'Pending Confirmation',
                'totals' => $totals,
            ];
        }

        $replacementStatus = getPOOpenReplacementStatus($db, $po_id);

        if ($totals['complete']) {
            $poStatus = 'Fully Delivered';
            $supplierStatus = 'Delivered';
            try {
                $db->prepare("UPDATE po_returns SET replacement_status='Received' WHERE po_id=? AND replacement_status IN ('Required','Scheduled')")
                   ->execute([$po_id]);
            } catch (Throwable $e) {}
        } elseif ($replacementStatus) {
            $poStatus = $totals['has_delivery'] ? 'Partially Delivered' : 'Pending';
            $supplierStatus = $replacementStatus;
        } elseif ($totals['has_delivery']) {
            $poStatus = 'Partially Delivered';
            $supplierStatus = 'Incomplete Delivery';
        } else {
            $poStatus = 'Pending';
            $supplierStatus = $po['supplier_status'] ?: 'Pending Confirmation';
        }

        $db->prepare("UPDATE purchase_orders
            SET po_status=?, supplier_status=?, updated_at=NOW()
            WHERE po_id=?")
           ->execute([$poStatus, $supplierStatus, $po_id]);

        return [
            'ok' => true,
            'po_status' => $poStatus,
            'supplier_status' => $supplierStatus,
            'totals' => $totals,
        ];
    }
}

if (!function_exists('syncAllOpenPOStatuses')) {
    function syncAllOpenPOStatuses(PDO $db, ?int $supplier_id = null): void {
        $sql = "SELECT po_id FROM purchase_orders WHERE po_status <> 'Cancelled'";
        $params = [];

        if ($supplier_id) {
            $sql .= " AND supplier_id = ?";
            $params[] = $supplier_id;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        while ($row = $stmt->fetch()) {
            syncSinglePOStatus($db, (int)$row['po_id']);
        }
    }
}

if (!function_exists('poStatusBadge')) {
    function poStatusBadge(string $status): string {
        return match($status) {
            'Fully Delivered'     => '<span class="badge badge-success">Fully Delivered</span>',
            'Partially Delivered' => '<span class="badge badge-warning">Partially Delivered</span>',
            'Incomplete Delivery' => '<span class="badge badge-warning">Incomplete Delivery</span>',
            'Cancelled'           => '<span class="badge badge-gray">Cancelled</span>',
            'Pending'             => '<span class="badge badge-warning">Pending</span>',
            default               => '<span class="badge badge-info">'.htmlspecialchars($status).'</span>',
        };
    }
}

if (!function_exists('supplierStatusBadge')) {
    function supplierStatusBadge(?string $status): string {
        $status = $status ?: 'Pending Confirmation';

        return match($status) {
            'Pending Confirmation'     => '<span class="badge badge-warning">Pending Confirmation</span>',
            'Waiting for Supplier Price' => '<span class="badge badge-warning">Waiting for Supplier Price</span>',
            'Supplier Prices Submitted'  => '<span class="badge badge-info">Supplier Prices Submitted</span>',
            'Owner Accepted'             => '<span class="badge badge-success">Owner Accepted</span>',
            'Owner Rejected'             => '<span class="badge badge-danger">Owner Rejected</span>',
            'Confirmed'                  => '<span class="badge badge-info">Confirmed</span>',
            'Processing'           => '<span class="badge badge-info">Processing</span>',
            'Shipped'              => '<span class="badge badge-info">Shipped</span>',
            'In Transit'           => '<span class="badge badge-info">In Transit</span>',
            'Delivered'            => '<span class="badge badge-success">Delivered</span>',
            'Incomplete Delivery'  => '<span class="badge badge-warning">Incomplete Delivery</span>',
            'Replacement Required' => '<span class="badge badge-danger">Replacement Required</span>',
            'Replacement Scheduled' => '<span class="badge badge-info">Replacement Scheduled</span>',
            default                => '<span class="badge badge-gray">'.htmlspecialchars($status).'</span>',
        };
    }
}
