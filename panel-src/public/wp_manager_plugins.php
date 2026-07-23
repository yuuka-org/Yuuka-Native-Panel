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
        if ($action === 'toggle') {
            WpManagerService::togglePlugin($site, (string) ($_POST['plugin_file'] ?? ''), ($_POST['activate'] ?? '') === '1', $user['id']);
            flash('success', 'Status plugin diperbarui.');
        } elseif ($action === 'delete') {
            WpManagerService::deletePlugin($site, (string) ($_POST['plugin_file'] ?? ''), isset($_POST['confirm_unknown_risk']), $user['id']);
            flash('success', 'Plugin dihapus.');
        } elseif ($action === 'install_slug') {
            WpManagerService::installPluginBySlug($site, trim((string) ($_POST['slug'] ?? '')), $user['id']);
            flash('success', 'Plugin berhasil dipasang.');
        } elseif ($action === 'install_zip') {
            if (!isset($_FILES['plugin_zip']) || $_FILES['plugin_zip']['error'] !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('Upload ZIP gagal atau tidak ada file dipilih');
            }
            $zipBytes = (string) file_get_contents($_FILES['plugin_zip']['tmp_name']);
            WpManagerService::installPluginByZip($site, $zipBytes, $user['id']);
            flash('success', 'Plugin berhasil dipasang dari ZIP.');
        } elseif ($action === 'update') {
            $version = WpManagerService::updatePlugin($site, (string) ($_POST['folder'] ?? ''), $user['id']);
            flash('success', "Plugin berhasil diupdate ke versi {$version}.");
        }
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/wp_manager_plugins.php?id=' . $id);
}

$plugins = WpManagerService::listPlugins($site);

