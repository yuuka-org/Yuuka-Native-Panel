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

    try {
        NginxService::updateWebsite(
            $id,
            trim((string) ($_POST['domain'] ?? '')),
            (string) ($_POST['php_version'] ?? ''),
            trim((string) ($_POST['document_root'] ?? '')),
            $user['id']
        );
        flash('success', 'Konfigurasi disimpan - situs Nginx sudah diperbarui.');
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/website_settings?id=' . $id . $embedSuffix);
}

$phpVersions = PhpService::installedVersions();
$activeWebsiteTab = 'general';

$pageTitle = 'Pengaturan - ' . $site['domain'];
include __DIR__ . ($embed ? '/partials/embed_header.php' : '/partials/header.php');
?>
<div class="d-flex">
<?php include __DIR__ . '/partials/website_settings_nav.php'; ?>
<div class="flex-grow-1">

<?php if (!$embed): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="fw-bold mb-0">Pengaturan: <?= e($site['domain']) ?></h4>
    <p class="text-muted mb-0">Mengubah Domain di sini TIDAK memindahkan file di server - Document Root diedit terpisah.</p>
  </div>
  <a href="/websites" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
</div>
<?php else: ?>
<?php include __DIR__ . '/partials/flash.php'; ?>
<p class="text-muted small">Mengubah Domain di sini TIDAK memindahkan file di server - Document Root diedit terpisah.</p>
<?php endif; ?>

<div class="card stat-card">
  <div class="card-body">
    <form method="post">
      <?= Csrf::field() ?>
      <fieldset <?= Rbac::can($user['role'], 'website.create') ? '' : 'disabled' ?>>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Domain</label>
          <input type="text" name="domain" class="form-control" value="<?= e($site['domain']) ?>" required pattern="^[a-zA-Z0-9.\-]+$">
          <?php if ((bool) $site['ssl_enabled']): ?>
          <div class="form-text text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Mengganti domain akan menonaktifkan SSL yang aktif sekarang - terbitkan ulang lewat tab Domain &amp; SSL setelah menyimpan.</div>
          <?php endif; ?>
        </div>
        <div class="col-md-3">
          <label class="form-label">Versi PHP</label>
          <select name="php_version" class="form-select">
            <?php foreach ($phpVersions as $v): ?>
              <option value="<?= e($v) ?>" <?= $site['php_version'] === $v ? 'selected' : '' ?>>PHP <?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-12">
          <label class="form-label">Document Root</label>
          <input type="text" name="document_root" class="form-control" value="<?= e($site['document_root']) ?>" required>
          <div class="form-text">Harus berada di dalam <code>/var/www/</code>.</div>
        </div>
      </div>
      <?php if (Rbac::can($user['role'], 'website.create')): ?>
      <button type="submit" class="btn btn-primary mt-3">Simpan</button>
      <?php endif; ?>
      </fieldset>
    </form>
  </div>
</div>

</div>
</div>

<?php include __DIR__ . ($embed ? '/partials/embed_footer.php' : '/partials/footer.php'); ?>
