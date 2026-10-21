<?php
require_once 'auth.php';
$me = require_login('admin');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    db()->prepare('UPDATE contact_messages SET handled = 1 WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
    redirect('admin_messages.php');
}
$rows = db()->query('SELECT * FROM contact_messages ORDER BY handled ASC, created_at DESC LIMIT 100')->fetchAll();
$title = 'Messages';
$poll = '';
include 'includes/header.php';
?>
<h1 class="h3 mb-3">Contact messages</h1>
<?php if (!$rows): ?><div class="empty">No messages yet.</div><?php endif; ?>
<?php foreach ($rows as $m): ?>
  <div class="load-item">
    <div class="d-flex justify-content-between flex-wrap gap-2">
      <b><?= e($m['name']) ?> <small class="text-muted">about <?= e($m['topic']) ?></small></b>
      <span class="st <?= $m['handled'] ? 'st-delivered' : 'st-open' ?>"><?= $m['handled'] ? 'handled' : 'new' ?></span>
    </div>
    <p class="my-2" style="white-space:pre-wrap"><?= e($m['message']) ?></p>
    <div class="sub">
      <span class="text-muted"><?= e(trim(($m['phone'] ?? '') . ' ' . ($m['email'] ?? ''))) ?> &middot; <?= e($m['created_at']) ?></span>
      <?php if (!$m['handled']): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn btn-outline-ink btn-sm">Mark handled</button></form>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
<?php include 'includes/footer.php'; ?>
