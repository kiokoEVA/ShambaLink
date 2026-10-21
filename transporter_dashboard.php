<?php
require_once 'auth.php';
$u = require_login('transporter');

// My vehicles
$vehicles = fetch_all('SELECT v.* FROM vehicles v WHERE v.owner_id = ? ORDER BY v.id DESC', [$u['id']]);

// Shared query: requests and jobs involving my vehicles
$base = "SELECT mr.*, v.label, v.capacity_kg, l.produce, l.weight_kg, l.pickup_date, l.deliver_by, l.status AS load_status,
                p.name AS pickup_name, d.name AS drop_name, f.name AS farmer, f.phone AS farmer_phone
         FROM match_requests mr
         JOIN vehicles v ON v.id = mr.vehicle_id
         JOIN loads l ON l.id = mr.load_id
         JOIN locations p ON p.id = l.origin_id
         JOIN locations d ON d.id = l.destination_id
         JOIN users f ON f.id = l.farmer_id
         WHERE v.owner_id = ? ";

// Farmers asking for one of my trucks
$requests = fetch_all($base . "AND mr.status = 'open' AND mr.requested_by = 'farmer' AND l.status = 'open' ORDER BY mr.id DESC", [$u['id']]);

// Confirmed jobs
$jobs = fetch_all($base . "AND mr.status = 'accepted' AND l.status IN ('matched','in_transit') ORDER BY l.pickup_date", [$u['id']]);

$title = 'My vehicles and jobs';
$poll = 'live';
include 'includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <div><h1 class="h3 mb-0">Hello, <?= e(explode(' ', $u['name'])[0]) ?></h1>
    <p class="text-muted mb-0">Your vehicles and jobs</p></div>
</div>
<?= flash_get() ?>

<h2 class="h5 mb-3">Requests for you</h2>
<?php if (!$requests): ?><div class="empty mb-4">No requests right now.</div><?php endif; ?>
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

<h2 class="h5 mt-4 mb-3">My vehicles</h2>
<?php if (!$vehicles): ?><div class="empty">You have not added a vehicle yet.</div><?php endif; ?>
<?php foreach ($vehicles as $v): ?>
  <div class="load-item">
    <b><?= e($v['label']) ?></b> <span class="text-muted">&middot; <?= number_format((int)$v['capacity_kg']) ?> kg</span>
  </div>
<?php endforeach; ?>
<?php include 'includes/footer.php'; ?>