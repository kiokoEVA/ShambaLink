<?php
require_once 'auth.php';
$u = require_login('transporter');
$err = [];
$v = ['label' => '', 'plate' => '', 'capacity_kg' => '', 'base' => (int)($u['location_id'] ?? 0), 'from' => date('Y-m-d'), 'to' => date('Y-m-d', strtotime('+14 days'))];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $v = ['label' => trim($_POST['label'] ?? ''), 'plate' => strtoupper(trim($_POST['plate'] ?? '')), 'capacity_kg' => trim($_POST['capacity_kg'] ?? ''),
          'base' => (int)($_POST['base'] ?? 0), 'from' => trim($_POST['from'] ?? ''), 'to' => trim($_POST['to'] ?? '')];
    $cap = (int)$v['capacity_kg'];
    if (mb_strlen($v['label']) < 2 || mb_strlen($v['label']) > 80) $err[] = 'Describe the vehicle, for example Isuzu FRR, 5 tonne.';
    if (mb_strlen($v['plate']) > 20) $err[] = 'The number plate is too long.';
    if ($cap < 100 || $cap > 40000) $err[] = 'Enter the capacity in kilograms (100 to 40,000).';
    if (!fetch_one('SELECT id FROM locations WHERE id = ?', [$v['base']])) $err[] = 'Choose where the vehicle is based.';
    if (!valid_date($v['from']) || !valid_date($v['to']) || $v['to'] < $v['from'] || $v['to'] < date('Y-m-d')) $err[] = 'Choose a free period that ends today or later, with the end after the start.';
    if (!$err) {
        $pdo = db(); $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO vehicles (owner_id, label, plate, capacity_kg, base_location_id) VALUES (?,?,?,?,?)')->execute([$u['id'], $v['label'], $v['plate'] ?: null, $cap, $v['base']]);
        $vid = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO vehicle_availability (vehicle_id, available_from, available_to) VALUES (?,?,?)')->execute([$vid, $v['from'], $v['to']]);
        $pdo->commit();
        flash_set('Vehicle added. You will now see loads that fit it.');
        redirect('transporter_dashboard.php');
    }
}
$title = 'Add a vehicle';
$poll = '';
include 'includes/header.php';
?>
<div class="row justify-content-center pt-lg-3"><div class="col-md-9 col-lg-7 col-xl-6">
  <h1 class="h3 mb-1">Add a vehicle</h1>
  <p class="text-muted mb-4">Tell us what you drive and when it is free. Farmers will find it, and you will see loads that fit.</p>
  <?php foreach ($err as $m): ?><div class="alert alert-danger py-2" role="alert"><?= e($m) ?></div><?php endforeach; ?>
  <form method="post" class="panel">
    <?= csrf_field() ?>
    <label class="form-label" for="label">Vehicle</label>
    <input class="form-control mb-3" id="label" name="label" placeholder="Isuzu FRR, 5 tonne" value="<?= e($v['label']) ?>" required>
    <div class="row">
      <div class="col-sm-6"><label class="form-label" for="capacity_kg">Capacity (kg)</label>
        <input class="form-control mb-3" id="capacity_kg" name="capacity_kg" type="number" min="100" max="40000" inputmode="numeric" value="<?= e($v['capacity_kg']) ?>" required></div>
      <div class="col-sm-6"><label class="form-label" for="plate">Number plate <span class="text-muted">(optional)</span></label>
        <input class="form-control mb-3" id="plate" name="plate" placeholder="KDA 123A" value="<?= e($v['plate']) ?>"></div>
    </div>
    <label class="form-label" for="base">Where is it based?</label>
    <select class="form-select mb-3" id="base" name="base" required><?= location_options($v['base'], 'Choose a place') ?></select>
    <div class="row">
      <div class="col-sm-6"><label class="form-label" for="from">Free from</label>
        <input class="form-control mb-3" id="from" name="from" type="date" value="<?= e($v['from']) ?>" required></div>
      <div class="col-sm-6"><label class="form-label" for="to">Free until</label>
        <input class="form-control mb-3" id="to" name="to" type="date" value="<?= e($v['to']) ?>" required></div>
    </div>
    <button class="btn btn-crate w-100">Add vehicle</button>
  </form>
</div></div>
<?php include 'includes/footer.php'; ?>
