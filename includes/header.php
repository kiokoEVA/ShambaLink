<?php
$u = $u ?? current_user();
$title = $title ?? APP_NAME;
$poll = $poll ?? ($u ? 'api/poll.php' : '');
$unread = 0;
if ($u) { $r = fetch_one('SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND is_read = 0', [$u['id']]); $unread = (int)$r['n']; }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> | <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700&family=Public+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="css/Style.css" rel="stylesheet">
<link rel="stylesheet" href="css/theme.css">
</head>
<body data-poll="<?= e($poll) ?>" data-interval="<?= (int)POLL_MS ?>">
<header class="topbar">
  <div class="container d-flex align-items-center gap-3 py-2">
    <a class="brand" href="index.php"><i class="bi bi-truck-front-fill"></i> <?= APP_NAME ?></a>
    <nav class="ms-auto d-flex align-items-center gap-3">
    <?php if ($u): ?>
      <a href="<?= home_for($u['role']) ?>">Dashboard</a>
      <a href="notification.php" class="bell" aria-label="Notifications"><i class="bi bi-bell"></i><span id="bell-count" class="count" <?= $unread ? '' : 'hidden' ?>><?= $unread ?></span></a>
      <span class="who d-none d-md-inline"><?= e($u['name']) ?></span>
      <a href="logout.php">Log out</a>
    <?php else: ?>
      <a href="login.php">Log in</a>
      <a href="register.php" class="btn btn-crate btn-sm">Create account</a>
    <?php endif; ?>
    </nav>
  </div>
</header>
<main class="container py-4">
