<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pageTitle = 'Suppliers';
$activePage = 'suppliers';
$db = getDB();
$u  = currentUser();
$msg = ''; $msgType = '';

function supplierUsernameBase(string $supplierName): string {
    $base = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', $supplierName));
    if ($base === '') { $base = 'supplier'; }
    return substr($base, 0, 24);
}

function generateSupplierUsername(PDO $db, string $supplierName): string {
    $base = supplierUsernameBase($supplierName);
    $candidate = $base;
    $i = 0;

    while (true) {
        if ($i > 0) {
            $candidate = substr($base, 0, 20) . random_int(100, 999);
        }

        $stmt = $db->prepare("SELECT user_id FROM users WHERE username=? LIMIT 1");
        $stmt->execute([$candidate]);
        if (!$stmt->fetch()) {
            return $candidate;
        }
        $i++;
    }
}

function generateTemporaryPassword(int $length = 8): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $password = 'TEMP-';
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $password;
}

// ── CRUD / Account Actions ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole(['Owner']);
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $sid  = (int)($_POST['supplier_id'] ?? 0);
        $data = [
            'supplier_name'  => trim($_POST['supplier_name'] ?? ''),
            'contact_person' => trim($_POST['contact_person'] ?? ''),
            'contact_number' => trim($_POST['contact_number'] ?? ''),
            'email'          => trim($_POST['email'] ?? ''),
            'address'        => trim($_POST['address'] ?? ''),
            'status'         => $_POST['status'] ?? 'Active',
            'remarks'        => trim($_POST['remarks'] ?? ''),
        ];

        if ($data['supplier_name'] === '' || $data['contact_person'] === '' || $data['contact_number'] === '' || $data['address'] === '') {
            $msg = 'Please complete supplier name, contact person, contact number, and address.'; $msgType = 'danger';
        } elseif ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $msg = 'Please enter a valid supplier email address.'; $msgType = 'danger';
        } elseif ($sid) {
            $db->prepare("UPDATE suppliers SET supplier_name=?,contact_person=?,contact_number=?,email=?,address=?,status=?,remarks=?,updated_at=NOW() WHERE supplier_id=?")
               ->execute(array_merge(array_values($data), [$sid]));
            $msg = 'Supplier updated successfully.'; $msgType = 'success';
        } else {
            $db->prepare("INSERT INTO suppliers (supplier_name,contact_person,contact_number,email,address,status,remarks,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())")
               ->execute([$data['supplier_name'],$data['contact_person'],$data['contact_number'],$data['email'],$data['address'],$data['status'],$data['remarks'],$u['id']]);
            $msg = 'Supplier added successfully. You can now create a supplier login account if needed.'; $msgType = 'success';
        }
    }

    if ($action === 'delete') {
        $sid = (int)($_POST['supplier_id'] ?? 0);
        $db->prepare("UPDATE suppliers SET status='Inactive', updated_at=NOW() WHERE supplier_id=?")->execute([$sid]);
        $db->prepare("UPDATE users SET status='Inactive', updated_at=NOW() WHERE role='Supplier' AND supplier_id=?")->execute([$sid]);
        $msg = 'Supplier and linked supplier account deactivated.'; $msgType = 'success';
    }

    if ($action === 'create_supplier_account') {
        $sid = (int)($_POST['supplier_id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM suppliers WHERE supplier_id=? LIMIT 1");
        $stmt->execute([$sid]);
        $supplier = $stmt->fetch();

        if (!$supplier) {
            $msg = 'Supplier not found.'; $msgType = 'danger';
        } else {
            $check = $db->prepare("SELECT username FROM users WHERE role='Supplier' AND supplier_id=? LIMIT 1");
            $check->execute([$sid]);
            if ($check->fetch()) {
                $msg = 'This supplier already has a linked login account.'; $msgType = 'warning';
            } else {
                $username = generateSupplierUsername($db, $supplier['supplier_name']);
                $tempPassword = generateTemporaryPassword(8);
                $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
                $fullName = trim($supplier['contact_person'] ?: $supplier['supplier_name']);

                $stmt = $db->prepare("INSERT INTO users
                    (full_name, username, password, role, branch, status, supplier_id, contact_number, email, address, must_change_password, created_by, created_at, updated_at)
                    VALUES (?, ?, ?, 'Supplier', 'Both', 'Active', ?, ?, ?, ?, 1, ?, NOW(), NOW())");
                $stmt->execute([
                    $fullName,
                    $username,
                    $hash,
                    $sid,
                    $supplier['contact_number'] ?? '',
                    $supplier['email'] ?? '',
                    $supplier['address'] ?? '',
                    (int)$u['id']
                ]);

                $_SESSION['anais_flash_alert'] = [
                    'type' => 'success',
                    'message' => 'Supplier account created. Username: '.$username.' | Temporary password: '.$tempPassword.' — Give this to the supplier. They must change it after login.'
                ];
                header('Location: /anais/suppliers.php');
                exit;
            }
        }
    }

    if ($action === 'reset_supplier_password') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $stmt = $db->prepare("SELECT u.user_id, u.username, u.role, u.supplier_id, s.supplier_name
            FROM users u
            LEFT JOIN suppliers s ON s.supplier_id=u.supplier_id
            WHERE u.user_id=? AND u.role='Supplier'
            LIMIT 1");
        $stmt->execute([$uid]);
        $account = $stmt->fetch();

        if (!$account) {
            $msg = 'Supplier account not found.'; $msgType = 'danger';
        } else {
            $tempPassword = generateTemporaryPassword(8);
            $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
            $db->prepare("UPDATE users SET password=?, must_change_password=1, status='Active', updated_at=NOW() WHERE user_id=?")
               ->execute([$hash, $uid]);

            $_SESSION['anais_flash_alert'] = [
                'type' => 'success',
                'message' => 'New temporary password for '.$account['username'].': '.$tempPassword.' — Give this to the supplier. They must change it after login.'
            ];
            header('Location: /anais/suppliers.php');
            exit;
        }
    }
}

// ── Fetch suppliers with linked login account info ─────────
$stmt = $db->query("SELECT
        s.*,
        u.user_id AS account_user_id,
        u.username AS account_username,
        u.status AS account_status,
        u.must_change_password,
        u.last_login_at
    FROM suppliers s
    LEFT JOIN users u ON u.supplier_id = s.supplier_id AND u.role = 'Supplier'
    ORDER BY s.supplier_name ASC");
$suppliers = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <div><h1>Suppliers</h1><p>Manage supplier records and see if each supplier has a login account.</p></div>
  <?php if (isOwner()): ?>
  <button class="btn btn-primary" onclick="openNewSupplier()">+ Add Supplier</button>
  <?php endif; ?>
</div>

<?php if ($msg): ?>
  <div class="alert alert-<?= $msgType ?>" data-auto-dismiss><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="card">
  <div class="card-header">
    <span class="card-title">Supplier List (<?= count($suppliers) ?>)</span>
    <input type="text" id="tableSearch" placeholder="🔍 Search…" style="width:220px">
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>#</th><th>Supplier Name</th><th>Contact Details</th><th>Supplier Status</th><th>Account Status</th><th>Username / Usage</th>
        <?php if (isOwner()): ?><th>Actions</th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php if (empty($suppliers)): ?>
        <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text-2)">No suppliers yet.</td></tr>
      <?php else: foreach ($suppliers as $i => $s): ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><strong><?= htmlspecialchars($s['supplier_name']) ?></strong><br>
              <small style="color:var(--text-2)"><?= htmlspecialchars(mb_strimwidth($s['address'] ?? '', 0, 55, '…')) ?></small></td>
          <td>
            <strong><?= htmlspecialchars($s['contact_person']) ?></strong><br>
            <small><?= htmlspecialchars($s['contact_number']) ?></small><br>
            <small><?= htmlspecialchars($s['email'] ?: 'No email') ?></small>
          </td>
          <td><span class="badge <?= $s['status']==='Active'?'badge-success':'badge-gray' ?>"><?= htmlspecialchars($s['status']) ?></span></td>
          <td>
            <?php if (!empty($s['account_user_id'])): ?>
              <span class="badge <?= $s['account_status']==='Active'?'badge-success':'badge-gray' ?>">Has Account</span><br>
              <small>Status: <?= htmlspecialchars($s['account_status']) ?></small>
            <?php else: ?>
              <span class="badge badge-warning">No Account</span><br>
              <small style="color:var(--text-2)">Create login if supplier will use portal.</small>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!empty($s['account_user_id'])): ?>
              <strong><?= htmlspecialchars($s['account_username']) ?></strong><br>
              <?php if ((int)($s['must_change_password'] ?? 0) === 1): ?>
                <small style="color:var(--warning)">Waiting for password change</small><br>
              <?php else: ?>
                <small style="color:var(--success)">Password changed</small><br>
              <?php endif; ?>
              <small style="color:var(--text-2)">
                Last login: <?= !empty($s['last_login_at']) ? date('M d, Y h:i A', strtotime($s['last_login_at'])) : 'Never' ?>
              </small>
            <?php else: ?>
              <span style="color:var(--text-2)">—</span>
            <?php endif; ?>
          </td>
          <?php if (isOwner()): ?>
          <td>
            <button class="btn btn-outline btn-sm"
              data-modal="supplierModal"
              data-edit='<?= htmlspecialchars(json_encode([
                'supplier_id'=>$s['supplier_id'],'supplier_name'=>$s['supplier_name'],
                'contact_person'=>$s['contact_person'],'contact_number'=>$s['contact_number'],
                'email'=>$s['email'],'address'=>$s['address'],'status'=>$s['status'],'remarks'=>$s['remarks']
              ]),ENT_QUOTES) ?>'>✏️ Edit</button>

            <?php if (empty($s['account_user_id'])): ?>
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="create_supplier_account">
                <input type="hidden" name="supplier_id" value="<?= (int)$s['supplier_id'] ?>">
                <button type="submit" class="btn btn-primary btn-sm"
                  onclick="return confirm('Create login account for this supplier? A temporary password will be generated.')">Create Account</button>
              </form>
            <?php else: ?>
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="reset_supplier_password">
                <input type="hidden" name="user_id" value="<?= (int)$s['account_user_id'] ?>">
                <button type="submit" class="btn btn-outline btn-sm"
                  onclick="return confirm('Generate a new temporary password for this supplier?')">Reset Password</button>
              </form>
            <?php endif; ?>

            <form method="POST" id="sdel<?= $s['supplier_id'] ?>" style="display:inline">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="supplier_id" value="<?= $s['supplier_id'] ?>">
              <button type="button" class="btn btn-danger btn-sm" onclick="confirmDelete('sdel<?= $s['supplier_id'] ?>','Deactivate this supplier and linked supplier account?')">Deactivate</button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (isOwner()): ?>
