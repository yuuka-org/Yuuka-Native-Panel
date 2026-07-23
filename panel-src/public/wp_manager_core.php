<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('wp.view');

$user = Auth::user();
$id = (int) ($_GET['id'] ?? 0);

try {
    $site = WpManagerService::getSite($id);
} catch (InvalidArgumentException $e) {
    flash('error', $e->getMessage());
    redirect('/wp_manager.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();
    Rbac::require('wp.manage');
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'update_core') {
            $result = WpManagerService::updateCore($site, $user['id']);
            flash('success', "WordPress berhasil diupdate ke versi {$result['version']}.");
        } elseif ($action === 'set_prefix') {
            WpManagerService::setTablePrefixOverride($id, trim((string) ($_POST['table_prefix'] ?? '')));
            flash('success', 'Prefix tabel database disimpan.');
        }
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/wp_manager_core.php?id=' . $id);
}

$checkResult = null;
if (($_GET['check'] ?? '') === '1') {
    try {
        $checkResult = WpManagerService::checkCoreUpdate($site);
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
}

$pageTitle = 'Core WordPress - ' . $site['domain'];
include __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="fw-bold mb-0">WP Manager: <?= e($site['domain']) ?></h4>
    <p class="text-muted mb-0">Versi WordPress saat ini: <strong><?= e($site['app_version'] ?? 'tidak diketahui') ?></strong></p>
  </div>
  <div class="btn-group">
    <a href="/wp_manager.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Daftar</a>
    <a href="/wp_manager_core.php?id=<?= (int) $id ?>" class="btn btn-primary">Core</a>
    <a href="/wp_manager_plugins.php?id=<?= (int) $id ?>" class="btn btn-outline-secondary">Plugin</a>
    <a href="/wp_manager_themes.php?id=<?= (int) $id ?>" class="btn btn-outline-secondary">Tema</a>
  </div>
</div>

<?php if ($checkResult !== null): ?>
  <div class="alert <?= $checkResult['update_available'] ? 'alert-warning' : 'alert-success' ?>">
    <?php if ($checkResult['update_available']): ?>
      Update tersedia: WordPress <strong><?= e($checkResult['latest_version']) ?></strong>.
    <?php else: ?>
      Sudah versi terbaru (<?= e($checkResult['latest_version']) ?>).
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-md-6">
    <div class="card stat-card h-100">
      <div class="card-header bg-white fw-semibold">Versi Core</div>
      <div class="card-body">
        <p>Versi terpasang: <strong><?= e($site['app_version'] ?? 'tidak diketahui') ?></strong></p>
        <a href="/wp_manager_core.php?id=<?= (int) $id ?>&check=1" class="btn btn-outline-secondary"><i class="bi bi-arrow-repeat me-1"></i>Cek Update</a>
        <?php if ($checkResult !== null && $checkResult['update_available'] && Rbac::can($user['role'], 'wp.manage')): ?>
        <button type="button" class="btn btn-primary ms-2" data-bs-toggle="modal" data-bs-target="#updateCoreModal">
          Update ke <?= e($checkResult['latest_version']) ?>
        </button>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-md-6">
    <div class="card stat-card h-100">
      <div class="card-header bg-white fw-semibold">Prefix Tabel Database</div>
      <div class="card-body">
        <p class="text-muted small">Dipakai WP Manager untuk membaca/menulis status plugin &amp; tema. Terisi otomatis - ubah manual hanya kalau terdeteksi salah (mis. wp-config.php pernah diedit manual).</p>
        <p>Saat ini: <code><?= e($site['table_prefix'] ?? 'tidak diketahui') ?></code></p>
        <?php if (Rbac::can($user['role'], 'wp.manage')): ?>
        <form method="post" class="d-flex gap-2">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="set_prefix">
          <input type="text" name="table_prefix" class="form-control" pattern="^[A-Za-z0-9_]{1,20}$" placeholder="wp_" value="<?= e($site['table_prefix'] ?? '') ?>" required>
          <button class="btn btn-outline-primary">Simpan</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if (Rbac::can($user['role'], 'wp.manage')): ?>
<div class="modal fade" id="updateCoreModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Update WordPress Core</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="update_core">
          <div class="alert alert-warning small mb-0">
            Backup website &amp; database akan dibuat otomatis dulu sebelum update. Proses ini singkat tapi <strong>tidak sepenuhnya atomik</strong> - kalau koneksi terputus di tengah proses, situs bisa sempat tidak bisa diakses sampai dipulihkan dari backup (lihat menu Backup).
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary">Ya, Update Sekarang</button>
        </div>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>
