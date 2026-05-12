<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pageTitle = 'Suppliers';
$activePage = 'suppliers';
$db = getDB();
$u  = currentUser();
$msg = ''; $msgType = '';

// ── CRUD ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole(['Owner']);
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $sid  = (int)($_POST['supplier_id'] ?? 0);
        $data = [
            'supplier_name'  => trim($_POST['supplier_name']),
            'contact_person' => trim($_POST['contact_person']),
            'contact_number' => trim($_POST['contact_number']),
            'email'          => trim($_POST['email']),
            'address'        => trim($_POST['address']),
            'status'         => $_POST['status'] ?? 'Active',
            'remarks'        => trim($_POST['remarks']),
        ];
        if ($sid) {
            $db->prepare("UPDATE suppliers SET supplier_name=?,contact_person=?,contact_number=?,email=?,address=?,status=?,remarks=?,updated_at=NOW() WHERE supplier_id=?")
               ->execute(array_merge(array_values($data), [$sid]));
            $msg = 'Supplier updated successfully.'; $msgType = 'success';
        } else {
            $db->prepare("INSERT INTO suppliers (supplier_name,contact_person,contact_number,email,address,status,remarks,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())")
               ->execute([$data['supplier_name'],$data['contact_person'],$data['contact_number'],$data['email'],$data['address'],$data['status'],$data['remarks'],$u['id']]);
            $msg = 'Supplier added successfully.'; $msgType = 'success';
        }
    }

    if ($action === 'delete') {
        $sid = (int)$_POST['supplier_id'];
        $db->prepare("UPDATE suppliers SET status='Inactive' WHERE supplier_id=?")->execute([$sid]);
        $msg = 'Supplier deactivated.'; $msgType = 'success';
    }
}

// ── Fetch ─────────────────────────────────────────────────
$stmt = $db->query("SELECT * FROM suppliers ORDER BY supplier_name ASC");
$suppliers = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <div><h1>Suppliers</h1><p>Manage supplier records.</p></div>
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
        <th>#</th><th>Supplier Name</th><th>Contact Person</th><th>Contact No.</th>
        <th>Email</th><th>Status</th>
        <?php if (isOwner()): ?><th>Actions</th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php if (empty($suppliers)): ?>
        <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text-2)">No suppliers yet.</td></tr>
      <?php else: foreach ($suppliers as $i => $s): ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><strong><?= htmlspecialchars($s['supplier_name']) ?></strong><br>
              <small style="color:var(--text-2)"><?= htmlspecialchars(substr($s['address'],0,50)) ?>…</small></td>
          <td><?= htmlspecialchars($s['contact_person']) ?></td>
          <td><?= htmlspecialchars($s['contact_number']) ?></td>
          <td><?= htmlspecialchars($s['email'] ?: '—') ?></td>
          <td><span class="badge <?= $s['status']==='Active'?'badge-success':'badge-gray' ?>"><?= $s['status'] ?></span></td>
          <?php if (isOwner()): ?>
          <td>
            <button class="btn btn-outline btn-sm"
              data-modal="supplierModal"
              data-edit='<?= htmlspecialchars(json_encode([
                'supplier_id'=>$s['supplier_id'],'supplier_name'=>$s['supplier_name'],
                'contact_person'=>$s['contact_person'],'contact_number'=>$s['contact_number'],
                'email'=>$s['email'],'address'=>$s['address'],'status'=>$s['status'],'remarks'=>$s['remarks']
              ]),ENT_QUOTES) ?>'>✏️ Edit</button>
            <form method="POST" id="sdel<?= $s['supplier_id'] ?>" style="display:inline">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="supplier_id" value="<?= $s['supplier_id'] ?>">
              <button type="button" class="btn btn-danger btn-sm" onclick="confirmDelete('sdel<?= $s['supplier_id'] ?>')">🗑</button>
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
