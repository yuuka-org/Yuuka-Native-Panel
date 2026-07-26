<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('cron.view');

$user = Auth::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            Rbac::require('cron.manage');
            $ownerType = (string) $_POST['owner_type'];
            CronService::createJob(
                trim((string) $_POST['name']),
                $ownerType,
                $ownerType === 'php' ? (int) $_POST['website_id'] : null,
                $ownerType === 'nodejs' ? (int) $_POST['nodejs_app_id'] : null,
                trim((string) $_POST['schedule']),
                (string) $_POST['command_type'],
                trim((string) ($_POST['command_arg'] ?? '')),
                $user['id']
            );
            flash('success', 'Cron job dibuat.');
        } elseif ($action === 'toggle') {
            Rbac::require('cron.manage');
            CronService::toggleJob((int) $_POST['id'], $_POST['enable'] === '1', $user['id']);
            flash('success', 'Status cron job diperbarui.');
        } elseif ($action === 'delete') {
            Rbac::require('cron.manage');
            CronService::deleteJob((int) $_POST['id'], $user['id']);
            flash('success', 'Cron job dihapus.');
        }
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/cron');
}

$jobs = CronService::listJobs();
$websites = NginxService::listWebsites();
$nodeApps = NodeService::listRegisteredApps();

$pageTitle = 'Cron Jobs';
include __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h4 class="fw-bold mb-0">Cron Job Manager</h4>
    <p class="text-muted mb-0">Terjadwal per website atau aplikasi Node.js</p>
  </div>
  <?php if (Rbac::can($user['role'], 'cron.manage')): ?>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCronModal"><i class="bi bi-plus-lg me-1"></i>Tambah Cron Job</button>
  <?php endif; ?>
</div>

<div class="card stat-card">
  <div class="card-body p-0">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light"><tr><th>Nama</th><th>Target</th><th>Jadwal</th><th>Command</th><th>Terakhir Jalan</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
      <?php if (empty($jobs)): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">Belum ada cron job</td></tr>
      <?php endif; ?>
      <?php foreach ($jobs as $job): ?>
        <tr>
          <td><?= e($job['name']) ?></td>
          <td><?= e($job['owner_type'] === 'php' ? ($job['website_domain'] ?? '-') : ($job['node_app_name'] ?? '-')) ?></td>
          <td><code><?= e($job['schedule']) ?></code></td>
          <td class="small text-muted"><?= e($job['command_type']) ?><?= $job['command_type'] !== 'php_artisan' ? ': ' . e($job['command_arg']) : '' ?></td>
          <td class="small"><?= e($job['last_run_at'] ?? 'belum pernah') ?></td>
          <td><?= $job['is_enabled'] ? '<span class="badge text-bg-success">Enabled</span>' : '<span class="badge text-bg-secondary">Disabled</span>' ?></td>
          <td class="text-end">
            <?php if (Rbac::can($user['role'], 'cron.manage')): ?>
            <form method="post" class="d-inline">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int) $job['id'] ?>">
              <input type="hidden" name="enable" value="<?= $job['is_enabled'] ? '0' : '1' ?>">
              <button class="btn btn-sm btn-outline-secondary"><i class="bi <?= $job['is_enabled'] ? 'bi-pause-fill' : 'bi-play-fill' ?>"></i></button>
            </form>
            <form method="post" class="d-inline" data-confirm="Hapus cron job <?= e($job['name']) ?>?">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $job['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="createCronModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Tambah Cron Job</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="create">
          <div class="mb-3">
            <label class="form-label">Nama</label>
            <input type="text" name="name" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Tipe Pemilik</label>
            <select name="owner_type" class="form-select" id="ownerType" onchange="toggleOwnerFields()" required>
              <option value="php">Website PHP</option>
              <option value="nodejs">Aplikasi Node.js</option>
            </select>
          </div>
          <div class="mb-3" id="phpOwnerField">
            <label class="form-label">Website</label>
            <select name="website_id" class="form-select">
              <?php foreach ($websites as $w): ?><option value="<?= (int) $w['id'] ?>"><?= e($w['domain']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3 d-none" id="nodeOwnerField">
            <label class="form-label">Aplikasi Node.js</label>
            <select name="nodejs_app_id" class="form-select">
              <?php foreach ($nodeApps as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e($a['app_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Jadwal (cron expression)</label>
            <input type="text" name="schedule" class="form-control" placeholder="*/5 * * * *" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Tipe Command</label>
            <select name="command_type" class="form-select">
              <option value="php_artisan">PHP artisan schedule:run</option>
              <option value="php_script">PHP script</option>
              <option value="node_script">Node.js script</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Path Script (relatif, kosongkan untuk artisan)</label>
            <input type="text" name="command_arg" class="form-control" placeholder="scripts/cron.js">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary">Simpan</button>
        </div>
      </div>
    </form>
  </div>
</div>
<script>
function toggleOwnerFields() {
  var v = document.getElementById('ownerType').value;
  document.getElementById('phpOwnerField').classList.toggle('d-none', v !== 'php');
  document.getElementById('nodeOwnerField').classList.toggle('d-none', v !== 'nodejs');
}
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
