<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('backup.view');

$user = Auth::user();

if (($_GET['download'] ?? '') !== '') {
    $path = BackupService::downloadPath((int) $_GET['download']);
    if ($path === null) {
        flash('error', 'File backup tidak ditemukan.');
        redirect('/settings_backup');
    }
    ActivityLog::record($user['id'], 'backup.download', 'Download backup: ' . basename($path));
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'restore') {
            Rbac::require('backup.manage');
            BackupService::restore((int) $_POST['id'], $user['id']);
            flash('success', 'Restore berhasil. Backup kondisi sebelumnya otomatis dibuat.');
        } elseif ($action === 'delete') {
            Rbac::require('backup.manage');
            BackupService::delete((int) $_POST['id'], $user['id']);
            flash('success', 'Backup dihapus.');
        } elseif ($action === 'cloud_config_save') {
            Rbac::require('backup.manage');
            CloudBackupService::saveConfig(
                ($_POST['cloud_enabled'] ?? '') === '1',
                trim((string) ($_POST['cloud_endpoint'] ?? '')),
                trim((string) ($_POST['cloud_region'] ?? '')),
                trim((string) ($_POST['cloud_bucket'] ?? '')),
                trim((string) ($_POST['cloud_access_key'] ?? '')),
                ($_POST['cloud_secret_key'] ?? '') !== '' ? (string) $_POST['cloud_secret_key'] : null,
                trim((string) ($_POST['cloud_path_prefix'] ?? '')),
                $user['id']
            );
            flash('success', 'Konfigurasi Cloud Storage disimpan.');
        } elseif ($action === 'schedule_create') {
            Rbac::require('backup.manage');
            $type = (string) ($_POST['schedule_type'] ?? '');
            $target = (string) ($_POST[$type === 'database' ? 'schedule_db' : ($type === 'website' ? 'schedule_website' : 'schedule_nodejs')] ?? '');
            BackupScheduleService::create(
                $type,
                $target,
                (string) ($_POST['interval_unit'] ?? ''),
                (int) ($_POST['interval_value'] ?? 1),
                $user['id']
            );
            flash('success', 'Jadwal backup dibuat.');
        } elseif ($action === 'schedule_toggle') {
            Rbac::require('backup.manage');
            BackupScheduleService::toggle((int) $_POST['id'], $_POST['enable'] === '1', $user['id']);
            flash('success', 'Status jadwal backup diperbarui.');
        } elseif ($action === 'schedule_delete') {
            Rbac::require('backup.manage');
            BackupScheduleService::delete((int) $_POST['id'], $user['id']);
            flash('success', 'Jadwal backup dihapus.');
        }
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/settings_backup');
}

$backups = BackupService::listBackups();
$cloudConfig = CloudBackupService::config();
$schedules = BackupScheduleService::listAll();
$scheduleWebsites = NginxService::listWebsites();
$scheduleNodeApps = NodeService::listRegisteredApps();
$scheduleDatabases = DatabaseService::listRegistry();
$activeSettingsTab = 'backup';

$pageTitle = 'Pengaturan - Backup & Restore';
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/settings_nav.php';

function settings_backup_format_bytes(int $bytes): string
{
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = (int) floor(log($bytes, 1024));
    $i = min($i, count($units) - 1);
    return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
}
?>

<div class="mb-4">
  <h4 class="fw-bold mb-0">Backup &amp; Restore</h4>
  <p class="text-muted mb-0">
    Riwayat backup database, website, dan aplikasi Node.js. Restore otomatis membuat backup kondisi saat ini terlebih dahulu.
    Untuk membuat backup baru, gunakan tombol Backup di halaman Website PHP / Node.js Apps / Database masing-masing.
  </p>
</div>

