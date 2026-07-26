<?php
/**
 * Shared sub-tab nav for the per-app Node.js settings pages (Umum/Domain/
 * Environment/Logs/Health Check/Backup). Two render modes:
 * - Normal (full page navigation): a horizontal btn-group, same
 *   "separate physical pages + toggle" convention as partials/settings_nav.php.
 * - Embedded ($embed true, see nodejs.php's Settings popup + partials/
 *   embed_header.php): a vertical sidebar instead, since this then lives
 *   inside a modal/iframe rather than a full page - carries &embed=1
 *   forward on every link so navigating tabs stays inside the popup.
 * Expects $app (nodejs_apps row) and $activeNodejsTab; $embed optional.
 */
$nodejsTabs = [
    'general' => ['/nodejs_settings', 'Umum', 'bi-sliders'],
    'domains' => ['/nodejs_domains', 'Domain', 'bi-globe2'],
    'env' => ['/nodejs_env', 'Environment', 'bi-key'],
    'logs' => ['/nodejs_logs', 'Logs', 'bi-file-text'],
    'health' => ['/nodejs_health', 'Health Check', 'bi-heart-pulse'],
    'backup' => ['/nodejs_backup', 'Backup', 'bi-cloud-arrow-down'],
];
$embed = $embed ?? false;
$idQuery = '?id=' . (int) $app['id'] . ($embed ? '&embed=1' : '');
?>
<?php if ($embed): ?>
<div class="nodejs-settings-sidebar flex-shrink-0 me-3" style="width:170px;">
  <div class="list-group">
    <?php foreach ($nodejsTabs as $key => [$href, $label, $icon]): ?>
      <a href="<?= e($href . $idQuery) ?>" class="list-group-item list-group-item-action <?= $activeNodejsTab === $key ? 'active' : '' ?>">
        <i class="bi <?= e($icon) ?> me-2"></i><?= e($label) ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php else: ?>
<div class="btn-group mb-3 flex-wrap">
  <?php foreach ($nodejsTabs as $key => [$href, $label, $icon]): ?>
    <a href="<?= e($href . $idQuery) ?>" class="btn btn-sm <?= $activeNodejsTab === $key ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
