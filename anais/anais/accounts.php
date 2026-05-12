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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_account') {
        // Owner no longer creates accounts manually.
        // Employee/Staff and OIC accounts must be requested from the Employee/Staff login page.
        // Supplier accounts must be registered from the Supplier tab.
        $msg = 'Manual account creation is disabled. Users must register/request their own account first.';
        $msgType = 'warning';
    }


    if ($action === 'update_account') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $role = $_POST['role'] ?? '';
        $branch = $_POST['branch'] ?? '';
        $contact_number = trim($_POST['contact_number'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $supplier_name = trim($_POST['supplier_name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');

        $stmt=$db->prepare("SELECT * FROM users WHERE user_id=? LIMIT 1"); $stmt->execute([$uid]); $acct=$stmt->fetch();
        if (!$acct) { $msg='Account not found.'; $msgType='danger'; }
        else {
            if (($acct['role'] ?? '') === 'Supplier') {
                // Supplier account: Owner can only edit branch and supplier details, not username/password/role.
                $role = 'Supplier';
            }
            if (!in_array($role, ['Owner','OIC','Employee','Supplier'], true) || !in_array($branch, ['Autobox','Autophoria','Both'], true)) {
                $msg='Invalid role or branch.'; $msgType='danger';
            } elseif ($email!=='' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $msg='Please enter a valid email address.'; $msgType='danger';
            } else {
                $sid = $acct['supplier_id'] ?? null;
                if ($role === 'Supplier') {
                    $sid = acctEnsureSupplierRecord($db, [
                        'role'=>'Supplier','supplier_id'=>$sid,'supplier_name'=>$supplier_name ?: ($acct['full_name'] ?? 'Supplier'),
                        'full_name'=>$contact_person ?: ($acct['full_name'] ?? ''),'username'=>$acct['username'],
                        'contact_number'=>$contact_number,'email'=>$email,'address'=>$address
                    ], (int)$u['id']);
                } else { $sid = null; }

                $db->prepare("UPDATE users SET role=?, branch=?, supplier_id=?, contact_number=?, email=?, address=?, updated_at=NOW() WHERE user_id=?")
                   ->execute([$role,$branch,$sid,$contact_number,$email,$address,$uid]);
                if ($role === 'Supplier' && $sid) {
                    $db->prepare("UPDATE suppliers SET supplier_name=COALESCE(NULLIF(?,''),supplier_name), contact_person=COALESCE(NULLIF(?,''),contact_person), contact_number=?, email=?, address=?, updated_at=NOW() WHERE supplier_id=?")
                       ->execute([$supplier_name,$contact_person,$contact_number,$email,$address,$sid]);
                }
                $msg='Account updated. Only role, branch, status, and contact/supplier details are editable here.'; $msgType='success';
            }
        }
    }



    if ($action === 'delete_account') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid === (int)$u['id']) { $msg='You cannot delete your own account.'; $msgType='danger'; }
        else {
            $stmt=$db->prepare("SELECT role, supplier_id FROM users WHERE user_id=? LIMIT 1"); $stmt->execute([$uid]); $target=$stmt->fetch();
            if (!$target) { $msg='Account not found.'; $msgType='danger'; }
            else {
                try {
                    if (($target['role'] ?? '') === 'Supplier') {
                        $db->prepare("UPDATE users SET status='Inactive', updated_at=NOW() WHERE user_id=?")->execute([$uid]);
                        if (!empty($target['supplier_id'])) $db->prepare("UPDATE suppliers SET status='Inactive', updated_at=NOW() WHERE supplier_id=?")->execute([(int)$target['supplier_id']]);
                        $msg='Supplier account is linked to supplier records, so it was deactivated instead of deleted.'; $msgType='warning';
                    } else {
                        $db->prepare("DELETE FROM users WHERE user_id=?")->execute([$uid]);
                        $msg='Account deleted successfully.'; $msgType='success';
                    }
                } catch (Throwable $e) {
                    $db->prepare("UPDATE users SET status='Inactive', updated_at=NOW() WHERE user_id=?")->execute([$uid]);
                    $msg='Account has linked records, so it was deactivated instead of deleted.'; $msgType='warning';
                }
            }
        }
    }

    if ($action === 'toggle_status') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid === (int)$u['id']) { $msg='You cannot deactivate your own account.'; $msgType='danger'; }
        else {
            $stmt=$db->prepare("SELECT status, supplier_id FROM users WHERE user_id=? LIMIT 1"); $stmt->execute([$uid]); $row=$stmt->fetch();
            if (!$row) { $msg='Account not found.'; $msgType='danger'; }
            else {
                $new = ($row['status'] ?? 'Inactive') === 'Active' ? 'Inactive' : 'Active';
                $db->prepare("UPDATE users SET status=?, updated_at=NOW() WHERE user_id=?")->execute([$new,$uid]);
                if (!empty($row['supplier_id'])) $db->prepare("UPDATE suppliers SET status=?, updated_at=NOW() WHERE supplier_id=?")->execute([$new,(int)$row['supplier_id']]);
                $msg='Account status set to '.$new.'.'; $msgType='success';
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

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <div><h1>Account Management</h1><p>View account requests, activate/deactivate accounts, edit role/branch/contact details, or delete accounts. Manual account creation is disabled.</p></div>
</div>
<?php if ($msg): ?><div class="alert alert-<?= $msgType ?>" data-auto-dismiss><?= htmlspecialchars($msg) ?></div><?php endif; ?>

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
    <button class="btn btn-outline btn-sm" data-modal="userModal" data-edit='<?= htmlspecialchars(json_encode($usr), ENT_QUOTES) ?>'>✏️ Edit</button>
    <?php if($usr['user_id'] !== (int)$u['id']): ?>
    <form method="POST" style="display:inline"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="user_id" value="<?= (int)$usr['user_id'] ?>"><button class="btn btn-sm <?= $usr['status']==='Active'?'btn-danger':'btn-primary' ?>" type="submit"><?= $usr['status']==='Active'?'Deactivate':'Activate' ?></button></form>
    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this account? If it has linked records, it will be deactivated instead.')"><input type="hidden" name="action" value="delete_account"><input type="hidden" name="user_id" value="<?= (int)$usr['user_id'] ?>"><button class="btn btn-danger btn-sm" type="submit">Delete</button></form>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table></div></div>

<div class="modal-backdrop hidden" id="userModal"><div class="modal"><div class="modal-header"><span class="modal-title">Account Details</span><button class="modal-close" onclick="closeModal('userModal')">×</button></div>
<form method="POST"><input type="hidden" name="action" id="account_action" value="update_account"><input type="hidden" name="user_id" value="0">
<div class="modal-body"><div class="form-grid">
  <div class="form-group full"><label>Full Name *</label><input type="text" name="full_name" required></div>
  <div class="form-group"><label>Username * <small>(unique)</small></label><input type="text" name="username" required></div>
  <div class="form-group"><label>Role *</label><select name="role"><option>Employee</option><option>OIC</option><option>Owner</option><option>Supplier</option></select></div>
  <div class="form-group"><label>Branch *</label><select name="branch"><option>Autobox</option><option>Autophoria</option><option>Both</option></select></div>
  <div class="form-group"><label>Contact Number</label><input type="text" name="contact_number"></div>
  <div class="form-group"><label>Email</label><input type="email" name="email"></div>
  <div class="form-group full"><label>Address</label><textarea name="address"></textarea></div>
  <div class="form-group full supplier-only"><label>Supplier / Business Name</label><input type="text" name="supplier_name"></div>
  <div class="form-group full supplier-only"><label>Supplier Contact Person</label><input type="text" name="contact_person"></div>
</div></div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('userModal')">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
</form></div></div>
<script>
function toggleSupplierFields(){
  const modal=document.getElementById('userModal'); const role=modal.querySelector('[name="role"]').value;
  modal.querySelectorAll('.supplier-only').forEach(el=>el.style.display= role==='Supplier' ? '' : 'none');
}
document.addEventListener('change', e=>{ if(e.target && e.target.matches('#userModal [name="role"]')) toggleSupplierFields(); });
document.addEventListener('click', function(e){
  const btn=e.target.closest('[data-modal="userModal"]'); if(!btn || !btn.dataset.edit) return;
  const d=JSON.parse(btn.dataset.edit); const m=document.getElementById('userModal');
  m.querySelector('#account_action').value='update_account';
  ['user_id','full_name','username','role','branch','contact_number','email','address','supplier_name','contact_person'].forEach(k=>{ const el=m.querySelector('[name="'+k+'"]'); if(el) el.value=d[k]||''; });
  m.querySelector('[name="username"]').disabled=true; m.querySelector('[name="full_name"]').disabled=true;
  if(d.role==='Supplier') { m.querySelector('[name="role"]').disabled=true; } else { m.querySelector('[name="role"]').disabled=false; }
  toggleSupplierFields();
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
