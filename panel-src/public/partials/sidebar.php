<?php
$role = Auth::user()['role'] ?? 'viewer';
$current = basename(currentPath());

$links = [
    ['href' => '/dashboard', 'icon' => 'bi-speedometer2', 'label' => t('sidebar.dashboard'), 'perm' => 'monitoring.view'],
    // Single entry for both Website PHP and Node.js Apps - they share one
    // sidebar slot with a PHP|Node.js toggle at the top of each page (see
    // websites.php/nodejs). 'match' marks every basename that should
    // keep this entry highlighted, since it no longer maps 1:1 to its own
    // href like every other entry here.
    ['href' => '/websites', 'icon' => 'bi-globe2', 'label' => t('sidebar.website'), 'perm' => 'website.view',
        'match' => ['websites', 'nodejs', 'nodejs_settings', 'nodejs_domains', 'nodejs_env', 'nodejs_logs', 'nodejs_health', 'nodejs_backup']],
    ['href' => '/app_installer', 'icon' => 'bi-grid-3x3-gap', 'label' => t('sidebar.app_installer'), 'perm' => 'apps.view'],
    ['href' => '/wp_manager', 'icon' => 'bi-wordpress', 'label' => t('sidebar.wp_manager'), 'perm' => 'wp.view',
        'match' => ['wp_manager', 'wp_manager_core', 'wp_manager_plugins', 'wp_manager_themes']],
    ['href' => '/file_manager', 'icon' => 'bi-folder2-open', 'label' => t('sidebar.file_manager'), 'perm' => 'files.view'],
    ['href' => '/databases', 'icon' => 'bi-database', 'label' => t('sidebar.database'), 'perm' => 'database.view'],
    ['href' => '/domains', 'icon' => 'bi-hdd-network', 'label' => t('sidebar.domain'), 'perm' => 'domain.manage'],
    ['href' => '/cron', 'icon' => 'bi-clock-history', 'label' => t('sidebar.cron_jobs'), 'perm' => 'cron.view'],
    ['href' => '/logs', 'icon' => 'bi-file-text', 'label' => t('sidebar.log'), 'perm' => 'logs.view'],
    ['href' => '/cloudflare', 'icon' => 'bi-cloud', 'label' => t('sidebar.cloudflare_tunnel'), 'perm' => 'monitoring.view'],
    ['href' => '/system', 'icon' => 'bi-arrow-repeat', 'label' => t('sidebar.system'), 'perm' => 'monitoring.view'],
    ['href' => '/terminal', 'icon' => 'bi-terminal', 'label' => t('sidebar.terminal'), 'perm' => 'terminal.access'],
    ['href' => '/users', 'icon' => 'bi-people', 'label' => t('sidebar.user_management'), 'perm' => 'users.manage'],
    // Same "single sidebar slot, several physical pages" pattern as the
    // Website entry above - Settings is now 5 sub-tabs (General/Page/
    // Alarm/Backup & Restore/Migrate), each its own file. Backup used to
    // be its own top-level entry; creating one is now a per-item action on
    // Website/Node.js/Database, and reviewing/restoring past backups lives
    // under Settings > Backup & Restore instead.
    ['href' => '/settings', 'icon' => 'bi-sliders', 'label' => t('sidebar.settings'), 'perm' => 'settings.manage',
        'match' => ['settings', 'settings_page', 'settings_alarm', 'settings_backup', 'settings_migrate']],
    ['href' => '/plugins', 'icon' => 'bi-puzzle', 'label' => t('sidebar.plugin'), 'perm' => 'plugin.manage'],
];

// Every enabled plugin's own manifest-declared menu entries - appended
// after the core links above, same permission ('plugin.manage', admin-
// only - see PluginService's class docblock on why there's no per-plugin
// permission). currentPath() strips query string, so a query-routed
// /plugin.php?slug=X&route=Y page can't be told apart from any OTHER
// plugin's page by basename alone - every plugin link just highlights
// whenever ANY plugin page is open, which is an acceptable trade-off for
// how rarely two plugins would even be open in the same sidebar session.
if (Rbac::can($role, 'plugin.manage')) {
    foreach (PluginService::menuItems() as $item) {
        $links[] = [
            'href' => '/plugin.php?slug=' . urlencode($item['slug']) . '&route=' . urlencode($item['route']),
            'icon' => $item['icon'],
            'label' => $item['label'],
            'perm' => 'plugin.manage',
            'match' => ['plugin.php'],
        ];
    }
}
?>
<ul class="sidebar-nav">
<?php foreach ($links as $link): ?>
  <?php if (!Rbac::can($role, $link['perm'])) continue; ?>
  <?php $matches = $link['match'] ?? [basename($link['href'])]; ?>
  <li>
    <a href="<?= e($link['href']) ?>" class="<?= in_array($current, $matches, true) ? 'active' : '' ?>" title="<?= e($link['label']) ?>">
      <i class="bi <?= e($link['icon']) ?>"></i>
      <span><?= e($link['label']) ?></span>
    </a>
  </li>
<?php endforeach; ?>
</ul>
