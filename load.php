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
    <?php if (!empty($load['needs_refrigeration'])): ?><p class="small mt-1 mb-0"><span class="badge text-bg-info">Refrigerated truck needed</span></p><?php endif; ?>
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
    <?php if (!empty($load['agreed_fare_kes'])): ?><p class="mb-1">Agreed price: <b>KES <?= number_format((int)$load['agreed_fare_kes']) ?></b></p><?php endif; ?>
    <?php if (in_array($load['status'], ['matched', 'in_transit', 'delivered'], true)): ?>
      <p class="mb-2">Call: <a href="tel:+<?= e($accepted['driver_phone']) ?>"><b><?= e(format_phone($accepted['driver_phone'])) ?></b></a></p>
    <?php endif; ?>
    <p class="text-muted small mb-0">Matched with a score of <?= (int)$accepted['score_total'] ?>/100.</p>
    <?= gauge(['prox' => $accepted['score_prox'], 'cap' => $accepted['score_cap'], 'avail' => $accepted['score_avail'], 'time' => $accepted['score_time']]) ?>
  </div>
<?php endif; ?>

<?php if ($load['status'] === 'delivered' && !empty($load['agreed_fare_kes'])): ?>
  <div class="panel mb-4">
    <h2 class="h5">Payment</h2>
    <p class="mb-2">Agreed price: <b>KES <?= number_format((int)$load['agreed_fare_kes']) ?></b></p>
    <?php if ($load['payment_status'] === 'confirmed'): ?>
      <p class="mb-0"><span class="badge text-bg-success">Paid</span>
        <span class="small text-muted"><?= $load['payment_method'] === 'mpesa' ? 'M-Pesa ' . e($load['payment_ref']) : 'Cash' ?>, confirmed by the driver.</span></p>
    <?php elseif ($load['payment_status'] === 'farmer_paid'): ?>
      <p class="mb-0"><span class="badge text-bg-warning">Waiting for the driver to confirm</span>
        <span class="small text-muted">You recorded <?= $load['payment_method'] === 'mpesa' ? 'M-Pesa ' . e($load['payment_ref']) : 'cash' ?>.</span></p>
    <?php else: ?>
      <p class="small text-muted">Pay the driver directly, by cash or M-Pesa, then record it here. ShambaLink records payments and does not process money.</p>
      <form method="post" action="request_action.php" class="d-flex gap-2 flex-wrap align-items-end">
        <?= csrf_field() ?><input type="hidden" name="action" value="pay"><input type="hidden" name="back" value="load.php?id=<?= (int)$id ?>"><input type="hidden" name="load_id" value="<?= (int)$id ?>">
        <div><label class="small text-muted d-block" for="payMethod">Paid by</label>
          <select class="form-select form-select-sm" id="payMethod" name="method"><option value="mpesa">M-Pesa</option><option value="cash">Cash</option></select></div>
        <div><label class="small text-muted d-block" for="payRef">M-Pesa code</label>
          <input class="form-control form-control-sm" id="payRef" name="ref" maxlength="12" placeholder="e.g. QGH7XYZ123"></div>
        <button class="btn btn-crate btn-sm">I have paid</button>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($offers): ?>
  <h2 class="h5 mb-3">Offers from transporters</h2>
  <?php foreach ($offers as $o): ?>
    <div class="load-item">
      <div class="d-flex justify-content-between flex-wrap gap-2 align-items-center">
        <div><b><?= e($o['driver']) ?></b> <span class="text-muted">&middot; <?= e($o['label']) ?> &middot; score <?= (int)$o['score_total'] ?>/100</span><?= !empty($o['fare_kes']) ? ' &middot; <b>KES ' . number_format((int)$o['fare_kes']) . '</b>' : '' ?></div>
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
            <h3 class="h6 mt-2 mb-0"><?= e($v['label']) ?><?= !empty($v['refrigerated']) ? ' <span class="badge text-bg-info">Refrigerated</span>' : '' ?></h3>
            <p class="text-muted small mb-0"><?= e($v['owner_name']) ?> &middot; based in <?= e($v['base_name']) ?>, about <?= e($s['km']) ?> km from pickup &middot; <?= number_format((int)$v['capacity_kg']) ?> kg</p></div>
          <div class="total"><?= (int)$s['total'] ?><small>/100</small></div>
        </div>
        <?= gauge($s) ?>
        <p class="small text-muted mt-2 mb-2">Estimated trip time about <?= e($s['hours']) ?> hours<?= !empty($s['route_km']) ? ', route about ' . e($s['route_km']) . ' km' : '' ?>.</p>
        <?php if (!empty($s['fare'])): ?><div class="mb-2" style="font-family:var(--head);font-size:1.5rem;font-weight:700">KES <?= number_format((int)$s['fare']) ?> <small class="text-muted" style="font-size:.85rem;font-weight:500">estimated fare</small></div><?php else: ?><div class="small text-muted mb-2">This truck has no rate yet. You can propose a price.</div><?php endif; ?>
        <?php if (!empty($v['refrigerated']) && !empty($s['fare'])): ?><div class="small text-muted mb-2">Includes a <?= (int)round(FARE_REFRIGERATED_PREMIUM * 100) ?>% refrigeration premium.</div><?php endif; ?>
        <?php if ($r && $r['status'] === 'open' && $r['requested_by'] === 'farmer'): ?>
          <?= status_badge('open') ?> <span class="small text-muted">Request sent<?= !empty($r['fare_kes']) ? ' for KES ' . number_format((int)$r['fare_kes']) : '' ?></span> <?= action_btn('cancel_request', ['request_id' => $r['id']], 'Cancel', 'btn-outline-ink btn-sm') ?>
        <?php elseif ($r && $r['status'] === 'open'): ?>
          <?= !empty($r['fare_kes']) ? '<span class="small">Their price: <b>KES ' . number_format((int)$r['fare_kes']) . '</b></span> ' : '' ?>
          <?= action_btn('accept', ['request_id' => $r['id']], 'Accept their offer') ?>
        <?php else: ?>
          <?= $r && $r['status'] === 'declined' ? status_badge('declined') . ' ' : '' ?>
          <form method="post" action="request_action.php">
            <?= csrf_field() ?><input type="hidden" name="action" value="request"><input type="hidden" name="back" value="load.php?id=<?= (int)$id ?>">
            <input type="hidden" name="load_id" value="<?= (int)$id ?>"><input type="hidden" name="vehicle_id" value="<?= (int)$v['id'] ?>">
            <button class="btn btn-crate btn-sm"><?= $r ? 'Request again' : 'Request this truck' ?><?= !empty($s['fare']) ? ' for KES ' . number_format((int)$s['fare']) : '' ?></button>
          </form>
          <div class="small text-muted mt-1">Fixed price, calculated automatically from the distance and the truck.</div>
        <?php endif; ?>
      </div></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>
<div class="panel mt-4 trip-box" id="trackBox" data-role="farmer" data-load-id="<?= (int)$id ?>" style="display:none">
  <h2 class="h5 mb-1">Locations and live tracking</h2>
  <p class="small text-muted mb-2 trip-info">Checking...</p>
  <form class="pin-form"><?= csrf_field() ?></form>
  <div class="pin-controls d-flex gap-2 flex-wrap align-items-center mb-2">
    <button type="button" class="btn btn-crate btn-sm btn-pin-gps">Use my current location as pickup</button>
    <button type="button" class="btn btn-outline-ink btn-sm btn-pin-map">Pick the pickup spot on the map</button>
    <span class="small text-muted pin-status"></span>
  </div>
  <div class="trip-map" style="height:320px;border-radius:12px;overflow:hidden"></div>
</div>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js" defer></script>
<script src="js/track.js?v=<?= @filemtime(__DIR__ . '/js/track.js') ?>" defer></script>
<script src="js/live.js?v=<?= @filemtime(__DIR__ . '/js/live.js') ?>" defer></script>
<?php include 'includes/footer.php'; ?>