<?php
$role = Auth::user()['role'] ?? 'viewer';
$current = basename(currentPath());

$links = [
    ['href' => '/dashboard', 'icon' => 'bi-speedometer2', 'label' => 'Dashboard', 'perm' => 'monitoring.view'],
    // Single entry for both Website PHP and Node.js Apps - they share one
    // sidebar slot with a PHP|Node.js toggle at the top of each page (see
    // websites.php/nodejs). 'match' marks every basename that should
    // keep this entry highlighted, since it no longer maps 1:1 to its own
    // href like every other entry here.
    ['href' => '/websites', 'icon' => 'bi-globe2', 'label' => 'Website', 'perm' => 'website.view',
        'match' => ['websites', 'nodejs', 'nodejs_settings', 'nodejs_domains', 'nodejs_env', 'nodejs_logs', 'nodejs_health', 'nodejs_backup']],
    ['href' => '/app_installer', 'icon' => 'bi-grid-3x3-gap', 'label' => 'App Installer', 'perm' => 'apps.view'],
    ['href' => '/wp_manager', 'icon' => 'bi-wordpress', 'label' => 'WP Manager', 'perm' => 'wp.view',
        'match' => ['wp_manager', 'wp_manager_core', 'wp_manager_plugins', 'wp_manager_themes']],
    ['href' => '/file_manager', 'icon' => 'bi-folder2-open', 'label' => 'File Manager', 'perm' => 'files.view'],
    ['href' => '/databases', 'icon' => 'bi-database', 'label' => 'Database', 'perm' => 'database.view'],
    ['href' => '/domains', 'icon' => 'bi-hdd-network', 'label' => 'Domain', 'perm' => 'domain.manage'],
    ['href' => '/cron', 'icon' => 'bi-clock-history', 'label' => 'Cron Jobs', 'perm' => 'cron.view'],
    ['href' => '/logs', 'icon' => 'bi-file-text', 'label' => 'Log', 'perm' => 'logs.view'],
    ['href' => '/cloudflare', 'icon' => 'bi-cloud', 'label' => 'Cloudflare Tunnel', 'perm' => 'monitoring.view'],
    ['href' => '/system', 'icon' => 'bi-arrow-repeat', 'label' => 'Sistem', 'perm' => 'monitoring.view'],
    ['href' => '/terminal', 'icon' => 'bi-terminal', 'label' => 'Terminal', 'perm' => 'terminal.access'],
    ['href' => '/users', 'icon' => 'bi-people', 'label' => 'Manajemen User', 'perm' => 'users.manage'],
    // Same "single sidebar slot, several physical pages" pattern as the
    // Website entry above - Settings is now 5 sub-tabs (General/Page/
    // Alarm/Backup & Restore/Migrate), each its own file. Backup used to
    // be its own top-level entry; creating one is now a per-item action on
    // Website/Node.js/Database, and reviewing/restoring past backups lives
    // under Settings > Backup & Restore instead.
    ['href' => '/settings', 'icon' => 'bi-sliders', 'label' => 'Pengaturan', 'perm' => 'settings.manage',
        'match' => ['settings', 'settings_page', 'settings_alarm', 'settings_backup', 'settings_migrate']],
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
