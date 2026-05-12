<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . (isSupplier() ? '/anais/supplier_portal.php' : '/anais/dashboard.php'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ANAIS – Supplier Registration</title>
<link rel="stylesheet" href="/anais/assets/css/style.css">
<?php if (file_exists(__DIR__ . '/assets/css/notifications.css')): ?>
<link rel="stylesheet" href="/anais/assets/css/notifications.css">
<?php endif; ?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
<div class="login-page">
  <div class="login-card" style="max-width:720px;width:96%">
    <div class="login-logo">
      <span class="logo-icon">🚚</span>
      <h1>Supplier Registration</h1>
      <p>Create a supplier account for the ANAIS Supplier Portal.</p>
    </div>

    <div id="registerMessage" class="hidden"></div>

    <form id="supplierRegisterForm" autocomplete="off">
      <div class="form-grid">
        <div class="form-group full">
          <label>Supplier / Business Name *</label>
          <input type="text" name="supplier_name" placeholder="Example: ABC Auto Parts Supply" required>
        </div>

        <div class="form-group">
          <label>Contact Person *</label>
          <input type="text" name="contact_person" placeholder="Full name" required>
        </div>

        <div class="form-group">
          <label>Contact Number *</label>
          <input type="text" name="contact_number" placeholder="09xxxxxxxxx" maxlength="15" required>
        </div>

        <div class="form-group full">
          <label>Email</label>
          <input type="email" name="email" placeholder="supplier@email.com">
        </div>

        <div class="form-group full">
          <label>Business Address *</label>
          <textarea name="address" placeholder="Complete supplier address" required></textarea>
        </div>

        <div class="form-group">
          <label>Username *</label>
          <input type="text" name="username" placeholder="Choose username" required>
        </div>

        <div class="form-group">
          <label>Password *</label>
          <input type="password" name="password" placeholder="Minimum 8 characters" minlength="8" required>
        </div>

        <div class="form-group full">
          <label>Confirm Password *</label>
          <input type="password" name="confirm_password" placeholder="Re-type password" minlength="8" required>
        </div>
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:10px;margin-top:8px">
        Register Supplier Account
      </button>
    </form>

    <p style="text-align:center;margin-top:18px;font-size:13px;color:var(--text-2)">
      Already registered? <a href="/anais/login.php">Back to Login</a>
    </p>
  </div>
</div>

<script src="/anais/assets/js/app.js"></script>
<script>
document.getElementById('supplierRegisterForm').addEventListener('submit', async function(e) {
  e.preventDefault();

  const form = e.currentTarget;
  const box = document.getElementById('registerMessage');
  const btn = form.querySelector('button[type="submit"]');

  function showMessage(type, message) {
    if (typeof window.showToast === 'function') {
      window.showToast(type, message);
    }

    box.className = 'alert alert-' + type;
    box.textContent = message;
  }

  showMessage('warning', 'Submitting supplier registration...');
  btn.disabled = true;

  try {
    const response = await fetch('/anais/api/supplier_register.php', {
      method: 'POST',
      body: new FormData(form),
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'same-origin'
    });

    const raw = await response.text();
    let data;

    try {
      data = JSON.parse(raw);
    } catch (err) {
      console.error('Supplier registration non-JSON response:', raw);
      throw new Error(raw ? raw.substring(0, 250) : 'Empty server response.');
    }

    showMessage(data.success ? 'success' : 'danger', data.message || (data.success ? 'Supplier registered successfully.' : 'Registration failed.'));

    if (data.success) {
      form.reset();
      setTimeout(() => {
        window.location.href = '/anais/login.php?registered=supplier';
      }, 1600);
    }
  } catch (err) {
    console.error(err);
    showMessage('danger', 'Unable to submit registration: ' + (err.message || 'Please check your server.'));
  } finally {
    btn.disabled = false;
  }
});
</script>
</body>
</html>
