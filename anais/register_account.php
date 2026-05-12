<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: /anais/dashboard.php');
    exit;
}

$msg = '';
$msgType = '';
$tempPassword = '';

function regColumnExists(PDO $db, string $table, string $column): bool {
    $s = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute([$table, $column]);
    return (int)$s->fetchColumn() > 0;
}

function regEnsureColumns(PDO $db): void {
    $cols = [
        'contact_number'      => "ALTER TABLE users ADD COLUMN contact_number VARCHAR(30) NULL AFTER supplier_id",
        'email'               => "ALTER TABLE users ADD COLUMN email VARCHAR(150) NULL AFTER contact_number",
        'address'             => "ALTER TABLE users ADD COLUMN address TEXT NULL AFTER email",
        'must_change_password'=> "ALTER TABLE users ADD COLUMN must_change_password TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER address"
    ];

    foreach ($cols as $c => $sql) {
        if (!regColumnExists($db, 'users', $c)) {
            try { $db->exec($sql); } catch (Throwable $e) {}
        }
    }
}

function regTempPass(): string {
    $a = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $p = 'TEMP-';
    for ($i = 0; $i < 8; $i++) {
        $p .= $a[random_int(0, strlen($a) - 1)];
    }
    return $p;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full     = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $role     = $_POST['role'] ?? 'Employee';
    $branch   = $_POST['branch'] ?? 'Autobox';
    $contact  = trim($_POST['contact_number'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $address  = trim($_POST['address'] ?? '');

    // Public account request is for internal Employee/Staff only.
    // Supplier accounts should still use the Supplier Registration page or Owner Account Management.
    if (!in_array($role, ['Employee', 'OIC'], true)) {
        $role = 'Employee';
    }

    if ($full === '' || $username === '' || $contact === '' || !in_array($branch, ['Autobox', 'Autophoria', 'Both'], true)) {
        $msg = 'Please complete name, username, contact number, role, and branch.';
        $msgType = 'danger';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]{4,50}$/', $username)) {
        $msg = 'Username must be 4–50 characters and may only contain letters, numbers, underscore, dash, or dot.';
        $msgType = 'danger';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = 'Please enter a valid email address.';
        $msgType = 'danger';
    } else {
        try {
            $db = getDB();
            regEnsureColumns($db);

            $du = $db->prepare("SELECT user_id FROM users WHERE username=? LIMIT 1");
            $du->execute([$username]);

            if ($du->fetch()) {
                $msg = 'Username already exists. Please choose another username.';
                $msgType = 'danger';
            } else {
                $tempPassword = regTempPass();
                $hash = password_hash($tempPassword, PASSWORD_DEFAULT);

                $st = $db->prepare("INSERT INTO users
                    (full_name, username, password, role, branch, status, supplier_id, contact_number, email, address, must_change_password, created_by, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, 'Inactive', NULL, ?, ?, ?, 1, NULL, NOW(), NOW())");
                $st->execute([$full, $username, $hash, $role, $branch, $contact, $email, $address]);

                $msg = 'Staff account request submitted. Your account is Inactive until the Owner activates it. Save your temporary password below.';
                $msgType = 'success';
            }
        } catch (Throwable $e) {
            $msg = 'Registration failed: ' . $e->getMessage();
            $msgType = 'danger';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>ANAIS – Staff Account Request</title>
<link rel="stylesheet" href="/anais/assets/css/style.css">
<?php if (file_exists(__DIR__ . '/assets/css/notifications.css')): ?>
<link rel="stylesheet" href="/anais/assets/css/notifications.css">
<?php endif; ?>
</head>
<body>
<div class="login-page">
  <div class="login-card" style="max-width:760px">
    <div class="login-logo">
      <img src="/anais/assets/img/anais-logo.png" class="login-logo-img" alt="ANAIS">
      <h1>Request Employee / Staff Account</h1>
      <p>For Autobox and Autophoria employees only. Accounts are created as Inactive and must be approved by the Owner.</p>
    </div>

    <?php if ($msg): ?>
      <div class="alert alert-<?= $msgType ?>" data-auto-dismiss><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <?php if ($tempPassword): ?>
      <div class="alert alert-warning">
        <strong>Temporary Password:</strong> <code><?= htmlspecialchars($tempPassword) ?></code><br>
        Use this after the Owner activates your account. You will be asked to change it.
      </div>
    <?php endif; ?>

    <form method="POST">
      <div class="form-grid">
        <div class="form-group full">
          <label>Full Name *</label>
          <input name="full_name" required>
        </div>

        <div class="form-group">
          <label>Username *</label>
          <input name="username" required>
        </div>

        <div class="form-group">
          <label>Role *</label>
          <select name="role" required>
            <option value="Employee">Employee / Staff</option>
            <option value="OIC">OIC</option>
          </select>
        </div>

        <div class="form-group">
          <label>Branch *</label>
          <select name="branch" required>
            <option value="Autobox">Autobox</option>
            <option value="Autophoria">Autophoria</option>
            <option value="Both">Both</option>
          </select>
        </div>

        <div class="form-group">
          <label>Contact Number *</label>
          <input name="contact_number" required>
        </div>

        <div class="form-group">
          <label>Email</label>
          <input type="email" name="email">
        </div>

        <div class="form-group full">
          <label>Address</label>
          <textarea name="address"></textarea>
        </div>
      </div>

      <div style="display:flex;gap:10px;margin-top:18px;flex-wrap:wrap">
        <button class="btn btn-primary" type="submit">Submit Staff Account Request</button>
        <a href="/anais/login.php?type=staff" class="btn btn-outline">Back to Employee / Staff Login</a>
      </div>
    </form>
  </div>
</div>
<script src="/anais/assets/js/app.js"></script>
</body>
</html>
