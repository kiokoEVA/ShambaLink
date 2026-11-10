<?php
/*
 * includes/profile_gate.php
 * Included at the top of the farmer and transporter dashboards, right after require_login().
 * Anyone without a saved email address (needed for receipts) or national ID number sees a short form first.
 * It covers accounts made before these fields existed, and any sign-up that skipped them.
 */
$__row = fetch_one('SELECT national_id, email FROM users WHERE id = ?', [$u['id']]);
$__needId = empty($__row['national_id']);
$__needEmail = empty($__row['email']);
if ($__needId || $__needEmail) {
    $__err = [];
    $__nid = '';
    $__em = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'profile') {
        csrf_check();
        $__sets = [];
        $__vals = [];
        if ($__needId) {
            $__nid = trim($_POST['national_id'] ?? '');
            if (!preg_match('/^\d{7,8}$/', $__nid)) $__err[] = 'Enter a valid national ID number (7 or 8 digits).';
            elseif (fetch_one('SELECT id FROM users WHERE national_id = ? AND id <> ?', [$__nid, $u['id']])) $__err[] = 'That ID number is already used by another account.';
            else { $__sets[] = 'national_id = ?'; $__vals[] = $__nid; }
        }
        if ($__needEmail) {
            $__em = strtolower(trim($_POST['email'] ?? ''));
            if (!filter_var($__em, FILTER_VALIDATE_EMAIL) || mb_strlen($__em) > 190) $__err[] = 'Enter a valid email address.';
            elseif (fetch_one('SELECT id FROM users WHERE email = ? AND id <> ?', [$__em, $u['id']])) $__err[] = 'That email address is already used by another account.';
            else { $__sets[] = 'email = ?'; $__vals[] = $__em; }
        }
        if (!$__err) {
            $__vals[] = $u['id'];
            db()->prepare('UPDATE users SET ' . implode(', ', $__sets) . ' WHERE id = ?')->execute($__vals);
            flash_set('Thank you. Your details are saved.');
            redirect(basename($_SERVER['PHP_SELF']));
        }
    }
    $title = 'One more step';
    $poll = '';
    include 'includes/header.php';
    ?>
    <div class="row justify-content-center pt-lg-3"><div class="col-md-8 col-lg-5">
      <h1 class="h3 mb-1">One more step</h1>
      <p class="text-muted mb-3">Please add the details below to continue. Your email is used to send you receipts.</p>
      <?php foreach ($__err as $m): ?><div class="alert alert-danger py-2" role="alert"><?= e($m) ?></div><?php endforeach; ?>
      <form method="post" class="panel">
        <?= csrf_field() ?><input type="hidden" name="action" value="profile">
        <?php if ($__needEmail): ?>
          <label class="form-label" for="email">Email address</label>
          <input class="form-control mb-3" id="email" name="email" type="email" autocomplete="email" maxlength="190" value="<?= e($__em) ?>" required>
        <?php endif; ?>
        <?php if ($__needId): ?>
          <label class="form-label" for="national_id">National ID number</label>
          <input class="form-control mb-3" id="national_id" name="national_id" inputmode="numeric" pattern="[0-9]{7,8}" maxlength="8" value="<?= e($__nid) ?>" required>
        <?php endif; ?>
        <button class="btn btn-crate w-100">Save and continue</button>
      </form>
    </div></div>
    <?php
    include 'includes/footer.php';
    exit;
}
unset($__row, $__needId, $__needEmail);