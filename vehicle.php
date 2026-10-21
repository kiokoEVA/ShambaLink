<?php
require_once 'auth.php';
$u = require_login('transporter');
$id = (int)($_GET['id'] ?? 0);
$veh = fetch_one('SELECT v.*, b.name AS base_name FROM vehicles v JOIN locations b ON b.id = v.base_location_id WHERE v.id = ? AND v.owner_id = ?', [$id, $u['id']]);
if (!$veh) { flash_set('Vehicle not found.', 'danger'); redirect('transporter_dashboard.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = $_POST['do'] ?? '';
    if ($a === 'add_range') {
        $f = trim($_POST['from'] ?? ''); $t = trim($_POST['to'] ?? '');
        if (valid_date($f) && valid_date($t) && $t >= $f && $t >= date('Y-m-d')) {
            db()->prepare('INSERT INTO vehicle_availability (vehicle_id, available_from, available_to) VALUES (?,?,?)')->execute([$id, $f, $t]);
            flash_set('Free period added.');
        } else flash_set('Choose dates where the end is not before the start, and not in the past.', 'danger');
    } elseif ($a === 'del_range') {
        db()->prepare('DELETE FROM vehicle_availability WHERE id = ? AND vehicle_id = ?')->execute([(int)($_POST['range_id'] ?? 0), $id]);
        flash_set('Free period removed.');
    } elseif ($a === 'toggle') {
        db()->prepare('UPDATE vehicles SET active = 1 - active WHERE id = ?')->execute([$id]);
        flash_set($veh['active'] ? 'Vehicle hidden. It will not appear in matches.' : 'Vehicle is active again.');
    }
    redirect('vehicle.php?id=' . $id);
}
$ranges = fetch_all('SELECT * FROM vehicle_availability WHERE vehicle_id = ? ORDER BY available_from', [$id]);
$title = $veh['label'];
$poll = '';
include 'includes/header.php';
?>
<a class="small" href="transporter_dashboard.php">&larr; Back to dashboard</a>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mt-2 mb-3">
  <div><h1 class="h3 mb-1"><?= e($veh['label']) ?></h1>
    <p class="text-muted mb-0"><?= number_format((int)$veh['capacity_kg']) ?> kg &middot; based in <?= e($veh['base_name']) ?><?= $veh['plate'] ? ' &middot; ' . e($veh['plate']) : '' ?></p></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="toggle">
    <button class="btn btn-outline-ink btn-sm"><?= $veh['active'] ? 'Hide from matches' : 'Make active again' ?></button></form>
</div>
<?= flash_get() ?>
<div class="row g-4">
  <div class="col-lg-7">
    <div class="panel">
      <h2 class="h5">When it is free</h2>
      <?php if (!$ranges): ?><p class="empty mt-3 mb-0">No free periods. Add one so this vehicle can be matched.</p>
      <?php else: ?><div class="ledger-wrap mt-3"><table class="ledger"><thead><tr><th>From</th><th>Until</th><th></th></tr></thead><tbody>
        <?php foreach ($ranges as $r): ?>
          <tr><td><?= e(fmt_date($r['available_from'])) ?></td><td><?= e(fmt_date($r['available_to'])) ?></td>
            <td class="text-end"><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="do" value="del_range"><input type="hidden" name="range_id" value="<?= (int)$r['id'] ?>"><button class="btn btn-outline-ink btn-sm">Remove</button></form></td></tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div>
  </div>
  <div class="col-lg-5">
    <form method="post" class="panel"><?= csrf_field() ?><input type="hidden" name="do" value="add_range">
      <h2 class="h5">Add a free period</h2>
      <label class="form-label mt-2" for="from">Free from</label>
      <input class="form-control mb-3" id="from" name="from" type="date" min="<?= date('Y-m-d') ?>" required>
      <label class="form-label" for="to">Free until</label>
      <input class="form-control mb-3" id="to" name="to" type="date" min="<?= date('Y-m-d') ?>" required>
      <button class="btn btn-crate w-100">Add period</button>
    </form>
  </div>
</div>
<?php include 'includes/footer.php'; ?>
