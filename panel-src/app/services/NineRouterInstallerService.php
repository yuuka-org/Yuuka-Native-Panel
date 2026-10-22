<?php
declare(strict_types=1);

/**
 * App Installer untuk 9Router (AppCatalog slug '9router') - AI gateway
 * berbasis Next.js yang didistribusikan lewat npm. Beda dengan aplikasi
 * PHP di AppInstallerService, hasil instalasinya adalah app Node.js biasa
 * (NodeService) - jadi Start/Stop/Restart, Logs, Env, Domain, SSL, Backup
 * semuanya langsung tersedia dari menu Node.js Apps tanpa kode khusus.
 *
 * Alur (saga + undo seperti AppInstallerService::installApp()):
 *   1. panel-exec.sh ninerouter-install: `npm install 9router@<versi>` ke
 *      /home/nodeapps/apps/<app> sebagai user nodeapps + runtime SQLite.
 *   2. NodeService::createApp() dengan script custom-server.js paketnya,
 *      env berisi secret acak (JWT/API key/password dashboard awal), dan
 *      proxy_profile 'streaming' (lihat nginx_build_nodejs_proxy_config()).
 *   3. Catat ke installed_apps (kolom nodejs_app_id, migrasi 2026101901).
 *
 * Sinkron di dalam request (sama seperti build command Node.js biasa di
 * op_pm2_deploy) - npm install paket ini (~55 MB, sedikit dependency)
 * normalnya selesai jauh di bawah fastcgi_read_timeout panel (120 detik).
 */
final class NineRouterInstallerService
{
    public const APP_SLUG = '9router';
    private const VERSION_PATTERN = '/^\d{1,3}\.\d{1,3}\.\d{1,4}$/';
    private const INSTALL_TIMEOUT_SECONDS = 420;

