<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('logs.view');

$user = Auth::user();
$websites = NginxService::listWebsites();
$phpVersions = PhpService::installedVersions();

$source = (string) ($_GET['source'] ?? 'deployment');
$target = (string) ($_GET['target'] ?? '');
$lines = min(2000, max(20, (int) ($_GET['lines'] ?? 200)));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();
    try {
        if (($_POST['action'] ?? '') === 'clear') {
            if ($_POST['source'] === 'nginx_access') {
                LogService::clearNginxAccess((string) $_POST['target']);
            } elseif ($_POST['source'] === 'nginx_error') {
                LogService::clearNginxError((string) $_POST['target']);
            }
            flash('success', 'Log dibersihkan.');
        }
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/logs?source=' . urlencode((string) $_POST['source']) . '&target=' . urlencode((string) $_POST['target']));
}

$output = '';
try {
    $output = match ($source) {
        'nginx_access' => $target !== '' ? LogService::nginxAccess($target, $lines) : '',
        'nginx_error' => $target !== '' ? LogService::nginxError($target, $lines) : '',
        'phpfpm_error' => $target !== '' ? LogService::phpFpmError($target, $lines) : '',
        'panel' => LogService::panelAppLog($lines),
        default => LogService::deploymentLog($lines),
    };
} catch (InvalidArgumentException $e) {
    flash('error', $e->getMessage());
}

$pageTitle = 'Log Viewer';
include __DIR__ . '/partials/header.php';
?>

<div class="mb-4">
  <h4 class="fw-bold mb-0">Log Viewer</h4>
  <p class="text-muted mb-0">Nginx, PHP-FPM, panel &amp; deployment log. Log aplikasi Node.js ada di halaman masing-masing aplikasi.</p>
</div>

<form method="get" class="row g-2 mb-3 align-items-end">
  <div class="col-md-3">
    <label class="form-label small">Sumber Log</label>
    <select name="source" id="sourceSelect" class="form-select" onchange="this.form.submit()">
      <option value="deployment" <?= $source === 'deployment' ? 'selected' : '' ?>>Deployment (installer)</option>
      <option value="panel" <?= $source === 'panel' ? 'selected' : '' ?>>Panel application error</option>
      <option value="nginx_access" <?= $source === 'nginx_access' ? 'selected' : '' ?>>Nginx Access Log</option>
      <option value="nginx_error" <?= $source === 'nginx_error' ? 'selected' : '' ?>>Nginx Error Log</option>
      <option value="phpfpm_error" <?= $source === 'phpfpm_error' ? 'selected' : '' ?>>PHP-FPM Error Log</option>
    </select>
  </div>
  <?php if (in_array($source, ['nginx_access', 'nginx_error'], true)): ?>
  <div class="col-md-3">
    <label class="form-label small">Domain</label>
    <select name="target" class="form-select">
      <?php foreach ($websites as $w): ?><option value="<?= e($w['domain']) ?>" <?= $target === $w['domain'] ? 'selected' : '' ?>><?= e($w['domain']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <?php elseif ($source === 'phpfpm_error'): ?>
  <div class="col-md-3">
    <label class="form-label small">Versi PHP</label>
    <select name="target" class="form-select">
      <?php foreach ($phpVersions as $v): ?><option value="<?= e($v) ?>" <?= $target === $v ? 'selected' : '' ?>>PHP <?= e($v) ?></option><?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
  <div class="col-md-2">
    <label class="form-label small">Jumlah baris</label>
    <select name="lines" class="form-select">
      <?php foreach ([100, 200, 500, 1000, 2000] as $opt): ?><option value="<?= $opt ?>" <?= $opt === $lines ? 'selected' : '' ?>><?= $opt ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <button class="btn btn-primary w-100">Tampilkan</button>
  </div>
  <?php if (in_array($source, ['nginx_access', 'nginx_error'], true) && $target !== '' && Rbac::can($user['role'], 'backup.manage')): ?>
  <div class="col-md-2">
    <form method="post" data-confirm="Kosongkan log ini?">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="clear">
      <input type="hidden" name="source" value="<?= e($source) ?>">
      <input type="hidden" name="target" value="<?= e($target) ?>">
      <button class="btn btn-outline-danger w-100">Clear Log</button>
    </form>
  </div>
  <?php endif; ?>
</form>

<div class="log-viewer"><?= e($output ?: 'Tidak ada data log untuk ditampilkan.') ?></div>

<?php include __DIR__ . '/partials/footer.php'; ?>
