<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('nodejs.view');

$user = Auth::user();
$id = (int) ($_GET['id'] ?? 0);
$embed = ($_GET['embed'] ?? '') === '1';
$embedSuffix = $embed ? '&embed=1' : '';
$app = NodeService::find($id);
if ($app === null) {
    flash('error', 'Aplikasi tidak ditemukan');
    redirect('/nodejs');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();
    Rbac::require('backup.manage');

    try {
        BackupService::backupNodeApp($app['app_name'], $user['id']);
        flash('success', 'Backup dibuat.');
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/nodejs_backup?id=' . $id . $embedSuffix);
}

$backups = array_values(array_filter(
    BackupService::listBackups(),
    static fn(array $b): bool => $b['type'] === 'nodejs' && $b['target_name'] === $app['app_name']
));
$activeNodejsTab = 'backup';

$pageTitle = 'Backup - ' . $app['app_name'];
include __DIR__ . ($embed ? '/partials/embed_header.php' : '/partials/header.php');
?>
<div class="d-flex">
<?php include __DIR__ . '/partials/nodejs_settings_nav.php'; ?>
<div class="flex-grow-1">

<?php if (!$embed): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="fw-bold mb-0">Backup: <?= e($app['app_name']) ?></h4>
    <p class="text-muted mb-0">Untuk restore/hapus file backup, gunakan menu Pengaturan &gt; Backup &amp; Restore.</p>
  </div>
  <div class="d-flex gap-2">
    <a href="/nodejs" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
    <?php if (Rbac::can($user['role'], 'backup.manage')): ?>
    <form method="post"><?= Csrf::field() ?>
      <button class="btn btn-primary"><i class="bi bi-cloud-arrow-down me-1"></i>Backup Sekarang</button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>
<?php include __DIR__ . '/partials/flash.php'; ?>
<?php if (Rbac::can($user['role'], 'backup.manage')): ?>
<form method="post" class="mb-3"><?= Csrf::field() ?>
  <button class="btn btn-sm btn-primary"><i class="bi bi-cloud-arrow-down me-1"></i>Backup Sekarang</button>
</form>
<?php endif; ?>
<?php endif; ?>

<div class="card stat-card">
  <div class="card-body p-0">
    <table class="table mb-0 align-middle">
      <thead class="table-light"><tr><th>Ukuran</th><th>Status</th><th>Dibuat</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
        <?php if (empty($backups)): ?>
          <tr><td colspan="4" class="text-center text-muted py-4">Belum ada backup untuk aplikasi ini</td></tr>
        <?php endif; ?>
        <?php foreach ($backups as $b): ?>
        <tr>
          <td><?= e((string) round(((int) $b['size_bytes']) / 1048576, 2)) ?> MB</td>
          <td>
            <?php $badgeClass = $b['status'] === 'completed' ? 'success' : ($b['status'] === 'failed' ? 'danger' : 'warning'); ?>
            <span class="badge text-bg-<?= $badgeClass ?>"><?= e($b['status']) ?></span>
          </td>
          <td class="small text-muted"><?= e($b['created_at']) ?></td>
          <td class="text-end"><a href="/settings_backup" class="btn btn-sm btn-outline-secondary">Kelola</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

</div>
</div>

<?php include __DIR__ . ($embed ? '/partials/embed_footer.php' : '/partials/footer.php'); ?>