<div class="card stat-card mb-4">
  <div class="card-header bg-white fw-semibold">Cloud Storage (S3 / Backblaze B2)</div>
  <div class="card-body">
    <p class="text-muted small">
      Setiap backup lokal yang berhasil dibuat (manual maupun terjadwal) otomatis diunggah ke sini kalau diaktifkan.
      Backblaze B2 memakai endpoint S3-compatible-nya sendiri (mis. <code>https://s3.us-west-002.backblazeb2.com</code>) - kosongkan Endpoint untuk AWS S3 asli.
    </p>
    <form method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="cloud_config_save">
      <fieldset <?= Rbac::can($user['role'], 'backup.manage') ? '' : 'disabled' ?>>
      <div class="form-check form-switch mb-3">
        <input type="checkbox" class="form-check-input" id="cloudEnabled" name="cloud_enabled" value="1" <?= $cloudConfig['enabled'] ? 'checked' : '' ?>>
        <label class="form-check-label" for="cloudEnabled">Aktifkan upload otomatis ke Cloud Storage</label>
      </div>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Endpoint (kosongkan untuk AWS S3)</label>
          <input type="text" name="cloud_endpoint" class="form-control" value="<?= e($cloudConfig['endpoint']) ?>" placeholder="https://s3.us-west-002.backblazeb2.com">
        </div>
        <div class="col-md-3">
          <label class="form-label">Region</label>
          <input type="text" name="cloud_region" class="form-control" value="<?= e($cloudConfig['region']) ?>" placeholder="us-east-1">
        </div>
        <div class="col-md-3">
          <label class="form-label">Bucket</label>
          <input type="text" name="cloud_bucket" class="form-control" value="<?= e($cloudConfig['bucket']) ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Access Key</label>
          <input type="text" name="cloud_access_key" class="form-control" value="<?= e($cloudConfig['access_key']) ?>" autocomplete="off" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Secret Key</label>
          <input type="password" name="cloud_secret_key" class="form-control" placeholder="<?= $cloudConfig['secret_key'] !== '' ? '•••••••• (kosongkan untuk tidak mengubah)' : '' ?>" autocomplete="new-password">
        </div>
        <div class="col-md-4">
          <label class="form-label">Path Prefix (folder dalam bucket)</label>
          <input type="text" name="cloud_path_prefix" class="form-control" value="<?= e(rtrim($cloudConfig['path_prefix'], '/')) ?>" placeholder="backups">
        </div>
      </div>
      <?php if (Rbac::can($user['role'], 'backup.manage')): ?>
      <button type="submit" class="btn btn-primary mt-3">Simpan Konfigurasi</button>
      <?php endif; ?>
      </fieldset>
    </form>
  </div>
</div>

<div class="card stat-card mb-4">
  <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
    <span>Jadwal Backup Otomatis</span>
    <?php if (Rbac::can($user['role'], 'backup.manage')): ?>
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createScheduleModal"><i class="bi bi-plus-lg me-1"></i>Tambah Jadwal</button>
    <?php endif; ?>
  </div>
  <div class="card-body p-0">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light"><tr><th>Tipe</th><th>Target</th><th>Interval</th><th>Terakhir Jalan</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
      <?php if (empty($schedules)): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">Belum ada jadwal backup otomatis</td></tr>
      <?php endif; ?>
      <?php foreach ($schedules as $s): ?>
        <tr>
          <td><span class="badge text-bg-light border"><?= e($s['type']) ?></span></td>
          <td><?= e($s['target_name']) ?></td>
          <td>Setiap <?= (int) $s['interval_value'] ?> <?= e($s['interval_unit']) ?></td>
          <td class="small text-muted"><?= e($s['last_run_at'] ?? 'belum pernah') ?><?= $s['last_run_status'] ? ' (' . e($s['last_run_status']) . ')' : '' ?></td>
          <td><?= $s['is_enabled'] ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-secondary">Nonaktif</span>' ?></td>
          <td class="text-end">
            <?php if (Rbac::can($user['role'], 'backup.manage')): ?>
            <form method="post" class="d-inline">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="schedule_toggle">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <input type="hidden" name="enable" value="<?= $s['is_enabled'] ? '0' : '1' ?>">
              <button class="btn btn-sm btn-outline-secondary"><i class="bi <?= $s['is_enabled'] ? 'bi-pause-fill' : 'bi-play-fill' ?>"></i></button>
            </form>
            <form method="post" class="d-inline" data-confirm="Hapus jadwal backup untuk <?= e($s['target_name']) ?>?">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="schedule_delete">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
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

<div class="modal fade" id="createScheduleModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Tambah Jadwal Backup</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="schedule_create">
          <div class="mb-3">
            <label class="form-label">Tipe</label>
            <select name="schedule_type" class="form-select" id="scheduleType" onchange="toggleScheduleTargetFields()" required>
              <option value="database">Database</option>
              <option value="website">Website PHP</option>
              <option value="nodejs">Aplikasi Node.js</option>
            </select>
          </div>
          <div class="mb-3" id="scheduleDbField">
            <label class="form-label">Database</label>
            <select name="schedule_db" class="form-select">
              <?php foreach ($scheduleDatabases as $d): ?><option value="<?= e($d['db_name']) ?>"><?= e($d['db_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3 d-none" id="scheduleWebsiteField">
            <label class="form-label">Website</label>
            <select name="schedule_website" class="form-select">
              <?php foreach ($scheduleWebsites as $w): ?><option value="<?= e($w['domain']) ?>"><?= e($w['domain']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3 d-none" id="scheduleNodejsField">
            <label class="form-label">Aplikasi Node.js</label>
            <select name="schedule_nodejs" class="form-select">
              <?php foreach ($scheduleNodeApps as $a): ?><option value="<?= e($a['app_name']) ?>"><?= e($a['app_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label">Setiap</label>
              <input type="number" name="interval_value" class="form-control" value="1" min="1" max="1000" required>
            </div>
            <div class="col-6">
              <label class="form-label">Satuan</label>
              <select name="interval_unit" class="form-select" required>
                <option value="minute">Menit</option>
                <option value="hour">Jam</option>
                <option value="day" selected>Hari</option>
                <option value="month">Bulan</option>
                <option value="year">Tahun</option>
              </select>
            </div>
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
function toggleScheduleTargetFields() {
  var v = document.getElementById('scheduleType').value;
  document.getElementById('scheduleDbField').classList.toggle('d-none', v !== 'database');
  document.getElementById('scheduleWebsiteField').classList.toggle('d-none', v !== 'website');
  document.getElementById('scheduleNodejsField').classList.toggle('d-none', v !== 'nodejs');
}
</script>

<div class="card stat-card">
  <div class="card-body p-0">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light"><tr><th>Tipe</th><th>Target</th><th>Ukuran</th><th>Status</th><th>Cloud</th><th>Dibuat</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
      <?php if (empty($backups)): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">Belum ada backup</td></tr>
      <?php endif; ?>
      <?php foreach ($backups as $b): ?>
        <tr>
          <td><span class="badge text-bg-light border"><?= e($b['type']) ?></span></td>
          <td><?= e($b['target_name']) ?></td>
          <td><?= e(settings_backup_format_bytes((int) $b['size_bytes'])) ?></td>
          <td>
            <?php $badgeClass = $b['status'] === 'completed' ? 'success' : ($b['status'] === 'failed' ? 'danger' : 'warning'); ?>
            <span class="badge text-bg-<?= $badgeClass ?>"><?= e($b['status']) ?></span>
          </td>
          <td><?= !empty($b['cloud_uploaded']) ? '<span class="badge text-bg-info" title="' . e((string) $b['cloud_uploaded_at']) . '"><i class="bi bi-cloud-check"></i></span>' : '<span class="text-muted">-</span>' ?></td>
          <td class="small text-muted"><?= e($b['created_at']) ?></td>
          <td class="text-end">
            <a href="/settings_backup?download=<?= (int) $b['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-download"></i></a>
            <?php if (Rbac::can($user['role'], 'backup.manage')): ?>
            <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#restore<?= (int) $b['id'] ?>"><i class="bi bi-arrow-counterclockwise"></i></button>
            <form method="post" class="d-inline" data-confirm="Hapus file backup ini?">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <div class="modal fade" id="restore<?= (int) $b['id'] ?>" tabindex="-1">
          <div class="modal-dialog">
            <form method="post">
              <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Restore Backup</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="restore">
                  <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                  <div class="alert alert-warning">Restore akan menimpa data <?= e($b['target_name']) ?> saat ini. Kondisi sebelum restore akan dibackup otomatis.</div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                  <button type="submit" class="btn btn-warning">Ya, Restore</button>
                </div>
              </div>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
