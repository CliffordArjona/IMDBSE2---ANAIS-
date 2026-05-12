<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
requireRole(['Owner']);

$pageTitle = 'Account Management';
$activePage = 'accounts';
$db = getDB();
$u  = currentUser();
$msg = ''; $msgType = '';

function acctColumnExists(PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}
function acctEnsureColumns(PDO $db): void {
    $cols = [
        'contact_number' => "ALTER TABLE users ADD COLUMN contact_number VARCHAR(30) NULL AFTER supplier_id",
        'email' => "ALTER TABLE users ADD COLUMN email VARCHAR(150) NULL AFTER contact_number",
        'address' => "ALTER TABLE users ADD COLUMN address TEXT NULL AFTER email",
        'must_change_password' => "ALTER TABLE users ADD COLUMN must_change_password TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER address",
    ];
    foreach ($cols as $c => $sql) {
        if (!acctColumnExists($db, 'users', $c)) { try { $db->exec($sql); } catch(Throwable $e) {} }
    }
}
function acctGeneratePassword(int $length = 8): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $p = 'TEMP-';
    for ($i=0; $i<$length; $i++) $p .= $alphabet[random_int(0, strlen($alphabet)-1)];
    return $p;
}

function acctEnsurePasswordResetTable(PDO $db): void {
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
    } catch (Throwable $e) {}

    if (!acctColumnExists($db, 'password_reset_requests', 'temporary_password_shown')) {
        try { $db->exec("ALTER TABLE password_reset_requests ADD COLUMN temporary_password_shown VARCHAR(50) DEFAULT NULL AFTER processed_by"); } catch(Throwable $e) {}
    }
}
function acctEnsureSupplierRecord(PDO $db, array $data, int $createdBy): ?int {
    if (($data['role'] ?? '') !== 'Supplier') return null;
    $sid = (int)($data['supplier_id'] ?? 0);
    if ($sid > 0) return $sid;
    $supplierName = trim($data['supplier_name'] ?? $data['full_name'] ?? $data['username'] ?? 'Supplier');
    $check = $db->prepare("SELECT supplier_id FROM suppliers WHERE supplier_name=? LIMIT 1");
    $check->execute([$supplierName]);
    $row = $check->fetch();
    if ($row) return (int)$row['supplier_id'];
    $stmt = $db->prepare("INSERT INTO suppliers (supplier_name,contact_person,contact_number,email,address,status,remarks,created_by,created_at,updated_at) VALUES (?,?,?,?,?,'Active',?, ?, NOW(), NOW())");
    $stmt->execute([
        $supplierName,
        trim($data['full_name'] ?? $supplierName),
        trim($data['contact_number'] ?? ''),
        trim($data['email'] ?? ''),
        trim($data['address'] ?? ''),
        'Created from Account Management.',
        $createdBy
    ]);
    return (int)$db->lastInsertId();
}

