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

// SVG area chart - width/height are viewBox units (scales to container via
// CSS, not fixed pixels), padding keeps the polyline off the axes so
// points at min/max value are never clipped.
$svgW = 900;
$svgH = 260;
$padL = 40;
$padR = 16;
$padT = 16;
$padB = 30;
$plotW = $svgW - $padL - $padR;
$plotH = $svgH - $padT - $padB;
$count = count($traffic);

$points = [];
foreach ($traffic as $i => $t) {
    $x = $count > 1 ? $padL + ($i / ($count - 1)) * $plotW : $padL + $plotW / 2;
    $y = $padT + $plotH - (($t['count'] / $max) * $plotH);
    $points[] = [round($x, 1), round($y, 1)];
}
$polyline = implode(' ', array_map(static fn(array $p): string => "{$p[0]},{$p[1]}", $points));
$areaPolygon = $polyline;
if (!empty($points)) {
    $baselineY = $padT + $plotH;
    $areaPolygon = "{$padL},{$baselineY} {$polyline} " . end($points)[0] . ",{$baselineY}";
}

// At most 8 x-axis labels regardless of day range, evenly spaced - the
// old version rotated a label under EVERY bar (writing-mode:vertical-rl),
// which turned into an unreadable wall of sideways text past ~15 days.
$labelStep = $count > 8 ? (int) ceil($count / 8) : 1;
$gridLines = [0, 0.25, 0.5, 0.75, 1];

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
    <?php if (empty($traffic) || $total === 0): ?>
      <p class="text-muted text-center py-5 mb-0">Belum ada traffic tercatat pada rentang ini.</p>
    <?php else: ?>
    <svg viewBox="0 0 <?= $svgW ?> <?= $svgH ?>" class="w-100" style="height:260px;" preserveAspectRatio="none">
      <defs>
        <linearGradient id="trafficFill" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="var(--brand)" stop-opacity="0.35"/>
          <stop offset="100%" stop-color="var(--brand)" stop-opacity="0.02"/>
        </linearGradient>
      </defs>
      <?php foreach ($gridLines as $g): $gy = round($padT + $plotH * (1 - $g), 1); ?>
        <line x1="<?= $padL ?>" y1="<?= $gy ?>" x2="<?= $svgW - $padR ?>" y2="<?= $gy ?>" stroke="currentColor" stroke-opacity="0.08" stroke-width="1"/>
        <text x="<?= $padL - 8 ?>" y="<?= $gy + 4 ?>" text-anchor="end" font-size="10" fill="currentColor" fill-opacity="0.55"><?= number_format((int) round($max * $g)) ?></text>
      <?php endforeach; ?>
      <polygon points="<?= e($areaPolygon) ?>" fill="url(#trafficFill)"/>
      <polyline points="<?= e($polyline) ?>" fill="none" stroke="var(--brand)" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
      <?php foreach ($points as $i => $p): ?>
        <?php if ($i % $labelStep !== 0 && $i !== $count - 1) continue; ?>
        <circle cx="<?= $p[0] ?>" cy="<?= $p[1] ?>" r="3" fill="var(--brand)">
          <title><?= e($traffic[$i]['date']) ?>: <?= number_format($traffic[$i]['count']) ?> request</title>
        </circle>
        <text x="<?= $p[0] ?>" y="<?= $svgH - 8 ?>" text-anchor="middle" font-size="10" fill="currentColor" fill-opacity="0.6"><?= e(date('d/m', strtotime($traffic[$i]['date']))) ?></text>
      <?php endforeach; ?>
    </svg>
    <?php endif; ?>
  </div>
</div>

</div>
</div>

<?php include __DIR__ . ($embed ? '/partials/embed_footer.php' : '/partials/footer.php'); ?>
