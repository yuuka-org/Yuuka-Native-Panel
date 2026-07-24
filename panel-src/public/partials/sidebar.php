<?php
$role = Auth::user()['role'] ?? 'viewer';
$current = basename(currentPath());

$links = [
    ['href' => '/dashboard.php', 'icon' => 'bi-speedometer2', 'label' => 'Dashboard', 'perm' => 'monitoring.view'],
    // Single entry for both Website PHP and Node.js Apps - they share one
    // sidebar slot with a PHP|Node.js toggle at the top of each page (see
    // websites.php/nodejs.php). 'match' marks every basename that should
    // keep this entry highlighted, since it no longer maps 1:1 to its own
    // href like every other entry here.
    ['href' => '/websites.php', 'icon' => 'bi-globe2', 'label' => 'Website', 'perm' => 'website.view',
        'match' => ['websites.php', 'nodejs.php', 'nodejs_env.php', 'nodejs_logs.php', 'nodejs_health.php']],
    ['href' => '/app_installer.php', 'icon' => 'bi-grid-3x3-gap', 'label' => 'App Installer', 'perm' => 'apps.view'],
    ['href' => '/wp_manager.php', 'icon' => 'bi-wordpress', 'label' => 'WP Manager', 'perm' => 'wp.view',
        'match' => ['wp_manager.php', 'wp_manager_core.php', 'wp_manager_plugins.php', 'wp_manager_themes.php']],
    ['href' => '/file_manager.php', 'icon' => 'bi-folder2-open', 'label' => 'File Manager', 'perm' => 'files.view'],
    ['href' => '/databases.php', 'icon' => 'bi-database', 'label' => 'Database', 'perm' => 'database.view'],
    ['href' => '/domains.php', 'icon' => 'bi-hdd-network', 'label' => 'Domain', 'perm' => 'domain.manage'],
    ['href' => '/cron.php', 'icon' => 'bi-clock-history', 'label' => 'Cron Jobs', 'perm' => 'cron.view'],
    ['href' => '/logs.php', 'icon' => 'bi-file-text', 'label' => 'Log', 'perm' => 'logs.view'],
    ['href' => '/cloudflare.php', 'icon' => 'bi-cloud', 'label' => 'Cloudflare Tunnel', 'perm' => 'monitoring.view'],
    ['href' => '/system.php', 'icon' => 'bi-arrow-repeat', 'label' => 'Sistem', 'perm' => 'monitoring.view'],
    ['href' => '/terminal.php', 'icon' => 'bi-terminal', 'label' => 'Terminal', 'perm' => 'terminal.access'],
    ['href' => '/users.php', 'icon' => 'bi-people', 'label' => 'Manajemen User', 'perm' => 'users.manage'],
    // Same "single sidebar slot, several physical pages" pattern as the
    // Website entry above - Settings is now 5 sub-tabs (General/Page/
    // Alarm/Backup & Restore/Migrate), each its own file. Backup used to
    // be its own top-level entry; creating one is now a per-item action on
    // Website/Node.js/Database, and reviewing/restoring past backups lives
    // under Settings > Backup & Restore instead.
    ['href' => '/settings.php', 'icon' => 'bi-sliders', 'label' => 'Pengaturan', 'perm' => 'settings.manage',
        'match' => ['settings.php', 'settings_page.php', 'settings_alarm.php', 'settings_backup.php', 'settings_migrate.php']],
];
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