acctEnsureColumns($db);
acctEnsurePasswordResetTable($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'generate_temp_password') {
        $request_id = (int)($_POST['request_id'] ?? 0);

        if ($request_id <= 0) {
            $msg = 'Invalid password reset request.';
            $msgType = 'danger';
        } else {
            try {
                $stmt = $db->prepare("SELECT pr.*, u.user_id, u.role, u.full_name, u.username
                    FROM password_reset_requests pr
                    JOIN users u ON u.user_id = pr.user_id
                    WHERE pr.request_id = ? AND pr.status = 'Pending'
                    LIMIT 1");
                $stmt->execute([$request_id]);
                $req = $stmt->fetch();

                if (!$req) {
                    $msg = 'Password reset request not found or already processed.';
                    $msgType = 'danger';
                } elseif (!in_array(($req['role'] ?? ''), ['Employee','OIC','Supplier'], true)) {
                    $msg = 'Only Employee, OIC, and Supplier reset requests are allowed here.';
                    $msgType = 'danger';
                } else {
                    $tempPassword = acctGeneratePassword(8);
                    $hash = password_hash($tempPassword, PASSWORD_DEFAULT);

                    $db->beginTransaction();
                    $db->prepare("UPDATE users SET password=?, must_change_password=1, updated_at=NOW() WHERE user_id=?")
                       ->execute([$hash, (int)$req['user_id']]);
                    $db->prepare("UPDATE password_reset_requests
                        SET status='Processed', processed_at=NOW(), processed_by=?, temporary_password_shown=?
                        WHERE request_id=?")
                       ->execute([(int)$u['id'], $tempPassword, $request_id]);
                    $db->commit();

                    $msg = 'Temporary password generated for '.$req['username'].': '.$tempPassword.' — Give this to the user. They must change it after login.';
                    $msgType = 'success';
                }
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                $msg = 'Unable to generate temporary password. Please run the latest database update SQL first.';
                $msgType = 'danger';
            }
        }
    } else {
        $uid = (int)($_POST['user_id'] ?? 0);

        if ($uid <= 0) {
            $msg = 'Invalid account.';
            $msgType = 'danger';
        } elseif ($uid === (int)$u['id']) {
            $msg = 'You cannot change your own Owner account here.';
            $msgType = 'warning';
        } else {
            $stmt = $db->prepare("SELECT user_id, role, status, supplier_id FROM users WHERE user_id=? LIMIT 1");
            $stmt->execute([$uid]);
            $acct = $stmt->fetch();

            if (!$acct) {
                $msg = 'Account not found.';
                $msgType = 'danger';
            } elseif ($action === 'activate_account' || $action === 'deactivate_account') {
            $newStatus = $action === 'activate_account' ? 'Active' : 'Inactive';
            $db->beginTransaction();
            try {
                $db->prepare("UPDATE users SET status=?, updated_at=NOW() WHERE user_id=?")
                   ->execute([$newStatus, $uid]);

                if (!empty($acct['supplier_id'])) {
                    $db->prepare("UPDATE suppliers SET status=?, updated_at=NOW() WHERE supplier_id=?")
                       ->execute([$newStatus, (int)$acct['supplier_id']]);
                }

                $db->commit();
                $msg = 'Account set to ' . $newStatus . ' successfully.';
                $msgType = 'success';
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                $msg = 'Unable to update account status. Please try again.';
                $msgType = 'danger';
            }
        } elseif ($action === 'delete_account') {
            try {
                $db->beginTransaction();

                if (!empty($acct['supplier_id'])) {
                    $db->prepare("UPDATE suppliers SET status='Inactive', updated_at=NOW() WHERE supplier_id=?")
                       ->execute([(int)$acct['supplier_id']]);
                }

                $db->prepare("DELETE FROM users WHERE user_id=?")
                   ->execute([$uid]);

                $db->commit();
                $msg = 'Account deleted successfully.';
                $msgType = 'success';
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }

                // If the account is linked to records, keep data safe by deactivating instead of breaking history.
                try {
                    $db->prepare("UPDATE users SET status='Inactive', updated_at=NOW() WHERE user_id=?")
                       ->execute([$uid]);
                    if (!empty($acct['supplier_id'])) {
                        $db->prepare("UPDATE suppliers SET status='Inactive', updated_at=NOW() WHERE supplier_id=?")
                           ->execute([(int)$acct['supplier_id']]);
                    }
                    $msg = 'Account has linked records, so it was deactivated instead of deleted.';
                    $msgType = 'warning';
                } catch (Throwable $e2) {
                    $msg = 'Unable to delete or deactivate account. Please try again.';
                    $msgType = 'danger';
                }
            }
        } else {
            $msg = 'Invalid account action.';
            $msgType = 'warning';
        }
        }
    }
}

