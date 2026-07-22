<?php
/** @var string $pageTitle */
$pageTitle = $pageTitle ?? 'Dashboard';
$currentUser = Auth::user();
?>
<!DOCTYPE html>
<html lang="id" data-bs-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="referrer" content="same-origin">
<title><?= e($pageTitle) ?> - Yuuka Server Panel</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="/assets/css/app.css" rel="stylesheet">
<?php if (!empty($extraHeadHtml)): ?>
<?= $extraHeadHtml ?>
<?php endif; ?>
</head>
<body>
<div class="app-shell">
  <nav class="app-sidebar" id="appSidebar">
    <div class="sidebar-brand">
      <i class="bi bi-hdd-network-fill"></i>
      <span>Yuuka Panel</span>
    </div>
    <?php include __DIR__ . '/sidebar.php'; ?>
  </nav>

  <div class="app-main">
    <header class="app-topbar">
      <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggle" type="button">
        <i class="bi bi-list"></i>
      </button>
      <div class="ms-auto d-flex align-items-center gap-3">
        <span class="badge text-bg-light border"><i class="bi bi-person-badge me-1"></i><?= e($currentUser['role'] ?? '') ?></span>
        <div class="dropdown">
          <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
            <i class="bi bi-person-circle me-1"></i><?= e($currentUser['username'] ?? 'guest') ?>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="/settings.php"><i class="bi bi-gear me-2"></i>Pengaturan</a></li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <form method="post" action="/logout.php">
                <?= Csrf::field() ?>
                <button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i>Logout</button>
              </form>
            </li>
          </ul>
        </div>
      </div>
    </header>

    <main class="app-content">
      <?php include __DIR__ . '/flash.php'; ?>
