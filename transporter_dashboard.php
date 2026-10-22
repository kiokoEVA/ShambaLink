<?php
require_once 'auth.php';
require_once 'includes/matching.php';
$u = require_login('transporter');
require 'includes/national_id_gate.php';

// ---- "I can carry this": offer one of my vehicles for an open load ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'offer') {
    csrf_check();
    $loadId = (int)($_POST['load_id'] ?? 0);
    $vehId  = (int)($_POST['vehicle_id'] ?? 0);
    // Re-use the ranking so every rule (approved driver, capacity, dates, busy day) is checked again on the server
    $mineRows = array_values(array_filter(rank_vehicles_for_load($loadId),
        fn($r) => (int)$r['veh']['id'] === $vehId && (int)$r['veh']['owner_id'] === (int)$u['id']));
    $loadRow = fetch_one('SELECT status FROM loads WHERE id = ?', [$loadId]);
    if (!$mineRows || !$loadRow || $loadRow['status'] !== 'open') {
        flash_set('That load is no longer available for this vehicle.', 'danger');
    } elseif ($mineRows[0]['req'] && $mineRows[0]['req']['status'] === 'open') {
        flash_set('There is already an open request for this load and vehicle.', 'danger');
    } else {
        $sc = $mineRows[0]['s'];
        db()->prepare("INSERT INTO match_requests (load_id, vehicle_id, requested_by, status, score_total, score_prox, score_cap, score_avail, score_time, distance_km)
                       VALUES (?, ?, 'transporter', 'open', ?, ?, ?, ?, ?, ?)")
            ->execute([$loadId, $vehId, $sc['total'], $sc['prox'], $sc['cap'], $sc['avail'], $sc['time'], $sc['distance_km']]);
        flash_set('Offer sent. The farmer will see it on their load.');
    }
    redirect('transporter_dashboard.php');
}

// ---- Add a vehicle (form at the bottom of the page) ----
$errV = [];
$f = ['label' => '', 'plate' => '', 'capacity_kg' => '', 'base' => (int)($u['location_id'] ?? 0),
      'from' => date('Y-m-d'), 'to' => date('Y-m-d', strtotime('+14 days'))];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_vehicle') {
    csrf_check();
    $f = ['label' => trim($_POST['label'] ?? ''), 'plate' => strtoupper(trim($_POST['plate'] ?? '')),
          'capacity_kg' => trim($_POST['capacity_kg'] ?? ''), 'base' => (int)($_POST['base_location_id'] ?? 0),
          'from' => trim($_POST['available_from'] ?? ''), 'to' => trim($_POST['available_to'] ?? '')];
    $cap = (int)$f['capacity_kg'];
    if (mb_strlen($f['label']) < 2 || mb_strlen($f['label']) > 80) $errV[] = 'Enter the vehicle name, for example Canter 3 tonne.';
    if (mb_strlen($f['plate']) > 20) $errV[] = 'The number plate is too long.';
    if ($cap < 100 || $cap > 40000) $errV[] = 'Enter the capacity in kilograms (100 to 40,000).';
    if (!fetch_one('SELECT id FROM locations WHERE id = ?', [$f['base']])) $errV[] = 'Choose where the vehicle is based.';
    if (!valid_date($f['from']) || !valid_date($f['to']) || $f['to'] < $f['from']) $errV[] = 'Enter a valid free-from and free-until date.';
    if (!$errV) {
        db()->prepare('INSERT INTO vehicles (owner_id, label, plate, capacity_kg, base_location_id, active) VALUES (?,?,?,?,?,1)')
            ->execute([$u['id'], $f['label'], $f['plate'], $cap, $f['base']]);
        $vid = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO vehicle_availability (vehicle_id, available_from, available_to) VALUES (?,?,?)')
            ->execute([$vid, $f['from'], $f['to']]);
        flash_set('Vehicle added.');
        redirect('transporter_dashboard.php');
    }
}

$me = fetch_one('SELECT driver_status, driver_note FROM users WHERE id = ?', [$u['id']]);
$vehicles = fetch_all('SELECT v.* FROM vehicles v WHERE v.owner_id = ? ORDER BY v.id DESC', [$u['id']]);

