<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

if (!isSupplier()) {
    header('Location: /anais/dashboard.php');
    exit;
}

$db = getDB();
$u = currentUser();
$msg = '';
$msgType = '';

$stmt = $db->prepare("SELECT user_id, must_change_password FROM users WHERE user_id=? LIMIT 1");
$stmt->execute([(int)$u['id']]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: /anais/logout.php');
    exit;
}

if ((int)($user['must_change_password'] ?? 0) !== 1) {
    header('Location: /anais/supplier_portal.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($newPassword) < 8) {
        $msg = 'New password must be at least 8 characters.';
        $msgType = 'danger';
    } elseif ($newPassword !== $confirmPassword) {
        $msg = 'New password and confirm password do not match.';
        $msgType = 'danger';
    } else {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);

        $update = $db->prepare("UPDATE users
            SET password=?,
                must_change_password=0,
                updated_at=NOW()
            WHERE user_id=?");
        $update->execute([$hash, (int)$u['id']]);

        // Remove temporary password from Owner Accounts after supplier creates final password.
        try {
            $clear = $db->prepare("UPDATE password_reset_requests
                SET temporary_password_shown = NULL
                WHERE user_id = ? AND status = 'Processed'");
            $clear->execute([(int)$u['id']]);
        } catch (Throwable $e) {
            // Do not block password change if cleanup fails.
        }

        $_SESSION['must_change_password'] = 0;
        $_SESSION['anais_flash_alert'] = [
            'type' => 'success',
            'message' => 'Password changed successfully.'
        ];

        header('Location: /anais/supplier_portal.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ANAIS – Change Password</title>
<link rel="stylesheet" href="/anais/assets/css/style.css">
<?php if (file_exists(__DIR__ . '/assets/css/notifications.css')): ?>
<link rel="stylesheet" href="/anais/assets/css/notifications.css">
<?php endif; ?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
<div class="login-page">
  <div class="login-card">
    <div class="login-logo login-logo-custom">
      <img src="/anais/assets/img/anais-logo.png" alt="ANAIS Auto Shop Logo" class="login-logo-img">
      <h1>Change Password</h1>
      <p>You are using a temporary password. Please create your own password.</p>
    </div>

    <?php if ($msg): ?>
      <div class="alert alert-<?= htmlspecialchars($msgType) ?>" data-auto-dismiss><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <form method="POST">
      <div class="form-group" style="margin-bottom:14px">
        <label>New Password</label>
        <input type="password" name="new_password" minlength="8" placeholder="Minimum 8 characters" required>
      </div>

      <div class="form-group" style="margin-bottom:18px">
        <label>Confirm New Password</label>
        <input type="password" name="confirm_password" minlength="8" placeholder="Re-type new password" required>
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:10px">
        Save New Password
      </button>
    </form>
  </div>
</div>
<script src="/anais/assets/js/app.js"></script>
</body>
</html>
