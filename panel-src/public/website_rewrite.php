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
        NginxService::updateAdvanced(
            $id,
            (string) ($site['default_index'] ?? ''),
            trim((string) ($_POST['custom_rewrite_rules'] ?? '')),
            (bool) $site['rate_limit_enabled'],
            (int) $site['rate_limit_rps'],
            (int) $site['rate_limit_burst'],
            (int) $site['max_conn_total'],
            (int) $site['max_conn_per_ip'],
            (int) $site['max_bandwidth_kbps'],
            (bool) $site['hotlink_protection_enabled'],
            (string) $site['hotlink_extensions'],
            (string) ($site['hotlink_allowed_referrers'] ?? ''),
            (int) $site['hotlink_response_code'],
            (bool) $site['hotlink_allow_empty_referer'],
            $user['id']
        );
        flash('success', 'URL Rewrite disimpan.');
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/website_rewrite?id=' . $id . $embedSuffix);
}

$activeWebsiteTab = 'rewrite';
$canEdit = Rbac::can($user['role'], 'website.create');

$pageTitle = 'URL Rewrite - ' . $site['domain'];
include __DIR__ . ($embed ? '/partials/embed_header.php' : '/partials/header.php');
?>
<div class="d-flex">
<?php include __DIR__ . '/partials/website_settings_nav.php'; ?>
<div class="flex-grow-1">

<?php if (!$embed): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="fw-bold mb-0">URL Rewrite: <?= e($site['domain']) ?></h4>
    <p class="text-muted mb-0">Berlaku untuk semua domain website ini.</p>
  </div>
  <a href="/websites" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
</div>
<?php else: ?>
<?php include __DIR__ . '/partials/flash.php'; ?>
<p class="text-muted small">Berlaku untuk semua domain website ini.</p>
<?php endif; ?>

<form method="post">
  <?= Csrf::field() ?>
  <fieldset <?= $canEdit ? '' : 'disabled' ?>>
  <div class="card stat-card mb-3">
    <div class="card-header bg-white fw-semibold">Custom URL Rewrite</div>
    <div class="card-body">
      <div class="mb-0">
        <textarea name="custom_rewrite_rules" class="form-control" rows="6" placeholder="rewrite ^/old-path$ /new-path permanent;"><?= e((string) ($site['custom_rewrite_rules'] ?? '')) ?></textarea>
        <div class="form-text">Baris <code>rewrite</code> Nginx mentah, disisipkan langsung ke konfigurasi situs. Divalidasi lewat <code>nginx -t</code> sebelum diterapkan - kalau salah syntax, perubahan otomatis dibatalkan.</div>
      </div>
    </div>
  </div>
  <?php if ($canEdit): ?>
  <button type="submit" class="btn btn-primary mb-3">Simpan</button>
  <?php endif; ?>
  </fieldset>
</form>

</div>
</div>

<?php include __DIR__ . ($embed ? '/partials/embed_footer.php' : '/partials/footer.php'); ?>
