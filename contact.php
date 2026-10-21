<?php
require_once 'auth.php';
$u = current_user();
$topics = ['question' => 'A question', 'problem' => 'Report a problem', 'partner' => 'Partnership', 'other' => 'Something else'];
$err = [];
$v = ['name' => $u['name'] ?? '', 'phone' => $u['phone'] ?? '', 'email' => $u['email'] ?? '', 'topic' => 'question', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!empty($_POST['website'])) redirect('contact.php');   // hidden field only bots fill in
    $v = [
        'name'    => trim($_POST['name'] ?? ''),
        'phone'   => trim($_POST['phone'] ?? ''),
        'email'   => trim($_POST['email'] ?? ''),
        'topic'   => $_POST['topic'] ?? '',
        'message' => trim($_POST['message'] ?? ''),
    ];
    $phone = $v['phone'] !== '' ? normalize_phone($v['phone']) : null;
    if (mb_strlen($v['name']) < 2 || mb_strlen($v['name']) > 100) $err[] = 'Enter your name.';
    if ($v['phone'] === '' && $v['email'] === '') $err[] = 'Give a phone number or an email so we can reply.';
    if ($v['phone'] !== '' && !$phone) $err[] = 'Enter a valid Kenyan mobile number, for example 0712 345 678.';
    if ($v['email'] !== '' && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $err[] = 'That email address does not look right.';
    if (!isset($topics[$v['topic']])) $err[] = 'Choose what your message is about.';
    if (mb_strlen($v['message']) < 10) $err[] = 'Write at least a sentence so we understand.';
    if (mb_strlen($v['message']) > 2000) $err[] = 'Please keep your message under 2000 characters.';
    if (time() - ($_SESSION['last_contact'] ?? 0) < 30) $err[] = 'Please wait a moment before sending another message.';
    if (!$err) {
        db()->prepare('INSERT INTO contact_messages (user_id, name, phone, email, topic, message) VALUES (?,?,?,?,?,?)')
            ->execute([$u['id'] ?? null, $v['name'], $phone, $v['email'] ?: null, $v['topic'], $v['message']]);
        $_SESSION['last_contact'] = time();
        flash_set('Thank you. We have your message and will reply soon.');
        redirect('contact.php');
    }
}
$title = 'Contact us';
$poll = '';
include 'includes/header.php';
?>
<div class="row justify-content-center pt-lg-4"><div class="col-md-9 col-lg-7 col-xl-6">
  <h1 class="h3 mb-1">Contact us</h1>
  <p class="text-muted mb-4">Questions, a problem with a match, or an idea for <?= APP_NAME ?>. Tell us and we will reply by phone or email.</p>
  <?= flash_get() ?>
  <?php foreach ($err as $m): ?><div class="alert alert-danger py-2" role="alert"><?= e($m) ?></div><?php endforeach; ?>
  <form method="post" class="panel">
    <?= csrf_field() ?>
    <div style="position:absolute;left:-9999px" aria-hidden="true">
      <label>Leave this empty <input name="website" tabindex="-1" autocomplete="off"></label>
    </div>
    <label class="form-label" for="name">Your name</label>
    <input class="form-control mb-3" id="name" name="name" autocomplete="name" value="<?= e($v['name']) ?>" required>
    <div class="row">
      <div class="col-sm-6">
        <label class="form-label" for="phone">Phone number</label>
        <input class="form-control mb-3" id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="0712 345 678" value="<?= e($v['phone']) ?>">
      </div>
      <div class="col-sm-6">
        <label class="form-label" for="email">Email</label>
        <input class="form-control mb-3" id="email" name="email" type="email" autocomplete="email" value="<?= e($v['email']) ?>">
      </div>
    </div>
    <div class="form-text text-muted mb-3" style="margin-top:-.5rem">Give at least one, so we can reply.</div>
    <label class="form-label" for="topic">What is it about?</label>
    <select class="form-select mb-3" id="topic" name="topic" required>
      <?php foreach ($topics as $k => $label): ?><option value="<?= $k ?>" <?= $v['topic'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select>
    <label class="form-label" for="message">Message</label>
    <textarea class="form-control mb-3" id="message" name="message" rows="6" maxlength="2000" required><?= e($v['message']) ?></textarea>
    <button class="btn btn-crate w-100">Send message</button>
  </form>
</div></div>
<?php include 'includes/footer.php'; ?>
