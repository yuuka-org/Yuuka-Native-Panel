<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

if (Auth::check()) {
    redirect('/dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        flash('error', 'Username dan password wajib diisi.');
    } elseif (Auth::attempt($username, $password)) {
        redirect('/dashboard.php');
    }
    redirect('/login.php');
}

$loginTitle = SettingsService::get('panel_login_title', 'Yuuka Server Panel');
$loginLogo = SettingsService::get('panel_login_logo');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login - <?= e($loginTitle) ?></title>
<script>
(function () {
  var theme = localStorage.getItem('yuuka-theme') || 'light';
  document.documentElement.setAttribute('data-bs-theme', theme);
})();
</script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="/assets/css/app.css" rel="stylesheet">
<style>
  body { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg,#1e1b4b,#4f46e5); }
  .login-card { width: 100%; max-width: 400px; border: none; border-radius: 1rem; }
</style>
</head>
<body>
<div class="card login-card shadow-lg">
  <div class="card-body p-4 p-md-5">
    <div class="text-center mb-4">
      <?php if ($loginLogo !== ''): ?>
        <img src="/<?= e($loginLogo) ?>" alt="Logo" style="max-height:56px;max-width:160px;object-fit:contain;">
      <?php else: ?>
        <i class="bi bi-hdd-network-fill fs-1 text-primary"></i>
      <?php endif; ?>
      <h4 class="mt-2 mb-0 fw-bold"><?= e($loginTitle) ?></h4>
      <p class="text-muted small">Masuk untuk mengelola server Anda</p>
    </div>
    <?php include __DIR__ . '/partials/flash.php'; ?>
    <form method="post" action="/login.php">
      <?= Csrf::field() ?>
      <div class="mb-3">
        <label class="form-label">Username</label>
        <input type="text" name="username" class="form-control" required autofocus autocomplete="username">
      </div>
      <div class="mb-3">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" required autocomplete="current-password">
      </div>
      <button type="submit" class="btn btn-primary w-100">Login</button>
    </form>
  </div>
</div>
</body>
</html>
