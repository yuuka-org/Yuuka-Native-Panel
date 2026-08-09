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
            trim((string) ($_POST['default_index'] ?? '')),
            (string) ($site['custom_rewrite_rules'] ?? ''),
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
        flash('success', 'Default Index disimpan.');
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/website_default_index?id=' . $id . $embedSuffix);
}

$activeWebsiteTab = 'default_index';
$canEdit = Rbac::can($user['role'], 'website.create');

$pageTitle = 'Default Index - ' . $site['domain'];
include __DIR__ . ($embed ? '/partials/embed_header.php' : '/partials/header.php');
?>
<div class="d-flex">
<?php include __DIR__ . '/partials/website_settings_nav.php'; ?>
<div class="flex-grow-1">

<?php if (!$embed): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="fw-bold mb-0">Default Index: <?= e($site['domain']) ?></h4>
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
    <div class="card-header bg-white fw-semibold">Default Index</div>
    <div class="card-body">
      <div class="mb-0">
        <label class="form-label">Default Index</label>
        <input type="text" name="default_index" class="form-control" value="<?= e((string) ($site['default_index'] ?? '')) ?>" placeholder="index.php index.html">
        <div class="form-text">Kosongkan untuk default (<code>index.php index.html</code>). Pisahkan dengan spasi kalau lebih dari satu.</div>
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
