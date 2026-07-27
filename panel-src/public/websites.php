<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('website.view');

$user = Auth::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            Rbac::require('website.create');
            $site = NginxService::createWebsite(
                trim((string) ($_POST['domain'] ?? '')),
                (string) ($_POST['php_version'] ?? ''),
                $user['id'],
                trim((string) ($_POST['git_repo_url'] ?? '')) ?: null,
                trim((string) ($_POST['git_branch'] ?? '')) ?: null
            );
            flash('success', "Website {$site['domain']} berhasil dibuat.");
        } elseif ($action === 'git_pull') {
            Rbac::require('website.create');
            NginxService::gitPull((int) $_POST['id'], $user['id']);
            flash('success', 'Berhasil git pull - situs diperbarui ke commit terbaru.');
        } elseif ($action === 'toggle') {
            Rbac::require('website.toggle');
            NginxService::toggleWebsite((int) $_POST['id'], $_POST['enable'] === '1', $user['id']);
            flash('success', 'Status website diperbarui.');
        } elseif ($action === 'delete') {
            Rbac::require('website.delete');
            NginxService::deleteWebsite((int) $_POST['id'], ($_POST['delete_files'] ?? '') === '1', $user['id']);
            flash('success', 'Website dihapus.');
        } elseif ($action === 'backup') {
            Rbac::require('backup.manage');
            $domain = (string) ($_POST['domain'] ?? '');
            BackupService::backupWebsite($domain, $user['id']);
            flash('success', "Backup {$domain} dibuat. Lihat di Pengaturan > Backup & Restore.");
        }
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/websites');
}

$websites = NginxService::listWebsites();
$phpVersions = PhpService::installedVersions();

$pageTitle = 'Website PHP';
include __DIR__ . '/partials/header.php';
?>

<?php if (Rbac::can($user['role'], 'nodejs.view')): ?>
<div class="btn-group mb-3">
  <a href="/websites" class="btn btn-sm btn-primary"><img src="https://cdn.jsdelivr.net/npm/simple-icons@v13/icons/php.svg" alt="" style="width:1em;height:1em;vertical-align:-0.125em;" class="me-1">PHP</a>
  <a href="/nodejs" class="btn btn-sm btn-outline-secondary"><img src="https://cdn.jsdelivr.net/npm/simple-icons@v13/icons/nodedotjs.svg" alt="" style="width:1em;height:1em;vertical-align:-0.125em;" class="me-1">Node.js</a>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h4 class="fw-bold mb-0">Website PHP</h4>
    <p class="text-muted mb-0">Kelola website PHP native / multi-versi</p>
  </div>
  <?php if (Rbac::can($user['role'], 'website.create')): ?>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createSiteModal">
    <i class="bi bi-plus-lg me-1"></i>Tambah Website
  </button>
  <?php endif; ?>
</div>

