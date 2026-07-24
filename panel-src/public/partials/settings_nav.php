<?php
/**
 * Shared sub-tab nav for the 5 Settings pages (General/Page/Alarm/
 * Backup & Restore/Migrate) - one file split across settings.php,
 * settings_page.php, settings_alarm.php, settings_backup.php,
 * settings_migrate.php, following the same "separate physical pages +
 * btn-group toggle" convention already used for Website<->Node.js and
 * WP Manager's Core/Plugin/Tema tabs (rather than Bootstrap JS tabs).
 * Expects $user and $activeSettingsTab ('general'|'page'|'alarm'|'backup'|'migrate').
 */
$settingsTabs = [
    'general' => ['/settings.php', 'General'],
    'page' => ['/settings_page.php', 'Page'],
    'alarm' => ['/settings_alarm.php', 'Alarm'],
    'backup' => ['/settings_backup.php', 'Backup & Restore'],
    'migrate' => ['/settings_migrate.php', 'Migrate'],
];
?>
<?php if (Rbac::can($user['role'], 'settings.manage')): ?>
<div class="btn-group mb-3 flex-wrap">
  <?php foreach ($settingsTabs as $key => [$href, $label]): ?>
    <a href="<?= e($href) ?>" class="btn btn-sm <?= $activeSettingsTab === $key ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
