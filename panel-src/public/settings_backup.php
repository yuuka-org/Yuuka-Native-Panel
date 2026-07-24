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
        redirect('/settings_backup.php');
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
        }
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/settings_backup.php');
}

$backups = BackupService::listBackups();
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

<div class="card stat-card">
  <div class="card-body p-0">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light"><tr><th>Tipe</th><th>Target</th><th>Ukuran</th><th>Status</th><th>Dibuat</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
      <?php if (empty($backups)): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">Belum ada backup</td></tr>
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
          <td class="small text-muted"><?= e($b['created_at']) ?></td>
          <td class="text-end">
            <a href="/settings_backup.php?download=<?= (int) $b['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-download"></i></a>
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
