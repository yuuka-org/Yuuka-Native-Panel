<?php
/**
 * Shared sub-tab nav for the per-app Node.js settings pages (Umum/Domain/
 * Environment/Logs/Health Check/Backup) - separate physical pages + a
 * btn-group toggle carrying ?id=, same convention as
 * partials/settings_nav.php (Website<->Node.js and WP Manager's tabs use
 * this pattern too, not Bootstrap JS tabs).
 * Expects $app (nodejs_apps row) and $activeNodejsTab.
 */
$nodejsTabs = [
    'general' => ['/nodejs_settings.php', 'Umum'],
    'domains' => ['/nodejs_domains.php', 'Domain'],
    'env' => ['/nodejs_env.php', 'Environment'],
    'logs' => ['/nodejs_logs.php', 'Logs'],
    'health' => ['/nodejs_health.php', 'Health Check'],
    'backup' => ['/nodejs_backup.php', 'Backup'],
];
?>
<div class="btn-group mb-3 flex-wrap">
  <?php foreach ($nodejsTabs as $key => [$href, $label]): ?>
    <a href="<?= e($href) ?>?id=<?= (int) $app['id'] ?>" class="btn btn-sm <?= $activeNodejsTab === $key ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>
