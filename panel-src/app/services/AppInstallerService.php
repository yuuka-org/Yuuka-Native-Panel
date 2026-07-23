<?php
declare(strict_types=1);

/**
 * Orchestrates installing a catalog app (AppCatalog) into a brand new
 * website: create the site, optionally provision a database, obtain the
 * app's source (download from a fixed catalog URL, or an admin-uploaded
 * ZIP for the "custom" tier), extract it into the document root, and for
 * fully-automated apps (currently just WordPress) generate the app's
 * config file. Every step reuses an existing, already-audited Service
 * (NginxService, DatabaseService, FileManagerService) - no new
 * panel-exec.sh subcommand was needed for this feature.
 *
 * Runs as a saga: each successful step pushes an undo closure, and if any
 * later step throws, every undo runs in reverse before the (wrapped)
 * exception propagates - so a failure partway through never leaves an
 * orphaned website/database behind.
 */
final class AppInstallerService
{
    private const MAX_REDIRECTS = 3;

    /** @return array{domain:string,app_slug:string,app_name:string,version:?string,db_name:?string,db_user:?string,db_password:?string} */
    public static function installApp(
        string $appSlug,
        string $domain,
        string $phpVersion,
        bool $createDatabase,
        ?string $dbName,
        ?string $dbUser,
        ?string $dbPassword,
        ?string $customZipBytes,
        ?string $appVersion,
        ?int $userId
    ): array {
        $app = AppCatalog::get($appSlug);
        if ($app === null) {
            throw new InvalidArgumentException('Aplikasi tidak dikenal');
        }
        $tier = $app['tier'];

        if ($tier === AppCatalog::TIER_GENERIC && ($customZipBytes === null || $customZipBytes === '')) {
            throw new InvalidArgumentException('File ZIP wajib diupload untuk Custom App');
        }

        // Resolve + validate the chosen version's PHP compatibility before
        // touching the network or creating anything - a mismatch here must
        // reject cleanly, not produce a site that's broken from the start.
        // The client-side dropdown already filters incompatible PHP
        // versions out, but that is only a convenience; it is re-checked
        // here because nothing server-side may trust it.
        $versionEntry = null;
        if (!empty($app['versions'])) {
            $wantedVersion = $appVersion !== null && $appVersion !== '' ? $appVersion : $app['versions'][0]['version'];
            $versionEntry = AppCatalog::findVersion($appSlug, $wantedVersion);
            if ($versionEntry === null) {
                throw new InvalidArgumentException('Versi aplikasi tidak dikenal');
            }
            if (!AppCatalog::phpCompatible($phpVersion, $versionEntry['php_min'], $versionEntry['php_max'])) {
                throw new InvalidArgumentException(
                    "{$app['name']} {$versionEntry['label']} butuh PHP {$versionEntry['php_min']}"
                    . ($versionEntry['php_max'] !== null ? " - {$versionEntry['php_max']}" : '+')
                    . ", tapi PHP {$phpVersion} dipilih."
                );
            }
        } elseif (isset($app['php_min']) && !AppCatalog::phpCompatible($phpVersion, $app['php_min'], $app['php_max'] ?? null)) {
            throw new InvalidArgumentException("{$app['name']} butuh PHP {$app['php_min']} ke atas, tapi PHP {$phpVersion} dipilih.");
        }

        $needsDb = $app['requires_database'] || $createDatabase;

        /** @var callable[] $undo */
        $undo = [];

        try {
            $website = NginxService::createWebsite($domain, $phpVersion, $userId);
            $websiteId = (int) $website['id'];
            $undo[] = static function () use ($websiteId, $userId): void {
                try {
                    NginxService::deleteWebsite($websiteId, true, $userId);
                } catch (Throwable) {
                    // best-effort rollback only - the original error is what matters
                }
            };

            $finalDbName = null;
            $finalDbUser = null;
            $finalDbPassword = null;

            if ($needsDb) {
                if ($tier === AppCatalog::TIER_FULL) {
                    // Auto-generated, never shown to the admin to type/remember -
                    // it's baked straight into the generated config file.
                    $finalDbName = 'wp_' . bin2hex(random_bytes(4));
                    $finalDbUser = 'wpu_' . bin2hex(random_bytes(4));
                    $finalDbPassword = bin2hex(random_bytes(16));
                } else {
                    $finalDbName = (string) $dbName;
                    $finalDbUser = (string) $dbUser;
                    $finalDbPassword = (string) $dbPassword;
                }

                DatabaseService::createDatabase(
                    $finalDbName,
                    $finalDbUser,
                    $finalDbPassword,
                    "App Installer: {$appSlug} ({$domain})",
                    $userId
                );
                $undo[] = static function () use ($finalDbName, $userId): void {
                    try {
                        $stmt = Database::app()->prepare('SELECT id FROM databases_registry WHERE db_name = :n');
                        $stmt->execute(['n' => $finalDbName]);
                        $registryId = $stmt->fetchColumn();
                        if ($registryId !== false) {
                            DatabaseService::dropDatabase((int) $registryId, $userId);
                        }
                    } catch (Throwable) {
                        // best-effort rollback only
                    }
                };
            }

            $version = $versionEntry['version'] ?? null;
            if ($tier === AppCatalog::TIER_GENERIC) {
                $zipBytes = (string) $customZipBytes;
            } elseif ($appSlug === 'wordpress') {
                $wpResult = ($appVersion !== null && $appVersion !== '')
                    ? WordpressInstallerService::downloadSpecificVersion($appVersion)
                    : WordpressInstallerService::downloadLatest();
                $zipBytes = self::stripSingleRootDir($wpResult['bytes']);
                $version = $wpResult['version'];
            } else {
                $zipBytes = self::downloadFixedUrl($versionEntry['download_url']);
                $zipBytes = self::stripSingleRootDir($zipBytes);
            }

            FileManagerService::extractZip('website', $domain, 'public', $zipBytes, $userId);

            // Persisted into installed_apps below so WP Manager can query
            // {prefix}options later without having to re-parse
            // wp-config.php for every site.
            $tablePrefix = null;
            if ($appSlug === 'wordpress') {
                $tablePrefix = WordpressInstallerService::generateTablePrefix();
                $salts = WordpressInstallerService::fetchSalts();
                $wpConfig = WordpressInstallerService::buildConfig(
                    (string) $finalDbName,
                    (string) $finalDbUser,
                    (string) $finalDbPassword,
                    $tablePrefix,
                    $salts
                );
                FileManagerService::writeFile('website', $domain, 'public/wp-config.php', $wpConfig, $userId);
            }

            $stmt = Database::app()->prepare(
                'INSERT INTO installed_apps (website_id, app_slug, app_version, table_prefix, db_name, created_by)
                 VALUES (:wid, :slug, :ver, :prefix, :db, :uid)'
            );
            $stmt->execute([
                'wid' => $websiteId, 'slug' => $appSlug, 'ver' => $version, 'prefix' => $tablePrefix,
                'db' => $finalDbName, 'uid' => $userId,
            ]);

            ActivityLog::record($userId, 'app_installer.install', "Aplikasi {$app['name']} diinstal ke {$domain}");

            return [
                'domain' => $domain,
                'app_slug' => $appSlug,
                'app_name' => $app['name'],
                'version' => $version,
                'db_name' => $finalDbName,
                'db_user' => $finalDbUser,
                'db_password' => $finalDbPassword,
            ];
        } catch (Throwable $e) {
            foreach (array_reverse($undo) as $undoStep) {
                $undoStep();
            }
            if ($e instanceof InvalidArgumentException || $e instanceof RuntimeException) {
                throw $e;
            }
            throw new RuntimeException('Gagal menginstal aplikasi: ' . $e->getMessage());
        }
    }

