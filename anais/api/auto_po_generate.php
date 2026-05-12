<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');

function respond(bool $ok, string $message, int $status = 200): void {
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message]);
    exit;
}

/**
 * Generate the next available draft PO number without trusting COUNT(*).
 * COUNT(*) can collide after deleted rows, failed attempts, or existing draft numbers.
 */
function nextDraftPONumber(PDO $db, array $reservedNumbers = []): string {
    $prefix = 'DRAFT-PO-' . date('Ymd') . '-';

    $stmt = $db->prepare("SELECT po_number FROM purchase_orders WHERE po_number LIKE ? ORDER BY po_number DESC");
    $stmt->execute([$prefix . '%']);

    $max = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $poNumber = (string)($row['po_number'] ?? '');
        if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $poNumber, $m)) {
            $max = max($max, (int)$m[1]);
        }
    }

    do {
        $max++;
        $candidate = $prefix . str_pad((string)$max, 3, '0', STR_PAD_LEFT);
    } while (in_array($candidate, $reservedNumbers, true));

    return $candidate;
}

try {
    requireLogin();

    if (!isOwner()) {
        respond(false, 'Only Owner can generate automated purchase orders.', 403);
    }

    $db = getDB();
    $u = currentUser();

    // Only generate for low-stock products with active suppliers that are NOT already inside an open PO.
    // This prevents repeated clicks from creating duplicate draft POs for the same low-stock products.
    $stmt = $db->query("SELECT p.*, s.supplier_name
        FROM products p
        JOIN suppliers s ON p.default_supplier_id = s.supplier_id
        WHERE p.status='Active'
          AND s.status='Active'
          AND p.reorder_level > 0
          AND p.current_stock <= p.reorder_level
          AND NOT EXISTS (
              SELECT 1
              FROM po_items pi
              JOIN purchase_orders po ON po.po_id = pi.po_id
              WHERE pi.product_id = p.product_id
                AND po.po_status NOT IN ('Fully Delivered', 'Cancelled')
          )
        ORDER BY p.default_supplier_id, p.branch, p.product_name");
    $items = $stmt->fetchAll();

    if (!$items) {
        respond(false, 'No new low-stock products need a draft PO. Existing low-stock items may already be in an open draft/pending PO.');
    }

    $groups = [];
    foreach ($items as $p) {
        // One draft PO per supplier. If products come from both branches, the PO branch is set to Both.
        $key = (string)$p['default_supplier_id'];
        $groups[$key][] = $p;
    }

    $created = [];
    $db->beginTransaction();

    foreach ($groups as $key => $products) {
        $supplier_id = (int)$key;
        $branches = array_values(array_unique(array_map(fn($x) => $x['branch'], $products)));
        $branch = count($branches) === 1 ? $branches[0] : 'Both';

        $po_id = 0;
        $po_number = '';
        $attempts = 0;

        // Retry protects against duplicate key collisions if a previous number already exists.
        do {
            $attempts++;
            $po_number = nextDraftPONumber($db, $created);

            try {
                $db->prepare("INSERT INTO purchase_orders
                    (po_number, supplier_id, branch, order_date, expected_delivery_date, po_status, is_draft, supplier_status, remarks, created_by, created_at, updated_at)
                    VALUES (?, ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'Pending', 1, 'Pending Confirmation', ?, ?, NOW(), NOW())")
                    ->execute([$po_number, $supplier_id, $branch, 'Auto-generated draft PO from low-stock alert. Grouped as one PO for the same supplier.', $u['id']]);

                $po_id = (int)$db->lastInsertId();
                break;
            } catch (PDOException $e) {
                // 23000 = integrity constraint violation, usually duplicate po_number.
                if (($e->getCode() !== '23000') || $attempts >= 10) {
                    throw $e;
                }
            }
        } while ($attempts < 10);

        if (!$po_id) {
            throw new RuntimeException('Could not create a unique draft PO number.');
        }

        foreach ($products as $p) {
            $qty = max(1, (int)$p['reorder_level'] - (int)$p['current_stock']);
            $db->prepare("INSERT INTO po_items (po_id, product_id, quantity_ordered, unit_cost) VALUES (?, ?, ?, ?)")
               ->execute([$po_id, $p['product_id'], $qty, 0.00]);
        }

        $created[] = $po_number;
    }

    $db->commit();
    respond(true, 'Draft PO(s) generated: ' . implode(', ', $created));
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    respond(false, 'Auto PO failed: ' . $e->getMessage(), 500);
}
