<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('plugin.manage');

$user = Auth::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'install_zip') {
            if (!isset($_FILES['zip']) || $_FILES['zip']['error'] !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('Upload file ZIP gagal atau tidak ada file dipilih.');
            }
            $content = file_get_contents($_FILES['zip']['tmp_name']);
            if ($content === false || $content === '') {
                throw new InvalidArgumentException('File ZIP kosong atau tidak bisa dibaca.');
            }
            $plugin = PluginService::installFromZip($content, $user['id']);
            flash('success', "Plugin '{$plugin['slug']}' terpasang - masih NONAKTIF, aktifkan dari tabel di bawah setelah kamu yakin sumbernya tepercaya.");
        } elseif ($action === 'install_git') {
            $plugin = PluginService::installFromGit(
                trim((string) ($_POST['repo_url'] ?? '')),
                trim((string) ($_POST['branch'] ?? '')) ?: null,
                $user['id']
            );
            flash('success', "Plugin '{$plugin['slug']}' terpasang - masih NONAKTIF, aktifkan dari tabel di bawah setelah kamu yakin sumbernya tepercaya.");
        } elseif ($action === 'enable') {
            PluginService::enable((string) ($_POST['slug'] ?? ''), $user['id']);
            flash('success', 'Plugin diaktifkan.');
        } elseif ($action === 'disable') {
            PluginService::disable((string) ($_POST['slug'] ?? ''), $user['id']);
            flash('success', 'Plugin dinonaktifkan.');
        } elseif ($action === 'uninstall') {
            PluginService::uninstall((string) ($_POST['slug'] ?? ''), $user['id']);
            flash('success', 'Plugin dihapus.');
        }
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/plugins');
}

$plugins = PluginService::listInstalled();

$pageTitle = 'Plugin';
include __DIR__ . '/partials/header.php';
?>

<div class="mb-4">
  <h4 class="fw-bold mb-0">Plugin</h4>
  <p class="text-muted mb-0">Perluas panel dengan modul tambahan (WAF, Hosting Seller, dll).</p>
</div>

<div class="alert alert-danger">
  <i class="bi bi-exclamation-octagon-fill me-1"></i>
  <strong>Plugin berjalan dengan privilege ROOT PENUH</strong> begitu diaktifkan - tidak ada sandbox
  di level kode. Menginstall dan mengaktifkan plugin sama saja dengan memberikan akses root penuh
  ke server ini kepada kode itu. Hanya install plugin dari sumber yang benar-benar kamu percaya.
</div>

<div class="card stat-card mb-4">
  <div class="card-header bg-white fw-semibold">Terpasang</div>
  <div class="card-body p-0">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light"><tr><th>Nama</th><th>Slug</th><th>Versi</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
      <?php if (empty($plugins)): ?>
        <tr><td colspan="5" class="text-center text-muted py-4">Belum ada plugin terpasang</td></tr>
      <?php endif; ?>
      <?php foreach ($plugins as $p): ?>
        <tr>
          <td>
            <?= e((string) ($p['manifest']['name'] ?? $p['slug'])) ?>
            <?php if ($p['missing']): ?><span class="badge text-bg-danger ms-1">File hilang</span><?php endif; ?>
          </td>
          <td><code><?= e($p['slug']) ?></code></td>
          <td><?= e((string) ($p['manifest']['version'] ?? '-')) ?></td>
          <td><?= $p['is_enabled'] ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-secondary">Nonaktif</span>' ?></td>
          <td class="text-end">
            <?php if (!$p['missing']): ?>
              <?php if ($p['is_enabled']): ?>
              <form method="post" class="d-inline" data-confirm="Nonaktifkan plugin '<?= e($p['slug']) ?>'?">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="disable">
                <input type="hidden" name="slug" value="<?= e($p['slug']) ?>">
                <button class="btn btn-sm btn-outline-secondary">Nonaktifkan</button>
              </form>
              <?php else: ?>
              <form method="post" class="d-inline" data-confirm="Aktifkan plugin '<?= e($p['slug']) ?>'? Plugin ini akan mendapat akses ROOT PENUH ke server begitu diaktifkan.">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="enable">
                <input type="hidden" name="slug" value="<?= e($p['slug']) ?>">
                <button class="btn btn-sm btn-primary">Aktifkan</button>
              </form>
              <?php endif; ?>
            <?php endif; ?>
            <form method="post" class="d-inline" data-confirm="Hapus plugin '<?= e($p['slug']) ?>' sepenuhnya? File-nya akan dihapus dari server.">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="uninstall">
              <input type="hidden" name="slug" value="<?= e($p['slug']) ?>">
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
      <div class="card-header bg-white fw-semibold">Install dari ZIP</div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="install_zip">
          <div class="mb-3">
            <input type="file" name="zip" class="form-control" accept=".zip" required>
            <div class="form-text">Harus berisi <code>plugin.json</code> di root paket (atau di dalam satu folder utama).</div>
          </div>
          <button type="submit" class="btn btn-primary">Install</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card stat-card h-100">
      <div class="card-header bg-white fw-semibold">Install dari Git</div>
      <div class="card-body">
        <form method="post">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="install_git">
          <div class="mb-3">
            <input type="url" name="repo_url" class="form-control" placeholder="https://github.com/user/plugin-repo.git" required>
          </div>
          <div class="mb-3">
            <input type="text" name="branch" class="form-control" placeholder="Branch (opsional, kosongkan = default repo)">
          </div>
          <button type="submit" class="btn btn-primary">Install</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
