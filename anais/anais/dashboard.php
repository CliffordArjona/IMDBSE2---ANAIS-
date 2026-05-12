<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
$pageTitle = 'Dashboard';
$activePage = 'dashboard';
$db = getDB();
$u  = currentUser();
$branch_clause = ($u['branch'] !== 'Both') ? "AND branch = ?" : "";
$branch_params = ($u['branch'] !== 'Both') ? [$u['branch']] : [];
$stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE status='Active' $branch_clause"); $stmt->execute($branch_params); $totalProducts = $stmt->fetchColumn();
$stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE status='Active' AND current_stock <= reorder_level AND reorder_level > 0 $branch_clause"); $stmt->execute($branch_params); $lowStock = $stmt->fetchColumn();
$today = date('Y-m-d');
$stmt = $db->prepare("SELECT COUNT(*) FROM stock_transactions st LEFT JOIN products p ON st.product_id=p.product_id WHERE st.transaction_type='Stock-Out' AND st.transaction_date=? " . ($u['branch'] !== 'Both' ? "AND p.branch=?" : ""));
$params = [$today]; if($u['branch'] !== 'Both') $params[] = $u['branch']; $stmt->execute($params); $todaySales = $stmt->fetchColumn();
$stmt = $db->prepare("SELECT COUNT(*) FROM purchase_orders WHERE po_status='Pending'" . ($u['branch']!=='Both' ? " AND branch IN (?, 'Both')" : "")); $stmt->execute($branch_params); $pendingPOs = $stmt->fetchColumn();
$stmt = $db->prepare("SELECT st.*, p.product_name, p.sku, p.branch, u.full_name AS recorded_by_name
    FROM stock_transactions st
    JOIN products p ON st.product_id=p.product_id
    LEFT JOIN users u ON st.recorded_by=u.user_id
    WHERE st.transaction_type='Stock-Out' " . ($u['branch']!=='Both' ? "AND p.branch=?" : "") . "
    ORDER BY st.transaction_date DESC, st.transaction_id DESC LIMIT 10");
$stmt->execute($branch_params); $salesTx = $stmt->fetchAll();
$stmt = $db->prepare("SELECT * FROM products WHERE status='Active' AND current_stock <= reorder_level AND reorder_level > 0 $branch_clause ORDER BY current_stock ASC LIMIT 8"); $stmt->execute($branch_params); $lowItems = $stmt->fetchAll();
include __DIR__ . '/includes/header.php';
?>
<div class="page-header"><div><h1>Dashboard</h1><p>Welcome back, <?= htmlspecialchars($u['name']) ?>. Sales and inventory overview.</p></div><div style="color:var(--text-2);font-size:13px"><?= date('l, F j, Y') ?></div></div>
<div class="stats-grid">
  <div class="stat-card"><div class="stat-icon blue">📦</div><div><div class="stat-value"><?= number_format($totalProducts) ?></div><div class="stat-label">Active Products</div></div></div>
  <div class="stat-card"><div class="stat-icon red">⚠️</div><div><div class="stat-value" style="color:var(--danger)"><?= number_format($lowStock) ?></div><div class="stat-label">Low Stock Items</div></div></div>
  <div class="stat-card"><div class="stat-icon green">💸</div><div><div class="stat-value"><?= number_format($todaySales) ?></div><div class="stat-label">Sales Transactions Today</div></div></div>
  <div class="stat-card"><div class="stat-icon yellow">📋</div><div><div class="stat-value"><?= number_format($pendingPOs) ?></div><div class="stat-label">Pending Purchase Orders</div></div></div>
</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;flex-wrap:wrap">
<div class="card" style="grid-column:1 / -1"><div class="card-header"><span class="card-title">Recent Sales Transactions</span><a href="/anais/reports.php?type=sales_history" class="btn btn-outline btn-sm">View Sales History</a></div><div class="table-wrap"><table>
<thead><tr><th>Transaction No.</th><th>Product</th><th>Quantity</th><th>Branch</th><th>Total</th><th>Recorded By</th><th>View Details</th></tr></thead><tbody>
<?php if(empty($salesTx)): ?><tr><td colspan="7" style="text-align:center;color:var(--text-2);padding:28px">No sales transactions yet.</td></tr><?php else: foreach($salesTx as $tx): ?>
<tr>
  <td><strong>#<?= str_pad((string)$tx['transaction_id'], 6, '0', STR_PAD_LEFT) ?></strong></td>
  <td><?= htmlspecialchars($tx['product_name']) ?><br><small style="color:var(--text-2)"><?= htmlspecialchars($tx['sku'] ?? '') ?></small></td>
  <td><?= (int)$tx['quantity'] ?></td>
  <td><span class="badge badge-info"><?= htmlspecialchars($tx['branch']) ?></span></td>
  <td>₱<?= number_format((float)($tx['total_amount'] ?? 0), 2) ?></td>
  <td><?= htmlspecialchars($tx['recorded_by_name'] ?? '—') ?></td>
  <td><a class="btn btn-outline btn-sm" href="/anais/stock_out_or.php?id=<?= (int)$tx['transaction_id'] ?>" target="_blank">Date & Time / Details</a></td>
</tr>
<?php endforeach; endif; ?>
</tbody></table></div></div>
<?php if($lowStock > 0): ?><div class="card" style="grid-column:1 / -1"><div class="card-header"><span class="card-title" style="color:var(--warning)">⚠️ Low Stock Alerts</span><a href="/anais/inventory.php?filter=low" class="btn btn-outline btn-sm">View All</a></div><div class="table-wrap"><table><thead><tr><th>Product</th><th>Branch</th><th>Category</th><th>Stock</th><th>Reorder Level</th><th>Action</th></tr></thead><tbody><?php foreach($lowItems as $item): ?><tr class="low-stock"><td><?= htmlspecialchars($item['product_name']) ?></td><td><span class="badge badge-info"><?= htmlspecialchars($item['branch']) ?></span></td><td><?= htmlspecialchars($item['category']) ?></td><td style="color:var(--danger);font-weight:700"><?= (int)$item['current_stock'] ?></td><td><?= (int)$item['reorder_level'] ?></td><td><?php if(isOwner()): ?><a href="/anais/auto_po.php" class="btn btn-outline btn-sm">Auto Generate PO</a><?php else: ?><span class="badge badge-warning">Notify Owner</span><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
