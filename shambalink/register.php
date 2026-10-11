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
<div class="row justify-content-center"><div class="col-md-8 col-lg-5">
  <h1 class="h3 mb-3">Create your account</h1>
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
    <input class="form-control mb-3" id="name" name="name" value="<?= e($v['name']) ?>" required>
    <label class="form-label" for="phone">Phone number</label>
    <input class="form-control mb-3" id="phone" name="phone" inputmode="tel" placeholder="0712 345 678" value="<?= e($v['phone']) ?>" required>
    <label class="form-label" for="location_id">Where are you based?</label>
    <select class="form-select mb-3" id="location_id" name="location_id" required><?= location_options((int)$v['location_id']) ?></select>
    <label class="form-label" for="password">Password</label>
    <input class="form-control mb-3" id="password" name="password" type="password" minlength="8" required>
    <label class="form-label" for="confirm">Confirm password</label>
    <input class="form-control mb-3" id="confirm" name="confirm" type="password" minlength="8" required>
    <button class="btn btn-crate w-100">Create account</button>
  </form>
  <p class="mt-3 small">Already registered? <a href="login.php">Log in</a></p>
</div></div>
<?php include 'includes/footer.php'; ?>
