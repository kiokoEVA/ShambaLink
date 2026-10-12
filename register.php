<?php
require_once 'auth.php';
if ($cu = current_user()) redirect(home_for($cu['role']));
$err = [];
$v = ['role' => in_array($_GET['role'] ?? '', ['farmer', 'transporter'], true) ? $_GET['role'] : 'farmer', 'name' => '', 'phone' => '', 'location_id' => 0];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $v = ['role' => $_POST['role'] ?? '', 'name' => trim($_POST['name'] ?? ''), 'phone' => trim($_POST['phone'] ?? ''), 'location_id' => (int)($_POST['location_id'] ?? 0)];
    $pw = $_POST['password'] ?? '';
    $phone = normalize_phone($v['phone']);
    if (!in_array($v['role'], ['farmer', 'transporter'], true)) $err[] = 'Choose whether you are a farmer or a transporter.';
    if (mb_strlen($v['name']) < 2 || mb_strlen($v['name']) > 100) $err[] = 'Enter your full name.';
    if (!$phone) $err[] = 'Enter a valid Kenyan mobile number, for example 0712 345 678.';
    if (!fetch_one('SELECT id FROM locations WHERE id = ?', [$v['location_id']])) $err[] = 'Choose your location.';
    if (strlen($pw) < 8) $err[] = 'Use a password with at least 8 characters.';
    if ($pw !== ($_POST['confirm'] ?? '')) $err[] = 'The two passwords do not match.';
    if (!$err) {
        try {
            db()->prepare('INSERT INTO users (name, phone, role, location_id, password_hash) VALUES (?,?,?,?,?)')
                ->execute([$v['name'], $phone, $v['role'], $v['location_id'], password_hash($pw, PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)db()->lastInsertId();
            flash_set('Account created. Welcome to ' . APP_NAME . '.');
            redirect(home_for($v['role']));
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') $err[] = 'That phone number is already registered. Log in instead.';
            else throw $ex;
        }
    }
}
$title = 'Create account';
$poll = '';
include 'includes/header.php';
?>
<div class="row justify-content-center pt-lg-4"><div class="col-md-8 col-lg-6 col-xl-5">
  <h1 class="h3 mb-1">Create your account</h1>
  <p class="text-muted mb-4">It takes a minute. Choose your role and we will show you what to do next.</p>
  <?php foreach ($err as $m): ?><div class="alert alert-danger py-2" role="alert"><?= e($m) ?></div><?php endforeach; ?>
  <form method="post" class="panel">
    <?= csrf_field() ?>
    <div class="role-pick mb-3">
      <input type="radio" class="btn-check" name="role" id="r1" value="farmer" <?= $v['role'] === 'farmer' ? 'checked' : '' ?>>
      <label for="r1"><b>I am a farmer</b><small>I need produce moved</small></label>
      <input type="radio" class="btn-check" name="role" id="r2" value="transporter" <?= $v['role'] === 'transporter' ? 'checked' : '' ?>>
      <label for="r2"><b>I am a transporter</b><small>I have a vehicle</small></label>
    </div>
    <label class="form-label" for="name">Full name</label>
    <input class="form-control mb-3" id="name" name="name" autocomplete="name" value="<?= e($v['name']) ?>" required>
    <label class="form-label" for="phone">Phone number</label>
    <input class="form-control mb-3" id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="0712 345 678" value="<?= e($v['phone']) ?>" required>
    <label class="form-label" for="location_id">Where are you based?</label>
    <select class="form-select mb-3" id="location_id" name="location_id" required><?= location_options((int)$v['location_id']) ?></select>
    <label class="form-label" for="password">Password</label>
    <input class="form-control" id="password" name="password" type="password" minlength="8" autocomplete="new-password" aria-describedby="pwhint" required>
    <div class="form-text text-muted mb-3" id="pwhint">At least 8 characters.</div>
    <label class="form-label" for="confirm">Confirm password</label>
    <input class="form-control mb-3" id="confirm" name="confirm" type="password" minlength="8" autocomplete="new-password" required>
    <button class="btn btn-crate w-100">Create account</button>
  </form>
  <div class="or-rule"><span>or</span></div>
  <a class="btn btn-outline-ink w-100 btn-google" id="googleBtn" href="google_start.php?role=farmer"><svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg> Continue with Google</a>
  <p class="mt-3 small text-muted">Already registered? <a href="login.php">Log in</a></p>
</div></div>
<script>
document.querySelectorAll('input[name=role]').forEach(function (i) {
  i.addEventListener('change', function () {
    document.getElementById('googleBtn').href = 'google_start.php?role=' + i.value;
  });
});
(function () { var c = document.querySelector('input[name=role]:checked'); if (c) document.getElementById('googleBtn').href = 'google_start.php?role=' + c.value; })();
</script>
<?php include 'includes/footer.php'; ?>