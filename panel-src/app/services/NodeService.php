<?php
declare(strict_types=1);

final class NodeService
{
    private const ALLOWED_NODE_VERSIONS = ['18', '20', '22'];
    private const ALLOWED_EXEC_MODES = ['fork', 'cluster'];

    public static function allowedNodeVersions(): array
    {
        return self::ALLOWED_NODE_VERSIONS;
    }

    /** @return array<int,array<string,mixed>> panel-registered apps */
    public static function listRegisteredApps(): array
    {
        return Database::app()->query('SELECT * FROM nodejs_apps ORDER BY app_name')->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::app()->prepare('SELECT * FROM nodejs_apps WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function findByPm2Name(string $pm2Name): ?array
    {
        $stmt = Database::app()->prepare('SELECT * FROM nodejs_apps WHERE pm2_name = :n');
        $stmt->execute(['n' => $pm2Name]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Merges the panel's registered apps (metadata) with the live PM2
     * process list (runtime truth). Apps present in PM2 but not in the
     * database are returned separately as "unmanaged".
     *
     * @return array{managed: array<int,array<string,mixed>>, unmanaged: array<int,array<string,mixed>>}
     */
    public static function combinedStatus(): array
    {
        $registered = self::listRegisteredApps();
        $pm2Map = nodejs_pm2_status_map();

        $managed = [];
        $seenPm2Names = [];
        foreach ($registered as $app) {
            $runtime = $pm2Map[$app['pm2_name']] ?? null;
            $seenPm2Names[] = $app['pm2_name'];
            $managed[] = [
                'meta' => $app,
                'runtime' => $runtime,
                'status' => $runtime['status'] ?? 'unknown',
            ];
        }

        $unmanaged = [];
        foreach ($pm2Map as $name => $proc) {
            if (!in_array($name, $seenPm2Names, true)) {
                $unmanaged[] = $proc;
            }
        }

        return ['managed' => $managed, 'unmanaged' => $unmanaged];
    }

    public static function isPortAvailable(int $port, ?int $excludeAppId = null): bool
    {
        if (!Validator::port($port)) {
            return false;
        }

        $pdo = Database::app();
        $sql = 'SELECT COUNT(*) FROM nodejs_apps WHERE port = :port';
        $params = ['port' => $port];
        if ($excludeAppId !== null) {
            $sql .= ' AND id != :id';
            $params['id'] = $excludeAppId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ((int) $stmt->fetchColumn() > 0) {
            return false;
        }

        $check = Executor::run('port-check', [(string) $port], null, 10);
        return $check['ok'] && trim($check['output']) === 'free';
    }

    public static function findFreePort(int $rangeStart = 3000, int $rangeEnd = 3999): ?int
    {
        for ($port = $rangeStart; $port <= $rangeEnd; $port++) {
            if (self::isPortAvailable($port)) {
                return $port;
            }
        }
        return null;
    }

    /**
     * @param array<string,string> $env
     */
    public static function createApp(
        string $appName,
        string $domain,
        string $nodeVersion,
        int $port,
        string $startCommand,
        ?string $buildCommand,
        int $instances,
        string $execMode,
        bool $autorestart,
        bool $watch,
        string $maxMemoryRestart,
        string $nodeEnv,
        array $env,
        ?int $userId
    ): array {
        if (!Validator::appName($appName)) {
            throw new InvalidArgumentException('Nama aplikasi tidak valid (huruf, angka, - dan _ saja)');
        }
        if ($domain !== '' && !Validator::domain($domain)) {
            throw new InvalidArgumentException('Domain tidak valid');
        }
        if (!in_array($nodeVersion, self::ALLOWED_NODE_VERSIONS, true)) {
            throw new InvalidArgumentException('Versi Node.js tidak didukung');
        }
        if (!in_array($execMode, self::ALLOWED_EXEC_MODES, true)) {
            throw new InvalidArgumentException('exec_mode tidak valid');
        }
        if (!Validator::port($port)) {
            throw new InvalidArgumentException('Port harus di antara 1024-65535');
        }
        if (!Validator::relativeScriptPath($startCommand)) {
            throw new InvalidArgumentException('Start command tidak valid');
        }
        if ($buildCommand !== null && $buildCommand !== '' && !Validator::buildCommand($buildCommand)) {
            throw new InvalidArgumentException('Build command mengandung karakter tidak diizinkan');
        }
        if (!Validator::maxMemoryRestart($maxMemoryRestart)) {
            throw new InvalidArgumentException('Max Memory Restart tidak valid (contoh: 512M, 1G, 150K)');
        }
        if ($instances < 1 || $instances > 32) {
            throw new InvalidArgumentException('Instances harus 1-32');
        }
        if (!self::isPortAvailable($port)) {
            throw new InvalidArgumentException("Port {$port} sudah digunakan aplikasi lain");
        }

        $pdo = Database::app();
        $pm2Name = $appName;

        $dup = $pdo->prepare('SELECT COUNT(*) FROM nodejs_apps WHERE pm2_name = :n');
        $dup->execute(['n' => $pm2Name]);
        if ((int) $dup->fetchColumn() > 0) {
            throw new InvalidArgumentException('Nama aplikasi (PM2 process name) sudah digunakan');
        }

        $cwd = "/home/nodeapps/apps/{$appName}";
        $env['PORT'] = (string) $port;

        $ecosystem = nodejs_build_ecosystem_config(
            $pm2Name, $cwd, $startCommand, $instances, $execMode,
            $autorestart, $watch, $maxMemoryRestart, $nodeEnv, $env
        );

        // Build command is intentionally NOT run here, even though it's
        // already saved below - $cwd is a brand-new, empty directory at
        // this point (files are uploaded/deployed AFTER the app is
        // registered, via File Manager or git), so e.g. "npm run build"
        // would just fail on a missing package.json and abort the whole
        // app registration. It first actually runs on the next explicit
        // redeploy (see updateApp()), by which point real project files
        // are expected to exist.
        $deploy = nodejs_pm2_deploy($pm2Name, $ecosystem, $nodeVersion);
        if (!$deploy['ok']) {
            throw new RuntimeException('Gagal menjalankan aplikasi via PM2: ' . $deploy['output']);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO nodejs_apps
                (app_name, pm2_name, domain, project_path, node_version, port, start_command, build_command,
                 instances, exec_mode, autorestart, watch, max_memory_restart, node_env, is_managed, created_by)
             VALUES
                (:app_name, :pm2_name, :domain, :project_path, :node_version, :port, :start_command, :build_command,
                 :instances, :exec_mode, :autorestart, :watch, :max_mem, :node_env, 1, :uid)'
        );
        $stmt->execute([
            'app_name' => $appName, 'pm2_name' => $pm2Name, 'domain' => $domain ?: null,
            'project_path' => $cwd, 'node_version' => $nodeVersion, 'port' => $port,
            'start_command' => $startCommand, 'build_command' => $buildCommand ?: null,
            'instances' => $instances, 'exec_mode' => $execMode, 'autorestart' => $autorestart ? 1 : 0,
            'watch' => $watch ? 1 : 0, 'max_mem' => $maxMemoryRestart, 'node_env' => $nodeEnv, 'uid' => $userId,
        ]);
        $id = (int) $pdo->lastInsertId();

        foreach ($env as $key => $value) {
            if ($key === 'PORT') {
                continue;
            }
            EnvService::setVariable($id, $key, $value, str_contains(strtolower($key), 'secret') || str_contains(strtolower($key), 'password') || str_contains(strtolower($key), 'key'));
        }

        if ($domain !== '') {
            $siteName = "node-{$domain}";
            $config = nginx_build_nodejs_proxy_config($domain, $port);
            $write = nginx_write_config($siteName, $config);
            if ($write['ok']) {
                nginx_enable_site($siteName);
                $pdo->prepare('INSERT INTO domains (domain, type, nodejs_app_id) VALUES (:d, "nodejs", :id)')
                    ->execute(['d' => $domain, 'id' => $id]);
            }
        }

        ActivityLog::record($userId, 'nodejs.create', "Aplikasi Node.js dibuat: {$appName} (port {$port})");

        return self::find($id);
    }

    /**
     * Edits an existing app's runtime config (everything createApp() takes
     * except app_name/domain/port, which stay fixed for the app's
     * lifetime) and redeploys it via PM2 - the only way to change
     * start/build command, NODE_ENV, instances, exec_mode, autorestart,
     * watch, max_memory_restart, or node_version after creation. Existing
     * env vars are preserved (edited separately via EnvService).
     */
    public static function updateApp(
        int $id,
        string $nodeVersion,
        string $startCommand,
        ?string $buildCommand,
        int $instances,
        string $execMode,
        bool $autorestart,
        bool $watch,
        string $maxMemoryRestart,
        string $nodeEnv,
        ?int $userId
    ): array {
        $app = self::find($id);
        if ($app === null) {
            throw new InvalidArgumentException('Aplikasi tidak ditemukan');
        }
        if (!in_array($nodeVersion, self::ALLOWED_NODE_VERSIONS, true)) {
            throw new InvalidArgumentException('Versi Node.js tidak didukung');
        }
        if (!in_array($execMode, self::ALLOWED_EXEC_MODES, true)) {
            throw new InvalidArgumentException('exec_mode tidak valid');
        }
        if (!Validator::relativeScriptPath($startCommand)) {
            throw new InvalidArgumentException('Start command tidak valid');
        }
        if ($buildCommand !== null && $buildCommand !== '' && !Validator::buildCommand($buildCommand)) {
            throw new InvalidArgumentException('Build command mengandung karakter tidak diizinkan');
        }
        if (!Validator::maxMemoryRestart($maxMemoryRestart)) {
            throw new InvalidArgumentException('Max Memory Restart tidak valid (contoh: 512M, 1G, 150K)');
        }
        if ($instances < 1 || $instances > 32) {
            throw new InvalidArgumentException('Instances harus 1-32');
        }

        $pdo = Database::app();
        $stmt = $pdo->prepare(
            'UPDATE nodejs_apps SET node_version = :nv, start_command = :sc, build_command = :bc,
                instances = :inst, exec_mode = :em, autorestart = :ar, watch = :w,
                max_memory_restart = :mmr, node_env = :ne, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'nv' => $nodeVersion, 'sc' => $startCommand, 'bc' => $buildCommand ?: null,
            'inst' => $instances, 'em' => $execMode, 'ar' => $autorestart ? 1 : 0,
            'w' => $watch ? 1 : 0, 'mmr' => $maxMemoryRestart, 'ne' => $nodeEnv, 'id' => $id,
        ]);

        $env = EnvService::plainMapForApp($id);
        $env['PORT'] = (string) $app['port'];
        $ecosystem = nodejs_build_ecosystem_config(
            $app['pm2_name'], $app['project_path'], $startCommand, $instances, $execMode,
            $autorestart, $watch, $maxMemoryRestart, $nodeEnv, $env
        );
        $deploy = nodejs_pm2_deploy($app['pm2_name'], $ecosystem, $nodeVersion, $buildCommand);
        if (!$deploy['ok']) {
            throw new RuntimeException('Gagal menerapkan konfigurasi: ' . $deploy['output']);
        }

        ActivityLog::record($userId, 'nodejs.update', "Konfigurasi aplikasi diubah & di-redeploy: {$app['app_name']}");

        return self::find($id);
    }

    /** @return array<int,array<string,mixed>> every domain (primary + extra) pointed at this app */
    public static function listDomains(int $appId): array
    {
        $stmt = Database::app()->prepare('SELECT * FROM domains WHERE nodejs_app_id = :id ORDER BY domain');
        $stmt->execute(['id' => $appId]);
        return $stmt->fetchAll();
    }

    public static function countDomains(int $appId): int
    {
        $stmt = Database::app()->prepare('SELECT COUNT(*) FROM domains WHERE nodejs_app_id = :id');
        $stmt->execute(['id' => $appId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Adds an additional domain pointing at the app's already-running port
     * - a separate Nginx site file per domain (mirrors how the app's
     * original/primary domain was wired in createApp()), all proxying to
     * the SAME PM2 process/port. The first domain ever added to a
     * domain-less app also becomes its "primary" domain (nodejs_apps.domain,
     * shown in the main table).
     */
    public static function addDomain(int $appId, string $domain, ?int $userId): void
    {
        $app = self::find($appId);
        if ($app === null) {
            throw new InvalidArgumentException('Aplikasi tidak ditemukan');
        }
        if (!Validator::domain($domain)) {
            throw new InvalidArgumentException('Domain tidak valid');
        }

        $pdo = Database::app();
        $dup = $pdo->prepare('SELECT COUNT(*) FROM domains WHERE domain = :d');
        $dup->execute(['d' => $domain]);
        if ((int) $dup->fetchColumn() > 0) {
            throw new InvalidArgumentException("Domain {$domain} sudah dipakai website/aplikasi lain");
        }

        $siteName = "node-{$domain}";
        $config = nginx_build_nodejs_proxy_config($domain, (int) $app['port']);
        $write = nginx_write_config($siteName, $config);
        if (!$write['ok']) {
            throw new RuntimeException('Gagal menulis konfigurasi Nginx: ' . $write['output']);
        }
        $enable = nginx_enable_site($siteName);
        if (!$enable['ok']) {
            throw new RuntimeException('Gagal mengaktifkan situs Nginx: ' . $enable['output']);
        }

        $pdo->prepare('INSERT INTO domains (domain, type, nodejs_app_id) VALUES (:d, "nodejs", :id)')
            ->execute(['d' => $domain, 'id' => $appId]);

        if ($app['domain'] === null || $app['domain'] === '') {
            $pdo->prepare('UPDATE nodejs_apps SET domain = :d WHERE id = :id')->execute(['d' => $domain, 'id' => $appId]);
        }

        ActivityLog::record($userId, 'nodejs.domain_add', "Domain ditambahkan ke {$app['app_name']}: {$domain}");
    }

    public static function removeDomain(int $appId, string $domain, ?int $userId): void
    {
        $app = self::find($appId);
        if ($app === null) {
            throw new InvalidArgumentException('Aplikasi tidak ditemukan');
        }

        $pdo = Database::app();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM domains WHERE domain = :d AND nodejs_app_id = :id');
        $stmt->execute(['d' => $domain, 'id' => $appId]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new InvalidArgumentException('Domain ini bukan milik aplikasi ini');
        }

        nginx_delete_site("node-{$domain}");
        $pdo->prepare('DELETE FROM domains WHERE domain = :d AND nodejs_app_id = :id')->execute(['d' => $domain, 'id' => $appId]);

        if ($app['domain'] === $domain) {
            $remaining = $pdo->prepare('SELECT domain FROM domains WHERE nodejs_app_id = :id ORDER BY domain LIMIT 1');
            $remaining->execute(['id' => $appId]);
            $next = $remaining->fetchColumn();
            $pdo->prepare('UPDATE nodejs_apps SET domain = :d WHERE id = :id')
                ->execute(['d' => $next !== false ? $next : null, 'id' => $appId]);
        }

        ActivityLog::record($userId, 'nodejs.domain_remove', "Domain dihapus dari {$app['app_name']}: {$domain}");
    }

    public static function controlApp(int $id, string $action, ?int $userId): void
    {
        $app = self::find($id);
        if ($app === null) {
            throw new InvalidArgumentException('Aplikasi tidak ditemukan');
        }

        $result = match ($action) {
            'start' => nodejs_pm2_start($app['pm2_name']),
            'stop' => nodejs_pm2_stop($app['pm2_name']),
            'restart' => nodejs_pm2_restart($app['pm2_name']),
            'reload' => nodejs_pm2_reload($app['pm2_name']),
            'reset' => nodejs_pm2_reset($app['pm2_name']),
            default => throw new InvalidArgumentException('Aksi tidak dikenal'),
        };

        if (!$result['ok']) {
            throw new RuntimeException("Gagal {$action} aplikasi: " . $result['output']);
        }

        ActivityLog::record($userId, "nodejs.{$action}", "Aplikasi {$app['app_name']}: {$action}");
    }

    /** Cloudflare for SaaS "Custom Hostnames" - see NginxService::wildcardHolder() for the cross-table "only one site total" rule this shares with websites. */
    public static function enableWildcard(int $id, ?int $userId): void
    {
        $app = self::find($id);
        if ($app === null) {
            throw new InvalidArgumentException('Aplikasi tidak ditemukan');
        }

        $holder = NginxService::wildcardHolder();
        if ($holder !== null && !($holder['type'] === 'nodejs' && $holder['id'] === $id)) {
            throw new InvalidArgumentException("Slot wildcard sudah dipakai oleh {$holder['name']} - nonaktifkan itu dulu (hanya satu situs yang boleh menerima domain apa saja dalam satu server).");
        }

        $siteName = "wildcard-node-{$app['app_name']}";
        $config = nginx_build_nodejs_wildcard_proxy_config((int) $app['port']);
        $write = nginx_write_config($siteName, $config);
        if (!$write['ok']) {
            throw new RuntimeException('Konfigurasi Nginx tidak valid: ' . $write['output']);
        }
        $enable = nginx_enable_site($siteName);
        if (!$enable['ok']) {
            throw new RuntimeException('Gagal mengaktifkan situs wildcard: ' . $enable['output']);
        }

        Database::app()->prepare('UPDATE nodejs_apps SET wildcard_enabled = 1 WHERE id = :id')->execute(['id' => $id]);
        ActivityLog::record($userId, 'nodejs.wildcard_enable', "Wildcard hostname diaktifkan untuk {$app['app_name']}");
    }

    public static function disableWildcard(int $id, ?int $userId): void
    {
        $app = self::find($id);
        if ($app === null) {
            throw new InvalidArgumentException('Aplikasi tidak ditemukan');
        }

        nginx_delete_site("wildcard-node-{$app['app_name']}");
        Database::app()->prepare('UPDATE nodejs_apps SET wildcard_enabled = 0 WHERE id = :id')->execute(['id' => $id]);
        ActivityLog::record($userId, 'nodejs.wildcard_disable', "Wildcard hostname dinonaktifkan untuk {$app['app_name']}");
    }

    public static function deleteApp(int $id, bool $deleteFiles, ?int $userId): void
    {
        $app = self::find($id);
        if ($app === null) {
            throw new InvalidArgumentException('Aplikasi tidak ditemukan');
        }

        nodejs_pm2_delete($app['pm2_name']);

        // Loop every domain, not just the "primary" one on nodejs_apps -
        // addDomain() lets an app own several, each as its own Nginx site.
        foreach (self::listDomains($id) as $d) {
            nginx_delete_site("node-{$d['domain']}");
        }
        if ($app['wildcard_enabled']) {
            nginx_delete_site("wildcard-node-{$app['app_name']}");
        }

        if ($deleteFiles) {
            Executor::run('fs-remove-nodeapp', [$app['app_name']], null, 30);
        }

        $pdo = Database::app();
        $pdo->prepare('DELETE FROM domains WHERE nodejs_app_id = :id')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM nodejs_apps WHERE id = :id')->execute(['id' => $id]);

        ActivityLog::record($userId, 'nodejs.delete', "Aplikasi dihapus: {$app['app_name']} (files_removed=" . ($deleteFiles ? 'yes' : 'no') . ')');
    }

    public static function clearLogs(int $id): void
    {
        $app = self::find($id);
        if ($app === null) {
            throw new InvalidArgumentException('Aplikasi tidak ditemukan');
        }
        nodejs_pm2_flush($app['pm2_name']);
    }

    public static function getLogs(int $id, int $lines = 150): string
    {
        $app = self::find($id);
        if ($app === null) {
            throw new InvalidArgumentException('Aplikasi tidak ditemukan');
        }
        $result = nodejs_pm2_logs($app['pm2_name'], $lines);
        return $result['output'];
    }

    /** Current live log file size - the SSE stream's starting offset. */
    public static function currentLogOffset(int $id): int
    {
        $app = self::find($id);
        if ($app === null) {
            throw new InvalidArgumentException('Aplikasi tidak ditemukan');
        }
        return nodejs_pm2_logs_size($app['pm2_name']);
    }

    /** @return array{offset:int, content:string} */
    public static function tailLogs(int $id, int $sinceOffset): array
    {
        $app = self::find($id);
        if ($app === null) {
            throw new InvalidArgumentException('Aplikasi tidak ditemukan');
        }
        return nodejs_pm2_logs_tail($app['pm2_name'], $sinceOffset);
    }

    /**
     * Previous runs' log files (each start/restart/reload/deploy gets its
     * own, see rotate_pm2_log() in panel-exec.sh) - newest first.
     * @return array<int, array{file:string, label:string}>
     */
    public static function listLogArchives(int $id): array
    {
        $app = self::find($id);
        if ($app === null) {
            throw new InvalidArgumentException('Aplikasi tidak ditemukan');
        }
        $files = nodejs_pm2_logs_list($app['pm2_name']);
        $prefixLen = strlen($app['pm2_name']);
        $out = [];
        foreach ($files as $file) {
            $ts = substr($file, $prefixLen, 17); // YYYYMMDDHHMMSSmmm
            $label = $file;
            if (strlen($ts) === 17) {
                $label = sprintf(
                    '%s-%s-%s %s:%s:%s.%s',
                    substr($ts, 0, 4), substr($ts, 4, 2), substr($ts, 6, 2),
                    substr($ts, 8, 2), substr($ts, 10, 2), substr($ts, 12, 2), substr($ts, 14, 3)
                );
            }
            $out[] = ['file' => $file, 'label' => $label];
        }
        return $out;
    }

    public static function getArchivedLog(int $id, string $file, int $lines = 1000): string
    {
        $app = self::find($id);
        if ($app === null) {
            throw new InvalidArgumentException('Aplikasi tidak ditemukan');
        }
        $result = nodejs_pm2_logs_read_archive($app['pm2_name'], $file, $lines);
        if (!$result['ok']) {
            throw new InvalidArgumentException('File log tidak ditemukan atau tidak valid');
        }
        return $result['output'];
    }

    /**
     * Registers a Node.js process that is already running in PM2 but not
     * yet tracked by the panel database ("Import to Panel"). Never
     * modifies the running PM2 process itself.
     */
    public static function importUnmanaged(string $pm2Name, ?int $userId): array
    {
        $proc = null;
        foreach (nodejs_pm2_jlist() as $p) {
            if ($p['name'] === $pm2Name) {
                $proc = $p;
                break;
            }
        }
        if ($proc === null) {
            throw new InvalidArgumentException('Proses PM2 tidak ditemukan');
        }

        $pdo = Database::app();
        $dup = $pdo->prepare('SELECT COUNT(*) FROM nodejs_apps WHERE pm2_name = :n');
        $dup->execute(['n' => $pm2Name]);
        if ((int) $dup->fetchColumn() > 0) {
            throw new InvalidArgumentException('Aplikasi ini sudah terdaftar di panel');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO nodejs_apps (app_name, pm2_name, project_path, node_version, port, start_command, instances, exec_mode, is_managed, created_by)
             VALUES (:app_name, :pm2_name, :cwd, :node_version, 0, :script, :instances, :exec_mode, 1, :uid)'
        );
        $stmt->execute([
            'app_name' => $pm2Name,
            'pm2_name' => $pm2Name,
            'cwd' => $proc['cwd'] ?? '/home/nodeapps/apps',
            'node_version' => 'unknown',
            'script' => basename((string) ($proc['script_path'] ?? 'server.js')),
            'instances' => $proc['instances'] ?? 1,
            'exec_mode' => in_array($proc['exec_mode'] ?? 'fork', self::ALLOWED_EXEC_MODES, true) ? $proc['exec_mode'] : 'fork',
            'uid' => $userId,
        ]);
        $id = (int) $pdo->lastInsertId();

        ActivityLog::record($userId, 'nodejs.import', "Aplikasi PM2 tidak dikelola diimpor ke panel: {$pm2Name}");

        return self::find($id);
    }
}
