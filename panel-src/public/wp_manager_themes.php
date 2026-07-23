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
        if ($action === 'activate') {
            WpManagerService::activateTheme($site, (string) ($_POST['folder'] ?? ''), $user['id']);
            flash('success', 'Tema diaktifkan.');
        } elseif ($action === 'delete') {
            WpManagerService::deleteTheme($site, (string) ($_POST['folder'] ?? ''), isset($_POST['confirm_unknown_risk']), $user['id']);
            flash('success', 'Tema dihapus.');
        } elseif ($action === 'install_slug') {
            WpManagerService::installThemeBySlug($site, trim((string) ($_POST['slug'] ?? '')), $user['id']);
            flash('success', 'Tema berhasil dipasang.');
        } elseif ($action === 'install_zip') {
            if (!isset($_FILES['theme_zip']) || $_FILES['theme_zip']['error'] !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('Upload ZIP gagal atau tidak ada file dipilih');
            }
            $zipBytes = (string) file_get_contents($_FILES['theme_zip']['tmp_name']);
            WpManagerService::installThemeByZip($site, $zipBytes, $user['id']);
            flash('success', 'Tema berhasil dipasang dari ZIP.');
        } elseif ($action === 'update') {
            $version = WpManagerService::updateTheme($site, (string) ($_POST['folder'] ?? ''), $user['id']);
            flash('success', "Tema berhasil diupdate ke versi {$version}.");
        }
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/wp_manager_themes.php?id=' . $id);
}

$themes = WpManagerService::listThemes($site);