// Read-only check, plain GET link (see wp_manager.php) - reuses the
// already-fetched $plugins list instead of scanning the filesystem twice.
$checkFolder = (string) ($_GET['check'] ?? '');
$checkResult = null;
if ($checkFolder !== '') {
    $current = null;
    foreach ($plugins as $p) {
        if ($p['folder'] === $checkFolder) {
            $current = $p;
            break;
        }
    }
    try {
        $checkResult = ['folder' => $checkFolder, 'result' => WpManagerService::checkPluginUpdate($checkFolder, $current['version'] ?? null)];
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
}

$pageTitle = 'Plugin WordPress - ' . $site['domain'];
include __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="fw-bold mb-0">WP Manager: <?= e($site['domain']) ?></h4>
    <p class="text-muted mb-0">Plugin terpasang: <?= count($plugins) ?></p>
  </div>
  <div class="btn-group">
    <a href="/wp_manager.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Daftar</a>
    <a href="/wp_manager_core.php?id=<?= (int) $id ?>" class="btn btn-outline-secondary">Core</a>
    <a href="/wp_manager_plugins.php?id=<?= (int) $id ?>" class="btn btn-primary">Plugin</a>
    <a href="/wp_manager_themes.php?id=<?= (int) $id ?>" class="btn btn-outline-secondary">Tema</a>
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
  Status database situs ini tidak diketahui (lihat menu Core untuk mengisi prefix tabel manual) - aktifkan/nonaktifkan plugin dinonaktifkan sementara, dan hapus plugin butuh konfirmasi risiko tambahan.
</div>
<?php endif; ?>

<div class="d-flex justify-content-end mb-3">
  <?php if (Rbac::can($user['role'], 'wp.manage')): ?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addPluginModal"><i class="bi bi-plus-lg me-1"></i>Tambah Plugin</button>
  <?php endif; ?>
</div>

<div class="card stat-card">
  <div class="card-body p-0">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light"><tr><th>Plugin</th><th>Versi</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
      <?php if (empty($plugins)): ?>
        <tr><td colspan="4" class="text-center text-muted py-4">Tidak ada plugin ditemukan</td></tr>
      <?php endif; ?>
      <?php foreach ($plugins as $p): $rowId = md5($p['folder']); ?>
        <tr>
          <td><?= e($p['name']) ?><div class="text-muted small"><?= e($p['folder']) ?></div></td>
          <td><?= e($p['version'] ?? '-') ?></td>
          <td>
            <?php if ($p['active'] === null): ?>
              <span class="badge text-bg-secondary">Tidak diketahui</span>
            <?php elseif ($p['active']): ?>
              <span class="badge text-bg-success">Aktif</span>
            <?php else: ?>
              <span class="badge text-bg-light border">Nonaktif</span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <a href="/wp_manager_plugins.php?id=<?= (int) $id ?>&check=<?= urlencode($p['folder']) ?>" class="btn btn-sm btn-outline-secondary" title="Cek update"><i class="bi bi-arrow-repeat"></i></a>
            <?php if (Rbac::can($user['role'], 'wp.manage')): ?>
              <?php if ($p['active'] !== null): ?>
              <form method="post" class="d-inline">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="plugin_file" value="<?= e($p['file']) ?>">
                <input type="hidden" name="activate" value="<?= $p['active'] ? '0' : '1' ?>">
                <button class="btn btn-sm btn-outline-secondary"><?= $p['active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
              </form>
              <?php endif; ?>
              <?php if ($checkResult !== null && $checkResult['folder'] === $p['folder'] && $checkResult['result']['update_available']): ?>
              <form method="post" class="d-inline">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="folder" value="<?= e($p['folder']) ?>">
                <button class="btn btn-sm btn-primary">Update ke <?= e($checkResult['result']['latest_version']) ?></button>
              </form>
              <?php endif; ?>
              <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#delPlugin<?= $rowId ?>"><i class="bi bi-trash"></i></button>
            <?php endif; ?>
          </td>
        </tr>
        <?php if (Rbac::can($user['role'], 'wp.manage')): ?>
        <div class="modal fade" id="delPlugin<?= $rowId ?>" tabindex="-1">
          <div class="modal-dialog">
            <form method="post">
              <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Hapus Plugin</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="plugin_file" value="<?= e($p['file']) ?>">
                  <div class="alert alert-warning">Hapus plugin <strong><?= e($p['name']) ?></strong> secara permanen dari server?</div>
                  <?php if ($p['active'] === null): ?>
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="confirm_unknown_risk" id="confirmRisk<?= $rowId ?>" required>
                    <label class="form-check-label small" for="confirmRisk<?= $rowId ?>">Status aktif plugin ini tidak diketahui (koneksi database situs gagal). Saya paham risikonya kalau ternyata plugin ini sedang aktif.</label>
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
<div class="modal fade" id="addPluginModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" enctype="multipart/form-data">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Tambah Plugin</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" id="addPluginAction" value="install_slug">
          <div class="mb-3">
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="radio" name="install_method" id="pluginMethodSlug" checked>
              <label class="form-check-label" for="pluginMethodSlug">Dari WordPress.org (slug)</label>
            </div>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="radio" name="install_method" id="pluginMethodZip">
              <label class="form-check-label" for="pluginMethodZip">Upload ZIP</label>
            </div>
          </div>
          <div id="pluginSlugField">
            <label class="form-label">Slug Plugin</label>
            <input type="text" name="slug" class="form-control" placeholder="contoh: akismet" pattern="^[a-z0-9]+(-[a-z0-9]+)*$">
            <div class="form-text mb-2">Bagian akhir URL halaman plugin di wordpress.org/plugins/&lt;slug&gt;/</div>
          </div>
          <div id="pluginZipField" style="display:none">
            <label class="form-label">File ZIP Plugin</label>
            <input type="file" name="plugin_zip" accept=".zip" class="form-control" disabled>
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
document.querySelectorAll('#addPluginModal input[name="install_method"]').forEach(function (radio) {
  radio.addEventListener('change', function () {
    var isZip = document.getElementById('pluginMethodZip').checked;
    document.getElementById('pluginSlugField').style.display = isZip ? 'none' : '';
    document.getElementById('pluginZipField').style.display = isZip ? '' : 'none';
    document.querySelector('#pluginSlugField input[name="slug"]').disabled = isZip;
    document.querySelector('#pluginZipField input[name="plugin_zip"]').disabled = !isZip;
    document.getElementById('addPluginAction').value = isZip ? 'install_zip' : 'install_slug';
  });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>
