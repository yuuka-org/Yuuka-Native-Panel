<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('website.view');

$user = Auth::user();
$id = (int) ($_GET['id'] ?? 0);
$embed = ($_GET['embed'] ?? '') === '1';
$embedSuffix = $embed ? '&embed=1' : '';
$site = NginxService::find($id);
if ($site === null) {
    flash('error', 'Website tidak ditemukan');
    redirect('/websites');
}

if (($_GET['download'] ?? '') !== '') {
    $path = BackupService::downloadPath((int) $_GET['download']);
    if ($path === null) {
        flash('error', 'File backup tidak ditemukan.');
        redirect('/website_backup?id=' . $id . $embedSuffix);
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
        if ($action === 'backup_now') {
            Rbac::require('backup.manage');
            BackupService::backupWebsite($site['domain'], $user['id']);
            flash('success', 'Backup dibuat.');
        } elseif ($action === 'restore') {
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
    redirect('/website_backup?id=' . $id . $embedSuffix);
}

$backups = array_values(array_filter(
    BackupService::listBackups(),
    static fn(array $b): bool => $b['type'] === 'website' && $b['target_name'] === $site['domain']
));
$activeWebsiteTab = 'backup';

function website_backup_format_bytes(int $bytes): string
{
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = min((int) floor(log($bytes, 1024)), count($units) - 1);
    return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
}

$pageTitle = 'Backup - ' . $site['domain'];
include __DIR__ . ($embed ? '/partials/embed_header.php' : '/partials/header.php');
?>
<div class="d-flex">
<?php include __DIR__ . '/partials/website_settings_nav.php'; ?>
<div class="flex-grow-1">

<?php if (!$embed): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="fw-bold mb-0">Backup: <?= e($site['domain']) ?></h4>
    <p class="text-muted mb-0">Restore otomatis membuat backup kondisi saat ini terlebih dahulu.</p>
  </div>
  <div class="d-flex gap-2">
    <a href="/websites" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
    <?php if (Rbac::can($user['role'], 'backup.manage')): ?>
    <form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="backup_now">
      <button class="btn btn-primary"><i class="bi bi-cloud-arrow-down me-1"></i>Backup Sekarang</button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>
<?php include __DIR__ . '/partials/flash.php'; ?>
<?php if (Rbac::can($user['role'], 'backup.manage')): ?>
<form method="post" class="mb-3"><?= Csrf::field() ?><input type="hidden" name="action" value="backup_now">
  <button class="btn btn-sm btn-primary"><i class="bi bi-cloud-arrow-down me-1"></i>Backup Sekarang</button>
</form>
<?php endif; ?>
<?php endif; ?>

<div class="card stat-card">
  <div class="card-body p-0">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light"><tr><th>Ukuran</th><th>Status</th><th>Dibuat</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
      <?php if (empty($backups)): ?>
        <tr><td colspan="4" class="text-center text-muted py-4">Belum ada backup untuk website ini</td></tr>
      <?php endif; ?>
      <?php foreach ($backups as $b): ?>
        <tr>
          <td><?= e(website_backup_format_bytes((int) $b['size_bytes'])) ?></td>
          <td>
            <?php $badgeClass = $b['status'] === 'completed' ? 'success' : ($b['status'] === 'failed' ? 'danger' : 'warning'); ?>
            <span class="badge text-bg-<?= $badgeClass ?>"><?= e($b['status']) ?></span>
          </td>
          <td class="small text-muted"><?= e($b['created_at']) ?></td>
          <td class="text-end">
            <a href="/website_backup?id=<?= $id ?>&download=<?= (int) $b['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-download"></i></a>
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
                  <div class="alert alert-warning">Restore akan menimpa data <?= e($site['domain']) ?> saat ini. Kondisi sebelum restore akan dibackup otomatis.</div>
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

</div>
</div>

<?php include __DIR__ . ($embed ? '/partials/embed_footer.php' : '/partials/footer.php'); ?>