$checkFolder = (string) ($_GET['check'] ?? '');
$checkResult = null;
if ($checkFolder !== '') {
    $current = null;
    foreach ($themes as $t) {
        if ($t['folder'] === $checkFolder) {
            $current = $t;
            break;
        }
    }
    try {
        $checkResult = ['folder' => $checkFolder, 'result' => WpManagerService::checkThemeUpdate($checkFolder, $current['version'] ?? null)];
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
}

$pageTitle = 'Tema WordPress - ' . $site['domain'];
include __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="fw-bold mb-0">WP Manager: <?= e($site['domain']) ?></h4>
    <p class="text-muted mb-0">Tema terpasang: <?= count($themes) ?></p>
  </div>
  <div class="btn-group">
    <a href="/wp_manager.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Daftar</a>
    <a href="/wp_manager_core.php?id=<?= (int) $id ?>" class="btn btn-outline-secondary">Core</a>
    <a href="/wp_manager_plugins.php?id=<?= (int) $id ?>" class="btn btn-outline-secondary">Plugin</a>
    <a href="/wp_manager_themes.php?id=<?= (int) $id ?>" class="btn btn-primary">Tema</a>
  </div>
</div>

<?php if ($checkResult !== null): $r = $checkResult['result']; ?>
<div class="alert <?= $r['update_available'] ? 'alert-warning' : 'alert-success' ?>">
  <?php if ($r['update_available']): ?>
    Update tersedia untuk <strong><?= e($checkResult['folder']) ?></strong>: versi <strong><?= e($r['latest_version']) ?></strong>.
  <?php else: ?>
    <strong><?= e($checkResult['folder']) ?></strong> sudah versi terbaru (<?= e($r['latest_version']) ?>).
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (empty($site['table_prefix'])): ?>
<div class="alert alert-secondary small">
  Status database situs ini tidak diketahui (lihat menu Core untuk mengisi prefix tabel manual) - aktivasi tema dinonaktifkan sementara, dan hapus tema butuh konfirmasi risiko tambahan.
</div>
<?php endif; ?>

<div class="d-flex justify-content-end mb-3">
  <?php if (Rbac::can($user['role'], 'wp.manage')): ?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addThemeModal"><i class="bi bi-plus-lg me-1"></i>Tambah Tema</button>
  <?php endif; ?>
</div>

<div class="card stat-card">
  <div class="card-body p-0">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light"><tr><th>Tema</th><th>Versi</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
      <?php if (empty($themes)): ?>
        <tr><td colspan="4" class="text-center text-muted py-4">Tidak ada tema ditemukan</td></tr>
      <?php endif; ?>
      <?php foreach ($themes as $t): $rowId = md5($t['folder']); ?>
        <tr>
          <td><?= e($t['name']) ?><div class="text-muted small"><?= e($t['folder']) ?></div></td>
          <td><?= e($t['version'] ?? '-') ?></td>
          <td>
            <?php if ($t['active'] === null): ?>
              <span class="badge text-bg-secondary">Tidak diketahui</span>
            <?php elseif ($t['active']): ?>
              <span class="badge text-bg-success">Aktif</span>
            <?php else: ?>
              <span class="badge text-bg-light border">Nonaktif</span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <a href="/wp_manager_themes.php?id=<?= (int) $id ?>&check=<?= urlencode($t['folder']) ?>" class="btn btn-sm btn-outline-secondary" title="Cek update"><i class="bi bi-arrow-repeat"></i></a>
            <?php if (Rbac::can($user['role'], 'wp.manage')): ?>
              <?php if (!$t['active']): ?>
              <form method="post" class="d-inline">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="activate">
                <input type="hidden" name="folder" value="<?= e($t['folder']) ?>">
                <button class="btn btn-sm btn-outline-secondary">Aktifkan</button>
              </form>
              <?php endif; ?>
              <?php if ($checkResult !== null && $checkResult['folder'] === $t['folder'] && $checkResult['result']['update_available']): ?>
              <form method="post" class="d-inline">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="folder" value="<?= e($t['folder']) ?>">
                <button class="btn btn-sm btn-primary">Update ke <?= e($checkResult['result']['latest_version']) ?></button>
              </form>
              <?php endif; ?>
              <?php if (!$t['active']): ?>
              <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#delTheme<?= $rowId ?>"><i class="bi bi-trash"></i></button>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
        <?php if (Rbac::can($user['role'], 'wp.manage') && !$t['active']): ?>
        <div class="modal fade" id="delTheme<?= $rowId ?>" tabindex="-1">
          <div class="modal-dialog">
            <form method="post">
              <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Hapus Tema</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="folder" value="<?= e($t['folder']) ?>">
                  <div class="alert alert-warning">Hapus tema <strong><?= e($t['name']) ?></strong> secara permanen dari server?</div>
                  <?php if ($t['active'] === null): ?>
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="confirm_unknown_risk" id="confirmRiskTheme<?= $rowId ?>" required>
                    <label class="form-check-label small" for="confirmRiskTheme<?= $rowId ?>">Status aktif tema ini tidak diketahui (koneksi database situs gagal). Saya paham risikonya.</label>
                  </div>
                  <?php endif; ?>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                  <button type="submit" class="btn btn-danger">Ya, Hapus</button>
                </div>
              </div>
            </form>
          </div>
        </div>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (Rbac::can($user['role'], 'wp.manage')): ?>
<div class="modal fade" id="addThemeModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" enctype="multipart/form-data">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Tambah Tema</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" id="addThemeAction" value="install_slug">
          <div class="mb-3">
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="radio" name="install_method" id="themeMethodSlug" checked>
              <label class="form-check-label" for="themeMethodSlug">Dari WordPress.org (slug)</label>
            </div>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="radio" name="install_method" id="themeMethodZip">
              <label class="form-check-label" for="themeMethodZip">Upload ZIP</label>
            </div>
          </div>
          <div id="themeSlugField">
            <label class="form-label">Slug Tema</label>
            <input type="text" name="slug" class="form-control" placeholder="contoh: twentytwentyfive" pattern="^[a-z0-9]+(-[a-z0-9]+)*$">
            <div class="form-text mb-2">Bagian akhir URL halaman tema di wordpress.org/themes/&lt;slug&gt;/</div>
          </div>
          <div id="themeZipField" style="display:none">
            <label class="form-label">File ZIP Tema</label>
            <input type="file" name="theme_zip" accept=".zip" class="form-control" disabled>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary">Pasang</button>
        </div>
      </div>
    </form>
  </div>
</div>
<script>
document.querySelectorAll('#addThemeModal input[name="install_method"]').forEach(function (radio) {
  radio.addEventListener('change', function () {
    var isZip = document.getElementById('themeMethodZip').checked;
    document.getElementById('themeSlugField').style.display = isZip ? 'none' : '';
    document.getElementById('themeZipField').style.display = isZip ? '' : 'none';
    document.querySelector('#themeSlugField input[name="slug"]').disabled = isZip;
    document.querySelector('#themeZipField input[name="theme_zip"]').disabled = !isZip;
    document.getElementById('addThemeAction').value = isZip ? 'install_zip' : 'install_slug';
  });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>