<div class="modal-backdrop hidden" id="supplierModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">Supplier Record</span>
      <button class="modal-close" onclick="closeModal('supplierModal')">×</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="supplier_id" id="edit_supplier_id" value="0">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group full">
            <label>Supplier / Business Name *</label>
            <input type="text" name="supplier_name" required>
          </div>
          <div class="form-group">
            <label>Contact Person *</label>
            <input type="text" name="contact_person" required>
          </div>
          <div class="form-group">
            <label>Contact Number *</label>
            <input type="text" name="contact_number" required>
          </div>
          <div class="form-group full">
            <label>Email</label>
            <input type="email" name="email">
          </div>
          <div class="form-group full">
            <label>Address *</label>
            <textarea name="address" required></textarea>
          </div>
          <div class="form-group">
            <label>Status</label>
            <select name="status">
              <option value="Active">Active</option>
              <option value="Inactive">Inactive</option>
            </select>
          </div>
          <div class="form-group full">
            <label>Remarks</label>
            <textarea name="remarks"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('supplierModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Supplier</button>
      </div>
    </form>
  </div>
</div>
<script>
function openNewSupplier() {
  document.getElementById('edit_supplier_id').value = '0';
  document.querySelector('#supplierModal form').reset();
  openModal('supplierModal');
}
document.addEventListener('click', function(e) {
  const btn = e.target.closest('[data-modal="supplierModal"]');
  if (btn && btn.dataset.edit) {
    const d = JSON.parse(btn.dataset.edit);
    document.getElementById('edit_supplier_id').value = d.supplier_id;
  }
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
