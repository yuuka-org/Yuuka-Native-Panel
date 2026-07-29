<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('website.view');

$id = (int) ($_GET['id'] ?? 0);
$embed = ($_GET['embed'] ?? '') === '1';
$site = NginxService::find($id);
if ($site === null) {
    flash('error', 'Website tidak ditemukan');
    redirect('/websites');
}

$days = min(90, max(7, (int) ($_GET['days'] ?? 30)));
$traffic = NginxService::trafficDaily($id, $days);
$max = max(1, ...array_column($traffic, 'count'));
$total = array_sum(array_column($traffic, 'count'));
$today = $traffic[count($traffic) - 1]['count'] ?? 0;
$activeWebsiteTab = 'traffic';

$pageTitle = 'Traffic Analysis - ' . $site['domain'];
include __DIR__ . ($embed ? '/partials/embed_header.php' : '/partials/header.php');
?>
<div class="d-flex">
<?php include __DIR__ . '/partials/website_settings_nav.php'; ?>
<div class="flex-grow-1">

<?php if (!$embed): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="fw-bold mb-0">Traffic Analysis: <?= e($site['domain']) ?></h4>
    <p class="text-muted mb-0">Jumlah request per hari, dibaca dari access log Nginx (termasuk yang sudah dirotasi).</p>
  </div>
  <a href="/websites" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
</div>
<?php else: ?>
<?php include __DIR__ . '/partials/flash.php'; ?>
<p class="text-muted small">Jumlah request per hari, dibaca dari access log Nginx (termasuk yang sudah dirotasi).</p>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-md-4">
    <div class="card stat-card h-100"><div class="card-body"><div class="text-muted small">Hari ini</div><div class="fs-3 fw-bold"><?= number_format($today) ?></div></div></div>
  </div>
  <div class="col-md-4">
    <div class="card stat-card h-100"><div class="card-body"><div class="text-muted small">Total <?= $days ?> hari terakhir</div><div class="fs-3 fw-bold"><?= number_format($total) ?></div></div></div>
  </div>
  <div class="col-md-4">
    <div class="card stat-card h-100"><div class="card-body"><div class="text-muted small">Rata-rata/hari</div><div class="fs-3 fw-bold"><?= number_format($total / max(1, $days), 1) ?></div></div></div>
  </div>
</div>

<div class="card stat-card mb-3">
  <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
    <span>Request per Hari</span>
    <div class="btn-group btn-group-sm">
      <?php foreach ([7, 30, 90] as $opt): ?>
        <a href="?id=<?= $id ?><?= $embed ? '&embed=1' : '' ?>&days=<?= $opt ?>" class="btn <?= $days === $opt ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $opt ?> hari</a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card-body">
    <div class="d-flex align-items-end gap-1" style="height:220px; overflow-x:auto;">
      <?php foreach ($traffic as $t):
        $h = max(2, (int) round($t['count'] / $max * 100));
        $label = date('d/m', strtotime($t['date']));
      ?>
      <div class="d-flex flex-column align-items-center justify-content-end h-100" style="min-width:<?= $days > 30 ? '8px' : '20px' ?>; flex:1;" title="<?= e($t['date']) ?>: <?= number_format($t['count']) ?> request">
        <div style="width:100%; height:<?= $h ?>%; background:var(--brand); border-radius:2px 2px 0 0;"></div>
        <?php if ($days <= 30): ?><div class="small text-muted mt-1" style="writing-mode:vertical-rl; font-size:0.65rem;"><?= e($label) ?></div><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

</div>
</div>

<?php include __DIR__ . ($embed ? '/partials/embed_footer.php' : '/partials/footer.php'); ?>
