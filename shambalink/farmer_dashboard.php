<?php
require_once 'auth.php';
require_once 'includes/actions.php';
$u = require_login('farmer');
handle_post($u);

$loads = fetch_all(
    'SELECT l.*, o.name AS origin, d.name AS destination
     FROM loads l JOIN locations o ON o.id = l.origin_id JOIN locations d ON d.id = l.destination_id
     WHERE l.farmer_id = ? ORDER BY l.id DESC', [$u['id']]);
$ms = [];
foreach (fetch_all(
    "SELECT m.*, o.vehicle_type, t.name AS transporter, t.phone AS tphone
     FROM matches m JOIN offers o ON o.id = m.offer_id JOIN users t ON t.id = o.transporter_id
     WHERE m.load_id IN (SELECT id FROM loads WHERE farmer_id = ?) AND m.status IN ('pending','accepted')
     ORDER BY m.score DESC", [$u['id']]) as $m) {
    $ms[$m['load_id']][] = $m;
}
$title = 'My loads';
$today = date('Y-m-d');
include 'includes/header.php';
?>
<div class="row g-4">
  <div class="col-lg-4">
    <h1 class="h4 mb-3">Post a load</h1>
    <?= flash_get() ?>
    <form method="post" class="panel" id="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="post_load">
      <label class="form-label" for="commodity">Produce</label>
      <input class="form-control mb-3" id="commodity" name="commodity" list="produce" maxlength="80" required>
      <datalist id="produce"><option>Tomatoes</option><option>Kale (sukuma wiki)</option><option>Cabbages</option><option>Potatoes</option><option>Onions</option><option>Mangoes</option><option>Bananas</option><option>Avocados</option><option>French beans</option><option>Oranges</option><option>Maize</option><option>Milk</option></datalist>
      <label class="form-label" for="weight_kg">Weight (kg)</label>
      <input class="form-control mb-3" id="weight_kg" name="weight_kg" type="number" min="1" max="40000" required>
      <label class="form-label" for="origin_id">Pickup location</label>
      <select class="form-select mb-3" id="origin_id" name="origin_id" required><?= location_options((int)$u['location_id']) ?></select>
      <label class="form-label" for="destination_id">Deliver to</label>
      <select class="form-select mb-3" id="destination_id" name="destination_id" required><?= location_options() ?></select>
      <div class="row g-2 mb-3">
        <div class="col-6"><label class="form-label" for="pickup_date">Pickup date</label><input class="form-control" id="pickup_date" name="pickup_date" type="date" min="<?= $today ?>" value="<?= $today ?>" required></div>
        <div class="col-6"><label class="form-label" for="deliver_by">Deliver by</label><input class="form-control" id="deliver_by" name="deliver_by" type="date" min="<?= $today ?>" value="<?= $today ?>" required></div>
      </div>
      <button class="btn btn-crate w-100">Post load</button>
    </form>
  </div>

  <div class="col-lg-8">
    <h2 class="h4 mb-3">My loads</h2>
    <section id="live">
    <?php if (!$loads): ?>
      <div class="empty">No loads yet. Post your first load and ranked transporters will appear for it.</div>
    <?php endif; ?>
    <?php foreach ($loads as $l): $lm = $ms[$l['id']] ?? []; ?>
      <article class="load-item">
        <div class="d-flex justify-content-between gap-3">
          <div>
            <h3 class="h6 mb-1"><?= e($l['commodity']) ?>, <?= number_format($l['weight_kg']) ?> kg</h3>
            <p class="mb-0 text-muted small"><?= e($l['origin']) ?> to <?= e($l['destination']) ?>. Pickup <?= e(date('j M', strtotime($l['pickup_date']))) ?>, deliver by <?= e(date('j M', strtotime($l['deliver_by']))) ?>.</p>
          </div>
          <div class="text-end"><?= badge($l['status']) ?></div>
        </div>

        <?php if ($l['status'] === 'open'): ?>
          <div class="d-flex gap-2 mt-3">
            <a class="btn btn-crate btn-sm" href="matches.php?load_id=<?= $l['id'] ?>">Find transporters</a>
            <form method="post" onsubmit="return confirm('Cancel this load?')"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_load"><input type="hidden" name="load_id" value="<?= $l['id'] ?>"><button class="btn btn-outline-ink btn-sm">Cancel load</button></form>
          </div>
        <?php endif; ?>

        <?php foreach ($lm as $m): ?>
          <div class="sub">
          <?php if ($m['status'] === 'accepted'): ?>
            <span><b><?= e($m['transporter']) ?></b> is carrying this load in a <?= e($m['vehicle_type']) ?>. Call <a href="tel:<?= e($m['tphone']) ?>"><?= e($m['tphone']) ?></a>.</span>
          <?php elseif ($m['initiated_by'] === 'transporter'): ?>
            <span><b><?= e($m['transporter']) ?></b> (<?= e($m['vehicle_type']) ?>, score <?= (int)$m['score'] ?>/100) wants to carry this.</span>
            <span class="d-flex gap-2">
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="accept"><input type="hidden" name="match_id" value="<?= $m['id'] ?>"><button class="btn btn-crate btn-sm">Accept</button></form>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="decline"><input type="hidden" name="match_id" value="<?= $m['id'] ?>"><button class="btn btn-outline-ink btn-sm">Decline</button></form>
            </span>
          <?php else: ?>
            <span>Waiting for <b><?= e($m['transporter']) ?></b> (<?= e($m['vehicle_type']) ?>) to reply.</span>
          <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </article>
    <?php endforeach; ?>
    </section>
  </div>
</div>
<script>
// keep "deliver by" from falling before the pickup date
const pu = document.getElementById('pickup_date'), db = document.getElementById('deliver_by');
pu.addEventListener('change', () => { db.min = pu.value; if (db.value < pu.value) db.value = pu.value; });
</script>
<?php include 'includes/footer.php'; ?>
