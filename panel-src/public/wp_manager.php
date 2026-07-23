<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('wp.view');

$user = Auth::user();

// Read-only, so a plain GET link (not a POST form) - matches how
// nodejs_env.php's ?export=1 works, no CSRF token needed for a
// non-mutating action.
$checkedId = (int) ($_GET['check'] ?? 0);
$checkResult = null;
if ($checkedId > 0) {
    try {
        $checkSite = WpManagerService::getSite($checkedId);
        $checkResult = ['site' => $checkSite, 'result' => WpManagerService::checkCoreUpdate($checkSite)];
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
}

$sites = WpManagerService::listSites();

$pageTitle = 'WP Manager';
include __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h4 class="fw-bold mb-0">WP Manager</h4>
    <p class="text-muted mb-0">Kelola WordPress yang sudah terinstal lewat App Installer</p>
  </div>
</div>

<?php if ($checkResult !== null): $r = $checkResult['result']; $s = $checkResult['site']; ?>
<div class="alert <?= $r['update_available'] ? 'alert-warning' : 'alert-success' ?>">
  <?php if ($r['update_available']): ?>
    Update tersedia untuk <strong><?= e($s['domain']) ?></strong>: WordPress <strong><?= e($r['latest_version']) ?></strong> (saat ini <?= e($s['app_version'] ?? 'tidak diketahui') ?>) - buka menu Core situs ini untuk update.
  <?php else: ?>
    <strong><?= e($s['domain']) ?></strong> sudah menggunakan versi terbaru (<?= e($r['latest_version']) ?>).
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card stat-card">
  <div class="card-body p-0">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr><th>Domain</th><th>Versi WordPress</th><th>PHP</th><th class="text-end">Aksi</th></tr>
      </thead>
      <tbody>
      <?php if (empty($sites)): ?>
        <tr><td colspan="4" class="text-center text-muted py-4">Belum ada situs WordPress. Instal lewat menu <a href="/app_installer.php">App Installer</a>.</td></tr>
      <?php endif; ?>
      <?php foreach ($sites as $site): ?>
        <tr>
          <td>
            <a href="http://<?= e($site['domain']) ?>" target="_blank" rel="noopener"><?= e($site['domain']) ?></a>
            <a href="http://<?= e($site['domain']) ?>/wp-admin/" target="_blank" rel="noopener" class="text-muted small ms-1" title="Buka wp-admin"><i class="bi bi-box-arrow-up-right"></i></a>
          </td>
          <td><?= e($site['app_version'] ?? 'tidak diketahui') ?></td>
          <td>PHP <?= e($site['php_version']) ?></td>
          <td class="text-end">
            <a href="/wp_manager.php?check=<?= (int) $site['website_id'] ?>" class="btn btn-sm btn-outline-secondary" title="Cek update WordPress"><i class="bi bi-arrow-repeat"></i></a>
            <div class="btn-group">
              <a href="/wp_manager_core.php?id=<?= (int) $site['website_id'] ?>" class="btn btn-sm btn-outline-secondary" title="Core"><i class="bi bi-gear"></i></a>
              <a href="/wp_manager_plugins.php?id=<?= (int) $site['website_id'] ?>" class="btn btn-sm btn-outline-secondary" title="Plugin"><i class="bi bi-puzzle"></i></a>
              <a href="/wp_manager_themes.php?id=<?= (int) $site['website_id'] ?>" class="btn btn-sm btn-outline-secondary" title="Tema"><i class="bi bi-palette"></i></a>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
