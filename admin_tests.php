<?php
// Admin-only: send a test SMS and a test email, and see exactly why one fails.
require_once 'auth.php';
$mailerFile = __DIR__ . '/includes/mailer.php';
if (is_file($mailerFile)) require_once $mailerFile;
require_once 'includes/sms.php';
$u = require_login('admin');

$result = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'sms') {
        $st = send_sms((int)$u['id'], trim($_POST['phone'] ?? ''), 'ShambaLink test message. If you can read this, SMS works.');
        $row = fetch_one('SELECT status, error FROM sms_log ORDER BY id DESC LIMIT 1');
        $result = 'SMS result: ' . $st . ($row && $row['error'] ? ' (' . $row['error'] . ')' : '');
    } elseif (($_POST['action'] ?? '') === 'email' && !function_exists('send_email')) {
        $result = 'Email code not loaded: ' . (is_file($mailerFile)
            ? 'includes/mailer.php exists but does not contain send_email(). Replace it with the mailer.php from the chat.'
            : 'includes/mailer.php is missing. Copy it into the includes folder.');
    } elseif (($_POST['action'] ?? '') === 'email') {
        $st = send_email((int)$u['id'], trim($_POST['email'] ?? ''), 'ShambaLink test email',
            '<p>If you can read this, <b>email works</b>. Receipts will arrive like this.</p>');
        $row = fetch_one('SELECT status, error FROM email_log ORDER BY id DESC LIMIT 1');
        $result = 'Email result: ' . $st . ($row && $row['error'] ? ' (' . $row['error'] . ')' : '');
    }
}
$smsLog = fetch_all('SELECT phone, status, error, created_at FROM sms_log ORDER BY id DESC LIMIT 5');
$mailLog = fetch_all('SELECT email, subject, status, error, created_at FROM email_log ORDER BY id DESC LIMIT 5');

// ---- setup checklist: every requirement, with a green tick or a red cross and how to fix it ----
$checks = [];
$chk = function (string $label, bool $ok, string $fix) use (&$checks) { $checks[] = [$label, $ok, $fix]; };
$hasTable = function (string $t): bool { try { return (bool)fetch_all("SHOW TABLES LIKE '" . $t . "'"); } catch (Throwable $e) { return false; } };
$smsCols = [];
try { $smsCols = array_column(fetch_all('SHOW COLUMNS FROM sms_log'), 'Field'); } catch (Throwable $e) {}
$mailPass = defined('MAIL_PASS') ? (string)MAIL_PASS : '';
$mailUser = defined('MAIL_USER') ? (string)MAIL_USER : '';
$emailChecks = [
  ['includes/mailer.php (the email sender)', function_exists('send_email'), 'Copy mailer.php into the includes folder (and delete includes/mail.php).'],
  ['includes/PHPMailer/PHPMailer.php', is_file(__DIR__ . '/includes/PHPMailer/PHPMailer.php'), 'Copy the PHPMailer folder into includes.'],
  ['includes/PHPMailer/SMTP.php', is_file(__DIR__ . '/includes/PHPMailer/SMTP.php'), 'Copy the PHPMailer folder into includes.'],
  ['includes/PHPMailer/Exception.php', is_file(__DIR__ . '/includes/PHPMailer/Exception.php'), 'Copy the PHPMailer folder into includes.'],
  ['config/mail.php exists', is_file(__DIR__ . '/config/mail.php'), 'Copy config/mail.example.php to config/mail.php.'],
  ['Gmail address set (MAIL_USER)', $mailUser !== '' && stripos($mailUser, 'yourname') === false, 'Put the sending Gmail address in config/mail.php.'],
  ['App password set (MAIL_PASS)', $mailPass !== '' && stripos($mailPass, 'PASTE') === false && strlen(str_replace(' ', '', $mailPass)) >= 16, 'Paste the 16-character Google app password, no spaces.'],
  ['PHP openssl extension is on', extension_loaded('openssl'), 'In C:\xampp\php\php.ini remove the ; before extension=openssl, then restart Apache.'],
  ['Table email_log exists', $hasTable('email_log'), 'Run email.sql in phpMyAdmin.'],
];
$smsChecks = [
  ['includes/sms.php (the SMS sender)', function_exists('send_sms'), 'Copy sms.php into the includes folder.'],
  ['config/sms.php exists', is_file(__DIR__ . '/config/sms.php'), 'Copy config/sms.example.php to config/sms.php.'],
  ['API key set (AT_API_KEY)', AT_API_KEY !== '' && stripos(AT_API_KEY, 'PASTE') === false, 'Paste your Africa\'s Talking sandbox API key in config/sms.php.'],
  ['PHP curl extension is on', function_exists('curl_init'), 'In php.ini remove the ; before extension=curl, then restart Apache.'],
  ['Table sms_log has error and provider_ref columns', in_array('error', $smsCols, true) && in_array('provider_ref', $smsCols, true), 'Add the missing columns to sms_log in phpMyAdmin.'],
];

$title = 'Tests';
$poll = '';
include 'includes/header.php';
?>
<h1 class="h3 mb-1">SMS and email tests</h1>
<p class="text-muted mb-3">Send one test message and read the result. Use this before a demo.</p>
<?php if ($result): ?><div class="alert alert-info" role="alert"><?= e($result) ?></div><?php endif; ?>

<div class="row g-4 mb-1">
  <?php foreach ([['Email setup', $emailChecks], ['SMS setup', $smsChecks]] as [$__name, $__list]): ?>
  <div class="col-lg-6"><div class="panel">
    <h2 class="h5"><?= e($__name) ?></h2>
    <?php foreach ($__list as [$__label, $__ok, $__fix]): ?>
      <div class="py-1"><span style="font-weight:700;color:<?= $__ok ? '#1b7f4b' : '#c0392b' ?>"><?= $__ok ? '&#10003;' : '&#10007;' ?></span>
        <?= e($__label) ?><?php if (!$__ok): ?><div class="small text-muted ms-4"><?= e($__fix) ?></div><?php endif; ?></div>
    <?php endforeach; ?>
  </div></div>
  <?php endforeach; ?>
</div>

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