    /**
     * @return array{app_id:int,app_name:string,domain:string,port:int,version:?string,initial_password:string,sqlite_runtime:?string}
     */
    public static function install(string $appName, string $domain, ?string $version, ?int $userId): array
    {
        $catalog = AppCatalog::get(self::APP_SLUG);
        if ($catalog === null) {
            throw new InvalidArgumentException('Aplikasi tidak dikenal');
        }

        $appName = trim($appName);
        $domain = strtolower(trim($domain));
        if (!Validator::appName($appName)) {
            throw new InvalidArgumentException('Nama aplikasi tidak valid (huruf, angka, - dan _ saja)');
        }
        if (!Validator::domain($domain)) {
            throw new InvalidArgumentException('Domain tidak valid');
        }
        $versionArg = self::versionArg($version);

        AppInstallerService::assertNodeAppSlotFree($appName, $domain);
        $pdo = Database::app();

        $port = NodeService::findFreePort();
        if ($port === null) {
            throw new RuntimeException('Tidak ada port kosong di rentang 3000-3999 untuk aplikasi baru');
        }
        $nodeVersion = (string) $catalog['node_version'];

        /** @var callable[] $undo */
        $undo = [];

        try {
            $result = Executor::run(
                'ninerouter-install',
                [$appName, $nodeVersion, $versionArg, 'fresh'],
                null,
                self::INSTALL_TIMEOUT_SECONDS
            );
            if (!$result['ok']) {
                throw new RuntimeException('Gagal memasang paket 9Router: ' . self::tail($result['output']));
            }
            // Only registered AFTER success: on failure the op itself has
            // already removed the folder it created (and if it failed on
            // "folder sudah ada", that folder was never ours to delete).
            $undo[] = static function () use ($appName): void {
                try {
                    Executor::run('fs-remove-nodeapp', [$appName], null, 60);
                } catch (Throwable) {
                    // best-effort rollback only
                }
            };

            $parsed = self::parseOutput($result['output']);
            $installedVersion = $parsed['version'] ?? ($versionArg !== 'latest' ? $versionArg : null);

            $initialPassword = self::randomPassword();
            $cwd = "/home/nodeapps/apps/{$appName}";
            $env = [
                // Loopback only - the only public path in is the Nginx vhost
                // createApp() writes below (Next's standalone server binds
                // 0.0.0.0 by default otherwise, exposing the raw port).
                'HOSTNAME' => '127.0.0.1',
                'DATA_DIR' => "{$cwd}/data",
                // What the package's own CLI would have injected
                // (hooks/sqliteRuntime.js buildEnvWithRuntime()) - the
                // runtime dir is where op_ninerouter_install put
                // better-sqlite3 via DATA_DIR.
                'NODE_PATH' => "{$cwd}/data/runtime/node_modules:{$cwd}/node_modules/9router/app/node_modules",
                'NODE_OPTIONS' => '--dns-result-order=ipv4first',
                'BASE_URL' => "http://127.0.0.1:{$port}",
                // Only consulted until the admin sets their own password in
                // the 9Router dashboard. Without it 9Router falls back to
                // "123456" AND refuses that default for any non-localhost
                // login - i.e. the dashboard would be unusable through the
                // domain at all.
                'INITIAL_PASSWORD' => $initialPassword,
                'JWT_SECRET' => bin2hex(random_bytes(32)),
                'API_KEY_SECRET' => bin2hex(random_bytes(32)),
                'MACHINE_ID_SALT' => bin2hex(random_bytes(16)),
            ];

            $nodeApp = NodeService::createApp(
                $appName,
                $domain,
                $nodeVersion,
                $port,
                (string) $catalog['start_script'],
                null,
                1,
                'fork',
                true,
                false,
                '1G',
                'production',
                $env,
                $userId,
                'streaming'
            );
            $appId = (int) $nodeApp['id'];
            $undo[] = static function () use ($appId, $userId): void {
                try {
                    NodeService::deleteApp($appId, true, $userId);
                } catch (Throwable) {
                    // best-effort rollback only
                }
            };

            // createApp() deliberately tolerates a failed Nginx write (the
            // app itself is still usable on its port) - for 9Router the
            // domain IS the whole point, so treat that as a failed install.
            if (NodeService::countDomains($appId) === 0) {
                throw new RuntimeException("Gagal membuat konfigurasi Nginx untuk {$domain}");
            }

            $stmt = $pdo->prepare(
                'INSERT INTO installed_apps (website_id, nodejs_app_id, app_slug, app_version, created_by)
                 VALUES (NULL, :aid, :slug, :ver, :uid)'
            );
            $stmt->execute(['aid' => $appId, 'slug' => self::APP_SLUG, 'ver' => $installedVersion, 'uid' => $userId]);

            ActivityLog::record(
                $userId,
                'app_installer.install',
                "Aplikasi {$catalog['name']} " . ($installedVersion ?? '') . " diinstal ke {$domain} (app Node.js {$appName}, port {$port})"
            );

            return [
                'app_id' => $appId,
                'app_name' => $appName,
                'domain' => $domain,
                'port' => $port,
                'version' => $installedVersion,
                'initial_password' => $initialPassword,
                'sqlite_runtime' => $parsed['sqlite_runtime'] ?? null,
            ];
        } catch (Throwable $e) {
            foreach (array_reverse($undo) as $undoStep) {
                $undoStep();
            }
            if ($e instanceof InvalidArgumentException || $e instanceof RuntimeException) {
                throw $e;
            }
            throw new RuntimeException('Gagal menginstal 9Router: ' . $e->getMessage());
        }
    }