$base = "SELECT mr.*, v.label, v.capacity_kg, l.produce, l.weight_kg, l.pickup_date, l.deliver_by, l.status AS load_status,
                p.name AS pickup_name, d.name AS drop_name, f.name AS farmer, f.phone AS farmer_phone
         FROM match_requests mr
         JOIN vehicles v ON v.id = mr.vehicle_id
         JOIN loads l ON l.id = mr.load_id
         JOIN locations p ON p.id = l.origin_id
         JOIN locations d ON d.id = l.destination_id
         JOIN users f ON f.id = l.farmer_id
         WHERE v.owner_id = ? ";
$requests = fetch_all($base . "AND mr.status = 'open' AND mr.requested_by = 'farmer' AND l.status = 'open' ORDER BY mr.id DESC", [$u['id']]);
$jobs     = fetch_all($base . "AND mr.status = 'accepted' AND l.status IN ('matched','in_transit') ORDER BY l.pickup_date", [$u['id']]);
$board    = open_loads_board((int)$u['id']);

$title = 'My vehicles and jobs';
$poll = '';          // live.js (below) does the polling
include 'includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <div><h1 class="h3 mb-0">Hello, <?= e(explode(' ', $u['name'])[0]) ?></h1>
    <p class="text-muted mb-0">Your vehicles and jobs</p>
    <p class="small mb-0">Driver status: <b><?= e(['unverified' => 'Not submitted', 'pending' => 'Waiting for review', 'approved' => 'Approved', 'rejected' => 'Rejected'][$me['driver_status']] ?? $me['driver_status']) ?></b>
      &middot; <a href="driver_documents.php">Driver documents</a></p></div>
</div>
<?= flash_get() ?>
<?php if ($me['driver_status'] !== 'approved'): ?>
  <div class="alert alert-warning">
    <?php if ($me['driver_status'] === 'pending'): ?>
      Your documents are with an admin for review. You will see open loads and appear in load matching once you are approved.
    <?php elseif ($me['driver_status'] === 'rejected'): ?>
      Your documents were rejected<?= $me['driver_note'] ? ': ' . e($me['driver_note']) : '' ?>. <a href="driver_documents.php">Fix and resubmit</a>.
    <?php else: ?>
      You are not an approved driver yet. <a href="driver_documents.php">Upload your documents</a> to see open loads and appear in load matching.
    <?php endif; ?>
  </div>
<?php endif; ?>

