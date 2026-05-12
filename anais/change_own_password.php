<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

$pageTitle = 'My Account';
$activePage = '';
$db = getDB();
$u = currentUser();
$msg = '';
$msgType = '';

$stmt = $db->prepare("SELECT user_id, full_name, username, password, role, branch, contact_number, email, address FROM users WHERE user_id=? LIMIT 1");
$stmt->execute([(int)$u['id']]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: /anais/logout.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'change_password';

    if ($action === 'update_contact') {
        $contactNumber = trim($_POST['contact_number'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = 'Please enter a valid email address.';
            $msgType = 'danger';
        } elseif (strlen($contactNumber) > 30) {
            $msg = 'Contact number is too long.';
            $msgType = 'danger';
        } else {
            $update = $db->prepare("UPDATE users
                SET contact_number=?, email=?, address=?, updated_at=NOW()
                WHERE user_id=?");
            $update->execute([$contactNumber, $email, $address, (int)$u['id']]);

            $_SESSION['anais_flash_alert'] = [
                'type' => 'success',
                'message' => 'Your contact details were updated successfully.'
            ];

            header('Location: /anais/change_own_password.php');
            exit;
        }
    }

    if ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $msg = 'Please complete all password fields.';
            $msgType = 'danger';
        } elseif (!password_verify($currentPassword, $user['password'])) {
            $msg = 'Current password is incorrect.';
            $msgType = 'danger';
        } elseif (strlen($newPassword) < 8) {
            $msg = 'New password must be at least 8 characters.';
            $msgType = 'danger';
        } elseif ($newPassword !== $confirmPassword) {
            $msg = 'New password and confirm password do not match.';
            $msgType = 'danger';
        } elseif (password_verify($newPassword, $user['password'])) {
            $msg = 'New password must be different from your current password.';
            $msgType = 'danger';
        } else {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $update = $db->prepare("UPDATE users SET password=?, must_change_password=0, updated_at=NOW() WHERE user_id=?");
            $update->execute([$hash, (int)$u['id']]);

            $_SESSION['must_change_password'] = 0;
            $_SESSION['anais_flash_alert'] = [
                'type' => 'success',
                'message' => 'Your password was changed successfully.'
            ];

            header('Location: ' . (($user['role'] ?? '') === 'Supplier' ? '/anais/supplier_portal.php' : '/anais/dashboard.php'));
            exit;
        }
    }
}

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <div>
    <h1>My Account</h1>
    <p>Update your own contact details or change your password. Passwords are never shown in Account Management.</p>
  </div>
</div>

<?php if ($msg): ?>
  <div class="alert alert-<?= htmlspecialchars($msgType) ?>" data-auto-dismiss><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="card" style="max-width:720px;margin-bottom:18px">
  <div class="card-header">
    <span class="card-title">My Contact Details</span>
  </div>
  <div class="card-body">
    <div class="form-grid" style="margin-bottom:16px">
      <div class="form-group">
        <label>Full Name</label>
        <input type="text" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" readonly>
      </div>
      <div class="form-group">
        <label>Username</label>
        <input type="text" value="<?= htmlspecialchars($user['username'] ?? '') ?>" readonly>
      </div>
      <div class="form-group">
        <label>Role</label>
        <input type="text" value="<?= htmlspecialchars($user['role'] ?? '') ?>" readonly>
      </div>
      <div class="form-group">
        <label>Branch</label>
        <input type="text" value="<?= htmlspecialchars($user['branch'] ?? '') ?>" readonly>
      </div>
    </div>

    <form method="POST" autocomplete="off">
      <input type="hidden" name="action" value="update_contact">
      <div class="form-grid">
        <div class="form-group">
          <label>Contact Number</label>
          <input type="text" name="contact_number" maxlength="30" value="<?= htmlspecialchars($user['contact_number'] ?? '') ?>" placeholder="09xxxxxxxxx">
        </div>
        <div class="form-group">
          <label>Email</label>
          <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" placeholder="owner@email.com">
        </div>
        <div class="form-group full">
          <label>Address</label>
          <textarea name="address" placeholder="Complete address"><?= htmlspecialchars($user['address'] ?? '') ?></textarea>
        </div>
      </div>
      <div style="margin-top:16px">
        <button type="submit" class="btn btn-primary">Save Contact Details</button>
        <a href="/anais/dashboard.php" class="btn btn-outline">Back</a>
      </div>
    </form>
  </div>
</div>

<div class="card" style="max-width:720px">
  <div class="card-header">
    <span class="card-title">Change Password</span>
  </div>
  <div class="card-body">
    <form method="POST" autocomplete="off">
      <input type="hidden" name="action" value="change_password">
      <div class="form-group" style="margin-bottom:14px">
        <label>Current Password</label>
        <input type="password" name="current_password" required>
      </div>
      <div class="form-group" style="margin-bottom:14px">
        <label>New Password</label>
        <input type="password" name="new_password" minlength="8" placeholder="Minimum 8 characters" required>
      </div>
      <div class="form-group" style="margin-bottom:18px">
        <label>Confirm New Password</label>
        <input type="password" name="confirm_password" minlength="8" required>
      </div>
      <button type="submit" class="btn btn-primary">Save New Password</button>
    </form>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
