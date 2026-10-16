<?php
require_once 'auth.php';
$u = require_login();
$rows = fetch_all('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 50', [$u['id']]);
db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([$u['id']]);
$title = 'Notifications';
$poll = '';
include 'includes/header.php';
?>
<h1 class="h3 mb-3">Notifications</h1>
<?php if (!$rows): ?>
  <div class="empty">Nothing yet. Updates about your loads and trucks will show here.</div>
<?php else: ?>
  <ul class="notes panel p-0">
    <?php foreach ($rows as $n): ?>
      <li<?= $n['is_read'] ? '' : ' class="unread"' ?>>
        <span><?= $n['link'] ? '<a href="' . e($n['link']) . '">' . e($n['message']) . '</a>' : e($n['message']) ?></span>
        <small class="text-muted"><?= e(date('j M, H:i', strtotime($n['created_at']))) ?></small>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php include 'includes/footer.php'; ?>


