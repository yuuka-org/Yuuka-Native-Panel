<?php
$role = Auth::user()['role'] ?? 'viewer';
$current = basename(currentPath());

$links = [
    ['href' => '/dashboard.php', 'icon' => 'bi-speedometer2', 'label' => 'Dashboard', 'perm' => 'monitoring.view'],
    ['href' => '/websites.php', 'icon' => 'bi-globe2', 'label' => 'Website PHP', 'perm' => 'website.view'],
    ['href' => '/app_installer.php', 'icon' => 'bi-grid-3x3-gap', 'label' => 'App Installer', 'perm' => 'apps.view'],
    ['href' => '/wp_manager.php', 'icon' => 'bi-wordpress', 'label' => 'WP Manager', 'perm' => 'wp.view'],
    ['href' => '/nodejs.php', 'icon' => 'bi-diagram-3', 'label' => 'Node.js Apps', 'perm' => 'nodejs.view'],
    ['href' => '/file_manager.php', 'icon' => 'bi-folder2-open', 'label' => 'File Manager', 'perm' => 'files.view'],
    ['href' => '/databases.php', 'icon' => 'bi-database', 'label' => 'Database', 'perm' => 'database.view'],
    ['href' => '/domains.php', 'icon' => 'bi-hdd-network', 'label' => 'Domain', 'perm' => 'domain.manage'],
    ['href' => '/cron.php', 'icon' => 'bi-clock-history', 'label' => 'Cron Jobs', 'perm' => 'cron.view'],
    ['href' => '/backups.php', 'icon' => 'bi-cloud-arrow-down', 'label' => 'Backup', 'perm' => 'backup.view'],
    ['href' => '/logs.php', 'icon' => 'bi-file-text', 'label' => 'Log', 'perm' => 'logs.view'],
    ['href' => '/cloudflare.php', 'icon' => 'bi-cloud', 'label' => 'Cloudflare Tunnel', 'perm' => 'monitoring.view'],
    ['href' => '/users.php', 'icon' => 'bi-people', 'label' => 'Manajemen User', 'perm' => 'users.manage'],
    ['href' => '/settings.php', 'icon' => 'bi-sliders', 'label' => 'Pengaturan', 'perm' => 'settings.manage'],
];
?>
<ul class="sidebar-nav">
<?php foreach ($links as $link): ?>
  <?php if (!Rbac::can($role, $link['perm'])) continue; ?>
  <li>
    <a href="<?= e($link['href']) ?>" class="<?= $current === basename($link['href']) ? 'active' : '' ?>">
      <i class="bi <?= e($link['icon']) ?>"></i>
      <span><?= e($link['label']) ?></span>
    </a>
  </li>
<?php endforeach; ?>
</ul>
