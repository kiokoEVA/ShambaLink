<?php
require_once 'auth.php';
$u = require_login('farmer');
$loads = fetch_all(<<<'SQL'
    SELECT l.*, p.name AS pickup_name, d.name AS drop_name,
      (SELECT COUNT(*) FROM match_requests mr WHERE mr.load_id = l.id AND mr.status = 'open' AND mr.requested_by = 'transporter') AS offers
    FROM loads l JOIN locations p ON p.id = l.origin_id JOIN locations d ON d.id = l.destination_id
    WHERE l.farmer_id = ?
    ORDER BY FIELD(l.status, 'open','matched','in_transit','delivered','cancelled'), l.pickup_date
SQL, [$u['id']]);
$count = ['open' => 0, 'matched' => 0, 'in_transit' => 0, 'delivered' => 0, 'cancelled' => 0];
foreach ($loads as $l) {
    if (isset($count[$l['status']])) $count[$l['status']]++;
}
$title = 'My dashboard';
$poll = 'live';
include 'includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <div><h1 class="h3 mb-0">Hello, <?= e(explode(' ', $u['name'])[0]) ?></h1>
    <p class="text-muted mb-0">Based in <?= e($u['location'] ?? 'your area') ?></p></div>
  <a class="btn btn-crate" href="post_load.php"><i class="bi bi-plus-lg"></i> Post a load</a>
</div>
<?= flash_get() ?>
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3"><div class="stat"><b><?= $count['open'] ?></b><span>Waiting for a truck</span></div></div>
  <div class="col-6 col-md-3"><div class="stat"><b><?= $count['matched'] ?></b><span>Truck confirmed</span></div></div>
  <div class="col-6 col-md-3"><div class="stat"><b><?= $count['in_transit'] ?></b><span>On the road</span></div></div>
  <div class="col-6 col-md-3"><div class="stat"><b><?= $count['delivered'] ?></b><span>Delivered</span></div></div>
</div>
<h2 class="h5 mb-3">My loads</h2>
<?php if (!$loads): ?>
  <div class="empty">You have not posted a load yet. <a href="post_load.php">Post your first load</a> and see which trucks fit.</div>
<?php endif; ?>
<?php foreach ($loads as $l): ?>
  <div class="load-item">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
      <div><b><?= e($l['produce']) ?></b> <span class="text-muted">&middot; <?= number_format((int)$l['weight_kg']) ?> kg</span><br>
        <span class="text-muted small"><?= e($l['pickup_name']) ?> &rarr; <?= e($l['drop_name']) ?> &middot; pickup <?= e(fmt_date($l['pickup_date'])) ?></span></div>
      <?= status_badge($l['status']) ?>
    </div>
    <div class="sub">
      <span class="text-muted"><?= $l['offers'] ? '<b>' . (int)$l['offers'] . ' offer' . ($l['offers'] > 1 ? 's' : '') . ' from transporters</b>' : ($l['status'] === 'open' ? 'See which trucks fit' : 'View details') ?></span>
      <a class="btn btn-outline-ink btn-sm" href="load.php?id=<?= (int)$l['id'] ?>"><?= $l['status'] === 'open' ? 'Find trucks' : 'Open' ?></a>
    </div>
  </div>
<?php endforeach; ?>
<?php include 'includes/footer.php'; ?>