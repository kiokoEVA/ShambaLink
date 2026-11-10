<?php
// Admin-only: send a test SMS and a test email, and see exactly why one fails.
require_once 'auth.php';
require_once 'includes/mail.php';
require_once 'includes/sms.php';
$u = require_login('admin');

$result = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'sms') {
        $st = send_sms((int)$u['id'], trim($_POST['phone'] ?? ''), 'ShambaLink test message. If you can read this, SMS works.');
        $row = fetch_one('SELECT status, error FROM sms_log ORDER BY id DESC LIMIT 1');
        $result = 'SMS result: ' . $st . ($row && $row['error'] ? ' (' . $row['error'] . ')' : '');
    } elseif (($_POST['action'] ?? '') === 'email') {
        $st = send_email((int)$u['id'], trim($_POST['email'] ?? ''), 'ShambaLink test email',
            '<p>If you can read this, <b>email works</b>. Receipts will arrive like this.</p>');
        $row = fetch_one('SELECT status, error FROM email_log ORDER BY id DESC LIMIT 1');
        $result = 'Email result: ' . $st . ($row && $row['error'] ? ' (' . $row['error'] . ')' : '');
    }
}
$smsLog = fetch_all('SELECT phone, status, error, created_at FROM sms_log ORDER BY id DESC LIMIT 5');
$mailLog = fetch_all('SELECT email, subject, status, error, created_at FROM email_log ORDER BY id DESC LIMIT 5');

$title = 'Tests';
$poll = '';
include 'includes/header.php';
?>
<h1 class="h3 mb-1">SMS and email tests</h1>
<p class="text-muted mb-3">Send one test message and read the result. Use this before a demo.</p>
<?php if ($result): ?><div class="alert alert-info" role="alert"><?= e($result) ?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-lg-6">
    <form method="post" class="panel">
      <?= csrf_field() ?><input type="hidden" name="action" value="sms">
      <h2 class="h5">Test SMS</h2>
      <p class="small text-muted">Provider key: <b><?= AT_API_KEY !== '' ? 'set' : 'NOT set (config/sms.php)' ?></b> &middot; mode: <b><?= AT_SANDBOX ? 'sandbox' : 'live' ?></b> &middot; curl: <b><?= function_exists('curl_init') ? 'on' : 'OFF' ?></b>.
        In sandbox mode the text shows in the Africa's Talking simulator, not on a real phone.</p>
      <label class="form-label" for="phone">Phone number</label>
      <input class="form-control mb-3" id="phone" name="phone" placeholder="0712 345 678" required>
      <button class="btn btn-crate">Send test SMS</button>
    </form>
  </div>
  <div class="col-lg-6">
    <form method="post" class="panel">
      <?= csrf_field() ?><input type="hidden" name="action" value="email">
      <h2 class="h5">Test email</h2>
      <p class="small text-muted">SMTP: <b><?= defined('MAIL_HOST') && MAIL_HOST !== '' ? e(MAIL_HOST) : 'NOT set (config/mail.php)' ?></b>.</p>
      <label class="form-label" for="email">Send to</label>
      <input class="form-control mb-3" id="email" name="email" type="email" placeholder="you@example.com" required>
      <button class="btn btn-crate">Send test email</button>
    </form>
  </div>
</div>

<div class="row g-4 mt-1">
  <div class="col-lg-6"><h2 class="h6">Last SMS</h2>
    <div class="ledger-wrap"><table class="ledger"><thead><tr><th>To</th><th>Status</th><th>Error</th></tr></thead><tbody>
      <?php foreach ($smsLog as $r): ?><tr><td><?= e($r['phone']) ?></td><td><?= e($r['status']) ?></td><td class="small"><?= e($r['error'] ?? '') ?></td></tr><?php endforeach; ?>
      <?php if (!$smsLog): ?><tr><td colspan="3" class="text-muted">Nothing yet.</td></tr><?php endif; ?></tbody></table></div></div>
  <div class="col-lg-6"><h2 class="h6">Last emails</h2>
    <div class="ledger-wrap"><table class="ledger"><thead><tr><th>To</th><th>Status</th><th>Error</th></tr></thead><tbody>
      <?php foreach ($mailLog as $r): ?><tr><td><?= e($r['email']) ?><br><span class="small text-muted"><?= e($r['subject']) ?></span></td><td><?= e($r['status']) ?></td><td class="small"><?= e($r['error'] ?? '') ?></td></tr><?php endforeach; ?>
      <?php if (!$mailLog): ?><tr><td colspan="3" class="text-muted">Nothing yet.</td></tr><?php endif; ?></tbody></table></div></div>
</div>
<?php include 'includes/footer.php'; ?>