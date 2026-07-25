<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('nodejs.env.manage');

$user = Auth::user();
$id = (int) ($_GET['id'] ?? 0);
$app = NodeService::find($id);
if ($app === null) {
    flash('error', 'Aplikasi tidak ditemukan');
    redirect('/nodejs.php');
}

// Handle .env export as a file download before any HTML is emitted.
if (($_GET['export'] ?? '') === '1') {
    $content = EnvService::toDotEnvExport($id);
    ActivityLog::record($user['id'], 'nodejs.env.export', "Export .env untuk {$app['app_name']}");
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $app['app_name'] . '.env"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'set') {
            EnvService::setVariable($id, trim((string) $_POST['key']), (string) $_POST['value'], isset($_POST['is_secret']));
            flash('success', 'Environment variable disimpan. Restart aplikasi agar perubahan diterapkan.');
        } elseif ($action === 'delete') {
            EnvService::deleteVariable($id, (string) $_POST['key']);
            flash('success', 'Environment variable dihapus.');
        } elseif ($action === 'import') {
            $parsed = EnvService::parseDotEnv((string) ($_POST['dotenv'] ?? ''));
            foreach ($parsed as $k => $v) {
                EnvService::setVariable($id, $k, $v, str_contains(strtolower($k), 'secret') || str_contains(strtolower($k), 'password') || str_contains(strtolower($k), 'key'));
            }
            flash('success', count($parsed) . ' variabel diimpor.');
        } elseif ($action === 'apply') {
            Rbac::require('nodejs.control');
            $env = EnvService::plainMapForApp($id);
            $env['PORT'] = (string) $app['port'];
            $ecosystem = nodejs_build_ecosystem_config(
                $app['pm2_name'], $app['project_path'], $app['start_command'],
                (int) $app['instances'], $app['exec_mode'], (bool) $app['autorestart'],
                (bool) $app['watch'], $app['max_memory_restart'], $app['node_env'], $env
            );
            $result = nodejs_pm2_deploy($app['pm2_name'], $ecosystem);
            if (!$result['ok']) {
                throw new RuntimeException('Gagal menerapkan environment variable: ' . $result['output']);
            }
            flash('success', 'Environment variable diterapkan dan aplikasi di-restart via PM2.');
        }
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/nodejs_env.php?id=' . $id);
}

$variables = EnvService::listForApp($id);
$activeNodejsTab = 'env';

$pageTitle = 'Environment - ' . $app['app_name'];
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/nodejs_settings_nav.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="fw-bold mb-0">Environment Variables: <?= e($app['app_name']) ?></h4>
    <p class="text-muted mb-0">Nilai secret disamarkan secara default. Tidak pernah dicatat ke log.</p>
  </div>
  <div class="d-flex gap-2">
    <a href="/nodejs.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
    <a href="/nodejs_env.php?id=<?= (int) $id ?>&export=1" class="btn btn-outline-primary"><i class="bi bi-download me-1"></i>Export .env</a>
    <?php if (Rbac::can($user['role'], 'nodejs.control')): ?>
    <form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="apply">
      <button class="btn btn-primary"><i class="bi bi-arrow-repeat me-1"></i>Terapkan &amp; Restart</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<div class="card stat-card mb-4">
  <div class="card-body p-0">
    <table class="table mb-0 align-middle">
      <thead class="table-light"><tr><th>Key</th><th>Value</th><th style="width:90px">Secret</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
        <?php if (empty($variables)): ?>
          <tr><td colspan="4" class="text-center text-muted py-4">Belum ada environment variable</td></tr>
        <?php endif; ?>
        <?php foreach ($variables as $key => $data): $elId = 'val_' . md5($key); ?>
        <tr>
          <td><code><?= e($key) ?></code></td>
          <td>
            <span class="secret-value" id="<?= $elId ?>" data-hidden="1" data-value="<?= e($data['value']) ?>">••••••••</span>
            <button type="button" class="btn btn-sm btn-link" data-toggle-secret="<?= $elId ?>"><i class="bi bi-eye"></i></button>
          </td>
          <td><?= $data['is_secret'] ? '<span class="badge text-bg-warning">Secret</span>' : '<span class="badge text-bg-light border">Plain</span>' ?></td>
          <td class="text-end">
            <form method="post" class="d-inline" data-confirm="Hapus variabel <?= e($key) ?>?">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="key" value="<?= e($key) ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="row g-3">
  <div class="col-md-6">
    <div class="card stat-card h-100">
      <div class="card-header bg-white fw-semibold">Tambah / Ubah Variable</div>
      <div class="card-body">
        <form method="post">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="set">
          <div class="mb-2">
            <label class="form-label">Key</label>
            <input type="text" name="key" class="form-control" pattern="^[A-Z_][A-Z0-9_]{0,127}$" placeholder="DATABASE_URL" required>
          </div>
          <div class="mb-2">
            <label class="form-label">Value</label>
            <input type="text" name="value" class="form-control">
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="is_secret" id="isSecret">
            <label class="form-check-label" for="isSecret">Tandai sebagai secret</label>
          </div>
          <button class="btn btn-primary w-100">Simpan</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card stat-card h-100">
      <div class="card-header bg-white fw-semibold">Import dari .env</div>
      <div class="card-body">
        <form method="post">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="import">
          <textarea name="dotenv" class="form-control mb-3" rows="6" placeholder="KEY=value&#10;ANOTHER_KEY=value"></textarea>
          <button class="btn btn-outline-primary w-100">Import</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