    /**
     * Updates the npm package in place (stop -> npm install -> start). Data
     * (DATA_DIR: providers, API keys, usage history) lives outside
     * node_modules and is never touched. The app is always started again,
     * even when the install fails, so a failed update never leaves it down -
     * npm only swaps the package directory in once the new one is fully
     * extracted, so the old version is still intact in that case.
     */
    public static function update(int $nodeAppId, ?string $version, ?int $userId): ?string
    {
        $stmt = Database::app()->prepare(
            'SELECT id FROM installed_apps WHERE nodejs_app_id = :id AND app_slug = :slug'
        );
        $stmt->execute(['id' => $nodeAppId, 'slug' => self::APP_SLUG]);
        if ($stmt->fetchColumn() === false) {
            throw new InvalidArgumentException('Instalasi 9Router tidak ditemukan');
        }
        $nodeApp = NodeService::find($nodeAppId);
        if ($nodeApp === null) {
            throw new InvalidArgumentException('Aplikasi Node.js tidak ditemukan');
        }
        $versionArg = self::versionArg($version);

        // node_version is whatever the admin last set in Node.js Apps >
        // Settings; anything non-numeric (e.g. 'unknown' from an import)
        // falls back to the catalog's own requirement.
        $nodeVersion = (string) $nodeApp['node_version'];
        if (!preg_match('/^\d{1,3}$/', $nodeVersion)) {
            $nodeVersion = (string) (AppCatalog::get(self::APP_SLUG)['node_version'] ?? '22');
        }

        $startFailure = null;
        NodeService::controlApp($nodeAppId, 'stop', $userId);
        try {
            $result = Executor::run(
                'ninerouter-install',
                [(string) $nodeApp['app_name'], $nodeVersion, $versionArg, 'update'],
                null,
                self::INSTALL_TIMEOUT_SECONDS
            );
        } finally {
            try {
                NodeService::controlApp($nodeAppId, 'start', $userId);
            } catch (Throwable $startError) {
                // Surfaced below only if the install itself succeeded -
                // otherwise the install error is the more useful one.
                $startFailure = $startError->getMessage();
            }
        }

        if (!$result['ok']) {
            throw new RuntimeException('Gagal update 9Router: ' . self::tail($result['output']));
        }
        if ($startFailure !== null) {
            throw new RuntimeException('9Router berhasil diupdate tapi gagal dijalankan ulang: ' . $startFailure);
        }

        $installedVersion = self::parseOutput($result['output'])['version'] ?? ($versionArg !== 'latest' ? $versionArg : null);
        Database::app()->prepare('UPDATE installed_apps SET app_version = :v WHERE nodejs_app_id = :id AND app_slug = :slug')
            ->execute(['v' => $installedVersion, 'id' => $nodeAppId, 'slug' => self::APP_SLUG]);

        ActivityLog::record($userId, 'app_installer.update', "9Router ({$nodeApp['app_name']}) diupdate ke " . ($installedVersion ?? $versionArg));

        return $installedVersion;
    }

    /** Empty/null = newest release on npm; otherwise an exact x.y.z. */
    private static function versionArg(?string $version): string
    {
        $version = trim((string) $version);
        if ($version === '') {
            return 'latest';
        }
        if (!preg_match(self::VERSION_PATTERN, $version)) {
            throw new InvalidArgumentException('Versi 9Router harus berformat x.y.z (contoh: 0.5.104) atau dikosongkan');
        }
        return $version;
    }

    /** @return array{version?:string,sqlite_runtime?:string} */
    private static function parseOutput(string $output): array
    {
        $parsed = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (str_starts_with($line, 'VERSION=')) {
                $v = trim(substr($line, 8));
                if (preg_match(self::VERSION_PATTERN, $v)) {
                    $parsed['version'] = $v;
                }
            } elseif (str_starts_with($line, 'SQLITE_RUNTIME=')) {
                $parsed['sqlite_runtime'] = trim(substr($line, 15));
            }
        }
        return $parsed;
    }

    /** npm's stderr can run to hundreds of lines - keep only the end, where the actual error is. */
    private static function tail(string $output, int $maxChars = 600): string
    {
        $output = trim($output);
        return strlen($output) > $maxChars ? '...' . substr($output, -$maxChars) : $output;
    }

    /** 20 chars, letters+digits only - easy to copy, no shell/URL-special characters. */
    private static function randomPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < 20; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }
}
