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
    $action = $_POST['action'] ?? 'save';

    try {
        if ($action === 'add_proxy') {
            NginxService::addReverseProxy($id, trim((string) ($_POST['path_prefix'] ?? '')), trim((string) ($_POST['target_url'] ?? '')), $user['id']);
            flash('success', 'Reverse Proxy ditambahkan.');
        } elseif ($action === 'remove_proxy') {
            NginxService::removeReverseProxy($id, (int) ($_POST['proxy_id'] ?? 0), $user['id']);
            flash('success', 'Reverse Proxy dihapus.');
        } else {
            NginxService::updateAdvanced(
                $id,
                trim((string) ($_POST['default_index'] ?? '')),
                trim((string) ($_POST['custom_rewrite_rules'] ?? '')),
                isset($_POST['redirect_enabled']),
                trim((string) ($_POST['redirect_target'] ?? '')),
                isset($_POST['rate_limit_enabled']),
                max(1, (int) ($_POST['rate_limit_rps'] ?? 10)),
                max(0, (int) ($_POST['rate_limit_burst'] ?? 20)),
                isset($_POST['hotlink_enabled']),
                trim((string) ($_POST['hotlink_extensions'] ?? '')),
                trim((string) ($_POST['hotlink_referrers'] ?? '')),
                $user['id']
            );
            flash('success', 'Pengaturan lanjutan disimpan - situs Nginx sudah diperbarui.');
        }
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/website_advanced?id=' . $id . $embedSuffix);
}

$proxies = NginxService::listReverseProxies($id);
$activeWebsiteTab = 'advanced';
$canEdit = Rbac::can($user['role'], 'website.create');

$pageTitle = 'Traffic & Rewrite - ' . $site['domain'];
include __DIR__ . ($embed ? '/partials/embed_header.php' : '/partials/header.php');
?>
<div class="d-flex">
<?php include __DIR__ . '/partials/website_settings_nav.php'; ?>
<div class="flex-grow-1">

<?php if (!$embed): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="fw-bold mb-0">Traffic &amp; Rewrite: <?= e($site['domain']) ?></h4>
    <p class="text-muted mb-0">Berlaku untuk semua domain website ini.</p>
  </div>
  <a href="/websites" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
</div>
<?php else: ?>
<?php include __DIR__ . '/partials/flash.php'; ?>
<p class="text-muted small">Berlaku untuk semua domain website ini.</p>
<?php endif; ?>

<?php if ((bool) $site['redirect_enabled']): ?>
<div class="alert alert-warning py-2 small"><i class="bi bi-exclamation-triangle me-1"></i>Redirect sedang aktif - selama aktif, Default Index/URL Rewrite/Reverse Proxy di bawah tidak akan pernah tercapai (semua request langsung dialihkan).</div>
<?php endif; ?>

