<?php
require_once 'auth.php';
require_once 'includes/actions.php';
$u = require_login('transporter');
handle_post($u);

$offers = fetch_all('SELECT o.*, b.name AS base FROM offers o JOIN locations b ON b.id = o.base_id WHERE o.transporter_id = ? ORDER BY o.id DESC', [$u['id']]);
$jobSql = "SELECT m.*, l.commodity, l.weight_kg, l.pickup_date, l.deliver_by, l.status AS load_status,
                  lo.name AS origin, ld.name AS destination, f.name AS farmer, f.phone AS farmer_phone, o.vehicle_type
           FROM matches m
           JOIN offers o ON o.id = m.offer_id JOIN loads l ON l.id = m.load_id
           JOIN locations lo ON lo.id = l.origin_id JOIN locations ld ON ld.id = l.destination_id
           JOIN users f ON f.id = l.farmer_id
           WHERE o.transporter_id = ? ";
$requests = fetch_all($jobSql . "AND m.initiated_by = 'farmer' AND m.status = 'pending' AND l.status = 'open' ORDER BY m.id DESC", [$u['id']]);
$jobs     = fetch_all($jobSql . "AND m.status = 'accepted' AND l.status IN ('matched','in_transit') ORDER BY l.pickup_date", [$u['id']]);

