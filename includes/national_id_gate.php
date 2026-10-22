<?php
/*
 * includes/national_id_gate.php
 * Included at the top of the farmer and transporter dashboards, right after require_login().
 * If the logged-in user has no national ID number saved (accounts made before this field existed,
 * or sign-ups that skipped it), they see a short form and nothing else until they enter it.
 */
$__row = fetch_one('SELECT national_id FROM users WHERE id = ?', [$u['id']]);
if (empty($__row['national_id'])) {
    $__err = [];
    $__nid = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'national_id') {
        csrf_check();
        $__nid = trim($_POST['national_id'] ?? '');
        if (!preg_match('/^\d{7,8}$/', $__nid)) {
            $__err[] = 'Enter a valid national ID number (7 or 8 digits).';
        } elseif (fetch_one('SELECT id FROM users WHERE national_id = ? AND id <> ?', [$__nid, $u['id']])) {
            $__err[] = 'That ID number is already used by another account.';
        } else {
            db()->prepare('UPDATE users SET national_id = ? WHERE id = ?')->execute([$__nid, $u['id']]);
            flash_set('Thank you. Your ID number is saved.');
            redirect(basename($_SERVER['PHP_SELF']));
        }
    }
    $title = 'National ID number';
    $poll = '';
    include 'includes/header.php';
    ?>
    <div class="row justify-content-center pt-lg-3"><div class="col-md-8 col-lg-5">
      <h1 class="h3 mb-1">One more step</h1>
      <p class="text-muted mb-3">Please add your national ID number to continue.</p>
      <?php foreach ($__err as $m): ?><div class="alert alert-danger py-2" role="alert"><?= e($m) ?></div><?php endforeach; ?>
      <form method="post" class="panel">
        <?= csrf_field() ?><input type="hidden" name="action" value="national_id">
        <label class="form-label" for="national_id">National ID number</label>
        <input class="form-control mb-3" id="national_id" name="national_id" inputmode="numeric" pattern="[0-9]{7,8}" maxlength="8" value="<?= e($__nid) ?>" required>
        <button class="btn btn-crate w-100">Save and continue</button>
      </form>
    </div></div>
    <?php
    include 'includes/footer.php';
    exit;
}
unset($__row);