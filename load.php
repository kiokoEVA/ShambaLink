<?php
require_once 'auth.php';
require_once 'includes/matching.php';
$u = require_login('farmer');
$id = (int)($_GET['id'] ?? 0);
$load = fetch_one(<<<'SQL'
    SELECT l.*, p.name AS pickup_name, d.name AS drop_name FROM loads l
    JOIN locations p ON p.id = l.origin_id JOIN locations d ON d.id = l.destination_id
    WHERE l.id = ? AND l.farmer_id = ?
SQL, [$id, $u['id']]);
if (!$load) { flash_set('Load not found.', 'danger'); redirect('farmer_dashboard.php'); }

$accepted = fetch_one(<<<'SQL'
    SELECT mr.*, v.label, v.capacity_kg, t.name AS driver, t.phone AS driver_phone
    FROM match_requests mr JOIN vehicles v ON v.id = mr.vehicle_id JOIN users t ON t.id = v.owner_id
    WHERE mr.load_id = ? AND mr.status = 'accepted'
SQL, [$id]);
$offers = $ranked = [];
if ($load['status'] === 'open') {
    $ranked = rank_vehicles_for_load($id);
    $offers = fetch_all(<<<'SQL'
        SELECT mr.*, v.label, t.name AS driver FROM match_requests mr JOIN vehicles v ON v.id = mr.vehicle_id JOIN users t ON t.id = v.owner_id
        WHERE mr.load_id = ? AND mr.status = 'open' AND mr.requested_by = 'transporter'
SQL, [$id]);
}
$title = $load['produce'] . ' load';
$poll = '';          // live.js (below) does the polling
include 'includes/header.php';
?>
<?= flash_get() ?>
<div id="live" data-role="farmer">
<a class="small" href="farmer_dashboard.php">&larr; Back to dashboard</a>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mt-2 mb-3">
  <div>
    <h1 class="h3 mb-1"><?= e($load['produce']) ?>, <?= number_format((int)$load['weight_kg']) ?> kg</h1>
    <p class="text-muted mb-0"><?= e($load['pickup_name']) ?> &rarr; <?= e($load['drop_name']) ?> &middot; pickup <?= e(fmt_date($load['pickup_date'])) ?><?= $load['deliver_by'] ? ' &middot; deliver by ' . e(fmt_date($load['deliver_by'])) : '' ?></p>
    <?php if ($load['notes']): ?><p class="small mt-1 mb-0"><?= e($load['notes']) ?></p><?php endif; ?>
  </div>
  <div class="text-end"><?= status_badge($load['status']) ?>
    <?php if (in_array($load['status'], ['open', 'matched'], true)): ?>
      <div class="mt-2"><?= action_btn('cancel_load', ['load_id' => $id], 'Cancel this load', 'btn-outline-ink btn-sm') ?></div>
    <?php endif; ?></div>
</div>
<?php if ($accepted): ?>
  <div class="panel mb-4">
    <h2 class="h5">Your truck</h2>
    <p class="mb-1"><b><?= e($accepted['driver']) ?></b> &middot; <?= e($accepted['label']) ?></p>
    <?php if (in_array($load['status'], ['matched', 'in_transit', 'delivered'], true)): ?>
      <p class="mb-2">Call: <a href="tel:+<?= e($accepted['driver_phone']) ?>"><b><?= e(format_phone($accepted['driver_phone'])) ?></b></a></p>
    <?php endif; ?>
    <p class="text-muted small mb-0">Matched with a score of <?= (int)$accepted['score_total'] ?>/100.</p>
    <?= gauge(['prox' => $accepted['score_prox'], 'cap' => $accepted['score_cap'], 'avail' => $accepted['score_avail'], 'time' => $accepted['score_time']]) ?>
  </div>
<?php endif; ?>

<?php if ($offers): ?>
  <h2 class="h5 mb-3">Offers from transporters</h2>
  <?php foreach ($offers as $o): ?>
    <div class="load-item">
      <div class="d-flex justify-content-between flex-wrap gap-2 align-items-center">
        <div><b><?= e($o['driver']) ?></b> <span class="text-muted">&middot; <?= e($o['label']) ?> &middot; score <?= (int)$o['score_total'] ?>/100</span></div>
        <div><?= action_btn('accept', ['request_id' => $o['id']], 'Accept') ?><?= action_btn('decline', ['request_id' => $o['id']], 'Decline', 'btn-outline-ink btn-sm') ?></div>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php if ($load['status'] === 'open'): ?>
  <h2 class="h5 mt-4 mb-1">Trucks that fit this load</h2>
  <p class="text-muted small mb-3">Ranked out of 100 on distance, load size, availability and delivery time. You choose who to trust.</p>
  <?php if (!$ranked): ?>
    <div class="empty">No truck fits yet. A truck needs to carry <?= number_format((int)$load['weight_kg']) ?> kg, be free on <?= e(fmt_date($load['pickup_date'])) ?>, and have no other job that day. New vehicles are added often, so check back soon.</div>
  <?php endif; ?>
  <div class="row g-3">
    <?php foreach ($ranked as $i => $row): $v = $row['veh']; $s = $row['s']; $r = $row['req']; ?>
      <div class="col-md-6"><div class="match-card<?= $i === 0 ? ' top' : '' ?>">
        <div class="d-flex justify-content-between align-items-start">
          <div><span class="rank-tag<?= $i === 0 ? '' : ' alt' ?>"><?= $i === 0 ? 'Recommended' : '#' . ($i + 1) ?></span>
            <h3 class="h6 mt-2 mb-0"><?= e($v['label']) ?></h3>
            <p class="text-muted small mb-0"><?= e($v['owner_name']) ?> &middot; based in <?= e($v['base_name']) ?>, about <?= e($s['km']) ?> km from pickup &middot; <?= number_format((int)$v['capacity_kg']) ?> kg</p></div>
          <div class="total"><?= (int)$s['total'] ?><small>/100</small></div>
        </div>
        <?= gauge($s) ?>
        <p class="small text-muted mt-2 mb-3">Estimated trip time about <?= e($s['hours']) ?> hours.</p>
        <?php if ($r && $r['status'] === 'open' && $r['requested_by'] === 'farmer'): ?>
          <?= status_badge('open') ?> <span class="small text-muted">Request sent</span> <?= action_btn('cancel_request', ['request_id' => $r['id']], 'Cancel', 'btn-outline-ink btn-sm') ?>
        <?php elseif ($r && $r['status'] === 'open'): ?>
          <?= action_btn('accept', ['request_id' => $r['id']], 'Accept their offer') ?>
        <?php else: ?>
          <?= $r && $r['status'] === 'declined' ? status_badge('declined') . ' ' : '' ?>
          <?= action_btn('request', ['load_id' => $id, 'vehicle_id' => $v['id']], $r ? 'Request again' : 'Request this truck') ?>
        <?php endif; ?>
      </div></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>
<script src="live.js" defer></script>
<?php include 'includes/footer.php'; ?>