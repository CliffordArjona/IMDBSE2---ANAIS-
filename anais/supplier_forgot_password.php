<?php
require_once __DIR__ . '/config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$msg = '';
$msgType = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');

    if ($username === '') {
        $msg = 'Please enter your supplier username.';
        $msgType = 'danger';
    } else {
        try {
            $db = getDB();

            $stmt = $db->prepare("SELECT user_id, username, full_name, role, status
                FROM users
                WHERE username = ?
                LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if (!$user || ($user['role'] ?? '') !== 'Supplier') {
                // Do not reveal too much, but for school/demo we show clear supplier message.
                $msg = 'Supplier account was not found. Please check your supplier username.';
                $msgType = 'danger';
            } elseif (($user['status'] ?? '') !== 'Active') {
                $msg = 'This supplier account is inactive. Please contact the Owner.';
                $msgType = 'warning';
            } else {
                // Avoid duplicate pending reset requests.
                $check = $db->prepare("SELECT request_id FROM password_reset_requests
                    WHERE user_id = ? AND status = 'Pending'
                    LIMIT 1");
                $check->execute([(int)$user['user_id']]);
                $existing = $check->fetch();

                if ($existing) {
                    $msg = 'You already have a pending password reset request. Please wait for the Owner to reset your password.';
                    $msgType = 'warning';
                } else {
                    $insert = $db->prepare("INSERT INTO password_reset_requests
                        (user_id, username, role, status, requested_at)
                        VALUES (?, ?, 'Supplier', 'Pending', NOW())");
                    $insert->execute([(int)$user['user_id'], $user['username']]);

                    $msg = 'Password reset request submitted. Please contact the Owner to reset your password.';
                    $msgType = 'success';
                    $username = '';
                }
            }
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'password_reset_requests') !== false) {
                $msg = 'Password reset request table is missing. Please import config/create_password_reset_requests.sql.';
            } else {
                $msg = 'Unable to submit request. Please try again.';
            }
            $msgType = 'danger';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ANAIS – Supplier Forgot Password</title>
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
      <h1>Forgot Password</h1>
      <p>Supplier password reset request</p>
    </div>

    <?php if ($msg): ?>
      <div class="alert alert-<?= htmlspecialchars($msgType) ?>" data-auto-dismiss><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <form method="POST">
      <div class="form-group" style="margin-bottom:14px">
        <label>Supplier Username</label>
        <input type="text" name="username" placeholder="Enter supplier username" value="<?= htmlspecialchars($username) ?>" required>
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:10px">
        Submit Reset Request
      </button>
    </form>

    <div style="text-align:center;margin-top:16px;font-size:13px">
      <a href="/anais/login.php?type=supplier">Back to Supplier Login</a>
    </div>
  </div>
</div>
<script src="/anais/assets/js/app.js"></script>
</body>
</html>
