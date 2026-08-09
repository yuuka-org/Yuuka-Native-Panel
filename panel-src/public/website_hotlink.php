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
            (string) ($site['custom_rewrite_rules'] ?? ''),
            (bool) $site['rate_limit_enabled'],
            (int) $site['rate_limit_rps'],
            (int) $site['rate_limit_burst'],
            (int) $site['max_conn_total'],
            (int) $site['max_conn_per_ip'],
            (int) $site['max_bandwidth_kbps'],
            isset($_POST['hotlink_enabled']),
            trim((string) ($_POST['hotlink_extensions'] ?? '')),
            trim((string) ($_POST['hotlink_referrers'] ?? '')),
            max(100, min(599, (int) ($_POST['hotlink_response_code'] ?? 403))),
            isset($_POST['hotlink_allow_empty_referer']),
            $user['id']
        );
        flash('success', 'Hotlink Protection disimpan.');
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/website_hotlink?id=' . $id . $embedSuffix);
}

$activeWebsiteTab = 'hotlink';
$canEdit = Rbac::can($user['role'], 'website.create');

$pageTitle = 'Hotlink Protection - ' . $site['domain'];
include __DIR__ . ($embed ? '/partials/embed_header.php' : '/partials/header.php');
?>
<div class="d-flex">
<?php include __DIR__ . '/partials/website_settings_nav.php'; ?>
<div class="flex-grow-1">

<?php if (!$embed): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="fw-bold mb-0">Hotlink Protection: <?= e($site['domain']) ?></h4>
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
    <div class="card-body">
      <div class="mb-3">
        <label class="form-label">URL Suffix</label>
        <input type="text" name="hotlink_extensions" class="form-control tag-chip-source" value="<?= e((string) $site['hotlink_extensions']) ?>" placeholder="jpg,gif,js,css,png">
        <div class="tag-chip-preview mt-2"></div>
        <div class="form-text">Ekstensi file yang dilindungi, pisahkan dengan koma.</div>
      </div>
      <div class="mb-3">
        <label class="form-label">Access Domain</label>
        <textarea name="hotlink_referrers" class="form-control" rows="3" placeholder="cdn-partner.com&#10;*.trusted-partner.com"><?= e((string) ($site['hotlink_allowed_referrers'] ?? '')) ?></textarea>
        <div class="form-text">Domain yang diizinkan hotlink selain domain situs ini sendiri, satu domain per baris.</div>
      </div>
      <div class="mb-3">
        <label class="form-label">Response</label>
        <input type="number" name="hotlink_response_code" class="form-control" style="max-width:150px" value="<?= (int) $site['hotlink_response_code'] ?>" min="100" max="599">
        <div class="form-text">Kode HTTP yang dikembalikan saat hotlink terdeteksi (umumnya 403 Forbidden atau 404 Not Found).</div>
      </div>
      <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" name="hotlink_enabled" id="hotlinkEnabled" <?= (bool) $site['hotlink_protection_enabled'] ? 'checked' : '' ?>>
        <label class="form-check-label" for="hotlinkEnabled">Enable Hotlink Protection</label>
      </div>
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="hotlink_allow_empty_referer" id="hotlinkAllowEmpty" <?= (bool) $site['hotlink_allow_empty_referer'] ? 'checked' : '' ?>>
        <label class="form-check-label" for="hotlinkAllowEmpty">Allow Empty HTTP_REFERER</label>
        <div class="form-text">Izinkan akses langsung/curl tanpa header Referer sama sekali. Matikan untuk perlindungan lebih ketat.</div>
      </div>
    </div>
  </div>
  <?php if ($canEdit): ?>
  <button type="submit" class="btn btn-primary mb-3">Save</button>
  <?php endif; ?>
  </fieldset>
</form>

</div>
</div>

<script src="/assets/js/tag-chip-input.js"></script>
<?php include __DIR__ . ($embed ? '/partials/embed_footer.php' : '/partials/footer.php'); ?>
