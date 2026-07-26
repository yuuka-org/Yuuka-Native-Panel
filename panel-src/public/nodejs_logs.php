<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('nodejs.logs.view');

$user = Auth::user();
$id = (int) ($_GET['id'] ?? 0);
$app = NodeService::find($id);
if ($app === null) {
    flash('error', 'Aplikasi tidak ditemukan');
    redirect('/nodejs');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();
    if (($_POST['action'] ?? '') === 'clear') {
        Rbac::require('nodejs.control');
        NodeService::clearLogs($id);
        flash('success', 'Log dibersihkan.');
    }
    redirect('/nodejs_logs?id=' . $id);
}

$lines = min(1000, max(10, (int) ($_GET['lines'] ?? 150)));
$logOutput = NodeService::getLogs($id, $lines);
$activeNodejsTab = 'logs';

$pageTitle = 'Logs - ' . $app['app_name'];
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/nodejs_settings_nav.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="fw-bold mb-0">Logs: <?= e($app['app_name']) ?></h4>
    <p class="text-muted mb-0">Output &amp; error log dari PM2 (<code>pm2 logs</code>)</p>
  </div>
  <div class="d-flex gap-2">
    <a href="/nodejs" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
    <?php if (Rbac::can($user['role'], 'nodejs.control')): ?>
    <form method="post" data-confirm="Kosongkan seluruh log aplikasi ini?">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="clear">
      <button class="btn btn-outline-danger"><i class="bi bi-eraser me-1"></i>Clear Logs</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<form method="get" class="d-flex gap-2 mb-3">
  <input type="hidden" name="id" value="<?= (int) $id ?>">
  <select name="lines" class="form-select" style="max-width:160px" onchange="this.form.submit()">
    <?php foreach ([50, 100, 150, 300, 500, 1000] as $opt): ?>
      <option value="<?= $opt ?>" <?= $opt === $lines ? 'selected' : '' ?>><?= $opt ?> baris</option>
    <?php endforeach; ?>
  </select>
</form>

<div class="log-viewer"><?= e($logOutput ?: 'Belum ada output log.') ?></div>

<?php include __DIR__ . '/partials/footer.php'; ?>
