<?php
require_once 'auth.php';
if ($cu = current_user()) redirect(home_for($cu['role']));
$error = '';
$login = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $login = trim($_POST['login'] ?? '');
    $row = null;
    if (preg_match('/^\d{7,8}$/', $login)) {
        // 7 or 8 digits: a national ID number
        $row = fetch_one('SELECT id, role, password_hash FROM users WHERE national_id = ?', [$login]);
    } else {
        // anything else: a phone number
        $norm = normalize_phone($login);
        $row = $norm ? fetch_one('SELECT id, role, password_hash FROM users WHERE phone = ?', [$norm]) : null;
    }
    if ($row && password_verify($_POST['password'] ?? '', $row['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$row['id'];
        redirect(home_for($row['role']));
    }
    usleep(400000);
    $error = 'ID number, phone number or password is incorrect.';
}
$title = 'Log in';
$poll = '';
include 'includes/header.php';
?>
<div class="row justify-content-center pt-lg-4"><div class="col-md-7 col-lg-5 col-xl-4">
  <h1 class="h3 mb-1">Welcome back</h1>
  <p class="text-muted mb-4">Log in with your ID number or phone number to post a load or a vehicle and see your matches.</p>
  <?= flash_get() ?>
  <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="panel">
    <?= csrf_field() ?>
    <label class="form-label" for="login">ID or phone number</label>
    <input class="form-control mb-3" id="login" name="login" type="text" inputmode="tel" autocomplete="username" placeholder="ID number or 0712 345 678" value="<?= e($login) ?>" required autofocus>
    <label class="form-label" for="password">Password</label>
    <input class="form-control mb-3" id="password" name="password" type="password" autocomplete="current-password" required>
    <button class="btn btn-crate w-100">Log in</button>
  </form>
  <p class="mt-3 small text-muted">New here? <a href="register.php">Create an account</a></p>
</div></div>
<?php include 'includes/footer.php'; ?>