$filterBranch = $_GET['branch'] ?? '';
$filterRole = $_GET['role'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');
$where=[]; $params=[];
if (in_array($filterBranch, ['Autobox','Autophoria','Both'], true)) { $where[]='u.branch=?'; $params[]=$filterBranch; }
if (in_array($filterRole, ['Owner','OIC','Employee','Supplier'], true)) { $where[]='u.role=?'; $params[]=$filterRole; }
if (in_array($filterStatus, ['Active','Inactive'], true)) { $where[]='u.status=?'; $params[]=$filterStatus; }
if ($search !== '') { $where[]='(u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR s.supplier_name LIKE ?)'; $like='%'.$search.'%'; array_push($params,$like,$like,$like,$like); }
$sql="SELECT u.*, s.supplier_name, s.contact_person FROM users u LEFT JOIN suppliers s ON s.supplier_id=u.supplier_id" . ($where ? ' WHERE '.implode(' AND ',$where) : '') . " ORDER BY u.status, u.role, u.full_name";
$stmt=$db->prepare($sql); $stmt->execute($params); $users=$stmt->fetchAll();

$resetRequests = [];
try {
    $resetStmt = $db->query("SELECT pr.*, u.full_name, u.must_change_password, u.status AS account_status
        FROM password_reset_requests pr
        LEFT JOIN users u ON u.user_id = pr.user_id
        WHERE pr.status = 'Pending'
           OR (pr.status = 'Processed' AND u.must_change_password = 1)
        ORDER BY pr.requested_at DESC");
    $resetRequests = $resetStmt->fetchAll();
} catch (Throwable $e) {
    $resetRequests = [];
}

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <div><h1>Account Management</h1><p>Owner can view contact details, activate/deactivate, and delete accounts. Passwords are never shown.</p></div>
</div>
<?php if ($msg): ?><div class="alert alert-<?= $msgType ?>" data-auto-dismiss><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<?php if (!empty($resetRequests)): ?>
<div class="card" style="margin-bottom:16px">
  <div class="card-header">
    <span class="card-title">Password Reset Requests (<?= count($resetRequests) ?>)</span>
    <small style="color:var(--text-2)">For users who forgot their temporary password. Password disappears after change.</small>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Name</th>
          <th>Username</th>
          <th>Role</th>
          <th>Requested At</th>
          <th>Status / Note</th>
          <th>Temporary Password</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($resetRequests as $req): ?>
        <tr>
          <td><strong><?= htmlspecialchars($req['full_name'] ?? '—') ?></strong></td>
          <td><?= htmlspecialchars($req['username'] ?? '') ?></td>
          <td><span class="badge badge-info"><?= htmlspecialchars($req['role'] ?? '') ?></span></td>
          <td><?= !empty($req['requested_at']) ? date('M d, Y h:i A', strtotime($req['requested_at'])) : '—' ?></td>
          <td>
            <?php if (($req['status'] ?? '') === 'Pending'): ?>
              <span class="badge badge-warning">Pending Request</span>
              <br><small style="color:var(--text-2)">Generate a new temporary password.</small>
            <?php else: ?>
              <span class="badge badge-info">Waiting for Password Change</span>
              <br><small style="color:var(--text-2)">User must login and create a new password.</small>
            <?php endif; ?>
          </td>
          <td>
            <?php if (($req['status'] ?? '') === 'Processed' && (int)($req['must_change_password'] ?? 0) === 1): ?>
              <code style="font-weight:700;font-size:13px"><?= htmlspecialchars($req['temporary_password_shown'] ?? 'Temporary password not stored') ?></code>
            <?php else: ?>
              <span style="color:var(--text-2)">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if (($req['status'] ?? '') === 'Pending'): ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('Generate a new temporary password for this user?')">
              <input type="hidden" name="action" value="generate_temp_password">
              <input type="hidden" name="request_id" value="<?= (int)$req['request_id'] ?>">
              <button type="submit" class="btn btn-primary btn-sm">Generate Temporary Password</button>
            </form>
            <?php else: ?>
              <span style="color:var(--text-2);font-size:12px">Auto-removes after password change</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px"><div class="card-body">
  <form method="GET" class="filter-bar">
    <input type="text" name="q" placeholder="Search name, username, email…" value="<?= htmlspecialchars($search) ?>">
    <select name="branch"><option value="">All Branches</option><?php foreach(['Autobox','Autophoria','Both'] as $b): ?><option value="<?= $b ?>" <?= $filterBranch===$b?'selected':'' ?>><?= $b ?></option><?php endforeach; ?></select>
    <select name="role"><option value="">All Roles</option><?php foreach(['Owner','OIC','Employee','Supplier'] as $r): ?><option value="<?= $r ?>" <?= $filterRole===$r?'selected':'' ?>><?= $r ?></option><?php endforeach; ?></select>
    <select name="status"><option value="">All Status</option><?php foreach(['Active','Inactive'] as $s): ?><option value="<?= $s ?>" <?= $filterStatus===$s?'selected':'' ?>><?= $s ?></option><?php endforeach; ?></select>
    <button class="btn btn-primary" type="submit">Filter</button>
    <a class="btn btn-outline" href="/anais/accounts.php">Clear</a>
  </form>
</div></div>

<div class="card"><div class="card-header"><span class="card-title">User Accounts (<?= count($users) ?>)</span></div><div class="table-wrap"><table>
<thead><tr><th>#</th><th>Name</th><th>Username</th><th>Contact</th><th>Role</th><th>Branch</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach($users as $i=>$usr): ?>
<tr>
  <td><?= $i+1 ?></td>
  <td><strong><?= htmlspecialchars($usr['full_name']) ?></strong><?php if($usr['supplier_name']): ?><br><small><?= htmlspecialchars($usr['supplier_name']) ?></small><?php endif; ?></td>
  <td><?= htmlspecialchars($usr['username']) ?></td>
  <td><?= htmlspecialchars($usr['contact_number'] ?? '—') ?><br><small><?= htmlspecialchars($usr['email'] ?? '') ?></small></td>
  <td><span class="badge badge-info"><?= htmlspecialchars($usr['role']) ?></span></td>
  <td><?= htmlspecialchars($usr['branch']) ?></td>
  <td><span class="badge <?= $usr['status']==='Active'?'badge-success':'badge-gray' ?>"><?= htmlspecialchars($usr['status']) ?></span></td>
  <td>
    <button type="button" class="btn btn-outline btn-sm account-details-btn"
      data-full-name="<?= htmlspecialchars($usr['full_name'] ?? '', ENT_QUOTES) ?>"
      data-username="<?= htmlspecialchars($usr['username'] ?? '', ENT_QUOTES) ?>"
      data-role="<?= htmlspecialchars($usr['role'] ?? '', ENT_QUOTES) ?>"
      data-branch="<?= htmlspecialchars($usr['branch'] ?? '', ENT_QUOTES) ?>"
      data-status="<?= htmlspecialchars($usr['status'] ?? '', ENT_QUOTES) ?>"
      data-contact-number="<?= htmlspecialchars($usr['contact_number'] ?? '', ENT_QUOTES) ?>"
      data-email="<?= htmlspecialchars($usr['email'] ?? '', ENT_QUOTES) ?>"
      data-address="<?= htmlspecialchars($usr['address'] ?? '', ENT_QUOTES) ?>"
      data-supplier-name="<?= htmlspecialchars($usr['supplier_name'] ?? '', ENT_QUOTES) ?>"
      data-contact-person="<?= htmlspecialchars($usr['contact_person'] ?? '', ENT_QUOTES) ?>">
      View Details
    </button>
    <?php if($usr['user_id'] !== (int)$u['id']): ?>
      <?php if(($usr['status'] ?? '') !== 'Active'): ?>
        <form method="POST" style="display:inline" onsubmit="return confirm('Activate this account?')">
          <input type="hidden" name="action" value="activate_account">
          <input type="hidden" name="user_id" value="<?= (int)$usr['user_id'] ?>">
          <button class="btn btn-primary btn-sm" type="submit">Activate</button>
        </form>
      <?php else: ?>
        <form method="POST" style="display:inline" onsubmit="return confirm('Deactivate this account?')">
          <input type="hidden" name="action" value="deactivate_account">
          <input type="hidden" name="user_id" value="<?= (int)$usr['user_id'] ?>">
          <button class="btn btn-outline btn-sm" type="submit">Deactivate</button>
        </form>
      <?php endif; ?>
      <form method="POST" style="display:inline" onsubmit="return confirm('Delete this account? If it has linked records, it will be deactivated instead to keep history safe.')">
        <input type="hidden" name="action" value="delete_account">
        <input type="hidden" name="user_id" value="<?= (int)$usr['user_id'] ?>">
        <button class="btn btn-danger btn-sm" type="submit">Delete</button>
      </form>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table></div></div>


<!-- View-only Account Details Modal -->
<div class="modal-backdrop hidden" id="accountDetailsModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">Account Contact Details</span>
      <button class="modal-close" type="button" onclick="closeModal('accountDetailsModal')">×</button>
    </div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="form-group full">
          <label>Full Name</label>
          <input type="text" id="detail_full_name" readonly>
        </div>
        <div class="form-group">
          <label>Username</label>
          <input type="text" id="detail_username" readonly>
        </div>
        <div class="form-group">
          <label>Role</label>
          <input type="text" id="detail_role" readonly>
        </div>
        <div class="form-group">
          <label>Branch</label>
          <input type="text" id="detail_branch" readonly>
        </div>
        <div class="form-group">
          <label>Status</label>
          <input type="text" id="detail_status" readonly>
        </div>
        <div class="form-group">
          <label>Contact Number</label>
          <input type="text" id="detail_contact_number" readonly>
        </div>
        <div class="form-group">
          <label>Email</label>
          <input type="text" id="detail_email" readonly>
        </div>
        <div class="form-group full">
          <label>Address</label>
          <textarea id="detail_address" readonly></textarea>
        </div>
        <div class="form-group full" id="detail_supplier_group" style="display:none">
          <label>Supplier Details</label>
          <input type="text" id="detail_supplier_name" readonly style="margin-bottom:8px">
          <input type="text" id="detail_contact_person" readonly>
        </div>
      </div>
      <p style="margin-top:14px;color:var(--text-2);font-size:12px">
        Password is hidden for privacy. Owner cannot edit account details here.
      </p>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline" onclick="closeModal('accountDetailsModal')">Close</button>
    </div>
  </div>
</div>

<script>
document.addEventListener('click', function(e) {
  const btn = e.target.closest('.account-details-btn');
  if (!btn) return;

  const setValue = (id, value) => {
    const el = document.getElementById(id);
    if (el) el.value = value || '—';
  };

  setValue('detail_full_name', btn.dataset.fullName);
  setValue('detail_username', btn.dataset.username);
  setValue('detail_role', btn.dataset.role);
  setValue('detail_branch', btn.dataset.branch);
  setValue('detail_status', btn.dataset.status);
  setValue('detail_contact_number', btn.dataset.contactNumber);
  setValue('detail_email', btn.dataset.email);
  setValue('detail_address', btn.dataset.address);
  setValue('detail_supplier_name', btn.dataset.supplierName ? 'Supplier: ' + btn.dataset.supplierName : '');
  setValue('detail_contact_person', btn.dataset.contactPerson ? 'Contact Person: ' + btn.dataset.contactPerson : '');

  const supplierGroup = document.getElementById('detail_supplier_group');
  if (supplierGroup) {
    supplierGroup.style.display = (btn.dataset.supplierName || btn.dataset.contactPerson) ? '' : 'none';
  }

  openModal('accountDetailsModal');
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