<form method="post">
  <?= Csrf::field() ?>
  <input type="hidden" name="action" value="save">
  <fieldset <?= $canEdit ? '' : 'disabled' ?>>

  <div class="card stat-card mb-3">
    <div class="card-header bg-white fw-semibold">Default Index &amp; URL Rewrite</div>
    <div class="card-body">
      <div class="mb-3">
        <label class="form-label">Default Index</label>
        <input type="text" name="default_index" class="form-control" value="<?= e((string) ($site['default_index'] ?? '')) ?>" placeholder="index.php index.html">
        <div class="form-text">Kosongkan untuk default (<code>index.php index.html</code>). Pisahkan dengan spasi kalau lebih dari satu.</div>
      </div>
      <div class="mb-0">
        <label class="form-label">Custom URL Rewrite</label>
        <textarea name="custom_rewrite_rules" class="form-control" rows="4" placeholder="rewrite ^/old-path$ /new-path permanent;"><?= e((string) ($site['custom_rewrite_rules'] ?? '')) ?></textarea>
        <div class="form-text">Baris <code>rewrite</code> Nginx mentah, disisipkan langsung ke konfigurasi situs. Divalidasi lewat <code>nginx -t</code> sebelum diterapkan - kalau salah syntax, perubahan otomatis dibatalkan.</div>
      </div>
    </div>
  </div>

  <div class="card stat-card mb-3">
    <div class="card-header bg-white fw-semibold">Redirect</div>
    <div class="card-body">
      <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" name="redirect_enabled" id="redirectEnabled" <?= (bool) $site['redirect_enabled'] ? 'checked' : '' ?>>
        <label class="form-check-label" for="redirectEnabled">Aktifkan - alihkan SELURUH website ini</label>
      </div>
      <input type="url" name="redirect_target" class="form-control" value="<?= e((string) ($site['redirect_target'] ?? '')) ?>" placeholder="https://tujuan.com">
      <div class="form-text">Path &amp; query string request asli akan ditambahkan otomatis di belakang URL ini.</div>
    </div>
  </div>

  <div class="card stat-card mb-3">
    <div class="card-header bg-white fw-semibold">Traffic Control (Rate Limit)</div>
    <div class="card-body">
      <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" name="rate_limit_enabled" id="rateLimitEnabled" <?= (bool) $site['rate_limit_enabled'] ? 'checked' : '' ?>>
        <label class="form-check-label" for="rateLimitEnabled">Aktifkan pembatasan request per-IP</label>
      </div>
      <div class="row g-2">
        <div class="col-md-4">
          <label class="form-label">Request/detik per IP</label>
          <input type="number" name="rate_limit_rps" class="form-control" value="<?= (int) $site['rate_limit_rps'] ?>" min="1" max="10000">
        </div>
        <div class="col-md-4">
          <label class="form-label">Burst (lonjakan sesaat yang masih ditoleransi)</label>
          <input type="number" name="rate_limit_burst" class="form-control" value="<?= (int) $site['rate_limit_burst'] ?>" min="0" max="10000">
        </div>
      </div>
    </div>
  </div>

  <div class="card stat-card mb-3">
    <div class="card-header bg-white fw-semibold">Hotlink Protection</div>
    <div class="card-body">
      <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" name="hotlink_enabled" id="hotlinkEnabled" <?= (bool) $site['hotlink_protection_enabled'] ? 'checked' : '' ?>>
        <label class="form-check-label" for="hotlinkEnabled">Aktifkan - blokir embed langsung dari domain lain</label>
      </div>
      <div class="mb-2">
        <label class="form-label">Ekstensi file yang dilindungi</label>
        <input type="text" name="hotlink_extensions" class="form-control" value="<?= e((string) $site['hotlink_extensions']) ?>" placeholder="jpg|jpeg|png|gif|webp|svg|mp4|mp3|css|js">
        <div class="form-text">Dipisah tanda <code>|</code>, tanpa titik.</div>
      </div>
      <div class="mb-0">
        <label class="form-label">Domain yang diizinkan hotlink (selain domain sendiri)</label>
        <textarea name="hotlink_referrers" class="form-control" rows="3" placeholder="cdn-partner.com&#10;*.trusted-partner.com"><?= e((string) ($site['hotlink_allowed_referrers'] ?? '')) ?></textarea>
        <div class="form-text">Satu domain per baris. Request tanpa header Referer (akses langsung/curl) selalu diizinkan.</div>
      </div>
    </div>
  </div>

  <?php if ($canEdit): ?>
  <button type="submit" class="btn btn-primary mb-3">Simpan Semua</button>
  <?php endif; ?>
  </fieldset>
</form>

<div class="card stat-card mb-3">
  <div class="card-header bg-white fw-semibold">Reverse Proxy</div>
  <div class="card-body">
    <?php if (empty($proxies)): ?>
      <p class="text-muted small mb-3">Belum ada aturan Reverse Proxy.</p>
    <?php else: ?>
    <table class="table table-sm align-middle mb-3">
      <thead class="table-light"><tr><th>Path</th><th>Target</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
        <?php foreach ($proxies as $p): ?>
        <tr>
          <td><code><?= e($p['path_prefix']) ?></code></td>
          <td><code><?= e($p['target_url']) ?></code></td>
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
    <?php endif; ?>
    <?php if ($canEdit): ?>
    <form method="post" class="d-flex gap-2 flex-wrap">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="add_proxy">
      <input type="text" name="path_prefix" class="form-control" style="max-width:200px" placeholder="/api" required pattern="^/.*">
      <input type="url" name="target_url" class="form-control" style="max-width:320px" placeholder="http://127.0.0.1:4000" required>
      <button class="btn btn-primary text-nowrap">Tambah</button>
    </form>
    <?php endif; ?>
  </div>
</div>

</div>
</div>

<?php include __DIR__ . ($embed ? '/partials/embed_footer.php' : '/partials/footer.php'); ?>
