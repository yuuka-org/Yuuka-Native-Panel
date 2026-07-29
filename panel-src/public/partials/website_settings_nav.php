<?php
/**
 * Shared sub-tab nav for per-site Website PHP/Static settings pages
 * (Umum/Domain/Traffic & Rewrite/Traffic Analysis/Backup) - exact same
 * two-render-mode pattern as partials/nodejs_settings_nav.php (full-page
 * btn-group vs embedded vertical sidebar inside the Settings popup).
 * Expects $site (websites row) and $activeWebsiteTab; $embed optional.
 */
$websiteTabs = [
    'general' => ['/website_settings', 'Umum', 'bi-sliders'],
    'domains' => ['/website_domains', 'Domain & SSL', 'bi-globe2'],
    'advanced' => ['/website_advanced', 'Traffic & Rewrite', 'bi-signpost-split'],
    'traffic' => ['/website_traffic', 'Traffic Analysis', 'bi-graph-up'],
    'backup' => ['/website_backup', 'Backup', 'bi-cloud-arrow-down'],
];
$embed = $embed ?? false;
$idQuery = '?id=' . (int) $site['id'] . ($embed ? '&embed=1' : '');
?>
<?php if ($embed): ?>
<div class="website-settings-sidebar flex-shrink-0 me-3" style="width:170px;">
  <div class="list-group">
    <?php foreach ($websiteTabs as $key => [$href, $label, $icon]): ?>
      <a href="<?= e($href . $idQuery) ?>" class="list-group-item list-group-item-action <?= $activeWebsiteTab === $key ? 'active' : '' ?>">
        <i class="bi <?= e($icon) ?> me-2"></i><?= e($label) ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php else: ?>
<div class="btn-group mb-3 flex-wrap">
  <?php foreach ($websiteTabs as $key => [$href, $label, $icon]): ?>
    <a href="<?= e($href . $idQuery) ?>" class="btn btn-sm <?= $activeWebsiteTab === $key ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
