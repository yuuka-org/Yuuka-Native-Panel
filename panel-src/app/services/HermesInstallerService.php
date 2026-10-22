<?php
declare(strict_types=1);

/**
 * App Installer untuk Hermes Agent (Nous Research, AppCatalog slug 'hermes').
 * Seperti 9Router, hasilnya app biasa di menu Node.js Apps (PM2 + vhost
 * proxy 'streaming'), jadi Start/Stop/Logs/Env/Domain & SSL/Backup langsung
 * tersedia. Bedanya:
 *
 *  - Hermes itu Python - PM2 menjalankan launcher Node kecil
 *    (yuuka-hermes.cjs, ditulis panel-exec.sh hermes-install) yang
 *    menjalankan `hermes dashboard --host 127.0.0.1` + `hermes gateway run`.
 *  - Instalasinya (installer resmi: uv, Python terkelola, Node, ripgrep,
 *    ffmpeg, build UI) 3-10 menit - jauh di atas batas request panel - jadi
 *    berjalan di background lewat systemd-run. Selama itu launcher
 *    menyajikan halaman status di domainnya dan meneruskan install.log ke
 *    log PM2 (Node.js Apps > Logs).
 *  - Login memakai auth bawaan Hermes, bukan BasicAuth Nginx: pola reverse
 *    proxy resmi Hermes adalah bind 127.0.0.1 + HERMES_DASHBOARD_PUBLIC_URL
 *    (tanpa itu penjaga DNS-rebinding/CORS-nya menolak Host & Origin dari
 *    domain), dan public_url non-loopback otomatis menyalakan auth gate yang
 *    menolak start tanpa auth provider. BasicAuth Nginx di atasnya hanya
 *    akan membuat dua kali login.
 */
final class HermesInstallerService
{
    public const APP_SLUG = 'hermes';
    private const USERNAME_PATTERN = '/^[a-zA-Z0-9_.-]{3,64}$/';

    /**
     * @return array{app_id:int,app_name:string,domain:string,port:int,username:string,password:string}
     */
    public static function install(string $appName, string $domain, string $username, ?int $userId): array
    {
        $catalog = AppCatalog::get(self::APP_SLUG);
        if ($catalog === null) {
            throw new InvalidArgumentException('Aplikasi tidak dikenal');
        }

        $appName = trim($appName);
        $domain = strtolower(trim($domain));
        $username = trim($username);
        if (!Validator::appName($appName)) {
            throw new InvalidArgumentException('Nama aplikasi tidak valid (huruf, angka, - dan _ saja)');
        }
        if (!Validator::domain($domain)) {
            throw new InvalidArgumentException('Domain tidak valid');
        }
        if (!preg_match(self::USERNAME_PATTERN, $username)) {
            throw new InvalidArgumentException('Username dashboard tidak valid (3-64 karakter: huruf, angka, . _ -)');
        }

        AppInstallerService::assertNodeAppSlotFree($appName, $domain);
        $port = NodeService::findFreePort();
        if ($port === null) {
            throw new RuntimeException('Tidak ada port kosong di rentang 3000-3999 untuk aplikasi baru');
        }
        $password = self::randomPassword();

        /** @var callable[] $undo */
        $undo = [];

        try {
            $prep = Executor::run('hermes-install', [$appName, 'prepare'], null, 60);
            if (!$prep['ok']) {
                throw new RuntimeException('Gagal menyiapkan folder Hermes: ' . $prep['output']);
            }
            // Registered only after success - a failed prepare never
            // touched a folder that wasn't ours (it refuses non-empty ones).
            $undo[] = static function () use ($appName): void {
                try {
                    Executor::run('fs-remove-nodeapp', [$appName], null, 60);
                } catch (Throwable) {
                    // best-effort rollback only
                }
            };

            $cwd = "/home/nodeapps/apps/{$appName}";
            $env = [
                'HERMES_HOME' => "{$cwd}/data",
                // http, not https: the domain has no certificate yet, and a
                // https public URL served over plain http would break the
                // login cookie. Switched to https after SSL is enabled (see
                // the follow-up note shown after install).
                'HERMES_DASHBOARD_PUBLIC_URL' => "http://{$domain}",
                'HERMES_DASHBOARD_BASIC_AUTH_USERNAME' => $username,
                'HERMES_DASHBOARD_BASIC_AUTH_PASSWORD' => $password,
                // Stable signing secret - without it Hermes' login sessions
                // reset on every restart.
                'HERMES_DASHBOARD_BASIC_AUTH_SECRET' => bin2hex(random_bytes(32)),
            ];

            $nodeApp = NodeService::createApp(
                $appName,
                $domain,
                (string) $catalog['node_version'],
                $port,
                (string) $catalog['start_script'],
                null,
                1,
                'fork',
                true,
                false,
                // Only the Node launcher counts against this - the Hermes
                // processes are its children.
                '512M',
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

            if (NodeService::countDomains($appId) === 0) {
                throw new RuntimeException("Gagal membuat konfigurasi Nginx untuk {$domain}");
            }

            Database::app()->prepare(
                'INSERT INTO installed_apps (website_id, nodejs_app_id, app_slug, app_version, created_by)
                 VALUES (NULL, :aid, :slug, :ver, :uid)'
            )->execute(['aid' => $appId, 'slug' => self::APP_SLUG, 'ver' => 'stable', 'uid' => $userId]);

            // Last on purpose: once the background job is running there is
            // no clean way to take it back, so everything that can still
            // fail synchronously has already succeeded.
            $start = Executor::run('hermes-install', [$appName, 'start'], null, 60);
            if (!$start['ok']) {
                throw new RuntimeException('Gagal memulai instalasi Hermes: ' . $start['output']);
            }

            ActivityLog::record(
                $userId,
                'app_installer.install',
                "Aplikasi {$catalog['name']} mulai diinstal ke {$domain} (app Node.js {$appName}, port {$port}, background)"
            );

            return [
                'app_id' => $appId,
                'app_name' => $appName,
                'domain' => $domain,
                'port' => $port,
                'username' => $username,
                'password' => $password,
            ];
        } catch (Throwable $e) {
            foreach (array_reverse($undo) as $undoStep) {
                $undoStep();
            }
            if ($e instanceof InvalidArgumentException || $e instanceof RuntimeException) {
                throw $e;
            }
            throw new RuntimeException('Gagal menginstal Hermes: ' . $e->getMessage());
        }
    }

    /**
     * Re-runs the official installer in the background (it updates the
     * existing checkout to the latest stable release; config, sessions,
     * skills & memory under HERMES_HOME are kept), then the runner restarts
     * the PM2 app itself once done - this call returns immediately.
     */
    public static function update(int $nodeAppId, ?int $userId): void
    {
        $stmt = Database::app()->prepare('SELECT id FROM installed_apps WHERE nodejs_app_id = :id AND app_slug = :slug');
        $stmt->execute(['id' => $nodeAppId, 'slug' => self::APP_SLUG]);
        if ($stmt->fetchColumn() === false) {
            throw new InvalidArgumentException('Instalasi Hermes tidak ditemukan');
        }
        $nodeApp = NodeService::find($nodeAppId);
        if ($nodeApp === null) {
            throw new InvalidArgumentException('Aplikasi Node.js tidak ditemukan');
        }

        $result = Executor::run('hermes-install', [(string) $nodeApp['app_name'], 'update'], null, 60);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal memulai update Hermes: ' . $result['output']);
        }
        ActivityLog::record($userId, 'app_installer.update', "Update Hermes ({$nodeApp['app_name']}) dimulai di background");
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