<div id="live" data-role="transporter">
  <h2 class="h5 mb-3">Requests for you</h2>
  <?php if (!$requests): ?><div class="empty mb-4">No requests right now. New ones appear here by themselves.</div><?php endif; ?>
  <?php foreach ($requests as $r): ?>
    <div class="load-item">
      <div class="d-flex justify-content-between flex-wrap gap-2">
        <div><b><?= e($r['produce']) ?></b> <span class="text-muted">&middot; <?= number_format((int)$r['weight_kg']) ?> kg</span><br>
          <span class="text-muted small"><?= e($r['farmer']) ?> asked for your <?= e($r['label']) ?>. <?= e($r['pickup_name']) ?> &rarr; <?= e($r['drop_name']) ?> &middot; pickup <?= e(fmt_date($r['pickup_date'])) ?></span></div>
        <div class="total"><?= (int)$r['score_total'] ?><small>/100</small></div>
      </div>
      <div class="mt-3">
        <?= action_btn('accept', ['request_id' => $r['id']], 'Accept') ?>
        <?= action_btn('decline', ['request_id' => $r['id']], 'Decline', 'btn-outline-ink btn-sm') ?>
      </div>
    </div>
  <?php endforeach; ?>

  <h2 class="h5 mt-4 mb-3">My jobs</h2>
  <?php if (!$jobs): ?><div class="empty mb-4">No confirmed jobs yet.</div><?php endif; ?>
  <?php foreach ($jobs as $j): ?>
    <div class="load-item">
      <div class="d-flex justify-content-between flex-wrap gap-2">
        <div><b><?= e($j['produce']) ?></b> <span class="text-muted">&middot; <?= number_format((int)$j['weight_kg']) ?> kg &middot; <?= e($j['label']) ?></span><br>
          <span class="text-muted small"><?= e($j['pickup_name']) ?> &rarr; <?= e($j['drop_name']) ?> &middot; pickup <?= e(fmt_date($j['pickup_date'])) ?><?= $j['deliver_by'] ? ' &middot; deliver by ' . e(fmt_date($j['deliver_by'])) : '' ?></span><br>
          <span class="small">Farmer <?= e($j['farmer']) ?>: <a href="tel:+<?= e($j['farmer_phone']) ?>"><?= e(format_phone($j['farmer_phone'])) ?></a></span></div>
        <?= status_badge($j['load_status']) ?>
      </div>
    </div>
  <?php endforeach; ?>

  <h2 class="h5 mt-4 mb-1">Open loads that fit your vehicles</h2>
  <p class="text-muted small mb-3">A load shows here when you are an approved driver with an active vehicle that can carry it, is free on the pickup date and is not already on a job that day.</p>
  <?php if (!$board): ?><div class="empty mb-4">No open loads fit your vehicles right now. This list updates by itself.</div><?php endif; ?>
  <?php foreach ($board as $row): $l = $row['load']; $b = $row['best']; ?>
    <div class="load-item">
      <div class="d-flex justify-content-between flex-wrap gap-2">
        <div><b><?= e($l['produce']) ?></b> <span class="text-muted">&middot; <?= number_format((int)$l['weight_kg']) ?> kg</span><br>
          <span class="text-muted small"><?= e($l['pickup_name']) ?> &rarr; <?= e($l['drop_name']) ?> &middot; pickup <?= e(fmt_date($l['pickup_date'])) ?></span><br>
          <span class="text-muted small">Best fit: <?= e($b['veh']['label']) ?>, about <?= (int)$b['s']['km'] ?> km to pickup, trip about <?= e($b['s']['hours']) ?> hours</span></div>
        <div class="total"><?= (int)$b['s']['total'] ?><small>/100</small></div>
      </div>
      <div class="mt-3">
        <?php if ($row['state'] === 'offered'): ?><span class="text-muted small">Offer sent. Waiting for the farmer.</span>
        <?php elseif ($row['state'] === 'requested'): ?><span class="text-muted small">This farmer asked you. See Requests for you.</span>
        <?php else: ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="offer">
            <input type="hidden" name="load_id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="vehicle_id" value="<?= (int)$b['veh']['id'] ?>">
            <button class="btn btn-crate btn-sm">I can carry this</button></form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <h2 class="h5 mt-4 mb-3">My vehicles</h2>
  <?php if (!$vehicles): ?><div class="empty">You have not added a vehicle yet. Use the form below.</div><?php endif; ?>
  <?php foreach ($vehicles as $v): ?>
    <div class="load-item">
      <b><?= e($v['label']) ?></b> <span class="text-muted">&middot; <?= number_format((int)$v['capacity_kg']) ?> kg<?= $v['plate'] ? ' &middot; ' . e($v['plate']) : '' ?></span>
    </div>
  <?php endforeach; ?>
</div>

<h2 class="h5 mt-4 mb-3">Add a vehicle</h2>
<?php foreach ($errV as $m): ?><div class="alert alert-danger py-2" role="alert"><?= e($m) ?></div><?php endforeach; ?>
<form method="post" class="panel mb-4">
  <?= csrf_field() ?><input type="hidden" name="action" value="add_vehicle">
  <div class="row g-2">
    <div class="col-sm-6"><label class="form-label" for="label">Vehicle</label>
      <input class="form-control mb-2" id="label" name="label" maxlength="80" placeholder="Canter 3 tonne" value="<?= e($f['label']) ?>" required></div>
    <div class="col-sm-6"><label class="form-label" for="plate">Number plate</label>
      <input class="form-control mb-2" id="plate" name="plate" maxlength="20" placeholder="KDA 123A" value="<?= e($f['plate']) ?>"></div>
    <div class="col-sm-6"><label class="form-label" for="capacity_kg">Capacity (kg)</label>
      <input class="form-control mb-2" id="capacity_kg" name="capacity_kg" type="number" min="100" max="40000" value="<?= e($f['capacity_kg']) ?>" required></div>
    <div class="col-sm-6"><label class="form-label" for="base_location_id">Based in</label>
      <select class="form-select mb-2" id="base_location_id" name="base_location_id" required><?= location_options($f['base'], 'Choose a place') ?></select></div>
    <div class="col-sm-6"><label class="form-label" for="available_from">Free from</label>
      <input class="form-control mb-2" id="available_from" name="available_from" type="date" min="<?= date('Y-m-d') ?>" value="<?= e($f['from']) ?>" required></div>
    <div class="col-sm-6"><label class="form-label" for="available_to">Free until</label>
      <input class="form-control mb-2" id="available_to" name="available_to" type="date" min="<?= date('Y-m-d') ?>" value="<?= e($f['to']) ?>" required></div>
  </div>
  <button class="btn btn-crate w-100 mt-2">Add vehicle</button>
</form>
<script src="live.js" defer></script>
<?php include 'includes/footer.php'; ?>