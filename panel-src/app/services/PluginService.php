<?php
declare(strict_types=1);

/**
 * Plugin system. Installed plugin CODE never lives in this repo or this
 * database - only metadata (enabled/disabled, who installed it, when)
 * lives in the `plugins` table; the actual files sit on disk under
 * PLUGIN_DIR, written there by panel-exec.sh's plugin-install-zip/git and
 * plugin-remove operations (see that file's comment on PLUGIN_DIR for why
 * it's a sibling of PANEL_ROOT, not nested inside it).
 *
 * Trust model (explicit operator choice over a sandboxed alternative):
 * an ENABLED plugin's own bin/*.sh scripts run with FULL ROOT privilege
 * via runScript()/panel-exec.sh's plugin-exec dispatch. Installing a
 * plugin is equivalent to granting it root on this server - there is no
 * code-level sandbox between one plugin and another, or between a
 * plugin and the panel itself (all PHP here runs in the same PHP-FPM
 * pool). Plugin management AND every plugin page are therefore gated to
 * admin-only ('plugin.manage' in Rbac), regardless of what permission an
 * individual plugin's own manifest might suggest.
 */
final class PluginService
{
    private const PLUGIN_DIR = '/opt/server-panel-plugins';

    /**
     * @return array<int,array{slug:string, manifest:array<string,mixed>|null, is_enabled:bool, installed_at:?string, missing:bool}>
     */
    public static function listInstalled(): array
    {
        $dbBySlug = self::dbRowsBySlug();
        $manifests = self::scanManifests();
        $out = [];

        foreach ($manifests as $slug => $manifest) {
            $db = $dbBySlug[$slug] ?? null;
            $out[] = [
                'slug' => $slug,
                'manifest' => $manifest,
                'is_enabled' => $db !== null && (bool) $db['is_enabled'],
                'installed_at' => $db['installed_at'] ?? null,
                'missing' => false,
            ];
            unset($dbBySlug[$slug]);
        }

        // DB rows whose plugin folder no longer exists (deleted outside
        // the panel, interrupted uninstall) - surfaced instead of hidden,
        // so a stale "enabled" row pointing at nothing doesn't silently
        // linger forever.
        foreach ($dbBySlug as $slug => $db) {
            $out[] = [
                'slug' => $slug,
                'manifest' => null,
                'is_enabled' => (bool) $db['is_enabled'],
                'installed_at' => $db['installed_at'],
                'missing' => true,
            ];
        }

        usort($out, static fn(array $a, array $b): int => strcmp($a['slug'], $b['slug']));
        return $out;
    }

    public static function find(string $slug): ?array
    {
        foreach (self::listInstalled() as $p) {
            if ($p['slug'] === $slug) {
                return $p;
            }
        }
        return null;
    }

