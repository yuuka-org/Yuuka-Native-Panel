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
        if ($action === 'add_redirect') {
            NginxService::addRedirect(
                $id,
                (string) ($_POST['source_domain'] ?? ''),
                trim((string) ($_POST['target_url'] ?? '')),
                (int) ($_POST['status_code'] ?? 301),
                isset($_POST['include_uri_params']),
                $user['id']
            );
            flash('success', 'Redirect ditambahkan.');
        } elseif ($action === 'remove_redirect') {
            NginxService::removeRedirect($id, (int) ($_POST['redirect_id'] ?? 0), $user['id']);
            flash('success', 'Redirect dihapus.');
        }
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/website_redirect?id=' . $id . $embedSuffix);
}

$redirects = NginxService::listRedirects($id);
$siteDomains = array_column(NginxService::listDomains($id), 'domain');
$activeWebsiteTab = 'redirect';
$canEdit = Rbac::can($user['role'], 'website.create');

$pageTitle = 'Redirect - ' . $site['domain'];
include __DIR__ . ($embed ? '/partials/embed_header.php' : '/partials/header.php');
?>
<div class="d-flex">
<?php include __DIR__ . '/partials/website_settings_nav.php'; ?>
<div class="flex-grow-1">

<?php if (!$embed): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="fw-bold mb-0">Redirect: <?= e($site['domain']) ?></h4>
    <p class="text-muted mb-0">Satu aturan per domain - domain yang punya Redirect tidak lagi melayani konten aslinya, semua request dialihkan.</p>
  </div>
  <a href="/websites" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
</div>
<?php else: ?>
<?php include __DIR__ . '/partials/flash.php'; ?>
<p class="text-muted small">Satu aturan per domain - domain yang punya Redirect tidak lagi melayani konten aslinya.</p>
<?php endif; ?>

<div class="card stat-card">
  <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
    <span>Aturan Redirect</span>
    <?php if ($canEdit): ?>
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addRedirectModal"><i class="bi bi-plus-lg me-1"></i>Tambah Redirect</button>
    <?php endif; ?>
  </div>
  <div class="card-body p-0">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light"><tr><th>Source</th><th>Target URL</th><th>Status</th><th>URI Params</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
        <?php if (empty($redirects)): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">Belum ada aturan Redirect</td></tr>
        <?php endif; ?>
        <?php foreach ($redirects as $r): ?>
        <tr>
          <td><code><?= e($r['source_domain']) ?></code></td>
          <td><code><?= e($r['target_url']) ?></code></td>
          <td><span class="badge text-bg-light border"><?= (int) $r['status_code'] ?></span></td>
          <td><?= $r['include_uri_params'] ? '<span class="badge text-bg-success">Ya</span>' : '<span class="badge text-bg-secondary">Tidak</span>' ?></td>
          <td class="text-end">
            <?php if ($canEdit): ?>
            <form method="post" class="d-inline" data-confirm="Hapus aturan Redirect untuk <?= e($r['source_domain']) ?>?">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="remove_redirect">
              <input type="hidden" name="redirect_id" value="<?= (int) $r['id'] ?>">
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

<div class="modal fade" id="addRedirectModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Tambah Redirect</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="add_redirect">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">Source (domain)</label>
              <select name="source_domain" class="form-select" required>
                <option value="" disabled selected>Pilih domain...</option>
                <?php foreach ($siteDomains as $sd): ?>
                  <option value="<?= e($sd) ?>"><?= e($sd) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select name="status_code" class="form-select">
                <option value="301" selected>301 Permanent</option>
                <option value="302">302 Temporary</option>
                <option value="307">307 Temporary (strict)</option>
                <option value="308">308 Permanent (strict)</option>
              </select>
            </div>
            <div class="col-md-12">
              <label class="form-label">Target URL</label>
              <input type="url" name="target_url" class="form-control" placeholder="https://tujuan.com" required>
            </div>
            <div class="col-md-12">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="include_uri_params" id="includeUriParams" checked>
                <label class="form-check-label" for="includeUriParams">Include URI Parameters</label>
                <div class="form-text">Aktif: path &amp; query string yang diakses ditambahkan otomatis di belakang Target URL. Nonaktif: selalu redirect persis ke Target URL apa pun path-nya.</div>
              </div>
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

</div>
</div>

<?php include __DIR__ . ($embed ? '/partials/embed_footer.php' : '/partials/footer.php'); ?>
