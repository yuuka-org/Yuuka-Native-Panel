<?php
/**
 * Shared sub-tab nav for per-site Website PHP/Static settings pages.
 * Each concern (Default Index, URL Rewrite, Redirect, Traffic Control,
 * Hotlink Protection, Reverse Proxy, SSL, Response Log) is its own
 * physical page/tab - deliberately NOT bundled into one combined
 * "Advanced" form, so a change to one setting can never accidentally
 * touch another's fields and each has its own focused save action.
 * Exact same two-render-mode pattern as partials/nodejs_settings_nav.php
 * (full-page btn-group vs embedded vertical sidebar inside the Settings
 * popup). Expects $site (websites row) and $activeWebsiteTab; $embed optional.
 */
$websiteTabs = [
    'general' => ['/website_settings', 'Umum', 'bi-sliders'],
    'domains' => ['/website_domains', 'Domain', 'bi-globe2'],
    'ssl' => ['/website_ssl', 'SSL', 'bi-shield-lock'],
    'limit_access' => ['/website_limit_access', 'Limit Access', 'bi-lock'],
    'default_index' => ['/website_default_index', 'Default Index', 'bi-file-earmark-text'],
    'rewrite' => ['/website_rewrite', 'URL Rewrite', 'bi-signpost-split'],
    'redirect' => ['/website_redirect', 'Redirect', 'bi-arrow-return-right'],
    'traffic_control' => ['/website_traffic_control', 'Traffic Control', 'bi-speedometer2'],
    'hotlink' => ['/website_hotlink', 'Hotlink Protection', 'bi-link-45deg'],
    'reverse_proxy' => ['/website_reverse_proxy', 'Reverse Proxy', 'bi-arrow-left-right'],
    'response_log' => ['/website_response_log', 'Response Log', 'bi-file-text'],
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