    /** @return array<string,array<string,mixed>> slug => decoded plugin.json */
    private static function scanManifests(): array
    {
        $out = [];
        if (!is_dir(self::PLUGIN_DIR)) {
            return $out;
        }
        foreach (scandir(self::PLUGIN_DIR) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !Validator::pluginSlug($entry)) {
                continue;
            }
            $manifestPath = self::PLUGIN_DIR . "/{$entry}/plugin.json";
            if (!is_file($manifestPath)) {
                continue;
            }
            $decoded = json_decode((string) file_get_contents($manifestPath), true);
            if (is_array($decoded)) {
                $out[$entry] = $decoded;
            }
        }
        return $out;
    }

    /** @return array<string,array<string,mixed>> slug => plugins row */
    private static function dbRowsBySlug(): array
    {
        $out = [];
        foreach (Database::app()->query('SELECT * FROM plugins')->fetchAll() as $row) {
            $out[$row['slug']] = $row;
        }
        return $out;
    }

    public static function installFromZip(string $zipContent, ?int $userId): array
    {
        $result = Executor::run('plugin-install-zip', [], $zipContent, 60);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal menginstall plugin: ' . $result['output']);
        }
        return self::finishInstall($result['output'], $userId);
    }

    public static function installFromGit(string $repoUrl, ?string $branch, ?int $userId): array
    {
        if (!Validator::gitUrl($repoUrl)) {
            throw new InvalidArgumentException('URL repository tidak valid (harus https://...)');
        }
        if ($branch !== null && $branch !== '' && !Validator::gitBranch($branch)) {
            throw new InvalidArgumentException('Nama branch tidak valid');
        }
        $result = Executor::run('plugin-install-git', [$repoUrl, (string) $branch], null, 60);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal menginstall plugin: ' . $result['output']);
        }
        return self::finishInstall($result['output'], $userId);
    }

    private static function finishInstall(string $execOutput, ?int $userId): array
    {
        if (!preg_match('/^SLUG:(.+)$/m', $execOutput, $m)) {
            throw new RuntimeException('Plugin terpasang tapi slug tidak terbaca dari output - cek manual.');
        }
        $slug = trim($m[1]);
        $plugin = self::find($slug);
        if ($plugin === null || $plugin['manifest'] === null) {
            throw new RuntimeException('Plugin terpasang tapi manifest tidak terbaca.');
        }
        $manifest = $plugin['manifest'];

        $pdo = Database::app();
        $stmt = $pdo->prepare(
            'INSERT INTO plugins (slug, name, version, is_enabled, installed_by)
             VALUES (:slug, :name, :version, 0, :uid)
             ON DUPLICATE KEY UPDATE name = VALUES(name), version = VALUES(version)'
        );
        $stmt->execute([
            'slug' => $slug,
            'name' => (string) ($manifest['name'] ?? $slug),
            'version' => (string) ($manifest['version'] ?? '0.0.0'),
            'uid' => $userId,
        ]);

        ActivityLog::record($userId, 'plugin.install', "Plugin diinstall: {$slug} (belum aktif)");
        return self::find($slug) ?? [];
    }

    public static function enable(string $slug, ?int $userId): void
    {
        $plugin = self::find($slug);
        if ($plugin === null || $plugin['manifest'] === null) {
            throw new InvalidArgumentException('Plugin tidak ditemukan');
        }

        $pdo = Database::app();
        $exists = $pdo->prepare('SELECT COUNT(*) FROM plugins WHERE slug = :s');
        $exists->execute(['s' => $slug]);
        if ((int) $exists->fetchColumn() === 0) {
            $pdo->prepare('INSERT INTO plugins (slug, name, version, is_enabled, installed_by) VALUES (:s, :n, :v, 1, :uid)')
                ->execute(['s' => $slug, 'n' => (string) ($plugin['manifest']['name'] ?? $slug), 'v' => (string) ($plugin['manifest']['version'] ?? '0.0.0'), 'uid' => $userId]);
        } else {
            $pdo->prepare('UPDATE plugins SET is_enabled = 1 WHERE slug = :s')->execute(['s' => $slug]);
        }

        self::syncCron($slug, $plugin['manifest']);
        ActivityLog::record($userId, 'plugin.enable', "Plugin diaktifkan: {$slug}");
    }

    public static function disable(string $slug, ?int $userId): void
    {
        Database::app()->prepare('UPDATE plugins SET is_enabled = 0 WHERE slug = :s')->execute(['s' => $slug]);
        Executor::run('cron-delete', ["plugin-{$slug}"], null, 10);
        ActivityLog::record($userId, 'plugin.disable', "Plugin dinonaktifkan: {$slug}");
    }

    public static function uninstall(string $slug, ?int $userId): void
    {
        self::disable($slug, $userId);
        $result = Executor::run('plugin-remove', [$slug], null, 30);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal menghapus file plugin: ' . $result['output']);
        }
        Database::app()->prepare('DELETE FROM plugins WHERE slug = :s')->execute(['s' => $slug]);
        ActivityLog::record($userId, 'plugin.uninstall', "Plugin dihapus: {$slug}");
    }

    /**
     * Writes/removes /etc/cron.d/plugin-<slug> from the manifest's own
     * `cron` entries - each runs as root directly (not through the
     * plugin-exec bridge, cron.d already specifies its own user and is
     * root-controlled) executing PLUGIN_DIR/<slug>/cron/<script> as-is.
     * Re-synced every enable() so editing a plugin's manifest and
     * re-enabling picks up schedule changes.
     */
    private static function syncCron(string $slug, array $manifest): void
    {
        $entries = $manifest['cron'] ?? [];
        if (!is_array($entries) || empty($entries)) {
            Executor::run('cron-delete', ["plugin-{$slug}"], null, 10);
            return;
        }

        $lines = ["# Managed by Yuuka Panel plugin system - plugin '{$slug}'. Do not edit manually."];
        foreach ($entries as $entry) {
            $schedule = (string) ($entry['schedule'] ?? '');
            $script = (string) ($entry['script'] ?? '');
            if (!Validator::cronSchedule($schedule) || !Validator::fileBaseName($script)) {
                continue;
            }
            $scriptPath = self::PLUGIN_DIR . "/{$slug}/cron/{$script}";
            $logFile = escapeshellarg(LOG_PATH . "/plugin-{$slug}-cron.log");
            $lines[] = "{$schedule} root " . escapeshellarg($scriptPath) . " >> {$logFile} 2>&1";
        }

        if (count($lines) === 1) {
            Executor::run('cron-delete', ["plugin-{$slug}"], null, 10);
            return;
        }

        $result = Executor::run('cron-write', ["plugin-{$slug}"], implode("\n", $lines) . "\n", 10);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal menulis jadwal cron plugin: ' . $result['output']);
        }
    }

    /**
     * Runs one of an ENABLED plugin's bin/*.sh scripts with full root
     * privilege (see class docblock). $script must be listed in that
     * plugin's OWN manifest under "exec_ops" - an extra whitelist layer
     * on top of panel-exec.sh's own path-containment check, so a
     * plugin's PHP page can't invoke some OTHER script that happens to
     * exist in bin/ but was never declared as an intended entry point.
     */
    public static function runScript(string $slug, string $script, array $args = [], ?string $stdin = null, int $timeout = 60): array
    {
        if (!Validator::pluginSlug($slug) || !Validator::pluginScript($script)) {
            throw new InvalidArgumentException('Nama plugin/script tidak valid');
        }
        $plugin = self::find($slug);
        if ($plugin === null || $plugin['manifest'] === null || !$plugin['is_enabled']) {
            throw new InvalidArgumentException('Plugin tidak aktif');
        }
        $execOps = $plugin['manifest']['exec_ops'] ?? [];
        if (!is_array($execOps) || !in_array($script, $execOps, true)) {
            throw new InvalidArgumentException("Script '{$script}' tidak terdaftar di exec_ops manifest plugin ini");
        }
        return Executor::run('plugin-exec', array_merge([$slug, $script], $args), $stdin, $timeout);
    }

    /**
     * Resolves a manifest-declared route to its PHP file, confined to
     * the plugin's own directory - used by public/plugin.php (admin
     * session required, see Rbac::require('plugin.manage') there).
     */
    public static function resolveRouteFile(array $plugin, string $route): ?string
    {
        return self::resolveManifestFile($plugin, 'routes', $route);
    }

    /**
     * Same resolution as resolveRouteFile(), but reads the manifest's
     * SEPARATE "api_routes" map - used by public/plugin_api.php, which
     * deliberately does NOT require an admin session (an external caller
     * like a WHMCS provisioning module has no panel login of its own).
     * The two are kept as distinct manifest keys/dispatchers so a plugin
     * author can never accidentally expose an admin-only page (routes)
     * to the internet, or vice versa - each file only ever ends up
     * reachable through the ONE dispatcher matching its declared kind.
     * Authenticating the caller (e.g. a shared-secret header) is the
     * PLUGIN's own responsibility inside its api_routes file - this
     * layer only enforces path containment, same as resolveRouteFile().
     */
    public static function resolveApiRouteFile(array $plugin, string $route): ?string
    {
        return self::resolveManifestFile($plugin, 'api_routes', $route);
    }

    private static function resolveManifestFile(array $plugin, string $manifestKey, string $route): ?string
    {
        $manifest = $plugin['manifest'];
        if ($manifest === null) {
            return null;
        }
        $routes = $manifest[$manifestKey] ?? [];
        if (!is_array($routes) || !isset($routes[$route]) || !is_string($routes[$route])) {
            return null;
        }
        $relative = $routes[$route];
        if (str_contains($relative, '..') || str_starts_with($relative, '/')) {
            return null;
        }
        $full = self::PLUGIN_DIR . "/{$plugin['slug']}/{$relative}";
        $real = realpath($full);
        $base = realpath(self::PLUGIN_DIR . "/{$plugin['slug']}");
        if ($real === false || $base === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $real;
    }

    /** @return array<int,array{slug:string,label:string,icon:string,route:string}> menu entries from every ENABLED plugin */
    public static function menuItems(): array
    {
        $items = [];
        foreach (self::listInstalled() as $p) {
            if (!$p['is_enabled'] || $p['manifest'] === null) {
                continue;
            }
            foreach ($p['manifest']['menu'] ?? [] as $entry) {
                if (!is_array($entry) || !isset($entry['label'], $entry['route'])) {
                    continue;
                }
                $items[] = [
                    'slug' => $p['slug'],
                    'label' => (string) $entry['label'],
                    'icon' => (string) ($entry['icon'] ?? 'bi-puzzle'),
                    'route' => (string) $entry['route'],
                ];
            }
        }
        return $items;
    }
}
