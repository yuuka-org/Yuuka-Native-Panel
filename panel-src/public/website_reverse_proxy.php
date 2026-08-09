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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();
    Rbac::require('website.create');
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_proxy') {
            NginxService::addReverseProxy(
                $id,
                trim((string) ($_POST['name'] ?? '')),
                trim((string) ($_POST['path_prefix'] ?? '')),
                trim((string) ($_POST['target_url'] ?? '')),
                isset($_POST['websocket_enabled']),
                isset($_POST['cache_enabled']),
                trim((string) ($_POST['send_domain'] ?? '$host')),
                isset($_POST['show_proxy_path']),
                $user['id']
            );
            flash('success', 'Reverse Proxy ditambahkan.');
        } elseif ($action === 'remove_proxy') {
            NginxService::removeReverseProxy($id, (int) ($_POST['proxy_id'] ?? 0), $user['id']);
            flash('success', 'Reverse Proxy dihapus.');
        }
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/website_reverse_proxy?id=' . $id . $embedSuffix);
}

$proxies = NginxService::listReverseProxies($id);
$activeWebsiteTab = 'reverse_proxy';
$canEdit = Rbac::can($user['role'], 'website.create');

$pageTitle = 'Reverse Proxy - ' . $site['domain'];
include __DIR__ . ($embed ? '/partials/embed_header.php' : '/partials/header.php');
?>
<div class="d-flex">
<?php include __DIR__ . '/partials/website_settings_nav.php'; ?>
<div class="flex-grow-1">

<?php if (!$embed): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="fw-bold mb-0">Reverse Proxy: <?= e($site['domain']) ?></h4>
    <p class="text-muted mb-0">Berlaku untuk semua domain website ini.</p>
  </div>
  <a href="/websites" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
</div>
<?php else: ?>
<?php include __DIR__ . '/partials/flash.php'; ?>
<p class="text-muted small">Berlaku untuk semua domain website ini.</p>
<?php endif; ?>

<?php $redirectCount = count(NginxService::listRedirects($id)); ?>
<?php if ($redirectCount > 0): ?>
<div class="alert alert-warning py-2 small"><i class="bi bi-exclamation-triangle me-1"></i><?= $redirectCount ?> domain situs ini punya aturan Redirect aktif (tab Redirect) - untuk domain tersebut, aturan Reverse Proxy di bawah tidak akan pernah tercapai.</div>
<?php endif; ?>

<div class="card stat-card">
  <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
    <span>Reverse Proxy</span>
    <?php if ($canEdit): ?>
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addProxyModal"><i class="bi bi-plus-lg me-1"></i>Tambah Reverse Proxy</button>
    <?php endif; ?>
  </div>
  <div class="card-body p-0">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light"><tr><th>Nama</th><th>Path</th><th>Target</th><th>WebSocket</th><th>Cache</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
        <?php if (empty($proxies)): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">Belum ada aturan Reverse Proxy</td></tr>
        <?php endif; ?>
        <?php foreach ($proxies as $p): ?>
        <tr>
          <td><?= e($p['name'] ?: '-') ?></td>
          <td><code><?= e($p['path_prefix']) ?></code></td>
          <td><code><?= e($p['target_url']) ?></code></td>
          <td><?= $p['websocket_enabled'] ? '<i class="bi bi-check-lg text-success"></i>' : '<i class="bi bi-dash text-muted"></i>' ?></td>
          <td><?= $p['cache_enabled'] ? '<i class="bi bi-check-lg text-success"></i>' : '<i class="bi bi-dash text-muted"></i>' ?></td>
          <td class="text-end">
            <?php if ($canEdit): ?>
            <form method="post" class="d-inline" data-confirm="Hapus aturan Reverse Proxy <?= e($p['path_prefix']) ?>?">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="remove_proxy">
              <input type="hidden" name="proxy_id" value="<?= (int) $p['id'] ?>">
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

<div class="modal fade" id="addProxyModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Tambah Reverse Proxy</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="add_proxy">
          <div class="d-flex gap-3 mb-3">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="websocket_enabled" id="wsEnabled" checked>
              <label class="form-check-label" for="wsEnabled">WebSocket Support</label>
            </div>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="cache_enabled" id="cacheEnabled">
              <label class="form-check-label" for="cacheEnabled">Enable Caching</label>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Proxy Name</label>
            <input type="text" name="name" class="form-control" placeholder="mis. api-backend">
          </div>
          <div class="mb-3">
            <label class="form-label">Proxy Path</label>
            <input type="text" name="path_prefix" class="form-control" placeholder="/api" required pattern="^/.*">
          </div>
          <div class="mb-3">
            <label class="form-label">Target URL</label>
            <input type="url" name="target_url" class="form-control" placeholder="http://127.0.0.1:4000" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Send Domain</label>
            <input type="text" name="send_domain" class="form-control" value="$host" placeholder="$host">
            <div class="form-text">Header <code>Host</code> yang dikirim ke upstream. Biarkan <code>$host</code> untuk meneruskan domain asli yang diakses pengunjung.</div>
          </div>
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="show_proxy_path" id="showProxyPath" checked>
            <label class="form-check-label" for="showProxyPath">Show Proxy Path</label>
            <div class="form-text">Aktif: path <?= e('/api/foo') ?> diteruskan apa adanya ke upstream. Nonaktif: prefix path dipangkas sebelum diteruskan (mis. <code>/api/foo</code> -&gt; <code>/foo</code>).</div>
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

</div>
</div>

<?php include __DIR__ . ($embed ? '/partials/embed_footer.php' : '/partials/footer.php'); ?>
