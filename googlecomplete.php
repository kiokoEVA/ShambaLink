<?php
// Step 3: finish the account. Google gives a name and email; matching and SMS need a phone number.
require_once 'auth.php';
if ($cu = current_user()) redirect(home_for($cu['role']));
$g = $_SESSION['google'] ?? null;
if (!$g) redirect('register.php');

$err = [];
$v = ['role' => $g['role'], 'name' => $g['name'], 'phone' => '', 'location_id' => 0];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $v = ['role' => $_POST['role'] ?? '', 'name' => trim($_POST['name'] ?? ''), 'phone' => trim($_POST['phone'] ?? ''), 'location_id' => (int)($_POST['location_id'] ?? 0)];
    $phone = normalize_phone($v['phone']);
    if (!in_array($v['role'], ['farmer', 'transporter'], true)) $err[] = 'Choose whether you are a farmer or a transporter.';
    if (mb_strlen($v['name']) < 2 || mb_strlen($v['name']) > 100) $err[] = 'Enter your full name.';
    if (!$phone) $err[] = 'Enter a valid Kenyan mobile number, for example 0712 345 678.';
    if (!fetch_one('SELECT id FROM locations WHERE id = ?', [$v['location_id']])) $err[] = 'Choose your location.';
    if ($phone && fetch_one('SELECT id FROM users WHERE phone = ?', [$phone])) $err[] = 'That phone number is already registered. Log in with your phone and password instead.';
    if (!$err) {
        try {
            // Google users never type a password, so store a random one nobody knows.
            $unusable = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            db()->prepare('INSERT INTO users (name, phone, role, location_id, password_hash, google_id, email) VALUES (?,?,?,?,?,?,?)')
                ->execute([$v['name'], $phone, $v['role'], $v['location_id'], $unusable, $g['sub'], $g['email']]);
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)db()->lastInsertId();
            unset($_SESSION['google']);
            flash_set('Account created. Welcome to ' . APP_NAME . '.');
            redirect(home_for($v['role']));
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') $err[] = 'That account or phone number is already registered.';
            else throw $ex;
        }
    }
}
$title = 'Finish your account';
$poll = '';
include 'includes/header.php';
?>
<div class="row justify-content-center pt-lg-4"><div class="col-md-8 col-lg-6 col-xl-5">
  <h1 class="h3 mb-1">One last step</h1>
  <p class="text-muted mb-4">Signed in with Google as <?= e($g['email']) ?>. We need your phone number so matches can reach you by SMS.</p>
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
    <input class="form-control mb-3" id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="0712 345 678" value="<?= e($v['phone']) ?>" required autofocus>
    <label class="form-label" for="location_id">Where are you based?</label>
    <select class="form-select mb-3" id="location_id" name="location_id" required><?= location_options((int)$v['location_id']) ?></select>
    <button class="btn btn-crate w-100">Create account</button>
  </form>
</div></div>
<?php include 'includes/footer.php'; ?>