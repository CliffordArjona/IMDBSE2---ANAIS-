<?php
require_once __DIR__ . '/config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$msg = '';
$msgType = '';
$username = '';

function staffResetColumnExists(PDO $db, string $table, string $column): bool {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function ensureStaffPasswordResetTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS password_reset_requests (
        request_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id MEDIUMINT UNSIGNED NOT NULL,
        username VARCHAR(50) NOT NULL,
        role ENUM('Employee','OIC','Supplier') NOT NULL DEFAULT 'Employee',
        status ENUM('Pending','Processed','Cancelled') NOT NULL DEFAULT 'Pending',
        requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        processed_at DATETIME DEFAULT NULL,
        processed_by MEDIUMINT UNSIGNED DEFAULT NULL,
        temporary_password_shown VARCHAR(50) DEFAULT NULL,
        PRIMARY KEY (request_id),
        KEY user_id (user_id),
        KEY status (status),
        KEY requested_at (requested_at),
        KEY processed_by (processed_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try {
        $db->exec("ALTER TABLE password_reset_requests MODIFY role ENUM('Employee','OIC','Supplier') NOT NULL DEFAULT 'Employee'");
    } catch (Throwable $e) {
        // Ignore if the DB engine/version blocks enum change; latest SQL update also fixes this.
    }

    if (!staffResetColumnExists($db, 'password_reset_requests', 'temporary_password_shown')) {
        try {
            $db->exec("ALTER TABLE password_reset_requests ADD COLUMN temporary_password_shown VARCHAR(50) DEFAULT NULL AFTER processed_by");
        } catch (Throwable $e) {}
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');

    if ($username === '') {
        $msg = 'Please enter your employee/staff username.';
        $msgType = 'danger';
    } else {
        try {
            $db = getDB();
            ensureStaffPasswordResetTable($db);

            $stmt = $db->prepare("SELECT user_id, username, full_name, role, status
                FROM users
                WHERE username = ?
                LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if (!$user || !in_array(($user['role'] ?? ''), ['Employee','OIC'], true)) {
                $msg = 'Employee/Staff account was not found. Please check your username.';
                $msgType = 'danger';
            } else {
                $check = $db->prepare("SELECT request_id FROM password_reset_requests
                    WHERE user_id = ? AND status = 'Pending'
                    LIMIT 1");
                $check->execute([(int)$user['user_id']]);
                $existing = $check->fetch();

                if ($existing) {
                    $msg = 'You already have a pending password reset request. Please wait for the Owner to generate a new temporary password.';
                    $msgType = 'warning';
                } else {
                    $insert = $db->prepare("INSERT INTO password_reset_requests
                        (user_id, username, role, status, requested_at)
                        VALUES (?, ?, ?, 'Pending', NOW())");
                    $insert->execute([(int)$user['user_id'], $user['username'], $user['role']]);

                    $msg = 'Password reset request submitted. Please ask the Owner to generate a new temporary password.';
                    $msgType = 'success';
                    $username = '';
                }
            }
        } catch (Throwable $e) {
            $msg = 'Unable to submit password reset request. Please run the latest database update SQL first.';
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
<title>ANAIS – Employee/Staff Forgot Password</title>
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
      <h1>Forgot Temporary Password</h1>
      <p>Employee / Staff password reset request</p>
    </div>

    <?php if ($msg): ?>
      <div class="alert alert-<?= htmlspecialchars($msgType) ?>" data-auto-dismiss><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
      <div class="form-group" style="margin-bottom:16px">
        <label>Employee / Staff Username</label>
        <input type="text" name="username" value="<?= htmlspecialchars($username) ?>" placeholder="Enter your username" required>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:10px">
        Request New Temporary Password
      </button>
    </form>

    <div style="text-align:center;margin-top:14px;font-size:13px">
      <a href="/anais/login.php?type=staff">Back to Employee / Staff Login</a>
    </div>
  </div>
</div>
<script src="/anais/assets/js/app.js"></script>
</body>
</html>
