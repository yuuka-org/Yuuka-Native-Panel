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
            isset($_POST['rate_limit_enabled']),
            max(1, (int) ($_POST['rate_limit_rps'] ?? 10)),
            max(0, (int) ($_POST['rate_limit_burst'] ?? 20)),
            max(0, (int) ($_POST['max_conn_total'] ?? 0)),
            max(0, (int) ($_POST['max_conn_per_ip'] ?? 0)),
            max(0, (int) ($_POST['max_bandwidth_kbps'] ?? 0)),
            (bool) $site['hotlink_protection_enabled'],
            (string) $site['hotlink_extensions'],
            (string) ($site['hotlink_allowed_referrers'] ?? ''),
            (int) $site['hotlink_response_code'],
            (bool) $site['hotlink_allow_empty_referer'],
            $user['id']
        );
        flash('success', 'Traffic Control disimpan.');
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/website_traffic_control?id=' . $id . $embedSuffix);
}

$activeWebsiteTab = 'traffic_control';
$canEdit = Rbac::can($user['role'], 'website.create');

$pageTitle = 'Traffic Control - ' . $site['domain'];
include __DIR__ . ($embed ? '/partials/embed_header.php' : '/partials/header.php');
?>
<div class="d-flex">
<?php include __DIR__ . '/partials/website_settings_nav.php'; ?>
<div class="flex-grow-1">

<?php if (!$embed): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="fw-bold mb-0">Traffic Control: <?= e($site['domain']) ?></h4>
    <p class="text-muted mb-0">Rate limit per-IP, berlaku untuk semua domain website ini.</p>
  </div>
  <a href="/websites" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
</div>
<?php else: ?>
<?php include __DIR__ . '/partials/flash.php'; ?>
<p class="text-muted small">Rate limit per-IP, berlaku untuk semua domain website ini.</p>
<?php endif; ?>

<form method="post">
  <?= Csrf::field() ?>
  <fieldset <?= $canEdit ? '' : 'disabled' ?>>
  <div class="card stat-card mb-3">
    <div class="card-header bg-white fw-semibold">Traffic Control (Rate Limit)</div>
    <div class="card-body">
      <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" name="rate_limit_enabled" id="rateLimitEnabled" <?= (bool) $site['rate_limit_enabled'] ? 'checked' : '' ?>>
        <label class="form-check-label" for="rateLimitEnabled">Aktifkan pembatasan request per-IP</label>
      </div>
      <div class="row g-2">
        <div class="col-md-6">
          <label class="form-label">Request/detik per IP</label>
          <input type="number" name="rate_limit_rps" class="form-control" value="<?= (int) $site['rate_limit_rps'] ?>" min="1" max="10000">
        </div>
        <div class="col-md-6">
          <label class="form-label">Burst (lonjakan sesaat yang masih ditoleransi)</label>
          <input type="number" name="rate_limit_burst" class="form-control" value="<?= (int) $site['rate_limit_burst'] ?>" min="0" max="10000">
        </div>
      </div>
    </div>
  </div>

  <div class="card stat-card mb-3">
    <div class="card-header bg-white fw-semibold">Batas Koneksi &amp; Bandwidth</div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Maximum Connections for Website</label>
          <input type="number" name="max_conn_total" class="form-control" value="<?= (int) $site['max_conn_total'] ?>" min="0" max="100000" placeholder="0">
          <div class="form-text">Total koneksi simultan ke seluruh situs ini, dari semua IP sekaligus. 0 = tanpa batas.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Maximum Connections per IP</label>
          <input type="number" name="max_conn_per_ip" class="form-control" value="<?= (int) $site['max_conn_per_ip'] ?>" min="0" max="100000" placeholder="0">
          <div class="form-text">Koneksi simultan maksimum dari satu alamat IP. 0 = tanpa batas.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Maximum Bandwidth per Request (KB/s)</label>
          <input type="number" name="max_bandwidth_kbps" class="form-control" value="<?= (int) $site['max_bandwidth_kbps'] ?>" min="0" max="1000000" placeholder="0">
          <div class="form-text">Kecepatan transfer maksimum per koneksi. 0 = tanpa batas.</div>
        </div>
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
