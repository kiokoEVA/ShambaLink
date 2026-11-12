<?php
require_once 'auth.php';
require_once 'includes/matching.php';
$u = require_login('transporter');
require 'includes/profile_gate.php';

// ---- One vehicle per driver (security): a driver may change its location and free dates, not add more ----
$myVehicleCount = (int)(fetch_one('SELECT COUNT(*) AS n FROM vehicles WHERE owner_id = ?', [$u['id']])['n'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_location') {
    csrf_check();
    $newBase = (int)($_POST['base_location_id'] ?? 0);
    $onJob = (int)(fetch_one("SELECT COUNT(*) AS n FROM match_requests mr
                              JOIN vehicles v ON v.id = mr.vehicle_id JOIN loads l ON l.id = mr.load_id
                              WHERE v.owner_id = ? AND mr.status = 'accepted' AND l.status IN ('matched','in_transit')", [$u['id']])['n'] ?? 0);
    if (!$myVehicleCount) flash_set('Add your vehicle first.', 'danger');
    elseif (!fetch_one('SELECT id FROM locations WHERE id = ?', [$newBase])) flash_set('Choose a valid place.', 'danger');
    elseif ($onJob) flash_set('You can change your location once your current job is delivered.', 'danger');
    else {
        db()->prepare('UPDATE vehicles SET base_location_id = ? WHERE owner_id = ?')->execute([$newBase, $u['id']]);
        flash_set('Location updated.');
    }
    redirect('transporter_dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_availability') {
    csrf_check();
    $from = trim($_POST['available_from'] ?? '');
    $to = trim($_POST['available_to'] ?? '');
    if (!$myVehicleCount) flash_set('Add your vehicle first.', 'danger');
    elseif (!valid_date($from) || !valid_date($to) || $to < $from || $to < date('Y-m-d')) flash_set('Enter a valid free-from and free-until date (not in the past).', 'danger');
    else {
        foreach (fetch_all('SELECT id FROM vehicles WHERE owner_id = ?', [$u['id']]) as $vv) {
            db()->prepare('DELETE FROM vehicle_availability WHERE vehicle_id = ?')->execute([$vv['id']]);
            db()->prepare('INSERT INTO vehicle_availability (vehicle_id, available_from, available_to) VALUES (?,?,?)')->execute([$vv['id'], $from, $to]);
        }
        flash_set('Availability updated.');
    }
    redirect('transporter_dashboard.php');
}

// ---- Add a vehicle (only while the driver has none) ----
$errV = [];
$f = ['label' => '', 'plate' => '', 'capacity_kg' => '', 'base' => (int)($u['location_id'] ?? 0),
      'from' => date('Y-m-d'), 'to' => date('Y-m-d', strtotime('+14 days')), 'type' => 'normal', 'rate' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_vehicle') {
    csrf_check();
    $f = ['label' => trim($_POST['label'] ?? ''), 'plate' => strtoupper(trim($_POST['plate'] ?? '')),
          'capacity_kg' => trim($_POST['capacity_kg'] ?? ''), 'base' => (int)($_POST['base_location_id'] ?? 0),
          'from' => trim($_POST['available_from'] ?? ''), 'to' => trim($_POST['available_to'] ?? ''),
          'type' => ($_POST['truck_type'] ?? '') === 'refrigerated' ? 'refrigerated' : 'normal'];
    $cap = (int)$f['capacity_kg'];
    if (mb_strlen($f['label']) < 2 || mb_strlen($f['label']) > 80) $errV[] = 'Enter the vehicle name, for example Canter 3 tonne.';
    if ($myVehicleCount >= 1) $errV[] = 'You can register only one vehicle. You can change its location and free dates instead.';
    $plateKey = preg_replace('/[^A-Z0-9]/', '', $f['plate']);
    if (strlen($plateKey) < 5 || strlen($plateKey) > 10) $errV[] = 'Enter the number plate, for example KDA 123A.';
    elseif (fetch_one("SELECT id FROM vehicles WHERE REPLACE(REPLACE(UPPER(plate), ' ', ''), '-', '') = ?", [$plateKey])) $errV[] = 'That number plate is already registered.';
    if ($cap < 100 || $cap > 40000) $errV[] = 'Enter the capacity in kilograms (100 to 40,000).';
    if (!fetch_one('SELECT id FROM locations WHERE id = ?', [$f['base']])) $errV[] = 'Choose where the vehicle is based.';
    if (!valid_date($f['from']) || !valid_date($f['to']) || $f['to'] < $f['from']) $errV[] = 'Enter a valid free-from and free-until date.';
    if (!$errV) {
        db()->prepare('INSERT INTO vehicles (owner_id, label, plate, capacity_kg, base_location_id, refrigerated, rate_per_km, active) VALUES (?,?,?,?,?,?,?,1)')
            ->execute([$u['id'], $f['label'], $f['plate'], $cap, $f['base'], $f['type'] === 'refrigerated' ? 1 : 0, rate_for_capacity($cap)]);
        $vid = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO vehicle_availability (vehicle_id, available_from, available_to) VALUES (?,?,?)')
            ->execute([$vid, $f['from'], $f['to']]);
        flash_set('Vehicle added.');
        redirect('transporter_dashboard.php');
    }
}

$me = fetch_one('SELECT driver_status, driver_note FROM users WHERE id = ?', [$u['id']]);
$vehicles = fetch_all('SELECT v.*, b.name AS base_name FROM vehicles v JOIN locations b ON b.id = v.base_location_id WHERE v.owner_id = ? ORDER BY v.id DESC', [$u['id']]);
$avail = fetch_one('SELECT a.available_from, a.available_to FROM vehicle_availability a JOIN vehicles v ON v.id = a.vehicle_id WHERE v.owner_id = ? ORDER BY a.available_to DESC LIMIT 1', [$u['id']]);

$base = "SELECT mr.*, v.label, v.capacity_kg, l.produce, l.weight_kg, l.pickup_date, l.deliver_by, l.status AS load_status, l.needs_refrigeration, l.agreed_fare_kes,
                p.name AS pickup_name, d.name AS drop_name, p.latitude AS o_lat, p.longitude AS o_lng, d.latitude AS d_lat, d.longitude AS d_lng, f.name AS farmer, f.phone AS farmer_phone
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
$pay      = fetch_all("SELECT l.id AS load_id, l.produce, l.agreed_fare_kes, l.payment_status, l.payment_method, l.payment_ref,
                              f.name AS farmer, d.name AS drop_name
                       FROM match_requests mr
                       JOIN vehicles v ON v.id = mr.vehicle_id
                       JOIN loads l ON l.id = mr.load_id
                       JOIN users f ON f.id = l.farmer_id
                       JOIN locations d ON d.id = l.destination_id
                       WHERE v.owner_id = ? AND mr.status = 'accepted' AND l.status = 'delivered'
                       ORDER BY (l.payment_status = 'confirmed'), l.id DESC LIMIT 20", [$u['id']]);
$earned = 0; $waiting = 0;
foreach ($pay as $p) { if ($p['payment_status'] === 'confirmed') $earned += (int)$p['agreed_fare_kes']; else $waiting += (int)$p['agreed_fare_kes']; }

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

<?php $inTransit = array_filter($jobs, fn($j) => in_array($j['load_status'], ['matched', 'in_transit'], true)); ?>
<?php if ($inTransit): ?>
  <div class="panel mb-4" id="shareBox">
    <h2 class="h5 mb-1">Live tracking</h2>
    <p class="small text-muted">Share your location from now until delivery, so the farmer can see the truck coming. Keep this page open.</p>
    <form id="shareForm"><?= csrf_field() ?></form>
    <?php foreach ($inTransit as $j): ?>
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 py-2">
        <div><b><?= e($j['produce']) ?></b> <span class="text-muted small">&rarr; <?= e($j['drop_name']) ?></span><br>
          <span class="small text-muted" data-share-status="<?= (int)$j['load_id'] ?>">Not sharing.</span></div>
        <div class="d-flex gap-2 flex-wrap">
          <button type="button" class="btn btn-crate btn-sm" data-share-load="<?= (int)$j['load_id'] ?>">Start sharing location</button>
          <?php if (isset($_GET['demo'])): ?>
            <button type="button" class="btn btn-outline-ink btn-sm" data-sim-load="<?= (int)$j['load_id'] ?>"
              data-o-lat="<?= e($j['o_lat']) ?>" data-o-lng="<?= e($j['o_lng']) ?>" data-d-lat="<?= e($j['d_lat']) ?>" data-d-lng="<?= e($j['d_lng']) ?>">Simulate drive (demo)</button>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php foreach ($inTransit as $j): ?>
  <div class="panel mb-4 trip-box" data-role="driver" data-load-id="<?= (int)$j['load_id'] ?>" style="display:none">
    <h2 class="h5 mb-1">Trip map: <?= e($j['produce']) ?> &rarr; <?= e($j['drop_name']) ?></h2>
    <p class="small text-muted mb-2 trip-info">Checking...</p>
    <div class="trip-nav d-flex gap-2 flex-wrap mb-2"></div>
    <div class="trip-map" style="height:300px;border-radius:12px;overflow:hidden"></div>
  </div>
<?php endforeach; ?>

<div id="live" data-role="transporter">
  <h2 class="h5 mb-3">Requests for you</h2>
  <?php if (!$requests): ?><div class="empty mb-4">No requests right now. New ones appear here by themselves.</div><?php endif; ?>
  <?php foreach ($requests as $r): ?>
    <div class="load-item">
      <div class="d-flex justify-content-between flex-wrap gap-2">
        <div><b><?= e($r['produce']) ?></b> <span class="text-muted">&middot; <?= number_format((int)$r['weight_kg']) ?> kg</span><?= !empty($r['needs_refrigeration']) ? ' <span class="badge text-bg-info">Refrigerated</span>' : '' ?><br>
          <span class="text-muted small"><?= e($r['farmer']) ?> asked for your <?= e($r['label']) ?>. <?= e($r['pickup_name']) ?> &rarr; <?= e($r['drop_name']) ?> &middot; pickup <?= e(fmt_date($r['pickup_date'])) ?><?= !empty($r['fare_kes']) ? ' &middot; <b>offered KES ' . number_format((int)$r['fare_kes']) . '</b>' : '' ?></span></div>
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
          <?= !empty($j['agreed_fare_kes']) ? '<span class="small">Agreed price: <b>KES ' . number_format((int)$j['agreed_fare_kes']) . '</b></span><br>' : '' ?>
          <span class="small">Farmer <?= e($j['farmer']) ?>: <a href="tel:+<?= e($j['farmer_phone']) ?>"><?= e(format_phone($j['farmer_phone'])) ?></a></span></div>
        <?= status_badge($j['load_status']) ?>
      </div>
      <div class="mt-3">
        <?php if ($j['load_status'] === 'matched'): ?><?= action_btn('start_trip', ['load_id' => $j['load_id']], 'Start trip') ?>
        <?php else: ?><?= action_btn('deliver', ['load_id' => $j['load_id']], 'Mark as delivered') ?><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <h2 class="h5 mt-4 mb-1">Payments</h2>
  <p class="small text-muted mb-3">Confirmed: <b>KES <?= number_format($earned) ?></b> &middot; Waiting: <b>KES <?= number_format($waiting) ?></b>. Farmers pay you directly, by cash or M-Pesa. ShambaLink records payments and does not process money.</p>
  <?php if (!$pay): ?><div class="empty mb-4">Delivered jobs appear here so you can confirm their payment.</div><?php endif; ?>
  <?php foreach ($pay as $p): ?>
    <div class="load-item">
      <div class="d-flex justify-content-between flex-wrap gap-2">
        <div><b><?= e($p['produce']) ?></b> <span class="text-muted small">&middot; <?= e($p['farmer']) ?> &middot; to <?= e($p['drop_name']) ?></span><br>
          <span class="small">Price: <b>KES <?= number_format((int)$p['agreed_fare_kes']) ?></b></span></div>
        <?php if ($p['payment_status'] === 'confirmed'): ?><span class="badge text-bg-success align-self-start">Paid</span>
        <?php elseif ($p['payment_status'] === 'farmer_paid'): ?><span class="badge text-bg-warning align-self-start">Farmer says paid</span>
        <?php else: ?><span class="badge text-bg-secondary align-self-start">Unpaid</span><?php endif; ?>
      </div>
      <?php if ($p['payment_status'] !== 'confirmed'): ?>
        <form method="post" action="request_action.php" class="d-flex gap-2 flex-wrap align-items-end mt-3">
          <?= csrf_field() ?><input type="hidden" name="action" value="confirm_payment"><input type="hidden" name="back" value="transporter_dashboard.php"><input type="hidden" name="load_id" value="<?= (int)$p['load_id'] ?>">
          <div><label class="small text-muted d-block" for="pm<?= (int)$p['load_id'] ?>">Received by</label>
            <select class="form-select form-select-sm" id="pm<?= (int)$p['load_id'] ?>" name="method">
              <option value="mpesa" <?= $p['payment_method'] !== 'cash' ? 'selected' : '' ?>>M-Pesa</option>
              <option value="cash" <?= $p['payment_method'] === 'cash' ? 'selected' : '' ?>>Cash</option></select></div>
          <div><label class="small text-muted d-block" for="pr<?= (int)$p['load_id'] ?>">M-Pesa code</label>
            <input class="form-control form-control-sm" id="pr<?= (int)$p['load_id'] ?>" name="ref" maxlength="12" value="<?= e($p['payment_ref'] ?? '') ?>" placeholder="e.g. QGH7XYZ123"></div>
          <button class="btn btn-crate btn-sm">Payment received</button>
        </form>
      <?php else: ?>
        <div class="small text-muted mt-2"><?= $p['payment_method'] === 'mpesa' ? 'M-Pesa ' . e($p['payment_ref']) : 'Cash' ?></div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <h2 class="h5 mt-4 mb-1">Open loads that fit your vehicles</h2>
  <p class="text-muted small mb-3">A load shows here when you are an approved driver with an active vehicle that can carry it, is free on the pickup date and is not already on a job that day.</p>
  <?php if (!$board): ?><div class="empty mb-4">No open loads fit your vehicles right now. This list updates by itself.</div><?php endif; ?>
  <?php foreach ($board as $row): $l = $row['load']; $b = $row['best']; ?>
    <div class="load-item">
      <div class="d-flex justify-content-between flex-wrap gap-2">
        <div><b><?= e($l['produce']) ?></b> <span class="text-muted">&middot; <?= number_format((int)$l['weight_kg']) ?> kg</span><?= !empty($l['needs_refrigeration']) ? ' <span class="badge text-bg-info">Refrigerated</span>' : '' ?><br>
          <span class="text-muted small"><?= e($l['pickup_name']) ?> &rarr; <?= e($l['drop_name']) ?> &middot; pickup <?= e(fmt_date($l['pickup_date'])) ?></span><br>
          <span class="text-muted small">Best fit: <?= e($b['veh']['label']) ?>, about <?= (int)$b['s']['km'] ?> km to pickup, trip about <?= e($b['s']['hours']) ?> hours</span>
          <?= !empty($b['s']['fare']) ? '<br><span class="small">Estimated fare <b>KES ' . number_format((int)$b['s']['fare']) . '</b>' . (!empty($b['veh']['refrigerated']) ? ' (includes refrigeration premium)' : '') . '</span>' : '' ?></div>
        <div class="total"><?= (int)$b['s']['total'] ?><small>/100</small></div>
      </div>
      <div class="mt-3">
        <?php if ($row['state'] === 'offered'): ?><span class="text-muted small">Offer sent. Waiting for the farmer.</span>
        <?php elseif ($row['state'] === 'requested'): ?><span class="text-muted small">This farmer asked you. See Requests for you.</span>
        <?php else: ?>
          <form method="post" action="request_action.php">
            <?= csrf_field() ?><input type="hidden" name="action" value="request_load"><input type="hidden" name="back" value="transporter_dashboard.php">
            <input type="hidden" name="load_id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="vehicle_id" value="<?= (int)$b['veh']['id'] ?>">
            <button class="btn btn-crate btn-sm">I can carry this<?= !empty($b['s']['fare']) ? ' for KES ' . number_format((int)$b['s']['fare']) : '' ?></button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <h2 class="h5 mt-4 mb-3">My vehicles</h2>
  <?php if (!$vehicles): ?><div class="empty">You have not registered your vehicle yet. Use the form below (one vehicle per driver).</div><?php endif; ?>
  <?php foreach ($vehicles as $v): ?>
    <div class="load-item">
      <b><?= e($v['label']) ?></b> <span class="text-muted">&middot; <?= number_format((int)$v['capacity_kg']) ?> kg<?= $v['plate'] ? ' &middot; ' . e($v['plate']) : '' ?> &middot; based in <?= e($v['base_name']) ?> &middot; <?= !empty($v['refrigerated']) ? 'Refrigerated' : 'Normal truck' ?> &middot; <?= 'KES ' . number_format(rate_for_capacity((int)$v['capacity_kg'])) . '/km' ?></span>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($myVehicleCount < 1): ?>
<h2 class="h5 mt-4 mb-1">Register your vehicle</h2>
<p class="small text-muted mb-3">For security each driver can register one vehicle. Later you can change where it is based and when it is free.</p>
<?php foreach ($errV as $m): ?><div class="alert alert-danger py-2" role="alert"><?= e($m) ?></div><?php endforeach; ?>
<form method="post" class="panel mb-4">
  <?= csrf_field() ?><input type="hidden" name="action" value="add_vehicle">
  <div class="row g-2">
    <div class="col-sm-6"><label class="form-label" for="label">Vehicle</label>
      <input class="form-control mb-2" id="label" name="label" maxlength="80" placeholder="Canter 3 tonne" value="<?= e($f['label']) ?>" required></div>
    <div class="col-sm-6"><label class="form-label" for="plate">Number plate (must be unique)</label>
      <input class="form-control mb-2" id="plate" name="plate" maxlength="20" placeholder="KDA 123A" value="<?= e($f['plate']) ?>" required></div>
    <div class="col-sm-6"><label class="form-label" for="capacity_kg">Capacity (kg)</label>
      <input class="form-control mb-2" id="capacity_kg" name="capacity_kg" type="number" min="100" max="40000" value="<?= e($f['capacity_kg']) ?>" required></div>
    <div class="col-12"><div class="small text-muted mb-2">The price is calculated automatically by ShambaLink: KES <?= (int)FARE_BASE_KES ?> + distance x a rate set by truck size, plus <?= (int)round(FARE_REFRIGERATED_PREMIUM * 100) ?>% for refrigerated trucks. You do not set a price.</div></div>
    <div class="col-sm-6"><label class="form-label" for="base_location_id">Based in</label>
      <select class="form-select mb-2" id="base_location_id" name="base_location_id" required><?= location_options($f['base'], 'Choose a place') ?></select></div>
    <div class="col-12"><label class="form-label" for="truck_type">Truck type</label>
      <select class="form-select mb-2" id="truck_type" name="truck_type">
        <option value="normal" <?= $f['type'] !== 'refrigerated' ? 'selected' : '' ?>>Normal truck</option>
        <option value="refrigerated" <?= $f['type'] === 'refrigerated' ? 'selected' : '' ?>>Refrigerated truck</option>
      </select></div>
    <div class="col-sm-6"><label class="form-label" for="available_from">Free from</label>
      <input class="form-control mb-2" id="available_from" name="available_from" type="date" min="<?= date('Y-m-d') ?>" value="<?= e($f['from']) ?>" required></div>
    <div class="col-sm-6"><label class="form-label" for="available_to">Free until</label>
      <input class="form-control mb-2" id="available_to" name="available_to" type="date" min="<?= date('Y-m-d') ?>" value="<?= e($f['to']) ?>" required></div>
  </div>
  <button class="btn btn-crate w-100 mt-2">Register vehicle</button>
</form>
<?php else: ?>
<h2 class="h5 mt-4 mb-1">Manage my vehicle</h2>
<p class="small text-muted mb-3">For security each driver registers one vehicle. You can change where it is based and when it is free. Your location can be changed again once any current job is delivered.</p>
<div class="row g-3 mb-4">
  <div class="col-md-6">
    <form method="post" class="panel">
      <?= csrf_field() ?><input type="hidden" name="action" value="change_location">
      <h3 class="h6">Change location</h3>
      <label class="form-label" for="new_base">Based in</label>
      <select class="form-select mb-3" id="new_base" name="base_location_id" required><?= location_options((int)($vehicles[0]['base_location_id'] ?? 0), 'Choose a place') ?></select>
      <button class="btn btn-crate w-100">Update location</button>
    </form>
  </div>
  <div class="col-md-6">
    <form method="post" class="panel">
      <?= csrf_field() ?><input type="hidden" name="action" value="update_availability">
      <h3 class="h6">When are you free?</h3>
      <div class="row g-2 mb-3">
        <div class="col-6"><label class="form-label" for="av_from">Free from</label>
          <input class="form-control" id="av_from" name="available_from" type="date" value="<?= e($avail['available_from'] ?? date('Y-m-d')) ?>" required></div>
        <div class="col-6"><label class="form-label" for="av_to">Free until</label>
          <input class="form-control" id="av_to" name="available_to" type="date" min="<?= date('Y-m-d') ?>" value="<?= e($avail['available_to'] ?? date('Y-m-d', strtotime('+14 days'))) ?>" required></div>
      </div>
      <button class="btn btn-crate w-100">Update availability</button>
    </form>
  </div>
</div>
<?php endif; ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js" defer></script>
<script src="js/track.js?v=<?= @filemtime(__DIR__ . '/js/track.js') ?>" defer></script>
<script src="js/live.js?v=<?= @filemtime(__DIR__ . '/js/live.js') ?>" defer></script>
<script>
window.addEventListener('load', function () {
  if (window.__trackLoaded) return;
  document.querySelectorAll('[data-share-status]').forEach(function (el) {
    el.textContent = 'track.js did not load. Check that it is in shambalink/js/track.js, then press Ctrl+Shift+R.';
  });
});
</script>
<?php include 'includes/footer.php'; ?>