<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . (isSupplier() ? '/anais/supplier_portal.php' : '/anais/dashboard.php'));
    exit;
}

$error = '';
$username = '';

$loginType = $_POST['login_type'] ?? ($_GET['type'] ?? 'staff');
if (!in_array($loginType, ['staff','supplier'], true)) {
    $loginType = 'staff';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $loginType = $_POST['login_type'] ?? 'staff';

    if (!in_array($loginType, ['staff','supplier'], true)) {
        $loginType = 'staff';
    }

    if ($username === '' || $password === '') {
        $error = 'Please enter your username and password.';
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND status = 'Active' LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $role = $user['role'] ?? '';

            if ($loginType === 'supplier' && $role !== 'Supplier') {
                $error = 'This login tab is for Supplier accounts only.';
            } elseif ($loginType === 'staff' && $role === 'Supplier') {
                $error = 'Please use the Supplier login tab.';
            } else {
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $role;
                $_SESSION['branch'] = $user['branch'];
                $_SESSION['supplier_id'] = $user['supplier_id'] ?? null;
                $_SESSION['must_change_password'] = (int)($user['must_change_password'] ?? 0);

                if ($role === 'Supplier' && (int)($user['must_change_password'] ?? 0) === 1) {
                    header('Location: /anais/supplier_change_password.php');
                } else {
                    header('Location: ' . ($role === 'Supplier' ? '/anais/supplier_portal.php' : '/anais/dashboard.php'));
                }
                exit;
            }
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

$flash = $_SESSION['anais_flash_alert'] ?? null;
unset($_SESSION['anais_flash_alert']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ANAIS – Login</title>
<link rel="stylesheet" href="/anais/assets/css/style.css">
<link rel="stylesheet" href="/anais/assets/css/notifications.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
.login-tabs {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  margin: 14px 0 18px;
  background: #f3f4f6;
  padding: 5px;
  border-radius: 12px;
}

.login-tab {
  border: 0;
  border-radius: 9px;
  padding: 10px 8px;
  font-weight: 700;
  font-size: 13px;
  cursor: pointer;
  background: transparent;
  color: var(--text-2);
  transition: .18s ease;
}

.login-tab.active {
  background: #fff;
  color: var(--accent);
  box-shadow: 0 8px 20px rgba(0,0,0,.10);
}

.login-tab:hover {
  color: var(--accent);
}

html.dark-mode .login-tabs {
  background: #111111;
}

html.dark-mode .login-tab.active {
  background: #000000;
  color: #ffffff;
  border: 1px solid #333333;
}

.employee-register-link {
  color: var(--accent);
  font-weight: 700;
  text-decoration: underline;
}

</style>

</head>
<body>
<?php if ($flash): ?>
  <div class="alert alert-<?= htmlspecialchars($flash['type'] ?? 'danger') ?>" data-auto-dismiss><?= htmlspecialchars($flash['message'] ?? 'An error occurred.') ?></div>
<?php endif; ?>

<div class="login-page">
  <div class="login-card">
    <div class="login-logo login-logo-custom">
      <img src="/anais/assets/img/anais-logo.png" alt="ANAIS Auto Shop Logo" class="login-logo-img">
      <h1>ANAIS</h1>
      <p>Autobox &amp; Autophoria Inventory System</p>
    </div>

    <div class="login-tabs">
      <button type="button" class="login-tab <?= $loginType === 'staff' ? 'active' : '' ?>" data-login-type="staff">
        Employee / Staff
      </button>
      <button type="button" class="login-tab <?= $loginType === 'supplier' ? 'active' : '' ?>" data-login-type="supplier">
        Supplier
      </button>
    </div>

    <?php if (isset($_GET['registered']) && $_GET['registered'] === 'supplier'): ?>
      <div class="alert alert-success" data-auto-dismiss>Supplier account created. You may now sign in.</div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="alert alert-danger" data-auto-dismiss>⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" id="loginForm">
      <input type="hidden" name="login_type" id="loginType" value="<?= htmlspecialchars($loginType) ?>">
      <div class="form-group" style="margin-bottom:14px">
        <label>Username</label>
        <input type="text" name="username" placeholder="Enter username" value="<?= htmlspecialchars($username) ?>" required>
      </div>
      <div class="form-group" style="margin-bottom:18px">
        <label>Password</label>
        <input type="password" name="password" placeholder="Enter password" required>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:10px">Sign In</button>
    </form>

    
    <div id="supplierForgotLink" style="text-align:center;margin-top:10px;font-size:13px;<?= $loginType === 'supplier' ? '' : 'display:none' ?>">
      <a href="/anais/supplier_forgot_password.php">Forgot password?</a>
    </div>

    <!-- Employee/Staff account request link: visible only on Employee / Staff login tab -->
    <div id="staffRequestLink" style="text-align:center;margin-top:14px;font-size:13px;<?= $loginType === 'staff' ? '' : 'display:none' ?>">
      <a href="/anais/register_account.php" style="color:var(--accent);font-weight:700;text-decoration:underline">
        Register an Employee Account
      </a>
    </div>

    <!-- Supplier registration link: visible only on Supplier login tab -->
    <div id="supplierRegisterLink" style="text-align:center;margin-top:14px;font-size:13px;<?= $loginType === 'supplier' ? '' : 'display:none' ?>">
      <a href="/anais/register_supplier.php" style="color:var(--accent);font-weight:700;text-decoration:underline">
        Register Supplier Account
      </a>
    </div>

    <div style="text-align:center;margin-top:18px;color:var(--text-2);font-size:12px">
      &copy; <?= date('Y') ?> ANAIS &mdash; For authorized users only
    </div>
  </div>
</div>
<script src="/anais/assets/js/app.js"></script>

<script>
document.querySelectorAll('.login-tab').forEach(function(btn) {
  btn.addEventListener('click', function() {
    const type = btn.dataset.loginType || 'staff';
    document.getElementById('loginType').value = type;

    document.querySelectorAll('.login-tab').forEach(function(tab) {
      tab.classList.toggle('active', tab.dataset.loginType === type);
    });

    const registerLink = document.getElementById('supplierRegisterLink');
    if (registerLink) {
      registerLink.style.display = type === 'supplier' ? '' : 'none';
    }

    const staffRequestLink = document.getElementById('staffRequestLink');
    if (staffRequestLink) {
      staffRequestLink.style.display = type === 'staff' ? '' : 'none';
    }

    const forgotLink = document.getElementById('supplierForgotLink');
    if (forgotLink) {
      forgotLink.style.display = type === 'supplier' ? '' : 'none';
    }

    const username = document.querySelector('input[name="username"]');
    if (username) {
      username.placeholder = type === 'supplier' ? 'Enter supplier username' : 'Enter staff username';
      username.focus();
    }
  });
});
</script>

</body>
</html>
