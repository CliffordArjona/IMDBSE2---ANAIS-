<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ANAIS – <?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></title>
<link rel="stylesheet" href="/anais/assets/css/style.css">
<link rel="stylesheet" href="/anais/assets/css/dark-mode.css">
<?php if (file_exists(__DIR__ . '/../assets/css/notifications.css')): ?>
<link rel="stylesheet" href="/anais/assets/css/notifications.css">
<?php endif; ?>
<?php if (file_exists(__DIR__ . '/../assets/css/table-clean.css')): ?>
<link rel="stylesheet" href="/anais/assets/css/table-clean.css">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script>
(function () {
  if (localStorage.getItem('anais_theme') === 'dark') {
    document.documentElement.classList.add('dark-mode');
  }
})();
</script>
</head>
<body>
<?php $u = currentUser(); ?>

<?php if (!empty($_SESSION['anais_flash_alert'])): ?>
  <?php
    $flash = $_SESSION['anais_flash_alert'];
    unset($_SESSION['anais_flash_alert']);
    $flashType = htmlspecialchars($flash['type'] ?? 'danger');
    $flashMsg  = htmlspecialchars($flash['message'] ?? 'An error occurred.');
  ?>
  <div class="alert alert-<?= $flashType ?>" data-auto-dismiss><?= $flashMsg ?></div>
<?php endif; ?>

<div class="app-shell">
  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="sidebar-brand">
      <img src="/anais/assets/img/anais-logo.png" alt="ANAIS Logo" class="brand-logo-img">
      <div class="brand-copy">
        <div class="brand-name">ANAIS</div>
        <div class="brand-sub">Inventory System</div>
      </div>
    </div>

    <nav class="sidebar-nav">
      <?php if (isSupplier()): ?>
      <a href="/anais/supplier_portal.php" class="nav-item <?= ($activePage??'')==='supplier_portal'?'active':'' ?>"><span>📋</span> Supplier PO Portal</a>
      <?php else: ?>
      <a href="/anais/dashboard.php" class="nav-item <?= ($activePage??'')==='dashboard'?'active':'' ?>"><span>📊</span> Dashboard</a>
      <a href="/anais/inventory.php" class="nav-item <?= ($activePage??'')==='inventory'?'active':'' ?>"><span>📦</span> Inventory / Stock</a>
      <?php if (canEdit()): ?>
      <!-- Stock-In and Stock-Out are now inside Inventory / Stock. -->
      <?php endif; ?>
      <?php if (isOwner()): ?>
      <a href="/anais/suppliers.php" class="nav-item <?= ($activePage??'')==='suppliers'?'active':'' ?>"><span>🏭</span> Suppliers</a>
      <a href="/anais/purchase_orders.php" class="nav-item <?= ($activePage??'')==='po'?'active':'' ?>"><span>📋</span> Purchase Orders</a>
      <a href="/anais/auto_po.php" class="nav-item <?= ($activePage??'')==='auto_po'?'active':'' ?>"><span>⚙️</span> Auto Purchase Order</a>
      <?php endif; ?>
      <?php if (canEdit()): ?>
      <a href="/anais/deliveries.php" class="nav-item <?= ($activePage??'')==='deliveries'?'active':'' ?>"><span>🚚</span> Deliveries</a>
      <a href="/anais/reports.php" class="nav-item <?= ($activePage??'')==='reports'?'active':'' ?>"><span>📈</span> Reports</a>
      <?php endif; ?>
      <?php if (isOwner()): ?>
      <a href="/anais/accounts.php" class="nav-item <?= ($activePage??'')==='accounts'?'active':'' ?>"><span>👤</span> Accounts</a>
      <?php endif; ?>
      <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
      <div class="user-chip">
        <div class="user-avatar"><?= strtoupper(substr($u['name'],0,1)) ?></div>
        <div>
          <div class="user-name"><?= htmlspecialchars($u['name']) ?></div>
          <div class="user-role"><?= htmlspecialchars($u['role']) ?> · <?= htmlspecialchars($u['branch']) ?></div>
        </div>
      </div>

      <button type="button" class="btn-theme-toggle" id="darkModeToggle" onclick="toggleDarkMode()">
        <span id="darkModeIcon">🌙</span>
        <span id="darkModeText">Dark Mode</span>
      </button>

      <a href="/anais/change_own_password.php" class="btn-logout" style="margin-bottom:8px">My Account</a>
      <a href="/anais/logout.php" class="btn-logout">Logout</a>
    </div>
  </aside>

  <!-- Main -->
  <main class="main-content">
    <div class="page-inner">