// Open-load board: show only loads at least one of my active vehicles can carry, with my best score
$active = array_values(array_filter($offers, fn($o) => (int)$o['active'] === 1));
$mine = [];
foreach (fetch_all('SELECT m.load_id, m.status, m.initiated_by FROM matches m JOIN offers o ON o.id = m.offer_id WHERE o.transporter_id = ?', [$u['id']]) as $m) $mine[$m['load_id']] = ($m['status'] === 'pending' && $m['initiated_by'] === 'farmer') ? 'requested' : $m['status'];
$board = [];
foreach (fetch_all(
    "SELECT l.*, lo.name AS origin, ld.name AS destination FROM loads l
     JOIN locations lo ON lo.id = l.origin_id JOIN locations ld ON ld.id = l.destination_id
     WHERE l.status = 'open' AND l.pickup_date >= CURDATE() ORDER BY l.id DESC LIMIT 50") as $l) {
    $best = null;
    foreach ($active as $o) {
        $sc = score_match($l, $o);
        if ($sc && (!$best || $sc['total'] > $best['sc']['total'])) $best = ['sc' => $sc, 'offer' => $o];
    }
    if ($best) $board[] = $l + ['best' => $best];
}
usort($board, fn($a, $b) => $b['best']['sc']['total'] <=> $a['best']['sc']['total']);
$title = 'My vehicles and jobs';
$today = date('Y-m-d');
include 'includes/header.php';
?>
<div class="row g-4">
  <div class="col-lg-4">
    <h1 class="h4 mb-3">Post a vehicle</h1>
    <?= flash_get() ?>
    <form method="post" class="panel">
      <?= csrf_field() ?><input type="hidden" name="action" value="post_offer">
      <label class="form-label" for="vehicle_type">Vehicle</label>
      <input class="form-control mb-3" id="vehicle_type" name="vehicle_type" list="vehicles" maxlength="60" placeholder="Canter, 3 tonne" required>
      <datalist id="vehicles"><option>Pickup, 1 tonne</option><option>Canter, 3 tonne</option><option>Isuzu FRR, 5 tonne</option><option>Lorry, 10 tonne</option><option>Trailer, 28 tonne</option></datalist>
      <label class="form-label" for="capacity_kg">Capacity (kg)</label>
      <input class="form-control mb-3" id="capacity_kg" name="capacity_kg" type="number" min="100" max="40000" required>
      <label class="form-label" for="base_id">Based in</label>
      <select class="form-select mb-3" id="base_id" name="base_id" required><?= location_options((int)$u['location_id']) ?></select>
      <div class="row g-2 mb-3">
        <div class="col-6"><label class="form-label" for="available_from">Free from</label><input class="form-control" id="available_from" name="available_from" type="date" min="<?= $today ?>" value="<?= $today ?>" required></div>
        <div class="col-6"><label class="form-label" for="available_to">Free until</label><input class="form-control" id="available_to" name="available_to" type="date" min="<?= $today ?>" value="<?= date('Y-m-d', strtotime('+14 days')) ?>" required></div>
      </div>
      <label class="form-label" for="rate_per_km">Rate per km (KES)</label>
      <input class="form-control mb-3" id="rate_per_km" name="rate_per_km" type="number" min="1" max="1000" step="0.5" required>
      <button class="btn btn-crate w-100">Post vehicle</button>
    </form>
  </div>

  <div class="col-lg-8">
    <section id="live">
      <h2 class="h4 mb-3">Requests for you</h2>
      <?php if (!$requests): ?><div class="empty mb-4">No requests right now. New ones appear here without refreshing.</div><?php endif; ?>
      <?php foreach ($requests as $r): ?>
        <article class="load-item">
          <div class="d-flex justify-content-between gap-3">
            <div>
              <h3 class="h6 mb-1"><?= e($r['commodity']) ?>, <?= number_format($r['weight_kg']) ?> kg</h3>
              <p class="mb-0 text-muted small"><?= e($r['farmer']) ?> asked for your <?= e($r['vehicle_type']) ?>. <?= e($r['origin']) ?> to <?= e($r['destination']) ?>, pickup <?= e(date('j M', strtotime($r['pickup_date']))) ?>.</p>
            </div>
            <div class="total sm"><?= (int)$r['score'] ?><small>/100</small></div>
          </div>
          <div class="d-flex gap-2 mt-3">
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="accept"><input type="hidden" name="match_id" value="<?= $r['id'] ?>"><button class="btn btn-crate btn-sm">Accept</button></form>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="decline"><input type="hidden" name="match_id" value="<?= $r['id'] ?>"><button class="btn btn-outline-ink btn-sm">Decline</button></form>
          </div>
        </article>
      <?php endforeach; ?>

      <h2 class="h4 mt-4 mb-3">My jobs</h2>
      <?php if (!$jobs): ?><div class="empty mb-4">No confirmed jobs yet.</div><?php endif; ?>
      <?php foreach ($jobs as $j): ?>
        <article class="load-item">
          <div class="d-flex justify-content-between gap-3">
            <div>
              <h3 class="h6 mb-1"><?= e($j['commodity']) ?>, <?= number_format($j['weight_kg']) ?> kg</h3>
              <p class="mb-0 text-muted small"><?= e($j['origin']) ?> to <?= e($j['destination']) ?>. Pickup <?= e(date('j M', strtotime($j['pickup_date']))) ?>, deliver by <?= e(date('j M', strtotime($j['deliver_by']))) ?>. Farmer <?= e($j['farmer']) ?>, <a href="tel:<?= e($j['farmer_phone']) ?>"><?= e($j['farmer_phone']) ?></a>.</p>
            </div>
            <div><?= badge($j['load_status']) ?></div>
          </div>
          <form method="post" class="mt-3"><?= csrf_field() ?><input type="hidden" name="action" value="trip"><input type="hidden" name="load_id" value="<?= $j['load_id'] ?>">
            <?php if ($j['load_status'] === 'matched'): ?><input type="hidden" name="to" value="in_transit"><button class="btn btn-crate btn-sm">Start trip</button>
            <?php else: ?><input type="hidden" name="to" value="delivered"><button class="btn btn-crate btn-sm">Mark as delivered</button><?php endif; ?>
          </form>
        </article>
      <?php endforeach; ?>

      <h2 class="h4 mt-4 mb-3">Open loads that fit your vehicles</h2>
      <?php if (!$board): ?><div class="empty mb-4">No open loads fit your active vehicles right now. Post a vehicle or widen its dates, and this list updates itself.</div>
      <?php else: ?>
      <div class="ledger-wrap mb-4"><table class="ledger">
        <thead><tr><th>Load</th><th>Route</th><th>Pickup</th><th>Your best fit</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($board as $l): $b = $l['best']; $state = $mine[$l['id']] ?? null; ?>
          <tr>
            <td><b><?= e($l['commodity']) ?></b><br><span class="text-muted small"><?= number_format($l['weight_kg']) ?> kg</span></td>
            <td><?= e($l['origin']) ?> to <?= e($l['destination']) ?><br><span class="text-muted small"><?= $b['sc']['route_km'] ?> km, about KES <?= number_format($b['sc']['cost']) ?></span></td>
            <td><?= e(date('j M', strtotime($l['pickup_date']))) ?></td>
            <td><b><?= $b['sc']['total'] ?></b>/100<br><span class="text-muted small"><?= e($b['offer']['vehicle_type']) ?></span></td>
            <td class="text-end">
              <?php if ($state === 'pending'): ?><span class="text-muted small">Waiting for farmer</span>
              <?php elseif ($state === 'requested'): ?><span class="text-muted small">Farmer asked you, see requests</span>
              <?php else: ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="apply"><input type="hidden" name="load_id" value="<?= $l['id'] ?>"><input type="hidden" name="offer_id" value="<?= $b['offer']['id'] ?>"><button class="btn btn-crate btn-sm">I can carry this</button></form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <?php endif; ?>

      <h2 class="h4 mb-3">My vehicles</h2>
      <?php if (!$offers): ?><div class="empty">You have not posted a vehicle yet. Use the form to add one.</div><?php endif; ?>
      <?php foreach ($offers as $o): ?>
        <article class="load-item d-flex justify-content-between align-items-center gap-3">
          <div>
            <h3 class="h6 mb-1"><?= e($o['vehicle_type']) ?>, <?= number_format($o['capacity_kg']) ?> kg</h3>
            <p class="mb-0 text-muted small">Based in <?= e($o['base']) ?>. Free <?= e(date('j M', strtotime($o['available_from']))) ?> to <?= e(date('j M', strtotime($o['available_to']))) ?>. KES <?= e(rtrim(rtrim(number_format($o['rate_per_km'], 2), '0'), '.')) ?> per km.</p>
          </div>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_offer"><input type="hidden" name="offer_id" value="<?= $o['id'] ?>">
            <button class="btn btn-outline-ink btn-sm"><?= $o['active'] ? 'Pause' : 'Resume' ?></button></form>
        </article>
      <?php endforeach; ?>
    </section>
  </div>
</div>
<script>
const af = document.getElementById('available_from'), at = document.getElementById('available_to');
af.addEventListener('change', () => { at.min = af.value; if (at.value < af.value) at.value = af.value; });
</script>
<?php include 'includes/footer.php'; ?>
