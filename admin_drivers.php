<?php
require_once 'auth.php';
$u = require_login('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $driver = fetch_one("SELECT id, name FROM users WHERE id = ? AND role = 'transporter'", [$id]);
    if (!$driver) {
        flash_set('Transporter not found.', 'danger');
    } elseif ($action === 'approve') {
        db()->prepare("UPDATE users SET driver_status = 'approved', driver_note = NULL, driver_reviewed_at = NOW() WHERE id = ?")->execute([$id]);
        flash_set($driver['name'] . ' is now an approved driver.');
    } elseif ($action === 'reject') {
        $note = trim($_POST['note'] ?? '');
        if ($note === '') {
            flash_set('Give a reason so the driver knows what to fix.', 'danger');
        } else {
            db()->prepare("UPDATE users SET driver_status = 'rejected', driver_note = ?, driver_reviewed_at = NOW() WHERE id = ?")->execute([mb_substr($note, 0, 255), $id]);
            flash_set($driver['name'] . ' was rejected.');
        }
    }
    redirect('admin_drivers.php');
}

$types = ['national_id' => 'National ID or passport', 'driving_licence' => 'Driving licence', 'insurance' => 'Vehicle insurance', 'vehicle_inspection' => 'Vehicle inspection (stamped)'];
$drivers = fetch_all("SELECT id, name, phone, email, national_id, driver_status, driver_note FROM users WHERE role = 'transporter'
                      ORDER BY FIELD(driver_status, 'pending', 'approved', 'rejected', 'unverified'), name");
$docs = [];
foreach (fetch_all('SELECT id, user_id, doc_type, original_name, expiry_date FROM driver_documents') as $d) $docs[$d['user_id']][] = $d;
$labels = ['unverified' => 'Not submitted', 'pending' => 'Waiting for review', 'approved' => 'Approved', 'rejected' => 'Rejected'];
$today = date('Y-m-d');

$title = 'Driver approvals';
$poll = '';
include 'includes/header.php';
?>
<h1 class="h3 mb-1">Driver approvals</h1>
<p class="text-muted mb-3">Open each document and check it is valid. The inspection must be approved and stamped. Then approve or reject the driver.</p>
<?= flash_get() ?>
<?php if (!$drivers): ?><div class="empty">No transporters have registered yet.</div><?php endif; ?>
<?php foreach ($drivers as $d): ?>
  <div class="load-item">
    <div class="d-flex justify-content-between flex-wrap gap-2">
      <div><b><?= e($d['name']) ?></b> <span class="text-muted small">&middot; <?= e($d['phone']) ?> &middot; <?= e($d['email']) ?></span><br>
        <span class="small">ID number: <b><?= e($d['national_id'] ?: 'not provided') ?></b></span></div>
      <span class="badge text-bg-<?= ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'][$d['driver_status']] ?? 'secondary' ?>"><?= e($labels[$d['driver_status']] ?? $d['driver_status']) ?></span>
    </div>
    <div class="small mt-2">
      <?php if (empty($docs[$d['id']])): ?><span class="text-muted">No documents uploaded.</span>
      <?php else: foreach ($docs[$d['id']] as $doc): $expired = $doc['expiry_date'] && $doc['expiry_date'] < $today; ?>
        <div><a href="view_document.php?id=<?= (int)$doc['id'] ?>" target="_blank"><?= e($types[$doc['doc_type']] ?? $doc['doc_type']) ?></a>
          <?php if ($doc['expiry_date']): ?><span class="<?= $expired ? 'text-danger' : 'text-muted' ?>"> &middot; <?= $expired ? 'EXPIRED' : 'expires' ?> <?= e(fmt_date($doc['expiry_date'])) ?></span><?php endif; ?></div>
      <?php endforeach; endif; ?>
    </div>
    <?php if ($d['driver_status'] === 'rejected' && $d['driver_note']): ?><div class="small text-muted mt-1">Reason: <?= e($d['driver_note']) ?></div><?php endif; ?>
    <?php if (in_array($d['driver_status'], ['pending', 'approved'], true)): ?>
      <div class="d-flex gap-2 flex-wrap mt-3 align-items-center">
        <?php if ($d['driver_status'] === 'pending'): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="action" value="approve"><button class="btn btn-crate btn-sm">Approve</button></form>
        <?php endif; ?>
        <form method="post" class="d-flex gap-2"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="action" value="reject">
          <input class="form-control form-control-sm" name="note" maxlength="255" placeholder="Reason" required>
          <button class="btn btn-outline-ink btn-sm"><?= $d['driver_status'] === 'approved' ? 'Revoke' : 'Reject' ?></button></form>
      </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
<?php include 'includes/footer.php'; ?>