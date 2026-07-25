<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('nodejs.view');

$user = Auth::user();
$id = (int) ($_GET['id'] ?? 0);
$app = NodeService::find($id);
if ($app === null) {
    flash('error', 'Aplikasi tidak ditemukan');
    redirect('/nodejs.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();
    Rbac::require('nodejs.control');

    try {
        NodeService::updateApp(
            $id,
            (string) ($_POST['node_version'] ?? ''),
            trim((string) ($_POST['start_command'] ?? '')),
            trim((string) ($_POST['build_command'] ?? '')) ?: null,
            max(1, (int) ($_POST['instances'] ?? 1)),
            (string) ($_POST['exec_mode'] ?? 'fork'),
            isset($_POST['autorestart']),
            isset($_POST['watch']),
            trim((string) ($_POST['max_memory_restart'] ?? '512M')),
            (string) ($_POST['node_env'] ?? 'production'),
            $user['id']
        );
        flash('success', 'Konfigurasi disimpan - aplikasi di-redeploy via PM2.');
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/nodejs_settings.php?id=' . $id);
}

$nodeVersions = NodeService::allowedNodeVersions();
$activeNodejsTab = 'general';

$pageTitle = 'Pengaturan - ' . $app['app_name'];
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/nodejs_settings_nav.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="fw-bold mb-0">Pengaturan: <?= e($app['app_name']) ?></h4>
    <p class="text-muted mb-0">Port internal: <code><?= e((string) $app['port']) ?></code> - menyimpan perubahan di bawah langsung me-redeploy aplikasi via PM2.</p>
  </div>
  <a href="/nodejs.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
</div>

<div class="card stat-card">
  <div class="card-body">
    <form method="post">
      <?= Csrf::field() ?>
      <fieldset <?= Rbac::can($user['role'], 'nodejs.control') ? '' : 'disabled' ?>>
      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label">Versi Node.js</label>
          <select name="node_version" class="form-select">
            <?php foreach ($nodeVersions as $v): ?>
              <option value="<?= e($v) ?>" <?= $app['node_version'] === $v ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">NODE_ENV</label>
          <select name="node_env" class="form-select">
            <?php foreach (['production', 'development', 'staging'] as $ne): ?>
              <option value="<?= $ne ?>" <?= $app['node_env'] === $ne ? 'selected' : '' ?>><?= $ne ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Exec Mode</label>
          <select name="exec_mode" class="form-select">
            <option value="fork" <?= $app['exec_mode'] === 'fork' ? 'selected' : '' ?>>fork</option>
            <option value="cluster" <?= $app['exec_mode'] === 'cluster' ? 'selected' : '' ?>>cluster</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Instances</label>
          <input type="number" name="instances" class="form-control" value="<?= (int) $app['instances'] ?>" min="1" max="32">
        </div>
        <div class="col-md-6">
          <label class="form-label">Start Command (relatif terhadap folder app)</label>
          <input type="text" name="start_command" class="form-control" value="<?= e($app['start_command']) ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Build Command (opsional, dijalankan sebelum redeploy)</label>
          <input type="text" name="build_command" class="form-control" value="<?= e($app['build_command'] ?? '') ?>" placeholder="npm run build">
        </div>
        <div class="col-md-3">
          <label class="form-label">Max Memory Restart</label>
          <input type="text" name="max_memory_restart" class="form-control" value="<?= e($app['max_memory_restart']) ?>" placeholder="512M">
        </div>
        <div class="col-md-3 d-flex flex-column justify-content-end">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="autorestart" id="autorestart" <?= $app['autorestart'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="autorestart">Autorestart</label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="watch" id="watch" <?= $app['watch'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="watch">Watch files</label>
          </div>
        </div>
      </div>
      <?php if (Rbac::can($user['role'], 'nodejs.control')): ?>
      <div class="alert alert-info mt-3 mb-3 py-2 small mb-0"><i class="bi bi-info-circle me-1"></i>Build Command dijalankan (sebagai user <code>nodeapps</code>, di folder aplikasi) sebelum PM2 di-restart dengan konfigurasi baru.</div>
      <button type="submit" class="btn btn-primary mt-3">Simpan &amp; Redeploy</button>
      <?php endif; ?>
      </fieldset>
    </form>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
