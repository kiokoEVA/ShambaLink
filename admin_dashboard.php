<?php
require_once 'auth.php';
$u = require_login('admin');

$one = fn(string $sql) => (fetch_one($sql)['n'] ?? 0);
$pending = $one("SELECT COUNT(*) AS n FROM users WHERE role = 'transporter' AND driver_status = 'pending'");
$stats = [
    'Farmers'            => $one("SELECT COUNT(*) AS n FROM users WHERE role = 'farmer'"),
    'Transporters'       => $one("SELECT COUNT(*) AS n FROM users WHERE role = 'transporter'"),
    'Approved drivers'   => $one("SELECT COUNT(*) AS n FROM users WHERE role = 'transporter' AND driver_status = 'approved'"),
    'Awaiting approval'  => $pending,
    'Loads posted'       => $one('SELECT COUNT(*) AS n FROM loads'),
    'Loads matched'      => $one("SELECT COUNT(*) AS n FROM loads WHERE status IN ('matched','in_transit','delivered')"),
    'Delivered'          => $one("SELECT COUNT(*) AS n FROM loads WHERE status = 'delivered'"),
    'Active vehicles'    => $one('SELECT COUNT(*) AS n FROM vehicles WHERE active = 1'),
];
// Average minutes from posting a load to the match being accepted
$avg = fetch_one("SELECT ROUND(AVG(TIMESTAMPDIFF(MINUTE, l.created_at, mr.responded_at)), 1) AS n
                  FROM match_requests mr JOIN loads l ON l.id = mr.load_id
                  WHERE mr.status = 'accepted' AND mr.responded_at IS NOT NULL")['n'] ?? null;
$sms = fetch_all('SELECT status, COUNT(*) AS n FROM sms_log GROUP BY status');
$byLoad = fetch_all('SELECT status, COUNT(*) AS n FROM loads GROUP BY status');
$log = fetch_all('SELECT * FROM sms_log ORDER BY id DESC LIMIT 15');
$users = fetch_all('SELECT u.name, u.phone, u.role, l.name AS location, u.created_at FROM users u LEFT JOIN locations l ON l.id = u.location_id ORDER BY u.id DESC LIMIT 15');
$title = 'Admin overview';
include 'includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <h1 class="h4 mb-0">Overview</h1>
  <a class="btn btn-crate btn-sm" href="admin_drivers.php">Review drivers<?= $pending ? ' (' . (int)$pending . ' waiting)' : '' ?></a>
</div>
<section id="live">
  <div class="row g-3 mb-4">
    <?php foreach ($stats as $k => $v): ?><div class="col-6 col-md-4 col-lg-3"><div class="stat"><b><?= (int)$v ?></b><span><?= e($k) ?></span></div></div><?php endforeach; ?>
  </div>
  <p class="mb-4">Average time from posting a load to a confirmed match: <b><?= $avg !== null ? e($avg) . ' minutes' : 'no confirmed matches yet' ?></b>.
     Loads by status: <?php foreach ($byLoad as $r) echo status_badge($r['status']), ' ', (int)$r['n'], '&nbsp;&nbsp;'; ?></p>

  <div class="row g-4">
    <div class="col-lg-7">
      <h2 class="h5">Recent SMS <span class="text-muted small">(<?= $sms ? e(implode(', ', array_map(fn($r) => $r['n'] . ' ' . $r['status'], $sms))) : 'none yet' ?>)</span></h2>
      <div class="ledger-wrap"><table class="ledger"><thead><tr><th>To</th><th>Message</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($log as $r): ?><tr><td class="text-nowrap"><?= e($r['phone']) ?></td><td><?= e($r['message']) ?></td><td><?= e($r['status']) ?></td></tr><?php endforeach; ?>
        <?php if (!$log): ?><tr><td colspan="3" class="text-muted">No messages yet.</td></tr><?php endif; ?>
      </tbody></table></div>
    </div>
    <div class="col-lg-5">
      <h2 class="h5">Newest users</h2>
      <div class="ledger-wrap"><table class="ledger"><thead><tr><th>Name</th><th>Role</th><th>Location</th></tr></thead><tbody>
        <?php foreach ($users as $r): ?><tr><td><?= e($r['name']) ?><br><span class="text-muted small"><?= e($r['phone']) ?></span></td><td><?= e($r['role']) ?></td><td><?= e($r['location']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    </div>
  </div>
</section>
<?php include 'includes/footer.php'; ?>