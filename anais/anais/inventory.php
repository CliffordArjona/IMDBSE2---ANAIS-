<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pageTitle = 'Inventory';
$activePage = 'inventory';
$db = getDB();
$u  = currentUser();
$msg = ''; $msgType = '';

// ── Stock movement helpers ─────────────────────────────────
function invTableColumnExists(PDO $db, string $table, string $column): bool {
    try {
        $stmt = $db->prepare("SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function invEnsureStockOutDiscountColumns(PDO $db): bool {
    $columns = [
        'discount_type'   => "ALTER TABLE stock_transactions ADD COLUMN discount_type ENUM('amount','percent') NOT NULL DEFAULT 'amount' AFTER transaction_date",
        'discount_value'  => "ALTER TABLE stock_transactions ADD COLUMN discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_type",
        'discount_amount' => "ALTER TABLE stock_transactions ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_value",
        'total_amount'    => "ALTER TABLE stock_transactions ADD COLUMN total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_amount",
    ];

    foreach ($columns as $column => $sql) {
        if (!invTableColumnExists($db, 'stock_transactions', $column)) {
            try { $db->exec($sql); } catch (Throwable $e) { /* handled by final column check */ }
        }
    }

    return invTableColumnExists($db, 'stock_transactions', 'discount_type')
        && invTableColumnExists($db, 'stock_transactions', 'discount_value')
        && invTableColumnExists($db, 'stock_transactions', 'discount_amount')
        && invTableColumnExists($db, 'stock_transactions', 'total_amount');
}


function invEnsureSRSExtraColumns(PDO $db): void {
    $columns = [
        ['products','barcode', "ALTER TABLE products ADD COLUMN barcode VARCHAR(100) NULL AFTER sku"],
        ['products','warranty_months', "ALTER TABLE products ADD COLUMN warranty_months SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER unit_price"],
        ['stock_transactions','warranty_until', "ALTER TABLE stock_transactions ADD COLUMN warranty_until DATE NULL AFTER transaction_date"],
        ['stock_transactions','customer_id_no', "ALTER TABLE stock_transactions ADD COLUMN customer_id_no VARCHAR(80) NULL AFTER reference_no"],
    ];
    foreach ($columns as [$table, $column, $sql]) {
        if (!invTableColumnExists($db, $table, $column)) {
            try { $db->exec($sql); } catch (Throwable $e) { /* ignore if already exists */ }
        }
    }
}

function invGetProductForMovement(PDO $db, int $product_id, array $u): array|false {
    $stmt = $db->prepare("SELECT product_id, product_name, branch, current_stock, unit, unit_price FROM products WHERE product_id=? AND status='Active' LIMIT 1");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();

    if (!$product) {
        return false;
    }

    if (($u['branch'] ?? '') !== 'Both' && ($product['branch'] ?? '') !== ($u['branch'] ?? '')) {
        return false;
    }

    return $product;
}

$hasDiscountCols = invEnsureStockOutDiscountColumns($db);
invEnsureSRSExtraColumns($db);

// ── CRUD Actions ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole(['Owner','OIC']);
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $pid  = (int)($_POST['product_id'] ?? 0);
        $data = [
            'sku'           => trim($_POST['sku']),
            'barcode'       => trim($_POST['barcode'] ?? ''),
            'product_name'  => trim($_POST['product_name']),
            'category'      => trim($_POST['category']),
            'brand'         => trim($_POST['brand']),
            'initial_stock' => max(0, (int)($_POST['initial_stock'] ?? 0)),
            'unit'          => trim($_POST['unit'] ?? ''),
            'branch'        => $_POST['branch'],
            'reorder_level' => (int)$_POST['reorder_level'],
            'unit_price'    => (float)$_POST['unit_price'],
            'warranty_months' => max(0, (int)($_POST['warranty_months'] ?? 0)),
            'default_supplier_id' => ($_POST['default_supplier_id'] ?? '') !== '' ? (int)$_POST['default_supplier_id'] : null,
            'status'        => 'Active', // Product status field removed from UI; products are active by default.
        ];
        // Duplicate validation:
        // 1. SKU must be unique.
        // 2. Same Product Name + Brand + Branch + Unit is treated as a possible duplicate.
        $dupSkuStmt = $db->prepare("SELECT product_id FROM products WHERE sku = ? AND product_id <> ? LIMIT 1");
        $dupSkuStmt->execute([$data['sku'], $pid]);

        $dupProductStmt = $db->prepare("SELECT product_id, sku FROM products
            WHERE LOWER(product_name) = LOWER(?)
              AND LOWER(brand) = LOWER(?)
              AND branch = ?
              AND unit = ?
              AND product_id <> ?
              AND status = 'Active'
            LIMIT 1");
        $dupProductStmt->execute([
            $data['product_name'],
            $data['brand'],
            $data['branch'],
            $data['unit'],
            $pid
        ]);
        $possibleDuplicate = $dupProductStmt->fetch();

        if ($dupSkuStmt->fetch()) {
            $msg = 'SKU already exists. Please use a different SKU.'; $msgType = 'danger';
        } elseif ($possibleDuplicate) {
            $msg = 'Possible duplicate product found. Same product name, brand, branch, and unit already exists. Existing SKU: ' . $possibleDuplicate['sku']; $msgType = 'danger';
        } elseif ($pid) {
            // Edit
            $db->prepare("UPDATE products SET sku=?,barcode=?,product_name=?,category=?,brand=?,unit=?,branch=?,reorder_level=?,unit_price=?,warranty_months=?,default_supplier_id=?,status=?,updated_at=NOW() WHERE product_id=?")
               ->execute([
                    $data['sku'],
                    $data['barcode'],
                    $data['product_name'],
                    $data['category'],
                    $data['brand'],
                    $data['unit'],
                    $data['branch'],
                    $data['reorder_level'],
                    $data['unit_price'],
                    $data['warranty_months'],
                    $data['default_supplier_id'],
                    $data['status'],
                    $pid
                ]);
            $msg = 'Product updated successfully.'; $msgType = 'success';
        } else {
            // Add
            $data['current_stock'] = $data['initial_stock'];
            $data['created_by']    = $u['id'];
            $db->prepare("INSERT INTO products (sku,barcode,product_name,category,brand,unit,branch,reorder_level,unit_price,warranty_months,default_supplier_id,status,current_stock,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
               ->execute([$data['sku'],$data['barcode'],$data['product_name'],$data['category'],$data['brand'],$data['unit'],$data['branch'],$data['reorder_level'],$data['unit_price'],$data['warranty_months'],$data['default_supplier_id'],$data['status'],$data['current_stock'],$u['id']]);

            $newProductId = (int)$db->lastInsertId();
            if ($data['current_stock'] > 0) {
                $db->prepare("INSERT INTO stock_transactions (product_id,transaction_type,quantity,reference_no,transaction_date,remarks,recorded_by,created_at) VALUES (?,'Stock-In',?,?,CURDATE(),?,?,NOW())")
                   ->execute([$newProductId,$data['current_stock'],'INITIAL-STOCK','Initial stock from Add Product form',$u['id']]);
            }

            $msg = 'Product added successfully.'; $msgType = 'success';
        }
    }

    if ($action === 'stock_in') {
        $product_id       = (int)($_POST['product_id'] ?? 0);
        $quantity         = (int)($_POST['quantity'] ?? 0);
        $reference_no     = trim($_POST['reference_no'] ?? '');
        $transaction_date = $_POST['transaction_date'] ?? '';
        $remarks          = trim($_POST['remarks'] ?? '');
        $warranty_until   = $_POST['warranty_until'] ?? null;

        $product = invGetProductForMovement($db, $product_id, $u);

        if (!$product) {
            $msg = 'Product not found or you are not allowed to update this branch.'; $msgType = 'danger';
        } elseif ($quantity <= 0 || $transaction_date === '') {
            $msg = 'Please enter a valid product, quantity, and transaction date.'; $msgType = 'danger';
        } else {
            try {
                $db->beginTransaction();
                $db->prepare("INSERT INTO stock_transactions
                    (product_id, transaction_type, quantity, reference_no, transaction_date, warranty_until, remarks, recorded_by, created_at)
                    VALUES (?, 'Stock-In', ?, ?, ?, ?, ?, ?, NOW())")
                   ->execute([$product_id, $quantity, $reference_no, $transaction_date, $warranty_until ?: null, $remarks, $u['id']]);

                $db->prepare("UPDATE products SET current_stock = current_stock + ?, updated_at=NOW() WHERE product_id=?")
                   ->execute([$quantity, $product_id]);

                $db->commit();
                $msg = 'Stock-In recorded successfully. '.$quantity.' '.($product['unit'] ?? 'unit(s)').' added to '.$product['product_name'].'.';
                $msgType = 'success';
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                $msg = 'Error recording Stock-In: '.$e->getMessage(); $msgType = 'danger';
            }
        }
    }

    if ($action === 'stock_out') {
        $product_id       = (int)($_POST['product_id'] ?? 0);
        $quantity         = (int)($_POST['quantity'] ?? 0);
        $reference_no     = trim($_POST['reference_no'] ?? '');
        $customer_id_no   = trim($_POST['customer_id_no'] ?? '');
        $transaction_date = $_POST['transaction_date'] ?? '';
        $discount_type    = $_POST['discount_type'] ?? 'amount';
        $discount_value   = max(0, (float)($_POST['discount_value'] ?? 0));
        $remarks          = trim($_POST['remarks'] ?? '');

        if (!in_array($discount_type, ['amount','percent'], true)) {
            $discount_type = 'amount';
        }

        $product = invGetProductForMovement($db, $product_id, $u);

        if (!$product) {
            $msg = 'Product not found or you are not allowed to update this branch.'; $msgType = 'danger';
        } elseif ($quantity <= 0 || $transaction_date === '') {
            $msg = 'Please enter a valid product, quantity, and transaction date.'; $msgType = 'danger';
        } elseif ((int)$product['current_stock'] < $quantity) {
            $msg = 'Insufficient stock. Available: '.(int)$product['current_stock']; $msgType = 'danger';
        } elseif ($discount_type === 'percent' && $discount_value > 100) {
            $msg = 'Percent discount cannot be greater than 100%.'; $msgType = 'danger';
        } else {
            $unit_price = (float)($product['unit_price'] ?? 0);
            $gross_total = $unit_price * $quantity;
            $discount_amount = ($discount_type === 'percent')
                ? ($gross_total * ($discount_value / 100))
                : $discount_value;

            if ($discount_amount > $gross_total) {
                $msg = 'Discount cannot be greater than the gross total.'; $msgType = 'danger';
            } else {
                $total_amount = max(0, $gross_total - $discount_amount);

                try {
                    $db->beginTransaction();

                    if ($hasDiscountCols) {
                        $db->prepare("INSERT INTO stock_transactions
                            (product_id, transaction_type, quantity, reference_no, customer_id_no, transaction_date, discount_type, discount_value, discount_amount, total_amount, remarks, recorded_by, created_at)
                            VALUES (?, 'Stock-Out', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                           ->execute([$product_id, $quantity, $reference_no, $customer_id_no, $transaction_date, $discount_type, $discount_value, $discount_amount, $total_amount, $remarks, $u['id']]);
                    } else {
                        $fallbackRemarks = trim($remarks . " | Gross: ₱" . number_format($gross_total, 2) . " | Discount: " . ($discount_type === 'percent' ? $discount_value . '%' : '₱' . number_format($discount_value, 2)) . " | Discount Amount: ₱" . number_format($discount_amount, 2) . " | Total: ₱" . number_format($total_amount, 2));
                        $db->prepare("INSERT INTO stock_transactions
                            (product_id, transaction_type, quantity, reference_no, transaction_date, remarks, recorded_by, created_at)
                            VALUES (?, 'Stock-Out', ?, ?, ?, ?, ?, NOW())")
                           ->execute([$product_id, $quantity, $reference_no, $transaction_date, $fallbackRemarks, $u['id']]);
                    }

                    $db->prepare("UPDATE products SET current_stock = current_stock - ?, updated_at=NOW() WHERE product_id=?")
                       ->execute([$quantity, $product_id]);

                    $db->commit();
                    $msg = 'Stock-Out recorded for '.$product['product_name'].'. Total: ₱'.number_format($total_amount, 2);
                    $msgType = 'success';
                } catch (Throwable $e) {
                    if ($db->inTransaction()) { $db->rollBack(); }
                    $msg = 'Error recording Stock-Out: '.$e->getMessage(); $msgType = 'danger';
                }
            }
        }
    }

    if ($action === 'delete') {
        requireRole(['Owner']);
        $pid = (int)$_POST['product_id'];
        try {
            $db->prepare("DELETE FROM products WHERE product_id=?")->execute([$pid]);
            $msg = 'Product permanently deleted.'; $msgType = 'success';
        } catch (PDOException $e) {
            $db->prepare("UPDATE products SET status='Inactive' WHERE product_id=?")->execute([$pid]);
            $msg = 'Product has linked transactions, so it was deactivated instead.'; $msgType = 'warning';
        }
    }
}

// ── Fetch Products ─────────────────────────────────────────
$filterBranch = $_GET['branch'] ?? ($u['branch'] !== 'Both' ? $u['branch'] : '');
$filterLow    = $_GET['filter'] ?? '';
$filterCategory = trim($_GET['category'] ?? '');
$filterBrand    = trim($_GET['brand'] ?? '');
$params = [];
$sql  = "SELECT p.*, s.supplier_name FROM products p LEFT JOIN suppliers s ON p.default_supplier_id=s.supplier_id WHERE p.status='Active'";
if ($filterBranch) { $sql .= " AND p.branch=?"; $params[] = $filterBranch; }
if ($filterLow === 'low') { $sql .= " AND p.current_stock <= p.reorder_level AND reorder_level > 0"; }
if ($filterCategory !== '') { $sql .= " AND p.category=?"; $params[] = $filterCategory; }
if ($filterBrand !== '') { $sql .= " AND p.brand=?"; $params[] = $filterBrand; }
$sql .= " ORDER BY p.product_name ASC";
$stmt = $db->prepare($sql); $stmt->execute($params);
$products = $stmt->fetchAll();
$suppliers = $db->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status='Active' ORDER BY supplier_name ASC")->fetchAll();
$categories = $db->query("SELECT DISTINCT category FROM products WHERE status='Active' AND category IS NOT NULL AND category <> '' ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);
$brands = $db->query("SELECT DISTINCT brand FROM products WHERE status='Active' AND brand IS NOT NULL AND brand <> '' ORDER BY brand ASC")->fetchAll(PDO::FETCH_COLUMN);

$movementBranchClause = ($u['branch'] !== 'Both') ? "AND branch = ?" : "";
$movementBranchParams = ($u['branch'] !== 'Both') ? [$u['branch']] : [];
$stmt = $db->prepare("SELECT product_id, sku, product_name, branch, current_stock, unit, unit_price
    FROM products
    WHERE status='Active' $movementBranchClause
    ORDER BY product_name ASC");
$stmt->execute($movementBranchParams);
$movementProducts = $stmt->fetchAll();

$stmt = $db->prepare("SELECT st.*, p.product_name, p.sku, p.branch, p.unit, u.full_name as by_name
    FROM stock_transactions st
    JOIN products p ON st.product_id = p.product_id
    LEFT JOIN users u ON st.recorded_by = u.user_id
    WHERE st.transaction_type IN ('Stock-In','Stock-Out') " . ($u['branch'] !== 'Both' ? "AND p.branch=?" : "") . "
    ORDER BY st.created_at DESC
    LIMIT 20");
$stmt->execute($movementBranchParams);
$recentMovements = $stmt->fetchAll();


function inventoryFilterQuery(array $overrides = []): string {
    $query = [
        'branch' => $GLOBALS['filterBranch'] ?? '',
        'filter' => $GLOBALS['filterLow'] ?? '',
        'category' => $GLOBALS['filterCategory'] ?? '',
        'brand' => $GLOBALS['filterBrand'] ?? '',
    ];

    foreach ($overrides as $key => $value) {
        $query[$key] = $value;
    }

    $query = array_filter($query, fn($v) => $v !== '' && $v !== null);
    return $query ? ('?' . http_build_query($query)) : '';
}

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <div><h1>Inventory</h1><p>Manage all product records.</p></div>
  <?php if (canEdit()): ?>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <button class="btn btn-primary" onclick="openModal('productModal')">+ Add Product</button>
    <button class="btn btn-outline" onclick="openStockInModal()">⬇️ Stock-In</button>
    <button class="btn btn-outline" onclick="openStockOutModal()">⬆️ Stock-Out</button>
  </div>
  <?php endif; ?>
</div>

<?php if ($msg): ?>
  <div class="alert alert-<?= $msgType ?>" data-auto-dismiss><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- Filters -->
<div class="card" style="margin-bottom:16px">
  <div class="card-body" style="padding:14px 20px">
    <div class="filter-bar" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <input type="text" id="tableSearch" placeholder="🔍 Search products…" style="min-width:220px">

      <?php if ($u['branch'] === 'Both'): ?>
      <select onchange="location.href='/anais/inventory.php'+inventoryBuildFilterUrl({branch:this.value})">
        <option value="">All Branches</option>
        <option value="Autobox" <?= $filterBranch==='Autobox'?'selected':'' ?>>Autobox</option>
        <option value="Autophoria" <?= $filterBranch==='Autophoria'?'selected':'' ?>>Autophoria</option>
      </select>
      <?php endif; ?>

      <select onchange="location.href='/anais/inventory.php'+inventoryBuildFilterUrl({category:this.value})">
        <option value="">All Categories</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= htmlspecialchars($cat) ?>" <?= $filterCategory===$cat?'selected':'' ?>>
            <?= htmlspecialchars($cat) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select onchange="location.href='/anais/inventory.php'+inventoryBuildFilterUrl({brand:this.value})">
        <option value="">All Brands</option>
        <?php foreach ($brands as $brand): ?>
          <option value="<?= htmlspecialchars($brand) ?>" <?= $filterBrand===$brand?'selected':'' ?>>
            <?= htmlspecialchars($brand) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <a href="/anais/inventory.php<?= inventoryFilterQuery(['filter'=>'low']) ?>" class="btn btn-outline btn-sm <?= $filterLow==='low'?'active':''; ?>">⚠️ Low Stock</a>
      <a href="/anais/inventory.php<?= inventoryFilterQuery(['filter'=>'']) ?>" class="btn btn-outline btn-sm">All</a>
      <a href="/anais/inventory.php" class="btn btn-outline btn-sm">Reset</a>
    </div>
  </div>
</div>

<script>
function inventoryBuildFilterUrl(changes) {
  const params = new URLSearchParams(window.location.search);

  Object.keys(changes).forEach(function(key) {
    if (changes[key]) {
      params.set(key, changes[key]);
    } else {
      params.delete(key);
    }
  });

  const qs = params.toString();
  return qs ? ('?' + qs) : '';
}
</script>

<div class="card">
  <div class="card-header">
    <span class="card-title">Products (<?= count($products) ?>)</span>
    <?php if ($filterLow || $filterCategory || $filterBrand || $filterBranch): ?>
      <small style="color:var(--text-2)">
        Filtered by:
        <?= $filterBranch ? 'Branch: '.htmlspecialchars($filterBranch).' ' : '' ?>
        <?= $filterCategory ? 'Category: '.htmlspecialchars($filterCategory).' ' : '' ?>
        <?= $filterBrand ? 'Brand: '.htmlspecialchars($filterBrand).' ' : '' ?>
        <?= $filterLow === 'low' ? 'Low Stock' : '' ?>
      </small>
    <?php endif; ?>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>#</th><th>SKU</th><th>Barcode / QR Code</th><th>Product Name</th><th>Category</th><th>Brand</th><th>Unit</th>
        <th>Branch</th><th>Stock</th><th>Reorder</th><th>Unit Price</th><th>Supplier</th>
        <?php if (canEdit()): ?><th>Actions</th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php if (empty($products)): ?>
        <tr><td colspan="12" style="text-align:center;padding:32px;color:var(--text-2)">No products found.</td></tr>
      <?php else: foreach ($products as $i => $p):
        $isLow = $p['reorder_level'] > 0 && $p['current_stock'] <= $p['reorder_level'];
      ?>
        <tr data-branch="<?= $p['branch'] ?>" class="<?= $isLow ? 'low-stock' : '' ?>">
          <td><?= $i+1 ?></td>
          <td><?= htmlspecialchars($p['sku']) ?></td>
          <td><?= htmlspecialchars($p['barcode'] ?? '—') ?></td>
          <td><strong><?= htmlspecialchars($p['product_name']) ?></strong><?php if((int)($p['warranty_months'] ?? 0)>0): ?><br><small style="color:var(--text-2)">Warranty: <?= (int)$p['warranty_months'] ?> month(s)</small><?php endif; ?></td>
          <td><?= htmlspecialchars($p['category']) ?></td>
          <td><?= htmlspecialchars($p['brand'] ?: '—') ?></td>
          <td><?= htmlspecialchars($p['unit']) ?></td>
          <td><span class="badge badge-info"><?= $p['branch'] ?></span></td>
          <td>
            <span style="font-weight:700;color:<?= $isLow?'var(--danger)':'inherit' ?>"><?= $p['current_stock'] ?></span>
            <?php if ($isLow): ?><span class="badge badge-warning" style="margin-left:4px">Low</span><?php endif; ?>
          </td>
          <td><?= $p['reorder_level'] ?></td>
          <td>₱<?= number_format($p['unit_price'], 2) ?></td>
          <td><?= htmlspecialchars($p['supplier_name'] ?? '—') ?></td>
          <?php if (canEdit()): ?>
          <td>
            <button type="button" class="btn btn-outline btn-sm" onclick="openStockInModal(<?= (int)$p['product_id'] ?>)">⬇️ In</button>
            <button type="button" class="btn btn-outline btn-sm" onclick="openStockOutModal(<?= (int)$p['product_id'] ?>)">⬆️ Out</button>
            <button class="btn btn-outline btn-sm"
              data-modal="productModal"
              data-edit='<?= json_encode(['product_id'=>$p['product_id'],'sku'=>$p['sku'],'barcode'=>$p['barcode'] ?? '','warranty_months'=>$p['warranty_months'] ?? 0,'product_name'=>$p['product_name'],'category'=>$p['category'],'brand'=>$p['brand'],'unit'=>$p['unit'],'branch'=>$p['branch'],'reorder_level'=>$p['reorder_level'],'unit_price'=>$p['unit_price'],'default_supplier_id'=>$p['default_supplier_id']]) ?>'>
              ✏️ Edit
            </button>
            <?php if (isOwner()): ?>
            <form method="POST" id="del<?= $p['product_id'] ?>" style="display:inline">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="product_id" value="<?= $p['product_id'] ?>">
              <button type="button" class="btn btn-danger btn-sm" onclick="confirmDelete('del<?= $p['product_id'] ?>','Delete this product record? If it has transactions, the system will deactivate it instead.')">🗑</button>
            </form>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (canEdit()): ?>

<div class="card" style="margin-top:16px">
  <div class="card-header">
    <span class="card-title">Recent Stock-In / Stock-Out Transactions</span>
    <small style="color:var(--text-2)">Latest movements are now managed inside Inventory.</small>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Date</th><th>Type</th><th>Product</th><th>Branch</th><th>Qty</th><th>Reference</th><th>Recorded By</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$recentMovements): ?>
        <tr><td colspan="7" style="text-align:center;padding:26px;color:var(--text-2)">No stock movement records yet.</td></tr>
      <?php else: foreach ($recentMovements as $tx): ?>
        <tr>
          <td><?= htmlspecialchars(date('M d, Y', strtotime($tx['transaction_date']))) ?></td>
          <td>
            <span class="badge <?= $tx['transaction_type']==='Stock-In' ? 'badge-success' : 'badge-warning' ?>">
              <?= htmlspecialchars($tx['transaction_type']) ?>
            </span>
          </td>
          <td><strong><?= htmlspecialchars($tx['product_name']) ?></strong><br><small style="color:var(--text-2)"><?= htmlspecialchars($tx['sku'] ?? '') ?></small></td>
          <td><span class="badge badge-info"><?= htmlspecialchars($tx['branch']) ?></span></td>
          <td><?= (int)$tx['quantity'] ?> <?= htmlspecialchars($tx['unit'] ?? '') ?></td>
          <td><?= htmlspecialchars($tx['reference_no'] ?: '—') ?></td>
          <td><?= htmlspecialchars($tx['by_name'] ?? '—') ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Unit Guide Panel - hidden by default -->
<div id="unitGuidePanel" class="unit-guide-panel hidden">
  <div class="unit-guide-card">
    <div class="unit-guide-header">
      <div>
        <strong>Unit Guide</strong>
        <small>Reference for car parts and accessories units.</small>
      </div>
      <button type="button" class="modal-close" onclick="hideUnitGuide()">×</button>
    </div>

    <div class="unit-guide-grid">
      <div><strong>pcs</strong><span>Single item like filter, bulb, plug, wiper</span></div>
      <div><strong>pair</strong><span>Two items sold together like mirrors, shocks, bulbs</span></div>
      <div><strong>set</strong><span>Complete group like matting set, seat cover set</span></div>
      <div><strong>kit</strong><span>Repair kit, cleaning kit, installation kit</span></div>
      <div><strong>box</strong><span>Boxed quantity of parts/items</span></div>
      <div><strong>pack</strong><span>Packaged small items</span></div>
      <div><strong>roll</strong><span>Tint, wire, tape, rubber roll</span></div>
      <div><strong>meter</strong><span>Wire, hose, trim sold by length</span></div>
      <div><strong>liter</strong><span>Oil, coolant, brake fluid, other fluids</span></div>
      <div><strong>gallon</strong><span>Large oil/coolant/chemical volume</span></div>
      <div><strong>bottle</strong><span>Wax, polish, cleaner, additive</span></div>
      <div><strong>can</strong><span>Spray paint, lubricant, air freshener</span></div>
      <div><strong>tube</strong><span>Grease, sealant, adhesive</span></div>
      <div><strong>sachet</strong><span>Small wax, cleaner, additive pack</span></div>
      <div><strong>drum</strong><span>Bulk oil or chemical storage</span></div>
      <div><strong>kg/gram</strong><span>Items measured by weight</span></div>
    </div>
  </div>
</div>

<!-- Stock-In Modal -->
<div class="modal-backdrop hidden" id="stockInModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">Stock-In Entry</span>
      <button class="modal-close" onclick="closeModal('stockInModal')">×</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="stock_in">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group full">
            <label>Product *</label>
            <select name="product_id" id="stockInProduct" required>
              <option value="">— Select Product —</option>
              <?php foreach ($movementProducts as $p): ?>
                <option value="<?= (int)$p['product_id'] ?>">
                  <?= htmlspecialchars($p['product_name']) ?> — <?= htmlspecialchars($p['branch']) ?> | Stock: <?= (int)$p['current_stock'] ?> <?= htmlspecialchars($p['unit']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Quantity In *</label>
            <input type="number" name="quantity" min="1" required>
          </div>
          <div class="form-group">
            <label>Transaction Date *</label>
            <input type="date" name="transaction_date" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="form-group">
            <label>Warranty Until</label>
            <input type="date" name="warranty_until">
          </div>
          <div class="form-group full">
            <label>Reference No.</label>
            <input type="text" name="reference_no" placeholder="Delivery receipt, invoice, or reference no.">
          </div>
          <div class="form-group full">
            <label>Remarks</label>
            <textarea name="remarks" placeholder="Optional notes"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('stockInModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Stock-In</button>
      </div>
    </form>
  </div>
</div>

<!-- Stock-Out Modal -->
<div class="modal-backdrop hidden" id="stockOutModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">Stock-Out Entry</span>
      <button class="modal-close" onclick="closeModal('stockOutModal')">×</button>
    </div>
    <form method="POST" id="inventoryStockOutForm">
      <input type="hidden" name="action" value="stock_out">
      <div class="modal-body">
        <?php if (!$hasDiscountCols): ?>
          <div class="alert alert-warning">Discount columns are not detected. The system will save discount details in remarks.</div>
        <?php endif; ?>
        <div class="form-grid">
          <div class="form-group full">
            <label>Product *</label>
            <select name="product_id" id="stockOutProduct" required>
              <option value="">— Select Product —</option>
              <?php foreach ($movementProducts as $p): ?>
                <option value="<?= (int)$p['product_id'] ?>" data-price="<?= htmlspecialchars((string)$p['unit_price']) ?>" data-stock="<?= (int)$p['current_stock'] ?>">
                  <?= htmlspecialchars($p['product_name']) ?> — <?= htmlspecialchars($p['branch']) ?> | Stock: <?= (int)$p['current_stock'] ?> <?= htmlspecialchars($p['unit']) ?> | ₱<?= number_format((float)$p['unit_price'], 2) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Quantity Out *</label>
            <input type="number" name="quantity" id="stockOutQty" min="1" required>
          </div>
          <div class="form-group">
            <label>Transaction Date *</label>
            <input type="date" name="transaction_date" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="form-group">
            <label>Discount Type</label>
            <select name="discount_type" id="stockOutDiscountType">
              <option value="amount">Amount (₱)</option>
              <option value="percent">Percent (%)</option>
            </select>
          </div>
          <div class="form-group">
            <label>Discount Value</label>
            <input type="number" name="discount_value" id="stockOutDiscountValue" min="0" step="0.01" value="0">
          </div>
          <div class="form-group">
            <label>ID Number for Discount</label>
            <input type="text" name="customer_id_no" placeholder="PWD / Senior / Student ID no.">
          </div>
          <div class="form-group">
            <label>Reference No.</label>
            <input type="text" name="reference_no" placeholder="OR number, invoice, or reference no.">
          </div>
          <div class="form-group full">
            <label>Remarks</label>
            <textarea name="remarks" placeholder="Customer name or optional notes"></textarea>
          </div>
          <div class="form-group full">
            <div class="alert alert-warning" style="margin-bottom:0">
              <strong>Computed Total:</strong>&nbsp;<span id="stockOutComputedTotal">₱0.00</span>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('stockOutModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Stock-Out</button>
      </div>
    </form>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal-backdrop hidden" id="productModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">Product Record</span>
      <button class="modal-close" onclick="closeModal('productModal')">×</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="product_id" id="edit_product_id" value="0">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group">
            <label>SKU *</label>
            <input type="text" name="sku" required placeholder="Unique SKU">
          </div>
          <div class="form-group">
            <label>Barcode / QR Code</label>
            <input type="text" name="barcode" placeholder="Scan or enter barcode / QR code">
          </div>
          <div class="form-group">
            <label>Product Name *</label>
            <input type="text" name="product_name" required>
          </div>
          <div class="form-group">
            <label>Category *</label>
            <input type="text" name="category" list="catList" required>
            <datalist id="catList">
              <option>Engine Parts</option><option>Brake System</option><option>Suspension</option><option>Electrical</option><option>Lighting</option><option>Tires &amp; Wheels</option><option>Interior Accessories</option><option>Exterior Accessories</option><option>Audio &amp; Electronics</option><option>Oils &amp; Fluids</option><option>Cleaning Supplies</option><option>Tools</option><option>Others</option>
            </datalist>
          </div>
          <div class="form-group">
            <label>Brand</label>
            <input type="text" name="brand">
          </div>
          <div class="form-group">
            <label>Stock-In / Initial Stock</label>
          <input type="number" name="initial_stock" min="0" value="0" placeholder="Starting stock quantity">
        </div>
        <div class="form-group">
          <label>Unit * <button type="button" class="unit-guide-btn" onclick="toggleUnitGuide()">Unit Guide</button></label>
            <select name="unit" required>
            <option value="">— Select Unit —</option>
            <option value="pcs">pcs</option>
            <option value="set">set</option>
            <option value="pair">pair</option>
            <option value="kit">kit</option>
            <option value="box">box</option>
            <option value="pack">pack</option>
            <option value="roll">roll</option>
            <option value="meter">meter</option>
            <option value="liter">liter</option>
            <option value="gallon">gallon</option>
            <option value="bottle">bottle</option>
            <option value="can">can</option>
            <option value="tube">tube</option>
            <option value="sachet">sachet</option>
            <option value="drum">drum</option>
            <option value="kg">kg</option>
            <option value="gram">gram</option>
          </select>
          </div>
          <div class="form-group">
            <label>Branch *</label>
            <select name="branch" required>
              <option value="Autobox">Autobox</option>
              <option value="Autophoria">Autophoria</option>
            </select>
          </div>
          <div class="form-group">
            <label>Unit Price (₱) *</label>
            <input type="number" name="unit_price" step="0.01" min="0" required value="0">
          </div>
          <div class="form-group">
            <label>Reorder Level</label>
            <input type="number" name="reorder_level" min="0" value="0">
          </div>
          <div class="form-group">
            <label>Warranty Months</label>
            <input type="number" name="warranty_months" min="0" value="0">
          </div>
          <div class="form-group">
            <label>Default Supplier</label>
            <select name="default_supplier_id">
              <option value="">— None —</option>
              <?php foreach ($suppliers as $s): ?>
                <option value="<?= $s['supplier_id'] ?>"><?= htmlspecialchars($s['supplier_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('productModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Product</button>
      </div>
    </form>
  </div>
</div>
<script>
// Clear hidden ID when opening for new
document.querySelector('[onclick="openModal(\'productModal\')"]')?.addEventListener('click', function() {
  document.getElementById('edit_product_id').value = '0';
  document.querySelector('#productModal form').reset();
});
// When editing, set hidden product_id
document.addEventListener('click', function(e) {
  const btn = e.target.closest('[data-modal="productModal"]');
  if (btn && btn.dataset.edit) {
    const d = JSON.parse(btn.dataset.edit);
    document.getElementById('edit_product_id').value = d.product_id;
  }
});

function setSelectValue(selectId, productId) {
  const select = document.getElementById(selectId);
  if (select && productId) select.value = String(productId);
}

function openStockInModal(productId) {
  const form = document.querySelector('#stockInModal form');
  if (form) form.reset();
  setSelectValue('stockInProduct', productId);
  openModal('stockInModal');
}

function openStockOutModal(productId) {
  const form = document.querySelector('#stockOutModal form');
  if (form) form.reset();
  setSelectValue('stockOutProduct', productId);
  computeInventoryStockOutTotal();
  openModal('stockOutModal');
}

function computeInventoryStockOutTotal() {
  const product = document.getElementById('stockOutProduct');
  const qty = document.getElementById('stockOutQty');
  const dtype = document.getElementById('stockOutDiscountType');
  const dval = document.getElementById('stockOutDiscountValue');
  const out = document.getElementById('stockOutComputedTotal');
  if (!product || !qty || !dtype || !dval || !out) return;

  const opt = product.options[product.selectedIndex];
  const price = parseFloat(opt?.dataset?.price || '0') || 0;
  const quantity = parseInt(qty.value || '0', 10) || 0;
  const gross = price * quantity;
  const discountValue = parseFloat(dval.value || '0') || 0;
  const discount = dtype.value === 'percent' ? gross * (discountValue / 100) : discountValue;
  const total = Math.max(0, gross - discount);
  out.textContent = '₱' + total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

['stockOutProduct','stockOutQty','stockOutDiscountType','stockOutDiscountValue'].forEach(function(id) {
  document.addEventListener('input', function(e) { if (e.target && e.target.id === id) computeInventoryStockOutTotal(); });
  document.addEventListener('change', function(e) { if (e.target && e.target.id === id) computeInventoryStockOutTotal(); });
});
</script>
<?php endif; ?>


<style>
.unit-guide-btn {
  border: 0;
  background: transparent;
  color: var(--accent);
  font-size: 11px;
  font-weight: 700;
  cursor: pointer;
  padding: 0;
  margin-left: 6px;
  text-decoration: underline;
}

.unit-guide-panel.hidden {
  display: none !important;
}

.unit-guide-panel {
  position: fixed;
  right: 28px;
  top: 50%;
  transform: translateY(-50%);
  width: 360px;
  max-width: calc(100vw - 40px);
  z-index: 300;
}

.unit-guide-card {
  background: #fff;
  border: 1px solid var(--border);
  border-radius: 14px;
  box-shadow: 0 20px 60px rgba(0,0,0,.20);
  overflow: hidden;
}

.unit-guide-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  padding: 14px 16px;
  background: #f9fafb;
  border-bottom: 1px solid var(--border);
}

.unit-guide-header strong {
  display: block;
  font-size: 14px;
  color: var(--text);
}

.unit-guide-header small {
  display: block;
  font-size: 11px;
  color: var(--text-2);
  margin-top: 2px;
}

.unit-guide-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
  padding: 14px;
  max-height: 64vh;
  overflow-y: auto;
}

.unit-guide-grid div {
  background: #f9fafb;
  border: 1px solid #edf0f3;
  border-radius: 8px;
  padding: 8px 9px;
}

.unit-guide-grid strong {
  display: block;
  color: var(--accent);
  font-size: 12px;
  margin-bottom: 2px;
}

.unit-guide-grid span {
  display: block;
  color: var(--text-2);
  font-size: 11px;
  line-height: 1.25;
}

html.dark-mode .unit-guide-card {
  background: #0b0b0b !important;
  color: #ffffff !important;
  border-color: #333333 !important;
}

html.dark-mode .unit-guide-header {
  background: #111111 !important;
  border-color: #333333 !important;
}

html.dark-mode .unit-guide-grid div {
  background: #111111 !important;
  border-color: #333333 !important;
}

@media (max-width: 980px) {
  .unit-guide-panel {
    left: 16px;
    right: 16px;
    bottom: 16px;
    top: auto;
    transform: none;
    width: auto;
  }

  .unit-guide-grid {
    max-height: 240px;
  }
}
</style>

<script>
function toggleUnitGuide() {
  const guide = document.getElementById('unitGuidePanel');
  if (guide) guide.classList.toggle('hidden');
}

function hideUnitGuide() {
  const guide = document.getElementById('unitGuidePanel');
  if (guide) guide.classList.add('hidden');
}

document.addEventListener('DOMContentLoaded', function() {
  hideUnitGuide();

  // Hide Unit Guide when Add Product modal is closed through X or Cancel.
  document.querySelectorAll('[onclick="closeModal(\'productModal\')"]').forEach(function(btn) {
    btn.addEventListener('click', hideUnitGuide);
  });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