    /** @return array<int,array{website_id:int,domain:string,app_slug:string,app_version:?string,db_name:?string,installed_at:string}> */
    public static function listInstalled(): array
    {
        return Database::app()->query(
            'SELECT ia.website_id, w.domain, ia.app_slug, ia.app_version, ia.db_name, ia.installed_at
             FROM installed_apps ia
             JOIN websites w ON w.id = ia.website_id
             ORDER BY ia.installed_at DESC'
        )->fetchAll();
    }

    /**
     * Downloads a fixed (never user-supplied) URL to a temp file under
     * sys_get_temp_dir() (inside the panel pool's open_basedir), enforces
     * a size cap, then returns the content as a string.
     */
    public static function downloadFixedUrl(string $url, int $timeoutSeconds = 120): string
    {
        $maxBytes = Config::getInt('APP_INSTALLER_MAX_DOWNLOAD_MB', 200) * 1024 * 1024;

        $tmpFile = tempnam(sys_get_temp_dir(), 'yuuka_app_');
        if ($tmpFile === false) {
            throw new RuntimeException('Gagal membuat file sementara untuk download');
        }
        $fh = fopen($tmpFile, 'wb');
        if ($fh === false) {
            @unlink($tmpFile);
            throw new RuntimeException('Gagal membuka file sementara untuk download');
        }

        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_FILE => $fh,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_USERAGENT => 'YuukaPanel-AppInstaller/1.0',
            ]);
            $ok = curl_exec($ch);
            $errno = curl_errno($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fh);
            $fh = null;

            if ($ok === false || $errno !== 0) {
                throw new RuntimeException('Gagal mengunduh source aplikasi (koneksi gagal)');
            }
            if ($httpCode < 200 || $httpCode >= 300) {
                throw new RuntimeException("Gagal mengunduh source aplikasi (HTTP {$httpCode})");
            }

            $size = filesize($tmpFile);
            if ($size === false || $size === 0) {
                throw new RuntimeException('File hasil download kosong');
            }
            if ($size > $maxBytes) {
                throw new RuntimeException('Ukuran file aplikasi melebihi batas APP_INSTALLER_MAX_DOWNLOAD_MB');
            }

            $content = file_get_contents($tmpFile);
            if ($content === false) {
                throw new RuntimeException('Gagal membaca file hasil download');
            }
            return $content;
        } finally {
            if (is_resource($fh)) {
                fclose($fh);
            }
            @unlink($tmpFile);
        }
    }

    /** Small text responses only (WordPress version-check/salt API). */
    public static function fetchTextUrl(string $url, int $maxBytes = 65536, int $timeoutSeconds = 30): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_USERAGENT => 'YuukaPanel-AppInstaller/1.0',
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $errno !== 0) {
            throw new RuntimeException('Gagal mengambil data dari server resmi aplikasi');
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException("Gagal mengambil data dari server resmi aplikasi (HTTP {$httpCode})");
        }
        if (strlen($body) > $maxBytes) {
            throw new RuntimeException('Respons dari server resmi aplikasi terlalu besar');
        }
        return $body;
    }

    /**
     * Many official app packages (WordPress, Drupal) wrap everything in a
     * single top-level folder (wordpress/, drupal-x.y.z/) - extracting
     * that as-is into a website's document root would produce
     * public/wordpress/index.php instead of public/index.php. Detects a
     * single shared top-level folder across every zip entry and rewrites
     * the archive without it; if the archive is already flat (Joomla/
     * phpBB), returns the input unchanged. Never called for the
     * generic/custom tier - an admin's own uploaded zip is extracted
     * exactly as they packaged it.
     *
     * Public because WpManagerService reuses this for WordPress core
     * updates (same official zip shape as a fresh install).
     */
    public static function stripSingleRootDir(string $zipBytes): string
    {
        $srcTmp = tempnam(sys_get_temp_dir(), 'yuuka_zipin_');
        $dstTmp = tempnam(sys_get_temp_dir(), 'yuuka_zipout_');
        if ($srcTmp === false || $dstTmp === false) {
            throw new RuntimeException('Gagal membuat file sementara untuk memproses ZIP');
        }

        try {
            file_put_contents($srcTmp, $zipBytes);

            $zipIn = new ZipArchive();
            if ($zipIn->open($srcTmp) !== true) {
                throw new RuntimeException('Gagal membuka ZIP aplikasi untuk diproses');
            }

            $commonPrefix = null;
            $hasRootLevelEntry = false;
            for ($i = 0; $i < $zipIn->numFiles; $i++) {
                $name = $zipIn->getNameIndex($i);
                if ($name === false || $name === '') {
                    continue;
                }
                $firstSlash = strpos($name, '/');
                if ($firstSlash === false) {
                    $hasRootLevelEntry = true;
                    break;
                }
                $prefix = substr($name, 0, $firstSlash + 1);
                if ($commonPrefix === null) {
                    $commonPrefix = $prefix;
                } elseif ($commonPrefix !== $prefix) {
                    $commonPrefix = null;
                    break;
                }
            }

            if ($hasRootLevelEntry || $commonPrefix === null) {
                $zipIn->close();
                return $zipBytes;
            }

            $zipOut = new ZipArchive();
            if ($zipOut->open($dstTmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                $zipIn->close();
                throw new RuntimeException('Gagal menyiapkan ZIP hasil pemrosesan');
            }

            $prefixLen = strlen($commonPrefix);
            for ($i = 0; $i < $zipIn->numFiles; $i++) {
                $name = $zipIn->getNameIndex($i);
                if ($name === false) {
                    continue;
                }
                $newName = substr($name, $prefixLen);
                if ($newName === '') {
                    continue;
                }
                if (str_ends_with($name, '/')) {
                    $zipOut->addEmptyDir($newName);
                    continue;
                }
                $contents = $zipIn->getFromIndex($i);
                if ($contents === false) {
                    continue;
                }
                $zipOut->addFromString($newName, $contents);
            }

            $zipIn->close();
            $zipOut->close();

            $result = file_get_contents($dstTmp);
            if ($result === false) {
                throw new RuntimeException('Gagal membaca ZIP hasil pemrosesan');
            }
            return $result;
        } finally {
            @unlink($srcTmp);
            @unlink($dstTmp);
        }
    }
}