<div class="card stat-card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr><th>Domain</th><th>PHP Version</th><th>Document Root</th><th>Git</th><th>SSL</th><th>Status</th><th class="text-end">Aksi</th></tr>
        </thead>
        <tbody>
        <?php if (empty($websites)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">Belum ada website</td></tr>
        <?php endif; ?>
        <?php foreach ($websites as $site): ?>
          <?php $gitStatus = !empty($site['git_repo_url']) ? NginxService::gitStatus((int) $site['id']) : null; ?>
          <tr>
            <td><a href="http://<?= e($site['domain']) ?>" target="_blank" rel="noopener"><?= e($site['domain']) ?></a></td>
            <td><span class="badge text-bg-light border">PHP <?= e($site['php_version']) ?></span></td>
            <td class="text-muted small"><?= e($site['document_root']) ?></td>
            <td class="small">
              <?php if ($gitStatus !== null && $gitStatus['is_git']): ?>
                <div><i class="bi bi-git me-1"></i><code><?= e($gitStatus['branch'] ?? '?') ?></code> @ <code><?= e($gitStatus['commit'] ?? '?') ?></code></div>
                <div class="text-muted" title="<?= e($gitStatus['message'] ?? '') ?>"><?= e(mb_strimwidth((string) ($gitStatus['message'] ?? ''), 0, 40, '...')) ?> &middot; <?= e($gitStatus['date'] ?? '') ?></div>
              <?php else: ?>
                <span class="text-muted">-</span>
              <?php endif; ?>
            </td>
            <td><?= $site['ssl_enabled'] ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-secondary">Tidak aktif</span>' ?></td>
            <td><?= $site['is_enabled'] ? '<span class="badge text-bg-success">Enabled</span>' : '<span class="badge text-bg-secondary">Disabled</span>' ?></td>
            <td class="text-end">
              <?php if (Rbac::can($user['role'], 'website.toggle')): ?>
              <form method="post" class="d-inline">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= e((string) $site['id']) ?>">
                <input type="hidden" name="enable" value="<?= $site['is_enabled'] ? '0' : '1' ?>">
                <button class="btn btn-sm btn-outline-secondary" title="<?= $site['is_enabled'] ? 'Disable' : 'Enable' ?>">
                  <i class="bi <?= $site['is_enabled'] ? 'bi-pause-fill' : 'bi-play-fill' ?>"></i>
                </button>
              </form>
              <?php endif; ?>
              <?php if ($gitStatus !== null && $gitStatus['is_git'] && Rbac::can($user['role'], 'website.create')): ?>
              <form method="post" class="d-inline" data-confirm="Git pull untuk <?= e($site['domain']) ?>? (fast-forward only)">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="git_pull">
                <input type="hidden" name="id" value="<?= e((string) $site['id']) ?>">
                <button class="btn btn-sm btn-outline-success" title="Pull / Update dari Git"><i class="bi bi-cloud-download"></i></button>
              </form>
              <?php endif; ?>
              <a href="/domains?website_id=<?= e((string) $site['id']) ?>" class="btn btn-sm btn-outline-primary" title="SSL / Domain"><i class="bi bi-shield-lock"></i></a>
              <?php if (Rbac::can($user['role'], 'files.view')): ?>
              <a href="/file_manager?scope=website&name=<?= urlencode($site['domain']) ?>" class="btn btn-sm btn-outline-secondary" title="File Manager"><i class="bi bi-folder2-open"></i></a>
              <?php endif; ?>
              <?php if (Rbac::can($user['role'], 'backup.manage')): ?>
              <form method="post" class="d-inline" data-confirm="Buat backup website <?= e($site['domain']) ?> sekarang?">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="backup">
                <input type="hidden" name="domain" value="<?= e($site['domain']) ?>">
                <button class="btn btn-sm btn-outline-secondary" title="Backup Sekarang"><i class="bi bi-cloud-arrow-down"></i></button>
              </form>
              <?php endif; ?>
              <?php if (Rbac::can($user['role'], 'website.delete')): ?>
              <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal<?= e((string) $site['id']) ?>" title="Hapus"><i class="bi bi-trash"></i></button>
              <?php endif; ?>
            </td>
          </tr>

          <div class="modal fade" id="deleteModal<?= e((string) $site['id']) ?>" tabindex="-1">
            <div class="modal-dialog">
              <form method="post">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title">Hapus Website</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= e((string) $site['id']) ?>">
                    <p>Yakin ingin menghapus website <strong><?= e($site['domain']) ?></strong>?</p>
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="delete_files" value="1" id="delFiles<?= e((string) $site['id']) ?>">
                      <label class="form-check-label" for="delFiles<?= e((string) $site['id']) ?>">
                        Hapus juga seluruh file di server (tidak dapat dibatalkan)
                      </label>
                    </div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger">Hapus</button>
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

<div class="modal fade" id="createSiteModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Tambah Website PHP</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="create">
          <div class="mb-3">
            <label class="form-label">Domain</label>
            <input type="text" name="domain" class="form-control" placeholder="contoh.com" required pattern="^[a-zA-Z0-9.\-]+$">
          </div>
          <div class="mb-3">
            <label class="form-label">Versi PHP</label>
            <select name="php_version" class="form-select" required>
              <?php foreach ($phpVersions as $v): ?>
                <option value="<?= e($v) ?>">PHP <?= e($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <hr>
          <div class="mb-3">
            <label class="form-label">Deploy dari Git (opsional)</label>
            <input type="url" name="git_repo_url" class="form-control" placeholder="https://github.com/user/repo.git">
            <div class="form-text">HTTPS saja. Untuk repo privat, sertakan token di URL: <code>https://user:TOKEN@github.com/...</code>. Repo harus punya folder <code>public/</code> di root-nya (seperti Laravel/Symfony) - itu yang jadi document root.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Branch (opsional, kosongkan = default repo)</label>
            <input type="text" name="git_branch" class="form-control" placeholder="main">
          </div>
          <p class="text-muted small mb-0">Kalau URL Git dikosongkan, document root kosong akan dibuat otomatis di <code>/var/www/&lt;domain&gt;/public</code>.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary">Buat Website</button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
